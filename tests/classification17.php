<?php
require __DIR__.'/common.php';
use Morshed\Core\{Problem,Security};
use Morshed\Product\{Content,Taxonomy,ExperienceCases,ClassificationDetails};
$count=0;
function yes17(bool $yes,string $name):void{global $count;if(!$yes){throw new RuntimeException($name);}++$count;echo "PASS $name\n";}
function no17(string $code,callable $fn):bool{try{$fn();}catch(Problem $e){return $e->errorCode===$code;}return false;}
$a=$db->one("SELECT id AS admin_id,user_id FROM m_admin_accounts WHERE username='content_test'")+['realm'=>'admin'];
$c=new Content($db,$audit);$tax=new Taxonomy($db);$details=new ClassificationDetails($db);$root=$c->term($a,['kind'=>'topic','title'=>'Root17 '.Security::id()]);$leaf=$root;
for($n=0;$n<35;$n++){$leaf=$c->term($a,['kind'=>'topic','title'=>'Level17 '.$n.' '.Security::id(),'parent_id'=>$leaf]);}
yes17(count($details->breadcrumbs($leaf))===36,'topic ancestry supports more than thirty levels without a fixed depth cap');
yes17(no17('TOPIC_CYCLE',fn()=>$c->term($a,['id'=>$root,'kind'=>'topic','title'=>$tax->topic($root)['title'],'parent_id'=>$leaf])),'moving an ancestor beneath its own descendant is rejected');
$other=$c->term($a,['kind'=>'topic','title'=>'Other17 '.Security::id(),'display_order'=>1]);
$issue=$c->term($a,['kind'=>'issue','title'=>'پرسش کامل آزمون ساختار '.Security::id(),'short_label'=>'جذب‌خادم ثابت','description'=>'شرح مسئله','related_topics'=>[$root,$other]]);
yes17($tax->issue($issue)['short_label']==='جذب‌خادم ثابت','full issue title and short card label persist separately');
yes17((int)$db->run('SELECT COUNT(*) FROM m_issue_topics WHERE issue_id=?',[$issue])->fetchColumn()===2,'one issue can be related to multiple topic branches');
$mentor=$db->one("SELECT user_id FROM m_mentors WHERE status='active' LIMIT 1")['user_id'];$cases=new ExperienceCases($db,$audit);
$input=['mentor_id'=>$mentor,'title'=>'روایت مستقل ساختار ۱۷','term_id'=>$leaf,'topics'=>[$leaf,$other],'issues'=>[$issue],'summary'=>'خلاصه','problem'=>'مسئله','context'=>'شرایط','actions'=>'اقدام','results'=>'نتیجه','lessons'=>'آموخته','visibility'=>'public'];
$saved=$cases->save($a,$input);$cases->submit($a,$saved['id']);$c->review($a,$saved['id'],'approved','');
yes17($c->experienceTotal(['topic'=>$root])===1,'parent topic search includes published experiences assigned directly to a deep descendant');
yes17($c->experienceTotal(['q'=>'جذب خادم ثابت'])===1,'short issue label search normalizes Persian half-spaces');
yes17(count($tax->labels([$saved['id']])[$saved['id']]['topics'])===2,'published experience retains multiple independent topics');
$newRoot=$c->term($a,['kind'=>'topic','title'=>'NewRoot17 '.Security::id()]);$c->term($a,['id'=>$leaf,'kind'=>'topic','title'=>$tax->topic($leaf)['title'],'parent_id'=>$newRoot]);
yes17($c->experienceTotal(['topic'=>$root])===0&&$c->experienceTotal(['topic'=>$newRoot])===1,'changing a parent refreshes the indexed ancestry without rewriting experience versions');
$c->term($a,['id'=>$issue,'kind'=>'issue','title'=>$tax->issue($issue)['title'],'active'=>0]);
yes17($db->one('SELECT version_id FROM m_experience_issues WHERE version_id=? AND term_id=?',[$saved['id'],$issue])!==null,'deactivation preserves historical experience issue links');
$blocks=[['type'=>'issues','title'=>'مسائل','ids'=>[$issue],'mode'=>'manual'],['type'=>'topics','title'=>'موضوعات','ids'=>[$other],'mode'=>'manual'],['type'=>'text','title'=>'پنهان','enabled'=>0]];
$draft=$c->saveStore($a,$blocks);$preview=$c->store($draft,true);
yes17(array_column($preview,'type')===['issues','topics'],'preview respects stored block order and hides disabled rows');
yes17($preview[0]['items']===[],'inactive issues cannot reappear through a manually selected storefront block');
yes17($preview[1]['items'][0]['id']===$other,'manual item order is persisted independently of block order');
$manualIds=array_column($c->experiences(),'id');$draft=$c->saveStore($a,[['type'=>'experiences','title'=>'Batch17','ids'=>$manualIds,'mode'=>'manual']]);$before=$db->queryCount();$batch=$c->store($draft,true);$queryCount=$db->queryCount()-$before;
yes17(count($batch[0]['items'])===count($manualIds)&&$queryCount<=12,'manually ordered experience cards are fetched as one bounded batch');
echo "CLASSIFICATION17 RESULT: $count passed\n";
