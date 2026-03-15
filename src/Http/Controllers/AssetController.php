<?php

namespace MakeDev\Orca\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AssetController
{
    public function css(Request $request): Response
    {
        return $this->serveAsset(
            dirname(__DIR__, 3).'/dist/orca.css',
            'text/css; charset=utf-8',
            $request,
        );
    }

    public function js(Request $request): Response
    {
        return $this->serveAsset(
            dirname(__DIR__, 3).'/dist/orca-annotator.js',
            'application/javascript; charset=utf-8',
            $request,
        );
    }

    public function webtermJs(Request $request): Response
    {
        return $this->serveAsset(
            dirname(__DIR__, 3).'/dist/orca-webterm.js',
            'application/javascript; charset=utf-8',
            $request,
        );
    }

    public function webtermCss(Request $request): Response
    {
        return $this->serveAsset(
            dirname(__DIR__, 3).'/dist/orca-webterm.css',
            'text/css; charset=utf-8',
            $request,
        );
    }

    /**
     * Serve a static file with caching headers and 304 support.
     */
    private function serveAsset(string $path, string $contentType, Request $request): Response
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
