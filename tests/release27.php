<?php
require __DIR__.'/common.php';
use Morshed\Core\{Security,Audit,Problem};
use Morshed\Product\{Content,AtlasData};
$a=$db->one("SELECT id AS admin_id,user_id FROM m_admin_accounts WHERE username='test_admin'")+['realm'=>'admin'];$content=new Content($db,new Audit($db));$n=0;
function ok27($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
function no27($code,$fn){try{$fn();}catch(Problem $e){ok27($e->errorCode===$code,$code);return;}throw new RuntimeException('Expected '.$code);}
foreach(['topic','issue'] as $kind){$d=['kind'=>$kind,'title'=>'حذف آزمایشی '.Security::id(),'short_label'=>'آزمون'];$id=$content->term($a,$d);$result=$content->deleteTerm($a,$id);ok27($result===$kind&&!$db->one('SELECT id FROM m_terms WHERE id=?',[$id]),'unused '.$kind.' removed');no27('NOT_FOUND',fn()=>$content->deleteTerm($a,$id));}
$id=$content->term($a,['kind'=>'topic','title'=>'والد آزمون '.Security::id()]);$child=$content->term($a,['kind'=>'topic','title'=>'فرزند آزمون '.Security::id(),'parent_id'=>$id]);no27('TERM_IN_USE',fn()=>$content->deleteTerm($a,$id));ok27((bool)$db->one('SELECT id FROM m_terms WHERE id=?',[$id]),'blocked deletion preserves parent');$content->deleteTerm($a,$child);$content->deleteTerm($a,$id);
$term=$db->one('SELECT term_id FROM m_experience_topics LIMIT 1');if($term){no27('TERM_IN_USE',fn()=>$content->deleteTerm($a,$term['term_id']));}
$id=$content->term($a,['kind'=>'issue','title'=>'دسترسی آزمون '.Security::id(),'short_label'=>'آزمون']);no27('ACCESS_DENIED',fn()=>$content->deleteTerm(['realm'=>'site','user_id'=>$a['user_id']],$id));ok27((bool)$db->one('SELECT id FROM m_terms WHERE id=?',[$id]),'unauthorized delete preserves term');$content->deleteTerm($a,$id);
$atlas=new AtlasData($db);foreach(['experiences','mentors','organizations'] as $layer){$full=$atlas->discover(['layer'=>$layer],true);ok27($full['regions']===$atlas->discover(['layer'=>$layer],true,true),'aggregate agrees with public '.$layer.' results');}
echo "$n release27 checks passed\n";
