<?php

namespace EGGL\PriceHistory\Modules\PriceComplianceManager;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/product-filters.php';

/** Generic bulk editor over Registry-registered modules; the user works with one module's fields at a time. */
class Editor
{
    const PER_PAGE            = 25;
    const VARIATIONS_PER_PAGE = 20;
    private $page_hook;

    public function __construct()
    {
        add_action('wp_ajax_eggl_ph_pcm_variations', [$this, 'ajax_variations']);
        add_action('wp_ajax_eggl_ph_pcm_save', [$this, 'ajax_save']);
    }

    public function set_page_hook($hook)
    {
        $this->page_hook = $hook;
    }

    public function assets($hook)
    {
        if (!$this->page_hook || $hook !== $this->page_hook) {
            return;
        }

        $root  = plugin_dir_path(dirname(__DIR__));
        $url   = plugin_dir_url(dirname(__DIR__));
        $asset = include $root . 'assets/build/price-compliance-manager.asset.php';
        wp_enqueue_script('eggl-ph-price-compliance-manager', $url . 'assets/build/price-compliance-manager.js', $asset['dependencies'], $asset['version'], true);
        wp_enqueue_style('eggl-ph-price-compliance-manager', $url . 'assets/build/price-compliance-manager.css', [], $asset['version']);
        wp_style_add_data('eggl-ph-price-compliance-manager', 'rtl', 'replace');
        wp_localize_script('eggl-ph-price-compliance-manager', 'egglPhPriceComplianceManager', [
            'loading'   => __('Loading variations…', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'saving'    => __('Saving changes…', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'noChanges' => __('No changes to save.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'saveFirst' => __('Save or discard your unsaved changes before changing filters or pages.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'failed'    => __('The request could not be completed. Your unsaved changes are still here. Please try again.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'show'      => __('Show variations', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'hide'      => __('Hide variations', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'import'    => __('Import CSV', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'resume'    => __('Resume import', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'importing' => __('Importing…', 'garanlabs-eu-guarantee-notice-durability-labels'),
        ]);
    }

    public static function nonce_action()
    {
        return 'eggl_ph_pcm_bulk_' . get_woocommerce_currency();
    }

    public static function selected_module($input)
    {
        $modules = Registry::all();
        $id      = isset($input['module']) && is_scalar($input['module']) ? sanitize_key((string) $input['module']) : '';
        return isset($modules[$id]) ? $id : (string) array_key_first($modules);
    }

    public static function filters($input)
    {
        $filters           = Product_Filters::sanitize($input);
        $filters['module'] = self::selected_module($input);
        $filters['paged']  = isset($input['paged']) && is_scalar($input['paged']) ? max(1, absint($input['paged'])) : 1;
        return $filters;
    }

    private function authorize()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('You cannot manage price compliance data.', 'garanlabs-eu-guarantee-notice-durability-labels')], 403);
        }
        check_ajax_referer(self::nonce_action(), 'nonce');
    }

    public static function products($ids)
    {
        global $wpdb;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }

        _prime_post_caches($ids, true, true);
        // WooCommerce also caches raw metadata with meta IDs, separately from WordPress.
        $raw          = array_fill_keys($ids, []);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder list only; IDs are bound below.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT post_id, meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) ORDER BY meta_id", $ids));
        foreach ($rows as $row) {
            $raw[$row->post_id][] = $row;
        }

        \WC_Data::prime_raw_meta_data_cache($raw, 'products');
        return array_values(array_filter(array_map('wc_get_product', $ids)));
    }

    /** Count each product once, including a variable product with an anchored variation. */
    public static function query_products($filters)
    {
        global $wpdb;
        $module = Registry::get($filters['module']);
        $lookup = $wpdb->prefix . 'wc_product_meta_lookup';
        $from   = "FROM {$wpdb->posts} p LEFT JOIN $lookup l ON l.product_id = p.ID";
        $where  = Product_Filters::where($filters);
        $set    = Product_Filters::status_condition($module);

        $counts = ['total' => 0, 'price_set' => 0, 'price_not_set' => 0];
        if ($set !== null) {
            // phpcs:ignore WordPress.DB.PreparedSQL -- Internal fragments are prepared above; table names come from wpdb.
            $counts                  = $wpdb->get_row("SELECT COUNT(*) AS total, COALESCE(SUM($set),0) AS price_set $from WHERE $where", ARRAY_A);
            $counts                  = array_map('intval', $counts);
            $counts['price_not_set'] = $counts['total'] - $counts['price_set'];
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL -- Internal fragments are prepared above; table names come from wpdb.
            $counts['total'] = (int) $wpdb->get_var("SELECT COUNT(*) $from WHERE $where");
        }
        $status = $set !== null ? $filters['status'] : 'all';
        $total  = $status === 'all' ? $counts['total'] : $counts[$status === 'set' ? 'price_set' : 'price_not_set'];
        if ($status !== 'all') {
            $where .= ' AND ' . ($status === 'unset' ? 'NOT ' : '') . $set;
        }

        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min($filters['paged'], $pages);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal SQL fragments are prepared above.
        $ids = $wpdb->get_col($wpdb->prepare("SELECT p.ID $from WHERE $where ORDER BY p.post_title ASC, p.ID ASC LIMIT %d OFFSET %d", self::PER_PAGE, ($page - 1) * self::PER_PAGE));
        return ['products' => self::products($ids), 'counts' => $counts, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    public static function query_variations($parent, $page, $search = '')
    {
        global $wpdb;
        $lookup = $wpdb->prefix . 'wc_product_meta_lookup';
        $from   = "FROM {$wpdb->posts} p LEFT JOIN $lookup l ON l.product_id = p.ID";
        $where  = $wpdb->prepare("p.post_type = 'product_variation' AND p.post_status IN ('publish','private','draft') AND p.post_parent = %d", $parent->get_id());
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= $wpdb->prepare(
                ' AND (p.post_title LIKE %s OR l.sku LIKE %s OR %s LIKE %s OR %s LIKE %s)',
                $like,
                $like,
                $parent->get_name(),
                $like,
                $parent->get_sku('edit'),
                $like
            );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL -- Prepared internal fragments and wpdb table names.
        $total = (int) $wpdb->get_var("SELECT COUNT(*) $from WHERE $where");
        $pages = max(1, (int) ceil($total / self::VARIATIONS_PER_PAGE));
        $page  = min(max(1, $page), $pages);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Prepared internal fragments.
        $ids = $wpdb->get_col($wpdb->prepare("SELECT p.ID $from WHERE $where ORDER BY p.menu_order ASC, p.ID ASC LIMIT %d OFFSET %d", self::VARIATIONS_PER_PAGE, ($page - 1) * self::VARIATIONS_PER_PAGE));
        return ['products' => self::products($ids), 'pages' => $pages, 'page' => $page, 'total' => $total];
    }

    /** A field of type 'price' arrives in the site's localized decimal format; every other type is used as-is. */
    private static function sanitize_field($module, $field_id, $raw)
    {
        if (!is_string($raw)) {
            return null;
        }
        $value = trim($raw);
        $type  = $module['fields'][$field_id]['type'] ?? 'text';
        return $type === 'price' ? str_replace(wc_get_price_decimal_separator(), '.', $value) : $value;
    }

    public static function save_changes($module_id, $changes)
    {
        if (!current_user_can('manage_woocommerce')) {
            return new \WP_Error('eggl_ph_pcm_access', __('You cannot manage price compliance data.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        $module = Registry::get($module_id);
        if (!$module) {
            return new \WP_Error('eggl_ph_pcm_module', __('Choose a valid data set to edit.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        if (!is_array($changes) || !$changes || count($changes) > 100) {
            return new \WP_Error('eggl_ph_pcm_batch', __('Save up to 100 changes at a time.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }

        $pending = [];
        foreach ($changes as $change) {
            $id      = is_array($change) ? filter_var($change['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            $product = $id ? wc_get_product($id) : false;
            if (!$product || !call_user_func($module['supports'], $product) || !in_array($product->get_status(), ['publish', 'private', 'draft', 'pending', 'future'], true) || !current_user_can('edit_post', $id) || isset($pending[$id])) {
                return new \WP_Error('eggl_ph_pcm_product', __('One of these products cannot be edited. Reload the page before saving.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            }
            if (!is_array($change['values'] ?? null) || !is_string($change['version'] ?? null)) {
                return new \WP_Error('eggl_ph_pcm_input', __('Enter valid values for every field.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            }
            $values = [];
            foreach ($module['fields'] as $field_id => $field) {
                $value = self::sanitize_field($module, $field_id, $change['values'][$field_id] ?? null);
                if ($value === null) {
                    return new \WP_Error('eggl_ph_pcm_input', __('Enter valid values for every field.', 'garanlabs-eu-guarantee-notice-durability-labels'));
                }
                $values[$field_id] = $value;
            }
            $validation = call_user_func($module['validate'], $values);
            if (is_wp_error($validation)) {
                return $validation;
            }
            $product->read_meta_data(true);
            // An identical result makes retries safe when the previous response was lost.
            if (!hash_equals(call_user_func($module['version'], $product), $change['version']) && !call_user_func($module['matches'], $product, $values)) {
                return self::conflict();
            }
            $pending[$id] = ['product' => $product, 'values' => $values, 'version' => $change['version']];
        }

        $rows = [];
        foreach ($pending as $id => $item) {
            $product = $item['product'];
            $values  = $item['values'];
            $product->read_meta_data(true);
            if (!call_user_func($module['matches'], $product, $values)) {
                if (!hash_equals(call_user_func($module['version'], $product), $item['version'])) {
                    return self::conflict($rows);
                }
                $result = call_user_func($module['set_values'], $product, $values);
                if (is_wp_error($result)) {
                    $result->add_data(['rows' => $rows]);
                    return $result;
                }
                if ($result === true) {
                    $product->save_meta_data();
                    $product->read_meta_data(true);
                    if (!call_user_func($module['matches'], $product, $values)) {
                        return new \WP_Error('eggl_ph_pcm_save', __('A change could not be saved. Reload to review its values before retrying.', 'garanlabs-eu-guarantee-notice-durability-labels'), ['rows' => $rows]);
                    }
                }
            }
            $rows[$id] = ['values' => call_user_func($module['get_values'], $product), 'version' => call_user_func($module['version'], $product)];
        }
        return ['rows' => $rows];
    }

    private static function conflict($rows = [])
    {
        return new \WP_Error('eggl_ph_pcm_conflict', __('A value changed since this page loaded. Reload before saving.', 'garanlabs-eu-guarantee-notice-durability-labels'), ['rows' => $rows]);
    }

    public function ajax_save()
    {
        $this->authorize();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing -- authorize() verifies the nonce; decoded, type-checked and strictly validated by save_changes.
        $module_id = isset($_POST['module']) && is_scalar($_POST['module']) ? sanitize_key(wp_unslash((string) $_POST['module'])) : '';
        $raw       = $_POST['changes'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing -- see above.
        $changes   = is_string($raw) && strlen($raw) <= 100000 ? json_decode(wp_unslash($raw), true) : null;
        $result    = self::save_changes($module_id, $changes);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message(), 'rows' => $result->get_error_data()['rows'] ?? []], 400);
        }
        wp_send_json_success($result);
    }

    private function table_head($module, $variations = false)
    {
        echo '<thead><tr>';
        $labels = array_merge(
            [$variations ? __('Variation', 'garanlabs-eu-guarantee-notice-durability-labels') : __('Product', 'garanlabs-eu-guarantee-notice-durability-labels'), __('SKU', 'garanlabs-eu-guarantee-notice-durability-labels'), __('Regular Price', 'garanlabs-eu-guarantee-notice-durability-labels')],
            wp_list_pluck($module['fields'], 'label')
        );
        foreach ($labels as $label) {
            echo '<th scope="col">' . esc_html($label) . '</th>';
        }
        echo '</tr></thead>';
    }

    private function rows($products, $module)
    {
        $columns = count($module['fields']) + 2;
        foreach ($products as $product) {
            $id       = $product->get_id();
            $editable = call_user_func($module['supports'], $product) && current_user_can('edit_post', $id);
            echo '<tr';
            if ($editable) {
                echo ' class="eggl-ph-pcm-row" data-product="' . esc_attr($id) . '" data-version="' . esc_attr(call_user_func($module['version'], $product)) . '"';
            }
            echo '><td><a href="' . esc_url($product->get_permalink()) . '" class="eggl-ph-pcm-product-link" target="_blank">' .  esc_html($product->get_name()) . '</a>';
            if ($product->is_type('variable')) {
                echo '<br><button type="button" class="button-link eggl-ph-pcm-expand" data-parent="' . esc_attr($id) . '" aria-expanded="false" aria-controls="eggl-ph-pcm-variations-' . esc_attr($id) . '">' . esc_html__('Show variations', 'garanlabs-eu-guarantee-notice-durability-labels') . '</button>';
            }
            $regular = $product->get_regular_price('edit');
            echo '</td><td>' . esc_html($product->get_sku('edit') ?: '—') . '</td><td>';
            if ($product->is_type('variable')) {
                echo esc_html__('See variations', 'garanlabs-eu-guarantee-notice-durability-labels');
            } elseif ($regular === '') {
                echo '—';
            } else {
                echo wp_kses_post(wc_price($regular));
            }
            echo '</td>';
            if ($editable) {
                $values = call_user_func($module['get_values'], $product);
                foreach ($module['fields'] as $field_id => $field) {
                    $value    = $field['type'] === 'price' && $values[$field_id] !== '' ? wc_format_localized_price($values[$field_id]) : ($values[$field_id] ?? '');
                    $input_id = 'eggl-ph-pcm-' . $field_id . '-' . $id;
                    echo '<td><label class="screen-reader-text" for="' . esc_attr($input_id) . '">' . esc_html($product->get_name() . ': ' . $field['label']) . '</label><input class="eggl-ph-pcm-value" id="' . esc_attr($input_id) . '" data-field="' . esc_attr($field_id) . '" data-original="' . esc_attr($value) . '" value="' . esc_attr($value) . '" type="' . ($field['type'] === 'date' ? 'date' : 'text') . '"' . ($field['type'] === 'price' ? ' inputmode="decimal" autocomplete="off"' : '') . '></td>';
                }
            } else {
                echo '<td colspan="' . esc_attr(count($module['fields'])) . '">' . esc_html($product->is_type('variable') ? __('Edit individual variations below.', 'garanlabs-eu-guarantee-notice-durability-labels') : __('No permission to edit.', 'garanlabs-eu-guarantee-notice-durability-labels')) . '</td>';
            }
            echo '</tr>';
            if ($product->is_type('variable')) {
                echo '<tr id="eggl-ph-pcm-variations-' . esc_attr($id) . '" class="eggl-ph-pcm-variation-container" hidden><td colspan="' . esc_attr($columns) . '"><div></div></td></tr>';
            }
        }
    }

    public function ajax_variations()
    {
        $this->authorize();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verified the request nonce above.
        $parent = isset($_POST['parent']) && is_scalar($_POST['parent']) ? wc_get_product(absint($_POST['parent'])) : false;
        if (!$parent || !$parent->is_type('variable') || !current_user_can('edit_post', $parent->get_id())) {
            wp_send_json_error(['message' => __('Choose a variable product.', 'garanlabs-eu-guarantee-notice-durability-labels')], 400);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verified the request nonce above.
        $filters = self::filters($_POST);
        $module  = Registry::get($filters['module']);
        if (!$module) {
            wp_send_json_error(['message' => __('Choose a valid data set to edit.', 'garanlabs-eu-guarantee-notice-durability-labels')], 400);
        }
        $result = self::query_variations($parent, $filters['paged'], $filters['s']);
        $columns = count($module['fields']) + 2;
        ob_start();
        echo '<table class="widefat striped eggl-ph-pcm-variation-table">';
        $this->table_head($module, true);
        echo '<tbody>';
        $this->rows($result['products'], $module);
        if (!$result['products']) {
            echo '<tr><td colspan="' . esc_attr($columns) . '">' . esc_html__('No variations match this search.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</td></tr>';
        }

        echo '</tbody></table><div class="eggl-ph-pcm-variation-pages">';
        foreach ([-1 => __('Previous', 'garanlabs-eu-guarantee-notice-durability-labels'), 1 => __('Next', 'garanlabs-eu-guarantee-notice-durability-labels')] as $direction => $label) {
            $page = $result['page'] + $direction;
            echo '<button type="button" class="button eggl-ph-pcm-variation-page" data-parent="' . esc_attr($parent->get_id()) . '" data-page="' . esc_attr($page) . '"' . disabled($page < 1 || $page > $result['pages'], true, false) . '>' . esc_html($label) . '</button> ';
        }
        /* translators: 1: current page number, 2: total number of pages. */
        echo '<span>' . esc_html(sprintf(__('Page %1$d of %2$d', 'garanlabs-eu-guarantee-notice-durability-labels'), $result['page'], $result['pages'])) . '</span></div>';
        wp_send_json_success(['html' => ob_get_clean()]);
    }

    public function render()
    {
        $modules = Registry::all();
        if (!$modules) {
            // Anchor Price is the only data set that registers here, and only while it's enabled.
            $url = add_query_arg(['page' => \EGGL\PriceHistory\Admin\PPH_Admin::PAGE_SLUG, 'tab' => 'anchor_price'], admin_url('admin.php'));
            echo '<div class="eggl-card"><div class="eggl-card__head"><h2>' . esc_html__('Nothing to edit yet', 'garanlabs-eu-guarantee-notice-durability-labels') . '</h2>';
            echo '<p>' . esc_html__('This screen edits base prices in bulk. Enable Base Price first, then come back here.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p></div>';
            echo '<div class="eggl-card__body"><a class="button button-primary" href="' . esc_url($url) . '">' . esc_html__('Go to Base Price settings', 'garanlabs-eu-guarantee-notice-durability-labels') . '</a></div></div>';
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search and pagination filters.
        $filters = self::filters($_GET);
        $module  = Registry::get($filters['module']);
        $result  = self::query_products($filters);
        $columns = count($module['fields']) + 2;

        echo '<div id="eggl-ph-price-compliance-manager" data-nonce="' . esc_attr(wp_create_nonce(self::nonce_action())) . '" data-ajax="' . esc_url(admin_url('admin-ajax.php')) . '" data-search="' . esc_attr($filters['s']) . '" data-module="' . esc_attr($filters['module']) . '">';
        CSV::section($filters);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only confirmation flag; no data is changed.
        echo '<p id="eggl-ph-pcm-message" role="status" aria-live="polite">' . (isset($_GET['saved']) ? esc_html__('Changes saved.', 'garanlabs-eu-guarantee-notice-durability-labels') : '') . '</p>';

        if (count($modules) > 1) {
            echo '<form method="get" class="eggl-ph-pcm-module"><input type="hidden" name="page" value="' . esc_attr(PCM_Admin::PAGE) . '"><label>' . esc_html__('Data to edit', 'garanlabs-eu-guarantee-notice-durability-labels') . '<select name="module" onchange="this.form.submit()">';
            foreach ($modules as $id => $entry) {
                echo '<option value="' . esc_attr($id) . '"' . selected($filters['module'], $id, false) . '>' . esc_html($entry['label']) . '</option>';
            }
            echo '</select></label></form>';
        }

        if (!empty($module['set_condition'])) {
            echo '<div class="eggl-ph-pcm-overview">';
            foreach (['total' => __('Products', 'garanlabs-eu-guarantee-notice-durability-labels'), 'price_set' => __('Set', 'garanlabs-eu-guarantee-notice-durability-labels'), 'price_not_set' => __('Not set', 'garanlabs-eu-guarantee-notice-durability-labels')] as $key => $label) {
                echo '<span><strong>' . esc_html($result['counts'][$key]) . '</strong> ' . esc_html($label) . '</span>';
            }
            echo '</div><p class="description">' . esc_html__('A variable product counts as set when any variation has a value. Expand it to edit individual variations.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
        }

        echo '<form method="get" class="eggl-ph-pcm-filters"><input type="hidden" name="page" value="' . esc_attr(PCM_Admin::PAGE) . '"><input type="hidden" name="module" value="' . esc_attr($filters['module']) . '"><label>' . esc_html__('Search products / SKU', 'garanlabs-eu-guarantee-notice-durability-labels') . '<input type="search" name="s" value="' . esc_attr($filters['s']) . '"></label>';
        $status_options = ['all' => __('All', 'garanlabs-eu-guarantee-notice-durability-labels'), 'set' => __('Set', 'garanlabs-eu-guarantee-notice-durability-labels'), 'unset' => __('Not set', 'garanlabs-eu-guarantee-notice-durability-labels')];
        $type_field = ['type' => [__('Product type', 'garanlabs-eu-guarantee-notice-durability-labels'), ['all' => __('All', 'garanlabs-eu-guarantee-notice-durability-labels'), 'simple' => __('Simple', 'garanlabs-eu-guarantee-notice-durability-labels'), 'variable' => __('Variable', 'garanlabs-eu-guarantee-notice-durability-labels')]]];
        $fields = !empty($module['set_condition']) ? $type_field + ['status' => [__('Status', 'garanlabs-eu-guarantee-notice-durability-labels'), $status_options]] : $type_field;
        foreach ($fields as $key => $field) {
            echo '<label>' . esc_html($field[0]) . '<select name="' . esc_attr($key) . '">';
            foreach ($field[1] as $value => $label) {
                echo '<option value="' . esc_attr($value) . '"' . selected($filters[$key], $value, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></label>';
        }
        echo '<label>' . esc_html__('Product category', 'garanlabs-eu-guarantee-notice-durability-labels');
        wp_dropdown_categories(['taxonomy' => 'product_cat', 'name' => 'category', 'id' => 'eggl-ph-pcm-category', 'selected' => $filters['category'], 'hierarchical' => true, 'hide_empty' => false, 'show_option_all' => __('All categories', 'garanlabs-eu-guarantee-notice-durability-labels'), 'value_field' => 'term_id']);
        echo '</label><button type="submit" class="button">' . esc_html__('Filter', 'garanlabs-eu-guarantee-notice-durability-labels') . '</button></form>';

        echo '<div class="eggl-ph-pcm-table-scroll"><table class="widefat striped eggl-ph-pcm-products">';
        $this->table_head($module);
        echo '<tbody>';
        $this->rows($result['products'], $module);
        if (!$result['products']) {
            echo '<tr><td colspan="' . esc_attr($columns) . '">' . esc_html__('No products match your filters.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</td></tr>';
        }
        echo '</tbody></table></div><div class="tablenav"><div class="tablenav-pages">';
        $base = add_query_arg(array_merge($filters, ['s' => rawurlencode($filters['s']), 'page' => PCM_Admin::PAGE, 'paged' => '%#%']), admin_url('admin.php'));
        echo wp_kses_post((string) paginate_links(['base' => $base, 'format' => '', 'current' => $result['page'], 'total' => $result['pages']]));
        echo '</div></div><p><button type="button" class="button button-primary" id="eggl-ph-pcm-save" disabled>' . esc_html__('Save changes', 'garanlabs-eu-guarantee-notice-durability-labels') . '</button> <button type="button" class="button" id="eggl-ph-pcm-discard" disabled>' . esc_html__('Discard changes', 'garanlabs-eu-guarantee-notice-durability-labels') . '</button> <span class="spinner eggl-ph-pcm-save-spinner" aria-hidden="true"></span></p>';
        echo '</div>';
    }
}
