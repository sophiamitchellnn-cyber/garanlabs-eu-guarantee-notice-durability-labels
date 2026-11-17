<?php
/**
 * Shop email: new defect claim.
 *
 * Override by copying to yourtheme/woocommerce/eggl-repair/emails/new-claim.php.
 *
 * @var string        $email_heading
 * @var string        $additional_content
 * @var int           $claim_id
 * @var WC_Order|null $order
 * @var WC_Email      $email
 */
if (!defined('ABSPATH')) exit;

do_action('woocommerce_email_header', $email_heading, $email); ?>

<p><?php esc_html_e('A customer has reported a defect and asks for a free repair or replacement under the legal guarantee.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
<p><a href="<?php echo esc_url(EGGL_Repair_Admin::claim_url($claim_id)); ?>"><?php esc_html_e('Open the claim', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></a></p>

<?php
EGGL_Repair_Module::template('emails/claim-details.php', ['claim_id' => $claim_id, 'order' => $order, 'sent_to_admin' => true]);

if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}

do_action('woocommerce_email_footer', $email);
