<?php
/**
 * تنظیمات مانیتورینگ سالن‌های قارچ
 * ------------------------------------------------------------
 * سه فیلد اندازه‌گیری داریم: compost - air - RH
 * دوازده سالن: salon1 .. salon12
 * جدول temps (یک سطر = یک خوانشِ هم‌زمان همه سالن‌ها)
 * جدول saloons (سطرها محصول همین خوانش‌ها هستند)
 */

require_once __DIR__ . '/database.php';

/** مجموع تعداد سالن‌ها */
define('SALOONS', SALOON_COUNT);

/** نام سالن‌ها (برای نمایش در پنل ادمین و داشبورد) */
function saloon_name(int $saloon): string
{
    return 'سالن ' . fa_num($saloon);
}

require_once __DIR__ . '/settings.php';

/**
 * تعریف فیلدها
 *  - label  : نام نمایشی
 *  - unit   : واحد
 *  - min/max: بازه مجاز ثبت (کلیدهای جدول int هستند)
 *  - good   : بازه مطلوب هر فیلد
 *  - step   : عدد برای شبیه‌ساز
 *  - color  : رنگ نمودار
 */
const FIELDS = [
    // آرایه برگشتی تابع fields() با رنگ/واحد و بازه مطلوب سالن‌ها ترکیب می‌شود
    'air' => [
        'label' => 'دمای هوا',
        'unit'  => '°C',
        'min'   => -10,
        'max'   => 60,
        'good'  => [15, 18],
        'step'  => 0.35,
        'base'  => 16.4,
        'color' => '#1f8a70',
    ],
    'compost' => [
        'label' => 'دمای کمپوست',
        'unit'  => '°C',
        'min'   => -10,
        'max'   => 90,
        'good'  => [16, 20],
        'step'  => 0.45,
        'base'  => 17.8,
        'color' => '#e26a2c',
    ],
    'RH' => [
        'label' => 'رطوبت نسبی',
        'unit'  => '%',
        'min'   => 0,
        'max'   => 100,
        'good'  => [85, 95],
        'step'  => 1.6,
        'base'  => 90,
        'color' => '#2d6cdf',
    ],
];

/**
 * ساختار فعلی جدول temps به صورت افقی است:
 * هر سطر = یک بازه زمانی و ستون‌ها salon1..salon12 برای همان فیلد.
 * میزان اختلاف مجاز هر سالن نسبت به میانگین سالن‌ها (فقط برای شبیه‌ساز)
 */
define('DETECT_SCHEMA', AUTODETECT);

/**
 * تشخیص ساختار جدول temps
 *  - 'pivot'    : ستون‌ها salon1..salon12  (ساختار فعلی دیتابیس)
 *  - 'vertical' : ستون‌ها saloon_id, field, value
 *  - 'legacy'   : فقط یک سطر compost/air/RH بدون تفکیک سالن (داده‌های قدیمی)
 *
 * @return array{layout:string, field_col:string, saloon_col:string, value_col:string}
 */
function temps_schema(): array
{
    static $schema = null;
    if ($schema !== null) {
        return $schema;
    }

    $columns = array_map(
        static fn($row) => strtolower($row['Field']),
        db_all('SHOW COLUMNS FROM temps')
    );

    $hasSaloonCols = count(array_filter($columns, static fn($c) => preg_match('/^sal+on\d+$/', $c))) > 0;

    if (DETECT_SCHEMA) {
        $saloonCol = null;
        foreach (['saloon_id', 'salon_id', 'saloon', 'salon', 'saloon_no'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                $saloonCol = $candidate;
                break;
            }
        }
        $fieldCol = null;
        foreach (['field', 'type', 'sensor', 'metric'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                $fieldCol = $candidate;
                break;
            }
        }
        $valueCol = null;
        foreach (['value', 'val', 'reading'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                $valueCol = $candidate;
                break;
            }
        }
        if ($saloonCol && $fieldCol && $valueCol) {
            return $schema = [
                'layout' => 'vertical',
                'field_col' => $fieldCol,
                'saloon_col' => $saloonCol,
                'value_col' => $valueCol,
            ];
        }
    }

    if ($hasSaloonCols) {
        return $schema = ['layout' => 'pivot', 'field_col' => '', 'saloon_col' => '', 'value_col' => ''];
    }

    return $schema = ['layout' => 'legacy', 'field_col' => '', 'saloon_col' => '', 'value_col' => ''];
}

/** بررسی اینکه آیا داده‌ای در جدول temps وجود دارد */
function has_data(): bool
{
    $row = db_all('SELECT 1 AS x FROM temps LIMIT 1');
    return !empty($row);
}

/**
 * تشخیص کلید فیلدها بر اساس نام ستون‌ها
 * در ساختار فعلی نام ستون با پیش/پسوند شماره سالن همراه است (مثل compost1)
 * یا نام فیلد خالی است (compost). اگر هیچ تطابقی پیدا نشد، ترتیب ستون‌ها
 * در گروه‌های ۱۲ تایی همان ترتیب FIELDS در نظر گرفته می‌شود.
 *
 * @return array{0:array<int,string>, 1:bool} [ترتیب کلیدها، نقشه‌برداری معتبر است؟]
 */
function detect_field_order(array $columns): array
{
    $found = [];
    $pos = 0;
    foreach ($columns as $column) {
        if (in_array($column, ['id', 'rid', 'created_at', 't', 'timestamp', 'date'], true)) {
            continue;
        }
        $matched = null;
        foreach (array_keys(FIELDS) as $field) {
            $pattern = '/^' . preg_quote(strtolower($field), '/') . '\d*$|^\d*' . preg_quote(strtolower($field), '/') . '$/';
            if (preg_match($pattern, $column)) {
                $matched = $field;
                break;
            }
        }
        if ($matched === null) {
            // نام ستون نامعلوم است؛ بر اساس موقعیت گروه‌بندی می‌کنیم
            $keys = array_keys(FIELDS);
            $matched = $keys[(int) floor($pos / SALOONS) % count($keys)];
        }
        if (!isset($found[$matched])) {
            $found[$matched] = [];
        }
        $found[$matched][] = $column;
        $pos++;
    }

    ksort($found);
    $order = array_keys($found);
    return [$order, count($found) === count(FIELDS)];
}

/** آدرس پایه URL صفحه جاری (برای fetch سمت کلاینت) */
function base_url(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($dir === '' ? '' : $dir) . '/';
}

/** تبدیل ارقام لاتین به فارسی (فقط برای نمایش) */
function fa_num($value): string
{
    return str_replace(range(0, 9), ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'], (string) $value);
}

/** پاسخ JSON و خروج */
function json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
