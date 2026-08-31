<?php
/**
 * Painel de Planejamento Metaltechne — várias telas, gráficos e edição em tempo real.
 * /custom/planejamento/index.php
 */

require '../../main.inc.php';
require __DIR__ . '/sched.inc.php';

if (!$user->id) {
	accessforbidden();
}

$langs->load('main');
$topmenu = 'mrp';
$leftmenu = 'mrp';

$CAP_H_PER_DAY = 12600 / 3600; // 3,5 h/turno (fallback)

// ---------- reset de configuração ----------
if (isset($_GET['reset'])) {
	if ($_GET['reset'] === 'equipe') {
		$def = array(
			'Miguel' => array('h_dia' => 4.0, 'postos' => array(1, 2, 6, 8)),
			'Kauan'  => array('h_dia' => 4.0, 'postos' => array(1, 2, 3, 4, 5, 8)),
			'Saulo'  => array('h_dia' => 4.5, 'postos' => array(7)),
			'Marcio' => array('h_dia' => 9.0, 'postos' => array(7)),
		);
		pc_const_set(PC_CONF_EQUIPE, json_encode($def));
		pc_const_set(PC_CONF_TEMPOS, json_encode(array()));
		pc_const_set(PC_CONF_FERIAS, json_encode(array()));
		pc_const_set(PC_CONF_AUSENCIAS, json_encode(array()));
		pc_const_set(PC_CONF_FIMSAT, '0');
		pc_const_set(PC_CONF_FIMSUN, '0');
	}
	header('Location: index.php');
	exit;
}

list($mo, $moWsSec, $wsNames, $wsTotalSec, $moSteps, $moStepOp) = pc_load_data();
$EQUIPE = pc_equipe_get();

// ---------- AJAX: salvar configuração e recalcular em tempo real ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && isset($_GET['cfg'])) {
	$raw = base64_decode(strtr($_GET['cfg'], '-_', '+/'));
	$cfg = json_decode($raw, true);
	if (!is_array($cfg)) {
		$cfg = array();
	}
	if (isset($cfg['equipe']) && is_array($cfg['equipe'])) {
		$clean = array();
		foreach ($cfg['equipe'] as $name => $e) {
			$name = trim($name);
			if ($name === '') {
				continue;
			}
			$postos = array();
			if (isset($e['postos']) && is_array($e['postos'])) {
				foreach ($e['postos'] as $w) {
					$w = (int)$w;
					if ($w > 0 && !in_array($w, $postos)) {
						$postos[] = $w;
					}
				}
			}
			$hd = isset($e['h_dia']) ? (float)str_replace(',', '.', $e['h_dia']) : 0;
			$clean[$name] = array('h_dia' => max(0, $hd), 'postos' => $postos);
		}
		pc_const_set(PC_CONF_EQUIPE, json_encode($clean));
		$EQUIPE = $clean;
	}
	if (isset($cfg['tempos']) && is_array($cfg['tempos'])) {
		$clean = array();
		foreach ($cfg['tempos'] as $k => $h) {
			$h = (float)str_replace(',', '.', $h);
			if ($h > 0) {
				$clean[$k] = round($h, 3);
			}
		}
		pc_const_set(PC_CONF_TEMPOS, json_encode($clean));
	}
	if (isset($cfg['ferias']) && is_array($cfg['ferias'])) {
		$clean = array();
		foreach ($cfg['ferias'] as $f) {
			$f = trim($f);
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) {
				$clean[] = $f;
			}
		}
		pc_const_set(PC_CONF_FERIAS, json_encode($clean));
	}
	if (isset($cfg['ausencias']) && is_array($cfg['ausencias'])) {
		$cleanA = array();
		foreach ($cfg['ausencias'] as $opName => $dates) {
			$opName = trim($opName);
			if (!isset($EQUIPE[$opName])) {
				continue;
			}
			$list = array();
			if (is_array($dates)) {
				foreach ($dates as $dd) {
					$dd = trim($dd);
					if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dd)) {
						$list[] = $dd;
					}
				}
			}
			if (!empty($list)) {
				$cleanA[$opName] = $list;
			}
		}
		pc_const_set(PC_CONF_AUSENCIAS, json_encode($cleanA));
	}
	pc_const_set(PC_CONF_FIMSAT, !empty($cfg['work_sat']) ? '1' : '0');
	pc_const_set(PC_CONF_FIMSUN, !empty($cfg['work_sun']) ? '1' : '0');
	if (isset($cfg['eficiencia'])) {
		$ef = (float)str_replace(',', '.', $cfg['eficiencia']);
		if ($ef < 1 || $ef > 100) {
			$ef = 85;
		}
		pc_const_set(PC_CONF_EFICIENCIA, (string)round($ef, 1));
	}
	if (isset($cfg['seq']) && is_array($cfg['seq'])) {
		$cleanSeq = array();
		foreach ($cfg['seq'] as $mid) {
			$mid = (int)$mid;
			if ($mid > 0 && !in_array($mid, $cleanSeq)) {
				$cleanSeq[] = $mid;
			}
		}
		pc_seq_set($cleanSeq);
	}
	if (isset($cfg['steps']) && is_array($cfg['steps'])) {
		$cleanSteps = array();
		foreach ($cfg['steps'] as $mid => $stepsList) {
			$mid = (int)$mid;
			if ($mid <= 0 || !is_array($stepsList)) {
				continue;
			}
			$list = array();
			foreach ($stepsList as $st) {
				$wid = (int)(isset($st['wid']) ? $st['wid'] : 0);
				$spc = (float)str_replace(',', '.', (isset($st['spc']) ? $st['spc'] : -1));
				if ($spc < 0 && isset($st['h'])) {
					$spc = (float)$st['h']; // compat: h total antigo
				}
				if ($wid > 0 && $spc > 0) {
					$op = isset($st['op']) ? trim($st['op']) : '';
					$list[] = array('wid' => $wid, 'spc' => round($spc, 3), 'op' => $op);
				}
			}
			if (!empty($list)) {
				$cleanSteps[$mid] = $list;
			}
		}
		pc_steps_set($cleanSteps);
	}
	// recalcula (MO/tempos podem ter mudado via override)
	list($mo, $moWsSec, $wsNames, $wsTotalSec, $moSteps, $moStepOp) = pc_load_data();

	$C = pc_calc($EQUIPE, $mo, $moWsSec, $wsNames, $wsTotalSec, $CAP_H_PER_DAY, $moSteps, $moStepOp, pc_eficiencia_get() / 100);
	// re-carrega o que veio do POST (ex.: h_dia novo) e devolve
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(array('calc' => $C, 'html' => pc_html($C)));
	$db->close();
	exit;
}

// ---------- render comum ----------
	$C = pc_calc($EQUIPE, $mo, $moWsSec, $wsNames, $wsTotalSec, $CAP_H_PER_DAY, $moSteps, $moStepOp, pc_eficiencia_get() / 100);

	// ---------- export CSV do cronograma ----------
if (isset($_GET['export']) && $_GET['export'] === 'sched') {
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="cronograma_planejamento.csv"');
	$out = fopen('php://output', 'w');
	fputcsv($out, array('dia', 'operador', 'op', 'produto', 'posto', 'horas'));
	foreach ($C['sched'] as $s) {
		fputcsv($out, array($s['ts'], $s['op'], $s['ref'], $s['prod'], $s['ws'], $s['h']));
	}
	fputcsv($out, array());
	fputcsv($out, array('total h', $C['totSched']));
	fputcsv($out, array('termino previsto', $C['endTs']));
	fputcsv($out, array('dias uteis', $C['nDays']));
	fclose($out);
	$db->close();
	exit;
}

$H = pc_html($C);

llxHeader('', 'Plano de Produção — Planejamento', '');
print '<style>' . pc_css() . '</style>';

print '<div style="margin-bottom:12px">';
print '<div class="inline-block" style="margin-right:16px"><a href="' . dol_buildpath('/mrp/mo_list.php', 1) . '" class="butAction">← Ordens de fabricação</a></div>';
print '<div class="inline-block" style="margin-right:16px"><a href="workload.php" class="butAction">Versão tabelas</a></div>';
print '<h1>Planejamento de Produção — Setembro 2026</h1></div>';

print '<div id="app">';

// navegação entre telas
print '<div class="pc-nav">';
foreach (array(
	'dash' => '📊 Dashboard',
	'mac'  => '🏭 Máquinas',
	'op'   => '👷 Operadores',
	'cron' => '📅 Cronograma',
	'seq'  => '📋 Sequência',
	'etp'  => '🛠️ Etapas',
	'mat'  => '📦 Materiais',
	'cfg'  => '⚙️ Configuração',
) as $tab => $lbl) {
	print '<button class="pc-tab" data-tab="' . $tab . '">' . $lbl . '</button>';
}
print '</div>';

print '<div class="pc-msg" id="pc-msg"></div>';

// ---------------- Dashboard ----------------
print '<div class="pc-panel" id="tab-dash"><div id="htCards">' . $H['cards'] . '</div>
	<div class="pc-grid3">
		<div class="pc-card"><h3>Carga por máquina (h) × capacidade</h3><canvas id="chDashBar" height="220"></canvas></div>
		<div class="pc-card"><h3>Uso da jornada por operador</h3><canvas id="chDashDonut" height="220"></canvas></div>
		<div class="pc-card"><h3>Horas acumuladas no cronograma (curva S)</h3><canvas id="chDashLine" height="220"></canvas></div>
	</div></div>';

// ---------------- Máquinas ----------------
print '<div class="pc-panel" id="tab-mac" style="display:none">
	<div class="pc-card"><h3>Horas por máquina vs capacidade mensal</h3><canvas id="chMacBar" height="220"></canvas></div>
	<div class="pc-card" style="margin-top:14px"><h3>Carga diária real por máquina (cronograma)</h3><div id="htMac">' . $H['macHeat'] . '</div></div></div>';

// ---------------- Operadores ----------------
print '<div class="pc-panel" id="tab-op" style="display:none">
	<div class="pc-grid2">
		<div class="pc-card"><h3>Horas no cronograma por operador</h3><canvas id="chOpBar" height="220"></canvas></div>
		<div class="pc-card"><h3>Distribuição por operador</h3><div id="htOp">' . $H['opCards'] . '</div></div>
	</div>
	<div class="pc-card" style="margin-top:14px"><h3>Detalhe por operador</h3><div id="htOpTab">' . $H['opTab'] . '</div></div></div>';

// ---------------- Cronograma ----------------
print '<div class="pc-panel" id="tab-cron" style="display:none">
	<p><a class="butAction" href="?export=sched" target="_blank" rel="noopener">Exportar cronograma (CSV)</a></p>
	<div class="pc-card"><h3>Linha do tempo (dia a dia) por operador</h3><div id="htGantt">' . $H['gantt'] . '</div></div>
	<div class="pc-card" style="margin-top:14px"><h3>Detalhe previsto dia a dia</h3><div id="htSched">' . $H['schedTab'] . '</div></div></div>';

// ---------------- Sequência (ordem de fabricação manual) ----------------
print '<div class="pc-panel" id="tab-seq" style="display:none">
	<div class="pc-grid2">
		<div class="pc-card"><h3>Ordem de fabricação (arraste para definir a prioridade)</h3>
			<p class="opacitymedium" style="font-size:12px">A ordem de cima para baixo é a sequência em que cada MO é fabricada. Arraste e solte para reordenar; o cronograma atualiza na hora. Os itens sem marcador seguem a ordem automática no fim.</p>
			<div id="seqList"></div>
			<p style="margin-top:8px"><button class="butAction" id="btn_seq_auto" type="button">Voltar para ordem automática</button></p>
		</div>
		<div class="pc-card"><h3>Resultado no tempo (operações por máquina)</h3><div id="seqGantt"></div></div>
	</div>
	<div class="pc-card" style="margin-top:14px"><h3>Fila por posto (sequência efetiva)</h3><div id="seqEsq"></div></div></div>';

// ---------------- Etapas (operações por MO + operador definido) ----------------
print '<div class="pc-panel" id="tab-etp" style="display:none">
	<p class="opacitymedium" style="font-size:12px">Defina as etapas (operações) de cada ordem de fabricação num fluxo visual. Em cada etapa você escolhe o <b>posto</b>, o <b>operador</b> (vazio = automático) e o tempo em <b>segundos por peça</b>. O sistema calcula o total da etapa = s/pç × quantidade e monta as datas. Arraste os cartões para reordenar o fluxo. Se a MO tiver etapas configuradas, elas substituem a BOM; as alterações recalculam na hora.</p>
	<div class="pc-card"><h3>Etapas por ordem de fabricação</h3><div id="etpList"></div>
		<p style="margin-top:10px"><button class="butAction" id="btn_etp_limpar" type="button">Limpar etapas customizadas (usar BOM em todas)</button></p>
	</div></div>';

// ---------------- Materiais ----------------
print '<div class="pc-panel" id="tab-mat" style="display:none">
	<div class="pc-card"><h3>Matéria-prima — consumo do plano vs estoque (armazém Fábrica)</h3><div id="htMats">' . $H['mats'] . '</div></div></div>';

// ---------------- Configuração ----------------
print '<div class="pc-panel" id="tab-cfg" style="display:none">
	<div class="pc-card"><h3>Eficiência de produção</h3><div id="htCfgEff">' . $H['cfgEff'] . '</div></div>
	<div class="pc-card"><h3>Equipe (horas/dia e postos habilitados)</h3><div id="htCfgEquipe">' . $H['cfgEquipe'] . '</div></div>
	<div class="pc-card" style="margin-top:14px"><h3>Ausências / férias por operador (aaaa-mm-dd separados por vírgula; dia inteiro)</h3><div id="htCfgAus">' . $H['cfgAus'] . '</div></div>
	<div class="pc-card" style="margin-top:14px"><h3>Tempos por ordem (h) — vazio = usar padrão da BOM</h3><div id="htCfgTempos">' . $H['cfgTempos'] . '</div></div>
	<div class="pc-card" style="margin-top:14px"><h3>Calendário</h3><div id="htCfgCal">' . $H['cfgCal'] . '</div></div></div>';

print '</div>';

print '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
print '<script>';
print 'var PLAN=' . json_encode($C) . ';';
print 'var HHTML=' . json_encode($H['charts']) . ';';
print '</script>';
print '<script>' . pc_js() . '</script>';

llxFooter();
$db->close();

// =====================================================================
function pc_css()
{
	return <<<'CSS'
.pc-nav{margin:10px 0 16px;border-bottom:2px solid #4e79a7}
.pc-tab{background:none;border:none;padding:10px 16px;font-size:14px;cursor:pointer;color:#455573;border-bottom:3px solid transparent}
.pc-tab.active{border-bottom-color:#4e79a7;color:#1a355e;font-weight:bold}
.pc-msg{margin:6px 0;color:#1a6b2f;font-weight:bold;min-height:18px}
.pc-card{background:#fff;border:1px solid #dde3ec;border-radius:6px;padding:12px}
.pc-grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.pc-grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-top:14px}
.pc-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px}
.kpi{background:#f4f7fb;border:1px solid #d8e0ec;border-radius:8px;padding:12px;text-align:center}
.kpi .v{font-size:26px;font-weight:bold;color:#1a355e}
.kpi .l{font-size:12px;color:#5a6a85}
.pc-tabla{width:100%;border-collapse:collapse;font-size:13px}
.pc-tabla th{background:#2b4a7e;color:#fff;padding:6px;text-align:left}
.pc-tabla td{padding:5px 6px;border-bottom:1px solid #e5eaf2}
.ht-heat td{min-width:52px;text-align:right;font-size:12px}
.ht-heat td.dl{background:#ffd9b0!important;color:#a33}
.ht-heat td.ol{background:#ffb3b3!important;color:#900;font-weight:bold}
.pc-gantt{position:relative;margin-top:6px}
.pc-gantt .row{position:relative;height:36px;border-bottom:1px solid #e5eaf2}
.pc-gantt .lbl{position:absolute;left:0;top:0;width:110px;font-size:12px;line-height:36px;overflow:hidden}
.pc-gantt .cells{position:absolute;left:114px;right:4px;top:0;height:36px}
.pc-gantt .cell{position:absolute;top:0;height:36px;border-left:1px solid #f0f3f8}
.pc-gantt .ghead{position:relative;height:30px;border-bottom:1px solid #c9d4e6}
.pc-gantt .ghead .cells{height:30px}
.pc-gantt .ghead .cell{height:30px;font-size:10px;color:#5a6a85;padding-top:2px}
.pc-gantt .bar{position:absolute;top:8px;height:20px;border-radius:4px;min-width:6px;cursor:default;font-size:0}
.pc-gantt .bar:hover{outline:2px solid #1a355e;font-size:0}
.pc-gantt .bar.has{background-image:linear-gradient(rgba(255,255,255,.25),rgba(255,255,255,.25))}
.today{background:#fff2cc!important}
.pc-warn{background:#ffe1e1;border:1px solid #e5a3a3;color:#8c1d18;padding:10px 12px;border-radius:6px;margin-bottom:12px;font-weight:bold}
td.lack{background:#ffe1e1;color:#8c1d18}
input[type=number],input[type=text],select,textarea{padding:4px}
#htMac{max-width:100%;overflow:auto}
.seq-wrap{display:grid;grid-template-columns:40px 1fr 1fr 70px;gap:6px;align-items:center;border-bottom:2px solid #2b4a7e;padding-bottom:6px;margin-bottom:4px}
.seq-head{font-weight:bold;color:#fff;background:#2b4a7e;padding:5px 8px;border-radius:4px}
.seq-head:first-child{text-align:center;background:transparent;color:#5a6a85}
.seq-row{display:grid;grid-template-columns:40px 1fr 1fr 70px;gap:6px;align-items:center;padding:6px 0;border-bottom:1px solid #e5eaf2;cursor:grab}
.seq-row.drag{opacity:.4}
.seq-row:hover{background:#f4f8ff}
.seq-pos{width:26px;height:26px;border-radius:50%;background:#4e79a7;color:#fff;font-weight:bold;text-align:center;line-height:26px;font-size:13px}
.seq-cell{padding:2px 4px;font-size:13px}
.seq-cell.main{font-weight:bold}
.seq-cell.num{text-align:right;color:#5a6a85}
.grip{cursor:grab;opacity:.5}
.tagm{background:#efe7ff;color:#6a3bb0;font-size:10px;border-radius:3px;padding:1px 4px;margin-left:4px;vertical-align:middle}
.seq-gantt{position:relative;margin-top:6px;max-width:100%;overflow-x:auto}
.seq-gantt .row{position:relative;height:36px;border-bottom:1px solid #e5eaf2;min-width:320px}
.seq-gantt .lbl{position:absolute;left:0;top:0;width:110px;font-size:12px;line-height:36px;overflow:hidden;white-space:nowrap}
.seq-gantt .cells{position:absolute;left:114px;right:4px;top:0;height:36px}
.seq-gantt .cell{position:absolute;top:0;height:36px;border-left:1px solid #f0f3f8}
.seq-gantt .ghead{position:relative;height:30px;border-bottom:1px solid #c9d4e6}
.seq-gantt .ghead .cells{height:30px}
.seq-gantt .ghead .cell{height:30px;font-size:10px;color:#5a6a85;padding-top:2px}
.seq-gantt .bar{position:absolute;top:8px;height:20px;border-radius:4px;min-width:6px;font-size:0}
.seq-gantt .bar.has{background-image:linear-gradient(rgba(255,255,255,.25),rgba(255,255,255,.25))}
.etp-mo{margin:8px 0;border:1px solid #e5eaf2;border-radius:6px;overflow:hidden}
.etp-mo summary{list-style:none;display:flex;align-items:center;gap:8px;padding:9px 12px;cursor:pointer;background:#f7f9fd;flex-wrap:wrap}
.etp-mo summary::-webkit-details-marker{display:none}
.etp-mo summary:hover{background:#eef4ff}
.etp-chev{display:inline-block;transition:transform .15s;color:#5a6a85;font-size:11px}
.etp-mo[open] .etp-chev{transform:rotate(90deg)}
.etp-body{padding:10px 12px;border-top:1px solid #e5eaf2}
.etp-flow{display:flex;align-items:stretch;gap:6px;overflow-x:auto;padding:6px 2px}
.etp-fnode{flex:0 0 210px;border:1px solid #d4def0;border-radius:8px;background:#fbfcff;box-shadow:0 1px 3px rgba(43,74,126,.08)}
.etp-fnode.drag{opacity:.4;border-style:dashed}
.etp-fhead{display:flex;align-items:center;gap:6px;padding:6px 8px;border-bottom:1px solid #eef1f6;background:#eef4ff;border-radius:8px 8px 0 0}
.etp-fhead .grip{cursor:grab;color:#8a93a6}
.etp-tot{margin-left:auto;font-size:12px;font-weight:bold;color:#2b7a3b;white-space:nowrap}
.etp-fbody{padding:8px;font-size:13px}
.etp-fbody select{width:100%;padding:3px 5px;border:1px solid #cbd5e6;border-radius:4px;font-size:12px;margin-bottom:5px}
.etp-spcbox{display:flex;align-items:center;gap:4px}
.etp-spcbox input{flex:1;min-width:0;padding:3px 5px;border:1px solid #cbd5e6;border-radius:4px;font-size:13px}
.etp-conn{flex:0 0 34px;display:flex;align-items:center;justify-content:center}
.etp-n{display:inline-flex;width:20px;height:20px;border-radius:50%;background:#4e79a7;color:#fff;font-size:11px;align-items:center;justify-content:center;flex:0 0 auto}
.etp-un{color:#5a6a85;font-size:12px}
.etp-btns{display:inline-flex;gap:4px;margin-left:auto}
button.mini{padding:2px 7px;font-size:12px;border:1px solid #cbd5e6;border-radius:4px;background:#fff;cursor:pointer}
button.mini:hover{background:#eef4ff}
button.mini.del{border-color:#f0b9b9;color:#c0392b}
button.mini.add{color:#2b7a3b;border-color:#bcdcc4}
.etp-buylbl{display:flex;align-items:center;gap:6px;font-size:12px;color:#2b7a3b;margin-top:6px;cursor:pointer}
.etp-buy{width:16px;height:16px;accent-color:#2b7a3b}
.etp-fnode.etp-buy{background:#f0fff4;border-color:#bcdcc4}
.etp-fnode.etp-buy .etp-tot{color:#2b7a3b}
.muted{color:#8a93a6;font-style:italic}
CSS;
}

function pc_js()
{
	return <<<'JS'
(function(){
	var CTX=null;
	function tiny(n){return Math.round(n*100)/100;}
	function afterCharts(fn){ if(window.Chart){fn();} else {setTimeout(function(){afterCharts(fn);},120);} }
	function refreshCharts(C){
		var ch={};
		ch.macsBar={labels:C.macs.map(function(m){return m.label;}),h:C.macs.map(function(m){return m.h;}),cap:C.macs.map(function(m){return m.cap;})};
		ch.opsDonut={labels:C.ops.map(function(o){return o.name;}),pct:C.ops.map(function(o){return o.pct;})};
		var days=C.bDays;var cum=[];var acc=0;
		for(var i=0;i<days.length;i++){acc+=(C.byDay[days[i]]||0);cum.push(tiny(acc));}
		ch.cum={days:C.bDays.map(function(d){return d.slice(5);}),cum:cum,tot:C.totSched};
		HHTML=ch;
	}
	function renderCharts(){
		if(!window.Chart){return;}
		var reg=window.__charts=window.__charts||{};
		function mk(id,cfg){if(reg[id]){reg[id].destroy();}reg[id]=new Chart(document.getElementById(id),cfg);}
		// dash bar
		var a=HHTML.macsBar;
		mk('chDashBar',{type:'bar',data:{labels:a.labels,datasets:[
			{label:'Horas no plano',data:a.h,backgroundColor:'#4e79a7'},
			{label:'Capacidade (mês)',data:a.cap,backgroundColor:'#d8e0ec'}]},
			options:{responsive:true,plugins:{legend:{position:'bottom'}}}});
		// mac bar
		mk('chMacBar',{type:'bar',data:{labels:a.labels,datasets:[
			{label:'Horas no plano',data:a.h,backgroundColor:'#59a14f'},
			{label:'Capacidade (mês)',data:a.cap,backgroundColor:'#d8e0ec'}]},
			options:{responsive:true,plugins:{legend:{position:'bottom'}}}});
		// donut operadores
		var d=HHTML.opsDonut;
		mk('chDashDonut',{type:'doughnut',data:{labels:d.labels,datasets:[
			{data:d.pct,backgroundColor:['#4e79a7','#f28e2b','#e15759','#76b7b2','#59a14f','#edc948','#b07aa1','#ff9da7']}]},
			options:{responsive:true,plugins:{legend:{position:'bottom'},tooltip:{callbacks:{label:function(cc){return cc.label+' · '+cc.parsed+'% da jornada';}}}}}});
		// linha acumulada
		var l=HHTML.cum;
		mk('chDashLine',{type:'line',data:{labels:l.days,datasets:[
			{label:'Horas acumuladas',data:l.cum,borderColor:'#e15759',backgroundColor:'rgba(225,87,89,.12)',fill:true,tension:.25},
			{label:'Total do plano',data:l.days.map(function(){return l.tot;}),borderColor:'#9aa5b5',borderDash:[6,4]}]},
			options:{responsive:true,plugins:{legend:{position:'bottom'}}}});
		// op bar
		var C=PLAN;
		mk('chOpBar',{type:'bar',data:{labels:C.ops.map(function(o){return o.name;}),datasets:[
			{label:'Horas no cronograma',data:C.ops.map(function(o){return o.h_sched;}),backgroundColor:'#f28e2b'},
			{label:'Jornada mensal',data:C.ops.map(function(o){return tiny(o.h_dia*C.nDays);}),backgroundColor:'#d8e0ec'}]},
			options:{indexAxis:'y',responsive:true,plugins:{legend:{position:'bottom'}}}});
		afterCharts(function(){CTX=null;});
	}
	function swap(id,html){document.getElementById(id).innerHTML=html;}
	function applyHtml(H){
		swap('htCards',H.cards);
		swap('htMac',H.macHeat);swap('htOp',H.opCards);swap('htOpTab',H.opTab);
		swap('htGantt',H.gantt);swap('htSched',H.schedTab);
		swap('htMats',H.mats);swap('htCfgAus',H.cfgAus);
	}
	function refresh(H){
		if(H.gantt){applyHtml(H);}
		renderCharts();
		renderSeq();
	}
	// ================== Sequência de fabricação manual ==================
	var SEQ_STATE=null;
	function seqOrder(){ // array de mids na ordem atual exibida
		if(SEQ_STATE){return SEQ_STATE.slice();}
		return PLAN.seqList.map(function(it){return it.mid;});
	}
	function esc(s){return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
	function seqListHtml(){
		var midOf={};PLAN.seqList.forEach(function(it){midOf[it.mid]=it;});
		var html='<div class="seq-wrap"><div class="seq-head">'+esc('MO')+'</div><div class="seq-head">'+esc('Produto')+'</div><div class="seq-head">'+esc('Qtd')+'</div></div>';
		seqOrder().forEach(function(mid,idx){
			var it=midOf[mid];if(!it){return;}
			html+='<div class="seq-row" draggable="true" data-mid="'+mid+'" data-idx="'+idx+'">'
				+'<div class="seq-pos">'+(idx+1)+'</div>'
				+'<div class="seq-cell main"><span class="grip">☰</span> <b>'+esc(it.ref)+'</b>'+(it.manual?' <span class="tagm">manual</span>':'')+'</div>'
				+'<div class="seq-cell">'+esc(it.label)+'</div>'
				+'<div class="seq-cell num">'+esc(it.qty)+'</div>'
				+'</div>';
		});
		if(!seqOrder().length){html='<p class="muted">Sem ordens de fabricação ativas.</p>';}
		return html;
	}
	function seqGanttHtml(){
		var days=PLAN.bDays||[];
		if(!days.length){return '<p class="muted">Sem dados.</p>';}
		var cellW=120;
		var colors=pcColors();
		// agrupar operações por máquina
		var macOrder=[],byMac={};
		PLAN.sched.forEach(function(s){
			var w=s.wid;
			if(!byMac[w]){byMac[w]={label:s.ws,ops:[]};macOrder.push(w);}
			byMac[w].ops.push(s);
		});
		// order machines by total hours desc
		var totByMac={};PLAN.sched.forEach(function(s){totByMac[s.wid]=(totByMac[s.wid]||0)+s.h;});
		macOrder.sort(function(a,b){return (totByMac[b]||0)-(totByMac[a]||0);});
		var dayIdx={};days.forEach(function(d,i){dayIdx[d]=i;});
		var today=PLAN.sched.length? PLAN.dayStart: todayISO();
		var weekMap={};days.forEach(function(d){weekMap[d]=(d<today)?'past':(d===today?'today':'');});
		// precompute start ts per op row for sequential bars: use MIN op? 
		var html='<div class="seq-gantt"><div class="ghead"><div class="lbl">Máquina</div><div class="cells">';
		days.forEach(function(d,i){
			html+='<div class="cell'+(d===todayISO()?' today':'')+'" style="left:'+(i*cellW)+'px;width:'+cellW+'px">'+d.slice(8)+'</div>';
		});
		html+='</div></div>';
		macOrder.forEach(function(w){
			// Gantt real por máquina: dentro de cada dia as atividades são posicionadas
			// horizontalmente pelo tempo acumulado (uma fica mais à frente, outra mais atrás)
			var perDay={};
			byMac[w].ops.forEach(function(op){
				if(!perDay[op.ts]){perDay[op.ts]=[];}
				perDay[op.ts].push(op);
			});
			var capDay=pcCapDay(w);
			html+='<div class="row"><div class="lbl">'+esc(byMac[w].label)+'</div><div class="cells">';
			days.forEach(function(d,i){html+='<div class="cell" style="left:'+(i*cellW)+'px;width:'+cellW+'px"></div>';});
			Object.keys(perDay).forEach(function(ts){
				var i=dayIdx[ts];if(i<0||i===undefined)return;
				var ops=perDay[ts]; // já em ordem cronológica (ordem no PLAN.sched)
				var c=colors[w]||'#9aa5b5';
				var cum=0;
				ops.forEach(function(op,k){
					var frac=capDay>0?op.h/capDay:0;
					var startX=i*cellW+2+cum*cellW;
					var wdt=Math.max(8,Math.min(cellW-4,(op.h/capDay)*cellW));
					if(startX>=i*cellW+cellW){return;}
					html+='<div class="bar has" title="'+esc(op.ref+' · '+op.op+' · '+op.h+'h · '+ts)+'" style="left:'+startX+'px;width:'+wdt+'px;background:'+c+'"></div>';
					cum+=frac;
				});
			});
			html+='</div></div>';
		});
		if(!macOrder.length){html='<p class="muted">Sem operações escalonadas.</p>';}
		else{html+='</div>';}
		return html;
	}
	function pcCapDay(w){
		var m=PLAN.macs.filter(function(x){return x.id===w;})[0];
		return (m&&m.capDay)?m.capDay:8;
	}
	function todayISO(){var d=new Date();return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');}
	function seqEsqHtml(){
		// fila por posto: para cada máquina, sequência de MOs agrupada por dia
		var byMac={},order=[];
		PLAN.sched.forEach(function(s){
			if(!byMac[s.wid]){byMac[s.wid]={label:s.ws,rows:[]};order.push(s.wid);}
		});
		var seen={};
		PLAN.sched.forEach(function(s){
			if(!byMac[s.wid].rows.length||byMac[s.wid].rows[byMac[s.wid].rows.length-1].ts!==s.ts){byMac[s.wid].rows.push({ts:s.ts,refs:[]});}
			byMac[s.wid].rows[byMac[s.wid].rows.length-1].refs.push(s.ref+' ('+s.h+'h)');
		});
		order.sort(function(a,b){return byMac[a].label.localeCompare(byMac[b].label);});
		var html='<table class="pc-tabla"><tr><th>Posto</th><th>Dia → MOs</th></tr>';
		order.forEach(function(w){
			html+='<tr><td><b>'+esc(byMac[w].label)+'</b></td><td>';
			byMac[w].rows.forEach(function(r){
				html+='<span style="display:inline-block;margin:2px 6px 2px 0;background:#f0f5ff;border:1px solid #c9d4e6;border-radius:4px;padding:2px 6px;font-size:12px"><b>'+esc(r.ts)+'</b> → '+esc(r.refs.join(', '))+'</span>';
			});
			html+='</td></tr>';
		});
		if(!order.length){html='<p class="muted">Sem operações escalonadas.</p>';}
		else{html+='</table>';}
		return html;
	}
	function pcColors(){return {1:'#4e79a7',2:'#f28e2b',3:'#e15759',4:'#76b7b2',5:'#59a14f',6:'#edc948',7:'#b07aa1',8:'#ff9da7'};}
	function renderSeq(){
		swap('seqList',seqListHtml());
		swap('seqGantt',seqGanttHtml());
		swap('seqEsq',seqEsqHtml());
		// Cronograma idêntico à Sequência: mesma grade por máquina e mesma fila por posto
		swap('htGantt',seqGanttHtml());
		swap('htSched',seqEsqHtml());
		bindSeqDrag();
		renderSteps();
	}
	function bindSeqDrag(){
		var wrap=document.getElementById('seqList');if(!wrap)return;
		// clicar em uma linha seta como "manual" e move para o topo efetivo
		var rows=wrap.querySelectorAll('.seq-row');
		for(var i=0;i<rows.length;i++){
			var r=rows[i];
			r.addEventListener('dragstart',function(e){e.dataTransfer.setData('text/plain',String(this.getAttribute('data-mid')));this.classList.add('drag');});
			r.addEventListener('dragend',function(e){this.classList.remove('drag');});
			r.addEventListener('dragover',function(e){e.preventDefault();});
			r.addEventListener('drop',function(e){
				e.preventDefault();
				var from=parseInt(e.dataTransfer.getData('text/plain'),10);
				var to=parseInt(this.getAttribute('data-mid'),10);
				if(from&&to&&from!==to){reorder(from,to);}
			});
			r.addEventListener('click',function(e){
				if(e.target.closest('.grip')){return;}
				var mid=parseInt(this.getAttribute('data-mid'),10);
				pinSeq(mid);
			});
		}
	}
	function reorder(from,to){
		var arr=seqOrder();
		var fi=arr.indexOf(from),ti=arr.indexOf(to);
		arr.splice(fi,1);
		arr.splice(ti,0,from);
		SEQ_STATE=arr;
		renderSeq();commitSeq();
	}
	function pinSeq(mid){
		var arr=seqOrder();
		var i=arr.indexOf(mid);if(i<0)return;
		arr.splice(i,1);arr.unshift(mid);
		SEQ_STATE=arr;
		renderSeq();commitSeq();
	}
	function commitSeq(){
		window.__pcSeq=seqOrder();
		window.PcSave(true);
	}
	function seqAuto(){
		SEQ_STATE=null;window.__pcSeq=[];renderSeq();window.PcSave(true);
	}

	// ================== Etapas de fabricação + operador por etapa ==================
	// Cada etapa = {wid (posto), spc (segundos por peça), op (operador)}
	// O tempo total da etapa = spc × quantidade da MO
	var ETP_EDIT=null;
	var ETP_BOM={}; // mids fixadas para usar a BOM (sem custom)
	var SEQ_MAP={};
	function etpQty(mid){return SEQ_MAP[mid]?SEQ_MAP[mid].qty:1;}
	function etpSpcVal(x){var v=parseFloat(String(x).replace(',','.'));return isFinite(v)&&v>0?v:0;}
	function etpBuyVal(x){return (x===1||x==='1'||x===true)?1:0;}
	function etpRows(mid){
		if(!ETP_EDIT){ETP_EDIT={};}
		if(!ETP_EDIT[mid]){
			var src=(PLAN.steps&&PLAN.steps[mid])?PLAN.steps[mid]:[];
			ETP_EDIT[mid]=src.map(function(s){return {wid:String(s.wid),spc:String(s.spc),op:s.op||''};});
		}
		return ETP_EDIT[mid];
	}
	function etpWsSel(mid,row,idx){
		var s='<select class="etp-ws" data-e-wid="'+idx+'" data-mid="'+mid+'"><option value="">— posto —</option>';
		Object.keys(PLAN.wsLabels||{}).forEach(function(w){
			if(PLAN.wsLabels[w]&&PLAN.wsLabels[w].length){s+='<option value="'+w+'"'+(String(row.wid)===String(w)?' selected':'')+'>'+esc(PLAN.wsLabels[w])+'</option>';}
		});
		return s+'</select>';
	}
	function etpOpSel(mid,row,idx){
		var s='<select class="etp-op" data-e-op="'+idx+'" data-mid="'+mid+'"><option value="">Auto</option>';
		(PLAN.operators||[]).forEach(function(o){s+='<option value="'+esc(o)+'"'+(row.op===o?' selected':'')+'>'+esc(o)+'</option>';});
		return s+'</select>';
	}
	function flowNode(mid,row,idx){
		var qty=etpQty(mid);
		var buy=etpBuyVal(row.buy);
		var totH=buy?0:(etpSpcVal(row.spc)*qty)/3600;
		return '<div class="etp-fnode'+(buy?' etp-buy':'')+'" draggable="true" data-mid="'+mid+'" data-idx="'+idx+'">'
			+'<div class="etp-fhead"><span class="grip" title="Arraste para mudar a ordem">⣿</span>'
			+'<span class="etp-n">'+(idx+1)+'</span>'
			+'<span class="etp-tot">= '+totH.toFixed(2)+' h</span>'
			+'<span class="etp-btns">'
			+'<button type="button" class="mini" data-e-up="'+idx+'" data-mid="'+mid+'" title="subir">↑</button>'
			+'<button type="button" class="mini" data-e-dn="'+idx+'" data-mid="'+mid+'" title="descer">↓</button>'
			+'<button type="button" class="mini del" data-e-del="'+idx+'" data-mid="'+mid+'" title="remover">✕</button>'
			+'</span></div>'
			+'<div class="etp-fbody">'+etpWsSel(mid,row,idx)+' '+etpOpSel(mid,row,idx)
			+'<label class="etp-buylbl"><input type="checkbox" class="etp-buy" data-e-buy="'+idx+'" data-mid="'+mid+'"'+(buy?' checked':'')+'> Compra pronto / terceirizado</label>'
			+'<div class="etp-spcbox"><input type="number" step="1" min="0.001" class="etp-spc" data-e-spc="'+idx+'" data-mid="'+mid+'" value="'+row.spc+'" placeholder="0" style="'+(buy?'opacity:.45':'')+'"> <span class="etp-un">s/pç</span></div>'
			+'</div></div>';
	}
	function flowHtml(mid){
		var steps=etpRows(mid);
		var html='<div class="etp-flow" data-mid="'+mid+'">';
		steps.forEach(function(row,idx){
			html+=flowNode(mid,row,idx);
			if(idx<steps.length-1){html+='<div class="etp-conn"><svg width="34" height="26" viewBox="0 0 34 26"><line x1="2" y1="13" x2="27" y2="13" stroke="#4e79a7" stroke-width="2"/><polygon points="29,13 22,8 22,18" fill="#4e79a7"/></svg></div>';}
		});
		if(!steps.length){html+='<p class="muted" style="flex:1;min-width:100%">Sem etapas. Clique em "+ Adicionar etapa".</p>';}
		html+='</div>';
		html+='<p style="margin-top:8px"><button type="button" class="butAction" data-e-add="1" data-mid="'+mid+'">+ Adicionar etapa</button> '
			+'<button type="button" class="butAction" data-e-bom="1" data-mid="'+mid+'">Restaurar etapas da BOM</button></p>';
		return html;
	}
	function stepsListHtml(){
		var html='<p class="opacitymedium" style="font-size:12px"><b>Clique numa MO para abrir o fluxo.</b> Cada etapa é um cartão: escolha o <b>posto</b>, o <b>operador</b> (Auto = automático) e o tempo em <b>segundos por peça</b>. O sistema calcula o total = s/pç × quantidade e arruma as datas. <b>Arraste os cartões com ⣿</b> para reordenar as etapas; as setas mostram a ordem do fluxo.</p>';
		SEQ_MAP={};
		(PLAN.seqList||[]).forEach(function(it){SEQ_MAP[it.mid]={ref:it.ref,label:it.label,qty:it.qty};});
		(PLAN.seqList||[]).forEach(function(it){
			var steps=etpRows(it.mid);
			html+='<details class="etp-mo" data-mid="'+it.mid+'"><summary class="etp-head"><span class="etp-chev">▶</span> <b>'+esc(it.ref)+'</b> <span class="opacitymedium">— '+esc(it.label)+' · '+esc(it.qty)+' un · '+steps.length+' etapa(s)</span></summary>'
				+'<div class="etp-body">'+flowHtml(it.mid)+'</div></details>';
		});
		if(!(PLAN.seqList||[]).length){html='<p class="muted">Sem ordens de fabricação.</p>';}
		return html;
	}
	function renderSteps(){
		var openList=[];
		var dets=document.querySelectorAll('.etp-mo[open]');
		for(var i=0;i<dets.length;i++){openList.push(dets[i].getAttribute('data-mid'));}
		swap('etpList',stepsListHtml());
		var nd=document.querySelectorAll('.etp-mo');
		for(var j=0;j<nd.length;j++){
			var m=nd[j].getAttribute('data-mid');
			if(openList.indexOf(m)>-1){
				nd[j].setAttribute('open','');
				var b=nd[j].querySelector('.etp-body');
				if(b){b.innerHTML=flowHtml(m);}
			}
		}
	}
	function etpRenderBody(mid){
		var moEl=document.querySelector('.etp-mo[data-mid="'+mid+'"]');
		if(moEl){var b=moEl.querySelector('.etp-body');if(b){b.innerHTML=flowHtml(mid);}}
	}
	function etpCommit(mid){window.__pcStepsETP=collectSteps();window.PcSave(true);}
	function collectSteps(){
		var out={};
		(PLAN.seqList||[]).forEach(function(it){
			if(ETP_BOM[it.mid]){return;} // usa BOM: não salva custom
			var rows=etpRows(it.mid).filter(function(r){return r.wid&&etpSpcVal(r.spc)>0;})
				.map(function(r){return {wid:parseInt(r.wid,10),spc:etpSpcVal(r.spc),op:r.op||''};});
			if(rows.length){out[it.mid]=rows;}
		});
		return out;
	}
	function etpAction(e){
		var t=e.target;
		if(!t||!t.hasAttribute){return;}
		var moEl=t.closest('.etp-mo');if(!moEl)return;
		var mid=parseInt(moEl.getAttribute('data-mid'),10);
		if(t.hasAttribute('data-e-add')){
			delete ETP_BOM[mid];
			etpRows(mid).push({wid:'',spc:'',op:''});
			etpRenderBody(mid);e.preventDefault();e.stopPropagation();etpCommit(mid);
		}else if(t.hasAttribute('data-e-del')){
			delete ETP_BOM[mid];
			var idx=parseInt(t.getAttribute('data-e-del'),10);
			etpRows(mid).splice(idx,1);etpRenderBody(mid);etpCommit(mid);
		}else if(t.hasAttribute('data-e-up')){
			delete ETP_BOM[mid];
			var idx=parseInt(t.getAttribute('data-e-up'),10);
			if(idx>0){var rows=etpRows(mid);var tmp=rows[idx-1];rows[idx-1]=rows[idx];rows[idx]=tmp;
				etpRenderBody(mid);etpCommit(mid);}
		}else if(t.hasAttribute('data-e-dn')){
			delete ETP_BOM[mid];
			var idx=parseInt(t.getAttribute('data-e-dn'),10);
			var rows=etpRows(mid);
			if(idx<rows.length-1){var tmp=rows[idx+1];rows[idx+1]=rows[idx];rows[idx]=tmp;
				etpRenderBody(mid);etpCommit(mid);}
		}else if(t.hasAttribute('data-e-bom')){
			// fixa a MO para usar a BOM (remove as custom desta MO)
			ETP_BOM[mid]=1;
			if(ETP_EDIT){delete ETP_EDIT[mid];}
			window.__pcStepsETP=collectSteps();
			window.PcSave(true);
		}
	}
	function etpInput(e){
		var t=e.target;
		if(!t||!t.hasAttribute)return;
		var moEl=t.closest('.etp-mo');if(!moEl)return;
		var mid=parseInt(moEl.getAttribute('data-mid'),10);
		var idx=parseInt(String(t.getAttribute('data-e-wid')||t.getAttribute('data-e-spc')||t.getAttribute('data-e-op')||'-1'),10);
		if(idx<0)return;
		var rows=etpRows(mid);
		if(!rows[idx])return;
		if(t.hasAttribute('data-e-wid')){rows[idx].wid=t.value;}
		else if(t.hasAttribute('data-e-spc')){
			var spc=parseFloat(String(t.value).replace(',','.'));
			rows[idx].spc=(isFinite(spc)&&spc>0)?spc:'';
			// atualiza o total exibido deste nó
			var nodeEl=t.closest('.etp-fnode');if(nodeEl){
				var qty=etpQty(mid);
				var tot=(etpSpcVal(rows[idx].spc)*qty)/3600;
				var totEl=nodeEl.querySelector('.etp-tot');if(totEl){totEl.textContent='= '+tot.toFixed(2)+' h';}
			}
		}
		else if(t.hasAttribute('data-e-op')){rows[idx].op=t.value;}
		delete ETP_BOM[mid];
		// salva só no change (blur), não a cada tecla
		if(e.type==='change'){etpCommit(mid);}
	}
	function etpDragStart(e){
		var t=e.target&&e.target.closest?e.target.closest('.etp-fnode'):null;
		if(!t)return;
		window.__etpDrag={mid:t.getAttribute('data-mid'),from:parseInt(t.getAttribute('data-idx'),10)};
		e.dataTransfer.setData('text/plain','step');
		t.classList.add('drag');
	}
	function etpDragOver(e){if(e.target.closest('.etp-fnode')){e.preventDefault();e.dataTransfer.dropEffect='move';}}
	function etpDrop(e){
		var t=e.target&&e.target.closest?e.target.closest('.etp-fnode'):null;
		var D=window.__etpDrag;if(!t||!D)return;
		e.preventDefault();
		var to=parseInt(t.getAttribute('data-idx'),10);
		if(String(t.getAttribute('data-mid'))===D.mid&&D.from!==to){
			var rows=etpRows(D.mid);
			var el=rows.splice(D.from,1)[0];rows.splice(to,0,el);
			etpRenderBody(D.mid);etpCommit(D.mid);
		}
		window.__etpDrag=null;
	}
	function etpDragEnd(e){var t=e.target;if(t&&t.classList){t.classList.remove('drag');}window.__etpDrag=null;}
	function bindEtp(){
		if(window.__etpBound){return;}
		window.__etpBound=true;
		document.addEventListener('click',etpAction);
		document.addEventListener('change',etpInput);
		document.addEventListener('input',etpInput);
		document.addEventListener('dragstart',etpDragStart);
		document.addEventListener('dragover',etpDragOver);
		document.addEventListener('drop',etpDrop);
		document.addEventListener('dragend',etpDragEnd);
	}

	
	window.PcInit=function(){
		refreshCharts(PLAN);
		renderCharts();
		renderSeq();
		bindEtp();
		// abas
		var tabs=document.querySelectorAll('.pc-tab');
		for(var i=0;i<tabs.length;i++){(function(b){b.addEventListener('click',function(){
			for(var j=0;j<tabs.length;j++){tabs[j].classList.remove('active');}
			b.classList.add('active');
			var panels=document.querySelectorAll('.pc-panel');
			for(var k=0;k<panels.length;k++){panels[k].style.display='none';}
			document.getElementById('tab-'+b.getAttribute('data-tab')).style.display='';
		});})(tabs[i]);}
	};
	function debounce(fn,ms){var t;return function(){var a=arguments,c=this;clearTimeout(t);t=setTimeout(function(){fn.apply(c,a);},ms);};}
	window.PcSave=debounce(function(){
		var C=PLAN,equipe={},tempos={},ferias=[],ausencias={};
		// equipe
		Object.keys(C.equipe).forEach(function(name){
			var el=document.getElementById('eq_'+name.replace(/[^A-Za-z0-9_]/g,''));
			if(!el){return;}
			var postos=[];
			var cb=document.querySelectorAll('input[data-op="'+name+'"]:checked');
			for(var i=0;i<cb.length;i++){postos.push(parseInt(cb[i].value,10));}
			equipe[name]={h_dia:parseFloat((el.value||'0').replace(',','.')),postos:postos};
		});
		// tempos
		var tp=document.querySelectorAll('input[data-tp]');
		for(var i=0;i<tp.length;i++){
			if(tp[i].value!==''){tempos[tp[i].getAttribute('data-tp')]=tp[i].value;}
		}
		// ferias
		var ft=document.getElementById('ferias_txt');
		if(ft){ft.value.split(/\r?\n/).forEach(function(s){s=s.trim();if(s){ferias.push(s);}});}
		// ausencias por operador
		Object.keys(C.equipe).forEach(function(name){
			var el=document.getElementById('aus_'+name.replace(/[^A-Za-z0-9_]/g,''));
			if(!el){return;}
			var list=[];
			el.value.split(/[,;\r\n\s]+/).forEach(function(s){s=s.trim();if(/^\d{4}-\d{2}-\d{2}$/.test(s)){list.push(s);}});
			if(list.length){ausencias[name]=list;}
		});
		var data={equipe:equipe,tempos:tempos,ferias:ferias,ausencias:ausencias,
			work_sat:document.getElementById('ws_sat').checked?1:0,
			work_sun:document.getElementById('ws_sun').checked?1:0};
		var ee=document.getElementById('eficiencia_inp');
		if(ee){data.eficiencia=ee.value;}
		if(window.__pcSeq!==undefined){
			data.seq=window.__pcSeq;
		}
		if(window.__pcStepsETP){
			data.steps=window.__pcStepsETP;
		}
		var msg=document.getElementById('pc-msg');
		msg.textContent='Recalculando…';
		var b64=btoa(encodeURIComponent(JSON.stringify(data)).replace(/%([0-9A-F]{2})/g,function(m,p){return String.fromCharCode('0x'+p);}));
		var q='ajax=1&cfg='+b64;
		fetch('index.php?'+q,{method:'GET',credentials:'same-origin'})
		.then(function(r){return r.text().then(function(t){return {t:t,r:r};});})
		.then(function(o){
			var res;
			try{res=JSON.parse(o.t);}catch(e){throw new Error('Resposta inválida: '+o.t.slice(0,160));}
			PLAN=res.calc;refreshCharts(PLAN);SEQ_STATE=null;renderSeq();refresh(res.html);
			msg.textContent='Salvo e recalculado ✓ — término '+PLAN.endTs+' ('+PLAN.totSched+' h, sobra '+PLAN.leftOver+' h)';
		})
		.catch(function(e){msg.textContent='Erro: '+e;});
	},800);
	function bindInputs(){
		var sel='input[data-eqh],input[data-opequ],input[data-tp],input[data-wsat],input[data-wsun],input[data-aus],#ferias_txt';
		// eventos delegados: sobrevivem à troca de innerHTML após salvar
		document.addEventListener('input',function(e){var n=e.target;if(n&&n.matches&&n.matches(sel)){window.PcSave();}});
		document.addEventListener('change',function(e){var n=e.target;if(n&&n.matches&&n.matches(sel)){window.PcSave();}});
		document.getElementById('btn_reset_equipe').addEventListener('click',function(){
			fetch('index.php?reset=equipe',{method:'POST',credentials:'same-origin'}).then(function(){location.reload();});
		});
		document.getElementById('btn_limp_tempos').addEventListener('click',function(){
			var tp=document.querySelectorAll('input[data-tp]');
			for(var i=0;i<tp.length;i++){tp[i].value='';}
			window.PcSave();
		});
		var bseq=document.getElementById('btn_seq_auto');
		if(bseq){bseq.addEventListener('click',function(){seqAuto();});}
		var betp=document.getElementById('btn_etp_limpar');
		if(betp){betp.addEventListener('click',function(){
			// limpa todas as etapas customizadas (usa BOM em todas)
			ETP_EDIT={};window.__pcStepsETP={};window.PcSave(true);
		});}
	}
	document.addEventListener('DOMContentLoaded',function(){window.PcInit();bindInputs();});
	if(document.readyState==='interactive'||document.readyState==='complete'){setTimeout(function(){window.PcInit();bindInputs();},50);}
})();
JS;
}

// =====================================================================
function pc_html($C)
{
	// ---- cards KPIs ----
	$pct = $C['pctDone'] === 100 ? '100' : '' . $C['pctDone'];
	$fim = $C['endTs'];
	$cards = '';
	if ($C['overdue']) {
		$cards .= '<div class="pc-warn">⚠ Atraso: o cronograma termina em ' . $C['endTs'] . ', após o prazo de ' . $C['dayEnd'] . '. Ajuste equipe, tempos ou permita fim de semana.</div>';
	}
	$cards .= '<div class="pc-kpis">';
	$cards .= '<div class="kpi"><div class="v">' . $C['totSched'] . ' h</div><div class="l">Total escalonado</div></div>';
	$cards .= '<div class="kpi"><div class="v">' . ($fim !== '' ? $fim : '—') . '</div><div class="l">Término previsto (prazo ' . $C['dayEnd'] . ')</div></div>';
	$cards .= '<div class="kpi"><div class="v">' . $C['nDays'] . ' d</div><div class="l">Dias úteis no plano</div></div>';
	$cards .= '<div class="kpi"><div class="v">' . $pct . '%</div><div class="l">Escalonado</div></div>';
	$cards .= '<div class="kpi"><div class="v">' . $C['teamCapH'] . ' h</div><div class="l">Jornada da equipe (mês)</div></div>';
	$cards .= '<div class="kpi"><div class="v">' . $C['missingN'] . '</div><div class="l">Matérias faltantes (R$ ' . $C['missingCost'] . ')</div></div>';
	$cards .= '<div class="kpi"><div class="v">' . count($C['moTimes']) . '</div><div class="l">Ordens de fabricação</div></div>';
	$cards .= '</div>';

	// ---- heat matrix por máquina ----
	$days = $C['bDays'];
	$heat = '<table class="pc-tabla ht-heat"><tr><th>Máquina</th>';
	foreach ($days as $d) {
		$heat .= '<th>' . substr($d, 8) . '</th>';
	}
	$heat .= '<th>Tot</th></tr>';
	foreach ($C['macs'] as $m) {
		$wid = $m['id'];
		$heat .= '<tr><td>' . $m['label'] . '</td>';
		$totd = 0;
		$capDay = $m['capDay'];
		foreach ($days as $d) {
			$h = isset($C['macDaily'][$wid][$d]) ? $C['macDaily'][$wid][$d] : '';
			$totd += is_numeric($h) ? $h : 0;
			if ($h === '') {
				$heat .= '<td class="muted">·</td>';
			} else {
				$cl = $h > $capDay . '' ? 'ol' : ($h > 0 && $h > $capDay * 0.85 ? 'dl' : '');
				$heat .= '<td' . ($cl !== '' ? ' class="' . $cl . '"' : '') . '>' . $h . '</td>';
			}
		}
		$heat .= '<td><b>' . round($totd, 1) . '</b></td></tr>';
	}
	$heat .= '</table>';

	// ---- op cards (doughnut alt / lista) ----
	$opCards = '<table class="pc-tabla"><tr><th>Operador</th><th>Jornada</th><th>Postos</th><th>No cronograma</th><th>Dias</th><th>Uso</th></tr>';
	foreach ($C['ops'] as $o) {
		$opCards .= '<tr><td>' . $o['name'] . '</td><td>' . $o['h_dia'] . ' h/dia</td><td>' . implode(', ', $o['postos']) . '</td><td><b>' . $o['h_sched'] . ' h</b></td><td>' . $o['dias'] . '</td><td>' . $o['pct'] . '%</td></tr>';
	}
	$opCards .= '<tr><td><b>Equipe</b></td><td>' . $C['teamDay'] . ' h/dia</td><td></td><td><b>' . $C['totSched'] . ' h</b></td><td></td><td></td></tr>';
	$opCards .= '</table>';

	// ---- op table detalhe por operador (dias x horas) ----
	$opTab = '<table class="pc-tabla"><tr><th>Operador</th><th>Dia a dia (h)</th><th>Total</th></tr>';
	foreach ($C['ops'] as $o) {
		$perDay = array();
		foreach ($C['sched'] as $s) {
			if ($s['op'] === $o['name']) {
				if (!isset($perDay[$s['ts']])) {
					$perDay[$s['ts']] = 0;
				}
				$perDay[$s['ts']] += $s['h'];
			}
		}
		$cells = array_map(function ($d, $h) {
			return $d . ':' . $h;
		}, array_keys($perDay), array_values($perDay));
		$opTab .= '<tr><td>' . $o['name'] . '</td><td>' . implode(' · ', $cells) . '</td><td><b>' . $o['h_sched'] . ' h</b></td></tr>';
	}
	$opTab .= '</table>';

	// ---- gantt por operador ----
	list($gantt, $legend) = pc_gantt($C);

	// ---- tabela cronograma ----
	$schedTab = '<table class="pc-tabla"><tr><th>Dia</th><th>Operador</th><th>O que será feito</th><th>Posto</th><th>Horas</th></tr>';
	$cur = null;
	foreach ($C['sched'] as $s) {
		if ($s['ts'] !== $cur) {
			$cur = $s['ts'];
			$schedTab .= '<tr><td><b>' . $s['ts'] . '</b></td>';
		} else {
			$schedTab .= '<tr><td></td>';
		}
		$schedTab .= '<td>' . $s['op'] . '</td><td>' . htmlspecialchars($s['ref'] . ' — ' . $s['prod']) . '</td><td>' . $s['ws'] . '</td><td align="right">' . $s['h'] . '</td></tr>';
	}
	$schedTab .= '</table>';

	// ---- config equipe ----
	$cfgE = '<table class="pc-tabla"><tr><th>Operador</th><th>Horas/dia</th><th>Postos habilitados</th></tr>';
	foreach ($C['equipe'] as $name => $e) {
		$cfgE .= '<tr><td>' . htmlspecialchars($name) . '</td>';
		$cfgE .= '<td><input type="number" step="0.5" min="0" data-eqh id="eq_' . preg_replace('/[^A-Za-z0-9_]/', '', $name) . '" value="' . $e['h_dia'] . '"></td><td>';
		foreach (array(1 => 'Furadeira', 2 => 'Lixadeira', 3 => 'Autrobot', 4 => 'Decapagem E1', 5 => 'Tamboriador', 6 => 'Torno', 7 => 'Solda M1', 8 => 'Rebitadeira') as $w => $lbl) {
			$chk = in_array($w, $e['postos']) ? ' checked' : '';
			$cfgE .= '<label style="margin-right:8px"><input type="checkbox" data-opequ="' . $w . '" data-op="' . htmlspecialchars($name) . '" value="' . $w . '"' . $chk . '> ' . $lbl . '</label>';
		}
		$cfgE .= '</td></tr>';
	}
	$cfgE .= '</table><p><button class="butAction" id="btn_reset_equipe" type="button">Restaurar equipe padrão</button> <span class="opacitymedium">Fim de semana e feriados na aba Calendário.</span></p>';

	// ---- config tempos ----
	$cfgT = '<table class="pc-tabla"><tr><th>OP</th><th>Produto</th><th>Posto</th><th>Horas (vazio = BOM)</th></tr>';
	foreach ($C['moTimes'] as $mid => $mt) {
		foreach ($mt['postos'] as $wid => $h) {
			$k = $mid . ':' . $wid;
			$val = isset($C['tempos'][$k]) ? $C['tempos'][$k] : '';
			$cfgT .= '<tr><td>' . htmlspecialchars($mt['ref']) . '</td><td>' . htmlspecialchars($mt['label']) . '</td><td>' . $wid . ' — ' . pc_cfglabel($wid) . '</td>';
			$cfgT .= '<td><input type="number" step="0.1" min="0" data-tp="' . $k . '" value="' . $val . '" placeholder="' . $h . '"></td></tr>';
		}
	}
	$cfgT .= '</table><p><button class="butAction" id="btn_limp_tempos" type="button">Limpar todos os tempos (usar BOM)</button></p>';

	// ---- config calendário ----
	$ff = implode("\n", array_map('htmlspecialchars', $C['ferias']));
	$cfgCal = '<p><label><input type="checkbox" data-wsat id="ws_sat"' . ($C['work_sat'] ? ' checked' : '') . '> Trabalhar aos sábados</label>
	<label style="margin-left:14px"><input type="checkbox" data-wsun id="ws_sun"' . ($C['work_sun'] ? ' checked' : '') . '> Trabalhar aos domingos</label></p>
	<p><b>Feriados / dias sem produção</b> (aaaa-mm-dd, um por linha):</p>
	<textarea id="ferias_txt" rows="4" cols="40" data-fer="1" placeholder="2026-09-07">' . $ff . '</textarea>
	<p class="opacitymedium">As alterações recalculam o cronograma automaticamente.</p>';

	// ---- materiais vs estoque ----
	$mats = '<table class="pc-tabla"><tr><th>Matéria-prima</th><th>Produto</th><th>Consumo no mês</th><th>Estoque (Fábrica)</th><th>Faltante</th><th>Preço</th><th>Custo faltante</th></tr>';
	foreach ($C['mats'] as $mt) {
		$cl = $mt['missing'] > 0.001 ? ' class="lack"' : '';
		$mats .= '<tr><td>' . htmlspecialchars($mt['ref']) . '</td><td>' . htmlspecialchars($mt['label']) . '</td>'
			. '<td align="right">' . $mt['need'] . '</td><td align="right">' . $mt['stock'] . '</td>'
			. '<td' . $cl . ' align="right"><b>' . $mt['missing'] . '</b></td>'
			. '<td align="right">' . number_format($mt['price'], 2, ',', '.') . '</td>'
			. '<td align="right">' . number_format($mt['cost'], 2, ',', '.') . '</td></tr>';
	}
	if (empty($C['mats'])) {
		$mats .= '<tr><td colspan="7" class="muted">Sem consumo de matéria-prima (nenhuma MO com BOM/consumo registrado).</td></tr>';
	}
	$mats .= '</table>';
	$mats .= '<p class="opacitymedium">Faltante = consumo do plano − estoque atual. Registre a matéria-prima na aba Estoque do Dolibarr (armazém Fábrica) para o saldo aparecer aqui.</p>';

	// ---- ausências por operador ----
	$cfgAus = '<table class="pc-tabla"><tr><th>Operador</th><th>Dias de ausência (aaaa-mm-dd, separados por vírgula)</th></tr>';
	foreach ($C['equipe'] as $name => $e) {
		$idOp = preg_replace('/[^A-Za-z0-9_]/', '', $name);
		$v = isset($C['ausencias'][$name]) ? implode(', ', $C['ausencias'][$name]) : '';
		$cfgAus .= '<tr><td>' . htmlspecialchars($name) . '</td><td><input type="text" style="min-width:320px" data-aus id="aus_' . $idOp . '" value="' . htmlspecialchars($v) . '" placeholder="2026-09-08, 2026-09-09"></td></tr>';
	}
	$cfgAus .= '</table>';
	$cfgAus .= '<p class="opacitymedium">No dia de ausência o operador não entra no cronograma e a capacidade das suas máquinas cai automaticamente.</p>';

	// ---- config eficiência ----
	$effVal = isset($C['eff']) ? (float)$C['eff'] : 85;
	if ($effVal < 1 || $effVal > 100) {
		$effVal = 85;
	}
	$cfgEff = '<table class="pc-tabla"><tr><th>Parâmetro</th><th>Valor</th></tr>'
		. '<tr><td>Eficiência de produção (%)</td><td><input type="number" step="1" min="1" max="100" id="eficiencia_inp" data-eff value="' . $effVal . '"></td></tr></table>'
		. '<p class="opacitymedium">Reduz a capacidade diária efetiva (peças/dia). Ex.: 85% significa que cada máquina produz o equivalente a 85% do tempo teórico por dia, alongando o cronograma de forma mais realista.</p>';

	return array(
		'cards' => $cards,
		'macHeat' => $heat,
		'opCards' => $opCards,
		'opTab' => $opTab,
		'gantt' => $gantt . '<div style="font-size:11px;margin-top:6px">' . $legend . '</div>',
		'schedTab' => $schedTab,
		'mats' => $mats,
		'cfgEquipe' => $cfgE,
		'cfgAus' => $cfgAus,
		'cfgTempos' => $cfgT,
		'cfgCal' => $cfgCal,
		'cfgEff' => $cfgEff,
		'charts' => pc_charts($C),
	);
}

function pc_cfglabel($wid)
{
	$m = array(
		1 => 'Furadeira', 2 => 'Lixadeira', 3 => 'Autrobot', 4 => 'Decapagem E1',
		5 => 'Tamboriador', 6 => 'Torno', 7 => 'Solda M1', 8 => 'Rebitadeira',
	);
	return isset($m[$wid]) ? $m[$wid] : ('#' . $wid);
}

function pc_charts($C)
{
	return array(
		'macsBar' => array(
			'labels' => array_map(function ($m) { return $m['label']; }, $C['macs']),
			'h' => array_map(function ($m) { return $m['h']; }, $C['macs']),
			'cap' => array_map(function ($m) { return $m['cap']; }, $C['macs']),
		),
		'opsDonut' => array(
			'labels' => array_map(function ($o) { return $o['name']; }, $C['ops']),
			'pct' => array_map(function ($o) { return $o['pct']; }, $C['ops']),
		),
		'cum' => array(),
	);
}

function pc_colors()
{
	return array(1 => '#4e79a7', 2 => '#f28e2b', 3 => '#e15759', 4 => '#76b7b2', 5 => '#59a14f', 6 => '#edc948', 7 => '#b07aa1', 8 => '#ff9da7');
}

function pc_gantt($C)
{
	$days = $C['bDays'];
	$cellW = 58;
	$colors = pc_colors();
	if (empty($days) || empty($C['ops'])) {
		return array('<p class="muted">Sem dados.</p>', '');
	}
	$g = '<div class="pc-gantt"><div class="ghead"><div class="lbl">Operador</div><div class="cells">';
	$today = date('Y-m-d');
	foreach ($days as $i => $d) {
		$g .= '<div class="cell' . ($d === $today ? ' today' : '') . '" style="left:' . ($i * $cellW) . 'px;width:' . $cellW . 'px">' . substr($d, 8) . '</div>';
	}
	$g .= '</div></div>';
	// agrupa sched por operador+dia
	$opDayMap = array();
	foreach ($C['sched'] as $s) {
		if (!isset($opDayMap[$s['op']])) {
			$opDayMap[$s['op']] = array();
		}
		if (!isset($opDayMap[$s['op']][$s['ts']])) {
			$opDayMap[$s['op']][$s['ts']] = array('h' => 0, 'wid' => $s['wid'], 'refs' => array());
		}
		$opDayMap[$s['op']][$s['ts']]['h'] += $s['h'];
		$opDayMap[$s['op']][$s['ts']]['refs'][] = $s['ref'] . ' · ' . $s['ws'] . ' ' . $s['h'] . 'h';
	}
	$dayIdx = array_flip($days);
	foreach ($C['ops'] as $o) {
		$g .= '<div class="row"><div class="lbl">' . htmlspecialchars($o['name']) . '</div><div class="cells">';
		foreach ($days as $i => $d) {
			$g .= '<div class="cell" style="left:' . ($i * $cellW) . 'px;width:' . $cellW . 'px"></div>';
		}
		if (isset($opDayMap[$o['name']])) {
			foreach ($opDayMap[$o['name']] as $ts => $info) {
				$i = isset($dayIdx[$ts]) ? $dayIdx[$ts] : -1;
				if ($i < 0) {
					continue;
				}
				$frac = $o['h_dia'] > 0 ? $info['h'] / $o['h_dia'] : 0;
				$wdt = max(14, min($cellW - 2, round($frac * ($cellW - 2))));
				$c = isset($colors[$info['wid']]) ? $colors[$info['wid']] : '#9aa5b5';
				$tt = htmlspecialchars(implode(' | ', $info['refs']));
				$g .= '<div class="bar has" title="' . $tt . '" style="left:' . ($i * $cellW + 1) . 'px;width:' . $wdt . 'px;background:' . $c . '"></div>';
			}
		}
		$g .= '</div></div>';
	}
	$g .= '</div>';
	$legend = '';
	$labels = array(1 => 'Furadeira', 2 => 'Lixadeira', 3 => 'Autrobot', 6 => 'Torno', 7 => 'Solda M1', 8 => 'Rebitadeira');
	foreach ($labels as $w => $l) {
		$legend .= '<span style="display:inline-block;margin-right:10px"><span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:' . $colors[$w] . ';vertical-align:middle"></span> ' . $l . '</span>';
	}
	return array($g, $legend);
}