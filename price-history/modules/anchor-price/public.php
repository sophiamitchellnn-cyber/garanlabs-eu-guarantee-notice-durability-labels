<?php

namespace EGGL\PriceHistory\Modules\AnchorPrice;

use EGGL\PriceHistory\Includes\Helper as Date_Helper;

if (!defined('ABSPATH')) {
    exit;
}

class AP_Public
{
    public function __construct()
    {
        // Keep Lowest Price's existing priority-10 output before the anchor.
        add_filter('woocommerce_get_price_html', [$this, 'append_price'], 20, 2);
        add_filter('woocommerce_show_variation_price', [$this, 'show_variation_price'], 20, 3);
    }

    public static function price_html(\WC_Product $product)
    {
        $message = Helper::settings()['message'];
        if ($message === '') {
            return '';
        }
        $is_variable = $product->is_type('variable');
        // A variable product shows its variations' anchors: one value when they match, a min-max range otherwise.
        $sources = $is_variable ? array_filter(array_map('wc_get_product', $product->get_visible_children())) : [$product];
        $dates = $prices = [];
        foreach ($sources as $source) {
            $data = Helper::get_data($source);
            if ($data) {
                $dates[] = $data['date'];
                $prices[] = (float) wc_get_price_to_display($source, ['price' => $data['price']]);
            }
        }
        if (!$prices) {
            return '';
        }
        // Y-m-d strings sort chronologically.
        $date = esc_html(Date_Helper::format_date(min($dates)));
        if (min($dates) !== max($dates)) {
            $date .= ' &ndash; ' . esc_html(Date_Helper::format_date(max($dates)));
        }
        $html = strtr(esc_html($message), [
            '{date}' => $date,
            '{price}' => min($prices) === max($prices) ? wc_price(min($prices)) : wc_format_price_range(min($prices), max($prices)),
        ]);
        $class = 'eggl-ph-anchor-price' . ($is_variable ? ' eggl-ph-anchor-price--variable-parent' : '');
        return '<div class="' . $class . '" style="display:block"><span>' . $html . '</span></div>';
    }

    public function append_price($price_html, $product)
    {
        if ((is_admin() && !wp_doing_ajax()) || !(Helper::supports($product) || $product->is_type('variable'))) {
            return $price_html;
        }
        if (!self::visible_here($product)) {
            return $price_html;
        }
        return $price_html . self::price_html($product);
    }

    public function show_variation_price($show, $parent, $variation)
    {
        if (!Helper::settings()['show_single']) {
            return $show;
        }
        return $show || self::price_html($variation) !== '';
    }

    /** Single product page: gated by "show_single". Everything else (archives, loops, widgets): "show_archive". */
    private static function visible_here(\WC_Product $product)
    {
        $settings = Helper::settings();
        if (is_product()) {
            $post_id = get_queried_object_id();
            $is_main = $product->get_id() === $post_id || ($product->is_type('variation') && $product->get_parent_id() === $post_id);
            return $is_main ? $settings['show_single'] : $settings['show_archive'];
        }
        // Variations of large variable products are loaded via wc-ajax=get_variation, where is_product() is false.
        if ($product->is_type('variation') && wp_doing_ajax()) {
            return $settings['show_single'];
        }
        return $settings['show_archive'];
    }
}
