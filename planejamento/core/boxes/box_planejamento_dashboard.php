<?php
/**
 * Box de atalho para o Dashboard de Planejamento (módulo custom planejamento)
 * /custom/planejamento/core/boxes/box_planejamento_dashboard.php
 */

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';

/**
 * Class to manage the box to access the planning dashboard
 */
class box_planejamento_dashboard extends ModeleBoxes
{
	public $boxcode  = "planejamento_dashboard";
	public $boximg   = "mrp";
	public $boxlabel = "Planejamento";

	/**
	 *  Constructor
	 *
	 *  @param  DoliDB  $db         Database handler
	 *  @param  string  $param      More parameters
	 */
	public function __construct($db, $param)
	{
		global $user;

		$this->db = $db;

		$this->hidden = !$user->id;

		$this->urltoaddentry = DOL_URL_ROOT.'/custom/planejamento/index.php';
		$this->msgNoRecords = '';
	}

	/**
	 *  Load data for box to show them later
	 *
	 *  @param	int		$max        Maximum number of records to load
	 *  @return	void
	 */
	public function loadBox($max = 5)
	{
		global $user, $langs, $conf;

		$this->max = $max;

		$this->info_box_head = array('text' => '📊 Planejamento de Produção');

		$url = DOL_URL_ROOT.'/custom/planejamento/index.php';

		// Texto deve começar com <a> mas precisa de asis=1 para não raspar o HTML
		$html = '<a href="'.$url.'" style="display:block;text-align:center;padding:12px 10px 12px 10px;margin:2px 0;background:#59a14f;color:#ffffff;border-radius:7px;font-size:15px;font-weight:600;text-decoration:none;letter-spacing:.2px">'
			.'📊 Abrir Dashboard de Planejamento ➜</a>'
			.'<div class="opacitymedium" style="margin-top:6px;font-size:12px">Capacidade · cronograma · operadores · máquinas · materiais</div>';

		$this->info_box_contents[0][] = array(
			'td' => 'class="center" style="padding:8px"',
			'text' => $html,
			'asis' => 1,
		);
	}

	/**
	 *	Method to show box.
	 *
	 *	@param	?array	$head        Array with properties of box title
	 *	@param	?array	$contents    Array with properties of box lines
	 *	@param	int		$nooutput    No print, only return string
	 *	@return	string
	 */
	public function showBox($head = null, $contents = null, $nooutput = 0)
	{
		return parent::showBox($this->info_box_head, $this->info_box_contents, $nooutput);
	}
}
