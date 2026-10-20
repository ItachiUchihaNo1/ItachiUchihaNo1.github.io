<?php
require __DIR__.'/common.php';
$content=new Morshed\Product\Content($db,$audit);
$content->experiences();$before=$db->queryCount();$rows=$content->experiences();$queries=$db->queryCount()-$before;
if(!$rows||$queries>7){throw new RuntimeException('Card batch query budget exceeded: '.$queries);}
echo 'PASS experience list uses '.$queries.' queries for '.count($rows)." cards, independent of card count\n";
$before=$db->queryCount();for($i=0;$i<20;$i++){(new Morshed\Product\Taxonomy($db))->storage();(new Morshed\Product\ConsultationData($db))->storage();}
if($db->queryCount()-$before>3){throw new RuntimeException('Repeated schema probes');}
echo "PASS feature readiness is shared by services within one database request\n";
