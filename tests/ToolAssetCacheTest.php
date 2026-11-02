<?php

use Laravel\Nova\Nova;
use MlSolutions\NovaLogViewer\Tool;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ToolAssetCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        require_once __DIR__.'/Support/NovaAssetRegistry.php';
        $this->root = sys_get_temp_dir().'/nova-logs-assets-'.bin2hex(random_bytes(6));
        foreach (['src', 'dist/js', 'dist/css'] as $directory) {
            mkdir($this->root.'/'.$directory, 0700, true);
        }
        copy(__DIR__.'/../src/Tool.php', $this->root.'/src/Tool.php');
        require $this->root.'/src/Tool.php';
    }

    protected function tearDown(): void
    {
        foreach (['src/Tool.php', 'dist/js/tool.js', 'dist/css/tool.css'] as $file) {
            if (is_file($this->root.'/'.$file)) {
                unlink($this->root.'/'.$file);
            }
        }
        foreach (['src', 'dist/js', 'dist/css', 'dist'] as $directory) {
            rmdir($this->root.'/'.$directory);
        }
        rmdir($this->root);
    }

    private function bootAssets(): array
    {
        Nova::$scripts = [];
        Nova::$styles = [];
        (new Tool)->boot();

        return [Nova::$scripts, Nova::$styles];
    }

    public function test_changed_bytes_refresh_only_the_changed_asset_url_even_with_equal_size_and_mtime(): void
    {
        $script = $this->root.'/dist/js/tool.js';
        $style = $this->root.'/dist/css/tool.css';
        file_put_contents($script, 'first();');
        file_put_contents($style, 'a{color:red}');
        touch($script, 1700000000);
        touch($style, 1700000000);
        $first = $this->bootAssets();
        $this->assertSame($first, $this->bootAssets());
        $this->assertSame($script, realpath($first[0][0]['path']));
        $this->assertSame($style, realpath($first[1][0]['path']));

        file_put_contents($script, 'other();');
        touch($script, 1700000000);
        clearstatcache();
        $second = $this->bootAssets();
        $this->assertNotSame($first[0][0]['url'], $second[0][0]['url']);
        $this->assertSame($first[1][0]['url'], $second[1][0]['url']);

        file_put_contents($style, 'a{color:tan}');
        touch($style, 1700000000);
        clearstatcache();
        $third = $this->bootAssets();
        $this->assertSame($second[0][0]['url'], $third[0][0]['url']);
        $this->assertNotSame($second[1][0]['url'], $third[1][0]['url']);
    }

    public function test_missing_assets_do_not_break_boot_or_remove_other_tools_assets(): void
    {
        Nova::$scripts = [['url' => '/nova-api/scripts/other-tool', 'path' => '/other.js']];
        Nova::$styles = [['url' => '/nova-api/styles/other-tool', 'path' => '/other.css']];
        (new Tool)->boot();
        $this->assertCount(1, Nova::$scripts);
        $this->assertCount(1, Nova::$styles);
        $this->assertSame('/nova-api/scripts/other-tool', Nova::$scripts[0]['url']);

        file_put_contents($this->root.'/dist/js/tool.js', 'first();');
        [$scripts, $styles] = $this->bootAssets();
        $this->assertCount(1, $scripts);
        $this->assertSame([], $styles);
    }
}
