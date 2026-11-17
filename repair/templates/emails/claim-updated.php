<?php
/**
 * Customer email: defect claim status changed.
 *
 * Override by copying to yourtheme/woocommerce/eggl-repair/emails/claim-updated.php.
 *
 * @var string        $email_heading
 * @var string        $additional_content
 * @var int           $claim_id
 * @var WC_Order|null $order
 * @var string        $new_status
 * @var string        $message
 * @var WC_Email      $email
 */
if (!defined('ABSPATH')) exit;

$eggl_rr_name = (string) EGGL_Repair_Claims::meta($claim_id, 'name', '');
$eggl_rr_text = EGGL_RR_Email_Claim_Updated_Text::for_status($new_status);

do_action('woocommerce_email_header', $email_heading, $email); ?>

<p><?php
if ($eggl_rr_name !== '') {
    /* translators: %s: customer name */
    printf(esc_html__('Hi %s,', 'garanlabs-eu-guarantee-notice-durability-labels'), esc_html($eggl_rr_name));
} else {
    esc_html_e('Hi,', 'garanlabs-eu-guarantee-notice-durability-labels');
}
?></p>
<?php if ($eggl_rr_text !== '') : ?>
    <p><?php echo esc_html($eggl_rr_text); ?></p>
<?php endif; ?>

<?php if ($message !== '') : ?>
    <table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation" style="margin:0 0 24px;">
        <tr>
            <td style="padding:14px 18px;background-color:<?php echo $new_status === 'rejected' ? '#fdf2f2' : '#f4fbfa'; ?>;border-left:4px solid <?php echo $new_status === 'rejected' ? '#b32d2e' : '#0f766e'; ?>;border-radius:4px;">
                <p style="margin:0 0 6px;font-weight:bold;"><?php echo esc_html($new_status === 'rejected' ? __('Reason:', 'garanlabs-eu-guarantee-notice-durability-labels') : __('Message from us:', 'garanlabs-eu-guarantee-notice-durability-labels')); ?></p>
                <div><?php echo wp_kses_post(wpautop(make_clickable(esc_html($message)))); ?></div>
            </td>
        </tr>
    </table>
<?php endif; ?>

<?php
EGGL_Repair_Module::template('emails/claim-details.php', ['claim_id' => $claim_id, 'order' => $order, 'sent_to_admin' => false]);

if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}

do_action('woocommerce_email_footer', $email);
