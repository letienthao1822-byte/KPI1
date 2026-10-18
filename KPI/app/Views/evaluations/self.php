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
  <title>Tự đánh giá KPI · KPI Portal</title>
  <style>
    *{box-sizing:border-box}body{margin:0;background:#f3f6fb;color:#18243a;font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}.top{height:68px;background:#fff;border-bottom:1px solid #e5eaf1;display:flex;align-items:center;justify-content:space-between;padding:0 max(24px,calc((100% - 1000px)/2))}.brand{font-size:21px;font-weight:750}.brand span,a{color:#456bd1}.main{max-width:1000px;margin:38px auto;padding:0 24px}.muted{color:#6f7b8e}.card{background:#fff;border:1px solid #e4e9f1;border-radius:14px;padding:22px;margin:16px 0}.card h2{font-size:18px;margin:0 0 6px}.score-row{display:grid;grid-template-columns:1fr 170px;gap:20px;align-items:center}.score-row label,.upload{font-size:14px;font-weight:650}.input{width:100%;margin-top:7px;padding:11px;border:1px solid #d7deea;border-radius:8px;font:inherit}.button{padding:12px 18px;border:0;border-radius:9px;background:#355fc4;color:white;font:inherit;font-weight:700;cursor:pointer}.button:disabled{opacity:.65;cursor:wait}.notice{color:#a32929}.toast{position:fixed;right:24px;bottom:24px;padding:12px 16px;background:#20375c;color:white;border-radius:8px;opacity:0;transition:opacity .2s}.toast.visible{opacity:1}@media(max-width:620px){.score-row{grid-template-columns:1fr;gap:10px}}
  </style>
</head>
<body>
  <header class="top"><div class="brand">KPI<span>Portal</span></div><a href="<?= $e($basePath) ?>/dashboard">Quay lại tổng quan</a></header>
  <main class="main">
    <h1>Tự đánh giá KPI</h1>
    <p class="muted">Nhập điểm cho từng tiêu chí trong kỳ hiện tại. Có thể đính kèm minh chứng PDF, JPG hoặc PNG (tối đa 10 MB mỗi tệp).</p>
    <?php if ($criteria === []): ?>
      <div class="card">Hiện chưa có tiêu chí KPI nào đang áp dụng. Vui lòng liên hệ quản trị viên.</div>
    <?php else: ?>
      <form id="self-evaluation-form" data-self-evaluation-form data-api-url="<?= $e($basePath) ?>/api/evaluate/submit" method="post" enctype="multipart/form-data">
        <input type="hidden" name="_token" value="<?= $e($token) ?>">
        <?php foreach ($criteria as $criterion): ?>
          <?php $criterionId = (int) $criterion['id']; $maxScore = min(100, (float) $criterion['max_score']); ?>
          <section class="card">
            <h2><?= $e($criterion['name']) ?></h2>
            <p class="muted"><?= $e($criterion['description'] ?? '') ?><?= $criterion['description'] ? ' · ' : '' ?>Trọng số <?= $e($criterion['weight']) ?>%</p>
            <div class="score-row">
              <label>Điểm tự đánh giá (0–<?= $e($maxScore) ?>)
                <input class="input" type="number" name="self_scores[<?= $criterionId ?>]" data-self-score data-criteria-id="<?= $criterionId ?>" min="0" max="<?= $e($maxScore) ?>" step="0.01" required>
              </label>
              <label class="upload">File minh chứng (không bắt buộc)
                <input class="input" type="file" name="evidences[<?= $criterionId ?>]" data-evidence data-criteria-id="<?= $criterionId ?>" accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png">
              </label>
            </div>
          </section>
        <?php endforeach; ?>
        <p data-form-status class="notice" role="status" hidden></p>
        <button class="button" type="submit">Gửi tự đánh giá</button>
      </form>
    <?php endif; ?>
  </main>
  <div id="toast" class="toast" role="status" aria-live="polite"></div>
  <script src="<?= $e($basePath) ?>/app.js" defer></script>
</body>
</html>
