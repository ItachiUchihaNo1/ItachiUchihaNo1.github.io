import { PrismaClient } from '@prisma/client';

const prisma = new PrismaClient();

async function main() {
  const adminPhone = process.env.ADMIN_PHONE || '09120000000';
  const admin = await prisma.user.upsert({
    where: { phone: adminPhone },
    update: { name: 'مدیر سامانه', city: 'تهران', roles: ['HAYAT_JOO','MODERATOR','KNOWLEDGE_EDITOR','ADMIN'], verifiedAt: new Date() },
    create: { phone: adminPhone, name: 'مدیر سامانه', city: 'تهران', roleInOrg: 'مدیر شبکه', roles: ['HAYAT_JOO','MODERATOR','KNOWLEDGE_EDITOR','ADMIN'], verifiedAt: new Date() }
  });


  const demo = (process.env.SEED_DEMO_DATA || 'true') === 'true';
  if (!demo) {
    console.log(`Admin phone: ${adminPhone}`);
    console.log('Demo data skipped.');
    return;
  }

  const expertsData = [
    {
      phone: '09121111111', name: 'حجت‌الاسلام علی حسینی', city: 'تهران',
      headline: 'راه‌اندازی و مدیریت هیأت نوجوان',
      bio: '۱۲ سال تجربه میدانی در سازماندهی، جذب و نگهداشت نوجوان در هیأت‌های محلی.',
      domains: ['کودک و نوجوان','جذب و ارتباط با مخاطب','مدیریت هیأت'], tags: ['نوجوان','سازماندهی','جذب'], yearsExperience: 12, experienceCount: 24
    },
    {
      phone: '09122222222', name: 'مهدی رضایی', city: 'مشهد',
      headline: 'ساخت و توسعه فضای هیأت',
      bio: 'تجربه عملی از تأمین زمین تا مشارکت مردمی، اجرا و بهره‌برداری از حسینیه.',
      domains: ['ساختمان و فضا','مالی و اقتصادی'], tags: ['حسینیه','مشارکت مردمی','ساخت'], yearsExperience: 10, experienceCount: 14
    },
    {
      phone: '09123333333', name: 'زهرا موسوی', city: 'قم',
      headline: 'مدیریت رسانه و تولید محتوای هیأت',
      bio: 'ساخت تیم رسانه، طراحی تقویم محتوایی و پوشش مناسبتی برای هیأت‌های شهری.',
      domains: ['رسانه','برنامه‌ریزی و محتوا'], tags: ['رسانه','تولید محتوا','تیم رسانه'], yearsExperience: 7, experienceCount: 18
    }
  ];

  const experts = [];
  for (const item of expertsData) {
    const user = await prisma.user.upsert({
      where: { phone: item.phone },
      update: { name: item.name, city: item.city, roles: ['HAYAT_JOO','EXPERT'], verifiedAt: new Date() },
      create: { phone: item.phone, name: item.name, city: item.city, roleInOrg: 'صاحب تجربه', roles: ['HAYAT_JOO','EXPERT'], verifiedAt: new Date() }
    });
    const profile = await prisma.expertProfile.upsert({
      where: { userId: user.id },
      update: { headline: item.headline, bio: item.bio, domains: item.domains, tags: item.tags, yearsExperience: item.yearsExperience, experienceCount: item.experienceCount, isVerified: true },
      create: { userId: user.id, headline: item.headline, bio: item.bio, domains: item.domains, tags: item.tags, yearsExperience: item.yearsExperience, experienceCount: item.experienceCount, isVerified: true, capacityWeek: 3, capacityMonth: 10 }
    });
    experts.push({user, profile});

    const existingSlots = await prisma.availabilitySlot.count({ where: { expertProfileId: profile.id } });
    if (!existingSlots) {
      const base = new Date();
      base.setHours(0,0,0,0);
      for (let d = 1; d <= 5; d++) {
        const slotDate = new Date(base);
        slotDate.setDate(slotDate.getDate() + d);
        slotDate.setHours(18 + (d % 2), 30, 0, 0);
        await prisma.availabilitySlot.create({ data: { expertProfileId: profile.id, startsAt: slotDate, duration: d % 2 ? 30 : 45 } });
      }
    }
  }

  const expCount = await prisma.experience.count();
  if (!expCount) {
    await prisma.experience.createMany({
      data: [
        {
          title: 'از ۲۰ نوجوان تا یک هیأت نوجوان فعال', domain: 'کودک و نوجوان', subdomain: 'راه‌اندازی هیأت نوجوان', city: 'تهران', orgType: 'محلی',
          problem: 'چگونه با یک جمع کوچک نوجوان، ساختار مستمر و پایدار ایجاد کنیم؟',
          solutions: ['شروع با هسته ۵ نفره مسئولیت‌پذیر','تقویم ثابت هفتگی','تفکیک برنامه جذب از برنامه نگهداشت'],
          keyPoints: ['مسئولیت واقعی به نوجوان بدهید','جلسه کوتاه ولی منظم بهتر از برنامه سنگین مقطعی است'],
          pitfalls: ['تمرکز صرف بر برنامه مناسبتی','وابسته کردن کار به یک مربی'], warnings: ['حریم خانواده‌ها و رضایت والدین را جدی بگیرید'], prerequisites: ['هسته اجرایی کوچک','فضای ثابت'], tags: ['نوجوان','جذب','مدیریت'], accessLevel: 'PUBLIC', status: 'PUBLISHED', createdById: admin.id, publishedAt: new Date()
        },
        {
          title: 'ساخت حسینیه با مشارکت مردم؛ از زمین تا بهره‌برداری', domain: 'ساختمان و فضا', subdomain: 'ساخت حسینیه', city: 'مشهد', orgType: 'محلی',
          problem: 'چطور پروژه ساخت بدون فرسودگی تیم و بی‌اعتمادی مالی جلو برود؟',
          solutions: ['فازبندی پروژه','گزارش مالی منظم','تعیین مسئول فنی مستقل'], keyPoints: ['شفافیت مالی بخشی از خود پروژه است','هر فاز باید خروجی قابل مشاهده داشته باشد'], pitfalls: ['شروع بدون برآورد هزینه','قول‌های مبهم به خیرین'], warnings: ['مجوزها و الزامات ایمنی باید قبل از اجرا بررسی شوند'], prerequisites: ['زمین یا قرارداد معتبر','برآورد اولیه'], tags: ['ساخت','حسینیه','مشارکت مردمی'], accessLevel: 'PUBLIC', status: 'PUBLISHED', createdById: admin.id, publishedAt: new Date()
        },
        {
          title: 'تیم رسانه ۵ نفره؛ نقش‌ها و فرآیند هفتگی', domain: 'رسانه', subdomain: 'تیم رسانه', city: 'قم', orgType: 'شهری',
          problem: 'چطور با تیم کم‌تعداد، خروجی منظم و قابل اتکا داشته باشیم؟',
          solutions: ['تعریف نقش ثابت','تقویم محتوا','جلسه مرور هفتگی ۲۰ دقیقه‌ای'], keyPoints: ['هر نفر مالک یک خروجی مشخص باشد','آرشیو فایل‌ها ساختار ثابت داشته باشد'], pitfalls: ['همه‌کاره بودن یک نفر','تولید بدون تقویم'], warnings: ['رضایت افراد در انتشار تصویر رعایت شود'], prerequisites: ['تقویم برنامه‌های هیأت','فضای اشتراک فایل'], tags: ['رسانه','تولید محتوا','تیم'], accessLevel: 'PUBLIC', status: 'PUBLISHED', createdById: admin.id, publishedAt: new Date()
        }
      ]
    });
  }

  console.log('Seed completed.');
  console.log(`Admin phone: ${adminPhone}`);
  console.log('Expert phones: 09121111111 / 09122222222 / 09123333333');
}

main().catch((e) => { console.error(e); process.exit(1); }).finally(() => prisma.$disconnect());
