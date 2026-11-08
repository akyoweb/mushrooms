# 🍄 مانیتورینگ سالن‌های پرورش قارچ

سیستم پایش لحظه‌ای **دمای هوا**، **دمای کمپوست** و **رطوبت نسبی (RH)** برای ۱۲ سالن پرورش قارچ.

ساخته‌شده با **PHP + MySQL + jQuery + Bootstrap 5 + Chart.js**.

---

## ✨ امکانات

- **داشبورد زنده**: ۱۲ کارت سالن، هر سالن با سه شاخص (هوا / کمپوست / رطوبت) و نمایش وضعیت رنگی
- **هشدارها**: تشخیص خودکار مقادیر خارج از محدودهٔ مطلوب + لیست هشدارهای فعال و پرش به کارت سالن
- **نمودار روند**: میانگین سه فیلد روی همه سالن‌ها (دمای هوا و کمپوست روی محور راست، رطوبت روی محور چپ)
- **جدول تفصیلی** همه سالن‌ها با فیلتر (سالم / دارای هشدار) و جستجو
- **بازخوانی خودکار** هر ۳۰ ثانیه (و توقف آن هنگام پنهان بودن تب)
- **فرم ثبت خوانش**: ورود گروهی مقدار برای همه سالن‌ها، پرکردن نمونه، اعتبارسنجی سمت کلاینت و سرور، ارسال با AJAX
- **رابط RTL فارسی**، ریسپانسیو و سازگار با موبایل
- **سازگار با چند ساختار جدول** (افقی، عمودی و ساختار قدیمی) بدون تغییر کد

---

## 🗂 ساختار پروژه

```
temps/
├── config.php        # تنظیمات: فیلدها، تعداد سالن، بازه‌های مطلوب، تشخیص ساختار جدول
├── database.php      # اتصال MySQLi + توابع کمکی کوئری
├── monitor.php       # لایه داده: خواندن خوانش‌ها، ارزیابی، ثبت
├── ajax.php          # API JSON (snapshot / history / save / simulate)
├── dashboard.php     # صفحه داشبورد
├── index.php         # فرم ثبت خوانش
├── style.css         # تم اختصاصی (RTL)
└── js/
    ├── app.js        # منطق داشبورد (jQuery + Chart.js)
    └── entry.js      # منطق فرم ثبت خوانش
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
- ثبت خوانش: <http://localhost/temps/index.php>

بدون سنسور هم می‌توانید دکمهٔ **«ثبت خوانش تستی»** در داشبورد را بزنید تا دادهٔ نمونه ساخته شود.

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
