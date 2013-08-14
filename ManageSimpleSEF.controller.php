<?php

/* * **** BEGIN LICENSE BLOCK *****
 * Version: MPL 1.1
 *
 * The contents of this file are subject to the Mozilla Public License Version
 * 1.1 (the "License"); you may not use this file except in compliance with
 * the License. You may obtain a copy of the License at
 * http://www.mozilla.org/MPL/
 *
 * Software distributed under the License is distributed on an "AS IS" basis,
 * WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
 * for the specific language governing rights and limitations under the
 * License.
 *
 * The Original Code is http://code.mattzuba.com code.
 *
 * The Initial Developer of the Original Code is
 * Matt Zuba.
 * Portions created by the Initial Developer are Copyright (C) 2010-2011
 * the Initial Developer. All Rights Reserved.
 *
 * Contributor(s):
 *
 * ***** END LICENSE BLOCK ***** */

// No Direct Access!
if (!defined('ELK'))
	die('Hacking attempt...');

class SimpleSEF_Controller extends Action_Controller
{
	/**
	 * Directs the admin to the proper page of settings for SimpleSEF
	 *
	 * @global array $txt
	 * @global array $context
	 */
	public function action_index()
	{
		global $txt, $context;

		// We're working with them settings here.
		require_once(SUBSDIR . '/Settings.class.php');

		$context['page_title'] = $txt['simplesef'];
		$context['sub_template'] = 'show_settings';

		$subActions = array(
			'basic' => array(
				'controller' => $this,
				'function' => 'action_BasicSettings'),
			'advanced' => array(
				'controller' => $this,
				'function' => 'action_AdvancedSettings'),
			'alias' => array(
				'controller' => $this,
				'function' => 'action_AliasSettings'),
		);

		// Default to sub action 'all'.
		$subAction = isset($_REQUEST['sa']) && isset($subActions[$_REQUEST['sa']]) ? $_REQUEST['sa'] : 'basic';

		$action = new Action();
		$action->initialize($subActions, 'basic');

		loadTemplate('SimpleSEF');

		// Load up all the tabs...
		$context[$context['admin_menu_name']]['tab_data'] = array(
			'title' => $txt['simplesef'],
			'description' => $txt['simplesef_desc'],
			'tabs' => array(
				'basic' => array(
				),
				'advanced' => array(
				),
				'alias' => array(
					'description' => $txt['simplesef_alias_desc'],
				),
			),
		);

		$action->dispatch($subAction);
	}

	/**
	 * Modifies the basic settings of SimpleSEF.
	 *
	 * @global string $scripturl
	 * @global array $txt
	 * @global array $context
	 * @global array $modSettings
	 */
	public function action_BasicSettings()
	{
		global $scripturl, $txt, $context, $modSettings;

		$this->_initSSEF_basicSettingsForm();

		$config_vars = $this->_SSEF_basicSettings->settings();

		$context['post_url'] = $scripturl . '?action=admin;area=simplesef;sa=basic;save';

		// Saving?
		if (isset($_GET['save']))
		{
			checkSession();

			$_POST['simplesef_suffix'] = trim($_POST['simplesef_suffix'], '.');

			$save_vars = $config_vars;

			// We don't want to break boards, so we'll make sure some stuff exists before actually enabling
			if (!empty($_POST['simplesef_enable']) && empty($modSettings['simplesef_enable']))
			{
				if (strpos($_SERVER['SERVER_SOFTWARE'], 'IIS') !== false && file_exists(BOARDDIR . '/web.config'))
					$_POST['simplesef_enable'] = strpos(implode('', file(BOARDDIR . '/web.config')), '<action type="Rewrite" url="index.php?q={R:1}"') !== false ? 1 : 0;
				elseif (strpos($_SERVER['SERVER_SOFTWARE'], 'IIS') === false && file_exists(BOARDDIR . '/.htaccess'))
					$_POST['simplesef_enable'] = strpos(implode('', file(BOARDDIR . '/.htaccess')), 'RewriteRule ^(.*)$ index.php') !== false ? 1 : 0;
				elseif (strpos($_SERVER['SERVER_SOFTWARE'], 'lighttpd') !== false)
					$_POST['simplesef_enable'] = 1;
				elseif (strpos($_SERVER['SERVER_SOFTWARE'], 'nginx') !== false)
					$_POST['simplesef_enable'] = 1;
				else
					$_POST['simplesef_enable'] = 0;
			}

			Settings_Form::save_db($config_vars);

			redirectexit('action=admin;area=simplesef;sa=basic');
		}

		// Prepare the settings...
		Settings_Form::prepare_db($config_vars);
	}

	/**
	 * Initialize the basic settings
	 */
	private function _initSSEF_basicSettingsForm()
	{
		global $txt;

		// instantiate the form
		$this->_SSEF_basicSettings = new Settings_Form();

		$config_vars = array(
			array('check', 'simplesef_enable', 'subtext' => $txt['simplesef_enable_desc']),
			array('check', 'simplesef_simple', 'subtext' => $txt['simplesef_simple_desc']),
			array('text', 'simplesef_space', 'size' => 6, 'subtext' => $txt['simplesef_space_desc']),
			array('text', 'simplesef_suffix', 'subtext' => $txt['simplesef_suffix_desc']),
			array('check', 'simplesef_advanced', 'subtext' => $txt['simplesef_advanced_desc']),
		);

		return $this->_SSEF_basicSettings->settings($config_vars);
	}

	/**
	 * Modifies the advanced settings for SimpleSEF.  Most setups won't need to
	 * touch this (except for maybe other languages)
	 *
	 * @global string $scripturl
	 * @global array $txt
	 * @global array $context
	 * @global array $modSettings
	 * @global array $settings
	 */
	public function action_AdvancedSettings()
	{
		global $scripturl, $txt, $context, $modSettings, $settings;

		$this->_initSSEF_advancedSettingsForm();

		$config_vars = $this->_SSEF_advancedSettings->settings();

		// Prepare the actions and ignore list
		$context['simplesef_dummy_ignore'] = !empty($modSettings['simplesef_ignore_actions']) ? explode(',', $modSettings['simplesef_ignore_actions']) : array();
		$context['simplesef_dummy_actions'] = array_diff(explode(',', $modSettings['simplesef_actions']), $context['simplesef_dummy_ignore']);
		$context['html_headers'] .= '<script type="text/javascript" src="' . $settings['default_theme_url'] . '/scripts/SelectSwapper.js?rc5"></script>';

		$context['post_url'] = $scripturl . '?action=admin;area=simplesef;sa=advanced;save';
		$context['settings_post_javascript'] = '
			function editAreas()
			{
				document.getElementById("simplesef_actions").disabled = "";
				document.getElementById("setting_simplesef_actions").nextSibling.nextSibling.style.color = "";
				document.getElementById("simplesef_useractions").disabled = "";
				document.getElementById("setting_simplesef_useractions").nextSibling.nextSibling.style.color = "";
				return false;
			}
			var swapper = new SelectSwapper({
				sFromBoxId			: "dummy_actions",
				sToBoxId			: "dummy_ignore",
				sToBoxHiddenId		: "simplesef_ignore_actions",
				sAddButtonId		: "simplesef_ignore_add",
				sAddAllButtonId		: "simplesef_ignore_add_all",
				sRemoveButtonId		: "simplesef_ignore_remove",
				sRemoveAllButtonId	: "simplesef_ignore_remove_all"
			});';

		// Saving?
		if (isset($_GET['save']))
		{
			checkSession();

			$save_vars = $config_vars;

			// Ignoring any actions??
			$save_vars[] = array('text', 'simplesef_ignore_actions');

			Settings_Form::save_db($config_vars);

			redirectexit('action=admin;area=simplesef;sa=advanced');
		}

		Settings_Form::prepare_db($config_vars);
	}

	/**
	 * Initialize the basic settings
	 */
	private function _initSSEF_advancedSettingsForm()
	{
		global $txt, $modSettings;

		// instantiate the form
		$this->_SSEF_advancedSettings = new Settings_Form();

		$config_vars = array(
			array('check', 'simplesef_lowercase', 'subtext' => $txt['simplesef_lowercase_desc']),
			array('large_text', 'simplesef_strip_words', 'size' => 6, 'subtext' => $txt['simplesef_strip_words_desc']),
			array('large_text', 'simplesef_strip_chars', 'size' => 6, 'subtext' => $txt['simplesef_strip_chars_desc']),
			array('check', 'simplesef_debug', 'subtext' => $txt['simplesef_debug_desc']),
			'',
			array('callback', 'simplesef_ignore'),
			array('title', 'title', 'label' => $txt['simplesef_action_title']),
			array('desc', 'desc', 'label' => $txt['simplesef_action_desc']),
			array('text', 'simplesef_actions', 'size' => 50, 'disabled' => 'disabled', 'preinput' => '<input type="hidden" name="simplesef_actions" value="' . $modSettings['simplesef_actions'] . '" />'),
			array('text', 'simplesef_useractions', 'size' => 50, 'disabled' => 'disabled', 'preinput' => '<input type="hidden" name="simplesef_useractions" value="' . $modSettings['simplesef_useractions'] . '" />'),
		);

		return $this->_SSEF_advancedSettings->settings($config_vars);
	}

	/**
	 * Modifies the Action Aliasing settings
	 *
	 * @global string $scripturl
	 * @global array $txt
	 * @global array $context
	 * @global array $modSettings
	 */
	public function action_AliasSettings()
	{
		global $scripturl, $txt, $context, $modSettings;

		$context['sub_template'] = 'alias_settings';

		$context['simplesef_aliases'] = !empty($modSettings['simplesef_aliases']) ? unserialize($modSettings['simplesef_aliases']) : array();

		$context['post_url'] = $scripturl . '?action=admin;area=simplesef;sa=alias';

		if (isset($_POST['save']))
		{
			checkSession();

			// Start with some fresh arrays
			$alias_original = array();
			$alias_new = array();

			// Clean up the passed in arrays
			if (isset($_POST['original'], $_POST['alias']))
			{
				// Make sure we don't allow duplicate actions or aliases
				$_POST['original'] = array_unique(array_filter($_POST['original'], create_function('$x', 'return $x != \'\';')));
				$_POST['alias'] = array_unique(array_filter($_POST['alias'], create_function('$x', 'return $x != \'\';')));
				$alias_original = array_intersect_key($_POST['original'], $_POST['alias']);
				$alias_new = array_intersect_key($_POST['alias'], $_POST['original']);
			}

			$aliases = !empty($alias_original) ? array_combine($alias_original, $alias_new) : array();

			// One last check
			foreach ($aliases as $orig => $alias)
				if ($orig == $alias)
					unset($aliases[$orig]);

			$updates = array(
				'simplesef_aliases' => serialize($aliases),
			);

			updateSettings($updates);

			redirectexit('action=admin;area=simplesef;sa=alias');
		}
	}

}