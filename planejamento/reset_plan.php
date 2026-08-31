<?php
require '/var/www/html/custom/planejamento/sched.inc.php';
pc_const_set('PLANCONF_STEPS', json_encode(array()));
pc_const_set('PLANCONF_SEQ', '');
echo "OK: steps e seq limpos\n";
$v = pc_const_get('PLANCONF_SEQ');
echo "SEQ (vazio?): [" . var_export($v, true) . "]\n";
$s = pc_const_get('PLANCONF_STEPS');
echo "STEPS: " . (is_array($s) ? json_encode($s) : var_export($s, true)) . "\n";
