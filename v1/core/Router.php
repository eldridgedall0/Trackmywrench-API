<?php
/**
 * GarageMinder Mobile API - Router
 * 
 * Lightweight URL router with:
 * - HTTP method matching (GET, POST, PUT, DELETE)
 * - Named route parameters ({id}, {vehicleId})
 * - Middleware support per route
 * - Route groups with shared middleware
 */

namespace GarageMinder\API\Core;

class Router
{
    private array $routes = [];
    private array $globalMiddleware = [];
    private ?string $groupPrefix = null;
    private array $groupMiddleware = [];

    /**
     * Register global middleware (runs on every request)
     */
    public function use(string $middlewareClass): self
    {
        $this->globalMiddleware[] = $middlewareClass;
        return $this;
    }

    /**
     * Group routes with shared prefix and middleware
     */
    public function group(string $prefix, array $middleware, callable $callback): void
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix = ($this->groupPrefix ?? '') . $prefix;
        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    /**
     * Register a route
     */
    public function route(string $method, string $path, string $endpointClass, array $middleware = []): self
    {
        $fullPath = ($this->groupPrefix ?? '') . $path;
        $allMiddleware = array_merge($this->groupMiddleware, $middleware);

        $this->routes[] = [
            'method'     => strtoupper($method),
            'path'       => $fullPath,
            'pattern'    => $this->pathToRegex($fullPath),
            'params'     => $this->extractParamNames($fullPath),
            'endpoint'   => $endpointClass,
            'middleware'  => $allMiddleware,
        ];

        return $this;
    }

    // Convenience methods
    public function get(string $path, string $endpoint, array $mw = []): self
    {
        return $this->route('GET', $path, $endpoint, $mw);
    }

    public function post(string $path, string $endpoint, array $mw = []): self
    {
        return $this->route('POST', $path, $endpoint, $mw);
    }

    public function put(string $path, string $endpoint, array $mw = []): self
    {
        return $this->route('PUT', $path, $endpoint, $mw);
    }

    public function delete(string $path, string $endpoint, array $mw = []): self
    {
        return $this->route('DELETE', $path, $endpoint, $mw);
    }

    /**
     * Dispatch an incoming request
     */
    public function dispatch(Request $request): void
    {
        $method = $request->getMethod();
        $path = $request->getPath();

        // Handle OPTIONS preflight
        if ($method === 'OPTIONS') {
            Response::success(null, 204);
            return;
        }

        // Find matching route
        $matched = null;
        $methodAllowed = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                if ($route['method'] === $method) {
                    // Extract named params
                    $params = [];
                    foreach ($route['params'] as $i => $name) {
                        $params[$name] = $matches[$i + 1] ?? null;
                    }
                    $request->setRouteParams($params);
                    $matched = $route;
                    break;
                }
                $methodAllowed = true;
            }
        }

        if (!$matched) {
            if ($methodAllowed) {
                Response::error('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
            } else {
                Response::error('Endpoint not found', 404, 'NOT_FOUND');
            }
            return;
        }

        // Run middleware pipeline: global → route-specific → endpoint
        $allMiddleware = array_merge($this->globalMiddleware, $matched['middleware']);
        $this->runMiddleware($allMiddleware, $request, function() use ($matched, $request) {
            $endpointClass = $matched['endpoint'];
            if (!class_exists($endpointClass)) {
                Response::error('Endpoint implementation not found', 500);
                return;
            }
            $endpoint = new $endpointClass();
            $endpoint->handle($request);
        });
    }

    /**
     * Run middleware chain
     */
    private function runMiddleware(array $middleware, Request $request, callable $final): void
    {
        if (empty($middleware)) {
            $final();
            return;
        }

        $class = array_shift($middleware);
        $instance = new $class();
        $instance->handle($request, function() use ($middleware, $request, $final) {
            $this->runMiddleware($middleware, $request, $final);
        });
    }

    /**
     * Convert route path to regex pattern
     * /vehicles/{id}/reminders → /^\/vehicles\/([^\/]+)\/reminders$/
     */
    private function pathToRegex(string $path): string
    {
        $pattern = preg_replace('/\{[^}]+\}/', '([^/]+)', $path);
        $pattern = str_replace('/', '\/', $pattern);
        return '/^' . $pattern . '$/';
    }

    /**
     * Extract parameter names from path
     * /vehicles/{id}/reminders → ['id']
     */
    private function extractParamNames(string $path): array
    {
        preg_match_all('/\{([^}]+)\}/', $path, $matches);
        return $matches[1] ?? [];
    }

    /**
     * Get all registered routes (for admin/docs)
     */
    public function getRoutes(): array
    {
        return array_map(function($route) {
            return [
                'method'   => $route['method'],
                'path'     => $route['path'],
                'endpoint' => $route['endpoint'],
            ];
        }, $this->routes);
    }
}
