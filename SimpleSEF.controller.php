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

class SimpleSEF_Controller extends Action_Controller
{
	/**
	 * Outputs a simple 'Not Found' message and the 404 header
	 */
	public function action_index()
	{
		header('HTTP/1.0 404 Not Found');
// 		self::log('404 Not Found: ' . $_SERVER['REQUEST_URL']);
		fatal_lang_error('simplesef_404', false);
	}
}