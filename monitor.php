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
        'SELECT `id`, `read_at` FROM `' . T_READINGS . '` ORDER BY `read_at` '
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
        $reading = ['rid' => (int) $main['id'], 't' => (string) $main['read_at']];
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
            $latest['t'] = (string) $detail['read_at'];
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
/* بازه‌های زمانی (هر نیم ساعت)                                        */
/* ------------------------------------------------------------------ */

/**
 * نوبت خوانشی که بازه جاری (مثل ۱۰:۰۰ یا ۱۰:۳۰) در آن ثبت شده است
 * @return array{id:int,read_at:string,period_start:string}|null
 */
function reading_in_period(?string $periodStart = null): ?array
{
    $periodStart = $periodStart ?: period_start();
    $rows = db_all(
        'SELECT `id`, `read_at`, `period_start` FROM `' . T_READINGS . '` WHERE `period_start` = ? LIMIT 1',
        [$periodStart],
        's'
    );
    if (!$rows) {
        return null;
    }
    return [
        'id' => (int) $rows[0]['id'],
        'read_at' => (string) $rows[0]['read_at'],
        'period_start' => (string) $rows[0]['period_start'],
    ];
}

/**
 * آخرین بازه‌های زمانی با وضعیت ثبت (برای نمایش «هر نیم ساعت» در داشبورد)
 * از آخرین نوبت خوانش به عقب، تا ۳۲ بازه لیست می‌شود.
 * @return array<int, array{period_start:string,period_end:string,read_at:?string,status:string}>
 */
function reading_slots(int $limit = 16, ?int $now = null): array
{
    $now = $now ?? time();
    $limit = max(1, min(96, $limit));
    $interval = READING_INTERVAL_MINUTES * 60;

    // بازه جاری (مثلاً 10:30 یا 19:00)
    $lastStart = intdiv($now, $interval) * $interval;

    // آخرین $limit نوبت ثبت‌شده (با زمان دقیق) را می‌خوانیم
    $rows = db_all(
        'SELECT `period_start`, `read_at` FROM `' . T_READINGS . '` ORDER BY `period_start` DESC LIMIT ' . $limit
    );
    $map = [];
    foreach ($rows as $row) {
        $map[date('Y-m-d H:i:s', strtotime((string) $row['period_start']))] = (string) $row['read_at'];
    }

    // لیست نزولی از بازه جاری عقب می‌رود؛ اگر رکوردهای ذخیره‌شده قدیمی‌تر باشند،
    // بازه‌های خالی بین آن‌ها هم نشان داده می‌شوند تا داده‌های قبلی دیده شوند
    $oldest = array_key_last($map);
    $from = $lastStart - ($limit - 1) * $interval;
    if ($oldest !== null) {
        $oldestTs = strtotime($oldest);
        if ($oldestTs !== false && $oldestTs < $from) {
            $from = $oldestTs;
        }
    }

    $slots = [];
    for ($t = $lastStart, $n = 0; $t >= $from && $n < $limit; $t -= $interval, $n++) {
        $start = date('Y-m-d H:i:s', $t);
        $slots[] = [
            'period_start' => $start,
            'period_end' => date('Y-m-d H:i:s', $t + $interval),
            'read_at' => $map[$start] ?? null,
            'status' => isset($map[$start]) ? 'ok' : 'missing',
        ];
    }
    return $slots;
}

/** زمان دقیق آخرین اجرای cron.php (از tmp/cron.log) */
function cron_last_run_value(): ?string
{
    $file = __DIR__ . '/tmp/cron.log';
    if (!is_file($file)) {
        return null;
    }
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return null;
    }
    $last = (string) end($lines);
    // خروجی: [2026-11-09 10:30:00.123456] period=... action=stored ...
    if (preg_match('/^\[([^\]]+)\]/', $last, $m)) {
        return $m[1];
    }
    return null;
}

/* ------------------------------------------------------------------ */
/* دریافت خودکار از دماسنج‌ها و ثبت                                   */
/* ------------------------------------------------------------------ */

/**
 * منبع جدول دماسنج‌ها: اولین جدول/ویوی که ستون‌های air و compost داشته باشد
 * (جدا از saloon_readings که خروجی خودمان است).
 */
function sensor_source(): ?string
{
    $found = [];
    $exclude = array_map('strtolower', [T_SALOONS, T_READINGS, T_SALOON_D, T_LEGACY, T_VIEW, SETTINGS_TABLE, 'admins']);

    // اگر جدول دماسنج‌ها در config.php تعریف شده باشد، همان منبع اصلی است
    if (defined('SENSOR_TABLE') && SENSOR_TABLE !== '') {
        return SENSOR_TABLE;
    }

    $tables = db_all('SHOW TABLES');
    // جدول‌ها را به ترتیب الفبا بررسی می‌کنیم تا انتخاب همیشه یکسان باشد
    usort($tables, static fn($a, $b) => strcmp((string) reset($a), (string) reset($b)));
    foreach ($tables as $table) {
        $name = (string) reset($table);
        if ($name === '' || in_array(strtolower($name), $exclude, true) || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            continue;
        }
        $columns = table_columns($name);
        $hasSaloon = (bool) array_intersect($columns, ['saloon_id', 'saloon', 'saloon_no', 'salon', 'salon_id']);
        $hasAir = (bool) array_intersect($columns, ['air', 'air_temp', 'temp', 'air_temperature']);
        $hasCompost = (bool) array_intersect($columns, ['compost', 'compost_temp', 'bed_temp']);
        // ستون شناسه سالن لازم است، وگرنه مقدار قابل نسبت دادن به سالن نیست
        if ($hasSaloon && ($hasAir || $hasCompost)) {
            $found[] = $name;
        }
    }

    // هر اسمی که به دماسنج شبیه‌تر است اولویت دارد (sensor…)
    usort($found, static function ($a, $b) {
        $score = static fn($n) => (strpos($n, 'sensor') !== false ? 0 : 1);
        return $score($a) <=> $score($b) ?: strcmp($a, $b);
    });

    return $found[0] ?? null;
}

/**
 * خواندن آخرین مقدار دماسنج‌ها (آرایه ۱۲ سالنی برای هر فیلد)
 * تا وقتی جدول دماسنج‌ها ساخته نشده، null برمی‌گردد.
 * ساختار پیشنهادی: CREATE TABLE sensor_readings (id BIGINT AUTO_INCREMENT, saloon_id TINYINT,
 * air DECIMAL(5,1), compost DECIMAL(5,1), RH DECIMAL(5,1), read_at DATETIME(6))
 * @return array{source:string, rows:array<int, array<string, ?float>>, read_at:?string}|null
 */
function sensor_latest_values(): ?array
{
    $source = sensor_source();
    if ($source === null) {
        return null;
    }

    $columns = table_columns($source);
    if (!$columns) {
        return null;
    }
    $find = static function (array $names) use ($columns): ?string {
        foreach ($names as $name) {
            if (in_array($name, $columns, true)) {
                return $name;
            }
        }
        return null;
    };

    $airCol = $find(['air', 'air_temp', 'temp', 'air_temperature']);
    $compostCol = $find(['compost', 'compost_temp', 'bed_temp']);
    $rhCol = $find(['RH', 'rh', 'humidity']);
    $saloonCol = $find(['saloon_id', 'saloon', 'saloon_no', 'salon', 'salon_id']);
    $timeCol = $find(['read_at', 'created_at', 'time', 'timestamp', 't']);
    if ($saloonCol === null) {
        // بدون شناسه سالن، نمی‌توان مقدار را به سالن درست نسبت داد
        return null;
    }

    $select = ['`' . $saloonCol . '` AS `saloon`'];
    if ($airCol) {
        $select[] = '`' . $airCol . '` AS `air`';
    }
    if ($compostCol) {
        $select[] = '`' . $compostCol . '` AS `compost`';
    }
    if ($rhCol) {
        $select[] = '`' . $rhCol . '` AS `RH`';
    }

    $rows = db_all(
        'SELECT ' . implode(', ', $select)
        . ($timeCol ? ', `' . $timeCol . '` AS `sensor_at`' : '')
        . ' FROM `' . $source . '` ORDER BY '
        . ($timeCol ? '`' . $timeCol . '` DESC, ' : '')
        . '`' . $saloonCol . '` ASC'
    );

    $latest = [];
    $latestAt = null;
    foreach ($rows as $row) {
        $saloon = (int) $row['saloon'];
        if ($saloon < 1 || $saloon > SALOONS || isset($latest[$saloon])) {
            continue;
        }
        $latest[$saloon] = [
            'air' => isset($row['air']) ? (float) $row['air'] : null,
            'compost' => isset($row['compost']) ? (float) $row['compost'] : null,
            'RH' => isset($row['RH']) ? (float) $row['RH'] : null,
        ];
        if ($latestAt === null && !empty($row['sensor_at'])) {
            $latestAt = (string) $row['sensor_at'];
        }
    }

    return $latest ? ['source' => $source, 'rows' => $latest, 'read_at' => $latestAt] : null;
}

/**
 * ثبت خودکار خوانش بازه جاری از دماسنج‌ها
 *  - هر بازه فقط یک بار ثبت می‌شود (period_start یکتاست)
 *  - زمان ثبت، زمان دقیق آزمایش دماسنج است (به میکروثانیه)
 *  - cron/ingest.php در دقیقه ۰ و ۳۰ این تابع را صدا می‌زند؛
 *    اگر cron تنظیم نشده باشد، بازهای که هیچ خوانشی ندارد در اولین بازدید داشبورد ثبت می‌شود.
 * @return array{action:string, reading_id:?int, read_at:?string, period_start:string}
 */
function auto_capture(?string $forcePeriod = null, bool $allowCatchUp = true): array
{
    monitor_boot();

    $now = time();
    $period = $forcePeriod ?: period_start($now);

    if (!$forcePeriod) {
        if (reading_in_period($period)) {
            return ['action' => 'already', 'reading_id' => null, 'read_at' => null, 'period_start' => $period];
        }
        if (!$allowCatchUp) {
            return ['action' => 'skipped', 'reading_id' => null, 'read_at' => null, 'period_start' => $period];
        }
    }

    $sensor = sensor_latest_values();
    if ($sensor === null) {
        // هنوز دماسنجی وصل نشده است
        return ['action' => 'no-sensor', 'reading_id' => null, 'read_at' => null, 'period_start' => $period];
    }

    $readAt = $sensor['read_at'] ?? now_micro();
    $readingId = insert_reading($sensor['rows'], $readAt, $period);

    return ['action' => 'stored', 'reading_id' => $readingId, 'read_at' => $readAt, 'period_start' => $period];
}

/* ------------------------------------------------------------------ */
/* ارزیابی و ثبت                                                      */
/* ------------------------------------------------------------------ */

/**
 * بازه مطلوب مؤثر یک سالن در یک فیلد
 * اولویت: بازه مرحله فعال سالن ← بازه سفارشی سالن (پنل ادمین) ← پیش‌فرض فیلد
 * @return array{0:float,1:float}
 */
function saloon_effective_range(string $field, int $saloon): array
{
    $stageRange = stage_range($saloon, $field);
    if ($stageRange !== null) {
        return $stageRange;
    }
    return saloon_range($field, $saloon);
}

/**
 * وضعیت یک مقدار نسبت به بازه مطلوب
 * اگر شماره سالن داده شود، بازه سفارشی همان سالن (از پنل ادمین) استفاده می‌شود.
 * اگر سالن مرحله فعال داشته باشد، بازه مطلوب همان مرحله اولویت دارد.
 * @return array{status:string,text:string,tone:string}
 */
function judge(string $field, ?float $value, ?int $saloon = null): array
{
    $meta = FIELDS[$field];
    if ($value === null) {
        return ['status' => 'nodata', 'text' => 'بدون داده', 'tone' => 'secondary'];
    }
    $range = $saloon !== null ? saloon_effective_range($field, $saloon) : $meta['good'];
    [$low, $high] = $range;
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

    // برای هر بازه نیم‌ساعتی فقط یک نوبت وجود دارد؛ اگر بازه جاری قبلاً ثبت شده،
    // همان نوبت با مقدار جدید به‌روزرسانی می‌شود (نه ردیف تکراری)
    if (reading_in_period()) {
        return update_reading_current($rows);
    }

    $readingId = insert_reading($rows);
    return ['ok' => true, 'reading_id' => $readingId, 'updated' => false];
}

/**
 * به‌روزرسانی خوانش بازه جاری (اگر وجود داشته باشد)
 * @param array $rows [saloonId => ['air'=>, 'compost'=>, 'RH'=>]]
 */
function update_reading_current(array $rows): array
{
    $reading = reading_in_period();
    if ($reading === null) {
        return ['ok' => false, 'error' => 'خوانشی برای بازه جاری پیدا نشد.'];
    }

    foreach ($rows as $saloonId => $values3) {
        db_run(
            'UPDATE `' . T_SALOON_D . '` SET `air` = ?, `compost` = ?, `RH` = ? WHERE `reading_id` = ? AND `saloon_id` = ?',
            [$values3['air'] ?? null, $values3['compost'] ?? null, $values3['RH'] ?? null, $reading['id'], (int) $saloonId],
            'dddii'
        );
    }

    // زمان دقیق آخرین تغییر همان نوبت هم ثبت می‌شود
    db_run('UPDATE `' . T_READINGS . '` SET `read_at` = ? WHERE `id` = ?', [now_micro(), $reading['id']], 'si');

    return ['ok' => true, 'reading_id' => $reading['id'], 'updated' => true];
}
