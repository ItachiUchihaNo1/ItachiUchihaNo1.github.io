# فرهنگ روابط

## روابط اصلیِ قابل استخراج از منبع

| رابطه | منبع حقیقت | عمومی |
|---|---|---|
| MENTOR_OWNS_EXPERIENCE | m_experiences.mentor_id | فقط مرشد فعال و تجربهٔ منتشرشده |
| EXPERIENCE_ABOUT_TOPIC | موضوع اصلی نسخه + m_experience_topics | طبقه‌بندی فعال، با حل ادغام |
| EXPERIENCE_ADDRESSES_ISSUE | m_experience_issues و سازگاری قدیمی | طبقه‌بندی فعال، با حل ادغام |
| EXPERIENCE_OCCURRED_AT_ORGANIZATION | انتساب تأییدشدهٔ همان نسخه | مجموعهٔ فعال و تأییدشده |
| EXPERIENCE_OCCURRED_IN_PLACE | مکان تأییدشدهٔ همان نسخه | مکان اجرای/شکل‌گیری؛ بدون سکونت شخصی |
| ORGANIZATION_LOCATED_IN | پروفایل مجموعه | محل استقرار مستقل از تجربه |
| USER → CONSULTATION_REQUEST → MENTOR | رزرو و جزئیات اصلی | خصوصی |
| SESSION / feedback | وضعیت و بازخورد اصلی رزرو | خصوصی |

متد `projectedEdges` مالکیت، موضوع‌ها، مسئله‌ها، مجموعه و مکان یک تجربهٔ عمومی را در لحظه استخراج می‌کند. اطلاعات رزرو هرگز در این خروجی عمومی وجود ندارد. وجود رابطهٔ مشترک به معنای یکی‌بودن دو تجربه یا عضویت مرشد در مجموعه نیست.

## روابط معنایی ذخیره‌شده

ISSUE_IN_TOPIC (مسئله به موضوع)، TOPIC_PARENT_OF (موضوع به زیرموضوع)، ISSUE_SUBISSUE_OF (زیرمسئله به مسئله)، ISSUE_RELATED_TO_ISSUE و EXPERIENCE_RELATED_TO_EXPERIENCE.

دو رابطهٔ آخر متقارن‌اند؛ ترتیب شناسه‌ها استاندارد و تکرار ممنوع است. حلقهٔ مستقیم، نوع نامعتبر و چرخهٔ سلسله‌مراتبی رد می‌شود. ادغام ایجادکنندهٔ چرخه هم رد می‌شود. ایجاد رابطه همواره «پیشنهاد» است، حتی با اطمینان ۱ هوش مصنوعی. فقط تصمیم صریح مدیر آن را تأیید می‌کند. منشأهای مجاز admin/system/mentor/ai/import؛ دسترسی public/private؛ وضعیت proposed/approved/rejected/revoked. بازبینی هم‌زمان با شمارهٔ revision محافظت می‌شود.
