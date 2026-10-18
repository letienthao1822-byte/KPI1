<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class User
{
    /** @return array<string, mixed>|false */
    public function findActiveByEmail(string $email): array|false
    {
        $sql = <<<'SQL'
            SELECT
                u.id AS user_id,
                u.full_name,
                u.password_hash,
                d.id AS department_id,
                r.name AS role_name
            FROM users AS u
            INNER JOIN user_roles AS ur ON ur.user_id = u.id
            INNER JOIN roles AS r ON r.id = ur.role_id
            LEFT JOIN departments AS d ON d.id = u.department_id
            WHERE u.email = :email
              AND u.is_active = 1
            ORDER BY ur.role_id ASC
            LIMIT 1
            SQL;

        $statement = Database::getInstance()->getConnection()->prepare($sql);
        $statement->execute(['email' => $email]);

        return $statement->fetch(PDO::FETCH_ASSOC);
    }
}
