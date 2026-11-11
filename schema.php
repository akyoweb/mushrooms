<?php
/**
 * ساختار نرمال دیتابیس (اتصال جدول سالن‌ها به دما و رطوبت)
 * ------------------------------------------------------------
 * saloons_table (خود سالن‌ها: salon1..salon12)
 *        │ 1
 *        │
 *        │ N
 * saloon_readings (هر سطر = دما/رطوبت یک سالن در یک زمان)
 *        ▲
 *        │ N
 *        │ 1
 * readings_table (هر سطر = یک نوبت خوانشِ هم‌زمان همه سالن‌ها)
 *
 * مراحل پرورش هر سالن:
 * saloons ──1:N──> saloon_stage_settings ──N:1──> saloon_stages
 * saloons ──1:N──> saloon_stage_logs     ──N:1──> saloon_stages
 *
 * خروجی نهایی در phpMyAdmin: ویوی saloon_monitor
 * (هر سطر: نام سالن + دمای هوا + دمای کمپوست + رطوبت + زمان)
 */

require_once __DIR__ . '/database.php';

/** نام جدول‌ها */
const T_SALOONS = 'saloons';
const T_READINGS = 'readings';
const T_SALOON_D = 'saloon_readings';
const T_VIEW = 'saloon_monitor';
const T_LEGACY = 'temps';

/** مراحل پرورش هر سالن (کمپوست، خاک‌دهی، هوادهی، فلش ۱، فلش ۲ و ...) */
const T_STAGES = 'saloon_stages';
/** تنظیمات هر سالن در هر مرحله (دمای مطلوب، رطوبت، مدت) */
const T_STAGE_SETTINGS = 'saloon_stage_settings';
/** سابقه اجرای مراحل هر سالن (شروع/پایان/یادداشت) */
const T_STAGE_LOGS = 'saloon_stage_logs';

/** جدول‌های داخلی که در شمارش «داده‌های واقعی» حساب نمی‌شوند */
function schema_tables(): array
{
    return [T_SALOONS, T_READINGS, T_SALOON_D, T_LEGACY, T_STAGES, T_STAGE_SETTINGS, T_STAGE_LOGS];
}

/** آیا ساختار جدید ساخته شده است؟ */
function schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        db_run('SELECT 1 FROM `' . T_SALOON_D . '` LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/** ستون‌های یک جدول (کوچک‌شده) */
function table_columns(string $table): array
{
    try {
        return array_map(
            static fn($row) => strtolower($row['Field']),
            db_all('SHOW COLUMNS FROM `' . $table . '`')
        );
    } catch (Throwable $e) {
        return [];
    }
}

/** آیا جدول یا ویو وجود دارد؟ (SHOW TABLES با پارامتر قابل آماده‌سازی نیست) */
function table_exists(string $table): bool
{
    $rows = db_all(
        'SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
        [$table],
        's'
    );
    return !empty($rows);
}

/* ------------------------------------------------------------------ */
/* ایجاد / مهاجرت                                                     */
/* ------------------------------------------------------------------ */

/**
 * ساخت (یا ارتقای) ساختار نرمال. قابل اجرا در هر بار لود صفحه؛
 * فقط اولین بار کار می‌کند و بعد از آن سریع بررسی می‌شود.
 * @return array{status:string, migrated:int, note:string}
 */
function schema_ensure(): array
{
    static $result = null;
    if ($result !== null) {
        return $result;
    }

    $migrated = 0;
    $note = '';

    try {
        // ---------- ۱) جدول سالن‌ها ----------
        if (!table_exists(T_SALOONS)) {
            db_run(
                'CREATE TABLE `' . T_SALOONS . '` (
                    `id` TINYINT UNSIGNED NOT NULL,
                    `name` VARCHAR(50) NOT NULL,
                    `code` VARCHAR(20) NOT NULL,
                    `active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_code` (`code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci'
            );
        } elseif (!in_array('id', table_columns(T_SALOONS), true)) {
            // ساختار قدیمی saloons: ستون‌های salon1..salon12 (هر سطر = یک دوره)
            db_run('DROP TABLE IF EXISTS `' . T_SALOONS . '_pivot`');
            db_run('RENAME TABLE `' . T_SALOONS . '` TO `' . T_SALOONS . '_pivot`');
            db_run(
                'CREATE TABLE `' . T_SALOONS . '` (
                    `id` TINYINT UNSIGNED NOT NULL,
                    `name` VARCHAR(50) NOT NULL,
                    `code` VARCHAR(20) NOT NULL,
                    `active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_code` (`code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci'
            );
            $note = 'جدول سالن‌ها از ساختار ستونی قدیمی به ساختار نرمال منتقل شد';
        }

        // ---------- ۲) جدول نوبت‌های خوانش ----------
        // read_at      : زمان دقیق ثبت (با میکروثانیه، مثل 2026-11-09 10:30:00.123456)
        // period_start : شروع بازه نیم‌ساعتی؛ یکتا بودن آن جلوی ثبت دوباره در همان بازه را می‌گیرد
        if (!table_exists(T_READINGS)) {
            db_run(
                'CREATE TABLE `' . T_READINGS . '` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `read_at` DATETIME(6) NOT NULL,
                    `period_start` DATETIME NOT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_period_start` (`period_start`),
                    KEY `idx_read_at` (`read_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        } else {
            schema_upgrade_readings();
        }

        // ---------- ۳) جدول دما/رطوبت هر سالن (کلید خارجی) ----------
        if (!table_exists(T_SALOON_D)) {
            db_run(
                'CREATE TABLE `' . T_SALOON_D . '` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `reading_id` INT UNSIGNED NOT NULL,
                    `saloon_id` TINYINT UNSIGNED NOT NULL,
                    `air` DECIMAL(5,1) NULL,
                    `compost` DECIMAL(5,1) NULL,
                    `RH` DECIMAL(5,1) NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_reading_saloon` (`reading_id`, `saloon_id`),
                    KEY `idx_saloon` (`saloon_id`),
                    CONSTRAINT `fk_sr_reading` FOREIGN KEY (`reading_id`)
                        REFERENCES `' . T_READINGS . '` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_sr_saloon` FOREIGN KEY (`saloon_id`)
                        REFERENCES `' . T_SALOONS . '` (`id`) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        } else {
            schema_upgrade_values();
        }

        // ---------- ۴) پر کردن سالن‌ها ----------
        $count = (int) db_all('SELECT COUNT(*) AS c FROM `' . T_SALOONS . '`')[0]['c'];
        if ($count < SALOON_COUNT) {
            $sql = 'INSERT IGNORE INTO `' . T_SALOONS . '` (`id`, `name`, `code`) VALUES ';
            $values = [];
            for ($i = 1; $i <= SALOON_COUNT; $i++) {
                $values[] = sprintf("(%d, 'سالن %d', 'salon%d')", $i, $i, $i);
            }
            db_run($sql . implode(',', $values));
        }

        // ---------- ۵) مراحل پرورش هر سالن ----------
        schema_ensure_stages();

        // ---------- ۶) انتقال داده‌های قدیمی temps → ساختار جدید ----------
        $migrated = schema_migrate_legacy();

        // ---------- ۷) ویوم آماده برای مشاهده در phpMyAdmin ----------
        db_run('DROP VIEW IF EXISTS `' . T_VIEW . '`');
        db_run(
            'CREATE VIEW `' . T_VIEW . '` AS
             SELECT
                r.`id`          AS `reading_id`,
                r.`read_at`     AS `read_at`,
                r.`period_start` AS `period_start`,
                s.`id`          AS `saloon_id`,
                s.`name`        AS `saloon`,
                d.`air`         AS `air_temp`,
                d.`compost`     AS `compost_temp`,
                d.`RH`          AS `humidity`,
                CASE
                WHEN d.`air` IS NULL AND d.`compost` IS NULL AND d.`RH` IS NULL THEN \'no data\'
                ELSE \'OK\'
                END             AS `status`
             FROM `' . T_SALOON_D . '` d
             JOIN `' . T_SALOONS . '` s ON s.`id` = d.`saloon_id`
             JOIN `' . T_READINGS . '` r ON r.`id` = d.`reading_id`'
        );
    } catch (Throwable $e) {
        error_log('schema_ensure: ' . $e->getMessage());
        $note = 'خطا در ساخت ساختار دیتابیس: ' . $e->getMessage();
    }

    return $result = [
        'status' => schema_ready() ? 'ok' : 'error',
        'migrated' => $migrated,
        'note' => $note,
    ];
}

/**
 * ارتقای جدول readings: دقت میکروثانیه + ستون period_start + ایندکس یکتا
 * روی جدول قدیمی، read_at فقط به ثانیه بود و امکان جلوگیری از تکرار بازه وجود نداشت
 */
function schema_upgrade_readings(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $columns = array_flip(table_columns(T_READINGS));

    if (($columns['read_at'] ?? '') === 'datetime') {
        db_run('ALTER TABLE `' . T_READINGS . '` MODIFY `read_at` DATETIME(6) NOT NULL');
        $columns['read_at'] = 'datetime(6)';
    }

    if (!isset($columns['period_start'])) {
        db_run('ALTER TABLE `' . T_READINGS . '` ADD COLUMN `period_start` DATETIME NULL AFTER `read_at`');
        $columns['period_start'] = 0;
        // برای داده‌های قبلی، شروع بازه نیم‌ساعتی همان زمان خوانش محاسبه می‌شود
        db_run(
            'UPDATE `' . T_READINGS . '` SET `period_start` = DATE_SUB(DATE_FORMAT(`read_at`, \'%Y-%m-%d %H:%i:00\'),'
            . ' INTERVAL (MINUTE(`read_at`) % ' . (int) READING_INTERVAL_MINUTES . ') MINUTE) WHERE `period_start` IS NULL'
        );
        db_run('UPDATE `' . T_READINGS . '` SET `period_start` = `read_at` WHERE `period_start` IS NULL');

        // ردیف‌های تکراری در یک بازه (از ثبت‌های دستی قبلی) ادغام می‌شوند؛ آخرین ردیف می‌ماند
        foreach (db_all(
            'SELECT `period_start` FROM `' . T_READINGS . '` GROUP BY `period_start` HAVING COUNT(*) > 1'
        ) as $dup) {
            $keepId = (int) db_all(
                'SELECT `id` FROM `' . T_READINGS . '` WHERE `period_start` = ? ORDER BY `id` DESC LIMIT 1',
                [$dup['period_start']],
                's'
            )[0]['id'];
            db_run(
                'DELETE FROM `' . T_READINGS . '` WHERE `period_start` = ? AND `id` <> ?',
                [$dup['period_start'], $keepId],
                'si'
            );
        }

        db_run('ALTER TABLE `' . T_READINGS . '` MODIFY `period_start` DATETIME NOT NULL');
    }

    // تابع اطلاعاتی: آیا ایندکس یکتا وجود دارد؟
    $indexes = db_all(
        'SELECT `INDEX_NAME`, `NON_UNIQUE` FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? ORDER BY `SEQ_IN_INDEX`',
        [T_READINGS, 'period_start'],
        'ss'
    );
    $hasUnique = false;
    foreach ($indexes as $idx) {
        if ((int) $idx['NON_UNIQUE'] === 0) {
            $hasUnique = true;
        }
    }
    if (!$hasUnique) {
        db_run('ALTER TABLE `' . T_READINGS . '` ADD UNIQUE KEY `uniq_period_start` (`period_start`)');
    }

    if (!isset($columns['created_at'])) {
        db_run('ALTER TABLE `' . T_READINGS . '` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }
}

/** مقدار دما/رطوبت اعشاری ذخیره می‌شود (دقت بیشتر دماسنج‌ها) و این‌جا ستون‌ها ارتقا می‌یابند */
function schema_upgrade_values(): void
{
    $columns = db_all('SHOW COLUMNS FROM `' . T_SALOON_D . '`');
    foreach ($columns as $column) {
        if (!in_array($column['Field'], ['air', 'compost', 'RH'], true)) {
            continue;
        }
        if (stripos((string) $column['Type'], 'decimal') === 0) {
            continue;
        }
        try {
            db_run('ALTER TABLE `' . T_SALOON_D . '` MODIFY `' . $column['Field'] . '` DECIMAL(5,1) NULL');
        } catch (Throwable $e) {
            // اگر مقادیر فعلی با DECIMAL سازگار نبودند، نوع قبلی حفظ می‌شود
            error_log('schema_upgrade_values: ' . $e->getMessage());
        }
    }
}

/**
 * انتقال داده‌های جدول قدیمی temps (compost/air/RH) به جدول readings + saloon_readings
 * هر سطر قدیمی = یک نوبت خوانش برای همه سالن‌ها
 * @return int تعداد نوبت‌های منتقل‌شده
 */
function schema_migrate_legacy(): int
{
    if (!table_exists(T_LEGACY)) {
        return 0;
    }

    $legacy = table_columns(T_LEGACY);
    if (!$legacy) {
        return 0;
    }

    $rows = db_all('SELECT * FROM `' . T_LEGACY . '` ORDER BY 1 ASC');
    if (!$rows) {
        return 0;
    }

    // جلوگیری از انتقال تکراری: اگر داده‌ای در ساختار جدید هست، دوباره منتقل نمی‌کنیم
    $existing = (int) db_all('SELECT COUNT(*) AS c FROM `' . T_READINGS . '`')[0]['c'];
    if ($existing > 0) {
        return 0;
    }

    // داده قدیمی با زمان خودش منتقل می‌شود؛ اگر در همان بازه نیم‌ساعتی چند سطر باشد،
    // سطرهای بعدی (به دلیل یکتا بودن period_start) پردازش نمی‌شوند
    $hasNumbered = !empty(array_filter($legacy, static fn($c) => preg_match('/^[a-z]+(\d+)$/', $c)));
    $count = 0;

    foreach ($rows as $row) {
        $time = $row['created_at'] ?? $row['read_at'] ?? $row['read_time'] ?? null;
        $time = $time ?: date('Y-m-d H:i:s');

        $period = period_start(strtotime($time));
        $taken = db_all('SELECT `id` FROM `' . T_READINGS . '` WHERE `period_start` = ?', [$period], 's');
        if ($taken) {
            continue;
        }

        db_run(
            'INSERT INTO `' . T_READINGS . '` (`read_at`, `period_start`) VALUES (?, ?)',
            [$time, $period],
            'ss'
        );
        $readingId = (int) db()->insert_id;

        for ($i = 1; $i <= SALOON_COUNT; $i++) {
            if ($hasNumbered) {
                $air = $row['air' . $i] ?? $row['air_' . $i] ?? null;
                $compost = $row['compost' . $i] ?? $row['compost_' . $i] ?? null;
                $rh = $row['rh' . $i] ?? $row['rh_' . $i] ?? null;
            } else {
                $air = $row['air'] ?? null;
                $compost = $row['compost'] ?? null;
                $rh = $row['RH'] ?? $row['rh'] ?? null;
            }

            if ($air === null && $compost === null && $rh === null) {
                continue;
            }

            db_run(
                'INSERT INTO `' . T_SALOON_D . '` (`reading_id`, `saloon_id`, `air`, `compost`, `RH`)
                 VALUES (?, ?, ?, ?, ?)',
                [$readingId, $i, $air, $compost, $rh],
                'iiiii'
            );
        }
        $count++;
    }

    return $count;
}

/* ------------------------------------------------------------------ */
/* مراحل پرورش سالن‌ها                                                */
/* ------------------------------------------------------------------ */

/**
 * ساخت جدول‌های مراحل پرورش (قابل اجرا در هر بار لود؛ فقط اولین بار کار می‌کند)
 *
 * saloon_stages          : تعریف مراحل (کمپوست، خاک‌دهی، هوادهی، فلش ۱، فلش ۲ و مراحل دلخواه)
 * saloon_stage_settings  : تنظیمات هر سالن در هر مرحله (دمای مطلوب هوا/کمپوست، رطوبت، مدت)
 * saloon_stage_logs      : سابقه اجرای مراحل هر سالن (شروع، پایان، یادداشت)
 */
function schema_ensure_stages(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!table_exists(T_STAGES)) {
        db_run(
            'CREATE TABLE `' . T_STAGES . '` (
                `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `code` VARCHAR(40) NOT NULL,
                `name` VARCHAR(80) NOT NULL,
                `icon` VARCHAR(40) NOT NULL DEFAULT \'bi-circle\',
                `color` VARCHAR(20) NOT NULL DEFAULT \'#1f8a70\',
                `sort_order` SMALLINT NOT NULL DEFAULT 0,
                `duration_days` SMALLINT UNSIGNED NULL,
                `air_min` DECIMAL(5,1) NULL,
                `air_max` DECIMAL(5,1) NULL,
                `compost_min` DECIMAL(5,1) NULL,
                `compost_max` DECIMAL(5,1) NULL,
                `rh_min` DECIMAL(5,1) NULL,
                `rh_max` DECIMAL(5,1) NULL,
                `description` VARCHAR(255) NOT NULL DEFAULT \'\',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_stage_code` (`code`),
                KEY `idx_stage_order` (`sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci'
        );
    }

    if (!table_exists(T_STAGE_SETTINGS)) {
        db_run(
            'CREATE TABLE `' . T_STAGE_SETTINGS . '` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `saloon_id` TINYINT UNSIGNED NOT NULL,
                `stage_id` SMALLINT UNSIGNED NOT NULL,
                `air_min` DECIMAL(5,1) NULL,
                `air_max` DECIMAL(5,1) NULL,
                `compost_min` DECIMAL(5,1) NULL,
                `compost_max` DECIMAL(5,1) NULL,
                `rh_min` DECIMAL(5,1) NULL,
                `rh_max` DECIMAL(5,1) NULL,
                `duration_days` SMALLINT UNSIGNED NULL,
                `note` VARCHAR(255) NOT NULL DEFAULT \'\',
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_saloon_stage` (`saloon_id`, `stage_id`),
                KEY `idx_stage` (`stage_id`),
                CONSTRAINT `fk_ss_saloon` FOREIGN KEY (`saloon_id`)
                    REFERENCES `' . T_SALOONS . '` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_ss_stage` FOREIGN KEY (`stage_id`)
                    REFERENCES `' . T_STAGES . '` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci'
        );
    }

    if (!table_exists(T_STAGE_LOGS)) {
        db_run(
            'CREATE TABLE `' . T_STAGE_LOGS . '` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `saloon_id` TINYINT UNSIGNED NOT NULL,
                `stage_id` SMALLINT UNSIGNED NOT NULL,
                `started_at` DATETIME NOT NULL,
                `ended_at` DATETIME NULL,
                `note` VARCHAR(255) NOT NULL DEFAULT \'\',
                `created_by` VARCHAR(50) NOT NULL DEFAULT \'\',
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_log_saloon` (`saloon_id`, `started_at`),
                KEY `idx_log_stage` (`stage_id`),
                CONSTRAINT `fk_sl_saloon` FOREIGN KEY (`saloon_id`)
                    REFERENCES `' . T_SALOONS . '` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_sl_stage` FOREIGN KEY (`stage_id`)
                    REFERENCES `' . T_STAGES . '` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci'
        );
    }
}

/* ------------------------------------------------------------------ */
/* خواندن                                                             */
/* ------------------------------------------------------------------ */

/**
 * سالن‌ها به ترتیب شناسه
 * @return array<int, array{id:int,name:string,code:string,active:int}>
 */
function saloon_rows(): array
{
    $rows = db_all('SELECT `id`, `name`, `code`, `active` FROM `' . T_SALOONS . '` ORDER BY `id` ASC');
    return array_map(static fn($r) => [
        'id' => (int) $r['id'],
        'name' => $r['name'],
        'code' => $r['code'],
        'active' => (int) $r['active'],
    ], $rows);
}

/** آیا سالن با این شناسه وجود دارد؟ */
function saloon_exists(int $saloon): bool
{
    if ($saloon < 1 || $saloon > SALOON_COUNT) {
        return false;
    }
    return !empty(db_all('SELECT 1 AS x FROM `' . T_SALOONS . '` WHERE `id` = ?', [$saloon], 'i'));
}

/** نام سالن از دیتابیس (با fallback به config) */
function saloon_label(int $saloon): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (saloon_rows() as $row) {
            $map[$row['id']] = $row['name'];
        }
    }
    return $map[$saloon] ?? saloon_name($saloon);
}

/**
 * آخرین نوبت خوانش (آخرین زمان)
 * @return array{id:int,read_at:string,rows:array<int,array{air:?int,compost:?int,RH:?int}>}|null
 */
function last_reading(): ?array
{
    $rows = db_all('SELECT `id`, `read_at` FROM `' . T_READINGS . '` ORDER BY `read_at` DESC LIMIT 1');
    if (!$rows) {
        return null;
    }
    return reading_detail((int) $rows[0]['id']);
}

/** جزئیات یک نوبت خوانش با مقدار هر سالن */
function reading_detail(int $readingId): ?array
{
    $main = db_all('SELECT `id`, `read_at` FROM `' . T_READINGS . '` WHERE `id` = ?', [$readingId], 'i');
    if (!$main) {
        return null;
    }

    $details = db_all(
        'SELECT `saloon_id`, `air`, `compost`, `RH` FROM `' . T_SALOON_D . '` WHERE `reading_id` = ? ORDER BY `saloon_id` ASC',
        [$readingId],
        'i'
    );

    $rows = [];
    foreach ($details as $d) {
        $rows[(int) $d['saloon_id']] = [
            'air' => $d['air'] === null ? null : (float) $d['air'],
            'compost' => $d['compost'] === null ? null : (float) $d['compost'],
            'RH' => $d['RH'] === null ? null : (float) $d['RH'],
        ];
    }

    return [
        'id' => (int) $main[0]['id'],
        'read_at' => $main[0]['read_at'],
        'rows' => $rows,
    ];
}

/**
 * ردیف‌های ویو saloon_monitor (هر سطر: سالن + دماها + زمان)
 * @param int $limit 0 = همه
 */
function monitor_rows(int $limit = 50): array
{
    $limit = max(1, min(5000, $limit));
    return db_all('SELECT * FROM `' . T_VIEW . '` ORDER BY `read_at` DESC, `saloon_id` ASC LIMIT ' . $limit);
}

/**
 * ثبت یک نوبت خوانش جدید
 * @param array $rows [saloonId => ['air'=>, 'compost'=>, 'RH'=>]]
 * @param string|null $readAt زمان دقیق ثبت؛ اگر خالی باشد زمان جاری با میکروثانیه
 * @param string|null $periodStart شروع بازه نیم‌ساعتی؛ اگر خالی باشد از readAt محاسبه می‌شود
 * @return int شناسه نوبت خوانش
 */
function insert_reading(array $rows, ?string $readAt = null, ?string $periodStart = null): int
{
    $readAt = $readAt ?: now_micro();
    $periodStart = $periodStart ?: period_start(strtotime($readAt));

    db_run(
        'INSERT INTO `' . T_READINGS . '` (`read_at`, `period_start`) VALUES (?, ?)',
        [$readAt, $periodStart],
        'ss'
    );
    $readingId = (int) db()->insert_id;

    $values = [];
    $params = [];
    $types = '';
    foreach ($rows as $saloonId => $values3) {
        $values[] = '(?, ?, ?, ?, ?)';
        $params[] = $readingId;
        $params[] = (int) $saloonId;
        $params[] = $values3['air'] ?? null;
        $params[] = $values3['compost'] ?? null;
        $params[] = $values3['RH'] ?? null;
        $types .= 'iiiii';
    }

    if ($values) {
        // مقدار NULL یعنی آن سالن در این نوبت خوانشی نداشته است
        db_run(
            'INSERT INTO `' . T_SALOON_D . '` (`reading_id`, `saloon_id`, `air`, `compost`, `RH`) VALUES ' . implode(',', $values),
            $params,
            $types
        );
    }

    return $readingId;
}
