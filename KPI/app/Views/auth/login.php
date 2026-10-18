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
  <title>Đăng nhập · KPI Portal</title>
  <style>
    *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f3f6fb;color:#18243a;font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}.card{width:min(100% - 32px,440px);padding:38px;background:#fff;border:1px solid #e4e9f1;border-radius:18px;box-shadow:0 18px 55px #1b355012}.brand{font-size:22px;font-weight:750;letter-spacing:-.5px}.brand span{color:#456bd1}.sub{color:#69758a;margin:8px 0 26px}.field{display:block;margin:18px 0 6px;font-size:14px;font-weight:650}.input{width:100%;padding:12px 13px;border:1px solid #d7deea;border-radius:9px;font:inherit}.input:focus{outline:3px solid #456bd126;border-color:#456bd1}.button{width:100%;margin-top:22px;padding:12px;border:0;border-radius:9px;background:#355fc4;color:white;font:inherit;font-weight:700;cursor:pointer}.error{padding:11px 13px;border-radius:8px;background:#fff0f0;color:#a32929}.hint{margin-top:20px;color:#7a8496;font-size:13px}.demo{display:block;margin-top:10px;color:#456bd1;text-align:center;text-decoration:none;font-size:14px}
  </style>
</head>
<body>
  <main class="card">
    <div class="brand">KPI<span>Portal</span></div>
    <p class="sub">Đăng nhập để tiếp tục vào hệ thống đánh giá KPI.</p>
    <?php if (!empty($error)): ?><p class="error" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <form method="post" action="<?= $e($basePath) ?>/login" autocomplete="on">
      <input type="hidden" name="_token" value="<?= $e($token) ?>">
      <label class="field" for="email">Email</label>
      <input class="input" id="email" name="email" type="email" autocomplete="username" required autofocus>
      <label class="field" for="password">Mật khẩu</label>
      <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
      <button class="button" type="submit">Đăng nhập</button>
    </form>
    <p class="hint">Tài khoản được quản trị viên cấp. Nếu chưa có tài khoản, vui lòng liên hệ đơn vị quản trị.</p>
    <a class="demo" href="<?= $e($basePath) ?>/index.html">Mở bản demo giao diện</a>
  </main>
</body>
</html>
