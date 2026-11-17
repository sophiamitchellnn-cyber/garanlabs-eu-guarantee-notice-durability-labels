<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/** The Claims tab of EU Guarantee & GARAN → Right to Repair. */
class EGGL_Repair_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct(['singular' => 'eggl_rr_claim', 'plural' => 'eggl_rr_claims', 'ajax' => false]);
    }

    /** Current status filter key ('' = open claims, 'all' = everything). */
    private function current_status() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
        $status = isset($_GET['claim_status']) ? sanitize_key(wp_unslash($_GET['claim_status'])) : '';
        return ($status === 'all' || isset(EGGL_Repair_Claims::statuses()[$status])) ? $status : '';
    }

    public function get_columns() {
        return [
            'cb'       => '<input type="checkbox">',
            'claim'    => __('Claim', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'order'    => __('Order', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'customer' => __('Customer', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'items'    => __('Items', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'remedy'   => __('Wants', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'status'   => __('Status', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'date'     => __('Received', 'garanlabs-eu-guarantee-notice-durability-labels'),
        ];
    }

    protected function get_sortable_columns() {
        return ['claim' => ['ID', true], 'date' => ['date', true]];
    }

    protected function get_bulk_actions() {
        return [
            'mark_in_progress' => __('Mark as in progress (emails customers)', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'delete'           => __('Delete', 'garanlabs-eu-guarantee-notice-durability-labels'),
        ];
    }

    protected function get_views() {
        $counts  = EGGL_Repair_Claims::count_by_status();
        $labels  = EGGL_Repair_Claims::status_labels();
        $current = $this->current_status();
        $open    = 0;
        foreach (EGGL_Repair_Claims::open_statuses() as $key) $open += $counts[$key];

        $link = function ($status, $label, $count) use ($current) {
            $url = EGGL_Repair_Admin::page_url($status === '' ? [] : ['claim_status' => $status]);
            return sprintf('<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url($url), $current === $status ? ' class="current" aria-current="page"' : '', esc_html($label), (int) $count);
        };

        $views = [
            'open' => $link('', __('Open', 'garanlabs-eu-guarantee-notice-durability-labels'), $open),
            'all'  => $link('all', __('All', 'garanlabs-eu-guarantee-notice-durability-labels'), array_sum($counts)),
        ];
        foreach ($labels as $key => $label) {
            if ($counts[$key] > 0) $views[$key] = $link($key, $label, $counts[$key]);
        }
        return $views;
    }

    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];

        $statuses = EGGL_Repair_Claims::statuses();
        $current  = $this->current_status();
        if ($current === 'all') {
            $post_status = array_values($statuses);
        } elseif ($current !== '') {
            $post_status = [$statuses[$current]];
        } else {
            $post_status = array_map(function ($key) use ($statuses) { return $statuses[$key]; }, EGGL_Repair_Claims::open_statuses());
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list arguments.
        $orderby = (isset($_GET['orderby']) && sanitize_key(wp_unslash($_GET['orderby'])) === 'ID') ? 'ID' : 'date';
        $order   = (isset($_GET['order']) && strtolower(sanitize_key(wp_unslash($_GET['order']))) === 'asc') ? 'ASC' : 'DESC';
        $search  = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        // phpcs:enable

        $args = [
            'post_type'      => EGGL_Repair_Claims::POST_TYPE,
            'post_status'    => $post_status,
            'posts_per_page' => 20,
            'paged'          => $this->get_pagenum(),
            'orderby'        => $orderby,
            'order'          => $order,
        ];

        if ($search !== '') {
            // Claim number, order number/id, or customer email/name.
            $number = absint(ltrim($search, '#'));
            $meta   = [
                'relation' => 'OR',
                ['key' => '_eggl_rr_email', 'value' => $search, 'compare' => 'LIKE'],
                ['key' => '_eggl_rr_name', 'value' => $search, 'compare' => 'LIKE'],
            ];
            if ($number) $meta[] = ['key' => '_eggl_rr_order_id', 'value' => $number];
            $by_meta = get_posts(array_merge($args, ['fields' => 'ids', 'posts_per_page' => -1, 'paged' => 1, 'meta_query' => $meta])); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            $ids     = $number ? array_merge($by_meta, [$number]) : $by_meta;
            $args['post__in'] = $ids ?: [0];
        }

        $query       = new WP_Query($args);
        $this->items = $query->posts;
        $this->set_pagination_args(['total_items' => (int) $query->found_posts, 'per_page' => 20]);
    }

    public function no_items() {
        esc_html_e('No defect claims here.', 'garanlabs-eu-guarantee-notice-durability-labels');
    }

    protected function column_cb($claim) {
        return '<input type="checkbox" name="claims[]" value="' . (int) $claim->ID . '">';
    }

    protected function column_claim($claim) {
        $url = EGGL_Repair_Admin::claim_url($claim->ID);
        return '<strong><a class="row-title" href="' . esc_url($url) . '">#' . esc_html(EGGL_Repair_Claims::number($claim->ID)) . '</a></strong>'
            . $this->row_actions(['view' => '<a href="' . esc_url($url) . '">' . esc_html__('Open', 'garanlabs-eu-guarantee-notice-durability-labels') . '</a>']);
    }

    protected function column_order($claim) {
        $order = wc_get_order((int) EGGL_Repair_Claims::meta($claim->ID, 'order_id', 0));
        return $order ? '<a href="' . esc_url($order->get_edit_order_url()) . '">#' . esc_html($order->get_order_number()) . '</a>' : '—';
    }

    protected function column_customer($claim) {
        $name  = (string) EGGL_Repair_Claims::meta($claim->ID, 'name', '');
        $email = (string) EGGL_Repair_Claims::meta($claim->ID, 'email', '');
        return esc_html($name) . ($name !== '' ? '<br>' : '') . '<span class="description">' . esc_html($email) . '</span>';
    }

    protected function column_items($claim) {
        $out = [];
        foreach ((array) EGGL_Repair_Claims::meta($claim->ID, 'items', []) as $line) {
            $out[] = esc_html($line['name'] . ' × ' . (int) $line['qty']);
        }
        return implode('<br>', $out);
    }

    protected function column_remedy($claim) {
        $remedy = (string) EGGL_Repair_Claims::meta($claim->ID, 'remedy', 'repair');
        return esc_html(EGGL_Repair_Claims::remedy_labels()[$remedy] ?? '');
    }

    protected function column_status($claim) {
        $status = EGGL_Repair_Claims::status($claim);
        return '<mark class="eggl-rr-status eggl-rr-status--' . esc_attr($status) . '">' . esc_html(EGGL_Repair_Claims::status_labels()[$status] ?? '') . '</mark>';
    }

    protected function column_date($claim) {
        return esc_html(get_the_date(get_option('date_format'), $claim));
    }
}
