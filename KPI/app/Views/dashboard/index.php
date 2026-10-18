<?php
declare(strict_types=1);
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= $e(\App\Core\Csrf::token()) ?>">
  <title>Tổng quan · KPI Portal</title>
  <style>
    *{box-sizing:border-box}body{margin:0;background:#f3f6fb;color:#18243a;font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}.top{height:68px;background:#fff;border-bottom:1px solid #e5eaf1;display:flex;align-items:center;justify-content:space-between;padding:0 max(24px,calc((100% - 1100px)/2))}.brand{font-size:21px;font-weight:750}.brand span{color:#456bd1}.logout{color:#456bd1;text-decoration:none;font-weight:650}.main{max-width:1100px;margin:48px auto;padding:0 24px}.eyebrow{color:#456bd1;font-size:13px;font-weight:750;text-transform:uppercase;letter-spacing:.08em}.panel{margin-top:24px;padding:28px;background:white;border:1px solid #e4e9f1;border-radius:15px}.muted{color:#6f7b8e}
  </style>
</head>
<body data-auth-guard="true">
  <header class="top"><div class="brand">KPI<span>Portal</span></div><form method="post" action="<?= $e($basePath) ?>/logout"><input type="hidden" name="_token" value="<?= $e(\App\Core\Csrf::token()) ?>"><button class="logout" style="border:0;background:none;font:inherit;cursor:pointer" type="submit">Đăng xuất</button></form></header>
  <main class="main">
    <div class="eyebrow">Không gian làm việc</div>
    <h1>Xin chào, <?= $e($_SESSION['full_name'] ?? 'cán bộ') ?></h1>
    <p class="muted">Bạn đã đăng nhập thành công vào hệ thống đánh giá KPI.</p>
    <section class="panel">
      <h2>Thông tin phiên đăng nhập</h2>
      <p><strong>Vai trò:</strong> <?= $e($roleName) ?></p>
      <p><strong>Mã đơn vị:</strong> <?= $departmentId === null ? 'Chưa gán' : $e($departmentId) ?></p>
      <p class="muted">Các màn hình quản lý tiêu chí, cán bộ và phiếu đánh giá sẽ được xây dựng trên nền MVC này.</p>
      <p><a class="logout" href="<?= $e($basePath) ?>/evaluations/self">Mở biểu mẫu tự đánh giá KPI</a></p>
      <?php if ($roleName === 'Manager'): ?><p><a class="logout" href="<?= $e($basePath) ?>/evaluations/manager">Mở danh sách duyệt KPI</a></p><?php endif; ?>
    </section>
  </main>
  <script src="<?= $e($basePath) ?>/public/assets/js/auth.js" defer></script>
</body>
</html>
