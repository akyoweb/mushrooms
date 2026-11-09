<?php
/**
 * ثبت خودکار خوانش هر نیم ساعت
 * ------------------------------------------------------------
 * این فایل باید در دقیقه ۰ و ۳۰ هر ساعت اجرا شود (cron یا Task Scheduler).
 * زمان دقیق هر خوانش (با میکروثانیه) در جدول readings ذخیره می‌شود و
 * هر بازه نیم‌ساعتی فقط یک ردیف دارد (period_start یکتاست).
 *
 * اجرای دستی برای تست:
 *   php cron.php            → خوانش بازه جاری را ثبت می‌کند
 *   php cron.php --force    → اگر بازه جاری ثبت شده باشد، دوباره تلاش نمی‌کند (همان)
 *
 * ویندوز (Task Scheduler) — نمونه دستور:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\temps\cron.php
 * متن‌باز (crontab):
 *   0,30 * * * * php /path/to/temps/cron.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once __DIR__ . '/monitor.php';

set_time_limit(60);

$result = auto_capture();
$line = sprintf(
    '[%s] period=%s action=%s%s',
    now_micro(),
    $result['period_start'],
    $result['action'],
    $result['reading_id'] ? ' reading_id=' . $result['reading_id'] . ' read_at=' . $result['read_at'] : ''
);

// لاگ اجرای cron برای بررسی در پنل ادمین
@file_put_contents(__DIR__ . '/tmp/cron.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);

echo $line . PHP_EOL;
exit(0);
