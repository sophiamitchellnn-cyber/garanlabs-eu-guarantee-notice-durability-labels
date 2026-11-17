<?php
/**
 * Uninstall cleanup for EU Legal-Guarantee Notice & GARAN Durability Label for WooCommerce.
 *
 * Runs ONLY when WordPress deletes the plugin (not on deactivate). By default we
 * KEEP settings so a delete + reinstall preserves the merchant's choices. Data is
 * removed only if the admin ticked "Also delete all settings when the plugin is
 * deleted" (option eggl_delete_on_uninstall = 'yes').
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Price history module: its scheduled jobs always go; its data only when "Delete all price history
// data on uninstall" (Price History → General) is ticked, since the recorded prices back the
// EU Omnibus 30-day lowest price and may need to be kept.
wp_unschedule_hook('eggl_ph_anchor_resolve_batch');
wp_unschedule_hook('eggl_ph_pcm_csv_cleanup');
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('eggl_ph_dpl_generate');
    as_unschedule_all_actions('eggl_ph_dpl_generate_continue');
}
$eggl_ph_general = get_option('eggl_ph_general');
if (is_array($eggl_ph_general) && !empty($eggl_ph_general['delete_plugin_data'])) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall cleanup; table name is fixed.
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}eggl_ph_price_history");
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('eggl_ph_') . '%'));
}

// GPSR module: product safety data only when "Delete all product safety data when the plugin is
// deleted" (EU Guarantee & GARAN → Product Safety (GPSR)) is ticked. Uploaded documents stay in the Media Library.
if (get_option('eggl_gpsr_delete_on_uninstall') === 'yes') {
    require_once __DIR__ . '/gpsr/includes/class-eggl-gpsr-fields.php';
    foreach (EGGL_GPSR_Fields::meta_keys() as $eggl_gpsr_key) {
        delete_post_meta_by_key($eggl_gpsr_key);
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('eggl_gpsr_') . '%'));
}

if (get_option('eggl_delete_on_uninstall') !== 'yes') {
    return;
}

$eggl_options = [
    'eggl_locale',
    'eggl_show_credit',
    'eggl_delete_on_uninstall',
    'eggl_notice_width',
    'eggl_installed_at',
    'eggl_show_welcome',
    'eggl_review_state',
    'eggl_review_snoozed_until',
    'eggl_rr_settings',
    'eggl_rr_page_id',
];

foreach ($eggl_options as $eggl_opt) {
    delete_option($eggl_opt);
}

// Withdrawal module settings (eggl_wd_*). Withdrawal requests themselves are kept — they are
// records of customers exercising a legal right and may need to be retained.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('eggl_wd_') . '%'));

// Price history module settings (eggl_ph_*). The recorded price history table is kept unless the
// module's own delete option above was ticked.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('eggl_ph_') . '%'));
// GPSR module settings (eggl_gpsr_*). Per-product safety data is kept unless the module's own delete option above was ticked.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('eggl_gpsr_') . '%'));
wp_cache_flush();

// Browser-drawn GARAN email images (EGGL_Garan_Raster), if any were ever created.
$eggl_uploads = wp_upload_dir(null, false);
$eggl_cache   = trailingslashit($eggl_uploads['basedir']) . 'eggl-garan/';
if (is_dir($eggl_cache)) {
    foreach ((array) glob($eggl_cache . '*.png') as $eggl_file) {
        wp_delete_file($eggl_file);
    }
    @rmdir($eggl_cache); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
