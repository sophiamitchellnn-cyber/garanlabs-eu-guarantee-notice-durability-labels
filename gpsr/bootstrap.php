<?php
if (!defined('ABSPATH')) exit;

/**
 * GPSR product safety information (Regulation (EU) 2023/988), CE & UKCA — bundled module.
 *
 * Ported from "Euverify- GPSR, CE & UKCA for WooCommerce" by euverify.com (GPLv3 or later, see
 * gpsr/license.txt), with eggl_gpsr_ prefixes and without the Euverify branding. Adds a
 * "Product Safety (GPSR)" tab to the WooCommerce Product data panel (manufacturer, EU/UK
 * responsible person, product documents) and a matching tab on the product page.
 *
 * Fully independent of the standalone plugin, so both can run side by side: own product meta
 * (_eggl_gpsr_*), product data tab, storefront tab, CSS classes and script handles. Data entered
 * in the standalone plugin is not imported.
 */
class EGGL_GPSR_Module {

    public static function boot() {
        if (!class_exists('WooCommerce')) return;

        require_once __DIR__ . '/includes/class-eggl-gpsr-fields.php';
        require_once __DIR__ . '/includes/class-eggl-gpsr-admin.php';
        require_once __DIR__ . '/includes/class-eggl-gpsr-frontend.php';
        require_once __DIR__ . '/includes/class-eggl-gpsr-settings.php';

        $settings = new EGGL_GPSR_Settings();
        add_action('admin_menu', [$settings, 'register_menu'], 20);
        add_action('admin_init', [$settings, 'register_settings']);
        add_action('admin_enqueue_scripts', [$settings, 'enqueue_assets']);
        add_filter('option_page_capability_' . EGGL_GPSR_Settings::OPTION_GROUP, [$settings, 'capability']);

        // Switched off under EU Guarantee & GARAN → Product Safety (GPSR): no editor or storefront tab, data kept.
        if (!EGGL_GPSR_Settings::enabled()) return;

        $admin = new EGGL_GPSR_Admin();
        add_filter('woocommerce_product_data_tabs', [$admin, 'add_tab']);
        add_action('woocommerce_product_data_panels', [$admin, 'render_panel']);
        add_action('woocommerce_process_product_meta', [$admin, 'save']);
        add_action('admin_enqueue_scripts', [$admin, 'enqueue_assets']);

        $frontend = new EGGL_GPSR_Frontend();
        add_filter('woocommerce_product_tabs', [$frontend, 'add_tab']);
        add_action('wp_enqueue_scripts', [$frontend, 'enqueue_assets']);
    }

    public static function url($path) {
        return plugins_url(ltrim($path, '/'), __FILE__);
    }

    /** Cache-busting version for a file under gpsr/ — see eggl_asset_version(). */
    public static function asset_version($path) {
        return eggl_asset_version('gpsr/' . ltrim($path, '/'));
    }
}
