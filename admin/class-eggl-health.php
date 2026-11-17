<?php
if (!defined('ABSPATH')) exit;

/**
 * Tells the merchant, in plain words, whether the notice and the GARAN label
 * are actually being shown — under Tools > Site Health, and as a status panel
 * at the top of the plugin's settings page. Both read the same checks().
 */
class EGGL_Health {

    /** Upper bound on products scanned for over-long label text, to keep Site Health fast on big catalogues. */
    const FIT_SCAN_LIMIT = 300;

    public function register_tests($tests) {
        $tests['direct']['eggl_notice'] = [
            'label' => __('EU legal-guarantee notice', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'test'  => [$this, 'test_notice'],
        ];
        $tests['direct']['eggl_garan'] = [
            'label' => __('GARAN durability label', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'test'  => [$this, 'test_garan'],
        ];
        return $tests;
    }

    public function test_notice() {
        return $this->site_health_result('eggl_notice', $this->notice_checks());
    }

    public function test_garan() {
        return $this->site_health_result('eggl_garan', $this->garan_checks());
    }

    /** Worst status wins; every check's message is listed in the description. */
    private function site_health_result($test, $checks) {
        $order  = ['good' => 0, 'recommended' => 1, 'critical' => 2];
        $worst  = $checks[0];
        foreach ($checks as $check) {
            if ($order[$check['status']] > $order[$worst['status']]) $worst = $check;
        }

        $description = '';
        foreach ($checks as $check) {
            $description .= '<p>' . esc_html($check['message']) . '</p>';
        }

        return [
            'label'       => $worst['title'],
            'status'      => $worst['status'],
            'badge'       => ['label' => __('EU guarantee', 'garanlabs-eu-guarantee-notice-durability-labels'), 'color' => $worst['status'] === 'good' ? 'green' : ($worst['status'] === 'critical' ? 'red' : 'orange')],
            'description' => $description,
            'actions'     => sprintf('<p><a href="%s">%s</a></p>', esc_url(admin_url('admin.php?page=' . EGGL_Settings::PAGE_SLUG)), esc_html__('Open the EU Guarantee settings', 'garanlabs-eu-guarantee-notice-durability-labels')),
            'test'        => $test,
        ];
    }

    /** @return array[] each ['status' => good|recommended|critical, 'title' => string, 'message' => string] */
    public function notice_checks() {
        $checks = [];

        if (get_option('eggl_enable_notice', 'yes') !== 'yes') {
            return [[
                'status'  => 'critical',
                'title'   => __('The legal-guarantee notice is switched off', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'message' => __('The notice is turned off in the plugin settings, so it is not shown anywhere. Every trader selling goods to EU consumers must display it — turn "Enable" back on.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            ]];
        }

        $lang = EGGL_I18N::store_lang();
        if (!file_exists(EGGL_PATH . 'assets/official/notice-' . $lang . '.png')) {
            if (!file_exists(EGGL_PATH . 'assets/official/notice-en.png')) {
                return [[
                    'status'  => 'critical',
                    'title'   => __('The legal-guarantee notice artwork is missing', 'garanlabs-eu-guarantee-notice-durability-labels'),
                    'message' => __('The official notice image files are missing from the plugin folder, so the notice cannot be shown anywhere. Reinstall the plugin to restore them.', 'garanlabs-eu-guarantee-notice-durability-labels'),
                ]];
            }
            $checks[] = [
                'status'  => 'recommended',
                'title'   => __('The notice is shown in English instead of your language', 'garanlabs-eu-guarantee-notice-durability-labels'),
                /* translators: %s: language code, e.g. "de" */
                'message' => sprintf(__('The official notice for your display language (%s) is missing from the plugin folder, so the English version is shown instead. Reinstall the plugin to restore it.', 'garanlabs-eu-guarantee-notice-durability-labels'), $lang),
            ];
        }

        if (!class_exists('WooCommerce')) {
            $checks[] = [
                'status'  => 'recommended',
                'title'   => __('The legal-guarantee notice is not placed automatically', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'message' => __('Automatic placement on product pages, the checkout and order emails needs WooCommerce. Without it, add the [eu_guarantee_notice] shortcode or the "EU Guarantee Notice" block to your pages yourself — this plugin cannot see where you put it, so it cannot check that for you.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            ];
            return $checks;
        }

        $places = [__('product pages', 'garanlabs-eu-guarantee-notice-durability-labels')];
        if (get_option('eggl_placement_checkout', 'no') === 'yes') $places[] = __('the checkout', 'garanlabs-eu-guarantee-notice-durability-labels');
        if (get_option('eggl_notice_email_enable', 'no') === 'yes') $places[] = __('order confirmation emails', 'garanlabs-eu-guarantee-notice-durability-labels');
        if (get_option('eggl_header_notice_enable', 'no') === 'yes') $places[] = __('the site header', 'garanlabs-eu-guarantee-notice-durability-labels');

        $checks[] = [
            'status'  => 'good',
            'title'   => __('The legal-guarantee notice is shown automatically', 'garanlabs-eu-guarantee-notice-durability-labels'),
            /* translators: %s: list of places, e.g. "product pages, the checkout" */
            'message' => sprintf(__('Shown on %s.', 'garanlabs-eu-guarantee-notice-durability-labels'), implode(', ', $places)),
        ];

        return $checks;
    }

    /** @return array[] same shape as notice_checks() */
    public function garan_checks() {
        if (!class_exists('WooCommerce')) {
            return [[
                'status'  => 'good',
                'title'   => __('The GARAN label is placed manually', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'message' => __('Without WooCommerce, add the [eu_garan years="…" brand="…" model="…"] shortcode or the "GARAN Label" block wherever a product carries a qualifying durability guarantee.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            ]];
        }

        $qualifying = $this->qualifying_product_ids();

        if (get_option('eggl_enable_garan', 'yes') !== 'yes') {
            if (!$qualifying) {
                return [[
                    'status'  => 'good',
                    'title'   => __('The GARAN label is switched off', 'garanlabs-eu-guarantee-notice-durability-labels'),
                    'message' => __('No product has a qualifying durability guarantee filled in, so nothing is missing.', 'garanlabs-eu-guarantee-notice-durability-labels'),
                ]];
            }
            return [[
                'status'  => 'recommended',
                'title'   => __('The GARAN label is switched off, but products qualify for it', 'garanlabs-eu-guarantee-notice-durability-labels'),
                /* translators: %d: number of products */
                'message' => sprintf(_n('%d product has a qualifying durability guarantee, but the GARAN label is turned off site-wide, so it is not shown.', '%d products have a qualifying durability guarantee, but the GARAN label is turned off site-wide, so it is not shown.', count($qualifying), 'garanlabs-eu-guarantee-notice-durability-labels'), count($qualifying)),
            ]];
        }

        if (!$qualifying) {
            return [[
                'status'  => 'good',
                'title'   => __('No product carries a GARAN label yet', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'message' => __('The label only applies where the producer offers a free, whole-product durability guarantee of more than 2 years. Fill in the EU Guarantee tab on those products (or upload a vendor label) and it appears automatically.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            ]];
        }

        $checks = [[
            'status'  => 'good',
            'title'   => __('The GARAN label is shown automatically', 'garanlabs-eu-guarantee-notice-durability-labels'),
            /* translators: %d: number of products */
            'message' => sprintf(_n('%d product shows the GARAN label.', '%d products show the GARAN label.', count($qualifying), 'garanlabs-eu-guarantee-notice-durability-labels'), count($qualifying)),
        ]];

        $too_long = [];
        foreach (array_slice($qualifying, 0, self::FIT_SCAN_LIMIT) as $id) {
            $product = wc_get_product($id);
            if ($product && EGGL_Product_Fields::fit_problems($product)) $too_long[] = $product->get_name();
        }
        if ($too_long) {
            $checks[] = [
                'status'  => 'recommended',
                'title'   => __('Some GARAN labels have text that is too long', 'garanlabs-eu-guarantee-notice-durability-labels'),
                /* translators: %s: list of product names */
                'message' => sprintf(__('The brand, model or duration is too wide for the official label on: %s. It is squeezed to fit — shorten it on the product\'s EU Guarantee tab.', 'garanlabs-eu-guarantee-notice-durability-labels'), implode(', ', array_slice($too_long, 0, 10)) . (count($too_long) > 10 ? ', …' : '')),
            ];
        }

        if (get_option('eggl_garan_checkout_enable', 'yes') !== 'yes') {
            $checks[] = [
                'status'  => 'recommended',
                'title'   => __('The GARAN label is not shown at checkout', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'message' => __('Article 8(2) of the Consumer Rights Directive names the durability label among the information to show directly before the order is placed. Turn on "Checkout page" in the GARAN settings.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            ];
        }

        $format = get_option('eggl_garan_email_format', 'link');
        if (get_option('eggl_garan_email_enable', 'no') === 'yes' && $format !== 'link'
            && (!function_exists('imagecreatefrompng') || !function_exists('imagettftext'))) {
            $checks[] = [
                'status'  => 'recommended',
                'title'   => __('Your server cannot draw GARAN label images for emails', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'message' => __('PHP\'s GD image extension (with FreeType) is missing, so label images for emails are drawn in your browser instead whenever you open a product in the editor. Until a product has been opened once, its emails carry a text line with a link. Ask your host to enable GD for fully automatic images.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            ];
        }

        return $checks;
    }

    /** Ids of products/variations that currently show a GARAN label (generated or vendor image). */
    private function qualifying_product_ids() {
        global $wpdb;
        // Candidates: anything with a >2-year duration or a vendor image; qualification is then
        // confirmed per product, since variations inherit from their parent.
        $ids = $wpdb->get_col(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_eggl_manufacturer_months'
             LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_eggl_vendor_garan_image_id'
             WHERE p.post_type IN ('product', 'product_variation') AND p.post_status = 'publish'
               AND (CAST(m.meta_value AS UNSIGNED) > 24 OR v.meta_value IS NOT NULL)"
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (!EGGL_Product_Fields::applies($id)) continue;
            if (EGGL_Product_Fields::qualified_months($id) || EGGL_Product_Fields::vendor_image_src($id) !== '') $out[] = $id;
        }
        return $out;
    }

    /** Status panel at the top of the settings page: problems first, or one quiet "all good" line. */
    public function render_panel() {
        $notice = $this->notice_checks();
        $garan  = $this->garan_checks();

        $problems = array_filter(array_merge($notice, $garan), function ($c) { return $c['status'] !== 'good'; });
        $good     = array_filter(array_merge($notice, $garan), function ($c) { return $c['status'] === 'good'; });

        echo '<div class="eggl-health">';
        foreach ($problems as $check) {
            printf('<div class="eggl-health__item eggl-health__item--%s"><strong>%s</strong> %s</div>',
                esc_attr($check['status']), esc_html($check['title']), esc_html($check['message']));
        }
        foreach ($good as $check) {
            printf('<div class="eggl-health__item eggl-health__item--good"><strong>%s</strong> %s</div>',
                esc_html($check['title']), esc_html($check['message']));
        }
        echo '</div>';
    }
}
