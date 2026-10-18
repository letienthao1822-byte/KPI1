<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Router;
use App\Core\ExceptionHandler;

define('APP_ROOT', dirname(__DIR__));

if (!defined('APP_BASE_PATH')) {
    $scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $scriptDirectory = rtrim(str_replace('\\', '/', dirname($scriptPath)), '/');
    $basePath = basename($scriptDirectory) === 'public'
        ? dirname($scriptDirectory)
        : $scriptDirectory;
    define('APP_BASE_PATH', $basePath === '/' ? '' : $basePath);
}

require APP_ROOT . '/app/Core/Database.php';
require APP_ROOT . '/app/Core/Request.php';
require APP_ROOT . '/app/Core/Router.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = APP_ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

ExceptionHandler::register();

$request = new Request();
$isApiRequest = str_starts_with($request->path(), '/api/');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Accept');
header('Vary: Origin');
if ($isApiRequest || $request->method() === 'OPTIONS') {
    header('Content-Type: application/json; charset=utf-8');
}

if ($request->method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

$router = new Router();
$registerApiRoutes = require APP_ROOT . '/routes/api.php';
$registerApiRoutes($router);
$registerWebRoutes = require APP_ROOT . '/routes/web.php';
$registerWebRoutes($router);
$router->resolve($request);
