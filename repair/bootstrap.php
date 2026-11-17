<?php
if (!defined('ABSPATH')) exit;

/**
 * Right to repair (Directive (EU) 2024/1799) — bundled module.
 *
 * Since 31 July 2026 a consumer who finds a defect within the legal guarantee period may choose
 * repair or replacement, the seller must tell them so, and choosing repair extends the seller's
 * liability by 12 months (Article 16, amending Article 10 and 13 of Directive (EU) 2019/771).
 *
 * This module adds:
 *  - a defect claim form ([eggl_repair_claim_form], on a "Report a defect" page created as a draft),
 *    reachable from My account → Orders, for guests via order number + email;
 *  - claims management under EU Guarantee & GARAN → Right to Repair (claims list, status changes,
 *    customer emails), with the 12-month extension written to the order item when a claim is
 *    marked "Repaired";
 *  - an optional "Your right to repair" notice for product pages and order emails ([eu_repair_notice]).
 *
 * WooCommerce only. Claims are a private post type (eggl_repair_claim) and are kept on uninstall,
 * like withdrawal requests: they are records of customers exercising a legal right.
 */
class EGGL_Repair_Module {

    public static function boot() {
        if (!class_exists('WooCommerce')) return;

        require_once __DIR__ . '/includes/class-eggl-repair-claims.php';
        require_once __DIR__ . '/includes/class-eggl-repair-settings.php';
        require_once __DIR__ . '/includes/class-eggl-repair-notice.php';
        require_once __DIR__ . '/includes/class-eggl-repair-form.php';
        require_once __DIR__ . '/includes/class-eggl-repair-admin.php';
        require_once __DIR__ . '/includes/class-eggl-repair-emails.php';
        require_once __DIR__ . '/includes/class-eggl-repair-privacy.php';
        require_once __DIR__ . '/includes/class-eggl-repair-header-link.php';

        add_action('init', ['EGGL_Repair_Claims', 'register'], 5);

        $settings = new EGGL_Repair_Settings();
        add_action('admin_init', [$settings, 'register_settings']);
        add_action('admin_init', ['EGGL_Repair_Settings', 'maybe_create_page']);
        add_filter('option_page_capability_' . EGGL_Repair_Settings::OPTION_GROUP, [$settings, 'capability']);

        $admin = new EGGL_Repair_Admin($settings);
        add_action('admin_menu', [$admin, 'register_menu'], 25);
        add_action('admin_init', [$admin, 'maybe_handle_actions']);
        add_action('admin_enqueue_scripts', [$admin, 'enqueue_assets']);
        add_action('add_meta_boxes', [$admin, 'add_order_meta_box'], 30);

        $emails = new EGGL_Repair_Emails();
        add_filter('woocommerce_email_classes', [$emails, 'register']);
        add_action('eggl_repair_claim_created', [$emails, 'claim_created'], 10, 1);
        add_action('eggl_repair_claim_status_changed', [$emails, 'claim_status_changed'], 10, 4);

        $privacy = new EGGL_Repair_Privacy();
        add_filter('wp_privacy_personal_data_exporters', [$privacy, 'register_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [$privacy, 'register_eraser']);

        // Header menu link ("Report a defect"); also keeps the claim page out of automatic page lists.
        (new EGGL_Repair_Header_Link())->init();

        $notice = new EGGL_Repair_Notice();
        add_action('init', [$notice, 'register_shortcode']);
        add_action('woocommerce_email_after_order_table', [$notice, 'render_email'], 40, 4);
        [$notice_hook, $notice_priority] = EGGL_Frontend::resolve_position(EGGL_Repair_Settings::get('notice_position'));
        add_action($notice_hook, [$notice, 'render_product'], $notice_priority + 1); // just after the legal-guarantee notice when both share a position

        // The shortcode is always registered, so a published page never shows it raw; switched off it shows a short message.
        $form = new EGGL_Repair_Form();
        add_action('init', [$form, 'register_shortcode']);
        add_filter('body_class', [$form, 'body_class']);

        // Switched off under Right to Repair → Settings: no form, no My account links. Existing claims stay manageable.
        if (!EGGL_Repair_Settings::enabled()) return;

        add_action('template_redirect', [$form, 'maybe_handle_post']);
        add_action('wp_enqueue_scripts', [$form, 'enqueue_assets']);
        add_filter('woocommerce_my_account_my_orders_actions', [$form, 'my_orders_action'], 20, 2);
        add_action('woocommerce_order_details_after_order_table', [$form, 'render_order_claims'], 20);
    }

    public static function path($path = '') {
        return __DIR__ . '/' . ltrim($path, '/');
    }

    public static function url($path) {
        return plugins_url(ltrim($path, '/'), __FILE__);
    }

    /** Cache-busting version for a file under repair/ — see eggl_asset_version(). */
    public static function asset_version($path) {
        return eggl_asset_version('repair/' . ltrim($path, '/'));
    }

    /**
     * Locates a template, letting themes override it under woocommerce/eggl-repair/ (same idea as
     * the withdrawal module's woocommerce/eggl-withdrawal/).
     */
    public static function template($name, $args = []) {
        wc_get_template($name, $args, 'woocommerce/eggl-repair/', self::path('templates/'));
    }
}
