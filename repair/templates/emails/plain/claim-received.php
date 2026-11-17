<?php
/**
 * Customer email (plain text): defect claim received.
 *
 * @var string        $email_heading
 * @var string        $additional_content
 * @var int           $claim_id
 * @var WC_Order|null $order
 */
if (!defined('ABSPATH')) exit;

$eggl_rr_name = (string) EGGL_Repair_Claims::meta($claim_id, 'name', '');

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n" . esc_html(wp_strip_all_tags($email_heading)) . "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
/* translators: %s: customer name */
echo esc_html($eggl_rr_name !== '' ? sprintf(__('Hi %s,', 'garanlabs-eu-guarantee-notice-durability-labels'), $eggl_rr_name) : __('Hi,', 'garanlabs-eu-guarantee-notice-durability-labels')) . "\n\n";
echo esc_html__('Thank you for letting us know about the defect. We have received your claim and will get back to you as soon as possible.', 'garanlabs-eu-guarantee-notice-durability-labels') . "\n\n";
/* translators: %d: number of months */
echo esc_html(sprintf(__('Good to know: if the item is repaired, your legal guarantee for it is extended by %d months.', 'garanlabs-eu-guarantee-notice-durability-labels'), EGGL_Repair_Settings::extension_months())) . "\n\n";

EGGL_Repair_Module::template('emails/plain/claim-details.php', ['claim_id' => $claim_id, 'order' => $order, 'sent_to_admin' => false]);

if ($additional_content) {
    echo esc_html(wp_strip_all_tags(wptexturize($additional_content))) . "\n\n";
}
echo "----------------------------------------\n\n";
echo esc_html(wp_strip_all_tags(preg_replace('#<br\s*/?>#i', "\n", (string) apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')))));
