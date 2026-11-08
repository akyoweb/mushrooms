<?php
/**
 * تنظیمات قابل ویرایش توسط ادمین + بوت‌استرپ دیتابیس
 * ------------------------------------------------------------
 * بازه مطلوب هر سالن برای هر فیلد در جدول admin_settings ذخیره می‌شود.
 * اگر ادمین مقداری را تغییر نداده باشد، مقدار پیش‌فرض FIELDS['good'] استفاده می‌شود.
 */

require_once __DIR__ . '/database.php';

/** نام جدول تنظیمات */
const SETTINGS_TABLE = 'admin_settings';

/** جدول‌های موردنیاز را در اولین اجرا می‌سازد */
function ensure_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // مدیریت خطا درون خودش انجام می‌شود تا صفحه با پیام ناخوانا از کار نیفتد
    try {
        db_run(
            'CREATE TABLE IF NOT EXISTS `' . SETTINGS_TABLE . '` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `saloon` TINYINT UNSIGNED NOT NULL,
                `field` VARCHAR(20) NOT NULL,
                `min_val` DECIMAL(6,1) NOT NULL,
                `max_val` DECIMAL(6,1) NOT NULL,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_saloon_field` (`saloon`, `field`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci'
        );

        db_run(
            'CREATE TABLE IF NOT EXISTS `admins` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `username` VARCHAR(50) NOT NULL,
                `password` VARCHAR(255) NOT NULL,
                `name` VARCHAR(80) NOT NULL DEFAULT \'\',
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_username` (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci'
        );
    } catch (Throwable $e) {
        error_log('ensure_tables: ' . $e->getMessage());
    }
}



/**
 * بازه‌های مطلوب همه سالن‌ها
 * @return array<string, array<int, array{0:int,1:int}>> [field => [saloon => [min, max]]]
 */
function good_ranges(): array
{
    static $ranges = null;
    if ($ranges !== null) {
        return $ranges;
    }

    $ranges = [];
    foreach (array_keys(FIELDS) as $field) {
        $default = FIELDS[$field]['good'];
        for ($i = 1; $i <= SALOONS; $i++) {
            $ranges[$field][$i] = [(int) $default[0], (int) $default[1]];
        }
    }

    try {
        ensure_tables();
        $rows = db_all('SELECT `saloon`, `field`, `min_val`, `max_val` FROM `' . SETTINGS_TABLE . '`');
        foreach ($rows as $row) {
            $field = $row['field'];
            $saloon = (int) $row['saloon'];
            if (!isset($ranges[$field][$saloon])) {
                continue;
            }
            $ranges[$field][$saloon] = [(int) round($row['min_val']), (int) round($row['max_val'])];
        }
    } catch (Throwable $e) {
        // اگر جدول تنظیمات در دسترس نیست، مقادیر پیش‌فرض استفاده می‌شوند
    }

    return $ranges;
}

/** بازه مطلوب یک سالن در یک فیلد */
function saloon_range(string $field, int $saloon): array
{
    $ranges = good_ranges();
    return $ranges[$field][$saloon] ?? FIELDS[$field]['good'];
}

/** آیا بازه‌های مطلوب سفارشی‌شده‌اند؟ (برای نمایش در داشبورد) */
function ranges_customized(): bool
{
    static $customized = null;
    if ($customized !== null) {
        return $customized;
    }
    try {
        ensure_tables();
        $row = db_all('SELECT COUNT(*) AS c FROM `' . SETTINGS_TABLE . '`');
        $customized = ((int) ($row[0]['c'] ?? 0)) > 0;
    } catch (Throwable $e) {
        $customized = false;
    }
    return $customized;
}

/**
 * ذخیره بازه‌های مطلوب (فقط تغییرات همان سالن‌ها)
 * @param array<string, array<int, array{0:mixed,1:mixed}>> $ranges
 * @return array{ok:bool, saved:int, errors:array<int,string>}
 */
function save_good_ranges(array $ranges): array
{
    ensure_tables();

    $saved = 0;
    $errors = [];

    foreach (array_keys(FIELDS) as $field) {
        $meta = FIELDS[$field];
        for ($i = 1; $i <= SALOONS; $i++) {
            if (!isset($ranges[$field][$i])) {
                continue;
            }
            $low = $ranges[$field][$i][0] ?? null;
            $high = $ranges[$field][$i][1] ?? null;

            if ($low === null || $low === '' || $high === null || $high === '' || !is_numeric($low) || !is_numeric($high)) {
                $errors[] = sprintf('%s %s: هر دو عدد حداقل و حداکثر لازم است.', $meta['label'], saloon_name($i));
                continue;
            }

            $low = (int) round((float) $low);
            $high = (int) round((float) $high);

            if ($low >= $high) {
                $errors[] = sprintf('%s %s: حداقل باید کمتر از حداکثر باشد.', $meta['label'], saloon_name($i));
                continue;
            }
            if ($low < $meta['min'] || $high > $meta['max']) {
                $errors[] = sprintf('%s %s: بازه باید بین %d و %d باشد.', $meta['label'], saloon_name($i), $meta['min'], $meta['max']);
                continue;
            }

            $sql = 'INSERT INTO `' . SETTINGS_TABLE . '` (`saloon`, `field`, `min_val`, `max_val`) VALUES (?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE `min_val` = VALUES(`min_val`), `max_val` = VALUES(`max_val`)';
            db_run($sql, [$i, $field, $low, $high], 'isii');
            $saved++;
        }
    }

    return ['ok' => count($errors) === 0, 'saved' => $saved, 'errors' => $errors];
}

/** حذف سفارشی‌سازی‌ها و بازگشت به مقادیر پیش‌فرض */
function reset_good_ranges(): int
{
    ensure_tables();
    return db_run('DELETE FROM `' . SETTINGS_TABLE . '`');
}

/** فیلدها همراه با بازه مطلوب هر سالن (برای API و پنل‌ها) */
function fields_with_ranges(): array
{
    $ranges = good_ranges();
    $fields = FIELDS;
    foreach ($fields as $field => &$meta) {
        $meta['ranges'] = $ranges[$field];
    }
    unset($meta);
    return $fields;
}
