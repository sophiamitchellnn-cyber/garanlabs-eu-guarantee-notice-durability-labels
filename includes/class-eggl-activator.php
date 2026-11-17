<?php
if (!defined('ABSPATH')) exit;

/**
 * Runs once on activation. add_option() only writes when the key is missing,
 * so a merchant's saved choices survive deactivate/reactivate and updates.
 */
class EGGL_Activator {
    public static function activate() {
        add_option('eggl_locale', 'auto'); // display language of the official notice; "auto" follows the site's own language
        add_option('eggl_show_credit', 'no');           // optional "Powered by GARAN Labs" credit — opt-in
        add_option('eggl_delete_on_uninstall', 'no');

        // First activation ever: record when (the review request waits a day) and show the welcome notice once.
        if (!get_option('eggl_installed_at')) {
            add_option('eggl_installed_at', time());
            update_option('eggl_show_welcome', 'yes');
        }
    }
}
