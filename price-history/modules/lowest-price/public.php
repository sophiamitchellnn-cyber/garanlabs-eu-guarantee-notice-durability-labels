<?php

namespace EGGL\PriceHistory\Modules\LowestPrice;

use EGGL\PriceHistory\Modules\LowestPrice\Helper;
use EGGL\PriceHistory\Modules\LowestPrice\LP_Admin;


if (! defined('ABSPATH')) exit;


class LP_Public
{

    private $module = 'lowest_price';

    private $plugin_name;
    private $version;
    private $multilingual;
    private $lowest_price_module;
    private $lowest_price_options;

    public function __construct($plugin_name, $version)
    {

        $this->plugin_name = $plugin_name;
        $this->version = $version;

        $general_options = EGGL_PH_OPTIONS['general'];

        $this->multilingual = $general_options['multilingual'] ?? false;

        $this->lowest_price_options = EGGL_PH_OPTIONS['lowest_price'];
        $this->lowest_price_module = $this->lowest_price_options['enable_lowest_price'] ?? false;

        add_filter('woocommerce_get_price_html', [$this, 'price_html'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }


    /**
     * Register the JavaScript for the public-facing side of the site.
     *
     * @since    2.6.4
     */
    public function enqueue_scripts()
    {
        // Also needed by Anchor Price to place variation notices.
        if (!$this->lowest_price_module && empty(EGGL_PH_OPTIONS['anchor_price']['enable'])) return;

        $script_asset_path = plugin_dir_path(dirname(__DIR__)) . 'assets/build/public.asset.php';
        $script_info       = file_exists($script_asset_path)
            ? include $script_asset_path
            : ['dependencies' => [], 'version' => $this->version];

        $dependencies = array_unique(array_merge($script_info['dependencies'], ['jquery']));

        wp_enqueue_script(
            $this->plugin_name . '-' . $this->module,
            plugin_dir_url(dirname(__DIR__)) . 'assets/build/public.js',
            $dependencies,
            $script_info['version'],
            true
        );

        wp_enqueue_style(
            $this->plugin_name . '-' . $this->module,
            plugin_dir_url(dirname(__DIR__)) . 'assets/build/public.css',
            [],
            $script_info['version']
        );
    }


    /**
     * Price html filter for adding lowest price info.
     *  
     * @since     1.0.0
     */
    public function price_html($price_html, $product)
    {
        if (is_admin() && !wp_doing_ajax()) {
            return $price_html;
        }

        $is_enabled = $this->lowest_price_options['enable_lowest_price'] ?? false;
        $only_single = $this->lowest_price_options['only_single'] ?? false;
        $price_display = $this->lowest_price_options['variable_product_price'] ?? LP_Admin::defaults('variable_product_price');
        $only_onsale = $this->lowest_price_options['only_onsale'] ?? true;
        $show_regular_price = $this->lowest_price_options['show_regular_price'] ?? true;
        $text = $this->lowest_price_options['text'] ?? LP_Admin::defaults('text');
        $variation_ids = [];

        if (!$is_enabled) {
            return $price_html;
        }

        $product_type = $product->get_type();
        $is_variable  = 'variable' === $product_type;
        $is_variation = 'variation' === $product_type;

        if ($only_single) {

            // Single product page.
            if (is_product()) {

                $post_id = get_queried_object_id();

                $main_product_on_page = $product->get_id() === $post_id;
                $related_to_main_product = $is_variation && $product->get_parent_id() === $post_id;

                if (!$related_to_main_product && !$main_product_on_page) {
                    return $price_html;
                }
            } else {
                return $price_html;
            }
        }


        if ($only_onsale && !$product->is_on_sale()) {
            return $price_html;
        }

        if ($is_variable && $price_display === 'none') {
            return $price_html;
        }

        if ($this->multilingual) {
            $text = LP_Admin::defaults('text');
        }

        $lowest_price = Helper::get_lowest_price($product->get_id(), $product);

        $swap_mode = $show_regular_price; // easier to read

        $should_swap =
            $swap_mode === 'swap_lowest_notxt' ||
            $swap_mode === 'swap_lowest_txt';

        $show_text =
            $swap_mode === 'swap_lowest_txt';

        // If swapping regular price with lowest price
        if ($should_swap && $lowest_price) {

            // Replace regular price with lowest price (striked-out)
            $swapped_regular = wc_price(
                wc_get_price_to_display($product, ['price' => $lowest_price])
            );

            $price_html = wc_format_sale_price(
                $swapped_regular,
                wc_price(wc_get_price_to_display($product))
            );

            // If swap mode says "no text", skip message entirely
            if (!$show_text) {
                return $price_html;
            }

            // Otherwise continue to lowest price message block below
        }

        $formatted_lp_range = '';

        if ($lowest_price) {

            if ($is_variable) {

                $var_min_price = $product->get_variation_price('min', true);
                $var_max_price = $product->get_variation_price('max', true);

                if ($var_min_price === $var_max_price && !$show_regular_price) {
                    $product_price_html = wc_price(wc_get_price_to_display($product));
                    $price_html = apply_filters('eggl_ph_price_html_before_lowest_price_html', $product_price_html, $product);
                }

                $variation_ids = $product->get_children();

                $vplp_info = Helper::get_variable_product_lowest_price_info($variation_ids);

                // Skip this block if min and max are the same, then we'll show just one price.
                if (($vplp_info['min'] && $vplp_info['max']) && ($vplp_info['min'] !== $vplp_info['max'])) {

                    if ($price_display === 'min') {
                        $lowest_price = $vplp_info['min'];
                    } elseif ($price_display === 'max') {
                        $lowest_price = $vplp_info['max'];
                    } else {
                        // Show price range (min - max) and format right away to include/exclude taxes.
                        $formatted_lp_range = wc_price(wc_get_price_to_display($product, ['price' => $vplp_info['min']]));
                        $formatted_lp_range .= ' - ';
                        $formatted_lp_range .= wc_price(wc_get_price_to_display($product, ['price' => $vplp_info['max']]));
                    }
                }
            } else {

                if (!$show_regular_price) {
                    // wc_get_price_to_display() returns the price including or excluding tax, 
                    // based on the ‘woocommerce_tax_display_shop’ setting.
                    // Here we want to remove striked out regular price.
                    $product_price_html = wc_price(wc_get_price_to_display($product));
                    $price_html = apply_filters('eggl_ph_price_html_before_lowest_price_html', $product_price_html, $product);
                }
            }

            $formatted_lowest_price = $formatted_lp_range ? $formatted_lp_range : wc_price(wc_get_price_to_display($product, ['price' => $lowest_price]));

            $lowest_price_html = str_replace('{lowest_price}', $formatted_lowest_price, $text);

            $wrapper_tag = apply_filters('eggl_ph_lowest_price_wrapper_tag', 'div');

            // Allow only div or span
            $wrapper_tag = in_array($wrapper_tag, ['div', 'span', 'p', 'small'], true)
                ? $wrapper_tag
                : 'div';

            $wrapper_class = 'eggl-ph-lowest-price';

            if ($is_variable && in_array($price_display, ['range', 'min', 'max'], true)) {
                $wrapper_class .= ' eggl-ph-lowest-price--variable-parent';
            }

            $price_html .= sprintf(
                '<%1$s class="%2$s">%3$s</%1$s>',
                esc_attr($wrapper_tag),
                esc_attr($wrapper_class),
                $lowest_price_html
            );
        }

        return $price_html;
    }
}
