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
if (!defined('SMF'))
    die('Hacking attempt...');

// Tell SMF about us
if (!empty($modSettings['simplesef_enable'])) {
    $sef_functions = array(
        'integrate_pre_load' => 'convertQueryString',
        'integrate_buffer' => 'ob_simplesef',
        'integrate_redirect' => 'fixRedirectUrl',
        'integrate_outgoing_email' => 'fixEmailOutput',
        'integrate_exit' => 'fixXMLOutput',
    );
    $modSettings = $sef_functions + $modSettings;
}

function convertQueryString() {
    global $boardurl, $modSettings, $scripturl, $simpleSEF, $language, $db_prefix, $sourcedir;

    $simpleSEF = array(
        'query_count' => 0,
        'convert_start' => microtime(),
        'actions' => !empty($modSettings['simplesef_actions']) ? explode(',', $modSettings['simplesef_actions']) : array(),
        'subactions' => !empty($modSettings['simplesef_subactions']) ? explode(',', $modSettings['simplesef_subactions']) : array(),
        'areas' => !empty($modSettings['simplesef_areas']) ? explode(',', $modSettings['simplesef_areas']) : array(),
        'useractions' => !empty($modSettings['simplesef_useractions']) ? explode(',', $modSettings['simplesef_useractions']) : array(),
        'strip_words' => !empty($modSettings['simplesef_strip_words']) ? sef_explode_csv($modSettings['simplesef_strip_words']) : array(),
        'strip_chars' => !empty($modSettings['simplesef_strip_chars']) ? sef_explode_csv($modSettings['simplesef_strip_chars']) : array(),
        'boardNameList' => array(),
        'topicNameList' => array(),
        'userNameList' => array(),
    );

    // Do a bit of post processing on the arrays above
    $simpleSEF['strip_words'] = array_filter($simpleSEF['strip_words'], create_function('$value', 'return !empty($value);'));
    array_walk($simpleSEF['strip_words'], 'trim');
    $simpleSEF['strip_chars'] = array_filter($simpleSEF['strip_chars'], create_function('$value', 'return !empty($value);'));
    array_walk($simpleSEF['strip_chars'], 'trim');

    $scripturl = $boardurl . '/index.php';

    // Make sure we know the URL of the current request.
    if (empty($_SERVER['REQUEST_URI']))
        $_SERVER['REQUEST_URL'] = $scripturl . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
    elseif (preg_match('~^([^/]+//[^/]+)~', $scripturl, $match) == 1)
        $_SERVER['REQUEST_URL'] = $match[1] . $_SERVER['REQUEST_URI'];
    else
        $_SERVER['REQUEST_URL'] = $_SERVER['REQUEST_URI'];

    // Check to see if they're accessing it from the wrong place.
    if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['SERVER_NAME'])) {
        $detected_url = isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) == 'on' ? 'https://' : 'http://';
        $detected_url .= empty($_SERVER['HTTP_HOST']) ? $_SERVER['SERVER_NAME'] . (empty($_SERVER['SERVER_PORT']) || $_SERVER['SERVER_PORT'] == '80' ? '' : ':' . $_SERVER['SERVER_PORT']) : $_SERVER['HTTP_HOST'];
        $temp = preg_replace('~/' . basename($scripturl) . '(/.+)?$~', '', strtr(dirname($_SERVER['PHP_SELF']), '\\', '/'));
        if ($temp != '/')
            $detected_url .= $temp;
    }
    if (isset($detected_url) && $detected_url != $boardurl) {
        // Try #1 - check if it's in a list of alias addresses.
        if (!empty($modSettings['forum_alias_urls'])) {
            $aliases = explode(',', $modSettings['forum_alias_urls']);

            foreach ($aliases as $alias) {
                // Rip off all the boring parts, spaces, etc.
                if ($detected_url == trim($alias) || strtr($detected_url, array('http://' => '', 'https://' => '')) == trim($alias))
                    $do_fix = true;
            }
        }

        // Hmm... check #2 - is it just different by a www?  Send them to the correct place!!
        if (empty($do_fix) && strtr($detected_url, array('://' => '://www.')) == $boardurl && (empty($_GET) || count($_GET) == 1) && SMF != 'SSI') {
            // Okay, this seems weird, but we don't want an endless loop - this will make $_GET not empty ;).
            if (empty($_GET))
                redirectexit('wwwRedirect');
            else {
                list ($k, $v) = each($_GET);

                if ($k != 'wwwRedirect')
                    redirectexit('wwwRedirect;' . $k . '=' . $v);
            }
        }

        // #3 is just a check for SSL...
        if (strtr($detected_url, array('https://' => 'http://')) == $boardurl)
            $do_fix = true;

        // Okay, #4 - perhaps it's an IP address?  We're gonna want to use that one, then. (assuming it's the IP or something...)
        if (!empty($do_fix) || preg_match('~^http[s]?://(?:[\d\.:]+|\[[\d:]+\](?::\d+)?)(?:$|/)~', $detected_url) == 1) {
            // Caching is good ;).
            $oldurl = $boardurl;

            // Fix $boardurl and $scripturl.
            $boardurl = $detected_url;
            $scripturl = strtr($scripturl, array($oldurl => $boardurl));
            $_SERVER['REQUEST_URL'] = strtr($_SERVER['REQUEST_URL'], array($oldurl => $boardurl));
        }
    }

    // Get the boards filled up
    getBoardNameList();

    if (($simpleSEF['extensions'] = cache_get_data('ssef_extensions', 3600)) == null) {
        $ext_dir = $sourcedir . '/SimpleSEF-Ext';
        $simpleSEF['extensions'] = array();
        if (is_readable($ext_dir)) {
            $dh = opendir($ext_dir);
            while ($filename = readdir($dh)) {
                // Skip these
                if (in_array($filename, array('.', '..')) || preg_match('~ssef_([a-zA-Z_-]+)\.php~', $filename, $match) == 0)
                    continue;

                $simpleSEF['extensions'][$match[1]] = $filename;
            }
        }

        cache_put_data('ssef_extensions', $simpleSEF['extensions'], 3600);
    }

    if (!empty($modSettings['queryless_urls']))
        updateSettings(array('queryless_urls' => '0'));

    if (SMF == 'SSI')
        return;

    // if the URL contains index.php but not action=admin, we want to do a redirect exit and redirect them to the proper page
    if (strpos($_SERVER['REQUEST_URL'], 'index.php') !== false) {
        if (strpos($_SERVER['REQUEST_URL'], 'action=admin') !== false || strpos($_SERVER['REQUEST_URL'], 'action=featuresettings') !== false || strpos($_SERVER['REQUEST_URL'], ';xml') !== false)
            return;

        define('WIRELESS', false);
        header('HTTP/1.1 301 Moved Permanently');

        // This seems crazy, redirecting to the same url, but it really isn't
        redirectexit($_SERVER['REQUEST_URL']);
    }

    // Parse the url
    $querystring = sef_reverse(urldecode($_SERVER['REQUEST_URL']));

    $_GET = $querystring + $_GET;

    // Need to grab any extra query parts from the original url and tack it on here
    $_SERVER['QUERY_STRING'] = http_build_query($querystring, '', ';') . (!empty($url_parts['query']) ? (!empty($querystring) ? ';' : '?') . $url_parts['query'] : '');

    if (isset($_GET['sdebug']) && function_exists('var_export'))
        log_error(var_export($_GET, true), 'debug', __FILE__, __LINE__);

    $simpleSEF['convert_end'] = microtime();
}

function ob_simplesef($buffer) {
    global $scripturl, $boardurl, $txt, $simpleSEF, $modSettings, $context, $db_prefix;

    $simpleSEF['ob_start'] = microtime();

    // Bump up our memory limit a bit
    if (@ini_get('memory_limit') < 128)
        @ini_set('memory_limit', '128M');

    // Grab the topics...
    $matches = array();
    preg_match_all('~\b(' . preg_quote($scripturl) . '\?' . (defined('SID') && SID != '' ? '(?:' . SID . '(?:;|&|&amp;))' : '') . 'topic=([0-9]+)[-a-zA-Z0-9+&@#/%?=\~_|!:,.;\[\]]*[-a-zA-Z0-9+&@#/%=\~_|\[\]])([^-a-zA-Z0-9+&@#/%=\~_|])~', $buffer, $matches);
    if (!empty($matches[2])) {
        // Fill the topic 'cache' in one fell swoop
        $request = db_query("
			SELECT t.ID_TOPIC, m.subject, t.ID_BOARD
			FROM {$db_prefix}topics AS t
				INNER JOIN {$db_prefix}messages AS m ON (m.ID_MSG = t.ID_FIRST_MSG)
			WHERE t.ID_TOPIC IN (" . implode(',', array_unique($matches[2])) . ")", __FILE__, __LINE__);
        while ($row = mysql_fetch_assoc($request)) {
            $simpleSEF['topicNameList'][$row['ID_TOPIC']] = array(
                'subject' => sef_encode($row['subject']),
                'board' => $simpleSEF['boardNameList'][$row['ID_BOARD']],
            );
        }
        mysql_free_result($request);
        $simpleSEF['query_count']++;

        $replacements = array();
        foreach (array_unique($matches[1]) as $i => $url)
            $replacements[$matches[0][$i]] = create_sef_url($url) . $matches[3][$i];
        $buffer = str_replace(array_keys($replacements), array_values($replacements), $buffer);
    }

    // We need to find urls that include a user id, so we can grab them all and fetch them ahead of time
    $matches = array();
    preg_match_all('~\b' . preg_quote($scripturl) . '.*?u=([0-9]+)~', $buffer, $matches);
    if (!empty($matches[1])) {
        $request = db_query("
			SELECT ID_MEMBER, realName
			FROM {$db_prefix}members
			WHERE ID_MEMBER IN (" . implode(',', array_unique($matches[1])) . ")", __FILE__, __LINE__);
        while ($row = mysql_fetch_assoc($request))
            $simpleSEF['userNameList'][$row['ID_MEMBER']] = sef_encode($row['realName']);
        mysql_free_result($request);
        $simpleSEF['query_count']++;
    }

    // Do the rest of the URLs (this will skip topics and admin urls)
    $matches = array();
    preg_match_all('~\b(' . preg_quote($scripturl) . '(?!\?action=(?:admin|featuresettings))[-a-zA-Z0-9+&@#/%?=\~_|!:,.;\[\]]*[-a-zA-Z0-9+&@#/%=\~_|\[\]]?)([^-a-zA-Z0-9+&@#/%=\~_|])~', $buffer, $matches);
    if (!empty($matches[0])) {
        $replacements = array();
        foreach (array_unique($matches[1]) as $i => $url)
            $replacements[$matches[0][$i]] = create_sef_url($url) . $matches[2][$i];
        $buffer = str_replace(array_keys($replacements), array_values($replacements), $buffer);
    }

    // Gotta fix up some javascript laying around in the templates
    $extra_replacements = array(
        '~/rand,~' => '/rand=',
        '~' . preg_quote($boardurl) . '/topic/~' => $scripturl . '?topic=',
        '~' . preg_quote('var smf_scripturl = "' . $boardurl) . '/~' => 'var smf_scripturl = "' . $scripturl,
        '~((?:to|bbc)Complete\.source.*?)/search/~' => '\\1/search,',
    );
    $buffer = preg_replace(array_keys($extra_replacements), array_values($extra_replacements), $buffer);

    // Check to see if we need to update the actions lists
    $changeArray = array();
    $possibleChanges = array('actions', 'useractions');
    foreach ($possibleChanges as $change)
        if (empty($modSettings['simplesef_' . $change]) || (substr_count($modSettings['simplesef_' . $change], ',') + 1) != count($simpleSEF[$change]))
            $changeArray['simplesef_' . $change] = implode(',', $simpleSEF[$change]);

    if (!empty($changeArray)) {
        updateSettings($changeArray);
        $simpleSEF['query_count']++;
    }

    $simpleSEF['ob_end'] = microtime();

    if (!empty($context['show_load_time'])) {
        $simpleSEF['execTime'] = round((array_sum(explode(' ', $simpleSEF['ob_end'])) - array_sum(explode(' ', $simpleSEF['ob_start']))) + (!empty($simpleSEF['convert_end']) ? (array_sum(explode(' ', $simpleSEF['convert_end'])) - array_sum(explode(' ', $simpleSEF['convert_start']))) : 0), 3);
        $buffer = preg_replace('~(' . preg_quote($txt['smf301']) . '.*?' . preg_quote($txt['smf302b']) . ')~', '\1<br />' . $txt['simplesef_adds'] . ' ' . $simpleSEF['execTime'] . $txt['smf302'] . $simpleSEF['query_count'] . $txt['smf302b'], $buffer);
    }

    // I think we're done
    return $buffer;
}

function fixRedirectUrl($setLocation, $refresh) {
    global $scripturl, $simpleSEF, $modSettings, $db_show_debug, $db_cache;

    $simpleSEF['redirect'] = true;

    // Only do this if it's an URL for this board and doesn't contain the admin action
    if (strpos($setLocation, $scripturl) !== false && strpos($setLocation, 'action=admin') === false)
        $setLocation = create_sef_url($setLocation);

    // We send a Refresh header only in special cases because Location looks better. (and is quicker...)
    if ($refresh && !WIRELESS)
        header('Refresh: 0; URL=' . strtr($setLocation, array(' ' => '%20')));
    else
        header('Location: ' . str_replace(' ', '%20', $setLocation));

    // Debugging.
    if (isset($db_show_debug) && $db_show_debug === true)
        $_SESSION['debug_redirect'] = $db_cache;

    obExit(false);
}

function fixXMLOutput($do_footer) {
    global $simpleSEF, $modSettings;

    if (!$do_footer && empty($simpleSEF['redirect'])) {
        $temp = ob_get_contents();

        ob_end_clean();
        ob_start(!empty($modSettings['enableCompressedOutput']) ? 'ob_gzhandler' : '');
        ob_start('ob_simplesef');

        echo $temp;
    }
}

function fixEmailOutput(&$subject, &$message, &$header) {
    // We're just fixing the subject and message
    $subject = ob_simplesef($subject);
    $message = ob_simplesef($message);

    // We must return true, otherwise we fail!
    return true;
}

function create_sef_url($url) {
    global $scripturl, $boardurl, $modSettings, $simpleSEF, $sourcedir;

    // Set our output strings to nothing.
    $sefstring = $sefstring2 = $sefstring3 = '';
    $query_parts = array();

    // Get the query string of the passed URL
    $params = array();
    $url_parts = parse_url(str_replace(array('&amp;', ';'), array('&', '&'), $url));
    parse_str(!empty($url_parts['query']) ? $url_parts['query'] : '', $params);

    if (!empty($params['action'])) {
        if (!in_array($params['action'], $simpleSEF['actions']))
            $simpleSEF['actions'][] = $params['action'];
        $query_parts['action'] = $params['action'];
        unset($params['action']);

        if (!empty($params['u'])) {
            if (!in_array($query_parts['action'], $simpleSEF['useractions']))
                $simpleSEF['useractions'][] = $query_parts['action'];
            $query_parts['user'] = getUserName($params['u']);
            unset($params['u'], $params['user']);
        }
    }

    if (!empty($query_parts['action']) && !empty($simpleSEF['extensions'][$query_parts['action']])) {
        require_once($sourcedir . '/SimpleSEF-Ext/' . $simpleSEF['extensions'][$query_parts['action']]);
        $class = ucwords($query_parts['action']);
        $extension = new $class();
        $sefstring2 = $extension->create($params);
    } else {
        if (!empty($params['board'])) {
            $query_parts['board'] = getBoardName($params['board']);
            unset($params['board']);
        }
        if (!empty($params['topic'])) {
            $query_parts['topic'] = getTopicName($params['topic']);
            unset($params['topic']);
        }

        foreach ($params as $key => $value) {
            if ($value == '')
                $sefstring3 .= $key . ',/';
            else {
                $sefstring2 .= $key;
                if (is_array($value))
                    $sefstring2 .= '[' . key($value) . '],' . $value[key($value)] . '/';
                else
                    $sefstring2 .= ',' . $value . '/';
            }
        }
    }

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

    return $boardurl . '/' . $sefstring . (!empty($url_parts['fragment']) ? '#' . $url_parts['fragment'] : '');
}

/* * ******************************************
 * 			Utility Functions				*
 * ****************************************** */

function getBoardId($boardName, $index) {
    global $modSettings, $simpleSEF;

    if (($boardId = array_search($boardName, $simpleSEF['boardNameList'])) !== false)
        return $boardId . '.0';

    if ($index === false)
        return false;

    $page = substr($boardName, $index + 1);
    if (is_numeric($page))
        $boardName = substr($boardName, 0, $index);
    else
        $page = '0';

    if (($boardId = array_search($boardName, $simpleSEF['boardNameList'])) !== false)
        return $boardId . '.' . $page;
    else
        return false;
}

function getBoardNameList($killCache = FALSE) {
    global $simpleSEF, $db_prefix, $language;

    if ($killCache || ($simpleSEF['boardNameList'] = cache_get_data('sef_board_list', 3600)) == null) {
        loadLanguage('index', $language, false);
        $request = db_query("
			SELECT ID_BOARD, name
			FROM {$db_prefix}boards", __FILE__, __LINE__);
        $simpleSEF['boardNameList'] = array();
        while ($row = mysql_fetch_assoc($request)) {
            // A bit extra overhead to account for duplicate board names
            $temp_name = sef_encode($row['name']);
            $i = 0;
            while (!empty($simpleSEF['boardNameList'][$temp_name . (!empty($i) ? $i + 1 : '')]))
                $i++;
            $simpleSEF['boardNameList'][$temp_name . (!empty($i) ? $i + 1 : '')] = $row['ID_BOARD'];
        }
        mysql_free_result($request);

        $simpleSEF['boardNameList'] = array_flip($simpleSEF['boardNameList']);

        // Add one to the query cound and put the data into the cache
        $simpleSEF['query_count']++;
        cache_put_data('sef_board_list', $simpleSEF['boardNameList'], 3600);
    }
}

function getBoardName($id) {
    global $modSettings, $simpleSEF;

    if (!empty($modSettings['simplesef_simple']))
        $boardName = 'board' . $modSettings['simplesef_space'] . $id;
    else {
        if (stripos($id, '.') !== false) {
            $page = substr($id, stripos($id, '.') + 1);
            $id = substr($id, 0, stripos($id, '.'));
        }

        if (empty($simpleSEF['boardNameList'][$id]))
            getBoardNameList(TRUE);
        $boardName = $simpleSEF['boardNameList'][$id];
        if (isset($page) && ($page > 0))
            $boardName = $boardName . $modSettings['simplesef_space'] . $page;
    }
    return $boardName;
}

function getTopicName($id) {
    global $modSettings, $simpleSEF, $db_prefix;

    @list($value, $start) = explode('.', $id);
    if (!isset($start))
        $start = '0';
    if (!empty($modSettings['simplesef_simple']) || !is_numeric($value))
        return 'topic' . $modSettings['simplesef_space'] . $id . '.' . $modSettings['simplesef_suffix'];

    // If the topic id isn't here (probably from a redirect) we need a query to get it
    if (empty($simpleSEF['topicNameList'][$value])) {
        $request = db_query("
			SELECT t.ID_TOPIC, m.subject, t.ID_BOARD
			FROM {$db_prefix}topics AS t
				INNER JOIN {$db_prefix}messages AS m ON (m.ID_MSG = t.ID_FIRST_MSG)
			WHERE t.ID_TOPIC = " . (int) $value . "
			LIMIT 1", __FILE__, __LINE__);
        while ($row = mysql_fetch_assoc($request)) {
            $simpleSEF['topicNameList'][$row['ID_TOPIC']] = array(
                'subject' => sef_encode($row['subject']),
                'board' => $simpleSEF['boardNameList'][$row['ID_BOARD']],
            );
        }
        mysql_free_result($request);
        $simpleSEF['query_count']++;
    }

    // and if it still doesn't exist
    if (empty($simpleSEF['topicNameList'][$value]))
        return 'topic' . $modSettings['simplesef_space'] . $id . '.' . $modSettings['simplesef_suffix'];

    $topicName = $simpleSEF['topicNameList'][$value]['subject'];
    $boardName = $simpleSEF['topicNameList'][$value]['board'];

    // Put it all together
    return $boardName . '/' . $topicName . $modSettings['simplesef_space'] . $value . '.' . $start . '.' . $modSettings['simplesef_suffix'];
}

function getUserName($id) {
    global $modSettings, $simpleSEF, $db_prefix;

    if (!empty($modSettings['simplesef_simple']) || !is_numeric($id))
        return 'user' . $modSettings['simplesef_space'] . $id;

    if (empty($simpleSEF['userNameList'][$id])) {
        $request = db_query("
			SELECT ID_MEMBER, realName
			FROM {$db_prefix}members
			WHERE ID_MEMBER = " . (int) $id . "
			LIMIT 1", __FILE__, __LINE__);
        while ($row = mysql_fetch_assoc($request))
            $simpleSEF['userNameList'][$row['ID_MEMBER']] = sef_encode($row['realName']);
        mysql_free_result($request);
        $simpleSEF['query_count']++;
    }

    // And if it's still empty...
    if (empty($simpleSEF['userNameList'][$id]))
        return 'user' . $modSettings['simplesef_space'] . $id;
    else
        return $simpleSEF['userNameList'][$id] . $modSettings['simplesef_space'] . $id;
}

function sef_reverse($url) {
    global $boardurl, $modSettings, $simpleSEF, $sourcedir;

    $url_parts = parse_url(str_replace($boardurl, '', $url));
    if (empty($url_parts['path']) || $url_parts['path'] == '/')
        return array();

    $url_array = explode('/', trim($url_parts['path'], '/'));
    $original_url_array = $url_array;
    $querystring = array();

    $current_value = array_pop($url_array);
    if (sef_strrpos($current_value, $modSettings['simplesef_suffix'])) {
        // remove the suffix and get the topic id
        $topic = str_replace($modSettings['simplesef_suffix'], '', $current_value);
        $topic = substr($topic, sef_strrpos($topic, $modSettings['simplesef_space']) + 1);
        $querystring['topic'] = $topic;

        // remove the board name too
        if (empty($modSettings['simplesef_simple']))
            array_pop($url_array);
    }
    else {
        //check to see if the last one in the url array is a board
        $boardName = $current_value;
        $index = sef_strrpos($boardName, $modSettings['simplesef_space']);
        if (substr($boardName, 0, $index) == 'board')
            $boardId = substr($boardName, sef_strrpos($boardName, $modSettings['simplesef_space']) + 1);
        else
            $boardId = getBoardId($boardName, $index);

        if ($boardId !== false)
            $querystring['board'] = $boardId;
        else
            array_push($url_array, $current_value);
    }

    if (!empty($url_array)) {
        // Do we have an action?
        if (in_array($url_array[0], $simpleSEF['actions'])) {
            $querystring['action'] = $url_array[0];
            array_shift($url_array);
            array_shift($original_url_array);

            // User
            if (!empty($url_array) && (in_array($querystring['action'], $simpleSEF['useractions']) !== false) && ($index = sef_strrpos($url_array[0], $modSettings['simplesef_space'])) !== false) {
                $user = substr($url_array[0], $index + 1);
                if (is_numeric($user))
                    $querystring['u'] = intval($user);
                else
                    $querystring['user'] = $user;
                array_shift($url_array);
                array_shift($original_url_array);
            }

            if (!empty($simpleSEF['extensions'][$querystring['action']])) {
                require_once($sourcedir . '/SimpleSEF-Ext/' . $simpleSEF['extensions'][$querystring['action']]);
                $class = ucwords($querystring['action']);
                $extension = new $class();
                $querystring += $extension->reverse($original_url_array);

                // Empty it out so it's not handled by this code
                $url_array = array();
            }
        } elseif (strpos($url_array[0], ',') === false) {
            header('HTTP/1.0 404 Not Found');
            die('404: Not Found');
        }

        // handle unknown variables
        $temp = array();
        foreach ($url_array as $part) {
            if (strpos($part, ',') !== false)
                $part = substr_replace($part, '=', strpos($part, ','), 1);
            parse_str($part, $temp);
            $querystring += $temp;
        }
    }

    return $querystring;
}

function sef_encode($string) {
    global $modSettings, $sourcedir, $simpleSEF, $txt;
    static $utf8_db = array();

    if (empty($string))
        return '';

    // We need to make sure all strings are either ISO-8859-1 or UTF-8 and if not, convert to UTF-8 (if the host has stuff installed right)
    $char_set = empty($modSettings['global_character_set']) ? $txt['lang_character_set'] : $modSettings['global_character_set'];
    if ($char_set != 'ISO-8859-1' || $char_set != 'UTF-8') {
        if (function_exists('iconv'))
            $string = iconv($char_set, 'UTF-8//IGNORE', $string);
        elseif (function_exists('mb_convert_encoding'))
            $string = mb_convert_encoding($string, 'UTF8', $char_set);
        elseif (function_exists('unicode_decode'))
            $string = unicode_decode($string, $char_set);
    }

    // A way to track/store the current character
    $character = 0;
    // Gotta return something...
    $result = '';

    $length = strlen($string);
    $i = 0;

    while ($i < $length) {
        $charInt = ord($string[$i++]);
        // We have a normal Ascii character
        if (($charInt & 0x80) == 0) {
            $character = $charInt;
        }
        // Two byte unicode character
        elseif (($charInt & 0xE0) == 0xC0) {
            $temp1 = ord($string[$i++]);
            if (($temp1 & 0xC0) != 0x80)
                $character = 63;
            else
                $character = ($charInt & 0x1F) << 6 | ($temp1 & 0x3F);
        }
        // Three byte unicode character
        elseif (($charInt & 0xF0) == 0xE0) {
            $temp1 = ord($string[$i++]);
            $temp2 = ord($string[$i++]);
            if (($temp1 & 0xC0) != 0x80 || ($temp2 & 0xC0) != 0x80)
                $character = 63;
            else
                $character = ($charInt & 0x0F) << 12 | ($temp1 & 0x3F) << 6 | ($temp2 & 0x3F);
        }
        // Four byte unicode character
        elseif (($charInt & 0xF8) == 0xF0) {
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
        if (!isset($utf8_db[$charBank])) {
            // Load up the bank if it's not already in memory
            $dbFile = $sourcedir . '/SimpleSEF-Db/x' . sprintf('%02x', $charBank) . '.php';

            if (!@include_once($dbFile))
                $utf8_db[$charBank] = array();
        }

        $finalChar = $character & 255;
        $result .= isset($utf8_db[$charBank][$finalChar]) ? $utf8_db[$charBank][$finalChar] : '?';
    }

    // Update the string with our new string
    $string = $result;

    $string = str_replace($simpleSEF['strip_chars'], '', $string);
    $string = implode(' ', array_diff(explode(' ', $string), $simpleSEF['strip_words']));
    $string = trim($string, " $modSettings[simplesef_space]");
    $string = urlencode($string);
    $string = str_replace('%2F', '', $string);
    $string = str_replace($modSettings['simplesef_space'], '+', $string);
    $string = preg_replace('~(\+)+~', $modSettings['simplesef_space'], $string);
    if (!empty($modSettings['simplesef_lowercase']))
        $string = strtolower($string);

    return $string;
}

function sef_explode_csv($str) {
    return preg_replace('/^"(.*)"$/', '$1', preg_split('/,(?=(?:[^"]*"[^"]*")*(?![^"]*"))/', trim($str)));
}

function sef_strrpos($haystack, $needle, $offset = 0) {
    // Why does strpos() do this? Anyway...
    $needle = !is_string($needle) ? ord(intval($needle)) : $needle;
    $haystack = !is_string($haystack) ? strval($haystack) : $haystack;

    // Setup
    $offset = intval($offset);
    $haystack_length = strlen($haystack);
    $needle_length = strlen($needle);

    // Intermezzo
    if ($needle_length == 0) {
        trigger_error(__FUNCTION__ . '(): Empty delimiter.', E_USER_WARNING);
        return false;
    }
    if ($offset < 0) {
        $haystack = substr($haystack, -$offset);
        $offset = 0;
    } elseif ($offset >= $haystack_length) {
        trigger_error(__FUNCTION__ . '(): Offset not contained in string.', E_USER_WARNING);
        return false;
    }
    // More setup
    $haystack_reverse = strrev($haystack);
    $needle_reverse = strrev($needle);

    // Search
    $pos = strpos($haystack_reverse, $needle_reverse, $offset);
    if ($pos === false)
        return false;
    else
        return $haystack_length - $needle_length - $pos;
}

if (!function_exists('http_build_query')) {

    function http_build_query($data, $prefix = null, $sep = '', $key = '') {
        $ret = array();
        foreach ((array) $data as $k => $v) {
            $k = urlencode($k);
            if (is_int($k) && $prefix != null)
                $k = $prefix . $k;
            if (!empty($key))
                $k = $key . '[' . $k . ']';

            if (is_array($v) || is_object($v))
                array_push($ret, http_build_query($v, '', $sep, $k));
            else
                array_push($ret, $k . '=' . urlencode($v));
        }

        if (empty($sep))
            $sep = ini_get("arg_separator.output");

        return implode($sep, $ret);
    }

}
?>