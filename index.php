<?php
require_once __DIR__ . '/monitor.php';
require_once __DIR__ . '/auth.php';

$saloons = SALOONS;
$dashboardUrl = base_url() . 'dashboard.php';
$admin = auth_check() ? auth_user() : null;
$adminUrl = base_url() . 'admin.php';
$loginUrl = base_url() . 'login.php?next=admin.php';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>ثبت خوانش دمای سالن‌ها</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="style.css" />
</head>
<body class="entry-page">
    <nav class="navbar app-navbar">
        <div class="container-fluid px-lg-4">
            <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
                <span class="brand-icon"><i class="bi bi-pencil-square"></i></span>
                <span>ثبت خوانش سالن‌ها</span>
            </a>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <a href="<?= htmlspecialchars($dashboardUrl) ?>" class="btn btn-sm btn-light">
                    <i class="bi bi-speedometer2"></i> داشبورد
                </a>
                <?php if ($admin): ?>
                    <a href="<?= htmlspecialchars($adminUrl) ?>" class="btn btn-sm btn-warning fw-semibold">
                        <i class="bi bi-sliders"></i> پنل ادمین
                    </a>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($loginUrl) ?>" class="btn btn-sm btn-outline-light">
                        <i class="bi bi-shield-lock"></i> ورود ادمین
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <main class="container-fluid px-lg-4 py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
            <div>
                <h1 class="page-title mb-1">ثبت خوانش جدید</h1>
                <p class="text-muted small mb-0">
                    برای هر <?= fa_num($saloons) ?> سالن، دماها و رطوبت را وارد کنید. یک سطر در جدول <code>temps</code> ثبت می‌شود.
                </p>
            </div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="demoToggle" />
                <label class="form-check-label small" for="demoToggle">پر کردن با مقادیر نمونه</label>
            </div>
        </div>

        <form id="readingForm" novalidate autocomplete="off">
            <div class="panel mb-4">
                <div class="panel-head">
                    <h2 class="panel-title"><i class="bi bi-sliders"></i> مقدار سریع برای همه سالن‌ها</h2>
                    <span class="small text-muted">ابتدا حجم کلی را وارد کنید، سپس در صورت نیاز هر سالن را اصلاح کنید.</span>
                </div>
                <div class="panel-body">
                    <div class="row g-3" id="bulkRow">
                        <?php foreach (FIELDS as $field => $meta): ?>
                            <div class="col-12 col-md-4">
                                <label class="form-label small fw-semibold">
                                    <?= htmlspecialchars($meta['label']) ?> (<?= htmlspecialchars($meta['unit']) ?>)
                                    <span class="text-muted fw-normal">مطلوب: <?= fa_num($meta['good'][0]) ?>–<?= fa_num($meta['good'][1]) ?></span>
                                </label>
                                <div class="input-group">
                                    <input type="number" class="form-control bulk-input" data-field="<?= htmlspecialchars($field) ?>"
                                           min="<?= (int) $meta['min'] ?>" max="<?= (int) $meta['max'] ?>" step="1"
                                           placeholder="<?= fa_num((int) round(($meta['good'][0] + $meta['good'][1]) / 2)) ?>" />
                                    <button class="btn btn-outline-secondary" type="button" data-apply="<?= htmlspecialchars($field) ?>">
                                        اعمال
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="col-12">
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-primary" id="btnApplyAll">اعمال همه</button>
                                <button type="button" class="btn btn-outline-secondary" id="btnClearAll">پاک کردن فرم</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <h2 class="panel-title"><i class="bi bi-grid-3x3-gap"></i> مقادیر <?= fa_num($saloons) ?> سالن</h2>
                    <span class="small text-muted">
                        <span class="legend-dot ok"></span> مطلوب
                        <span class="legend-dot bad"></span> خارج از محدوده
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 entry-table">
                        <thead>
                            <tr>
                                <th style="width:90px">سالن</th>
                                <?php foreach (FIELDS as $field => $meta): ?>
                                    <th>
                                        <?= htmlspecialchars($meta['label']) ?>
                                        <small class="text-muted d-block">
                                            <?= htmlspecialchars($meta['unit']) ?> • <?= fa_num($meta['min']) ?> تا <?= fa_num($meta['max']) ?>
                                        </small>
                                    </th>
                                <?php endforeach; ?>
                                <th style="width:60px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($i = 1; $i <= $saloons; $i++): ?>
                                <tr>
                                    <td class="fw-semibold">سالن <?= fa_num($i) ?></td>
                                    <?php foreach (FIELDS as $field => $meta): ?>
                                        <td>
                                            <input type="number" class="form-control form-control-sm saloon-input"
                                                   name="<?= htmlspecialchars($field) ?>[]"
                                                   data-field="<?= htmlspecialchars($field) ?>"
                                                   data-good-min="<?= (int) $meta['good'][0] ?>"
                                                   data-good-max="<?= (int) $meta['good'][1] ?>"
                                                   min="<?= (int) $meta['min'] ?>" max="<?= (int) $meta['max'] ?>" step="1"
                                                   aria-label="<?= htmlspecialchars($meta['label']) ?> سالن <?= $i ?>" />
                                        </td>
                                    <?php endforeach; ?>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-light row-fill" data-saloon="<?= $i ?>" title="پر کردن این سالن با مقادیر نمونه">
                                            <i class="bi bi-magic"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
                <div class="panel-foot d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span class="small text-muted" id="formHint">مقادیر باید عدد صحیح باشند.</span>
                    <div class="d-flex gap-2">
                        <button type="reset" class="btn btn-outline-secondary">بازنشانی</button>
                        <button type="submit" class="btn btn-brand px-4">
                            <i class="bi bi-check2-circle"></i> ثبت خوانش در دیتابیس
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <footer class="text-center text-muted small py-4">
            مانیتورینگ سالن‌های پرورش قارچ • PHP + MySQL + jQuery + Bootstrap
        </footer>
    </main>

    <div class="toast-container position-fixed bottom-0 start-0 p-3" id="toastArea"></div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.APP = {
            apiUrl: <?= json_encode(base_url() . 'ajax.php') ?>,
            saloons: <?= (int) $saloons ?>,
            fields: <?= json_encode(FIELDS, JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
    <script src="js/entry.js"></script>
</body>
</html>
