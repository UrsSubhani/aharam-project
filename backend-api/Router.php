<?php
/**
 * Router.php — Lightweight URL router
 *
 * Parses the incoming URI against registered routes and dispatches
 * to the appropriate controller method.
 *
 * Route parameters are extracted from :param segments, e.g.:
 *   GET /menu/{restaurant_id}  →  ['restaurant_id' => '5']
 *
 * Usage in index.php:
 *   $router = new Router();
 *   $router->get('/restaurants', 'RestaurantController@index');
 *   $router->post('/login',      'AuthController@login');
 *   $router->dispatch();
 */

declare(strict_types=1);

class Router
{
    /** @var array<string, array{pattern: string, handler: string, params: string[]}> */
    private array $routes = [];

    // ── Route registration ────────────────────────────────────────────────────

    public function get(string $path, string $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, string $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, string $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }

    public function patch(string $path, string $handler): void
    {
        $this->addRoute('PATCH', $path, $handler);
    }

    public function delete(string $path, string $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    // ── Dispatch ──────────────────────────────────────────────────────────────

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = $this->normalizeUri($_SERVER['REQUEST_URI'] ?? '/');

        foreach ($this->routes as $key => $route) {
            [$routeMethod, $pattern] = explode('|', $key, 2);

            if ($routeMethod !== $method) {
                continue;
            }

            if (preg_match('#' . $pattern . '#', $uri, $matches)) {
                // Extract named parameters
                $params = array_filter(
                    array_intersect_key($matches, array_flip($route['params'])),
                    'is_string',
                    ARRAY_FILTER_USE_KEY
                );

                $this->callHandler($route['handler'], $params);
                return;
            }
        }

        // No route matched
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => "Route not found: $method $uri",
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function addRoute(string $method, string $path, string $handler): void
    {
        // Convert :param placeholders to named regex groups
        $params  = [];
        $pattern = preg_replace_callback('/:([a-zA-Z_]+)/', function ($m) use (&$params) {
            $params[] = $m[1];
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $path);

        $this->routes[$method . '|^' . $pattern . '$'] = [
            'pattern' => '^' . $pattern . '$',
            'handler' => $handler,
            'params'  => $params,
        ];
    }

    /**
     * Strip query string and script name prefix from URI.
     */
    private function normalizeUri(string $uri): string
    {
        // Remove query string
        if (false !== $pos = strpos($uri, '?')) {
            $uri = substr($uri, 0, $pos);
        }

        // Remove base path (e.g., /aharam/backend-api) if running in subdirectory
        $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
        if ($scriptDir !== '/' && str_starts_with($uri, $scriptDir)) {
            $uri = substr($uri, strlen($scriptDir));
        }

        return '/' . ltrim($uri, '/');
    }

    private function callHandler(string $handler, array $params): void
    {
        [$class, $method] = explode('@', $handler, 2);

        $file = __DIR__ . "/controllers/{$class}.php";
        if (!file_exists($file)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => "Controller $class not found."]);
            return;
        }

        require_once $file;

        if (!class_exists($class)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => "Class $class not found."]);
            return;
        }

        $controller = new $class();

        if (!method_exists($controller, $method)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => "Method {$class}::{$method} not found."]);
            return;
        }

        $controller->$method($params);
    }
}
