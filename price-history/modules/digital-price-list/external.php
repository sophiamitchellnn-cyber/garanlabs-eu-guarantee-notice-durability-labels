<?php

namespace EGGL\PriceHistory\Modules\DigitalPriceList;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Physical stores whose price list comes from an external system (ERP) instead of webshop products.
 *
 * A file can arrive four ways: dropped into the store's incoming folder (e.g. by SFTP), POSTed to the
 * REST endpoint, fetched from a remote URL, or uploaded manually in the admin. Every file is validated,
 * then published exactly like a generated store list: archived under the required file name, copied to
 * the store's current.csv and kept for the retention window. An invalid file never replaces the
 * previously published list. A missing file for the day raises a Status warning and an optional e-mail.
 */
class External_Source
{
    const STATUS = 'eggl_ph_dpl_external_status';
    const CHECK_HOOK = 'eggl_ph_dpl_external_check';
    const UPLOAD_ACTION = 'eggl_ph_dpl_external_upload';
    const LOCK_PREFIX = 'eggl_ph_dpl_ext_lock_';
    const LOCK_TTL = 5 * MINUTE_IN_SECONDS;
    // A file this recent may still be uploading over SFTP; it is picked up on the next check.
    const SETTLE_SECONDS = 60;
    const DEFAULT_MAX_BYTES = 50 * MB_IN_BYTES;
    const DELIMITERS = [',', ';', "\t", '|'];
    // Seconds a remote URL fetch may take in total; past that the fetch is recorded as rejected and the
    // other stores and the generation carry on (filterable: eggl_ph_dpl_external_timeout).
    const REMOTE_TIMEOUT = 20;

    public function __construct()
    {
        add_action('admin_init', [self::class, 'maybe_schedule']);
        add_action(self::CHECK_HOOK, [self::class, 'check_all']);
        // The daily generation also picks up waiting files, so they publish at the scheduled time at the latest.
        add_action(Generator::CRON_HOOK, [self::class, 'check_all'], 5);
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('admin_post_' . self::UPLOAD_ACTION, [self::class, 'manual_upload']);
    }

    /* ---------- Store helpers ---------- */

    /** Configured, complete stores using an external price list, keyed by id. */
    public static function stores()
    {
        return array_filter(Helper::stores(), static function ($store) {
            return $store['source'] === 'external' && Helper::store_is_complete($store);
        });
    }

    public static function incoming_dir($store_id)
    {
        return Helper::store_dir($store_id) . '/incoming';
    }

    public static function rejected_dir($store_id)
    {
        return self::incoming_dir($store_id) . '/rejected';
    }

    public static function rest_url($store_id)
    {
        return rest_url(REST_Controller::ROUTE_NAMESPACE . '/stores/' . rawurlencode($store_id) . '/price-list');
    }

    /** "naziv, šifra; MPC" → ['naziv', 'šifra', 'mpc'] (lowercase, trimmed, unique). */
    public static function parse_columns($value)
    {
        $columns = preg_split('/[,;\n]+/', sanitize_textarea_field((string) $value));
        $columns = array_map(static fn($column) => self::column_key($column), $columns);
        return array_values(array_unique(array_filter($columns, 'strlen')));
    }

    private static function column_key($column)
    {
        return function_exists('mb_strtolower') ? mb_strtolower(trim((string) $column), 'UTF-8') : strtolower(trim((string) $column));
    }

    /* ---------- Status ---------- */

    public static function all_status()
    {
        $status = get_option(self::STATUS, []);
        return is_array($status) ? $status : [];
    }

    /**
     * filename/rows/received_at/origin describe the published list; error/error_at the last rejected
     * file (cleared by the next valid one); hash dedupes repeated fetches of the same file in one day.
     */
    public static function status($store_id)
    {
        return wp_parse_args(self::all_status()[$store_id] ?? [], [
            'filename'    => '',
            'rows'        => 0,
            'received_at' => 0,
            'origin'      => '',
            'hash'        => '',
            'checked_at'  => 0,
            'error'       => '',
            'error_at'    => 0,
            'alerted_on'  => '',
        ]);
    }

    private static function set_status($store_id, array $changes)
    {
        $all = self::all_status();
        $all[$store_id] = array_merge(self::status($store_id), $changes);
        // Drop stores that are no longer configured.
        $all = array_intersect_key($all, Helper::stores());
        update_option(self::STATUS, $all, false);
    }

    /** True once today's deadline has passed and no list has been received today (site timezone). */
    public static function is_overdue($store_id)
    {
        $received = (int) self::status($store_id)['received_at'];
        $today = current_time('Y-m-d');
        if ($received && wp_date('Y-m-d', $received) === $today) {
            return false;
        }
        return current_time('H:i') >= Helper::settings()['external_deadline'];
    }

    /* ---------- Scheduling ---------- */

    public static function maybe_schedule()
    {
        if (!function_exists('as_has_scheduled_action')) {
            return;
        }
        $needed = Helper::settings()['enable'] && self::stores();
        $scheduled = as_has_scheduled_action(self::CHECK_HOOK);
        if ($needed && !$scheduled) {
            as_schedule_recurring_action(time() + MINUTE_IN_SECONDS, HOUR_IN_SECONDS, self::CHECK_HOOK, [], '', true);
        } elseif (!$needed && $scheduled) {
            as_unschedule_all_actions(self::CHECK_HOOK);
        }
    }

    /** Creates each external store's incoming folder so the ERP has somewhere to upload to. */
    public static function prepare_dirs($stores = null)
    {
        foreach ($stores ?? self::stores() as $store_id => $store) {
            try {
                Generator::ensure_dirs([Helper::store_dir($store_id), Helper::store_archive_dir($store_id), self::incoming_dir($store_id)]);
            } catch (\Throwable $error) {
                self::set_status($store_id, ['error' => $error->getMessage(), 'error_at' => time()]);
            }
        }
    }

    /** Hourly (and on Generate Now / the daily run): picks up folder uploads, fetches remote URLs, raises overdue alerts. */
    public static function check_all()
    {
        if (!Helper::settings()['enable']) {
            return;
        }
        $stores = self::stores();
        self::prepare_dirs($stores);
        foreach ($stores as $store_id => $store) {
            self::check_folder($store_id);
            if ($store['remote_url'] !== '') {
                self::fetch_remote($store_id, $store['remote_url']);
            }
            self::set_status($store_id, ['checked_at' => time()]);
            self::maybe_alert_overdue($store_id, $store);
        }
    }

    /* ---------- Delivery: folder ---------- */

    // phpcs:disable WordPress.WP.AlternativeFunctions -- Local files in the plugin's own upload folder; streamed, renamed atomically (see Generator).

    /** Imports the newest settled CSV in the incoming folder; older waiting files are superseded and removed. */
    public static function check_folder($store_id)
    {
        $dir = self::incoming_dir($store_id);
        $files = [];
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'csv') {
                $mtime = filemtime($file);
                if ($mtime !== false && time() - $mtime >= self::SETTLE_SECONDS) {
                    $files[$file] = $mtime;
                }
            }
        }
        if (!$files) {
            return null;
        }
        arsort($files);
        $newest = array_key_first($files);
        foreach (array_keys(array_slice($files, 1, null, true)) as $older) {
            wp_delete_file($older);
        }
        $result = self::import($store_id, $newest, 'folder', basename($newest));
        if (is_wp_error($result)) {
            self::reject_file($store_id, $newest);
        } else {
            wp_delete_file($newest);
        }
        return $result;
    }

    /** Keeps a rejected file for inspection (rejected/ is cleaned after the retention window) and stops it being retried. */
    private static function reject_file($store_id, $path)
    {
        if (!is_file($path)) {
            return;
        }
        $dir = self::rejected_dir($store_id);
        if (!wp_mkdir_p($dir)) {
            wp_delete_file($path);
            return;
        }
        rename($path, $dir . '/' . current_time('Ymd-His') . '_' . sanitize_file_name(basename($path)));
        $cutoff = time() - Generator::RETENTION_DAYS * DAY_IN_SECONDS;
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                wp_delete_file($file);
            }
        }
    }

    /* ---------- Delivery: remote URL ---------- */

    /** wp_tempnam() lives in an admin include that cron and REST requests don't load. */
    private static function tempnam($prefix)
    {
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        return wp_tempnam($prefix);
    }

    /**
     * A remote URL that points to a .csv file on this site itself (e.g. in the uploads folder) as a local
     * path, or null. Reading it from disk avoids an HTTP request to our own server, which can hang when
     * the PHP workers are busy or the server can't reach its own public address. Only real .csv files
     * inside the WordPress folders are accepted, so a URL can never publish e.g. wp-config.php.
     */
    public static function local_path($url)
    {
        $strip = static fn($value) => untrailingslashit(preg_replace('#^https?://#i', '', (string) $value));
        $target = $strip(strtok((string) $url, '?#'));
        $upload = wp_upload_dir(null, false);
        $bases = [
            $strip($upload['baseurl']) => $upload['basedir'],
            $strip(content_url())      => WP_CONTENT_DIR,
            $strip(site_url())         => ABSPATH,
            $strip(home_url())         => ABSPATH,
        ];
        foreach ($bases as $base_url => $base_dir) {
            if ($base_url === '' || stripos($target, $base_url . '/') !== 0) {
                continue;
            }
            $path = realpath(trailingslashit($base_dir) . rawurldecode(substr($target, strlen($base_url) + 1)));
            $root = realpath($base_dir);
            if ($path && $root && strpos($path, trailingslashit($root)) === 0 && is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
                return $path;
            }
            return null; // Same site, but not a plain file (e.g. a script generating the CSV): fetch over HTTP.
        }
        return null;
    }

    public static function fetch_remote($store_id, $url)
    {
        $local = self::local_path($url);
        if ($local !== null) {
            return self::import($store_id, $local, 'url', basename($local));
        }

        $tmp = self::tempnam('eggl-ph-dpl-remote');
        $timeout = max(1, (int) apply_filters('eggl_ph_dpl_external_timeout', self::REMOTE_TIMEOUT, $url));
        // CDNs such as Cloudflare cache .csv files by default; a unique query string and no-cache headers get a fresh copy.
        $response = wp_safe_remote_get(add_query_arg('eggl_ph_nocache', time(), $url), [
            'headers'             => ['Cache-Control' => 'no-cache', 'Pragma' => 'no-cache'],
            'timeout'             => $timeout,
            'stream'              => true,
            'filename'            => $tmp,
            'limit_response_size' => self::max_bytes(),
        ]);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            wp_delete_file($tmp);
            $message = is_wp_error($response)
                ? $response->get_error_message()
                /* translators: %d: HTTP status code. */
                : sprintf(__('the server answered with HTTP status %d', 'garanlabs-eu-guarantee-notice-durability-labels'), $code);
            /* translators: %s: the reason the download failed. */
            return self::record_error($store_id, new \WP_Error('eggl_ph_dpl_ext_fetch', sprintf(__('Could not download the price list from the remote URL: %s.', 'garanlabs-eu-guarantee-notice-durability-labels'), $message)), 'url');
        }
        $result = self::import($store_id, $tmp, 'url', basename((string) wp_parse_url($url, PHP_URL_PATH)));
        wp_delete_file($tmp);
        return $result;
    }

    /* ---------- Delivery: REST ---------- */

    public static function register_routes()
    {
        register_rest_route(REST_Controller::ROUTE_NAMESPACE, '/stores/(?P<store>[a-z0-9-]+)/price-list', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'rest_upload'],
            // Authenticate with a WordPress application password of a user who can manage WooCommerce.
            'permission_callback' => static fn() => current_user_can('manage_woocommerce'),
        ]);
    }

    /** Accepts a multipart "file" field, or the CSV as the raw request body (Content-Type: text/csv). */
    public static function rest_upload(\WP_REST_Request $request)
    {
        $store_id = (string) $request['store'];
        $store = self::stores()[$store_id] ?? null;
        if (!Helper::settings()['enable'] || !$store) {
            return new \WP_Error('eggl_ph_dpl_ext_store', __('No physical store with an external price list matches this address.', 'garanlabs-eu-guarantee-notice-durability-labels'), ['status' => 404]);
        }

        $files = $request->get_file_params();
        $tmp = self::tempnam('eggl-ph-dpl-rest');
        $name = '';
        if (!empty($files['file']['tmp_name']) && is_uploaded_file($files['file']['tmp_name'])) {
            $name = (string) $files['file']['name'];
            $copied = copy($files['file']['tmp_name'], $tmp);
        } else {
            $body = (string) $request->get_body();
            if (strlen($body) > self::max_bytes()) {
                wp_delete_file($tmp);
                return new \WP_Error('eggl_ph_dpl_ext_size', self::size_message(), ['status' => 413]);
            }
            $copied = file_put_contents($tmp, $body) !== false;
        }
        if (!$copied) {
            wp_delete_file($tmp);
            return new \WP_Error('eggl_ph_dpl_ext_write', __('Could not store the uploaded file.', 'garanlabs-eu-guarantee-notice-durability-labels'), ['status' => 500]);
        }

        self::prepare_dirs([$store_id => $store]);
        $result = self::import($store_id, $tmp, 'api', $name, true);
        wp_delete_file($tmp);
        if (is_wp_error($result)) {
            $result->add_data(['status' => $result->get_error_code() === 'eggl_ph_dpl_ext_locked' ? 409 : 422]);
            return $result;
        }
        return rest_ensure_response($result + ['url' => Helper::get_store_current_url($store_id)]);
    }

    /* ---------- Delivery: manual upload ---------- */

    /**
     * Posted from the settings form (like Generate Now) via the upload button's formaction; each store's
     * file input is eggl_ph_dpl_upload[{store_id}], and every store with a chosen file is imported.
     */
    public static function manual_upload()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You cannot manage the Digital Price List.', 'garanlabs-eu-guarantee-notice-durability-labels'), '', ['response' => 403]);
        }
        check_admin_referer(DPL_Admin::nonce_action(), 'nonce');
        $files = $_FILES['eggl_ph_dpl_upload'] ?? []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Upload metadata is type-checked below; PHP upload paths must not be unslashed.
        $tmp_names = is_array($files['tmp_name'] ?? null) ? $files['tmp_name'] : [];
        $names = is_array($files['name'] ?? null) ? $files['name'] : [];

        $ok = false;
        $stores = self::stores();
        foreach ($tmp_names as $store_id => $tmp_name) {
            $store_id = sanitize_key((string) $store_id);
            if (!isset($stores[$store_id]) || !is_string($tmp_name) || $tmp_name === '' || !is_uploaded_file($tmp_name)) {
                continue;
            }
            self::prepare_dirs([$store_id => $stores[$store_id]]);
            $result = self::import($store_id, $tmp_name, 'manual', sanitize_file_name((string) ($names[$store_id] ?? '')), true);
            $ok = $ok || !is_wp_error($result);
        }

        $redirect = wp_get_referer() ?: admin_url();
        wp_safe_redirect(add_query_arg('eggl_ph_dpl_uploaded', $ok ? '1' : '0', $redirect));
        exit;
    }

    /* ---------- Validation ---------- */

    private static function max_bytes()
    {
        return (int) apply_filters('eggl_ph_dpl_external_max_bytes', self::DEFAULT_MAX_BYTES);
    }

    private static function size_message()
    {
        /* translators: %s: maximum file size, e.g. 50 MB. */
        return sprintf(__('The file is larger than the %s limit.', 'garanlabs-eu-guarantee-notice-durability-labels'), size_format(self::max_bytes()));
    }

    private static function invalid($message)
    {
        return new \WP_Error('eggl_ph_dpl_ext_invalid', $message);
    }

    /** Most frequent candidate separator in the header line; quoted text is ignored so "a, b" in a name can't win. */
    private static function detect_delimiter($line)
    {
        $line = preg_replace('/"[^"]*"/', '', $line);
        $best = '';
        $best_count = 0;
        foreach (self::DELIMITERS as $delimiter) {
            $count = substr_count($line, $delimiter);
            if ($count > $best_count) {
                $best = $delimiter;
                $best_count = $count;
            }
        }
        return $best;
    }

    /**
     * Checks size, encoding (UTF-8, BOM optional), separator, header, required columns, a consistent
     * column count on every row (a truncated upload fails here) and at least one data row.
     *
     * @return array{rows: int, columns: string[], delimiter: string}|\WP_Error
     */
    public static function validate($path, $required = null)
    {
        clearstatcache(true, $path);
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false) {
            return self::invalid(__('The file could not be read.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        if ($size === 0) {
            return self::invalid(__('The file is empty.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        if ($size > self::max_bytes()) {
            return self::invalid(self::size_message());
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            return self::invalid(__('The file could not be read.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        try {
            // Pass 1: encoding, line by line (a UTF-8 character never spans a newline).
            for ($number = 1; ($line = fgets($handle)) !== false; $number++) {
                if (!preg_match('//u', $line)) {
                    /* translators: %d: line number in the file. */
                    return self::invalid(sprintf(__('The file is not UTF-8 encoded (first problem on line %d). Export it from your ERP as UTF-8.', 'garanlabs-eu-guarantee-notice-durability-labels'), $number));
                }
            }

            // Pass 2: structure.
            rewind($handle);
            $first = (string) fgets($handle);
            $bom = strncmp($first, "\xEF\xBB\xBF", 3) === 0;
            $delimiter = self::detect_delimiter($bom ? substr($first, 3) : $first);
            if ($delimiter === '') {
                return self::invalid(__('No column separator found in the header row. Use a comma, semicolon, tab or | between columns.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            }
            rewind($handle);
            if ($bom) {
                fread($handle, 3);
            }
            $header = fgetcsv($handle, 0, $delimiter, '"', '');
            $columns = array_map([self::class, 'column_key'], (array) $header);
            if (!$header || in_array('', $columns, true)) {
                return self::invalid(__('The header row has an empty column name.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            }
            $missing = array_diff(self::parse_columns($required ?? Helper::settings()['external_required_columns']), $columns);
            if ($missing) {
                /* translators: %s: comma-separated list of missing column names. */
                return self::invalid(sprintf(__('Required columns are missing: %s.', 'garanlabs-eu-guarantee-notice-durability-labels'), implode(', ', $missing)));
            }

            $width = count($header);
            $rows = 0;
            for ($number = 2; ($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false; $number++) {
                if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                    continue; // Blank line.
                }
                if (count($row) !== $width) {
                    /* translators: 1: row number; 2: number of columns found; 3: number of columns in the header. */
                    return self::invalid(sprintf(__('Row %1$d has %2$d columns, but the header has %3$d. The file may be incomplete or use a different separator.', 'garanlabs-eu-guarantee-notice-durability-labels'), $number, count($row), $width));
                }
                $rows++;
            }
            if ($rows === 0) {
                return self::invalid(__('The file has a header row but no products.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            }
        } finally {
            fclose($handle);
        }

        return ['rows' => $rows, 'columns' => $columns, 'delimiter' => $delimiter];
    }

    /* ---------- Import + publish ---------- */

    private static function lock($store_id)
    {
        $key = self::LOCK_PREFIX . $store_id;
        if (add_option($key, time(), '', 'no')) {
            return true;
        }
        if (time() - (int) get_option($key, 0) > self::LOCK_TTL) {
            delete_option($key);
            return add_option($key, time(), '', 'no');
        }
        return false;
    }

    private static function unlock($store_id)
    {
        delete_option(self::LOCK_PREFIX . $store_id);
    }

    /**
     * Validates $path and publishes it as the store's current price list. The file content is kept
     * byte-for-byte; only its name changes to the store file name pattern. The same file received again
     * on the same day is skipped (an hourly URL fetch doesn't add an archive entry every hour) unless $force.
     *
     * @return array{rows: int, filename: string, skipped?: bool}|\WP_Error
     */
    public static function import($store_id, $path, $origin, $source_name = '', $force = false)
    {
        $store = self::stores()[$store_id] ?? null;
        if (!$store) {
            return new \WP_Error('eggl_ph_dpl_ext_store', __('This store does not use an external price list.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        if (!self::lock($store_id)) {
            return new \WP_Error('eggl_ph_dpl_ext_locked', __('Another price list for this store is being imported. Try again in a moment.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        try {
            // A store's own required columns (e.g. a café's services list) replace the global ones.
            $valid = self::validate($path, $store['required_columns'] !== '' ? $store['required_columns'] : null);
            if (is_wp_error($valid)) {
                return self::record_error($store_id, $valid, $origin, $source_name);
            }

            $hash = hash_file('sha256', $path);
            $previous = self::status($store_id);
            if (!$force && $hash === $previous['hash'] && $previous['received_at'] && wp_date('Y-m-d', (int) $previous['received_at']) === current_time('Y-m-d')) {
                return ['rows' => $previous['rows'], 'filename' => $previous['filename'], 'skipped' => true];
            }

            $now = current_datetime();
            $filename = Helper::build_store_filename($store, Generator::next_sequence(), $now);
            $archive_path = Helper::store_archive_dir($store_id) . '/' . $filename;
            $tmp = $archive_path . '.tmp';
            if (!copy($path, $tmp)) {
                wp_delete_file($tmp);
                return self::record_error($store_id, new \WP_Error('eggl_ph_dpl_ext_write', __('Could not store the price list file.', 'garanlabs-eu-guarantee-notice-durability-labels')), $origin, $source_name);
            }
            $error = Generator::publish($tmp, $archive_path, Helper::store_dir($store_id) . '/current.csv');
            if ($error !== '') {
                return self::record_error($store_id, new \WP_Error('eggl_ph_dpl_ext_publish', $error), $origin, $source_name);
            }

            self::set_status($store_id, [
                'filename'    => $filename,
                'rows'        => $valid['rows'],
                'received_at' => time(),
                'origin'      => $origin,
                'hash'        => $hash,
                'error'       => '',
                'error_at'    => 0,
            ]);
            Generator::cleanup_archive();
            do_action('eggl_ph_dpl_external_imported', $store_id, $filename, $valid, $origin);
            return ['rows' => $valid['rows'], 'filename' => $filename];
        } finally {
            self::unlock($store_id);
        }
    }

    // phpcs:enable WordPress.WP.AlternativeFunctions

    /* ---------- Errors and alerts ---------- */

    public static function origin_label($origin)
    {
        $labels = [
            'folder' => __('folder upload', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'api'    => __('REST API', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'url'    => __('remote URL', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'manual' => __('manual upload', 'garanlabs-eu-guarantee-notice-durability-labels'),
        ];
        return $labels[$origin] ?? $origin;
    }

    /** Stores the rejection for the Status box and e-mails it; the published list is left untouched. */
    private static function record_error($store_id, \WP_Error $error, $origin, $source_name = '')
    {
        $message = $error->get_error_message();
        if ($source_name !== '') {
            $message = $source_name . ': ' . $message;
        }
        /* translators: 1: how the file arrived, e.g. remote URL; 2: the reason it was rejected. */
        $message = sprintf(__('%1$s — %2$s', 'garanlabs-eu-guarantee-notice-durability-labels'), self::origin_label($origin), $message);
        $previous = self::status($store_id);
        // The hourly check would otherwise e-mail the same problem (e.g. a URL that is down) every hour.
        $repeat = $previous['error'] === $message && $previous['error_at'] && wp_date('Y-m-d', (int) $previous['error_at']) === current_time('Y-m-d');
        self::set_status($store_id, ['error' => $message, 'error_at' => time()]);

        $store = Helper::get_store($store_id);
        if ($store && !$repeat) {
            self::mail(
                /* translators: %s: store premises label, e.g. P-01. */
                sprintf(__('Price list for store %s was rejected', 'garanlabs-eu-guarantee-notice-durability-labels'), $store['premises_label']),
                /* translators: 1: store premises label; 2: store address; 3: reason. */
                sprintf(__("The price list file for store %1\$s (%2\$s) was not published:\n\n%3\$s\n\nThe previous price list stays published until a valid file arrives.", 'garanlabs-eu-guarantee-notice-durability-labels'), $store['premises_label'], $store['address'], $message)
            );
        }
        return $error;
    }

    /** Once per store per day, after the deadline, when no list has arrived that day. */
    private static function maybe_alert_overdue($store_id, $store)
    {
        $today = current_time('Y-m-d');
        if (!self::is_overdue($store_id) || self::status($store_id)['alerted_on'] === $today) {
            return;
        }
        self::set_status($store_id, ['alerted_on' => $today]);
        $received = (int) self::status($store_id)['received_at'];
        self::mail(
            /* translators: %s: store premises label, e.g. P-01. */
            sprintf(__('No price list received today for store %s', 'garanlabs-eu-guarantee-notice-durability-labels'), $store['premises_label']),
            /* translators: 1: store premises label; 2: store address; 3: expected time HH:MM; 4: date and time of the last received list. */
            sprintf(__("No new price list file has arrived for store %1\$s (%2\$s) by %3\$s today.\n\nLast received: %4\$s\n\nThe price list must be current every day. Check the export from your ERP.", 'garanlabs-eu-guarantee-notice-durability-labels'),
                $store['premises_label'],
                $store['address'],
                Helper::settings()['external_deadline'],
                $received ? wp_date('Y-m-d H:i', $received) : __('never', 'garanlabs-eu-guarantee-notice-durability-labels'))
        );
    }

    private static function mail($subject, $message)
    {
        $to = Helper::settings()['external_alert_email'];
        if ($to === '') {
            return;
        }
        $message .= "\n\n" . admin_url('admin.php?page=' . \EGGL\PriceHistory\Admin\PPH_Admin::PAGE_SLUG);
        wp_mail($to, '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] ' . $subject, $message);
    }
}
