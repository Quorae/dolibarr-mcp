<?php
/* Copyright (C) 2026 Dolibarr MCP contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *  \defgroup   dolimcp     Module DoliMcp
 *  \brief      DoliMcp module descriptor.
 *
 *  \file       dolimcp/core/modules/modDoliMcp.class.php
 *  \ingroup    dolimcp
 *  \brief      Description and activation file for module DoliMcp
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *  Description and activation class for module DoliMcp
 */
class modDoliMcp extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		$this->numero = 591300;
		$this->rights_class = 'dolimcp';
		$this->family = 'interface';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'DoliMcpDescription';
		$this->descriptionlong = 'DoliMcpDescription';
		$this->editor_name = 'Dolibarr MCP contributors';
		$this->editor_url = 'https://github.com/dolibarr-mcp/dolibarr-mcp';

		// Single source of truth for the module version.
		$this->version = '0.1.0';

		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'generic';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
			'websitetemplates' => 0,
			'captcha' => 0
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@dolimcp');
		$this->hidden = false;
		$this->depends = array('modApi');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('dolimcp@dolimcp');
		$this->phpmin = array(8, 1);
		$this->need_dolibarr_version = array(24, 0);
		$this->need_javascript_ajax = 0;
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();
		$this->const = array();

		if (!isModEnabled('dolimcp')) {
			$conf->dolimcp = new stdClass();
			$conf->dolimcp->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();

		// No own permissions: tools apply the existing Dolibarr rights of the API key owner.
		$this->rights = array();
		$this->menu = array();
	}

	/**
	 *  Function called when module is enabled.
	 *  Creates the tables, then adds constants, boxes, permissions and menus into the database.
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          	1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/dolimcp/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);

		return $this->_init(array(), $options);
	}

	/**
	 *	Function called when module is disabled.
	 *	Removes constants, boxes and permissions from the database. Tables and data are kept.
	 *
	 *	@param	string		$options	Options when disabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
