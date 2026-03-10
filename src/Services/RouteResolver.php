<?php

namespace MakeDev\Orca\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Component;
use ReflectionClass;
use ReflectionMethod;

class RouteResolver
{
    /**
     * Resolve a URL to its route handler metadata.
     *
     * @return array{type: string, handler: string|null, name: string|null}
     */
    public function resolve(string $url): array
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        try {
            $request = Request::create($path, 'GET');
            $route = Route::getRoutes()->match($request);
            $action = $route->getAction();
            $name = $route->getName();

            if (isset($action['controller'])) {
                $controller = Str::contains($action['controller'], '@')
                    ? $action['controller']
                    : $action['controller'].'@__invoke';

                $controllerClass = Str::before($controller, '@');

                if ($this->isLivewireComponent($controllerClass)) {
                    return [
                        'type' => 'livewire',
                        'handler' => $controller,
                        'name' => $name,
                    ];
                }

                return [
                    'type' => 'controller',
                    'handler' => $controller,
                    'name' => $name,
                ];
            }

            if (isset($action['uses']) && is_string($action['uses'])) {
                if (Str::contains($action['uses'], 'Livewire')) {
                    return [
                        'type' => 'livewire',
                        'handler' => $action['uses'],
                        'name' => $name,
                    ];
                }

                return [
                    'type' => 'controller',
                    'handler' => $action['uses'],
                    'name' => $name,
                ];
            }

            if ($action['uses'] instanceof \Closure) {
                return [
                    'type' => 'closure',
                    'handler' => 'Closure',
                    'name' => $name,
                ];
            }
        } catch (\Throwable) {
            // Route not found or match failure
        }

        return [
            'type' => 'unknown',
            'handler' => null,
            'name' => null,
        ];
    }

    /**
     * Resolve a handler string to its file path relative to base_path.
     */
    public function resolveHandlerFile(?string $handler): ?string
    {
        if (! $handler || $handler === 'Closure') {
            return null;
        }

        $class = Str::before($handler, '@');

        if (! class_exists($class) || Str::startsWith($class, 'Illuminate\\')) {
            return null;
        }

        try {
            $filePath = (new ReflectionClass($class))->getFileName();

            return $filePath ? Str::after($filePath, base_path().'/') : null;
        } catch (\ReflectionException) {
            return null;
        }
    }

    /**
     * Resolve view file paths for a handler, relative to base_path.
     *
     * @return string[]
     */
    public function resolveViewFiles(?string $handler, ?string $sourceUrl = null): array
    {
        $views = [];

        // For Route::view() routes, resolve the view from the route definition
        if ($sourceUrl && $handler && Str::contains($handler, 'ViewController')) {
            $views = $this->resolveViewFromRoute($sourceUrl);
        }

        // For controllers/Livewire components, parse the method source
        if (empty($views) && $handler && $handler !== 'Closure' && ! Str::startsWith(Str::before($handler, '@'), 'Illuminate\\')) {
            $views = $this->resolveViewsFromMethodSource($handler);
        }

        return $views;
    }

    /**
     * @return string[]
     */
    private function resolveViewFromRoute(string $url): array
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        try {
            $request = Request::create($path, 'GET');
            $route = Route::getRoutes()->match($request);
            $defaults = $route->defaults;

            if (isset($defaults['view'])) {
                return $this->resolveViewNameToFile($defaults['view']);
            }
        } catch (\Throwable) {
            //
        }

        return [];
    }

    /**
     * @return string[]
     */
    private function resolveViewsFromMethodSource(string $handler): array
    {
        $class = Str::before($handler, '@');
        $method = Str::after($handler, '@');

        if (! class_exists($class) || ! $method) {
            return [];
        }

        // For Livewire components, parse the render() method instead
        $methodsToCheck = $this->isLivewireComponent($class)
            ? ['render']
            : [$method];

        $paths = [];

        try {
            foreach ($methodsToCheck as $m) {
                if (! method_exists($class, $m)) {
                    continue;
                }

                $reflection = new ReflectionMethod($class, $m);
                $source = file_get_contents($reflection->getFileName());
                $lines = array_slice(
                    explode("\n", $source),
                    $reflection->getStartLine() - 1,
                    $reflection->getEndLine() - $reflection->getStartLine() + 1,
                );
                $methodSource = implode("\n", $lines);

                preg_match_all("/view\(\s*['\"]([^'\"]+)['\"]/", $methodSource, $matches);

                foreach ($matches[1] as $viewName) {
                    $paths = array_merge($paths, $this->resolveViewNameToFile($viewName));
                }
            }

            return array_values(array_unique($paths));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return string[]
     */
    private function resolveViewNameToFile(string $viewName): array
    {
        try {
            $filePath = view()->getFinder()->find($viewName);

            return [Str::after($filePath, base_path().'/')];
        } catch (\Throwable) {
            return [];
        }
    }

    private function isLivewireComponent(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($class);

            return $reflection->isSubclassOf(Component::class);
        } catch (\ReflectionException) {
            return false;
        }
    }
}
