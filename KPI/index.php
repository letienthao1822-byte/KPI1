<?php

declare(strict_types=1);

if (!defined('APP_BASE_PATH')) {
    define('APP_BASE_PATH', rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/'));
}

require __DIR__ . '/public/index.php';
