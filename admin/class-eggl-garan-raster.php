<?php
if (!defined('ABSPATH')) exit;

/**
 * Fallback for servers without PHP's GD/FreeType, which can't draw the GARAN
 * label PNG that order emails embed or attach. Instead, the admin's own
 * browser draws it from the same SVG the storefront shows (see
 * assets/js/eggl-garan-raster.js) whenever a product is opened in the editor
 * or the settings page is visited, and uploads it here. Images are cached in
 * uploads/eggl-garan/, one file per distinct brand/model/years.
 */
class EGGL_Garan_Raster {

    const AJAX_ACTION = 'eggl_store_garan_png';
    const RENDER_WIDTH = 800;   // px; emails scale it down with width/height attributes
    const MAX_BYTES = 3145728;  // 3 MB
    const SWEEP_LIMIT = 20;     // missing images drawn per settings-page visit

    /** Aspect ratio of assets/official/garan-full.svg's viewBox (269.29 x 283.46). */
    const RATIO = 1.05262;

    public static function server_can_draw() {
        return function_exists('imagecreatefrompng') && function_exists('imagettftext');
    }

    public static function key($brand, $model, $years) {
        return md5(wp_json_encode([(string) $brand, (string) $model, (string) $years]));
    }

    private static function dir() {
        $uploads = wp_upload_dir(null, false);
        return trailingslashit($uploads['basedir']) . 'eggl-garan/';
    }

    public static function path($brand, $model, $years) {
        return self::dir() . self::key($brand, $model, $years) . '.png';
    }

    /**
     * The browser-drawn image in garan_render_email_png()'s own return shape, for
     * display at $width px, or false if none has been drawn yet for these values.
     */
    public static function cached_render($brand, $model, $years, $width) {
        $path = self::path($brand, $model, $years);
        if (!is_readable($path)) return false;
        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') return false;
        return ['bytes' => $bytes, 'height' => (int) round($width * self::RATIO)];
    }

    /** Label values for $products (and their variations) that still need drawing. */
    public static function pending($products) {
        $pending = [];
        foreach ($products as $product) {
            if (!$product) continue;
            $candidates = [$product];
            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $child_id) {
                    $child = wc_get_product($child_id);
                    if ($child) $candidates[] = $child;
                }
            }
            foreach ($candidates as $candidate) {
                if (!EGGL_Product_Fields::applies($candidate) || EGGL_Product_Fields::vendor_image_src($candidate) !== '') continue;
                $months = EGGL_Product_Fields::qualified_months($candidate);
                if (!$months) continue;

                $brand = EGGL_Product_Fields::brand($candidate);
                $model = EGGL_Product_Fields::model($candidate);
                $years = (string) max(1, (int) round($months / 12));
                $key   = self::key($brand, $model, $years);
                if (isset($pending[$key]) || file_exists(self::path($brand, $model, $years))) continue;

                $pending[$key] = [
                    'brand' => $brand,
                    'model' => $model,
                    'years' => $years,
                    'svg'   => EGGL_Frontend::garan_svg_markup($brand, $model, $years),
                ];
            }
        }
        return array_values($pending);
    }

    /** Product editor and settings page: hand the browser anything still missing. Only when the server can't draw. */
    public function enqueue($hook) {
        if (self::server_can_draw() || !function_exists('wc_get_product')) return;
        if (get_option('eggl_enable_garan', 'yes') !== 'yes' || get_option('eggl_garan_email_enable', 'no') !== 'yes') return;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $products = [];
        if ($screen && $screen->id === 'product') {
            global $post;
            if ($post instanceof WP_Post) $products[] = wc_get_product($post->ID);
        } elseif (strpos((string) $hook, EGGL_Settings::PAGE_SLUG) !== false) {
            $products = $this->sweep_candidates();
        } else {
            return;
        }

        $pending = self::pending(array_filter($products));
        if (!$pending) return;

        wp_enqueue_script('eggl-garan-raster', EGGL_URL . 'assets/js/eggl-garan-raster.js', [], eggl_asset_version('assets/js/eggl-garan-raster.js'), true);
        wp_localize_script('eggl-garan-raster', 'egglGaranRaster', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::AJAX_ACTION,
            'nonce'   => wp_create_nonce(self::AJAX_ACTION),
            'width'   => self::RENDER_WIDTH,
            'fonts'   => [
                'Inter-Regular'   => EGGL_URL . 'assets/fonts/Inter-Regular.ttf',
                'Inter-ExtraBold' => EGGL_URL . 'assets/fonts/Inter-ExtraBold.ttf',
            ],
            'items'   => array_slice($pending, 0, self::SWEEP_LIMIT),
        ]);
    }

    /** Recently edited products with a guarantee filled in — the settings-page sweep for existing catalogues. */
    private function sweep_candidates() {
        $ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'modified',
            'fields'         => 'ids',
            'meta_query'     => [['key' => '_eggl_manufacturer_months', 'value' => 24, 'compare' => '>', 'type' => 'NUMERIC']],
        ]);
        return array_map('wc_get_product', $ids);
    }

    /** AJAX: stores one browser-drawn PNG. The file name is derived from the values, never taken from the request. */
    public function ajax_store() {
        check_ajax_referer(self::AJAX_ACTION);
        if (!current_user_can('edit_products')) wp_send_json_error(null, 403);

        // Only ever hashed into the file name (never output or stored), so compared exactly as sent —
        // sanitizing could alter them and break the match with the values emails look up.
        $brand = isset($_POST['brand']) ? mb_substr((string) wp_unslash($_POST['brand']), 0, 300) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $model = isset($_POST['model']) ? mb_substr((string) wp_unslash($_POST['model']), 0, 300) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $years = isset($_POST['years']) ? (string) absint($_POST['years']) : '';
        $data  = isset($_POST['png']) ? (string) wp_unslash($_POST['png']) : '';

        if ($brand === '' || $model === '' || $years === '0' || strpos($data, 'data:image/png;base64,') !== 0) {
            wp_send_json_error(null, 400);
        }
        $bytes = base64_decode(substr($data, 22), true);
        if ($bytes === false || strlen($bytes) > self::MAX_BYTES || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            wp_send_json_error(null, 400);
        }

        if (!wp_mkdir_p(self::dir())) wp_send_json_error(null, 500);
        $written = file_put_contents(self::path($brand, $model, $years), $bytes); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $written ? wp_send_json_success() : wp_send_json_error(null, 500);
    }
}
