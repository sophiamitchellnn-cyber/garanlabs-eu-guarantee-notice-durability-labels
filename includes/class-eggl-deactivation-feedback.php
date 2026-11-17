<?php
if (!defined('ABSPATH')) exit;

/**
 * Deactivation feedback modal on the Plugins list screen. Intercepts the
 * "Deactivate" link for this plugin, shows a short survey, then proceeds
 * with deactivation whether or not the merchant submits feedback.
 */
class EGGL_Deactivation_Feedback {

    const NONCE_ACTION = 'eggl_deactivation_feedback';

    public function render_modal() {
        if (!current_user_can('deactivate_plugins')) return;

        $plugin_slug = plugin_basename(EGGL_FILE);
        $nonce       = wp_create_nonce(self::NONCE_ACTION);
        ?>
        <div id="eggl-deactivation-modal" style="display:none;position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.55);align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:10px;width:520px;max-width:92%;box-shadow:0 16px 48px rgba(0,0,0,.28);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;overflow:hidden;">

                <div style="background:linear-gradient(135deg,#0f766e 0%,#14b8a6 100%);padding:20px 24px;display:flex;align-items:center;justify-content:space-between;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <svg style="width:22px;height:22px;fill:#fff;flex-shrink:0;" viewBox="0 0 24 24"><path d="M11 7h2v2h-2zm0 4h2v6h-2zm1-9C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/></svg>
                        <h3 style="margin:0;color:#fff;font-size:16px;font-weight:600;"><?php esc_html_e('Quick Feedback — 30 Seconds!', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h3>
                    </div>
                    <button id="eggl-modal-close" style="background:none;border:none;cursor:pointer;padding:4px;line-height:1;color:rgba(255,255,255,.75);display:flex;align-items:center;" aria-label="<?php esc_attr_e('Close', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>">
                        <svg style="width:20px;height:20px;fill:currentColor;" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                    </button>
                </div>

                <div style="padding:22px 24px 16px;">
                    <p style="margin:0 0 16px;color:#3c434a;font-size:14px;line-height:1.6;"><?php esc_html_e("Please let us know why you're deactivating — your feedback helps us improve the plugin.", 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
                    <form id="eggl-deactivation-form">
                        <textarea id="eggl-deactivation-details" name="eggl_details" rows="4" required
                            placeholder="<?php esc_attr_e('Please tell us why you are deactivating the plugin…', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>"
                            style="width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:6px;resize:vertical;font-family:inherit;font-size:13px;color:#3c434a;box-sizing:border-box;line-height:1.5;"></textarea>
                    </form>
                </div>

                <div style="padding:14px 24px 18px;border-top:1px solid #f0f0f0;display:flex;align-items:center;justify-content:flex-end;gap:10px;background:#fafafa;">
                    <button id="eggl-submit-deactivate" disabled style="background:#99d3cc;color:#fff;border:none;border-radius:6px;padding:8px 22px;cursor:not-allowed;font-size:13px;font-family:inherit;font-weight:600;transition:background .15s;">
                        <?php esc_html_e('Submit & Deactivate', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>
                    </button>
                </div>

            </div>
        </div>
        <script type="text/javascript">
        (function($){
            var deactivateUrl = '';
            var pluginFile    = '<?php echo esc_js($plugin_slug); ?>';
            var pluginDir     = pluginFile.split('/')[0];
            var nonce         = '<?php echo esc_js($nonce); ?>';
            var $modal        = $('#eggl-deactivation-modal');
            var $details      = $('#eggl-deactivation-details');
            var $submitBtn    = $('#eggl-submit-deactivate');

            /* Intercept the Deactivate link for this specific plugin. */
            $(document).on('click', 'tr[data-plugin="' + pluginFile + '"] .deactivate a, tr[data-slug="' + pluginDir + '"] .deactivate a', function(e){
                e.preventDefault();
                deactivateUrl = $(this).attr('href');
                $modal.css('display', 'flex');
            });

            /* Close via × button or clicking the overlay. */
            $('#eggl-modal-close').on('click', function(){ $modal.hide(); });
            $modal.on('click', function(e){ if ($(e.target).is($modal)) { $modal.hide(); } });
            $(document).on('keydown', function(e){ if (e.key === 'Escape') { $modal.hide(); } });

            /* The submit button only unlocks once feedback has been typed in. */
            $details.on('input', function(){
                var hasText = $.trim($details.val()).length > 0;
                $submitBtn.prop('disabled', !hasText).css({
                    background: hasText ? '#0f766e' : '#99d3cc',
                    cursor:     hasText ? 'pointer'  : 'not-allowed'
                });
            });

            /* Submit & Deactivate — send feedback then redirect. */
            $submitBtn.on('click', function(){
                var details = $.trim($details.val());
                if (!details) {
                    return;
                }

                $submitBtn.prop('disabled', true).css('opacity', '.7').text('<?php echo esc_js(__('Sending…', 'garanlabs-eu-guarantee-notice-durability-labels')); ?>');

                $.post(ajaxurl, {
                    action:      'eggl_submit_deactivation_feedback',
                    _ajax_nonce: nonce,
                    details:     details
                }).always(function(){
                    window.location.href = deactivateUrl;
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * AJAX: send deactivation feedback to Supabase (same project used by our
     * other plugins, so feedback lands in one shared dashboard).
     */
    public function ajax_submit() {
        check_ajax_referer(self::NONCE_ACTION);

        if (!current_user_can('deactivate_plugins')) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
        }

        $details = isset($_POST['details']) ? sanitize_textarea_field(wp_unslash($_POST['details'])) : '';

        if ('' === $details) {
            wp_send_json_error(['message' => 'Feedback is required.']);
        }

        $supabase_url = 'https://mkehtfdqzlepqbjnbhae.supabase.co';
        $supabase_key = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Im1rZWh0ZmRxemxlcHFiam5iaGFlIiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODIxOTAyNDUsImV4cCI6MjA5Nzc2NjI0NX0.OCZ4QEvV4Phfs_JYmTeHJZfDtHo7IgCfwhrEgqxqzPg';

        $current_user = wp_get_current_user();

        $payload = wp_json_encode([
            'details'  => $details,
            'version'  => EGGL_VERSION,
            'site_url' => get_site_url(),
            'email'    => $current_user->user_email,
        ]);

        wp_remote_post(
            $supabase_url . '/rest/v1/plugin_feedback',
            [
                'timeout' => 10,
                'headers' => [
                    'apikey'        => $supabase_key,
                    'Authorization' => 'Bearer ' . $supabase_key,
                    'Content-Type'  => 'application/json',
                    'Prefer'        => 'return=minimal',
                ],
                'body' => $payload,
            ]
        );

        wp_send_json_success();
    }
}
