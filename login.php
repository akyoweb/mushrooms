<?php
require_once __DIR__ . '/auth.php';

auth_session_start();

$isFirstRun = admin_count() === 0;
$next = basename((string) ($_GET['next'] ?? $_POST['next'] ?? 'admin.php'));
$allowedNext = ['admin.php', 'dashboard.php'];
if (!in_array($next, $allowedNext, true)) {
    $next = 'admin.php';
}

// از قبل وارد شده است
if (auth_check() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . base_url() . $next);
    exit;
}

$errors = [];
$success = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors[] = 'توکن امنیتی نامعتبر است؛ صفحه را دوباره باز کنید.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));

        if ($isFirstRun) {
            // اولین ورود: ساخت حساب مدیر
            $result = create_first_admin($username, (string) ($_POST['password'] ?? ''), (string) ($_POST['name'] ?? ''));
            if (!$result['ok']) {
                $errors[] = $result['error'];
            } else {
                $login = auth_attempt($username, (string) ($_POST['password'] ?? ''));
                if ($login['ok']) {
                    header('Location: ' . base_url() . $next);
                    exit;
                }
                $errors[] = $login['error'] ?? 'حساب ساخته شد اما ورود انجام نشد.';
            }
        } else {
            $login = auth_attempt($username, (string) ($_POST['password'] ?? ''));
            if ($login['ok']) {
                header('Location: ' . base_url() . $next);
                exit;
            }
            $errors[] = $login['error'];
        }
    }
}

$attempts = login_attempt_status();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= $isFirstRun ? 'ساخت حساب مدیر' : 'ورود ادمین' ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" href="style.css" />
</head>

<body class="auth-page">
    <div class="auth-wrap">
        <div class="auth-card">
            <div class="auth-head">
                <span class="brand-icon"><i class="bi bi-shield-lock"></i></span>
                <h1 class="auth-title"><?= $isFirstRun ? 'ساخت حساب مدیر' : 'ورود ادمین' ?></h1>
                <p class="auth-sub">
                    <?= $isFirstRun
                        ? 'هیچ ادمینی وجود ندارد. اولین حساب مدیر را بسازید.'
                        : 'برای مدیریت دماهای مطلوب سالن‌ها وارد شوید.' ?>
                </p>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-danger py-2 small mb-3">
                    <?php foreach ($errors as $error): ?>
                        <div><i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!$isFirstRun && $attempts['remaining'] <= 2): ?>
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="bi bi-clock-history"></i>
                    <?= $attempts['wait'] > 0
                        ? 'ورود موقتاً قفل است. کمی بعد تلاش کنید.'
                        : fa_num($attempts['remaining']) . ' تلاش دیگر باقی مانده است.' ?>
                </div>
            <?php endif; ?>

            <form method="post" action="login.php" class="auth-form" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

                <?php if ($isFirstRun): ?>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold" for="name">نام نمایشی (اختیاری)</label>
                        <input type="text" class="form-control" id="name" name="name" placeholder="مثلاً مدیر فارم" />
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="username">نام کاربری</label>
                    <div class="input-icon">
                        <i class="bi bi-person"></i>
                        <input type="text" class="form-control" id="username" name="username"
                            value="<?= htmlspecialchars($username) ?>" required autofocus dir="ltr"
                            placeholder="admin" />
                    </div>
                </div>

                <div class="mb-2">
                    <label class="form-label small fw-semibold" for="password">رمز عبور</label>
                    <div class="input-icon">
                        <i class="bi bi-key"></i>
                        <input type="password" class="form-control" id="password" name="password" required dir="ltr"
                            placeholder="••••••••" />
                        <button type="button" class="btn-eye" id="togglePass" aria-label="نمایش رمز">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <?php if ($isFirstRun): ?>
                        <div class="form-text small">حداقل ۸ کاراکتر؛ رمز با الگوریتم bcrypt ذخیره می‌شود.</div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-brand w-100 mt-3">
                    <i class="bi <?= $isFirstRun ? 'bi-person-plus' : 'bi-box-arrow-in-left' ?>"></i>
                    <?= $isFirstRun ? 'ساخت حساب و ورود' : 'ورود' ?>
                </button>
            </form>

            <div class="auth-foot">
                <a href="dashboard.php"><i class="bi bi-speedometer2"></i> بازگشت به داشبورد</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script>
        // نمایش/مخفی‌کردن رمز عبور
        $('#togglePass').on('click', function () {
            var $input = $('#password');
            var show = $input.attr('type') === 'password';
            $input.attr('type', show ? 'text' : 'password');
            $(this).find('i').attr('class', show ? 'bi bi-eye-slash' : 'bi bi-eye');
        });
    </script>
</body>

</html>