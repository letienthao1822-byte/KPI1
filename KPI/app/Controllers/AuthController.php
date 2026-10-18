<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Csrf;
use RuntimeException;

final class AuthController
{
    public function login(Request $request): bool
    {
        $username = trim((string) $request->input('username', $request->input('email', '')));
        $password = (string) $request->input('password', '');

        if ($username === '' || $password === '') {
            return false;
        }

        $sql = <<<'SQL'
            SELECT
                u.id AS user_id,
                u.full_name,
                u.password_hash,
                d.id AS department_id,
                r.name AS role_name,
                r.role_key
            FROM users AS u
            INNER JOIN user_roles AS ur ON ur.user_id = u.id
            INNER JOIN roles AS r ON r.id = ur.role_id
            LEFT JOIN departments AS d ON d.id = u.department_id
            WHERE (u.email = :email OR u.employee_code = :employee_code)
              AND u.is_active = 1
            ORDER BY ur.role_id ASC
            LIMIT 1
            SQL;

        $statement = Database::getInstance()->getConnection()->prepare($sql);
        $statement->execute([
            'email' => $username,
            'employee_code' => $username,
        ]);
        $user = $statement->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
            throw new RuntimeException('Unable to start the session.');
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['user_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role_name'] = $user['role_name'];
        $_SESSION['role_key'] = $user['role_key'];
        $_SESSION['department_id'] = $user['department_id'] === null
            ? null
            : (int) $user['department_id'];

        return true;
    }

    public function loginApi(Request $request): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->login($request)) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Tài khoản hoặc mật khẩu không chính xác.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'user' => $this->sessionUser(),
            'csrf_token' => Csrf::token(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function checkApiSession(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Phiên đăng nhập không còn hiệu lực.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode([
            'success' => true,
            'user' => $this->sessionUser(),
            'csrf_token' => Csrf::token(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{id:int,name:string,role:string,department_id:?int} */
    private function sessionUser(): array
    {
        $roleKey = strtolower((string) ($_SESSION['role_key'] ?? ''));
        $role = match ($roleKey) {
            'admin' => 'Admin',
            'manager' => 'Manager',
            'official' => 'Employee',
            default => (string) ($_SESSION['role_name'] ?? ''),
        };

        return [
            'id' => (int) ($_SESSION['user_id'] ?? 0),
            'name' => (string) ($_SESSION['full_name'] ?? ''),
            'role' => $role,
            'department_id' => isset($_SESSION['department_id']) ? (int) $_SESSION['department_id'] : null,
        ];
    }
}
