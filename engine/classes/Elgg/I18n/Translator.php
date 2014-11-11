<?php
namespace Elgg\I18n;

/**
 * WARNING: API IN FLUX. DO NOT USE DIRECTLY.
 *
 * @access private
 *
 * @package    Elgg.Core
 * @subpackage I18n
 * @since      1.10.0
 */
class Translator {
	
	/**
	 * Initializes new translator
	 */
	public function __construct() {
		$this->defaultPath = dirname(dirname(dirname(dirname(__DIR__)))) . "/languages/";
	}
	
	/**
	 * Given a message key, returns an appropriately translated full-text string
	 *
	 * @param string $message_key The short message code
	 * @param array  $args        An array of arguments to pass through vsprintf().
	 * @param string $language    Optionally, the standard language code
	 *                            (defaults to site/user default, then English)
	 *
	 * @return string Either the translated string, the English string,
	 * or the original language string.
	 */
	function translate($message_key, $args = array(), $language = "") {
		global $CONFIG;
	
		static $CURRENT_LANGUAGE;
	
		// old param order is deprecated
		if (!is_array($args)) {
			elgg_deprecated_notice(
				'As of Elgg 1.8, the 2nd arg to elgg_echo() is an array of string replacements and the 3rd arg is the language.',
				1.8
			);
	
			$language = $args;
			$args = array();
		}
	
		if (!isset($CONFIG->translations)) {
			// this means we probably had an exception before translations were initialized
			register_translations($this->defaultPath);
		}
	
		if (!$CURRENT_LANGUAGE) {
			$CURRENT_LANGUAGE = get_language();
		}
		if (!$language) {
			$language = $CURRENT_LANGUAGE;
		}
	
		if (isset($CONFIG->translations[$language][$message_key])) {
			$string = $CONFIG->translations[$language][$message_key];
		} else if (isset($CONFIG->translations["en"][$message_key])) {
			$string = $CONFIG->translations["en"][$message_key];
			elgg_log(sprintf('Missing %s translation for "%s" language key', $language, $message_key), 'NOTICE');
		} else {
			$string = $message_key;
			elgg_log(sprintf('Missing English translation for "%s" language key', $message_key), 'NOTICE');
		}
	
		// only pass through if we have arguments to allow backward compatibility
		// with manual sprintf() calls.
		if ($args) {
			$string = vsprintf($string, $args);
		}
	
		return $string;
	}
	
	/**
	 * Add a translation.
	 *
	 * Translations are arrays in the Zend Translation array format, eg:
	 *
	 *	$english = array('message1' => 'message1', 'message2' => 'message2');
	 *  $german = array('message1' => 'Nachricht1','message2' => 'Nachricht2');
	 *
	 * @param string $country_code   Standard country code (eg 'en', 'nl', 'es')
	 * @param array  $language_array Formatted array of strings
	 *
	 * @return bool Depending on success
	 */
	function addTranslation($country_code, $language_array) {
		global $CONFIG;
		if (!isset($CONFIG->translations)) {
			$CONFIG->translations = array();
		}
	
		$country_code = strtolower($country_code);
		$country_code = trim($country_code);
		if (is_array($language_array) && sizeof($language_array) > 0 && $country_code != "") {
			if (!isset($CONFIG->translations[$country_code])) {
				$CONFIG->translations[$country_code] = $language_array;
			} else {
				$CONFIG->translations[$country_code] = $language_array + $CONFIG->translations[$country_code];
			}
			return true;
		}
		return false;
	}
	
	/**
	 * Detect the current language being used by the current site or logged in user.
	 *
	 * @return string The language code for the site/user or "en" if not set
	 */
	function getCurrentLanguage() {
		$language = get_language();
	
		if (!$language) {
			$language = 'en';
		}
	
		return $language;
	}
	
	/**
	 * Gets the current language in use by the system or user.
	 *
	 * @return string The language code (eg "en") or false if not set
	 */
	function getLanguage() {
		global $CONFIG;
	
		$user = _elgg_services()->session->getLoggedInUser();
		$language = false;
	
		if (($user) && ($user->language)) {
			$language = $user->language;
		}
	
		if ((!$language) && (isset($CONFIG->language)) && ($CONFIG->language)) {
			$language = $CONFIG->language;
		}
	
		if ($language) {
			return $language;
		}
	
		return false;
	}
	
	/**
	 * @access private
	 */
	function loadTranslations() {
		global $CONFIG;
	
		if ($CONFIG->system_cache_enabled) {
			$loaded = true;
			$languages = array_unique(array('en', get_current_language()));
			foreach ($languages as $language) {
				$data = elgg_load_system_cache("$language.lang");
				if ($data) {
					add_translation($language, unserialize($data));
				} else {
					$loaded = false;
				}
			}
	
			if ($loaded) {
				$CONFIG->i18n_loaded_from_cache = true;
				// this is here to force 
				$CONFIG->language_paths[$this->defaultPath] = true;
				return;
			}
		}
	
		// load core translations from languages directory
		register_translations($this->defaultPath);
	}
	
	
	
	/**
	 * When given a full path, finds translation files and loads them
	 *
	 * @param string $path     Full path
	 * @param bool   $load_all If true all languages are loaded, if
	 *                         false only the current language + en are loaded
	 *
	 * @return bool success
	 */
	function registerTranslations($path, $load_all = false) {
		global $CONFIG;
	
		$path = sanitise_filepath($path);
	
		// Make a note of this path just incase we need to register this language later
		if (!isset($CONFIG->language_paths)) {
			$CONFIG->language_paths = array();
		}
		$CONFIG->language_paths[$path] = true;
	
		// Get the current language based on site defaults and user preference
		$current_language = get_current_language();
		elgg_log("Translations loaded from: $path", "INFO");
	
		// only load these files unless $load_all is true.
		$load_language_files = array(
			'en.php',
			"$current_language.php"
		);
	
		$load_language_files = array_unique($load_language_files);
	
		$handle = opendir($path);
		if (!$handle) {
			elgg_log("Could not open language path: $path", 'ERROR');
			return false;
		}
	
		$return = true;
		while (false !== ($language = readdir($handle))) {
			// ignore bad files
			if (substr($language, 0, 1) == '.' || substr($language, -4) !== '.php') {
				continue;
			}
	
			if (in_array($language, $load_language_files) || $load_all) {
				$result = include_once($path . $language);
				if (!$result) {
					$return = false;
					continue;
				} elseif (is_array($result)) {
					add_translation(basename($language, '.php'), $result);
				}
			}
		}
	
		return $return;
	}
	
	/**
	 * Reload all translations from all registered paths.
	 *
	 * This is only called by functions which need to know all possible translations.
	 *
	 * @todo Better on demand loading based on language_paths array
	 *
	 * @return void
	 */
	function reloadAllTranslations() {
		global $CONFIG;
	
		static $LANG_RELOAD_ALL_RUN;
		if ($LANG_RELOAD_ALL_RUN) {
			return;
		}
	
		if ($CONFIG->i18n_loaded_from_cache) {
			$cache = elgg_get_system_cache();
			$cache_dir = $cache->getVariable("cache_path");
			$filenames = elgg_get_file_list($cache_dir, array(), array(), array(".lang"));
			foreach ($filenames as $filename) {
				// Look for files matching for example 'en.lang', 'cmn.lang' or 'pt_br.lang'.
				// Note that this regex is just for the system cache. The original language
				// files are allowed to have uppercase letters (e.g. pt_BR.php).
				if (preg_match('/(([a-z]{2,3})(_[a-z]{2})?)\.lang$/', $filename, $matches)) {
					$language = $matches[1];
					$data = elgg_load_system_cache("$language.lang");
					if ($data) {
						add_translation($language, unserialize($data));
					}
				}
			}
		} else {
			foreach ($CONFIG->language_paths as $path => $dummy) {
				register_translations($path, true);
			}
		}
	
		$LANG_RELOAD_ALL_RUN = true;
	}
	
	/**
	 * Return an array of installed translations as an associative
	 * array "two letter code" => "native language name".
	 *
	 * @return array
	 */
	function getInstalledTranslations() {
		global $CONFIG;
	
		// Ensure that all possible translations are loaded
		reload_all_translations();
	
		$installed = array();
	
		foreach ($CONFIG->translations as $k => $v) {
			$installed[$k] = elgg_echo($k, array(), $k);
			if (_elgg_services()->session->isAdminLoggedIn()) {
				$completeness = get_language_completeness($k);
				if (($completeness < 100) && ($k != 'en')) {
					$installed[$k] .= " (" . $completeness . "% " . elgg_echo('complete') . ")";
				}
			}
		}
	
		return $installed;
	}
	
	/**
	 * Return the level of completeness for a given language code (compared to english)
	 *
	 * @param string $language Language
	 *
	 * @return int
	 */
	function getLanguageCompleteness($language) {
		global $CONFIG;
	
		// Ensure that all possible translations are loaded
		reload_all_translations();
	
		$language = sanitise_string($language);
	
		$en = count($CONFIG->translations['en']);
	
		$missing = get_missing_language_keys($language);
		if ($missing) {
			$missing = count($missing);
		} else {
			$missing = 0;
		}
	
		//$lang = count($CONFIG->translations[$language]);
		$lang = $en - $missing;
	
		return round(($lang / $en) * 100, 2);
	}
	
	/**
	 * Return the translation keys missing from a given language,
	 * or those that are identical to the english version.
	 *
	 * @param string $language The language
	 *
	 * @return mixed
	 */
	function getMissingLanguageKeys($language) {
		global $CONFIG;
	
		// Ensure that all possible translations are loaded
		reload_all_translations();
	
		$missing = array();
	
		foreach ($CONFIG->translations['en'] as $k => $v) {
			if ((!isset($CONFIG->translations[$language][$k]))
			|| ($CONFIG->translations[$language][$k] == $CONFIG->translations['en'][$k])) {
				$missing[] = $k;
			}
		}
	
		if (count($missing)) {
			return $missing;
		}
	
		return false;
	}

}