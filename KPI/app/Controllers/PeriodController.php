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

final class PeriodController
{
    public function openNewPeriod(Request $request): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!Csrf::verify($request->input('_token', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
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

        $start = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d');
        $end = (new DateTimeImmutable($start, new DateTimeZone('Asia/Ho_Chi_Minh')))->modify('last day of this month')->format('Y-m-d');
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

            $existing = $pdo->prepare('SELECT id FROM kpi_evaluations WHERE period_start = :start AND period_end = :end LIMIT 1');
            $existing->execute(['start' => $start, 'end' => $end]);
            if ($existing->fetchColumn()) {
                throw new PeriodRequestException(409, 'Kỳ đánh giá này đã được mở.');
            }
            if ((int) $pdo->query('SELECT COUNT(*) FROM kpi_criteria WHERE is_active = 1')->fetchColumn() < 1) {
                throw new PeriodRequestException(400, 'Cần có ít nhất một tiêu chí đang áp dụng trước khi mở kỳ.');
            }

            $createEvaluations = $pdo->prepare(
                "INSERT INTO kpi_evaluations (subject_user_id, evaluator_user_id, period_start, period_end, status)
                 SELECT u.id, :admin_id, :period_start, :period_end, 'draft'
                 FROM users AS u
                 WHERE u.is_active = 1
                   AND NOT EXISTS (
                     SELECT 1 FROM user_roles AS ur
                     INNER JOIN roles AS r ON r.id = ur.role_id
                     WHERE ur.user_id = u.id AND LOWER(r.role_key) = 'admin'
                   )"
            );
            $createEvaluations->execute(['admin_id' => $adminId, 'period_start' => $start, 'period_end' => $end]);
            $evaluationCount = $createEvaluations->rowCount();
            if ($evaluationCount < 1) {
                throw new PeriodRequestException(400, 'Không tìm thấy cán bộ đang hoạt động để tạo phiếu.');
            }

            $createDetails = $pdo->prepare(
                'INSERT INTO kpi_details (evaluation_id, criterion_id)
                 SELECT e.id, c.id
                 FROM kpi_evaluations AS e
                 INNER JOIN kpi_criteria AS c ON c.is_active = 1
                 WHERE e.period_start = :period_start AND e.period_end = :period_end AND e.evaluator_user_id = :admin_id'
            );
            $createDetails->execute(['period_start' => $start, 'period_end' => $end, 'admin_id' => $adminId]);
            $detailCount = $createDetails->rowCount();

            $pdo->commit();
            $this->respond(201, [
                'success' => true,
                'message' => 'Đã mở kỳ đánh giá và tạo phiếu nháp cho cán bộ.',
                'period_start' => $start,
                'period_end' => $end,
                'evaluations_created' => $evaluationCount,
                'details_created' => $detailCount,
            ]);
        } catch (PeriodRequestException $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->respond($exception->statusCode, ['success' => false, 'message' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Opening KPI period failed: ' . $exception->getMessage());
            $this->respond(500, ['success' => false, 'message' => 'Không thể mở kỳ đánh giá.']);
        } finally {
            if ($lockAcquired && $pdo instanceof PDO) {
                $pdo->query("SELECT RELEASE_LOCK('kpi_period_open_guard')");
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

final class PeriodRequestException extends RuntimeException
{
    public int $statusCode;

    public function __construct(int $statusCode, string $message)
    {
        $this->statusCode = $statusCode;
        parent::__construct($message);
    }
}
