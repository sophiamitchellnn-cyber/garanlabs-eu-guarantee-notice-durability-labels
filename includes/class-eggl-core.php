<?php
if (!defined('ABSPATH')) exit;

/**
 * Orchestrates the plugin: instantiates each feature class and wires its
 * hooks through EGGL_Loader. Feature classes never call add_action/add_filter
 * on themselves — this keeps hook registration in one auditable place.
 */
class EGGL_Core {

    private $loader;

    public function __construct() {
        $this->loader = new EGGL_Loader();
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    private function define_admin_hooks() {
        $settings = new EGGL_Settings();
        $this->loader->add_action('admin_menu', $settings, 'register_menu');
        $this->loader->add_action('admin_init', $settings, 'register_settings');
        $this->loader->add_action('admin_enqueue_scripts', $settings, 'enqueue_assets');
        $this->loader->add_filter('plugin_action_links_' . plugin_basename(EGGL_FILE), $settings, 'action_links');

        $fields = new EGGL_Product_Fields();
        $this->loader->add_filter('woocommerce_product_data_tabs', $fields, 'add_tab');
        $this->loader->add_action('woocommerce_product_data_panels', $fields, 'render_panel');
        $this->loader->add_action('admin_enqueue_scripts', $fields, 'enqueue_assets');
        $this->loader->add_action('woocommerce_process_product_meta', $fields, 'save');
        $this->loader->add_action('woocommerce_product_after_variable_attributes', $fields, 'render_variation_fields', 10, 3);
        $this->loader->add_action('woocommerce_save_product_variation', $fields, 'save_variation', 10, 2);
        $this->loader->add_action('admin_notices', $fields, 'fit_notice');

        $vendor_uploads = new EGGL_Vendor_Uploads();
        $this->loader->add_action('admin_menu', $vendor_uploads, 'register_menu');
        $this->loader->add_action('admin_init', $vendor_uploads, 'maybe_handle_request');
        $this->loader->add_action('admin_enqueue_scripts', $vendor_uploads, 'enqueue_assets');

        $health = new EGGL_Health();
        $this->loader->add_filter('site_status_tests', $health, 'register_tests');

        $admin_notices = new EGGL_Admin_Notices();
        $this->loader->add_action('admin_init', $admin_notices, 'stamp_install_time');
        $this->loader->add_action('admin_notices', $admin_notices, 'render');
        $this->loader->add_action('admin_post_' . EGGL_Admin_Notices::ACTION, $admin_notices, 'handle_dismiss');

        $raster = new EGGL_Garan_Raster();
        $this->loader->add_action('admin_enqueue_scripts', $raster, 'enqueue');
        $this->loader->add_action('wp_ajax_' . EGGL_Garan_Raster::AJAX_ACTION, $raster, 'ajax_store');

        $deactivation_feedback = new EGGL_Deactivation_Feedback();
        $this->loader->add_action('admin_footer-plugins.php', $deactivation_feedback, 'render_modal');
        $this->loader->add_action('wp_ajax_eggl_submit_deactivation_feedback', $deactivation_feedback, 'ajax_submit');
    }

    private function define_public_hooks() {
        $frontend = new EGGL_Frontend();
        $this->loader->add_action('wp_enqueue_scripts', $frontend, 'enqueue_assets');
        $this->loader->add_action('wp_body_open', $frontend, 'render_header_notice');
        $this->loader->add_filter('wp_nav_menu_items', $frontend, 'inject_menu_item', 10, 2);
        $this->loader->add_filter('wp_page_menu', $frontend, 'inject_page_menu_item', 10, 2);
        $this->loader->add_filter('render_block_core/navigation', $frontend, 'inject_navigation_block_item', 10, 2);
        $this->loader->add_action('wp_footer', $frontend, 'render_header_notice_modal');
        $this->loader->add_action('wp_footer', $frontend, 'render_header_notice_fallback', 20);

        [$notice_hook, $notice_priority] = EGGL_Frontend::resolve_position(get_option('eggl_notice_position', 'after_excerpt'));
        $this->loader->add_action($notice_hook, $frontend, 'render_notice', $notice_priority);

        [$garan_hook, $garan_priority] = EGGL_Frontend::resolve_position(get_option('eggl_garan_position', 'after_excerpt'));
        $this->loader->add_action($garan_hook, $frontend, 'render_garan', $garan_priority);

        $this->loader->add_action('woocommerce_before_checkout_form', $frontend, 'render_checkout_notice', 5);
        $this->loader->add_filter('render_block_woocommerce/checkout', $frontend, 'render_checkout_notice_block', 10, 2);

        $this->loader->add_action('woocommerce_email_after_order_table', $frontend, 'render_email_notice', 20, 4);
        $this->loader->add_action('woocommerce_email_after_order_table', $frontend, 'render_email_garan', 30, 4);
        $this->loader->add_action('phpmailer_init', $frontend, 'embed_email_images');
        $this->loader->add_action('phpmailer_init', $frontend, 'embed_email_garan_images');
        $this->loader->add_filter('woocommerce_email_attachments', $frontend, 'add_email_attachments', 10, 4);
        $this->loader->add_action('shutdown', $frontend, 'cleanup_email_attachment_tmp_files');

        $this->loader->add_action('wp_ajax_eggl_garan_svg', $frontend, 'output_garan_svg_endpoint');
        $this->loader->add_action('wp_ajax_nopriv_eggl_garan_svg', $frontend, 'output_garan_svg_endpoint');

        $this->loader->add_action('wp_ajax_eggl_garan_png', $frontend, 'output_garan_png_endpoint');
        $this->loader->add_action('wp_ajax_nopriv_eggl_garan_png', $frontend, 'output_garan_png_endpoint');

        $this->loader->add_filter('woocommerce_checkout_cart_item_quantity', $frontend, 'render_checkout_garan_cart_item', 10, 3);
        $this->loader->add_action('woocommerce_after_cart_item_name', $frontend, 'render_cart_garan_name', 10, 2);
        $this->loader->add_action('wp_enqueue_scripts', $frontend, 'enqueue_checkout_garan_block_assets', 20);

        // Fix each order line's legal guarantee period at purchase (per-product override, EU Guarantee tab).
        add_action('woocommerce_checkout_create_order_line_item', ['EGGL_Product_Fields', 'snapshot_legal_months'], 10, 4);

        $this->loader->add_action('init', $frontend, 'register_manual_placement');
        $this->loader->add_action('enqueue_block_editor_assets', $frontend, 'enqueue_block_editor_assets');
    }

    public function run() {
        $this->loader->run();
    }
}
