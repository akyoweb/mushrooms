<?php
/**
 * API مانیتورینگ (JSON)
 * همه درخواست‌های صفحه داشبورد از اینجا می‌گذرند.
 *
 * action = snapshot   : وضعیت جاری همه سالن‌ها + آمار + بازه‌های نیم‌ساعتی
 * action = history    : سری زمانی یک فیلد (برای نمودار)
 * action = save       : ثبت خوانش بازه جاری
 * action = simulate   : ساخت داده تستی (فقط برای آزمایش)
 * action = capture    : ثبت خودکار خوانش بازه جاری (همان کار cron)
 */

require_once __DIR__ . '/monitor.php';

$action = $_REQUEST['action'] ?? 'snapshot';

try {
    switch ($action) {
        case 'history':
            action_history();
            break;
        case 'save':
            action_save();
            break;
        case 'simulate':
            action_simulate();
            break;
        case 'capture':
            action_capture();
            break;
        case 'snapshot':
        default:
            action_snapshot();
            break;
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'خطا در سرور: ' . $e->getMessage()], 500);
}

/**
 * وضعیت جاری: آخرین مقدار ۱۲ سالن در سه فیلد + ارزیابی + لیست هشدارها
 */
function action_snapshot(): void
{
    $saloons = SALOONS;
    // اگر cron تنظیم نشده باشد، بازه‌ای که خوانش ندارد در اولین بازدید ثبت می‌شود
    $capture = auto_capture(null, true);
    $snapshot = latest_snapshot();
    $latest = $snapshot['latest'];

    $views = [];
    $alerts = [];
    $goodCount = 0;
    $activeStages = active_stages();

    for ($i = 0; $i < $saloons; $i++) {
        $saloonId = $i + 1;
        $active = $activeStages[$saloonId] ?? null;
        $card = ['saloon' => $saloonId, 'values' => [], 'issues' => [], 'stage' => $active];
        foreach (FIELDS as $field => $meta) {
            $value = $latest ? ($latest[$field][$i] ?? null) : null;
            // بازه مطلوب: مرحله فعال سالن ← بازه سفارشی سالن (پنل ادمین) ← پیش‌فرض
            $range = saloon_effective_range($field, $saloonId);
            $check = judge($field, $value, $saloonId);
            $card['values'][$field] = [
                'value' => $value,
                'status' => $check['status'],
                'text' => $check['text'],
                'good' => $range,
                'default_good' => $meta['good'],
                'custom' => $range !== [(int) $meta['good'][0], (int) $meta['good'][1]],
                'from_stage' => $active !== null && stage_range($saloonId, $field) !== null,
                'label' => $meta['label'],
                'unit' => $meta['unit'],
            ];
            if ($check['status'] === 'low' || $check['status'] === 'high') {
                $card['issues'][] = $meta['label'];
                $alerts[] = [
                    'saloon' => $saloonId,
                    'stage' => $active['stage_name'] ?? null,
                    'field' => $field,
                    'label' => $meta['label'],
                    'status' => $check['status'],
                    'value' => $value,
                    'unit' => $meta['unit'],
                    'good' => $range,
                ];
            }
        }
        if (!$card['issues']) {
            $goodCount++;
        }
        $card['status'] = $card['issues'] ? 'warn' : 'ok';
        $views[] = $card;
    }

    // زمان دقیق آخرین خوانش ثبت‌شده (نه زمان درخواست)
    $updatedAt = $latest['t'] ?? null;
    $serverNow = now_micro();

    $histories = [];
    foreach (array_keys(FIELDS) as $field) {
        $histories[$field] = build_history($field, 30);
    }

    $slots = reading_slots(16);

    json_out([
        'ok' => true,
        'layout' => 'normal (saloons ↔ saloon_readings)',
        'saloons' => $views,
        'alerts' => $alerts,
        'alertsCount' => count($alerts),
        'goodCount' => $goodCount,
        'totalSaloons' => $saloons,
        'averages' => $snapshot['averages'],
        'readings' => $snapshot['count'],
        'hasData' => $latest !== null,
        'updatedAt' => $updatedAt,
        'serverNow' => $serverNow,
        'interval' => READING_INTERVAL_MINUTES,
        'currentPeriod' => period_start(),
        'periodEnd' => period_end(),
        'capture' => [
            'action' => $capture['action'],
            'readAt' => $capture['read_at'],
            'sensor' => sensor_source(),
        ],
        'slots' => $slots,
        'cronLog' => cron_last_run_value(),
        'timezone' => APP_TIMEZONE,
        'fields' => FIELDS,
        'ranges' => good_ranges(),
        'fieldsWithRanges' => fields_with_ranges(),
        'rangesCustomized' => ranges_customized(),
        'stages' => array_values(stage_rows(true)),
        'histories' => $histories,
    ]);
}



/**
 * سری زمانی یک فیلد: برای هر خوانش میانگین/کمینه/بیشینه ۱۲ سالن
 */
function build_history(string $field, int $limit = 30): array
{
    $readings = fetch_readings($limit);
    $points = [];
    foreach ($readings as $r) {
        $values = $r[$field] ?? [];
        if (!$values) {
            continue;
        }
        $points[] = [
            'rid' => $r['rid'],
            't' => $r['t'],
            'avg' => round(array_sum($values) / count($values), 1),
            'min' => min($values),
            'max' => max($values),
        ];
    }
    return $points;
}

/**
 * تاریخچه یک فیلد یا همه فیلدها برای نمودار
 */
function action_history(): void
{
    $field = $_GET['field'] ?? 'all';
    $limit = max(5, min(500, (int) ($_GET['limit'] ?? 40)));

    $out = [];
    $fields = $field === 'all' ? array_keys(FIELDS) : [$field];
    foreach ($fields as $f) {
        if (!isset(FIELDS[$f])) {
            continue;
        }
        $out[$f] = [
            'label' => FIELDS[$f]['label'],
            'unit' => FIELDS[$f]['unit'],
            'good' => FIELDS[$f]['good'],
            'color' => FIELDS[$f]['color'],
            'points' => build_history($f, $limit),
        ];
    }

    json_out(['ok' => true, 'series' => $out]);
}

/**
 * ثبت خوانش جدید
 * ورودی: compost[], air[], RH[] (هر کدام ۱۲ مقدار، به ترتیب سالن ۱..۱۲)
 */
function action_save(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_out(['ok' => false, 'error' => 'فقط درخواست POST مجاز است.'], 405);
    }

    // هر فیلد می‌تواند آرایه باشد (compost[]) یا فیلدهای شماره‌دار (compost1..compost12)
    $payload = [];
    foreach (array_keys(FIELDS) as $field) {
        $payload[$field] = $_POST[$field] ?? null;
        for ($i = 1; $i <= SALOONS; $i++) {
            $payload[$field . $i] = $_POST[$field . $i] ?? null;
        }
    }

    $result = save_reading($payload);

    if (!$result['ok']) {
        json_out(['ok' => false, 'error' => $result['error']], 422);
    }

    json_out([
        'ok' => true,
        'message' => !empty($result['updated'])
            ? 'خوانش بازه جاری به‌روزرسانی شد (هر بازه نیم‌ساعتی یک رکورد دارد).'
            : 'خوانش جدید با موفقیت ثبت شد.',
        'reading_id' => $result['reading_id'],
        'updated' => !empty($result['updated']),
    ]);
}

/** ثبت خودکار خوانش بازه جاری از دماسنج‌ها (همان کاری که cron انجام می‌دهد) */
function action_capture(): void
{
    $result = auto_capture(null, true);
    $messages = [
        'stored' => 'خوانش بازه جاری ثبت شد.',
        'already' => 'خوانش این بازه قبلاً ثبت شده است.',
        'no-sensor' => 'هیچ جدول دماسنجی پیدا نشد؛ ابتدا دماسنج‌ها را وصل کنید.',
        'skipped' => 'این بازه رد شد.',
    ];

    json_out([
        'ok' => $result['action'] !== 'skipped',
        'message' => $messages[$result['action']] ?? $result['action'],
        'result' => $result,
        'sensor' => sensor_source(),
        'interval' => READING_INTERVAL_MINUTES,
    ]);
}

/**
 * شبیه‌ساز داده: یک خوانش واقع‌گرایانه حول مقدار مطلوب می‌سازد
 * تا قبل از اتصال سنسورها بتوانید سیستم را کامل تست کنید.
 */
function action_simulate(): void
{
    $saloons = (int) ($_REQUEST['saloons'] ?? SALOONS);
    $saloons = max(1, min(SALOONS, $saloons));
    $data = [];
    $decimals = [];
    foreach (FIELDS as $field => $meta) {
        $decimals[$field] = (int) ($meta['decimals'] ?? 0);
    }

    foreach (FIELDS as $field => $meta) {
        $data[$field] = [];
        $center = ($meta['good'][0] + $meta['good'][1]) / 2;
        for ($i = 0; $i < $saloons; $i++) {
            // گاهی یکی از سالن‌ها خارج از محدوده باشد تا هشدارها دیده شوند
            $deviation = (mt_rand(-100, 100) / 100) * $meta['step'] * 4;
            if (mt_rand(1, 100) <= 8) {
                $deviation += (mt_rand(0, 1) ? 1 : -1) * $meta['step'] * 6;
            }
            $value = round($center + $deviation, $decimals[$field]);
            // سالن‌های ارسال‌نشده با میانگین پر می‌شوند تا بازه‌ها ۱۲ تایی بماند
            $data[$field][$i] = $value;
        }
        for ($i = $saloons; $i < SALOONS; $i++) {
            $data[$field][$i] = round($center, $decimals[$field]);
        }
    }

    $result = save_reading($data);
    if (!$result['ok']) {
        json_out(['ok' => false, 'error' => $result['error']], 422);
    }

    json_out([
        'ok' => true,
        'message' => !empty($result['updated'])
            ? 'خوانش تستی بازه جاری جای‌گزین شد (هر بازه یک رکورد دارد).'
            : 'یک خوانش شبیه‌سازی‌شده ثبت شد.',
    ]);
}
