<?php

namespace EGGL\PriceHistory\Modules\DigitalPriceList;

use EGGL\PriceHistory\Modules\PriceComplianceManager\Registry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the price list's `unit` and `unit_price` columns (price per unit of measure).
 *
 * Order, first match wins:
 * 1. The product's own Unit / Unit Quantity fields (product edit screen, Price Compliance Manager, CSV import).
 * 2. The configured source: Price Per Unit PRO meta, or custom meta keys (Auto tries both, in that order).
 * 3. Product weight and the shop weight unit, only when the weight fallback is enabled.
 *
 * Variations without their own values inherit them from the parent product at each step.
 */
class Unit_Price
{
    const META_UNIT = '_eggl_ph_unit';
    const META_QUANTITY = '_eggl_ph_unit_quantity';
    const SOURCES = ['auto', 'ppu', 'meta', 'none'];

    // Price Per Unit PRO (Mechcomp) per-product meta.
    const PPU_UNITS = '_mcmp_ppu_cust_num_of_units_override';
    const PPU_SUFFIX = '_mcmp_ppu_recalc_text_override';
    const PPU_CUSTOM_UNIT = '_mcmp_ppu_custom_unit_override';
    const PPU_RATIO = '_mcmp_ppu_ratio_unit_override';

    public function __construct()
    {
        if (!Helper::settings()['enable']) {
            return;
        }
        add_action('woocommerce_product_options_general_product_data', [$this, 'simple_product_fields']);
        add_action('woocommerce_variation_options_pricing', [$this, 'variation_fields'], 30, 3);
        add_action('woocommerce_admin_process_product_object', [$this, 'save_fields']);
        add_action('woocommerce_admin_process_variation_object', [$this, 'save_fields']);
        add_action('eggl_ph_price_compliance_manager_register', [self::class, 'register']);
    }

    /* ---------- Resolution ---------- */

    /** @return array{unit: string, unit_price: string, quantity: string} */
    public static function resolve(\WC_Product $product)
    {
        $empty = ['unit' => '', 'unit_price' => '', 'quantity' => ''];
        $price = $product->get_price('edit');
        if ($price === '' || !is_numeric($price)) {
            return $empty;
        }
        $price = (float) $price;
        $settings = Helper::settings();

        $candidates = [[self::class, 'from_own_fields']];
        $source = $settings['unit_source'];
        if ($source === 'auto' || $source === 'ppu') {
            $candidates[] = [self::class, 'from_ppu'];
        }
        if ($source === 'auto' || $source === 'meta') {
            $candidates[] = [self::class, 'from_custom_meta'];
        }
        if ($settings['unit_weight_fallback']) {
            $candidates[] = [self::class, 'from_weight'];
        }

        foreach ($candidates as $candidate) {
            $found = call_user_func($candidate, $product, $settings);
            if (!$found) {
                continue;
            }
            // A direct unit price (custom meta) is used as-is; otherwise price ÷ quantity × ratio.
            if (isset($found['unit_price'])) {
                $unit_price = $found['unit_price'];
            } elseif (!empty($found['quantity']) && $found['quantity'] > 0) {
                $unit_price = $price / $found['quantity'] * ($found['ratio'] ?? 1);
            } else {
                continue;
            }
            return [
                'unit'       => $found['unit'],
                'unit_price' => wc_format_decimal($unit_price, wc_get_price_decimals()),
                'quantity'   => empty($found['quantity']) ? '' : wc_format_decimal($found['quantity']),
            ];
        }

        return $empty;
    }

    /** Reads a meta value from the product, falling back to the parent product for a variation. */
    private static function meta(\WC_Product $product, $key)
    {
        if ($key === '') {
            return '';
        }
        $value = $product->get_meta($key, true, 'edit');
        if (($value === '' || $value === null) && $product->is_type('variation')) {
            $value = get_post_meta($product->get_parent_id(), $key, true);
        }
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** Accepts 10, 10.5 and 10,5. Returns null for anything that isn't a positive number. */
    public static function number($value)
    {
        $value = str_replace([' ', ','], ['', '.'], trim((string) $value));
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    /** "/l", "/ l", "per l" style suffixes become a plain unit name. */
    public static function clean_unit($unit)
    {
        $unit = trim(wp_strip_all_tags(html_entity_decode((string) $unit, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return trim(ltrim($unit, '/'));
    }

    private static function from_own_fields(\WC_Product $product)
    {
        $unit = self::clean_unit(self::meta($product, self::META_UNIT));
        $quantity = self::number(self::meta($product, self::META_QUANTITY));
        return $unit !== '' && $quantity ? ['unit' => $unit, 'quantity' => $quantity] : null;
    }

    /**
     * Price Per Unit PRO: quantity from "custom number of units", unit from "custom unit name", else from the
     * recalculation suffix (e.g. "/l" → "l"). Mirrors what the product page shows; weight is never used here.
     */
    private static function from_ppu(\WC_Product $product)
    {
        $quantity = self::number(self::meta($product, self::PPU_UNITS));
        $unit = self::clean_unit(self::meta($product, self::PPU_CUSTOM_UNIT));
        if ($unit === '') {
            $unit = self::clean_unit(self::meta($product, self::PPU_SUFFIX));
        }
        $found = $quantity && $unit !== '' ? ['unit' => $unit, 'quantity' => $quantity, 'ratio' => self::number(self::meta($product, self::PPU_RATIO)) ?? 1] : null;
        return apply_filters('eggl_ph_dpl_ppu_unit_price', $found, $product);
    }

    private static function from_custom_meta(\WC_Product $product, $settings)
    {
        $unit = self::clean_unit(self::meta($product, $settings['unit_meta_unit']));
        if ($unit === '') {
            return null;
        }
        $direct = self::number(self::meta($product, $settings['unit_meta_price']));
        if ($direct) {
            return ['unit' => $unit, 'unit_price' => $direct];
        }
        $quantity = self::number(self::meta($product, $settings['unit_meta_quantity']));
        return $quantity ? ['unit' => $unit, 'quantity' => $quantity, 'ratio' => $settings['unit_ratio']] : null;
    }

    private static function from_weight(\WC_Product $product)
    {
        $weight = self::number($product->get_weight('edit'));
        if (!$weight && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            $weight = $parent ? self::number($parent->get_weight('edit')) : null;
        }
        return $weight ? ['unit' => (string) get_option('woocommerce_weight_unit', 'kg'), 'quantity' => $weight] : null;
    }

    /* ---------- Product edit fields ---------- */

    public static function supports($product)
    {
        return $product instanceof \WC_Product && $product->is_type(['simple', 'variation']);
    }

    public function simple_product_fields()
    {
        $this->fields(wc_get_product(get_the_ID()));
    }

    public function variation_fields($loop, $variation_data, $variation)
    {
        $this->fields(wc_get_product($variation->ID));
    }

    private function fields($product)
    {
        if (!self::supports($product)) {
            return;
        }
        $id = $product->get_id();
        $variation = $product->is_type('variation');
        echo '<div class="options_group eggl-ph-unit-fields">';
        wp_nonce_field('eggl_ph_unit_' . $id, 'eggl_ph_unit_nonce[' . $id . ']', false);
        woocommerce_wp_text_input([
            'id'            => 'eggl_ph_unit_' . $id,
            'name'          => 'eggl_ph_unit_data[' . $id . '][unit]',
            'label'         => __('Price List Unit', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'value'         => $product->get_meta(self::META_UNIT, true, 'edit'),
            'placeholder'   => 'l, kg, m²',
            'wrapper_class' => $variation ? 'form-row form-row-first' : '',
            'description'   => __('Unit of measure for the digital price list unit price, e.g. l, kg, m. Leave empty to use the source chosen in the Digital Price List settings.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'desc_tip'      => true,
        ]);
        woocommerce_wp_text_input([
            'id'            => 'eggl_ph_unit_quantity_' . $id,
            'name'          => 'eggl_ph_unit_data[' . $id . '][quantity]',
            'label'         => __('Price List Unit Quantity', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'value'         => $product->get_meta(self::META_QUANTITY, true, 'edit'),
            'placeholder'   => '10',
            'wrapper_class' => $variation ? 'form-row form-row-last' : '',
            'custom_attributes' => ['inputmode' => 'decimal'],
            'description'   => __('How many units the product contains, e.g. 10 for a 10 l can. Unit price = price ÷ quantity.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'desc_tip'      => true,
        ]);
        echo '</div>';
    }

    public function save_fields($product)
    {
        if (!self::supports($product) || !current_user_can('edit_post', $product->get_id())) {
            return;
        }
        $id = $product->get_id();
        $nonces = $_POST['eggl_ph_unit_nonce'] ?? []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Validated and unslashed below.
        $submitted = $_POST['eggl_ph_unit_data'] ?? []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Type-checked and sanitized below.
        if (
            !is_array($nonces) || !is_string($nonces[$id] ?? null)
            || !wp_verify_nonce(wp_unslash($nonces[$id]), 'eggl_ph_unit_' . $id)
            || !is_array($submitted) || !is_array($submitted[$id] ?? null)
        ) {
            return;
        }
        $values = [
            'unit'     => is_string($submitted[$id]['unit'] ?? null) ? wp_unslash($submitted[$id]['unit']) : '',
            'quantity' => is_string($submitted[$id]['quantity'] ?? null) ? wp_unslash($submitted[$id]['quantity']) : '',
        ];
        $valid = self::validate($values);
        if (is_wp_error($valid)) {
            \WC_Admin_Meta_Boxes::add_error(esc_html($valid->get_error_message()));
            return;
        }
        self::set_values($product, $values);
    }

    /* ---------- Price Compliance Manager (editor + CSV import/export) ---------- */

    public static function register()
    {
        Registry::register('unit_price', [
            'label'      => __('Price List Unit', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'fields'     => [
                'unit'     => ['label' => __('Unit', 'garanlabs-eu-guarantee-notice-durability-labels'), 'csv_column' => 'unit', 'type' => 'text'],
                'quantity' => ['label' => __('Unit Quantity', 'garanlabs-eu-guarantee-notice-durability-labels'), 'csv_column' => 'unit_quantity', 'type' => 'price'],
            ],
            'supports'   => [self::class, 'supports'],
            'version'    => [self::class, 'version'],
            'get_values' => [self::class, 'get_values'],
            'validate'   => [self::class, 'validate'],
            'set_values' => [self::class, 'set_values'],
            'matches'    => [self::class, 'matches'],
        ]);
    }

    public static function get_values(\WC_Product $product)
    {
        return [
            'unit'     => (string) $product->get_meta(self::META_UNIT, true, 'edit'),
            'quantity' => (string) $product->get_meta(self::META_QUANTITY, true, 'edit'),
        ];
    }

    public static function version(\WC_Product $product)
    {
        return hash('sha256', wp_json_encode(self::get_values($product)));
    }

    private static function normalize(array $values)
    {
        $quantity = trim((string) ($values['quantity'] ?? ''));
        $number = self::number($quantity);
        return [
            'unit'     => sanitize_text_field(self::clean_unit($values['unit'] ?? '')),
            'quantity' => $quantity === '' ? '' : ($number ? wc_format_decimal($number) : $quantity),
        ];
    }

    /** Both empty clears the override; otherwise both are required and the quantity must be a positive number. */
    public static function validate(array $values)
    {
        $values = self::normalize($values);
        if ($values['unit'] === '' && $values['quantity'] === '') {
            return true;
        }
        if ($values['unit'] === '' || $values['quantity'] === '') {
            return new \WP_Error('eggl_ph_unit_pair', __('Enter both a price list unit and a unit quantity, or leave both empty.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        if (!self::number($values['quantity'])) {
            return new \WP_Error('eggl_ph_unit_quantity', __('The unit quantity must be a number greater than zero.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        return true;
    }

    public static function matches(\WC_Product $product, array $values)
    {
        return self::get_values($product) === self::normalize($values);
    }

    public static function set_values(\WC_Product $product, array $values)
    {
        $valid = self::validate($values);
        if (is_wp_error($valid)) {
            return $valid;
        }
        if (self::matches($product, $values)) {
            return false;
        }
        $values = self::normalize($values);
        if ($values['unit'] === '') {
            $product->delete_meta_data(self::META_UNIT);
            $product->delete_meta_data(self::META_QUANTITY);
        } else {
            $product->update_meta_data(self::META_UNIT, $values['unit']);
            $product->update_meta_data(self::META_QUANTITY, $values['quantity']);
        }
        return true;
    }
}
