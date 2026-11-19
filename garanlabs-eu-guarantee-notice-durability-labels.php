<?php
/**
 * Plugin Name: EU Consumer Rights for WooCommerce – Guarantee Notice, GARAN Label, Withdrawal Button, Right to Repair, Omnibus Price & GPSR
 * Description: Add the official EU legal-guarantee notice (Regulation (EU) 2025/1960) and the GARAN durability label to WooCommerce — product pages, checkout, and order emails, in 24 EU languages — plus an EU order withdrawal button (Directive (EU) 2023/2673) and price history with the EU Omnibus 30-day lowest price, GPSR product safety information (Regulation (EU) 2023/988), and right-to-repair defect claims (Directive (EU) 2024/1799). Also works without WooCommerce via shortcodes and blocks.
 * Version: 2.2.5
 * Author: GARAN Labs
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: garanlabs-eu-guarantee-notice-durability-labels
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

// A second copy of this plugin under another folder name (e.g. a GitHub ZIP unpacked as "...-main")
// would redeclare everything and cause a fatal error; stay inactive and say so instead.
if (defined('EGGL_VERSION')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>' . esc_html__('Two copies of "EU Consumer Rights for WooCommerce" are installed in different plugin folders. Only the first one is running. Deactivate the extra copy and remove its folder (e.g. over FTP/SSH) rather than deleting it in Plugins, so the shared settings are not touched.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p></div>';
    });
    return;
}

define('EGGL_VERSION', '2.2.5');
define('EGGL_FILE', __FILE__);
define('EGGL_PATH', plugin_dir_path(__FILE__));
define('EGGL_URL', plugin_dir_url(__FILE__));

/**
 * Cache-busting version for an enqueued asset: the file's own mtime, so
 * editing assets/css/*.css or assets/js/*.js during development always
 * invalidates any previously cached copy, without needing to remember to
 * bump EGGL_VERSION by hand. Falls back to EGGL_VERSION if the file is
 * missing for some reason.
 */
function eggl_asset_version($relative_path) {
    $file = EGGL_PATH . ltrim($relative_path, '/');
    return file_exists($file) ? (string) filemtime($file) : EGGL_VERSION;
}

require_once EGGL_PATH . 'includes/class-eggl-loader.php';
require_once EGGL_PATH . 'includes/class-eggl-i18n.php';
require_once EGGL_PATH . 'includes/class-eggl-garan-fit.php';
require_once EGGL_PATH . 'includes/class-eggl-activator.php';
require_once EGGL_PATH . 'includes/class-eggl-deactivation-feedback.php';
require_once EGGL_PATH . 'includes/class-eggl-core.php';
require_once EGGL_PATH . 'admin/class-eggl-settings.php';
require_once EGGL_PATH . 'admin/class-eggl-product-fields.php';
require_once EGGL_PATH . 'admin/class-eggl-vendor-uploads.php';
require_once EGGL_PATH . 'admin/class-eggl-health.php';
require_once EGGL_PATH . 'admin/class-eggl-admin-notices.php';
require_once EGGL_PATH . 'admin/class-eggl-garan-raster.php';
require_once EGGL_PATH . 'public/class-eggl-frontend.php';
require_once EGGL_PATH . 'withdrawal/bootstrap.php';
require_once EGGL_PATH . 'price-history/bootstrap.php';
require_once EGGL_PATH . 'gpsr/bootstrap.php';
require_once EGGL_PATH . 'repair/bootstrap.php';

register_activation_hook(__FILE__, ['EGGL_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['EGGL_Price_History_Module', 'deactivate']);

add_action('init', function () {
    load_plugin_textdomain(
        'garanlabs-eu-guarantee-notice-durability-labels',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
});

add_action('plugins_loaded', function () {
    // Runs with or without WooCommerce: without it, the notice and label are placed with the
    // shortcodes/blocks and the header notice, and every WooCommerce-only hook simply never fires.
    $plugin = new EGGL_Core();
    $plugin->run();
});

// EU order withdrawal button (WooCommerce only); independent of the standalone withdrawal plugin.
add_action('plugins_loaded', ['EGGL_Withdrawal_Module', 'boot'], 20);

// Price history, EU Omnibus lowest price, anchor price & digital price list (WooCommerce only); independent of the standalone Product Price History plugin.
add_action('plugins_loaded', ['EGGL_Price_History_Module', 'boot'], 20);

// GPSR product safety information — manufacturer, responsible person, warnings, documents (WooCommerce only); independent of the standalone Euverify GPSR plugin.
add_action('plugins_loaded', ['EGGL_GPSR_Module', 'boot'], 20);

// Right to repair (Directive (EU) 2024/1799) — defect claims with repair or replacement, 12-month guarantee extension after a repair (WooCommerce only).
add_action('plugins_loaded', ['EGGL_Repair_Module', 'boot'], 20);
