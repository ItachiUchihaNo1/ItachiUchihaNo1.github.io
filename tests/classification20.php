<?php
require __DIR__.'/common.php';
use Morshed\Core\{Security,Problem};
use Morshed\Product\{Classifications,Content,ExperienceCases,Taxonomy,AtlasData,Knowledge,DiscoveryFilters};
$checks=0;
function yes20(bool $ok,string $name):void{global $checks;if(!$ok){throw new RuntimeException($name);}++$checks;echo "PASS $name\n";}
function rejects20(string $code,callable $fn):bool{try{$fn();}catch(Problem $e){return $e->errorCode===$code;}return false;}
$a=$db->one("SELECT id AS admin_id,user_id FROM m_admin_accounts WHERE username='test_admin'")+['realm'=>'admin'];
$editor=$db->one("SELECT id AS admin_id,user_id FROM m_admin_accounts WHERE username='content_test'")+['realm'=>'admin'];
$before=[];foreach(['m_experiences','m_experience_versions','m_experience_topics','m_experience_issues','m_bookings'] as $table){$before[$table]=(int)$db->run("SELECT COUNT(*) FROM $table")->fetchColumn();}
$f=new Classifications($db);$f->storage();foreach($before as $table=>$total){yes20((int)$db->run("SELECT COUNT(*) FROM $table")->fetchColumn()===$total,'migration preserves '.$table);}
$f->storage();yes20(count($f->audiences())>=19&&count($f->types())>=19,'managed defaults and retry-safe bootstrap');
$c=new Content($db,$audit);$cases=new ExperienceCases($db,$audit);$atlas=new AtlasData($db);$tax=new Taxonomy($db);$suffix=Security::id();
$art=$c->term($editor,['kind'=>'topic','title'=>'هنر۲۰ '.$suffix]);$education=$c->term($editor,['kind'=>'topic','title'=>'آموزش۲۰ '.$suffix]);$growth=$c->term($editor,['kind'=>'topic','title'=>'رشد۲۰ '.$suffix]);$leaf=$c->term($editor,['kind'=>'topic','title'=>'زیرموضوع۲۰ '.$suffix,'parent_id'=>$art]);
$fund=$c->term($editor,['kind'=>'issue','title'=>'تأمین منابع مالی۲۰ '.$suffix]);$setup=$c->term($editor,['kind'=>'issue','title'=>'راه‌اندازی۲۰ '.$suffix]);
$child=$f->saveCatalog($editor,'audiences',['title'=>'کودک۲۰ '.$suffix,'family'=>'age']);$teen=$f->saveCatalog($editor,'audiences',['title'=>'نوجوان۲۰ '.$suffix,'family'=>'age']);$student=$f->saveCatalog($editor,'audiences',['title'=>'دانش‌آموز۲۰ '.$suffix,'family'=>'role']);$type=$f->saveCatalog($editor,'organization-types',['title'=>'آموزشگاه۲۰ '.$suffix]);
$org=$atlas->saveOrganization($a,['name'=>'آموزشگاه مشخص۲۰ '.$suffix,'org_type'=>$type,'verified'=>'1']);$heyat=$atlas->saveOrganization($a,['name'=>'هیئت۲۰ '.$suffix,'org_type'=>'heyat','verified'=>'1']);
$mentor=$db->one("SELECT user_id FROM m_mentors WHERE status='active' LIMIT 1")['user_id'];
function input20(string $name,array $topics,array $aud=[],array $problems=[],array $org=[]):array{global $mentor;return ['mentor_id'=>$mentor,'title'=>$name,'topics'=>$topics,'_topics_present'=>'1','_problems_present'=>'1','_audiences_present'=>'1','audiences'=>$aud,'problems'=>$problems,'_organization_present'=>'1','organization_mode'=>'independent','problem'=>'روایت مسئله','context'=>'منابع واقعی','actions'=>'اقدامات','results'=>'نتایج','lessons'=>'آموخته','visibility'=>'public']+$org;}
function publish20(array $input):array{global $cases,$c,$editor;$s=$cases->save($editor,$input);$cases->submit($editor,$s['id']);$c->review($editor,$s['id'],'approved','');return $s;}
$school=input20('راه‌اندازی آموزشگاه هنری۲۰',[$art,$education],[$child,$teen],[['title'=>'راه‌اندازی آموزشگاه هنر','description'=>'شرح مشخص راه‌اندازی','tag_id'=>$setup]]);$school['organization_mode']='existing';$school['org_type']=$type;$school['organization_id']=$org;$first=publish20($school);
yes20(count($f->version($first['id'])['problems'])===1&&count($tax->labels([$first['id']])[$first['id']]['topics'])===2,'art school preserves two topics and a separately titled problem');
yes20($c->experienceTotal(['organization_id'=>$org])===0,'new attribution remains private before manager approval');
$atlas->attribution($a,['version_id'=>$first['id'],'org_id'=>$org,'status'=>'approved','kind'=>'organization']);
yes20($c->experienceTotal(['org_type'=>$type,'organization_id'=>$org,'audience'=>[$child,$student],'topic'=>[$education,$growth]])===1,'OR inside a criterion and AND across all four dimensions');
$event=publish20(input20('رویداد هنر۲۰',[$leaf],[$teen],[['title'=>'تأمین بودجهٔ جشنوارهٔ واقعی۲۰ '.$suffix,'description'=>'جذب پشتیبانی مالی واژه‌خاص۲۰ '.$suffix,'tag_id'=>$fund]]));
yes20($f->version($event['id'])['problems'][0]['title']!==$tax->issue($fund)['title'],'specific problem is not the reusable tag title');
yes20($c->experienceTotal(['q'=>'واژه خاص۲۰ '.$suffix])===1&&$c->mentorTotal(['q'=>'واژه خاص۲۰ '.$suffix])===1,'text search finds actual problem description with normalized half spaces');
$camp=publish20(input20('اردوی نوجوانان۲۰',[$education,$growth],[$teen,$student]));yes20(count($f->version($camp['id'])['audiences'])===2,'camp supports audiences from multiple families');
$iraq=input20('موکب در عراق۲۰',[$growth],[$teen]);$iraq['organization_mode']='existing';$iraq['org_type']='heyat';$iraq['organization_id']=$heyat;$iraq=publish20($iraq);$atlas->attribution($a,['version_id'=>$iraq['id'],'org_id'=>$heyat,'status'=>'approved','kind'=>'organization']);
yes20($atlas->publicLinks($iraq['id'])['places']===[],'organization attribution never invents an execution place for Iraq');
$independent=publish20(input20('تجربهٔ مستقل۲۰',[$art]));yes20($f->version($independent['id'])['context']['independent']==1&&$atlas->publicLinks($independent['id'])['organizations']===[],'independent experience requires no artificial organization');
yes20($c->mentorTotal(['issue'=>$fund,'audience'=>$child])===0,'mentor filters cannot assemble conditions from unrelated experiences');
yes20($c->mentorTotal(['issue'=>$fund,'audience'=>$teen,'topic'=>$art])===1,'mentor has one published experience matching every selected criterion');
yes20($atlas->discover(['issue'=>$fund,'audience'=>$child])['total']===0&&$atlas->discover(['issue'=>$fund,'audience'=>$teen])['total']===1,'atlas shares the same four-dimensional predicate');
yes20($atlas->discover(['issue'=>$fund],true)['total']===0,'unlocated experiences are not assigned inferred map positions');
$proposalInput=input20('پیشنهاد مجموعه۲۰',[$art]);$proposalInput['organization_mode']='proposed';$proposalInput['org_type']=$type;$proposalInput['organization_name']='آموزشگاه پیشنهادی۲۰ '.$suffix;$proposed=publish20($proposalInput);$proposal=$f->version($proposed['id'])['proposals'][0];
yes20($atlas->publicLinks($proposed['id'])['organizations']===[],'organization proposal is never a verified public identity');
yes20(rejects20('INVALID_ORGANIZATION',fn()=>$f->reviewProposal($a,['id'=>$proposal['id'],'decision'=>'approved','org_id'=>$heyat])),'proposal cannot connect to an incompatible organization type');
$f->reviewProposal($a,['id'=>$proposal['id'],'decision'=>'approved','org_id'=>$org]);yes20($atlas->publicLinks($proposed['id'])['organizations'][0]['id']===$org,'manager resolves proposal to existing verified organization without duplication');
yes20(rejects20('ACCESS_DENIED',fn()=>$f->saveCatalog(['user_id'=>$mentor,'realm'=>'site'],'audiences',['title'=>'غیرمجاز','family'=>'age'])),'site actors cannot manage classification catalogs');
$noTopic=$cases->save($editor,input20('پیش‌نویس بدون موضوع۲۰',[]));$cases->submit($editor,$noTopic['id']);yes20(rejects20('TOPIC_REQUIRED',fn()=>$c->review($editor,$noTopic['id'],'approved','')),'draft can omit topics but publication requires one');
$f->saveCatalog($editor,'audiences',['id'=>$child,'title'=>'کودک۲۰ '.$suffix,'family'=>'age','active'=>0]);$c->term($editor,['id'=>$education,'kind'=>'topic','title'=>'آموزش۲۰ '.$suffix,'active'=>0]);
$edit=$school;$edit['experience_id']=$first['experience_id'];$edit['base_version_id']=$first['id'];$edit['problems']=$f->version($first['id'])['problems'];$edit['problems'][0]['tag_id']=$setup;$edited=$cases->save($editor,$edit);
yes20(count($f->version($edited['id'])['audiences'])===2&&count($tax->labels([$edited['id']])[$edited['id']]['topics'])===2,'editing retains inactive historical audience and topic links');
yes20(rejects20('INVALID_AUDIENCE',fn()=>$cases->save($editor,input20('مخاطب غیرفعال جدید',[$art],[$child]))),'inactive audience cannot be newly selected');
$renamed=$c->term($editor,['id'=>$fund,'kind'=>'issue','title'=>'نام تازه برچسب۲۰ '.$suffix]);yes20($f->version($event['id'])['problems'][0]['title']!==$tax->issue($renamed)['title'],'renaming tag does not rewrite the real problem');
$merge=$c->term($editor,['kind'=>'issue','title'=>'مقصد ادغام۲۰ '.$suffix]);(new Knowledge($db))->mergeTerms($editor,$fund,$merge);yes20($c->experienceTotal(['issue'=>$merge])===1&&$db->one('SELECT term_id FROM m_problem_tags WHERE version_id=? AND term_id=?',[$event['id'],$fund])!==null,'tag merge resolves old problem links without destroying history');
$removed=$cases->save($editor,['experience_id'=>$event['experience_id'],'base_version_id'=>$event['id'],'_problems_present'=>'1']);$cases->submit($editor,$removed['id']);$c->review($editor,$removed['id'],'approved','');
yes20($c->experienceTotal(['issue'=>$merge])===0&&$db->one('SELECT term_id FROM m_problem_tags WHERE version_id=?',[$event['id']])!==null,'removing a real problem removes its derived filter tag while historical problem links remain');
echo 'CLASSIFICATION20 RESULT: '.$checks." checks passed\n";
