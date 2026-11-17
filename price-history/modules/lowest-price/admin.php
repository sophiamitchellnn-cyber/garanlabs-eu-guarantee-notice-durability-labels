<?php

namespace EGGL\PriceHistory\Modules\LowestPrice;

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
class LP_Admin {
    private $module = 'lowest_price';

    private $plugin_name;

    private $version;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        add_filter( 'eggl_ph_settings_sections', [$this, 'settings_section'] );
        add_filter( 'eggl_ph_settings_fields', [$this, 'settings_fields'] );
        add_action( 'eggl_ph_form_top', [$this, 'panel_description'] );
    }

    /**
     * @since    2.0.0
     */
    public function settings_section( $sections ) {
        $sections[] = [
            'id'     => 'eggl_ph_lowest_price',
            'title'  => esc_html__( 'Lowest Price', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            'parent' => 'eggl_ph_price_compliance',
        ];
        return $sections;
    }

    /**
     * @since    1.2.0
     */
    public static function defaults( $option_name = '' ) {
        $options = [
            'enable_lowest_price'    => 0,
            'only_single'            => 0,
            'only_onsale'            => 1,
            'show_regular_price'     => 1,
            'inherit_regular'        => 1,
            'variable_product_price' => 'range',
            'text'                   => esc_html__( 'Lowest Price in the last 30 days: {lowest_price}', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            'enable_custom'          => 0,
        ];
        return $options[$option_name] ?? '';
    }

    /**
     * @since    2.0.0
     */
    public static function settings_fields( $fields ) {
        $options = [
            [
                'type'    => 'checkbox',
                'name'    => 'enable_lowest_price',
                'label'   => esc_html__( 'Enable', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'default' => self::defaults( 'enable_lowest_price' ),
            ],
            [
                'type'    => 'checkbox',
                'name'    => 'only_single',
                'label'   => esc_html__( 'Only on product page', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'default' => self::defaults( 'only_single' ),
            ],
            [
                'type'    => 'checkbox',
                'name'    => 'only_onsale',
                'label'   => esc_html__( 'Only when product on-sale', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'default' => self::defaults( 'only_onsale' ),
            ],
            [
                'type'    => 'checkbox',
                'name'    => 'inherit_regular',
                'label'   => esc_html__( 'Inherit from regular price', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'desc'    => esc_html__( 'When insufficient price history information is available, the regular price will be displayed as the lowest price.', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'default' => self::defaults( 'inherit_regular' ),
            ],
            [
                'type'    => 'select',
                'name'    => 'variable_product_price',
                'label'   => esc_html__( 'Variable product price', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'options' => [
                    'range' => esc_html__( 'Range (min - max)', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                    'min'   => esc_html__( 'Min', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                    'max'   => esc_html__( 'Max', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                    'none'  => esc_html__( 'Don\'t display', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                ],
                'default' => self::defaults( 'range' ),
            ],
            [
                'type'    => 'select',
                'name'    => 'show_regular_price',
                'label'   => esc_html__( 'Regular price', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'options' => [
                    ''                  => esc_html__( 'Don\'t display', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                    '1'                 => esc_html__( 'Show regular price', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                    'swap_lowest_notxt' => esc_html__( 'Swap with lowest price — no message', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                    'swap_lowest_txt'   => esc_html__( 'Swap with lowest price — include message', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                ],
                'desc'    => esc_html__( 'Applies only when the product is on sale. Controls whether to show the regular price or replace it with the lowest price from the last 30 days, with optional message settings', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'default' => self::defaults( 'show_regular_price' ),
            ],
            [
                'type'              => 'text',
                'name'              => 'text',
                'label'             => esc_html__( 'Text', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'desc'              => esc_html__( 'Placeholder for lowest price {lowest_price}', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'default'           => self::defaults( 'text' ),
                'sanitize_callback' => 'wp_filter_post_kses',
            ],
        ];
        $fields['eggl_ph_lowest_price'] = $options;
        return $fields;
    }

    /**
     * Output description text under panel title.
     * 
     * @since   2.3.0
     */
    public function panel_description( $form ) {
        $id = $form['id'] ?? '';
        if ( 'eggl_ph_lowest_price' === $id ) {
            echo '<div class="eggl-ph-plugin-panel-description">';
            echo '<p>';
            echo wp_kses_post( __( "The new Consumer Protection Act officially took effect in European Union (EU) on May 28, 2022, as published in Official Gazette No. 19/2022 The Act aims to enhance consumer protection by addressing unfair practices and modernizing rules. Key changes focus on price display for goods and services, with specific regulations for special forms of sales like special offers, sell-outs, and discounts.<br>\n            For special sales, prices must be displayed in two ways: <br>the current price during the sale period and <strong>the lowest price applied for the same goods in the 30 days prior to the sale's initiation.</strong> Traders are allowed to use percentages, percentage values, or cross out the previous price, as long as both the current and lowest prices are clearly presented numerically.<br><br><em>The content of this information is not intended as legal advice and should not be interpreted as such. If you have additional questions or find any aspect unclear, please consult with your regular legal advisor for further clarification.</em>", 'garanlabs-eu-guarantee-notice-durability-labels' ) );
            echo '</p>';
            echo '</div>';
        }
    }

}
