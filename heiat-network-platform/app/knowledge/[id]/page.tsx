import { notFound } from 'next/navigation';
import { AppChrome } from '@/components/AppChrome';
import { prisma } from '@/lib/prisma';

function Block({title,items}:{title:string;items:string[]}){if(!items.length)return null;return <div className="panel"><h3>{title}</h3>{items.map((x,i)=><div className="list-item" key={i}><div className="list-icon">{i+1}</div><div><b>{x}</b></div></div>)}</div>}
export default async function ExperiencePage({params}:{params:Promise<{id:string}>}){
  const {id}=await params; const x=await prisma.experience.findUnique({where:{id}}); if(!x||x.status!=='PUBLISHED')notFound();
  return <AppChrome active="knowledge"><div className="page-head"><h2>{x.title}</h2><p>{x.domain}{x.city?` · ${x.city}`:''}{x.orgType?` · ${x.orgType}`:''}</p></div>
    <div className="panel"><h3>صورت مسئله</h3><p className="small" style={{lineHeight:2}}>{x.problem}</p><div className="tags">{x.tags.map(t=><span key={t} className="tag">{t}</span>)}</div></div>
    <Block title="راه‌حل‌هایی که اجرا شد" items={x.solutions}/><Block title="نکات کلیدی" items={x.keyPoints}/><Block title="خطاها و دام‌ها" items={x.pitfalls}/><Block title="هشدارها" items={x.warnings}/><Block title="پیش‌نیازها" items={x.prerequisites}/>
  </AppChrome>
}
