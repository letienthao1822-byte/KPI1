<?php

declare(strict_types=1);

use App\Core\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this seeder from the command line.\n");
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/app/Core/Database.php';

final class DatabaseSeeder
{
    private PDO $pdo;
    private int $adminId;
    /** @var array<int, int> department id => manager user id */
    private array $managerIds = [];
    /** @var list<array{id:int,department_id:int}> */
    private array $employees = [];
    /** @var list<int> */
    private array $criterionIds = [];

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
    }

    public function run(): void
    {
        $this->truncateOldData();
        $this->ensureRoles();

        $this->pdo->beginTransaction();
        try {
            $departments = $this->createDepartments();
            $roles = $this->getRoleIds();
            $this->createCriteria();
            $this->createUsers($departments, $roles);
            $this->createDemoEvaluations();
            $this->createDummyEvidenceFile();
            $this->pdo->commit();

            echo "Database seeding completed.\n";
            echo "Users: 1 admin, 3 managers, 20 employees.\n";
            echo "Submitted evaluations: 30.\n";
            echo "Demo password for all seeded accounts: 123456\n";
            echo "Admin: admin@kpi.local\n";
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** Delete prior demo records while preserving roles, permissions, and role permissions. */
    private function truncateOldData(): void
    {
        $foreignKeyChecks = (int) $this->pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (['kpi_details', 'kpi_evaluations', 'user_roles', 'kpi_criteria', 'users', 'departments'] as $table) {
                // Table names are fixed constants above, never provided by request input.
                $this->pdo->exec('TRUNCATE TABLE `' . $table . '`');
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = ' . ($foreignKeyChecks === 0 ? '0' : '1'));
        }
    }

    private function ensureRoles(): void
    {
        $statement = $this->pdo->prepare('INSERT IGNORE INTO roles (role_key, name) VALUES (:role_key, :name)');
        foreach ([
            'admin' => 'Quản trị viên',
            'manager' => 'Manager',
            'official' => 'Cán bộ được đánh giá',
        ] as $key => $name) {
            $statement->execute(['role_key' => $key, 'name' => $name]);
        }
    }

    /** @return array<string, int> */
    private function getRoleIds(): array
    {
        $statement = $this->pdo->query("SELECT role_key, id FROM roles WHERE role_key IN ('admin', 'manager', 'official')");
        $roles = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $roles[(string) $row['role_key']] = (int) $row['id'];
        }
        foreach (['admin', 'manager', 'official'] as $key) {
            if (!isset($roles[$key])) {
                throw new RuntimeException("Required role is missing: {$key}");
            }
        }

        return $roles;
    }

    /** @return array<string, int> */
    private function createDepartments(): array
    {
        $statement = $this->pdo->prepare('INSERT INTO departments (name) VALUES (:name)');
        $departments = [];
        foreach (['Phòng CNTT', 'Phòng Kế hoạch', 'Phòng Nhân sự'] as $name) {
            $statement->execute(['name' => $name]);
            $departments[$name] = (int) $this->pdo->lastInsertId();
        }

        return $departments;
    }

    private function createCriteria(): void
    {
        $criteria = [
            ['KPI-CM', 'Năng lực chuyên môn', 'Mức độ hoàn thành nhiệm vụ chuyên môn được giao.', 40.00],
            ['KPI-KL', 'Chấp hành kỷ luật và quy chế', 'Thực hiện nội quy, quy chế và kỷ luật công vụ.', 20.00],
            ['KPI-TD', 'Tinh thần, thái độ phục vụ', 'Thái độ phối hợp, trách nhiệm và phục vụ người dân.', 15.00],
            ['KPI-SD', 'Sáng kiến và cải tiến', 'Đề xuất hoặc áp dụng sáng kiến nâng cao hiệu quả công việc.', 15.00],
            ['KPI-HC', 'Học tập và phát triển năng lực', 'Tham gia học tập, bồi dưỡng và nâng cao năng lực.', 10.00],
        ];
        $statement = $this->pdo->prepare(
            'INSERT INTO kpi_criteria (criterion_code, name, description, target_type, weight, max_score, is_active)
             VALUES (:code, :name, :description, :target_type, :weight, 100, 1)'
        );
        foreach ($criteria as [$code, $name, $description, $weight]) {
            $statement->execute([
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'target_type' => 'official',
                'weight' => number_format((float) $weight, 2, '.', ''),
            ]);
            $this->criterionIds[] = (int) $this->pdo->lastInsertId();
        }
    }

    /** @param array<string, int> $departments
     *  @param array<string, int> $roles
     */
    private function createUsers(array $departments, array $roles): void
    {
        $passwordHash = password_hash('123456', PASSWORD_DEFAULT);
        if (!is_string($passwordHash)) {
            throw new RuntimeException('Unable to hash the demo password.');
        }

        $insertUser = $this->pdo->prepare(
            'INSERT INTO users (department_id, employee_code, full_name, email, password_hash, position_title, is_active)
             VALUES (:department_id, :employee_code, :full_name, :email, :password_hash, :position_title, 1)'
        );
        $assignRole = $this->pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');

        $this->adminId = $this->insertUser(
            $insertUser,
            $assignRole,
            null,
            'ADMIN001',
            'Quản trị hệ thống',
            'admin@kpi.local',
            'Quản trị viên',
            $passwordHash,
            $roles['admin']
        );

        $departmentList = array_values($departments);
        foreach ($departmentList as $index => $departmentId) {
            $number = $index + 1;
            $this->managerIds[$departmentId] = $this->insertUser(
                $insertUser,
                $assignRole,
                $departmentId,
                sprintf('MGR%03d', $number),
                'Quản lý phòng ' . $number,
                sprintf('manager%02d@kpi.local', $number),
                'Trưởng phòng',
                $passwordHash,
                $roles['manager']
            );
        }

        $departmentIds = array_values($departments);
        for ($number = 1; $number <= 20; $number++) {
            $departmentId = $departmentIds[($number - 1) % count($departmentIds)];
            $userId = $this->insertUser(
                $insertUser,
                $assignRole,
                $departmentId,
                sprintf('CB%03d', $number),
                sprintf('Cán bộ Demo %02d', $number),
                sprintf('employee%02d@kpi.local', $number),
                'Chuyên viên',
                $passwordHash,
                $roles['official']
            );
            $this->employees[] = ['id' => $userId, 'department_id' => $departmentId];
        }
    }

    private function insertUser(
        \PDOStatement $insertUser,
        \PDOStatement $assignRole,
        ?int $departmentId,
        string $employeeCode,
        string $fullName,
        string $email,
        string $positionTitle,
        string $passwordHash,
        int $roleId
    ): int {
        $insertUser->execute([
            'department_id' => $departmentId,
            'employee_code' => $employeeCode,
            'full_name' => $fullName,
            'email' => $email,
            'password_hash' => $passwordHash,
            'position_title' => $positionTitle,
        ]);
        $userId = (int) $this->pdo->lastInsertId();
        $assignRole->execute(['user_id' => $userId, 'role_id' => $roleId]);

        return $userId;
    }

    private function createDemoEvaluations(): void
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Ho_Chi_Minh'));
        $periods = [
            $today->modify('first day of last month'),
            $today->modify('first day of this month'),
        ];
        $insertEvaluation = $this->pdo->prepare(
            "INSERT INTO kpi_evaluations
                (subject_user_id, evaluator_user_id, period_start, period_end, status, submitted_at)
             VALUES (:subject_user_id, :evaluator_user_id, :period_start, :period_end, 'submitted', :submitted_at)"
        );
        $insertDetail = $this->pdo->prepare(
            'INSERT INTO kpi_details (evaluation_id, criterion_id, self_score, evidence_path)
             VALUES (:evaluation_id, :criterion_id, :self_score, :evidence_path)'
        );

        $demoEvaluations = [];
        foreach ($this->employees as $index => $employee) {
            $demoEvaluations[] = [$employee, $periods[0]];
            if ($index < 10) {
                $demoEvaluations[] = [$employee, $periods[1]];
            }
        }

        foreach ($demoEvaluations as [$employee, $period]) {
            $periodStart = $period->format('Y-m-d');
            $periodEnd = $period->modify('last day of this month')->format('Y-m-d');
            $maxSubmittedDay = $period->format('Y-m') === $today->format('Y-m')
                ? min((int) $today->format('j'), 20)
                : 20;
            $submittedAt = $period->modify('+' . random_int(0, $maxSubmittedDay - 1) . ' days')->format('Y-m-d 10:00:00');
            $insertEvaluation->execute([
                'subject_user_id' => $employee['id'],
                'evaluator_user_id' => $this->managerIds[$employee['department_id']],
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'submitted_at' => $submittedAt,
            ]);
            $evaluationId = (int) $this->pdo->lastInsertId();

            foreach ($this->criterionIds as $criterionId) {
                $insertDetail->execute([
                    'evaluation_id' => $evaluationId,
                    'criterion_id' => $criterionId,
                    'self_score' => number_format((float) random_int(60, 100), 2, '.', ''),
                    // Stored as a private project-relative path for the secure evidence streaming endpoint.
                    'evidence_path' => 'storage/evidence/dummy.pdf',
                ]);
            }
        }
    }

    private function createDummyEvidenceFile(): void
    {
        $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'evidence';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the evidence storage directory.');
        }

        $path = $directory . DIRECTORY_SEPARATOR . 'dummy.pdf';
        if (is_file($path)) {
            return;
        }

        $content = "BT /F1 14 Tf 20 55 Td (Demo KPI Evidence) Tj ET\n";
        $objects = [
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
            '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 300 100] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj',
            '4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj',
            '5 0 obj << /Length ' . strlen($content) . " >> stream\n" . $content . 'endstream endobj',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object . "\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($index = 1; $index <= 5; $index++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
        }
        $pdf .= "trailer << /Size 6 /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
        if (file_put_contents($path, $pdf, LOCK_EX) === false) {
            throw new RuntimeException('Unable to create the demo evidence PDF.');
        }
    }
}

try {
    (new DatabaseSeeder())->run();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Seeding failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
