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
  <meta name="csrf-token" content="<?= $e($token) ?>">
  <title>Duyệt KPI · KPI Portal</title>
  <style>
    *{box-sizing:border-box}body{margin:0;background:#f3f6fb;color:#18243a;font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}.top{height:68px;background:#fff;border-bottom:1px solid #e5eaf1;display:flex;align-items:center;justify-content:space-between;padding:0 max(24px,calc((100% - 1100px)/2))}.brand{font-size:21px;font-weight:750}.brand span,a{color:#456bd1}.main{max-width:1100px;margin:38px auto;padding:0 24px}.muted,small{display:block;color:#6f7b8e}.panel{overflow:auto;background:#fff;border:1px solid #e4e9f1;border-radius:14px}.table{width:100%;border-collapse:collapse;min-width:650px}.table th,.table td{padding:15px 18px;text-align:left;border-bottom:1px solid #edf0f5}.table th{font-size:13px;color:#68758a;background:#fafbfd}.table tr:last-child td{border-bottom:0}.button{padding:9px 14px;border:0;border-radius:8px;background:#355fc4;color:white;font:inherit;font-weight:700;cursor:pointer}.button:disabled{opacity:.65;cursor:wait}.modal-backdrop{position:fixed;inset:0;z-index:10;display:grid;place-items:center;padding:20px;background:#14203980}.modal-backdrop[hidden]{display:none}.modal{width:min(100%,760px);max-height:90vh;overflow:auto;background:white;border-radius:15px;padding:24px}.modal-header{display:flex;justify-content:space-between;align-items:center}.approval-detail{display:grid;grid-template-columns:1fr 180px;gap:20px;align-items:center;padding:17px 0;border-bottom:1px solid #edf0f5}.approval-detail small{margin:5px 0}.approval-detail label{font-size:13px;font-weight:650}.approval-detail input{display:block;width:100%;margin-top:5px;padding:10px;border:1px solid #d7deea;border-radius:8px;font:inherit}.approval-error,[data-approval-status]{color:#a32929}.modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}.secondary{padding:9px 14px;border:1px solid #d7deea;border-radius:8px;background:white;font:inherit;cursor:pointer}.toast{position:fixed;right:24px;bottom:24px;padding:12px 16px;background:#20375c;color:white;border-radius:8px;opacity:0;transition:opacity .2s}.toast.visible{opacity:1}@media(max-width:620px){.approval-detail{grid-template-columns:1fr;gap:8px}}
  </style>
</head>
<body data-approval-api="<?= $e($basePath) ?>/api/evaluate">
  <header class="top"><div class="brand">KPI<span>Portal</span></div><a href="<?= $e($basePath) ?>/dashboard">Quay lại tổng quan</a></header>
  <main class="main">
    <h1>Phiếu KPI chờ duyệt</h1>
    <p class="muted">Danh sách chỉ gồm cán bộ thuộc phòng ban của bạn.</p>
    <section class="panel">
      <table class="table">
        <thead><tr><th>Cán bộ</th><th>Kỳ đánh giá</th><th>Điểm tự chấm</th><th>Thao tác</th></tr></thead>
        <tbody id="pending-evaluations-body"><tr><td colspan="4">Đang tải danh sách...</td></tr></tbody>
      </table>
    </section>
  </main>
  <div class="modal-backdrop" id="approval-modal" hidden>
    <section class="modal" role="dialog" aria-modal="true" aria-labelledby="approval-title">
      <div class="modal-header"><h2 id="approval-title">Thẩm định phiếu KPI</h2><button type="button" class="secondary" data-close-approval aria-label="Đóng">Đóng</button></div>
      <form id="approval-form">
        <div id="approval-details"></div>
        <p data-approval-status role="alert" hidden></p>
        <div class="modal-actions"><button type="button" class="secondary" data-close-approval>Hủy</button><button type="submit" class="button">Xác nhận duyệt</button></div>
      </form>
    </section>
  </div>
  <div id="toast" class="toast" role="status" aria-live="polite"></div>
  <script src="<?= $e($basePath) ?>/app.js" defer></script>
</body>
</html>
