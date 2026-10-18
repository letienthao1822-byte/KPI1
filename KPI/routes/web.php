<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\EvaluationController;
use App\Controllers\HomeController;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Router;
use App\Core\View;
use App\Middleware\RoleMiddleware;

return static function (Router $router): void {
    $router->get('/', static function (): void {
        if (!empty($_SESSION['user_id'])) {
            (new HomeController())->index();
            return;
        }

        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        header('Location: ' . $basePath . '/public/login.html');
    });

    $router->get('/login', static function (): void {
        if (!empty($_SESSION['user_id'])) {
            (new HomeController())->index();
            return;
        }

        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        header('Location: ' . $basePath . '/public/login.html');
    });

    $router->post('/login', static function (Request $request): void {
        if (!Csrf::verify($request->input('_token'))) {
            http_response_code(419);
            $error = 'Phiên đăng nhập đã hết hạn. Vui lòng thử lại.';
            View::render('auth/login', ['token' => Csrf::token(), 'error' => $error]);
            return;
        }

        if ((new AuthController())->login($request)) {
            $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
            header('Location: ' . $basePath . '/dashboard');
            return;
        }

        $_SESSION['_flash_error'] = 'Email hoặc mật khẩu không chính xác, hoặc tài khoản đã bị khóa.';
        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        header('Location: ' . $basePath . '/login');
    });

    $router->get('/dashboard', static function (): void {
        (new HomeController())->index();
    });

    $router->get('/admin-dashboard.html', static function (): void {
        RoleMiddleware::check('Admin');
        (new HomeController())->index();
    });

    $router->get('/manager-dashboard.html', static function (): void {
        RoleMiddleware::check('Manager');
        (new HomeController())->index();
    });

    $router->get('/employee-kpi.html', static function (): void {
        RoleMiddleware::check('official');
        (new HomeController())->index();
    });

    $router->post('/evaluations/self', static function (Request $request): void {
        if (!Csrf::verify($request->input('_token'))) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(419);
            echo json_encode(['success' => false, 'message' => 'Phiên gửi biểu mẫu đã hết hạn.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        (new EvaluationController())->submitSelfEvaluation($request);
    });

    $router->get('/evaluations/self', static function (): void {
        (new EvaluationController())->showSelfEvaluationForm();
    });

    $router->get('/evaluations/manager', static function (): void {
        RoleMiddleware::check('Manager');
        View::render('evaluations/pending', ['token' => Csrf::token()]);
    });

    $router->get('/evaluations/pending', static function (): void {
        (new EvaluationController())->getPendingEvaluations();
    });

    $approveEvaluation = static function (Request $request): void {
        if (!Csrf::verify($request->input('_token'))) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(419);
            echo json_encode(['success' => false, 'message' => 'Phiên duyệt đã hết hạn.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        (new EvaluationController())->approveEvaluation($request);
    };
    $router->put('/evaluations/approve', $approveEvaluation);
    $router->patch('/evaluations/approve', $approveEvaluation);

    $router->post('/logout', static function (Request $request): void {
        if (!Csrf::verify($request->input('_token'))) {
            http_response_code(419);
            echo 'Phiên đăng xuất không hợp lệ.';
            return;
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        header('Location: ' . $basePath . '/login');
    });
};
