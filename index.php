<?php
require_once __DIR__ . '/monitor.php';
require_once __DIR__ . '/auth.php';

$saloons = SALOONS;
$dashboardUrl = base_url() . 'dashboard.php';
$admin = auth_check() ? auth_user() : null;
$adminUrl = base_url() . 'admin.php';
$loginUrl = base_url() . 'login.php?next=admin.php';

// بازه مطلوب هر فیلد برای هر سالن (از پنل ادمین)
$ranges = [];
foreach (array_keys(FIELDS) as $field) {
    for ($i = 1; $i <= $saloons; $i++) {
        $ranges[$field][$i] = saloon_range($field, $i);
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>دمای مطلوب سالن‌ها</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="style.css" />
</head>
<body class="entry-page">
    <nav class="navbar app-navbar">
        <div class="container-fluid px-lg-4">
            <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
                <span class="brand-icon"><i class="bi bi-bullseye"></i></span>
                <span>دمای مطلوب سالن‌ها</span>
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
                <h1 class="page-title mb-1">دمای مطلوب هر <?= fa_num($saloons) ?> سالن</h1>
                <p class="text-muted small mb-0">
                    این صفحه فقط برای دیدن و تعیین محدوده مطلوب است. مقادیر واقعی دما به‌صورت خودکار از دماسنج‌ها
                    دریافت می‌شود و نیازی به وارد کردن دستی نیست.
                </p>
            </div>
            <?php if ($admin): ?>
                <a href="<?= htmlspecialchars($adminUrl) ?>" class="btn btn-sm btn-brand">
                    <i class="bi bi-pencil-square"></i> ویرایش در پنل ادمین
                </a>
            <?php endif; ?>
        </div>

        <div class="alert alert-secondary py-2 small d-flex align-items-center gap-2" role="note">
            <i class="bi bi-info-circle"></i>
            <span>
                تا اتصال دماسنج‌ها، داده‌ای برای نمایش وجود ندارد. محدوده‌های زیر «دمای مطلوب» هر سالن هستند
                و داشبورد خوانش‌های دریافتی را با همین محدوده‌ها مقایسه می‌کند.
            </span>
        </div>

        <!-- خلاصه دماهای مطلوب به تفکیک فیلد -->
        <div class="row g-3 mb-4">
            <?php foreach (FIELDS as $field => $meta): ?>
                <div class="col-12 col-lg-4">
                    <div class="panel h-100">
                        <div class="panel-head">
                            <h2 class="panel-title">
                                <i class="bi bi-thermometer-half"></i>
                                <?= htmlspecialchars($meta['label']) ?>
                                <small class="text-muted fw-normal">(<?= htmlspecialchars($meta['unit']) ?>)</small>
                            </h2>
                        </div>
                        <div class="panel-body py-2 small text-muted">
                            مقدار پیش‌فرض مطلوب:
                            <b><?= fa_num($meta['good'][0]) ?> – <?= fa_num($meta['good'][1]) ?></b>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- جدول فقط‌خواندنی دمای مطلوب هر سالن -->
        <div class="panel">
            <div class="panel-head">
                <h2 class="panel-title"><i class="bi bi-grid-3x3-gap"></i> محدوده مطلوب <?= fa_num($saloons) ?> سالن</h2>
                <span class="small text-muted">فقط مشاهده — برای تغییر، وارد پنل ادمین شوید</span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 entry-table">
                    <thead>
                        <tr>
                            <th style="width:90px">سالن</th>
                            <?php foreach (FIELDS as $field => $meta): ?>
                                <th>
                                    <?= htmlspecialchars($meta['label']) ?>
                                    <small class="text-muted d-block"><?= htmlspecialchars($meta['unit']) ?></small>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($i = 1; $i <= $saloons; $i++): ?>
                            <tr>
                                <td class="fw-semibold"><?= saloon_name($i) ?></td>
                                <?php foreach (FIELDS as $field => $meta): ?>
                                    <?php
                                    [$low, $high] = $ranges[$field][$i];
                                    $isCustom = $low !== (int) $meta['good'][0] || $high !== (int) $meta['good'][1];
                                    ?>
                                    <td>
                                        <div class="range-readonly">
                                            <span class="fw-semibold"><?= fa_num($low) ?> – <?= fa_num($high) ?></span>
                                            <span class="text-muted"><?= htmlspecialchars($meta['unit']) ?></span>
                                            <?php if ($isCustom): ?>
                                                <span class="badge text-bg-warning">سفارشی</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <div class="panel-foot d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="small text-muted">
                    <i class="bi bi-snow2"></i> خوانش واقعی توسط دماسنج‌ها دریافت و در داشبورد نمایش داده می‌شود.
                </span>
                <a href="<?= htmlspecialchars($dashboardUrl) ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-speedometer2"></i> رفتن به داشبورد
                </a>
            </div>
        </div>

        <footer class="text-center text-muted small py-4">
            مانیتورینگ سالن‌های پرورش قارچ • PHP + MySQL + Bootstrap
        </footer>
    </main>
</body>
</html>
