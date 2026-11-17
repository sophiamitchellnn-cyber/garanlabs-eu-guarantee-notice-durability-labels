<?php
if (!defined('ABSPATH')) exit;

/**
 * Price history, EU Omnibus lowest price & Croatian price rules — bundled module.
 *
 * Ported from "Product Price History for WooCommerce" by Devnet (GPLv2 or later, see
 * price-history/license.txt), re-namespaced to EGGL\PriceHistory with eggl_ph_ prefixes, and
 * without Freemius, upsells or the PRO-only settings. Its admin screens live under
 * EU Guarantee & GARAN → Price History / Price Compliance Manager.
 *
 * Fully independent of the standalone plugin, so both can run side by side: own table
 * ({prefix}eggl_ph_price_history), options (eggl_ph_*), product meta (_eggl_ph_*), AJAX actions,
 * cron/Action Scheduler hooks, REST namespace (eggl-price-history/v1) and uploads folder
 * (eggl-ph-price-list).
 */
class EGGL_Price_History_Module {

    /** Version of the ported code; drives the table install/upgrade check. */
    const VERSION = '2.7.0';

    const DB_VERSION_OPTION = 'eggl_ph_db_version';

    public static function boot() {
        if (!class_exists('WooCommerce')) return;

        define('EGGL_PH_VERSION', self::VERSION);
        define('EGGL_PH_OPTIONS', [
            'general'            => get_option('eggl_ph_general'),
            'chart'              => get_option('eggl_ph_chart'),
            'lowest_price'       => get_option('eggl_ph_lowest_price'),
            'anchor_price'       => get_option('eggl_ph_anchor_price'),
            'digital_price_list' => get_option('eggl_ph_digital_price_list'),
        ]);

        self::maybe_install();

        require_once __DIR__ . '/includes/plugin.php';
        $plugin = new \EGGL\PriceHistory\Includes\PPH_Plugin();
        $plugin->run();
    }

    /**
     * Creates the price history table on the first request after the module appears — the plugin
     * is usually already active when it's updated to a version with this module, so the activation
     * hook alone would never run for those sites.
     */
    private static function maybe_install() {
        if (get_option(self::DB_VERSION_OPTION) === self::VERSION) return;

        require_once __DIR__ . '/includes/activator.php';
        \EGGL\PriceHistory\Includes\Activator::activate();
        update_option(self::DB_VERSION_OPTION, self::VERSION);
    }

    public static function deactivate() {
        require_once __DIR__ . '/includes/deactivator.php';
        \EGGL\PriceHistory\Includes\Deactivator::deactivate();
    }
}
