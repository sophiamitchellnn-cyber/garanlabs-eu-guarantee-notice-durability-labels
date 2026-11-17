<?php
if (!defined('ABSPATH')) exit;

/**
 * Settings screen built on the WordPress Settings API, split across two tabs
 * (Display / Advanced). Each tab has its OWN option group — options.php resets
 * every option registered in a group to empty whenever that group's form is
 * submitted, even ones the current form doesn't render a field for, so sharing
 * one group across both tabs would wipe out the other tab's settings on save.
 * All features here are free and fully functional; there are no locked
 * features and no licence code.
 */
class EGGL_Settings {

    const OPTION_GROUP_DISPLAY  = 'eggl_options_display';
    const OPTION_GROUP_ADVANCED = 'eggl_options_advanced';
    const PAGE_SLUG             = 'eggl-guarantee-settings';

    /** Capability for the plugin's admin screens: shop managers with WooCommerce, administrators without it (manage_woocommerce doesn't exist then). */
    public static function capability() {
        return class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options';
    }

    public function register_menu() {
        add_menu_page(
            __('EU Guarantee & GARAN', 'garanlabs-eu-guarantee-notice-durability-labels'),
            __('EU Guarantee & GARAN', 'garanlabs-eu-guarantee-notice-durability-labels'),
            self::capability(),
            self::PAGE_SLUG,
            [$this, 'render'],
            'dashicons-shield',
            56
        );
    }

    public function action_links($links) {
        $url = admin_url('admin.php?page=' . self::PAGE_SLUG);
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'garanlabs-eu-guarantee-notice-durability-labels') . '</a>');
        return $links;
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, self::PAGE_SLUG) === false) return;
        wp_enqueue_style('eggl-admin', EGGL_URL . 'assets/css/eggl-admin.css', [], eggl_asset_version('assets/css/eggl-admin.css'));
    }

    public function register_settings() {
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_enable_notice', [
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default' => 'yes',
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_enable_garan', [
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default' => 'yes',
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_notice_display_mode', [
            'sanitize_callback' => [$this, 'sanitize_display_mode'],
            'default' => 'nested',
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_notice_style', [
            'sanitize_callback' => [$this, 'sanitize_notice_style'],
            'default' => 'minimal',
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_notice_custom_text', [
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_notice_custom_size', [
            'sanitize_callback' => [$this, 'sanitize_notice_custom_size'],
            'default' => 14,
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_notice_width', [
            'sanitize_callback' => [$this, 'sanitize_notice_width'],
            'default' => 460,
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_garan_display_mode', [
            'sanitize_callback' => [$this, 'sanitize_display_mode'],
            'default' => 'nested',
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_notice_position', [
            'sanitize_callback' => [$this, 'sanitize_position'],
            'default' => 'after_excerpt',
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_garan_position', [
            'sanitize_callback' => [$this, 'sanitize_position'],
            'default' => 'after_excerpt',
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_garan_width', [
            'sanitize_callback' => [$this, 'sanitize_garan_width'],
            'default' => 220,
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_garan_checkout_enable', [
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default' => 'yes',
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_garan_checkout_width', [
            'sanitize_callback' => [$this, 'sanitize_garan_checkout_width'],
            'default' => 96,
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_garan_email_enable', [
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default' => 'no',
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_garan_email_format', [
            'sanitize_callback' => [$this, 'sanitize_email_format'],
            'default' => 'link',
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_locale', [
            'sanitize_callback' => [$this, 'sanitize_locale'],
            'default' => 'auto',
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_header_notice_enable', [
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default' => 'no',
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_placement_checkout', [
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default' => 'no',
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_notice_email_enable', [
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default' => 'no',
        ]);
        register_setting(self::OPTION_GROUP_DISPLAY, 'eggl_notice_email_format', [
            'sanitize_callback' => [$this, 'sanitize_email_format'],
            'default' => 'link',
        ]);
        register_setting(self::OPTION_GROUP_ADVANCED, 'eggl_show_credit', [
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default' => 'no',
        ]);
        register_setting(self::OPTION_GROUP_ADVANCED, 'eggl_delete_on_uninstall', [
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default' => 'no',
        ]);

        add_settings_section('eggl_section_general', __('General', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'section_intro_general'], 'eggl-tab-display');
        add_settings_field('eggl_locale', __('Display language', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_locale'], 'eggl-tab-display', 'eggl_section_general');

        add_settings_section('eggl_section_notice', __('Legal Guarantee Notice (Annex I)', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'section_intro_notice'], 'eggl-tab-display');
        add_settings_field('eggl_enable_notice', __('Enable', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_enable_notice'], 'eggl-tab-display', 'eggl_section_notice');
        add_settings_field('eggl_notice_display_mode', __('Display mode', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_notice_display_mode'], 'eggl-tab-display', 'eggl_section_notice');
        add_settings_field('eggl_notice_style', __('Row style', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_notice_style'], 'eggl-tab-display', 'eggl_section_notice');
        add_settings_field('eggl_notice_custom_text', __('Trigger text', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_notice_custom_text'], 'eggl-tab-display', 'eggl_section_notice');
        add_settings_field('eggl_notice_custom_size', __('Trigger text size', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_notice_custom_size'], 'eggl-tab-display', 'eggl_section_notice');
        add_settings_field('eggl_notice_width', __('Notice width', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_notice_width'], 'eggl-tab-display', 'eggl_section_notice');
        add_settings_field('eggl_notice_position', __('Product page position', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_notice_position'], 'eggl-tab-display', 'eggl_section_notice');
        add_settings_field('eggl_header_notice_enable', __('Header notice', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_header_notice_enable'], 'eggl-tab-display', 'eggl_section_notice');
        add_settings_field('eggl_placement_checkout', __('Checkout page', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_placement_checkout'], 'eggl-tab-display', 'eggl_section_notice');
        add_settings_field('eggl_notice_email_enable', __('Order confirmation email', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_notice_email_enable'], 'eggl-tab-display', 'eggl_section_notice');
        add_settings_field('eggl_notice_email_format', __('Email content', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_notice_email_format'], 'eggl-tab-display', 'eggl_section_notice', ['class' => 'eggl-row--conditional', 'data-eggl-show-when' => 'eggl_notice_email_enable:yes']);
        add_settings_field('eggl_notice_manual_placement', __('Manual placement', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_notice_manual_placement'], 'eggl-tab-display', 'eggl_section_notice');

        add_settings_section('eggl_section_garan', __('GARAN Durability Label (Annex II)', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'section_intro_garan'], 'eggl-tab-display');
        add_settings_field('eggl_enable_garan', __('Enable', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_enable_garan'], 'eggl-tab-display', 'eggl_section_garan');
        add_settings_field('eggl_garan_display_mode', __('Display mode', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_garan_display_mode'], 'eggl-tab-display', 'eggl_section_garan');
        add_settings_field('eggl_garan_position', __('Product page position', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_garan_position'], 'eggl-tab-display', 'eggl_section_garan');
        add_settings_field('eggl_garan_width', __('Badge width', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_garan_width'], 'eggl-tab-display', 'eggl_section_garan');
        add_settings_field('eggl_garan_checkout_enable', __('Checkout page', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_garan_checkout_enable'], 'eggl-tab-display', 'eggl_section_garan');
        add_settings_field('eggl_garan_checkout_width', __('Checkout badge size', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_garan_checkout_width'], 'eggl-tab-display', 'eggl_section_garan', ['class' => 'eggl-row--conditional', 'data-eggl-show-when' => 'eggl_garan_checkout_enable:yes']);
        add_settings_field('eggl_garan_email_enable', __('Order confirmation email', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_garan_email_enable'], 'eggl-tab-display', 'eggl_section_garan');
        add_settings_field('eggl_garan_email_format', __('Email content', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_garan_email_format'], 'eggl-tab-display', 'eggl_section_garan', ['class' => 'eggl-row--conditional', 'data-eggl-show-when' => 'eggl_garan_email_enable:yes']);
        add_settings_field('eggl_garan_manual_placement', __('Manual placement', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_garan_manual_placement'], 'eggl-tab-display', 'eggl_section_garan');

        add_settings_section('eggl_section_advanced', __('Advanced', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'section_intro_advanced'], 'eggl-tab-advanced');
        add_settings_field('eggl_show_credit', __('Plugin credit', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_credit'], 'eggl-tab-advanced', 'eggl_section_advanced');
        add_settings_field('eggl_delete_on_uninstall', __('On plugin deletion', 'garanlabs-eu-guarantee-notice-durability-labels'),
            [$this, 'field_delete_on_uninstall'], 'eggl-tab-advanced', 'eggl_section_advanced');
    }

    public function section_intro_general() {
        echo '<p>' . esc_html__('Settings that apply site-wide, to both the legal-guarantee notice and the GARAN label.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function section_intro_notice() {
        echo '<p>' . esc_html__('Everything related to the harmonised EU legal-guarantee notice: where and how it appears.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function section_intro_garan() {
        echo '<p>' . esc_html__('Everything related to the GARAN commercial durability label: where and how it appears. Set the manufacturer guarantee per product under Product data → EU Guarantee.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
        echo '<div class="notice notice-warning inline"><p>' . esc_html__('Edit a WooCommerce product and open the "EU Guarantee" tab in the product data box. Fill Guarantee duration, brand and model there and the label appears on that product.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p></div>';
    }

    public function section_intro_advanced() {
        echo '<p>' . esc_html__('Housekeeping: plugin credit and what happens to your settings on removal.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    /**
     * Renders a checkbox as a modern toggle switch. Keeps the same
     * name/value contract as a plain checkbox — a hidden "no" input first so
     * unchecked still submits a value, then the real checkbox — so no
     * sanitize_checkbox()/get_option() call site needs to change.
     */
    private function render_toggle($name, $checked, $label) {
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="no">';
        echo '<label class="eggl-toggle">';
        echo '<input type="checkbox" class="eggl-toggle__input" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="yes" ' . checked($checked, 'yes', false) . '>';
        echo '<span class="eggl-toggle__track"><span class="eggl-toggle__thumb"></span></span>';
        echo '<span class="eggl-toggle__text">' . esc_html($label) . '</span>';
        echo '</label>';
    }

    public function sanitize_locale($value) {
        $value = sanitize_text_field((string) $value);
        if ($value === 'auto') return 'auto';
        return array_key_exists($value, EGGL_I18N::languages()) ? $value : 'auto';
    }

    public function sanitize_checkbox($value) {
        return $value === 'yes' ? 'yes' : 'no';
    }

    public function sanitize_display_mode($value) {
        return in_array($value, ['popup', 'full'], true) ? $value : 'nested';
    }

    public function sanitize_email_format($value) {
        return in_array($value, ['image', 'attachment'], true) ? $value : 'link';
    }

    public function sanitize_notice_style($value) {
        return in_array($value, ['classic', 'custom'], true) ? $value : 'minimal';
    }

    public function sanitize_position($value) {
        $value = sanitize_key((string) $value);
        return array_key_exists($value, EGGL_Frontend::positions()) ? $value : 'after_excerpt';
    }

    /** Badge width in px: 40-1000, falling back to the default (220) if empty/invalid. */
    public function sanitize_garan_width($value) {
        $value = (int) $value;
        if ($value < 40) return 220;
        return min($value, 1000);
    }

    /** Maximum notice width in px: 200-1200, falling back to the default (460) if empty/invalid. */
    public function sanitize_notice_width($value) {
        $value = (int) $value;
        if ($value < 200) return 460;
        return min($value, 1200);
    }

    /** Checkout/cart GARAN badge width in px: 40-300 (small item rows/columns), falling back to the default (96) if empty/invalid. */
    public function sanitize_garan_checkout_width($value) {
        $value = (int) $value;
        if ($value < 40) return 96;
        return min($value, 300);
    }

    /** Custom trigger font size in px: 10-48, falling back to the default (14) if empty/invalid. */
    public function sanitize_notice_custom_size($value) {
        $value = (int) $value;
        if ($value < 10) return 14;
        return min($value, 48);
    }

    public function field_locale() {
        $locale = get_option('eggl_locale', 'auto');
        $languages = EGGL_I18N::languages();
        $detected = isset($languages[EGGL_I18N::detect_site_lang()]) ? $languages[EGGL_I18N::detect_site_lang()] : $languages['en'];

        echo '<select name="eggl_locale" id="eggl_locale">';
        printf(
            '<option value="auto"%s>%s</option>',
            selected($locale, 'auto', false),
            esc_html(sprintf(__('Automatic — matches your site language (currently %s)', 'garanlabs-eu-guarantee-notice-durability-labels'), $detected))
        );
        foreach ($languages as $code => $name) {
            printf('<option value="%s"%s>%s</option>', esc_attr($code), selected($locale, $code, false), esc_html($name));
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Language of the official legal-guarantee notice (Annex I). "Automatic" follows your site\'s own language (Settings → General → Site Language) and updates if that ever changes; pick a specific language instead to keep the notice fixed regardless of site language.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    /** Human-readable labels for EGGL_Frontend::positions(), in on-page order. */
    private function position_labels() {
        return [
            'before_summary'    => __('Before the product summary (above the title)', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'under_image'       => __('Under the product image (inside the gallery)', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'after_title'       => __('After the product title', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'after_price'       => __('After the price', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'after_excerpt'     => __('After the short description (default)', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'after_add_to_cart' => __('After Add to cart', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'after_summary'     => __('After the product summary (near the tabs)', 'garanlabs-eu-guarantee-notice-durability-labels'),
        ];
    }

    private function render_position_select($name, $value) {
        echo '<select name="' . esc_attr($name) . '" id="' . esc_attr($name) . '">';
        foreach ($this->position_labels() as $key => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($key), selected($value, $key, false), esc_html($label));
        }
        echo '</select>';
    }

    private function render_display_mode_select($name, $value) {
        echo '<select name="' . esc_attr($name) . '" id="' . esc_attr($name) . '">';
        printf('<option value="popup"%s>%s</option>', selected($value, 'popup', false), esc_html__('Popup — compact summary that opens the full graphic in a dialog', 'garanlabs-eu-guarantee-notice-durability-labels'));
        printf('<option value="full"%s>%s</option>', selected($value, 'full', false), esc_html__('Full — display the full graphic directly on the page', 'garanlabs-eu-guarantee-notice-durability-labels'));
        printf('<option value="nested"%s>%s</option>', selected($value, 'nested', false), esc_html__('Nested — compact trigger that expands the full graphic in place when clicked (default)', 'garanlabs-eu-guarantee-notice-durability-labels'));
        echo '</select>';
    }

    public function field_notice_display_mode() {
        // Falls back to the old shared eggl_display_mode option so sites that
        // set it before the notice/label modes were split keep their choice.
        $default = get_option('eggl_display_mode', 'nested');
        $val = get_option('eggl_notice_display_mode', $default);
        $this->render_display_mode_select('eggl_notice_display_mode', $val);
        echo '<p class="description">' . esc_html__('How the legal-guarantee notice displays on the product page.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_garan_display_mode() {
        // Falls back to the old shared eggl_display_mode option so sites that
        // set it before the notice/label modes were split keep their choice.
        $default = get_option('eggl_display_mode', 'nested');
        $val = get_option('eggl_garan_display_mode', $default);
        $this->render_display_mode_select('eggl_garan_display_mode', $val);
        echo '<p class="description">' . esc_html__('How the GARAN durability label displays on the product page.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_notice_style() {
        $val = get_option('eggl_notice_style', 'minimal');
        echo '<select name="eggl_notice_style" id="eggl_notice_style">';
        printf('<option value="minimal"%s>%s</option>', selected($val, 'minimal', false), esc_html__('Minimal — light neutral row with a chevron (default)', 'garanlabs-eu-guarantee-notice-durability-labels'));
        printf('<option value="classic"%s>%s</option>', selected($val, 'classic', false), esc_html__('Classic — teal-accented row with a "View" button', 'garanlabs-eu-guarantee-notice-durability-labels'));
        printf('<option value="custom"%s>%s</option>', selected($val, 'custom', false), esc_html__('Custom — your own text, click to open the notice', 'garanlabs-eu-guarantee-notice-durability-labels'));
        echo '</select>';
        echo '<p class="description">' . esc_html__('Visual style of the compact notice trigger shown in "popup" and "nested" display mode, and on the checkout page.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_notice_custom_text() {
        $text = get_option('eggl_notice_custom_text', '');
        echo '<input type="text" class="regular-text" name="eggl_notice_custom_text" id="eggl_notice_custom_text" value="' . esc_attr($text) . '" placeholder="' . esc_attr(EGGL_I18N::t('s_header_notice')) . '">';
        echo '<p class="description">' . esc_html__('Text shown on the compact notice row for every Row style — click opens the legal-guarantee notice. Leave empty to use the default, "Your legal guarantee rights", in your store\'s language.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_notice_custom_size() {
        $size = (int) get_option('eggl_notice_custom_size', 14);

        echo '<label class="eggl-dim-field">' . esc_html__('Font size (px)', 'garanlabs-eu-guarantee-notice-durability-labels')
            . '<input type="number" name="eggl_notice_custom_size" id="eggl_notice_custom_size" min="10" max="48" step="1" value="' . esc_attr($size) . '"></label>';
        echo '<p class="description">' . esc_html__('Text size of the notice row for every Row style (default 14). With the Custom style, the shield icon and chevron scale with it automatically.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_notice_manual_placement() {
        echo '<p class="description">' . esc_html__('Shortcode:', 'garanlabs-eu-guarantee-notice-durability-labels') . ' <code>[eu_guarantee_notice]</code></p>';
        echo '<p class="description">' . esc_html__('Or add the "EU Guarantee Notice" block in the editor.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
        echo '<p class="description">' . esc_html__('Always available — uses the same display mode and row style settings above. Also requires "Enable" above to be on.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_notice_position() {
        $this->render_position_select('eggl_notice_position', get_option('eggl_notice_position', 'after_excerpt'));
        echo '<p class="description">' . esc_html__('Where the legal-guarantee notice appears on the product page.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_garan_position() {
        $this->render_position_select('eggl_garan_position', get_option('eggl_garan_position', 'after_excerpt'));
        echo '<p class="description">' . esc_html__('Where the GARAN durability label appears on the product page.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_notice_width() {
        $width = (int) get_option('eggl_notice_width', 460);

        echo '<label class="eggl-dim-field">' . esc_html__('Maximum width (px)', 'garanlabs-eu-guarantee-notice-durability-labels')
            . '<input type="number" name="eggl_notice_width" id="eggl_notice_width" min="200" max="1200" step="1" value="' . esc_attr($width) . '"></label>';
        echo '<p class="description">' . esc_html__('How wide the notice can grow on the product page — the compact row, the expanded graphic in "nested" mode, and the graphic in "full" mode. It never exceeds the space available, and the graphic keeps its proportions. Default 460.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_garan_width() {
        $width = (int) get_option('eggl_garan_width', 220);

        echo '<label class="eggl-dim-field">' . esc_html__('Width (px)', 'garanlabs-eu-guarantee-notice-durability-labels')
            . '<input type="number" name="eggl_garan_width" id="eggl_garan_width" min="40" max="1000" step="1" value="' . esc_attr($width) . '"></label>';
        echo '<p class="description">' . esc_html__('Sets the size of the compact GARAN badge shown in "popup" and "nested" display mode. Height adjusts automatically to keep the graphic in proportion. Does not affect "full" display mode, which always shows the complete graphic at its standard size.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_garan_checkout_enable() {
        $this->render_toggle('eggl_garan_checkout_enable', get_option('eggl_garan_checkout_enable', 'yes'),
            __('Show the label at checkout for products in the basket that have one', 'garanlabs-eu-guarantee-notice-durability-labels'));
        echo '<p class="description">' . esc_html__('Article 8(2) of the Consumer Rights Directive names the durability label among the information that must be shown directly before the consumer places the order.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
        echo '<p class="description">' . esc_html__('Works on the classic checkout and the block checkout. Also shows on the classic Cart page, under each qualifying item\'s thumbnail image.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_garan_checkout_width() {
        $width = (int) get_option('eggl_garan_checkout_width', 96);

        echo '<label class="eggl-dim-field">' . esc_html__('Width (px)', 'garanlabs-eu-guarantee-notice-durability-labels')
            . '<input type="number" name="eggl_garan_checkout_width" id="eggl_garan_checkout_width" min="40" max="300" step="1" value="' . esc_attr($width) . '"></label>';
        echo '<p class="description">' . esc_html__('Sets the size of the compact GARAN badge shown at checkout and on the Cart page. Kept separate from the main "Badge width" above, since checkout/cart item rows are usually much narrower.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_garan_email_enable() {
        $this->render_toggle('eggl_garan_email_enable', get_option('eggl_garan_email_enable', 'no'),
            __('Add the GARAN durability label to WooCommerce order confirmation emails', 'garanlabs-eu-guarantee-notice-durability-labels'));
        echo '<p class="description">' . esc_html__('Shown to the customer only (never in the admin\'s copy), once per qualifying product in the order, on the processing, on-hold, and completed order emails, and on the customer invoice email.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_garan_email_format() {
        $val = get_option('eggl_garan_email_format', 'link');
        echo '<select name="eggl_garan_email_format" id="eggl_garan_email_format">';
        printf('<option value="link"%s>%s</option>', selected($val, 'link', false), esc_html__('Short text line per product with a link that opens the label graphic', 'garanlabs-eu-guarantee-notice-durability-labels'));
        printf('<option value="image"%s>%s</option>', selected($val, 'image', false), esc_html__('Full label graphic (embedded per product)', 'garanlabs-eu-guarantee-notice-durability-labels'));
        printf('<option value="attachment"%s>%s</option>', selected($val, 'attachment', false), esc_html__('Short text line per product, with the label graphic sent as a file attachment', 'garanlabs-eu-guarantee-notice-durability-labels'));
        echo '</select>';
        echo '<p class="description">' . esc_html__('"Short text line" keeps the email compact: product name and guarantee duration, with a link that opens that product\'s actual GARAN badge (brand/model/years) as a standalone image. "Full label graphic" renders that same badge as a real image directly in the email for every qualifying product — this adds real height with multiple products. "Attachment" keeps the same short text line but attaches each qualifying product\'s label graphic as a PNG file to the email instead of embedding or linking it. Rendered with the bundled font and template graphic (no server configuration needed); if that ever isn\'t possible it automatically falls back to the same short text line instead of showing nothing.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_garan_manual_placement() {
        echo '<p class="description">' . esc_html__('Shortcode:', 'garanlabs-eu-guarantee-notice-durability-labels') . ' <code>[eu_garan]</code></p>';
        echo '<p class="description">' . esc_html__('Placed on a single product page with no attributes, it pulls the duration/brand/model straight from that product\'s saved EU Guarantee data (Product data → EU Guarantee) and only shows if that product actually qualifies.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
        echo '<p class="description">' . esc_html__('Or add the "GARAN label" block, which defaults to the same "use saved product data" behaviour, or fills in the same three fields with a live preview when turned off.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
        echo '<p class="description">' . esc_html__('Give it years/brand/model explicitly — e.g. [eu_garan years="5" brand="Acme" model="AC-1200"] — to override the saved product data and render unconditionally wherever placed, skipping the eligibility check. Uses the same display mode setting above either way. Also requires "Enable" above to be on.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_enable_notice() {
        $this->render_toggle('eggl_enable_notice', get_option('eggl_enable_notice', 'yes'),
            __('Show the EU legal-guarantee notice on product pages', 'garanlabs-eu-guarantee-notice-durability-labels'));
        echo '<p class="description">' . esc_html__('Site-wide switch. Turn off to hide the notice everywhere, regardless of per-product settings.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_enable_garan() {
        $this->render_toggle('eggl_enable_garan', get_option('eggl_enable_garan', 'yes'),
            __('Show the GARAN durability label for qualifying products', 'garanlabs-eu-guarantee-notice-durability-labels'));
        echo '<p class="description">' . esc_html__('Site-wide switch. Turn off to hide the GARAN label everywhere, even for products set up to show it. Set the manufacturer guarantee per product under Product data → EU Guarantee.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_header_notice_enable() {
        $this->render_toggle('eggl_header_notice_enable', get_option('eggl_header_notice_enable', 'no'),
            __('Show a "Your legal guarantee rights" sentence in the site header, on every page', 'garanlabs-eu-guarantee-notice-durability-labels'));
        echo '<p class="description">' . esc_html__('Automatically added as a menu item next to Home / Cart / Checkout — in the desktop, tablet, and mobile navigation alike. The harmonised legal-guarantee notice appears in a small preview on mouse roll-over, and in full on click or tap. Falls back to a standalone banner if no navigation menu can be resolved.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_placement_checkout() {
        $this->render_toggle('eggl_placement_checkout', get_option('eggl_placement_checkout', 'no'),
            __('Show the legal-guarantee notice on the Checkout page, under the "Checkout" heading', 'garanlabs-eu-guarantee-notice-durability-labels'));
        echo '<p class="description">' . esc_html__('Works with both the classic checkout shortcode and the block-based Checkout page.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_notice_email_enable() {
        $this->render_toggle('eggl_notice_email_enable', get_option('eggl_notice_email_enable', 'no'),
            __('Add the notice to WooCommerce order confirmation emails', 'garanlabs-eu-guarantee-notice-durability-labels'));
        echo '<p class="description">' . esc_html__('Shown to the customer only (never in the admin\'s copy) on the processing, on-hold, and completed order emails, and on the customer invoice email.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_notice_email_format() {
        $val = get_option('eggl_notice_email_format', 'link');
        echo '<select name="eggl_notice_email_format" id="eggl_notice_email_format">';
        printf('<option value="image"%s>%s</option>', selected($val, 'image', false), esc_html__('Full notice graphic (embedded image)', 'garanlabs-eu-guarantee-notice-durability-labels'));
        printf('<option value="link"%s>%s</option>', selected($val, 'link', false), esc_html__('Short text line with a link that opens the notice graphic', 'garanlabs-eu-guarantee-notice-durability-labels'));
        printf('<option value="attachment"%s>%s</option>', selected($val, 'attachment', false), esc_html__('Short text line, with the notice graphic sent as a file attachment', 'garanlabs-eu-guarantee-notice-durability-labels'));
        echo '</select>';
        echo '<p class="description">' . esc_html__('"Full notice graphic" is embedded directly in the email so it still shows if the recipient\'s mail client blocks remote images by default. "Short text line" keeps the email compact — the link opens the same official notice graphic in a new tab instead. "Attachment" keeps that same short text line but attaches the official notice graphic to the email as a file instead of linking or embedding it.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_credit() {
        $this->render_toggle('eggl_show_credit', get_option('eggl_show_credit', 'no'),
            __('Show a small "Powered by GARAN Labs" credit under the notice', 'garanlabs-eu-guarantee-notice-durability-labels'));
        echo '<p class="description">' . esc_html__('Off by default. Turn on only if you want to display the credit on your site.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function field_delete_on_uninstall() {
        $this->render_toggle('eggl_delete_on_uninstall', get_option('eggl_delete_on_uninstall', 'no'),
            __('Also delete all settings when the plugin is deleted', 'garanlabs-eu-guarantee-notice-durability-labels'));
        echo '<p class="description">' . esc_html__('Off by default — settings survive a delete/reinstall. Tick only for a clean removal.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
    }

    public function render() {
        if (!current_user_can(self::capability())) return;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch, no state change.
        $tab = (isset($_GET['tab']) && sanitize_key(wp_unslash($_GET['tab'])) === 'advanced') ? 'advanced' : 'display';
        include EGGL_PATH . 'admin/views/eggl-settings-page.php';
    }
}
