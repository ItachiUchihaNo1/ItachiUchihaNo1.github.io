# معماری پیاده‌شده

## چیدمان استقرار

```text
/home/CPANEL_USER/
  morshed-core/                  # خصوصی؛ بیرون تمام ریشه‌های وب
    bootstrap.php
    config/local.php             # فقط روی سرور، در بسته نیست
    src/Core, Identity, Access, Legal, Product, Http, Notifications
    templates/
    database/migrations/001..006
    bin/maintenance.php
    storage/sessions/site,admin
    storage/files/               # فایل خصوصی جلسات
    storage/content-files/       # فایل محتوا؛ انتشار فقط با مجوز مدل داده
  morshedapp.ir/                  # Document Root سایت
    index.php, .htaccess, assets/
  admin.morshedapp.ir/            # Document Root مدیریت
    index.php, .htaccess, assets/
```

نمونه‌ها مسیر حساب شما را حدس نمی‌زنند. ریشه‌ها طبق تصویر مستقل‌اند؛ public_html برای مرشد فرض نشده است. محیط آزمایش، DB و دو ورودی و config جدا دارد. تنظیم‌ها و مسیر هسته از درخواست مرورگر گرفته نمی‌شوند.

## جریان درخواست

ورودی ثابت هر دامنه realm را تعیین می‌کند. bootstrap تنظیم خصوصی و headers را بار می‌کند. Application هویت، Host/HTTPS، CSRF و نشست همان realm را بررسی می‌کند. ProductController مسیرهای محصول را به سرویس‌های Content، Consultations، Operations، Delivery و سرویس فایل می‌دهد. قالب‌ها فقط نمایش می‌دهند؛ منطق و query در HTML اولیه یا مرورگر نیست.

سرویس‌ها علاوه بر کنترل HTTP، نقش زنده، مالکیت و وضعیت را در سرور بررسی می‌کنند. مدیر محتوا مجوز عملیات کاربران، رزرو، شکایت یا تنظیمات حساس ندارد. مدیر سامانه دسترسی خودکار به آرشیو ندارد. مهلت نشست سایت و مدیریت جداست و cookie دامنه والد تنظیم نمی‌شود.

## مدل داده

هویت: users، admin_accounts، auth_identities، auth_sessions، otp_challenges، rate_limits، external_flows، identity_assertions.
محتوا: mentors، terms، mentor_terms، experiences، experience_versions، content_assets، experience_assets، store_versions و store_publication.
جلسه: schedule_settings، schedule_rules، schedule_exceptions، bookings، booking_events، time_proposals، messages، private_files.
عملیات: tickets، ticket_messages، feedback، archive_grants، organizations، memberships، notifications، notification_templates، notification_deliveries، public_settings، legal_versions، legal_publications، user_acceptances، audit_logs.

SQL مرجع دقیق FK، CHECK، UNIQUE و INDEX است. پیام‌ها sequence سراسری پایدار دارند؛ دریافت بر اساس after با مالکیت و limit است. نسخه تجربه و مستند آن immutable می‌ماند؛ تغییرات، ردیف نسخه جدید می‌سازند. ویترین نیز snapshot مستقل دارد و انتشار اشاره‌گر جداست.

## هم‌زمانی

نوشتن عملیات حساس محصول در تراکنش و با قفل ردیف installation سری می‌شود؛ محدودیت نقش و وضعیت زیر همان قفل بررسی می‌شود. ظرفیت رزرو علاوه بر آن، ردیف تقویم مرشد را قفل می‌کند. این انتخاب برای نسخه اول کم‌ترافیک cPanel، قابل آزمون و ساده است؛ در مقیاس بیشتر می‌توان قفل‌ها را با حفظ ترتیب و آزمون هم‌زمانی ریزتر کرد. ادعای مقیاس نامحدود نشده است.

کلید یکتای کاربر/درخواست جلوی رزرو تکراری را می‌گیرد؛ داده متفاوت با همان کلید رد می‌شود. پیشنهاد زمان ظرفیت جدید را تضمین نمی‌کند؛ هنگام پذیرش دوباره کنترل می‌شود و در شکست، زمان قبلی باقی می‌ماند. Cron شرط صحت ظرفیت یا انقضا نیست.

## ذخیره و ضبط

ACK پیام تنها پس از commit صادر می‌شود. پیکربندی flush دیتابیس برای آغاز نوشتن جلسه بررسی می‌شود. خطای ذخیره، متن فرم یا صف در حافظه همان صفحه را پاک نمی‌کند. داده کسب‌وکار در localStorage/sessionStorage نیست. فایل‌ها با نام تصادفی، MIME/پسوند/اندازه محدود و SHA-256، بیرون وب هستند. فایل عمومی محتوا نیز تنها با انتشار نسخه/پروفایل یا مجوز بررسی ارائه می‌شود.

سرویس رسانه صوت/تصویر در این معماری فرض نشده است. جزئیات شرط اتصال در MEDIA-REQUIREMENTS-FA.md آمده. دامنه PHP برای API و صفحات کافی است؛ انتقال و ضبط صوت/تصویر به سرویس مجزا یا زیرساخت تأییدشده نیاز دارد.
