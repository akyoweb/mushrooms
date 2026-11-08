<?php
/**
 * احراز هویت ادمین
 * ------------------------------------------------------------
 * - سشن امن (HttpOnly + SameSite) و انقضای خودکار
 * - رمز عبور با password_hash (bcrypt)
 * - توکن CSRF برای فرم‌ها
 * - محدودیت تلاش ورود (۵ بار در ۱۵ دقیقه به ازای هر آی‌پی)
 */

require_once __DIR__ . '/config.php';

/** نام سشن (جداست تا با پروژه‌های دیگر htdocs قاطی نشود) */
const ADMIN_SESSION = 'mushroom_admin';

/** مدت اعتبار سشن ورود (ثانیه) */
const ADMIN_SESSION_TTL = 7200;

/** حداکثر تلاش ناموفق ورود و پنجره زمانی */
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW = 900;

/** شروع سشن امن */
function auth_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // سشن فقط برای مسیر همین پروژه
    $path = rtrim((string) parse_url(base_url(), PHP_URL_PATH), '/');
    if ($path === '') {
        $path = '/';
    }

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $path . '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, $path . '/; SameSite=Lax', null, false, true);
    }

    session_name(ADMIN_SESSION);
    session_start();

    // انقضای خودکار
    if (isset($_SESSION['admin']['time']) && time() - (int) $_SESSION['admin']['time'] > ADMIN_SESSION_TTL) {
        unset($_SESSION['admin']);
    }
    if (auth_check()) {
        $_SESSION['admin']['time'] = time();
    }
}

/** توکن CSRF برای سشن جاری */
function csrf_token(): string
{
    auth_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** نمایش فیلد hidden توکن CSRF */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/** اعتبارسنجی توکن CSRF */
function csrf_valid(?string $token): bool
{
    auth_session_start();
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/** خروج JSON برای درخواست‌های نامعتبر CSRF */
function csrf_abort(): void
{
    if (function_exists('json_out') && strpos($_SERVER['SCRIPT_NAME'] ?? '', 'ajax.php') !== false) {
        json_out(['ok' => false, 'error' => 'توکن امنیتی نامعتبر است؛ صفحه را دوباره باز کنید.'], 419);
    }
    http_response_code(419);
    echo 'توکن امنیتی نامعتبر است؛ صفحه را دوباره باز کنید.';
    exit;
}

/** آیا کاربر ادمین وارد شده است؟ */
function auth_check(): bool
{
    auth_session_start();
    return !empty($_SESSION['admin']['id']);
}

/** اطلاعات ادمین جاری */
function auth_user(): ?array
{
    auth_session_start();
    return $_SESSION['admin'] ?? null;
}

/** استفاده در بالای صفحات محافظت‌شده؛ در صورت نبود ورود، ریدایرکت به login.php */
function require_admin(): void
{
    if (!auth_check()) {
        $back = basename($_SERVER['SCRIPT_NAME'] ?? 'admin.php');
        header('Location: ' . base_url() . 'login.php?next=' . urlencode($back));
        exit;
    }
}

/** تعداد رکورد ادمین (برای صفحه ورود) */
function admin_count(): int
{
    try {
        ensure_tables();
        $row = db_all('SELECT COUNT(*) AS c FROM `admins`');
        return (int) ($row[0]['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/* ------------------------------------------------------------------ */
/* محدودیت تلاش ورود                                                  */
/* ------------------------------------------------------------------ */

function login_attempts_file(): string
{
    $dir = __DIR__ . '/tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/login_attempts.json';
}

function login_attempts_all(): array
{
    $file = login_attempts_file();
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

/** تلاش‌های باقی‌مانده و ثانیه‌های انتظار برای آی‌پی جاری */
function login_attempt_status(): array
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $all = login_attempts_all();
    $entry = $all[$ip] ?? ['count' => 0, 'first' => time()];

    if (time() - (int) $entry['first'] > LOGIN_WINDOW) {
        $entry = ['count' => 0, 'first' => time()];
    }

    return [
        'count' => (int) $entry['count'],
        'remaining' => max(0, LOGIN_MAX_ATTEMPTS - (int) $entry['count']),
        'wait' => (int) $entry['count'] >= LOGIN_MAX_ATTEMPTS
            ? max(0, LOGIN_WINDOW - (time() - (int) $entry['first']))
            : 0,
    ];
}

function login_attempt_fail(): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $all = login_attempts_all();
    $entry = $all[$ip] ?? null;

    if (!$entry || time() - (int) $entry['first'] > LOGIN_WINDOW) {
        $entry = ['count' => 0, 'first' => time()];
    }
    $entry['count'] = (int) $entry['count'] + 1;
    $all[$ip] = $entry;

    // پاک‌سازی رکوردهای قدیمی
    foreach ($all as $key => $value) {
        if (time() - (int) ($value['first'] ?? 0) > LOGIN_WINDOW * 4) {
            unset($all[$key]);
        }
    }

    @file_put_contents(login_attempts_file(), json_encode($all), LOCK_EX);
}

function login_attempt_reset(): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $all = login_attempts_all();
    unset($all[$ip]);
    @file_put_contents(login_attempts_file(), json_encode($all), LOCK_EX);
}

/* ------------------------------------------------------------------ */
/* ورود / خروج / ساخت ادمین                                           */
/* ------------------------------------------------------------------ */

/**
 * بررسی نام کاربری و رمز
 * @return array{ok:bool, error?:string, user?:array}
 */
function auth_attempt(string $username, string $password): array
{
    $status = login_attempt_status();
    if ($status['wait'] > 0) {
        return [
            'ok' => false,
            'error' => 'تلاش‌های ناموفق زیاد بوده است. لطفاً ' . fa_num(ceil($status['wait'] / 60)) . ' دقیقه دیگر تلاش کنید.',
        ];
    }

    ensure_tables();

    $rows = db_all('SELECT * FROM `admins` WHERE `username` = ? LIMIT 1', [$username], 's');
    $admin = $rows[0] ?? null;

    if (!$admin || !password_verify($password, $admin['password'])) {
        login_attempt_fail();
        $left = login_attempt_status();
        return [
            'ok' => false,
            'error' => 'نام کاربری یا رمز عبور اشتباه است.' . ($left['remaining'] > 0
                ? ' (' . fa_num($left['remaining']) . ' تلاش باقی مانده)'
                : ' ورود موقتاً قفل شد.'),
        ];
    }

    // بازنویسی هش در صورت تغییر الگوریتم پیش‌فرض
    if (password_needs_rehash($admin['password'], PASSWORD_DEFAULT)) {
        db_run('UPDATE `admins` SET `password` = ? WHERE `id` = ?', [password_hash($password, PASSWORD_DEFAULT), (int) $admin['id']], 'si');
    }

    login_attempt_reset();
    auth_session_start();
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['admin'] = [
        'id' => (int) $admin['id'],
        'username' => $admin['username'],
        'name' => $admin['name'] !== '' ? $admin['name'] : $admin['username'],
        'time' => time(),
    ];

    if (empty($_SESSION['admin']['id'])) {
        return ['ok' => false, 'error' => 'ساخت سشن ورود ناموفق بود؛ دسترسی پوشه tmp را بررسی کنید.'];
    }

    return ['ok' => true, 'user' => $_SESSION['admin']];
}

/** خروج از حساب */
function auth_logout(): void
{
    auth_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', false, true);
    }
    session_destroy();
}

/**
 * ساخت اولین ادمین (فقط وقتی هیچ ادمینی وجود ندارد)
 * @return array{ok:bool, error?:string}
 */
function create_first_admin(string $username, string $password, string $name = ''): array
{
    ensure_tables();

    if (admin_count() > 0) {
        return ['ok' => false, 'error' => 'ادمین از قبل ساخته شده است.'];
    }

    $username = trim($username);
    if (!preg_match('/^[a-zA-Z0-9_.\-]{3,50}$/', $username)) {
        return ['ok' => false, 'error' => 'نام کاربری باید ۳ تا ۵۰ کاراکتر و فقط شامل حروف انگلیسی، عدد، نقطه، خط تیره و آندرلاین باشد.'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'رمز عبور باید حداقل ۸ کاراکتر باشد.'];
    }

    db_run(
        'INSERT INTO `admins` (`username`, `password`, `name`) VALUES (?, ?, ?)',
        [$username, password_hash($password, PASSWORD_DEFAULT), trim($name)],
        'sss'
    );

    return ['ok' => true];
}
