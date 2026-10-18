<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class Logger
{
    public static function info(string $message, array $data = []): void
    {
        self::write('INFO', $message, $data);
    }

    public static function error(string $message, Throwable $exception): void
    {
        self::write('ERROR', $message, [
            'exception' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'detail' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    private static function write(string $level, string $message, array $data): void
    {
        try {
            $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                return;
            }

            $record = sprintf(
                "[%s] %-5s %s%s\n",
                (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d\TH:i:sP'),
                $level,
                str_replace(["\r", "\n"], ' ', $message),
                $data === [] ? '' : ' ' . (json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}')
            );
            file_put_contents($directory . DIRECTORY_SEPARATOR . 'app.log', $record, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Logging must never interrupt the request or expose internal details to the client.
        }
    }
}
