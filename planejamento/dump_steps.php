<?php
require '/var/www/html/conf/conf.php';
$d = new mysqli($dolibarr_main_db_host, $dolibarr_main_db_user, $dolibarr_main_db_pass, $dolibarr_main_db_name);
if ($d->connect_error) { fwrite(STDERR, "ERR ".$d->connect_error."\n"); exit(1); }
$names = array('PLANCONF_STEPS','PLANCONF_SEQ','PLANCONF_TEM','PLANCONF_EQU');
foreach ($names as $n) {
	$r = $d->query("SELECT name, value FROM llx_const WHERE name='".$d->real_escape_string($n)."'");
	while ($row = $r->fetch_assoc()) {
		echo "=== $n ===\n".$row['value']."\n\n";
	}
}
$d->close();
