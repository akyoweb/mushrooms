<?php
/**
 * اتصال به دیتابیس و توابع کمکی مشترک
 *
 * نکته: ساختار جدول‌های فعلی به صورت افقی (Pivot) است، یعنی هر سطر جدول
 * temps یک بازه زمانی (یک خوانش) است و ستون‌های salon1..salon12 مربوط به
 * هر سالن هستند. برای استفاده از همین ساختار بدون تغییر دیتابیس، در
 * config.php هدر ستون‌ها (compost / air / RH) را تعریف کرده‌ایم.
 * اگر دیتابیس را به ساختار عمودی درآوردید، فقط AUTODETECT را true کنید.
 */

define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'mushroom');
define('SALOON_COUNT', 12);

/** اگر true باشد، ساختار جدول temps به صورت خودکار تشخیص داده می‌شود */
const AUTODETECT = false;

function db(): mysqli
{
    static $db = null;
    if ($db === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        mysqli_set_charset($db, 'utf8mb4');
    }
    return $db;
}

/** اجرای کوئری و برگرداندن همه سطرها */
function db_all(string $sql, array $params = [], string $types = ''): array
{
    $stmt = db()->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

/** اجرای کوئری (INSERT/UPDATE) و برگرداندن تعداد سطرهای تأثیرگرفته */
function db_run(string $sql, array $params = [], string $types = ''): int
{
    $stmt = db()->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

/** فیلدهای POST را عددی/صفر می‌کند و بازه مجاز را اعمال می‌کند */
function clean_number($value, float $min, float $max): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    $number = (int) round((float) $value);
    return max((int) $min, min((int) $max, $number));
}
