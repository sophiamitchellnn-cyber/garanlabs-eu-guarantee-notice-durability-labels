<?php
if (!defined('ABSPATH')) exit;

/**
 * "Your right to repair" notice: tells consumers they may choose repair or replacement for a
 * defect within the legal guarantee, and that choosing repair extends the guarantee (Directive
 * (EU) 2024/1799). Storefront text follows the plugin's display language (EGGL_I18N), like the
 * legal-guarantee notice. Always shown on the claim form; optionally on product pages
 * ([eu_repair_notice] anywhere) and in order emails.
 */
class EGGL_Repair_Notice {

    public static function title() {
        return EGGL_I18N::t('s_rr_title');
    }

    public static function body() {
        return sprintf(EGGL_I18N::t('s_rr_body'), EGGL_Repair_Settings::extension_months());
    }

    /**
     * "24 months" in the storefront language, with the right word form for the number (several
     * EU languages change the noun with the count, e.g. Polish 24 miesiące / 12 miesięcy).
     */
    public static function months_label($n, $lang = null) {
        $n    = (int) $n;
        $lang = $lang ?: EGGL_I18N::store_lang();
        $m10  = $n % 10;
        $m100 = $n % 100;
        switch ($lang) {
            case 'pl': $w = ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) ? 'miesiące' : ($n === 1 ? 'miesiąc' : 'miesięcy'); break;
            case 'hr': $w = ($m10 === 1 && $m100 !== 11) ? 'mjesec' : (($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) ? 'mjeseca' : 'mjeseci'); break;
            case 'sl': $w = $m100 === 1 ? 'mesec' : ($m100 === 2 ? 'meseca' : (($m100 === 3 || $m100 === 4) ? 'mesece' : 'mesecev')); break;
            case 'cs': $w = $n === 1 ? 'měsíc' : (($n >= 2 && $n <= 4) ? 'měsíce' : 'měsíců'); break;
            case 'sk': $w = $n === 1 ? 'mesiac' : (($n >= 2 && $n <= 4) ? 'mesiace' : 'mesiacov'); break;
            case 'lt': $w = ($m10 === 1 && $m100 !== 11) ? 'mėnuo' : (($m10 === 0 || ($m100 >= 11 && $m100 <= 19)) ? 'mėnesių' : 'mėnesiai'); break;
            case 'lv': $w = ($m10 === 1 && $m100 !== 11) ? 'mēnesis' : 'mēneši'; break;
            case 'ro': $w = $n === 1 ? 'lună' : (($n === 0 || ($m100 >= 20 || $m100 === 0)) ? 'de luni' : 'luni'); break;
            case 'mt': $w = $n === 1 ? 'xahar' : (($m100 >= 2 && $m100 <= 10) ? 'xhur' : 'xahar'); break;
            case 'bg': $w = $n === 1 ? 'месец' : 'месеца'; break;
            case 'el': $w = $n === 1 ? 'μήνας' : 'μήνες'; break;
            case 'fi': $w = $n === 1 ? 'kuukausi' : 'kuukautta'; break;
            case 'et': $w = $n === 1 ? 'kuu' : 'kuud'; break;
            case 'hu': $w = 'hónap'; break;
            case 'ga': $w = 'mí'; break;
            case 'de': $w = $n === 1 ? 'Monat' : 'Monate'; break;
            case 'fr': $w = 'mois'; break;
            case 'es': $w = $n === 1 ? 'mes' : 'meses'; break;
            case 'pt': $w = $n === 1 ? 'mês' : 'meses'; break;
            case 'it': $w = $n === 1 ? 'mese' : 'mesi'; break;
            case 'nl': $w = $n === 1 ? 'maand' : 'maanden'; break;
            case 'sv': $w = $n === 1 ? 'månad' : 'månader'; break;
            case 'da': $w = $n === 1 ? 'måned' : 'måneder'; break;
            default:   $w = $n === 1 ? 'month' : 'months';
        }
        return $n . ' ' . $w;
    }

    /** Legal guarantee period of a product (its own, else the store default), in months. */
    public static function product_months($product) {
        $own = class_exists('EGGL_Product_Fields') ? EGGL_Product_Fields::legal_months($product) : 0;
        return $own ?: EGGL_Repair_Settings::guarantee_years() * 12;
    }

    /** "Legal guarantee for this product: 24 months from delivery." */
    public static function period_text($months) {
        return sprintf(EGGL_I18N::t('s_rr_period'), self::months_label($months));
    }

    /** Notice HTML. $link: also link to the claim form (when its page is published). $product: add its legal guarantee period. */
    public static function html($link = true, $product = null) {
        $url = $link ? EGGL_Repair_Settings::form_url() : '';
        $period = '';
        $variation_periods = [];
        if ($product instanceof WC_Product) {
            $months = self::product_months($product);
            $period = self::period_text($months);
            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $variation_id) {
                    $variation = wc_get_product($variation_id);
                    if ($variation && self::product_months($variation) !== $months) {
                        $variation_periods[(int) $variation_id] = self::period_text(self::product_months($variation));
                    }
                }
            }
        }
        ob_start();
        ?>
        <div class="eggl-rr-notice" role="note">
            <span class="eggl-rr-notice__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
            </span>
            <div class="eggl-rr-notice__text">
                <strong><?php echo esc_html(self::title()); ?></strong>
                <?php if ($period !== '') : ?>
                    <p class="eggl-rr-notice__period" data-eggl-rr-period="<?php echo esc_attr($period); ?>"<?php echo $variation_periods ? ' data-eggl-rr-variations="' . esc_attr(wp_json_encode($variation_periods)) . '"' : ''; ?>><?php echo esc_html($period); ?></p>
                <?php endif; ?>
                <p><?php echo esc_html(self::body()); ?></p>
                <?php if ($url) : ?>
                    <a class="eggl-rr-notice__link" href="<?php echo esc_url($url); ?>"><?php echo esc_html(EGGL_I18N::t('s_rr_link')); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Product-page version: a compact row like the legal-guarantee notice's "nested" row
     * ("Your right to repair" + the product's period, chevron), expanding in place to the full
     * text. Uses the plugin's shared .eggl-row styles and toggle script (eggl-frontend.css/js).
     */
    public static function compact_html(WC_Product $product) {
        $months   = self::product_months($product);
        $panel_id = 'eggl-rr-panel-' . (int) $product->get_id();
        $variations = [];
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) continue;
                $m = self::product_months($variation);
                if ($m !== $months) $variations[(int) $variation_id] = ['p' => self::period_text($m), 's' => self::months_label($m)];
            }
        }
        ob_start();
        ?>
        <div class="eggl-widget eggl-widget--notice eggl-widget--nested eggl-rr-compact" data-eggl<?php echo $variations ? ' data-eggl-rr-variations="' . esc_attr(wp_json_encode($variations)) . '"' : ''; ?>
             data-eggl-rr-period="<?php echo esc_attr(self::period_text($months)); ?>" data-eggl-rr-short="<?php echo esc_attr(self::months_label($months)); ?>">
            <button type="button" class="eggl-row eggl-row--trigger" data-eggl-toggle="<?php echo esc_attr($panel_id); ?>" aria-expanded="false">
                <span class="eggl-row__icon" aria-hidden="true">
                    <svg width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="11" fill="var(--eggl-notice-icon,#333c4d)"/><path d="M14.1 7.4a.7.7 0 0 0 0 1l1.1 1.1a.7.7 0 0 0 1 0l2.2-2.2a3.8 3.8 0 0 1-5 5l-4.1 4.1a1.3 1.3 0 0 1-1.8-1.8l4.1-4.1a3.8 3.8 0 0 1 5-5l-2.2 2.2z" fill="none" stroke="#fff" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span class="eggl-row__text">
                    <strong><?php echo esc_html(self::title()); ?></strong>
                    <span class="eggl-row__sub" data-eggl-rr-short-text><?php echo esc_html(self::months_label($months)); ?></span>
                </span>
                <span class="eggl-row__btn" aria-hidden="true"><span class="eggl-sr-only"><?php echo esc_html(EGGL_I18N::t('s_view')); ?></span></span>
            </button>
            <div class="eggl-nested__panel eggl-rr-compact__panel" id="<?php echo esc_attr($panel_id); ?>" hidden>
                <p class="eggl-rr-compact__period" data-eggl-rr-period-text><?php echo esc_html(self::period_text($months)); ?></p>
                <p><?php echo esc_html(self::body()); ?></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function enqueue_style() {
        wp_enqueue_style('eggl-repair', EGGL_Repair_Module::url('assets/repair.css'), [], EGGL_Repair_Module::asset_version('assets/repair.css'));
    }

    public function register_shortcode() {
        add_shortcode('eu_repair_notice', [$this, 'shortcode']);
    }

    public function shortcode($atts = []) {
        self::enqueue_style();
        return self::html();
    }

    /** Product page placement (setting "Product page"). Physical products the EU guarantee applies to only. */
    public function render_product() {
        if (EGGL_Repair_Settings::get('notice_product') !== 'yes') return;
        global $product;
        if (!$product instanceof WC_Product || $product->is_virtual() || $product->is_downloadable()) return;
        if (class_exists('EGGL_Product_Fields') && !EGGL_Product_Fields::applies($product)) return;

        self::enqueue_style();
        $html = self::compact_html($product);
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in compact_html()

        // Variable product whose variations have their own periods: switch the texts with the chosen variation.
        if (strpos($html, 'data-eggl-rr-variations') !== false) {
            wp_add_inline_script('wc-add-to-cart-variation', "jQuery(function($){var w=$('.eggl-rr-compact[data-eggl-rr-variations]').first();if(!w.length)return;var m=w.data('egglRrVariations')||{},dp=w.attr('data-eggl-rr-period'),ds=w.attr('data-eggl-rr-short'),p=w.find('[data-eggl-rr-period-text]'),s=w.find('[data-eggl-rr-short-text]');function set(a,b){p.text(a);s.text(b);}$('form.variations_form').on('found_variation',function(e,v){var x=m[v.variation_id];x?set(x.p,x.s):set(dp,ds);}).on('reset_data',function(){set(dp,ds);});});");
        }
    }

    /** Order emails (setting "Order emails"): customer copies of the usual order notifications only. */
    public function render_email($order, $sent_to_admin = false, $plain_text = false, $email = null) {
        if ($sent_to_admin || EGGL_Repair_Settings::get('notice_email') !== 'yes' || !$order instanceof WC_Order) return;
        $email_id = is_object($email) && isset($email->id) ? $email->id : '';
        $wanted   = (array) apply_filters('eggl_repair_notice_email_ids', ['customer_processing_order', 'customer_completed_order', 'customer_invoice', 'customer_on_hold_order']);
        if ($email_id !== '' && !in_array($email_id, $wanted, true)) return;

        $url = EGGL_Repair_Settings::form_url($order);
        if ($plain_text) {
            echo "\n" . esc_html(mb_strtoupper(self::title())) . "\n" . esc_html(self::body()) . "\n";
            if ($url) echo esc_html(EGGL_I18N::t('s_rr_link')) . ': ' . esc_url_raw($url) . "\n";
            echo "\n";
            return;
        }
        ?>
        <div style="margin:0 0 24px;padding:14px 18px;border:1px solid #e2e5eb;border-radius:8px;background:#f8f9fb;">
            <p style="margin:0 0 6px;font-weight:bold;"><?php echo esc_html(self::title()); ?></p>
            <p style="margin:0;"><?php echo esc_html(self::body()); ?></p>
            <?php if ($url) : ?>
                <p style="margin:8px 0 0;"><a href="<?php echo esc_url($url); ?>"><?php echo esc_html(EGGL_I18N::t('s_rr_link')); ?></a></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
