<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Csrf;
use App\Core\View;
use App\Core\Logger;
use DateTimeImmutable;
use DateTimeZone;
use finfo;
use PDO;
use RuntimeException;
use Throwable;

final class EvaluationController
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function getPendingEvaluations(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $managerId = $this->managerId();
        $departmentId = filter_var($_SESSION['department_id'] ?? null, FILTER_VALIDATE_INT);
        if ($managerId === null || !$departmentId || $departmentId < 1) {
            $this->respond(403, ['success' => false, 'message' => 'Chỉ quản lý có phòng ban hợp lệ mới được xem danh sách này.']);
            return;
        }

        try {
            $pdo = Database::getInstance()->getConnection();
            $statement = $pdo->prepare(
                'SELECT
                    e.id AS evaluation_id,
                    e.period_start,
                    e.period_end,
                    e.submitted_at,
                    u.id AS user_id,
                    u.employee_code,
                    u.full_name,
                    u.position_title,
                    u.department_id,
                    d.id AS detail_id,
                    c.id AS criterion_id,
                    c.name AS criterion_name,
                    c.weight,
                    c.max_score,
                    d.self_score,
                    d.evidence_path AS stored_evidence_path
                 FROM kpi_evaluations AS e
                 INNER JOIN users AS u ON u.id = e.subject_user_id
                 LEFT JOIN kpi_details AS d ON d.evaluation_id = e.id
                 LEFT JOIN kpi_criteria AS c ON c.id = d.criterion_id
                 WHERE e.status = :status
                   AND u.department_id = :department_id
                   AND u.id <> :manager_id
                 ORDER BY e.submitted_at ASC, e.id ASC'
            );
            $statement->execute([
                'status' => 'submitted',
                'department_id' => $departmentId,
                'manager_id' => $managerId,
            ]);

            $evaluations = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $evaluationId = (int) $row['evaluation_id'];
                if (!isset($evaluations[$evaluationId])) {
                    $evaluations[$evaluationId] = [
                        'evaluation_id' => $evaluationId,
                        'period_start' => $row['period_start'],
                        'period_end' => $row['period_end'],
                        'submitted_at' => $row['submitted_at'],
                        'user_id' => (int) $row['user_id'],
                        'employee_code' => $row['employee_code'],
                        'full_name' => $row['full_name'],
                        'position_title' => $row['position_title'],
                        'department_id' => (int) $row['department_id'],
                        'details' => [],
                        '_weighted_score' => 0.0,
                        '_total_weight' => 0.0,
                    ];
                }

                if ($row['detail_id'] !== null) {
                    $weight = (float) $row['weight'];
                    $selfScore = $row['self_score'] === null ? null : (float) $row['self_score'];
                    $evidencePath = null;
                    if ($row['stored_evidence_path'] !== null) {
                        $basePath = defined('APP_BASE_PATH') ? APP_BASE_PATH : '';
                        $evidencePath = $basePath . '/api/evaluate/evidence?detail_id=' . (int) $row['detail_id'];
                    }

                    $evaluations[$evaluationId]['details'][] = [
                        'detail_id' => (int) $row['detail_id'],
                        'criteria_id' => (int) $row['criterion_id'],
                        'criterion_name' => $row['criterion_name'],
                        'weight' => $weight,
                        'max_score' => (float) $row['max_score'],
                        'self_score' => $selfScore,
                        'evidence_path' => $evidencePath,
                    ];

                    if ($selfScore !== null && $weight > 0) {
                        $evaluations[$evaluationId]['_weighted_score'] += $selfScore * $weight;
                        $evaluations[$evaluationId]['_total_weight'] += $weight;
                    }
                }
            }

            foreach ($evaluations as &$evaluation) {
                $evaluation['self_score'] = $evaluation['_total_weight'] > 0
                    ? round($evaluation['_weighted_score'] / $evaluation['_total_weight'], 2)
                    : null;
                unset($evaluation['_weighted_score'], $evaluation['_total_weight']);
            }
            unset($evaluation);

            $this->respond(200, ['success' => true, 'data' => array_values($evaluations)]);
        } catch (Throwable $exception) {
            error_log('Unable to load pending KPI evaluations: ' . $exception->getMessage());
            $this->respond(500, ['success' => false, 'message' => $exception->getMessage()]);
        }
    }

    public function streamEvidence(Request $request): void
    {
        $managerId = $this->managerId();
        $departmentId = filter_var($_SESSION['department_id'] ?? null, FILTER_VALIDATE_INT);
        $detailId = filter_var($request->input('detail_id'), FILTER_VALIDATE_INT);
        if ($managerId === null || !$departmentId || $departmentId < 1) {
            $this->respond(403, ['success' => false, 'message' => 'Chỉ quản lý mới được xem file minh chứng.']);
            return;
        }
        if (!$detailId || $detailId < 1) {
            $this->respond(400, ['success' => false, 'message' => 'Mã chi tiết đánh giá không hợp lệ.']);
            return;
        }

        try {
            $statement = Database::getInstance()->getConnection()->prepare(
                'SELECT d.evidence_path
                 FROM kpi_details AS d
                 INNER JOIN kpi_evaluations AS e ON e.id = d.evaluation_id
                 INNER JOIN users AS u ON u.id = e.subject_user_id
                 WHERE d.id = :detail_id
                   AND u.department_id = :department_id
                   AND u.id <> :manager_id'
            );
            $statement->execute([
                'detail_id' => $detailId,
                'department_id' => $departmentId,
                'manager_id' => $managerId,
            ]);
            $evidencePath = $statement->fetchColumn();
            if (!is_string($evidencePath) || $evidencePath === '') {
                $this->respond(404, ['success' => false, 'message' => 'Không tìm thấy file minh chứng.']);
                return;
            }

            $evidenceRoot = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'evidence');
            $candidate = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $evidencePath));
            if ($evidenceRoot === false || $candidate === false || !str_starts_with($candidate, $evidenceRoot . DIRECTORY_SEPARATOR) || !is_file($candidate)) {
                $this->respond(404, ['success' => false, 'message' => 'File minh chứng không còn tồn tại.']);
                return;
            }

            $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = $fileInfo === false ? false : finfo_file($fileInfo, $candidate);
            if ($fileInfo !== false) {
                finfo_close($fileInfo);
            }
            if (!is_string($mimeType) || !in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
                $this->respond(415, ['success' => false, 'message' => 'Định dạng file minh chứng không được hỗ trợ.']);
                return;
            }

            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: inline; filename="evidence-' . $detailId . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            readfile($candidate);
        } catch (Throwable $exception) {
            error_log('Unable to stream KPI evidence: ' . $exception->getMessage());
            $this->respond(500, ['success' => false, 'message' => $exception->getMessage()]);
        }
    }

    public function approveEvaluation(Request $request): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!Csrf::verify($request->input('_token', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
            $this->respond(419, ['success' => false, 'message' => 'Phiên duyệt đã hết hạn.']);
            return;
        }

        $managerId = $this->managerId();
        $departmentId = filter_var($_SESSION['department_id'] ?? null, FILTER_VALIDATE_INT);
        if ($managerId === null || !$departmentId || $departmentId < 1) {
            $this->respond(403, ['success' => false, 'message' => 'Chỉ quản lý có phòng ban hợp lệ mới được duyệt phiếu.']);
            return;
        }

        $payload = $request->json();
        $evaluationId = filter_var($payload['evaluation_id'] ?? null, FILTER_VALIDATE_INT);
        $scores = $payload['scores'] ?? $payload['manager_scores'] ?? null;
        if (!$evaluationId || $evaluationId < 1 || !is_array($scores) || $scores === []) {
            $this->respond(400, ['success' => false, 'message' => 'Payload cần có evaluation_id và danh sách scores.']);
            return;
        }

        $validatedScores = [];
        foreach ($scores as $item) {
            if (!is_array($item)) {
                $this->respond(400, ['success' => false, 'message' => 'Mỗi điểm cần có criteria_id và manager_score.']);
                return;
            }

            $criterionId = filter_var($item['criteria_id'] ?? null, FILTER_VALIDATE_INT);
            $scoreValue = $item['manager_score'] ?? null;
            if (!$criterionId || $criterionId < 1 || !is_numeric($scoreValue)) {
                $this->respond(400, ['success' => false, 'message' => 'Mã tiêu chí hoặc điểm quản lý không hợp lệ.']);
                return;
            }

            $score = (float) $scoreValue;
            if (!is_finite($score) || $score < 0 || $score > 100 || isset($validatedScores[$criterionId])) {
                $this->respond(400, ['success' => false, 'message' => 'Điểm phải từ 0 đến 100 và mỗi tiêu chí chỉ được gửi một lần.']);
                return;
            }

            $validatedScores[$criterionId] = $score;
        }

        try {
            $pdo = Database::getInstance()->getConnection();
        } catch (Throwable $exception) {
            error_log('KPI approval database connection failed: ' . $exception->getMessage());
            $this->respond(500, ['success' => false, 'message' => $exception->getMessage()]);
            return;
        }

        try {
            $pdo->beginTransaction();

            $evaluationStatement = $pdo->prepare(
                'SELECT e.id
                 FROM kpi_evaluations AS e
                 INNER JOIN users AS u ON u.id = e.subject_user_id
                 WHERE e.id = :evaluation_id
                   AND e.status = :status
                   AND u.department_id = :department_id
                   AND u.id <> :manager_id
                 FOR UPDATE'
            );
            $evaluationStatement->execute([
                'evaluation_id' => $evaluationId,
                'status' => 'submitted',
                'department_id' => $departmentId,
                'manager_id' => $managerId,
            ]);

            if (!$evaluationStatement->fetch(PDO::FETCH_ASSOC)) {
                $pdo->rollBack();
                $this->respond(404, ['success' => false, 'message' => 'Không tìm thấy phiếu đang chờ duyệt trong phòng ban của bạn.']);
                return;
            }

            $detailExists = $pdo->prepare(
                'SELECT id FROM kpi_details WHERE evaluation_id = :evaluation_id AND criterion_id = :criterion_id'
            );
            $updateDetail = $pdo->prepare(
                'UPDATE kpi_details
                 SET manager_score = :manager_score
                 WHERE evaluation_id = :evaluation_id AND criterion_id = :criterion_id'
            );

            foreach ($validatedScores as $criterionId => $score) {
                $detailExists->execute([
                    'evaluation_id' => $evaluationId,
                    'criterion_id' => $criterionId,
                ]);
                if (!$detailExists->fetch(PDO::FETCH_ASSOC)) {
                    throw new RuntimeException("Tiêu chí {$criterionId} không thuộc phiếu đánh giá này.");
                }

                $updateDetail->execute([
                    'manager_score' => number_format($score, 2, '.', ''),
                    'evaluation_id' => $evaluationId,
                    'criterion_id' => $criterionId,
                ]);
            }

            $missingScores = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM kpi_details
                 WHERE evaluation_id = :evaluation_id AND manager_score IS NULL'
            );
            $missingScores->execute(['evaluation_id' => $evaluationId]);
            if ((int) $missingScores->fetchColumn() > 0) {
                throw new RuntimeException('Cần chấm điểm quản lý cho tất cả tiêu chí trước khi duyệt phiếu.');
            }

            $totalStatement = $pdo->prepare(
                'SELECT ROUND(SUM(d.manager_score * c.weight) / NULLIF(SUM(c.weight), 0), 2)
                 FROM kpi_details AS d
                 INNER JOIN kpi_criteria AS c ON c.id = d.criterion_id
                 WHERE d.evaluation_id = :evaluation_id'
            );
            $totalStatement->execute(['evaluation_id' => $evaluationId]);
            $overallScore = $totalStatement->fetchColumn();
            if ($overallScore === false || $overallScore === null) {
                throw new RuntimeException('Không thể tính điểm tổng hợp cho phiếu này.');
            }

            $updateEvaluation = $pdo->prepare(
                'UPDATE kpi_evaluations
                 SET overall_score = :overall_score, status = :status, reviewed_at = NOW()
                 WHERE id = :evaluation_id'
            );
            $updateEvaluation->execute([
                'overall_score' => $overallScore,
                'status' => 'manager_approved',
                'evaluation_id' => $evaluationId,
            ]);

            $pdo->commit();
            Logger::info(
                sprintf('Quản lý %d đã duyệt phiếu KPI %d với tổng điểm %s', $managerId, $evaluationId, number_format((float) $overallScore, 2, '.', '')),
                [
                    'manager_id' => $managerId,
                    'evaluation_id' => $evaluationId,
                    'overall_score' => (float) $overallScore,
                ]
            );
            $this->respond(200, [
                'success' => true,
                'message' => 'Đã duyệt phiếu đánh giá thành công.',
                'evaluation_id' => $evaluationId,
                'overall_score' => (float) $overallScore,
            ]);
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('KPI evaluation approval SQL error: ' . $exception->getMessage());
            $this->respond(500, ['success' => false, 'message' => $exception->getMessage()]);
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->respond(400, ['success' => false, 'message' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('KPI evaluation approval failed: ' . $exception->getMessage());
            $this->respond(500, ['success' => false, 'message' => $exception->getMessage()]);
        }
    }

    private function managerId(): ?int
    {
        $roleName = strtolower(trim((string) ($_SESSION['role_name'] ?? '')));
        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);

        return $roleName === 'manager' && $userId && $userId > 0 ? (int) $userId : null;
    }

    public function showSelfEvaluationForm(): void
    {
        if (empty($_SESSION['user_id'])) {
            $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
            header('Location: ' . $basePath . '/login');
            return;
        }

        try {
            $statement = Database::getInstance()->getConnection()->query(
                'SELECT id, criterion_code, name, description, weight, max_score
                 FROM kpi_criteria
                 WHERE is_active = 1
                 ORDER BY criterion_code, id'
            );
            View::render('evaluations/self', [
                'criteria' => $statement->fetchAll(PDO::FETCH_ASSOC),
                'token' => Csrf::token(),
            ]);
        } catch (Throwable $exception) {
            error_log('Unable to render self-evaluation form: ' . $exception->getMessage());
            http_response_code(500);
            echo 'Không thể tải biểu mẫu tự đánh giá. Vui lòng thử lại sau.';
        }
    }

    public function submitSelfEvaluation(Request $request): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!Csrf::verify($request->input('_token', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
            $this->respond(419, ['success' => false, 'message' => 'Phiên gửi biểu mẫu đã hết hạn.']);
            return;
        }

        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId || $userId < 1) {
            $this->respond(401, ['success' => false, 'message' => 'Vui lòng đăng nhập để gửi tự đánh giá.']);
            return;
        }

        $scores = $request->input('self_scores', []);
        if (!is_array($scores) || $scores === []) {
            $this->respond(400, ['success' => false, 'message' => 'Vui lòng nhập ít nhất một điểm tự đánh giá.']);
            return;
        }

        try {
            $pdo = Database::getInstance()->getConnection();
        } catch (Throwable $exception) {
            error_log('KPI database connection failed: ' . $exception->getMessage());
            $this->respond(500, ['success' => false, 'message' => $exception->getMessage()]);
            return;
        }

        $movedFiles = [];

        try {
            $pdo->beginTransaction();

            $evaluationStatement = $pdo->prepare(
                "SELECT id
                 FROM kpi_evaluations
                 WHERE subject_user_id = :subject_user_id
                   AND status = 'draft'
                   AND period_start <= CURDATE() AND period_end >= CURDATE()
                 ORDER BY period_start DESC, id DESC
                 LIMIT 1 FOR UPDATE"
            );
            $evaluationStatement->execute(['subject_user_id' => $userId]);
            $evaluationId = (int) $evaluationStatement->fetchColumn();
            if ($evaluationId < 1) {
                throw new RuntimeException('Hiện không có kỳ đánh giá nào đang mở cho bạn.');
            }

            $detailStatement = $pdo->prepare(
                'UPDATE kpi_details
                 SET self_score = :self_score, evidence_path = COALESCE(:evidence_path, evidence_path)
                 WHERE evaluation_id = :evaluation_id AND criterion_id = :criterion_id'
            );
            $insertDetailStatement = $pdo->prepare(
                'INSERT INTO kpi_details (evaluation_id, criterion_id, self_score, evidence_path)
                 VALUES (:evaluation_id, :criterion_id, :self_score, :evidence_path)'
            );
            $criterionStatement = $pdo->prepare(
                'SELECT max_score FROM kpi_criteria WHERE id = :criterion_id AND is_active = 1'
            );

            $files = $_FILES['evidences'] ?? [];
            if (!is_array($files)) {
                throw new RuntimeException('Dữ liệu file minh chứng không hợp lệ.');
            }
            foreach ($scores as $criterionId => $scoreValue) {
                $criterionId = filter_var($criterionId, FILTER_VALIDATE_INT);
                if (!$criterionId || $criterionId < 1 || !is_numeric($scoreValue)) {
                    throw new RuntimeException('Mã tiêu chí hoặc điểm tự đánh giá không hợp lệ.');
                }

                $score = (float) $scoreValue;
                if (!is_finite($score) || $score < 0 || $score > 100) {
                    throw new RuntimeException('Điểm tự đánh giá phải nằm trong khoảng từ 0 đến 100.');
                }

                $criterionStatement->execute(['criterion_id' => $criterionId]);
                $criterion = $criterionStatement->fetch(PDO::FETCH_ASSOC);
                if (!$criterion) {
                    throw new RuntimeException('Tiêu chí KPI không tồn tại hoặc đã ngừng áp dụng.');
                }
                if ($score > (float) $criterion['max_score']) {
                    throw new RuntimeException('Điểm tự đánh giá vượt quá điểm tối đa của tiêu chí.');
                }

                $evidencePath = null;
                $file = $this->fileForCriterion($files, (string) $criterionId);
                if ($file !== null && $file['error'] !== UPLOAD_ERR_NO_FILE) {
                    $evidencePath = $this->storeEvidence($file, $movedFiles);
                }

                $detailStatement->execute([
                    'evaluation_id' => $evaluationId,
                    'criterion_id' => $criterionId,
                    'self_score' => number_format($score, 2, '.', ''),
                    'evidence_path' => $evidencePath,
                ]);
                if ($detailStatement->rowCount() === 0) {
                    $existsStatement = $pdo->prepare(
                        'SELECT id FROM kpi_details WHERE evaluation_id = :evaluation_id AND criterion_id = :criterion_id'
                    );
                    $existsStatement->execute(['evaluation_id' => $evaluationId, 'criterion_id' => $criterionId]);
                    if (!$existsStatement->fetchColumn()) {
                        $insertDetailStatement->execute([
                            'evaluation_id' => $evaluationId,
                            'criterion_id' => $criterionId,
                            'self_score' => number_format($score, 2, '.', ''),
                            'evidence_path' => $evidencePath,
                        ]);
                    }
                }
            }

            $submitStatement = $pdo->prepare(
                "UPDATE kpi_evaluations SET status = 'submitted', submitted_at = NOW()
                 WHERE id = :evaluation_id AND status = 'draft'"
            );
            $submitStatement->execute(['evaluation_id' => $evaluationId]);
            if ($submitStatement->rowCount() !== 1) {
                throw new RuntimeException('Phiếu đánh giá đã được gửi hoặc không còn ở trạng thái nháp.');
            }

            $pdo->commit();
            $this->respond(200, [
                'success' => true,
                'message' => 'Đã gửi phiếu tự đánh giá KPI thành công.',
                'evaluation_id' => $evaluationId,
            ]);
        } catch (RuntimeException $exception) {
            $this->rollbackAndRemoveFiles($pdo, $movedFiles);
            $this->respond(400, ['success' => false, 'message' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            $this->rollbackAndRemoveFiles($pdo, $movedFiles);
            error_log('KPI self-evaluation submission failed: ' . $exception->getMessage());
            $this->respond(500, ['success' => false, 'message' => $exception->getMessage()]);
        }
    }

    /** @param array<string, mixed> $files
     *  @return array{name:string,tmp_name:string,error:int,size:int}|null
     */
    private function fileForCriterion(array $files, string $criterionId): ?array
    {
        if (!isset($files['name']) || !is_array($files['name']) || !array_key_exists($criterionId, $files['name'])) {
            return null;
        }

        return [
            'name' => (string) ($files['name'][$criterionId] ?? ''),
            'tmp_name' => (string) ($files['tmp_name'][$criterionId] ?? ''),
            'error' => (int) ($files['error'][$criterionId] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($files['size'][$criterionId] ?? 0),
        ];
    }

    /** @param array{name:string,tmp_name:string,error:int,size:int} $file
     *  @param list<string> $movedFiles
     */
    private function storeEvidence(array $file, array &$movedFiles): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage($file['error']));
        }

        if ($file['size'] < 1 || $file['size'] > 10 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('File minh chứng không hợp lệ hoặc vượt quá giới hạn 10 MB.');
        }

        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($fileInfo === false) {
            throw new RuntimeException('Không thể kiểm tra loại file minh chứng trên máy chủ.');
        }

        $mimeType = finfo_file($fileInfo, $file['tmp_name']);
        finfo_close($fileInfo);
        if (!is_string($mimeType) || !isset(self::ALLOWED_MIME_TYPES[$mimeType])) {
            throw new RuntimeException('Chỉ chấp nhận file PDF, JPEG hoặc PNG làm minh chứng.');
        }

        $storageDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'evidence';
        if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0750, true) && !is_dir($storageDirectory)) {
            throw new RuntimeException('Không thể tạo thư mục lưu file minh chứng.');
        }

        $filename = date('YmdHis') . '_' . uniqid('', true) . '.' . self::ALLOWED_MIME_TYPES[$mimeType];
        $destination = $storageDirectory . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Không thể lưu file minh chứng đã tải lên.');
        }

        $movedFiles[] = $destination;

        return 'storage/evidence/' . $filename;
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File minh chứng vượt quá giới hạn dung lượng cho phép.',
            UPLOAD_ERR_PARTIAL => 'File minh chứng chỉ được tải lên một phần.',
            UPLOAD_ERR_NO_TMP_DIR => 'Máy chủ chưa cấu hình thư mục tạm để nhận file.',
            UPLOAD_ERR_CANT_WRITE => 'Máy chủ không thể ghi file minh chứng.',
            UPLOAD_ERR_EXTENSION => 'Một tiện ích PHP đã dừng việc tải file.',
            default => 'Lỗi khi tải file minh chứng lên.',
        };
    }

    /** @param list<string> $movedFiles */
    private function rollbackAndRemoveFiles(PDO $pdo, array $movedFiles): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        foreach ($movedFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
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
