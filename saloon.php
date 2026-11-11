<?php
/**
 * بخش اختصاصی هر سالن
 * ------------------------------------------------------------
 * - دمای مطلوب هوا / کمپوست و رطوبت هر سالن (بازه پیش‌فرض سالن)
 * - مراحل پرورش: کمپوست، خاک‌دهی، هوادهی، فلش ۱، فلش ۲ و مراحل دلخواه
 * - تنظیم جداگانه دمای مطلوب هر مرحله برای همین سالن
 * - شروع/پایان مرحله و سابقه اجرای مراحل
 *
 * آدرس: saloon.php?id=1
 */

require_once __DIR__ . '/monitor.php';
require_once __DIR__ . '/auth.php';

$saloon = (int) ($_GET['id'] ?? $_POST['saloon'] ?? 1);
if (!saloon_exists($saloon)) {
    http_response_code(404);
    $saloon = 1;
}

$admin = auth_check() ? auth_user() : null;
$isAdmin = $admin !== null;
$saloonName = saloon_label($saloon);

$notice = '';
$noticeTone = 'success';
$errors = [];

/* ------------------------- پردازش فرم‌ها ------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors[] = 'توکن امنیتی نامعتبر است؛ صفحه را دوباره بارگذاری کنید.';
    } elseif (!$isAdmin) {
        $errors[] = 'برای تغییر تنظیمات باید وارد حساب ادمین شوید.';
    } else {
        $form = $_POST['form'] ?? '';

        if ($form === 'ranges') {
            // بازه مطلوب پیش‌فرض همین سالن (همان admin_settings)
            $input = $_POST['range'] ?? [];
            $payload = [];
            foreach (array_keys(FIELDS) as $field) {
                $payload[$field][$saloon] = [
                    $input[$field]['min'] ?? null,
                    $input[$field]['max'] ?? null,
                ];
            }
            $result = save_good_ranges($payload);
            if ($result['ok']) {
                $notice = 'دمای مطلوب ' . $saloonName . ' ذخیره شد.';
            } else {
                $errors = $result['errors'];
                $noticeTone = 'warning';
            }
        } elseif ($form === 'stages') {
            // تنظیمات مراحل همین سالن
            $input = $_POST['stage'] ?? [];
            $payload = [];
            foreach ($input as $stageId => $row) {
                $payload[(int) $stageId] = [
                    'air' => [$row['air']['min'] ?? null, $row['air']['max'] ?? null],
                    'compost' => [$row['compost']['min'] ?? null, $row['compost']['max'] ?? null],
                    'rh' => [$row['rh']['min'] ?? null, $row['rh']['max'] ?? null],
                    'duration_days' => $row['duration_days'] ?? null,
                    'note' => $row['note'] ?? '',
                ];
            }
            $result = save_saloon_stage_settings($saloon, $payload);
            if ($result['ok']) {
                $notice = 'تنظیمات ' . fa_num($result['saved']) . ' مرحله برای ' . $saloonName . ' ذخیره شد.';
            } else {
                $errors = $result['errors'];
                $noticeTone = 'warning';
            }
        } elseif ($form === 'start_stage') {
            $stageId = (int) ($_POST['stage_id'] ?? 0);
            $startedAt = trim((string) ($_POST['started_at'] ?? ''));
            $startedAt = $startedAt === '' ? null : str_replace('T', ' ', $startedAt) . ':00';
            $result = start_stage($saloon, $stageId, $startedAt, (string) ($_POST['note'] ?? ''), (string) $admin['name']);
            if ($result['ok']) {
                $stage = stage_find($stageId);
                $notice = 'مرحله «' . ($stage['name'] ?? '') . '» برای ' . $saloonName . ' شروع شد.';
            } else {
                $errors[] = $result['error'];
                $noticeTone = 'warning';
            }
        } elseif ($form === 'end_stage') {
            $result = end_active_stage($saloon);
            if ($result['ok']) {
                $notice = 'مرحله فعال ' . $saloonName . ' پایان یافت.';
            } else {
                $errors[] = $result['error'];
                $noticeTone = 'warning';
            }
        } elseif ($form === 'reset_stages') {
            $deleted = reset_saloon_stage_settings($saloon);
            $notice = $deleted > 0
                ? 'تنظیمات سفارشی مراحل ' . $saloonName . ' حذف و پیش‌فرض‌ها بازگردانده شد.'
                : 'تنظیم سفارشی‌ای برای حذف وجود نداشت.';
        }
    }
}

/* ------------------------- داده‌های صفحه ------------------------- */

$stages = stage_rows();
$stageSettings = saloon_stage_settings($saloon);
$active = active_stage($saloon);
$logs = stage_logs($saloon, 20);

// بازه مطلوب پیش‌فرض سالن (پنل ادمین)
$ranges = [];
foreach (array_keys(FIELDS) as $field) {
    $ranges[$field] = saloon_range($field, $saloon);
}

// آخرین خوانش همین سالن
$snapshot = latest_snapshot();
$latest = $snapshot['latest'];
$values = [];
foreach (array_keys(FIELDS) as $field) {
    $values[$field] = $latest ? ($latest[$field][$saloon - 1] ?? null) : null;
}

$dashboardUrl = base_url() . 'dashboard.php';
$adminUrl = base_url() . 'admin.php';
$loginUrl = base_url() . 'login.php?next=' . urlencode('saloon.php?id=' . $saloon);
$saloonList = saloon_rows();
$nowInput = date('Y-m-d\TH:i');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($saloonName) ?> — بخش اختصاصی</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="style.css" />
</head>

<body>
    <nav class="navbar app-navbar sticky-top">
        <div class="container-fluid px-lg-4">
            <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
                <span class="brand-icon"><i class="bi bi-house-door"></i></span>
                <span><?= htmlspecialchars($saloonName) ?></span>
            </a>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <select id="saloonSwitch" class="form-select form-select-sm saloon-switch" aria-label="انتخاب سالن">
                    <?php foreach ($saloonList as $row): ?>
                        <option value="<?= (int) $row['id'] ?>" <?= $row['id'] === $saloon ? 'selected' : '' ?>>
                            <?= htmlspecialchars($row['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <a href="<?= htmlspecialchars($dashboardUrl) ?>" class="btn btn-sm btn-light">
                    <i class="bi bi-speedometer2"></i> داشبورد
                </a>
                <?php if ($isAdmin): ?>
                    <a href="<?= htmlspecialchars($adminUrl) ?>" class="btn btn-sm btn-warning fw-semibold">
                        <i class="bi bi-sliders"></i> پنل ادمین
                    </a>
                    <span class="admin-chip d-none d-md-inline-flex">
                        <i class="bi bi-person-circle"></i> <?= htmlspecialchars($admin['name']) ?>
                    </span>
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
                <h1 class="page-title mb-1"><?= htmlspecialchars($saloonName) ?></h1>
                <p class="text-muted small mb-0">
                    بخش اختصاصی سالن • دمای مطلوب و مراحل پرورش این سالن
                    <span class="mx-2">|</span>
                    آخرین خوانش: <span class="slot-time fw-semibold"><?= $latest ? htmlspecialchars((string) $latest['t']) : '—' ?></span>
                </p>
            </div>
            <div class="d-flex gap-2">
                <?php if ($active): ?>
                    <span class="badge stage-badge" style="--stage-color: <?= htmlspecialchars($active['stage_color']) ?>">
                        <i class="bi <?= htmlspecialchars($active['stage_icon']) ?>"></i>
                        مرحله فعال: <?= htmlspecialchars($active['stage_name']) ?>
                    </span>
                <?php else: ?>
                    <span class="badge text-bg-secondary"><i class="bi bi-pause-circle"></i> مرحله‌ای فعال نیست</span>
                <?php endif; ?>
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

        <?php if (!$isAdmin): ?>
            <div class="alert alert-secondary py-2 small d-flex align-items-center gap-2">
                <i class="bi bi-eye"></i>
                <span>این صفحه در حالت مشاهده است. برای تغییر دمای مطلوب و مراحل، وارد حساب ادمین شوید.</span>
            </div>
        <?php endif; ?>

        <!-- خوانش لحظه‌ای + مرحله فعال -->
        <div class="row g-3 mb-4">
            <?php foreach (FIELDS as $field => $meta): ?>
                <?php
                $value = $values[$field];
                $check = judge($field, $value, $saloon);
                $range = saloon_effective_range($field, $saloon);
                $fromStage = stage_range($saloon, $field) !== null;
                ?>
                <div class="col-12 col-md-4">
                    <div class="summary-card" data-field="<?= htmlspecialchars($field) ?>">
                        <div class="summary-head">
                            <span class="summary-label"><?= htmlspecialchars($meta['label']) ?></span>
                            <span class="summary-unit"><?= htmlspecialchars($meta['unit']) ?></span>
                        </div>
                        <div class="summary-value">
                            <span class="val"><?= $value === null ? '—' : fa_num(number_format($value, 1, '.', '')) ?></span>
                            <span class="badge text-bg-<?= $check['tone'] ?>"><?= htmlspecialchars($check['text']) ?></span>
                        </div>
                        <div class="summary-foot">
                            بازه مطلوب فعلی:
                            <b><?= fa_num($range[0]) ?> – <?= fa_num($range[1]) ?></b>
                            <?php if ($fromStage): ?>
                                <span class="badge text-bg-primary">از مرحله <?= htmlspecialchars($active['stage_name']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- دمای مطلوب پیش‌فرض سالن -->
        <div class="panel mb-4">
            <div class="panel-head">
                <h2 class="panel-title"><i class="bi bi-bullseye"></i> دمای مطلوب <?= htmlspecialchars($saloonName) ?></h2>
                <span class="small text-muted">
                    وقتی مرحله‌ای فعال نباشد، همین بازه‌ها برای ارزیابی استفاده می‌شوند.
                </span>
            </div>
            <form method="post" action="saloon.php?id=<?= $saloon ?>" id="rangesForm">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="ranges">
                <input type="hidden" name="saloon" value="<?= $saloon ?>">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 ranges-table">
                        <thead>
                            <tr>
                                <th>فیلد</th>
                                <th style="width:170px">حداقل</th>
                                <th style="width:170px">حداکثر</th>
                                <th>پیش‌فرض</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (FIELDS as $field => $meta): ?>
                                <?php [$low, $high] = $ranges[$field]; ?>
                                <tr>
                                    <td class="fw-semibold">
                                        <?= htmlspecialchars($meta['label']) ?>
                                        <small class="text-muted">(<?= htmlspecialchars($meta['unit']) ?>)</small>
                                    </td>
                                    <td>
                                        <input type="number" step="1" class="form-control form-control-sm range-input"
                                            name="range[<?= htmlspecialchars($field) ?>][min]" value="<?= (int) $low ?>"
                                            min="<?= (int) $meta['min'] ?>" max="<?= (int) $meta['max'] ?>"
                                            <?= $isAdmin ? '' : 'disabled' ?>>
                                    </td>
                                    <td>
                                        <input type="number" step="1" class="form-control form-control-sm range-input"
                                            name="range[<?= htmlspecialchars($field) ?>][max]" value="<?= (int) $high ?>"
                                            min="<?= (int) $meta['min'] ?>" max="<?= (int) $meta['max'] ?>"
                                            <?= $isAdmin ? '' : 'disabled' ?>>
                                    </td>
                                    <td class="small text-muted">
                                        <?= fa_num($meta['good'][0]) ?> – <?= fa_num($meta['good'][1]) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($isAdmin): ?>
                    <div class="panel-foot d-flex justify-content-end">
                        <button type="submit" class="btn btn-brand btn-sm px-4">
                            <i class="bi bi-check2-circle"></i> ذخیره دمای مطلوب
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- مراحل پرورش -->
        <div class="panel mb-4">
            <div class="panel-head">
                <h2 class="panel-title"><i class="bi bi-diagram-2"></i> مراحل پرورش <?= htmlspecialchars($saloonName) ?></h2>
                <div class="d-flex align-items-center gap-2">
                    <span class="small text-muted"><?= fa_num(count($stages)) ?> مرحله</span>
                    <?php if ($isAdmin): ?>
                        <a href="<?= htmlspecialchars($adminUrl) ?>#stages" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-plus-circle"></i> مدیریت و افزودن مرحله
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="panel-body">
                <div class="stage-timeline">
                    <?php foreach ($stages as $stage): ?>
                        <?php
                        $setting = $stageSettings[$stage['id']] ?? null;
                        $isActive = $active !== null && $active['stage_id'] === $stage['id'];
                        ?>
                        <div class="stage-chip <?= $isActive ? 'is-active' : '' ?> <?= $stage['is_active'] ? '' : 'is-off' ?>"
                            style="--stage-color: <?= htmlspecialchars($stage['color']) ?>">
                            <i class="bi <?= htmlspecialchars($stage['icon']) ?>"></i>
                            <span><?= htmlspecialchars($stage['name']) ?></span>
                            <?php if ($isActive): ?><small>فعال</small><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($isAdmin): ?>
                    <div class="d-flex flex-wrap align-items-end gap-2 mt-3 pt-3 border-top">
                        <form method="post" action="saloon.php?id=<?= $saloon ?>" class="d-flex flex-wrap align-items-end gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form" value="start_stage">
                            <input type="hidden" name="saloon" value="<?= $saloon ?>">
                            <div>
                                <label class="form-label small mb-1">شروع مرحله</label>
                                <select name="stage_id" class="form-select form-select-sm" required>
                                    <?php foreach ($stages as $stage): ?>
                                        <?php if (!$stage['is_active']) {
                                            continue;
                                        } ?>
                                        <option value="<?= $stage['id'] ?>" <?= $active && $active['stage_id'] === $stage['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($stage['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label small mb-1">تاریخ شروع</label>
                                <input type="datetime-local" name="started_at" class="form-control form-control-sm" value="<?= $nowInput ?>">
                            </div>
                            <div>
                                <label class="form-label small mb-1">یادداشت</label>
                                <input type="text" name="note" class="form-control form-control-sm" maxlength="255" placeholder="اختیاری">
                            </div>
                            <button type="submit" class="btn btn-brand btn-sm">
                                <i class="bi bi-play-fill"></i> شروع
                            </button>
                        </form>
                        <?php if ($active): ?>
                            <form method="post" action="saloon.php?id=<?= $saloon ?>" class="ms-auto">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form" value="end_stage">
                                <input type="hidden" name="saloon" value="<?= $saloon ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                    <i class="bi bi-stop-fill"></i> پایان مرحله فعال
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- تنظیمات هر مرحله برای این سالن -->
        <form method="post" action="saloon.php?id=<?= $saloon ?>" id="stagesForm">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="stages">
            <input type="hidden" name="saloon" value="<?= $saloon ?>">

            <div class="panel mb-4">
                <div class="panel-head">
                    <h2 class="panel-title"><i class="bi bi-thermometer-half"></i> دمای مطلوب هر مرحله در این سالن</h2>
                    <span class="small text-muted">
                        هر مرحله بازه مخصوص خودش را دارد؛ خالی گذاشتن یعنی استفاده از پیش‌فرض مرحله.
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 stage-table">
                        <thead>
                            <tr>
                                <th style="min-width:150px">مرحله</th>
                                <th>دمای هوا (°C)</th>
                                <th>دمای کمپوست (°C)</th>
                                <th>رطوبت (%)</th>
                                <th style="width:110px">مدت (روز)</th>
                                <th style="min-width:150px">یادداشت</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stages as $stage): ?>
                                <?php
                                $setting = $stageSettings[$stage['id']] ?? [
                                    'air' => [null, null], 'compost' => [null, null], 'rh' => [null, null],
                                    'duration_days' => null, 'note' => '', 'custom' => false,
                                ];
                                $sid = (int) $stage['id'];
                                ?>
                                <tr class="<?= $setting['custom'] ? 'row-custom' : '' ?> <?= $active && $active['stage_id'] === $sid ? 'row-active' : '' ?>">
                                    <td>
                                        <span class="stage-name" style="--stage-color: <?= htmlspecialchars($stage['color']) ?>">
                                            <i class="bi <?= htmlspecialchars($stage['icon']) ?>"></i>
                                            <?= htmlspecialchars($stage['name']) ?>
                                        </span>
                                        <?php if ($active && $active['stage_id'] === $sid): ?>
                                            <span class="badge text-bg-success">فعال</span>
                                        <?php endif; ?>
                                        <?php if (!$stage['is_active']): ?>
                                            <span class="badge text-bg-secondary">غیرفعال</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php foreach (['air' => 'air', 'compost' => 'compost', 'rh' => 'RH'] as $key => $field): ?>
                                        <?php $meta = FIELDS[$field]; ?>
                                        <td>
                                            <div class="range-pair">
                                                <input type="number" step="1" class="form-control form-control-sm"
                                                    name="stage[<?= $sid ?>][<?= $key ?>][min]"
                                                    value="<?= $setting[$key][0] === null ? '' : (int) $setting[$key][0] ?>"
                                                    min="<?= (int) $meta['min'] ?>" max="<?= (int) $meta['max'] ?>"
                                                    placeholder="<?= $stage[$key][0] === null ? '—' : (int) $stage[$key][0] ?>"
                                                    <?= $isAdmin ? '' : 'disabled' ?>>
                                                <span class="range-sep">–</span>
                                                <input type="number" step="1" class="form-control form-control-sm"
                                                    name="stage[<?= $sid ?>][<?= $key ?>][max]"
                                                    value="<?= $setting[$key][1] === null ? '' : (int) $setting[$key][1] ?>"
                                                    min="<?= (int) $meta['min'] ?>" max="<?= (int) $meta['max'] ?>"
                                                    placeholder="<?= $stage[$key][1] === null ? '—' : (int) $stage[$key][1] ?>"
                                                    <?= $isAdmin ? '' : 'disabled' ?>>
                                            </div>
                                        </td>
                                    <?php endforeach; ?>
                                    <td>
                                        <input type="number" step="1" min="0" max="365" class="form-control form-control-sm"
                                            name="stage[<?= $sid ?>][duration_days]"
                                            value="<?= $setting['duration_days'] === null ? '' : (int) $setting['duration_days'] ?>"
                                            placeholder="<?= $stage['duration_days'] === null ? '—' : (int) $stage['duration_days'] ?>"
                                            <?= $isAdmin ? '' : 'disabled' ?>>
                                    </td>
                                    <td>
                                        <input type="text" maxlength="255" class="form-control form-control-sm"
                                            name="stage[<?= $sid ?>][note]" value="<?= htmlspecialchars($setting['note']) ?>"
                                            placeholder="اختیاری" <?= $isAdmin ? '' : 'disabled' ?>>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($isAdmin): ?>
                    <div class="panel-foot d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <button type="submit" form="resetStagesForm" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-arrow-counterclockwise"></i> بازگشت به پیش‌فرض مراحل
                        </button>
                        <button type="submit" class="btn btn-brand px-4">
                            <i class="bi bi-check2-circle"></i> ذخیره تنظیمات مراحل
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($isAdmin): ?>
            <form method="post" action="saloon.php?id=<?= $saloon ?>" id="resetStagesForm"
                onsubmit="return confirm('تنظیمات سفارشی مراحل این سالن حذف شود؟');">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="reset_stages">
                <input type="hidden" name="saloon" value="<?= $saloon ?>">
            </form>
        <?php endif; ?>

        <!-- سابقه مراحل -->
        <div class="panel">
            <div class="panel-head">
                <h2 class="panel-title"><i class="bi bi-clock-history"></i> سابقه مراحل <?= htmlspecialchars($saloonName) ?></h2>
                <span class="small text-muted">جدول <code><?= T_STAGE_LOGS ?></code></span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 db-view-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>مرحله</th>
                            <th>شروع</th>
                            <th>پایان</th>
                            <th>یادداشت</th>
                            <th>ثبت‌کننده</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$logs): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">هنوز مرحله‌ای برای این سالن ثبت نشده است.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="text-muted"><?= fa_num($log['id']) ?></td>
                                <td>
                                    <span class="stage-name" style="--stage-color: <?= htmlspecialchars($log['stage_color']) ?>">
                                        <i class="bi <?= htmlspecialchars($log['stage_icon']) ?>"></i>
                                        <?= htmlspecialchars($log['stage_name']) ?>
                                    </span>
                                </td>
                                <td class="slot-time text-end"><?= htmlspecialchars($log['started_at']) ?></td>
                                <td class="slot-time text-end">
                                    <?= $log['ended_at'] === null
                                        ? '<span class="badge text-bg-success">در جریان</span>'
                                        : htmlspecialchars($log['ended_at']) ?>
                                </td>
                                <td class="small"><?= htmlspecialchars($log['note']) ?: '—' ?></td>
                                <td class="small text-muted"><?= htmlspecialchars($log['created_by']) ?: '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <footer class="text-center text-muted small py-4">
            <?= htmlspecialchars($saloonName) ?> • مانیتورینگ سالن‌های پرورش قارچ
        </footer>
    </main>

    <div class="toast-container position-fixed bottom-0 start-0 p-3" id="toastArea"></div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.SALOON_PAGE = {
            saloon: <?= (int) $saloon ?>,
            baseUrl: <?= json_encode(base_url()) ?>
        };
    </script>
    <script src="js/saloon.js"></script>
</body>

</html>
