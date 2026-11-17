<?php

namespace EGGL\PriceHistory\Modules\DigitalPriceList;

if (!defined('ABSPATH')) {
    exit;
}

/** Read-only, paginated public price list; uses the same Product_Mapper as the CSV generator (spec §28/§29). */
class REST_Controller
{
    const ROUTE_NAMESPACE = 'eggl-price-history/v1';
    const MAX_PER_PAGE = 200;

    public function __construct()
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes()
    {
        register_rest_route(self::ROUTE_NAMESPACE, '/price-list', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [self::class, 'get_items'],
            'permission_callback' => '__return_true',
            'args'                => [
                'page' => [
                    'type'              => 'integer',
                    'default'           => 1,
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'type'              => 'integer',
                    'default'           => 50,
                    'minimum'           => 1,
                    'maximum'           => self::MAX_PER_PAGE,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    public static function get_items(\WP_REST_Request $request)
    {
        if (empty(Helper::settings()['enable'])) {
            return new \WP_Error('eggl_ph_dpl_disabled', __('Digital Price List is not enabled.', 'garanlabs-eu-guarantee-notice-durability-labels'), ['status' => 404]);
        }

        global $wpdb;
        $page = max(1, (int) $request->get_param('page'));
        $per_page = min(self::MAX_PER_PAGE, max(1, (int) $request->get_param('per_page')));
        $where = Generator::purchasable_join_where();

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p {$where}");
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p {$where} ORDER BY p.ID LIMIT %d OFFSET %d",
            $per_page,
            ($page - 1) * $per_page
        )));

        _prime_post_caches($ids, false, true);
        $items = [];
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if (!$product || !Product_Mapper::is_exportable($product)) {
                continue;
            }
            $items[] = array_merge(['id' => $id], Product_Mapper::map($product));
        }

        $response = rest_ensure_response($items);
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) (int) ceil($total / $per_page));
        return $response;
    }
}
