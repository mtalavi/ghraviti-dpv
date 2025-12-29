<?php
/**
 * Router - Simple URL Router
 * 
 * Maps URL patterns to controller actions.
 * Supports GET and POST methods, path parameters.
 * 
 * Usage:
 *   Router::get('/users/{id}', [UserController::class, 'show']);
 *   Router::post('/login', [AuthController::class, 'login']);
 *   Router::dispatch();
 */

require_once __DIR__ . '/Request.php';
require_once __DIR__ . '/Controller.php';

class Router
{
    private static array $routes = [];
    private static string $basePath = '';

    /**
     * Set base path (for subdirectory installations)
     */
    public static function setBasePath(string $path): void
    {
        self::$basePath = rtrim($path, '/');
    }

    /**
     * Register a GET route
     */
    public static function get(string $path, array $handler): void
    {
        self::addRoute('GET', $path, $handler);
    }

    /**
     * Register a POST route
     */
    public static function post(string $path, array $handler): void
    {
        self::addRoute('POST', $path, $handler);
    }

    /**
     * Register a route for any method
     */
    public static function any(string $path, array $handler): void
    {
        self::addRoute('GET', $path, $handler);
        self::addRoute('POST', $path, $handler);
    }

    /**
     * Add a route to the registry
     */
    private static function addRoute(string $method, string $path, array $handler): void
    {
        self::$routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
        ];
    }

    /**
     * Load routes from config file
     */
    public static function loadRoutes(string $file): void
    {
        if (!file_exists($file)) {
            return;
        }

        $routes = require $file;

        foreach ($routes as $definition => $handler) {
            // Parse "GET /path" format
            $parts = explode(' ', $definition, 2);
            if (count($parts) === 2) {
                $method = strtoupper($parts[0]);
                $path = $parts[1];
                self::addRoute($method, $path, $handler);
            }
        }
    }

    /**
     * Match current request to a route and dispatch
     * 
     * @return bool True if route was matched, false otherwise
     */
    public static function dispatch(): bool
    {
        $request = new Request();
        $method = $request->method();
        $path = $request->path();

        // Remove base path from request path
        if (self::$basePath && strpos($path, self::$basePath) === 0) {
            $path = substr($path, strlen(self::$basePath)) ?: '/';
        }

        foreach (self::$routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = self::matchPath($route['path'], $path);

            if ($params !== false) {
                self::callHandler($route['handler'], $request, $params);
                return true;
            }
        }

        return false;
    }

    /**
     * Match a route path pattern against request path
     * Returns array of params if matched, false otherwise
     */
    private static function matchPath(string $pattern, string $path): array|false
    {
        // Convert {param} to regex capture groups
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $path, $matches)) {
            // Extract named params only
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return $params;
        }

        return false;
    }

    /**
     * Call the route handler
     */
    private static function callHandler(array $handler, Request $request, array $params): void
    {
        [$controllerClass, $method] = $handler;

        // Auto-load controller if file exists
        $controllerFile = __DIR__ . '/../controllers/' . $controllerClass . '.php';
        if (file_exists($controllerFile)) {
            require_once $controllerFile;
        }

        if (!class_exists($controllerClass)) {
            throw new RuntimeException("Controller not found: $controllerClass");
        }

        $controller = new $controllerClass();

        if (!method_exists($controller, $method)) {
            throw new RuntimeException("Method not found: {$controllerClass}::{$method}");
        }

        // Call controller method with request and params
        $controller->$method($request, $params);
    }

    /**
     * Get all registered routes (for debugging)
     */
    public static function getRoutes(): array
    {
        return self::$routes;
    }

    /**
     * Clear all routes (useful for testing)
     */
    public static function clear(): void
    {
        self::$routes = [];
    }
}
