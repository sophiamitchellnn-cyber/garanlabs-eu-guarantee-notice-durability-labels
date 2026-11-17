<?php

namespace EGGL\PriceHistory\Modules\DigitalPriceList;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generates the CSV, archives it under its own timestamped name, then atomically republishes
 * current.csv as a copy of that archive entry. current.csv is only ever touched after the
 * archive write succeeds, so a failed run never removes the previously published file (spec §25/§32).
 */
class Generator
{
    const CRON_HOOK = 'eggl_ph_dpl_generate';
    const CONTINUE_HOOK = 'eggl_ph_dpl_generate_continue';
    const PRICE_CHANGE_HOOK = 'eggl_ph_dpl_generate_after_price_change';
    const EXTRA_HOOK = 'eggl_ph_dpl_generate_extra';
    const EXTRA_SIGNATURE_OPTION = 'eggl_ph_dpl_extra_schedule';
    // Edits (incl. bulk edits and imports) are collected for this long, then the list is rebuilt once.
    const PRICE_CHANGE_DELAY = 5 * MINUTE_IN_SECONDS;
    const PRICE_PROPS = ['price', 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'status', 'sku', 'global_unique_id'];
    const BATCH_SIZE = 200;
    const LOCK_OPTION = 'eggl_ph_dpl_generation_lock';
    const LOCK_TTL = 10 * MINUTE_IN_SECONDS;
    const RETENTION_DAYS = 30;
    const STATE_OPTION = 'eggl_ph_dpl_generation_state';
    const SEQUENCE_OPTION = 'eggl_ph_dpl_generation_sequence';
    // Keeps each HTTP/Action Scheduler request short regardless of catalog size (a full run chains
    // across as many ticks as needed via CONTINUE_HOOK; a large catalog processed in one request
    // reliably exceeds typical nginx/php-fpm timeouts, e.g. spec §7 "large catalog behavior").
    const TIME_BUDGET = 20;

    public function __construct()
    {
        add_action('admin_init', [self::class, 'maybe_schedule']);
        add_action(self::CRON_HOOK, [self::class, 'generate']);
        add_action(self::CONTINUE_HOOK, [self::class, 'generate']);
        add_action('woocommerce_product_object_updated_props', [self::class, 'on_product_change'], 10, 2);
        add_action(self::PRICE_CHANGE_HOOK, [self::class, 'generate_after_change']);
        add_action(self::EXTRA_HOOK, [self::class, 'generate_after_change']);
        // Extra runs also pick up waiting ERP files first, like the daily run.
        add_action(self::EXTRA_HOOK, [External_Source::class, 'check_all'], 5);
    }

    /** One daily recurring action per extra time; rebuilt only when the list of times changes. */
    private static function reschedule_extra($settings)
    {
        $times = !empty($settings['enable']) && !empty($settings['enable_schedule']) ? Helper::parse_times($settings['extra_times']) : [];
        $signature = implode(',', $times) . '|' . wp_timezone_string();
        $pending = function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::EXTRA_HOOK);
        if (get_option(self::EXTRA_SIGNATURE_OPTION) === $signature && ($pending || !$times)) {
            return;
        }
        as_unschedule_all_actions(self::EXTRA_HOOK);
        foreach ($times as $time) {
            [$hour, $minute] = array_map('intval', explode(':', $time));
            $next = (new \DateTimeImmutable('today', wp_timezone()))->setTime($hour, $minute);
            if ($next->getTimestamp() <= time()) {
                $next = $next->modify('+1 day');
            }
            // Not "unique": Action Scheduler's uniqueness ignores args, so it would keep only the first time.
            as_schedule_recurring_action($next->getTimestamp(), DAY_IN_SECONDS, self::EXTRA_HOOK, [$time], '', false);
        }
        update_option(self::EXTRA_SIGNATURE_OPTION, $signature, false);
    }

    /**
     * Croatian rules require a changed price list to be published by 11:00; rebuilding shortly after
     * each price change keeps the published list current without waiting for the daily run.
     */
    public static function on_product_change($product, $updated_props)
    {
        if (!array_intersect((array) $updated_props, self::PRICE_PROPS)) {
            return;
        }
        self::queue_regeneration();
    }

    public static function queue_regeneration($delay = self::PRICE_CHANGE_DELAY)
    {
        $settings = Helper::settings();
        if (empty($settings['enable']) || empty($settings['regenerate_on_change']) || !function_exists('as_has_scheduled_action')) {
            return;
        }
        if (!as_has_scheduled_action(self::PRICE_CHANGE_HOOK)) {
            as_schedule_single_action(time() + $delay, self::PRICE_CHANGE_HOOK, [], '', true);
        }
    }

    /** A run already in progress may have read the old price; try again shortly instead of reporting a lock error. */
    public static function generate_after_change()
    {
        if (self::is_running()) {
            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time() + self::PRICE_CHANGE_DELAY, self::PRICE_CHANGE_HOOK, [], '', true);
            }
            return;
        }
        self::generate();
    }

    public static function maybe_schedule()
    {
        $settings = Helper::settings();
        if ($settings['enable'] && $settings['enable_schedule']) {
            self::reschedule($settings);
        }
    }

    /**
     * Avoids duplicate schedules on repeated activation/settings saves (spec §23), and re-schedules
     * when the configured time-of-day no longer matches what's actually scheduled (e.g. the user
     * changed Scheduled Generation Time) rather than leaving the old time in effect indefinitely.
     */
    public static function reschedule($settings)
    {
        if (!function_exists('as_unschedule_all_actions')) {
            return; // Action Scheduler unavailable; manual "Generate Now" still works.
        }
        self::reschedule_extra($settings);
        if (empty($settings['enable']) || empty($settings['enable_schedule'])) {
            as_unschedule_all_actions(self::CRON_HOOK);
            return;
        }

        $next_run = function_exists('as_next_scheduled_action') ? as_next_scheduled_action(self::CRON_HOOK) : false;
        if ($next_run) {
            $scheduled_time = (new \DateTimeImmutable('@' . $next_run))->setTimezone(wp_timezone())->format('H:i');
            if ($scheduled_time === $settings['schedule_time']) {
                return; // Already scheduled at the desired time-of-day.
            }
            as_unschedule_all_actions(self::CRON_HOOK);
        }

        [$hour, $minute] = array_map('intval', explode(':', $settings['schedule_time']));
        $next = (new \DateTimeImmutable('today', wp_timezone()))->setTime($hour, $minute);
        if ($next->getTimestamp() <= time()) {
            $next = $next->modify('+1 day');
        }
        as_schedule_recurring_action($next->getTimestamp(), DAY_IN_SECONDS, self::CRON_HOOK, [], '', true);
    }

    /**
     * An exclusive lock via add_option()'s unique-key insert; self-recovers if a process crashed
     * mid-run. Returns a random token (identifying this specific run) on success, or false.
     */
    private static function lock()
    {
        $token = wp_generate_password(12, false);
        if (add_option(self::LOCK_OPTION, ['time' => time(), 'token' => $token], '', 'no')) {
            return $token;
        }
        $current = get_option(self::LOCK_OPTION, null);
        $locked_at = is_array($current) ? (int) ($current['time'] ?? 0) : 0;
        if ($locked_at && time() - $locked_at > self::LOCK_TTL) {
            delete_option(self::LOCK_OPTION);
            if (add_option(self::LOCK_OPTION, ['time' => time(), 'token' => $token], '', 'no')) {
                return $token;
            }
        }
        return false;
    }

    private static function unlock()
    {
        delete_option(self::LOCK_OPTION);
    }

    /**
     * Throws a RuntimeException carrying a static, hardcoded translated message. Never echoed
     * directly: callers only ever store it via Helper::set_status()/add_settings_error(), both of
     * which esc_html() it before output (see admin.php render_status() and settings_section()), so
     * routing every throw site through here lets one ignore comment cover all of them instead of
     * repeating the same justification at each call site.
     */
    private static function fail($message)
    {
        throw new \RuntimeException($message); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- see docblock above.
    }

    /** True only if the lock is both present and still owned by this run's token (not reclaimed by another run). */
    private static function own_lock($token)
    {
        $current = get_option(self::LOCK_OPTION, null);
        return is_array($current) && ($current['token'] ?? null) === $token;
    }

    /** Pushes the lock's staleness clock forward; a legitimately slow large-catalog run must not be reclaimed as crashed mid-way. */
    private static function renew_lock($token)
    {
        update_option(self::LOCK_OPTION, ['time' => time(), 'token' => $token], false);
    }

    private static function state()
    {
        $state = get_option(self::STATE_OPTION, null);
        return is_array($state) ? $state : null;
    }

    private static function save_state($state)
    {
        update_option(self::STATE_OPTION, $state, false);
    }

    private static function clear_state()
    {
        delete_option(self::STATE_OPTION);
    }

    /** Storage/generation sequence number, persisted and incremented once per logical run (never resets), matching the required "broj pohrane" file-name field. */
    public static function next_sequence()
    {
        $sequence = (int) get_option(self::SEQUENCE_OPTION, 0) + 1;
        update_option(self::SEQUENCE_OPTION, $sequence, false);
        return $sequence;
    }

    /** True while a generation run (this tick or a chained continuation) is in progress. */
    public static function is_running()
    {
        return self::state() !== null || (bool) get_option(self::LOCK_OPTION, false);
    }

    public static function ensure_dirs(array $dirs)
    {
        foreach ($dirs as $dir) {
            if (!wp_mkdir_p($dir)) {
                self::fail(__('Could not create the price list storage directory.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            }
            if (!file_exists($dir . '/index.php')) {
                file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
            }
            if (!file_exists($dir . '/.htaccess')) {
                file_put_contents($dir . '/.htaccess', "Deny from all\n");
            }
        }
    }

    /** Snapshotted per run in start(), so a settings change mid-run can't mix two formats in one file. */
    private static $delimiter = ';';

    private static function use_format($state)
    {
        self::$delimiter = $state['format']['delimiter'] ?? ';';
    }

    /** The row's values in the layout's column order. */
    private static function layout_row(array $row, array $columns)
    {
        return array_map(static fn($field) => $row[$field] ?? '', array_keys($columns));
    }

    private static function write_row($handle, $row)
    {
        $row = array_map(static function ($value) {
            $value = wp_check_invalid_utf8((string) $value);
            // A leading apostrophe makes formula-looking text inert in spreadsheet applications.
            if (preg_match('/^[\s\x00-\x1f]*[=+@\-]/u', $value) || preg_match('/^[\t\r\n]/', $value)) {
                $value = "'" . $value;
            }
            return $value;
        }, $row);
        if (fputcsv($handle, $row, self::$delimiter, '"', '') === false) {
            self::fail(__('Could not write CSV.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
    }

    /** Published simple products and variations of published parents; shared with the REST endpoint. */
    public static function purchasable_join_where()
    {
        global $wpdb;
        return "LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent
            WHERE (
                (p.post_type = 'product' AND p.post_status = 'publish' AND EXISTS (
                    SELECT 1 FROM {$wpdb->term_relationships} tr
                    JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                    JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                    WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_type' AND t.slug = 'simple'))
                OR (p.post_type = 'product_variation' AND p.post_status = 'publish' AND parent.post_type = 'product' AND parent.post_status = 'publish')
            )";
    }

    private static function product_ids($cursor, $max_id)
    {
        global $wpdb;
        // purchasable_join_where() (below) returns a fixed literal SQL fragment built only from
        // $wpdb table-name properties and hardcoded keywords, no external input; the sniffs below
        // can't see through that helper call, but every actual value here goes through the %d
        // placeholders that ARE passed to prepare().
        $sql = "SELECT p.ID FROM {$wpdb->posts} p " . self::purchasable_join_where() . ' AND p.ID > %d AND p.ID <= %d ORDER BY p.ID LIMIT %d';
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
        $ids = $wpdb->get_col($wpdb->prepare($sql, $cursor, $max_id, self::BATCH_SIZE));
        if ($wpdb->last_error) {
            self::fail(__('Could not read the product catalog.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        return array_map('intval', $ids);
    }

    /*
     * The methods below (through finish()) use raw fopen()/fwrite()/fclose()/rename() rather than
     * WP_Filesystem intentionally: writing a 50k+ row CSV needs true streaming (WP_Filesystem's
     * get_contents()/put_contents() load the whole file into memory), append-mode writes across
     * chained batches need byte-accurate fopen(..., 'ab'), and finish() depends on rename()'s
     * atomicity to guarantee current.csv is never left partially written (WP_Filesystem::move() on
     * non-direct transports falls back to copy+delete, which is not atomic and could serve a
     * truncated file mid-publish). See spec §7 "large catalog behavior" / "atomic publishing".
     */
    // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.rename_rename

    /**
     * Deletes archive entries older than the retention window (webshop and every configured store);
     * never touches a current.csv (outside the archive directories). The newest entry of each archive
     * is always kept, matching list_price_lists(), so a stalled schedule never leaves an outlet empty.
     */
    public static function cleanup_archive()
    {
        $cutoff = time() - self::RETENTION_DAYS * DAY_IN_SECONDS;
        $dirs = [Helper::archive_dir()];
        foreach (array_keys(Helper::stores()) as $store_id) {
            $dirs[] = Helper::store_archive_dir($store_id);
        }
        foreach ($dirs as $dir) {
            $lists = self::list_price_lists($dir);
            $keep = $lists['current']['filename'] ?? '';
            foreach (glob($dir . '/*.csv') ?: [] as $file) {
                if (basename($file) !== $keep && filemtime($file) < $cutoff) {
                    wp_delete_file($file);
                }
            }
        }
    }

    /**
     * Generated CSVs, each under its own name: the newest is the current list (kept even past the
     * retention window, so a stalled schedule never leaves the page empty); older ones are listed only
     * within cleanup_archive()'s cutoff, newest first. Defaults to the webshop archive; pass a store's
     * archive directory for that store's lists.
     */
    public static function list_price_lists($archive_dir = null)
    {
        $entries = [];
        foreach (glob(($archive_dir ?: Helper::archive_dir()) . '/*.csv') ?: [] as $file) {
            $mtime = filemtime($file);
            if ($mtime !== false) {
                $entries[] = ['filename' => basename($file), 'timestamp' => $mtime];
            }
        }
        // Same-second runs tie on mtime; the name's timestamp/sequence then keeps the order stable.
        usort($entries, static fn($a, $b) => [$b['timestamp'], $b['filename']] <=> [$a['timestamp'], $a['filename']]);
        $current = array_shift($entries);
        $cutoff = time() - self::RETENTION_DAYS * DAY_IN_SECONDS;
        return [
            'current'  => $current,
            'previous' => array_values(array_filter($entries, static fn($entry) => $entry['timestamp'] >= $cutoff)),
        ];
    }

    /** Creates a tmp CSV holding the UTF-8 BOM and header row. */
    private static function create_csv($tmp_path, $format)
    {
        self::use_format(['format' => $format]);
        $handle = fopen($tmp_path, 'wb');
        if (!$handle) {
            self::fail(__('Could not create a temporary price list file.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        try {
            if ($format['bom'] && fwrite($handle, "\xEF\xBB\xBF") !== 3) {
                self::fail(__('Could not write CSV.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            }
            self::write_row($handle, apply_filters('eggl_ph_dpl_csv_columns', array_values($format['columns'])));
        } finally {
            fclose($handle);
        }
    }

    /** Selected product categories plus all their subcategories, so choosing a parent category covers its children. */
    private static function expand_categories(array $term_ids)
    {
        $all = $term_ids;
        foreach ($term_ids as $term_id) {
            $children = get_term_children($term_id, 'product_cat');
            if (!is_wp_error($children)) {
                $all = array_merge($all, $children);
            }
        }
        return array_values(array_unique(array_map('intval', $all)));
    }

    /** Removes every tmp file a (failed or orphaned) run may have left: the webshop list and each store's copy. */
    private static function discard_tmp($state)
    {
        $paths = [$state['tmp_path'] ?? ''];
        foreach ((array) ($state['stores'] ?? []) as $store_state) {
            $paths[] = $store_state['tmp_path'] ?? '';
        }
        foreach (array_filter($paths) as $path) {
            if (file_exists($path)) {
                wp_delete_file($path);
            }
        }
    }

    /**
     * Opens the tmp files (webshop + one per complete physical store) and writes their header rows;
     * returns the fresh progress state for process_batches(). Store settings and their expanded
     * category sets are snapshotted into the state so a settings change mid-run can't mix rules.
     */
    private static function start($token)
    {
        global $wpdb;
        self::ensure_dirs([Helper::upload_dir(), Helper::archive_dir()]);
        $sequence = self::next_sequence();
        $now = current_datetime();
        $filename = Helper::build_filename($sequence, $now->format('Ymd-His'));
        $archive_path = Helper::archive_dir() . '/' . $filename;

        $state = [
            'tmp_path'     => $archive_path . '.tmp',
            'archive_path' => $archive_path,
            'filename'     => $filename,
            'cursor'       => 0,
            'max_id'       => (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}"),
            'count'        => 0,
            'token'        => $token,
            'stores'       => [],
            'format'       => [
                'delimiter' => Helper::settings()['csv_delimiter'],
                'bom'       => Helper::settings()['csv_bom'],
                'columns'   => Helper::csv_columns(),
            ],
        ];

        try {
            self::create_csv($state['tmp_path'], $state['format']);
            foreach (Helper::stores() as $store_id => $store) {
                if (!Helper::store_is_complete($store)) {
                    continue; // Shown as incomplete in the admin status; no file name can be built without its details.
                }
                if ($store['source'] === 'external') {
                    continue; // Published from the ERP file by External_Source, not from webshop products.
                }
                if (Helper::covered_by_all_stores_list($store)) {
                    continue; // The webshop list (ZA_SVA_PRODAJNA_MJESTA) covers this store.
                }
                self::ensure_dirs([Helper::store_dir($store_id), Helper::store_archive_dir($store_id)]);
                $store_filename = Helper::build_store_filename($store, $sequence, $now);
                $store_archive = Helper::store_archive_dir($store_id) . '/' . $store_filename;
                $state['stores'][$store_id] = [
                    'store'        => $store,
                    'categories'   => $store['scope'] === 'all' ? [] : self::expand_categories($store['categories']),
                    'tags'         => $store['scope'] === 'all' ? [] : $store['tags'],
                    'products'     => $store['scope'] === 'all' ? [] : $store['products'],
                    'tmp_path'     => $store_archive . '.tmp',
                    'archive_path' => $store_archive,
                    'filename'     => $store_filename,
                    'count'        => 0,
                ];
                self::create_csv($state['stores'][$store_id]['tmp_path'], $state['format']);
            }
        } catch (\Throwable $error) {
            self::discard_tmp($state);
            throw $error;
        }

        return $state;
    }

    /**
     * Whether a store's product scope and the per-store filter include this product. "include" keeps
     * products matching ANY selected category (incl. subcategories), tag or product; "exclude" drops
     * them. A selected variable product covers all its variations. $sets holds the store's flipped
     * id sets; $terms the product's own flipped category/tag ids (a variation's are its parent's).
     */
    private static function store_includes($store_state, array $sets, array $terms, \WC_Product $product)
    {
        $scope = $store_state['store']['scope'];
        if ($scope === 'all') {
            $include = true;
        } else {
            $matches = isset($sets['products'][$product->get_id()])
                || ($product->get_parent_id() && isset($sets['products'][$product->get_parent_id()]))
                || array_intersect_key($terms['product_cat'], $sets['categories'])
                || array_intersect_key($terms['product_tag'], $sets['tags']);
            $include = $scope === 'include' ? $matches : !$matches;
        }
        return (bool) apply_filters('eggl_ph_dpl_store_include_product', $include, $product, $store_state['store']);
    }

    /** Processes batches until the catalog is exhausted (returns true) or the tick's time budget runs out (returns false). */
    private static function process_batches(array &$state)
    {
        wc_set_time_limit(0);
        self::use_format($state);
        $columns = $state['format']['columns'] ?? Helper::csv_columns();
        $deadline = microtime(true) + apply_filters('eggl_ph_dpl_time_budget', self::TIME_BUDGET);

        $stores = (array) ($state['stores'] ?? []);
        $sets = [];
        $needs_terms = ['product_cat' => false, 'product_tag' => false];
        foreach ($stores as $store_id => $store_state) {
            $sets[$store_id] = [
                'categories' => array_flip($store_state['categories'] ?? []),
                'tags'       => array_flip($store_state['tags'] ?? []),
                'products'   => array_flip($store_state['products'] ?? []),
            ];
            $needs_terms['product_cat'] = $needs_terms['product_cat'] || $sets[$store_id]['categories'];
            $needs_terms['product_tag'] = $needs_terms['product_tag'] || $sets[$store_id]['tags'];
        }

        $handle = fopen($state['tmp_path'], 'ab');
        if (!$handle) {
            self::fail(__('Could not continue writing the price list file.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        $store_handles = [];
        try {
            foreach ($stores as $store_id => $store_state) {
                $store_handles[$store_id] = fopen($store_state['tmp_path'], 'ab');
                if (!$store_handles[$store_id]) {
                    unset($store_handles[$store_id]);
                    self::fail(__('Could not continue writing a physical store price list file.', 'garanlabs-eu-guarantee-notice-durability-labels'));
                }
            }

            while (true) {
                $ids = self::product_ids($state['cursor'], $state['max_id']);
                if (!$ids) {
                    return true;
                }
                $state['cursor'] = max($ids);
                _prime_post_caches($ids, false, true);
                foreach ($ids as $id) {
                    $product = wc_get_product($id);
                    if (!$product || !Product_Mapper::is_exportable($product)) {
                        continue;
                    }
                    $row = Product_Mapper::map($product);
                    self::write_row($handle, self::layout_row($row, $columns));
                    $state['count']++;

                    if (!$store_handles) {
                        continue;
                    }
                    // Variations carry no categories/tags of their own; they belong to their parent's.
                    $terms = [];
                    foreach ($needs_terms as $taxonomy => $needed) {
                        $terms[$taxonomy] = $needed
                            ? array_flip(array_map('intval', wc_get_product_term_ids($product->get_parent_id() ?: $product->get_id(), $taxonomy)))
                            : [];
                    }
                    foreach ($stores as $store_id => $store_state) {
                        if (!self::store_includes($store_state, $sets[$store_id], $terms, $product)) {
                            continue;
                        }
                        // Lets a store publish its own values (e.g. a different shelf price) for the same product.
                        $store_row = apply_filters('eggl_ph_dpl_store_row', $row, $product, $store_state['store']);
                        self::write_row($store_handles[$store_id], self::layout_row($store_row, $columns));
                        $state['stores'][$store_id]['count']++;
                    }
                }
                if (wp_cache_supports('flush_runtime')) {
                    wp_cache_flush_runtime();
                }
                if (microtime(true) >= $deadline) {
                    return false;
                }
            }
        } finally {
            fclose($handle);
            foreach ($store_handles as $store_handle) {
                fclose($store_handle);
            }
        }
    }

    /** Archives a finished tmp file, then atomically republishes $current_path as a copy of it. Returns an error message or ''. */
    public static function publish($tmp_path, $archive_path, $current_path)
    {
        if (!rename($tmp_path, $archive_path)) {
            wp_delete_file($tmp_path);
            return __('Could not archive the generated price list.', 'garanlabs-eu-guarantee-notice-durability-labels');
        }

        $current_tmp = $current_path . '.tmp';
        if (!copy($archive_path, $current_tmp) || !rename($current_tmp, $current_path)) {
            wp_delete_file($current_tmp);
            // The just-archived file is still valid and retrievable; only the "current" pointer failed to update.
            return __('Price list archived, but could not publish current.csv.', 'garanlabs-eu-guarantee-notice-durability-labels');
        }

        return '';
    }

    /**
     * Publishes the webshop list (a failure there fails the run), then each physical store's copy.
     * A store failing is reported but doesn't undo the webshop or the other stores' lists.
     */
    private static function finish($state)
    {
        $error = self::publish($state['tmp_path'], $state['archive_path'], Helper::upload_dir() . '/current.csv');
        if ($error !== '') {
            self::fail($error);
        }

        $stores = [];
        $errors = [];
        foreach ((array) ($state['stores'] ?? []) as $store_id => $store_state) {
            $error = self::publish($store_state['tmp_path'], $store_state['archive_path'], Helper::store_dir($store_id) . '/current.csv');
            if ($error !== '') {
                /* translators: 1: physical store premises label, e.g. P-01; 2: the error message. */
                $errors[] = sprintf(__('Store %1$s: %2$s', 'garanlabs-eu-guarantee-notice-durability-labels'), $store_state['store']['premises_label'], $error);
                continue;
            }
            $stores[$store_id] = ['rows' => $store_state['count'], 'filename' => $store_state['filename']];
        }

        return ['rows' => $state['count'], 'filename' => $state['filename'], 'stores' => $stores, 'errors' => $errors];
    }
    // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.rename_rename

    /**
     * One bounded tick of a (possibly multi-tick) generation run. Starts a new run or resumes an
     * in-progress one; if the catalog isn't fully processed within this tick's time budget, saves
     * progress and chains itself via CONTINUE_HOOK rather than blocking the calling request further
     * (spec §7 "large catalog behavior" — a synchronous full-catalog run reliably 504s on a large
     * store over a real HTTP request, even though it completes fine in an unbounded CLI process).
     */
    public static function generate()
    {
        $settings = Helper::settings();
        if (empty($settings['enable'])) {
            return new \WP_Error('eggl_ph_dpl_disabled', __('Digital Price List is not enabled.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }

        $state = self::state();
        // A resumed state whose lock was reclaimed (e.g. a stuck continuation past LOCK_TTL, picked
        // up again later by a separate trigger such as the daily schedule) is orphaned: discard it
        // rather than risk two runs writing the same tmp file concurrently.
        if ($state !== null && !self::own_lock($state['token'] ?? null)) {
            self::discard_tmp($state);
            self::clear_state();
            $state = null;
        }

        if ($state === null) {
            $token = self::lock();
            if (!$token) {
                $message = __('Another Digital Price List generation is already running.', 'garanlabs-eu-guarantee-notice-durability-labels');
                Helper::set_status(['error' => $message]);
                return new \WP_Error('eggl_ph_dpl_locked', $message);
            }
            try {
                $state = self::start($token);
            } catch (\Throwable $error) {
                Helper::set_status(['error' => $error->getMessage()]);
                self::unlock();
                return new \WP_Error('eggl_ph_dpl_failed', $error->getMessage());
            }
        } else {
            self::renew_lock($state['token']);
        }

        try {
            $done = self::process_batches($state);
        } catch (\Throwable $error) {
            self::discard_tmp($state);
            self::clear_state();
            self::unlock();
            Helper::set_status(['error' => $error->getMessage()]);
            return new \WP_Error('eggl_ph_dpl_failed', $error->getMessage());
        }

        if (!$done) {
            if (!function_exists('as_enqueue_async_action')) {
                // Action Scheduler is expected (bundled with the required WooCommerce dependency);
                // fall back to finishing synchronously rather than stalling forever with no chain.
                while (!$done) {
                    $done = self::process_batches($state);
                }
            } else {
                self::save_state($state);
                as_enqueue_async_action(self::CONTINUE_HOOK, [], '', false);
                return ['in_progress' => true, 'rows' => $state['count']];
            }
        }

        try {
            $result = self::finish($state);
        } catch (\Throwable $error) {
            self::discard_tmp($state);
            self::clear_state();
            self::unlock();
            Helper::set_status(['error' => $error->getMessage()]);
            return new \WP_Error('eggl_ph_dpl_failed', $error->getMessage());
        }

        self::clear_state();
        self::unlock();
        // A store whose publish failed keeps its previous status (its current.csv is unchanged); removed stores drop out.
        $previous_stores = Helper::status()['stores'];
        $store_status = [];
        foreach (Helper::stores() as $store_id => $store) {
            if (isset($result['stores'][$store_id])) {
                $store_status[$store_id] = $result['stores'][$store_id];
            } elseif (isset($previous_stores[$store_id]) && !Helper::covered_by_all_stores_list($store)) {
                $store_status[$store_id] = $previous_stores[$store_id];
            }
        }
        Helper::set_status([
            'generated_at' => current_time('mysql'),
            'rows'         => $result['rows'],
            'filename'     => $result['filename'],
            'error'        => implode(' ', $result['errors']),
            'stores'       => $store_status,
        ]);
        self::cleanup_archive();
        do_action('eggl_ph_dpl_generated', $result);
        return $result;
    }
}
