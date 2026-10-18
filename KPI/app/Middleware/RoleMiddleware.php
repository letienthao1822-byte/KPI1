<?php

declare(strict_types=1);

namespace App\Middleware;

final class RoleMiddleware
{
    public static function check(string $requiredRole): void
    {
        AuthMiddleware::handle();

        $expectedRole = strtolower(trim($requiredRole));
        $actualName = strtolower(trim((string) ($_SESSION['role_name'] ?? '')));
        $actualKey = strtolower(trim((string) ($_SESSION['role_key'] ?? '')));
        if ($expectedRole === $actualName || $expectedRole === $actualKey) {
            return;
        }

        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Forbidden. You do not have permission to access this resource.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
