<?php

namespace EGGL\PriceHistory\Modules\AnchorPrice;

if (!defined('ABSPATH')) {
    exit;
}

class AP_Admin
{
    public function __construct()
    {
        add_filter('eggl_ph_settings_sections', [$this, 'settings_section']);
        add_filter('eggl_ph_settings_fields', [self::class, 'settings_fields']);
        add_filter('sanitize_option_' . Helper::SETTINGS, [self::class, 'sanitize_settings'], 20);
        add_action('eggl_ph_form_top', [$this, 'panel_description']);
    }

    /**
     * Output description text under panel title.
     */
    public function panel_description($form)
    {
        $id = $form['id'] ?? '';

        if (Helper::SETTINGS === $id) {

            echo '<div class="eggl-ph-plugin-panel-description">';

            echo '<p>';

            echo wp_kses_post(__("Under the regulations currently in force in Croatia, traders must display a base price alongside the current price. For most products, this is the regular price applicable on September 10, 2026, while certain previously regulated product categories retain the May 2, 2025 reference date. The Base Price module helps you store and display this base price and its applicable date.<br><br><em>This information is provided for general guidance only and does not constitute legal advice.</em>", 'garanlabs-eu-guarantee-notice-durability-labels'));

            echo '</p>';

            echo '</div>';
        }
    }

    public function settings_section($sections)
    {
        $sections[] = [
            'id'     => Helper::SETTINGS,
            'title'  => __('Base Price', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'parent' => 'eggl_ph_price_compliance'
        ];
        return $sections;
    }

    public static function sanitize_settings($value)
    {
        $settings = Helper::normalize_settings($value);
        if (is_wp_error($settings)) {
            add_settings_error(Helper::SETTINGS, $settings->get_error_code(), $settings->get_error_message());
            return Helper::settings();
        }
        return $settings;
    }

    public static function settings_fields($fields)
    {
        $fields[Helper::SETTINGS] = [
            [
                'name'    => 'enable',
                'type'    => 'checkbox',
                'label'   => __('Enable', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('enable')
            ],
            [
                'name'    => 'date',
                'type'    => 'date',
                'label'   => __('Reference Date', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('date')
            ],
            [
                'name'              => 'message',
                'type'              => 'text',
                'label'             => __('Base Price Message', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default'           => Helper::defaults('message'),
                'sanitize_callback' => 'sanitize_text_field',
                'desc'              => __('Use {date} and {price} for the individual product’s saved reference date and base price.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'show_single',
                'type'    => 'checkbox',
                'label'   => __('Show on product page', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('show_single')
            ],
            [
                'name'    => 'show_archive',
                'type'    => 'checkbox',
                'label'   => __('Show in archives', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'desc'    => __('Shop, category and other archive pages.', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('show_archive')
            ]
        ];
        return $fields;
    }
}
