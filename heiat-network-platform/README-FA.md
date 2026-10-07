# شبکه تجربه هیأت — نسخه MVP قابل اجرا

این پروژه نسخه‌ی واقعی و دیتابیس‌دار مدل ۳ «شبکه تجربه هیأت» است و برای اجرای MVP طراحی شده است. معماری اصلی با مستند محصول هماهنگ است: ثبت مسئله، بررسی ناظر، Matching صاحب تجربه، رزرو، جلسه، رضایت‌سنجی و تبدیل جلسه به تجربه.

## چه چیزهایی الان واقعاً کار می‌کند؟

- ثبت‌نام و ورود با شماره موبایل و OTP
- تکمیل پروفایل کاربر
- ثبت مسئله و پیگیری وضعیت
- صف بررسی برای ناظر شبکه
- تأیید/رد/درخواست اطلاعات تکمیلی توسط ناظر
- Matching صاحب تجربه بر اساس حوزه، شهر، سابقه و زمان آزاد
- پروفایل صاحب تجربه و درخواست تأیید
- تأیید صاحب تجربه توسط ناظر
- تعریف زمان‌های آزاد مشاور
- رزرو جلسه ۳۰ یا ۴۵ دقیقه‌ای
- جلوگیری از رزرو همزمان یک Slot
- صفحه جلسات من
- اتاق جلسه مبتنی بر Jitsi
- ثبت پایان جلسه
- رضایت‌سنجی
- بانک تجربه
- تبدیل جلسه انجام‌شده به تجربه توسط مدیر دانش
- جست‌وجوی تجربه و صاحب تجربه
- نقش‌های هیأت‌جو، صاحب تجربه، ناظر، مدیر دانش و ادمین
- لاگ بعضی عملیات مدیریتی
- Docker + PostgreSQL + Caddy + HTTPS خودکار

## چیزهایی که برای نسخه نهایی باید به سرویس بیرونی متصل شوند

این موارد بدون حساب/کلید سرویس‌دهنده نمی‌توانند «واقعی» شوند و در این پروژه محل اتصالشان آماده است:

1. **ارسال پیامک OTP** — در توسعه کد در لاگ نمایش داده می‌شود. در تولید `SMS_PROVIDER=webhook` را فعال کنید و وب‌هوک پنل پیامکی خود را وارد کنید.
2. **جلسه تصویری** — پیش‌فرض به Jitsi متصل است. برای حریم بالاتر، Jitsi اختصاصی خودتان را روی دامنه جدا بالا بیاورید و `JITSI_BASE_URL` را تغییر دهید.
3. **ضبط جلسه، تبدیل صوت به متن و خلاصه هوشمند** — هنوز به سرویس ذخیره فایل/ASR/AI متصل نشده است. ساختار Experience و پنل مدیر دانش آماده است، اما این مرحله فعلاً دستی است.
4. **فایل پیوست** — مدل دیتابیس دارد اما آپلود به Object Storage هنوز اضافه نشده است.

برای راه‌اندازی اولیه و استفاده واقعی کاربران، موارد ۱ و ۲ کافی‌اند. موارد ۳ و ۴ می‌توانند فاز دوم باشند.

---

# 1) تکنولوژی‌ها

- Frontend + Backend: Next.js
- Database: PostgreSQL
- ORM: Prisma
- Authentication: OTP + signed httpOnly cookie
- Reverse proxy / SSL: Caddy
- Deployment: Docker Compose
- Video: Jitsi

مزیت این معماری این است که فرانت و API در یک پروژه‌اند و برای تیم کوچک نگهداری ساده‌تری دارد.

---

# 2) پیش‌نیاز سرور

برای تست محلی:

- Node.js 22+
- PostgreSQL 16+ یا Docker Desktop

برای سایت واقعی:

- VPS Ubuntu 24.04 یا مشابه
- حداقل 2 vCPU / 4GB RAM برای شروع
- دامنه یا ساب‌دامین مثل `app.example.ir`
- دسترسی SSH
- Docker و Docker Compose

اگر Jitsi را هم روی همان VPS خودتان self-host کنید، سرور قوی‌تری لازم دارید. پیشنهاد بهتر برای شروع این است که اپ و دیتابیس روی VPS اصلی باشند و جلسه تصویری جدا باشد.

---

# 3) اجرای محلی با Docker

## مرحله اول — فایل محیطی

در ریشه پروژه:

```bash
cp .env.example .env
```

در `.env` حداقل این موارد را تغییر دهید:

```env
DOMAIN=localhost
AUTH_SECRET=یک-رشته-تصادفی-خیلی-طولانی
OTP_PEPPER=یک-رشته-تصادفی-متفاوت-خیلی-طولانی
POSTGRES_PASSWORD=یک-رمز-قوی
DATABASE_URL=postgresql://heiat:رمز-قوی@db:5432/heiat?schema=public
ADMIN_PHONE=شماره-موبایل-مدیر
SMS_PROVIDER=development
SEED_DEMO_DATA=true
```

برای ساخت secret در لینوکس:

```bash
openssl rand -hex 32
```

دو بار اجرا کنید و خروجی‌ها را جداگانه برای `AUTH_SECRET` و `OTP_PEPPER` قرار دهید.

## مرحله دوم — بالا آوردن دیتابیس

```bash
docker compose up -d db
```

## مرحله سوم — ساخت Image اپ

```bash
docker compose build app
```

## مرحله چهارم — ایجاد جداول

```bash
docker compose run --rm app npx prisma db push
```

## مرحله پنجم — ساخت ادمین و داده نمونه

```bash
docker compose run --rm app npm run db:seed
```

## مرحله ششم — اجرای اپ

برای تست محلی می‌توانید از فایل Compose توسعه استفاده کنید:

```bash
docker compose -f docker-compose.dev.yml --env-file .env up -d
```

سپس `http://localhost:3000` را باز کنید.

یا مستقیم Node را اجرا کنید. اگر PostgreSQL را بیرون Docker اجرا می‌کنید، در `DATABASE_URL` به‌جای `db` از `localhost` استفاده کنید:

```bash
npm install
npx prisma generate
npx prisma db push
npm run db:seed
npm run dev
```

و سپس:

```text
http://localhost:3000
```

در حالت `SMS_PROVIDER=development` کد OTP داخل ترمینال چاپ می‌شود و در صفحه ورود نیز در حالت توسعه نمایش داده می‌شود.

---

# 4) اجرای واقعی روی دامنه

فرض مثال:

```text
app.example.ir
```

## DNS

در پنل دامنه یک رکورد A بسازید:

```text
Name: app
Type: A
Value: IP_VPS
```

صبر کنید DNS منتشر شود.

## ورود به VPS

```bash
ssh root@IP_VPS
```

## نصب Docker

روی Ubuntu:

```bash
apt update
apt install -y docker.io docker-compose-v2 git
systemctl enable --now docker
```

## انتقال پروژه

می‌توانید ZIP را روی سرور آپلود کنید یا پروژه را در GitHub/GitLab خصوصی قرار دهید و clone کنید.

مثلاً:

```bash
mkdir -p /opt/heiat
cd /opt/heiat
```

فایل‌ها را اینجا قرار دهید.

## تنظیم .env تولید

```bash
cp .env.example .env
nano .env
```

نمونه:

```env
NODE_ENV=production
APP_URL=https://app.example.ir
DOMAIN=app.example.ir
AUTH_SECRET=...
OTP_PEPPER=...
POSTGRES_DB=heiat
POSTGRES_USER=heiat
POSTGRES_PASSWORD=...
DATABASE_URL=postgresql://heiat:...@db:5432/heiat?schema=public
ADMIN_PHONE=0912xxxxxxx
SEED_DEMO_DATA=false
SMS_PROVIDER=webhook
SMS_WEBHOOK_URL=https://YOUR-SMS-ADAPTER.example/send
SMS_WEBHOOK_TOKEN=...
JITSI_BASE_URL=https://meet.jit.si
```

## ساخت و آماده‌سازی دیتابیس

```bash
docker compose up -d db
docker compose build app
docker compose run --rm app npx prisma db push
docker compose run --rm app npm run db:seed
```

## اجرای همه سرویس‌ها

```bash
docker compose up -d
```

بررسی:

```bash
docker compose ps
```

لاگ اپ:

```bash
docker compose logs -f app
```

لاگ Caddy:

```bash
docker compose logs -f caddy
```

اگر DNS درست باشد، Caddy خودش SSL رایگان می‌گیرد و سایت روی این آدرس باز می‌شود:

```text
https://app.example.ir
```

---

# 5) ورود ادمین

شماره‌ای که در `.env` قرار داده‌اید:

```env
ADMIN_PHONE=09xxxxxxxxx
```

بعد از اجرای seed این کاربر نقش‌های زیر را دارد:

- ADMIN
- MODERATOR
- KNOWLEDGE_EDITOR
- HAYAT_JOO

با همان شماره از صفحه ورود وارد شوید.

در پروفایل لینک‌های زیر نمایش داده می‌شود:

- پنل ناظر شبکه
- پنل مدیر دانش

---

# 6) اتصال پنل پیامکی واقعی

در پروژه این قرارداد تعریف شده است:

```http
POST SMS_WEBHOOK_URL
Authorization: Bearer SMS_WEBHOOK_TOKEN
Content-Type: application/json

{
  "to": "09123456789",
  "message": "کد ورود شبکه تجربه هیأت: 123456"
}
```

وب‌هوک شما باید این درخواست را بگیرد و با API سرویس پیامکی‌تان ارسال کند و HTTP 2xx برگرداند.

مزیت این طراحی این است که اپ به یک شرکت پیامکی خاص قفل نمی‌شود.

برای تست:

```env
SMS_PROVIDER=development
```

برای تولید:

```env
SMS_PROVIDER=webhook
SMS_WEBHOOK_URL=https://...
SMS_WEBHOOK_TOKEN=...
```

**حتماً قبل از تولید `SMS_PROVIDER=development` را خاموش کنید.**

---

# 7) جریان واقعی کاربر

1. کاربر با شماره موبایل وارد می‌شود.
2. نام، شهر، نقش و نام هیأت را تکمیل می‌کند.
3. مسئله جدید ثبت می‌کند.
4. مسئله وارد صف ناظر می‌شود.
5. ناظر:
   - تأیید می‌کند، یا
   - برای تکمیل برمی‌گرداند، یا
   - رد می‌کند.
6. بعد از تأیید، Matching اجرا می‌شود.
7. کاربر صاحبان تجربه مناسب را می‌بیند.
8. یکی از زمان‌های آزاد را رزرو می‌کند.
9. جلسه ساخته می‌شود.
10. در زمان مقرر وارد اتاق Jitsi می‌شوند.
11. جلسه پایان‌یافته ثبت می‌شود.
12. کاربر امتیاز می‌دهد.
13. مدیر دانش جلسه را به Experience تبدیل می‌کند.
14. تجربه در بانک دانش نمایش داده می‌شود.

---

# 8) جریان صاحب تجربه

1. کاربر وارد پروفایل می‌شود.
2. «درخواست نقش صاحب تجربه» را می‌زند.
3. حوزه‌ها، شرح تجربه، سال سابقه و ظرفیت را وارد می‌کند.
4. ناظر شبکه درخواست را تأیید می‌کند.
5. پروفایل عمومی می‌شود.
6. صاحب تجربه زمان‌های آزاد را وارد می‌کند.
7. کاربران می‌توانند آن Slotها را رزرو کنند.

---

# 9) Matching چگونه کار می‌کند؟

نسخه فعلی امتیاز می‌دهد:

- تطابق حوزه: امتیاز اصلی
- شهر مشابه: امتیاز اضافه
- داشتن زمان آزاد: امتیاز اضافه
- سال تجربه
- تعداد تجربه‌های ثبت‌شده

این نسخه عمداً قابل توضیح و شفاف است. در فاز بعد می‌توانید Semantic Matching/AI را اضافه کنید بدون اینکه ساختار محصول عوض شود.

---

# 10) بکاپ دیتابیس

روی سرور یک پوشه بسازید:

```bash
mkdir -p /opt/backups/heiat
```

بکاپ دستی:

```bash
docker compose exec -T db pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB" > /opt/backups/heiat/heiat-$(date +%F-%H%M).sql
```

حتماً بعداً Cron روزانه و نگهداری چند نسخه را تنظیم کنید.

---

# 11) آپدیت پروژه

قبل از هر آپدیت:

1. بکاپ دیتابیس
2. دریافت نسخه جدید کد
3. build
4. اعمال تغییرات Prisma
5. restart

دستورات:

```bash
docker compose exec -T db pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB" > backup.sql
docker compose build app
docker compose run --rm app npx prisma db push
docker compose up -d app
```

برای پروژه‌ای که وارد تولید جدی شد، به‌جای `db push` باید migration نسخه‌بندی‌شده استفاده شود.

---

# 12) امنیت قبل از انتشار عمومی

این موارد را حتماً انجام دهید:

- `AUTH_SECRET` قوی و تصادفی
- `OTP_PEPPER` جدا از AUTH_SECRET
- پسورد PostgreSQL قوی
- خاموش کردن `SMS_PROVIDER=development`
- `SEED_DEMO_DATA=false`
- محدود کردن SSH به کلید
- فعال کردن Firewall فقط برای 22/80/443
- بکاپ روزانه
- مانیتورینگ فضای دیسک
- ثبت Privacy Policy و Terms
- رضایت صریح قبل از هر نوع ضبط
- عدم قرار دادن شماره شخصی در UI
- استفاده از Jitsi اختصاصی یا سرویس ویدئو با قرارداد مناسب، اگر داده حساس دارید
- اضافه کردن rate-limit سراسری با Redis قبل از رشد زیاد کاربران

---

# 13) ساختار پوشه‌ها

```text
app/
  api/                 APIهای بک‌اند
  admin/               پنل ناظر و مدیر دانش
  expert/              مدیریت صاحب تجربه
  experts/             لیست و پروفایل مشاوران
  issues/              ثبت و پیگیری مسئله
  knowledge/           بانک تجربه
  bookings/            مشاوره‌های من
  sessions/            اتاق و وضعیت جلسه
  login/               OTP
components/            کامپوننت‌های مشترک
lib/                   auth, matching, sms, prisma
prisma/                مدل دیتابیس و seed
Dockerfile
docker-compose.yml
Caddyfile
```

---

# 14) بعد از MVP چه چیزهایی پیشنهاد می‌شود؟

ترتیب توسعه منطقی:

1. اتصال پیامک واقعی
2. تست با ۲۰ تا ۵۰ کاربر محدود
3. اصلاح UX ثبت مسئله و Matching
4. Upload فایل با S3-compatible storage
5. پیام‌رسان داخل اپ
6. ضبط دوطرفه با Consent واقعی
7. Speech-to-Text
8. خلاصه‌سازی و استخراج Experience با AI
9. داشبورد Analytics
10. Push Notification / PWA
11. Matching معنایی با embedding
12. جست‌وجوی پیشرفته بانک تجربه

اول کاربران واقعی را روی چرخه اصلی بیاورید؛ قبل از اثبات این چرخه، ساخت AI سنگین یا ویدئوی کاملاً اختصاصی هزینه اضافه ایجاد می‌کند.
