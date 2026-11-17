<?php
/**
 * Customer email: defect claim received.
 *
 * Override by copying to yourtheme/woocommerce/eggl-repair/emails/claim-received.php.
 *
 * @var string        $email_heading
 * @var string        $additional_content
 * @var int           $claim_id
 * @var WC_Order|null $order
 * @var WC_Email      $email
 */
if (!defined('ABSPATH')) exit;

$eggl_rr_name = (string) EGGL_Repair_Claims::meta($claim_id, 'name', '');

do_action('woocommerce_email_header', $email_heading, $email); ?>

<p><?php
if ($eggl_rr_name !== '') {
    /* translators: %s: customer name */
    printf(esc_html__('Hi %s,', 'garanlabs-eu-guarantee-notice-durability-labels'), esc_html($eggl_rr_name));
} else {
    esc_html_e('Hi,', 'garanlabs-eu-guarantee-notice-durability-labels');
}
?></p>
<p><?php esc_html_e('Thank you for letting us know about the defect. We have received your claim and will get back to you as soon as possible.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
<p><?php
/* translators: %d: number of months */
printf(esc_html__('Good to know: if the item is repaired, your legal guarantee for it is extended by %d months.', 'garanlabs-eu-guarantee-notice-durability-labels'), (int) EGGL_Repair_Settings::extension_months());
?></p>

<?php
EGGL_Repair_Module::template('emails/claim-details.php', ['claim_id' => $claim_id, 'order' => $order, 'sent_to_admin' => false]);

if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}

do_action('woocommerce_email_footer', $email);
