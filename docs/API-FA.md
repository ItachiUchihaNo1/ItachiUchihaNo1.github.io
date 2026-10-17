# API هویت و محصول

سایت و مدیریت هرکدام API هم‌مبدأ خود را با همان هسته اجرا می‌کنند. اعتبارنامه و نشست سایت در مدیریت معتبر نیست. با pretty_urls=false، مثلاً `/api/v1/me` از طریق `/index.php?r=%2Fapi%2Fv1%2Fme` در دسترس است. هیچ CORS عمومی تعریف نشده است.

POST از JSON object یا فرم URL-encoded پشتیبانی می‌کند. ابتدا GET `/api/v1/csrf` را با CookieJar همان origin بخوانید؛ مقدار csrf را در X-CSRF-Token یا فیلد csrf بفرستید. Cookieهای احراز هویت HttpOnly هستند و توکن احراز هویت در پاسخ JSON داده نمی‌شود. پس از ورود csrf عوض می‌شود و باید دوباره خوانده شود.

| سطح | مسیر پس از /api/v1 | متد و ورودی اصلی |
|---|---|---|
| هر دو | /csrf | GET؛ ساخت/خواندن CSRF همان realm |
| هر دو | /auth/password | POST؛ سایت: phone,password؛ مدیریت: username,password |
| هر دو | /me | GET؛ فقط مشخصات مجاز همان نشست |
| هر دو | /logout | POST |
| سایت | /auth/otp/request | POST؛ phone,purpose=login یا reset |
| سایت | /auth/otp/verify | POST؛ challenge_id,code؛ شماره و مالک از challenge سرور |
| سایت | /auth/password/reset | POST؛ challenge_id,code,new_password |
| سایت | /me/profile | POST؛ display_name؛ user_id و role ورودی نادیده گرفته می‌شوند |
| سایت | /me/password | POST؛ current_password,new_password؛ برای اولین رمز، OTP تازه لازم است |
| هر دو | /me/phone/request | POST؛ phone,current_password؛ مالک از نشست، رمز متناسب با realm جاری |
| هر دو | /me/phone/verify | POST؛ challenge_id,code؛ فقط شماره همان حساب اصلی |
| هر دو | /me/sessions | GET؛ فقط نشست‌های مالک و realm جاری |
| هر دو | /me/sessions/revoke | POST؛ id؛ تغییر شناسه مجوز مالکیت نمی‌دهد |
| هر دو | /me/sessions/revoke-others | POST؛ نشست جاری حفظ می‌شود |
| مدیریت | /me/password | POST؛ current_password,new_password؛ تمام نشست‌های مدیریت همان مدیر بسته می‌شوند |
| سایت | /legal/accept | POST؛ id نسخه منتشرشده فعلی قوانین پایه |
| سایت | /notifications | GET؛ حداکثر ۵۰ اعلان مالک |
| سایت | /notifications/read | POST؛ id؛ مالکیت در SQL بررسی می‌شود |
| مدیریت | /setup | POST؛ token,username,display_name,new_password؛ فقط کلید نصب و قبل از اولین مدیر |
| مدیریت | /users | GET؛ q,page؛ مدیر سامانه |
| مدیریت | /users/status | POST؛ id,status,current_password؛ مدیر سامانه |
| مدیریت | /admins/create | POST؛ user_id,username,new_password,role,current_password؛ مدیر سامانه |
| مدیریت | /admins/role | POST؛ id,role,current_password؛ مدیر سامانه |
| مدیریت | /legal/create | POST؛ title,body؛ نسخه جدید قوانین پایه بدون انتشار |
| مدیریت | /legal/publish | POST؛ id؛ فقط قوانین پایه |

موفقیت عمومی mutation به صورت `{"ok":true}` است؛ درخواست OTP فعال challenge_id و purpose می‌دهد، اما هیچ کدی برنمی‌گرداند. مسیر ارسال وقتی TSMS خاموش است 503/SMS_UNAVAILABLE می‌دهد. پاسخ مبهم ارائه‌دهنده هرگز تحویل واقعی معرفی نمی‌شود. تأیید OTP فقط با کد صحیح، مهلت و تعداد تلاش معتبر موفق می‌شود.

خطا: `{"error":{"code":"...","message":"..."}}`. کدهای رایج: INVALID_INPUT، INVALID_PHONE، INVALID_CODE، INVALID_PASSWORD، INVALID_CREDENTIALS، CSRF_FAILED، UNAUTHENTICATED، ACCESS_DENIED، REAUTH_REQUIRED، RATE_LIMITED، IDENTITY_CONFLICT، IDENTITY_CHANGED، LEGAL_VERSION_CHANGED، SETUP_LOCKED، SERVICE_UNAVAILABLE. خطای غیرمنتظره شناسه رخداد غیرحساس دارد و SQL/stack/request خام برنمی‌گرداند.

## API محصول

همه مسیرهای زیر پس از `/api/v1` و روی origin همان realm هستند. پارامتر id خواندن در query string قرار می‌گیرد، نه داخل مقدار r. مثال: `/index.php?r=%2Fapi%2Fv1%2Fexperience&id=...`.

| realm | مسیر | ورودی / خروجی |
|---|---|---|
| سایت عمومی | GET /, /mentors, /search, /experiences | بلوک‌های عمومی یا rows و تعداد نتایج؛ q,term,topic,issue,sort=relevant/latest,page |
| سایت عمومی | GET /mentor/profile, /experience | id؛ فقط پروفایل فعال یا نسخه منتشرشده |
| سایت عمومی | GET /slots | mentor_id,date=YYYY-MM-DD؛ زمان‌های UTC و برچسب تهران؛ رزرو عمومی باز نمی‌شود |
| سایت | POST /auth/google/start, /auth/eitaa/start | purpose=login/link/reauth؛ state و URL یا صفحه تأیید؛ پیش‌فرض PROVIDER_DISABLED |
| سایت | GET /auth/google/callback | state,code؛ تبادل سرور؛ redirect URI دقیق در راهنما |
| سایت | POST /auth/eitaa/complete | state,init_data؛ HMAC + تأیید رسمی + replay guard |
| سایت | POST /me/identities/unlink | provider؛ احراز تازه و حفظ حداقل یک روش قابل استفاده |
| سایت مرشد | GET /mentor, /mentor/experiences, /mentor/experience, /mentor/calendar | فقط اطلاعات مرشد صاحب نشست؛ id تجربه برای ویرایش |
| سایت مرشد | POST /mentor/accept | پذیرش دعوت پس از قوانین پایه |
| سایت مرشد | POST /mentor/profile/save | headline,bio,terms[]؛ ارسال برای بررسی |
| سایت مرشد | POST /mentor/experience/save | experience_id اختیاری، base_version_id برای ویرایش، title,body,term_id,asset_ids[]؛ شناسه نسخه جدید |
| سایت مرشد | POST /mentor/experience/submit | id نسخه پیش‌نویس |
| سایت مرشد | POST /mentor/avatar, /mentor/evidence | multipart attachment؛ تصویر پروفایل نیازمند بررسی یا فایل مستند هنوز خصوصی |
| هر دو | GET /content/asset | id؛ فایل محتوا فقط برای مالک/بازبین یا با انتشار معتبر |
| سایت مرشد | POST /mentor/calendar/settings | duration_minutes,buffer_minutes,lead_hours,daily_capacity,auto_accept,acceptance_paused,access_scope=public/organization |
| سایت مرشد | POST /mentor/calendar/rule | weekday: 0 یکشنبه تا 6 شنبه؛ start_time,end_time به قالب HH:MM یا start_minute,end_minute برای API |
| سایت مرشد | POST /mentor/calendar/remove, /mentor/calendar/exception | id بازه، یا date و remove=1 برای حذف تعطیلی |
| سایت | POST /bookings/create | همیشه BOOKING_CLOSED تا تصمیم بعدی کاربر؛ bypass ورودی ندارد |
| سایت | GET /sessions, /booking | رزروهای طرفین؛ id جزئیات |
| سایت / مدیر سامانه | POST /booking/action | id,action=accept/reject/cancel/complete,note؛ وضعیت و نقش بررسی می‌شود |
| سایت طرف جلسه | POST /booking/propose | id,starts_at یا starts_local به قالب YYYY-MM-DDTHH:MM تهران |
| سایت طرف مقابل | POST /booking/answer | id رزرو برای بازگشت UI، proposal_id,accept=1/0 |
| سایت طرف جلسه | POST /session/message | id,client_key (۱۲ تا ۶۴ بایت),body؛ ACK دارای id فقط پس از commit |
| سایت طرف / مدیر دارای مجوز | GET /session یا مدیریت /archive | id رزرو، after برای pagination پیام؛ مدیر فقط با grant |
| سایت طرف جلسه | POST /session/files | multipart id,attachment؛ فقط جلسه فعال و رضایت معتبر |
| هر دو | GET /files/download | id فایل؛ مجوز آرشیو و یکپارچگی؛ attachment خصوصی |
| سایت | POST /support/create | kind=support/complaint,title,body,booking_id در شکایت الزامی |
| سایت مالک / مدیر سامانه | GET /support, /ticket و POST /ticket/reply | id,body؛ تغییر state فقط مدیر |
| سایت دریافت‌کننده | POST /feedback | id رزرو پایان‌یافته، score=1..5,body؛ یک‌بار |
| مدیر سامانه | POST /mentors/invite, /mentors/status | user_id,headline,bio برای دعوت؛ id,status برای تصمیم |
| مدیر محتوا/سامانه | POST /taxonomy/save | id اختیاری، kind,title,parent_id,active |
| مدیر محتوا/سامانه | POST /experience/review, /experience/unpublish | id نسخه و decision=approved/changes/rejected,note؛ توقف با id تجربه |
| مدیر محتوا/سامانه | GET /storefront, /storefront/preview, /storefront/items | نسخه‌ها، id پیش‌نمایش، یا kind,q برای انتخاب محتوای عمومی |
| مدیر محتوا/سامانه | POST /storefront/save, /storefront/publish | blocks_json آرایه بلوک معتبر؛ یا id نسخه برای انتشار/بازیابی |
| مدیر سامانه | POST /bookings/internal | user_id,mentor_id,starts_at/starts_local,problem,idempotency_key؛ صرفاً داخلی |
| مدیر سامانه | POST /archives/grant, /archives/revoke | ticket_id,reviewer_id,purpose,hours=1..72؛ یا id مجوز |
| مدیر سامانه | POST /organizations/save, /organizations/member | id,name,active؛ یا org_id,user_id,remove=0/1 |
| مدیر سامانه | POST /notification-templates/create | name,title,body؛ placeholders محدود |
| مدیر سامانه | POST /notification-deliveries/send | user_id,template_id,message,request_key؛ ذخیره درون سامانه و رسید پایدار |
| مدیر سامانه | POST /general/save | about_text,support_email,support_phone؛ هیچ secret ورودی عمومی ندارد |

بعضی صفحات هویت مدیریتی قدیمی همچنان HTML ارائه می‌دهند؛ API فراگیر برای همه صفحات صرفاً از روی پیشوند فرض نشود. APIهای محصول JSON دارند، به‌جز دریافت فایل که باینری و کنترل‌شده است. پیام‌ها حداکثر ۱۰۰ ردیف و سایر فهرست‌های محصول معمولاً ۲۰ یا ۵۰ ردیف دارند. رویداد حساس یا secret در خروجی خطا نیست. مسیر رسانه، انتشار شرایط ضبط و اتصال اعلان بیرونی فعال وجود ندارد.
