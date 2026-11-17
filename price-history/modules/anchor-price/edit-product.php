<?php

namespace EGGL\PriceHistory\Modules\AnchorPrice;

use EGGL\PriceHistory\Includes\Helper as Date_Helper;

if (!defined('ABSPATH')) {
    exit;
}

class Edit_Product
{
    const CUSTOM = '__custom__';

    public function __construct()
    {
        add_action('woocommerce_product_options_general_product_data', [$this, 'simple_product_fields']);
        add_action('woocommerce_variation_options_pricing', [$this, 'variation_fields'], 20, 3);
        add_action('woocommerce_admin_process_product_object', [$this, 'save_fields']);
        add_action('woocommerce_admin_process_variation_object', [$this, 'save_fields']);
    }

    public function simple_product_fields()
    {
        $this->fields(wc_get_product(get_the_ID()));
    }

    public function variation_fields($loop, $variation_data, $variation)
    {
        $this->fields(wc_get_product($variation->ID));
    }

    public function fields($product)
    {
        if (!Helper::supports($product)) {
            return;
        }
        $id = $product->get_id();
        $variation = $product->is_type('variation');
        $stored = Helper::get_date($product);
        [$options, $selected] = $this->date_options($product, $stored);
        $today = (new \DateTimeImmutable('today', wp_timezone()))->format('Y-m-d');
        $locked = Helper::has_stored_data($product);
        echo '<div class="options_group eggl-ph-anchor-fields' . ($variation ? ' eggl-ph-anchor-fields-variation form-row form-row-first' : '') . ($locked ? ' eggl-ph-anchor-locked' : '') . '">';
        wp_nonce_field('eggl_ph_anchor_price_' . $id, 'eggl_ph_anchor_nonce[' . $id . ']', false);
        if ($locked) {
            echo '<div class="eggl-ph-anchor-lock-overlay" role="button" tabindex="0">'
                . '<span class="dashicons dashicons-edit"></span> '
                . esc_html__('Click to edit', 'garanlabs-eu-guarantee-notice-durability-labels')
                . '</div>';
        }
        //echo '<div class="eggl-ph-anchor-date-row">';
        woocommerce_wp_select([
            'id' => 'eggl_ph_anchor_date_choice_' . $id,
            'name' => 'eggl_ph_anchor_data[' . $id . '][date_choice]',
            'label' => __('Base Price Date', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'options' => $options,
            'value' => $selected,
            'wrapper_class' => 'eggl-ph-anchor-date-choice',
            // Locking hides the fields behind an overlay; pointer-events alone doesn't stop tab/keyboard access.
            'custom_attributes' => $locked
                ? ['data-custom-value' => self::CUSTOM, 'tabindex' => '-1']
                : ['data-custom-value' => self::CUSTOM],
        ]);
        woocommerce_wp_text_input([
            'id' => 'eggl_ph_anchor_date_custom_' . $id,
            'name' => 'eggl_ph_anchor_data[' . $id . '][date_custom]',
            'label' => __('Custom Date', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'type' => 'date',
            // A select can't stay unset; prefill with the stored date, or today, so switching to custom never starts blank.
            'value' => $stored ?? $today,
            'wrapper_class' => 'eggl-ph-anchor-date-custom' . ($selected === self::CUSTOM ? '' : ' eggl-ph-hidden'),
            'custom_attributes' => $locked ? ['tabindex' => '-1', 'readonly' => 'readonly'] : [],
        ]);

        $this->price_field($id, $product, $locked);

        echo '</div>';
    }

    private function price_field($id, $product, $locked)
    {
        woocommerce_wp_text_input([
            'id' => 'eggl_ph_anchor_price_' . $id,
            'name' => 'eggl_ph_anchor_data[' . $id . '][price]',
            'label' => __('Base Price', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'data_type' => 'price',
            'value' => Helper::get_price($product) ?? '',
            'description' => __('Enter a date and price, including zero. Clear both fields to remove the base price.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'desc_tip' => true,
            'custom_attributes' => $locked ? ['tabindex' => '-1', 'readonly' => 'readonly'] : [],
        ]);
    }

    /** Croatia presets plus the settings date and any already-stored date, deduplicated, with a custom-date sentinel. */
    private function date_options($product, $stored)
    {
        $options = [];
        foreach (Helper::preset_dates() as $date) {
            $options[$date] = Date_Helper::format_date($date);
        }
        $settings_date = Helper::settings()['date'];
        if ($settings_date !== '') {
            $options[$settings_date] = $options[$settings_date] ?? Date_Helper::format_date($settings_date);
        }
        $selected = $this->preselect($product, $stored, $settings_date);
        if ($selected !== self::CUSTOM) {
            $options[$selected] = $options[$selected] ?? Date_Helper::format_date($selected);
        }
        $options[self::CUSTOM] = __('Custom date…', 'garanlabs-eu-guarantee-notice-durability-labels');
        return [$options, $selected];
    }

    /** Stored data wins; else today for a new/just-created product; else the settings date; else custom/blank. */
    private function preselect($product, $stored, $settings_date)
    {
        if ($stored !== null) {
            return $stored;
        }
        $created = $product->get_date_created();
        $today = new \DateTimeImmutable('today', wp_timezone());
        // An unsaved product (e.g. the "Add new product" screen) has no post_date_gmt yet, hence no date_created.
        if (!$created || $created->getTimestamp() >= $today->getTimestamp()) {
            return $today->format('Y-m-d');
        }
        return $settings_date !== '' ? $settings_date : self::CUSTOM;
    }

    public function save_fields($product)
    {
        if (!Helper::supports($product) || !current_user_can('edit_post', $product->get_id())) {
            return;
        }
        $id = $product->get_id();
        // Validate the nested shape first; unslash and verify the individual nonce below.
        $nonces = $_POST['eggl_ph_anchor_nonce'] ?? []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Validated and unslashed below.
        $submitted = $_POST['eggl_ph_anchor_data'] ?? []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Each field is type-checked, unslashed and strictly validated below.
        if (
            !is_array($nonces) || !is_string($nonces[$id] ?? null)
            || !wp_verify_nonce(wp_unslash($nonces[$id]), 'eggl_ph_anchor_price_' . $id)
            || !is_array($submitted) || !array_key_exists($id, $submitted)
        ) {
            return;
        }
        // A manual save or removal must not be immediately replaced by the recording hook.
        Resolver::skip_for_request($product);
        $input = $submitted[$id];
        if (!is_array($input) || !is_string($input['date_choice'] ?? null) || !is_string($input['date_custom'] ?? null) || !is_string($input['price'] ?? null)) {
            \WC_Admin_Meta_Boxes::add_error(esc_html__('Enter a reference date and base price.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            return;
        }
        $choice = trim(wp_unslash($input['date_choice']));
        $date = $choice === self::CUSTOM ? trim(wp_unslash($input['date_custom'])) : $choice;
        $price = trim(str_replace(wc_get_price_decimal_separator(), '.', wp_unslash($input['price'])));
        if ($date === '' && $price === '') {
            $result = Helper::delete($product);
        } elseif (($date === '' || $price === '') && !Helper::has_stored_data($product)) {
            // A select can't stay unset like a blank text field: an unrelated save of an untouched product
            // must not error just because the date preselected from settings has no matching price yet.
            $result = true;
        } else {
            $result = Helper::set_data($product, $date, $price);
        }
        if (is_wp_error($result)) {
            \WC_Admin_Meta_Boxes::add_error(esc_html($result->get_error_message()));
        }
    }
}
