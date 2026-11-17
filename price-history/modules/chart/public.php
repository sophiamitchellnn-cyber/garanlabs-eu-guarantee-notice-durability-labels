<?php

namespace EGGL\PriceHistory\Modules\Chart;

use EGGL\PriceHistory\Modules\Chart\Helper;
use EGGL\PriceHistory\Includes\Helper as GlobalHelper;
use EGGL\PriceHistory\Modules\Chart\Chart_Admin;
use EGGL\PriceHistory\Includes\Compatibility\CompatibilityManager;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
class Chart_Public {
    private $module = 'chart';

    private $plugin_name;

    private $full_name;

    private $version;

    private $chart_options;

    private $chart_module;

    private $has_data;

    private $multilingual;

    private $eggl_ph_data;

    private $localized_data;

    private $wrapper_id = 'egglPhWrapper';

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param      string    $plugin_name       The name of the plugin.
     * @param      string    $version    The version of this plugin.
     */
    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->full_name = $this->plugin_name . '-' . $this->module;
        $this->multilingual = EGGL_PH_OPTIONS['general']['multilingual'] ?? false;
        $this->chart_options = EGGL_PH_OPTIONS['chart'];
        $this->chart_module = $this->chart_options['enable_chart'] ?? false;
        $chart_position = $this->chart_options['position'] ?? 'woocommerce_product_meta_start';
        add_action( 'wp_enqueue_scripts', [$this, 'enqueue_styles'] );
        add_action( 'wp_enqueue_scripts', [$this, 'enqueue_scripts'] );
        if ( strpos( $chart_position, 'woocommerce_' ) === 0 ) {
            add_action( $chart_position, [$this, 'echo_price_history_output'] );
        }
        add_action( 'wp_ajax_eggl_ph_variation', [$this, 'get_variation_price_history'] );
        add_action( 'wp_ajax_nopriv_eggl_ph_variation', [$this, 'get_variation_price_history'] );
        add_action( 'wp_ajax_eggl_ph_all_variations', [$this, 'get_all_variations_price_history'] );
        add_action( 'wp_ajax_nopriv_eggl_ph_all_variations', [$this, 'get_all_variations_price_history'] );
    }

    /**
     * Register the stylesheets for the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function enqueue_styles() {
        $name = $this->full_name;
        wp_enqueue_style(
            $name,
            plugin_dir_url( __DIR__ ) . '../assets/build/public-chart.css',
            [],
            $this->version,
            'all'
        );
    }

    /**
     * Register the JavaScript for the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts() {
        $name = $this->full_name;
        $script_asset_path = plugin_dir_url( __DIR__ ) . '../assets/build/public-chart.asset.php';
        $script_info = ( file_exists( $script_asset_path ) ? include $script_asset_path : [
            'dependencies' => ['jquery'],
            'version'      => $this->version,
        ] );
        wp_register_script(
            $name,
            plugin_dir_url( __DIR__ ) . '../assets/build/public-chart.js',
            $script_info['dependencies'],
            $script_info['version'],
            true
        );
        /* translators: 1: time period */
        $text_no_data = __( 'No price changes in %s', 'garanlabs-eu-guarantee-notice-durability-labels' );
        $this->localized_data = [
            'ajaxurl'   => admin_url( 'admin-ajax.php' ),
            'wrapperId' => $this->wrapper_id,
            'strings'   => [
                'price'   => esc_html__( 'Price', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'date'    => esc_html__( 'Date', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'title'   => esc_html__( 'Price History', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'lowest'  => esc_html__( 'Lowest:', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'highest' => esc_html__( 'Highest:', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'average' => esc_html__( 'Average:', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'no_data' => esc_html( $text_no_data ),
            ],
        ];
        // Go ahead only if Chart module enabled.
        if ( $this->chart_module ) {
            $this->eggl_ph_data = $this->single_product_price_history_data();
            // Go ahead and load scripts only if we found entries.
            if ( !empty( $this->eggl_ph_data ) ) {
                $this->has_data = true;
                $this->localized_data['egglPhData'] = $this->eggl_ph_data;
                $this->localized_data['productId'] = get_the_ID();
                wp_enqueue_script( $name );
                wp_localize_script( $name, 'eggl_ph_chart_data', $this->localized_data );
            }
        }
    }

    /**
     * Format pph data for chart.
     *  
     * @since     1.0.0
     */
    public function get_eggl_ph_data_by_id( $product_id, $product = null ) {
        $data = [];
        $date_range = $this->chart_options['date_range'] ?? null;
        $date_range = ( $date_range ?: 'all' );
        $range_selector = [];
        $min_prices_to_display = $this->chart_options['min_prices_to_display'] ?? 1;
        $exclude_above_price = $this->chart_options['exclude_above_price'] ?? null;
        $exclude_below_price = $this->chart_options['exclude_below_price'] ?? null;
        $background_color = $this->chart_options['background_color'] ?? 'transparent';
        $border_color = $this->chart_options['border_color'] ?? null;
        $only_on_variation = $this->chart_options['only_for_variation'] ?? false;
        $text_color = $this->chart_options['text_color'] ?? 'black';
        $type = Chart_Admin::sanitize_type( $this->chart_options['type'] ?? Chart_Admin::defaults( 'type' ) );
        $x_axis_label = 'show_all';
        /**
         * Date object used in JS method `toLocaleString()`
         */
        $date_format_x_axis = [
            'locale' => '',
            'format' => [
                'month' => 'short',
                'day'   => 'numeric',
            ],
        ];
        $date_format_tooltip = [
            'locale' => '',
            'format' => [
                'month' => 'short',
                'day'   => 'numeric',
                'year'  => 'numeric',
            ],
        ];
        $currency = apply_filters(
            'eggl_ph_price_history_currency',
            '',
            $product_id,
            $product
        );
        $price_history = GlobalHelper::get_price_history( $product_id, [
            'hidden'   => false,
            'currency' => $currency,
        ] );
        $data['chartRanges'] = [
            'default' => $date_range,
        ];
        $found_entries = count( $price_history );
        if ( $found_entries < $min_prices_to_display ) {
            return;
        }
        $data['display'] = true;
        if ( $product && $product->is_type( 'variable' ) && $only_on_variation ) {
            $data['display'] = false;
        }
        if ( $product ) {
            foreach ( $price_history as &$entry ) {
                $entry['regular_price'] = wc_get_price_to_display( $product, [
                    'price' => $entry['regular_price'],
                ] );
                $entry['sale_price'] = wc_get_price_to_display( $product, [
                    'price' => $entry['sale_price'],
                ] );
                $entry['price'] = wc_get_price_to_display( $product, [
                    'price' => $entry['price'],
                ] );
            }
        }
        $data['entries'] = $price_history;
        $data['chartSettings'] = [
            'background_color' => $background_color,
            'border_color'     => $border_color,
            'text_color'       => $text_color,
            'type'             => $type,
            'x_axis_label'     => $x_axis_label,
            'date_format'      => [
                'x_axis'  => $date_format_x_axis,
                'tooltip' => $date_format_tooltip,
            ],
            'price_decimals'   => wc_get_price_decimals(),
        ];
        return $data;
    }

    /**
     * Get formatted pph data on single product page.
     *  
     * @since     1.0.0
     */
    public function single_product_price_history_data() {
        $data = [];
        if ( function_exists( 'is_product' ) && is_product() ) {
            global $product;
            if ( empty( $product ) || !is_a( $product, 'WC_Product' ) ) {
                $product = wc_get_product( get_the_id() );
            }
            if ( $product->get_meta( '_eggl_ph_hide_chart' ) ) {
                return;
            }
            $data = $this->get_eggl_ph_data_by_id( $product->get_id(), $product );
        }
        return $data;
    }

    /**
     * output html wrapper where chart will initiate..
     *  
     * @since     1.0.0
     */
    public function price_history_output( $product_id = '' ) {
        if ( $this->chart_module ) {
            $max_width_unit = $this->chart_options['max_width_unit'] ?? '%';
            $bg_color = $this->chart_options['background_color'] ?? 'transparent';
            $max_width = ( isset( $this->chart_options['max_width'] ) ? $this->chart_options['max_width'] . $max_width_unit : '100%' );
            $title = $this->chart_options['title'] ?? Chart_Admin::defaults( 'title' );
            $description = $this->chart_options['description'] ?? Chart_Admin::defaults( 'description' );
            if ( $this->multilingual ) {
                $title = Chart_Admin::defaults( 'title' );
                $description = Chart_Admin::defaults( 'description' );
            }
            // $style = '--eggl-ph-chart--background-color:' . $bg_color . ';';
            $style = '--eggl-ph-chart--max-width:' . $max_width . ';';
            $wrapper_id = $this->wrapper_id;
            if ( $product_id ) {
                $wrapper_id .= '--' . $product_id;
            }
            $display = Chart_Admin::sanitize_display( $this->chart_options['display'] ?? Chart_Admin::defaults( 'display' ) );
            $collapsible = $display !== 'always';
            $html = '<div id="' . esc_attr( $wrapper_id ) . '" class="eggl-ph-wrapper" data-product-id="' . esc_attr( $product_id ) . '" style="' . esc_attr( $style ) . '">';
            // A collapsible chart shows its title on the trigger row instead.
            if ( trim( $title ) && !$collapsible ) {
                $html .= '<span class="eggl-ph-chart-title">' . esc_html( $title ) . '</span>';
            }
            $html .= '</div>';
            if ( !$collapsible ) {
                return $html;
            }
            $toggle_text = ( $this->multilingual ? '' : trim( (string) ($this->chart_options['toggle_text'] ?? '') ) );
            $toggle_text = ( $toggle_text ?: (trim( $title ) ?: Chart_Admin::defaults( 'title' )) );
            $toggle_style = Chart_Admin::sanitize_toggle_style( $this->chart_options['toggle_style'] ?? Chart_Admin::defaults( 'toggle_style' ) );
            return $this->collapsible_output(
                $html,
                $wrapper_id,
                $toggle_text,
                $display === 'open',
                $style,
                $toggle_style
            );
        }
    }

    /**
     * Wraps the chart in the same nested trigger as the legal-guarantee notice
     * (public/views/eggl-notice.php) — the "custom" style's plain icon/text/arrow, or the boxed row —
     * so assets/js/eggl-frontend.js's [data-eggl-toggle] handler opens and closes it. The trigger
     * stays hidden until the chart script marks the chart as shown (see the .eggl-ph-collapsible
     * rule in assets/css/eggl-frontend.css), so it never opens onto an empty panel — e.g. a variable
     * product before a variation is chosen.
     */
    private function collapsible_output(
        $chart_html,
        $wrapper_id,
        $text,
        $open,
        $style,
        $toggle_style
    ) {
        $panel_id = $wrapper_id . '-panel';
        $toggle = 'data-eggl-toggle="' . esc_attr( $panel_id ) . '" aria-controls="' . esc_attr( $panel_id ) . '" aria-expanded="' . (( $open ? 'true' : 'false' )) . '"';
        $icon_path = '<path d="M4 20V10m6 10V4m6 16v-7m4 7H2" stroke="%s" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>';
        if ( $toggle_style === 'row' ) {
            $classes = 'eggl-widget eggl-widget--nested eggl-ph-collapsible' . (( get_option( 'eggl_notice_style', 'minimal' ) === 'classic' ? ' eggl-widget--classic' : '' ));
            $icon = '<svg width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false">' . sprintf( $icon_path, 'var(--eggl-notice-icon,#333c4d)' ) . '</svg>';
            $trigger = '<button type="button" class="eggl-row eggl-row--trigger" ' . $toggle . '>';
            $trigger .= '<span class="eggl-row__icon" aria-hidden="true">' . $icon . '</span>';
            $trigger .= '<span class="eggl-row__text"><strong>' . esc_html( $text ) . '</strong></span>';
            $trigger .= '<span class="eggl-row__btn" aria-hidden="true"></span>';
            $trigger .= '</button>';
        } else {
            $classes = 'eggl-widget eggl-widget--nested eggl-ph-collapsible eggl-ph-collapsible--simple';
            $icon = '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false">' . sprintf( $icon_path, 'currentColor' ) . '</svg>';
            $trigger = '<button type="button" class="eggl-notice-custom-trigger" ' . $toggle . '>';
            $trigger .= '<span class="eggl-notice-custom-trigger__icon" aria-hidden="true">' . $icon . '</span>';
            $trigger .= '<span class="eggl-notice-custom-trigger__text">' . esc_html( $text ) . '</span>';
            $trigger .= '<span class="eggl-notice-custom-trigger__chevron" aria-hidden="true"></span>';
            $trigger .= '</button>';
        }
        $html = '<div class="' . esc_attr( $classes ) . '" style="' . esc_attr( $style ) . '">';
        $html .= $trigger;
        $html .= '<div class="eggl-nested__panel" id="' . esc_attr( $panel_id ) . '"' . (( $open ? '' : ' hidden' )) . '>' . $chart_html . '</div>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Ajax action for fetching variation price history data.
     *  
     * @since     1.0.0
     */
    public function get_variation_price_history() {
        $variation_id = ( isset( $_GET['args']['id'] ) ? intval( $_GET['args']['id'] ) : null );
        $variation_product = wc_get_product( $variation_id );
        $data = $this->get_eggl_ph_data_by_id( $variation_id, $variation_product );
        wp_send_json( $data );
        wp_die();
    }

    /**
     * Ajax action for fetching all variations price history data.
     *  
     * @since     2.5.0
     */
    public function get_all_variations_price_history() {
        $parent_id = ( isset( $_GET['args']['parent_id'] ) ? intval( $_GET['args']['parent_id'] ) : null );
        $args = [
            'parent' => $parent_id,
            'type'   => 'variation',
        ];
        $variations = wc_get_products( $args );
        $output = [];
        foreach ( $variations as $variation ) {
            $variation_id = $variation->get_id();
            $data = $this->get_eggl_ph_data_by_id( $variation_id, $variation );
            $output[$variation_id] = $data['entries'] ?? [];
        }
        wp_send_json( $output );
        wp_die();
    }

    /**
     * 
     * @since   2.5.0
     */
    public function echo_price_history_output() {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped internally.
        echo $this->price_history_output();
    }

}
