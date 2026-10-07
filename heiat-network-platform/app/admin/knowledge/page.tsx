import { AppChrome } from '@/components/AppChrome';
import { requireRole } from '@/lib/auth';
import { prisma } from '@/lib/prisma';
import KnowledgeForm from './KnowledgeForm';
export default async function KnowledgeAdminPage(){await requireRole('KNOWLEDGE_EDITOR','ADMIN');const sessions=await prisma.consultationSession.findMany({where:{status:'COMPLETED',experience:null},include:{ticket:true,expert:true,user:true},orderBy:{completedAt:'desc'},take:20});return <AppChrome active="profile"><div className="page-head"><h2>ویرایشگر مدیر دانش</h2><p>جلسه انجام‌شده را به تجربه ساختاریافته و قابل استفاده مجدد تبدیل کن.</p></div>{sessions.length?sessions.map(s=><KnowledgeForm key={s.id} session={JSON.parse(JSON.stringify(s))}/>):<div className="empty"><div className="big">▤</div>جلسه تکمیل‌شده‌ای برای تبدیل به تجربه وجود ندارد.</div>}</AppChrome>}
