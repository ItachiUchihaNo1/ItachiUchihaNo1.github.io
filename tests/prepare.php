<?php
require __DIR__.'/common.php';
if($db->all('SHOW TABLES')){throw new RuntimeException('Test schema must start empty.');}
foreach(glob(__DIR__.'/../morshed-core/database/migrations/*.sql') as $migration){if(!str_contains(basename($migration),'007_')){$db->pdo->exec(file_get_contents($migration));}}
\Morshed\Core\Upgrade::apply($db);\Morshed\Core\Upgrade::apply($db);
echo "PASS migration applied to empty local database\n";
