<?php

namespace EGGL\PriceHistory\Admin;

use EGGL\PriceHistory\Includes\Helper;
use EGGL\PriceHistory\Includes\Activator;
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
class PPH_Admin {
    const PAGE_SLUG = 'eggl-price-history';

    private $plugin_name;

    private $version;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        add_action( 'admin_enqueue_scripts', [$this, 'enqueue_styles'] );
        add_action( 'admin_enqueue_scripts', [$this, 'enqueue_scripts'] );
        add_action( 'admin_menu', [$this, 'admin_menu'], 100 );
        add_action( 'wp_ajax_eggl_ph_delete_old_data', [$this, 'delete_old_data'] );
        add_action( 'wp_ajax_eggl_ph_repair_tables', [$this, 'eggl_ph_repair_tables'] );
    }

    /**
     * Register the stylesheets for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_styles( $hook ) {
        $hook = (string) $hook;
        // The module's own screens use the plugin-wide card layout (assets/css/eggl-admin.css).
        if ( strpos( $hook, self::PAGE_SLUG ) !== false || strpos( $hook, \EGGL\PriceHistory\Modules\PriceComplianceManager\PCM_Admin::PAGE ) !== false ) {
            wp_enqueue_style(
                'eggl-admin',
                EGGL_URL . 'assets/css/eggl-admin.css',
                [],
                eggl_asset_version( 'assets/css/eggl-admin.css' )
            );
        }
        if ( !self::is_plugin_screen( $hook ) ) {
            return;
        }
        wp_enqueue_style( 'wp-color-picker' );
        if ( strpos( $hook, self::PAGE_SLUG ) !== false ) {
            return;
        }
        wp_enqueue_style(
            $this->plugin_name,
            plugin_dir_url( __DIR__ ) . 'assets/build/admin.css',
            [],
            eggl_asset_version( 'price-history/assets/build/admin.css' ),
            'all'
        );
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts( $hook ) {
        if ( !self::is_plugin_screen( $hook ) ) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script(
            'wp-color-picker-alpha',
            plugin_dir_url( __DIR__ ) . 'assets/color-picker/wp-color-picker-alpha.min.js',
            ['wp-color-picker'],
            $this->version,
            true
        );
        $script_asset_path = plugin_dir_path( __DIR__ ) . 'assets/build/admin.asset.php';
        $script_info = ( file_exists( $script_asset_path ) ? include $script_asset_path : [
            'dependencies' => ['jquery'],
            'version'      => $this->version,
        ] );
        wp_enqueue_script(
            $this->plugin_name,
            plugin_dir_url( __DIR__ ) . 'assets/build/admin.js',
            array_unique( array_merge( ['jquery'], $script_info['dependencies'] ) ),
            $script_info['version'],
            true
        );
        /* translators: %1$s: number of deleted entries */
        $text_delete_success = __( '%1$s old entries have been successfully deleted.', 'garanlabs-eu-guarantee-notice-durability-labels' );
        $script_data = [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'eggl-ph-nonce' ),
            'text'    => [
                '30_days'                  => esc_html__( '30 days', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                '3_months'                 => esc_html__( '3 months', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                '6_months'                 => esc_html__( '6 months', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                '12_months'                => esc_html__( '12 months', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'delete_confirm'           => esc_html__( 'Are you sure you want to proceed? This action will irreversibly delete old data.', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'delete_success'           => esc_html( $text_delete_success ),
                'delete_none'              => esc_html__( 'No old data was found or deleted.', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'delete_confirm_2'         => esc_html__( 'Continuing will permanently erase all entries associated with this product, and this action cannot be undone.', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'delete_confirm_3'         => esc_html__( 'Are you sure you want to proceed? This action will irreversibly delete this data.', 'garanlabs-eu-guarantee-notice-durability-labels' ),
                'prompt_lowest_price_bulk' => esc_html__( 'Set the lowest price for all variations:', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            ],
            'plan'    => Helper::plan( 'all' ),
        ];
        wp_localize_script( $this->plugin_name, 'eggl_ph_script', $script_data );
    }

    /**
     * The settings screen and the product editor (where the price history tab lives).
     */
    public static function is_plugin_screen( $hook ) {
        return in_array( $hook, ['post.php', 'post-new.php'], true ) || strpos( (string) $hook, self::PAGE_SLUG ) !== false;
    }

    /**
     * Add the settings screen under EU Guarantee & GARAN.
     *
     * @since    1.0.0
     */
    public function admin_menu() {
        $plugin_settings = new Settings();
        add_submenu_page(
            \EGGL_Settings::PAGE_SLUG,
            esc_html__( 'Price History', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            esc_html__( 'Price History', 'garanlabs-eu-guarantee-notice-durability-labels' ),
            \EGGL_Settings::capability(),
            self::PAGE_SLUG,
            [$plugin_settings, 'settings_page']
        );
    }

    /**
     * Ajax action for deleting old price history data.
     *  
     * @since     2.1.3
     */
    public function delete_old_data() {
        check_ajax_referer( 'eggl-ph-nonce', 'security' );
        if ( !current_user_can( \EGGL_Settings::capability() ) ) {
            wp_send_json_error( null, 403 );
        }
        $older_than = ( isset( $_POST['args']['older_than'] ) ? sanitize_text_field( $_POST['args']['older_than'] ) : null );
        $deleted = Helper::delete_old_data( 'price_history', $older_than );
        wp_send_json( $deleted );
        wp_die();
    }

    /**
     * Ajax action for creating missing tables.
     *  
     * @since     2.4.0
     */
    public function eggl_ph_repair_tables() {
        check_ajax_referer( 'eggl-ph-nonce', 'security' );
        if ( !current_user_can( \EGGL_Settings::capability() ) ) {
            wp_send_json_error( null, 403 );
        }
        require_once plugin_dir_path( __DIR__ ) . 'includes/activator.php';
        Activator::activate();
        wp_send_json( 'ok' );
        wp_die();
    }

}
