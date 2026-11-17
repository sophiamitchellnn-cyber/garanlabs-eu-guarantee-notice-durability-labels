<?php

namespace EGGL\PriceHistory\Admin;

use EGGL\PriceHistory\Includes\Helper;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
class Settings {
    private $settings_api;

    public function __construct() {
        $this->settings_api = new Settings_API('eggl_ph');
        $this->admin_init();
    }

    public function admin_init() {
        $page = ( isset( $_REQUEST['page'] ) ? sanitize_text_field( $_REQUEST['page'] ) : '' );
        $option_page = ( isset( $_REQUEST['option_page'] ) ? sanitize_text_field( $_REQUEST['option_page'] ) : '' );
        $is_settings_page = $page === 'eggl-price-history';
        // When saving options.
        $is_option_page = !empty( $option_page ) && strpos( $option_page, 'eggl_ph' ) === 0;
        if ( !$is_settings_page && !$is_option_page ) {
            return;
        }
        //set the settings
        $this->settings_api->set_sections( $this->get_settings_sections() );
        $this->settings_api->set_fields( $this->get_settings_fields() );
        //initialize settings
        $this->settings_api->admin_init();
        // options.php defaults to manage_options; let whoever can open the screen (shop managers) save it.
        foreach ( $this->get_settings_sections() as $section ) {
            add_filter( 'option_page_capability_' . $section['id'], ['\\EGGL_Settings', 'capability'] );
        }
        // Calling it from here to avoid unnecessary code execution.
        add_action( 'eggl_ph_form_top', [$this, 'panel_description'] );
    }

    public function get_settings_sections() {
        $sections[] = [
            'id'    => 'eggl_ph_general',
            'title' => esc_html__( 'General', 'garanlabs-eu-guarantee-notice-durability-labels' ),
        ];
        $sections[] = [
            'id'    => 'eggl_ph_price_compliance',
            'title' => esc_html__( 'Price Compliance', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            'tabs'  => true,
        ];
        return apply_filters( 'eggl_ph_settings_sections', $sections );
    }

    /**
     * Returns all the settings fields
     *
     * @return array settings fields
     */
    public function get_settings_fields() {
        $settings_fields = [
            'eggl_ph_general' => [
                [
                    'type'    => 'checkbox',
                    'name'    => 'multilingual',
                    'label'   => esc_html__( 'Multilingual', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                    'desc'    => esc_html__( 'Use your own translated strings.', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                    'default' => 0,
                ],
                [
                    'type'    => 'checkbox',
                    'name'    => 'delete_plugin_data',
                    'label'   => esc_html__( 'Delete all price history data on uninstall', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                    'desc'    => esc_html__( 'When the plugin is deleted, also remove the Price History settings and every recorded price history entry from the database.', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                    'default' => 0,
                ]
            ],
        ];
        return apply_filters( 'eggl_ph_settings_fields', $settings_fields );
    }

    public function settings_page() {
        $tabs = $this->settings_api->get_tab_sections();
        if ( empty( $tabs ) ) {
            return;
        }
        // Tabs are addressed without the option prefix: ?tab=lowest_price → eggl_ph_lowest_price.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch, no state change.
        $requested = ( isset( $_GET['tab'] ) ? 'eggl_ph_' . sanitize_key( wp_unslash( $_GET['tab'] ) ) : '' );
        $current = ( isset( $tabs[$requested] ) ? $requested : array_key_first( $tabs ) );
        $base_url = admin_url( 'admin.php?page=' . PPH_Admin::PAGE_SLUG );
        $intros = [
            'eggl_ph_general' => __( 'Price changes are recorded automatically for every product and variation. These settings apply to the whole module.', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            'eggl_ph_chart'   => __( 'A price history chart on the product page. Hide it for a single product in that product\'s Product Price History tab.', 'garanlabs-eu-guarantee-notice-durability-labels' ),
        ];
        // The eggl-ph-plugin-settings-page class and data-id are what assets/build/admin.js looks for
        // before it sets up the colour pickers.
        echo '<div class="wrap eggl-settings eggl-ph-settings eggl-ph-plugin-settings-page" data-id="eggl_ph">';
        echo '<div class="eggl-page-header">';
        echo '<h1>' . esc_html__( 'Price History', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '</h1>';
        echo '<p class="eggl-lede">' . esc_html__( 'Record every WooCommerce price change and show the EU Omnibus lowest price from the last 30 days, a price history chart, and the base price and digital price list required in Croatia. This module is a display helper and does not provide legal advice or guarantee compliance.', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '</p>';
        echo '</div>';
        // options.php records a "Settings saved." notice on every save.
        settings_errors();
        echo '<div class="eggl-tabs">';
        foreach ( $tabs as $id => $tab ) {
            $url = add_query_arg( 'tab', substr( $id, strlen( 'eggl_ph_' ) ), $base_url );
            echo '<a href="' . esc_url( $url ) . '" class="eggl-tabs__item' . (( $id === $current ? ' is-active' : '' )) . '">' . esc_html( $tab['title'] ) . '</a>';
        }
        echo '</div>';
        $intro = ( isset( $intros[$current] ) ? '<p>' . esc_html( $intros[$current] ) . '</p>' : '' );
        $this->settings_api->show_form( $tabs[$current], $intro );
        echo '</div>';
        $this->dependency_script();
    }

    /**
     * Greys out rows that the current choices make irrelevant: the chart's trigger settings unless
     * the chart is collapsible, and (what admin.js does for the original table layout) Lowest
     * Price's "Regular price" unless "Only when product on-sale" is on, and its text when the lowest
     * price replaces the regular price without text.
     */
    private function dependency_script() {
        ?>
        <script>
        (function() {
            // Chart: the trigger settings only apply to a collapsible chart.
            var display = document.getElementById('eggl_ph_chart[display]');
            if (display) {
                var triggerRows = ['toggle_style', 'toggle_text'].map(function(id) {
                    var el = document.getElementById('eggl_ph_chart[' + id + ']');
                    return el ? el.closest('.eggl-row') : null;
                }).filter(Boolean);
                var syncDisplay = function() {
                    triggerRows.forEach(function(row) { row.classList.toggle('is-disabled', display.value === 'always'); });
                };
                display.addEventListener('change', syncDisplay);
                syncDisplay();
            }

            var onsale = document.getElementById('eggl_ph_lowest_price[only_onsale]');
            var regular = document.getElementById('eggl_ph_lowest_price[show_regular_price]');
            if (!onsale || !regular) return;
            var regularRow = regular.closest('.eggl-row');
            var textInput = document.getElementById('eggl_ph_lowest_price[text]');
            var textRow = textInput ? textInput.closest('.eggl-row') : null;
            var sync = function() {
                regularRow.classList.toggle('is-disabled', !onsale.checked);
                if (textRow) textRow.classList.toggle('is-disabled', onsale.checked && regular.value === 'swap_lowest_notxt');
            };
            onsale.addEventListener('change', sync);
            regular.addEventListener('change', sync);
            sync();
        })();
        </script>
        <?php 
    }

    /**
     * Get all the pages
     *
     * @return array page names with key value pairs
     */
    public function get_pages() {
        $pages = get_pages();
        $pages_options = [];
        if ( $pages ) {
            foreach ( $pages as $page ) {
                $pages_options[$page->ID] = $page->post_title;
            }
        }
        return $pages_options;
    }

    /**
     * Output description text above panel title.
     * 
     * @since   2.4.0
     */
    public function panel_description( $form ) {
        if ( Helper::missing_eggl_ph_tables() ) {
            echo '<div class="notice notice-error inline eggl-ph-alert">';
            echo '<p>';
            echo wp_kses_post( __( '<strong>Attention:</strong> It appears that some required tables are missing in the database.<br>
            Please click the button below to repair the database tables.', 'garanlabs-eu-guarantee-notice-durability-labels' ) );
            echo ' <a href="#" class="button button-primary eggl-ph-repair-tables">' . esc_html__( 'Repair', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '</a>';
            echo '</p>';
            echo '</div>';
        }
    }

}
