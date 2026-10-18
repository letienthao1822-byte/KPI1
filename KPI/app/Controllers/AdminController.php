<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class AdminController
{
    public function addCriteria(Request $request): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->verifyCsrf($request)) {
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
            if ((int) $pdo->query("SELECT GET_LOCK('kpi_criteria_weight_guard', 5)")->fetchColumn() !== 1) {
                $this->respond(503, ['success' => false, 'message' => 'Không thể khóa bộ tiêu chí để cập nhật.']);
                return;
            }
            $lockAcquired = true;
            $pdo->beginTransaction();

            // Run the aggregate before insertion. The advisory lock prevents concurrent additions from exceeding 100%.
            $weightSum = (float) $pdo->query(
                'SELECT COALESCE(SUM(weight), 0) FROM kpi_criteria WHERE is_active = 1'
            )->fetchColumn();
            if ($weightSum + (float) $weight > 100.00001) {
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
                'description' => $description === '' ? null : $description,
                'target_type' => $targetType,
                'weight' => number_format((float) $weight, 2, '.', ''),
            ]);
            $criterionId = (int) $pdo->lastInsertId();
            $pdo->commit();

            $this->respond(201, [
                'success' => true,
                'message' => 'Đã thêm tiêu chí KPI.',
                'criterion_id' => $criterionId,
            ]);
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Admin criterion creation failed: ' . $exception->getMessage());
            $this->respond(500, ['success' => false, 'message' => 'Không thể thêm tiêu chí KPI.']);
        } finally {
            if ($lockAcquired && $pdo instanceof PDO) {
                $pdo->query("SELECT RELEASE_LOCK('kpi_criteria_weight_guard')");
            }
        }
    }

    public function openPeriod(Request $request): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->verifyCsrf($request)) {
            $this->respond(419, ['success' => false, 'message' => 'Phiên làm việc đã hết hạn.']);
            return;
        }

        $payload = $request->json();
        $month = filter_var($payload['month'] ?? null, FILTER_VALIDATE_INT);
        $year = filter_var($payload['year'] ?? null, FILTER_VALIDATE_INT);
        if (!$month || $month < 1 || $month > 12 || !$year || $year < 2000 || $year > 2200) {
            $this->respond(400, ['success' => false, 'message' => 'Tháng hoặc năm đánh giá không hợp lệ.']);
            return;
        }

        $adminId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$adminId || $adminId < 1) {
            $this->respond(401, ['success' => false, 'message' => 'Vui lòng đăng nhập lại.']);
            return;
        }

        $timezone = new DateTimeZone('Asia/Ho_Chi_Minh');
        $periodStart = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $timezone))->format('Y-m-d');
        $periodEnd = (new DateTimeImmutable($periodStart, $timezone))->modify('last day of this month')->format('Y-m-d');
        $pdo = null;
        $lockAcquired = false;
        try {
            $pdo = Database::getInstance()->getConnection();
            if ((int) $pdo->query("SELECT GET_LOCK('kpi_period_open_guard', 5)")->fetchColumn() !== 1) {
                $this->respond(503, ['success' => false, 'message' => 'Không thể khóa thao tác mở kỳ đánh giá.']);
                return;
            }
            $lockAcquired = true;
            $pdo->beginTransaction();

            $existing = $pdo->prepare(
                'SELECT id FROM kpi_evaluations
                 WHERE period_start = :period_start AND period_end = :period_end
                 LIMIT 1'
            );
            $existing->execute(['period_start' => $periodStart, 'period_end' => $periodEnd]);
            if ($existing->fetchColumn()) {
                throw new AdminRequestException(409, 'Kỳ đánh giá này đã được mở.');
            }

            if ((int) $pdo->query('SELECT COUNT(*) FROM kpi_criteria WHERE is_active = 1')->fetchColumn() === 0) {
                throw new AdminRequestException(400, 'Cần có ít nhất một tiêu chí đang áp dụng trước khi mở kỳ.');
            }

            $createEvaluations = $pdo->prepare(
                "INSERT INTO kpi_evaluations
                    (subject_user_id, evaluator_user_id, period_start, period_end, status)
                 SELECT u.id, :admin_id, :period_start, :period_end, 'draft'
                 FROM users AS u
                 WHERE u.is_active = 1
                   AND NOT EXISTS (
                     SELECT 1
                     FROM user_roles AS ur
                     INNER JOIN roles AS r ON r.id = ur.role_id
                     WHERE ur.user_id = u.id AND LOWER(r.role_key) = 'admin'
                   )"
            );
            $createEvaluations->execute([
                'admin_id' => $adminId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);
            $evaluationCount = $createEvaluations->rowCount();
            if ($evaluationCount === 0) {
                throw new AdminRequestException(400, 'Không tìm thấy nhân sự đang hoạt động để tạo phiếu.');
            }

            $createDetails = $pdo->prepare(
                'INSERT INTO kpi_details (evaluation_id, criterion_id)
                 SELECT e.id, c.id
                 FROM kpi_evaluations AS e
                 CROSS JOIN kpi_criteria AS c
                 WHERE e.period_start = :period_start
                   AND e.period_end = :period_end
                   AND e.evaluator_user_id = :admin_id
                   AND c.is_active = 1'
            );
            $createDetails->execute([
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'admin_id' => $adminId,
            ]);
            $detailCount = $createDetails->rowCount();

            $pdo->commit();
            $this->respond(201, [
                'success' => true,
                'message' => 'Đã mở kỳ đánh giá và tạo phiếu nháp cho nhân sự.',
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'evaluations_created' => $evaluationCount,
                'details_created' => $detailCount,
            ]);
        } catch (AdminRequestException $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->respond($exception->statusCode, ['success' => false, 'message' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Admin period creation failed: ' . $exception->getMessage());
            $this->respond(500, ['success' => false, 'message' => 'Không thể mở kỳ đánh giá.']);
        } finally {
            if ($lockAcquired && $pdo instanceof PDO) {
                $pdo->query("SELECT RELEASE_LOCK('kpi_period_open_guard')");
            }
        }
    }

    private function verifyCsrf(Request $request): bool
    {
        return Csrf::verify($request->input('_token', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null));
    }

    /** @param array<string, mixed> $payload */
    private function respond(int $status, array $payload): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

final class AdminRequestException extends RuntimeException
{
    public int $statusCode;

    public function __construct(int $statusCode, string $message)
    {
        $this->statusCode = $statusCode;
        parent::__construct($message);
    }
}
