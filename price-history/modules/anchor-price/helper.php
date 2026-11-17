<?php

namespace EGGL\PriceHistory\Modules\AnchorPrice;

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__, 2) . '/includes/helper.php';

class Helper
{
    const SETTINGS = 'eggl_ph_anchor_price';
    const META_DATE = '_eggl_ph_anchor_price_date';
    const META_PRICE = '_eggl_ph_anchor_price';

    public static function supports($product)
    {
        return $product instanceof \WC_Product && $product->is_type(['simple', 'variation']);
    }

    public static function normalize_date($date)
    {
        if (
            is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            return $date;
        }
        return new \WP_Error('eggl_ph_anchor_date', __('Enter a valid reference date.', 'garanlabs-eu-guarantee-notice-durability-labels'));
    }

    public static function normalize_price($price)
    {
        if (is_string($price) || is_int($price) || is_float($price)) {
            $price = trim((string) $price);
            if (preg_match('/^(?:\d+(?:\.\d*)?|\.\d+)$/D', $price) && is_finite((float) $price)) {
                // Always 2 decimals (e.g. an ERP-synced 70.7455 → 70.75), wherever the base price is saved, read or exported.
                $price = ltrim(wc_format_decimal($price, 2, true), '0');

                return $price === '' ? '0' : ($price[0] === '.' ? '0' . $price : $price);
            }
        }

        return new \WP_Error('eggl_ph_invalid_anchor_price', __('Enter a non-negative price using digits and a decimal separator.', 'garanlabs-eu-guarantee-notice-durability-labels'));
    }

    /** Single source of default values, shared by normalize_settings() and AP_Admin::settings_fields(). */
    public static function defaults($option_name = '')
    {
        $options = [
            'enable'       => 0,
            'date'         => '',
            'message'      => __('Price on {date}: {price}', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'show_single'  => 1,
            'show_archive' => 1,
        ];

        return $options[$option_name] ?? '';
    }

    /** A missing/invalid settings array (e.g. before this module's settings are ever saved) resolves to defaults, matching the other modules' settings behavior, rather than surfacing an error. */
    public static function normalize_settings($settings)
    {
        if (!is_array($settings)) {
            $settings = [];
        }
        $date = $settings['date'] ?? self::defaults('date');
        if ($date !== '') {
            $date = self::normalize_date($date);
            if (is_wp_error($date)) {
                return $date;
            }
        }
        $message = $settings['message'] ?? self::defaults('message');
        if (!is_string($message)) {
            return new \WP_Error('eggl_ph_anchor_message', __('Enter a Base Price message.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        // Active by default; the main switch itself defaults off (opt-in).
        $show_single = array_key_exists('show_single', $settings) ? !empty($settings['show_single']) : (bool) self::defaults('show_single');
        $show_archive = array_key_exists('show_archive', $settings) ? !empty($settings['show_archive']) : (bool) self::defaults('show_archive');
        return [
            'date'         => $date,
            'message'      => sanitize_text_field($message),
            'enable'       => !empty($settings['enable']),
            'show_single'  => $show_single,
            'show_archive' => $show_archive,
        ];
    }

    public static function settings()
    {
        $settings = self::normalize_settings(get_option(self::SETTINGS, []));
        return is_wp_error($settings) ? self::normalize_settings([]) : $settings;
    }

    public static function store_country()
    {
        if (function_exists('WC') && WC()->countries) {
            return WC()->countries->get_base_country();
        }
        return strtok((string) get_option('woocommerce_default_country', ''), ':');
    }

    /** Reference dates offered in the product editor alongside the admin's own choice; Croatia only for now. */
    public static function preset_dates()
    {
        return self::store_country() === 'HR' ? ['2025-05-02', '2026-09-10'] : [];
    }

    public static function get_date(\WC_Product $product)
    {
        $date = self::normalize_date($product->get_meta(self::META_DATE, true, 'edit'));
        return self::supports($product) && !is_wp_error($date) ? $date : null;
    }

    public static function get_price(\WC_Product $product)
    {
        $price = self::normalize_price($product->get_meta(self::META_PRICE, true, 'edit'));
        return self::supports($product) && !is_wp_error($price) ? $price : null;
    }

    public static function get_data(\WC_Product $product)
    {
        $date = self::get_date($product);
        $price = self::get_price($product);
        return $date !== null && $price !== null ? ['date' => $date, 'price' => $price] : null;
    }

    /** Even an incomplete explicit pair blocks automatic resolution. */
    public static function has_stored_data(\WC_Product $product)
    {
        return $product->meta_exists(self::META_DATE) || $product->meta_exists(self::META_PRICE);
    }

    /** Include incomplete stored values in optimistic concurrency checks. */
    public static function version(\WC_Product $product)
    {
        return hash('sha256', wp_json_encode([
            $product->meta_exists(self::META_DATE),
            $product->get_meta(self::META_DATE, true, 'edit'),
            $product->meta_exists(self::META_PRICE),
            $product->get_meta(self::META_PRICE, true, 'edit'),
        ]));
    }

    private static function product_error()
    {
        return new \WP_Error('eggl_ph_anchor_product', __('Base prices belong to simple products or individual variations.', 'garanlabs-eu-guarantee-notice-durability-labels'));
    }

    /** Setters stage metadata; the caller owns persistence. */
    public static function set_date(\WC_Product $product, $date)
    {
        if (!self::supports($product)) {
            return self::product_error();
        }
        $date = self::normalize_date($date);
        if (is_wp_error($date)) {
            return $date;
        }
        $product->update_meta_data(self::META_DATE, $date);
        return true;
    }

    public static function set_price(\WC_Product $product, $price)
    {
        if (!self::supports($product)) {
            return self::product_error();
        }
        $price = self::normalize_price($price);
        if (is_wp_error($price)) {
            return $price;
        }
        $product->update_meta_data(self::META_PRICE, $price);
        return true;
    }

    public static function set_data(\WC_Product $product, $date, $price)
    {
        if (!self::supports($product)) {
            return self::product_error();
        }
        $date = self::normalize_date($date);
        $price = self::normalize_price($price);
        if (is_wp_error($date) || is_wp_error($price)) {
            return is_wp_error($date) ? $date : $price;
        }
        self::set_date($product, $date);
        self::set_price($product, $price);
        return true;
    }

    public static function delete(\WC_Product $product)
    {
        if (!self::supports($product)) {
            return self::product_error();
        }
        $product->delete_meta_data(self::META_DATE);
        $product->delete_meta_data(self::META_PRICE);
        return true;
    }
}
