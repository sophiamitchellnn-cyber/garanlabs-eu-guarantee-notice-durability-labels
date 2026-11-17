<?php
/**
 * Right to repair: find the order (guests) or pick one (logged-in customers).
 *
 * Override by copying to yourtheme/woocommerce/eggl-repair/form-lookup.php.
 *
 * @var WP_Error|null $errors
 * @var array         $posted ['number', 'email']
 * @var WC_Order[]    $orders the logged-in customer's orders that can still be claimed for
 * @var string        $nonce
 */
if (!defined('ABSPATH')) exit;

echo EGGL_Repair_Notice::html(false); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html()
EGGL_Repair_Module::template('form-errors.php', ['errors' => $errors]);
?>

<?php if ($orders) : ?>
    <?php EGGL_Repair_Module::template('order-select.php', ['orders' => $orders, 'selected' => 0]); ?>
    <p class="eggl-rr__or"><?php esc_html_e('Or look up another order:', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
<?php else : ?>
    <p class="eggl-rr__intro"><?php esc_html_e('Enter your order number and the email address you used for the order.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
<?php endif; ?>

<form method="post" class="woocommerce-form eggl-rr__form eggl-rr__form--lookup">
    <?php
    woocommerce_form_field('eggl_rr_number', [
        'type'              => 'text',
        'label'             => __('Order number', 'garanlabs-eu-guarantee-notice-durability-labels'),
        'required'          => true,
        'class'             => ['form-row-wide'],
        'id'                => 'eggl_rr_number',
        'custom_attributes' => ['autocomplete' => 'off', 'required' => 'required'],
    ], $posted['number'] ?? '');
    woocommerce_form_field('eggl_rr_email', [
        'type'              => 'email',
        'label'             => __('Email address', 'garanlabs-eu-guarantee-notice-durability-labels'),
        'required'          => true,
        'class'             => ['form-row-wide'],
        'id'                => 'eggl_rr_email',
        'autocomplete'      => 'email',
        'custom_attributes' => ['required' => 'required'],
    ], $posted['email'] ?? '');
    ?>
    <input type="hidden" name="eggl_rr_action" value="lookup">
    <input type="hidden" name="eggl_rr_nonce" value="<?php echo esc_attr($nonce); ?>">
    <p class="form-row eggl-rr__submit">
        <button type="submit" class="woocommerce-button button woocommerce-form-return_request__submit eggl-rr__button<?php echo esc_attr(function_exists('wc_wp_theme_get_element_class_name') && wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : ''); ?>"><?php esc_html_e('Find my order', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></button>
    </p>
</form>
