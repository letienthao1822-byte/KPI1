<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

final class ExceptionHandler
{
    private static bool $handled = false;

    public static function register(): void
    {
        ini_set('display_errors', '0');
        set_exception_handler([self::class, 'handle']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handle(Throwable $exception): void
    {
        self::report($exception);
        self::respond();
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }

        self::report(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
        self::respond();
    }

    private static function report(Throwable $exception): void
    {
        if (self::$handled) {
            return;
        }
        self::$handled = true;
        Logger::error('Unhandled application exception', $exception);
    }

    private static function respond(): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Đã có lỗi hệ thống xảy ra, vui lòng liên hệ Admin',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
