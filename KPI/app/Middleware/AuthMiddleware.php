<?php

declare(strict_types=1);

namespace App\Middleware;

final class AuthMiddleware
{
    public static function handle(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId && $userId > 0) {
            return;
        }

        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized. Please sign in.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
