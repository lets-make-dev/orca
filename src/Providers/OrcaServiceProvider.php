<?php

namespace MakeDev\Orca\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use MakeDev\Orca\Console\Commands\ClaudeHook;
use MakeDev\Orca\Console\Commands\CleanupScreenshots;
use MakeDev\Orca\Http\Middleware\InjectLauncher;
use MakeDev\Orca\Livewire\Launcher;
use Modules\ModuleLoader\Support\ModuleInfoRegistry;

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
        $this->registerModuleInfo();
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
            CleanupScreenshots::class,
            ClaudeHook::class,
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

    protected function registerModuleInfo(): void
    {
        if (! class_exists(ModuleInfoRegistry::class)) {
            return;
        }

        ModuleInfoRegistry::register([
            'name' => 'Orca',
            'description' => 'AI-powered development assistant with Claude integration, terminal sessions, and browser-based code interaction.',
            'version' => '1.0.0',
            'keyFiles' => [
                'packages/MakeDev/Orca/src/Livewire/Launcher.php',
                'packages/MakeDev/Orca/src/Jobs/RunClaudeSession.php',
                'packages/MakeDev/Orca/src/Models/OrcaSession.php',
                'packages/MakeDev/Orca/config/orca.php',
            ],
            'capabilities' => [
                'Claude Sessions',
                'Terminal Pop-Out',
                'Screenshot Capture',
                'Session Management',
            ],
            'dependencies' => [
                'livewire/livewire',
                'laravel/framework',
            ],
        ]);
    }

    private function basePath(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path ? '/'.$path : '');
    }
}
