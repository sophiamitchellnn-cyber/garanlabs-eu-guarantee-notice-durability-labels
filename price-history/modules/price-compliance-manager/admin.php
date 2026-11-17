<?php

namespace EGGL\PriceHistory\Modules\PriceComplianceManager;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/editor.php';
require_once __DIR__ . '/csv.php';

/** Admin UI for the Price Compliance Manager: one page, bulk-editing whichever registered module the user selects. */
class PCM_Admin
{
    const PAGE = 'eggl-ph-price-compliance-manager';
    private $editor;

    public function __construct()
    {
        $this->editor = new Editor();
        new CSV();
        add_action('init', [self::class, 'register_modules']);
        add_action('admin_menu', [$this, 'menu'], 100);
        add_action('admin_enqueue_scripts', [$this->editor, 'assets']);
    }

    /** Modules hook into this to expose their fields; fired once all modules have registered their own listeners. */
    public static function register_modules()
    {
        do_action('eggl_ph_price_compliance_manager_register');
    }

    public function menu()
    {
        $hook = add_submenu_page(
            \EGGL_Settings::PAGE_SLUG,
            __('Price Compliance Manager', 'garanlabs-eu-guarantee-notice-durability-labels'),
            __('Price Compliance Manager', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'manage_woocommerce',
            self::PAGE,
            [$this, 'page']
        );
        $this->editor->set_page_hook($hook);
    }

    public function page()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You cannot manage price compliance data.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        // Same page header as the plugin's other admin screens (admin/views/eggl-settings-page.php).
        echo '<div class="wrap eggl-settings eggl-ph-pcm-page">';
        echo '<div class="eggl-page-header"><h1>' . esc_html__('Price Compliance Manager', 'garanlabs-eu-guarantee-notice-durability-labels') . '</h1>';
        echo '<p class="eggl-lede">' . esc_html__('Edit price compliance data for many products on one screen, or export and import it as CSV. Filter the list to find products, and expand a variable product to edit its variations.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p></div>';
        $this->editor->render();
        echo '</div>';
    }
}
