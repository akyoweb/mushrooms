<?php
require_once __DIR__ . '/monitor.php';

$saloons = SALOONS;
$dashboardUrl = base_url() . 'dashboard.php';
$entryUrl = base_url() . 'index.php';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>پایش سالن‌های پرورش قارچ</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="style.css" />
</head>

<body>
    <nav class="navbar navbar-expand-lg app-navbar sticky-top">
        <div class="container-fluid px-lg-4">
            <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
                <span class="brand-icon"><i class="bi bi-thermometer-half"></i></span>
                <span>پایش سالن‌های قارچ</span>
            </a>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <button type="button" id="btnRefresh" class="btn btn-sm btn-light">
                    <i class="bi bi-arrow-clockwise"></i> بازخوانی
                </button>
                <a href="<?= htmlspecialchars($entryUrl) ?>" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-plus-lg"></i> ثبت خوانش
                </a>
            </div>
        </div>
    </nav>

    <main class="container-fluid px-lg-4 py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
            <div>
                <h1 class="page-title mb-1">داشبورد مانیتورینگ</h1>
                <p class="text-muted small mb-0">
                    آخرین به‌روزرسانی:
                    <span id="updatedAt" class="fw-semibold">—</span>
                    <span class="mx-2">|</span>
                    ساختار داده:
                    <span id="layoutBadge" class="badge text-bg-secondary">—</span>
                    <span class="mx-2">|</span>
                    تعداد خوانش‌ها: <span id="readingsCount" class="fw-semibold">0</span>
                </p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" id="btnSimulate" class="btn btn-outline-primary btn-sm"
                    title="فقط برای تست بدون سنسور">
                    <i class="bi bi-cpu"></i> ثبت خوانش تستی
                </button>
            </div>
        </div>

        <div id="alertBox"></div>

        <!-- خلاصه بالا -->
        <div class="row g-3 mb-4" id="summaryRow">
            <?php foreach (FIELDS as $field => $meta): ?>
                <div class="col-12 col-md-4">
                    <div class="summary-card" data-field="<?= htmlspecialchars($field) ?>">
                        <div class="summary-head">
                            <span class="summary-label"><?= htmlspecialchars($meta['label']) ?></span>
                            <span class="summary-unit"><?= htmlspecialchars($meta['unit']) ?></span>
                        </div>
                        <div class="summary-value">
                            <span class="val" data-avg="<?= htmlspecialchars($field) ?>">—</span>
                            <span class="badge text-bg-secondary" data-alert-count="<?= htmlspecialchars($field) ?>">0
                                هشدار</span>
                        </div>
                        <div class="summary-foot">
                            میانگین کل • محدوده مطلوب:
                            <b><?= htmlspecialchars($meta['good'][0]) ?> – <?= htmlspecialchars($meta['good'][1]) ?></b>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row g-3 mb-4">
            <!-- نمودار -->
            <div class="col-12 col-xl-8">
                <div class="panel h-100">
                    <div class="panel-head">
                        <h2 class="panel-title"><i class="bi bi-graph-up"></i> روند میانگین ۱۲ سالن</h2>
                        <div class="btn-group btn-group-sm" role="group" id="chartToggles">
                            <button type="button" class="btn btn-outline-secondary active" data-field="all">همه</button>
                            <?php foreach (FIELDS as $field => $meta): ?>
                                <button type="button" class="btn btn-outline-secondary"
                                    data-field="<?= htmlspecialchars($field) ?>">
                                    <?= htmlspecialchars($meta['label']) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="panel-body">
                        <div class="chart-legend" id="chartLegend"></div>
                        <div class="chart-wrap">
                            <canvas id="chart" aria-label="نمودار روند دما و رطوبت" role="img"></canvas>
                            <div class="chart-empty" id="chartEmpty">هنوز داده‌ای ثبت نشده است.</div>
                        </div>
                    </div>
                    <div class="panel-foot small text-muted">
                        <i class="bi bi-info-circle"></i>
                        خطوط، میانگین هر فیلد بین ۱۲ سالن در هر خوانش است. دماها با هم و رطوبت در نمودار جداگانه نمایش
                        داده می‌شود.
                    </div>
                </div>
            </div>

            <!-- هشدارها -->
            <div class="col-12 col-xl-4">
                <div class="panel h-100">
                    <div class="panel-head">
                        <h2 class="panel-title"><i class="bi bi-bell"></i> هشدارهای فعال</h2>
                        <span class="badge text-bg-danger rounded-pill" id="alertsTotal">0</span>
                    </div>
                    <div class="panel-body panel-body-scroll" id="alertsList">
                        <p class="text-muted small mb-0">در حال بارگذاری...</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- کارت سالن‌ها -->
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h2 class="section-title mb-0"><i class="bi bi-grid-3x3-gap"></i> وضعیت <?= $saloons ?> سالن</h2>
            <div class="d-flex align-items-center gap-2">
                <input type="search" id="saloonSearch" class="form-control form-control-sm" placeholder="جستجوی سالن..."
                    style="max-width:180px" />
                <select id="saloonFilter" class="form-select form-select-sm" style="max-width:190px">
                    <option value="all">همه سالن‌ها</option>
                    <option value="warn">فقط دارای هشدار</option>
                    <option value="ok">فقط سالم</option>
                </select>
            </div>
        </div>
        <div class="row g-3" id="saloonGrid">
            <?php for ($i = 1; $i <= $saloons; $i++): ?>
                <div class="col-12 col-sm-6 col-lg-4 col-xxl-3 saloon-col" data-saloon="<?= $i ?>">
                    <div class="saloon-card skeleton" style="--d: <?= $i * 30 ?>ms">
                        <div class="saloon-head">
                            <span class="saloon-name">سالن <?= $i ?></span>
                            <span class="saloon-state"></span>
                        </div>
                        <div class="saloon-body"></div>
                    </div>
                </div>
            <?php endfor; ?>
        </div>

        <!-- جدول تفصیلی -->
        <div class="panel mt-4">
            <div class="panel-head">
                <h2 class="panel-title"><i class="bi bi-table"></i> جدول تفصیلی سالن‌ها</h2>
                <span class="small text-muted" id="tableHint">مقادیر لحظه‌ای</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 saloon-table">
                    <thead>
                        <tr>
                            <th>سالن</th>
                            <?php foreach (FIELDS as $field => $meta): ?>
                                <th>
                                    <?= htmlspecialchars($meta['label']) ?>
                                    <small class="text-muted">(<?= htmlspecialchars($meta['unit']) ?>)</small>
                                </th>
                            <?php endforeach; ?>
                            <th>وضعیت</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">در حال بارگذاری...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <footer class="text-center text-muted small py-4">
            مانیتورینگ سالن‌های پرورش قارچ • PHP + MySQL + jQuery + Bootstrap
        </footer>
    </main>

    <div class="toast-container position-fixed bottom-0 start-0 p-3" id="toastArea"></div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        window.APP = {
            apiUrl: <?= json_encode(base_url() . 'ajax.php') ?>,
            saloons: <?= (int) $saloons ?>,
            fields: <?= json_encode(FIELDS, JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
    <script src="js/app.js"></script>
</body>

</html>