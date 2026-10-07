import Link from 'next/link';
import { AppChrome } from '@/components/AppChrome';
import { SearchBox } from '@/components/SearchBox';
import { prisma } from '@/lib/prisma';
import { getCurrentUser } from '@/lib/auth';
import { DOMAINS, TICKET_STATUS } from '@/lib/constants';

export default async function HomePage(){
  const user=await getCurrentUser();
  const [experts, experiences, tickets]=await Promise.all([
    prisma.expertProfile.findMany({where:{isVerified:true},include:{user:true,slots:{where:{isBooked:false,startsAt:{gt:new Date()}},orderBy:{startsAt:'asc'},take:1}},take:4,orderBy:{experienceCount:'desc'}}),
    prisma.experience.findMany({where:{status:'PUBLISHED',accessLevel:'PUBLIC'},orderBy:{publishedAt:'desc'},take:4}),
    user?prisma.ticket.findMany({where:{userId:user.id},orderBy:{createdAt:'desc'},take:3}):Promise.resolve([])
  ]);
  return <AppChrome active="home">
    <SearchBox/>
    <div style={{height:12}}/>
    <div className="quick-grid">
      <Link href={user?'/issues/new':'/login'} className="quick-card"><div className="qicon">＋</div><b>ثبت مسئله</b><small>مسئله‌ای داری؟</small></Link>
      <Link href="/experts" className="quick-card"><div className="qicon">◎</div><b>صاحبان تجربه</b><small>فرد مناسب را پیدا کن</small></Link>
      <Link href="/knowledge" className="quick-card"><div className="qicon">▤</div><b>تجربه‌ها</b><small>راه‌های رفته‌شده</small></Link>
      <Link href="/profile" className="quick-card"><div className="qicon">⌘</div><b>شبکه همراهان</b><small>عضویت و نقش‌ها</small></Link>
    </div>

    <section className="hero">
      <div className="eyebrow">شبکه مشورت و تجربه هیأت</div>
      <h2>هر مسئله‌ای، یک راه‌رفته دارد.</h2>
      <p>مسئله را دقیق ثبت کن؛ ابتدا تجربه‌های مرتبط را می‌بینی و اگر کافی نبود، به صاحب تجربه‌ای که این مسیر را واقعاً رفته وصل می‌شوی.</p>
      <div className="hero-actions"><Link className="btn btn-primary" href={user?'/issues/new':'/login'}>ثبت مسئله</Link><Link className="btn btn-ghost" href="/experts">پیدا کردن صاحب تجربه</Link></div>
    </section>

    {tickets.length>0&&<section className="section"><div className="section-head"><div className="section-title">وضعیت مسئله‌های من</div><Link href="/issues">مشاهده همه</Link></div><div className="panel">{tickets.map(t=><Link key={t.id} href="/issues" className="list-item"><div className="list-icon">?</div><div><b>{t.title}</b><small>{t.domain} · {new Intl.DateTimeFormat('fa-IR').format(t.createdAt)}</small></div><span className="status blue">{TICKET_STATUS[t.status]||t.status}</span></Link>)}</div></section>}

    <section className="section"><div className="section-head"><div className="section-title">در چه زمینه‌ای دنبال کمک هستی؟</div></div><div className="cat-grid">{DOMAINS.slice(0,12).map((d,i)=><Link key={d} href={'/knowledge?domain='+encodeURIComponent(d)} className="cat"><div className="ci">{['◉','♧','◇','◫','↗','◈','⌂','▤','⌘','✦','◎','★'][i%12]}</div><span>{d}</span></Link>)}</div></section>

    <section className="section"><div className="section-head"><div className="section-title">صاحبان تجربه پیشنهادی</div><Link href="/experts">مشاهده همه</Link></div><div className="h-scroll">{experts.map(e=><div key={e.id} className="advisor-card"><div className="advisor-head"><div className="avatar">👤</div><div><h3>{e.user.name}</h3><span className="verified">تأیید شبکه</span><div className="advisor-sub">{e.headline}</div></div></div><div className="tags">{e.tags.slice(0,3).map(t=><span key={t} className="tag">{t}</span>)}</div><div className="advisor-meta"><span>{e.yearsExperience} سال تجربه</span><span>{e.experienceCount} تجربه ثبت‌شده</span></div><Link className="btn btn-solid block" href={'/experts/'+e.id}>مشاهده و رزرو</Link></div>)}</div></section>

    <section className="section"><div className="section-head"><div className="section-title">شاید پاسخ مسئله‌ات همین‌جا باشد</div><Link href="/knowledge">همه تجربه‌ها</Link></div><div className="article-grid">{experiences.map(x=><Link key={x.id} href={'/knowledge/'+x.id} className="article"><div className="article-img"/><div className="article-body"><b>{x.title}</b><small>{x.domain} · تجربه میدانی</small></div></Link>)}</div></section>

    <section className="section"><div className="panel"><h3>راهی را رفته‌ای؟</h3><p className="small muted">تجربه‌ات می‌تواند مسیر یک هیأت دیگر را کوتاه‌تر کند. درخواست نقش صاحب تجربه از پروفایل انجام می‌شود.</p><Link className="btn btn-outline" href={user?'/profile':'/login'}>به جمع صاحبان تجربه بپیوند</Link></div></section>
  </AppChrome>
}
