<?php

namespace EGGL\PriceHistory\Includes;

if ( !defined( 'ABSPATH' ) ) {
    exit;
}
class Helper {
    /** Format site-local date strings or UTC timestamps for display only. */
    public static function format_date( $date, $time_format = '' ) {
        $format = get_option( 'date_format' ) . (( $time_format ? ' ' . $time_format : '' ));
        if ( is_int( $date ) ) {
            return wp_date( $format, $date );
        }
        // mysql2date() parses in the site timezone, so a plugin changing PHP's default timezone can't shift the day.
        return ( is_string( $date ) && $date !== '' ? (string) mysql2date( $format, $date ) : '' );
    }

    /**
     * Create price history entry in the pph table.
     *
     * @since     1.0.0
     */
    public static function create_entry( $product, $interval = null, $values = [] ) {
        if ( !$product && empty( $values ) ) {
            return;
        }
        do_action( 'eggl_ph_before_create_entry', $product, $values );
        $product_id = $values['product_id'] ?? $product->get_id();
        $parent_product_id = $values['parent_product_id'] ?? $product->get_parent_id();
        $regular_price = $values['regular_price'] ?? $product->get_regular_price();
        $sale_price = $values['sale_price'] ?? $product->get_sale_price();
        $price = $values['price'] ?? $product->get_price();
        $type = $values['type'] ?? $product->get_type();
        $currency = $values['currency'] ?? get_woocommerce_currency();
        $hidden = $values['hidden'] ?? 0;
        $date_created = $values['date_created'] ?? current_time( 'mysql' );
        if ( !$product->is_type( 'variable' ) ) {
            // Check for scheduled sale - if dates are set, skip recording this change
            $sale_date_from = $product->get_date_on_sale_from();
            $sale_date_to = $product->get_date_on_sale_to();
            // Also check REQUEST for sale dates being set/changed
            $has_sale_schedule = false;
            if ( $sale_date_from || $sale_date_to ) {
                $has_sale_schedule = true;
            }
            // Check if sale dates are being set in the request
            if ( isset( $_REQUEST['_sale_price_dates_from'] ) || isset( $_REQUEST['_sale_price_dates_to'] ) ) {
                $request_date_from = wc_clean( $_REQUEST['_sale_price_dates_from'] ?? '' );
                $request_date_to = wc_clean( $_REQUEST['_sale_price_dates_to'] ?? '' );
                if ( !empty( $request_date_from ) || !empty( $request_date_to ) ) {
                    $has_sale_schedule = true;
                }
            }
            // If there's a sale schedule, don't record this price change
            if ( $has_sale_schedule ) {
                return;
            }
        }
        if ( $price === '' || $price === null ) {
            return;
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'eggl_ph_price_history';
        $most_recent_entry = $wpdb->get_row( $wpdb->prepare( "SELECT id, price, date_created FROM {$table_name} WHERE product_id = %d AND currency = %s ORDER BY date_created DESC LIMIT 1", $product_id, $currency ) );
        $interval_has_elapsed = true;
        $price_has_changed = true;
        if ( $most_recent_entry ) {
            $old_price = $most_recent_entry->price;
            $new_price = $price;
            $price_has_changed = self::did_price_change( $old_price, $new_price );
            if ( $interval && is_numeric( $interval ) ) {
                $current_timestamp = strtotime( $date_created );
                $entry_timestamp = strtotime( $most_recent_entry->date_created );
                // Check if interval is provided and interval time has elapsed
                $interval_has_elapsed = $current_timestamp - $entry_timestamp >= $interval;
            }
        }
        // Only insert a new row or update existing row if necessary
        if ( $price_has_changed ) {
            // Prepare the data for insertion or update
            $data = [
                'product_id'        => $product_id,
                'parent_product_id' => $parent_product_id,
                'regular_price'     => $regular_price,
                'sale_price'        => $sale_price,
                'price'             => $price,
                'product_type'      => $type,
                'currency'          => $currency,
                'hidden'            => $hidden,
                'date_created'      => $date_created,
            ];
            $placeholders = [
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s'
            ];
            if ( $interval_has_elapsed ) {
                // Insert a new row
                $wpdb->insert( $table_name, $data, $placeholders );
            } else {
                // ignore current date. We need preserve first date to determine if interval has elapsed.
                unset($data['date_created']);
                // For exiting entry we'll update all data except date.
                self::update_entry( $most_recent_entry->id, $data );
            }
        }
    }

    /**
     * Update price history entry in the pph table.
     *
     * @since     1.0.0
     */
    public static function update_entry( $id, $data ) {
        if ( !$id ) {
            return;
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'eggl_ph_price_history';
        $data_to_update = [];
        $placeholders = [];
        if ( isset( $data['product_id'] ) ) {
            $data_to_update['product_id'] = $data['product_id'];
            $placeholders[] = '%d';
        }
        if ( isset( $data['parent_product_id'] ) ) {
            $data_to_update['parent_product_id'] = $data['parent_product_id'];
            $placeholders[] = '%d';
        }
        if ( isset( $data['regular_price'] ) ) {
            $data_to_update['regular_price'] = $data['regular_price'];
            $placeholders[] = '%s';
        }
        if ( isset( $data['sale_price'] ) ) {
            $data_to_update['sale_price'] = $data['sale_price'];
            $placeholders[] = '%s';
        }
        if ( isset( $data['price'] ) ) {
            $data_to_update['price'] = $data['price'];
            $placeholders[] = '%s';
        }
        if ( isset( $data['product_type'] ) ) {
            $data_to_update['product_type'] = $data['product_type'];
            $placeholders[] = '%s';
        }
        if ( isset( $data['currency'] ) ) {
            $data_to_update['currency'] = $data['currency'];
            $placeholders[] = '%s';
        }
        if ( isset( $data['hidden'] ) ) {
            $data_to_update['hidden'] = $data['hidden'];
            $placeholders[] = '%d';
        }
        if ( isset( $data['date_created'] ) ) {
            $data_to_update['date_created'] = $data['date_created'];
            $placeholders[] = '%s';
        }
        if ( !empty( $data_to_update ) ) {
            $wpdb->update(
                $table_name,
                $data_to_update,
                [
                    'id' => $id,
                ],
                $placeholders,
                ['%d']
            );
        }
    }

    /**
     * Delete price history entry from the pph table.
     *
     * @since     1.0.0
     */
    public static function delete_product_entries( $product_id ) {
        if ( !$product_id ) {
            return;
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'eggl_ph_price_history';
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} WHERE product_id = %d", $product_id ) );
    }

    /**
     * Delete price history entry from the ph tablpe.
     *
     * @since     2.5.0
     */
    public static function delete_entry( $id ) {
        if ( !$id ) {
            return;
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'eggl_ph_price_history';
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} WHERE id = %d", $id ) );
    }

    /**
     * Get price history entry from the pph table.
     *
     * @since     1.0.0
     */
    public static function get_price_history( $product_id, $args = [] ) {
        if ( !$product_id ) {
            return;
        }
        $get_hidden = $args['hidden'] ?? true;
        $range = $args['range'] ?? '';
        $currency = $args['currency'] ?? '';
        global $wpdb;
        $table_name = $wpdb->prefix . 'eggl_ph_price_history';
        $hidden_rule = ( $get_hidden ? '' : 'AND hidden <> 1' );
        $currency_rule = '';
        $price_history = null;
        if ( $range ) {
            // From last xy days/months
            // convert input like "7_days" to "-7 days".
            $str_range = '-' . str_replace( '_', ' ', $range );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $currency_rule and $hidden_rule are internally constructed SQL fragments and not user input.
            $price_history = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE product_id = %d {$currency_rule} {$hidden_rule} AND date_created >= %s ORDER BY date_created ASC", $product_id, gmdate( 'Y-m-d', strtotime( $str_range ) ) ), ARRAY_A );
        } else {
            // All time entries
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $currency_rule and $hidden_rule are internally constructed SQL fragments and not user input.
            $price_history = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE product_id = %d {$currency_rule} {$hidden_rule} ORDER BY date_created ASC", $product_id ), ARRAY_A );
        }
        return $price_history;
    }

    public static function get_variable_latest_entries( $parent_id ) {
        $variation_ids = self::get_variation_ids( $parent_id );
        if ( empty( $variation_ids ) ) {
            return [];
        }
        $latest_entries = [];
        foreach ( $variation_ids as $vid ) {
            // Get latest entry only
            $entry = self::get_last_entry( $vid );
            if ( $entry ) {
                $latest_entries[] = $entry;
            }
        }
        return $latest_entries;
    }

    public static function get_variation_ids( $parent_id ) {
        $product = wc_get_product( $parent_id );
        if ( !$product || !$product->is_type( 'variable' ) ) {
            return [];
        }
        return $product->get_children();
        // returns variation IDs
    }

    public static function get_last_entry( $product_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'eggl_ph_price_history';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE product_id = %d ORDER BY date_created DESC LIMIT 1", $product_id ), ARRAY_A );
    }

    /**
     * Delete old data from the pph table.
     *
     * @since     2.1.3
     */
    public static function delete_old_data( $table, $older_than ) {
        if ( !$older_than ) {
            return;
        }
        $date_interval = "";
        $interval_value = "";
        // Extract the numeric value from the older_than using regex
        preg_match( '/(\\d+)_\\w+/', $older_than, $matches );
        if ( !empty( $matches ) ) {
            $interval_value = intval( $matches[1] );
            $date_interval = preg_replace( '/\\d+_/', '', $older_than );
            $date_interval = rtrim( $date_interval, 's' );
        }
        global $wpdb;
        $table_name = $wpdb->prefix . 'eggl_ph_' . $table;
        $wpdb->get_results( $wpdb->prepare( "DELETE FROM {$table_name} WHERE date_created < DATE_SUB(NOW(), INTERVAL %d {$date_interval})", $interval_value ), ARRAY_A );
        return $wpdb->rows_affected;
    }

    /**
     * CHeck if pph tables exist.
     *
     * @since     2.4.0
     */
    public static function missing_eggl_ph_tables() {
        global $wpdb;
        $tables = ['price_history'];
        $missing = false;
        foreach ( $tables as $table ) {
            $full_table_name = $wpdb->prefix . 'eggl_ph_' . $table;
            if ( $wpdb->get_var( "SHOW TABLES LIKE '{$full_table_name}'" ) !== $full_table_name ) {
                $missing = true;
            }
        }
        return $missing;
    }

    /**
     * Check if price is valid.
     * 
     * @since       2.5.0
     */
    public static function is_valid_price( $price ) {
        // Check if the price is numeric or a string that can be converted to a float
        return is_numeric( $price ) || is_string( $price ) && preg_match( '/^\\d+(\\,\\d+)?$/', $price );
    }

    /**
     * Convert number/numeric string to float.
     * 
     * @since       2.5.0
     */
    public static function convert_to_float( $price ) {
        // Convert a price formatted with a comma to a float
        if ( is_string( $price ) ) {
            $price = str_replace( ',', '.', $price );
        }
        return floatval( $price );
    }

    /**
     * Return plan name.
     * 
     * @since       2.5.0
     */
    public static function plan( $output = 'name' ) {
        $info = [
            'class'   => 'eggl-ph-plan',
            'name'    => 'free',
            'premium' => false,
            'can_use' => false,
            'message' => esc_html__( 'This feature is available on the {planName} plan. Please upgrade to access it.', 'garanlabs-eu-guarantee-notice-durability-labels' ),
        ];
        return $info[$output] ?? $info;
    }

    public static function did_price_change( $old_price, $new_price ) {
        $decimals = wc_get_price_decimals();
        // Normalize null/empty values
        if ( $old_price === null || $old_price === '' ) {
            $old_price = '0';
        }
        if ( $new_price === null || $new_price === '' ) {
            $new_price = '0';
        }
        // Always treat as strings for bccomp
        $old_price = (string) $old_price;
        $new_price = (string) $new_price;
        if ( function_exists( 'bccomp' ) ) {
            return bccomp( $old_price, $new_price, $decimals ) !== 0;
        }
        // Fallback: compare formatted floats
        return number_format(
            (float) $old_price,
            $decimals,
            '.',
            ''
        ) !== number_format(
            (float) $new_price,
            $decimals,
            '.',
            ''
        );
    }

}
