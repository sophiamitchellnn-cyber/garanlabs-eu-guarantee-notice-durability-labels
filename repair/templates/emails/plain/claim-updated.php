<?php
/**
 * Customer email (plain text): defect claim status changed.
 *
 * @var string        $email_heading
 * @var string        $additional_content
 * @var int           $claim_id
 * @var WC_Order|null $order
 * @var string        $new_status
 * @var string        $message
 */
if (!defined('ABSPATH')) exit;

$eggl_rr_name = (string) EGGL_Repair_Claims::meta($claim_id, 'name', '');
$eggl_rr_text = EGGL_RR_Email_Claim_Updated_Text::for_status($new_status);

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n" . esc_html(wp_strip_all_tags($email_heading)) . "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
/* translators: %s: customer name */
echo esc_html($eggl_rr_name !== '' ? sprintf(__('Hi %s,', 'garanlabs-eu-guarantee-notice-durability-labels'), $eggl_rr_name) : __('Hi,', 'garanlabs-eu-guarantee-notice-durability-labels')) . "\n\n";
if ($eggl_rr_text !== '') echo esc_html($eggl_rr_text) . "\n\n";
if ($message !== '') {
    echo esc_html(mb_strtoupper($new_status === 'rejected' ? __('Reason:', 'garanlabs-eu-guarantee-notice-durability-labels') : __('Message from us:', 'garanlabs-eu-guarantee-notice-durability-labels'))) . "\n" . esc_html($message) . "\n\n";
}

EGGL_Repair_Module::template('emails/plain/claim-details.php', ['claim_id' => $claim_id, 'order' => $order, 'sent_to_admin' => false]);

if ($additional_content) {
    echo esc_html(wp_strip_all_tags(wptexturize($additional_content))) . "\n\n";
}
echo "----------------------------------------\n\n";
echo esc_html(wp_strip_all_tags(preg_replace('#<br\s*/?>#i', "\n", (string) apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')))));
