<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use RuntimeException;

final class Router
{
    /** @var array<string, array<string, Closure|string>> */
    private array $routes = ['GET' => [], 'POST' => [], 'PUT' => [], 'PATCH' => [], 'DELETE' => []];

    public function get(string $path, callable|string $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|string $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, callable|string $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, callable|string $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, callable|string $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    public function resolve(?Request $request = null): void
    {
        $request ??= new Request();
        $handler = $this->routes[$request->method()][$request->path()] ?? null;

        if ($handler === null) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Route not found.',
                'method' => $request->method(),
                'path' => $request->path(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($handler instanceof Closure) {
            $handler($request);
            return;
        }

        [$controllerName, $method] = array_pad(explode('@', $handler, 2), 2, null);
        if ($method === null || $method === '') {
            throw new RuntimeException("Invalid controller route handler: {$handler}");
        }

        $controllerClass = str_contains($controllerName, '\\')
            ? $controllerName
            : 'App\\Controllers\\' . $controllerName;

        if (!class_exists($controllerClass) || !method_exists($controllerClass, $method)) {
            throw new RuntimeException("Controller action not found: {$handler}");
        }

        $controller = new $controllerClass();
        $action = new \ReflectionMethod($controller, $method);
        if ($action->getNumberOfParameters() > 0) {
            $controller->{$method}($request);
            return;
        }

        $controller->{$method}();
    }

    public function dispatch(Request $request): void
    {
        $this->resolve($request);
    }

    private function add(string $method, string $path, callable|string $handler): void
    {
        if (!isset($this->routes[$method])) {
            throw new RuntimeException("Unsupported route method: {$method}");
        }

        $normalizedPath = '/' . trim($path, '/');
        $this->routes[$method][$normalizedPath] = is_string($handler)
            ? $handler
            : Closure::fromCallable($handler);
    }
}
