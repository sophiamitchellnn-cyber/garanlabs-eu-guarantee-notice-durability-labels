<?php
if (!defined('ABSPATH')) exit;

/**
 * "Vendor GARAN Labels" submenu page: lets the merchant upload a manufacturer/vendor-supplied
 * GARAN durability label image for a specific product, instead of the plugin's own generated
 * label graphic. When set, EGGL_Product_Fields::vendor_image_src() makes that image take over
 * everywhere the GARAN label would otherwise render for that product — product page, checkout,
 * cart, shortcode/block, and order-confirmation emails — see public/class-eggl-frontend.php.
 */
class EGGL_Vendor_Uploads {

    const PAGE_SLUG    = 'eggl-vendor-garan-uploads';
    const META_KEY     = '_eggl_vendor_garan_image_id';
    const YEARS_META_KEY = '_eggl_vendor_garan_years';

    public function register_menu() {
        // Assigns images to WooCommerce products, and uses WooCommerce's product search.
        if (!class_exists('WooCommerce')) return;

        add_submenu_page(
            EGGL_Settings::PAGE_SLUG,
            __('Vendor GARAN Labels', 'garanlabs-eu-guarantee-notice-durability-labels'),
            __('Vendor GARAN Labels', 'garanlabs-eu-guarantee-notice-durability-labels'),
            EGGL_Settings::capability(),
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, self::PAGE_SLUG) === false) return;

        wp_enqueue_media();
        wp_enqueue_style('eggl-admin', EGGL_URL . 'assets/css/eggl-admin.css', [], eggl_asset_version('assets/css/eggl-admin.css'));

        // WooCommerce bundles the select2 CSS straight into its own admin.css (registered as
        // 'woocommerce_admin_styles') rather than a separate 'select2' handle — enqueuing a
        // handle named 'select2' is a no-op here and leaves the product-search widget unstyled.
        wp_enqueue_style('woocommerce_admin_styles');
        wp_enqueue_script('wc-enhanced-select');

        wp_enqueue_script('eggl-admin-vendor-upload', EGGL_URL . 'assets/js/eggl-admin-vendor-upload.js',
            ['jquery', 'wc-enhanced-select'], eggl_asset_version('assets/js/eggl-admin-vendor-upload.js'), true);
        wp_localize_script('eggl-admin-vendor-upload', 'egglVendorUpload', [
            'chooseTitle'  => __('Select or upload the vendor GARAN label image', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'chooseButton' => __('Use this image', 'garanlabs-eu-guarantee-notice-durability-labels'),
        ]);
    }

    /** Processes the upload-assignment and remove-assignment form submissions, before any output. Hooked to admin_init. */
    public function maybe_handle_request() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page check, verified below before any state change.
        if (!isset($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== self::PAGE_SLUG) return;
        if (!current_user_can(EGGL_Settings::capability())) return;

        if (isset($_POST['eggl_vendor_save']) && check_admin_referer('eggl_save_vendor_garan_image')) {
            $product_id    = isset($_POST['eggl_vendor_product_id']) ? absint($_POST['eggl_vendor_product_id']) : 0;
            $attachment_id = isset($_POST['eggl_vendor_image_id']) ? absint($_POST['eggl_vendor_image_id']) : 0;
            $years         = isset($_POST['eggl_vendor_years']) ? absint($_POST['eggl_vendor_years']) : 0;

            // Duration is required — it fills the compact trigger badge (see EGGL_Product_Fields::vendor_years()).
            if ($product_id && $attachment_id && $years > 0
                && in_array(get_post_type($product_id), ['product', 'product_variation'], true)
                && wp_attachment_is_image($attachment_id)) {
                update_post_meta($product_id, self::META_KEY, $attachment_id);
                update_post_meta($product_id, self::YEARS_META_KEY, $years);

                $this->redirect(['eggl_saved' => $product_id]);
            }
            $this->redirect(['eggl_error' => 1]);
        }

        if (isset($_GET['eggl_remove']) && check_admin_referer('eggl_remove_vendor_garan_image')) {
            $product_id = absint($_GET['eggl_remove']);
            if ($product_id) {
                delete_post_meta($product_id, self::META_KEY);
                delete_post_meta($product_id, self::YEARS_META_KEY);
            }
            $this->redirect(['eggl_removed' => $product_id]);
        }
    }

    private function redirect($extra_args) {
        $url = add_query_arg(array_merge(['page' => self::PAGE_SLUG], $extra_args), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    const PER_PAGE = 10;

    public function render() {
        if (!current_user_can(EGGL_Settings::capability())) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination, no state change.
        $eggl_paged = isset($_GET['eggl_paged']) ? max(1, absint($_GET['eggl_paged'])) : 1;

        $eggl_query = new WP_Query([
            'post_type'      => ['product', 'product_variation'],
            'post_status'    => 'any',
            'posts_per_page' => self::PER_PAGE,
            'paged'          => $eggl_paged,
            'meta_key'       => self::META_KEY,
            'meta_compare'   => 'EXISTS',
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => false,
        ]);

        $eggl_products_with_images = $eggl_query->posts;
        $eggl_total_pages          = $eggl_query->max_num_pages;
        $eggl_current_page         = $eggl_paged;

        include EGGL_PATH . 'admin/views/eggl-vendor-uploads-page.php';
    }
}
