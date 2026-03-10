<?php

namespace MakeDev\Orca\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use MakeDev\Orca\Http\Middleware\InjectLauncher;
use MakeDev\Orca\Livewire\Launcher;

class OrcaServiceProvider extends ServiceProvider
{
    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerConfig();
        $this->registerViews();
        $this->registerLivewireComponents();
        $this->registerMiddleware();
        $this->loadMigrationsFrom($this->basePath('database/migrations'));
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands([
            \MakeDev\Orca\Console\Commands\CleanupScreenshots::class,
        ]);
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $this->publishes([
            $this->basePath('config/orca.php') => config_path('orca.php'),
        ], 'orca-config');

        $this->mergeConfigFrom($this->basePath('config/orca.php'), 'orca');
    }

    /**
     * Register views.
     */
    protected function registerViews(): void
    {
        $this->publishes([
            $this->basePath('resources/views') => resource_path('views/vendor/orca'),
        ], 'orca-views');

        $this->loadViewsFrom($this->basePath('resources/views'), 'orca');
    }

    /**
     * Register Livewire components for this package.
     */
    protected function registerLivewireComponents(): void
    {
        Livewire::component('orca-launcher', Launcher::class);
    }

    /**
     * Push the InjectLauncher middleware to the web group.
     */
    protected function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->pushMiddlewareToGroup('web', InjectLauncher::class);
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }

    private function basePath(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path ? '/'.$path : '');
    }
}
