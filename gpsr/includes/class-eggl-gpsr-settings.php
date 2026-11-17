<?php
if (!defined('ABSPATH')) exit;

/**
 * EU Guarantee & GARAN → Product Safety (GPSR): the module's global on/off switch and its
 * "delete data on uninstall" option. The safety details themselves are entered per product.
 */
class EGGL_GPSR_Settings {

    const PAGE_SLUG       = 'eggl-gpsr';
    const OPTION_GROUP    = 'eggl_gpsr_options';
    const OPTION_ENABLED  = 'eggl_gpsr_enabled';
    const OPTION_UNINSTALL = 'eggl_gpsr_delete_on_uninstall';

    /** Global switch: off hides the product editor tab and the storefront tab (product data is kept). */
    public static function enabled() {
        return get_option(self::OPTION_ENABLED, 'yes') !== 'no';
    }

    public function register_menu() {
        add_submenu_page(
            EGGL_Settings::PAGE_SLUG,
            __('Product Safety (GPSR)', 'garanlabs-eu-guarantee-notice-durability-labels'),
            __('Product Safety (GPSR)', 'garanlabs-eu-guarantee-notice-durability-labels'),
            EGGL_Settings::capability(),
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function register_settings() {
        register_setting(self::OPTION_GROUP, self::OPTION_ENABLED, [
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default'           => 'yes',
        ]);
        register_setting(self::OPTION_GROUP, self::OPTION_UNINSTALL, [
            'sanitize_callback' => [$this, 'sanitize_checkbox'],
            'default'           => 'no',
        ]);
    }

    /** Lets shop managers save this page through options.php, which otherwise requires manage_options. */
    public function capability() {
        return EGGL_Settings::capability();
    }

    public function sanitize_checkbox($value) {
        return $value === 'yes' ? 'yes' : 'no';
    }

    /** Same look as the other EU Guarantee & GARAN screens. */
    public function enqueue_assets($hook) {
        if (strpos((string) $hook, self::PAGE_SLUG) === false) return;
        wp_enqueue_style('eggl-admin', EGGL_URL . 'assets/css/eggl-admin.css', [], eggl_asset_version('assets/css/eggl-admin.css'));
    }

    public function render() {
        if (!current_user_can(EGGL_Settings::capability())) return;

        $products_url = admin_url('edit.php?post_type=product');
        ?>
        <div class="wrap eggl-settings eggl-gpsr-settings">
          <div class="eggl-page-header">
            <h1><?php esc_html_e('Product Safety (GPSR)', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h1>
            <p class="eggl-lede"><?php esc_html_e('Show the product safety information required by the General Product Safety Regulation (EU) 2023/988 — manufacturer, EU/UK responsible person, warnings and product documents — in a "Product safety" tab on your product pages. This plugin is a display helper and does not provide legal advice or guarantee compliance.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
          </div>

          <?php settings_errors(); ?>

          <form method="post" action="options.php" class="eggl-form">
            <?php settings_fields(self::OPTION_GROUP); ?>

            <div class="eggl-card">
              <div class="eggl-card__head">
                <h2><?php esc_html_e('General', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h2>
                <p><?php esc_html_e('Switch the product safety feature on or off for the whole site.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
                <div class="notice notice-warning inline">
                  <p>
                    <?php
                    printf(
                        /* translators: %s: link to the WooCommerce Products list */
                        esc_html__('Product safety details are entered on each product: edit a product in %s and open the "Product Safety (GPSR)" tab in the product data box. Fill in the manufacturer, responsible person, warnings and documents there, and switch on "Show Product Safety tab" so they appear on that product\'s page.', 'garanlabs-eu-guarantee-notice-durability-labels'),
                        '<a href="' . esc_url($products_url) . '">' . esc_html__('Products', 'garanlabs-eu-guarantee-notice-durability-labels') . '</a>'
                    );
                    ?>
                  </p>
                </div>
              </div>
              <div class="eggl-card__body">
                <div class="eggl-row">
                  <div class="eggl-row__label"><label for="<?php echo esc_attr(self::OPTION_ENABLED); ?>"><?php esc_html_e('Product Safety (GPSR)', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></label></div>
                  <div class="eggl-row__control">
                    <?php $this->render_toggle(self::OPTION_ENABLED, self::enabled() ? 'yes' : 'no', __('Show the Product Safety (GPSR) tab in the product editor and on product pages', 'garanlabs-eu-guarantee-notice-durability-labels')); ?>
                    <p class="description"><?php esc_html_e('When off, the tab is hidden everywhere. Product safety details already entered are kept and come back when you switch it on again.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
                  </div>
                </div>
              </div>
            </div>

            <div class="eggl-card">
              <div class="eggl-card__head">
                <h2><?php esc_html_e('Advanced', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h2>
                <p><?php esc_html_e('What happens to product safety data when the plugin is removed.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
              </div>
              <div class="eggl-card__body">
                <div class="eggl-row">
                  <div class="eggl-row__label"><label for="<?php echo esc_attr(self::OPTION_UNINSTALL); ?>"><?php esc_html_e('On plugin deletion', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></label></div>
                  <div class="eggl-row__control">
                    <?php $this->render_toggle(self::OPTION_UNINSTALL, get_option(self::OPTION_UNINSTALL, 'no'), __('Delete all product safety data when the plugin is deleted', 'garanlabs-eu-guarantee-notice-durability-labels')); ?>
                    <p class="description"><?php esc_html_e('Removes every product\'s GPSR details and these settings. Uploaded documents stay in the Media Library. Deactivating the plugin never deletes anything.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
                  </div>
                </div>
              </div>
            </div>

            <?php submit_button(__('Save changes', 'garanlabs-eu-guarantee-notice-durability-labels')); ?>
          </form>
        </div>
        <?php
    }

    /** Toggle switch matching EGGL_Settings::render_toggle(): hidden "no" first so an unticked box still saves. */
    private function render_toggle($name, $value, $label) {
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="no">';
        echo '<label class="eggl-toggle">';
        echo '<input type="checkbox" class="eggl-toggle__input" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="yes" ' . checked($value, 'yes', false) . '>';
        echo '<span class="eggl-toggle__track"><span class="eggl-toggle__thumb"></span></span>';
        echo '<span class="eggl-toggle__text">' . esc_html($label) . '</span>';
        echo '</label>';
    }
}
