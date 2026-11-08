<?php
/**
 * لایه داده مانیتورینگ
 * خواندن و ثبت داده‌ها مستقل از اینکه جدول temps افقی (compost1..air1..RH1)
 * یا عمودی (saloon_id/field/value) باشد.
 */

require_once __DIR__ . '/config.php';

/* ------------------------------------------------------------------ */
/* نقشه ستون‌ها                                                        */
/* ------------------------------------------------------------------ */

/** نام ستون‌های جدول temps (کش‌شده) */
function temps_columns(): array
{
    static $columns = null;
    if ($columns === null) {
        $columns = array_map(
            static fn($row) => strtolower($row['Field']),
            db_all('SHOW COLUMNS FROM temps')
        );
    }
    return $columns;
}

/**
 * نقشه ستون‌های افقی: برای هر فیلد، آرایه‌ای از نام ۱۲ ستون سالن به ترتیب ۱..۱۲
 * مثال: ['air' => ['air1', ..., 'air12'], 'compost' => [...], 'RH' => [...]]
 */
function pivot_columns(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $columns = temps_columns();
    $map = [];

    // حالت اول: هر فیلد ستون‌های شماره‌دار خودش را دارد (compost1..compost12)
    foreach (array_keys(FIELDS) as $field) {
        $cols = [];
        $numberCols = [];
        $plain = strtolower($field);
        foreach ($columns as $column) {
            if ($column === $plain) {
                $cols = [$column];
                break;
            }
            if (preg_match('/^' . preg_quote($plain, '/') . '(\d+)$/', $column, $m)) {
                $numberCols[(int) $m[1]] = $column;
            }
        }
        if ($numberCols) {
            ksort($numberCols);
            $cols = array_values($numberCols);
        }
        if ($cols) {
            $map[$field] = $cols;
        }
    }

    if (count($map) === count(FIELDS)) {
        return $map;
    }

    // حالت دوم: ساختار فعلی؛ هر فیلد همان ستون‌های salonN را تکرار می‌کند
    $saloonCols = [];
    foreach ($columns as $column) {
        if (preg_match('/^sal+on(\d+)$/', $column, $m)) {
            $saloonCols[(int) $m[1]] = $column;
        }
    }
    if ($saloonCols) {
        ksort($saloonCols);
        $list = array_values($saloonCols);
        $map = [];
        foreach (array_keys(FIELDS) as $field) {
            $map[$field] = $list;
        }
        $map['__salon__'] = $list;
        return $map;
    }

    return $map;
}

/* ------------------------------------------------------------------ */
/* خواندن                                                             */
/* ------------------------------------------------------------------ */

/**
 * خوانش‌ها را از قدیم به جدید برمی‌گرداند
 * هر خوانش: ['rid'=>int, 'air'=>[0=>.., 1=>..], 'compost'=>[...], 'RH'=>[...]]
 */
function fetch_readings(int $limit = 0): array
{
    $schema = temps_schema();

    if ($schema['layout'] === 'vertical') {
        return fetch_readings_vertical($limit);
    }

    $map = pivot_columns();
    if (!$map) {
        return [];
    }

    // SELECT یک بار برای هر ستون فیزیکی انجام می‌شود (بدون تکرار نام alias)
    $select = [];
    $colIndex = [];
    foreach ($map as $field => $cols) {
        if ($field === '__salon__') {
            continue;
        }
        $colIndex[$field] = [];
        foreach ($cols as $slot => $col) {
            if (!in_array($col, $select, true)) {
                $select[] = $col;
            }
            $colIndex[$field][$slot] = array_search($col, $select, true);
        }
    }

    $cols = array_map(static fn($c) => "`$c`", $select);
    $raw = db_all('SELECT ' . implode(', ', $cols) . ' FROM temps');

    $total = count($raw);
    $start = $limit > 0 ? max(0, $total - $limit) : 0;
    $rows = [];

    foreach (array_slice($raw, $start) as $index => $row) {
        $values = array_values($row);
        $reading = ['rid' => $start + $index + 1];

        foreach ($colIndex as $field => $positions) {
            $reading[$field] = [];
            for ($i = 0; $i < SALOONS; $i++) {
                $position = $positions[$i] ?? ($positions[0] ?? 0);
                $reading[$field][] = (float) ($values[$position] ?? 0);
            }
        }
        $rows[] = $reading;
    }

    return $rows;
}

/** خواندن ساختار عمودی (saloon_id / field / value) */
function fetch_readings_vertical(int $limit = 0): array
{
    $schema = temps_schema();
    $sc = $schema['saloon_col'];
    $fc = $schema['field_col'];
    $vc = $schema['value_col'];

    $timeCol = 'id';
    foreach (['t', 'created_at', 'timestamp', 'id'] as $candidate) {
        if (in_array($candidate, temps_columns(), true)) {
            $timeCol = $candidate;
            break;
        }
    }

    $raw = db_all("SELECT `$sc` AS sid, `$fc` AS fld, `$vc` AS val, `$timeCol` AS tk FROM temps ORDER BY `$timeCol` ASC");

    $grouped = [];
    $counter = 0;
    foreach ($raw as $r) {
        $key = $r['tk'] ?? 'g' . (int) floor($counter++ / (SALOONS * count(FIELDS)));
        if (!isset($grouped[$key])) {
            $grouped[$key] = ['rid' => is_numeric($key) ? (int) $key : count($grouped) + 1, 'data' => []];
        }
        $grouped[$key]['data'][$r['fld']][(int) $r['sid']] = (float) $r['val'];
    }

    $grouped = array_values($grouped);
    if ($limit > 0) {
        $grouped = array_slice($grouped, -$limit);
    }

    $rows = [];
    foreach ($grouped as $g) {
        $reading = ['rid' => $g['rid']];
        foreach (array_keys(FIELDS) as $field) {
            $reading[$field] = [];
            for ($i = 1; $i <= SALOONS; $i++) {
                $reading[$field][] = $g['data'][$field][$i] ?? 0;
            }
        }
        $rows[] = $reading;
    }
    return $rows;
}

/**
 * وضعیت جاری: آخرین خوانش + میانگین کل + تعداد خوانش‌ها
 */
function latest_snapshot(): array
{
    $readings = fetch_readings(0);
    $latest = $readings ? $readings[count($readings) - 1] : null;

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
 * @return array{status:string,text:string,tone:string}
 */
function judge(string $field, ?float $value): array
{
    $meta = FIELDS[$field];
    if ($value === null) {
        return ['status' => 'nodata', 'text' => 'بدون داده', 'tone' => 'secondary'];
    }
    [$low, $high] = $meta['good'];
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

    $schema = temps_schema();

    if ($schema['layout'] === 'vertical') {
        insert_vertical($schema, $clean);
    } else {
        insert_pivot($clean);
    }

    insert_saloons($clean);

    return ['ok' => true];
}

/** ثبت در ساختار افقی */
function insert_pivot(array $clean): void
{
    $map = pivot_columns();
    $unique = [];

    foreach (array_keys(FIELDS) as $field) {
        $fieldCols = $map[$field] ?? (end($map) ?: []);

        if (count($fieldCols) === 1) {
            // ستون واحد برای این فیلد (ساختار قدیمی compost/air/RH): میانگین سالن‌ها
            $unique[$fieldCols[0]] = (int) round(array_sum($clean[$field]) / SALOONS);
            continue;
        }

        for ($i = 0; $i < SALOONS; $i++) {
            $col = $fieldCols[$i] ?? $fieldCols[0];
            // اگر ستون‌ها مشترک باشند (salonN) همان مقدار سالن ذخیره می‌شود
            $unique[$col] = $clean[$field][$i];
        }
    }

    $names = array_keys($unique);
    $sql = 'INSERT INTO temps (`' . implode('`,`', $names) . '`) VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')';
    db_run($sql, array_values($unique), str_repeat('i', count($names)));
}

/** ثبت در ساختار عمودی */
function insert_vertical(array $schema, array $clean): void
{
    $sid = $schema['saloon_col'];
    $fld = $schema['field_col'];
    $val = $schema['value_col'];
    $hasTs = in_array('created_at', temps_columns(), true);
    $now = date('Y-m-d H:i:s');

    foreach ($clean as $field => $values) {
        foreach ($values as $index => $v) {
            $cols = ["`$sid`", "`$fld`", "`$val`"];
            $place = ['i', 's', 'd'];
            $params = [$index + 1, $field, $v];
            if ($hasTs) {
                $cols[] = '`created_at`';
                $place[] = 's';
                $params[] = $now;
            }
            $sql = 'INSERT INTO temps (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
            db_run($sql, $params, implode('', $place));
        }
    }
}

/**
 * ثبت هم‌زمان در جدول saloons (اگر وجود داشته باشد و ستون‌هایش salonN باشند)
 * جدول saloons اختیاری است؛ خطایش مانع ثبت temps نمی‌شود.
 */
function insert_saloons(array $clean): void
{
    try {
        $saloonCols = array_map(
            static fn($r) => strtolower($r['Field']),
            db_all('SHOW COLUMNS FROM saloons')
        );

        $values = [];
        foreach ($saloonCols as $col) {
            if (preg_match('/^sal+on(\d+)$/', $col, $m)) {
                $index = ((int) $m[1]) - 1;
                $field = isset($clean['compost'][$index]) ? 'compost' : array_key_first($clean);
                $values[$col] = $clean[$field][$index] ?? 0;
            }
        }

        if ($values) {
            $names = array_keys($values);
            $sql = 'INSERT INTO saloons (`' . implode('`,`', $names) . '`) VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')';
            db_run($sql, array_values($values), str_repeat('i', count($names)));
        }
    } catch (Throwable $e) {
        // جدول saloons در دسترس نیست یا ساختار متفاوتی دارد
    }
}
