<?php
if (!defined('ABSPATH')) exit;

/**
 * EU order withdrawal button (Directive (EU) 2023/2673) — bundled module.
 *
 * Ported from "EU Order Withdrawal Button for WooCommerce" by vendidero (GPLv3, see
 * withdrawal/license.txt), re-namespaced to EGGL\Withdrawal with eggl_wd_ prefixes. Its admin
 * screens live under EU Guarantee & GARAN → Withdrawals (Requests / Settings tabs).
 *
 * Fully independent of the standalone plugin, so both can run side by side: own order type
 * (eggl_withdrawal), withdrawal statuses (wc-egwd-*), order statuses (wc-egwd-pending,
 * wc-egwd-withdrawn), order item type, data stores, email ids, options, shortcodes, AJAX
 * endpoints, and templates (under woocommerce/eggl-withdrawal/ for theme overrides).
 */
class EGGL_Withdrawal_Module {

    const NS = 'EGGL\\Withdrawal\\';

    public static function boot() {
        if (!class_exists('WooCommerce')) return;

        spl_autoload_register([__CLASS__, 'autoload']);

        \EGGL\Withdrawal\Package::init();
    }

    public static function autoload($class) {
        if (strpos($class, self::NS) !== 0) return;

        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen(self::NS))) . '.php';
        if (is_readable($file)) {
            require $file;
        }
    }
}
