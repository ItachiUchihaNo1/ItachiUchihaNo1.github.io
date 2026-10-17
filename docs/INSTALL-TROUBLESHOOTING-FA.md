# تشخیص خطای نصب بدون نمایش اسرار

اصلاح bootstrap فقط علت‌های مجاز و غیرمحرمانه را در لاگ ثبت می‌کند؛ متن خام exception، رمز، نام کاربری دیتابیس و مسیر خصوصی ثبت نمی‌شوند. متن عمومی سایت همچنان عمومی می‌ماند.

۱. از bootstrap.php فعلی هسته نسخه پشتیبان خصوصی بگیرید. فایل داخل morshed-installation-diagnostics.zip را در همان پوشه morshed-core جایگزین کنید. local.php و SQL نیازی به جایگزینی ندارند.
۲. صفحه /index.php?r=%2Fsetup را روی دامنه مدیریت یک بار دوباره باز کنید و کد پیگیری تازه را یادداشت کنید.
۳. در File Manager فایل morshed-core/storage/logs/installation.log را باز کنید و خط همان کد پیگیری را پیدا کنید. این فایل خصوصی با نسخه جدید bootstrap ساخته می‌شود. اگر فایل ایجاد نشد، قابلیت نوشتن پوشه خصوصی هسته باید بررسی شود. گزارش پیش‌فرض سرور نیز همچنان ثبت می‌شود؛ در cPanel بخش Metrics → Errors ممکن است همان خط دیده شود. اگر آنجا نبود، محل error_log را از File Manager یا تنظیمات PHP دامنه بررسی کنید. نام و محل لاگ بسته به میزبان فرق دارد؛ لازم نیست فایل تنظیمات یا کل لاگ را برای پشتیبانی گفتگو ارسال کنید.

نمونه غیرمحرمانه:
Morshed incident 123456abcdef reason=DB_ACCESS_DENIED

| علت | بررسی بعدی |
|---|---|
| CONFIG_FILE_MISSING | فایل دقیقاً morshed-core/config/local.php باشد؛ نام local.php.txt درست نیست. |
| CONFIG_PHP_SYNTAX | نقل‌قول‌ها، ویرگول‌ها و ساختار PHP فایل خصوصی بررسی شوند؛ محتوای خصوصی را در چت نفرستید. |
| CONFIG_APP_KEY_OR_ENVIRONMENT | app_key باید دقیقاً ۶۴ نویسه 0-9 و a-f باشد؛ environment برابر staging یا production باشد. |
| CONFIG_DATABASE_INCOMPLETE / CONFIG_DATABASE_DSN | آرایه database و dsn/user/password و پیشوند mysql: را طبق نمونه بررسی کنید. |
| DB_ACCESS_DENIED | نام کامل کاربر DB، رمز واقعی همان کاربر، میزبان و مجوز اتصال بررسی شوند. رمز ورود cPanel لزوماً رمز DB نیست. |
| DB_PERMISSION_DENIED / DB_QUERY_PERMISSION_DENIED | کاربر DB به همان دیتابیس وصل و دارای مجوز لازم باشد. |
| DB_NOT_FOUND / DB_NOT_SELECTED | dbname در DSN نام دقیق دیتابیس انتخاب‌شده در phpMyAdmin، همراه پیشوند حساب باشد. |
| DB_CONNECTION_FAILED / DB_CONNECTION_LOST | اتصال localhost/socket و سرویس DB یا محدودیت میزبان بررسی شود. |
| DB_TABLE_MISSING / DB_COLUMN_MISSING / SCHEMA_VERSION_MISMATCH | Import و نسخه schema بررسی شوند؛ سورس حاضر schema 6 می‌خواهد. |
| PHP_REQUIREMENTS_MISSING | PHP مؤثر دامنه 8.2+ و pdo_mysql باشد؛ PHP phpMyAdmin معیار PHP دامنه نیست. |
| SESSION_DIRECTORY_UNWRITABLE / SESSION_START_FAILED | قابلیت ساخت/نوشتن پوشه خصوصی storage/sessions برای کاربر PHP بررسی شود؛ 777 راه‌حل پیشنهادی نیست. |
| APPLICATION_FAILED / DB_QUERY_FAILED | علت هنوز دقیق مشخص نشده؛ کد مجاز و مرحله خطا برای بررسی بعدی ارسال شود. |

برای بررسی نسخه دیتابیس، فقط این query خواندنی را در DB پروژه اجرا کنید:
SELECT version, description FROM m_schema_migrations ORDER BY version;

نسخه‌های 1 تا 6 باید وجود داشته باشند. فایل یکپارچه نصب را دوباره روی DB نصب‌شده اجرا نکنید؛ ابتدا علت معلوم شود. این اصلاح، دورزدن احراز هویت، خاموش‌کردن TLS یا دست‌کاری اطلاعات کاربران نیست.

مرجع بخش Errors در cPanel: https://docs.cpanel.net/cpanel/metrics/errors/

مسیر ثابت خصوصی، فقط زمان UTC، کد پیگیری و reason مجاز را ثبت می‌کند و سقف یک مگابایت دارد. لاگ جایگزین حسابرسی دیتابیس نیست. هیچ تنظیم محرمانه‌ای داخل فایل اصلاحی نیست. آزمون ساخت فایل و رد ورودی دلخواه نیز موفق بود.
