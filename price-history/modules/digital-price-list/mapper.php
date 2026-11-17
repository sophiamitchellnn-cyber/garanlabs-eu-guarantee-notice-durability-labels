<?php

namespace EGGL\PriceHistory\Modules\DigitalPriceList;

use EGGL\PriceHistory\Modules\AnchorPrice\Helper as AnchorPrice;
use EGGL\PriceHistory\Modules\AnchorPrice\Resolver as AnchorResolver;

if (!defined('ABSPATH')) {
    exit;
}

/** Turns a purchasable WC_Product into the public row shape; shared by the CSV generator and REST endpoint (spec §29). Read-only. */
class Product_Mapper
{
    const COLUMNS = ['product_name', 'product_code', 'brand', 'unit', 'unit_price', 'retail_price', 'special_sale', 'special_sale_name', 'anchor_price', 'barcode', 'availability'];
    // Fields a CSV column layout may use (Helper::csv_columns()); the REST row keeps COLUMNS plus these.
    const FIELDS = ['product_name', 'product_code', 'brand', 'net_quantity', 'unit', 'retail_price', 'special_sale', 'special_sale_flag', 'special_sale_name', 'unit_price', 'barcode', 'ean', 'base_price', 'anchor_price', 'availability'];

    /** Prices in the price list always use 2 decimals, whatever the stored precision (e.g. 70.7455 from an ERP sync). */
    public static function money($value)
    {
        if ($value === '' || $value === null || !is_numeric($value)) {
            return '';
        }
        return number_format(round((float) $value, (int) apply_filters('eggl_ph_dpl_price_decimals', 2)), (int) apply_filters('eggl_ph_dpl_price_decimals', 2), '.', '');
    }

    /** Simple products and individual variations only; variable parents are never exported as a row (spec §14). */
    public static function is_exportable(\WC_Product $product)
    {
        if (!$product->is_type(['simple', 'variation'])) {
            return false;
        }
        if (!in_array($product->get_status(), ['publish'], true)) {
            return false;
        }
        // A row without a price (e.g. an unpriced variation, or a parent product's leftover data) isn't a price list entry.
        if ($product->get_price('edit') === '') {
            return false;
        }
        return (bool) apply_filters('eggl_ph_dpl_include_product', true, $product);
    }

    public static function name(\WC_Product $product)
    {
        $name = $product->is_type('variation') ? $product->get_name() : $product->get_title();
        $name = wp_strip_all_tags(html_entity_decode((string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return (string) apply_filters('eggl_ph_dpl_product_name', $name, $product);
    }

    public static function product_code(\WC_Product $product)
    {
        return (string) apply_filters('eggl_ph_dpl_product_code', $product->get_sku('edit'), $product);
    }

    public static function brand(\WC_Product $product)
    {
        $brand = '';
        if (taxonomy_exists('product_brand')) {
            $id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
            $terms = get_the_terms($id, 'product_brand');
            if ($terms && !is_wp_error($terms)) {
                $brand = implode(' | ', wp_list_pluck($terms, 'name'));
            }
        }
        return (string) apply_filters('eggl_ph_dpl_brand', $brand, $product);
    }

    /** Unit of measure for the unit price; source chosen in settings (see Unit_Price). */
    public static function unit(\WC_Product $product)
    {
        return (string) apply_filters('eggl_ph_dpl_unit', Unit_Price::resolve($product)['unit'], $product);
    }

    public static function unit_price(\WC_Product $product)
    {
        return (string) apply_filters('eggl_ph_dpl_unit_price', self::money(Unit_Price::resolve($product)['unit_price']), $product);
    }

    /** Net quantity in the unit of measure, e.g. 10 for a 10 l can (same source as the unit price). */
    public static function net_quantity(\WC_Product $product)
    {
        return (string) apply_filters('eggl_ph_dpl_net_quantity', Unit_Price::resolve($product)['quantity'], $product);
    }

    public static function retail_price(\WC_Product $product)
    {
        $price = $product->get_price('edit');
        return (string) apply_filters('eggl_ph_dpl_retail_price', self::money($price), $product);
    }

    public static function special_sale(\WC_Product $product)
    {
        return (string) apply_filters('eggl_ph_dpl_special_sale', $product->is_on_sale('edit') ? 'yes' : 'no', $product);
    }

    public static function special_sale_name(\WC_Product $product)
    {
        $name = $product->is_on_sale('edit') ? __('sale', 'garanlabs-eu-guarantee-notice-durability-labels') : '';
        return (string) apply_filters('eggl_ph_dpl_special_sale_name', $name, $product);
    }

    public static function anchor_price(\WC_Product $product)
    {
        $price = AnchorPrice::get_price($product) ?? (AnchorResolver::resolve($product)['price'] ?? null);
        return (string) apply_filters('eggl_ph_dpl_anchor_price', self::money($price ?? ''), $product);
    }

    /** "Bazna cijena" under the 2026 Croatian ordinances: the plugin's anchor price under its new name. */
    public static function base_price(\WC_Product $product)
    {
        return (string) apply_filters('eggl_ph_dpl_base_price', self::anchor_price($product), $product);
    }

    /** DA/NE marker for whether the price is a special form of sale. */
    public static function special_sale_flag(\WC_Product $product)
    {
        return (string) apply_filters('eggl_ph_dpl_special_sale_flag', $product->is_on_sale('edit') ? 'DA' : 'NE', $product);
    }

    /**
     * EAN: a configured meta key first; otherwise WooCommerce's GTIN/EAN/UPC/ISBN field when it is a
     * plain 8, 12, 13 or 14 digit code. The Barkod column always shows the WooCommerce field as entered.
     */
    public static function ean(\WC_Product $product)
    {
        $key = Helper::settings()['ean_meta_key'];
        $ean = $key !== '' ? trim((string) $product->get_meta($key, true, 'edit')) : '';
        if ($ean === '' && $key !== '' && $product->is_type('variation')) {
            $ean = trim((string) get_post_meta($product->get_parent_id(), $key, true));
        }
        if ($ean === '') {
            $code = method_exists($product, 'get_global_unique_id') ? trim((string) $product->get_global_unique_id('edit')) : '';
            $ean = preg_match('/^(\d{8}|\d{12,14})$/D', $code) ? $code : '';
        }
        return (string) apply_filters('eggl_ph_dpl_ean', $ean, $product);
    }

    public static function barcode(\WC_Product $product)
    {
        $barcode = method_exists($product, 'get_global_unique_id') ? $product->get_global_unique_id('edit') : '';
        return (string) apply_filters('eggl_ph_dpl_barcode', $barcode, $product);
    }

    public static function availability(\WC_Product $product)
    {
        $available = $product->is_purchasable() && $product->is_in_stock();
        return (string) apply_filters('eggl_ph_dpl_availability', $available ? 'available' : 'unavailable', $product);
    }

    public static function map(\WC_Product $product)
    {
        $row = [
            'product_name'      => self::name($product),
            'product_code'      => self::product_code($product),
            'brand'             => self::brand($product),
            'net_quantity'      => self::net_quantity($product),
            'unit'              => self::unit($product),
            'unit_price'        => self::unit_price($product),
            'retail_price'      => self::retail_price($product),
            'special_sale'      => self::special_sale($product),
            'special_sale_flag' => self::special_sale_flag($product),
            'special_sale_name' => self::special_sale_name($product),
            'anchor_price'      => self::anchor_price($product),
            'base_price'        => self::base_price($product),
            'barcode'           => self::barcode($product),
            'ean'               => self::ean($product),
            'availability'      => self::availability($product),
        ];

        return apply_filters('eggl_ph_dpl_row', $row, $product);
    }
}
