<?php
require __DIR__.'/common.php';
// common.php refuses every database except an explicitly named random loopback fixture.
$db->run('DELETE FROM m_rate_limits');
echo "PASS disposable loopback test counters reset between independent HTTP suites\n";
