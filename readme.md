# SimpleSEF #

This mod creates content filled URLs for your forum.

Examples:
http://www.yourboard.com/index.php?board=1.0 =>> http://www.yourboard.com/general_discussion/
http://www.yourboard.com/index.php?topic=1.0 =>> http://www.yourboard.com/general_discussion/welcome_smf_1.0.html
http://www.yourboard.com/index.php?action=profile =>> http://www.yourboard.com/profile

Contribute to the initial developer Matt Zuba:
[ ![donate](https://www.paypal.com/en_US/i/btn/btn_donate_LG.gif)](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=3751784)

Latest Version: 2.0

Features of SimpleSEF:
 - Makes no core code changes to SMF [b]AT ALL[/b]
 - Works with Apache (mod_rewrite required) or IIS7 (Microsoft URL Rewrite module required + web.config files in your directory)
 - Custom action handling for other mods
 - Action ignoring - Prevent urls with certain actions from being rewritten.
 - Action aliasing - change 'register' to 'signup' for example.
 - Very low overhead to each page load - Average database query count per page load - 2 (with caching enabled, 3 without)
 - 'Simple' mode, puts just the words 'board', 'topic' or 'user' into the url, instead of content filled urls
 - Removes user specified words and characters from board and topic and usernames (thinks like ! $ &, etc, and short words, like 'the', 'at', 'and', etc.  These are customizable in the admin panel.
 - Smart - when you add mods with new actions to your board, SimpleSEF readily recognizes the new ones and accounts for them without any interaction from you
 - Specify the 'space' character in the URL (ie: general_discussion, general-discussion, general.discussion, etc)
 - UTF-8 compatible, changes non-ASCII characters to their closes US-ASCII equivilant.

Post-Install Notes:
Please ensure your .htaccess or web.config file contains the proper information for this mod to work.  Visit the admin panel and click on the [Help] link at the end of the bolded text in the page description for more information.

Change Log
-------------
v 2.0-ElkArte
+ UTF8 only
+ Added options to have urlslinks without extension

v 2.0
+ Now makes ZERO code changes to SMF 2.0
- Development of mod for SMF 1.1.x will no longer be active
+ Able to ignore actions when rewriting now
! Fix to custom handling
! Fix to pagination
+ Able to alias actions (change an action name)
+ PHP 5+ only now
+ Class based implementation

v 1.1.1
! Bug on 404

v 1.1
+ Added ability for custom extensions for actions (http://www.mattzuba.com/2010/10/custom-action-handling-with-smf-and-simplesef/)
! 404 capability for actions that don't exist (which could really be files or folders too)
! Error when newly created board isn't in cache yet

v 1.0.3
! Code cleanup
! Did away with areas/subactions (fixes a few bugs)
! Visual Verification wouldn't display on refresh
! Fix quick moderation icon issues
! Add 'User Actions' array to admin panel
! OpenID didn't play well due to periods in the variable names in URIs
! Better capture URLs in the output buffer

v 1.0.2
! Kept better track of which actions have a 'user' parameter
! Fixed an endless loop causing load spikes depending on the formatted URL
+ Added support for SMF 1.1
! Fixed issue with ?theme=xx and ?language=xx support (Thanks Feline)

v 1.0.1
! Fixed bug that would not properly transliterate non ISO-8859-1 or UTF-8 languages

v 1.0.0
+ Initial Release
