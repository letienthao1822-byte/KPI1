<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Logger;
use PDO;
use Throwable;

final class FileController
{
    private const ALLOWED_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function viewEvidence(int $evidenceId): void
    {
        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId || $userId < 1) {
            $this->respondError(401, 'Vui lòng đăng nhập để xem file minh chứng.');
            return;
        }
        if ($evidenceId < 1) {
            $this->respondError(400, 'Mã file minh chứng không hợp lệ.');
            return;
        }

        try {
            $statement = Database::getInstance()->getConnection()->prepare(
                'SELECT d.evidence_path, e.subject_user_id AS owner_user_id, e.status
                 FROM kpi_details AS d
                 INNER JOIN kpi_evaluations AS e ON e.id = d.evaluation_id
                 WHERE d.id = :evidence_id
                 LIMIT 1'
            );
            $statement->execute(['evidence_id' => $evidenceId]);
            $evidence = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$evidence || !is_string($evidence['evidence_path']) || $evidence['evidence_path'] === '') {
                $this->respondError(404, 'Không tìm thấy file minh chứng.');
                return;
            }

            $roleName = strtolower(trim((string) ($_SESSION['role_name'] ?? '')));
            $roleKey = strtolower(trim((string) ($_SESSION['role_key'] ?? '')));
            $isPrivileged = in_array($roleName, ['manager', 'admin', 'quản trị viên'], true)
                || in_array($roleKey, ['manager', 'admin'], true);
            if ((int) $evidence['owner_user_id'] !== (int) $userId && !$isPrivileged) {
                $this->respondError(403, 'Bạn không có quyền xem file minh chứng này.');
                return;
            }

            $storageRoot = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'evidence');
            $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $evidence['evidence_path']);
            $fullFilePath = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $relativePath);
            if ($storageRoot === false || $fullFilePath === false
                || !str_starts_with($fullFilePath, $storageRoot . DIRECTORY_SEPARATOR)
                || !is_file($fullFilePath) || !is_readable($fullFilePath)) {
                $this->respondError(404, 'File minh chứng không tồn tại.');
                return;
            }

            $mimeType = mime_content_type($fullFilePath);
            if (!is_string($mimeType) || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
                $this->respondError(415, 'Định dạng file minh chứng không được hỗ trợ.');
                return;
            }

            $fileSize = filesize($fullFilePath);
            if ($fileSize === false) {
                $this->respondError(500, 'Không thể đọc file minh chứng.');
                return;
            }

            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . $fileSize);
            header('Content-Disposition: inline; filename="evidence-' . $evidenceId . '.' . $this->extensionFor($mimeType) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            readfile($fullFilePath);
        } catch (Throwable $exception) {
            Logger::error('Unable to deliver KPI evidence', $exception);
            $this->respondError(500, 'Không thể tải file minh chứng.');
        }
    }

    private function extensionFor(string $mimeType): string
    {
        return match ($mimeType) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        };
    }

    private function respondError(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
