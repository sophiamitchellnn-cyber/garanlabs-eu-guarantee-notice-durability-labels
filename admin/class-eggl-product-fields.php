<?php
if (!defined('ABSPATH')) exit;

/**
 * Per-product manufacturer-guarantee fields, exposed as their own "EU Guarantee"
 * tab in the WooCommerce Product data panel (rather than crowding the General
 * tab). Used to qualify a product for the harmonised GARAN durability label
 * (Annex II), with a live preview of the label reflecting the field values.
 */
class EGGL_Product_Fields {

    const PANEL_ID = 'eggl_guarantee_data';

    public function add_tab($tabs) {
        $tabs['eggl_guarantee'] = [
            'label'    => __('EU Guarantee', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'target'   => self::PANEL_ID,
            'class'    => ['eggl_options_tab'],
            'priority' => 80,
        ];
        return $tabs;
    }

    /** Registers the tab's dashicon and the panel's preview assets, product screen only. */
    public function enqueue_assets() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'product') return;

        wp_register_style('eggl-product-tab-icon', false, [], EGGL_VERSION);
        wp_enqueue_style('eggl-product-tab-icon');
        wp_add_inline_style('eggl-product-tab-icon', '.eggl_options_tab a::before{font-family:dashicons;content:"\f332" !important;}');

        wp_enqueue_style('eggl-admin-product', EGGL_URL . 'assets/css/eggl-admin-product.css', [], eggl_asset_version('assets/css/eggl-admin-product.css'));
        wp_enqueue_script('eggl-admin-product', EGGL_URL . 'assets/js/eggl-admin-product.js', [], eggl_asset_version('assets/js/eggl-admin-product.js'), true);
        // Same font metrics and field widths EGGL_Garan_Fit checks against, so the live
        // preview can warn about over-long text before the product is even saved.
        wp_add_inline_script('eggl-admin-product', 'window.egglFit = ' . wp_json_encode([
            'metrics' => EGGL_Garan_Fit::metrics(),
            'widths'  => [
                'brand' => EGGL_Garan_Fit::BRAND_WIDTH,
                'model' => EGGL_Garan_Fit::MODEL_WIDTH,
                'years' => EGGL_Garan_Fit::YEARS_WIDTH,
            ],
            'tolerance' => EGGL_Garan_Fit::TOLERANCE,
            'sizes'   => ['field' => EGGL_Garan_Fit::FIELD_SIZE, 'years' => EGGL_Garan_Fit::YEARS_SIZE, 'yearsTracking' => EGGL_Garan_Fit::YEARS_TRACKING],
            'i18n'    => [
                'brand' => __('Brand/Trademark is too wide for the label and will be squeezed to fit — shorten it if you can.', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'model' => __('Model identifier is too wide for the label and will be squeezed to fit — shorten it if you can.', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'years' => __('Duration has too many digits for the label.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            ],
        ]) . ';', 'before');
    }

    public function render_panel() {
        global $post;
        $pid = $post ? $post->ID : 0;

        $applies    = $pid ? get_post_meta($pid, '_eggl_applies', true) : '';
        $legal_months = $pid ? (int) get_post_meta($pid, '_eggl_legal_months', true) : 0;
        $show_garan = $pid ? get_post_meta($pid, '_eggl_show_garan', true) : '';
        $months     = $pid ? intval(get_post_meta($pid, '_eggl_manufacturer_months', true)) : 0;
        $years      = $months > 0 ? (int) round($months / 12) : '';
        $brand      = $pid ? (string) get_post_meta($pid, '_eggl_brand', true) : '';
        $model      = $pid ? (string) get_post_meta($pid, '_eggl_model', true) : '';

        ?>
        <div id="<?php echo esc_attr(self::PANEL_ID); ?>" class="panel woocommerce_options_panel">
            <div class="options_group eggl-section eggl-section--legal">
                <div class="eggl-section__head">
                    <h4><?php esc_html_e('Legal guarantee (seller — by law)', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h4>
                    <p><?php esc_html_e('The guarantee every consumer has against your shop by law (at least 2 years in the EU). Used for the legal-guarantee notice and for right-to-repair defect claims.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
                </div>
                <?php
                woocommerce_wp_checkbox([
                    'id'          => '_eggl_applies',
                    'label'       => __('EU guarantee applies', 'garanlabs-eu-guarantee-notice-durability-labels'),
                    'description' => __('On for physical goods (B2C). Turn off for out-of-scope items (e.g. food).', 'garanlabs-eu-guarantee-notice-durability-labels'),
                    'value'       => ($applies === '' ? 'yes' : $applies),
                ]);
                woocommerce_wp_text_input([
                    'id'                => '_eggl_legal_months',
                    'label'             => __('Legal guarantee (months)', 'garanlabs-eu-guarantee-notice-durability-labels'),
                    'type'              => 'number',
                    'value'             => $legal_months ? $legal_months : '',
                    /* translators: %d: number of months */
                    'placeholder'       => sprintf(__('Store default: %d months', 'garanlabs-eu-guarantee-notice-durability-labels'), self::default_legal_months()),
                    'custom_attributes' => ['min' => 12, 'max' => 240, 'step' => 1],
                    'description'       => __('Leave empty to use the store default (Right to Repair → Settings). Fill in only if this product differs by law — e.g. 12 for used goods where your country allows a shorter period, or longer where the period depends on the product\'s expected lifespan (Netherlands, Finland). This is not the manufacturer\'s guarantee below.', 'garanlabs-eu-guarantee-notice-durability-labels'),
                ]);
                ?>
            </div>

            <div class="eggl-section__head eggl-section__head--garan">
                <h4><?php esc_html_e('GARAN label — commercial durability guarantee (manufacturer)', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h4>
                <p><?php esc_html_e('A voluntary guarantee the manufacturer offers on top of the legal guarantee, shown with the GARAN label. It does not change the legal guarantee period above.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
            </div>

            <div class="eggl-garan-editor" data-eggl-editor data-eggl-parent-editor>
                <div class="eggl-garan-editor__form">
                    <label class="eggl-field eggl-field--checkbox">
                        <input type="checkbox" name="_eggl_show_garan" value="yes" data-eggl-input="show_garan"
                               <?php checked($show_garan === '' ? 'yes' : $show_garan, 'yes'); ?>>
                        <?php esc_html_e('Show GARAN label for this product', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>
                    </label>

                    <div class="eggl-field">
                        <label for="_eggl_manufacturer_years"><?php esc_html_e('Commercial guarantee (whole years)', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></label>
                        <input type="number" min="3" step="1" id="_eggl_manufacturer_years" name="_eggl_manufacturer_years"
                               value="<?php echo esc_attr($years); ?>" data-eggl-input="years">
                        <p class="description"><?php esc_html_e('The manufacturer\'s durability guarantee for the whole product. Only durations of more than 2 years qualify for the GARAN label — enter 3 or more.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
                    </div>

                    <div class="eggl-field">
                        <label for="_eggl_brand"><?php esc_html_e('Brand/Trademark (producer)', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></label>
                        <div class="eggl-field__input-wrap">
                            <input type="text" id="_eggl_brand" name="_eggl_brand" maxlength="40"
                                   value="<?php echo esc_attr($brand); ?>" data-eggl-input="brand">
                            <span class="eggl-field__count" data-eggl-count="brand"><?php echo esc_html(mb_strlen($brand)); ?>/40</span>
                        </div>
                    </div>

                    <div class="eggl-field">
                        <label for="_eggl_model"><?php esc_html_e('Model identifier', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></label>
                        <div class="eggl-field__input-wrap">
                            <input type="text" id="_eggl_model" name="_eggl_model" maxlength="40"
                                   value="<?php echo esc_attr($model); ?>" data-eggl-input="model">
                            <span class="eggl-field__count" data-eggl-count="model"><?php echo esc_html(mb_strlen($model)); ?>/40</span>
                        </div>
                    </div>

                    <p class="eggl-garan-editor__hint"><?php esc_html_e('Saved with this product’s Update/Publish button.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
                </div>

                <div class="eggl-garan-editor__preview">
                    <p class="eggl-garan-editor__preview-title"><?php esc_html_e('Preview', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>

                    <div class="eggl-garan-preview" data-eggl-preview>
                        <?php
                        /* Always render the design's own placeholders here — JS applies the
                         * current field values (including any already-saved ones) on load, so
                         * it always has the true placeholder markup to restore when a field is
                         * cleared. See eggl-admin-product.js. */
                        echo EGGL_Frontend::garan_svg_markup('', '', '', true, __('GARAN durability label preview', 'garanlabs-eu-guarantee-notice-durability-labels'));
                        ?>
                    </div>

                    <p class="eggl-garan-editor__preview-caption"><?php esc_html_e('This is exactly how the label will render in your storefront.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
                    <p class="eggl-garan-editor__preview-note" data-eggl-hidden-note hidden><?php esc_html_e('This label will not show on the frontend while "Show GARAN label" is off.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
                    <ul class="eggl-garan-editor__fit-warning" data-eggl-fit-warning hidden></ul>
                </div>
            </div>
        </div>
        <?php
    }

    public function save($post_id) {
        if (!isset($_POST['woocommerce_meta_nonce'])
            || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['woocommerce_meta_nonce'])), 'woocommerce_save_data')) {
            return;
        }
        update_post_meta($post_id, '_eggl_applies', isset($_POST['_eggl_applies']) ? 'yes' : 'no');

        $legal = isset($_POST['_eggl_legal_months']) ? absint(wp_unslash($_POST['_eggl_legal_months'])) : 0;
        $this->update_or_delete($post_id, '_eggl_legal_months', $legal >= 12 ? min(240, $legal) : '');
        update_post_meta($post_id, '_eggl_show_garan', isset($_POST['_eggl_show_garan']) ? 'yes' : 'no');

        $years = isset($_POST['_eggl_manufacturer_years']) ? max(0, intval(wp_unslash($_POST['_eggl_manufacturer_years']))) : 0;
        update_post_meta($post_id, '_eggl_manufacturer_months', $years * 12);

        $brand = isset($_POST['_eggl_brand']) ? sanitize_text_field(wp_unslash($_POST['_eggl_brand'])) : '';
        update_post_meta($post_id, '_eggl_brand', mb_substr($brand, 0, 40));

        $model = isset($_POST['_eggl_model']) ? sanitize_text_field(wp_unslash($_POST['_eggl_model'])) : '';
        update_post_meta($post_id, '_eggl_model', mb_substr($model, 0, 40));

    }

    /**
     * Per-variation overrides, shown inside each variation's panel on the Variations tab.
     * Every field is optional: left blank (or "Same as parent product"), the variation
     * inherits the parent product's EU Guarantee tab value — see meta().
     */
    public function render_variation_fields($loop, $variation_data, $variation) {
        $vid = is_object($variation) ? (int) $variation->ID : 0;

        $legal_months = $vid ? (int) get_post_meta($vid, '_eggl_legal_months', true) : 0;
        $show_garan = $vid ? (string) get_post_meta($vid, '_eggl_show_garan', true) : '';
        $months     = $vid ? intval(get_post_meta($vid, '_eggl_manufacturer_months', true)) : 0;
        $years      = $months > 0 ? (int) round($months / 12) : '';
        $brand      = $vid ? (string) get_post_meta($vid, '_eggl_brand', true) : '';
        $model      = $vid ? (string) get_post_meta($vid, '_eggl_model', true) : '';
        $inherit    = __('Inherited from the parent product when blank', 'garanlabs-eu-guarantee-notice-durability-labels');
        ?>
        <div class="eggl-variation-fields" data-eggl-editor data-eggl-variation>
            <p class="form-row form-row-full"><strong><?php esc_html_e('EU Guarantee — legal guarantee (seller) and GARAN label (manufacturer)', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></strong></p>
            <?php
            woocommerce_wp_text_input([
                'id'                => "_eggl_variation_legal_months_{$loop}",
                'name'              => "_eggl_variation_legal_months[{$loop}]",
                'label'             => __('Legal guarantee (months)', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'type'              => 'number',
                'value'             => $legal_months ? $legal_months : '',
                'placeholder'       => $inherit,
                'custom_attributes' => ['min' => 12, 'max' => 240, 'step' => 1],
                'wrapper_class'     => 'form-row form-row-full',
            ]);
            woocommerce_wp_select([
                'id'            => "_eggl_variation_show_garan_{$loop}",
                'name'          => "_eggl_variation_show_garan[{$loop}]",
                'label'         => __('GARAN label', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'value'         => in_array($show_garan, ['yes', 'no'], true) ? $show_garan : '',
                'options'       => [
                    ''    => __('Same as parent product', 'garanlabs-eu-guarantee-notice-durability-labels'),
                    'yes' => __('Show for this variation', 'garanlabs-eu-guarantee-notice-durability-labels'),
                    'no'  => __('Hide for this variation', 'garanlabs-eu-guarantee-notice-durability-labels'),
                ],
                'custom_attributes' => ['data-eggl-input' => 'show_garan'],
                'wrapper_class' => 'form-row form-row-first',
            ]);
            woocommerce_wp_text_input([
                'id'                => "_eggl_variation_years_{$loop}",
                'name'              => "_eggl_variation_years[{$loop}]",
                'label'             => __('Commercial guarantee (whole years)', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'type'              => 'number',
                'value'             => $years,
                'placeholder'       => $inherit,
                'custom_attributes' => ['min' => 3, 'step' => 1, 'data-eggl-input' => 'years'],
                'wrapper_class'     => 'form-row form-row-last',
            ]);
            woocommerce_wp_text_input([
                'id'                => "_eggl_variation_brand_{$loop}",
                'name'              => "_eggl_variation_brand[{$loop}]",
                'label'             => __('Brand/Trademark (producer)', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'value'             => $brand,
                'placeholder'       => $inherit,
                'custom_attributes' => ['maxlength' => 40, 'data-eggl-input' => 'brand'],
                'wrapper_class'     => 'form-row form-row-first',
            ]);
            woocommerce_wp_text_input([
                'id'                => "_eggl_variation_model_{$loop}",
                'name'              => "_eggl_variation_model[{$loop}]",
                'label'             => __('Model identifier', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'value'             => $model,
                'placeholder'       => $inherit,
                'custom_attributes' => ['maxlength' => 40, 'data-eggl-input' => 'model'],
                'wrapper_class'     => 'form-row form-row-last',
            ]);
            ?>
            <div class="form-row form-row-full eggl-variation-preview">
                <p class="eggl-garan-editor__preview-title"><?php esc_html_e('Preview', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
                <div class="eggl-garan-preview" data-eggl-preview>
                    <?php
                    // Placeholders only — eggl-admin-product.js fills in this variation's values,
                    // falling back to the parent's (live) EU Guarantee tab fields when blank.
                    echo EGGL_Frontend::garan_svg_markup('', '', '', true, __('GARAN durability label preview', 'garanlabs-eu-guarantee-notice-durability-labels'));
                    ?>
                </div>
                <p class="eggl-garan-editor__preview-note" data-eggl-hidden-note hidden><?php esc_html_e('This label will not show on the frontend for this variation.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
                <ul class="eggl-garan-editor__fit-warning" data-eggl-fit-warning hidden></ul>
            </div>
        </div>
        <?php
    }

    /** Saves render_variation_fields(). Blank values delete the variation's own meta so it falls back to the parent's. */
    public function save_variation($variation_id, $i) {
        // Nonce already verified by WooCommerce's save_variations AJAX handler / product save.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $legal = isset($_POST['_eggl_variation_legal_months'][$i]) ? absint(wp_unslash($_POST['_eggl_variation_legal_months'][$i])) : 0;
        $this->update_or_delete($variation_id, '_eggl_legal_months', $legal >= 12 ? min(240, $legal) : '');

        $show = isset($_POST['_eggl_variation_show_garan'][$i]) ? sanitize_key(wp_unslash($_POST['_eggl_variation_show_garan'][$i])) : '';
        $this->update_or_delete($variation_id, '_eggl_show_garan', in_array($show, ['yes', 'no'], true) ? $show : '');

        $years = isset($_POST['_eggl_variation_years'][$i]) ? max(0, intval(wp_unslash($_POST['_eggl_variation_years'][$i]))) : 0;
        $this->update_or_delete($variation_id, '_eggl_manufacturer_months', $years > 0 ? $years * 12 : '');

        $brand = isset($_POST['_eggl_variation_brand'][$i]) ? sanitize_text_field(wp_unslash($_POST['_eggl_variation_brand'][$i])) : '';
        $this->update_or_delete($variation_id, '_eggl_brand', mb_substr($brand, 0, 40));

        $model = isset($_POST['_eggl_variation_model'][$i]) ? sanitize_text_field(wp_unslash($_POST['_eggl_variation_model'][$i])) : '';
        $this->update_or_delete($variation_id, '_eggl_model', mb_substr($model, 0, 40));

        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    private function update_or_delete($post_id, $key, $value) {
        if ($value === '') {
            delete_post_meta($post_id, $key);
        } else {
            update_post_meta($post_id, $key, $value);
        }
    }

    /**
     * Reads $key for $product — for a variation, its own value if set, otherwise the
     * parent product's, so variations without their own overrides behave exactly like
     * their parent (EU Guarantee tab data, vendor label image, etc.).
     */
    private static function meta($product, $key) {
        $pid   = is_object($product) ? $product->get_id() : intval($product);
        $value = get_post_meta($pid, $key, true);
        if ($value === '' || $value === false) {
            $parent_id = self::parent_id($pid);
            if ($parent_id) $value = get_post_meta($parent_id, $key, true);
        }
        return $value;
    }

    /** Parent product id when $pid is a variation, otherwise 0. */
    private static function parent_id($pid) {
        if (!$pid || get_post_type($pid) !== 'product_variation') return 0;
        return (int) wp_get_post_parent_id($pid);
    }

    /**
     * Fit problems (EGGL_Garan_Fit::problems()) for the generated label $product would
     * show, or [] if it shows none, uses a vendor-supplied image instead, or fits.
     */
    public static function fit_problems($product) {
        if (!self::applies($product) || self::vendor_image_src($product) !== '') return [];
        $months = self::qualified_months($product);
        if (!$months) return [];

        $model_from_name = (string) self::meta($product, '_eggl_model') === '';
        return EGGL_Garan_Fit::problems(self::brand($product), self::model($product),
            (string) max(1, (int) round($months / 12)), $model_from_name);
    }

    /** Product edit screen: lists any label text that won't fit, for the product and each of its variations. */
    public function fit_notice() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'product' || !function_exists('wc_get_product')) return;

        global $post;
        $product = ($post instanceof WP_Post) ? wc_get_product($post->ID) : null;
        if (!$product) return;

        $items = [];
        foreach (self::fit_problems($product) as $problem) $items[] = $problem;
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) continue;
                foreach (self::fit_problems($variation) as $problem) {
                    /* translators: 1: variation name, 2: the problem */
                    $items[] = sprintf(__('%1$s: %2$s', 'garanlabs-eu-guarantee-notice-durability-labels'), $variation->get_name(), $problem);
                }
            }
        }
        if (!$items) return;

        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('GARAN label: some text is too long for the official label.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</strong></p><ul style="list-style:disc;margin-left:1.5em;">';
        foreach ($items as $item) echo '<li>' . esc_html($item) . '</li>';
        echo '</ul></div>';
    }

    /** Store-wide legal guarantee period in months (Right to Repair → Settings), 24 if that module isn't loaded. */
    public static function default_legal_months() {
        return class_exists('EGGL_Repair_Settings') ? EGGL_Repair_Settings::guarantee_years() * 12 : 24;
    }

    /** $product's own legal guarantee period in months (a variation falls back to its parent's), or 0 for the store default. */
    public static function legal_months($product) {
        $months = (int) self::meta($product, '_eggl_legal_months');
        return $months >= 12 ? $months : 0;
    }

    /**
     * Saves the product's own legal guarantee period on a new order line (hooked to
     * woocommerce_checkout_create_order_line_item, classic and block checkout), so the period
     * the customer bought with stays fixed even if the product's setting changes later.
     */
    public static function snapshot_legal_months($item, $cart_item_key = '', $values = [], $order = null) {
        $product = is_callable([$item, 'get_product']) ? $item->get_product() : null;
        $months  = $product ? self::legal_months($product) : 0;
        if ($months) $item->update_meta_data('_eggl_legal_months', $months);
    }

    /** EU guarantee applies everywhere unless excluded per-product. */
    public static function applies($product) {
        return self::meta($product, '_eggl_applies') !== 'no';
    }

    /** Qualified for GARAN = shown and > 24 months (> 2 years). Returns months or 0. */
    public static function qualified_months($product) {
        $pid = is_object($product) ? $product->get_id() : intval($product);

        if (self::meta($pid, '_eggl_show_garan') === 'no') return 0;

        $months = intval(self::meta($pid, '_eggl_manufacturer_months'));
        return ($months > 24) ? $months : 0;
    }

    /** Brand/Trademark shown on $product's label: its own (or, for a variation, its parent's) field, else the "brand" attribute, else the site name. */
    public static function brand($product) {
        $product = is_object($product) ? $product : wc_get_product(intval($product));
        if (!$product) return get_bloginfo('name');
        return self::meta($product, '_eggl_brand') ?: ($product->get_attribute('brand') ?: get_bloginfo('name'));
    }

    /** Model identifier shown on $product's label: its own (or, for a variation, its parent's) field, else the product name. */
    public static function model($product) {
        $product = is_object($product) ? $product : wc_get_product(intval($product));
        if (!$product) return '';
        return self::meta($product, '_eggl_model') ?: $product->get_name();
    }

    /** Vendor-supplied GARAN label image attachment id for $product (from the "Vendor GARAN Labels" admin page), or 0 if none has been uploaded. */
    public static function vendor_image_id($product) {
        return (int) self::meta($product, '_eggl_vendor_garan_image_id');
    }

    /** Vendor-supplied GARAN label image URL for $product, or '' if none is set/the attachment no longer exists. When set, this replaces the plugin's own generated label graphic for that product everywhere it would otherwise render. */
    public static function vendor_image_src($product) {
        $id = self::vendor_image_id($product);
        if (!$id) return '';
        $src = wp_get_attachment_image_url($id, 'full');
        return $src ? $src : '';
    }

    /** Filesystem path of the vendor-supplied GARAN label image for $product, or '' if none — used to attach/embed the real file in order-confirmation emails without re-encoding it. */
    public static function vendor_image_path($product) {
        $id = self::vendor_image_id($product);
        if (!$id) return '';
        $path = get_attached_file($id);
        return ($path && file_exists($path)) ? $path : '';
    }

    /**
     * Whole-years duration set directly on the "Vendor GARAN Labels" admin page, for the
     * compact trigger badge only — independent of this product's own Duration
     * field (qualified_months()), so a vendor image can be used without also completing that
     * separate workflow. Returns 0 if unset.
     */
    public static function vendor_years($product) {
        return (int) self::meta($product, '_eggl_vendor_garan_years');
    }
}
