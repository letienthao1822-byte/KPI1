<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;

final class HomeController
{
    public function index(): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /' . trim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/') . '/login');
            exit;
        }

        View::render('dashboard/index', [
            'roleName' => $_SESSION['role_name'] ?? 'Chưa gán vai trò',
            'departmentId' => $_SESSION['department_id'] ?? null,
        ]);
    }
}
