<?php
/* verif.php — diagnóstico standalone (sem main.inc.php) */
class PcRes { public $n; public function __construct($n){ $this->n = $n; } }
class PcDb {
	public $d;
	public function __construct(){
		$h = getenv('MYSQL_HOST') ?: 'mariadb';
		$this->d = new mysqli($h, 'dolibarr', 'dolibarr', 'dolibarr', 3306);
		if ($this->d->connect_error) { exit('ERR CONN: ' . $this->d->connect_error . "\n"); }
	}
	public function query($sql){
		$r = $this->d->query($sql);
		if (!$r) { return false; }
		if (is_object($r)) { return new PcRes($r); }
		return true;
	}
	public function escape($s){ return $this->d->real_escape_string($s); }
	public function num_rows($r){ return $r->n->num_rows; }
	public function fetch_object($r){ return $r->n->fetch_object(); }
}
$db = new PcDb();
$conf = new stdClass();
require_once __DIR__ . '/sched.inc.php';

$EQUIPE = pc_equipe_get();
list($mo, $moWsSec, $wsNames, $wsTotalSec) = pc_load_data();

echo "== MOs carregadas: " . count($mo) . "\n";
$totSec = 0;
$perMo = array();
foreach ($moWsSec as $mid => $wslist) {
	$perMo[$mid] = 0;
	foreach ($wslist as $wid => $sec) { $perMo[$mid] += $sec; }
	$totSec += $perMo[$mid];
}
echo "== Total horas (todas MOs): " . round($totSec / 3600.0, 2) . " h\n";
foreach ($mo as $mid => $m) {
	echo sprintf("   %-18s qty=%s %6.2f h\n", $m['ref'], $m['qty'], $perMo[$mid] / 3600.0);
}

$capH = 4.0;
list($dayStart, $dayEnd) = pc_window($mo);
echo "\n== Janela: " . date('Y-m-d', $dayStart) . " .. " . date('Y-m-d', $dayEnd) . "\n";
$bDays = pc_bdays($dayStart, $dayEnd);
echo "== Dias de trabalho (pc_bdays): " . count($bDays) . "\n";
echo "   lista: ";
$lista = array();
foreach ($bDays as $d) { $lista[] = date('Y-m-d', $d); }
echo implode(',', $lista) . "\n";

list($sched, $endTs, $totSched, $leftOver, $opTotals, $opDays, $mDay, $wsCapDay, $_b) =
	pc_schedule($EQUIPE, $moWsSec, $dayStart, $dayEnd, $capH);
echo "\n== Cronograma: total escalonado " . round($totSched, 2) . " h, sobra " . round($leftOver, 2) . " h\n";
echo "== Termino: " . ($endTs ? date('Y-m-d', $endTs) : '-') . "\n";
echo "== Por operador:\n";
foreach ($opTotals as $o => $t) {
	echo sprintf("   %-7s %6.2f h (%d dias)\n", $o, $t, count($opDays[$o]));
}
echo "\n== Capacidade por posto (h/dia):\n";
foreach ($wsCapDay as $wid => $cap) { echo "   ws[$wid] = $cap h/dia\n"; }
$usedDays = array();
foreach ($sched as $s) { $usedDays[$s['ts']] = 1; }
echo "\n== Dias usados no cronograma: " . count($usedDays) . " de " . count($bDays) . "\n";
$prazo = strtotime('2026-09-30');
echo "== Folga vs prazo 30/09: " . round(($prazo - $endTs) / 86400, 1) . " dias\n";
echo "\n===== CSV EXPORT (replica do handler index.php?export=sched) =====\n";
$out = fopen('php://output', 'w');
fputcsv($out, array('dia', 'operador', 'op', 'produto', 'posto', 'horas'));
foreach ($sched as $s) {
	$m = isset($mo[$s['mid']]) ? $mo[$s['mid']] : array('ref' => 'MO#' . $s['mid'], 'label' => '');
	fputcsv($out, array(date('Y-m-d', $s['ts']), $s['op'], $m['ref'], $m['label'], $wsNames[$s['wid']], round($s['h'], 2)));
}
fflush($out);
echo "   (linhas sched exportadas: " . count($sched) . ")\n";

echo "\n== LLX_CONF persistida (pos-verbose):\n";
foreach (array('PLANCONF_EQUIPE', 'PLANCONF_TEMPOS', 'PLANCONF_FERIAS', 'PLANCONF_FIMSAT', 'PLANCONF_FIMSUN') as $k) {
	echo "   $k = " . pc_const_get($k, '(vazio)') . "\n";
}

echo "\n===== TESTE AUSENCIA: Miguel fora em 16 e 17/09 =====\n";
$AUS = array('Miguel' => array('2026-09-16', '2026-09-17'));
list($s2, $end2, $tot2, $left2, $ot2, $od2, $md2, $cap2, $bd2) = pc_schedule($EQUIPE, $moWsSec, $dayStart, $dayEnd, $capH, $AUS);
echo "   termino: " . ($end2 ? date('Y-m-d', $end2) : '-') . " (era 2026-09-22)\n";
echo "   total: " . round($tot2, 2) . " h, sobra: " . round($left2, 2) . "\n";
foreach ($ot2 as $o => $t) { echo "   $o: " . round($t, 2) . " h\n"; }
echo "   dias de Miguel: " . count($od2['Miguel']) . "\n";
echo "   dias na lista: ";
$used2 = array();
foreach ($s2 as $s) { $used2[date('Y-m-d', $s['ts'])] = 1; }
ksort($used2);
echo implode(',', array_keys($used2)) . "\n";
echo "   check 16/17: Miguel agendado? " . count(array_filter($s2, function ($x) { return ($x['ts'] == '2026-09-16' || $x['ts'] == '2026-09-17') && $x['op'] == 'Miguel'; })) . " (esperado 0)\n";

echo "\n===== MATERIAIS =====\n";
$mats = pc_load_materials();
$mont = 0;
foreach ($mats as $mt) {
	$mont += $mt['cost'];
	echo sprintf("   %-14s need=%-6s stock=%-6s falt=%-6s preco=%-8s custo=%.2f\n", $mt['ref'], $mt['need'], $mt['stock'], $mt['missing'], $mt['price'], $mt['cost']);
}
echo "   total custo faltante: R$ " . round($mont, 2) . "\n";

echo "\n===== USUARIOS/GRUPOS (status) =====\n";
$r = $db->query("SELECT u.login, u.lastname, g.nom AS grp, w.ref AS ws FROM llx_workstation_workstation_usergroup wg JOIN llx_workstation_workstation w ON w.rowid=wg.fk_workstation JOIN llx_usergroup g ON g.rowid=wg.fk_usergroup JOIN llx_usergroup_user ug ON ug.fk_usergroup=g.rowid JOIN llx_user u ON u.rowid=ug.fk_user ORDER BY w.rowid, u.login");
while ($o = $db->fetch_object($r)) { echo "   {$o->ws} :: {$o->login} ({$o->lastname}) grp={$o->grp}\n"; }
$r = $db->query("SELECT login, statut, lastname, firstname FROM llx_user WHERE fk_soc IS NULL ORDER BY login");
while ($o = $db->fetch_object($r)) { echo "   user {$o->login} statut={$o->statut}\n"; }