<?php

namespace MlSolutions\NovaLogViewer\Support;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/** Stateless, bounded file access. No entry index, cache or database access. */
class BoundedLogReader
{
    private ?array $discovery = null;

    public function __construct(private array $options, private $parser) {}

    private function limit(string $key, int $default, int $max): int
    {
        return max(1, min($max, (int) ($this->options[$key] ?? $default)));
    }

    public function files(): array
    {
        if ($this->discovery !== null) {
            return $this->discovery;
        }
        $files = [];
        $examined = 0;
        $partial = false;
        $started = microtime(true);
        foreach ($this->options['roots'] as $root) {
            $root = realpath($root);
            if (! $root || ! is_dir($root) || is_link($root)) {
                continue;
            }
            try {
                $iterator = new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS);
            } catch (\Throwable) {
                $partial = true;

                continue;
            }
            foreach ($iterator as $item) {
                if (++$examined > $this->limit('discovery_entries', 1000, 10000) || microtime(true) - $started > 0.25) {
                    $partial = true;
                    break 2;
                }
                try {
                    $path = $this->allowed($item->getPathname());
                    $stat = stat($path);
                    $id = hash('sha256', $this->options['node'].'|'.$path);
                    $files[] = ['id' => $id, 'name' => basename($path), 'size' => $stat['size'], 'modified' => $stat['mtime']];
                    if (count($files) >= $this->limit('max_files', 100, 100)) {
                        $partial = true;
                        break 2;
                    }
                } catch (\Throwable) { /* Outside allowlist, unreadable, or removed. */
                }
            }
        }
        usort($files, fn ($a, $b) => ($b['modified'] <=> $a['modified']) ?: strcmp($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']));

        return $this->discovery = ['data' => $files, 'partial' => $partial];
    }

    public function allowed(string $path): string
    {
        $real = realpath($path);
        $name = basename($path);
        $validName = preg_match('/^laravel-[A-Za-z0-9._-]+\.log$/D', $name) || ($name === 'laravel.log' && ($this->options['include_single'] ?? true));
        if (! $validName || is_link($path) || ! $real || ! is_file($real) || ! is_readable($real)) {
            throw new RuntimeException('Arquivo não permitido ou indisponível.');
        }
        foreach ($this->options['roots'] as $configured) {
            $root = realpath($configured);
            // Flat roots only: reject nested paths and directory symlinks as well.
            if ($root && ! is_link($configured) && dirname($real) === $root && dirname($path) === $root) {
                return $real;
            }
        }
        throw new RuntimeException('Arquivo fora das pastas autorizadas.');
    }

    public function path(string $id): string
    {
        foreach ($this->files()['data'] as $file) {
            if (hash_equals($file['id'], $id)) {
                foreach ($this->options['roots'] as $root) {
                    $root = realpath($root);
                    if ($root && hash_equals($id, hash('sha256', $this->options['node'].'|'.$root.'/'.$file['name']))) {
                        return $this->allowed($root.'/'.$file['name']);
                    }
                }
            }
        }
        throw new RuntimeException('Arquivo removido, inacessível ou fora da descoberta limitada.');
    }

    private function scope(): string
    {
        $scope = $this->options['scope'] ?? '';

        return $this->options['node'].'|'.(string) (is_callable($scope) ? $scope() : $scope);
    }

    public function encode(array $value): string
    {
        return Crypt::encryptString(json_encode(['v' => 1, 'scope' => $this->scope(), 'expires' => time() + 1800, 'value' => $value], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function decode(string $token): array
    {
        try {
            if (strlen($token) > 65536) {
                throw new RuntimeException;
            }
            $data = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
            if ($data['v'] !== 1 || $data['scope'] !== $this->scope() || $data['expires'] < time() || ! is_array($data['value'])) {
                throw new RuntimeException;
            }

            return $data['value'];
        } catch (\Throwable) {
            throw new RuntimeException('Cursor inválido ou expirado. Atualize a consulta.');
        }
    }

    private function anchor($handle, int $offset, int $bytes): string
    {
        if (fseek($handle, $offset) !== 0) {
            throw new RuntimeException('Não foi possível posicionar a leitura.');
        }

        return hash('sha256', $bytes ? fread($handle, $bytes) : '');
    }

    public function snapshot(?string $id = null): array
    {
        $id ??= $this->files()['data'][0]['id'] ?? null;
        if (! $id) {
            return [];
        }
        $handle = $this->openPath($this->path($id));
        try {
            $stat = fstat($handle);
            $size = $stat['size'];
            $bytes = min(256, $size);

            return ['id' => $id, 'name' => basename($this->path($id)), 'dev' => $stat['dev'], 'ino' => $stat['ino'], 'size' => $size, 'modified' => $stat['mtime'], 'head' => $this->anchor($handle, 0, $bytes), 'tail' => $this->anchor($handle, max(0, $size - $bytes), $bytes)];
        } finally {
            fclose($handle);
        }
    }

    private function openPath(string $path)
    {
        $before = lstat($this->allowed($path));
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            throw new RuntimeException('Arquivo sem permissão de leitura.');
        }
        $after = fstat($handle);
        try {
            $this->allowed($path);
        } catch (\Throwable $e) {
            fclose($handle);
            throw $e;
        }
        if ($before['dev'] !== $after['dev'] || $before['ino'] !== $after['ino']) {
            fclose($handle);
            throw new RuntimeException('Arquivo mudou durante a abertura.');
        }

        return $handle;
    }

    public function open(array $file)
    {
        $handle = $this->openPath($this->path($file['id']));
        $stat = fstat($handle);
        $bytes = min(256, $file['size']);
        if ($file['dev'] !== $stat['dev'] || $file['ino'] !== $stat['ino'] || $stat['size'] < $file['size'] || $file['head'] !== $this->anchor($handle, 0, $bytes) || $file['tail'] !== $this->anchor($handle, max(0, $file['size'] - $bytes), $bytes)) {
            fclose($handle);
            throw new RuntimeException('Arquivo rotacionado, truncado ou alterado. Atualize a consulta.');
        }

        return $handle;
    }

    /** Forward physical order. Carry only one bounded record across budgets. */
    public function page(array $file, array $filters = [], ?string $cursor = null, int $perPage = 50): array
    {
        $state = ['offset' => 0, 'active' => null, 'line_start' => true, 'overlap' => ''];
        if ($cursor) {
            $decoded = $this->decode($cursor);
            if (($decoded['file'] ?? null) !== $file || ($decoded['filters'] ?? null) !== $filters || !isset($decoded['state']['offset'], $decoded['state']['line_start'], $decoded['state']['overlap']) || !array_key_exists('active', $decoded['state'])) {
                throw new RuntimeException('Cursor pertence a outro arquivo ou filtro.');
            }
            $state = $decoded['state'];
        }
        if (! $file) {
            return ['data' => [], 'next_cursor' => null, 'partial' => false, 'bytes_read' => 0, 'scanned_to' => 0];
        }
        $handle = $this->open($file);
        $rows = [];
        $read = 0;
        $records = 0;
        $started = microtime(true);
        $budget = $this->limit('read_bytes', 1048576, 1048576);
        $preview = $this->limit('entry_preview_bytes', 16384, 16384);
        $perPage = max(1, min(100, $perPage));
        $needle = mb_strtolower((string) ($filters['search'] ?? ''));
        try {
            fseek($handle, $state['offset']);
            while ($state['offset'] < $file['size'] && $read < $budget && $records < $this->limit('scan_records', 500, 500) && microtime(true) - $started < min(0.25, max(0.001, (float) ($this->options['read_seconds'] ?? 0.25)))) {
                $offset = $state['offset'];
                $length = min(8192, $budget - $read, $file['size'] - $offset);
                $line = fgets($handle, $length + 1);
                if ($line === false || $line === '') {
                    throw new RuntimeException('Leitura interrompida; o arquivo pode ter sido truncado.');
                }
                $read += strlen($line);
                $metadata = $state['line_start'] ? ($this->parser)($line) : null;
                if ($metadata && $state['active']) {
                    $entry = $this->entry($file, $state['active'], $offset, true);
                    $records++;
                    if ($this->matches($entry, $filters, $state['active']['match'])) {
                        $rows[] = $entry;
                    }
                    $state['active'] = null;
                    if (count($rows) >= $perPage) {
                        fseek($handle, $offset);
                        break;
                    }
                }
                if ($metadata) {
                    $state['active'] = ['start' => $offset, 'meta' => $metadata, 'preview_bytes' => 0, 'match' => false];
                    $state['overlap'] = '';
                }
                if (! $state['active'] && $offset === 0) {
                    $state['active'] = ['start' => 0, 'meta' => ['level' => 'UNKNOWN', 'message' => '[Formato não reconhecido]', 'occurred_at' => null, 'category' => 'unknown'], 'preview_bytes' => 0, 'match' => false];
                }
                if ($state['active']) {
                    $state['active']['preview_bytes'] = min($preview, $state['active']['preview_bytes'] + strlen($line));
                    $haystack = mb_strtolower(mb_convert_encoding($state['overlap'].$line, 'UTF-8', 'UTF-8'));
                    $state['active']['match'] = $state['active']['match'] || $needle === '' || str_contains($haystack, $needle);
                    $state['overlap'] = substr($line, -2048);
                }
                $state['offset'] = ftell($handle);
                $state['line_start'] = str_ends_with($line, "\n");
            }
            if ($state['offset'] === $file['size'] && $state['active']) {
                $entry = $this->entry($file, $state['active'], $state['offset'], false);
                if ($this->matches($entry, $filters, $state['active']['match'])) {
                    $rows[] = $entry;
                }
                $state['active'] = null;
            }
            // Revalidate the same high water anchors after the bounded scan.
            $check = $this->open($file);
            fclose($check);
            $more = $state['offset'] < $file['size'];

            return ['data' => $rows, 'next_cursor' => $more ? $this->encode(['file' => $file, 'filters' => $filters, 'state' => $state]) : null, 'partial' => $more, 'bytes_read' => $read, 'scanned_to' => $state['offset'], 'file' => $file['name'], 'ceiling' => $file['size']];
        } finally {
            fclose($handle);
        }
    }

    private function entry(array $file, array $active, int $end, bool $complete): array
    {
        $reference = hash('sha256', $this->options['node'].'|'.$file['id'].'|'.$file['dev'].'|'.$file['ino'].'|'.hash('sha256', json_encode($active['meta'])).'|'.$active['start']);

        return $active['meta'] + ['reference' => $reference, 'file_id' => $file['id'], 'log_file' => $file['name'], 'start' => $active['start'], 'end' => $end, 'complete' => $complete, 'preview_truncated' => $end - $active['start'] > $active['preview_bytes'], 'locator' => $this->encode(['file' => $file, 'start' => $active['start'], 'end' => $end, 'reference' => $reference, 'complete' => $complete])];
    }

    private function matches(array $entry, array $filters, bool $search): bool
    {
        if (! $search) {
            return false;
        }
        foreach (['level', 'category', 'channel', 'environment', 'exception', 'fingerprint'] as $key) {
            if (! empty($filters[$key]) && ($entry[$key] ?? null) !== $filters[$key]) {
                return false;
            }
        }
        if (empty($filters['include_testing']) && ($entry['environment'] ?? null) === 'testing') {
            return false;
        }
        if (! empty($filters['exclude_deprecations']) && ($entry['category'] ?? null) === 'deprecation') {
            return false;
        }
        if (! empty($filters['failures']) && (! in_array($entry['level'], ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY']) || ($entry['category'] ?? null) === 'deprecation')) {
            return false;
        }
        if (! empty($filters['critical']) && (! in_array($entry['level'], ['CRITICAL', 'ALERT', 'EMERGENCY']) || ($entry['category'] ?? null) === 'deprecation')) {
            return false;
        }
        if (! empty($entry['occurred_at']) && ((! empty($filters['from']) && $entry['occurred_at'] < $filters['from']) || (! empty($filters['to']) && $entry['occurred_at'] > $filters['to']))) {
            return false;
        }

        return true;
    }

    public function part(string $reference, string $locator, int $offset = 0, int $limit = 65536): array
    {
        $data = $this->decode($locator);
        if (($data['reference'] ?? null) !== $reference || $offset < 0 || $offset > $data['end'] - $data['start']) {
            throw new RuntimeException('Localizador ou offset inválido.');
        }
        $handle = $this->open($data['file']);
        try {
            fseek($handle, $data['start']);
            $header = fgets($handle, 8193) ?: '';
            $meta = ($this->parser)($header) ?? ['message' => '[Formato não reconhecido]', 'level' => 'UNKNOWN', 'category' => 'unknown', 'occurred_at' => null];
            $size = $data['end'] - $data['start'];
            $limit = max(1, min(262144, $limit));
            fseek($handle, $data['start'] + $offset);
            $raw = $offset < $size ? fread($handle, min($limit, $size - $offset)) : '';
            if (strlen($raw) !== min($limit, $size - $offset)) {
                throw new RuntimeException('Arquivo mudou durante a leitura.');
            }
            $check = $this->open($data['file']);
            fclose($check);
            $next = $offset + strlen($raw);

            return ['entry' => (object) ($meta + ['reference' => $reference, 'log_file' => $data['file']['name'], 'file_id' => $data['file']['id']]), 'text' => mb_convert_encoding($raw, 'UTF-8', 'UTF-8'), 'offset' => $offset, 'next_offset' => $next < $size ? $next : null, 'bytes' => $size, 'complete' => $data['complete']];
        } finally {
            fclose($handle);
        }
    }
}
