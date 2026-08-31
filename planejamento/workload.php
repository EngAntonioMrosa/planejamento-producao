<?php
/**
 * Plano de Producao + Horas ocupadas por maquina (carga)
 * Pagina customizada no volume /var/www/html/custom (persistente)
 */

require '../../main.inc.php';

if (!$user->id) {
	accessforbidden();
}

$langs->load('bills');
$langs->load('main');

// Contexto de menu = MRP (como paginas nativas), evita renderizacao fora do lugar
$topmenu = 'mrp';
$leftmenu = 'mrp';

// ------- util ------- 
function pc_sec2h($sec) {
	return $sec / 3600.0;
}

// ------- fonte: MOs + consumo + tempo padrao (extrafield tempo, seg/unidade) -------
$sql = "SELECT m.rowid AS mo_id, m.ref AS mo_ref, m.qty AS mo_qty, m.status,
        m.date_start_planned AS ds, m.date_end_planned AS de,
        p.ref AS prod, p.label AS prod_label,
        w.rowid AS ws_id, w.ref AS ws_ref, w.label AS ws_label,
        mp.qty AS line_qty, pe.tempo AS tempo
        FROM llx_mrp_mo m
        JOIN llx_mrp_production mp ON mp.fk_mo=m.rowid AND mp.role='toconsume'
        JOIN llx_product p ON p.rowid=m.fk_product
        LEFT JOIN llx_workstation_workstation w ON w.rowid=mp.fk_default_workstation
        LEFT JOIN llx_bom_bomline_extrafields pe ON pe.fk_object=mp.origin_id AND mp.origin_type='bomline'
        WHERE m.status NOT IN (9) AND m.entity=1
        ORDER BY m.ref, mp.position";

$res = $db->query($sql);
$rows = array();
if ($res) {
	while ($obj = $db->fetch_object($res)) {
		$rows[] = $obj;
	}
}

// ------- agrega -------
$wsNames = array();      // ws_id => label
$mcSet = array();        // mo_id => obj (first)
$mo = array();           // mo_id => mo_qty, ref, prod, status, dates
foreach ($rows as $r) {
	if ($r->ws_id) {
		$wsNames[$r->ws_id] = ($r->ws_label ? $r->ws_label : $r->ws_ref);
	}
	if (!isset($mo[$r->mo_id])) {
		$mo[$r->mo_id] = array(
			'ref' => $r->mo_ref, 'prod' => $r->prod, 'label' => $r->prod_label,
			'qty' => $r->mo_qty, 'status' => $r->status, 'ds' => $r->ds, 'de' => $r->de
		);
	}
}

$tmpSec = array(); // ws_id => sec per MO
$moWsSec = array(); // mo_id => array(ws_id => sec)
foreach ($rows as $r) {
	if (!$r->ws_id || !$r->tempo) {
		continue;
	}
	$sec = $r->tempo * $r->mo_qty;
	if (!isset($moWsSec[$r->mo_id])) {
		$moWsSec[$r->mo_id] = array();
	}
	if (!isset($moWsSec[$r->mo_id][$r->ws_id])) {
		$moWsSec[$r->mo_id][$r->ws_id] = 0;
	}
	$moWsSec[$r->mo_id][$r->ws_id] += $sec;
}

// total por maquina
$wsTotalSec = array();
foreach ($moWsSec as $mid => $wslist) {
	foreach ($wslist as $wid => $sec) {
		if (!isset($wsTotalSec[$wid])) {
			$wsTotalSec[$wid] = 0;
		}
		$wsTotalSec[$wid] += $sec;
	}
}

// janela do plano
$minT = null;
$maxT = null;
foreach ($mo as $m) {
	if ($m['ds']) {
		$ts = strtotime($m['ds']);
		if (is_null($minT) || $ts < $minT) {
			$minT = $ts;
		}
	}
	if ($m['de']) {
		$ts = strtotime($m['de']);
		if (is_null($maxT) || $ts > $maxT) {
			$maxT = $ts;
		}
	}
}
if (is_null($minT) || is_null($maxT)) {
	$minT = time();
	$maxT = time();
}
$dayStart = strtotime(date('Y-m-d', $minT));
$dayEnd = strtotime(date('Y-m-d', $maxT));
$nDays = (int)(($dayEnd - $dayStart) / 86400) + 1;

$CAP_H_PER_DAY = 12600 / 3600; // 3,5 h por turno (planilha Tempos de Fabricacao, fallback p/ posto sem equipe)

// Equipe (aba "Equipe" da planilha): operador => horas/dia disponiveis e postos habilitados (rowid workstation)
$EQUIPE = array(
	'Miguel' => array('h_dia' => 4.0, 'postos' => array(1, 2, 6, 8)),   // Furadeira, Lixadeira, Torno, Rebitadeira
	'Kauan'  => array('h_dia' => 4.0, 'postos' => array(1, 2, 3, 4, 5, 8)), // Furadeira, Lixadeira, Autrobot, Decapagem, Tamboriador, Rebitadeira
	'Saulo'  => array('h_dia' => 4.5, 'postos' => array(7)),            // Solda Manual M1
	'Marcio' => array('h_dia' => 9.0, 'postos' => array(7)),            // Solda Manual M1
);

// capacidade por posto = soma das horas/dia dos operadores habilitados
$wsCapDay = array();
foreach ($EQUIPE as $op) {
	foreach ($op['postos'] as $wid) {
		if (!isset($wsCapDay[$wid])) {
			$wsCapDay[$wid] = 0;
		}
		$wsCapDay[$wid] += $op['h_dia'];
	}
}

// nome completo dos postos (fallback p/ postos sem carga no plano)
$WS_LABEL = array(
	1 => 'Furadeira', 2 => 'Lixadeira', 3 => 'Autrobot', 4 => 'Decapagem estação 1',
	5 => 'Tamboriador', 6 => 'Torno', 7 => 'Solda Manual Máquina 1', 8 => 'Rebitadeira manual'
);
function ws_label($wid, $wsNames, $WS_LABEL) {
	return isset($wsNames[$wid]) ? $wsNames[$wid] : (isset($WS_LABEL[$wid]) ? $WS_LABEL[$wid] : '#'.$wid);
}

// grade dia x maquina (horas, distribuicao uniforme na janela da MO)
$grade = array(); // ws_id => array(daykey => hours)
foreach ($moWsSec as $mid => $wslist) {
	$m = $mo[$mid];
	$d0 = strtotime(date('Y-m-d', strtotime($m['ds'])));
	$d1 = strtotime(date('Y-m-d', strtotime($m['de'])));
	if ($d1 < $d0 || $d0 > $dayEnd || $d1 < $dayStart) {
		$nd = 1;
		$w0 = $dayStart;
		$w1 = $dayEnd;
	} else {
		$nd = ((int)(($d1 - $d0) / 86400)) + 1;
		$w0 = max($d0, $dayStart);
		$w1 = min($d1, $dayEnd);
	}
	$ndw = ((int)(($w1 - $w0) / 86400)) + 1;
	if ($ndw < 1) {
		$ndw = 1;
	}
	foreach ($wslist as $wid => $sec) {
		$perDay = $sec / $ndw;
		for ($d = $w0; $d <= $w1; $d += 86400) {
			if (!isset($grade[$wid])) {
				$grade[$wid] = array();
			}
			if (!isset($grade[$wid][$d])) {
				$grade[$wid][$d] = 0;
			}
			$grade[$wid][$d] += $perDay / 3600;
		}
	}
}

$statusLbl = array('0' => 'Rascunho', '1' => 'Validado', '2' => 'Em andamento', '3' => 'Produzida');

llxHeader('', 'Plano de Produção - Carga por Máquina', '');

print '<div style="margin-bottom:10px"><div class="inline-block" style="margin-right:16px"><a href="'.dol_buildpath('/mrp/mo_list.php', 1).'" class="butAction">← Ordens de fabricação</a></div><h1>Plano de Produção — Setembro 2026 — Horas ocupadas por máquina</h1></div>';

if (empty($mo)) {
	print 'Nenhuma ordem de fabricação encontrada.';
	llxFooter();
	exit;
}

// ---------- 1) consolidado por maquina ----------
print '<h2>1. Horas totais por máquina</h2>';
print '<table class="noborder" width="100%"><tr class="liste_titre">';
print '<td>Máquina</td><td align="right">Horas no plano</td><td align="right">OPs envolvidas</td>';
print '<td align="right">Capacidade (equipe habilitada × '.$nDays.' dias)</td><td align="right">Utilização</td></tr>';

$totH = 0;
foreach ($wsTotalSec as $wid => $sec) {
	$label = isset($wsNames[$wid]) ? $wsNames[$wid] : ('#'.$wid);
	$h = pc_sec2h($sec);
	$totH += $h;
	$capDay = isset($wsCapDay[$wid]) ? $wsCapDay[$wid] : $CAP_H_PER_DAY;
	$cap = $capDay * $nDays;
	$pct = $cap > 0 ? ($h / $cap) * 100 : 0;
	$nOps = 0;
	foreach ($moWsSec as $wslist) {
		if (isset($wslist[$wid])) {
			$nOps++;
		}
	}
	$cell = $pct;
	print '<tr class="'.((int)($pct) % 2 == 0 ? 'pair' : 'impair').'">';
	print '<td>'.$label.'</td>';
	print '<td align="right">'.round($h, 1).' h</td>';
	print '<td align="right">'.$nOps.'</td>';
	print '<td align="right">'.round($cap, 1).' h</td>';
	print '<td align="right">'.round($pct, 0).'%</td></tr>';
	unset($cell);
}
print '<tr class="liste_total"><td>Total</td><td align="right">'.round($totH, 1).' h</td><td align="right"></td><td align="right"></td><td align="right"></td></tr>';
print '</table>';

// ---------- 2) matriz diaria de carga ----------
print '<h2>2. Carga diária por máquina (horas — distribuídas na janela de cada OP)</h2>';
print '<div style="overflow:auto"><table class="noborder">';
$dayCols = array();
for ($d = $dayStart; $d <= $dayEnd; $d += 86400) {
	$dayCols[] = $d;
}
print '<tr class="liste_titre"><td>Máquina</td>';
foreach ($dayCols as $d) {
	print '<td align="center">'.date('d/m', $d).'</td>';
}
print '<td align="right">Total</td></tr>';

foreach ($wsTotalSec as $wid => $sec) {
	$label = isset($wsNames[$wid]) ? $wsNames[$wid] : ('#'.$wid);
	$capDay = isset($wsCapDay[$wid]) ? $wsCapDay[$wid] : $CAP_H_PER_DAY;
	print '<tr class="'.((int)($wid) % 2 == 0 ? 'pair' : 'impair').'"><td>'.$label.'</td>';
	foreach ($dayCols as $d) {
		$h = isset($grade[$wid][$d]) ? $grade[$wid][$d] : 0;
		if ($h <= 0) {
			print '<td align="center" class="muted">·</td>';
		} else {
			$over = $h > $capDay;
			print '<td align="right"'.($over ? ' style="color:#b00;font-weight:bold"' : '').'>'.round($h, 1).'</td>';
		}
	}
	print '<td align="right" style="font-weight:bold">'.round(pc_sec2h($sec), 1).'</td></tr>';
}
print '</table></div>';

// ---------- 3) detalhe por OP ----------
print '<h2>3. Detalhe por ordem de fabricação (horas por máquina)</h2>';
print '<table class="noborder" width="100%"><tr class="liste_titre">';
print '<td>OP</td><td>Produto</td><td align="right">Qtd</td><td>Situação</td><td>Início</td><td>Fim</td>';
foreach ($wsNames as $wid => $label) {
	print '<td align="right">'.$label.' (h)</td>';
}
print '<td align="right">Total (h)</td></tr>';

$i = 0;
foreach ($mo as $mid => $m) {
	$i++;
	$tr = ($i % 2 == 0 ? 'pair' : 'impair');
	print '<tr class="'.$tr.'">';
	print '<td>'.$m['ref'].'</td><td>'.$m['label'].'</td><td align="right">'.(int)$m['qty'].'</td>';
	$st = isset($statusLbl[$m['status']]) ? $statusLbl[$m['status']] : $m['status'];
	print '<td>'.$st.'</td>';
	print '<td>'.dol_print_date($m['ds'], 'day').'</td><td>'.dol_print_date($m['de'], 'day').'</td>';
	$moTot = 0;
	foreach ($wsNames as $wid => $label) {
		$sec = isset($moWsSec[$mid][$wid]) ? $moWsSec[$mid][$wid] : 0;
		$moTot += $sec;
		print '<td align="right">'.($sec > 0 ? round(pc_sec2h($sec), 1) : '').'</td>';
	}
	print '<td align="right" style="font-weight:bold">'.round(pc_sec2h($moTot), 1).'</td>';
	print '</tr>';
}
print '</table>';

// ---------- 4) carga por operador (habilitações + horas/dia da aba Equipe) ----------
print '<h2>4. Carga por operador (horas e postos, da planilha Equipe)</h2>';
print '<table class="noborder" width="100%"><tr class="liste_titre">';
print '<td>Operador</td><td align="right">Horas/dia</td><td>Postos habilitados</td><td align="right">Postos programados (h/mês)</td><td align="right">Carga/mês</td><td align="right">Uso da jornada</td></tr>';

$totTeamDay = 0;
$opLoad = array(); // nome => array('postos' => array(wid=>hmes), 'h_mes'=>..)
foreach ($EQUIPE as $opName => $op) {
	$totTeamDay += $op['h_dia'];
	$opLoad[$opName] = array('postos' => array(), 'h_mes' => 0);
	foreach ($op['postos'] as $wid) {
		if (!isset($wsTotalSec[$wid])) {
			continue;
		}
		// soma de operadores habilitados no posto (peso)
		$wsum = isset($wsCapDay[$wid]) ? $wsCapDay[$wid] : 0;
		if ($wsum <= 0) {
			continue;
		}
		$hMes = pc_sec2h($wsTotalSec[$wid]) * ($op['h_dia'] / $wsum);
		$opLoad[$opName]['postos'][$wid] = $hMes;
		$opLoad[$opName]['h_mes'] += $hMes;
	}
}

$i = 0;
foreach ($opLoad as $opName => $d) {
	$i++;
	$tr = ($i % 2 == 0 ? 'pair' : 'impair');
	$hDia = $EQUIPE[$opName]['h_dia'];
	$postosH = array();
	foreach ($EQUIPE[$opName]['postos'] as $wid) {
		$postosH[] = ws_label($wid, $wsNames, $WS_LABEL);
	}
	$program = array();
	foreach ($d['postos'] as $wid => $hmes) {
		$program[] = ws_label($wid, $wsNames, $WS_LABEL).' '.round($hmes, 1).'h';
	}
	$pctJorn = $hDia > 0 ? ($d['h_mes'] / ($hDia * $nDays)) * 100 : 0;
	print '<tr class="'.$tr.'">';
	print '<td>'.$opName.'</td>';
	print '<td align="right">'.round($hDia, 1).' h</td>';
	print '<td>'.implode(', ', $postosH).'</td>';
	print '<td>'.(count($program) ? implode('; ', $program) : '—').'</td>';
	print '<td align="right">'.round($d['h_mes'], 1).' h</td>';
	print '<td align="right">'.round($pctJorn, 0).'%</td></tr>';
}
print '<tr class="liste_total"><td>Equipe ('.$nDays.' dias)</td><td align="right">'.round($totTeamDay, 1).' h/dia = '.round($totTeamDay * $nDays, 1).' h</td><td></td><td></td><td align="right">'.round($totH, 1).' h</td><td align="right">'.round($totH / ($totTeamDay * $nDays) * 100, 1).'%</td></tr>';
print '</table>';
print '<p class="opacitymedium">Repartição proporcional às horas/dia de cada operador habilitado no posto. A capacidade de cada máquina é a soma das horas/dia dos operadores habilitados nela.</p>';

// ---------- 5) cronograma diário previsto (escalonamento por operador) ----------
// Regras: dias uteis; cada operador max h_dia/dia; tarefa de solda (w=3) pode rodar
// no AUTROBOT (Kauan) ou na SOLDA-MANUAL (Saulo/Marcio); maquina compartilhada usada
// por 1 operador por dia; continuidade preferida; primeiro recursos escassos (1 operador).
$tasks = array();
$i = 0;
foreach ($moWsSec as $mid => $wslist) {
	foreach ($wslist as $wid => $sec) {
		if ($wid == 3) {
			$ops = array('Kauan', 'Saulo', 'Marcio');
		} elseif ($wid == 7) {
			$ops = array('Saulo', 'Marcio');
		} else {
			$ops = array();
			foreach ($EQUIPE as $n => $o) {
				if (in_array($wid, $o['postos'])) {
					$ops[] = $n;
				}
			}
		}
		if (empty($ops)) {
			continue;
		}
		$tasks[$i] = array('mid' => $mid, 'wid' => $wid, 'left' => pc_sec2h($sec), 'ops' => $ops);
		$i++;
	}
}
function pc_mch($op, $t) {
	if ($t['wid'] == 3 && ($op == 'Saulo' || $op == 'Marcio')) {
		return 7;
	}
	return $t['wid'];
}
$SHARED = array(1 => 1, 2 => 1, 7 => 1);

$bDays = array();
for ($d = $dayStart; $d <= $dayEnd; $d += 86400) {
	$w = (int)date('w', $d);
	if ($w != 0 && $w != 6) {
		$bDays[] = $d;
	}
}
if (empty($bDays)) {
	$bDays[] = $dayStart;
}

$opOrder = array_keys($EQUIPE);
$sched = array();
$opDay = array();
$mDay = array();
$owner = array();
$lastC = array();
$safety = 90;
$di = 0;
while ($di < count($bDays) && $safety-- > 0) {
	$left = false;
	foreach ($tasks as $t) {
		if ($t['left'] > 0.02) {
			$left = true;
			break;
		}
	}
	if (!$left) {
		break;
	}
	$ts = $bDays[$di];
	$nOp = count($opOrder);
	for ($k = 0; $k < $nOp; $k++) {
		$op = $opOrder[($di + $k) % $nOp];
		while (true) {
			$usedOp = isset($opDay[$op][$ts]) ? $opDay[$op][$ts] : 0;
			if ($usedOp >= $EQUIPE[$op]['h_dia'] - 0.005) {
				$opDay[$op][$ts] = $EQUIPE[$op]['h_dia'];
				break;
			}
			$opFree = $EQUIPE[$op]['h_dia'] - $usedOp;
			if ($opFree <= 0.05) {
				break;
			}
			$cand = array();
			foreach ($tasks as $ti2 => $tt) {
				if ($tt['left'] <= 0.02 || !in_array($op, $tt['ops'])) {
					continue;
				}
				$m = pc_mch($op, $tt);
				$capFree = isset($wsCapDay[$m]) ? $wsCapDay[$m] : $CAP_H_PER_DAY;
				$mUsed = isset($mDay[$m][$ts]) ? $mDay[$m][$ts] : 0;
				if ($mUsed > $capFree - 0.005) {
					continue;
				}
				if (isset($SHARED[$m]) && isset($owner[$m][$ts]) && $owner[$m][$ts] != $op) {
					continue;
				}
				$cand[] = $ti2;
			}
			if (empty($cand)) {
				break;
			}
			usort($cand, function ($a, $b) use ($tasks, $lastC, $op) {
				$last = isset($lastC[$op]) ? $lastC[$op] : -1;
				$ca = ($a == $last ? 0 : 1);
				$cb = ($b == $last ? 0 : 1);
				if ($ca != $cb) {
					return $ca - $cb;
				}
				$d1 = count($tasks[$a]['ops']) - count($tasks[$b]['ops']);
				if ($d1 != 0) {
					return $d1;
				}
				return ($tasks[$b]['left'] > $tasks[$a]['left'] ? 1 : ($tasks[$b]['left'] < $tasks[$a]['left'] ? -1 : 0));
			});
			$ti = $cand[0];
			$t = &$tasks[$ti];
			$m = pc_mch($op, $t);
			$capFree = isset($wsCapDay[$m]) ? $wsCapDay[$m] : $CAP_H_PER_DAY;
			$mUsed = isset($mDay[$m][$ts]) ? $mDay[$m][$ts] : 0;
			$chunk = min($t['left'], $opFree, $capFree - $mUsed);
			if ($chunk <= 0.01) {
				unset($t);
				break;
			}
			$t['left'] -= $chunk;
			if ($t['left'] < 0.001) {
				$t['left'] = 0.0;
			}
			$mDay[$m][$ts] = $mUsed + $chunk;
			if ($mDay[$m][$ts] >= $capFree - 0.01) {
				$mDay[$m][$ts] = $capFree;
			}
			$opDay[$op][$ts] = $usedOp + $chunk;
			if ($opDay[$op][$ts] >= $EQUIPE[$op]['h_dia'] - 0.01) {
				$opDay[$op][$ts] = $EQUIPE[$op]['h_dia'];
			}
			$owner[$m][$ts] = $op;
			$lastC[$op] = $ti;
			$sched[] = array('ts' => $ts, 'op' => $op, 'mid' => $t['mid'], 'wid' => $m, 'h' => $chunk);
			unset($t);
		}
	}
	$di++;
}

$endTs = 0;
$totSched = 0;
foreach ($sched as $s) {
	if ($s['ts'] > $endTs) {
		$endTs = $s['ts'];
	}
	$totSched += $s['h'];
}
$leftOver = 0;
foreach ($tasks as $t) {
	$leftOver += $t['left'];
}

print '<div id="sec5">';
print '<h2>5. Cronograma diário previsto (usando todos os operadores)</h2>';
print '<p><b>Término previsto: '.dol_print_date($endTs, 'day').'</b> ('.(($endTs - $dayStart) / 86400 + 1).' dias corridos, dias úteis) · total escalonado '.round($totSched, 1).' h';
if ($leftOver > 0.02) {
	print ' · <b style="color:#b00">ATENÇÃO: '.round($leftOver, 1).' h sem operador habilitado</b>';
} else {
	print ' · plano 100% escalonado';
}
$fimAntes = ($endTs && $endTs <= $dayEnd) ? ' · termina '.ceil(($dayEnd - $endTs) / 86400).' dia(s) antes do prazo ('.dol_print_date($dayEnd, 'day').')' : '';
print $fimAntes;
print '</p>';

// resumo por operador
print '<table class="noborder" width="100%"><tr class="liste_titre"><td>Operador</td><td align="right">Horas no cronograma</td><td align="right">Dias com trabalho</td></tr>';
$i = 0;
foreach ($opOrder as $op) {
	$i++;
	$hOp = 0;
	$dOp = array();
	foreach ($sched as $s) {
		if ($s['op'] == $op) {
			$hOp += $s['h'];
			$dOp[$s['ts']] = 1;
		}
	}
	print '<tr class="'.($i % 2 == 0 ? 'pair' : 'impair').'"><td>'.$op.'</td><td align="right">'.round($hOp, 1).' h</td><td align="right">'.count($dOp).'</td></tr>';
}
print '</table>';

// detalhe dia a dia
print '<table class="noborder" width="100%"><tr class="liste_titre"><td style="width:90px">Dia</td><td>Operador</td><td>O que será feito</td><td align="right">Horas</td></tr>';
$curTs = null;
$i = 0;
foreach ($sched as $s) {
	$i++;
	$tr = ($i % 2 == 0 ? 'pair' : 'impair');
	print '<tr class="'.$tr.'">';
	if ($s['ts'] != $curTs) {
		$curTs = $s['ts'];
		print '<td><b>'.dol_print_date($s['ts'], 'day').'</b></td>';
	} else {
		print '<td></td>';
	}
	$mid = $s['mid'];
	$m = $mo[$mid];
	$wsLbl = ws_label($s['wid'], $wsNames, $WS_LABEL);
	print '<td>'.$s['op'].'</td>';
	print '<td>'.$m['ref'].' — '.$m['label'].' · '.$wsLbl.'</td>';
	print '<td align="right">'.round($s['h'], 2).' h</td>';
	print '</tr>';
}
print '</table>';
print '<p class="opacitymedium">Escalonamento: dias úteis (sem sáb/dom), uso da jornada de cada operador (planilha Equipe), tarefas de solda executáveis no AUTROBOT ou na Solda Manual, máquinas compartilhadas alternando operador por dia (1 por jornada), preferência a quem iniciou a operação e aos recursos exclusivos (ex.: Torno — Miguel).</p>';

print '</div>';

print '<p class="opacitymedium">Capacidade diária de referência (fallback): 3,5 h/turno (12 600 s). Tempos padrão por etapa gravados na BOM (campo "Tempo (s)").</p>';

llxFooter();
$db->close();