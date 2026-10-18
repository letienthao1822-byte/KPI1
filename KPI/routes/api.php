<?php

declare(strict_types=1);

use App\Core\Router;
use App\Core\Request;
use App\Controllers\EvaluationController;
use App\Controllers\ReportController;
use App\Controllers\FileController;
use App\Controllers\AuthController;
use App\Controllers\AdminController;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;

return static function (Router $router): void {
    $router->post('/api/auth/login', static function (Request $request): void {
        (new AuthController())->loginApi($request);
    });

    $router->get('/api/auth/check', static function (): void {
        (new AuthController())->checkApiSession();
    });

    $router->post('/api/evaluate/submit', static function (Request $request): void {
        AuthMiddleware::handle();
        (new EvaluationController())->submitSelfEvaluation($request);
    });

    $router->get('/api/evaluate/pending', static function (): void {
        RoleMiddleware::check('Manager');
        (new EvaluationController())->getPendingEvaluations();
    });

    $router->get('/api/evaluate/evidence', static function (Request $request): void {
        AuthMiddleware::handle();
        $evidenceId = filter_var(
            $request->input('evidence_id', $request->input('detail_id', $request->input('id'))),
            FILTER_VALIDATE_INT
        );
        if (!$evidenceId || $evidenceId < 1) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Mã file minh chứng không hợp lệ.'], JSON_UNESCAPED_UNICODE);
            return;
        }
        (new FileController())->viewEvidence((int) $evidenceId);
    });

    $router->put('/api/evaluate/approve', static function (Request $request): void {
        RoleMiddleware::check('Manager');
        (new EvaluationController())->approveEvaluation($request);
    });

    $router->get('/api/reports/kpi.csv', static function (): void {
        RoleMiddleware::check('Admin');
        (new ReportController())->exportKpiReport();
    });

    $router->get('/api/report/export', static function (): void {
        RoleMiddleware::check('Admin');
        (new ReportController())->exportKpiReport();
    });

    $router->post('/api/admin/criteria', static function (Request $request): void {
        RoleMiddleware::check('Admin');
        (new AdminController())->addCriteria($request);
    });

    $router->post('/api/admin/period', static function (Request $request): void {
        RoleMiddleware::check('Admin');
        (new AdminController())->openPeriod($request);
    });
};
