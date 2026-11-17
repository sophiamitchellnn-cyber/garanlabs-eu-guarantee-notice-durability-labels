<?php
/**
 * Right to repair: confirmation after a claim was sent.
 *
 * Override by copying to yourtheme/woocommerce/eggl-repair/form-sent.php.
 *
 * @var string   $claim_number
 * @var WC_Order $order
 */
if (!defined('ABSPATH')) exit;
?>
<div class="woocommerce-message eggl-rr__sent" role="status">
    <?php
    /* translators: %s: claim number */
    printf(esc_html__('Thank you — we have received your defect claim #%s and sent a confirmation to your email address. We will get back to you as soon as possible.', 'garanlabs-eu-guarantee-notice-durability-labels'), esc_html($claim_number));
    ?>
</div>
<?php if (EGGL_Repair_Claims::order_is_claimable($order)) : ?>
    <p><a href="<?php echo esc_url(EGGL_Repair_Settings::form_url($order)); ?>"><?php esc_html_e('Report another defect for this order', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></a></p>
<?php endif; ?>
