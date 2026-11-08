<?php
/**
 * لایه داده مانیتورینگ
 * ------------------------------------------------------------
 * ساختار نرمال دیتابیس:
 *   saloons (سالن‌ها) ──1:N──> saloon_readings (دما و رطوبت هر سالن)
 *                              ▲ N:1
 *                            readings (نوبت خوانش)
 * مشاهده آماده در phpMyAdmin: SELECT * FROM saloon_view / saloon_monitor
 */

require_once __DIR__ . '/config.php';

/* ------------------------------------------------------------------ */
/* آماده‌سازی ساختار                                                  */
/* ------------------------------------------------------------------ */

/** مطمئن می‌شود ساختار نرمال و ویوها آماده‌اند */
function monitor_boot(): array
{
    return schema_ensure();
}

/**
 * خوانش‌ها را از قدیم به جدید برمی‌گرداند
 * هر خوانش: ['rid'=>int, 't'=>datetime, 'air'=>[0=>..], 'compost'=>[...], 'RH'=>[...]]
 * (کلیدهای آرایه از صفر شروع می‌شوند و به سالن ۱..۱۲ نگاشت می‌شوند)
 */
function fetch_readings(int $limit = 0): array
{
    monitor_boot();

    $readings = db_all(
        'SELECT `id`, `read_at` FROM `' . T_READINGS . '` ORDER BY `id` '
        . ($limit > 0 ? 'DESC LIMIT ' . max(1, min(100000, $limit)) : 'ASC')
    );

    if ($limit > 0) {
        $readings = array_reverse($readings);
    }
    if (!$readings) {
        return [];
    }

    $ids = array_map(static fn($r) => (int) $r['id'], $readings);
    $place = implode(',', array_fill(0, count($ids), '?'));
    $details = db_all(
        'SELECT `reading_id`, `saloon_id`, `air`, `compost`, `RH` FROM `' . T_SALOON_D . '`
         WHERE `reading_id` IN (' . $place . ')',
        $ids,
        str_repeat('i', count($ids))
    );

    $grouped = [];
    foreach ($details as $d) {
        $grouped[(int) $d['reading_id']][(int) $d['saloon_id']] = $d;
    }

    $rows = [];
    foreach ($readings as $main) {
        $reading = ['rid' => (int) $main['id'], 't' => $main['read_at']];
        foreach (array_keys(FIELDS) as $field) {
            $reading[$field] = [];
            for ($i = 1; $i <= SALOONS; $i++) {
                $value = $grouped[(int) $main['id']][$i][$field] ?? null;
                $reading[$field][] = $value === null ? 0.0 : (float) $value;
            }
        }
        $rows[] = $reading;
    }

    return $rows;
}

/**
 * وضعیت جاری: آخرین خوانش + میانگین کل + تعداد خوانش‌ها
 * مقدار null برای سالنی که در آخرین نوبت داده نداشته باشد.
 */
function latest_snapshot(): array
{
    monitor_boot();

    $readings = fetch_readings(0);
    $latest = $readings ? $readings[count($readings) - 1] : null;

    if ($latest !== null) {
        $detail = reading_detail($latest['rid']);
        if ($detail) {
            foreach (array_keys(FIELDS) as $field) {
                for ($i = 0; $i < SALOONS; $i++) {
                    $latest[$field][$i] = $detail['rows'][$i + 1][$field] ?? null;
                }
            }
            $latest['t'] = $detail['read_at'];
        }
    }

    $averages = [];
    foreach (array_keys(FIELDS) as $field) {
        $sum = 0;
        $count = 0;
        foreach ($readings as $r) {
            foreach ($r[$field] as $v) {
                $sum += $v;
                $count++;
            }
        }
        $averages[$field] = $count ? round($sum / $count, 1) : null;
    }

    return [
        'latest'   => $latest,
        'averages' => $averages,
        'count'    => count($readings),
    ];
}

/* ------------------------------------------------------------------ */
/* ارزیابی و ثبت                                                      */
/* ------------------------------------------------------------------ */

/**
 * وضعیت یک مقدار نسبت به بازه مطلوب
 * اگر شماره سالن داده شود، بازه سفارشی همان سالن (از پنل ادمین) استفاده می‌شود.
 * @return array{status:string,text:string,tone:string}
 */
function judge(string $field, ?float $value, ?int $saloon = null): array
{
    $meta = FIELDS[$field];
    if ($value === null) {
        return ['status' => 'nodata', 'text' => 'بدون داده', 'tone' => 'secondary'];
    }
    [$low, $high] = $saloon !== null ? saloon_range($field, $saloon) : $meta['good'];
    if ($value < $low) {
        return ['status' => 'low', 'text' => 'کمتر از حد مطلوب', 'tone' => 'info'];
    }
    if ($value > $high) {
        return ['status' => 'high', 'text' => 'بیشتر از حد مطلوب', 'tone' => 'danger'];
    }
    return ['status' => 'ok', 'text' => 'در محدوده مطلوب', 'tone' => 'success'];
}

/**
 * ثبت یک خوانش جدید برای همه سالن‌ها
 * @param array $data ['air'=>[..], 'compost'=>[..], 'RH'=>[..]]
 */
function save_reading(array $data): array
{
    monitor_boot();

    $clean = [];
    foreach (array_keys(FIELDS) as $field) {
        $meta = FIELDS[$field];
        $input = $data[$field] ?? null;
        // ارسال به صورت آرایه یا فیلدهای air1..air12 هر دو پشتیبانی می‌شود
        if (!is_array($input)) {
            $input = [];
            for ($i = 1; $i <= SALOONS; $i++) {
                $input[$i - 1] = $data[$field . $i] ?? null;
            }
        }
        if (!array_filter($input, static fn($v) => $v !== null && $v !== '')) {
            return ['ok' => false, 'error' => 'مقادیر ' . $meta['label'] . ' ارسال نشده است.'];
        }
        $input = array_values($input);
        for ($i = 1; $i <= SALOONS; $i++) {
            $value = clean_number($input[$i - 1] ?? null, $meta['min'], $meta['max']);
            if ($value === null) {
                return ['ok' => false, 'error' => sprintf('مقدار %s سالن %d نامعتبر است.', $meta['label'], $i)];
            }
            $clean[$field][] = $value;
        }
    }

    // ردیف‌های جدول saloon_readings: هر سالن یک سطر
    $rows = [];
    for ($i = 1; $i <= SALOONS; $i++) {
        $rows[$i] = [
            'air' => $clean['air'][$i - 1],
            'compost' => $clean['compost'][$i - 1],
            'RH' => $clean['RH'][$i - 1],
        ];
    }

    $readingId = insert_reading($rows);
    return ['ok' => true, 'reading_id' => $readingId];
}
