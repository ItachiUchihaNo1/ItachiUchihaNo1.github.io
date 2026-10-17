# اجزای تحویل

- `morshed-cpanel-package.zip`: سورس، سه بخش استقرار، شش migration، SQL یکپارچه نصب تازه، تنظیمات نمونه، تست‌ها، راهنمای کار و راهنمای cPanel.
- `morshed-deploy/site-public.zip`: محتوا را مستقیماً در Document Root سایت آزمایشی Extract کنید.
- `morshed-deploy/admin-public.zip`: محتوا را مستقیماً در Document Root مدیریت آزمایشی Extract کنید.
- `morshed-deploy/core-private.zip`: پوشه morshed-core را در محل خصوصی بیرون تمام ریشه‌های وب Extract کنید.

مسیرهای پیش‌فرض ورودی برای سه پوشه sibling کار می‌کنند. اگر نام یا عمق پوشه خصوصی فرق دارد، require ثابت هر index.php را طبق INSTALL-CPANEL-FA.md تنظیم کنید. هیچ‌کدام از zipها config پرشده، فایل جلسه، حساب یا محتوای نمونه ندارند. فایل‌های tests/tools/docs نباید در Document Root قرار بگیرند.

MANIFEST-SHA256.json اثرانگشت فایل‌ها را دارد. بسته‌بندی با tools/package.py انجام شده و zip دوباره خوانده و با بایت‌های سورس مقایسه می‌شود. hash خود zip نیز کنار بسته ارائه می‌شود.

از START-HERE-FA.md شروع کنید؛ بعد نصب آزمایشی، راهنمای استفاده و STATUS-FA.md را بخوانید. این تحویل تأیید انتشار عمومی همه قابلیت‌های سند نیست؛ موارد مسدود و ناتمام در STATUS روشن شده‌اند. هیچ تغییری در هاست واقعی شما انجام نشده است.
