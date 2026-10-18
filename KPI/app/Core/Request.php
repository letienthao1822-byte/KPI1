<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string, mixed>|null */
    private ?array $jsonPayload = null;

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $basePath = defined('APP_BASE_PATH')
            ? APP_BASE_PATH
            : rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

        if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }

        return '/' . trim($path, '/');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $this->json()[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->jsonPayload !== null) {
            return $this->jsonPayload;
        }

        $decoded = json_decode(file_get_contents('php://input') ?: '', true);
        $this->jsonPayload = is_array($decoded) ? $decoded : [];

        return $this->jsonPayload;
    }
}
