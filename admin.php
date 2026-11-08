<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/monitor.php';

require_admin();

$admin = auth_user();
$notice = '';
$noticeTone = 'success';
$errors = [];

/* ------------------------- پردازش فرم‌ها ------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors[] = 'توکن امنیتی نامعتبر است؛ صفحه را دوباره بارگذاری کنید.';
    } else {
        $action = $_POST['form'] ?? 'save';

        if ($action === 'save') {
            // ورودی: range[field][saloon][min|max]
            $input = $_POST['range'] ?? [];
            $payload = [];
            foreach (array_keys(FIELDS) as $field) {
                for ($i = 1; $i <= SALOONS; $i++) {
                    $payload[$field][$i] = [
                        $input[$field][$i]['min'] ?? null,
                        $input[$field][$i]['max'] ?? null,
                    ];
                }
            }

            $result = save_good_ranges($payload);
            if ($result['ok']) {
                $notice = 'دماهای مطلوب ' . fa_num($result['saved']) . ' مورد با موفقیت ذخیره شد.';
                $noticeTone = 'success';
            } else {
                $errors = $result['errors'];
                $notice = 'بخشی از مقادیر ذخیره نشد؛ خطاها را برطرف کنید.';
                $noticeTone = 'warning';
            }
        } elseif ($action === 'reset') {
            $deleted = reset_good_ranges();
            $notice = $deleted > 0
                ? 'همه بازه‌ها به مقادیر پیش‌فرض بازگشتند (' . fa_num($deleted) . ' تنظیم حذف شد).'
                : 'تنظیم سفارشی‌ای برای حذف وجود نداشت.';
            $noticeTone = 'success';
        } elseif ($action === 'apply_all') {
            // اعمال مقدار یک سالن به همه سالن‌ها فقط در همین فرم (بدون ذخیره)
            $notice = 'از دکمه‌های ردیف برای کپی مقادیر در فرم استفاده کنید.';
        }
    }
}

// بعد از هر تغییر، بازه‌ها را دوباره می‌خوانیم (کش تابع پاک نمی‌شود، لذا از DB می‌خوانیم)
$ranges = [];
foreach (array_keys(FIELDS) as $field) {
    for ($i = 1; $i <= SALOONS; $i++) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'save' && empty($errors)) {
            $input = $_POST['range'] ?? [];
            $ranges[$field][$i] = [
                (int) round((float) ($input[$field][$i]['min'] ?? FIELDS[$field]['good'][0])),
                (int) round((float) ($input[$field][$i]['max'] ?? FIELDS[$field]['good'][1])),
            ];
        } else {
            $ranges[$field][$i] = saloon_range($field, $i);
        }
    }
}

$fields = FIELDS;
$customized = (int) db_all('SELECT COUNT(*) AS c FROM `' . SETTINGS_TABLE . '`')[0]['c'];
$dashboardUrl = base_url() . 'dashboard.php';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>پنل ادمین — دماهای مطلوب</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="style.css" />
</head>
<body>
    <nav class="navbar app-navbar sticky-top">
        <div class="container-fluid px-lg-4">
            <a class="navbar-brand d-flex align-items-center gap-2" href="admin.php">
                <span class="brand-icon"><i class="bi bi-sliders"></i></span>
                <span>پنل ادمین</span>
            </a>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <span class="admin-chip d-none d-sm-inline-flex">
                    <i class="bi bi-person-circle"></i>
                    <?= htmlspecialchars($admin['name']) ?>
                </span>
                <a href="<?= htmlspecialchars($dashboardUrl) ?>" class="btn btn-sm btn-light">
                    <i class="bi bi-speedometer2"></i> داشبورد
                </a>
                <a href="logout.php" class="btn btn-sm btn-outline-light">
                    <i class="bi bi-box-arrow-right"></i> خروج
                </a>
            </div>
        </div>
    </nav>

    <main class="container-fluid px-lg-4 py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
            <div>
                <h1 class="page-title mb-1">تعیین دماهای مطلوب هر سالن</h1>
                <p class="text-muted small mb-0">
                    مقادیر خارج از بازه‌ی هر سالن در داشبورد به‌صورت «کمتر/بیشتر از حد مطلوب» هشدار داده می‌شود.
                    <span class="mx-2">|</span>
                    تنظیمات سفارشی: <b><?= fa_num($customized) ?></b> ردیف
                </p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnFillDefaults">
                    <i class="bi bi-arrow-counterclockwise"></i> پرکردن با پیش‌فرض‌ها
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#resetModal">
                    <i class="bi bi-trash3"></i> بازگشت به پیش‌فرض
                </button>
            </div>
        </div>

        <?php if ($notice || $errors): ?>
            <div class="alert alert-<?= $errors ? 'warning' : $noticeTone ?> py-2 small">
                <?php if ($notice): ?><div><i class="bi bi-info-circle"></i> <?= htmlspecialchars($notice) ?></div><?php endif; ?>
                <?php foreach ($errors as $error): ?>
                    <div><i class="bi bi-x-circle"></i> <?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="admin.php" id="rangesForm">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="save">

            <!-- انتخاب فیلد -->
            <div class="panel mb-3">
                <div class="panel-head">
                    <h2 class="panel-title"><i class="bi bi-bullseye"></i> فیلد اندازه‌گیری</h2>
                    <div class="btn-group btn-group-sm" id="fieldTabs">
                        <?php foreach ($fields as $field => $meta): ?>
                            <button type="button" class="btn btn-outline-secondary <?= $field === array_key_first($fields) ? 'active' : '' ?>"
                                    data-field="<?= htmlspecialchars($field) ?>">
                                <?= htmlspecialchars($meta['label']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="panel-body py-2">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="small text-muted">اعمال یکسان روی همه سالن‌ها:</span>
                        <div class="input-group input-group-sm" style="max-width:150px">
                            <span class="input-group-text">حداقل</span>
                            <input type="number" class="form-control" id="bulkMin" step="1">
                        </div>
                        <div class="input-group input-group-sm" style="max-width:150px">
                            <span class="input-group-text">حداکثر</span>
                            <input type="number" class="form-control" id="bulkMax" step="1">
                        </div>
                        <button type="button" class="btn btn-sm btn-brand" id="btnBulkApply">
                            <i class="bi bi-arrow-down-square"></i> اعمال روی این فیلد
                        </button>
                    </div>
                </div>
            </div>

            <?php foreach ($fields as $field => $meta): ?>
                <div class="panel mb-3 range-panel" data-panel="<?= htmlspecialchars($field) ?>"
                     <?= $field === array_key_first($fields) ? '' : 'hidden' ?>>
                    <div class="panel-head">
                        <h2 class="panel-title">
                            <i class="bi bi-thermometer-half"></i>
                            <?= htmlspecialchars($meta['label']) ?>
                            <small class="text-muted fw-normal">(<?= htmlspecialchars($meta['unit']) ?>)</small>
                        </h2>
                        <span class="small text-muted">
                            پیش‌فرض: <b><?= fa_num($meta['good'][0]) ?> – <?= fa_num($meta['good'][1]) ?></b>
                            <span class="mx-1">|</span>
                            بازه مجاز ثبت: <?= fa_num($meta['min']) ?> تا <?= fa_num($meta['max']) ?>
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 ranges-table">
                            <thead>
                                <tr>
                                    <th style="width:120px">سالن</th>
                                    <th style="width:170px">حداقل (<?= htmlspecialchars($meta['unit']) ?>)</th>
                                    <th style="width:170px">حداکثر (<?= htmlspecialchars($meta['unit']) ?>)</th>
                                    <th>وضعیت</th>
                                    <th style="width:150px">کپی</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($i = 1; $i <= SALOONS; $i++): ?>
                                    <?php
                                    [$low, $high] = $ranges[$field][$i];
                                    $isCustom = $low !== (int) $meta['good'][0] || $high !== (int) $meta['good'][1];
                                    ?>
                                    <tr data-saloon="<?= $i ?>" class="<?= $isCustom ? 'row-custom' : '' ?>">
                                        <td class="fw-semibold"><?= saloon_name($i) ?></td>
                                        <td>
                                            <input type="number" step="1"
                                                   class="form-control form-control-sm range-input"
                                                   name="range[<?= htmlspecialchars($field) ?>][<?= $i ?>][min]"
                                                   value="<?= (int) $low ?>"
                                                   min="<?= (int) $meta['min'] ?>" max="<?= (int) $meta['max'] ?>"
                                                   data-good-min="<?= (int) $meta['min'] ?>" data-good-max="<?= (int) $meta['max'] ?>"
                                                   aria-label="حداقل <?= htmlspecialchars($meta['label']) ?> <?= saloon_name($i) ?>">
                                        </td>
                                        <td>
                                            <input type="number" step="1"
                                                   class="form-control form-control-sm range-input"
                                                   name="range[<?= htmlspecialchars($field) ?>][<?= $i ?>][max]"
                                                   value="<?= (int) $high ?>"
                                                   min="<?= (int) $meta['min'] ?>" max="<?= (int) $meta['max'] ?>"
                                                   data-good-min="<?= (int) $meta['min'] ?>" data-good-max="<?= (int) $meta['max'] ?>"
                                                   aria-label="حداکثر <?= htmlspecialchars($meta['label']) ?> <?= saloon_name($i) ?>">
                                        </td>
                                        <td class="text-status small"></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-light copy-row" title="اعمال این بازه روی همه سالن‌ها">
                                                    <i class="bi bi-arrow-down-circle"></i>
                                                </button>
                                                <button type="button" class="btn btn-light reset-row" title="بازگشت به پیش‌فرض">
                                                    <i class="bi bi-arrow-counterclockwise"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="panel">
                <div class="panel-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span class="small text-muted" id="formStatus">آماده ذخیره</span>
                    <button type="submit" class="btn btn-brand px-4">
                        <i class="bi bi-check2-circle"></i> ذخیره تنظیمات
                    </button>
                </div>
            </div>
        </form>

        <footer class="text-center text-muted small py-4">
            پنل ادمین • مانیتورینگ سالن‌های پرورش قارچ
        </footer>
    </main>

    <!-- مودال بازگشت به پیش‌فرض -->
    <div class="modal fade" id="resetModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="admin.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="reset">
                    <div class="modal-header">
                        <h5 class="modal-title">بازگشت به مقادیر پیش‌فرض</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="بستن"></button>
                    </div>
                    <div class="modal-body small">
                        همه بازه‌های سفارشی هر <?= fa_num(SALOONS) ?> سالن حذف می‌شوند و مقادیر پیش‌فرض
                        (<code>config.php</code>) دوباره اعمال می‌شود. ادامه می‌دهید؟
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">انصراف</button>
                        <button type="submit" class="btn btn-danger btn-sm">حذف تنظیمات سفارشی</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="toast-container position-fixed bottom-0 start-0 p-3" id="toastArea"></div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.ADMIN_PAGE = {
            fields: <?= json_encode(array_map(static fn($m) => [
                'label' => $m['label'],
                'unit' => $m['unit'],
                'min' => (int) $m['min'],
                'max' => (int) $m['max'],
                'good' => [(int) $m['good'][0], (int) $m['good'][1]],
            ], $fields), JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
    <script src="js/admin.js"></script>
</body>
</html>
