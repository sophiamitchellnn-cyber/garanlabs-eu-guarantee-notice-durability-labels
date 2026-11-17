<?php
/**
 * Right to repair: "Order" dropdown for logged-in customers. Picking an order loads its
 * claim form (auto-submits with JavaScript; the Continue button covers browsers without it).
 *
 * Override by copying to yourtheme/woocommerce/eggl-repair/order-select.php.
 *
 * @var WC_Order[] $orders   the customer's orders that can still be claimed for
 * @var int        $selected order id shown below, 0 for none
 */
if (!defined('ABSPATH')) exit;

$eggl_rr_btn_cls = function_exists('wc_wp_theme_get_element_class_name') && wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : '';
?>
<form method="get" action="<?php echo esc_url(get_permalink(EGGL_Repair_Settings::page_id())); ?>" class="woocommerce-form eggl-rr__form eggl-rr__form--select" data-eggl-rr-order-select>
    <?php
    // Plain permalinks keep the page in the query string; carry it over so the GET form stays on this page.
    $eggl_rr_query = (string) wp_parse_url(get_permalink(EGGL_Repair_Settings::page_id()), PHP_URL_QUERY);
    parse_str($eggl_rr_query, $eggl_rr_query_args);
    foreach ($eggl_rr_query_args as $eggl_rr_qk => $eggl_rr_qv) {
        echo '<input type="hidden" name="' . esc_attr($eggl_rr_qk) . '" value="' . esc_attr($eggl_rr_qv) . '">';
    }
    ?>
    <div class="form-row form-row-wide validate-required" id="eggl_rr_order_field">
        <label for="eggl_rr_order_select" class="required_field"><?php esc_html_e('Order', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
        <span class="woocommerce-input-wrapper">
            <select id="eggl_rr_order_select" name="eggl_rr_order" class="input-text eggl-rr__order-select" required>
                <?php if (!$selected) : ?>
                    <option value="" selected disabled><?php esc_html_e('Please select an order', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></option>
                <?php endif; ?>
                <?php foreach ($orders as $eggl_rr_order) :
                    $eggl_rr_names = implode(', ', array_map(function ($item) { return $item->get_name(); }, EGGL_Repair_Claims::claimable_items($eggl_rr_order)));
                    ?>
                    <option value="<?php echo esc_attr($eggl_rr_order->get_id()); ?>" <?php selected($selected, $eggl_rr_order->get_id()); ?>>
                        <?php
                        /* translators: 1: order number, 2: order date */
                        echo esc_html(sprintf(__('Order #%1$s from %2$s', 'garanlabs-eu-guarantee-notice-durability-labels'), $eggl_rr_order->get_order_number(), wc_format_datetime($eggl_rr_order->get_date_created())) . ' — ' . $eggl_rr_names);
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </span>
    </div>
    <p class="form-row eggl-rr__select-submit">
        <button type="submit" class="woocommerce-button button woocommerce-form-return_request__submit eggl-rr__button<?php echo esc_attr($eggl_rr_btn_cls); ?>"><?php esc_html_e('Continue', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></button>
    </p>
</form>
