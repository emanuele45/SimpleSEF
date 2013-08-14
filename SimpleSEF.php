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
 *   emanuele (Adapted to ElkArte)
 *
 * ***** END LICENSE BLOCK ***** */

// No Direct Access!
if (!defined('ELK'))
	die('Hacking attempt...');

class SimpleSEF
{

	/**
	 * @var Tracks the added queries used during execution
	 */
	private static $queryCount = 0;
	/**
	 * @var array Tracks benchmarking information
	 */
	private static $benchMark = array('total' => 0, 'marks' => array());
	/**
	 * @var array All actions used in the forum (normally defined in index.php
	 * 	but may come from custom action mod too)
	 */
	private static $actions = array();
	/**
	 * @var array All ignored actions used in the forum
	 */
	private static $ignoreactions = array('admin', 'openidreturn');
	/**
	 * @var array Actions that have aliases
	 */
	private static $aliasactions = array();
	/**
	 * @var array Actions that may have a 'u' or 'user' parameter in the URL
	 */
	private static $useractions = array();
	/**
	 * @var array Words to strip while encoding
	 */
	private static $stripWords = array();
	/**
	 * @var array Characters to strip while encoding
	 */
	private static $stripChars = array();
	/**
	 * @var array Stores boards found in the output after a database query
	 */
	private static $boardNames = array();
	/**
	 * @var array Stores topics found in the output after a database query
	 */
	private static $topicNames = array();
	/**
	 * @var array Stores usernames found in the output after a database query
	 */
	private static $userNames = array();
	/**
	 * @var array Tracks the available extensions
	 */
	private static $extensions = array();
	/**
	 * @var bool Properly track redirects
	 */
	private static $redirect = false;

	/**
	 * Initialize the mod and it's settings.  We can't use a constructor
	 * might change this in the future (either singleton or two classes,
	 * one to handle the integration hooks and one that does the dirty work)
	 *
	 * @global array $modSettings ELK's modSettings variable
	 * @staticvar boolean $done Says if this has been done already
	 * @param boolean $force Force the init to run again if already done
	 * @return void
	 */
	public static function init($force = false)
	{
		global $modSettings;
		static $done = false;

		if ($done && !$force)
			return;
		$done = true;

		self::$actions = !empty($modSettings['simplesef_actions']) ? explode(',', $modSettings['simplesef_actions']) : array();
		self::$ignoreactions = array_merge(self::$ignoreactions, !empty($modSettings['simplesef_ignore_actions']) ? explode(',', $modSettings['simplesef_ignore_actions']) : array());
		self::$aliasactions = !empty($modSettings['simplesef_aliases']) ? unserialize($modSettings['simplesef_aliases']) : array();
		self::$useractions = !empty($modSettings['simplesef_useractions']) ? explode(',', $modSettings['simplesef_useractions']) : array();
		self::$stripWords = !empty($modSettings['simplesef_strip_words']) ? self::explode_csv($modSettings['simplesef_strip_words']) : array();
		self::$stripChars = !empty($modSettings['simplesef_strip_chars']) ? self::explode_csv($modSettings['simplesef_strip_chars']) : array();

		// Do a bit of post processing on the arrays above
		self::$stripWords = array_filter(self::$stripWords, create_function('$value', 'return !empty($value);'));
		array_walk(self::$stripWords, 'trim');
		self::$stripChars = array_filter(self::$stripChars, create_function('$value', 'return !empty($value);'));
		array_walk(self::$stripChars, 'trim');

		self::loadBoardNames($force);
		self::loadExtensions($force);
		self::fixHooks($force);

		self::log('Pre-fix GET:' . var_export($_GET, true));

		// We need to fix our GET array too...
		parse_str(preg_replace('~&(\w+)(?=&|$)~', '&$1=', strtr($_SERVER['QUERY_STRING'], array(';?' => '&', ';' => '&', '%00' => '', "\0" => ''))), $_GET);

		self::log('Post-fix GET:' . var_export($_GET, true), 'Init Complete (forced: ' . ($force ? 'true' : 'false') . ')');
	}

	/**
	 * Implements integrate_pre_load
	 * Converts the incoming query string 'q=' into a proper querystring and get
	 * variable array.  q= comes from the .htaccess rewrite.
	 * Will have to figure out how to do some checking of other types of SEF mods
	 * and be able to rewrite those as well.  Currently we only rewrite our own urls
	 *
	 * @global string $boardurl ELK's board url
	 * @global array $modSettings
	 * @global string $scripturl
	 * @global string $language
	 * @return void
	 */
	public static function convertQueryString()
	{
		global $boardurl, $modSettings, $scripturl, $language;

		if (empty($modSettings['simplesef_enable']))
			return;

		self::init();

		$scripturl = $boardurl . '/index.php';

		// Make sure we know the URL of the current request.
		if (empty($_SERVER['REQUEST_URI']))
			$_SERVER['REQUEST_URL'] = $scripturl . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
		elseif (preg_match('~^([^/]+//[^/]+)~', $scripturl, $match) == 1)
			$_SERVER['REQUEST_URL'] = $match[1] . $_SERVER['REQUEST_URI'];
		else
			$_SERVER['REQUEST_URL'] = $_SERVER['REQUEST_URI'];

		if (!empty($modSettings['queryless_urls']))
			updateSettings(array('queryless_urls' => '0'));

		if (ELK == 'SSI')
			return;

		// if the URL contains index.php but not our ignored actions, rewrite the URL
		if (strpos($_SERVER['REQUEST_URL'], 'index.php') !== false && !(isset($_GET['xml']) || (!empty($_GET['action']) && in_array($_GET['action'], self::$ignoreactions))))
		{
			self::log('Rewriting and redirecting permanently: ' . $_SERVER['REQUEST_URL']);
			header('HTTP/1.1 301 Moved Permanently');
			header('Location: ' . self::create_sef_url($_SERVER['REQUEST_URL']));
			exit();
		}

		// Parse the url
		if (!empty($_GET['q']))
		{
			$querystring = self::route($_GET['q']);
			$_GET = $querystring + $_GET;
			$_REQUEST = $_POST + $_GET;

			// Make sure REMOTE_ADDR, other IPs, and the like are parsed
			$req = request();

			// Parse the $_REQUEST and make sure things like board, topic don't have weird stuff
			$req->parseRequest();
		}

		// Need to grab any extra query parts from the original url and tack it on here
		$_SERVER['QUERY_STRING'] = http_build_query($_GET, '', ';');

		self::log('Post-convert GET:' . var_export($_GET, true));
	}

	/**
	 * Implements integrate_buffer
	 * This is the core of the mod.  Rewrites the output buffer to create SEF
	 * urls.  It will only rewrite urls for the site at hand, not other urls
	 *
	 * @global string $scripturl
	 * @global string $boardurl
	 * @global array $txt
	 * @global array $modSettings
	 * @global array $context
	 * @param string $buffer The output buffer after ELK has output the templates
	 * @return string Returns the altered buffer (or unaltered if the mod is disabled)
	 */
	public static function ob_simplesef($buffer)
	{
		global $scripturl, $boardurl, $txt, $modSettings, $context;

		if (empty($modSettings['simplesef_enable']))
			return $buffer;

		self::benchmark('buffer');

		// Bump up our memory limit a bit
		setMemoryLimit('128M');

		// Grab the topics...
		$matches = array();
		preg_match_all('~\b' . preg_quote($scripturl) . '.*?topic=([0-9]+)~', $buffer, $matches);
		if (!empty($matches[1]))
			self::loadTopicNames(array_unique($matches[1]));

		// We need to find urls that include a user id, so we can grab them all and fetch them ahead of time
		$matches = array();
		preg_match_all('~\b' . preg_quote($scripturl) . '.*?u=([0-9]+)~', $buffer, $matches);
		if (!empty($matches[1]))
			self::loadUserNames(array_unique($matches[1]));

		// Grab all URLs and fix them
		$matches = array();
		$count = 0;
		preg_match_all('~\b(' . preg_quote($scripturl) . '[-a-zA-Z0-9+&@#/%?=\~_|!:,.;\[\]]*[-a-zA-Z0-9+&@#/%=\~_|\[\]]?)([^-a-zA-Z0-9+&@#/%=\~_|])~', $buffer, $matches);
		if (!empty($matches[0]))
		{
			$replacements = array();
			foreach (array_unique($matches[1]) as $i => $url)
			{
				$replacement = self::create_sef_url($url);
				if ($url != $replacement)
					$replacements[$matches[0][$i]] = $replacement . $matches[2][$i];
			}
			$buffer = str_replace(array_keys($replacements), array_values($replacements), $buffer);
			$count = count($replacements);
		}

		// Gotta fix up some javascript laying around in the templates
		$extra_replacements = array(
			'/$d\',' => '_%1$d/\',', // Page index for MessageIndex
			'/rand,' => '/rand=', // Verification Image
			'%1.html$d\',' => '%1$d.html\',', // Page index on MessageIndex for topics
			$boardurl . '/topic/' => $scripturl . '?topic=', // Also for above
			'%1_%1$d/\',' => '%1$d/\',', // Page index on Members listing
			'var elk_scripturl = "' . $boardurl . '/' => 'var elk_scripturl = "' . $scripturl,
		);
		$buffer = str_replace(array_keys($extra_replacements), array_values($extra_replacements), $buffer);

		// Check to see if we need to update the actions lists
		$changeArray = array();
		$possibleChanges = array('actions', 'useractions');
		foreach ($possibleChanges as $change)
			if (empty($modSettings['simplesef_' . $change]) || (substr_count($modSettings['simplesef_' . $change], ',') + 1) != count(self::$$change))
				$changeArray['simplesef_' . $change] = implode(',', self::$$change);

		if (!empty($changeArray))
		{
			updateSettings($changeArray);
			self::$queryCount++;
		}

		self::benchmark('buffer');

		if (!empty($context['show_load_time']))
			$buffer = preg_replace('~(' . preg_quote($txt['page_created']) . '.*?' . preg_quote($txt['queries']) . ')~', '$1<br />' . sprintf($txt['simplesef_adds'], $count) . ' ' . round(self::$benchMark['total'], 3) . $txt['seconds_with'] . self::$queryCount . $txt['queries'], $buffer);

		self::log('SimpleSEF rewrote ' . $count . ' urls in ' . self::$benchMark['total'] . ' seconds');

		// I think we're done
		return $buffer;
	}

	/**
	 * Implements integrate_redirect
	 * When ELK calls redirectexit, we need to rewrite the URL its redirecting to
	 * Without this, the convertQueryString would catch it, but would cause an
	 * extra page load.  This helps reduce server load and streamlines redirects
	 *
	 * @global string $scripturl
	 * @global array $modSettings
	 * @param string $setLocation The original location (passed by reference)
	 * @param boolean $refresh Unused, but declares if we are using meta refresh
	 * @return <type>
	 */
	public static function fixRedirectUrl(&$setLocation, &$refresh)
	{
		global $scripturl, $modSettings;

		if (empty($modSettings['simplesef_enable']))
			return;

		self::$redirect = true;
		self::log('Fixing redirect location: ' . $setLocation);

		// Only do this if it's an URL for this board
		if (strpos($setLocation, $scripturl) !== false)
			$setLocation = self::create_sef_url($setLocation);
	}

	/**
	 * Implements integrate_exit
	 * When ELK outputs XML data, the buffer function is never called.  To
	 * circumvent this, we use the _exit hook which is called just before ELK
	 * exits.  If ELK didn't output a footer, it typically didn't run through
	 * our output buffer.  This catches the buffer and runs it through.
	 *
	 * @global array $modSettings
	 * @param boolean $do_footer If we didn't do a footer and we're not wireless
	 * @return void
	 */
	public static function fixXMLOutput($do_footer)
	{
		global $modSettings;

		if (empty($modSettings['simplesef_enable']))
			return;

		if (!$do_footer && !self::$redirect)
		{
			$temp = ob_get_contents();

			ob_end_clean();
			ob_start(!empty($modSettings['enableCompressedOutput']) ? 'ob_gzhandler' : '');
			ob_start(array('SimpleSEF', 'ob_simplesef'));

			echo $temp;

			self::log('Rewriting XML Output');
		}
	}

	/**
	 * Implements integrate_outgoing_mail
	 * Simply adjusts the subject and message of an email with proper urls
	 *
	 * @global array $modSettings
	 * @param string $subject The subject of the email
	 * @param string $message Body of the email
	 * @param string $header Header of the email (we don't adjust this)
	 * @return boolean Always returns true to prevent ELK from erroring
	 */
	public static function fixEmailOutput(&$subject, &$message, &$header)
	{
		global $modSettings;

		if (empty($modSettings['simplesef_enable']))
			return true;

		// We're just fixing the subject and message
		$subject = self::ob_simplesef($subject);
		$message = self::ob_simplesef($message);

		self::log('Rewriting email output');

		// We must return true, otherwise we fail!
		return true;
	}

	/**
	 * Implements integrate_actions
	 * @param array $actions ELK's actions array
	 */
	public static function actionArray(&$actions)
	{
		$actions['simplesef-404'] = array('SimpleSEF.php', array('SimpleSEF', 'http404NotFound'));
	}

	/**
	 * Outputs a simple 'Not Found' message and the 404 header
	 */
	public static function http404NotFound()
	{
		header('HTTP/1.0 404 Not Found');
		self::log('404 Not Found: ' . $_SERVER['REQUEST_URL']);
		fatal_lang_error('simplesef_404', false);
	}

	/**
	 * Implements integrate_menu_buttons
	 * Adds some SimpleSEF settings to the main menu under the admin menu
	 *
	 * @global string $scripturl
	 * @global array $txt
	 * @global array $modSettings
	 * @param array $menu_buttons Array of menu buttons, post processed
	 * @return void
	 */
	public static function menuButtons(&$menu_buttons)
	{
		global $scripturl, $txt, $modSettings;

		// If there's no admin menu, don't add our button
		if (empty($txt['simplesef']) || !allowedTo('admin_forum') || isset($menu_buttons['admin']['sub_buttons']['simplesef']))
			return;

		$counter = array_search('featuresettings', array_keys($menu_buttons['admin']['sub_buttons'])) + 1;

		$menu_buttons['admin']['sub_buttons'] = array_merge(
			array_slice($menu_buttons['admin']['sub_buttons'], 0, $counter, true), array('simplesef' => array(
				'title' => $txt['simplesef'],
				'href' => $scripturl . '?action=admin;area=simplesef',
				'sub_buttons' => array(
					'basic' => array('title' => $txt['simplesef_basic'], 'href' => $scripturl . '?action=admin;area=simplesef;sa=basic'),
				),
			)), array_slice($menu_buttons['admin']['sub_buttons'], $counter, count($menu_buttons['admin']['sub_buttons']), true)
		);

		if (!empty($modSettings['simplesef_advanced']))
		{
			$menu_buttons['admin']['sub_buttons']['simplesef']['sub_buttons']['advanced'] = array('title' => $txt['simplesef_advanced'], 'href' => $scripturl . '?action=admin;area=simplesef;sa=advanced');
			$menu_buttons['admin']['sub_buttons']['simplesef']['sub_buttons']['alias'] = array('title' => $txt['simplesef_alias'], 'href' => $scripturl . '?action=admin;area=simplesef;sa=alias');
		}
	}

	/**
	 * Implements integrate_admin_areas
	 * Adds SimpleSEF options to the admin panel
	 *
	 * @global array $txt
	 * @global array $modSettings
	 * @param array $admin_areas
	 */
	public static function adminAreas(&$admin_areas)
	{
		global $txt, $modSettings;

		// We insert it after Features and Options
		$counter = array_search('featuresettings', array_keys($admin_areas['config']['areas'])) + 1;

		$admin_areas['config']['areas'] = array_merge(
			array_slice($admin_areas['config']['areas'], 0, $counter, true), array('simplesef' => array(
				'label' => $txt['simplesef'],
				'file' => 'ManageSimpleSEF.controller.php',
				'controller' => 'SimpleSEF_Controller',
				'function' => 'action_index',
				'icon' => 'search.gif',
				'subsections' => array(
					'basic' => array($txt['simplesef_basic']),
					'advanced' => array($txt['simplesef_advanced'], 'enabled' => !empty($modSettings['simplesef_advanced'])),
					'alias' => array($txt['simplesef_alias'], 'enabled' => !empty($modSettings['simplesef_advanced'])),
				),
			)), array_slice($admin_areas['config']['areas'], $counter, count($admin_areas['config']['areas']), true)
		);
	}

	/**
	 * Implements integrate_load_theme
	 * Loads up our language files
	 */
	public static function loadTheme()
	{
		loadLanguage('SimpleSEF');
	}

	/**
	 * This is a helper function of sorts that actually creates the SEF urls.
	 * It compiles the different parts of a normal URL into a SEF style url
	 *
	 * @global array $modSettings
	 * @param string $url URL to SEFize
	 * @return string Either the original url if not enabled or ignored, or a new URL
	 */
	public static function create_sef_url($url)
	{
		global $modSettings;

		if (empty($modSettings['simplesef_enable']))
			return $url;

		// Set our output strings to nothing.
		$sefstring = $sefstring2 = $sefstring3 = '';
		$query_parts = array();

		// Get the query string of the passed URL
		$url_parts = parse_url($url);
		$params = array();
		parse_str(!empty($url_parts['query']) ? preg_replace('~&(\w+)(?=&|$)~', '&$1=', strtr($url_parts['query'], array('&amp;' => '&', ';' => '&'))) : '', $params);

		if (!empty($params['action']))
		{
			// If we're ignoring this action, just return the original URL
			if (in_array($params['action'], self::$ignoreactions))
			{
				self::log('create_sef_url: Ignoring ' . $params['action']);
				return $url;
			}

			if (!in_array($params['action'], self::$actions))
				self::$actions[] = $params['action'];
			$query_parts['action'] = $params['action'];
			unset($params['action']);

			if (!empty($params['u']))
			{
				if (!in_array($query_parts['action'], self::$useractions))
					self::$useractions[] = $query_parts['action'];
				$query_parts['user'] = self::getUserName($params['u']);
				unset($params['u'], $params['user']);
			}
		}

		if (!empty($query_parts['action']) && !empty(self::$extensions[$query_parts['action']]))
		{
			require_once(SOURCEDIR . '/SimpleSEF-Ext/' . self::$extensions[$query_parts['action']]);
			$class = ucwords($query_parts['action']);
			$extension = new $class();
			$sefstring2 = $extension->create($params);
		}
		else
		{
			if (!empty($params['board']))
			{
				$query_parts['board'] = self::getBoardName($params['board']);
				unset($params['board']);
			}
			if (!empty($params['topic']))
			{
				$query_parts['topic'] = self::getTopicName($params['topic']);
				unset($params['topic']);
			}

			foreach ($params as $key => $value)
			{
				if ($value == '')
					$sefstring3 .= $key . './';
				else
				{
					$sefstring2 .= $key;
					if (is_array($value))
						$sefstring2 .= '[' . key($value) . '].' . $value[key($value)] . '/';
					else
						$sefstring2 .= '.' . $value . '/';
				}
			}
		}

		// Fix the action if it's being aliased
		if (isset($query_parts['action']) && !empty(self::$aliasactions[$query_parts['action']]))
			$query_parts['action'] = self::$aliasactions[$query_parts['action']];

		// Build the URL
		if (isset($query_parts['action']))
			$sefstring .= $query_parts['action'] . '/';
		if (isset($query_parts['user']))
			$sefstring .= $query_parts['user'] . '/';
		if (isset($sefstring2))
			$sefstring .= $sefstring2;
		if (isset($sefstring3))
			$sefstring .= $sefstring3;
		if (isset($query_parts['board']))
			$sefstring .= $query_parts['board'] . '/';
		if (isset($query_parts['topic']))
			$sefstring .= $query_parts['topic'];

		return str_replace('index.php' . (!empty($url_parts['query']) ? '?' . $url_parts['query'] : ''), $sefstring, $url); //$boardurl . '/' . $sefstring . (!empty($url_parts['fragment']) ? '#' . $url_parts['fragment'] : '');
	}

	public static function fixHooks($force = false)
	{
		global $modSettings;

		// We only do this once an hour, no need to overload things
		if (!$force && cache_get_data('simplesef_fixhooks', 3600) !== NULL)
			return;

		$db = database();

		$request = $db->query('', '
			SELECT variable, value
			FROM {db_prefix}settings
			WHERE variable LIKE {string:variable}', array(
			'variable' => 'integrate_%',
			)
		);

		$hooks = array();
		while (($row = $db->fetch_assoc($request)))
			$hooks[$row['variable']] = $row['value'];
		$db->free_result($request);
		self::$queryCount++;

		$fixups = array();
		if (!empty($hooks['integrate_pre_load']) && strpos($hooks['integrate_pre_load'], 'SimpleSEF') !== 0)
		{
			$fixups['integrate_pre_load'] = 'SimpleSEF::convertQueryString,' . str_replace(',SimpleSEF::convertQueryString', '', $hook['integrate_pre_load']);
		}
		if (!empty($hooks['integrate_buffer']) && strpos($hooks['integrate_buffer'], 'SimpleSEF') !== 0)
		{
			$fixups['integrate_buffer'] = 'SimpleSEF::ob_simplesef,' . str_replace(',SimpleSEF::ob_simplesef', '', $hook['integrate_buffer']);
		}
		if (!empty($hooks['integrate_exit']) && strpos($hooks['integrate_exit'], 'SimpleSEF') !== 0)
		{
			$fixups['integrate_exit'] = 'SimpleSEF::fixXMLOutput,' . str_replace(',SimpleSEF::fixXMLOutput', '', $hook['integrate_exit']);
		}

		if (!empty($fixups))
			updateSettings($fixups);

		// Update modSettings
		foreach ($fixups as $hook => $functions)
			$modSettings[$hook] = str_replace($hooks[$hook], $fixups[$hook], $modSettings[$hook]);

		cache_put_data('simplesef_fixhooks', true, 3600);

		self::log('Fixed up integration hooks: ' . var_export($fixups, true));
	}

	/********************************************
	 *           Utility Functions              *
	 ********************************************/

	/**
	 * Takes in a board name and tries to determine it's id
	 *
	 * @global array $modSettings
	 * @param string $boardName
	 * @return mixed Will return false if it can't find an id or the id if found
	 */
	private static function getBoardId($boardName)
	{
		global $modSettings;

		if (($boardId = array_search($boardName, self::$boardNames)) !== false)
			return $boardId . '.0';

		if (($index = strrpos($boardName, $modSettings['simplesef_space'])) === false)
			return false;

		$page = substr($boardName, $index + 1);
		if (is_numeric($page))
			$boardName = substr($boardName, 0, $index);
		else
			$page = '0';

		if (($boardId = array_search($boardName, self::$boardNames)) !== false)
			return $boardId . '.' . $page;
		else
			return false;
	}

	/**
	 * Generates a board name from the ID.  Checks the existing array and reloads
	 * it if it's not in there for some reason
	 *
	 * @global array $modSettings
	 * @param int $id Board ID
	 * @return string
	 */
	private static function getBoardName($id)
	{
		global $modSettings;

		if (!empty($modSettings['simplesef_simple']))
			$boardName = 'board' . $modSettings['simplesef_space'] . $id;
		else
		{
			if (stripos($id, '.') !== false)
			{
				$page = substr($id, stripos($id, '.') + 1);
				$id = substr($id, 0, stripos($id, '.'));
			}

			if (empty(self::$boardNames[$id]))
				self::loadBoardNames(true);
			$boardName = !empty(self::$boardNames[$id]) ? self::$boardNames[$id] : 'board';
			if (isset($page) && ($page > 0))
				$boardName = $boardName . $modSettings['simplesef_space'] . $page;
		}
		return $boardName;
	}

	/**
	 * Generates a topic name from it's id.  This is typically called from
	 * create_sef_url which is called from ob_simplesef which prepopulates topics.
	 * If the topic isn't prepopulated, it attempts to find it.
	 *
	 * @global array $modSettings
	 * @param int $id
	 * @return string Topic name with it's associated board name
	 */
	private static function getTopicName($id)
	{
		global $modSettings;

		@list($value, $start) = explode('.', $id);
		if (!isset($start))
			$start = '0';
		if (!empty($modSettings['simplesef_simple']) || !is_numeric($value))
			return 'topic' . $modSettings['simplesef_space'] . $id . (!empty($modSettings['simplesef_suffix']) ? '.' . $modSettings['simplesef_suffix'] : '/');

		// If the topic id isn't here (probably from a redirect) we need a query to get it
		if (empty(self::$topicNames[$value]))
			self::loadTopicNames((int) $value);

		// and if it still doesn't exist
		if (empty(self::$topicNames[$value]))
		{
			$topicName = 'topic';
			$boardName = 'board';
		}
		else
		{
			$topicName = 't/' . self::$topicNames[$value]['subject'];
			$boardName = self::getBoardName(self::$topicNames[$value]['board_id']);
		}

		// Put it all together
		return $boardName . '/' . $topicName . $modSettings['simplesef_space'] . $value . '.' . $start . (!empty($modSettings['simplesef_suffix']) ? '.' . $modSettings['simplesef_suffix'] : '/');
	}

	/**
	 * Generates a username from the ID.  See above comment block for
	 * pregeneration information
	 *
	 * @global array $modSettings
	 * @param int $id User ID
	 * @return string User name
	 */
	private static function getUserName($id)
	{
		global $modSettings;

		if (!empty($modSettings['simplesef_simple']) || !is_numeric($id))
			return 'user' . $modSettings['simplesef_space'] . $id;

		if (empty(self::$userNames[$id]))
			self::loadUserNames((int) $id);

		// And if it's still empty...
		if (empty(self::$userNames[$id]))
			return 'user' . $modSettings['simplesef_space'] . $id;
		else
			return self::$userNames[$id] . $modSettings['simplesef_space'] . $id;
	}

	/**
	 * Takes the q= part of the query string passed in and tries to find out
	 * how to put the URL into terms ELK can understand.  If it can't, it forces
	 * the action to SimpleSEF's own 404 action and throws a nice error page.
	 *
	 * @global string $boardurl
	 * @global array $modSettings
	 * @param string $query Querystring to deal with
	 * @return array Returns an array suitable to be merged with $_GET
	 */
	private static function route($query)
	{
		global $boardurl, $modSettings;

		$url_parts = explode('/', trim($query, '/'));
		$querystring = array();

		$current_value = reset($url_parts);
		// Do we have an action?
		if ((in_array($current_value, self::$actions) || in_array($current_value, self::$aliasactions)) && !in_array($current_value, self::$ignoreactions) )
		{
			$querystring['action'] = array_shift($url_parts);

			// We may need to fix the action
			if (($reverse_alias = array_search($current_value, self::$aliasactions)) !== false)
				$querystring['action'] = $reverse_alias;
			$current_value = reset($url_parts);

			// User
			if (!empty($current_value) && in_array($querystring['action'], self::$useractions) && ($index = strrpos($current_value, $modSettings['simplesef_space'])) !== false)
			{
				$user = substr(array_shift($url_parts), $index + 1);
				if (is_numeric($user))
					$querystring['u'] = intval($user);
				else
					$querystring['user'] = $user;
				$current_value = reset($url_parts);
			}

			if (!empty(self::$extensions[$querystring['action']]))
			{
				require_once(SOURCEDIR . '/SimpleSEF-Ext/' . self::$extensions[$querystring['action']]);
				$class = ucwords($querystring['action']);
				$extension = new $class();
				$querystring += $extension->route($url_parts);
				self::log('Rerouted "' . $querystring['action'] . '" action with extension');

				// Empty it out so it's not handled by this code
				$url_parts = array();
			}
		}

		if (!empty($url_parts))
		{
			$current_value = array_pop($url_parts);

			if ((!empty($modSettings['simplesef_simple']) && (substr($current_value, 0, 6) === 'topic_')) || strrpos($current_value, $modSettings['simplesef_suffix']) || (isset($url_parts[count($url_parts) - 1]) && $url_parts[count($url_parts) - 1] == 't'))
			{
				// remove the suffix and get the topic id
				$topic = str_replace($modSettings['simplesef_suffix'], '', $current_value);
				$topic = substr($topic, strrpos($topic, $modSettings['simplesef_space']) + 1);
				$querystring['topic'] = $topic;

				// remove the board name too
				if (empty($modSettings['simplesef_simple']))
				{
					if (isset($url_parts[count($url_parts) - 1]) && $url_parts[count($url_parts) - 1] == 't')
						array_pop($url_parts);

					array_pop($url_parts);
				}
			}
			else
			{
				//check to see if the last one in the url array is a board
				if (preg_match('~^board_([\d]+(\.[\d]+)?)$~', $current_value, $match))
					$boardId = $match[1];
				else
					$boardId = self::getBoardId($current_value);

				if ($boardId !== false)
					$querystring['board'] = $boardId;
				else
					array_push($url_parts, $current_value);
			}

			if (!empty($url_parts) && (strpos($url_parts[0], '.') === false && strpos($url_parts[0], ',') === false))
				$querystring['action'] = 'simplesef-404';

			// handle unknown variables
			$temp = array();
			foreach ($url_parts as $part)
			{
				if (strpos($part, '.') !== false)
					$part = substr_replace($part, '=', strpos($part, '.'), 1);

				// Backwards compatibility
				elseif (strpos($part, ',') !== false)
					$part = substr_replace($part, '=', strpos($part, ','), 1);
				parse_str($part, $temp);
				$querystring += $temp;
			}
		}

		self::log('Rerouted "' . $query . '" to ' . var_export($querystring, true));

		return $querystring;
	}

	/**
	 * Loads any extensions that other mod authors may have introduced
	 */
	private static function loadExtensions($force = false)
	{
		if ($force || (self::$extensions = cache_get_data('simplsef_extensions', 3600)) === NULL)
		{
			$ext_dir = SOURCEDIR . '/SimpleSEF-Ext';
			self::$extensions = array();
			if (is_readable($ext_dir))
			{
				$dh = opendir($ext_dir);
				while ($filename = readdir($dh))
				{
					// Skip these
					if (in_array($filename, array('.', '..')) || preg_match('~ssef_([a-zA-Z_-]+)\.php~', $filename, $match) == 0)
						continue;

					self::$extensions[$match[1]] = $filename;
				}
			}

			cache_put_data('simplesef_extensions', self::$extensions, 3600);
			self::log('Cache hit failed, reloading extensions');
		}
	}

	/**
	 * Loads all board names from the forum into a variable and cache (if possible)
	 * This helps reduce the number of queries needed for SimpleSEF to run
	 *
	 * @global string $language
	 * @param boolean $force Forces a reload of board names
	 */
	private static function loadBoardNames($force = false)
	{
		global $language;

		if ($force || (self::$boardNames = cache_get_data('simplesef_board_list', 3600)) == NULL)
		{
			$db = database();

			loadLanguage('index', $language, false);
			$request = $db->query('', '
				SELECT id_board, name
				FROM {db_prefix}boards', array()
			);
			$boards = array();
			while ($row = $db->fetch_assoc($request))
			{
				// A bit extra overhead to account for duplicate board names
				$temp_name = self::encode($row['name']);
				$i = 0;
				while (!empty($boards[$temp_name . (!empty($i) ? $i + 1 : '')]))
					$i++;
				$boards[$temp_name . (!empty($i) ? $i + 1 : '')] = $row['id_board'];
			}
			$db->free_result($request);

			self::$boardNames = array_flip($boards);

			// Add one to the query cound and put the data into the cache
			self::$queryCount++;
			cache_put_data('simplesef_board_list', self::$boardNames, 3600);
			self::log('Cache hit failed, reloading board names');
		}
	}

	/**
	 * Takes one or more topic id's, grabs their information from the database
	 * and stores it for later use.  Helps keep queries to a minimum.
	 *
	 * @param mixed $ids Can either be a single id or an array of ids
	 */
	private static function loadTopicNames($ids)
	{
		$ids = is_array($ids) ? $ids : array($ids);
		$db = database();

		// Fill the topic 'cache' in one fell swoop
		$request = $db->query('', '
			SELECT t.id_topic, m.subject, t.id_board
			FROM {db_prefix}topics AS t
				INNER JOIN {db_prefix}messages AS m ON (m.id_msg = t.id_first_msg)
			WHERE t.id_topic IN ({array_int:topics})', array(
			'topics' => $ids,
			)
		);
		while ($row = $db->fetch_assoc($request))
		{
			self::$topicNames[$row['id_topic']] = array(
				'subject' => self::encode($row['subject']),
				'board_id' => $row['id_board'],
			);
		}
		$db->free_result($request);
		self::$queryCount++;
	}

	/**
	 * Takes one or more user ids and stores the usernames for those users for
	 * later user
	 *
	 * @param mixed $ids can be either a single id or an array of them
	 */
	private static function loadUserNames($ids)
	{
		$ids = is_array($ids) ? $ids : array($ids);
		$db = database();

		$request = $db->query('', '
			SELECT id_member, real_name
			FROM {db_prefix}members
			WHERE id_member IN ({array_int:members})', array(
			'members' => $ids,
			)
		);
		while ($row = $db->fetch_assoc($request))
			self::$userNames[$row['id_member']] = self::encode($row['real_name']);
		$db->free_result($request);
		self::$queryCount++;
	}

	/**
	 * The encode function is responsible for transforming any string of text
	 * in the URL into something that looks good and representable.
	 *
	 * @global array $modSettings
	 * @global array $txt
	 * @staticvar array $utf8_db
	 * @param string $string String to encode
	 * @return string Returns an encoded string
	 */
	private static function encode($string)
	{
		global $modSettings, $txt;
		static $utf8_db = array();

		if (empty($string))
			return '';

		// A way to track/store the current character
		$character = 0;
		// Gotta return something...
		$result = '';

		$length = strlen($string);
		$i = 0;

		while ($i < $length)
		{
			$charInt = ord($string[$i++]);
			// We have a normal Ascii character
			if (($charInt & 0x80) == 0)
			{
				$character = $charInt;
			}
			// Two byte unicode character
			elseif (($charInt & 0xE0) == 0xC0)
			{
				$temp1 = ord($string[$i++]);
				if (($temp1 & 0xC0) != 0x80)
					$character = 63;
				else
					$character = ($charInt & 0x1F) << 6 | ($temp1 & 0x3F);
			}
			// Three byte unicode character
			elseif (($charInt & 0xF0) == 0xE0)
			{
				$temp1 = ord($string[$i++]);
				$temp2 = ord($string[$i++]);
				if (($temp1 & 0xC0) != 0x80 || ($temp2 & 0xC0) != 0x80)
					$character = 63;
				else
					$character = ($charInt & 0x0F) << 12 | ($temp1 & 0x3F) << 6 | ($temp2 & 0x3F);
			}
			// Four byte unicode character
			elseif (($charInt & 0xF8) == 0xF0)
			{
				$temp1 = ord($string[$i++]);
				$temp2 = ord($string[$i++]);
				$temp3 = ord($string[$i++]);
				if (($temp1 & 0xC0) != 0x80 || ($temp2 & 0xC0) != 0x80 || ($temp3 & 0xC0) != 0x80)
					$character = 63;
				else
					$character = ($charInt & 0x07) << 18 | ($temp1 & 0x3F) << 12 | ($temp2 & 0x3F) << 6 | ($temp3 & 0x3F);
			}
			// More than four bytes... ? mark
			else
				$character = 63;

			// Need to get the bank this character is in.
			$charBank = $character >> 8;
			if (!isset($utf8_db[$charBank]))
			{
				// Load up the bank if it's not already in memory
				$dbFile = SOURCEDIR . '/SimpleSEF-Db/x' . sprintf('%02x', $charBank) . '.php';

				if (!is_readable($dbFile) || !@include_once($dbFile))
					$utf8_db[$charBank] = array();
			}

			$finalChar = $character & 255;
			$result .= isset($utf8_db[$charBank][$finalChar]) ? $utf8_db[$charBank][$finalChar] : '?';
		}

		// Update the string with our new string
		$string = $result;

		$string = implode(' ', array_diff(explode(' ', $string), self::$stripWords));
		$string = str_replace(self::$stripChars, '', $string);
		$string = trim($string, " $modSettings[simplesef_space]\t\n\r");
		$string = urlencode($string);
		$string = str_replace('%2F', '', $string);
		$string = str_replace($modSettings['simplesef_space'], '+', $string);
		$string = preg_replace('~(\+)+~', $modSettings['simplesef_space'], $string);
		if (!empty($modSettings['simplesef_lowercase']))
			$string = strtolower($string);

		return $string;
	}

	/**
	 * Helper function to properly explode a CSV list (Accounts for quotes)
	 *
	 * @param string $str String to explode
	 * @return array Exploded string
	 */
	private static function explode_csv($str)
	{
		return!empty($str) ? preg_replace_callback('/^"(.*)"$/', create_function('$match', 'return trim($match[1]);'), preg_split('/,(?=(?:[^"]*"[^"]*")*(?![^"]*"))/', trim($str))) : array();
	}

	/**
	 * Small helper function for benchmarking SimpleSEF.  It's semi smart in the
	 * fact that you don't need to specify a 'start' or 'stop'... just pass the
	 * 'marker' twice and that starts and stops it automatically and adds to the total
	 *
	 * @param string $marker
	 */
	private static function benchmark($marker)
	{
		if (!empty(self::$benchMark['marks'][$marker]))
		{
			self::$benchMark['marks'][$marker]['stop'] = microtime(true);
			self::$benchMark['total'] += self::$benchMark['marks'][$marker]['stop'] - self::$benchMark['marks'][$marker]['start'];
		}
		else
			self::$benchMark['marks'][$marker]['start'] = microtime(true);
	}

	/**
	 * Simple function to aide in logging debug statements
	 * May pass as many simple variables as arguments as you wish
	 *
	 * @global array $modSettings
	 */
	private static function log()
	{
		global $modSettings;

		if (!empty($modSettings['simplesef_debug']))
			foreach (func_get_args() as $string)
				log_error($string, 'debug', __FILE__);
	}

}