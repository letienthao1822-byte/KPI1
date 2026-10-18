<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    /** @param array<string, mixed> $data */
    public static function render(string $view, array $data = []): void
    {
        $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $view) . '.php';

        if (!is_file($file)) {
            throw new RuntimeException("View not found: {$view}");
        }

        extract($data, EXTR_SKIP);
        require $file;
    }
}
