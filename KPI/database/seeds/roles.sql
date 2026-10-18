USE kpi_system;

INSERT INTO roles (role_key, name) VALUES
  ('admin', 'Quản trị viên'),
  ('evaluator', 'Người đánh giá'),
  ('official', 'Cán bộ được đánh giá'),
  ('manager', 'Manager')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO permissions (permission_key, description) VALUES
  ('users.manage', 'Quản lý tài khoản và phân quyền'),
  ('criteria.manage', 'Quản lý bộ tiêu chí KPI'),
  ('evaluations.create', 'Tạo và cập nhật phiếu đánh giá'),
  ('evaluations.review', 'Phê duyệt hoặc từ chối phiếu đánh giá'),
  ('evaluations.view_own', 'Xem phiếu đánh giá của bản thân'),
  ('reports.view', 'Xem báo cáo tổng hợp')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r CROSS JOIN permissions p
WHERE r.role_key = 'admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r CROSS JOIN permissions p
WHERE r.role_key = 'evaluator'
  AND p.permission_key IN ('evaluations.create', 'evaluations.review', 'reports.view');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r CROSS JOIN permissions p
WHERE r.role_key = 'official'
  AND p.permission_key = 'evaluations.view_own';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r CROSS JOIN permissions p
WHERE r.role_key = 'manager'
  AND p.permission_key IN ('evaluations.review', 'reports.view');
