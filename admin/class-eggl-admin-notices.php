<?php
if (!defined('ABSPATH')) exit;

/**
 * Dismissible admin notices: a one-time welcome after first activation
 * pointing to the settings page, a one-time announcement of the order
 * withdrawal feature (2.0), and a review request once the plugin has been in
 * use for a day. All are dismissed through admin-post.php with a nonce, and
 * only shown to users who can manage the plugin's settings.
 */
class EGGL_Admin_Notices {

    const ACTION        = 'eggl_dismiss_notice';
    const REVIEW_DELAY  = DAY_IN_SECONDS;
    const REVIEW_SNOOZE = 7 * DAY_IN_SECONDS;
    const REVIEW_URL    = 'https://wordpress.org/support/plugin/garanlabs-eu-guarantee-notice-durability-labels/reviews/#new-post';

    /** Installs from before this feature have no install time yet: start their review clock now. */
    public function stamp_install_time() {
        if (!get_option('eggl_installed_at')) {
            add_option('eggl_installed_at', time());
        }
    }

    public function render() {
        if (!current_user_can(EGGL_Settings::capability())) return;

        $screen      = function_exists('get_current_screen') ? get_current_screen() : null;
        $screen_id   = $screen ? (string) $screen->id : '';
        $on_settings = strpos($screen_id, EGGL_Settings::PAGE_SLUG) !== false;

        if (get_option('eggl_show_welcome') === 'yes') {
            if ($on_settings) {
                // They found the settings page — the welcome has done its job.
                delete_option('eggl_show_welcome');
            } else {
                $this->render_welcome();
            }
        }

        // Withdrawal feature announcement: done once the merchant has opened the Withdrawals screen.
        $on_withdrawals = strpos($screen_id, 'eggl-withdrawals') !== false;
        if ($on_withdrawals && $this->withdrawal_notice_due()) {
            update_option('eggl_wd_feature_notice', 'dismissed', false);
        }

        if ($this->withdrawal_notice_due()) {
            // Feature announcement: only on the dashboard, the plugins list and this plugin's own screens.
            $on_plugin_screen = $on_settings || strpos($screen_id, EGGL_Vendor_Uploads::PAGE_SLUG) !== false;
            if ($screen_id === 'dashboard' || $screen_id === 'plugins' || $on_plugin_screen) {
                $this->render_withdrawal_feature();
            }
        }

        // Review request: on every admin screen.
        if ($this->review_due()) $this->render_review();
    }

    private function withdrawal_notice_due() {
        return class_exists('\\EGGL\\Withdrawal\\Package', false)
            && get_option('eggl_wd_feature_notice') !== 'dismissed';
    }

    private function render_withdrawal_feature() {
        $settings_url = admin_url('admin.php?page=eggl-withdrawals');
        $is_live      = function_exists('eggl_wd_has_public_withdrawal_page') && eggl_wd_has_public_withdrawal_page();
        ?>
        <div class="notice notice-info eggl-admin-notice eggl-admin-notice--withdrawal">
            <p><strong><?php esc_html_e('New: EU order withdrawal button', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></strong></p>
            <p><?php esc_html_e('From 19 June 2026, online shops selling to consumers in the EU must offer an easy way to withdraw from a contract online (Directive (EU) 2023/2673). This plugin now adds a withdrawal form, a "Withdraw from contract" button in the footer and header menu, and a Withdraw button in My account, with confirmation emails and a list of requests to confirm or reject.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
            <p><?php echo esc_html($is_live
                ? __('Your withdrawal page is live. Review the settings and manage incoming requests under EU Guarantee & GARAN → Withdrawals.', 'garanlabs-eu-guarantee-notice-durability-labels')
                : __('A "Withdraw from contract" page has been created as a draft, so nothing has changed on your shop yet. Review the settings, then publish the page to switch it on.', 'garanlabs-eu-guarantee-notice-durability-labels')); ?></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Set up withdrawals', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></a>
                <a class="button-link" style="margin-left:8px" href="<?php echo esc_url($this->dismiss_url('withdrawal_feature')); ?>"><?php esc_html_e('Dismiss', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></a>
            </p>
        </div>
        <?php
    }

    private function review_due() {
        $state = (string) get_option('eggl_review_state', '');
        if ($state === 'done') return false;

        $installed = (int) get_option('eggl_installed_at', 0);
        if (!$installed || time() - $installed < self::REVIEW_DELAY) return false;

        $snoozed_until = (int) get_option('eggl_review_snoozed_until', 0);
        return time() >= $snoozed_until;
    }

    private function dismiss_url($which) {
        return wp_nonce_url(add_query_arg(['action' => self::ACTION, 'eggl_notice' => $which], admin_url('admin-post.php')), self::ACTION);
    }

    private function render_welcome() {
        ?>
        <div class="notice notice-info eggl-admin-notice">
            <p><strong><?php esc_html_e('EU Legal-Guarantee Notice & GARAN Durability Label is active.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></strong></p>
            <p><?php echo esc_html(class_exists('WooCommerce')
                ? __('The legal-guarantee notice is already showing on your product pages. Choose its language, placement and look, and switch on checkout and email copies, in the settings.', 'garanlabs-eu-guarantee-notice-durability-labels')
                : __('WooCommerce is not active, so place the notice yourself with the [eu_guarantee_notice] shortcode or the "EU Guarantee Notice" block. Choose its language and look in the settings.', 'garanlabs-eu-guarantee-notice-durability-labels')); ?></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=' . EGGL_Settings::PAGE_SLUG)); ?>"><?php esc_html_e('Open settings', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></a>
                <a class="button-link" style="margin-left:8px" href="<?php echo esc_url($this->dismiss_url('welcome')); ?>"><?php esc_html_e('Dismiss', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></a>
            </p>
        </div>
        <?php
    }

    private function render_review() {
        ?>
        <div class="notice notice-info eggl-admin-notice">
            <p><?php esc_html_e('Is the EU Guarantee Notice & GARAN Label plugin helping your shop get ready for the new EU rules? A short review on WordPress.org helps other shop owners find it — it takes a minute.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url($this->dismiss_url('review_done')); ?>" target="_blank" rel="noopener noreferrer"
                   onclick="this.closest('.eggl-admin-notice').style.display='none';"><?php esc_html_e('Leave a review', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></a>
                <a class="button" style="margin-left:6px" href="<?php echo esc_url($this->dismiss_url('review_later')); ?>"><?php esc_html_e('Maybe later', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></a>
                <a class="button-link" style="margin-left:8px" href="<?php echo esc_url($this->dismiss_url('review_never')); ?>"><?php esc_html_e('Don\'t ask again', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></a>
            </p>
        </div>
        <?php
    }

    /** admin-post.php handler for every button above. */
    public function handle_dismiss() {
        check_admin_referer(self::ACTION);
        if (!current_user_can(EGGL_Settings::capability())) wp_die('', '', ['response' => 403]);

        $which = isset($_GET['eggl_notice']) ? sanitize_key(wp_unslash($_GET['eggl_notice'])) : '';
        switch ($which) {
            case 'welcome':
                delete_option('eggl_show_welcome');
                break;
            case 'withdrawal_feature':
                update_option('eggl_wd_feature_notice', 'dismissed', false);
                break;
            case 'review_done':
                update_option('eggl_review_state', 'done');
                // Opened in a new tab: send it on to the review form itself.
                wp_redirect(self::REVIEW_URL); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed wordpress.org URL
                exit;
            case 'review_later':
                update_option('eggl_review_snoozed_until', time() + self::REVIEW_SNOOZE);
                break;
            case 'review_never':
                update_option('eggl_review_state', 'done');
                break;
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }
}
