# 🍄 مانیتورینگ سالن‌های پرورش قارچ

سیستم پایش لحظه‌ای **دمای هوا**، **دمای کمپوست** و **رطوبت نسبی (RH)** برای ۱۲ سالن پرورش قارچ.

ساخته‌شده با **PHP + MySQL + jQuery + Bootstrap 5 + Chart.js**.

---

## ✨ امکانات

- **داشبورد زنده**: ۱۲ کارت سالن، هر سالن با سه شاخص (هوا / کمپوست / رطوبت) و نمایش وضعیت رنگی
- **هشدارها**: تشخیص خودکار مقادیر خارج از محدودهٔ مطلوب + لیست هشدارهای فعال و پرش به کارت سالن
- **نمودار روند**: میانگین سه فیلد روی همه سالن‌ها (دمای هوا و کمپوست روی محور راست، رطوبت روی محور چپ)
- **جدول تفصیلی** همه سالن‌ها با فیلتر (سالم / دارای هشدار) و جستجو
- **ثبت خودکار هر ۳۰ دقیقه**: خوانش دماسنج‌ها با زمان دقیق (میکروثانیه) در دیتابیس ذخیره می‌شود؛ در هر بازه فقط یک رکورد وجود دارد
- **بازخوانی خودکار** هر ۳۰ ثانیه (و توقف آن هنگام پنهان بودن تب)
- **جدول بازه‌های نیم‌ساعتی** در داشبورد: وضعیت ثبت هر بازه و زمان دقیق ثبت
- **ورود ادمین** با رمز هش‌شده (bcrypt)، توکن CSRF و محدودیت تلاش ناموفق (۵ بار در ۱۵ دقیقه)
- **تعیین بازه مطلوب هر سالن** به‌صورت جداگانه توسط ادمین (هر سالن می‌تواند بازهٔ خودش را داشته باشد)
- **رابط RTL فارسی**، ریسپانسیو و سازگار با موبایل
- **سازگار با چند ساختار جدول** (افقی، عمودی و ساختار قدیمی) بدون تغییر کد

---

## 🗂 ساختار پروژه

```
temps/
├── config.php        # تنظیمات: فیلدها، تعداد سالن، بازه‌های پیش‌فرض، تشخیص ساختار جدول
├── database.php      # اتصال MySQLi + توابع کمکی کوئری
├── settings.php      # بازه مطلوب هر سالن + ساخت خودکار جدول‌های admins و admin_settings
├── auth.php          # سشن، CSRF، ورود/خروج، محدودیت تلاش ورود
├── monitor.php       # لایه داده: خواندن خوانش‌ها، ارزیابی، ثبت، ثبت خودکار بازه‌ها
├── cron.php          # ثبت خودکار خوانش بازه جاری (برای cron / Task Scheduler)
├── ajax.php          # API JSON (snapshot / history / save / simulate / capture)
├── dashboard.php     # صفحه داشبورد
├── index.php         # نمای فقط‌خواندنی دماهای مطلوب (خوانش واقعی از دماسنج‌ها)
├── login.php         # ورود ادمین (+ ساخت حساب مدیر در اولین اجرا)
├── logout.php        # خروج
├── admin.php         # پنل ادمین: تعیین دما/رطوبت مطلوب هر سالن
├── style.css         # تم اختصاصی (RTL)
└── js/
    ├── app.js        # منطق داشبورد (jQuery + Chart.js)
    ├── entry.js      # (قدیمی) منطق فرم ثبت دستی — دیگر استفاده نمی‌شود
    └── admin.js      # منطق پنل ادمین (کپی/اعتبارسنجی بازه‌ها)
```

---

## ⚙️ نصب و راه‌اندازی

### ۱) پیش‌نیازها

- PHP 8.0+ (با افزونه mysqli)
- MySQL / MariaDB
- Apache (مثلاً XAMPP)

### ۲) قرار دادن پروژه

پوشهٔ پروژه را در ریشهٔ وب‌سرور کپی کنید، مثلاً:

```
C:\xampp\htdocs\temps
```

### ۳) ساخت دیتابیس

```sql
CREATE DATABASE mushroom DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_persian_ci;
USE mushroom;

-- جدول خوانش‌ها (یک سطر = یک خوانش هم‌زمان همه سالن‌ها)
CREATE TABLE temps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  air1     INT NOT NULL DEFAULT 0,  compost1 INT NOT NULL DEFAULT 0,  RH1     INT NOT NULL DEFAULT 0,
  air2     INT NOT NULL DEFAULT 0,  compost2 INT NOT NULL DEFAULT 0,  RH2     INT NOT NULL DEFAULT 0,
  -- ...
  air12    INT NOT NULL DEFAULT 0,  compost12 INT NOT NULL DEFAULT 0, RH12    INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- جدول سالن‌ها
CREATE TABLE saloons (
  salon1 INT NOT NULL DEFAULT 0, salon2 INT NOT NULL DEFAULT 0, salon3 INT NOT NULL DEFAULT 0,
  salon4 INT NOT NULL DEFAULT 0, salon5 INT NOT NULL DEFAULT 0, salon6 INT NOT NULL DEFAULT 0,
  salon7 INT NOT NULL DEFAULT 0, salon8 INT NOT NULL DEFAULT 0, salon9 INT NOT NULL DEFAULT 0,
  salon10 INT NOT NULL DEFAULT 0, salon11 INT NOT NULL DEFAULT 0, salon12 INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
```

> ساختار پیشنهادی: برای هر سالن سه ستون جدا (`air1..12`، `compost1..12`، `RH1..12`).
> برنامه با ساختارهای دیگر هم کار می‌کند (پایین‌تر را ببینید).

### ۴) تنظیمات اتصال

در فایل `database.php`:

```php
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'mushroom');
```

### ۵) اجرا

- داشبورد: <http://localhost/temps/dashboard.php>
- دماهای مطلوب: <http://localhost/temps/index.php>
- ورود ادمین: <http://localhost/temps/login.php>

بدون سنسور هم می‌توانید دکمهٔ **«ثبت خوانش تستی»** در داشبورد را بزنید تا دادهٔ نمونه ساخته شود.

---

## ⏱ ثبت هر ۳۰ دقیقه با زمان دقیق

هر خوانش در جدول `readings` با دو زمان ذخیره می‌شود:

| ستون | نوع | توضیح |
|---|---|---|
| `read_at` | `DATETIME(6)` | زمان دقیق ثبت با میکروثانیه (مثلاً `2026-11-09 10:30:00.123456`) |
| `period_start` | `DATETIME` (یکتا) | شروع بازه نیم‌ساعتی؛ مانع ثبت تکراری در یک بازه می‌شود |

مقادیر دما به‌صورت `DECIMAL(5,1)` ذخیره می‌شوند (یک رقم اعشار).

### منبع داده: دماسنج‌ها

`auto_capture()` در `monitor.php` آخرین مقدار هر سالن را از جدول دماسنج‌ها می‌خواند.
جدول باید یکی از این نام‌های ستون را داشته باشد: شناسه سالن (`saloon_id` / `saloon`) و مقدار (`air`/`air_temp`, `compost`, `RH`/`humidity`)
و بهتر است ستون زمان `read_at` داشته باشد. نام جدول را در `config.php` تعیین کنید:

```php
const SENSOR_TABLE = 'sensor_readings'; // خالی = تشخیص خودکار
```

نمونهٔ ساختار جدول دماسنج‌ها:

```sql
CREATE TABLE sensor_readings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  saloon_id TINYINT UNSIGNED NOT NULL,
  air DECIMAL(5,1) NULL,
  compost DECIMAL(5,1) NULL,
  RH DECIMAL(5,1) NULL,
  read_at DATETIME(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

تا وقتی این جدول ساخته نشود، وضعیت با نشان «دماسنج وصل نشده» در داشبورد نمایش داده می‌شود.

### زمان‌بندی (cron)

```bash
# لینوکس — دقیقه ۰ و ۳۰ هر ساعت
0,30 * * * * php /path/to/temps/cron.php
```

```powershell
# ویندوز — Task Scheduler، تکرار روزانه هر ۳۰ دقیقه
C:\xampp\php\php.exe C:\xampp\htdocs\temps\cron.php
```

لاگ اجرا در `tmp/cron.log` ذخیره می‌شود و آخرین اجرا در داشبورد دیده می‌شود.
اگر cron تنظیم نشده باشد، هنگام باز شدن داشبورد بازه‌ای که هنوز خوانش ندارد به‌صورت خودکار ثبت می‌شود.

---

## 🔐 ورود ادمین و دماهای مطلوب هر سالن

در **اولین باز کردن `login.php`**، چون هیچ ادمینی وجود ندارد، فرم «ساخت حساب مدیر» نمایش داده می‌شود؛
نام کاربری، رمز (حداقل ۸ کاراکتر) و نام نمایشی را وارد کنید. رمز با `password_hash()` (bcrypt) ذخیره می‌شود.

### پنل ادمین (`admin.php`)

| قابلیت | توضیح |
|---|---|
| تب فیلدها | جدا کردن دما هوا / دمای کمپوست / رطوبت نسبی |
| بازه هر سالن | حداقل و حداکثر مطلوب به‌صورت مستقل برای هر یک از ۱۲ سالن |
| اعمال روی همه | یک بازه را روی همه سالن‌ها اعمال کنید (بعد ذخیره) |
| کپی ردیف | بازه یک سالن را روی ۱۲ سالن کپی کنید |
| بازگشت به پیش‌فرض | پرکردن فرم با مقادیر `FIELDS['good']` یا حذف همهٔ تنظیمات سفارشی |
| اعتبارسنجی | حداقل < حداکثر و داخل بازه مجاز فیزیکی فیلد |

سالن‌هایی که بازه‌شان تغییر کرده در داشبورد با نشانگر 📌 نمایش داده می‌شوند و داوری (سالم/کمتر از حد/بیشتر از حد)
برای هر سالن با بازهٔ خودش انجام می‌شود.

### امنیت

- سشن با کوکی `SameSite=Lax` + `HttpOnly` و انقضای خودکار ۲ ساعته
- توکن CSRF در همهٔ فرم‌ها و پنل ادمین
- `session_regenerate_id()` بعد از ورود موفق
- قفل موقت ورود: ۵ تلاش ناموفق در ۱۵ دقیقه (بر اساس آی‌پی، در `tmp/login_attempts.json`)
- صفحات `admin.php` و `logout.php` با `require_admin()` محافظت می‌شوند

### جدول‌های خودکار

در اولین استفاده، `admin_settings` و `admins` به‌صورت خودکار ساخته می‌شوند (نیازی به اجرای دستی SQL نیست).
اگر ستون `created_at` در جدول `temps` نباشد، اضافه می‌شود.

---

## 🧩 سازگاری با ساختارهای مختلف جدول `temps`

| ساختار                             | ستون‌ها                                           | پشتیبانی                        |
| ---------------------------------- | ------------------------------------------------- | ------------------------------- |
| افقی شماره‌دار (پیشنهادی)          | `air1..air12`, `compost1..compost12`, `RH1..RH12` | ✅ خودکار                       |
| افقی با ستون‌های `salon1..salon12` | همان ستون‌ها برای هر سه فیلد                      | ✅ خودکار                       |
| عمودی                              | `saloon_id`, `field`, `value`, `created_at`       | ✅ با `AUTODETECT = true`       |
| قدیمی (بدون تفکیک سالن)            | `compost`, `air`, `RH`                            | ✅ میانگین سالن‌ها ذخیره می‌شود |

در کانفیگ `temps_schema()` و `pivot_columns()` در `config.php` / `monitor.php` این تشخیص انجام می‌شود.

---

## 🎛 تنظیم بازه‌های مطلوب

در `config.php`:

```php
const FIELDS = [
    'air'     => ['good' => [15, 18], ...],  // دمای هوا °C
    'compost' => ['good' => [16, 20], ...],  // دمای کمپوست °C
    'RH'      => ['good' => [85, 95], ...],  // رطوبت %
];
```

مقادیر خارج از بازهٔ `good` در داشبورد به‌صورت هشدار (کمتر / بیشتر از حد مطلوب) نمایش داده می‌شوند.

---

## 🔌 API

| Endpoint                                     | متد  | توضیح                                                     |
| -------------------------------------------- | ---- | --------------------------------------------------------- |
| `ajax.php?action=snapshot`                   | GET  | وضعیت جاری همه سالن‌ها، آمار، هشدارها و تاریخچه           |
| `ajax.php?action=history&field=all&limit=40` | GET  | سری زمانی یک فیلد یا همه فیلدها                           |
| `ajax.php` (`action=save`)                   | POST | ثبت خوانش (`air[]`, `compost[]`, `RH[]` یا `air1..12`، …) |
| `ajax.php` (`action=simulate`)               | POST | ساخت یک خوانش نمونه (برای تست)                            |
| `ajax.php` (`action=capture`)                | GET/POST | ثبت خودکار خوانش بازه جاری از دماسنج‌ها (همان کار `cron.php`) |

نمونهٔ ثبت خوانش:

```bash
curl -X POST http://localhost/temps/ajax.php \
  -d "action=save" \
  -d "air[]=16&air[]=17&..." \
  -d "compost[]=18&compost[]=19&..." \
  -d "RH[]=90&RH[]=91&..."
```

---

## 🧪 تست سریع

```bash
php -l ajax.php
php -l monitor.php
node --check js/app.js
```

---

## 📄 لایسنس

این پروژه برای استفادهٔ شخصی/آموزشی آزاد است.
