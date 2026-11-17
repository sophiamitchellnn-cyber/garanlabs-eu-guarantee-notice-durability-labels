<?php
if (!defined('ABSPATH')) exit;

/**
 * Lightweight internal i18n. English is the source/default. Storefront strings
 * follow the merchant display language (eggl_locale) so the surrounding text
 * matches the official EU graphic. Admin strings follow the WordPress admin
 * locale. Missing keys fall back to English.
 */
class EGGL_I18N {

    private static $strings = null;
    private static $languages = null;

    private static function strings() {
        if (self::$strings === null) {
            self::$strings = include EGGL_PATH . 'includes/data/eggl-strings.php';
        }
        return self::$strings;
    }

    public static function languages() {
        if (self::$languages === null) {
            self::$languages = include EGGL_PATH . 'includes/data/eggl-languages.php';
        }
        return self::$languages;
    }

    /**
     * "auto" (the default) re-detects the site's current language on every
     * call, so the notice keeps following the site language if it's changed
     * later — it's never frozen at whatever was seeded on activation.
     */
    public static function store_lang() {
        $value = (string) get_option('eggl_locale', 'auto');
        if ($value === 'auto') $value = self::detect_site_lang();
        return strtolower(substr($value, 0, 2));
    }

    /**
     * Best-guess "Display language" for the "Automatic" option: the language the
     * visitor is reading (current_locale() — the page's language on a Polylang/WPML
     * site, otherwise the site's own) if it's one of the 24 languages this plugin
     * supports, else English.
     *
     * Returns one of languages()'s own keys — "en" (bare) or a hyphenated
     * "xx-XX" like "it-IT" — since that's the actual format eggl_locale is
     * stored in (store_lang() only extracts the leading 2 letters when it reads
     * the option back for actual use) and it's what the admin dropdown's
     * <option value> attributes are, so selected() there still matches correctly.
     */
    public static function detect_site_lang() {
        $wp_locale = self::current_locale(); // e.g. "de_DE", "it_IT", "en_US" (or a bare "de" from WPML)
        $languages = self::languages();

        $hyphenated = str_replace('_', '-', $wp_locale);
        if (array_key_exists($hyphenated, $languages)) return $hyphenated;

        $lang_code = strtolower(substr($wp_locale, 0, 2));
        if ($lang_code === 'en') return 'en';

        foreach (array_keys($languages) as $key) {
            if (strtolower(substr($key, 0, 2)) === $lang_code) return $key;
        }

        return 'en';
    }

    /**
     * The language the current visitor is reading. On a Polylang or WPML site that's the
     * page's own language, asked of the multilingual plugin directly — its locale filter
     * doesn't reach every request (e.g. some AJAX/REST calls). Otherwise the site locale.
     * The admin screens keep the site locale, so "Automatic" there describes the site default.
     */
    private static function current_locale() {
        if (!is_admin() || wp_doing_ajax()) {
            if (function_exists('pll_current_language')) {
                $pll = pll_current_language('locale');
                if (is_string($pll) && $pll !== '') return $pll;
            }
            $wpml = apply_filters('wpml_current_language', null);
            if (is_string($wpml) && $wpml !== '' && $wpml !== 'all') return $wpml;
        }
        return (string) get_locale();
    }

    public static function admin_lang() {
        $l = function_exists('get_user_locale') ? get_user_locale() : get_locale();
        return strtolower(substr((string) $l, 0, 2));
    }

    /** Translate a key for the given (or storefront) language, fallback English. */
    public static function t($key, $lang = null) {
        $lang = $lang ?: self::store_lang();
        $strings = self::strings();
        if (isset($strings[$lang][$key])) return $strings[$lang][$key];
        if (isset($strings['en'][$key])) return $strings['en'][$key];
        return $key;
    }

    /** Translate a key for the admin language. */
    public static function a($key) {
        return self::t($key, self::admin_lang());
    }

    /** Your Europe legal-guarantee page for the storefront language. */
    public static function notice_learn_more_url($lang = null) {
        $lang = $lang ?: self::store_lang();
        return 'https://europa.eu/youreurope/citizens/consumers/shopping/guarantees-returns/index_' . $lang . '.htm';
    }

    /** Your Europe commercial-durability-guarantee page (language-neutral). */
    public static function label_learn_more_url() {
        return 'https://europa.eu/youreurope/commercial-guarantee-durability/index.htm';
    }
}
