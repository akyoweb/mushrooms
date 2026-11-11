<?php
/**
 * مراحل پرورش هر سالن
 * ------------------------------------------------------------
 * هر سالن بخش اختصاصی خودش را دارد و در آن می‌تواند:
 *   - دمای مطلوب هوا / کمپوست و رطوبت را برای هر مرحله جدا تنظیم کند
 *   - مرحله فعال سالن را مشخص کند (کمپوست، خاک‌دهی، هوادهی، فلش ۱، فلش ۲ و ...)
 *   - مرحله جدید اضافه / ویرایش / حذف کند
 *   - سابقه اجرای مراحل را ببیند
 *
 * جدول‌ها (در schema.php ساخته می‌شوند):
 *   saloon_stages         : تعریف مراحل (مشترک بین همه سالن‌ها)
 *   saloon_stage_settings : تنظیمات هر سالن در هر مرحله
 *   saloon_stage_logs     : سابقه اجرای مراحل هر سالن
 */

require_once __DIR__ . '/schema.php';

/** مراحل پیش‌فرضی که در اولین اجرا ساخته می‌شوند */
function stage_defaults(): array
{
    return [
        [
            'code' => 'compost',
            'name' => 'کمپوست',
            'icon' => 'bi-layers-half',
            'color' => '#8a6d3b',
            'duration_days' => 14,
            'air' => [24, 28],
            'compost' => [25, 30],
            'rh' => [70, 80],
            'description' => 'پاستوریزه و آماده‌سازی کمپوست پیش از خاک‌دهی',
        ],
        [
            'code' => 'casing',
            'name' => 'خاک‌دهی',
            'icon' => 'bi-bucket',
            'color' => '#6b5b95',
            'duration_days' => 7,
            'air' => [22, 25],
            'compost' => [24, 27],
            'rh' => [85, 92],
            'description' => 'پوشاندن بستر با لایه خاک پوششی',
        ],
        [
            'code' => 'aeration',
            'name' => 'هوادهی',
            'icon' => 'bi-wind',
            'color' => '#2d6cdf',
            'duration_days' => 5,
            'air' => [19, 22],
            'compost' => [22, 25],
            'rh' => [88, 95],
            'description' => 'تهویه و کاهش دما برای تحریک میسلیوم',
        ],
        [
            'code' => 'flush1',
            'name' => 'فلش ۱',
            'icon' => 'bi-1-circle',
            'color' => '#1f8a70',
            'duration_days' => 6,
            'air' => [16, 18],
            'compost' => [17, 19],
            'rh' => [88, 94],
            'description' => 'برداشت چین اول',
        ],
        [
            'code' => 'flush2',
            'name' => 'فلش ۲',
            'icon' => 'bi-2-circle',
            'color' => '#e26a2c',
            'duration_days' => 6,
            'air' => [16, 18],
            'compost' => [17, 19],
            'rh' => [86, 92],
            'description' => 'برداشت چین دوم',
        ],
    ];
}

/** ساخت جدول‌ها + درج مراحل پیش‌فرض (فقط اگر جدول خالی باشد) */
function stages_boot(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        schema_ensure_stages();
        $count = (int) db_all('SELECT COUNT(*) AS c FROM `' . T_STAGES . '`')[0]['c'];
        if ($count === 0) {
            $order = 0;
            foreach (stage_defaults() as $stage) {
                $order += 10;
                db_run(
                    'INSERT INTO `' . T_STAGES . '`
                        (`code`, `name`, `icon`, `color`, `sort_order`, `duration_days`,
                         `air_min`, `air_max`, `compost_min`, `compost_max`, `rh_min`, `rh_max`, `description`)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $stage['code'],
                        $stage['name'],
                        $stage['icon'],
                        $stage['color'],
                        $order,
                        $stage['duration_days'],
                        $stage['air'][0],
                        $stage['air'][1],
                        $stage['compost'][0],
                        $stage['compost'][1],
                        $stage['rh'][0],
                        $stage['rh'][1],
                        $stage['description'],
                    ],
                    'ssssiiiiiiiis'
                );
            }
        }
    } catch (Throwable $e) {
        error_log('stages_boot: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/* خواندن                                                             */
/* ------------------------------------------------------------------ */

/**
 * همه مراحل به ترتیب نمایش
 * @param bool $onlyActive فقط مراحل فعال
 * @return array<int, array<string, mixed>>
 */
function stage_rows(bool $onlyActive = false): array
{
    stages_boot();
    $rows = db_all(
        'SELECT * FROM `' . T_STAGES . '`' . ($onlyActive ? ' WHERE `is_active` = 1' : '')
        . ' ORDER BY `sort_order` ASC, `id` ASC'
    );
    return array_map('stage_normalize', $rows);
}

/** یک مرحله با شناسه */
function stage_find(int $stageId): ?array
{
    stages_boot();
    $rows = db_all('SELECT * FROM `' . T_STAGES . '` WHERE `id` = ?', [$stageId], 'i');
    return $rows ? stage_normalize($rows[0]) : null;
}

/** یک مرحله با کد (مثل compost) */
function stage_find_code(string $code): ?array
{
    stages_boot();
    $rows = db_all('SELECT * FROM `' . T_STAGES . '` WHERE `code` = ?', [$code], 's');
    return $rows ? stage_normalize($rows[0]) : null;
}

/** تبدیل سطر دیتابیس به آرایه استاندارد */
function stage_normalize(array $row): array
{
    $num = static fn($v) => $v === null ? null : (float) $v;
    return [
        'id' => (int) $row['id'],
        'code' => (string) $row['code'],
        'name' => (string) $row['name'],
        'icon' => (string) ($row['icon'] ?: 'bi-circle'),
        'color' => (string) ($row['color'] ?: '#1f8a70'),
        'sort_order' => (int) $row['sort_order'],
        'duration_days' => $row['duration_days'] === null ? null : (int) $row['duration_days'],
        'air' => [$num($row['air_min']), $num($row['air_max'])],
        'compost' => [$num($row['compost_min']), $num($row['compost_max'])],
        'rh' => [$num($row['rh_min']), $num($row['rh_max'])],
        'description' => (string) $row['description'],
        'is_active' => (int) $row['is_active'],
    ];
}

/**
 * تنظیمات یک سالن در همه مراحل
 * اگر برای سالن/مرحله تنظیمی ذخیره نشده باشد، مقدار پیش‌فرض خود مرحله استفاده می‌شود.
 * @return array<int, array<string, mixed>> [stageId => تنظیمات]
 */
function saloon_stage_settings(int $saloon): array
{
    stages_boot();

    $out = [];
    foreach (stage_rows() as $stage) {
        $out[$stage['id']] = [
            'stage_id' => $stage['id'],
            'air' => $stage['air'],
            'compost' => $stage['compost'],
            'rh' => $stage['rh'],
            'duration_days' => $stage['duration_days'],
            'note' => '',
            'custom' => false,
        ];
    }

    $rows = db_all(
        'SELECT * FROM `' . T_STAGE_SETTINGS . '` WHERE `saloon_id` = ?',
        [$saloon],
        'i'
    );
    foreach ($rows as $row) {
        $stageId = (int) $row['stage_id'];
        if (!isset($out[$stageId])) {
            continue;
        }
        $num = static fn($v) => $v === null ? null : (float) $v;
        $out[$stageId] = [
            'stage_id' => $stageId,
            'air' => [$num($row['air_min']), $num($row['air_max'])],
            'compost' => [$num($row['compost_min']), $num($row['compost_max'])],
            'rh' => [$num($row['rh_min']), $num($row['rh_max'])],
            'duration_days' => $row['duration_days'] === null ? null : (int) $row['duration_days'],
            'note' => (string) $row['note'],
            'custom' => true,
        ];
    }

    return $out;
}

/** تنظیمات یک سالن در یک مرحله */
function saloon_stage_setting(int $saloon, int $stageId): ?array
{
    $all = saloon_stage_settings($saloon);
    return $all[$stageId] ?? null;
}

/**
 * مرحله فعال هر سالن (آخرین مرحله‌ای که شروع شده و هنوز تمام نشده است)
 * @return array<int, array<string, mixed>> [saloonId => log + stage]
 */
function active_stages(): array
{
    stages_boot();

    $rows = db_all(
        'SELECT l.*, s.`name` AS `stage_name`, s.`code` AS `stage_code`, s.`icon` AS `stage_icon`, s.`color` AS `stage_color`
         FROM `' . T_STAGE_LOGS . '` l
         JOIN `' . T_STAGES . '` s ON s.`id` = l.`stage_id`
         WHERE l.`ended_at` IS NULL
         ORDER BY l.`started_at` ASC, l.`id` ASC'
    );

    $out = [];
    foreach ($rows as $row) {
        // اگر چند مرحله باز مانده باشد، آخرین مورد فعال است
        $out[(int) $row['saloon_id']] = [
            'log_id' => (int) $row['id'],
            'stage_id' => (int) $row['stage_id'],
            'stage_name' => (string) $row['stage_name'],
            'stage_code' => (string) $row['stage_code'],
            'stage_icon' => (string) $row['stage_icon'],
            'stage_color' => (string) $row['stage_color'],
            'started_at' => (string) $row['started_at'],
            'note' => (string) $row['note'],
        ];
    }
    return $out;
}

/** مرحله فعال یک سالن */
function active_stage(int $saloon): ?array
{
    $all = active_stages();
    return $all[$saloon] ?? null;
}

/**
 * سابقه اجرای مراحل یک سالن (جدیدترین اول)
 * @return array<int, array<string, mixed>>
 */
function stage_logs(int $saloon, int $limit = 30): array
{
    stages_boot();
    $limit = max(1, min(200, $limit));
    $rows = db_all(
        'SELECT l.*, s.`name` AS `stage_name`, s.`icon` AS `stage_icon`, s.`color` AS `stage_color`
         FROM `' . T_STAGE_LOGS . '` l
         JOIN `' . T_STAGES . '` s ON s.`id` = l.`stage_id`
         WHERE l.`saloon_id` = ?
         ORDER BY l.`started_at` DESC, l.`id` DESC
         LIMIT ' . $limit,
        [$saloon],
        'i'
    );
    return array_map(static fn($r) => [
        'id' => (int) $r['id'],
        'stage_id' => (int) $r['stage_id'],
        'stage_name' => (string) $r['stage_name'],
        'stage_icon' => (string) $r['stage_icon'],
        'stage_color' => (string) $r['stage_color'],
        'started_at' => (string) $r['started_at'],
        'ended_at' => $r['ended_at'] === null ? null : (string) $r['ended_at'],
        'note' => (string) $r['note'],
        'created_by' => (string) $r['created_by'],
    ], $rows);
}

/**
 * بازه مطلوب یک فیلد در مرحله فعال سالن
 * اگر مرحله‌ای فعال نباشد یا بازه‌اش تعریف نشده باشد، null برمی‌گردد
 * تا داشبورد از بازه پیش‌فرض سالن (admin_settings) استفاده کند.
 * @return array{0:float,1:float}|null
 */
function stage_range(int $saloon, string $field): ?array
{
    $active = active_stage($saloon);
    if ($active === null) {
        return null;
    }
    $setting = saloon_stage_setting($saloon, $active['stage_id']);
    if ($setting === null) {
        return null;
    }
    $key = $field === 'RH' ? 'rh' : $field;
    $range = $setting[$key] ?? null;
    if (!is_array($range) || $range[0] === null || $range[1] === null) {
        return null;
    }
    return [(float) $range[0], (float) $range[1]];
}

/* ------------------------------------------------------------------ */
/* ذخیره                                                              */
/* ------------------------------------------------------------------ */

/**
 * اعتبارسنجی بازه یک فیلد
 * @return array{0:?float,1:?float,2:?string} [min, max, error]
 */
function stage_validate_range(string $field, $low, $high): array
{
    $meta = FIELDS[$field];
    if (($low === null || $low === '') && ($high === null || $high === '')) {
        return [null, null, null];
    }
    if (!is_numeric($low) || !is_numeric($high)) {
        return [null, null, sprintf('بازه %s کامل نیست.', $meta['label'])];
    }
    $low = (float) $low;
    $high = (float) $high;
    if ($low >= $high) {
        return [null, null, sprintf('در %s حداقل باید کمتر از حداکثر باشد.', $meta['label'])];
    }
    if ($low < $meta['min'] || $high > $meta['max']) {
        return [null, null, sprintf('بازه %s باید بین %s و %s باشد.', $meta['label'], $meta['min'], $meta['max'])];
    }
    return [$low, $high, null];
}

/**
 * ذخیره تنظیمات مراحل یک سالن
 * @param array $input [stageId => ['air'=>[min,max], 'compost'=>[..], 'rh'=>[..], 'duration_days'=>, 'note'=>]]
 * @return array{ok:bool, saved:int, errors:array<int,string>}
 */
function save_saloon_stage_settings(int $saloon, array $input): array
{
    stages_boot();

    $saved = 0;
    $errors = [];
    $stages = stage_rows();

    foreach ($stages as $stage) {
        $stageId = $stage['id'];
        if (!isset($input[$stageId])) {
            continue;
        }
        $row = $input[$stageId];

        $values = [];
        foreach (['air' => 'air', 'compost' => 'compost', 'rh' => 'RH'] as $key => $field) {
            [$low, $high, $error] = stage_validate_range(
                $field,
                $row[$key][0] ?? null,
                $row[$key][1] ?? null
            );
            if ($error !== null) {
                $errors[] = $stage['name'] . ': ' . $error;
                continue 2;
            }
            $values[$key] = [$low, $high];
        }

        $duration = $row['duration_days'] ?? null;
        $duration = ($duration === null || $duration === '') ? null : (int) $duration;
        if ($duration !== null && ($duration < 0 || $duration > 365)) {
            $errors[] = $stage['name'] . ': مدت مرحله باید بین ۰ تا ۳۶۵ روز باشد.';
            continue;
        }

        db_run(
            'INSERT INTO `' . T_STAGE_SETTINGS . '`
                (`saloon_id`, `stage_id`, `air_min`, `air_max`, `compost_min`, `compost_max`, `rh_min`, `rh_max`, `duration_days`, `note`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                `air_min` = VALUES(`air_min`), `air_max` = VALUES(`air_max`),
                `compost_min` = VALUES(`compost_min`), `compost_max` = VALUES(`compost_max`),
                `rh_min` = VALUES(`rh_min`), `rh_max` = VALUES(`rh_max`),
                `duration_days` = VALUES(`duration_days`), `note` = VALUES(`note`)',
            [
                $saloon,
                $stageId,
                $values['air'][0],
                $values['air'][1],
                $values['compost'][0],
                $values['compost'][1],
                $values['rh'][0],
                $values['rh'][1],
                $duration,
                mb_substr(trim((string) ($row['note'] ?? '')), 0, 255),
            ],
            'iidddddisi'
        );
        $saved++;
    }

    return ['ok' => count($errors) === 0, 'saved' => $saved, 'errors' => $errors];
}

/** حذف تنظیمات سفارشی یک سالن (بازگشت به پیش‌فرض مراحل) */
function reset_saloon_stage_settings(int $saloon): int
{
    stages_boot();
    return db_run('DELETE FROM `' . T_STAGE_SETTINGS . '` WHERE `saloon_id` = ?', [$saloon], 'i');
}

/**
 * شروع یک مرحله برای سالن
 * مرحله‌ای که قبلاً باز بوده بسته می‌شود (هر سالن در هر لحظه یک مرحله فعال دارد).
 * @return array{ok:bool, error?:string, log_id?:int}
 */
function start_stage(int $saloon, int $stageId, ?string $startedAt = null, string $note = '', string $by = ''): array
{
    stages_boot();

    $stage = stage_find($stageId);
    if ($stage === null) {
        return ['ok' => false, 'error' => 'مرحله انتخاب‌شده وجود ندارد.'];
    }
    if (!$stage['is_active']) {
        return ['ok' => false, 'error' => 'این مرحله غیرفعال است.'];
    }

    $startedAt = $startedAt ?: date('Y-m-d H:i:s');
    if (strtotime($startedAt) === false) {
        return ['ok' => false, 'error' => 'تاریخ شروع نامعتبر است.'];
    }

    // بستن مرحله باز قبلی
    db_run(
        'UPDATE `' . T_STAGE_LOGS . '` SET `ended_at` = ? WHERE `saloon_id` = ? AND `ended_at` IS NULL',
        [$startedAt, $saloon],
        'si'
    );

    db_run(
        'INSERT INTO `' . T_STAGE_LOGS . '` (`saloon_id`, `stage_id`, `started_at`, `note`, `created_by`)
         VALUES (?, ?, ?, ?, ?)',
        [$saloon, $stageId, $startedAt, mb_substr(trim($note), 0, 255), mb_substr($by, 0, 50)],
        'iisss'
    );

    return ['ok' => true, 'log_id' => (int) db()->insert_id];
}

/** پایان مرحله فعال سالن */
function end_active_stage(int $saloon, ?string $endedAt = null): array
{
    stages_boot();
    $active = active_stage($saloon);
    if ($active === null) {
        return ['ok' => false, 'error' => 'این سالن مرحله فعالی ندارد.'];
    }
    $endedAt = $endedAt ?: date('Y-m-d H:i:s');
    db_run(
        'UPDATE `' . T_STAGE_LOGS . '` SET `ended_at` = ? WHERE `id` = ?',
        [$endedAt, $active['log_id']],
        'si'
    );
    return ['ok' => true];
}

/* ------------------------------------------------------------------ */
/* مدیریت مراحل (پنل ادمین)                                           */
/* ------------------------------------------------------------------ */

/** ساخت کد یکتا از نام مرحله */
function stage_make_code(string $name, ?int $ignoreId = null): string
{
    $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
    if ($base === '') {
        $base = 'stage';
    }
    $base = substr($base, 0, 30);
    $code = $base;
    $i = 2;
    while (true) {
        $rows = db_all('SELECT `id` FROM `' . T_STAGES . '` WHERE `code` = ?', [$code], 's');
        if (!$rows || ($ignoreId !== null && (int) $rows[0]['id'] === $ignoreId)) {
            return $code;
        }
        $code = $base . '-' . $i;
        $i++;
    }
}

/**
 * افزودن یا ویرایش مرحله
 * @param array $data name, icon, color, duration_days, air[min,max], compost[..], rh[..], description, is_active
 * @return array{ok:bool, id?:int, errors:array<int,string>}
 */
function save_stage(array $data, ?int $stageId = null): array
{
    stages_boot();

    $errors = [];
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80) {
        $errors[] = 'نام مرحله لازم است و حداکثر ۸۰ کاراکتر باشد.';
    }

    $ranges = [];
    foreach (['air' => 'air', 'compost' => 'compost', 'rh' => 'RH'] as $key => $field) {
        [$low, $high, $error] = stage_validate_range(
            $field,
            $data[$key][0] ?? null,
            $data[$key][1] ?? null
        );
        if ($error !== null) {
            $errors[] = $error;
        }
        $ranges[$key] = [$low, $high];
    }

    $duration = $data['duration_days'] ?? null;
    $duration = ($duration === null || $duration === '') ? null : (int) $duration;
    if ($duration !== null && ($duration < 0 || $duration > 365)) {
        $errors[] = 'مدت مرحله باید بین ۰ تا ۳۶۵ روز باشد.';
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    $icon = preg_match('/^bi-[a-z0-9-]+$/', (string) ($data['icon'] ?? '')) ? (string) $data['icon'] : 'bi-circle';
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($data['color'] ?? '')) ? (string) $data['color'] : '#1f8a70';
    $description = mb_substr(trim((string) ($data['description'] ?? '')), 0, 255);
    $isActive = !empty($data['is_active']) ? 1 : 0;

    if ($stageId !== null) {
        $existing = stage_find($stageId);
        if ($existing === null) {
            return ['ok' => false, 'errors' => ['مرحله پیدا نشد.']];
        }
        db_run(
            'UPDATE `' . T_STAGES . '` SET `name` = ?, `icon` = ?, `color` = ?, `duration_days` = ?,
                `air_min` = ?, `air_max` = ?, `compost_min` = ?, `compost_max` = ?,
                `rh_min` = ?, `rh_max` = ?, `description` = ?, `is_active` = ?
             WHERE `id` = ?',
            [
                $name,
                $icon,
                $color,
                $duration,
                $ranges['air'][0],
                $ranges['air'][1],
                $ranges['compost'][0],
                $ranges['compost'][1],
                $ranges['rh'][0],
                $ranges['rh'][1],
                $description,
                $isActive,
                $stageId,
            ],
            'sssididddddsii'
        );
        return ['ok' => true, 'id' => $stageId];
    }

    $code = stage_make_code($name);
    $order = (int) (db_all('SELECT COALESCE(MAX(`sort_order`), 0) AS m FROM `' . T_STAGES . '`')[0]['m'] ?? 0) + 10;
    db_run(
        'INSERT INTO `' . T_STAGES . '`
            (`code`, `name`, `icon`, `color`, `sort_order`, `duration_days`,
             `air_min`, `air_max`, `compost_min`, `compost_max`, `rh_min`, `rh_max`, `description`, `is_active`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $code,
            $name,
            $icon,
            $color,
            $order,
            $duration,
            $ranges['air'][0],
            $ranges['air'][1],
            $ranges['compost'][0],
            $ranges['compost'][1],
            $ranges['rh'][0],
            $ranges['rh'][1],
            $description,
            $isActive,
        ],
        'ssssiiddddddssi'
    );

    return ['ok' => true, 'id' => (int) db()->insert_id];
}

/**
 * حذف مرحله (تنظیمات و سابقه مرتبط هم حذف می‌شوند)
 * @return array{ok:bool, error?:string}
 */
function delete_stage(int $stageId): array
{
    stages_boot();
    $stage = stage_find($stageId);
    if ($stage === null) {
        return ['ok' => false, 'error' => 'مرحله پیدا نشد.'];
    }
    $total = (int) db_all('SELECT COUNT(*) AS c FROM `' . T_STAGES . '`')[0]['c'];
    if ($total <= 1) {
        return ['ok' => false, 'error' => 'حداقل یک مرحله باید باقی بماند.'];
    }
    db_run('DELETE FROM `' . T_STAGES . '` WHERE `id` = ?', [$stageId], 'i');
    return ['ok' => true];
}

/** جابه‌جایی ترتیب مرحله (بالا/پایین) */
function move_stage(int $stageId, string $direction): bool
{
    stages_boot();
    $stages = stage_rows();
    $index = null;
    foreach ($stages as $i => $stage) {
        if ($stage['id'] === $stageId) {
            $index = $i;
            break;
        }
    }
    if ($index === null) {
        return false;
    }
    $swap = $direction === 'up' ? $index - 1 : $index + 1;
    if (!isset($stages[$swap])) {
        return false;
    }

    // ترتیب‌ها را دوباره شماره‌گذاری می‌کنیم تا همیشه یکنواخت بماند
    $ordered = $stages;
    [$ordered[$index], $ordered[$swap]] = [$ordered[$swap], $ordered[$index]];
    $order = 10;
    foreach ($ordered as $stage) {
        db_run('UPDATE `' . T_STAGES . '` SET `sort_order` = ? WHERE `id` = ?', [$order, $stage['id']], 'ii');
        $order += 10;
    }
    return true;
}
