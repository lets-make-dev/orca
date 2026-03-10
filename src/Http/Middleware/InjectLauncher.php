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

        $cssTag = '<link rel="stylesheet" href="/orca/orca.css">';
        $content = str_replace('</head>', $cssTag."\n</head>", $content);

        $jsTag = '<script src="/orca/orca.js" defer></script>';
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
