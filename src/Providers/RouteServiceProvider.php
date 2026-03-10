<?php

namespace MakeDev\Orca\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
        Route::get('/orca/orca.css', function (Request $request) {
            return $this->serveAsset(
                dirname(__DIR__, 2).'/dist/orca.css',
                'text/css; charset=utf-8',
                $request,
            );
        });

        Route::get('/orca/orca.js', function (Request $request) {
            return $this->serveAsset(
                dirname(__DIR__, 2).'/dist/orca-annotator.js',
                'application/javascript; charset=utf-8',
                $request,
            );
        });
    }

    /**
     * Serve a static file with caching headers and 304 support.
     */
    private function serveAsset(string $path, string $contentType, Request $request): \Symfony\Component\HttpFoundation\Response
    {
        if (! file_exists($path)) {
            abort(404);
        }

        $lastModified = filemtime($path);
        $expires = strtotime('+1 year');
        $cacheControl = 'public, max-age=31536000';

        $ifModifiedSince = $request->header('if-modified-since');

        if ($ifModifiedSince !== null && @strtotime($ifModifiedSince) === $lastModified) {
            return response('', 304, [
                'Expires' => sprintf('%s GMT', gmdate('D, d M Y H:i:s', $expires)),
                'Cache-Control' => $cacheControl,
            ]);
        }

        return response()->file($path, [
            'Content-Type' => $contentType,
            'Expires' => sprintf('%s GMT', gmdate('D, d M Y H:i:s', $expires)),
            'Cache-Control' => $cacheControl,
            'Last-Modified' => sprintf('%s GMT', gmdate('D, d M Y H:i:s', $lastModified)),
        ]);
    }
}
