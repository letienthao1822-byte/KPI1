<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Logger;
use PDO;
use RuntimeException;
use Throwable;

final class ReportController
{
    public function exportKpiReport(): void
    {
        $roleKey = strtolower(trim((string) ($_SESSION['role_key'] ?? '')));
        $roleName = strtolower(trim((string) ($_SESSION['role_name'] ?? '')));
        $isAdmin = $roleKey === 'admin' || in_array($roleName, ['admin', 'quản trị viên'], true);

        if (empty($_SESSION['user_id']) || !$isAdmin) {
            http_response_code(empty($_SESSION['user_id']) ? 401 : 403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => empty($_SESSION['user_id'])
                    ? 'Vui lòng đăng nhập để xuất báo cáo.'
                    : 'Bạn không có quyền xuất báo cáo KPI.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        try {
            $statement = Database::getInstance()->getConnection()->prepare(
                'SELECT
                    u.employee_code,
                    u.full_name,
                    d.name AS department_name,
                    e.period_start,
                    e.period_end,
                    ROUND(SUM(kd.manager_score * c.weight) / NULLIF(SUM(c.weight), 0), 2) AS overall_score
                 FROM kpi_evaluations AS e
                 INNER JOIN users AS u ON u.id = e.subject_user_id
                 LEFT JOIN departments AS d ON d.id = u.department_id
                 INNER JOIN kpi_details AS kd ON kd.evaluation_id = e.id
                 INNER JOIN kpi_criteria AS c ON c.id = kd.criterion_id
                 WHERE e.status = :status
                 GROUP BY e.id, u.employee_code, u.full_name, d.name, e.period_start, e.period_end
                 ORDER BY e.period_start DESC, d.name ASC, u.full_name ASC'
            );
            $statement->execute(['status' => 'manager_approved']);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            $timestamp = date('Ymd_His');
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="Bao_Cao_KPI_' . $timestamp . '.csv"');
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');

            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new RuntimeException('Unable to open the report output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Mã cán bộ',
                'Tên cán bộ',
                'Phòng ban',
                'Từ ngày',
                'Đến ngày',
                'Tổng điểm quản lý chấm',
                'Xếp loại',
            ], ',', '"', '\\');

            foreach ($rows as $row) {
                $score = (float) $row['overall_score'];
                fputcsv($output, [
                    $this->protectCsvText($row['employee_code']),
                    $this->protectCsvText($row['full_name']),
                    $this->protectCsvText($row['department_name'] ?? ''),
                    $row['period_start'],
                    $row['period_end'],
                    number_format($score, 2, '.', ''),
                    $this->calculateGrade($score),
                ], ',', '"', '\\');
            }

            fclose($output);
        } catch (Throwable $exception) {
            Logger::error('KPI report export failed', $exception);
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => 'Không thể xuất báo cáo KPI.',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
    }

    private function calculateGrade(float $score): string
    {
        if ($score >= 90) {
            return 'Xuất sắc';
        }
        if ($score >= 70) {
            return 'Tốt';
        }
        if ($score >= 50) {
            return 'Khá';
        }

        return 'Kém';
    }

    private function protectCsvText(mixed $value): string
    {
        $text = (string) $value;
        return preg_match('/^[\x00-\x20]*[=+\-@]/u', $text) === 1 ? "'" . $text : $text;
    }
}
