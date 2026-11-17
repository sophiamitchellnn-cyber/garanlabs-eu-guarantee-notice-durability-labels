<?php

namespace EGGL\PriceHistory\Modules\DigitalPriceList;

if (!defined('ABSPATH')) {
    exit;
}

/** Serves the public /{path}/, /{path}/current.csv and /{path}/archive/{file} URLs without exposing the uploads path directly. */
class Router
{
    const FLUSH_FLAG = 'eggl_ph_dpl_flush_rewrite';
    // Bump whenever add_rules() changes, so existing installs pick up new rules once without a manual permalink save.
    const RULES_VERSION = '2';
    const RULES_VERSION_OPTION = 'eggl_ph_dpl_rules_version';

    public function __construct()
    {
        add_action('init', [self::class, 'add_rules']);
        add_filter('query_vars', [self::class, 'query_vars']);
        add_action('template_redirect', [self::class, 'serve']);
        add_action('update_option_' . Helper::SETTINGS, [self::class, 'maybe_flag_flush'], 10, 2);
        // Runs after add_rules() (same hook, later priority) so a pending flush picks up the current path.
        add_action('init', [self::class, 'maybe_flush'], 20);
        // WordPress's canonical redirect otherwise force-appends a trailing slash to file-like URLs
        // such as /{path}/current.csv, turning the documented direct CSV link into a 301 redirect.
        add_filter('redirect_canonical', [self::class, 'skip_canonical_redirect']);
    }

    public static function skip_canonical_redirect($redirect)
    {
        return get_query_var(Helper::QUERY_VAR) !== '' ? false : $redirect;
    }

    public static function add_rules()
    {
        $path = Helper::get_public_path();
        add_rewrite_rule('^' . $path . '/?$', 'index.php?' . Helper::QUERY_VAR . '=index', 'top');
        add_rewrite_rule('^' . $path . '/current\.csv$', 'index.php?' . Helper::QUERY_VAR . '=current', 'top');
        add_rewrite_rule('^' . $path . '/archive/([^/]+)$', 'index.php?' . Helper::QUERY_VAR . '=archive&eggl_ph_dpl_file=$matches[1]', 'top');
        // Physical stores: generic rules (no per-store rules), so adding/removing a store needs no rewrite flush.
        add_rewrite_rule('^' . $path . '/stores/([a-z0-9-]+)/current\.csv$', 'index.php?' . Helper::QUERY_VAR . '=store_current&eggl_ph_dpl_store=$matches[1]', 'top');
        add_rewrite_rule('^' . $path . '/stores/([a-z0-9-]+)/archive/([^/]+)$', 'index.php?' . Helper::QUERY_VAR . '=store_archive&eggl_ph_dpl_store=$matches[1]&eggl_ph_dpl_file=$matches[2]', 'top');
    }

    public static function query_vars($vars)
    {
        $vars[] = Helper::QUERY_VAR;
        $vars[] = 'eggl_ph_dpl_file';
        $vars[] = 'eggl_ph_dpl_store';
        return $vars;
    }

    public static function maybe_flag_flush($old_value, $new_value)
    {
        $old_value = is_array($old_value) ? $old_value : [];
        $new_value = is_array($new_value) ? $new_value : [];
        if (($old_value['path'] ?? '') !== ($new_value['path'] ?? '') || !empty($old_value['enable']) !== !empty($new_value['enable'])) {
            update_option(self::FLUSH_FLAG, 1, false);
        }
    }

    public static function maybe_flush()
    {
        if (get_option(self::FLUSH_FLAG) || get_option(self::RULES_VERSION_OPTION) !== self::RULES_VERSION) {
            flush_rewrite_rules(false);
            delete_option(self::FLUSH_FLAG);
            update_option(self::RULES_VERSION_OPTION, self::RULES_VERSION, false);
        }
    }

    private static function fail($status = 404)
    {
        status_header($status);
        nocache_headers();
        exit;
    }

    private static function serve_file($path, $filename)
    {
        if (!is_file($path)) {
            self::fail();
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        // Store file names keep spaces, diacritics and ':' (required format), so send an ASCII fallback plus the exact UTF-8 name (RFC 6266/5987).
        $fallback = preg_replace('/[^A-Za-z0-9._ -]+/', '_', remove_accents($filename));
        header('Content-Disposition: inline; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        // phpcs:ignore WordPress.Security.EscapeOutput, WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams a generated CSV file straight to output without loading it into memory; WP_Filesystem::get_contents() would buffer the whole file first.
        readfile($path);
    }

    private static function serve_archive($file)
    {
        $file = basename((string) $file);
        if ($file === '' || !preg_match('/^[A-Za-z0-9._-]+\.csv$/D', $file)) {
            self::fail();
        }
        $archive_dir = realpath(Helper::archive_dir());
        $real = $archive_dir ? realpath($archive_dir . '/' . $file) : false;
        if (!$real || strpos($real, $archive_dir . DIRECTORY_SEPARATOR) !== 0) {
            self::fail();
        }
        self::serve_file($real, $file);
    }

    /**
     * Store archive names may contain spaces, diacritics, '.' and ':' — validated by excluding path
     * separators and other unsafe characters (never present in generated names) rather than by an
     * ASCII allowlist, then confined to the store's archive directory via realpath().
     */
    private static function serve_store_archive($store_id, $file)
    {
        // Pretty permalinks hand over the matched path segment still percent-encoded; with plain
        // permalinks $_GET has already decoded it. Generated names never contain '%', so one decode
        // is correct in both cases (and a leftover '%' is rejected below).
        $file = wp_basename(rawurldecode((string) $file));
        if ($file === '' || !preg_match('/^[^\/\\\\%?*|"<>\x00-\x1f]+\.csv$/uD', $file)) {
            self::fail();
        }
        $archive_dir = realpath(Helper::store_archive_dir($store_id));
        $real = $archive_dir ? realpath($archive_dir . '/' . $file) : false;
        if (!$real || strpos($real, $archive_dir . DIRECTORY_SEPARATOR) !== 0) {
            self::fail();
        }
        self::serve_file($real, $file);
    }

    private static function index_entry($entry, $store_id = '')
    {
        $url = $store_id !== '' ? Helper::get_store_archive_url($store_id, $entry['filename']) : Helper::get_archive_url($entry['filename']);
?>
<li>
<span class="date"><?php echo esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $entry['timestamp']), 'Y-m-d H:i')); ?></span>
<a href="<?php echo esc_url($url); ?>"><?php echo esc_html($entry['filename']); ?></a>
</li>
<?php
    }

    /** Minimal standalone HTML page — current + archived CSVs still in the retention window; no WP page/template involved. */
    private static function serve_index()
    {
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');

        $store = Helper::get_store_info();
        $lists = Generator::list_price_lists();
        $physical = [];
        foreach (Helper::stores() as $store_id => $physical_store) {
            $store_lists = Generator::list_price_lists(Helper::store_archive_dir($store_id));
            if ($store_lists['current']) {
                $physical[$store_id] = ['store' => $physical_store, 'lists' => $store_lists];
            }
        }
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($store['name']); ?> &mdash; <?php esc_html_e('Digital Price List', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></title>
<style>
body{font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:40rem;margin:2rem auto;padding:0 1rem;color:#1d2327}
h1{font-size:1.4rem;margin:0 0 .25rem}
h2{font-size:1.1rem;margin-top:2rem}
ul{list-style:none;margin:0;padding:0}
li{padding:.4rem 0;border-bottom:1px solid #dcdcde}
a{color:#2271b1;text-decoration:none}
a:hover{text-decoration:underline}
.date{color:#646970;margin-right:.5rem}
h3{font-size:1rem;margin:1.5rem 0 .25rem}
.meta{color:#646970;margin:0 0 .5rem}
</style>
</head>
<body>
<h1><?php echo esc_html($store['name']); ?></h1>
<p><?php esc_html_e('Digital Price List', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>

<?php if (!$lists['current']): ?>
<p><?php esc_html_e('No price list has been generated yet.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
<?php else: ?>
<h2><?php esc_html_e('Current Price List', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h2>
<ul>
<?php self::index_entry($lists['current']); ?>
</ul>

<?php if ($lists['previous']): ?>
<h2><?php esc_html_e('Previous Price Lists', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h2>
<ul>
<?php foreach ($lists['previous'] as $entry) self::index_entry($entry); ?>
</ul>
<?php endif; ?>
<?php endif; ?>

<?php if ($physical): ?>
<h2><?php esc_html_e('Physical Stores', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h2>
<?php foreach ($physical as $store_id => $entry): ?>
<h3><?php echo esc_html($entry['store']['address']); ?></h3>
<p class="meta"><?php echo esc_html(implode(' · ', array_filter([$entry['store']['outlet_type'], $entry['store']['premises_label'], $entry['store']['store_number']]))); ?></p>
<ul>
<?php self::index_entry($entry['lists']['current'], $store_id); ?>
<?php foreach ($entry['lists']['previous'] as $previous) self::index_entry($previous, $store_id); ?>
</ul>
<?php endforeach; ?>
<?php endif; ?>
</body>
</html>
<?php
    }

    public static function serve()
    {
        $mode = get_query_var(Helper::QUERY_VAR);
        if (!in_array($mode, ['index', 'current', 'archive', 'store_current', 'store_archive'], true)) {
            return;
        }
        if (empty(Helper::settings()['enable'])) {
            self::fail();
        }
        if ($mode === 'index') {
            self::serve_index();
        } elseif ($mode === 'current') {
            // Stable alias URL, but the download keeps the generated list's own name.
            $filename = Helper::status()['filename'];
            self::serve_file(Helper::upload_dir() . '/current.csv', $filename !== '' ? $filename : 'current.csv');
        } elseif ($mode === 'archive') {
            self::serve_archive(get_query_var('eggl_ph_dpl_file'));
        } else {
            // Only stores that are still configured are served; a removed store's URLs 404.
            $store_id = (string) get_query_var('eggl_ph_dpl_store');
            if (!Helper::get_store($store_id)) {
                self::fail();
            }
            if ($mode === 'store_current') {
                $filename = Helper::get_store($store_id)['source'] === 'external'
                    ? External_Source::status($store_id)['filename']
                    : (Helper::status()['stores'][$store_id]['filename'] ?? '');
                self::serve_file(Helper::store_dir($store_id) . '/current.csv', $filename !== '' ? $filename : 'current.csv');
            } else {
                self::serve_store_archive($store_id, get_query_var('eggl_ph_dpl_file'));
            }
        }
        exit;
    }
}
