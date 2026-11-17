<?php
/**
 * Right to repair: the defect claim form for one order.
 *
 * Override by copying to yourtheme/woocommerce/eggl-repair/form-claim.php.
 *
 * @var WC_Order                $order
 * @var WC_Order_Item_Product[] $items      claimable lines, keyed by item id
 * @var int[]                   $open_items item ids that already have an open claim
 * @var DateTimeImmutable[]     $item_ends  guarantee end per item id
 * @var WP_Error|null           $errors
 * @var array                   $posted
 * @var int                     $photos     max photos (0 = no uploads)
 * @var string                  $nonce
 * @var string                  $ts         signed render time
 * @var string                  $key        order key
 * @var WC_Order[]               $customer_orders the logged-in customer's claimable orders (for the order dropdown)
 */
if (!defined('ABSPATH')) exit;

$eggl_rr_months  = EGGL_Repair_Settings::extension_months();
$eggl_rr_remedy  = $posted['remedy'] ?? '';
$eggl_rr_btn_cls = function_exists('wc_wp_theme_get_element_class_name') && wc_wp_theme_get_element_class_name('button') ? ' ' . wc_wp_theme_get_element_class_name('button') : '';
$eggl_rr_req     = '&nbsp;<span class="required" aria-hidden="true">*</span>';
?>
<?php if (!empty($customer_orders) && in_array($order->get_id(), array_map(function ($o) { return $o->get_id(); }, $customer_orders), true)) : ?>
    <?php EGGL_Repair_Module::template('order-select.php', ['orders' => $customer_orders, 'selected' => $order->get_id()]); ?>
<?php else : ?>
    <div class="eggl-rr__order-summary">
        <span class="eggl-rr__order-summary-label"><?php esc_html_e('Order', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></span>
        <strong>#<?php echo esc_html($order->get_order_number()); ?></strong>
        <span class="eggl-rr__order-summary-date"><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></span>
    </div>
<?php endif; ?>

<?php EGGL_Repair_Module::template('form-errors.php', ['errors' => $errors]); ?>

<form method="post" enctype="multipart/form-data" class="woocommerce-form eggl-rr__form eggl-rr__form--claim">

    <fieldset class="eggl-rr__section">
        <legend class="eggl-rr__section-title"><span class="eggl-rr__step">1</span><?php esc_html_e('Which item is defective?', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></legend>
        <ul class="eggl-rr__items">
            <?php foreach ($items as $eggl_rr_item_id => $eggl_rr_item) :
                $eggl_rr_open    = in_array((int) $eggl_rr_item_id, $open_items, true);
                $eggl_rr_checked = in_array((int) $eggl_rr_item_id, (array) ($posted['items'] ?? []), true) || count($items) === 1;
                $eggl_rr_qty     = (int) $eggl_rr_item->get_quantity();
                $eggl_rr_end     = $item_ends[$eggl_rr_item_id] ?? null;
                $eggl_rr_product = $eggl_rr_item->get_product();
                $eggl_rr_thumb   = $eggl_rr_product ? $eggl_rr_product->get_image([64, 64]) : '';
                ?>
                <li class="eggl-rr__item<?php echo $eggl_rr_open ? ' is-disabled' : ''; ?>">
                    <label class="eggl-rr__item-main">
                        <input class="eggl-rr__check" type="checkbox" name="eggl_rr_items[]" value="<?php echo esc_attr($eggl_rr_item_id); ?>" <?php checked($eggl_rr_checked && !$eggl_rr_open); ?> <?php disabled($eggl_rr_open); ?>>
                        <?php if ($eggl_rr_thumb) : ?>
                            <span class="eggl-rr__item-thumb"><?php echo wp_kses_post($eggl_rr_thumb); ?></span>
                        <?php endif; ?>
                        <span class="eggl-rr__item-text">
                            <span class="eggl-rr__item-name"><?php echo esc_html($eggl_rr_item->get_name()); ?></span>
                            <span class="eggl-rr__item-meta">
                                <?php if ($eggl_rr_open) : ?>
                                    <?php esc_html_e('A claim for this item is already being handled.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>
                                <?php elseif ($eggl_rr_end) : ?>
                                    <?php
                                    /* translators: %s: date */
                                    printf(esc_html__('Legal guarantee until %s', 'garanlabs-eu-guarantee-notice-durability-labels'), esc_html(wp_date(get_option('date_format'), $eggl_rr_end->getTimestamp())));
                                    ?>
                                <?php endif; ?>
                            </span>
                        </span>
                    </label>
                    <?php if ($eggl_rr_qty > 1 && !$eggl_rr_open) : ?>
                        <label class="eggl-rr__qty">
                            <span><?php esc_html_e('Quantity', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></span>
                            <input type="number" class="input-text" min="1" max="<?php echo esc_attr($eggl_rr_qty); ?>" name="eggl_rr_qty[<?php echo esc_attr($eggl_rr_item_id); ?>]" value="<?php echo esc_attr(min($eggl_rr_qty, max(1, (int) ($posted['qty'][$eggl_rr_item_id] ?? 1)))); ?>">
                        </label>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </fieldset>

    <fieldset class="eggl-rr__section">
        <legend class="eggl-rr__section-title"><span class="eggl-rr__step">2</span><?php esc_html_e('Describe the defect', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></legend>
        <?php
        woocommerce_form_field('eggl_rr_description', [
            'type'              => 'textarea',
            'label'             => __('Describe the defect', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'required'          => true,
            'class'             => ['form-row-wide', 'eggl-rr__label-hidden'],
            'id'                => 'eggl_rr_description',
            'placeholder'       => __('What is wrong, and when does it happen?', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'custom_attributes' => ['rows' => 5, 'minlength' => 10, 'required' => 'required'],
        ], $posted['description'] ?? '');
        woocommerce_form_field('eggl_rr_noticed', [
            'type'              => 'date',
            'label'             => __('When did you notice the defect?', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'class'             => ['form-row-wide'],
            'id'                => 'eggl_rr_noticed',
            'custom_attributes' => ['max' => wp_date('Y-m-d')],
        ], $posted['noticed'] ?? '');
        ?>

        <?php if ($photos > 0) : ?>
            <div class="form-row form-row-wide eggl-rr__photos">
                <label for="eggl_rr_photos"><?php esc_html_e('Photos of the defect', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>&nbsp;<span class="optional">(<?php esc_html_e('optional', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>)</span></label>
                <label class="eggl-rr__dropzone" for="eggl_rr_photos">
                    <svg class="eggl-rr__dropzone-icon" viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h2l1.5-2h7L17 5h2a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><circle cx="12" cy="12.5" r="3.5"/></svg>
                    <span class="eggl-rr__dropzone-text" data-eggl-rr-files data-empty="<?php esc_attr_e('Add photos', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>"><?php esc_html_e('Add photos', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></span>
                    <span class="eggl-rr__dropzone-hint">
                        <?php
                        /* translators: %d: maximum number of photos */
                        printf(esc_html__('Up to %d photos, JPG, PNG or WebP, 5 MB each.', 'garanlabs-eu-guarantee-notice-durability-labels'), (int) $photos);
                        ?>
                    </span>
                    <input type="file" id="eggl_rr_photos" name="eggl_rr_photos[]" accept="image/jpeg,image/png,image/webp" multiple>
                </label>
            </div>
        <?php endif; ?>
    </fieldset>

    <fieldset class="eggl-rr__section">
        <legend class="eggl-rr__section-title"><span class="eggl-rr__step">3</span><?php esc_html_e('What would you like us to do?', 'garanlabs-eu-guarantee-notice-durability-labels'); ?><?php echo $eggl_rr_req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup ?></legend>
        <div class="eggl-rr__choices">
            <label class="eggl-rr__choice">
                <input type="radio" name="eggl_rr_remedy" value="repair" required <?php checked($eggl_rr_remedy === 'repair'); ?>>
                <span class="eggl-rr__choice-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></span>
                <span class="eggl-rr__choice-text">
                    <strong><?php esc_html_e('Repair', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></strong>
                    <span>
                        <?php
                        /* translators: %d: number of months */
                        printf(esc_html__('Free of charge. Your legal guarantee for the repaired item is extended by %d months.', 'garanlabs-eu-guarantee-notice-durability-labels'), (int) $eggl_rr_months);
                        ?>
                    </span>
                    <span class="eggl-rr__badge"><?php
                    /* translators: %d: number of months the legal guarantee is extended by */
                    printf(esc_html__('+%d months', 'garanlabs-eu-guarantee-notice-durability-labels'), (int) $eggl_rr_months);
                    ?></span>
                </span>
            </label>
            <label class="eggl-rr__choice">
                <input type="radio" name="eggl_rr_remedy" value="replacement" <?php checked($eggl_rr_remedy === 'replacement'); ?>>
                <span class="eggl-rr__choice-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"/><path d="M3 21v-5h5"/><path d="M3 12a9 9 0 0 1 15.5-6.2L21 8"/><path d="M21 3v5h-5"/></svg></span>
                <span class="eggl-rr__choice-text">
                    <strong><?php esc_html_e('Replacement', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></strong>
                    <span><?php esc_html_e('Free of charge, with an item of the same kind.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></span>
                </span>
            </label>
        </div>
        <p class="eggl-rr__hint"><?php esc_html_e('If the remedy you choose is impossible or would cost the seller disproportionately more than the other one, we will contact you about the alternative.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
    </fieldset>

    <fieldset class="eggl-rr__section">
        <legend class="eggl-rr__section-title"><span class="eggl-rr__step">4</span><?php esc_html_e('Your contact details', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></legend>
        <?php
        ?>
        <div class="eggl-rr__grid">
        <?php
        woocommerce_form_field('eggl_rr_email', [
            'type'              => 'email',
            'label'             => __('Email', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'required'          => true,
            'class'             => ['form-row-wide'],
            'id'                => 'eggl_rr_email',
            'autocomplete'      => 'email',
            'custom_attributes' => ['required' => 'required'],
        ], $posted['email'] ?? '');
        woocommerce_form_field('eggl_rr_first_name', [
            'type'              => 'text',
            'label'             => __('First name', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'required'          => true,
            'class'             => ['form-row-first'],
            'id'                => 'eggl_rr_first_name',
            'autocomplete'      => 'given-name',
            'custom_attributes' => ['required' => 'required', 'maxlength' => 40],
        ], $posted['first_name'] ?? '');
        woocommerce_form_field('eggl_rr_last_name', [
            'type'              => 'text',
            'label'             => __('Last name', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'required'          => true,
            'class'             => ['form-row-last'],
            'id'                => 'eggl_rr_last_name',
            'autocomplete'      => 'family-name',
            'custom_attributes' => ['required' => 'required', 'maxlength' => 40],
        ], $posted['last_name'] ?? '');
        woocommerce_form_field('eggl_rr_phone', [
            'type'         => 'tel',
            'label'        => __('Phone', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'class'        => ['form-row-wide'],
            'id'           => 'eggl_rr_phone',
            'autocomplete' => 'tel',
        ], $posted['phone'] ?? '');
        ?>
        </div>
        <?php
        ?>
    </fieldset>

    <p class="eggl-rr__hp" aria-hidden="true">
        <label for="eggl_rr_website">Website</label>
        <input type="text" id="eggl_rr_website" name="eggl_rr_website" tabindex="-1" autocomplete="off" value="">
    </p>

    <input type="hidden" name="eggl_rr_action" value="submit">
    <input type="hidden" name="eggl_rr_nonce" value="<?php echo esc_attr($nonce); ?>">
    <input type="hidden" name="eggl_rr_ts" value="<?php echo esc_attr($ts); ?>">
    <input type="hidden" name="eggl_rr_order" value="<?php echo esc_attr($order->get_id()); ?>">
    <input type="hidden" name="eggl_rr_key" value="<?php echo esc_attr($key); ?>">

    <p class="form-row eggl-rr__submit">
        <button type="submit" class="woocommerce-button button woocommerce-form-return_request__submit eggl-rr__button<?php echo esc_attr($eggl_rr_btn_cls); ?>"><?php esc_html_e('Send claim', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></button>
    </p>
</form>
