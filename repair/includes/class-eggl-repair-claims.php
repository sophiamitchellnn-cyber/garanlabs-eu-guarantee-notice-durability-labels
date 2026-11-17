<?php
if (!defined('ABSPATH')) exit;

/**
 * Defect claims: a private post type with one post status per claim state, plus the legal
 * guarantee arithmetic — when an order item's guarantee ends, and the 12-month extension a
 * repair adds (Directive (EU) 2024/1799, Article 16).
 *
 * Claim meta:
 *   _eggl_rr_order_id      int     WooCommerce order id
 *   _eggl_rr_items         array   [{item_id, qty, name}]
 *   _eggl_rr_remedy        string  repair|replacement (the customer's choice)
 *   _eggl_rr_description   string  the defect, in the customer's words
 *   _eggl_rr_noticed       string  Y-m-d the defect was noticed (optional)
 *   _eggl_rr_email / _eggl_rr_name / _eggl_rr_phone
 *   _eggl_rr_photos        int[]   attachment ids
 *   _eggl_rr_reason        string  last message to the customer (e.g. rejection reason)
 *   _eggl_rr_log           array   [{time, status, message, user}] — history shown in the admin
 *   _eggl_rr_extended      array   item_id => Y-m-d new guarantee end, set when marked "Repaired"
 * Order item meta:
 *   _eggl_rr_guarantee_until  Y-m-d  the item's legal guarantee end after any repair extensions
 */
class EGGL_Repair_Claims {

    const POST_TYPE = 'eggl_repair_claim';
    const ITEM_META = '_eggl_rr_guarantee_until';

    /** status key => post status. Keys are what code and emails use; post statuses are max 20 chars. */
    public static function statuses() {
        return [
            'received'    => 'eggl-rr-received',
            'in_progress' => 'eggl-rr-progress',
            'repaired'    => 'eggl-rr-repaired',
            'replaced'    => 'eggl-rr-replaced',
            'refunded'    => 'eggl-rr-refunded',
            'rejected'    => 'eggl-rr-rejected',
        ];
    }

    public static function status_labels() {
        return [
            'received'    => __('Received', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'in_progress' => __('In progress', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'repaired'    => __('Repaired', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'replaced'    => __('Replaced', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'refunded'    => __('Refunded or price reduced', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'rejected'    => __('Rejected', 'garanlabs-eu-guarantee-notice-durability-labels'),
        ];
    }

    public static function remedy_labels() {
        return [
            'repair'      => __('Repair', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'replacement' => __('Replacement', 'garanlabs-eu-guarantee-notice-durability-labels'),
        ];
    }

    /** Claims still waiting for the merchant. */
    public static function open_statuses() {
        return ['received', 'in_progress'];
    }

    /** Hooked to init. */
    public static function register() {
        register_post_type(self::POST_TYPE, [
            'labels'          => [
                'name'          => __('Defect claims', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'singular_name' => __('Defect claim', 'garanlabs-eu-guarantee-notice-durability-labels'),
            ],
            'public'          => false,
            'show_ui'         => false, // managed on EU Guarantee & GARAN → Right to Repair
            'show_in_rest'    => false,
            'rewrite'         => false,
            'query_var'       => false,
            'supports'        => [],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
        ]);

        // Internal statuses: claims only ever show on the plugin's own screen, which labels them itself.
        foreach (self::statuses() as $key => $status) {
            register_post_status($status, [
                'label'                     => self::status_labels()[$key],
                'public'                    => false,
                'internal'                  => true,
                'exclude_from_search'       => true,
                'show_in_admin_all_list'    => false,
                'show_in_admin_status_list' => false,
            ]);
        }
    }

    /** Status key of a claim post ('' if not a claim). */
    public static function status($claim) {
        $claim = get_post($claim);
        if (!$claim || $claim->post_type !== self::POST_TYPE) return '';
        $key = array_search($claim->post_status, self::statuses(), true);
        return $key === false ? '' : $key;
    }

    public static function meta($claim_id, $key, $default = '') {
        $value = get_post_meta((int) $claim_id, '_eggl_rr_' . $key, true);
        return ($value === '' || $value === null || $value === false) ? $default : $value;
    }

    /** Claim number shown to customers and merchants. */
    public static function number($claim_id) {
        return (string) apply_filters('eggl_repair_claim_number', (string) (int) $claim_id, (int) $claim_id);
    }

    /* ----------------------------------------------------------------------------------------
     * Legal guarantee arithmetic
     * ------------------------------------------------------------------------------------- */

    /** Delivery date the guarantee runs from: completed date, else paid date, else order date. */
    public static function delivery_date(WC_Order $order) {
        $date = $order->get_date_completed() ?: $order->get_date_paid() ?: $order->get_date_created();
        return apply_filters('eggl_repair_order_delivery_date', $date, $order);
    }

    /**
     * Legal guarantee period in months for an order line: the period saved on the line at purchase,
     * else its product's own period (EU Guarantee tab), else the store default.
     */
    public static function period_months($item = null) {
        if ($item instanceof WC_Order_Item) {
            $months = (int) $item->get_meta('_eggl_legal_months');
            if ($months >= 12) return $months;
            $product = is_callable([$item, 'get_product']) ? $item->get_product() : null;
            if ($product && class_exists('EGGL_Product_Fields')) {
                $months = EGGL_Product_Fields::legal_months($product);
                if ($months) return $months;
            }
        }
        return EGGL_Repair_Settings::guarantee_years() * 12;
    }

    /** Statutory end of the legal guarantee for $order (an order line's own period if given; no extensions), or null. */
    public static function base_end(WC_Order $order, $item = null) {
        $delivered = self::delivery_date($order);
        if (!$delivered) return null;
        $end = (new DateTimeImmutable('@' . $delivered->getTimestamp()))->setTimezone(wp_timezone());
        return $end->modify('+' . self::period_months($item) . ' months')->setTime(23, 59, 59);
    }

    /** End of the legal guarantee for an order item, including any repair extensions, or null. */
    public static function item_end(WC_Order_Item $item, WC_Order $order) {
        $base     = self::base_end($order, $item);
        $extended = (string) $item->get_meta(self::ITEM_META);
        if ($extended !== '') {
            $ext = DateTimeImmutable::createFromFormat('!Y-m-d', $extended, wp_timezone());
            if ($ext) {
                $ext = $ext->setTime(23, 59, 59);
                if (!$base || $ext > $base) return $ext;
            }
        }
        return $base;
    }

    /** Order statuses whose goods can be claimed for (Right to Repair → Settings → Orders that can be claimed; Completed by default). */
    public static function claimable_order_statuses() {
        return (array) apply_filters('eggl_repair_claimable_order_statuses', EGGL_Repair_Settings::claimable_statuses());
    }

    /**
     * Whether an order line can be claimed for: a physical product the EU guarantee applies to,
     * still inside its (possibly extended) guarantee period.
     */
    public static function item_is_claimable(WC_Order_Item $item, WC_Order $order) {
        if (!$item instanceof WC_Order_Item_Product) return false;
        $product = $item->get_product();
        if ($product && ($product->is_virtual() || $product->is_downloadable())) return false;
        if ($product && class_exists('EGGL_Product_Fields') && !EGGL_Product_Fields::applies($product)) return false;

        $end = self::item_end($item, $order);
        $claimable = $end && time() <= $end->getTimestamp();
        return (bool) apply_filters('eggl_repair_item_is_claimable', $claimable, $item, $order);
    }

    /** Claimable order lines of $order, keyed by item id. */
    public static function claimable_items(WC_Order $order) {
        if (!in_array($order->get_status(), self::claimable_order_statuses(), true)) return [];
        $out = [];
        foreach ($order->get_items() as $item_id => $item) {
            if (self::item_is_claimable($item, $order)) $out[(int) $item_id] = $item;
        }
        return $out;
    }

    public static function order_is_claimable(WC_Order $order) {
        return (bool) self::claimable_items($order);
    }

    /* ----------------------------------------------------------------------------------------
     * Queries
     * ------------------------------------------------------------------------------------- */

    /** Claim posts for an order, newest first. */
    public static function for_order($order_id) {
        return get_posts([
            'post_type'      => self::POST_TYPE,
            'post_status'    => array_values(self::statuses()),
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_key'       => '_eggl_rr_order_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value'     => (int) $order_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        ]);
    }

    /** Item ids of $order that already have an open (received / in progress) claim. */
    public static function items_with_open_claim($order_id) {
        $ids = [];
        foreach (self::for_order($order_id) as $claim) {
            if (!in_array(self::status($claim), self::open_statuses(), true)) continue;
            foreach ((array) self::meta($claim->ID, 'items', []) as $line) {
                $ids[] = (int) $line['item_id'];
            }
        }
        return array_values(array_unique($ids));
    }

    public static function count_by_status() {
        $counts = wp_count_posts(self::POST_TYPE);
        $out    = [];
        foreach (self::statuses() as $key => $status) {
            $out[$key] = isset($counts->$status) ? (int) $counts->$status : 0;
        }
        return $out;
    }

    /* ----------------------------------------------------------------------------------------
     * Writes
     * ------------------------------------------------------------------------------------- */

    /**
     * Creates a claim. $data: order (WC_Order), items ([item_id => qty]), remedy, description,
     * noticed (Y-m-d|''), email, name, phone, photos (attachment ids).
     *
     * @return int|WP_Error claim id
     */
    public static function create(array $data) {
        /** @var WC_Order $order */
        $order = $data['order'];
        $lines = [];
        foreach ($data['items'] as $item_id => $qty) {
            $item = $order->get_item($item_id);
            if (!$item) continue;
            $lines[] = ['item_id' => (int) $item_id, 'qty' => max(1, min((int) $qty, (int) $item->get_quantity())), 'name' => $item->get_name()];
        }
        if (!$lines) return new WP_Error('eggl_rr_no_items', __('Please choose at least one item.', 'garanlabs-eu-guarantee-notice-durability-labels'));

        $claim_id = wp_insert_post([
            'post_type'   => self::POST_TYPE,
            'post_status' => self::statuses()['received'],
            /* translators: %s: order number */
            'post_title'  => sprintf(__('Defect claim for order #%s', 'garanlabs-eu-guarantee-notice-durability-labels'), $order->get_order_number()),
            'post_author' => (int) $order->get_customer_id(),
        ], true);
        if (is_wp_error($claim_id)) return $claim_id;

        $meta = [
            'order_id'    => $order->get_id(),
            'items'       => $lines,
            'remedy'      => $data['remedy'] === 'replacement' ? 'replacement' : 'repair',
            'description' => $data['description'],
            'noticed'     => $data['noticed'],
            'email'       => $data['email'],
            'name'        => $data['name'],
            'phone'       => $data['phone'],
            'photos'      => array_map('intval', (array) $data['photos']),
            'log'         => [['time' => time(), 'status' => 'received', 'message' => '', 'user' => 0]],
        ];
        foreach ($meta as $key => $value) {
            update_post_meta($claim_id, '_eggl_rr_' . $key, $value);
        }
        foreach ($meta['photos'] as $attachment_id) {
            wp_update_post(['ID' => $attachment_id, 'post_parent' => $claim_id]);
        }

        $remedy = self::remedy_labels()[$meta['remedy']];
        $order->add_order_note(sprintf(
            /* translators: 1: claim number, 2: chosen remedy (Repair/Replacement) */
            __('Defect claim #%1$s received from the customer — requested remedy: %2$s.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            self::number($claim_id), $remedy
        ));

        do_action('eggl_repair_claim_created', $claim_id);
        return $claim_id;
    }

    /**
     * Moves a claim to $new_status (a key of statuses()). $message goes to the customer (and the
     * history). Marking a claim "repaired" extends each claimed item's guarantee by the configured
     * months. Fires eggl_repair_claim_status_changed($claim_id, $old, $new, $message).
     */
    public static function set_status($claim_id, $new_status, $message = '') {
        $statuses = self::statuses();
        if (!isset($statuses[$new_status])) return false;
        $old = self::status($claim_id);
        if ($old === '') return false;

        $message = trim((string) $message);
        if ($old === $new_status && $message === '') return false;

        wp_update_post(['ID' => (int) $claim_id, 'post_status' => $statuses[$new_status]]);
        update_post_meta($claim_id, '_eggl_rr_reason', $message);

        $log   = (array) self::meta($claim_id, 'log', []);
        $log[] = ['time' => time(), 'status' => $new_status, 'message' => $message, 'user' => get_current_user_id()];
        update_post_meta($claim_id, '_eggl_rr_log', $log);

        if ($new_status === 'repaired' && $old !== 'repaired') {
            self::apply_repair_extension($claim_id);
        }

        $order = wc_get_order((int) self::meta($claim_id, 'order_id', 0));
        if ($order && $old !== $new_status) {
            $order->add_order_note(sprintf(
                /* translators: 1: claim number, 2: new status */
                __('Defect claim #%1$s: status changed to %2$s.', 'garanlabs-eu-guarantee-notice-durability-labels'),
                self::number($claim_id), self::status_labels()[$new_status]
            ));
        }

        do_action('eggl_repair_claim_status_changed', (int) $claim_id, $old, $new_status, $message);
        return true;
    }

    /**
     * Extends the legal guarantee of every item on a repaired claim by the configured months,
     * counted from the item's current guarantee end (so repeated repairs keep adding up).
     */
    public static function apply_repair_extension($claim_id) {
        $order = wc_get_order((int) self::meta($claim_id, 'order_id', 0));
        if (!$order) return;

        $months   = EGGL_Repair_Settings::extension_months();
        $extended = [];
        foreach ((array) self::meta($claim_id, 'items', []) as $line) {
            $item = $order->get_item((int) $line['item_id']);
            if (!$item) continue;
            $end = self::item_end($item, $order);
            if (!$end) continue;
            $new_end = $end->modify('+' . $months . ' months')->format('Y-m-d');
            $item->update_meta_data(self::ITEM_META, $new_end);
            $item->save_meta_data();
            $extended[(int) $line['item_id']] = $new_end;

            $order->add_order_note(sprintf(
                /* translators: 1: product name, 2: date, 3: number of months, 4: claim number */
                __('Legal guarantee for "%1$s" extended to %2$s (%3$d months for the repair under claim #%4$s).', 'garanlabs-eu-guarantee-notice-durability-labels'),
                $item->get_name(), date_i18n(get_option('date_format'), strtotime($new_end)), $months, self::number($claim_id)
            ));
        }
        update_post_meta($claim_id, '_eggl_rr_extended', $extended);
    }
}
