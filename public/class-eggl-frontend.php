<?php
if (!defined('ABSPATH')) exit;

/**
 * Renders the harmonised EU legal-guarantee notice (Annex I of Regulation
 * (EU) 2025/1960) and the GARAN durability label (Annex II) on the product
 * page. The notice and the label are fully independent widgets: each has its
 * own on-page position and its own display mode — "popup" (compact summary
 * that opens the full graphic in a dialog), "full" (the full graphic shown
 * directly on the page), or "nested" (a compact trigger that expands the
 * full graphic inline, in place, when clicked).
 */
class EGGL_Frontend {

    /**
     * True once the header notice has actually been spliced into a nav menu
     * (real or fallback) during this request. Lets the wp_footer safety net
     * know whether it still needs to print the notice itself.
     */
    private $header_notice_menu_rendered = false;

    /** True once the checkout notice has been printed, guarding against firing on both the classic and block checkout templates. */
    private $checkout_notice_rendered = false;

    /** Decrementing ids for manually-placed (shortcode/block) instances — always negative, so they can never clash with a real (positive) product id used by the automatic per-product placement. */
    private static $manual_uid = 0;

    /** Per-request cache for checkout_garan_cart_labels(), keyed by nothing (one cart per request) — avoids re-rendering per cart-item row on the classic checkout. */
    private $checkout_garan_labels_cache = null;

    /** Guards render_email_notice() against firing twice for the same order/email/format if WooCommerce fires the hook more than once in a request. */
    private $email_notice_rendered = [];

    /** Guards render_email_garan() against firing twice for the same order/email/format if WooCommerce fires the hook more than once in a request. */
    private $email_garan_rendered = [];

    /** url => filesystem path for images render_email_notice() has printed this request; embed_email_images() turns these into CID attachments so the notice still shows when a mail client blocks remote images. */
    private static $email_embeddable_images = [];

    /** placeholder url => rasterized PNG bytes render_email_garan() has produced this request; embed_email_garan_images() turns these into CID attachments (no source file exists — the badge is generated per product — so it's embedded from the string directly). */
    private static $email_embeddable_garan_pngs = [];

    /** Filesystem paths of temp GARAN label PNGs written this request for the "attachment" email format — cleaned up by cleanup_email_attachment_tmp_files() once the email has been sent. */
    private static $email_attachment_tmp_files = [];

    private function next_manual_uid() {
        self::$manual_uid--;
        return self::$manual_uid;
    }

    /** Product-page position choices, mapped to a WooCommerce hook + priority. */
    public static function positions() {
        return [
            'before_summary'    => ['woocommerce_before_single_product_summary', 5],
            'under_image'       => ['woocommerce_product_thumbnails', 25],
            'after_title'       => ['woocommerce_single_product_summary', 6],
            'after_price'       => ['woocommerce_single_product_summary', 11],
            'after_excerpt'     => ['woocommerce_single_product_summary', 21],
            'after_add_to_cart' => ['woocommerce_single_product_summary', 31],
            'after_summary'     => ['woocommerce_after_single_product_summary', 5],
        ];
    }

    /** [hook, priority] for a stored position key, falling back to the default. */
    public static function resolve_position($key) {
        $positions = self::positions();
        return isset($positions[$key]) ? $positions[$key] : $positions['after_excerpt'];
    }

    /**
     * The GARAN durability label (Annex II) as SVG markup, with Brand/Trademark,
     * Model identifier and the duration number set to the given values — each
     * falls back to the design's own placeholder ("Brand/Trademark", "Model
     * identifier", "XX") when empty. Tightly coupled to the text markup in
     * assets/official/garan-full.svg — if that file's text elements change,
     * update the anchor strings below to match.
     *
     * @param bool $with_ids Mark the three editable text elements (data-eggl-svg="brand|model|years")
     *                       so the admin/block-editor previews can update them live via JS. Data
     *                       attributes rather than ids, since a variable product's edit screen
     *                       shows one preview per variation.
     */
    public static function garan_svg_markup($brand = '', $model = '', $years = '', $with_ids = false, $aria_label = '') {
        static $template = null;
        static $instance = 0;
        if ($template === null) {
            $template = (string) file_get_contents(EGGL_PATH . 'assets/official/garan-full.svg');
        }
        $instance++;

        // The source file's <clipPath> ids (used by the QR code) are shared
        // across every render of this markup. Namespace them per call so two
        // copies of the label can safely coexist on one page (e.g. a
        // quick-view modal open alongside the main product) without one
        // instance's clip-paths clobbering the other's.
        $suffix = '-eggl' . $instance;
        $svg = preg_replace('/\bid="([a-zA-Z0-9_-]+)"/', 'id="$1' . $suffix . '"', $template);
        $svg = preg_replace('/url\(#([a-zA-Z0-9_-]+)\)/', 'url(#$1' . $suffix . ')', $svg);

        $svg = str_replace(
            '<text class="cls-5" transform="translate(6.32 74.52)">',
            '<text class="cls-5" transform="translate(6.32 74.52)"' . ($with_ids ? ' data-eggl-svg="brand"' : '') . '>',
            $svg
        );
        if ($brand !== '') {
            $svg = str_replace(
                '<tspan x="0" y="0">Brand/</tspan><tspan class="cls-19" x="28.34" y="0">T</tspan><tspan class="cls-22" x="33.61" y="0">rademark</tspan>',
                // Compressed (never overflowing into the model column) if too wide — see EGGL_Garan_Fit.
                '<tspan x="0" y="0"' . EGGL_Garan_Fit::squeeze_attrs(EGGL_Garan_Fit::brand_width($brand), EGGL_Garan_Fit::BRAND_WIDTH) . '>' . esc_html($brand) . '</tspan>',
                $svg
            );
        }

        $svg = str_replace(
            '<text class="cls-5" transform="translate(196.75 74.52)">',
            '<text class="cls-5" transform="translate(196.75 74.52)"' . ($with_ids ? ' data-eggl-svg="model"' : '') . '>',
            $svg
        );
        if ($model !== '') {
            $svg = str_replace('<tspan x="0" y="0">Model identifier</tspan>',
                '<tspan x="0" y="0"' . EGGL_Garan_Fit::squeeze_attrs(EGGL_Garan_Fit::model_width($model), EGGL_Garan_Fit::MODEL_WIDTH) . '>' . esc_html($model) . '</tspan>', $svg);
        }

        $svg = str_replace(
            '<text class="cls-3" transform="translate(5.07 150.57)">',
            '<text class="cls-3" transform="translate(5.07 150.57)"' . ($with_ids ? ' data-eggl-svg="years"' : '') . '>',
            $svg
        );
        // See the matching comment in garan_nested_svg_markup(): never leave the template's
        // own "XX" placeholder showing — blank it out rather than skipping the replacement.
        $years_display = ($years !== '' && (int) $years !== 0) ? (string) $years : '';
        $svg = str_replace('<tspan x="0" y="0">XX</tspan>',
            '<tspan x="0" y="0"' . EGGL_Garan_Fit::squeeze_attrs(EGGL_Garan_Fit::years_width($years_display), EGGL_Garan_Fit::YEARS_WIDTH) . '>' . esc_html($years_display) . '</tspan>', $svg);

        if ($aria_label !== '') {
            $svg = str_replace(
                '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 269.29 283.46">',
                '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 269.29 283.46" role="img" aria-label="' . esc_attr($aria_label) . '">',
                $svg
            );
        }

        // The source file's "cls-N" CSS classes (defined in its own <style>
        // block) are NOT scoped to that one <svg> — if this markup and
        // garan_nested_svg_markup() (or two copies of this call) ever land on
        // the same page, their same-named classes collide and can repaint
        // each other's shapes with the wrong color. Namespace them last, once
        // the text-content replacements above (which match the original
        // "cls-N" class names) are done.
        $svg = preg_replace('/\bcls-(\d+)\b/', 'cls-$1' . $suffix, $svg);

        return $svg;
    }

    /**
     * The compact "nested" GARAN badge (GARAN wordmark + duration number +
     * EU shield) as SVG markup, used as the click-to-expand trigger in
     * "nested" display mode. Tightly coupled to assets/official/garan-nested.svg.
     */
    public static function garan_nested_svg_markup($years = '', $aria_label = '') {
        static $template = null;
        static $instance = 0;
        if ($template === null) {
            $template = (string) file_get_contents(EGGL_PATH . 'assets/official/garan-nested.svg');
        }
        $instance++;

        $suffix = '-egglnest' . $instance;
        $svg = preg_replace('/\bid="([a-zA-Z0-9_-]+)"/', 'id="$1' . $suffix . '"', $template);
        $svg = preg_replace('/url\(#([a-zA-Z0-9_-]+)\)/', 'url(#$1' . $suffix . ')', $svg);
        // See the matching comment in garan_svg_markup(): "cls-N" classes are
        // not scoped to this <svg> and must be namespaced to avoid colliding
        // with another instance of this markup, or with garan_svg_markup(),
        // on the same page.
        $svg = preg_replace('/\bcls-(\d+)\b/', 'cls-$1' . $suffix, $svg);

        // The template's own "XX" is a fill-in-the-blank placeholder meant to always be
        // replaced — never leave it showing to a shopper. Blank it out (rather than skipping
        // the replacement) when there's no real duration to show, e.g. a vendor-supplied label
        // image was set without also filling in this product's own Duration field.
        $years_display = ($years !== '' && (int) $years !== 0) ? (string) $years : '';
        $svg = str_replace('<tspan x="0" y="0">XX</tspan>',
            '<tspan x="0" y="0"' . EGGL_Garan_Fit::squeeze_attrs(EGGL_Garan_Fit::years_width($years_display), EGGL_Garan_Fit::YEARS_WIDTH) . '>' . esc_html($years_display) . '</tspan>', $svg);

        if ($aria_label !== '') {
            $svg = str_replace(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 368.5 56.69">',
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 368.5 56.69" role="img" aria-label="' . esc_attr($aria_label) . '">',
                $svg
            );
        }

        return $svg;
    }

    public function enqueue_assets() {
        $on_product   = function_exists('is_product') && is_product();
        $on_checkout  = function_exists('is_checkout') && is_checkout();
        $on_cart      = function_exists('is_cart') && is_cart();
        $header_enabled = get_option('eggl_header_notice_enable', 'no') === 'yes';
        $notice_at_checkout = get_option('eggl_placement_checkout', 'no') === 'yes';
        // The GARAN-at-checkout/cart placement's own hooks (render_checkout_garan_cart_item(),
        // render_cart_garan_name()) fire deep in body content, well after wp_head() has
        // already printed the queued <link> tags — so if "eggl-frontend" wasn't enqueued by
        // this earlier, properly-timed hook, their later wp_enqueue_style() call is a no-op and
        // the CSS silently never loads. Enqueue proactively here whenever the setting is on and
        // we're on a page it could apply to, regardless of whether the cart turns out to have a
        // qualifying item — a harmless slight over-fetch, not a late one.
        $garan_at_checkout = get_option('eggl_enable_garan', 'yes') === 'yes' && get_option('eggl_garan_checkout_enable', 'yes') === 'yes';

        $needed = $on_product || $header_enabled
            || ($on_checkout && ($notice_at_checkout || $garan_at_checkout))
            || ($on_cart && $garan_at_checkout);
        if (!$needed) return;
        $this->ensure_frontend_assets();
    }

    /** Enqueues the shared frontend CSS/JS (+ the GARAN width CSS var) — idempotent, safe to call from multiple hooks. */
    private function ensure_frontend_assets() {
        wp_enqueue_style('eggl-frontend', EGGL_URL . 'assets/css/eggl-frontend.css', [], eggl_asset_version('assets/css/eggl-frontend.css'));
        wp_enqueue_script('eggl-frontend', EGGL_URL . 'assets/js/eggl-frontend.js', [], eggl_asset_version('assets/js/eggl-frontend.js'), true);

        $width        = max(40, (int) get_option('eggl_garan_width', 220));
        $notice_width = max(200, min(1200, (int) get_option('eggl_notice_width', 460)));
        wp_add_inline_style('eggl-frontend', ':root{--eggl-garan-width:' . $width . 'px;--eggl-notice-width:' . $notice_width . 'px}');
    }

    /** Normalizes a stored display-mode value, falling back to "popup". */
    private function normalize_mode($mode) {
        return in_array($mode, ['popup', 'full'], true) ? $mode : 'nested';
    }

    private function notice_mode() {
        // Falls back to the old shared eggl_display_mode option so sites that
        // set it before the notice/label modes were split keep their choice.
        $default = get_option('eggl_display_mode', 'nested');
        return $this->normalize_mode(get_option('eggl_notice_display_mode', $default));
    }

    private function garan_mode() {
        $default = get_option('eggl_display_mode', 'nested');
        return $this->normalize_mode(get_option('eggl_garan_display_mode', $default));
    }

    /** Visual style of the compact notice row: "minimal" (default), "classic", or "custom". */
    private function notice_style() {
        $val = get_option('eggl_notice_style', 'minimal');
        return in_array($val, ['classic', 'custom'], true) ? $val : 'minimal';
    }

    /** Merchant-entered trigger text for the notice row (every row style), falling back to "Your legal guarantee rights" (in the store's language) when empty. */
    private function notice_custom_text() {
        $text = trim((string) get_option('eggl_notice_custom_text', ''));
        return $text !== '' ? $text : EGGL_I18N::t('s_header_notice');
    }

    /** Font size (px) of the notice row's trigger text, every row style; with the "custom" style the icon and chevron scale with it. */
    private function notice_custom_size() {
        return max(10, (int) get_option('eggl_notice_custom_size', 14));
    }

    private function maybe_render_credit() {
        if (get_option('eggl_show_credit', 'no') === 'yes') {
            include EGGL_PATH . 'public/views/eggl-credit.php';
        }
    }

    public function render_notice() {
        global $product;
        if (!$product) return;
        if (!EGGL_Product_Fields::applies($product)) return; // out of scope (e.g. food)
        if (get_option('eggl_enable_notice', 'yes') !== 'yes') return; // site-wide switch

        $lang = EGGL_I18N::store_lang();
        $file = EGGL_PATH . 'assets/official/notice-' . $lang . '.png';
        if (!file_exists($file)) {
            $lang = 'en';
            $file = EGGL_PATH . 'assets/official/notice-en.png';
        }
        if (!file_exists($file)) return;

        $eggl_uid         = $product->get_id();
        $eggl_mode        = $this->notice_mode();
        $eggl_style       = $this->notice_style();
        $eggl_custom_text = $this->notice_custom_text();
        $eggl_custom_size = $this->notice_custom_size();
        $eggl_notice_src  = EGGL_URL . 'assets/official/notice-' . $lang . '.png';
        $eggl_notice_alt  = EGGL_I18N::t('s_notice_alt');

        include EGGL_PATH . 'public/views/eggl-notice.php';
        $this->maybe_render_credit();
    }

    /** Whether $email_id is one of the order-notification emails that get eggl content by default, filterable for sites using custom email classes. */
    private function email_ids_wanted($email_id, $filter_tag) {
        $default = ['customer_processing_order', 'customer_completed_order', 'customer_invoice', 'customer_on_hold_order'];
        $ids = (array) apply_filters($filter_tag, $default);
        return $email_id === '' || in_array($email_id, $ids, true);
    }

    /** Email order-notification ids that get the notice by default, filterable for sites using custom email classes. */
    private function email_notice_wanted($email_id) {
        return $this->email_ids_wanted($email_id, 'eggl_notice_email_ids');
    }

    /** Email order-notification ids that get the GARAN label by default, filterable for sites using custom email classes. */
    private function email_garan_wanted($email_id) {
        return $this->email_ids_wanted($email_id, 'eggl_garan_email_ids');
    }

    /**
     * Legal-guarantee notice in the order confirmation email — customer-facing
     * order emails only (never the admin's own copy). Always the full official
     * notice graphic (no display-mode/style setting; those only apply to the
     * on-page widget), matching WooCommerce's own woocommerce_email_after_order_table
     * hook, which fires for both HTML and plain-text order emails.
     */
    public function render_email_notice($order, $sent_to_admin = false, $plain_text = false, $email = null) {
        if ($sent_to_admin) return;
        if (get_option('eggl_enable_notice', 'yes') !== 'yes') return;
        if (get_option('eggl_notice_email_enable', 'no') !== 'yes') return;

        $email_id = (is_object($email) && isset($email->id)) ? (string) $email->id : '';
        if (!$this->email_notice_wanted($email_id)) return;

        $order_id = (is_object($order) && method_exists($order, 'get_id')) ? $order->get_id() : 0;
        $key = $email_id . ':' . ($plain_text ? 'plain' : 'html') . ':' . $order_id;
        if (isset($this->email_notice_rendered[$key])) return;
        $this->email_notice_rendered[$key] = true;

        $lang = EGGL_I18N::store_lang();
        $file = EGGL_PATH . 'assets/official/notice-' . $lang . '.png';
        if (!file_exists($file)) {
            $lang = 'en';
            $file = EGGL_PATH . 'assets/official/notice-en.png';
        }
        if (!file_exists($file)) return;

        $src = EGGL_URL . 'assets/official/notice-' . $lang . '.png';
        $url = EGGL_I18N::notice_learn_more_url();
        $format = get_option('eggl_notice_email_format', 'link');

        if ($plain_text) {
            if ($format === 'attachment') {
                echo "\n" . EGGL_I18N::t('s_notice_title') . ' (' . EGGL_I18N::t('s_notice_sub') . ") — "
                    . EGGL_I18N::t('s_attached') . "\n";
            } else {
                echo "\n" . EGGL_I18N::t('s_notice_title') . ' (' . EGGL_I18N::t('s_notice_sub') . ") — "
                    . EGGL_I18N::t('s_readmore') . ' ' . $url . "\n";
            }
            return;
        }

        if ($format === 'attachment') {
            // The actual attachment is added by add_email_attachments(), hooked to
            // woocommerce_email_attachments — this only prints the compact text line.
            printf(
                '<table cellspacing="0" cellpadding="0" border="0" role="presentation" style="width:100%%;margin:24px 0;"><tr><td style="padding:0;font-family:inherit;font-size:14px;line-height:1.5;">'
                . '<strong>%s</strong> <span style="color:#666;">(%s)</span> — %s.'
                . '</td></tr></table>',
                esc_html(EGGL_I18N::t('s_notice_title')),
                esc_html(EGGL_I18N::t('s_notice_sub')),
                esc_html(EGGL_I18N::t('s_attached'))
            );
            return;
        }

        if ($format !== 'image') {
            // Links straight to the official notice graphic ($src), not the general
            // europa.eu info page — clicking opens/shows the actual notice image.
            printf(
                '<table cellspacing="0" cellpadding="0" border="0" role="presentation" style="width:100%%;margin:24px 0;"><tr><td style="padding:0;font-family:inherit;font-size:14px;line-height:1.5;">'
                . '<strong>%s</strong> <span style="color:#666;">(%s)</span> — %s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>.'
                . '</td></tr></table>',
                esc_html(EGGL_I18N::t('s_notice_title')),
                esc_html(EGGL_I18N::t('s_notice_sub')),
                esc_html(EGGL_I18N::t('s_readmore')),
                esc_url($src),
                esc_html(EGGL_I18N::t('s_link'))
            );
            return;
        }

        $size = @getimagesize($file);

        // Displayed size, not the source PNG's native pixel size (the notice graphic is a
        // tall A4-style poster, ~1100x1500px). Outlook's desktop renderer ignores the CSS
        // below and renders the img width/height attributes literally, so those attributes
        // must already be the small display size or the notice takes over the whole email.
        $width  = (int) apply_filters('eggl_notice_email_width', 300);
        $height = ($size && $size[0] > 0) ? (int) round($width * $size[1] / $size[0]) : 0;

        // Tracked so embed_email_images() can turn it into a CID attachment — the notice
        // still shows even when the recipient's mail client blocks remote images by default.
        self::$email_embeddable_images[$src] = $file;

        $img = sprintf(
            '<img src="%s" alt="%s" width="%d"%s style="display:block;width:100%%;max-width:%dpx;height:auto;border:0;outline:none;text-decoration:none;" />',
            esc_url($src),
            esc_attr(EGGL_I18N::t('s_notice_alt')),
            $width,
            $height ? ' height="' . $height . '"' : '',
            $width
        );

        $img = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" style="text-decoration:none;">' . $img . '</a>';

        printf(
            '<table cellspacing="0" cellpadding="0" border="0" role="presentation" style="width:100%%;margin:24px 0;"><tr><td style="padding:0;">%s</td></tr></table>',
            $img
        );
    }

    /** Identity of a rendered GARAN label: the vendor image if one is used, otherwise its years/brand/model. */
    private static function garan_signature($vendor_image_src, $years, $brand, $model) {
        return $vendor_image_src !== '' ? 'img:' . $vendor_image_src : 'gen:' . $years . "\n" . $brand . "\n" . $model;
    }

    /** Qualifying order items as product_id => ['years' => int, 'brand' => string, 'model' => string], one per distinct label. Shared by render_email_garan() and add_email_attachments(). */
    private function garan_email_items($order) {
        $items = [];
        $seen_signatures = [];
        foreach ($order->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product')) continue;
            $product = $item->get_product();
            if (!$product) continue;

            $product_id = $product->get_id();
            if (isset($items[$product_id])) continue;
            if (!EGGL_Product_Fields::applies($product)) continue;

            $vendor_image_src = EGGL_Product_Fields::vendor_image_src($product);
            $months = EGGL_Product_Fields::qualified_months($product);
            // A vendor-supplied label image qualifies the product on its own — see the
            // matching comment in render_garan().
            if (!$months && $vendor_image_src === '') continue;

            $data = [
                'years'              => $months ? max(1, (int) round($months / 12)) : (EGGL_Product_Fields::vendor_years($product) ?: ''),
                'brand'              => EGGL_Product_Fields::brand($product),
                'model'              => EGGL_Product_Fields::model($product),
                'vendor_image_src'   => $vendor_image_src,
                'vendor_image_path'  => EGGL_Product_Fields::vendor_image_path($product),
            ];

            // Several order items with an identical label get it once, not once each.
            $signature = self::garan_signature($data['vendor_image_src'], $data['years'], $data['brand'], $data['model']);
            if (isset($seen_signatures[$signature])) continue;
            $seen_signatures[$signature] = true;

            $items[$product_id] = $data;
        }
        return $items;
    }

    /** This product's actual label graphic URL for email "link"/plain-text formats — the vendor-supplied image if one is set, otherwise the plugin's own generated-label endpoint. */
    private function garan_email_link_url($product_id, $data) {
        return $data['vendor_image_src'] !== '' ? $data['vendor_image_src'] : $this->garan_svg_endpoint_url($product_id);
    }

    /**
     * GARAN durability label (Annex II) in the order confirmation email — customer-facing
     * order emails only (never the admin's own copy). One entry per qualifying product in
     * the order (deduplicated by product id), matching WooCommerce's own
     * woocommerce_email_after_order_table hook, which fires for both HTML and plain-text
     * order emails. Registered at a later priority than render_email_notice() so the label
     * appears below the legal-guarantee notice when both are enabled.
     */
    public function render_email_garan($order, $sent_to_admin = false, $plain_text = false, $email = null) {
        if ($sent_to_admin) return;
        if (get_option('eggl_enable_garan', 'yes') !== 'yes') return;
        if (get_option('eggl_garan_email_enable', 'no') !== 'yes') return;
        if (!is_object($order) || !method_exists($order, 'get_items')) return;

        $email_id = (is_object($email) && isset($email->id)) ? (string) $email->id : '';
        if (!$this->email_garan_wanted($email_id)) return;

        $order_id = method_exists($order, 'get_id') ? $order->get_id() : 0;
        $key = $email_id . ':' . ($plain_text ? 'plain' : 'html') . ':' . $order_id;
        if (isset($this->email_garan_rendered[$key])) return;
        $this->email_garan_rendered[$key] = true;

        $items = $this->garan_email_items($order);
        if (!$items) return;

        if ($plain_text) {
            $format = get_option('eggl_garan_email_format', 'link');
            foreach ($items as $product_id => $data) {
                if ($format === 'attachment') {
                    echo "\n" . EGGL_I18N::t('s_label_heading') . ' — ' . $data['model'] . ' (' . $data['brand'] . '): '
                        . $data['years'] . ' — ' . EGGL_I18N::t('s_attached') . "\n";
                } else {
                    echo "\n" . EGGL_I18N::t('s_label_heading') . ' — ' . $data['model'] . ' (' . $data['brand'] . '): '
                        . $data['years'] . ' — ' . EGGL_I18N::t('s_readmore') . ' ' . $this->garan_email_link_url($product_id, $data) . "\n";
                }
            }
            return;
        }

        if (get_option('eggl_garan_email_format', 'link') === 'attachment') {
            // The actual per-product attachments are added by add_email_attachments(), hooked
            // to woocommerce_email_attachments — this only prints the compact text lines.
            $rows = '';
            foreach ($items as $product_id => $data) {
                $rows .= sprintf(
                    '<tr><td style="padding:4px 0;font-family:inherit;font-size:14px;line-height:1.5;">'
                    . '<strong>%s</strong> — %s (%s): %s. %s.'
                    . '</td></tr>',
                    esc_html(EGGL_I18N::t('s_label_heading')),
                    esc_html($data['model']),
                    esc_html($data['brand']),
                    esc_html($data['years']),
                    esc_html(EGGL_I18N::t('s_attached'))
                );
            }
            printf('<table cellspacing="0" cellpadding="0" border="0" role="presentation" style="width:100%%;margin:24px 0;">%s</table>', $rows);
            return;
        }

        if (get_option('eggl_garan_email_format', 'link') !== 'image') {
            $rows = '';
            foreach ($items as $product_id => $data) {
                $rows .= sprintf(
                    '<tr><td style="padding:4px 0;font-family:inherit;font-size:14px;line-height:1.5;">'
                    . '<strong>%s</strong> — %s (%s): %s. <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>.'
                    . '</td></tr>',
                    esc_html(EGGL_I18N::t('s_label_heading')),
                    esc_html($data['model']),
                    esc_html($data['brand']),
                    esc_html($data['years']),
                    esc_url($this->garan_email_link_url($product_id, $data)),
                    esc_html(EGGL_I18N::t('s_link'))
                );
            }
            printf('<table cellspacing="0" cellpadding="0" border="0" role="presentation" style="width:100%%;margin:24px 0;">%s</table>', $rows);
            return;
        }

        // SVG doesn't work here at all: Gmail, Outlook.com, and Yahoo strip SVG images from
        // HTML email outright (it can carry embedded scripts), whether delivered as a remote
        // <img> or a CID attachment — this is a mail-client security policy, not a delivery
        // mechanics problem, and most hosts don't have Imagick+librsvg to rasterize it anyway.
        // So this composites a real PNG itself — a bundled pre-rendered background plus the
        // three dynamic strings drawn with GD (present on effectively every PHP install) — and
        // embeds that as a CID attachment, like the notice image. Falls back to the compact
        // text+link line, per product, if GD or the bundled assets are somehow unavailable —
        // better than silently shipping an image nothing shows.
        $width = (int) apply_filters('eggl_garan_email_width', 160);
        $aria  = EGGL_I18N::t('s_label_aria_full');

        $rows = '';
        foreach ($items as $product_id => $data) {
            if ($data['vendor_image_path'] !== '') {
                // Vendor-supplied image: embed the real uploaded file directly, the same way
                // render_email_notice() embeds the official notice PNG — no GD compositing.
                $size   = @getimagesize($data['vendor_image_path']);
                $height = ($size && $size[0] > 0) ? (int) round($width * $size[1] / $size[0]) : 0;

                self::$email_embeddable_images[$data['vendor_image_src']] = $data['vendor_image_path'];

                $rows .= sprintf(
                    '<tr><td style="padding:12px 0;"><img src="%s" alt="%s" width="%d"%s style="display:block;width:%dpx;height:auto;max-width:100%%;border:0;outline:none;text-decoration:none;" /></td></tr>',
                    esc_url($data['vendor_image_src']),
                    esc_attr($aria),
                    $width,
                    $height ? ' height="' . $height . '"' : '',
                    $width
                );
                continue;
            }

            $rendered = $this->garan_render_email_png($data['brand'], $data['model'], $data['years'], $width);

            if ($rendered !== false) {
                // A real, fetchable URL (output_garan_png_endpoint() below can regenerate the
                // same bytes on request), not just an internal marker — unlike a fake
                // placeholder scheme, this still loads even in contexts that never fire
                // phpmailer_init at all, e.g. WooCommerce's email "Preview", which renders the
                // HTML directly without ever sending it (so embed_email_garan_images()'s cid:
                // swap never runs there). Sent for real, it's still swapped to a cid:
                // attachment as usual, same as the legal-guarantee notice image.
                // esc_url() entity-encodes the query string's "&" (e.g. to "&#038;"), so the
                // map key must be the ESCAPED string — the raw $url never actually appears in
                // $phpmailer->Body, only this one does, and embed_email_garan_images() matches
                // by plain strpos().
                $url = esc_url($this->garan_png_endpoint_url($product_id, $width));
                self::$email_embeddable_garan_pngs[$url] = $rendered['bytes'];

                $rows .= sprintf(
                    '<tr><td style="padding:12px 0;"><img src="%s" alt="%s" width="%d" height="%d" style="display:block;width:%dpx;height:auto;max-width:100%%;border:0;outline:none;text-decoration:none;" /></td></tr>',
                    $url,
                    esc_attr($aria),
                    $width,
                    $rendered['height'],
                    $width
                );
                continue;
            }

            $rows .= sprintf(
                '<tr><td style="padding:4px 0;font-family:inherit;font-size:14px;line-height:1.5;">'
                . '<strong>%s</strong> — %s (%s): %s. <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>.'
                . '</td></tr>',
                esc_html(EGGL_I18N::t('s_label_heading')),
                esc_html($data['model']),
                esc_html($data['brand']),
                esc_html($data['years']),
                esc_url($this->garan_svg_endpoint_url($product_id)),
                esc_html(EGGL_I18N::t('s_link'))
            );
        }
        printf('<table cellspacing="0" cellpadding="0" border="0" role="presentation" style="width:100%%;margin:24px 0;">%s</table>', $rows);
    }

    /**
     * Adds the notice graphic and/or per-product GARAN label PNGs as real file attachments to
     * customer order emails, when the corresponding "Email content" setting is "attachment"
     * instead of "link"/"image". Hooked to WooCommerce's own woocommerce_email_attachments
     * filter, which runs at send time — separately from render_email_notice()/
     * render_email_garan(), which only print the compact text line for this format.
     */
    public function add_email_attachments($attachments, $email_id, $object = null, $email = null) {
        if (!is_array($attachments)) $attachments = [];
        $email_id = (string) $email_id;

        if (get_option('eggl_enable_notice', 'yes') === 'yes'
            && get_option('eggl_notice_email_enable', 'no') === 'yes'
            && get_option('eggl_notice_email_format', 'link') === 'attachment'
            && $this->email_notice_wanted($email_id)
        ) {
            $lang = EGGL_I18N::store_lang();
            $file = EGGL_PATH . 'assets/official/notice-' . $lang . '.png';
            if (!file_exists($file)) $file = EGGL_PATH . 'assets/official/notice-en.png';
            if (file_exists($file)) $attachments[] = $file;
        }

        if (get_option('eggl_enable_garan', 'yes') === 'yes'
            && get_option('eggl_garan_email_enable', 'no') === 'yes'
            && get_option('eggl_garan_email_format', 'link') === 'attachment'
            && $this->email_garan_wanted($email_id)
            && is_object($object) && method_exists($object, 'get_items')
        ) {
            $width = (int) apply_filters('eggl_garan_email_width', 160);
            foreach ($this->garan_email_items($object) as $product_id => $data) {
                if ($data['vendor_image_path'] !== '') {
                    $attachments[] = $data['vendor_image_path'];
                    continue;
                }

                $rendered = $this->garan_render_email_png($data['brand'], $data['model'], $data['years'], $width);
                if ($rendered === false) continue;

                $path = $this->write_garan_attachment_tmp($rendered['bytes'], $product_id);
                if ($path) $attachments[] = $path;
            }
        }

        return $attachments;
    }

    /** Writes GARAN label PNG bytes to a temp file for add_email_attachments() — there's no source file per product to attach directly, unlike the notice graphic. Tracked for cleanup_email_attachment_tmp_files(). Returns the path, or false on failure. */
    private function write_garan_attachment_tmp($bytes, $product_id) {
        $tmp = wp_tempnam('eggl-garan-' . (int) $product_id . '.png');
        if (!$tmp) return false;
        if (file_put_contents($tmp, $bytes) === false) {
            @unlink($tmp);
            return false;
        }
        self::$email_attachment_tmp_files[] = $tmp;
        return $tmp;
    }

    /** Deletes the temp GARAN label PNGs write_garan_attachment_tmp() created this request, once the email has been sent. Hooked to 'shutdown' so it runs regardless of how many emails fired. */
    public function cleanup_email_attachment_tmp_files() {
        foreach (self::$email_attachment_tmp_files as $file) {
            if (is_file($file)) @unlink($file);
        }
        self::$email_attachment_tmp_files = [];
    }

    /**
     * Composites the GARAN badge as a PNG for email: assets/official/email/garan-template.png
     * (garan-full.svg pre-rendered once at build time, with the brand/model/duration text
     * removed) plus the three dynamic strings drawn on top with GD, using the bundled Inter
     * fonts. No SVG parsing and no PHP extension beyond GD (present on effectively every PHP
     * install), so this doesn't depend on Imagick or the server having librsvg.
     *
     * Returns ['bytes' => <PNG string>, 'height' => <int>] sized to $width, or false if GD/the
     * bundled assets aren't usable — callers must handle the false case, not assume success.
     */
    private function garan_render_email_png($brand, $model, $years, $width) {
        // No GD/FreeType on this server: use the copy the admin's browser drew, if there is one yet.
        if (!EGGL_Garan_Raster::server_can_draw()) return EGGL_Garan_Raster::cached_render($brand, $model, $years, $width);

        $template_path = EGGL_PATH . 'assets/official/email/garan-template.png';
        $regular_font   = EGGL_PATH . 'assets/fonts/Inter-Regular.ttf';
        $extrabold_font = EGGL_PATH . 'assets/fonts/Inter-ExtraBold.ttf';
        if (!is_readable($template_path) || !is_readable($regular_font) || !is_readable($extrabold_font)) return false;

        $canvas = @imagecreatefrompng($template_path);
        if (!$canvas) return false;

        $canvas_width  = imagesx($canvas);
        $canvas_height = imagesy($canvas);
        imagesavealpha($canvas, true);

        // garan-full.svg's own viewBox is 269.29 x 283.46 — every coordinate/font-size below is
        // copied from that source file's <text transform="translate(...)"> and CSS font-size,
        // then scaled to whatever size garan-template.png actually was rendered at.
        $scale = ($canvas_width / 269.29 + $canvas_height / 283.46) / 2;
        $ink   = imagecolorallocate($canvas, 35, 31, 32); // #231f20, this design's own text color

        // Brand/model row: 9px text, baseline y=74.52, between x=6.32/196.75 and the row's own
        // right edge (the underline at x2="262.97" and the QR block's left edge respectively).
        $this->garan_draw_fitted_text($canvas, $regular_font, $ink, $brand,
            6.32 * $scale, 74.52 * $scale, (196.75 - 6.32 - 4) * $scale, 9 * $scale);
        $this->garan_draw_fitted_text($canvas, $regular_font, $ink, $model,
            196.75 * $scale, 74.52 * $scale, (262.97 - 196.75) * $scale, 9 * $scale);

        // Duration number: 80px extra-bold, baseline y=150.57, left-aligned at x=5.07 up to
        // the calendar icon's left edge (x=121.47).
        $this->garan_draw_fitted_text($canvas, $extrabold_font, $ink, (string) $years,
            5.07 * $scale, 150.57 * $scale, (121.47 - 5.07 - 4) * $scale, 80 * $scale);

        ob_start();
        imagepng($canvas);
        $png = ob_get_clean();
        imagedestroy($canvas);

        $height = (int) round($width * $canvas_height / $canvas_width);
        if ($width !== $canvas_width) {
            $png = $this->garan_resize_png($png, $width, $height);
        }

        return ['bytes' => $png, 'height' => $height];
    }

    /** Draws $text at ($x, $y baseline) with GD, shrinking the font size to fit $max_width, then truncating with an ellipsis if it's still too wide at the smallest size tried. Silently does nothing for empty text. */
    private function garan_draw_fitted_text($canvas, $font, $color, $text, $x, $y, $max_width, $size) {
        $text = trim((string) $text);
        if ($text === '' || $max_width <= 0) return;

        $min_size = max(6, $size * 0.55);
        while ($size > $min_size) {
            $box = imagettfbbox($size, 0, $font, $text);
            if (abs($box[2] - $box[0]) <= $max_width) break;
            $size -= 1;
        }

        $box = imagettfbbox($size, 0, $font, $text);
        if (abs($box[2] - $box[0]) > $max_width) {
            while (function_exists('mb_strlen') ? mb_strlen($text) > 1 : strlen($text) > 1) {
                $text = function_exists('mb_substr') ? mb_substr($text, 0, -1) : substr($text, 0, -1);
                $trial = rtrim($text) . '…';
                $box = imagettfbbox($size, 0, $font, $trial);
                if (abs($box[2] - $box[0]) <= $max_width) {
                    $text = $trial;
                    break;
                }
            }
        }

        imagettftext($canvas, $size, 0, (int) round($x), (int) round($y), $color, $font, $text);
    }

    /** Resizes PNG bytes to $width x $height, preserving transparency — used to scale garan_render_email_png()'s output (rendered at the bundled template's own resolution) down to the configured email display width. */
    private function garan_resize_png($png, $width, $height) {
        $src = @imagecreatefromstring($png);
        if (!$src) return $png;

        $dst = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefilledrectangle($dst, 0, 0, $width, $height, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $width, $height, imagesx($src), imagesy($src));

        ob_start();
        imagepng($dst);
        $resized = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return $resized;
    }

    /** Turns any render_email_garan() "image" format URL the outgoing email's body actually contains into a base64 CID attachment, embedding straight from the already-rendered PNG bytes (there's no source file per product to embed from). */
    public function embed_email_garan_images($phpmailer) {
        if (!self::$email_embeddable_garan_pngs || !is_object($phpmailer)) return;
        if (!method_exists($phpmailer, 'addStringEmbeddedImage') || !isset($phpmailer->Body)) return;

        $body = (string) $phpmailer->Body;
        foreach (self::$email_embeddable_garan_pngs as $url => $png) {
            if (strpos($body, $url) === false) continue;

            $cid = 'eggl-garan-' . md5($url);
            if (!$phpmailer->addStringEmbeddedImage($png, $cid, 'garan-label.png', 'base64', 'image/png')) continue;

            $body = str_replace($url, 'cid:' . $cid, $body);
        }
        $phpmailer->Body = $body;
    }

    /** Public URL that renders this product's GARAN label as a standalone PNG image, via output_garan_png_endpoint() below — used as the "image" email format's <img src>, so it still loads even where embed_email_garan_images() never runs (e.g. WooCommerce's email "Preview", which renders the HTML without ever sending it through phpmailer_init). */
    private function garan_png_endpoint_url($product_id, $width) {
        return add_query_arg(
            ['action' => 'eggl_garan_png', 'product_id' => (int) $product_id, 'w' => (int) $width],
            admin_url('admin-ajax.php')
        );
    }

    /**
     * Serves the GARAN label for ?product_id=<id>&w=<width> as a standalone image/png
     * response and exits — the target of garan_png_endpoint_url(). Reachable logged-in or
     * logged-out since the label shown on the product page is already public. 404s for
     * anything that isn't a real, currently-qualifying product, or if rendering fails.
     */
    public function output_garan_png_endpoint() {
        $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        $product = ($product_id && function_exists('wc_get_product')) ? wc_get_product($product_id) : false;

        if (!$product || !EGGL_Product_Fields::applies($product)) {
            status_header(404);
            exit;
        }

        $months = EGGL_Product_Fields::qualified_months($product);
        if (!$months) {
            status_header(404);
            exit;
        }

        $vendor_src = EGGL_Product_Fields::vendor_image_src($product);
        if ($vendor_src !== '') {
            wp_safe_redirect($vendor_src);
            exit;
        }

        $width = isset($_GET['w']) ? max(40, min(1000, absint($_GET['w']))) : 160;
        $years = max(1, (int) round($months / 12));
        $brand = EGGL_Product_Fields::brand($product);
        $model = EGGL_Product_Fields::model($product);

        $rendered = $this->garan_render_email_png($brand, $model, $years, $width);
        if ($rendered === false) {
            status_header(404);
            exit;
        }

        nocache_headers();
        header('Content-Type: image/png');
        echo $rendered['bytes'];
        exit;
    }

    /** Public URL that renders this product's GARAN label as a standalone SVG image, via output_garan_svg_endpoint() below — lets the "link" email format open the actual label graphic (with this product's brand/model/years) instead of the general learn-more page. */
    private function garan_svg_endpoint_url($product_id) {
        return add_query_arg(['action' => 'eggl_garan_svg', 'product_id' => (int) $product_id], admin_url('admin-ajax.php'));
    }

    /**
     * Serves the GARAN label for ?product_id=<id> as a standalone image/svg+xml
     * response and exits — the target of garan_svg_endpoint_url(), reachable
     * logged-in or logged-out since the label shown on the product page is
     * already public. 404s for anything that isn't a real, currently-qualifying
     * product, so it can't be used to render arbitrary label text.
     */
    public function output_garan_svg_endpoint() {
        $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        $product = ($product_id && function_exists('wc_get_product')) ? wc_get_product($product_id) : false;

        if (!$product || !EGGL_Product_Fields::applies($product)) {
            status_header(404);
            exit;
        }

        $months = EGGL_Product_Fields::qualified_months($product);
        if (!$months) {
            status_header(404);
            exit;
        }

        $vendor_src = EGGL_Product_Fields::vendor_image_src($product);
        if ($vendor_src !== '') {
            wp_safe_redirect($vendor_src);
            exit;
        }

        $years = max(1, (int) round($months / 12));
        $brand = EGGL_Product_Fields::brand($product);
        $model = EGGL_Product_Fields::model($product);
        $aria  = EGGL_I18N::t('s_label_aria_full');

        $svg = self::garan_svg_markup($brand, $model, $years, false, $aria);

        // garan_svg_markup() only sets viewBox, on purpose — on the product page it's sized by
        // CSS. Serving it as a bare image/svg+xml document left sizing/centering to each
        // browser's own built-in image viewer (inconsistent, and stretched to fill the
        // viewport with no width/height set) — wrap it in a minimal HTML page instead, so the
        // display size and centering are ours to control regardless of browser.
        $svg = preg_replace('/<svg /', '<svg style="display:block;width:100%;height:auto;" ', $svg, 1);
        $display_width = (int) apply_filters('eggl_garan_svg_width', 440);

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        printf(
            '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>%s</title></head>'
            . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f5f5f5;">'
            . '<div style="width:%dpx;max-width:90%%;">%s</div>'
            . '</body></html>',
            esc_html($aria),
            $display_width,
            $svg
        );
        exit;
    }

    /** Turns any render_email_notice()/vendor GARAN image the outgoing email's body actually contains into a base64 CID attachment. Mime type is detected per file rather than assumed, since this now also embeds vendor-uploaded images (jpg/png/webp/…), not just the plugin's own PNG notice graphic. */
    public function embed_email_images($phpmailer) {
        if (!self::$email_embeddable_images || !is_object($phpmailer)) return;
        if (!method_exists($phpmailer, 'addEmbeddedImage') || !isset($phpmailer->Body)) return;

        $body = (string) $phpmailer->Body;
        foreach (self::$email_embeddable_images as $url => $path) {
            if (strpos($body, $url) === false || !is_readable($path)) continue;

            $mime = wp_check_filetype($path)['type'];
            if (!$mime) $mime = 'image/png';

            $cid = 'eggl-notice-' . md5($path);
            if (!$phpmailer->addEmbeddedImage($path, $cid, basename($path), 'base64', $mime)) continue;

            $body = str_replace($url, 'cid:' . $cid, $body);
        }
        $phpmailer->Body = $body;
    }

    /** Fixed id: only one header-notice modal exists per page, regardless of placement. */
    const HEADER_MODAL_ID = 'eggl-modal-header-notice';

    /**
     * Text/image for the site-wide header notice (e.g. "Your legal guarantee
     * rights"), or null if the feature is off or the notice graphic for the
     * resolved language is missing.
     */
    private function header_notice_vars() {
        if (get_option('eggl_header_notice_enable', 'no') !== 'yes') return null;

        $lang = EGGL_I18N::store_lang();
        $file = EGGL_PATH . 'assets/official/notice-' . $lang . '.png';
        if (!file_exists($file)) {
            $lang = 'en';
            $file = EGGL_PATH . 'assets/official/notice-en.png';
        }
        if (!file_exists($file)) return null;

        return [
            'src'  => EGGL_URL . 'assets/official/notice-' . $lang . '.png',
            'alt'  => EGGL_I18N::t('s_notice_alt'),
            'text' => EGGL_I18N::t('s_header_notice'),
        ];
    }

    /**
     * Registered nav menu locations the header notice should be injected into
     * as a menu item — fully automatic, no merchant configuration. Guesses
     * the site's primary header menu, then adds any location that looks like
     * its responsive mobile/tablet counterpart (many themes, like Storefront,
     * render the same menu twice under different theme_locations — once for
     * desktop, once for a handheld/off-canvas toggle) so the notice shows up
     * consistently across desktop, tablet, and mobile. Works whether or not
     * the theme has an actual menu assigned — inject_menu_item() handles a
     * real assigned menu, inject_page_menu_item() handles the automatic
     * "list all pages" fallback WordPress shows (e.g. Home / Cart / Checkout)
     * when no menu has been created yet.
     */
    public static function resolve_menu_locations() {
        $registered = get_registered_nav_menus();
        if (!$registered) return [];

        $primary = '';
        foreach (['primary', 'main-menu', 'main', 'header', 'top'] as $guess) {
            if (isset($registered[$guess])) { $primary = $guess; break; }
        }
        if ($primary === '') {
            $keys = array_keys($registered);
            $primary = $keys[0];
        }

        $targets = [$primary];
        foreach (array_keys($registered) as $loc) {
            if ($loc !== $primary && preg_match('/handheld|mobile|toggle|off.?canvas|hamburger|small.?screen/i', $loc)) {
                $targets[] = $loc;
            }
        }
        return $targets;
    }

    /**
     * True when the header notice should render as a nav menu item (next to
     * Home / Cart / Checkout) rather than a standalone banner. Covers two
     * unrelated menu systems: classic themes (a registered nav_menu location,
     * handled by inject_menu_item()/inject_page_menu_item()) and block themes
     * (which register no nav_menu locations at all — their Navigation block
     * is instead caught by inject_navigation_block_item()). Assuming "yes" for
     * any block theme is optimistic — it's true for the common case of a
     * Navigation block in the site's header template part — but safe either
     * way: render_header_notice_fallback() catches it in the footer if no
     * Navigation block (or nav_menu location) actually rendered on this page,
     * so the notice never silently disappears.
     */
    private function header_notice_uses_menu() {
        if (!empty($this->resolve_menu_locations())) return true;
        return function_exists('wp_is_block_theme') && wp_is_block_theme();
    }

    /**
     * Standalone banner placement, shown on every front-end page via
     * wp_body_open. Skipped when the notice is instead injected into a nav
     * menu by inject_menu_item(). The harmonised notice graphic appears in a
     * small preview on mouse roll-over (pure CSS :hover / :focus-within, no
     * JS needed) and in full in a modal dialog, opened on click or tap.
     */
    public function render_header_notice() {
        if (is_admin() || $this->header_notice_uses_menu()) return;
        $vars = $this->header_notice_vars();
        if (!$vars) return;

        $eggl_modal_id    = self::HEADER_MODAL_ID;
        $eggl_notice_src  = $vars['src'];
        $eggl_notice_alt  = $vars['alt'];
        $eggl_header_text = $vars['text'];
        include EGGL_PATH . 'public/views/eggl-header-notice.php';
    }

    /**
     * Nav menu placement: appends the header notice as a menu item (e.g. next
     * to Home / Cart / Checkout) to the resolved theme_location's output.
     */
    public function inject_menu_item($items, $args) {
        if (is_admin() || !$this->header_notice_uses_menu()) return $items;
        if (empty($args->theme_location) || !in_array($args->theme_location, $this->resolve_menu_locations(), true)) return $items;

        $vars = $this->header_notice_vars();
        if (!$vars) return $items;

        $eggl_modal_id    = self::HEADER_MODAL_ID;
        $eggl_notice_src  = $vars['src'];
        $eggl_notice_alt  = $vars['alt'];
        $eggl_header_text = $vars['text'];
        ob_start();
        include EGGL_PATH . 'public/views/eggl-header-notice-menu-item.php';
        $this->header_notice_menu_rendered = true;
        return $items . ob_get_clean();
    }

    /**
     * Fallback nav menu placement: when the resolved theme_location has no
     * menu assigned yet, WordPress renders its own "list all pages" menu
     * (e.g. Home / Cart / Checkout / My account / Shop) via wp_page_menu()
     * instead of firing wp_nav_menu_items. This appends the header notice as
     * a matching <li> to that fallback list.
     */
    public function inject_page_menu_item($menu, $args) {
        if (is_admin() || !$this->header_notice_uses_menu()) return $menu;
        $theme_location = is_array($args) && !empty($args['theme_location']) ? $args['theme_location'] : '';
        if ($theme_location === '' || !in_array($theme_location, $this->resolve_menu_locations(), true)) return $menu;

        $vars = $this->header_notice_vars();
        if (!$vars) return $menu;

        $eggl_modal_id    = self::HEADER_MODAL_ID;
        $eggl_notice_src  = $vars['src'];
        $eggl_notice_alt  = $vars['alt'];
        $eggl_header_text = $vars['text'];
        ob_start();
        include EGGL_PATH . 'public/views/eggl-header-notice-menu-item.php';
        $li = ob_get_clean();

        // Splice the item in just before the outermost closing </ul>, since
        // $menu is the full "<div class=...><ul>...</ul></div>" markup.
        $pos = strripos($menu, '</ul>');
        $this->header_notice_menu_rendered = true;
        if ($pos === false) return $menu . $li;
        return substr($menu, 0, $pos) . $li . substr($menu, $pos);
    }

    /**
     * Block-theme nav menu placement: block themes register no nav_menu
     * location at all (inject_menu_item()/inject_page_menu_item() never
     * fire), and instead render navigation via the core/navigation block. WP
     * core fires "render_block_{$block['blockName']}" for every rendered
     * block regardless of type — the same generic mechanism this plugin
     * already uses for the WooCommerce Checkout block — so this catches every
     * core/navigation block on the page and appends the notice as an item.
     * Inserted right after the block's own <ul class="wp-block-navigation__container">
     * opening tag (not before its closing tag) to sidestep having to find the
     * *matching* closing tag among any nested submenu </ul>s.
     */
    public function inject_navigation_block_item($block_content, $block) {
        if (is_admin()) return $block_content;
        $vars = $this->header_notice_vars();
        if (!$vars) return $block_content;

        if (!preg_match('/<ul\b[^>]*\bclass="[^"]*\bwp-block-navigation__container\b[^"]*"[^>]*>/i', $block_content, $match, PREG_OFFSET_CAPTURE)) {
            return $block_content;
        }

        $eggl_modal_id    = self::HEADER_MODAL_ID;
        $eggl_notice_src  = $vars['src'];
        $eggl_notice_alt  = $vars['alt'];
        $eggl_header_text = $vars['text'];
        ob_start();
        include EGGL_PATH . 'public/views/eggl-header-notice-nav-block-item.php';
        $li = ob_get_clean();

        $insert_at = $match[0][1] + strlen($match[0][0]);
        $this->header_notice_menu_rendered = true;
        return substr($block_content, 0, $insert_at) . $li . substr($block_content, $insert_at);
    }

    /** The modal dialog for the nav-menu placement, printed once in the footer. */
    public function render_header_notice_modal() {
        if (is_admin() || !$this->header_notice_uses_menu()) return;
        $vars = $this->header_notice_vars();
        if (!$vars) return;

        $eggl_modal_id    = self::HEADER_MODAL_ID;
        $eggl_notice_src  = $vars['src'];
        $eggl_notice_alt  = $vars['alt'];
        $eggl_header_text = $vars['text'];
        include EGGL_PATH . 'public/views/eggl-header-notice-modal.php';
    }

    /**
     * Safety net for the nav-menu placement: some page templates (e.g. the
     * WooCommerce "Coming soon" canvas, or a page builder's own header) skip
     * the theme's normal navigation entirely, so neither inject_menu_item()
     * nor inject_page_menu_item() ever fires. If that happened, print the
     * notice near the end of the page instead of letting it disappear.
     */
    public function render_header_notice_fallback() {
        if (is_admin() || !$this->header_notice_uses_menu() || $this->header_notice_menu_rendered) return;
        $vars = $this->header_notice_vars();
        if (!$vars) return;

        $eggl_modal_id    = self::HEADER_MODAL_ID;
        $eggl_notice_src  = $vars['src'];
        $eggl_notice_alt  = $vars['alt'];
        $eggl_header_text = $vars['text'];
        echo '<div class="eggl-header-notice" data-eggl>';
        include EGGL_PATH . 'public/views/eggl-header-notice-trigger.php';
        echo '</div>';
    }

    public function render_garan() {
        global $product;
        if (!$product) return;
        if (get_option('eggl_enable_garan', 'yes') !== 'yes') return; // site-wide switch

        if (!$product->is_type('variable')) {
            $html = $this->product_garan_html($product, $product->get_id());
            if ($html === '') return;
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in eggl-garan.php
            $this->maybe_render_credit();
            return;
        }

        // Variable product: the parent's label shows until a variation is chosen, then
        // assets/js/eggl-frontend.js swaps in that variation's pre-rendered label (its own
        // overrides, falling back to the parent's EU Guarantee data — see
        // EGGL_Product_Fields::meta()), or hides it if the variation doesn't qualify.
        $default    = $this->product_garan_html($product, $product->get_id());
        $variations = [];
        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation || !$variation->variation_is_visible()) continue;
            $variations[(int) $variation_id] = $this->product_garan_html($variation, (int) $variation_id);
        }
        if ($default === '' && !array_filter($variations, 'strlen')) return;

        echo '<div class="eggl-garan-variations" data-eggl-garan-variations>';
        echo '<div data-eggl-garan-current>' . $default . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<template data-eggl-garan-default>' . $default . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        foreach ($variations as $variation_id => $html) {
            echo '<template data-eggl-garan-variation="' . esc_attr($variation_id) . '">' . $html . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        echo '</div>';
        $this->maybe_render_credit();
    }

    /** Product-page GARAN label HTML for $product (a simple product, variable parent or variation), or '' if it doesn't qualify. */
    private function product_garan_html($product, $uid) {
        if (!EGGL_Product_Fields::applies($product)) return ''; // out of scope (e.g. food)

        $eggl_vendor_image_src = EGGL_Product_Fields::vendor_image_src($product);
        $months = EGGL_Product_Fields::qualified_months($product);
        // A vendor-supplied label image is sufficient on its own — it doesn't also require the
        // years/brand/model fields on this product's EU Guarantee tab to be filled in.
        if (!$months && $eggl_vendor_image_src === '') return '';

        $eggl_uid   = $uid;
        $eggl_mode  = $this->garan_mode();
        $eggl_years = $months ? max(1, (int) round($months / 12)) : (EGGL_Product_Fields::vendor_years($product) ?: '');
        $eggl_brand = EGGL_Product_Fields::brand($product);
        $eggl_model = EGGL_Product_Fields::model($product);

        ob_start();
        include EGGL_PATH . 'public/views/eggl-garan.php';
        return ob_get_clean();
    }

    /**
     * GARAN label HTML for every unique, qualifying product currently in the
     * basket, keyed by product id — Article 8(2) of the Consumer Rights
     * Directive names the durability label among the information that must
     * be shown directly before the consumer places the order. Always the
     * compact "popup" display (badge that opens the full graphic in a
     * dialog on click), regardless of the product-page display mode
     * setting, to keep checkout/cart item rows compact. Shared by the
     * checkout and cart-page placements.
     */
    private function checkout_garan_cart_labels() {
        if ($this->checkout_garan_labels_cache !== null) return $this->checkout_garan_labels_cache;

        $labels = [];
        if (function_exists('WC') && WC()->cart) {
            $width = max(40, (int) get_option('eggl_garan_checkout_width', 96));

            $seen_ids = [];
            $seen_signatures = [];
            foreach (WC()->cart->get_cart() as $cart_item) {
                $product_id = $this->cart_item_label_id($cart_item);
                if ($product_id <= 0 || isset($seen_ids[$product_id])) continue;
                $seen_ids[$product_id] = true;
                if (!EGGL_Product_Fields::applies($product_id)) continue;

                $eggl_vendor_image_src = EGGL_Product_Fields::vendor_image_src($product_id);
                $months = EGGL_Product_Fields::qualified_months($product_id);
                // A vendor-supplied label image qualifies the product on its own — see the
                // matching comment in render_garan().
                if (!$months && $eggl_vendor_image_src === '') continue;

                $product = wc_get_product($product_id);
                if (!$product) continue;

                $eggl_uid   = $this->next_manual_uid();
                $eggl_mode  = 'popup';
                $eggl_years = $months ? max(1, (int) round($months / 12)) : (EGGL_Product_Fields::vendor_years($product_id) ?: '');
                $eggl_brand = EGGL_Product_Fields::brand($product);
                $eggl_model = EGGL_Product_Fields::model($product);

                // Items carrying an identical label share one: only the first such row shows it.
                $signature = self::garan_signature($eggl_vendor_image_src, $eggl_years, $eggl_brand, $eggl_model);
                if (isset($seen_signatures[$signature])) continue;
                $seen_signatures[$signature] = true;

                ob_start();
                include EGGL_PATH . 'public/views/eggl-garan.php';
                $label_html = ob_get_clean();

                // Scoped inline override of the shared --eggl-garan-width variable, so the
                // configured checkout/cart size applies here regardless of the main "Badge
                // width" setting (used by the product-page placement) or any width set by
                // an ancestor (e.g. the block checkout's thumbnail-column CSS).
                $labels[$product_id] = '<div style="--eggl-garan-width:' . $width . 'px">' . $label_html . '</div>';
            }
        }

        return $this->checkout_garan_labels_cache = $labels;
    }

    /** The id a cart item's label is keyed by — the variation's own id for a variable product (so per-variation overrides apply), otherwise the product id. */
    private function cart_item_label_id($cart_item) {
        if (!is_array($cart_item)) return 0;
        if (!empty($cart_item['variation_id'])) return (int) $cart_item['variation_id'];
        return isset($cart_item['product_id']) ? (int) $cart_item['product_id'] : 0;
    }

    /** Classic checkout: appends the label under each qualifying cart-item row's quantity. */
    public function render_checkout_garan_cart_item($html, $cart_item, $cart_item_key) {
        if (get_option('eggl_enable_garan', 'yes') !== 'yes') return $html;
        if (get_option('eggl_garan_checkout_enable', 'yes') !== 'yes') return $html;

        $product_id = $this->cart_item_label_id($cart_item);
        if ($product_id <= 0) return $html;

        $labels = $this->checkout_garan_cart_labels();
        if (!isset($labels[$product_id])) return $html;

        $this->ensure_frontend_assets();
        return $html . '<div class="eggl-garan-checkout-slot">' . $labels[$product_id] . '</div>';
    }

    /**
     * Classic Cart page: prints the label right after the product name, inside the
     * same "product-name" column as the rest of that item's details (not the
     * thumbnail column) — woocommerce_after_cart_item_name fires exactly once per
     * row, unlike woocommerce_cart_item_name (used twice per row for different
     * purposes: an internal aria-label lookup and the actual rendered name), so
     * there's no risk of double-printing or leaking markup into that aria-label.
     */
    public function render_cart_garan_name($cart_item, $cart_item_key) {
        if (get_option('eggl_enable_garan', 'yes') !== 'yes') return;
        if (get_option('eggl_garan_checkout_enable', 'yes') !== 'yes') return;

        $product_id = $this->cart_item_label_id($cart_item);
        if ($product_id <= 0) return;

        $labels = $this->checkout_garan_cart_labels();
        if (!isset($labels[$product_id])) return;

        $this->ensure_frontend_assets();
        echo '<div class="eggl-garan-checkout-slot">' . $labels[$product_id] . '</div>';
    }

    /**
     * Block checkout: cart items render client-side (React), so there's no
     * per-row PHP filter to hook — instead, this hands each qualifying
     * product's pre-rendered label HTML to assets/js/eggl-garan-checkout.js
     * via wp_localize_script, and that script injects it using WooCommerce
     * Blocks' checkout extensibility APIs.
     */
    public function enqueue_checkout_garan_block_assets() {
        if (!function_exists('is_checkout') || !is_checkout()) return;
        if (get_option('eggl_enable_garan', 'yes') !== 'yes') return;
        if (get_option('eggl_garan_checkout_enable', 'yes') !== 'yes') return;

        $labels = $this->checkout_garan_cart_labels();
        if (!$labels) return;

        $post = get_post();
        if (!($post instanceof WP_Post) || !has_block('woocommerce/checkout', $post) || !wp_script_is('wc-blocks-checkout', 'registered')) {
            return;
        }

        $this->ensure_frontend_assets();

        wp_enqueue_script('eggl-garan-checkout', EGGL_URL . 'assets/js/eggl-garan-checkout.js',
            ['wp-element', 'wp-plugins', 'wc-blocks-checkout'], eggl_asset_version('assets/js/eggl-garan-checkout.js'), true);

        $keyed = [];
        foreach ($labels as $product_id => $label_html) {
            $keyed[(string) $product_id] = $label_html;
        }
        wp_localize_script('eggl-garan-checkout', 'egglGaranCheckout', ['labels' => $keyed]);
    }

    /** Registers the [eu_guarantee_notice]/[eu_garan] shortcodes and their matching blocks. Hooked on 'init'. */
    public function register_manual_placement() {
        add_shortcode('eu_guarantee_notice', [$this, 'shortcode_notice']);
        add_shortcode('eu_garan', [$this, 'shortcode_garan']);

        register_block_type(EGGL_PATH . 'blocks/notice', ['render_callback' => [$this, 'render_block_notice']]);
        register_block_type(EGGL_PATH . 'blocks/garan', ['render_callback' => [$this, 'render_block_garan']]);
    }

    /** Editor-only assets for the two blocks, including the GARAN block's live-preview SVG template. */
    public function enqueue_block_editor_assets() {
        wp_enqueue_script('eggl-block-notice', EGGL_URL . 'blocks/notice/edit.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n'],
            eggl_asset_version('blocks/notice/edit.js'), true);

        wp_enqueue_script('eggl-block-garan', EGGL_URL . 'blocks/garan/edit.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'],
            eggl_asset_version('blocks/garan/edit.js'), true);

        wp_enqueue_style('eggl-block-editor', EGGL_URL . 'assets/css/eggl-block-editor.css', [],
            eggl_asset_version('assets/css/eggl-block-editor.css'));

        // Same template garan_svg_markup() renders server-side (with placeholder-text ids), so the
        // block's JS can swap text nodes live — see the matching technique in eggl-admin-product.js.
        $garan_template = self::garan_svg_markup('', '', '', true, __('GARAN durability label preview', 'garanlabs-eu-guarantee-notice-durability-labels'));
        wp_add_inline_script('eggl-block-garan', 'window.EGGL_GARAN_TEMPLATE = ' . wp_json_encode($garan_template) . ';', 'before');
    }

    /**
     * [eu_guarantee_notice] shortcode and the "EU Guarantee Notice" block's render callback.
     * Manual placement: not tied to a product, so skips EGGL_Product_Fields::applies() —
     * only the site-wide "Enable" switch gates it.
     */
    public function shortcode_notice($atts = []) {
        if (get_option('eggl_enable_notice', 'yes') !== 'yes') return '';

        $lang = EGGL_I18N::store_lang();
        $file = EGGL_PATH . 'assets/official/notice-' . $lang . '.png';
        if (!file_exists($file)) {
            $lang = 'en';
            $file = EGGL_PATH . 'assets/official/notice-en.png';
        }
        if (!file_exists($file)) return '';

        $eggl_uid         = $this->next_manual_uid();
        $eggl_mode        = $this->notice_mode();
        $eggl_style       = $this->notice_style();
        $eggl_custom_text = $this->notice_custom_text();
        $eggl_custom_size = $this->notice_custom_size();
        $eggl_notice_src  = EGGL_URL . 'assets/official/notice-' . $lang . '.png';
        $eggl_notice_alt  = EGGL_I18N::t('s_notice_alt');

        ob_start();
        include EGGL_PATH . 'public/views/eggl-notice.php';
        $this->maybe_render_credit();
        return ob_get_clean();
    }

    /** "EU Guarantee Notice" block's render_callback. */
    public function render_block_notice($block_attributes = [], $content = '', $block = null) {
        return $this->shortcode_notice([]);
    }

    /**
     * [eu_garan] shortcode and the "GARAN label" block's render callback.
     *
     * With no attributes given (or all three left blank), pulls years/brand/model from the
     * current product's own saved GARAN data — the same _eggl_brand/_eggl_model/
     * _eggl_manufacturer_months meta the automatic per-product placement uses — and respects
     * that product's eligibility (EGGL_Product_Fields::qualified_months()), so it renders
     * nothing for a product that doesn't qualify. Requires a product context
     * (e.g. placed on a single product page).
     *
     * Passing years/brand/model explicitly (e.g. [eu_garan years="5" brand="Acme"
     * model="AC-1200"]) overrides the saved product data and skips the eligibility check
     * entirely, so it renders unconditionally wherever placed.
     */
    public function shortcode_garan($atts = []) {
        if (get_option('eggl_enable_garan', 'yes') !== 'yes') return '';

        $atts = shortcode_atts(['years' => '', 'brand' => '', 'model' => ''], (array) $atts, 'eu_garan');
        $explicit = ($atts['years'] !== '' || $atts['brand'] !== '' || $atts['model'] !== '');

        $eggl_vendor_image_src = '';

        if ($explicit) {
            // An explicit years/brand/model override bypasses the product's own saved data
            // entirely (see the class doc above), so a vendor image tied to that product
            // doesn't apply here either.
            $eggl_years = $atts['years'] !== '' ? max(1, (int) $atts['years']) : '';
            $eggl_brand = $atts['brand'] !== '' ? sanitize_text_field((string) $atts['brand']) : get_bloginfo('name');
            $eggl_model = $atts['model'] !== '' ? sanitize_text_field((string) $atts['model']) : '';
        } else {
            global $product;
            if (!$product) return '';
            if (!EGGL_Product_Fields::applies($product)) return '';

            $eggl_vendor_image_src = EGGL_Product_Fields::vendor_image_src($product);
            $months = EGGL_Product_Fields::qualified_months($product);
            // A vendor-supplied label image qualifies the product on its own — see the
            // matching comment in render_garan().
            if (!$months && $eggl_vendor_image_src === '') return '';

            $eggl_years = $months ? max(1, (int) round($months / 12)) : (EGGL_Product_Fields::vendor_years($product) ?: '');
            $eggl_brand = EGGL_Product_Fields::brand($product);
            $eggl_model = EGGL_Product_Fields::model($product);
        }

        $eggl_uid  = $this->next_manual_uid();
        $eggl_mode = $this->garan_mode();

        ob_start();
        include EGGL_PATH . 'public/views/eggl-garan.php';
        $this->maybe_render_credit();
        return ob_get_clean();
    }

    /** "GARAN label" block's render_callback. */
    public function render_block_garan($block_attributes = []) {
        // The block's "Use this product's saved data" toggle decides explicitly whether to pull
        // from the product's saved EU Guarantee data or use the block's own years/brand/model
        // fields — deterministic, rather than inferring it from the fields being blank.
        $use_product_data = !isset($block_attributes['useProductData']) || $block_attributes['useProductData'];
        if ($use_product_data) {
            return $this->shortcode_garan([]);
        }

        // block.json's "years" attribute defaults to 0 (no empty-string state for a number
        // attribute) — treat 0/unset the same as the shortcode's default empty years.
        $years = isset($block_attributes['years']) ? (int) $block_attributes['years'] : 0;
        return $this->shortcode_garan([
            'years' => $years > 0 ? $years : '',
            'brand' => isset($block_attributes['brand']) ? $block_attributes['brand'] : '',
            'model' => isset($block_attributes['model']) ? $block_attributes['model'] : '',
        ]);
    }

    /** Fixed id: only one checkout notice modal exists per page. */
    const CHECKOUT_MODAL_ID = 'eggl-modal-checkout-notice';

    /**
     * Text/image for the checkout-page notice, or null if the placement is
     * off or the notice graphic for the resolved language is missing.
     * Independent of the per-product notice's display-mode setting — the
     * checkout notice is always the compact "popup" style, since it isn't
     * tied to a single product.
     */
    private function checkout_notice_vars() {
        if (get_option('eggl_placement_checkout', 'no') !== 'yes') return null;

        $lang = EGGL_I18N::store_lang();
        $file = EGGL_PATH . 'assets/official/notice-' . $lang . '.png';
        if (!file_exists($file)) {
            $lang = 'en';
            $file = EGGL_PATH . 'assets/official/notice-en.png';
        }
        if (!file_exists($file)) return null;

        return [
            'src'   => EGGL_URL . 'assets/official/notice-' . $lang . '.png',
            'alt'   => EGGL_I18N::t('s_notice_alt'),
            'label' => EGGL_I18N::t('s_notice_title'),
        ];
    }

    /** Classic checkout template (the [woocommerce_checkout] shortcode), right under the page's "Checkout" heading. */
    public function render_checkout_notice() {
        if (is_admin() || $this->checkout_notice_rendered) return;
        $vars = $this->checkout_notice_vars();
        if (!$vars) return;

        $this->checkout_notice_rendered = true;
        $eggl_modal_id    = self::CHECKOUT_MODAL_ID;
        $eggl_notice_src  = $vars['src'];
        $eggl_notice_alt  = $vars['alt'];
        $eggl_header_text = $vars['label'];
        $eggl_style       = $this->notice_style();
        $eggl_custom_text = $this->notice_custom_text();
        $eggl_custom_size = $this->notice_custom_size();
        include EGGL_PATH . 'public/views/eggl-checkout-notice.php';
    }

    /** Block-based checkout template (the Checkout block), prepended just above the block's own content. */
    public function render_checkout_notice_block($block_content, $block) {
        if (is_admin() || $this->checkout_notice_rendered) return $block_content;
        $name = is_array($block) && !empty($block['blockName']) ? $block['blockName'] : '';
        if ($name !== 'woocommerce/checkout') return $block_content;

        $vars = $this->checkout_notice_vars();
        if (!$vars) return $block_content;

        $this->checkout_notice_rendered = true;
        $eggl_modal_id    = self::CHECKOUT_MODAL_ID;
        $eggl_notice_src  = $vars['src'];
        $eggl_notice_alt  = $vars['alt'];
        $eggl_header_text = $vars['label'];
        $eggl_style       = $this->notice_style();
        $eggl_custom_text = $this->notice_custom_text();
        $eggl_custom_size = $this->notice_custom_size();
        ob_start();
        include EGGL_PATH . 'public/views/eggl-checkout-notice.php';
        return ob_get_clean() . (string) $block_content;
    }
}
