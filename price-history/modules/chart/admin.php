<?php

namespace EGGL\PriceHistory\Modules\Chart;

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
class Chart_Admin {
    private $module = 'chart';

    private $plugin_name;

    private $version;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        add_filter( 'eggl_ph_settings_sections', [$this, 'settings_section'] );
        add_filter( 'eggl_ph_settings_fields', [$this, 'settings_fields'] );
    }

    /**
     * Format range_selector options for select2 picker.
     *
     * @since    2.0.0
     */
    static function formatted_range_selector_options() {
        $formatted_options = [];
        $option = EGGL_PH_OPTIONS['chart'];
        $selected = ( isset( $option['range_selector'] ) ? array_flip( $option['range_selector'] ) : [] );
        $options = [
            'all'       => esc_html__( 'All', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            '7_days'    => esc_html__( 'Last 7 days', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            '30_days'   => esc_html__( 'Last 30 days', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            '3_months'  => esc_html__( 'Last 3 months', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            '6_months'  => esc_html__( 'Last 6 months', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            '12_months' => esc_html__( 'Last 12 months', 'garanlabs-eu-guarantee-notice-durability-labels' ),
        ];
        foreach ( $options as $key => $label ) {
            $formatted_options[] = [
                'id'       => $key,
                'text'     => $label,
                'selected' => isset( $selected[$key] ),
            ];
        }
        return $formatted_options;
    }

    /**
     * @since    1.2.0
     */
    public static function defaults( $option_name = '' ) {
        $description_text = '';
        $options = [
            'enable_chart'          => 0,
            'position'              => 'woocommerce_product_meta_start',
            'tab_title'             => esc_html__( 'Price History', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            'tab_priority'          => 100,
            'date_range'            => 'all',
            'range_selector'        => '',
            'min_prices_to_display' => 2,
            'exclude_above_price'   => '',
            'exclude_below_price'   => '',
            'only_for_variation'    => 0,
            'title'                 => esc_html_x( 'Price History', 'Chart title', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            'description'           => $description_text,
            'daily_average'         => 0,
            'type'                  => 'bar',
            'display'               => 'always',
            'toggle_style'          => 'simple',
            'toggle_text'           => '',
            'border_color'          => 'rgba(130,36,227,0.5)',
            'text_color'            => 'rgba(0,0,0,1)',
            'background_color'      => 'rgba(255,255,255,0)',
            'max_width'             => '100',
            'x_axis_label'          => 'show_all',
            'summary_stats'         => '',
        ];
        return $options[$option_name] ?? '';
    }

    /**
     * @since    2.0.0
     */
    public function settings_section( $sections ) {
        $sections[] = [
            'id'    => 'eggl_ph_chart',
            'title' => esc_html__( 'Chart', 'garanlabs-eu-guarantee-notice-durability-labels' ),
        ];
        return $sections;
    }

    /**
     * Chart types the front-end script (assets/build/public-chart.js) can draw.
     */
    public static function types() {
        return [
            'bar'     => esc_html__( 'Bar', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            'line'    => esc_html__( 'Line', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            'stepped' => esc_html__( 'Stepped line', 'garanlabs-eu-guarantee-notice-durability-labels' ),
        ];
    }

    /**
     * How the chart sits on the product page: always open, or behind a collapsible trigger row.
     */
    public static function display_modes() {
        return [
            'always'    => esc_html__( 'Always visible', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            'collapsed' => esc_html__( 'Collapsible – starts closed', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            'open'      => esc_html__( 'Collapsible – starts open', 'garanlabs-eu-guarantee-notice-durability-labels' ),
        ];
    }

    public static function sanitize_display( $value ) {
        return ( is_string( $value ) && isset( self::display_modes()[$value] ) ? $value : self::defaults( 'display' ) );
    }

    public static function toggle_styles() {
        return [
            'simple' => esc_html__( 'Simple – icon, text and arrow', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            'row'    => esc_html__( 'Row – boxed, like the legal-guarantee notice', 'garanlabs-eu-guarantee-notice-durability-labels' ),
        ];
    }

    public static function sanitize_toggle_style( $value ) {
        return ( is_string( $value ) && isset( self::toggle_styles()[$value] ) ? $value : self::defaults( 'toggle_style' ) );
    }

    public static function sanitize_type( $value ) {
        return ( is_string( $value ) && isset( self::types()[$value] ) ? $value : self::defaults( 'type' ) );
    }

    /**
     * @since    2.0.0
     */
    public static function settings_fields( $fields ) {
        $chart = [
            [
                'type'    => 'checkbox',
                'name'    => 'enable_chart',
                'label'   => esc_html__( 'Enable', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'default' => self::defaults( 'enable_chart' ),
            ],
            [
                'type'    => 'select',
                'name'    => 'position',
                'label'   => esc_html__( 'Position', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'options' => [
                    'woocommerce_product_meta_start'           => esc_html__( 'Before product meta (SKU, Category, Tag)', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                    'woocommerce_after_single_product_summary' => esc_html__( 'After product summary', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                    'woocommerce_after_single_product'         => esc_html__( 'After product', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                ],
                'default' => self::defaults( 'position' ),
            ],
            [
                'type'              => 'select',
                'name'              => 'display',
                'label'             => esc_html__( 'Display', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'options'           => self::display_modes(),
                'desc'              => esc_html__( 'Collapsible hides the chart behind a clickable trigger (see Trigger style and Trigger text below); shoppers click it to show or hide the chart.', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'default'           => self::defaults( 'display' ),
                'sanitize_callback' => [self::class, 'sanitize_display'],
            ],
            [
                'type'              => 'select',
                'name'              => 'toggle_style',
                'label'             => esc_html__( 'Trigger style', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'options'           => self::toggle_styles(),
                'desc'              => esc_html__( 'Simple shows just an icon, the text and an arrow, in your theme\'s own text style. Row puts them in a box like the legal-guarantee notice.', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'default'           => self::defaults( 'toggle_style' ),
                'sanitize_callback' => [self::class, 'sanitize_toggle_style'],
            ],
            [
                'type'              => 'text',
                'name'              => 'toggle_text',
                'label'             => esc_html__( 'Trigger text', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'placeholder'       => esc_attr( self::defaults( 'title' ) ),
                'desc'              => esc_html__( 'Text shoppers click to show or hide the chart. Leave empty to use the chart title.', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'default'           => self::defaults( 'toggle_text' ),
                'sanitize_callback' => 'sanitize_text_field',
            ],
            [
                'type'    => 'select',
                'name'    => 'date_range',
                'label'   => esc_html__( 'Date range', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'options' => [
                    'all'       => esc_html__( 'All', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                    '7_days'    => esc_html__( 'Last 7 days', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                    '30_days'   => esc_html__( 'Last 30 days', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                    '3_months'  => esc_html__( 'Last 3 months', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                    '6_months'  => esc_html__( 'Last 6 months', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                    '12_months' => esc_html__( 'Last 12 months', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                ],
                'default' => self::defaults( 'date_range' ),
            ],
            [
                'type'              => 'number',
                'name'              => 'min_prices_to_display',
                'label'             => esc_html__( 'Minimum prices to display', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'step'              => '1',
                'default'           => self::defaults( 'min_prices_to_display' ),
                'sanitize_callback' => 'absint',
            ],
            [
                'type'    => 'checkbox',
                'name'    => 'only_for_variation',
                'label'   => esc_html__( 'Show chart on variable products only when variation is selected', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'default' => self::defaults( 'only_for_variation' ),
            ],
            [
                'type'              => 'select',
                'name'              => 'type',
                'label'             => esc_html__( 'Chart type', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'options'           => self::types(),
                'desc'              => esc_html__( 'Bar shows each recorded price as a column. Line connects the prices; Stepped line holds each price flat until the next change.', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'default'           => self::defaults( 'type' ),
                'sanitize_callback' => [self::class, 'sanitize_type'],
            ],
            [
                'type'              => 'text',
                'name'              => 'title',
                'label'             => esc_html__( 'Title', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'default'           => self::defaults( 'title' ),
                'sanitize_callback' => 'sanitize_text_field',
            ],
            [
                'type'    => 'color',
                'name'    => 'border_color',
                'label'   => esc_html__( 'Graph border color', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'desc'    => esc_html__( 'Colour of the bars, or of the line.', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'default' => self::defaults( 'border_color' ),
            ],
            [
                'type'              => 'number',
                'name'              => 'max_width',
                'label'             => esc_html__( 'Chart maximal width', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'min'               => 0,
                'step'              => '1',
                'default'           => self::defaults( 'max_width' ),
                'sanitize_callback' => 'absint',
            ],
            [
                'type'    => 'select',
                'name'    => 'max_width_unit',
                'label'   => '',
                'options' => [
                    '%'   => '%',
                    'px'  => 'px',
                    'em'  => 'em',
                    'rem' => 'rem',
                    'vw'  => 'vw',
                    'vh'  => 'vh',
                ],
                'class'   => 'attach-units-to--max_width',
                'default' => '%',
            ],
            [
                'type'    => 'color',
                'name'    => 'text_color',
                'label'   => esc_html__( 'Chart text color', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'default' => self::defaults( 'text_color' ),
            ],
            [
                'type'    => 'color',
                'name'    => 'background_color',
                'label'   => esc_html__( 'Chart background color', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'default' => self::defaults( 'background_color' ),
            ],
        ];
        $fields['eggl_ph_chart'] = $chart;
        return $fields;
    }

}
