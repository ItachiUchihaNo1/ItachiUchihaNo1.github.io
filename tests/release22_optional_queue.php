<?php
require __DIR__.'/common.php';
if(($argv[2]??'')==='hold'){$db->run('RENAME TABLE m_eitaa_deliveries TO m_eitaa_deliveries_saved');}
elseif(($argv[2]??'')==='restore'){$db->run('RENAME TABLE m_eitaa_deliveries_saved TO m_eitaa_deliveries');}
else{throw new RuntimeException('Unknown test operation');}
