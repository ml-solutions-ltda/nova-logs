<?php

namespace MlSolutions\NovaLogViewer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use MlSolutions\NovaLogViewer\Support\BoundedLogReader;

class NativeLogController extends Controller
{
    public function __construct(private BoundedLogReader $reader) {}

    public function files()
    {
        return response()->json($this->reader->files())->header('Cache-Control', 'private, no-store');
    }

    public function entries(Request $request)
    {
        $data = $request->validate(['file_id' => 'nullable|string|size:64|regex:/^[a-f0-9]+$/', 'cursor' => 'nullable|string|max:65536', 'snapshot' => 'nullable|string|max:65536', 'level' => 'nullable|in:ERROR,CRITICAL,ALERT,EMERGENCY,WARNING,INFO,DEBUG,NOTICE', 'search' => 'nullable|string|max:256']);
        $filters = array_intersect_key($data, array_flip(['level', 'search']));
        try {
            $file = ! empty($data['snapshot']) ? $this->reader->decode($data['snapshot']) : $this->reader->snapshot($data['file_id'] ?? null);
            if ($file && !isset($file['id'], $file['size'], $file['dev'], $file['ino'], $file['head'], $file['tail'])) { throw new \RuntimeException('Snapshot inválido. Atualize a consulta.'); }
            if (! empty($data['file_id']) && ($file['id'] ?? null) !== $data['file_id']) {
                abort(422, 'Atualize o arquivo selecionado.');
            }

            return response()->json($this->reader->page($file, $filters, $data['cursor'] ?? null) + ['snapshot' => $this->reader->encode($file)])->header('Cache-Control', 'private, no-store');
        } catch (\RuntimeException $e) {
            abort(409, $e->getMessage());
        }
    }

    public function show(Request $request, string $reference)
    {
        $data = $request->validate(['locator' => 'required|string|max:8192', 'offset' => 'sometimes|integer|min:0']);
        try {
            return response()->json($this->reader->part($reference, $data['locator'], $data['offset'] ?? 0))->header('Cache-Control', 'private, no-store');
        } catch (\RuntimeException $e) {
            abort(409, $e->getMessage());
        }
    }

    public function download(string $id)
    {
        try {
            $file = $this->reader->snapshot($id);
            $handle = $this->reader->open($file);
        } catch (\RuntimeException $e) {
            abort(409, $e->getMessage());
        }

        return response()->streamDownload(function () use ($handle, $file) {
            try {
                $remaining = $file['size'];
                while ($remaining > 0 && ! connection_aborted()) {
                    $chunk = fread($handle, min(65536, $remaining));
                    if ($chunk === '' || $chunk === false) {
                        break;
                    } echo $chunk;
                    $remaining -= strlen($chunk);
                }
            } finally {
                fclose($handle);
            }
        }, $file['name'], ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
