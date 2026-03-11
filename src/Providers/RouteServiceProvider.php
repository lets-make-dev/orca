<?php

namespace MakeDev\Orca\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use MakeDev\Orca\Http\Controllers\AssetController;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * Define the routes for the application.
     */
    public function map(): void
    {
        $this->mapWebRoutes();
        $this->mapAssetRoutes();
    }

    /**
     * Define the "web" routes for the application.
     */
    protected function mapWebRoutes(): void
    {
        Route::middleware('web')->group(dirname(__DIR__, 2).'/routes/web.php');
    }

    /**
     * Register routes that serve pre-compiled CSS and JS assets.
     */
    protected function mapAssetRoutes(): void
    {
        Route::get('/orca/orca.css', [AssetController::class, 'css']);
        Route::get('/orca/orca.js', [AssetController::class, 'js']);
    }
}
