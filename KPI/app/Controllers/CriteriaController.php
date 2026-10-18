<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use PDO;
use Throwable;

final class CriteriaController
{
    public function addCriteria(Request $request): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!Csrf::verify($request->input('_token', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
            $this->respond(419, ['success' => false, 'message' => 'Phiên làm việc đã hết hạn.']);
            return;
        }

        $payload = $request->json();
        $name = trim((string) ($payload['name'] ?? ''));
        $weight = $payload['weight'] ?? null;
        $targetType = trim((string) ($payload['target_type'] ?? 'official'));
        $description = isset($payload['description']) ? trim((string) $payload['description']) : null;
        if ($name === '' || mb_strlen($name) > 200 || !is_numeric($weight)
            || !is_finite((float) $weight) || (float) $weight <= 0 || (float) $weight > 100
            || !preg_match('/^[a-zA-Z0-9_-]{1,30}$/', $targetType)) {
            $this->respond(400, ['success' => false, 'message' => 'Tên, trọng số hoặc loại đối tượng không hợp lệ.']);
            return;
        }

        $pdo = null;
        $lockAcquired = false;
        try {
            $pdo = Database::getInstance()->getConnection();
            $lock = $pdo->query("SELECT GET_LOCK('kpi_criteria_weight_guard', 5)")->fetchColumn();
            if ((int) $lock !== 1) {
                $this->respond(503, ['success' => false, 'message' => 'Không thể khóa bộ tiêu chí để cập nhật.']);
                return;
            }
            $lockAcquired = true;
            $pdo->beginTransaction();

            // This aggregate is checked before the INSERT. The advisory lock serializes concurrent additions.
            $sum = (float) $pdo->query('SELECT COALESCE(SUM(weight), 0) FROM kpi_criteria WHERE is_active = 1')->fetchColumn();
            if ($sum + (float) $weight > 100.00001) {
                $pdo->rollBack();
                $this->respond(400, ['success' => false, 'message' => 'Tổng trọng số không được vượt 100%']);
                return;
            }

            $statement = $pdo->prepare(
                'INSERT INTO kpi_criteria (criterion_code, name, description, target_type, weight)
                 VALUES (:code, :name, :description, :target_type, :weight)'
            );
            $statement->execute([
                'code' => 'KPI-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3))),
                'name' => $name,
                'description' => $description !== '' ? $description : null,
                'target_type' => $targetType,
                'weight' => number_format((float) $weight, 2, '.', ''),
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
            $this->respond(201, ['success' => true, 'message' => 'Đã thêm tiêu chí KPI.', 'id' => $id]);
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Adding KPI criterion failed: ' . $exception->getMessage());
            $this->respond(500, ['success' => false, 'message' => 'Không thể thêm tiêu chí KPI.']);
        } finally {
            if ($lockAcquired && $pdo instanceof PDO) {
                $pdo->query("SELECT RELEASE_LOCK('kpi_criteria_weight_guard')");
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function respond(int $status, array $payload): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
