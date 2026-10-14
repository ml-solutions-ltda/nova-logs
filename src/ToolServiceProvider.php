<?php

namespace MlSolutions\NovaLogViewer;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Http\Middleware\Authenticate;
use Laravel\Nova\Nova;
use MlSolutions\NovaLogViewer\Http\Middleware\Authorize;
use MlSolutions\NovaLogViewer\Support\BoundedLogReader;
use MlSolutions\NovaLogViewer\Support\LaravelHeader;

class ToolServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPublishing();

        $this->app->booted(function () {
            $this->routes();
        });

        Nova::serving(function (ServingNova $event) {
            Nova::provideToScript([
                'viewer' => config('log-viewer.colors.levels'),
            ]);
        });
    }

    /**
     * Register the tool's routes.
     *
     * @return void
     */
    protected function routes()
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Nova::router(['nova', Authenticate::class, Authorize::class], 'nova-logs')
            ->group(__DIR__.'/../routes/inertia.php');

        Route::middleware(['nova', Authorize::class])
            ->prefix('nova-vendor/mlsolutions/nova-log-viewer')
            ->group(__DIR__.'/../routes/api.php');
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nova-logs.php', 'nova-logs');
        $this->app->bind(BoundedLogReader::class, function () {
            $options = config('nova-logs.reader', []);
            $options += ['roots' => [storage_path('logs')], 'node' => 'local'];
            $options['scope'] = fn () => (string) request()->user()?->getAuthIdentifier();

            return new BoundedLogReader($options, [new LaravelHeader, 'header']);
        });

        if (config('nova-logs.log-viewer.force_disable_routing', true)) {
            config()->set('log-viewer.route.enabled', false);
        }
    }

    /**
     * Register the package's publishable resources.
     *
     * @return void
     */
    private function registerPublishing()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/nova-logs.php' => config_path('nova-logs.php'),
            ], 'nova-logs-config');
        }
    }
}
