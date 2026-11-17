<?php

namespace EGGL\PriceHistory\Modules\AnchorPrice;

use EGGL\PriceHistory\Modules\PriceComplianceManager\Registry;

if (!defined('ABSPATH')) {
    exit;
}

/** Registers Anchor Date + Anchor Price with the Price Compliance Manager. */
class Compliance
{
    public function __construct()
    {
        add_action('eggl_ph_price_compliance_manager_register', [self::class, 'register']);
    }

    public static function register()
    {
        Registry::register('anchor_price', [
            'label'         => __('Base Price', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'fields'        => [
                'date'  => ['label' => __('Reference Date', 'garanlabs-eu-guarantee-notice-durability-labels'), 'csv_column' => 'anchor_price_date', 'type' => 'date'],
                'price' => ['label' => __('Base Price', 'garanlabs-eu-guarantee-notice-durability-labels'), 'csv_column' => 'anchor_price', 'type' => 'price'],
            ],
            'supports'      => [Helper::class, 'supports'],
            'version'       => [Helper::class, 'version'],
            'get_values'    => [self::class, 'get_values'],
            'validate'      => [self::class, 'validate'],
            'set_values'    => [self::class, 'set_values'],
            'matches'       => [self::class, 'matches'],
            'set_condition' => [self::class, 'set_condition'],
            'csv_get_values' => [self::class, 'csv_get_values'],
        ]);
    }

    public static function get_values(\WC_Product $product)
    {
        return [
            'date'  => Helper::get_date($product) ?? '',
            'price' => Helper::get_price($product) === null ? '' : Helper::get_price($product),
        ];
    }

    /** Both empty is valid (clears the anchor); otherwise both must parse as a real date and a non-negative price. */
    public static function validate(array $values)
    {
        $date = trim((string) ($values['date'] ?? ''));
        $price = trim((string) ($values['price'] ?? ''));
        if ($date === '' && $price === '') {
            return true;
        }
        $date = Helper::normalize_date($date);
        if (is_wp_error($date)) {
            return $date;
        }
        $price = Helper::normalize_price($price);
        return is_wp_error($price) ? $price : true;
    }

    /** True when the product's stored date/price already equal the given (pre-localization) values. */
    public static function matches(\WC_Product $product, array $values)
    {
        $date = trim((string) ($values['date'] ?? ''));
        $price = trim((string) ($values['price'] ?? ''));
        if ($date === '' && $price === '') {
            return !Helper::has_stored_data($product);
        }
        $date = Helper::normalize_date($date);
        $price = Helper::normalize_price($price);
        return !is_wp_error($date) && !is_wp_error($price) && Helper::get_data($product) === ['date' => $date, 'price' => $price];
    }

    /** Date and price are a pair: both empty clears the anchor, otherwise both are (re)validated and saved together. */
    public static function set_values(\WC_Product $product, array $values)
    {
        if (self::matches($product, $values)) {
            return false;
        }
        $date = trim((string) ($values['date'] ?? ''));
        $price = trim((string) ($values['price'] ?? ''));
        $result = $date === '' && $price === '' ? Helper::delete($product) : Helper::set_data($product, $date, $price);
        return is_wp_error($result) ? $result : true;
    }

    /** Export-only history fallback; editor/import values remain stored-only. */
    public static function csv_get_values(\WC_Product $product)
    {
        return Resolver::resolve($product) ?? self::get_values($product);
    }

    /** Mirrors the pairing rule above in SQL: a complete pair includes zero, but not empty fields. */
    public static function set_condition()
    {
        global $wpdb;
        return $wpdb->prepare("(
            (EXISTS (SELECT 1 FROM %i anchor_date JOIN %i anchor_price ON anchor_price.post_id = anchor_date.post_id
                WHERE anchor_date.post_id = p.ID AND anchor_date.meta_key = %s AND anchor_date.meta_value <> ''
                AND anchor_price.meta_key = %s AND anchor_price.meta_value <> '')
             AND NOT EXISTS (SELECT 1 FROM %i tr JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN %i t ON t.term_id = tt.term_id WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_type' AND t.slug = 'variable'))
            OR EXISTS (SELECT 1 FROM %i v JOIN %i anchor_date ON anchor_date.post_id = v.ID JOIN %i anchor_price ON anchor_price.post_id = v.ID
                WHERE v.post_parent = p.ID AND v.post_type = 'product_variation' AND v.post_status IN ('publish','private','draft')
                AND anchor_date.meta_key = %s AND anchor_date.meta_value <> '' AND anchor_price.meta_key = %s AND anchor_price.meta_value <> '')
        )", $wpdb->postmeta, $wpdb->postmeta, Helper::META_DATE, Helper::META_PRICE,
            $wpdb->term_relationships, $wpdb->term_taxonomy, $wpdb->terms,
            $wpdb->posts, $wpdb->postmeta, $wpdb->postmeta, Helper::META_DATE, Helper::META_PRICE);
    }
}
