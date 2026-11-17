<?php
/**
 * Shop email (plain text): new defect claim.
 *
 * @var string        $email_heading
 * @var string        $additional_content
 * @var int           $claim_id
 * @var WC_Order|null $order
 */
if (!defined('ABSPATH')) exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n" . esc_html(wp_strip_all_tags($email_heading)) . "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
echo esc_html__('A customer has reported a defect and asks for a free repair or replacement under the legal guarantee.', 'garanlabs-eu-guarantee-notice-durability-labels') . "\n";
echo esc_url_raw(EGGL_Repair_Admin::claim_url($claim_id)) . "\n\n";

EGGL_Repair_Module::template('emails/plain/claim-details.php', ['claim_id' => $claim_id, 'order' => $order, 'sent_to_admin' => true]);

if ($additional_content) {
    echo esc_html(wp_strip_all_tags(wptexturize($additional_content))) . "\n\n";
}
echo "----------------------------------------\n\n";
echo esc_html(wp_strip_all_tags(preg_replace('#<br\s*/?>#i', "\n", (string) apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')))));
