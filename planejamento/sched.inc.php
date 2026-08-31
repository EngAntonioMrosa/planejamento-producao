<?php
/**
 * sched.inc.php ÔÇö n├║cleo de dados + escalonador (compartilhado entre telas).
 * L├¬ MOs/tempos do Dolibarr, aplica overrides gravados em llx_const,
 * escala o cronograma por operador e devolve tudo no formato JSON.
 */

function pc_sec2h($sec)
{
	return $sec / 3600.0;
}

const PC_CONF_EQUIPE   = 'PLANCONF_EQUIPE';
const PC_CONF_TEMPOS   = 'PLANCONF_TEMPOS';
const PC_CONF_FERIAS   = 'PLANCONF_FERIAS';
const PC_CONF_FIMSAT   = 'PLANCONF_FIMSAT';
const PC_CONF_FIMSUN   = 'PLANCONF_FIMSUN';
const PC_CONF_AUSENCIAS = 'PLANCONF_AUSENCIAS';
const PC_CONF_SEQ      = 'PLANCONF_SEQ';
const PC_CONF_STEPS    = 'PLANCONF_STEPS';
const PC_CONF_EFICIENCIA = 'PLANCONF_EFICIENCIA';

function pc_const_get($key, $default)
{
	global $db;
	$sql = "SELECT value FROM llx_const WHERE name='" . $db->escape($key) . "' AND entity=1";
	$res = $db->query($sql);
	if ($res) {
		$obj = $db->fetch_object($res);
		if ($obj && $obj->value != '') {
			return $obj->value;
		}
	}
	return $default;
}

function pc_const_set($key, $value)
{
	global $db;
	$val = $db->escape($value);
	$sql = "SELECT rowid FROM llx_const WHERE name='" . $db->escape($key) . "' AND entity=1";
	$res = $db->query($sql);
	if ($res && $db->num_rows($res) > 0) {
		$db->query("UPDATE llx_const SET value='" . $val . "', tms=NOW() WHERE name='" . $db->escape($key) . "' AND entity=1");
	} else {
		$db->query("INSERT INTO llx_const (name, value, type, entity) VALUES ('" . $db->escape($key) . "', '" . $val . "', 'text', 1)");
	}
}

function pc_json_get($key, $default)
{
	$raw = pc_const_get($key, '');
	$d = json_decode($raw, true);
	return is_array($d) ? $d : $default;
}

/**
 * Config da equipe: operador => array(h_dia, postos list)
 */
function pc_equipe_get()
{
	$def = array(
		'Miguel' => array('h_dia' => 4.0, 'postos' => array(1, 2, 6, 8)),
		'Kauan'  => array('h_dia' => 4.0, 'postos' => array(1, 2, 3, 4, 5, 8)),
		'Saulo'  => array('h_dia' => 4.5, 'postos' => array(7)),
		'Marcio' => array('h_dia' => 9.0, 'postos' => array(7)),
	);
	return pc_json_get(PC_CONF_EQUIPE, $def);
}

/**
 * Overrides de tempos (chave "mid:wid" => horas) definidas pelo usu├írio.
 */
function pc_tempos_get()
{
	return pc_json_get(PC_CONF_TEMPOS, array());
}

function pc_ferias_get()
{
	return pc_json_get(PC_CONF_FERIAS, array());
}

/**
 * Aus├¬ncias/f├®rias por operador: operador => [ 'Y-m-d', ... ].
 */
function pc_ausencias_get()
{
	$raw = pc_json_get(PC_CONF_AUSENCIAS, array());
	$out = array();
	foreach ($raw as $op => $dates) {
		$list = array();
		foreach ((array)$dates as $dd) {
			$dd = trim($dd);
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dd)) {
				$list[] = $dd;
			}
		}
		if (!empty($list)) {
			$out[$op] = $list;
		}
	}
	return $out;
}

function pc_work_sat()
{
	return pc_const_get(PC_CONF_FIMSAT, '0') === '1';
}

function pc_work_sun()
{
	return pc_const_get(PC_CONF_FIMSUN, '0') === '1';
}

/**
 * Efici├¬ncia global (%): quanto da capacidade di├íria ├® usada de fato (default 85).
 * Reduz a previs├úo de pe├ºas/dia (capacidade efetiva) e deixa o plano mais realista.
 */
function pc_eficiencia_get()
{
	$v = (float)str_replace(',', '.', pc_const_get(PC_CONF_EFICIENCIA, '85'));
	if ($v <= 0) {
		$v = 85;
	}
	return min(100, max(1, $v));
}

/**
 * Sequ├¬ncia manual das ordens de fabrica├º├úo (MO): array ordenado de mo_id.
 * vazio = ordem autom├ítica (por ref).
 */
function pc_seq_get()
{
	$raw = pc_const_get(PC_CONF_SEQ, '');
	$seq = array();
	if ($raw !== '') {
		foreach (explode(',', $raw) as $id) {
			$id = (int)trim($id);
			if ($id > 0) {
				$seq[] = $id;
			}
		}
	}
	return $seq;
}

function pc_seq_set($ids)
{
	pc_const_set(PC_CONF_SEQ, implode(',', $ids));
}

/**
 * Etapas de fabrica├º├úo sobrescritas por MO: mo_id => array( {wid, h, op}, ... ).
 * op = operador fixo ('' = autom├ítico). Presente = usa estas etapas em vez da BOM.
 */
function pc_steps_get()
{
	return pc_json_get(PC_CONF_STEPS, array());
}

function pc_steps_set($steps)
{
	pc_const_set(PC_CONF_STEPS, json_encode($steps));
}

/**
 * Carrega MOs + tempos padr├úo (segundos). Retorna [mo, moWsSec, wsNames, wsTotalSec].
 * moWsSec: mo_id => array(ws_id => segundos); aplica overrides em horas.
 */
function pc_load_data()
{
	global $db;
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

	$rows = array();
	$res = $db->query($sql);
	if ($res) {
		while ($obj = $db->fetch_object($res)) {
			$rows[] = $obj;
		}
	}

	$wsNames = array();
	$mo = array();
	foreach ($rows as $r) {
		if ($r->ws_id) {
			$wsNames[$r->ws_id] = ($r->ws_label ? $r->ws_label : $r->ws_ref);
		}
		if (!isset($mo[$r->mo_id])) {
			$mo[$r->mo_id] = array(
				'ref' => $r->mo_ref, 'prod' => $r->prod, 'label' => $r->prod_label,
				'qty' => $r->mo_qty, 'status' => $r->status, 'ds' => $r->ds, 'de' => $r->de,
			);
		}
	}

	// Etapas efetivas por MO (mescladas com sobreescrita custom do usu├írio)
	$custom = pc_steps_get();
	$moSteps = array();  // mo_id => array({wid, sec, op?}) em ordem
	// 1) etapas da BOM (mantendo a ordem de position)
	foreach ($rows as $r) {
		if (!$r->ws_id) {
			continue;
		}
		$tempo = (float)$r->tempo;
		if ($tempo <= 0) {
			$tempo = 1.0; // fallback se sem tempo cadastrado
		}
		if (!isset($moSteps[$r->mo_id])) {
			$moSteps[$r->mo_id] = array();
		}
		$moSteps[$r->mo_id][] = array('wid' => (int)$r->ws_id, 'spc' => $tempo, 'sec' => $tempo * $r->mo_qty, 'op' => '');
	}
	// 2) se a MO tem etapas custom, substitui
	if (!empty($custom)) {
		foreach ($custom as $mid => $stepsList) {
			if (!isset($mo[$mid])) {
				continue;
			}
			$qty = (float)$mo[$mid]['qty'];
			if ($qty <= 0) {
				$qty = 1;
			}
			$clean = array();
			foreach ((array)$stepsList as $st) {
				$wid = (int)(isset($st['wid']) ? $st['wid'] : 0);
				$spc = (float)(isset($st['spc']) ? $st['spc'] : -1);
				if ($spc < 0 && isset($st['h'])) {
					$spc = (float)$st['h'] * 3600.0 / $qty; // compat: h total antigo
				}
				if ($wid <= 0 || $spc <= 0) {
					continue;
				}
				$clean[] = array('wid' => $wid, 'spc' => $spc, 'sec' => $spc * $qty, 'op' => (isset($st['op']) ? trim($st['op']) : ''));
			}
			$moSteps[$mid] = $clean;
		}
	}
	// 3) overrides de tempo por 'mid:wid' (horas)
	$over = pc_tempos_get();
	if (!empty($over)) {
		foreach ($moSteps as $mid => &$listSteps) {
			foreach ($listSteps as &$st) {
				$k = $mid . ':' . $st['wid'];
				if (isset($over[$k]) && (float)$over[$k] > 0) {
					$st['sec'] = (float)$over[$k] * 3600.0;
				}
			}
		}
		unset($listSteps, $st);
	}
	// 4) agrega para o escalonador + operador fixo por 'mid:wid'
	$moWsSec = array();
	$moStepOp = array();
	foreach ($moSteps as $mid => $listSteps) {
		foreach ($listSteps as $st) {
			$wid = $st['wid'];
			if (!empty($st["buy"])) {
                continue; // etapa comprada/terceirizada: nao gera tempo local
            }
            if (!isset($moWsSec[$mid])) {
				$moWsSec[$mid] = array();
			}
			if (!isset($moWsSec[$mid][$wid])) {
				$moWsSec[$mid][$wid] = 0;
			}
			$moWsSec[$mid][$wid] += $st['sec'];
			if (!empty($st['op'])) {
				$moStepOp[$mid . ':' . $wid] = $st['op'];
			}
		}
	}

	$wsTotalSec = array();
	foreach ($moWsSec as $wslist) {
		foreach ($wslist as $wid => $sec) {
			if (!isset($wsTotalSec[$wid])) {
				$wsTotalSec[$wid] = 0;
			}
			$wsTotalSec[$wid] += $sec;
		}
	}

	// normaliza etapas p/ UI: h e op sempre presentes
	foreach ($moSteps as $mid => &$listSteps) {
		$qty = (float)(isset($mo[$mid]['qty']) ? $mo[$mid]['qty'] : 1);
		if ($qty <= 0) {
			$qty = 1;
		}
		foreach ($listSteps as &$st) {
			$st['h'] = round($st['sec'] / 3600.0, 3); // total em horas
			$st['spc'] = round($st['sec'] / $qty, 3); // segundos por pe├ºa
			if (!isset($st['op']) || $st['op'] === null) {
				$st['op'] = '';
			}
			unset($st['sec']);
		}
	}
	unset($listSteps, $st);

	return array($mo, $moWsSec, $wsNames, $wsTotalSec, $moSteps, $moStepOp);
}

/**
 * Materiais necess├írios (consumo das MOs) vs estoque real no armaz├®m "F├íbrica".
 * Retorna lista ordenada por consumo desc + totais de falta.
 */
function pc_load_materials()
{
	global $db;
	$sql = "SELECT p.rowid, p.ref, p.label, COALESCE(NULLIF(p.pmp,0), p.price) AS price,
	        SUM(mp.qty) AS need,
	        COALESCE((SELECT SUM(ps.reel) FROM llx_product_stock ps WHERE ps.fk_product=p.rowid), 0) AS stock
	        FROM llx_mrp_production mp
	        JOIN llx_mrp_mo m ON m.rowid=mp.fk_mo AND m.entity=1 AND m.status NOT IN (9)
	        JOIN llx_product p ON p.rowid=mp.fk_product
	        WHERE mp.role='toconsume'
	        GROUP BY p.rowid, p.ref, p.label, COALESCE(NULLIF(p.pmp,0), p.price)
	        ORDER BY need DESC";
	$list = array();
	$res = $db->query($sql);
	if ($res) {
		while ($o = $db->fetch_object($res)) {
			$need = (float)$o->need;
			$stock = (float)$o->stock;
			$missing = max(0.0, $need - $stock);
			$list[] = array(
				'ref' => $o->ref,
				'label' => ($o->label ? $o->label : ''),
				'need' => round($need, 1),
				'stock' => round($stock, 1),
				'missing' => round($missing, 1),
				'price' => (float)$o->price,
				'cost' => round($missing * (float)$o->price, 2),
			);
		}
	}
	return $list;
}

/**
 * Janela do plano (extremidades das MOs).
 */
function pc_window($mo)
{
	$minT = null;
	$maxT = null;
	foreach ($mo as $m) {
		if (!empty($m['ds'])) {
			$ts = strtotime($m['ds']);
			if (is_null($minT) || $ts < $minT) {
				$minT = $ts;
			}
		}
		if (!empty($m['de'])) {
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
	return array($dayStart, $dayEnd);
}

/**
 * Dias de trabalho dentro da janela, respeitando feriados/configs de fim de semana.
 */
function pc_bdays($dayStart, $dayEnd)
{
	$workSat = pc_work_sat();
	$workSun = pc_work_sun();
	$fer = pc_ferias_get();
	$map = array();
	foreach ($fer as $f) {
		$map[date('Y-m-d', strtotime($f))] = 1;
	}
	$out = array();
	for ($d = $dayStart; $d <= $dayEnd; $d += 86400) {
		$w = (int)date('w', $d);
		if ($w == 0 && !$workSun) {
			continue;
		}
		if ($w == 6 && !$workSat) {
			continue;
		}
		if (isset($map[date('Y-m-d', $d)])) {
			continue;
		}
		$out[] = $d;
	}
	return $out;
}

/**
 * M├íquina efetiva da tarefa para um operador (solda 3 roda no AUTROBOT ou SOLDA MANUAL).
 */
function pc_mch($op, $t)
{
	if ($t['wid'] == 3 && ($op == 'Saulo' || $op == 'Marcio')) {
		return 7;
	}
	return $t['wid'];
}

/**
 * Escalonador: retorna array(sched, endTs, totSched, leftOver, opTotals, opDays, mDay).
 * $AUS: operador => [ 'Y-m-d', ... ] nos quais o operador n├úo trabalha.
 * $SEQ: sequ├¬ncia manual de mids (ordem de prioridade de fabrica├º├úo); vazio = por ref.
 * $moStepOp: "mid:wid" => operador fixo p/ a etapa (restringe a escala a esse operador).
 */
function pc_schedule($EQUIPE, $moWsSec, $dayStart, $dayEnd, $CAP_H_PER_DAY, $AUS = array(), $SEQ = array(), $moStepOp = array(), $EF = 1.0, $moSteps = array(), $opPrioWs = array())
{
	// operadores que priorizam uma m├íquina: s├│ migram para outra quando n├úo h├í
	// mais trabalho eleg├¡vel na priorit├íria (ex.: Miguel prioriza o Torno ┬À wid 6)
	if (empty($opPrioWs)) {
		$opPrioWs = array('Miguel' => 6);
	}

	// ordem das MOs: sequ├¬ncia manual primeiro, depois as demais (por key original)
	$midOrder = array();
	foreach ($SEQ as $mid) {
		if (isset($moWsSec[$mid]) && !in_array($mid, $midOrder)) {
			$midOrder[] = $mid;
		}
	}
	foreach (array_keys($moWsSec) as $mid) {
		if (!in_array($mid, $midOrder)) {
			$midOrder[] = $mid;
		}
	}
	$prioOf = array_flip($midOrder);

	// efici├¬ncia global (%): reduz a capacidade di├íria efetiva de cada operador
	$effEq = array();
	foreach ($EQUIPE as $op => $o) {
		$effEq[$op] = array('h_dia' => $o['h_dia'] * $EF, 'postos' => $o['postos']);
	}

	$tasks = array();
	$i = 0;
	foreach ($midOrder as $mid) {
		$wslist = $moWsSec[$mid];
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
			// operador fixo para esta etapa (mid:wid) define quem executa
			$fixOp = isset($moStepOp[$mid . ':' . $wid]) ? $moStepOp[$mid . ':' . $wid] : '';
			if ($fixOp !== '') {
				if (in_array($fixOp, $ops)) {
					$ops = array($fixOp);
				} else {
					continue; // operador fixo n├úo qualificado para o posto: pula etapa
				}
			}
			$tasks[$i] = array('mid' => $mid, 'wid' => $wid, 'left' => pc_sec2h($sec), 'ops' => $ops, 'prio' => isset($prioOf[$mid]) ? $prioOf[$mid] : PHP_INT_MAX);
			$i++;
		}
	}

	// sequ├¬ncia de etapas por MO (ordem da BOM/custom): wid distintos na ordem de fabrica├º├úo
	$stepOrder = array();
	foreach ($moSteps as $mid => $stepsList) {
		$ord = array();
		foreach ((array)$stepsList as $st) {
			$ew = (int)(isset($st['wid']) ? $st['wid'] : 0);
			if ($ew > 0 && !in_array($ew, $ord)) {
				$ord[] = $ew;
			}
		}
		if (!empty($ord)) {
			$stepOrder[$mid] = $ord;
		}
	}

	    // sequencia de etapas por MO (ordem da BOM/custom): wid distintos na ordem de fabricacao
    $stepOrder = array();
    foreach ($moSteps as $mid => $stepsList) {
        $ord = array();
        foreach ((array)$stepsList as $st) {
            $ew = (int)(isset($st["wid"]) ? $st["wid"] : 0);
            if ($ew > 0 && !in_array($ew, $ord)) {
                $ord[] = $ew;
            }
        }
        if (!empty($ord)) {
            $stepOrder[$mid] = $ord;
        }
    }

    // maquina prioritaria por operador (ex.: Miguel prioriza o Torno = wid 6)
    $opPrioWs = array("Miguel" => 6);

    $wsCapBase = array();
	foreach ($effEq as $op) {
		foreach ($op['postos'] as $wid) {
			if (!isset($wsCapBase[$wid])) {
				$wsCapBase[$wid] = 0;
			}
			$wsCapBase[$wid] += $op['h_dia'];
		}
	}
	$wsCapDay = $wsCapBase;

	// aus├¬ncias: mapa op -> dia(y-m-d) e base de redu├º├úo por posto
	$absence = array();
	foreach ($AUS as $opName => $dates) {
		if (!isset($EQUIPE[$opName])) {
			continue;
		}
		foreach ($dates as $dd) {
			$absence[$opName][date('Y-m-d', strtotime($dd))] = 1;
		}
	}

	$SHARED = array(1 => 1, 2 => 1, 7 => 1);
	$bDays = pc_bdays($dayStart, $dayEnd);
	if (empty($bDays)) {
		$bDays[] = $dayStart;
	}

	$opOrder = array_keys($EQUIPE);
	$sched = array();
	$mDay = array();
	$owner = array();
	$lastC = array();
	$safety = 120;
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
		$dStr = date('Y-m-d', $ts);

		// capacidade do dia = base menos horas de quem est├í ausente naquele dia
		$capDayNow = $wsCapBase;
		foreach ($absence as $opA => $daysA) {
			if (isset($daysA[$dStr])) {
				foreach ($EQUIPE[$opA]['postos'] as $widA) {
					if (!isset($capDayNow[$widA])) {
						$capDayNow[$widA] = 0;
					}
					$capDayNow[$widA] -= $effEq[$opA]['h_dia'];
				}
			}
		}

		$nOp = count($opOrder);
		for ($k = 0; $k < $nOp; $k++) {
			$op = $opOrder[($di + $k) % $nOp];
			if (isset($absence[$op][$dStr])) {
				continue;
			}
			while (true) {
				$usedOp = isset($mDay['_op'][$op][$ts]) ? $mDay['_op'][$op][$ts] : 0;
				if ($usedOp >= $effEq[$op]['h_dia'] - 0.005) {
					$mDay['_op'][$op][$ts] = $effEq[$op]['h_dia'];
					break;
				}
				$opFree = $effEq[$op]['h_dia'] - $usedOp;
				if ($opFree <= 0.05) {
					break;
				}
				$cand = array();
				foreach ($tasks as $ti2 => $tt) {
					if ($tt['left'] <= 0.02 || !in_array($op, $tt['ops'])) {
						continue;
					}
					$m = pc_mch($op, $tt);
					// preced├¬ncia: n├úo come├ºa etapa enquanto as m├íquinas anteriores da mesma MO n├úo terminaram
					$okPrec = true;
					$ord = isset($stepOrder[$tt['mid']]) ? $stepOrder[$tt['mid']] : array();
					$pi = array_search($tt['wid'], $ord);
					if ($pi !== false) {
						for ($q = 0; $q < $pi; $q++) {
							$pw = $ord[$q];
							foreach ($tasks as $pt) {
								if ($pt['mid'] == $tt['mid'] && $pt['wid'] == $pw && $pt['left'] > 0.02) {
									$okPrec = false;
									break;
								}
							}
							if (!$okPrec) {
								break;
							}
						}
					}
					if (!$okPrec) {
						continue;
					}
					$capFree = isset($capDayNow[$m]) ? $capDayNow[$m] : $CAP_H_PER_DAY;
					$mUsed = isset($mDay[$m][$ts]) ? $mDay[$m][$ts] : 0;
					if ($mUsed > $capFree - 0.005) {
						continue;
					}
					                    // precedencia: nao comeca etapa enquanto as maquinas anteriores da mesma MO nao terminaram
                    $okPrec = true;
                    $ord = isset($stepOrder[$tt["mid"]]) ? $stepOrder[$tt["mid"]] : array();
                    $pi = array_search($tt["wid"], $ord);
                    if ($pi !== false) {
                        for ($q = 0; $q < $pi; $q++) {
                            $pw = $ord[$q];
                            foreach ($tasks as $pt) {
                                if ($pt["mid"] == $tt["mid"] && $pt["wid"] == $pw && $pt["left"] > 0.02) {
                                    $okPrec = false;
                                    break;
                                }
                            }
                            if (!$okPrec) break;
                        }
                    }
                    if (!$okPrec) continue;
                    if (isset($SHARED[$m]) && isset($owner[$m][$ts]) && $owner[$m][$ts] != $op) {
						continue;
					}
					$cand[] = $ti2;
				}
				if (empty($cand)) {
					break;
				}
				usort($cand, function ($a, $b) use ($tasks, $lastC, $op, $opPrioWs) {
					// sequ├¬ncia manual da MO manda primeiro
					$pa = $tasks[$a]['prio'];
					$pb = $tasks[$b]['prio'];
					if ($pa != $pb) {
						return $pa - $pb;
					}
					// m├íquina priorit├íria do operador: s├│ vai para outra depois que a priorit├íria n├úo tiver mais trabalho
					$pw = isset($opPrioWs[$op]) ? $opPrioWs[$op] : 0;
					if ($pw) {
						$ca = ($tasks[$a]['wid'] == $pw ? 0 : 1);
						$cb = ($tasks[$b]['wid'] == $pw ? 0 : 1);
						if ($ca != $cb) {
							return $ca - $cb;
						}
					}
					                    // maquina prioritaria do operador: so vai para outra depois que a prioritaria nao tiver mais trabalho
                    $pw = isset($opPrioWs[$op]) ? $opPrioWs[$op] : 0;
                    if ($pw) {
                        $ca = ($tasks[$a]["wid"] == $pw ? 0 : 1);
                        $cb = ($tasks[$b]["wid"] == $pw ? 0 : 1);
                        if ($ca != $cb) {
                            return $ca - $cb;
                        }
                    }
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
				$capFree = isset($capDayNow[$m]) ? $capDayNow[$m] : $CAP_H_PER_DAY;
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
				$mDay['_op'][$op][$ts] = $usedOp + $chunk;
				if ($mDay['_op'][$op][$ts] >= $effEq[$op]['h_dia'] - 0.01) {
					$mDay['_op'][$op][$ts] = $effEq[$op]['h_dia'];
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

	$opTotals = array();
	$opDays = array();
	foreach ($EQUIPE as $op => $o) {
		$opTotals[$op] = 0.0;
		$opDays[$op] = array();
	}
	foreach ($sched as $s) {
		$opTotals[$s['op']] += $s['h'];
		$opDays[$s['op']][$s['ts']] = 1;
	}

	return array($sched, $endTs, $totSched, $leftOver, $opTotals, $opDays, $mDay, $wsCapDay, $bDays);
}

/**
 * Monta o payload JSON consumido pelo frontend.
 */
function pc_calc($EQUIPE, $mo, $moWsSec, $wsNames, $wsTotalSec, $CAP_H_PER_DAY, $moSteps = array(), $moStepOp = array(), $EF = 1.0)
{
	list($dayStart, $dayEnd) = pc_window($mo);
	$AUS = pc_ausencias_get();
	$SEQ = pc_seq_get();
	list($sched, $endTs, $totSched, $leftOver, $opTotals, $opDays, $mDay, $wsCapDay, $bDays) =
		pc_schedule($EQUIPE, $moWsSec, $dayStart, $dayEnd, $CAP_H_PER_DAY, $AUS, $SEQ, $moStepOp, $EF, $moSteps);
	$nDays = count($bDays);

	$WS_LABEL_FIX = array(
		1 => 'Furadeira', 2 => 'Lixadeira', 3 => 'Autrobot', 4 => 'Decapagem esta├º├úo 1',
		5 => 'Tamboriador', 6 => 'Torno', 7 => 'Solda Manual M├íquina 1', 8 => 'Rebitadeira manual',
	);
	$macLbl = function ($wid) use ($wsNames, $WS_LABEL_FIX) {
		return isset($wsNames[$wid]) ? $wsNames[$wid] : (isset($WS_LABEL_FIX[$wid]) ? $WS_LABEL_FIX[$wid] : '#' . $wid);
	};

	// m├íquinas
	$macs = array();
	foreach ($wsTotalSec as $wid => $sec) {
		$capDay = isset($wsCapDay[$wid]) ? $wsCapDay[$wid] : $CAP_H_PER_DAY;
		$macs[] = array(
			'id' => (int)$wid,
			'label' => $macLbl($wid),
			'h' => round(pc_sec2h($sec), 1),
			'capDay' => round($capDay, 1),
			'cap' => round($capDay * $nDays, 1),
			'pct' => round(pc_sec2h($sec) / max($capDay * $nDays, 0.001) * 100, 0),
		);
	}
	usort($macs, function ($a, $b) {
		return $b['h'] - $a['h'];
	});

	// grade di├íria real (do cronograma)
	$macDaily = array();
	foreach ($mDay as $wid => $days) {
		if ($wid === '_op') {
			continue;
		}
		foreach ($days as $ts => $h) {
			$macDaily[(int)$wid][date('Y-m-d', $ts)] = round($h, 2);
		}
	}

	// operadores
	$ops = array();
	$teamDay = 0;
	foreach ($EQUIPE as $name => $o) {
		$teamDay += $o['h_dia'];
		$ops[] = array(
			'name' => $name,
			'h_dia' => $o['h_dia'],
			'postos' => array_map(function ($w) use ($macLbl) {
				return $macLbl($w);
			}, $o['postos']),
			'h_sched' => round($opTotals[$name], 1),
			'dias' => count($opDays[$name]),
			'pct' => round($opTotals[$name] / max($o['h_dia'] * $nDays, 0.001) * 100, 0),
		);
	}
	$teamCapH = $teamDay * $nDays;

	// cronograma dia a dia
	$schedRows = array();
	foreach ($sched as $s) {
		$m = isset($mo[$s['mid']]) ? $mo[$s['mid']] : array('ref' => 'MO#' . $s['mid'], 'label' => '', 'qty' => 0);
		$schedRows[] = array(
			'ts' => date('Y-m-d', $s['ts']),
			'op' => $s['op'],
			'mid' => $s['mid'],
			'wid' => (int)$s['wid'],
			'ws' => $macLbl($s['wid']),
			'h' => round($s['h'], 2),
			'ref' => $m['ref'],
			'prod' => $m['label'],
		);
	}

	// acumulado por dia (curva S)
	$byDay = array();
	foreach ($schedRows as $s) {
		if (!isset($byDay[$s['ts']])) {
			$byDay[$s['ts']] = 0;
		}
		$byDay[$s['ts']] += $s['h'];
	}

	// tempos por MO para edi├º├úo
	$moTimes = array();
	foreach ($moWsSec as $mid => $wslist) {
		$moTimes[$mid] = array(
			'ref' => $mo[$mid]['ref'],
			'label' => $mo[$mid]['label'],
			'postos' => array(),
		);
		foreach ($wslist as $wid => $sec) {
			$moTimes[$mid]['postos'][(int)$wid] = round(pc_sec2h($sec), 2);
		}
	}

	// materiais vs estoque
	$mats = pc_load_materials();
	$missN = 0;
	$missCost = 0.0;
	foreach ($mats as $mt) {
		if ($mt['missing'] > 0.001) {
			$missN++;
			$missCost += $mt['cost'];
		}
	}

	$endISO = $endTs ? date('Y-m-d', $endTs) : '';
	$overdue = ($endISO !== '' && strcmp($endISO, date('Y-m-d', $dayEnd)) > 0);

	// Lista ordenada de MOs para a aba de sequ├¬ncia (manual primeiro, depois por ref)
	$seqOrder = array();
	foreach ($SEQ as $mid) {
		if (isset($mo[$mid]) && !in_array($mid, $seqOrder)) {
			$seqOrder[] = $mid;
		}
	}
	$rest = array_keys($mo);
	sort($rest);
	foreach ($rest as $mid) {
		if (!in_array($mid, $seqOrder)) {
			$seqOrder[] = $mid;
		}
	}
	$seqList = array();
	foreach ($seqOrder as $pos => $mid) {
		if (!isset($mo[$mid])) {
			continue;
		}
		$m = $mo[$mid];
		$seqList[] = array(
			'mid' => (int)$mid,
			'pos' => $pos,
			'ref' => $m['ref'],
			'label' => $m['label'],
			'qty' => $m['qty'],
			'status' => $m['status'],
			'manual' => in_array($mid, $SEQ) ? 1 : 0,
		);
	}

	return array(
		'dayStart' => date('Y-m-d', $dayStart),
		'dayEnd' => date('Y-m-d', $dayEnd),
		'nDays' => $nDays,
		'endTs' => $endISO,
		'totSched' => round($totSched, 1),
		'leftOver' => round($leftOver, 2),
		'pctDone' => $leftOver <= 0.02 ? 100 : round(($totSched - $leftOver) / max($totSched, 0.001) * 100, 0),
		'macs' => $macs,
		'macDaily' => $macDaily,
		'ops' => $ops,
		'teamDay' => round($teamDay, 1),
		'teamCapH' => round($teamCapH, 1),
		'sched' => $schedRows,
		'byDay' => $byDay,
		'bDays' => array_map(function ($d) {
			return date('Y-m-d', $d);
		}, $bDays),
		'moTimes' => $moTimes,
		'equipe' => $EQUIPE,
		'tempos' => pc_tempos_get(),
		'ferias' => pc_ferias_get(),
		'work_sat' => pc_work_sat(),
		'work_sun' => pc_work_sun(),
		'ausencias' => $AUS,
		'eff' => pc_eficiencia_get(),
		'seq' => $SEQ,
		'seqList' => $seqList,
		'steps' => $moSteps,
		'operators' => array_keys($EQUIPE),
		'wsLabels' => $wsNames,
		'mats' => $mats,
		'missingN' => $missN,
		'missingCost' => round($missCost, 2),
		'overdue' => $overdue,
		'err' => '',
	);
}
