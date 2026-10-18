<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?self $instance = null;
    private PDO $connection;

    private function __construct()
    {
        $config = $this->loadEnvironment(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env');

        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
            if (!array_key_exists($key, $config)) {
                throw new RuntimeException("Missing required environment variable: {$key}");
            }
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['DB_HOST'],
            $config['DB_NAME']
        );

        try {
            $this->connection = new PDO($dsn, $config['DB_USER'], $config['DB_PASS'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Unable to connect to the database.', 0, $exception);
        }
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    /** @return array<string, string> */
    private function loadEnvironment(string $path): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException('.env file not found or is not readable.');
        }

        $values = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException('Unable to read .env file.');
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            if (strlen($value) >= 2) {
                $quote = $value[0];
                if (($quote === '"' || $quote === "'") && $value[strlen($value) - 1] === $quote) {
                    $value = substr($value, 1, -1);
                }
            }

            $values[$key] = $value;
        }

        return $values;
    }
}
