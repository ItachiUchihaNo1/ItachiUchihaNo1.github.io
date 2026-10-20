<?php
require __DIR__.'/common.php';
use Morshed\Core\{Security,Problem};
use Morshed\Product\{Taxonomy,Content,Catalog,ExperienceCases};
$passed=0;$failed=0;
function t13(string $name,callable $fn):void{global $passed,$failed;try{$fn();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: ".$e->getMessage()."\n";}}
function eq13($a,$b):void{if($a!==$b){throw new RuntimeException('Unexpected result '.json_encode([$a,$b]));}}
function reject13(string $code,callable $fn):void{try{$fn();}catch(Problem $e){eq13($e->errorCode,$code);return;}throw new RuntimeException('Expected '.$code);}
$content=new Content($db,$audit);$tax=new Taxonomy($db);$catalog=new Catalog($db);$cases=new ExperienceCases($db,$audit);
$ca=$db->one("SELECT id AS admin_id,user_id FROM m_admin_accounts WHERE username='content_test'")+['realm'=>'admin'];
$mentor=$db->one("SELECT user_id FROM m_mentors WHERE headline='معرفی کوتاه' AND status='active' LIMIT 1")['user_id'];
$other=Security::id();$db->run('INSERT INTO m_users(id,display_name,created_at,updated_at) VALUES(?,?,?,?)',[$other,'مرشد دوم موضوع‌ها',time(),time()]);$db->run("INSERT INTO m_mentors(user_id,headline,bio,status,invited_at,accepted_at,updated_at) VALUES(?,?,?,'active',?,?,?)",[$other,'مدیریت رویداد','تجربه‌های اجرایی',time(),time(),time()]);$db->run('INSERT INTO m_schedule_settings(mentor_id) VALUES(?)',[$other]);
$topicA=$content->term($ca,['kind'=>'topic','title'=>'موکب آزمون موضوع','icon'=>'flag']);$topicB=$content->term($ca,['kind'=>'topic','title'=>'رویداد آزمون موضوع','icon'=>'users']);
$finance=$content->term($ca,['kind'=>'issue','title'=>'تأمین مالی مشترک آزمون']);$team=$content->term($ca,['kind'=>'issue','title'=>'جذب نیرو مشترک آزمون']);
t13('issues can exist independently of a topic',fn()=>eq13($db->one('SELECT parent_id FROM m_terms WHERE id=?',[$finance])['parent_id'],null));
t13('topic icon persists through a separate service instance',fn()=>eq13((new Taxonomy($db))->topic($topicA)['icon'],'flag'));
t13('unsafe icons roll back the entire taxonomy change',function()use($content,$db,$ca){reject13('INVALID_INPUT',fn()=>$content->term($ca,['kind'=>'topic','title'=>'نباید ثبت شود','icon'=>'<script>']));eq13($db->one('SELECT id FROM m_terms WHERE title=?',['نباید ثبت شود']),null);});
function case13(string $mentor,string $topic,array $issues):array{return ['mentor_id'=>$mentor,'title'=>'روایت آزمون حوزه','term_id'=>$topic,'issues'=>$issues,'summary'=>'معرفی کوتاه','problem'=>'هدف و مسئله اجرایی','context'=>'شرایط اولیه','actions'=>'اقدام اجرایی','results'=>'نتیجه عملی','lessons'=>'آموخته‌ها','visibility'=>'public'];}
$inputA=case13($mentor,$topicA,[$finance,$team]);$inputB=case13($other,$topicB,[$finance]);
$a=$cases->save($ca,$inputA);$cases->submit($ca,$a['id']);$content->review($ca,$a['id'],'approved','');
$b=$cases->save($ca,$inputB);$cases->submit($ca,$b['id']);$content->review($ca,$b['id'],'approved','');
t13('one shared issue discovers experiences across two unrelated topics',fn()=>eq13($content->experienceTotal(['issue'=>$finance]),2));
t13('one shared issue discovers both mentors across topics',fn()=>eq13($content->mentorTotal(['issue'=>$finance]),2));
t13('topic and issue filters intersect on each experience',function()use($content,$finance,$topicA,$topicB,$team){eq13($content->experienceTotal(['topic'=>$topicA,'issue'=>$finance]),1);eq13($content->experienceTotal(['topic'=>$topicB,'issue'=>$team]),0);});
t13('issue label searches work without repeating that label in the narrative',function()use($content){eq13($content->experienceTotal(['q'=>'تأمین مالی مشترک آزمون']),2);eq13($content->mentorTotal(['q'=>'تأمین مالی مشترک آزمون']),2);});
t13('cards expose the chosen topic and all published issue labels',function()use($content,$a,$topicA,$finance,$team){$e=$content->experience($a['experience_id']);eq13($e['topic_id'],$topicA);$tags=array_column($e['issue_tags'],'id');sort($tags);$want=[$finance,$team];sort($want);eq13($tags,$want);});
t13('topic counts include published experiences exactly once',function()use($tax,$topicA,$topicB){eq13((int)$tax->topic($topicA)['experience_count'],1);eq13((int)$tax->topic($topicB)['experience_count'],1);});
$new=$cases->save($ca,array_merge($inputB,['experience_id'=>$b['experience_id'],'base_version_id'=>$b['id'],'issues'=>[$team]]));
t13('draft label edits never change published filters or cards',function()use($content,$finance,$b){eq13($content->experienceTotal(['issue'=>$finance]),2);eq13(count($content->experience($b['experience_id'])['issue_tags']),1);});
$cases->submit($ca,$new['id']);$content->review($ca,$new['id'],'approved','');
t13('publishing a revision activates only its own issue assignments',function()use($content,$finance,$team){eq13($content->experienceTotal(['issue'=>$finance]),1);eq13($content->experienceTotal(['issue'=>$team]),2);});
t13('invalid issue identifiers roll back a newly attempted revision',function()use($cases,$ca,$inputB,$b,$new,$db){$before=(int)$db->run('SELECT COUNT(*) FROM m_experience_versions WHERE experience_id=?',[$b['experience_id']])->fetchColumn();reject13('INVALID_TOPIC',fn()=>$cases->save($ca,array_merge($inputB,['experience_id'=>$b['experience_id'],'base_version_id'=>$new['id'],'issues'=>['invalid']])));eq13((int)$db->run('SELECT COUNT(*) FROM m_experience_versions WHERE experience_id=?',[$b['experience_id']])->fetchColumn(),$before);});
$reader=$db->one("SELECT id FROM m_users WHERE display_name='آزمون کارت reader'")['id'];$reader2=$db->one("SELECT id FROM m_users WHERE display_name='آزمون کارت other'")['id'];$actor=['user_id'=>$reader,'realm'=>'site'];
t13('experience likes persist and remain isolated from another account',function()use($catalog,$actor,$reader2,$a,$db){$catalog->saveExperience($actor,$a['experience_id'],true);$catalog->saveExperience($actor,$a['experience_id'],true);eq13((new Catalog($db))->savedExperiences($actor['user_id']),[$a['experience_id']]);eq13($catalog->savedExperiences($reader2),[]);});
t13('liking an experience does not like its mentor',fn()=>eq13($catalog->savedIds($reader),[]));
t13('experience unlike changes only that account',function()use($catalog,$actor,$reader2,$a){$catalog->saveExperience(['user_id'=>$reader2,'realm'=>'site'],$a['experience_id'],true);$catalog->saveExperience($actor,$a['experience_id'],false);eq13($catalog->savedExperiences($actor['user_id']),[]);eq13($catalog->savedExperiences($reader2),[$a['experience_id']]);});
t13('non-site actors cannot change experience likes',fn()=>reject13('ACCESS_DENIED',fn()=>$catalog->saveExperience(['user_id'=>$reader,'realm'=>'admin'],$a['experience_id'],true)));
echo "TAXONOMY13 RESULT: $passed passed, $failed failed\n";exit($failed?1:0);
