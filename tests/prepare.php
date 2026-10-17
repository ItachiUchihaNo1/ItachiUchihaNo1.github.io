<?php
require __DIR__.'/common.php';
if($db->all('SHOW TABLES')){throw new RuntimeException('Test schema must start empty.');}
foreach(glob(__DIR__.'/../morshed-core/database/migrations/*.sql') as $migration){$db->pdo->exec(file_get_contents($migration));}
echo "PASS migration applied to empty local database\n";
