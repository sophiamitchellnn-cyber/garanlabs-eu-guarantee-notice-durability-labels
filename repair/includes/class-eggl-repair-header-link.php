<?php
if (!defined('ABSPATH')) exit;

/**
 * Optional "Report a defect" link in the site's header menu (Right to Repair → Settings → Header menu),
 * built like the withdrawal module's header link: classic menus on the header location, the automatic
 * "list all pages" fallback menu, and block-theme Navigation blocks (EGGL_Frontend::resolve_menu_locations()).
 *
 * The claim page is always kept out of automatic page lists, so it never shows twice and stays hidden
 * when the setting is off; when it's on, our own item is added instead, in the store's display language.
 */
class EGGL_Repair_Header_Link {

    public function init() {
        add_filter('wp_nav_menu_items', [$this, 'nav_menu_items'], 21, 2);
        add_filter('wp_page_menu_args', [$this, 'page_menu_args'], 21);
        add_filter('wp_page_menu', [$this, 'page_menu'], 21, 2);
        add_filter('get_pages', [$this, 'hide_from_page_lists'], 10, 2);
        add_filter('render_block_core/navigation', [$this, 'navigation_block'], 21, 2);
    }

    public static function is_enabled() {
        return EGGL_Repair_Settings::enabled() && EGGL_Repair_Settings::get('header_link') === 'yes' && EGGL_Repair_Settings::page_is_published();
    }

    private static function url() {
        return EGGL_Repair_Settings::form_url();
    }

    private static function is_header_location($location) {
        return $location !== '' && in_array($location, EGGL_Frontend::resolve_menu_locations(), true);
    }

    /** Whether the menu already links to the claim page (e.g. the merchant added it by hand). */
    private static function menu_has_link($html) {
        $page_id = EGGL_Repair_Settings::page_id();
        $url     = self::url();
        return ($url !== '' && strpos($html, esc_url($url)) !== false)
            || ($page_id > 0 && (strpos($html, 'page-item-' . $page_id . '"') !== false || strpos($html, 'page-item-' . $page_id . ' ') !== false));
    }

    private static function item_html($block_theme = false) {
        $current = is_page(EGGL_Repair_Settings::page_id());
        $text    = EGGL_I18N::t('s_rr_link');
        if ($block_theme) {
            return '<li class="wp-block-navigation-item eggl-rr-header-link' . ($current ? ' current-menu-item' : '') . '"><a class="wp-block-navigation-item__content" href="' . esc_url(self::url()) . '"' . ($current ? ' aria-current="page"' : '') . '><span class="wp-block-navigation-item__label">' . esc_html($text) . '</span></a></li>';
        }
        // "menu-link": the class Astra and many other themes use to space and style their menu links.
        return '<li class="menu-item eggl-rr-header-link' . ($current ? ' current-menu-item' : '') . '"><a class="menu-link" href="' . esc_url(self::url()) . '"' . ($current ? ' aria-current="page"' : '') . '>' . esc_html($text) . '</a></li>';
    }

    /** Classic theme with a menu assigned to the header location. */
    public function nav_menu_items($items, $args) {
        if (is_admin() || !self::is_enabled() || empty($args->theme_location) || !self::is_header_location($args->theme_location)) return $items;
        return self::menu_has_link($items) ? $items : $items . self::item_html();
    }

    /** Keep the claim page out of the automatic "list all pages" menu (page_menu() adds our own item back when on). */
    public function page_menu_args($args) {
        $page_id = EGGL_Repair_Settings::page_id();
        if (is_admin() || !$page_id) return $args;
        $exclude         = array_filter(array_map('absint', explode(',', isset($args['exclude']) ? (string) $args['exclude'] : '')));
        $exclude[]       = $page_id;
        $args['exclude'] = implode(',', array_unique($exclude));
        return $args;
    }

    /** Block themes' Page List block (and other front-end page lists): leave the claim page out. */
    public function hide_from_page_lists($pages, $args = []) {
        $page_id = EGGL_Repair_Settings::page_id();
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || !$page_id || !is_array($pages)) return $pages;
        return array_values(array_filter($pages, function ($page) use ($page_id) {
            return !is_object($page) || (int) $page->ID !== $page_id;
        }));
    }

    /** Fallback page menu on the header location. */
    public function page_menu($menu, $args) {
        $location = is_array($args) && !empty($args['theme_location']) ? $args['theme_location'] : '';
        if (is_admin() || !self::is_enabled() || !self::is_header_location($location) || self::menu_has_link($menu)) return $menu;
        $pos = strripos($menu, '</ul>');
        return $pos === false ? $menu . self::item_html() : substr($menu, 0, $pos) . self::item_html() . substr($menu, $pos);
    }

    /** Block themes: append to the header Navigation block's top-level list. */
    public function navigation_block($content, $block) {
        if (is_admin() || !self::is_enabled() || self::menu_has_link($content)) return $content;
        if (!preg_match('/<ul\b[^>]*\bclass="[^"]*\bwp-block-navigation__container\b[^"]*"[^>]*>/i', $content, $match, PREG_OFFSET_CAPTURE)) return $content;

        $start = $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $pos   = $start;
        while ($depth > 0 && preg_match('#<ul\b|</ul>#i', $content, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $depth += (strtolower($m[0][0]) === '</ul>') ? -1 : 1;
            $pos    = $m[0][1] + ($depth > 0 ? strlen($m[0][0]) : 0);
        }
        if ($depth !== 0) return substr($content, 0, $start) . self::item_html(true) . substr($content, $start);
        return substr($content, 0, $pos) . self::item_html(true) . substr($content, $pos);
    }
}
