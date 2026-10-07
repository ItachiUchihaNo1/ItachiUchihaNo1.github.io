import { AppChrome } from '@/components/AppChrome';
import { requireUser } from '@/lib/auth';
import IssueForm from './IssueForm';
export default async function NewIssuePage(){const user=await requireUser();return <AppChrome active="issue"><div className="page-head"><h2>ثبت مسئله جدید</h2><p>صورت مسئله را دقیق بنویس؛ هدف شبکه، وصل‌کردن تو به کسی است که این مسیر را واقعاً رفته.</p></div><IssueForm city={user.city}/></AppChrome>}
