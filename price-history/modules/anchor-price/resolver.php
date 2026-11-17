<?php

namespace EGGL\PriceHistory\Modules\AnchorPrice;

if (!defined('ABSPATH')) {
    exit;
}

class Resolver
{
    const HOOK = 'eggl_ph_anchor_resolve_batch';
    const BATCH_SIZE = 10;
    private static $manual = [];

    public function __construct()
    {
        add_action('eggl_ph_after_record_saved', [self::class, 'materialize']);
        add_action('update_option_' . Helper::SETTINGS, [self::class, 'settings_saved'], 10, 2);
        add_action('add_option_' . Helper::SETTINGS, [self::class, 'settings_saved'], 10, 2);
        add_action(self::HOOK, [self::class, 'batch'], 10, 3);
    }

    public static function skip_for_request(\WC_Product $product)
    {
        self::$manual[$product->get_id()] = true;
    }

    public static function unschedule()
    {
        wp_unschedule_hook(self::HOOK);
    }

    public static function settings_saved($old, $settings)
    {
        $normalized = Helper::normalize_settings($settings);
        if (is_wp_error($normalized)) {
            return;
        }
        $previous = is_array($old) ? Helper::normalize_settings($old) : null;
        $old_date = is_wp_error($previous) || !$previous ? '' : $previous['date'];
        $date = $normalized['date'];
        if ($old_date === $date) {
            return;
        }
        self::unschedule();
        if ($date !== '') {
            // End-of-day reference prices are only settled after the reference day ends.
            $after_reference = (new \DateTimeImmutable($date . ' 23:59:59', wp_timezone()))->getTimestamp() + 1;
            wp_schedule_single_event(max(time() + 1, $after_reference), self::HOOK, [0, 0, $date]);
        }
    }

    /** Read stored anchors first, then recorded regular-price evidence. Never writes metadata. */
    public static function resolve(\WC_Product $product)
    {
        if (!Helper::supports($product)) {
            return null;
        }
        // Incomplete explicit pairs also block automatic resolution.
        if (Helper::has_stored_data($product)) {
            return Helper::get_data($product);
        }
        $date = Helper::settings()['date'];
        if ($date === '' || $date >= current_time('Y-m-d')) {
            return null;
        }
        $cutoff = new \DateTimeImmutable($date . ' 23:59:59', wp_timezone());
        // WC reads post_date_gmt here; history also records draft saves, not publication events.
        // ponytail: this date is editable; an immutable first-publication date needs an audit trail.
        $created = $product->get_date_created('edit');
        $new_product = $created && $created->getTimestamp() > $cutoff->getTimestamp();
        global $wpdb;
        $table = $wpdb->prefix . 'eggl_ph_price_history';
        if ($new_product) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT regular_price, currency, hidden, date_created FROM %i WHERE product_id = %d ORDER BY date_created ASC, id ASC LIMIT 1", $table, $product->get_id()));
        } else {
            $row = $wpdb->get_row($wpdb->prepare("SELECT regular_price, currency, hidden, date_created FROM %i WHERE product_id = %d AND date_created <= %s ORDER BY date_created DESC, id DESC LIMIT 1", $table, $product->get_id(), $cutoff->format('Y-m-d H:i:s')));
        }
        if (!$row || $row->hidden || $row->currency !== get_woocommerce_currency()) {
            return null;
        }
        $recorded = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $row->date_created, wp_timezone());
        $price = Helper::normalize_price($row->regular_price);
        if (!$recorded || $recorded->format('Y-m-d H:i:s') !== $row->date_created || $recorded->getTimestamp() > time()
            || ($created && $recorded->getTimestamp() < $created->getTimestamp()) || is_wp_error($price)) {
            return null;
        }
        if (!$new_product) {
            // Interval recording can replace a price while retaining the earlier timestamp.
            $general = get_option('eggl_ph_general', []);
            $interval = is_numeric($general['record_interval'] ?? null) ? max(0, (float) $general['record_interval']) : 0;
            if ($recorded->getTimestamp() + $interval > $cutoff->getTimestamp()) {
                return null;
            }
        }
        return ['date' => $new_product ? wp_date('Y-m-d', $created->getTimestamp(), wp_timezone()) : $date, 'price' => $price];
    }

    public static function materialize($product)
    {
        if (!Helper::supports($product) || !$product->get_id() || isset(self::$manual[$product->get_id()]) || Helper::has_stored_data($product)) {
            return false;
        }
        // Do not discard pending changes on the object owned by WooCommerce's save lifecycle.
        $stored = wc_get_product($product->get_id());
        if (!$stored) {
            return false;
        }
        $stored->read_meta_data(true);
        $data = self::resolve($stored);
        if (!$data) {
            return false;
        }
        $stored->read_meta_data(true);
        if (Helper::has_stored_data($stored)) {
            return false;
        }
        Helper::set_data($stored, $data['date'], $data['price']);
        $stored->save_meta_data();
        $stored->read_meta_data(true);
        return Helper::get_data($stored) === $data;
    }

    /** Fixed-size keyset batches keep existing-catalog work off frontend requests. */
    public static function batch($cursor, $max_id, $date)
    {
        if ($date !== Helper::settings()['date'] || $date === '' || $date >= current_time('Y-m-d')) {
            return;
        }
        global $wpdb;
        $max_id = $max_id ?: (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}");
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND ID <= %d AND post_type IN ('product','product_variation') AND post_status IN ('publish','private','draft','pending','future') ORDER BY ID LIMIT %d", $cursor, $max_id, self::BATCH_SIZE));
        if (!$ids) {
            return;
        }
        _prime_post_caches($ids, false, false);
        foreach ($ids as $id) {
            self::materialize(wc_get_product($id));
        }
        if (count($ids) === self::BATCH_SIZE) {
            wp_schedule_single_event(time() + 1, self::HOOK, [(int) max($ids), (int) $max_id, $date]);
        }
    }
}
