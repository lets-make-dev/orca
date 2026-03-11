<?php

namespace MakeDev\Orca\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\HttpFoundation\Response;

class InjectLauncher
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldInject($request, $response)) {
            return $response;
        }

        $content = $response->getContent();

        if ($content === false) {
            return $response;
        }

        $distDir = dirname(__DIR__, 3).'/dist';
        $cssVersion = @filemtime($distDir.'/orca.css') ?: 0;
        $jsVersion = @filemtime($distDir.'/orca-annotator.js') ?: 0;

        $cssTag = '<link rel="stylesheet" href="/orca/orca.css?v='.$cssVersion.'">';
        $content = str_replace('</head>', $cssTag."\n</head>", $content);

        $jsTag = '<script src="/orca/orca.js?v='.$jsVersion.'" defer></script>';
        $livewireTag = Blade::render('@livewire(\'orca-launcher\')');

        $content = str_replace('</body>', $jsTag."\n".$livewireTag."\n</body>", $content);

        $response->setContent($content);

        return $response;
    }

    private function shouldInject(Request $request, Response $response): bool
    {
        if (! app()->isLocal()) {
            return false;
        }

        if (! config('orca.enabled', true)) {
            return false;
        }

        if ($request->hasHeader('X-Livewire')) {
            return false;
        }

        $contentType = $response->headers->get('Content-Type', '');

        return str_contains($contentType, 'text/html');
    }
}
