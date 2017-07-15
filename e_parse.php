<?php
/*
 * e107 website system
 *
 * Copyright (C) 2008-2010 e107 Inc (e107.org)
 * Released under the terms and conditions of the
 * GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
 *
 *
 *
 * $Source: /cvs_backup/e107_0.8/e107_plugins/linkwords/e_tohtml.php,v $
 * $Revision$
 * $Date$
 * $Author$
 */

if ( ! defined('e107_INIT')) {
	exit;
}

//if ( basename( $_SERVER['PHP_SELF'] ) == basename(__FILE__) ) { die('Access denied'); }

// if plugin not installed or admin area - return // todo: doesn't seem to work - check if it is creating a loop
if (e_ADMIN_AREA === true || ! e107::isInstalled('nofollow')) {
	return;
}


class nofollow_parse
{


	/**
	 * Plugin Preferences
	 *
	 * @var array
	 */
	private static $Prefs = [];
	/**
	 * Operational status
	 *
	 * @var boolean
	 */
	private static $Active = false;
	/**
	 * Exclude/ignore domains
	 *
	 * @var array
	 */
	private static $excludeDomains = [];
	/**
	 * Exclude/ignore pages
	 *
	 * @var array
	 */
	private static $excludePages = [];
	/**
	 * Parsing method used
	 *
	 * @var type
	 */
	private static $parseMethod = null;


	/**
	 * constructor
	 */
	public function __construct()
	{

		// - set plugin prefs
		self::$Prefs = self::getPrefs();
		// Begin - set status
		self::$Active = self::getStatus();

		// if plugin not active - return
		// todo: seems it doesn't work either. Need to implement it all in toHtml method
		if ( ! self::$Active) {
			return;
		}

		// - set exclude pages
		self::$excludePages = self::getExcludePages();
		// - set exclude domains
		self::$excludeDomains = self::getExcludeDomains();
		// - set parse method
		self::$parseMethod = self::getParseMethod();

		// If an exclude page - return
		// todo: doesn't work this way has to implement down the line like exclude domains or make use of context
		if (self::excludePage()) {
			return;
		}
	}


	/**
	 * Retrieve and return plugin preferences
	 *
	 * @access protected
	 * @return array associative array of plugin preferences
	 */
	protected static function getPrefs()
	{
		return e107::getPlugPref('nofollow');
	}


	/**
	 * Get plugin operation status preference
	 *
	 * @return integer|boolean
	 */
	protected static function getStatus()
	{
		return self::$Prefs['active'];

	}


	/**
	 * Get exclude pages as a numeric array
	 *
	 * @return array
	 */
	protected static function getExcludePages()
	{
		return explode("\n", self::$Prefs['ignore_pages']);
	}


	/**
	 * Get exclude pages as a numeric array
	 *
	 * @return array
	 */
	protected static function getExcludeDomains()
	{
		if (isset(self::$Prefs['ignore_domains'])) {
			$domains = self::nlStringToArray(self::$Prefs['ignore_domains']);
			$domains[] = e_DOMAIN;

			return array_unique($domains);
		}

		return [e_DOMAIN];
	}


	/**
	 * Helper method - Convert newline delimited string to numeric array
	 *
	 * @param type $str_with_nl
	 *
	 * @return array
	 */
	protected static function nlStringToArray($str_with_nl)
	{
		$str = str_replace(["\r\n", "\n\r"], "|", $str_with_nl);

		return array_unique(explode("|", $str));
	}


	/**
	 * Get parse method preference
	 *
	 * @return string
	 */
	protected static function getParseMethod()
	{
		return trim(self::$Prefs['parse_method']);
	}


	/**
	 * Check if present page is a strpos of exclude page
	 *
	 * @todo preferably need a foreach loop to loop through all the listed
	 *       exclude pages
	 * @return boolean
	 */
	protected static function excludePage()
	{
		$present_page = $_SERVER['REQUEST_URI']; //e_REQUEST_URI

		foreach (self::$excludePages as $xpage) {
			if (strpos($present_page, $xpage) !== false) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Split up $text by HTML tags and inner text scan for anchor tags and
	 * apply nofollow to 'suitable' anchor tag candidates
	 * (adopted from linkwords plugin.)
	 *
	 * @param str  $text - text string that will be altered
	 * @param str  $opts ['context'] - default context
	 * @param bool $logflag - switch to log the makenofollow on post
	 *
	 * @return string Modified text
	 * @access protected
	 * @todo   fix omit based on contexts
	 */
	protected static function regexHtmlParse_Nofollow($text)
	{

		$nf_text = '';

		$pattern = '#(<.*?>)#mis';
		$fragments = preg_split($pattern, $text, -1,
			PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);

		foreach ($fragments as $fragment) {
			if (self::isOpeningAnchor($fragment) && ! self::hasExcludeDomain($fragment)) {
				$nf_text .= self::stamp_NoFollow($fragment);
			} else {
				$nf_text .= $fragment;
			}
		}

		return $nf_text;
	}


	/**
	 * Checks if text fragment is an opening anchor tag
	 *
	 * @param $fragment
	 *
	 * @return bool
	 */
	protected static function isOpeningAnchor($fragment)
	{
		if (strpos($fragment, '<a') !== false && ! strpos($fragment, '<a')) {
			return true;
		}

		return false;
	}


	/**
	 * Check if the anchor tag URL is an excluded domain
	 *
	 * @param string $anchor
	 *
	 * @return boolean
	 * @todo add foreach loop to iterate through all exclude domains when have
	 *       multiple
	 */
	protected static function hasExcludeDomain($anchor)
	{
		$excludes = self::$excludeDomains;

		$href = self::getHrefValue($anchor);

		// debug
		self::_debugLog($href);

		foreach ($excludes as $exclude) {
			if (strpos($href, $exclude) !== false) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Returns the href attribute value of an anchor tag
	 *
	 * @param string $anchor
	 *
	 * @return string href attribute value | null
	 */
	protected static function getHrefValue($anchor)
	{
		preg_match('~<a (?>[^>h]++|\Bh|h(?!ref\b))*href\s*=\s*["\']?\K[^"\'>\s]++~i',
			$anchor, $matches);

		if ($matches) {
			return $matches[0];
		}

		return null;
	}


	/**
	 * Debug logger
	 *
	 * @param string $content String content that's being passed in as argument
	 * @param string $logname Optional log file name
	 */
	private static function _debugLog($content, $logname = 'Nofollow-Debug')
	{
		$path = e_PLUGIN . 'nofollow/' . $logname . '.log';
		file_put_contents($path, $content . "\n", FILE_APPEND);
		unset($path, $content);
	}


	/**
	 * Helper method for regexHtmlParse_Nofollow Add rel="nofollow" attribute
	 * to HTML anchor elements if not present. If already have rel attr. but
	 * no 'nofollow', append 'nofollow' to its value. Insert
	 * rel="nofollow" for every other anchor elements passed to it.
	 *
	 * @param $anchor - string with opening anchor tag that is passed in
	 *
	 * @return string - The modified opening anchor tag string
	 * @access protected
	 * @todo   may be refactor the name to 'insert_Nofollow' or 'add_Nofollow'
	 */
	protected static function stamp_NoFollow($anchor)
	{
		if (strpos($anchor, 'nofollow')) {
			return $anchor;
		}

		if (strpos($anchor, 'rel')) {
			$pattern = "/rel=([\"'])([^\\1]+?)\\1/";
			//$replace = "rel=\\1\\2 nofollow\\1";
			$replace = "rel=\\1\\2 nofollow\\1 target=\"_blank\"";

			return preg_replace($pattern, $replace, $anchor);
		} else {
			$pattern = "/<a /";
			$replace = "<a rel=\"nofollow\" ";

			return preg_replace($pattern, $replace, $anchor);
		}
	}


	/**
	 * Nofollow DOM parser method using simple_html_dom.php library
	 *
	 * @param string - $text
	 *
	 * @return string - Parsed $text
	 * @access protected
	 */
	protected static function simpleHtmlDomParse_Nofollow($text)
	{
		require_once __DIR__ . '/lib/simple_html_dom.php';
		//e107::library('load', 'simple_html_dom');

		$dom = new simple_html_dom;

		$dom->load($text);

		$anchors = $dom->find('a');

		foreach ($anchors as $anchor) {

			if ((string)$anchor->rel == 'nofollow' || self::hasExcludeDomain($anchor) || self::excludePage()) {
				continue;
			}

			if ((strpos((string)$anchor->rel,
						'nofollow') === false) && strpos((string)$anchor->rel,
					'external') !== false
			) {
				$anchor->rel = 'nofollow';
				$anchor->target = '_blank';
			} else {
				$anchor->rel = 'nofollow';
			}
		}

		$text = $dom->save();

		$dom->clear();

		return $text;
	}


	/**
	 * e107 HTML parser hook
	 *
	 * @param string $text html/text to be processed.
	 * @param string $context Current context ie.  OLDDEFAULT | BODY | TITLE |
	 *                        SUMMARY | DESCRIPTION | WYSIWYG etc.
	 *
	 * @return string
	 * @access public
	 */
	public function toHtml($text, $context = '')
	{
		// todo: use context - USER_TITLE, USER_BODY is all that's really needs
		// ..checking but can also use BODY and title which is used in news. Need to understand more
		if (self::$Active) {

			$method = self::$parseMethod;

			if (method_exists($this, $method)) {

				$text = $this->$method($text);

				return $text;

			} else {

				return $text;

			}

		}

		return $text;
	}


	/**
	 * TODO: This Method
	 * check the Opening anchor tag has an href value and is valid and the
	 * domain name in the href value is not an excludeDomain
	 *
	 * @param $input
	 *
	 * @return bool
	 */
	protected function mandateNofollow($input)
	{
		$href = self::getHrefValue($input);

		if (null === $href || self::hasExcludeDomain($href)) {
			return false;
		}

		return true;
	}


	/**
	 * Boilerplate Sub-method to break-apart the above method logic for
	 * simplicity and maintainalbility and add the operational conditional
	 * checks of plugin
	 *
	 * @param string $anchor
	 *
	 * @return string
	 * @todo develop the method, do the hasExcludeDomain() and internal link
	 *       checks here
	 */
	protected function processAnchor($anchor)
	{
		// IF hasExcludeDomain() OR internalLink()
		//      RETURN $anchor
		// ELSE
		//      RETURN stamp_Nofollow( $anchor );
		return $processed;
	}


}
