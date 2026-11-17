<?php

namespace EGGL\PriceHistory\Modules\PriceComplianceManager;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/product-filters.php';
require_once __DIR__ . '/editor.php';

/** CSV export/import for whichever registered modules the user selects; never writes prices itself. */
class CSV
{
    const BATCH_SIZE = 200;
    const CLEANUP    = 'eggl_ph_pcm_csv_cleanup';

    public function __construct()
    {
        add_action('admin_post_eggl_ph_pcm_csv_export', [$this, 'download']);
        add_action('wp_ajax_eggl_ph_pcm_csv_upload', [$this, 'upload']);
        add_action('wp_ajax_eggl_ph_pcm_csv_import', [$this, 'process']);
        add_action(self::CLEANUP, [self::class, 'cleanup']);
    }

    public static function nonce_action()
    {
        return 'eggl_ph_pcm_csv_' . get_woocommerce_currency();
    }

    public static function section($filters)
    {
        $modules = Registry::all();
?>
        <section class="eggl-ph-pcm-csv" aria-labelledby="eggl-ph-pcm-csv-title">
            <h2 id="eggl-ph-pcm-csv-title"><?php esc_html_e('Import / Export', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h2>
            <div class="nav-tab-wrapper eggl-ph-pcm-csv-tabs" role="tablist" aria-label="<?php esc_attr_e('Price compliance CSV', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>">
                <button type="button" id="eggl-ph-pcm-csv-export-tab" class="nav-tab nav-tab-active" role="tab" aria-selected="true" aria-controls="eggl-ph-pcm-csv-export-panel"><?php esc_html_e('Export', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></button>
                <button type="button" id="eggl-ph-pcm-csv-import-tab" class="nav-tab" role="tab" aria-selected="false" aria-controls="eggl-ph-pcm-csv-import-panel" tabindex="-1"><?php esc_html_e('Import', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></button>
            </div>
            <div id="eggl-ph-pcm-csv-export-panel" role="tabpanel" aria-labelledby="eggl-ph-pcm-csv-export-tab">
            <form class="eggl-ph-pcm-csv-export" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="eggl_ph_pcm_csv_export">
                <?php wp_nonce_field(self::nonce_action(), 'nonce'); ?>
                <?php foreach (['s', 'type', 'category', 'status', 'module'] as $key) : ?>
                    <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($filters[$key] ?? ''); ?>">
                <?php endforeach; ?>
                <fieldset>
                    <legend><?php esc_html_e('Data to export', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></legend>
                    <?php foreach ($modules as $id => $module) : ?>
                        <label><input type="checkbox" name="modules[]" value="<?php echo esc_attr($id); ?>" checked> <?php echo esc_html($module['label']); ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <button class="button" type="submit"><?php esc_html_e('Export CSV', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></button>
                <span class="spinner" aria-hidden="true"></span>
                <span class="eggl-ph-pcm-csv-export-status" role="status" data-loading="<?php esc_attr_e('Generating CSV…', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>" data-failed="<?php esc_attr_e('CSV export failed. Please try again.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>"></span>
            </form>
            <p class="description"><?php esc_html_e('Exports products and variations matching the filters below.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
            </div>
            <div id="eggl-ph-pcm-csv-import-panel" role="tabpanel" aria-labelledby="eggl-ph-pcm-csv-import-tab" hidden>
            <form class="eggl-ph-pcm-csv-import" method="post" enctype="multipart/form-data">
                <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce(self::nonce_action())); ?>">
                <fieldset>
                    <legend><?php esc_html_e('Data to import', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></legend>
                    <?php foreach ($modules as $id => $module) : ?>
                        <label><input type="checkbox" name="modules[]" value="<?php echo esc_attr($id); ?>" checked> <?php echo esc_html($module['label']); ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <label for="eggl-ph-pcm-csv-file" class="screen-reader-text"><?php esc_html_e('Price compliance CSV file', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></label>
                <input id="eggl-ph-pcm-csv-file" name="csv" type="file" accept=".csv,text/csv" required>
                <button class="button" type="submit" disabled><?php esc_html_e('Import CSV', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></button>
            </form>
            <p class="description"><?php esc_html_e('Use a UTF-8 CSV with a product_id column (preferred) or, if product_id is absent, a sku column, plus the columns for the data sets selected above. Leave a row\'s columns for a data set empty to leave it unchanged; imported values always overwrite the current stored values.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
            <div class="eggl-ph-pcm-csv-progress" hidden>
                <div class="eggl-ph-pcm-csv-progress-meter">
                    <progress max="100" value="0" aria-label="<?php esc_attr_e('CSV import progress', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>"></progress>
                    <span class="eggl-ph-pcm-csv-percent" aria-hidden="true">0%</span>
                </div>
                <p class="eggl-ph-pcm-csv-import-status" role="status"></p>
            </div>
            <a class="eggl-ph-pcm-csv-refresh" href="<?php echo esc_url(remove_query_arg(['saved', 'paged'])); ?>" hidden><?php esc_html_e('Refresh table to review imported values', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></a>
            </div>
        </section>
<?php
    }

    private static function authorize($ajax = true)
    {
        if (!current_user_can('manage_woocommerce')) {
            if ($ajax) {
                wp_send_json_error(['message' => __('You cannot manage price compliance data.', 'garanlabs-eu-guarantee-notice-durability-labels')], 403);
            }
            wp_die(esc_html__('You cannot manage price compliance data.', 'garanlabs-eu-guarantee-notice-durability-labels'), '', ['response' => 403]);
        }
        if ($ajax) {
            check_ajax_referer(self::nonce_action(), 'nonce');
        } else {
            check_admin_referer(self::nonce_action(), 'nonce');
        }
    }

    /**
     * Throws a RuntimeException carrying a static, hardcoded translated message. The only caller
     * (download()) catches \Throwable and surfaces getMessage() via wp_die(esc_html(...)).
     */
    private static function fail($message)
    {
        throw new \RuntimeException($message); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- see docblock above.
    }

    // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Streams CSVs without loading them into memory and implements a resumable, byte-offset, flock()-guarded import; WP_Filesystem supports neither.
    private static function write_row($handle, $row)
    {
        $row = array_map(static function ($value) {
            $value = wp_check_invalid_utf8(wp_strip_all_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            // A leading apostrophe makes formula-looking text inert in spreadsheet applications.
            if (preg_match('/^[\s\x00-\x1f]*[=+@\-]/u', $value) || preg_match('/^[\t\r\n]/', $value)) {
                $value = "'" . $value;
            }
            return $value;
        }, $row);
        if (fputcsv($handle, $row, ',', '"', '') === false) {
            self::fail(__('Could not write CSV.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
    }

    private static function columns($modules)
    {
        $columns = ['product_id', 'parent_id', 'type', 'product_name', 'product_url', 'sku', 'categories', 'regular_price'];
        foreach ($modules as $module) {
            foreach ($module['fields'] as $field) {
                $columns[] = $field['csv_column'];
            }
        }
        return $columns;
    }

    private static function product_ids($filters, $cursor, $max_id)
    {
        global $wpdb;
        $module = Registry::get($filters['module']);
        $lookup = $wpdb->prefix . 'wc_product_meta_lookup';
        $where  = Product_Filters::where($filters);
        $set    = Product_Filters::status_condition($module);
        if ($filters['status'] !== 'all' && $set !== null) {
            $where .= ' AND ' . ($filters['status'] === 'unset' ? 'NOT ' : '') . $set;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal SQL fragments are prepared above.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN $lookup l ON l.product_id = p.ID WHERE p.ID > %d AND p.ID <= %d AND $where ORDER BY p.ID LIMIT %d",
            $cursor,
            $max_id,
            self::BATCH_SIZE
        ));
        return array_map('intval', $ids);
    }

    private static function write_product_row($handle, $product, $parent, $modules)
    {
        $terms = get_the_terms($parent ? $parent->get_id() : $product->get_id(), 'product_cat');
        $row   = [
            $product->get_id(), $product->get_parent_id(), $product->get_type(), $product->get_name(),
            $product->get_permalink(), $product->get_sku('edit'),
            $terms && !is_wp_error($terms) ? implode(' | ', wp_list_pluck($terms, 'name')) : '', $product->get_regular_price('edit'),
        ];
        foreach ($modules as $id => $module) {
            $supports = call_user_func($module['supports'], $product);
            $values   = $supports ? call_user_func($module['csv_get_values'] ?? $module['get_values'], $product) : array_fill_keys(array_keys($module['fields']), '');
            foreach (array_keys($module['fields']) as $field_id) {
                $row[] = $values[$field_id] ?? '';
            }
        }
        self::write_row($handle, $row);
    }

    public static function export($handle, $filters, $module_ids)
    {
        global $wpdb;
        if (fwrite($handle, "\xEF\xBB\xBF") !== 3) {
            self::fail(__('Could not write CSV.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        $modules = [];
        foreach ($module_ids as $id) {
            $module = Registry::get($id);
            if ($module) {
                $modules[$id] = $module;
            }
        }
        self::write_row($handle, self::columns($modules));
        $max_id = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}");
        $cursor = 0;
        $count  = 0;
        while ($ids = self::product_ids($filters, $cursor, $max_id)) {
            $cursor   = max($ids);
            $products = [];
            foreach (Editor::products($ids) as $product) {
                $products[$product->get_id()] = $product;
            }
            $variation_ids = [];
            foreach ($products as $product) {
                if ($product->is_type('variable')) {
                    $variation_ids = array_merge($variation_ids, $product->get_children());
                }
            }
            foreach (Editor::products($variation_ids) as $variation) {
                $products[$variation->get_id()] = $variation;
            }
            foreach ($ids as $id) {
                $product = $products[$id] ?? null;
                if (!$product || !current_user_can('edit_post', $id)) {
                    continue;
                }
                if ($product->is_type('variable')) {
                    foreach ($product->get_children() as $variation_id) {
                        $variation = $products[$variation_id] ?? null;
                        if (!$variation || !current_user_can('edit_post', $variation_id)) {
                            continue;
                        }
                        self::write_product_row($handle, $variation, $product, $modules);
                        $count++;
                    }
                    continue;
                }
                self::write_product_row($handle, $product, null, $modules);
                $count++;
            }
            // Only discard request-local caches; never flush the shared persistent cache.
            if (wp_cache_supports('flush_runtime')) {
                wp_cache_flush_runtime();
            }
        }
        return $count;
    }

    public function download()
    {
        self::authorize(false);
        $handle = null;
        try {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verified the nonce above.
            $filters      = Editor::filters($_POST);
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verified the nonce above.
            $selected     = isset($_POST['modules']) && is_array($_POST['modules']) ? array_map('sanitize_key', wp_unslash($_POST['modules'])) : [];
            $module_ids   = array_values(array_intersect(array_keys(Registry::all()), $selected));
            if (!$module_ids) {
                self::fail(__('Choose at least one data set to export.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            }

            wc_set_time_limit(0);
            // Finish generation before sending CSV headers, so errors cannot become partial downloads.
            $handle = tmpfile();
            if (!$handle) {
                self::fail(__('Could not prepare CSV download.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            }
            self::export($handle, $filters, $module_ids);
            $size = ftell($handle);
            rewind($handle);
            while (ob_get_level()) {
                ob_end_clean();
            }
            nocache_headers();
            header('X-Accel-Buffering: no');
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="price-compliance-' . gmdate('Y-m-d') . '.csv"');
            header('Content-Length: ' . $size);
            header('X-Content-Type-Options: nosniff');
            fpassthru($handle); // phpcs:ignore WordPress.Security.EscapeOutput -- CSV cells are escaped by fputcsv and spreadsheet formulas neutralized by write_row.
            fclose($handle);
            exit;
        } catch (\Throwable $error) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            wp_die(esc_html($error->getMessage()), '', ['response' => 400]);
        }
    }
    public static function read_header($handle, $modules)
    {
        $delimiter = ',';
        $header    = fgetcsv($handle, 0, $delimiter, '"', '');
        if (is_array($header) && count($header) === 1) {
            rewind($handle);
            $delimiter = ';';
            $header    = fgetcsv($handle, 0, $delimiter, '"', '');
        }
        if (!$header) {
            self::fail(__('The CSV file is empty.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header    = array_map('trim', $header);
        foreach ($header as $column) {
            if (!preg_match('//u', $column)) {
                self::fail(__('Use a UTF-8 CSV file.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            }
        }
        if (count($header) !== count(array_unique($header))) {
            self::fail(__('CSV column names must be unique.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        // product_id is preferred; sku is only a fallback for CSVs with no product_id column at all.
        if (in_array('product_id', $header, true)) {
            $identifier = 'product_id';
        } elseif (in_array('sku', $header, true)) {
            $identifier = 'sku';
        } else {
            self::fail(__('CSV needs a product_id column, or a sku column if product_id is not available.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        $columns = [];
        foreach ($modules as $module_id => $module) {
            foreach ($module['fields'] as $field_id => $field) {
                $index = array_search($field['csv_column'], $header, true);
                if ($index === false) {
                    self::fail(sprintf(
                        /* translators: %s: CSV column name, e.g. "anchor_price_date". */
                        __('CSV needs a column named %s for the selected data set.', 'garanlabs-eu-guarantee-notice-durability-labels'),
                        $field['csv_column']
                    ));
                }
                $columns[$module_id][$field_id] = $index;
            }
        }
        return ['identifier' => $identifier, 'product' => array_search($identifier, $header, true), 'width' => count($header), 'delimiter' => $delimiter, 'modules' => $columns];
    }

    public static function import_batch($handle, $header, $modules)
    {
        $stats = array_fill_keys(['rows', 'updated', 'unchanged', 'skipped', 'invalid', 'failed', 'conflicts'], 0);
        for ($i = 0; $i < self::BATCH_SIZE && ($row = fgetcsv($handle, 0, $header['delimiter'], '"', '')) !== false; $i++) {
            if ($row === [null]) {
                continue;
            }
            $stats['rows']++;
            if (count($row) !== $header['width'] || array_filter($row, static function ($value) {
                return !preg_match('//u', (string) $value);
            })) {
                $stats['invalid']++;
                continue;
            }
            $value = trim($row[$header['product']]);
            if ($header['identifier'] === 'product_id') {
                $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            } else {
                $id = $value === '' ? false : wc_get_product_id_by_sku($value);
            }
            $product = $id ? wc_get_product($id) : false;
            if (!$product || !in_array($product->get_status(), ['publish', 'private', 'draft', 'pending', 'future'], true) || !current_user_can('edit_post', $id)) {
                $stats['skipped']++;
                continue;
            }
            if ($product->is_type('variation')) {
                $parent = wc_get_product($product->get_parent_id());
                if (!$parent || !$parent->is_type('variable') || in_array($parent->get_status(), ['trash', 'auto-draft'], true)) {
                    $stats['skipped']++;
                    continue;
                }
            }
            foreach ($header['modules'] as $module_id => $columns) {
                $module = $modules[$module_id] ?? null;
                if (!$module || !call_user_func($module['supports'], $product)) {
                    continue;
                }
                $values = [];
                $empty  = true;
                foreach ($columns as $field_id => $col) {
                    $raw = trim($row[$col]);
                    if ($raw !== '') {
                        $empty = false;
                    }
                    $values[$field_id] = $raw;
                }
                // No data provided for this data set on this row: leave it unchanged.
                if ($empty) {
                    continue;
                }
                $normalized = [];
                foreach ($values as $field_id => $raw) {
                    $normalized[$field_id] = $module['fields'][$field_id]['type'] === 'price' ? str_replace(wc_get_price_decimal_separator(), '.', $raw) : $raw;
                }
                if (is_wp_error(call_user_func($module['validate'], $normalized))) {
                    $stats['invalid']++;
                    continue;
                }
                $product->read_meta_data(true);
                $before = call_user_func($module['get_values'], $product);
                // CSV import is authoritative: always save against the product's current version, never against a stale exported one.
                $result = Editor::save_changes($module_id, [['product_id' => $id, 'values' => $values, 'version' => call_user_func($module['version'], $product)]]);
                if (is_wp_error($result)) {
                    $stats[$result->get_error_code() === 'eggl_ph_pcm_conflict' ? 'conflicts' : 'failed']++;
                    continue;
                }
                $after = $result['rows'][$id]['values'] ?? $before;
                $stats[$before === $after ? 'unchanged' : 'updated']++;
            }
        }
        return $stats;
    }

    private static function path($token)
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token)) {
            self::fail(__('Invalid import session.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        return trailingslashit(get_temp_dir()) . 'eggl-ph-pcm-' . $token . '.csv';
    }

    public static function cleanup($token)
    {
        $path = self::path($token);
        if (is_file($path)) {
            wp_delete_file($path);
        }
        delete_transient('eggl_ph_pcm_csv_' . $token);
        wp_clear_scheduled_hook(self::CLEANUP, [$token]);
    }

    public function upload()
    {
        self::authorize();
        $token  = null;
        $source = null;
        $handle = null;
        try {
            $file = $_FILES['csv'] ?? []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing -- authorize() verified the nonce above; uploaded-file metadata is type-checked below and PHP upload paths must not be unslashed.
            if (
                !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_string($file['name'] ?? null) || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv'
                || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])
                || filesize($file['tmp_name']) === 0 || filesize($file['tmp_name']) > wp_max_upload_size()
            ) {
                self::fail(__('Upload a non-empty CSV within the site upload limit.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verified the nonce above.
            $selected   = isset($_POST['modules']) && is_array($_POST['modules']) ? array_map('sanitize_key', wp_unslash($_POST['modules'])) : [];
            $module_ids = array_values(array_intersect(array_keys(Registry::all()), $selected));
            if (!$module_ids) {
                self::fail(__('Choose at least one data set to import.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            }
            $modules = [];
            foreach ($module_ids as $module_id) {
                $modules[$module_id] = Registry::get($module_id);
            }

            $source = fopen($file['tmp_name'], 'rb');
            $header = self::read_header($source, $modules);
            $offset = ftell($source);
            rewind($source);
            $token  = bin2hex(random_bytes(16));
            $handle = fopen(self::path($token), 'x+b');
            if (!$handle) {
                self::fail(__('Could not create a temporary import file.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            }
            chmod(self::path($token), 0600);
            $copied = stream_copy_to_stream($source, $handle);
            fclose($source);
            fclose($handle);
            $source = null;
            $handle = null;
            if ($copied !== filesize($file['tmp_name'])) {
                self::fail(__('Could not copy the complete CSV.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            }

            $state = [
                'user'    => get_current_user_id(),
                'context' => self::nonce_action(),
                'offset'  => $offset,
                'header'  => $header,
                'modules' => $module_ids,
                'stats'   => array_fill_keys(['rows', 'updated', 'unchanged', 'skipped', 'invalid', 'failed', 'conflicts'], 0),
            ];
            if (
                !set_transient('eggl_ph_pcm_csv_' . $token, $state, 2 * DAY_IN_SECONDS)
                || !wp_schedule_single_event(time() + DAY_IN_SECONDS, self::CLEANUP, [$token])
            ) {
                self::fail(__('Could not start the import session.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            }
        } catch (\Throwable $error) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($token) {
                self::cleanup($token);
            }
            wp_send_json_error(['message' => $error->getMessage()], 400);
        }
        wp_send_json_success(['token' => $token]);
    }

    public function process()
    {
        self::authorize();
        $handle = null;
        try {
            $token = $_POST['token'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing -- authorize() verified the nonce; path() accepts only fixed-length hexadecimal tokens.
            $path   = self::path($token);
            $handle = is_file($path) ? fopen($path, 'rb') : false;
            if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
                self::fail(__('Import is busy or expired. Retry, or upload the file again.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            }
            $state = get_transient('eggl_ph_pcm_csv_' . $token);
            if (!$state || $state['user'] !== get_current_user_id() || $state['context'] !== self::nonce_action()) {
                self::fail(__('Import session expired or the store currency changed. Upload the file again.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            }
            if (empty($state['done'])) {
                if (fseek($handle, $state['offset']) !== 0) {
                    self::fail(__('Could not resume the CSV file.', 'garanlabs-eu-guarantee-notice-durability-labels'));
                }
                $modules = [];
                foreach ($state['modules'] as $module_id) {
                    $module = Registry::get($module_id);
                    if ($module) {
                        $modules[$module_id] = $module;
                    }
                }
                wc_set_time_limit(60);
                $stats = self::import_batch($handle, $state['header'], $modules);
                foreach ($stats as $key => $count) {
                    $state['stats'][$key] += $count;
                }
                $state['offset'] = ftell($handle);
                $state['done']   = feof($handle);
                if (!set_transient('eggl_ph_pcm_csv_' . $token, $state, 2 * DAY_IN_SECONDS)) {
                    self::fail(__('Could not save import progress. Review saved values before uploading again.', 'garanlabs-eu-guarantee-notice-durability-labels'));
                }
            }
            $progress = $state['done'] ? 100 : min(99, (int) floor(100 * $state['offset'] / max(1, filesize($path))));
            fclose($handle);
            $handle = null;
            $result = ['done' => $state['done'], 'progress' => $progress, 'message' => self::summary($state['stats']), 'stats' => $state['stats']];
        } catch (\Throwable $error) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            wp_send_json_error(['message' => $error->getMessage()], 400);
        }
        wp_send_json_success($result);
    }
    // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_chmod

    public static function summary($stats)
    {
        return sprintf(
            /* translators: 1: processed rows, 2: updated values, 3: unchanged values, 4: skipped rows, 5: invalid values, 6: conflicts, 7: failed saves. */
            __('%1$s rows processed. %2$s updated. %3$s unchanged. %4$s skipped (missing or uneditable products). %5$s invalid. %6$s conflicts. %7$s failed saves.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            number_format_i18n($stats['rows']),
            number_format_i18n($stats['updated']),
            number_format_i18n($stats['unchanged']),
            number_format_i18n($stats['skipped']),
            number_format_i18n($stats['invalid']),
            number_format_i18n($stats['conflicts']),
            number_format_i18n($stats['failed'])
        );
    }
}
