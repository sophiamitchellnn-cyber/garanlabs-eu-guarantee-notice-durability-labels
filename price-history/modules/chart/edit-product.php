<?php

namespace EGGL\PriceHistory\Modules\Chart;


if (! defined('ABSPATH')) exit;


class Edit_Product
{


    public function __construct()
    {

        add_action('eggl_ph_before_table_wrapper', [$this, 'eggl_ph_note']);
        add_action('eggl_ph_before_editable_table', [$this, 'eggl_ph_hide_chart']);
        add_action('woocommerce_process_product_meta', [$this, 'save_fields'], 10, 2);
    }

    /**
     * Add note to pph tab/panel.
     *  
     * @since     2.3.0
     */
    public function eggl_ph_note($count)
    {
        $options = EGGL_PH_OPTIONS['chart'];
        $min_prices_to_display = $options['min_prices_to_display'] ?? 0;

        if ($min_prices_to_display > $count) {
            echo '<p class="eggl-ph-note">';

            printf(
                /* translators: 1: minimum number of prices required to display the chart, 2: number of recorded price changes */
                esc_html__(
                    'Note that the chart won\'t be visible as you\'ve set Minimum prices to display to %1$d, and there have been %2$d recorded price changes since the plugin activation.',
                    'garanlabs-eu-guarantee-notice-durability-labels'
                ),
                (int) $min_prices_to_display,
                (int) $count
            );

            echo '</p>';
        }
    }


    /**
     * Add options to pph tab/panel.
     *  
     * @since     1.0.0
     */
    public function eggl_ph_hide_chart()
    {
        $id = get_the_ID();

        woocommerce_wp_checkbox([
            'id'          => 'eggl_ph_hide_chart',
            'value'       => get_post_meta($id, '_eggl_ph_hide_chart', true),
            'label'       => esc_html__('Hide chart', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'description' => esc_html__('Don\'t show the price history chart on this product\'s page. Prices are still recorded.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'desc_tip'    => false,
        ]);
    }


    /**
     * Save product fields.
     *  
     * @since     1.0.0
     */
    public function save_fields($id, $post)
    {

        $hide_chart = isset($_POST['eggl_ph_hide_chart']) ? 'yes' : '';
        update_post_meta($id, '_eggl_ph_hide_chart', $hide_chart);
    }
}
