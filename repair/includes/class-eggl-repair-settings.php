<?php
if (!defined('ABSPATH')) exit;

/**
 * Right to Repair settings (one array option, eggl_rr_settings), shown on the "Settings" tab of
 * EU Guarantee & GARAN → Right to Repair, and the "Report a defect" page created as a draft.
 */
class EGGL_Repair_Settings {

    const OPTION       = 'eggl_rr_settings';
    const OPTION_GROUP = 'eggl_rr_options';
    const PAGE_OPTION  = 'eggl_rr_page_id';

    public static function defaults() {
        return [
            'enabled'          => 'yes',
            'order_statuses'   => ['completed'],
            'guarantee_years'  => 2,
            'extension_months' => 12,
            'photos'           => 'yes',
            'max_photos'       => 3,
            'account_link'     => 'yes',
            'header_link'      => 'yes',
            'notice_product'   => 'no',
            'notice_position'  => 'after_excerpt',
            'notice_email'     => 'no',
        ];
    }

    public static function get($key) {
        $settings = get_option(self::OPTION, []);
        $settings = is_array($settings) ? array_merge(self::defaults(), $settings) : self::defaults();
        return $settings[$key] ?? null;
    }

    public static function enabled() {
        return self::get('enabled') === 'yes';
    }

    /** Every order status in the store (core and custom), as status key without "wc-" => label. */
    public static function status_choices() {
        $out = [];
        foreach (wc_get_order_statuses() as $key => $label) {
            $out[strpos($key, 'wc-') === 0 ? substr($key, 3) : $key] = $label;
        }
        return $out;
    }

    /** The statuses chosen under "Orders that can be claimed" (Completed if none are left). */
    public static function claimable_statuses() {
        $chosen = array_values(array_intersect((array) self::get('order_statuses'), array_keys(self::status_choices())));
        return $chosen ?: ['completed'];
    }

    /** Legal guarantee period in years (Directive (EU) 2019/771: at least 2; some member states give more). */
    public static function guarantee_years() {
        return max(2, (int) self::get('guarantee_years'));
    }

    /** Liability extension for a repair, in months (Directive (EU) 2024/1799: 12; member states may give more). */
    public static function extension_months() {
        return max(12, (int) self::get('extension_months'));
    }

    public static function page_id() {
        return (int) get_option(self::PAGE_OPTION, 0);
    }

    public static function page_is_published() {
        $id = self::page_id();
        return $id && get_post_status($id) === 'publish';
    }

    /** The claim form page URL, optionally preset to an order (with its key, so guests skip the lookup). */
    public static function form_url($order = null) {
        if (!self::page_is_published()) return '';
        $url = get_permalink(self::page_id());
        if ($order instanceof WC_Order) {
            $url = add_query_arg(['eggl_rr_order' => $order->get_id(), 'eggl_rr_key' => $order->get_order_key()], $url);
        }
        return $url;
    }

    /** Creates the "Report a defect" page once, as a draft — publishing it switches the form on. Hooked to admin_init. */
    public static function maybe_create_page() {
        if (self::page_id() && get_post(self::page_id())) return;
        if (!current_user_can('edit_pages')) return;

        $page_id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_title'   => _x('Report a defect', 'repair page title', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'post_name'    => _x('report-a-defect', 'repair page slug', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'post_content' => '<!-- wp:shortcode -->[eggl_repair_claim_form]<!-- /wp:shortcode -->',
        ]);
        if ($page_id && !is_wp_error($page_id)) {
            update_option(self::PAGE_OPTION, (int) $page_id, false);
        }
    }

    public function register_settings() {
        register_setting(self::OPTION_GROUP, self::OPTION, ['sanitize_callback' => [$this, 'sanitize'], 'default' => self::defaults()]);
        register_setting(self::OPTION_GROUP, self::PAGE_OPTION, ['sanitize_callback' => [$this, 'sanitize_page']]);
    }

    /** Lets shop managers save the form through options.php, which otherwise requires manage_options. */
    public function capability() {
        return EGGL_Settings::capability();
    }

    public function sanitize($input) {
        $input = is_array($input) ? $input : [];
        $yes   = function ($key) use ($input) { return (isset($input[$key]) && $input[$key] === 'yes') ? 'yes' : 'no'; };
        return [
            'enabled'          => $yes('enabled'),
            'order_statuses'   => array_values(array_intersect(array_map('sanitize_key', (array) ($input['order_statuses'] ?? [])), array_keys(self::status_choices()))) ?: ['completed'],
            'guarantee_years'  => max(2, min(10, (int) ($input['guarantee_years'] ?? 2))),
            'extension_months' => max(12, min(60, (int) ($input['extension_months'] ?? 12))),
            'photos'           => $yes('photos'),
            'max_photos'       => max(1, min(10, (int) ($input['max_photos'] ?? 3))),
            'account_link'     => $yes('account_link'),
            'header_link'      => $yes('header_link'),
            'notice_product'   => $yes('notice_product'),
            'notice_position'  => array_key_exists($input['notice_position'] ?? '', EGGL_Frontend::positions()) ? $input['notice_position'] : 'after_excerpt',
            'notice_email'     => $yes('notice_email'),
        ];
    }

    public function sanitize_page($value) {
        $value = absint($value);
        return ($value && get_post_type($value) === 'page') ? $value : 0;
    }

    /* ---------------------------------------------------------------------------------------- */

    private function field_name($key) {
        return self::OPTION . '[' . $key . ']';
    }

    private function toggle($key, $label) {
        $name = $this->field_name($key);
        $id   = 'eggl_rr_' . $key;
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="no">';
        echo '<label class="eggl-toggle">';
        echo '<input type="checkbox" class="eggl-toggle__input" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="yes" ' . checked(self::get($key), 'yes', false) . '>';
        echo '<span class="eggl-toggle__track"><span class="eggl-toggle__thumb"></span></span>';
        echo '<span class="eggl-toggle__text">' . esc_html($label) . '</span>';
        echo '</label>';
    }

    private function row($label, $for, callable $control) {
        echo '<div class="eggl-row"><div class="eggl-row__label"><label for="' . esc_attr($for) . '">' . esc_html($label) . '</label></div><div class="eggl-row__control">';
        $control();
        echo '</div></div>';
    }

    private function description($text) {
        echo '<p class="description">' . esc_html($text) . '</p>';
    }

    /** The Settings tab body (inside EGGL_Repair_Admin's page wrapper). */
    public function render() {
        $page_id = self::page_id();
        ?>
        <form method="post" action="options.php" class="eggl-form">
            <?php settings_fields(self::OPTION_GROUP); ?>

            <div class="eggl-card">
                <div class="eggl-card__head">
                    <h2><?php esc_html_e('Defect claims', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h2>
                    <p><?php esc_html_e('Customers report a defect, choose repair or replacement, and you handle the claim under the Claims tab.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
                    <?php if (!self::page_is_published()) : ?>
                        <div class="notice notice-warning inline"><p>
                            <?php esc_html_e('The claim form is not live yet: publish the "Report a defect" page to switch it on.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>
                            <?php if ($page_id && get_post($page_id)) : ?>
                                <a href="<?php echo esc_url(get_edit_post_link($page_id)); ?>"><?php esc_html_e('Edit the page', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></a>
                            <?php endif; ?>
                        </p></div>
                    <?php endif; ?>
                </div>
                <div class="eggl-card__body">
                    <?php
                    $this->row(__('Right to repair', 'garanlabs-eu-guarantee-notice-durability-labels'), 'eggl_rr_enabled', function () {
                        $this->toggle('enabled', __('Let customers report defects and request repair or replacement', 'garanlabs-eu-guarantee-notice-durability-labels'));
                        $this->description(__('When off, the form and the My account links disappear. Claims already received stay under the Claims tab.', 'garanlabs-eu-guarantee-notice-durability-labels'));
                    });
                    $this->row(__('Claim form page', 'garanlabs-eu-guarantee-notice-durability-labels'), 'eggl_rr_page_id', function () use ($page_id) {
                        wp_dropdown_pages([
                            'name'              => self::PAGE_OPTION,
                            'id'                => 'eggl_rr_page_id',
                            'selected'          => $page_id,
                            'post_status'       => ['publish', 'draft', 'private'],
                            'show_option_none'  => __('— Select a page —', 'garanlabs-eu-guarantee-notice-durability-labels'),
                            'option_none_value' => 0,
                        ]);
                        echo '<p class="description">' . esc_html__('The page must contain the shortcode', 'garanlabs-eu-guarantee-notice-durability-labels') . ' <code>[eggl_repair_claim_form]</code>.</p>';
                    });
                    $this->row(__('Orders that can be claimed', 'garanlabs-eu-guarantee-notice-durability-labels'), 'eggl_rr_order_statuses', function () {
                        $chosen = self::claimable_statuses();
                        echo '<select id="eggl_rr_order_statuses" name="' . esc_attr($this->field_name('order_statuses')) . '[]" multiple="multiple" class="wc-enhanced-select" style="min-width:360px;max-width:520px" data-placeholder="' . esc_attr__('Choose order statuses…', 'garanlabs-eu-guarantee-notice-durability-labels') . '">';
                        foreach (self::status_choices() as $key => $label) {
                            printf('<option value="%s"%s>%s</option>', esc_attr($key), selected(in_array($key, $chosen, true), true, false), esc_html($label));
                        }
                        echo '</select>';
                        $this->description(__('Customers can report a defect only for orders with one of these statuses. Completed is recommended: the legal guarantee covers goods the customer has received, and its period runs from the completed date. Add a status such as "Delivered" from a shipping plugin if you use one. If none is chosen, Completed is used.', 'garanlabs-eu-guarantee-notice-durability-labels'));
                    });
                    $this->row(__('Legal guarantee period', 'garanlabs-eu-guarantee-notice-durability-labels'), 'eggl_rr_guarantee_years', function () {
                        echo '<input type="number" min="2" max="10" step="1" id="eggl_rr_guarantee_years" name="' . esc_attr($this->field_name('guarantee_years')) . '" value="' . esc_attr(self::guarantee_years()) . '" class="small-text"> ' . esc_html__('years from delivery', 'garanlabs-eu-guarantee-notice-durability-labels');
                        $this->description(__('Store default — at least 2 years in every EU country; some countries give more. A product can have its own period in its EU Guarantee tab (e.g. used goods). Customers can report defects for items still inside their period (plus any repair extensions).', 'garanlabs-eu-guarantee-notice-durability-labels'));
                    });
                    $this->row(__('Extension after a repair', 'garanlabs-eu-guarantee-notice-durability-labels'), 'eggl_rr_extension_months', function () {
                        echo '<input type="number" min="12" max="60" step="1" id="eggl_rr_extension_months" name="' . esc_attr($this->field_name('extension_months')) . '" value="' . esc_attr(self::extension_months()) . '" class="small-text"> ' . esc_html__('months', 'garanlabs-eu-guarantee-notice-durability-labels');
                        $this->description(__('Directive (EU) 2024/1799 extends the seller\'s liability by 12 months when a defect is fixed by repair; member states may give more. Applied automatically to each item when you mark a claim "Repaired".', 'garanlabs-eu-guarantee-notice-durability-labels'));
                    });
                    $this->row(__('Photos', 'garanlabs-eu-guarantee-notice-durability-labels'), 'eggl_rr_photos', function () {
                        $this->toggle('photos', __('Let customers attach photos of the defect', 'garanlabs-eu-guarantee-notice-durability-labels'));
                        echo '<p><label>' . esc_html__('Up to', 'garanlabs-eu-guarantee-notice-durability-labels') . ' <input type="number" min="1" max="10" name="' . esc_attr($this->field_name('max_photos')) . '" value="' . esc_attr((int) self::get('max_photos')) . '" class="small-text"> ' . esc_html__('photos (JPG, PNG or WebP, 5 MB each)', 'garanlabs-eu-guarantee-notice-durability-labels') . '</label></p>';
                    });
                    $this->row(__('Header menu', 'garanlabs-eu-guarantee-notice-durability-labels'), 'eggl_rr_header_link', function () {
                        $this->toggle('header_link', __('Show a "Report a defect" link in the header menu', 'garanlabs-eu-guarantee-notice-durability-labels'));
                        $this->description(__('Added to the main header menu (classic menus, automatic page menus and block-theme navigation) while the claim page is published. When off, the page is also kept out of menus that list all pages automatically. A link you add to a menu by hand is always kept.', 'garanlabs-eu-guarantee-notice-durability-labels'));
                    });
                    $this->row(__('My account', 'garanlabs-eu-guarantee-notice-durability-labels'), 'eggl_rr_account_link', function () {
                        $this->toggle('account_link', __('Show a "Report a defect" button next to eligible orders', 'garanlabs-eu-guarantee-notice-durability-labels'));
                        $this->description(__('Also lists the order\'s defect claims and any guarantee extensions on the order page.', 'garanlabs-eu-guarantee-notice-durability-labels'));
                    });
                    ?>
                </div>
            </div>

            <div class="eggl-card">
                <div class="eggl-card__head">
                    <h2><?php esc_html_e('Right to repair notice', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h2>
                    <p><?php esc_html_e('Sellers must tell consumers they can choose repair or replacement, and that choosing repair extends the legal guarantee. The notice is always shown on the claim form; it can also appear here:', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
                </div>
                <div class="eggl-card__body">
                    <?php
                    $this->row(__('Product page', 'garanlabs-eu-guarantee-notice-durability-labels'), 'eggl_rr_notice_product', function () {
                        $this->toggle('notice_product', __('Show the right to repair notice on product pages', 'garanlabs-eu-guarantee-notice-durability-labels'));
                        echo '<p><select name="' . esc_attr($this->field_name('notice_position')) . '" aria-label="' . esc_attr__('Product page position', 'garanlabs-eu-guarantee-notice-durability-labels') . '">';
                        foreach (EGGL_Frontend::positions() as $key => $unused) {
                            printf('<option value="%s"%s>%s</option>', esc_attr($key), selected(self::get('notice_position'), $key, false), esc_html(self::position_label($key)));
                        }
                        echo '</select></p>';
                    });
                    $this->row(__('Order emails', 'garanlabs-eu-guarantee-notice-durability-labels'), 'eggl_rr_notice_email', function () {
                        $this->toggle('notice_email', __('Add the notice to order confirmation emails', 'garanlabs-eu-guarantee-notice-durability-labels'));
                        $this->description(__('Processing, completed and invoice emails, customer copies only — with a link to the claim form once its page is published.', 'garanlabs-eu-guarantee-notice-durability-labels'));
                    });
                    $this->row(__('Manual placement', 'garanlabs-eu-guarantee-notice-durability-labels'), '', function () {
                        echo '<p class="description">' . esc_html__('Shortcode:', 'garanlabs-eu-guarantee-notice-durability-labels') . ' <code>[eu_repair_notice]</code></p>';
                    });
                    ?>
                </div>
            </div>

            <?php submit_button(__('Save changes', 'garanlabs-eu-guarantee-notice-durability-labels')); ?>
        </form>
        <?php
    }

    private static function position_label($key) {
        $labels = [
            'before_summary'    => __('Before the product summary (above the title)', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'under_image'       => __('Under the product image (inside the gallery)', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'after_title'       => __('After the product title', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'after_price'       => __('After the price', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'after_excerpt'     => __('After the short description (default)', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'after_add_to_cart' => __('After Add to cart', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'after_summary'     => __('After the product summary (near the tabs)', 'garanlabs-eu-guarantee-notice-durability-labels'),
        ];
        return $labels[$key] ?? $key;
    }
}
