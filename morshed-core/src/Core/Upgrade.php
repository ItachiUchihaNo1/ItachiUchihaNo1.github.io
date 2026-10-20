<?php
declare(strict_types=1);
namespace Morshed\Core;
final class Upgrade
{
    public static function ready(Database $db):bool{if($db->featureReady('schema7')){return true;}$ready=(int)$db->run('SELECT MAX(version) FROM m_schema_migrations')->fetchColumn()>=7;if($ready){$db->markFeatureReady('schema7');}return $ready;}
    public static function apply(Database $db):void
    {
        if((int)$db->run("SELECT GET_LOCK('morshed_schema_7',10)")->fetchColumn()!==1){throw new Problem('UPGRADE_BUSY','به‌روزرسانی دیگری در حال اجراست.',409);}
        try{
            $v=(int)$db->run('SELECT MAX(version) FROM m_schema_migrations')->fetchColumn();
            if($v===7){return;}if($v!==6){throw new Problem('UPGRADE_UNSUPPORTED','نسخه فعلی برای این به‌روزرسانی مناسب نیست.',409);}
            $sql=file_get_contents(__DIR__.'/../../database/migrations/007_profiles_and_experience_cases.sql');
            foreach(explode(';',$sql) as $statement){if(trim($statement)!==''){$db->pdo->exec($statement);}}
        }finally{$db->run("SELECT RELEASE_LOCK('morshed_schema_7')");}
    }
}
