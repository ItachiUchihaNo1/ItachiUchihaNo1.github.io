import Link from 'next/link';
import { getCurrentUser } from '@/lib/auth';

export async function AppChrome({ children, active = 'home' }: { children: React.ReactNode; active?: string }) {
  const user = await getCurrentUser();
  return (
    <div className="app-shell">
      <header className="topbar">
        <div className="top-row">
          <Link href="/" className="brand">
            <div className="logo">هـ</div>
            <div><h1>شبکه تجربه هیأت</h1><small>صاحب مسئله ← صاحب تجربه</small></div>
          </Link>
          <Link className="icon-btn" href={user ? '/profile' : '/login'} aria-label="پروفایل">{user ? '●' : '↪'}</Link>
        </div>
      </header>
      <main className="content">{children}</main>
      {user && <nav className="bottom-nav">
        <Link className={`nav-btn ${active==='home'?'active':''}`} href="/"><span className="ni">⌂</span><span>خانه</span></Link>
        <Link className={`nav-btn ${active==='knowledge'?'active':''}`} href="/knowledge"><span className="ni">▤</span><span>تجربه‌ها</span></Link>
        <Link className={`nav-btn ${active==='issue'?'active':''}`} href="/issues/new"><span className="nav-plus">＋</span><span className="nav-plus-label">ثبت مسئله</span></Link>
        <Link className={`nav-btn ${active==='sessions'?'active':''}`} href="/bookings"><span className="ni">◫</span><span>مشاوره‌ها</span></Link>
        <Link className={`nav-btn ${active==='profile'?'active':''}`} href="/profile"><span className="ni">○</span><span>پروفایل</span></Link>
      </nav>}
    </div>
  );
}
