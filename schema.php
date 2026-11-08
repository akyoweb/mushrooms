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

/** جدول‌های داخلی که در شمارش «داده‌های واقعی» حساب نمی‌شوند */
function schema_tables(): array
{
    return [T_SALOONS, T_READINGS, T_SALOON_D, T_LEGACY];
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
        if (!table_exists(T_READINGS)) {
            db_run(
                'CREATE TABLE `' . T_READINGS . '` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `read_at` DATETIME NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_read_at` (`read_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        }

        // ---------- ۳) جدول دما/رطوبت هر سالن (کلید خارجی) ----------
        if (!table_exists(T_SALOON_D)) {
            db_run(
                'CREATE TABLE `' . T_SALOON_D . '` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `reading_id` INT UNSIGNED NOT NULL,
                    `saloon_id` TINYINT UNSIGNED NOT NULL,
                    `air` SMALLINT NULL,
                    `compost` SMALLINT NULL,
                    `RH` TINYINT UNSIGNED NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_reading_saloon` (`reading_id`, `saloon_id`),
                    KEY `idx_saloon` (`saloon_id`),
                    CONSTRAINT `fk_sr_reading` FOREIGN KEY (`reading_id`)
                        REFERENCES `' . T_READINGS . '` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_sr_saloon` FOREIGN KEY (`saloon_id`)
                        REFERENCES `' . T_SALOONS . '` (`id`) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
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

        // ---------- ۵) انتقال داده‌های قدیمی temps → ساختار جدید ----------
        $migrated = schema_migrate_legacy();

        // ---------- ۶) ویو آماده برای مشاهده در phpMyAdmin ----------
        db_run('DROP VIEW IF EXISTS `' . T_VIEW . '`');
        db_run(
            'CREATE VIEW `' . T_VIEW . '` AS
             SELECT
                r.`id`          AS `reading_id`,
                r.`read_at`     AS `read_at`,
                s.`id`          AS `saloon_id`,
                s.`name`        AS `saloon`,
                d.`air`         AS `air_temp`,
                d.`compost`     AS `compost_temp`,
                d.`RH`          AS `humidity`,
                CASE
                WHEN d.`air` IS NULL AND d.`compost` IS NULL AND d.`RH` IS NULL THEN \'no data\'
                WHEN d.`air` BETWEEN 15 AND 18
                     AND d.`compost` BETWEEN 16 AND 20
                     AND d.`RH` BETWEEN 85 AND 95 THEN \'OK\'
                ELSE \'OUT\'
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

    $hasNumbered = !empty(array_filter($legacy, static fn($c) => preg_match('/^[a-z]+(\d+)$/', $c)));
    $count = 0;

    foreach ($rows as $row) {
        $time = $row['created_at'] ?? $row['read_at'] ?? $row['read_time'] ?? null;
        $time = $time ?: date('Y-m-d H:i:s');

        db_run('INSERT INTO `' . T_READINGS . '` (`read_at`) VALUES (?)', [$time], 's');
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
    $rows = db_all('SELECT `id`, `read_at` FROM `' . T_READINGS . '` ORDER BY `id` DESC LIMIT 1');
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
        'SELECT `saloon_id`, `air`, `compost`, `RH` FROM `' . T_SALOON_D . '` WHERE `reading_id` = ?',
        [$readingId],
        'i'
    );

    $rows = [];
    foreach ($details as $d) {
        $rows[(int) $d['saloon_id']] = [
            'air' => $d['air'] === null ? null : (int) $d['air'],
            'compost' => $d['compost'] === null ? null : (int) $d['compost'],
            'RH' => $d['RH'] === null ? null : (int) $d['RH'],
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
    return db_all('SELECT * FROM `' . T_VIEW . '` ORDER BY `reading_id` DESC, `saloon_id` ASC LIMIT ' . $limit);
}

/**
 * ثبت یک نوبت خوانش جدید
 * @param array $rows [saloonId => ['air'=>, 'compost'=>, 'RH'=>]]
 * @param string|null $readAt
 * @return int شناسه نوبت خوانش
 */
function insert_reading(array $rows, ?string $readAt = null): int
{
    $readAt = $readAt ?: date('Y-m-d H:i:s');

    db_run('INSERT INTO `' . T_READINGS . '` (`read_at`) VALUES (?)', [$readAt], 's');
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
        db_run(
            'INSERT INTO `' . T_SALOON_D . '` (`reading_id`, `saloon_id`, `air`, `compost`, `RH`) VALUES ' . implode(',', $values),
            $params,
            $types
        );
    }

    return $readingId;
}
