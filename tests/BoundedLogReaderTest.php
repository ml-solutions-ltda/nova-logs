<?php

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Facade;
use MlSolutions\NovaLogViewer\Support\BoundedLogReader;
use MlSolutions\NovaLogViewer\Support\LaravelHeader;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../src/Support/BoundedLogReader.php';
require_once __DIR__.'/../src/Support/LaravelHeader.php';
class BoundedLogReaderTest extends TestCase
{
    private string $root;

    private BoundedLogReader $reader;

    protected function setUp(): void
    {
        $app = new Container;
        Container::setInstance($app);
        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();
        $app->instance('config', new Repository(['app' => ['timezone' => 'UTC']]));
        $app->instance('encrypter', new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
        $this->root = sys_get_temp_dir().'/bounded-package-'.bin2hex(random_bytes(5));
        mkdir($this->root, 0700);
        $this->reader = new BoundedLogReader(['roots' => [$this->root], 'node' => 'one', 'scope' => 'operator', 'read_bytes' => 2048], [new LaravelHeader, 'header']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') as $path) {
            unlink($path);
        }rmdir($this->root);
    }

    public function test_budgets_multiline_search_and_stateless_continuation(): void
    {
        file_put_contents($this->root.'/laravel-a.log', "[2026-10-14 12:00:00] production.ERROR: First\n".str_repeat("trace abcdef\n", 900)."needle\n[2026-10-14 12:00:01] production.INFO: Second\n");
        $file = $this->reader->snapshot();
        $cursor = null;
        $all = [];
        $pages = 0;
        do {
            $page = $this->reader->page($file, ['search' => 'needle'], $cursor, 1);
            $this->assertLessThanOrEqual(2048, $page['bytes_read']);
            $all = array_merge($all, $page['data']);
            $cursor = $page['next_cursor'];
            $pages++;
        } while ($cursor && $pages < 20);
        $this->assertGreaterThan(2, $pages);
        $this->assertCount(1, $all);
        $this->assertStringContainsString('First', $this->reader->part($all[0]['reference'], $all[0]['locator'])['text']);
    }

    public function test_allowlist_and_rotation_and_invalid_tokens(): void
    {
        $path = $this->root.'/laravel-a.log';
        file_put_contents($path, "[2026-10-14 12:00:00] production.ERROR: First\n");
        file_put_contents($this->root.'/botman-debug.log', 'foreign');
        symlink($path, $this->root.'/laravel-link.log');
        $this->assertCount(1, $this->reader->files()['data']);
        $file = $this->reader->snapshot();
        rename($path, $path.'.old');
        file_put_contents($path, 'replacement');
        $this->expectException(RuntimeException::class);
        $this->reader->page($file);
    }

    public function test_expired_and_foreign_scope_tokens(): void
    {
        $expired = Crypt::encryptString(json_encode(['v' => 1, 'scope' => 'one|operator', 'expires' => time() - 1, 'value' => []]));
        try {
            $this->reader->decode($expired);
            $this->fail('Expired accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('expirado', $e->getMessage());
        }
        $token = $this->reader->encode(['value' => 1]);
        $other = new BoundedLogReader(['roots' => [$this->root], 'node' => 'one', 'scope' => 'another'], [new LaravelHeader, 'header']);
        $this->expectException(RuntimeException::class);
        $other->decode($token);
    }
    public function testUnreadableFileIsUnavailableToApplicationUser(): void
    {
        $path=$this->root.'/laravel-unreadable.log';file_put_contents($path,'private');chmod($path,0000);
        if (is_readable($path)) { chmod($path,0600);$this->markTestSkipped('Run as www-data to verify Unix permissions.'); }
        $this->assertSame([],$this->reader->files()['data']);
        try {$this->reader->allowed($path);$this->fail('Unreadable file allowed');}catch(RuntimeException){$this->assertTrue(true);}finally{chmod($path,0600);}
    }

}
