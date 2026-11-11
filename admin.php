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
        } elseif ($action === 'stage_save') {
            // افزودن یا ویرایش مرحله پرورش
            $stageId = (int) ($_POST['stage_id'] ?? 0);
            $payload = [
                'name' => $_POST['name'] ?? '',
                'icon' => $_POST['icon'] ?? '',
                'color' => $_POST['color'] ?? '',
                'duration_days' => $_POST['duration_days'] ?? null,
                'air' => [$_POST['air_min'] ?? null, $_POST['air_max'] ?? null],
                'compost' => [$_POST['compost_min'] ?? null, $_POST['compost_max'] ?? null],
                'rh' => [$_POST['rh_min'] ?? null, $_POST['rh_max'] ?? null],
                'description' => $_POST['description'] ?? '',
                'is_active' => !empty($_POST['is_active']),
            ];
            $result = save_stage($payload, $stageId > 0 ? $stageId : null);
            if ($result['ok']) {
                $notice = $stageId > 0 ? 'مرحله ویرایش شد.' : 'مرحله جدید اضافه شد.';
            } else {
                $errors = $result['errors'];
                $noticeTone = 'warning';
            }
        } elseif ($action === 'stage_delete') {
            $result = delete_stage((int) ($_POST['stage_id'] ?? 0));
            if ($result['ok']) {
                $notice = 'مرحله حذف شد (تنظیمات و سابقه مرتبط هم حذف شدند).';
            } else {
                $errors[] = $result['error'];
                $noticeTone = 'warning';
            }
        } elseif ($action === 'stage_move') {
            $moved = move_stage((int) ($_POST['stage_id'] ?? 0), (string) ($_POST['direction'] ?? 'up'));
            $notice = $moved ? 'ترتیب مراحل تغییر کرد.' : 'جابه‌جایی امکان‌پذیر نبود.';
            $noticeTone = $moved ? 'success' : 'warning';
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

// مراحل پرورش (مشترک بین همه سالن‌ها)
$stages = stage_rows();
$stageSettingsCount = (int) db_all('SELECT COUNT(*) AS c FROM `' . T_STAGE_SETTINGS . '`')[0]['c'];
$activeStageCount = count(active_stages());
$editStageId = (int) ($_GET['stage'] ?? 0);
$editStage = $editStageId > 0 ? stage_find($editStageId) : null;
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
                <a href="#stages" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-diagram-2"></i> مراحل پرورش
                </a>
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
                                    <th style="width:150px">سالن</th>
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
                                        <td class="fw-semibold">
                                            <a class="saloon-link" href="saloon.php?id=<?= $i ?>" title="بخش اختصاصی سالن">
                                                <?= saloon_name($i) ?> <i class="bi bi-box-arrow-up-left small"></i>
                                            </a>
                                        </td>
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

        <!-- مراحل پرورش سالن‌ها -->
        <div class="panel mt-4" id="stages">
            <div class="panel-head">
                <h2 class="panel-title"><i class="bi bi-diagram-2"></i> مراحل پرورش سالن‌ها</h2>
                <span class="small text-muted">
                    <?= fa_num(count($stages)) ?> مرحله •
                    <?= fa_num($stageSettingsCount) ?> تنظیم اختصاصی سالن •
                    <?= fa_num($activeStageCount) ?> سالن با مرحله فعال
                </span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 stage-admin-table">
                    <thead>
                        <tr>
                            <th style="width:60px">ترتیب</th>
                            <th>مرحله</th>
                            <th>دمای هوا</th>
                            <th>دمای کمپوست</th>
                            <th>رطوبت</th>
                            <th style="width:90px">مدت (روز)</th>
                            <th style="width:90px">وضعیت</th>
                            <th style="width:150px">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stages as $index => $stage): ?>
                            <tr class="<?= $stage['is_active'] ? '' : 'is-off' ?>">
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <form method="post" action="admin.php#stages">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="form" value="stage_move">
                                            <input type="hidden" name="stage_id" value="<?= $stage['id'] ?>">
                                            <input type="hidden" name="direction" value="up">
                                            <button type="submit" class="btn btn-light btn-sm" title="بالا" <?= $index === 0 ? 'disabled' : '' ?>>
                                                <i class="bi bi-arrow-up"></i>
                                            </button>
                                        </form>
                                        <form method="post" action="admin.php#stages">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="form" value="stage_move">
                                            <input type="hidden" name="stage_id" value="<?= $stage['id'] ?>">
                                            <input type="hidden" name="direction" value="down">
                                            <button type="submit" class="btn btn-light btn-sm" title="پایین" <?= $index === count($stages) - 1 ? 'disabled' : '' ?>>
                                                <i class="bi bi-arrow-down"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                                <td>
                                    <span class="stage-name" style="--stage-color: <?= htmlspecialchars($stage['color']) ?>">
                                        <i class="bi <?= htmlspecialchars($stage['icon']) ?>"></i>
                                        <?= htmlspecialchars($stage['name']) ?>
                                    </span>
                                    <small class="text-muted d-block"><code><?= htmlspecialchars($stage['code']) ?></code></small>
                                </td>
                                <td class="small"><?= $stage['air'][0] === null ? '—' : fa_num($stage['air'][0]) . ' – ' . fa_num($stage['air'][1]) ?></td>
                                <td class="small"><?= $stage['compost'][0] === null ? '—' : fa_num($stage['compost'][0]) . ' – ' . fa_num($stage['compost'][1]) ?></td>
                                <td class="small"><?= $stage['rh'][0] === null ? '—' : fa_num($stage['rh'][0]) . ' – ' . fa_num($stage['rh'][1]) ?></td>
                                <td class="small"><?= $stage['duration_days'] === null ? '—' : fa_num($stage['duration_days']) ?></td>
                                <td>
                                    <span class="badge text-bg-<?= $stage['is_active'] ? 'success' : 'secondary' ?>"
                                        data-bs-toggle="tooltip" title="<?= htmlspecialchars($stage['description']) ?>">
                                        <?= $stage['is_active'] ? 'فعال' : 'غیرفعال' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="admin.php?stage=<?= $stage['id'] ?>#stageForm" class="btn btn-light" title="ویرایش">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <form method="post" action="admin.php#stages"
                                            onsubmit="return confirm('مرحله «<?= htmlspecialchars($stage['name'], ENT_QUOTES) ?>» و همه تنظیمات و سابقه آن حذف شود؟');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="form" value="stage_delete">
                                            <input type="hidden" name="stage_id" value="<?= $stage['id'] ?>">
                                            <button type="submit" class="btn btn-light text-danger" title="حذف">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="panel-foot small text-muted">
                <i class="bi bi-info-circle"></i>
                مراحل مشترک بین همه سالن‌ها هستند؛ دمای مطلوب هر مرحله برای هر سالن در صفحه اختصاصی همان سالن تنظیم می‌شود.
            </div>
        </div>

        <!-- فرم افزودن / ویرایش مرحله -->
        <div class="panel mt-4" id="stageForm">
            <div class="panel-head">
                <h2 class="panel-title">
                    <i class="bi <?= $editStage ? 'bi-pencil-square' : 'bi-plus-circle' ?>"></i>
                    <?= $editStage ? 'ویرایش مرحله: ' . htmlspecialchars($editStage['name']) : 'افزودن مرحله جدید' ?>
                </h2>
                <?php if ($editStage): ?>
                    <a href="admin.php#stages" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-x-lg"></i> انصراف از ویرایش
                    </a>
                <?php endif; ?>
            </div>
            <form method="post" action="admin.php#stages">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="stage_save">
                <input type="hidden" name="stage_id" value="<?= $editStage ? $editStage['id'] : 0 ?>">
                <div class="panel-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label small">نام مرحله</label>
                            <input type="text" name="name" class="form-control form-control-sm" maxlength="80" required
                                value="<?= htmlspecialchars($editStage['name'] ?? '') ?>" placeholder="مثلاً فلش ۳">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small">آیکون (Bootstrap)</label>
                            <input type="text" name="icon" class="form-control form-control-sm" dir="ltr"
                                value="<?= htmlspecialchars($editStage['icon'] ?? 'bi-circle') ?>" placeholder="bi-circle">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small">رنگ</label>
                            <input type="color" name="color" class="form-control form-control-sm form-control-color"
                                value="<?= htmlspecialchars($editStage['color'] ?? '#1f8a70') ?>">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small">مدت (روز)</label>
                            <input type="number" name="duration_days" min="0" max="365" class="form-control form-control-sm"
                                value="<?= $editStage && $editStage['duration_days'] !== null ? (int) $editStage['duration_days'] : '' ?>">
                        </div>
                        <div class="col-6 col-md-2 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="stageActive"
                                    <?= !$editStage || $editStage['is_active'] ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="stageActive">فعال</label>
                            </div>
                        </div>

                        <?php foreach (['air' => 'دمای هوا', 'compost' => 'دمای کمپوست', 'rh' => 'رطوبت'] as $key => $label): ?>
                            <?php $meta = FIELDS[$key === 'rh' ? 'RH' : $key]; ?>
                            <div class="col-12 col-md-4">
                                <label class="form-label small">
                                    <?= htmlspecialchars($label) ?> (<?= htmlspecialchars($meta['unit']) ?>) — پیش‌فرض مرحله
                                </label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">حداقل</span>
                                    <input type="number" step="1" name="<?= $key ?>_min" class="form-control"
                                        min="<?= (int) $meta['min'] ?>" max="<?= (int) $meta['max'] ?>"
                                        value="<?= $editStage && $editStage[$key][0] !== null ? (int) $editStage[$key][0] : '' ?>">
                                    <span class="input-group-text">حداکثر</span>
                                    <input type="number" step="1" name="<?= $key ?>_max" class="form-control"
                                        min="<?= (int) $meta['min'] ?>" max="<?= (int) $meta['max'] ?>"
                                        value="<?= $editStage && $editStage[$key][1] !== null ? (int) $editStage[$key][1] : '' ?>">
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div class="col-12">
                            <label class="form-label small">توضیح</label>
                            <input type="text" name="description" class="form-control form-control-sm" maxlength="255"
                                value="<?= htmlspecialchars($editStage['description'] ?? '') ?>" placeholder="اختیاری">
                        </div>
                    </div>
                </div>
                <div class="panel-foot d-flex justify-content-end">
                    <button type="submit" class="btn btn-brand btn-sm px-4">
                        <i class="bi bi-check2-circle"></i> <?= $editStage ? 'ذخیره تغییرات' : 'افزودن مرحله' ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- ساختار متصل دیتابیس -->
        <?php
        $schemaInfo = monitor_boot();
        $viewRows = monitor_rows(36);
        $saloonList = saloon_rows();
        ?>
        <div class="panel mt-4">
            <div class="panel-head">
                <h2 class="panel-title"><i class="bi bi-diagram-3"></i> ساختار متصل دیتابیس</h2>
                <span class="small text-muted">
                    <?= fa_num(count($viewRows)) ?> سطر از ویو <code><?= T_VIEW ?></code>
                    <?php if ($schemaInfo['migrated'] > 0): ?>
                        • <span class="text-success"><?= fa_num($schemaInfo['migrated']) ?> نوبت خوانش قدیمی منتقل شد</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="panel-body">
                <div class="schema-flow">
                    <div class="schema-box">
                        <b><i class="bi bi-house-door"></i> <?= T_SALOONS ?></b>
                        <span><?= fa_num(count($saloonList)) ?> سالن</span>
                    </div>
                    <span class="schema-arrow">1 : N</span>
                    <div class="schema-box">
                        <b><i class="bi bi-thermometer-half"></i> <?= T_SALOON_D ?></b>
                        <span>دما + رطوبت هر سالن</span>
                    </div>
                    <span class="schema-arrow">N : 1</span>
                    <div class="schema-box">
                        <b><i class="bi bi-clock-history"></i> <?= T_READINGS ?></b>
                        <span>زمان هر نوبت خوانش</span>
                    </div>
                </div>
                <div class="schema-flow mt-2">
                    <div class="schema-box">
                        <b><i class="bi bi-house-door"></i> <?= T_SALOONS ?></b>
                        <span>سالن‌ها</span>
                    </div>
                    <span class="schema-arrow">1 : N</span>
                    <div class="schema-box">
                        <b><i class="bi bi-sliders"></i> <?= T_STAGE_SETTINGS ?></b>
                        <span>دمای مطلوب هر مرحله در هر سالن</span>
                    </div>
                    <span class="schema-arrow">N : 1</span>
                    <div class="schema-box">
                        <b><i class="bi bi-diagram-2"></i> <?= T_STAGES ?></b>
                        <span>کمپوست، خاک‌دهی، هوادهی، فلش ۱، فلش ۲ و ...</span>
                    </div>
                </div>
                <div class="schema-flow mt-2">
                    <div class="schema-box">
                        <b><i class="bi bi-house-door"></i> <?= T_SALOONS ?></b>
                        <span>سالن‌ها</span>
                    </div>
                    <span class="schema-arrow">1 : N</span>
                    <div class="schema-box">
                        <b><i class="bi bi-clock-history"></i> <?= T_STAGE_LOGS ?></b>
                        <span>سابقه شروع/پایان مراحل هر سالن</span>
                    </div>
                </div>

                <div class="table-responsive mt-3">
                    <table class="table table-sm table-hover align-middle mb-0 db-view-table">
                        <thead>
                            <tr>
                                <th>reading_id</th>
                                <th>read_at</th>
                                <th>saloon</th>
                                <th>air_temp</th>
                                <th>compost_temp</th>
                                <th>humidity</th>
                                <th>status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$viewRows): ?>
                                <tr><td colspan="7" class="text-center text-muted py-3">هنوز خوانشی ثبت نشده است.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($viewRows as $row): ?>
                                <tr>
                                    <td class="text-muted"><?= fa_num($row['reading_id']) ?></td>
                                    <td dir="ltr" class="text-end small"><?= htmlspecialchars((string) $row['read_at']) ?></td>
                                    <td class="fw-semibold"><?= htmlspecialchars((string) $row['saloon']) ?></td>
                                    <td><?= $row['air_temp'] === null ? '—' : fa_num((int) $row['air_temp']) ?></td>
                                    <td><?= $row['compost_temp'] === null ? '—' : fa_num((int) $row['compost_temp']) ?></td>
                                    <td><?= $row['humidity'] === null ? '—' : fa_num((int) $row['humidity']) . '٪' ?></td>
                                    <td><span class="badge text-bg-<?= $row['status'] === 'OK' ? 'success' : ($row['status'] === 'OUT' ? 'warning' : 'secondary') ?>"><?= htmlspecialchars((string) $row['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="panel-foot small text-muted">
                <i class="bi bi-database"></i> در phpMyAdmin این ویو را باز کنید: <code>SELECT * FROM <?= T_VIEW ?>;</code>
            </div>
        </div>

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
