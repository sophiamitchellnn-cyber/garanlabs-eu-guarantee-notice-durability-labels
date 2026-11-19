<?php

namespace EGGL\PriceHistory\Modules\DigitalPriceList;

if (!defined('ABSPATH')) {
    exit;
}

/** Settings, plus the single centralized resolver for store info, paths, URLs and filenames (spec §22). */
class Helper
{
    const SETTINGS = 'eggl_ph_digital_price_list';
    const STATUS = 'eggl_ph_digital_price_list_status';
    const DEFAULT_PATH = 'price-list';
    const DEFAULT_SCHEDULE_TIME = '06:00';
    const QUERY_VAR = 'eggl_ph_price_list';

    /**
     * Single source of default values, shared by normalize_settings() and DPL_Admin::settings_fields().
     * Store name/address resolve from WooCommerce/site settings where a reliable source exists, so a
     * first save already reflects real store data instead of empty placeholders.
     */
    public static function defaults($option_name = '')
    {
        $options = [
            'enable'           => 0,
            'enable_schedule'  => 1,
            'schedule_time'    => self::DEFAULT_SCHEDULE_TIME,
            // Extra daily generation times, e.g. "10:30" so a price change synced outside WooCommerce is still published by 11:00.
            'extra_times'      => '',
            'path'             => self::DEFAULT_PATH,
            'filename_pattern' => self::FILENAME_PATTERN,
            // No dedicated WooCommerce "store name" option exists; the site title is the closest reliable source.
            'store_name'       => get_bloginfo('name'),
            'store_address'    => (string) get_option('woocommerce_store_address', ''),
            // No reliable WooCommerce source for an outlet type; 'webshop' is the sensible default for a WooCommerce store's own price list.
            'outlet_type'      => 'webshop',
            'outlet_id'        => '1',
            // Physical stores: each gets its own copy of the price list (Croatian rules require one per business premises).
            'stores'                 => [],
            'store_filename_pattern' => self::STORE_FILENAME_PATTERN,
            // CSV format (Croatian Pravilnik o načinu objave cjenika proizvoda, in force from 17.11.2026).
            'filename_underscores' => 0,
            // One price list for all stores (čl. 6(4)): the webshop list carries ZA_SVA_PRODAJNA_MJESTA instead of store data.
            'all_stores_list'      => 0,
            'csv_delimiter'        => ';',
            // Decimal point by default (89.99); a comma (89,99) matches ERP exports that use one, so all of a trader's files look alike.
            'csv_decimal'          => '.',
            'csv_bom'              => 1,
            'csv_columns'          => '',
            'oib'                  => '',
            'price_list_type'      => 'roba',
            'ean_meta_key'         => '',
            'regenerate_on_change' => 1,
            // Unit price (price per unit of measure): see Unit_Price for the resolution order.
            'unit_source'          => 'auto',
            'unit_meta_unit'       => '',
            'unit_meta_quantity'   => '',
            'unit_meta_price'      => '',
            'unit_ratio'           => '1',
            // Off by default: product weight is usually the shipping weight, not the unit-price quantity.
            'unit_weight_fallback' => 0,
            // Unit column only, when no unit-price source has a unit: a product meta key (e.g. a theme's
            // unit of measurement field), then a fixed default such as "kom". The unit price is unaffected.
            'unit_fallback_meta'    => '',
            'unit_fallback_default' => '',
            // External (ERP) store price lists: see External_Source.
            'external_required_columns' => '',
            'external_deadline'         => '08:00',
            'external_alert_email'      => '',
        ];

        return $options[$option_name] ?? '';
    }

    const STORE_SCOPES = ['all', 'include', 'exclude'];
    const PRICE_LIST_TYPES = ['roba', 'usluge'];
    const DELIMITERS = [';', ','];
    const DECIMALS = ['.', ','];
    // Default file names follow Pravilnik o načinu objave cjenika proizvoda (NN 132/2026) čl. 6(3): OIB, store
    // type, address, premises label, goods/services, storage number, YYYYMMDD_HHMM. A saved earlier default
    // (listed below) is migrated to the current one; a custom pattern is kept as typed.
    // "Broj pohrane" is the storage (version) number, so stores use the same incrementing {sequence} as the
    // webshop; the store itself is already identified by its address and premises label.
    const STORE_FILENAME_PATTERN = '{oib}_{outlet_type}_{address}_{premises_label}_{price_list_type}_{sequence}_{timestamp_hr}';
    const FILENAME_PATTERN = '{oib}_{outlet_type}_{address}_{outlet_id}_{price_list_type}_{sequence}_{timestamp_hr}';
    // Čl. 6(4): a single price list for all stores uses this text in place of the store data.
    const ALL_STORES_LABEL = 'ZA_SVA_PRODAJNA_MJESTA';
    const ALL_STORES_FILENAME_PATTERN = '{oib}_{za_sva}_{price_list_type}_{sequence}_{timestamp_hr}';
    const OLD_FILENAME_PATTERNS = [
        '{outlet_type}_{address}_{outlet_id}_{sequence}_{timestamp}',
        '{outlet_type}_{address}_{outlet_id}_{sequence}_{timestamp_hr}',
    ];
    const OLD_STORE_FILENAME_PATTERNS = [
        // Its {time} ':' is blocked in URLs by many firewalls.
        '{outlet_type}_{address}_{premises_label}_{store_number}_{date}_{time}',
        '{outlet_type}_{address}_{premises_label}_{store_number}_{timestamp_hr}',
        // 2.2.5 default: used the fixed store number as the storage number.
        '{oib}_{outlet_type}_{address}_{premises_label}_{price_list_type}_{store_number}_{timestamp_hr}',
    ];

    /** Default CSV layout: field => column header, in the order of the goods price list items (Pravilnik čl. 3). */
    public static function default_csv_columns()
    {
        return [
            'product_name'      => 'Naziv robe',
            'product_code'      => 'Šifra robe',
            'brand'             => 'Robna marka',
            'net_quantity'      => 'Neto količina',
            'unit'              => 'Jedinica mjere',
            'retail_price'      => 'MPC',
            'special_sale_name' => 'Naziv posebnog oblika prodaje',
            'unit_price'        => 'CJM',
            'barcode'           => 'Barkod',
            'ean'               => 'EAN',
            'base_price'        => 'Bazna cijena',
        ];
    }

    /** "field = Header" lines → [field => header]; unknown fields are dropped; nothing valid → the default layout. */
    public static function parse_csv_columns($value)
    {
        $columns = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $value) as $line) {
            if (!preg_match('/^\s*([a-z_]+)\s*(?:=|\|)\s*(.*?)\s*$/u', $line, $m) || !in_array($m[1], Product_Mapper::FIELDS, true)) {
                continue;
            }
            $columns[$m[1]] = $m[2] !== '' ? sanitize_text_field($m[2]) : $m[1];
        }
        return $columns;
    }

    public static function csv_columns_text(array $columns)
    {
        return implode("\n", array_map(static fn($field, $header) => $field . ' = ' . $header, array_keys($columns), $columns));
    }

    /** The CSV layout in use: [field => header]. */
    public static function csv_columns()
    {
        $columns = self::parse_csv_columns(self::settings()['csv_columns']);
        return apply_filters('eggl_ph_dpl_csv_layout', $columns ?: self::default_csv_columns());
    }
    const STORE_SOURCES = ['generated', 'external'];
    const DEFAULT_STORE_TYPE = 'prodavaonica';

    /**
     * Normalizes the physical store list. Deterministic (safe on every settings() read): a store
     * without an id gets one derived from its premises label/address, deduplicated, and kept stable
     * afterwards via the form's hidden id field so its public URLs don't change when details are edited.
     */
    public static function normalize_stores($stores)
    {
        if (!is_array($stores)) {
            return [];
        }

        $entries = [];
        foreach ($stores as $store) {
            if (!is_array($store)) {
                continue;
            }
            $scope = $store['scope'] ?? 'all';
            $source = $store['source'] ?? 'generated';
            $remote_url = trim((string) ($store['remote_url'] ?? ''));
            $remote_url = $remote_url !== '' && preg_match('#^https?://#i', $remote_url) ? esc_url_raw($remote_url, ['http', 'https']) : '';
            $type = $store['price_list_type'] ?? 'roba';
            $entry = [
                'id'             => self::store_id_slug($store['id'] ?? ''),
                'outlet_type'    => sanitize_text_field($store['outlet_type'] ?? self::DEFAULT_STORE_TYPE),
                'address'        => sanitize_text_field($store['address'] ?? ''),
                'premises_label' => sanitize_text_field($store['premises_label'] ?? ''),
                'store_number'   => sanitize_text_field($store['store_number'] ?? ''),
                'scope'          => in_array($scope, self::STORE_SCOPES, true) ? $scope : 'all',
                'categories'     => self::id_list($store['categories'] ?? []),
                'tags'           => self::id_list($store['tags'] ?? []),
                'products'       => self::id_list($store['products'] ?? []),
                'source'         => in_array($source, self::STORE_SOURCES, true) ? $source : 'generated',
                'remote_url'     => $remote_url,
                'price_list_type' => in_array($type, self::PRICE_LIST_TYPES, true) ? $type : 'roba',
                // Overrides the global External File Required Columns for this store (e.g. a café's services list).
                'required_columns' => sanitize_text_field($store['required_columns'] ?? ''),
            ];
            // A blank row (added in the form and left empty) is dropped; anything partially filled is kept so no input is lost.
            if ($entry['address'] === '' && $entry['premises_label'] === '' && $entry['store_number'] === '') {
                continue;
            }
            if ($entry['outlet_type'] === '') {
                $entry['outlet_type'] = self::DEFAULT_STORE_TYPE;
            }
            $entries[] = $entry;
        }

        // Pass 1: stores that already have an id claim it (first occurrence wins), so a newly added
        // store can never take over, and change the public URL of, an existing one.
        $ids = [];
        foreach ($entries as $k => $entry) {
            if ($entry['id'] !== '' && !in_array($entry['id'], $ids, true)) {
                $ids[$k] = $entry['id'];
            }
        }
        // Pass 2: new stores (and duplicated ids) get an id derived from their label/address, suffixed if taken.
        foreach ($entries as $k => $entry) {
            if (isset($ids[$k])) {
                continue;
            }
            $base = $entry['id'] !== '' ? $entry['id'] : self::store_id_slug($entry['premises_label'] !== '' ? $entry['premises_label'] : $entry['address']);
            $base = $base !== '' ? $base : 'store';
            $id = $base;
            for ($i = 2; in_array($id, $ids, true); $i++) {
                $id = $base . '-' . $i;
            }
            $ids[$k] = $id;
        }

        $normalized = [];
        foreach ($entries as $k => $entry) {
            $entry['id'] = $ids[$k];
            $normalized[$ids[$k]] = $entry;
        }

        return $normalized;
    }

    /** "10:30, 9:00 14:00" → ['09:00', '10:30', '14:00']; invalid entries and the main schedule time are dropped. */
    public static function parse_times($value, $exclude = '')
    {
        $times = [];
        foreach (preg_split('/[\s,;]+/', (string) $value) as $time) {
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/D', $time, $m)) {
                $times[] = sprintf('%02d:%02d', $m[1], $m[2]);
            }
        }
        $times = array_values(array_diff(array_unique($times), [$exclude]));
        sort($times);
        return array_slice($times, 0, 12);
    }

    /** Replaces a stored earlier default file name pattern with the current default; custom patterns are untouched. */
    public static function migrate_patterns($value)
    {
        if (is_array($value)) {
            if (isset($value['filename_pattern']) && in_array($value['filename_pattern'], self::OLD_FILENAME_PATTERNS, true)) {
                $value['filename_pattern'] = self::FILENAME_PATTERN;
            }
            if (isset($value['store_filename_pattern']) && in_array($value['store_filename_pattern'], self::OLD_STORE_FILENAME_PATTERNS, true)) {
                $value['store_filename_pattern'] = self::STORE_FILENAME_PATTERN;
            }
        }
        return $value;
    }

    private static function webshop_pattern($pattern)
    {
        $pattern = sanitize_text_field((string) $pattern);
        return in_array($pattern, self::OLD_FILENAME_PATTERNS, true) ? self::FILENAME_PATTERN : $pattern;
    }

    private static function store_pattern($pattern)
    {
        $pattern = sanitize_text_field((string) $pattern);
        return in_array($pattern, self::OLD_STORE_FILENAME_PATTERNS, true) ? self::STORE_FILENAME_PATTERN : $pattern;
    }

    /** A product meta key as typed by the admin (e.g. _my_unit); whitespace and markup removed. */
    private static function meta_key($key)
    {
        return preg_replace('/\s+/', '', sanitize_text_field((string) $key));
    }

    /** Unique positive integer ids (term or product ids) from a posted list. */
    private static function id_list($ids)
    {
        return array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
    }

    /** True when a store's include/exclude scope has at least one category, tag or product to match. */
    public static function store_has_filters($store)
    {
        return $store['categories'] || $store['tags'] || $store['products'];
    }

    /** URL/directory-safe store id: lowercase ASCII letters, digits and dashes only. */
    private static function store_id_slug($value)
    {
        $value = strtolower(sanitize_title(remove_accents((string) $value)));
        return trim(preg_replace('/[^a-z0-9-]+/', '', $value), '-');
    }

    /** A store is only generated once it has every value its file name needs. */
    public static function store_is_complete($store)
    {
        return $store['address'] !== '' && $store['premises_label'] !== '' && $store['store_number'] !== '';
    }

    /** With one price list for all stores, a store that would get a generated copy is covered by the webshop list instead. */
    public static function covered_by_all_stores_list($store)
    {
        return self::settings()['all_stores_list'] && $store['source'] !== 'external';
    }

    /** Configured physical stores keyed by id. */
    public static function stores()
    {
        return self::settings()['stores'];
    }

    public static function get_store($id)
    {
        $stores = self::stores();
        return $stores[(string) $id] ?? null;
    }

    /** A missing/invalid settings array (e.g. before this module's settings are ever saved) resolves to defaults, matching the other modules' settings behavior, rather than surfacing an error. */
    public static function normalize_settings($settings)
    {
        if (!is_array($settings)) {
            $settings = [];
        }

        $path = sanitize_title($settings['path'] ?? self::defaults('path'));
        if ($path === '') {
            $path = self::defaults('path');
        }

        $schedule_time = $settings['schedule_time'] ?? self::defaults('schedule_time');
        if (!is_string($schedule_time) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $schedule_time)) {
            $schedule_time = self::defaults('schedule_time');
        }

        return [
            'enable'           => !empty($settings['enable']),
            'enable_schedule'  => array_key_exists('enable_schedule', $settings) ? !empty($settings['enable_schedule']) : (bool) self::defaults('enable_schedule'),
            'schedule_time'    => $schedule_time,
            'extra_times'      => implode(', ', self::parse_times($settings['extra_times'] ?? '', $schedule_time)),
            'path'             => $path,
            'filename_pattern' => self::webshop_pattern($settings['filename_pattern'] ?? self::defaults('filename_pattern')),
            'store_name'       => sanitize_text_field($settings['store_name'] ?? self::defaults('store_name')),
            'store_address'    => sanitize_text_field($settings['store_address'] ?? self::defaults('store_address')),
            'outlet_type'      => sanitize_text_field($settings['outlet_type'] ?? self::defaults('outlet_type')),
            'outlet_id'        => sanitize_text_field($settings['outlet_id'] ?? self::defaults('outlet_id')),
            'stores'           => self::normalize_stores($settings['stores'] ?? self::defaults('stores')),
            'store_filename_pattern' => self::store_pattern($settings['store_filename_pattern'] ?? self::defaults('store_filename_pattern')),
            'filename_underscores' => !empty($settings['filename_underscores']),
            'all_stores_list'      => !empty($settings['all_stores_list']),
            'csv_delimiter'        => in_array($settings['csv_delimiter'] ?? '', self::DELIMITERS, true) ? $settings['csv_delimiter'] : self::defaults('csv_delimiter'),
            'csv_decimal'          => in_array($settings['csv_decimal'] ?? '', self::DECIMALS, true) ? $settings['csv_decimal'] : self::defaults('csv_decimal'),
            'csv_bom'              => array_key_exists('csv_bom', $settings) ? !empty($settings['csv_bom']) : (bool) self::defaults('csv_bom'),
            'csv_columns'          => self::csv_columns_text(self::parse_csv_columns($settings['csv_columns'] ?? '') ?: self::default_csv_columns()),
            'oib'                  => preg_replace('/[^0-9A-Za-z]/', '', (string) ($settings['oib'] ?? '')),
            'price_list_type'      => in_array($settings['price_list_type'] ?? '', self::PRICE_LIST_TYPES, true) ? $settings['price_list_type'] : self::defaults('price_list_type'),
            'ean_meta_key'         => self::meta_key($settings['ean_meta_key'] ?? ''),
            'regenerate_on_change' => array_key_exists('regenerate_on_change', $settings) ? !empty($settings['regenerate_on_change']) : (bool) self::defaults('regenerate_on_change'),
            'unit_source'          => in_array($settings['unit_source'] ?? '', Unit_Price::SOURCES, true) ? $settings['unit_source'] : self::defaults('unit_source'),
            'unit_meta_unit'       => self::meta_key($settings['unit_meta_unit'] ?? ''),
            'unit_meta_quantity'   => self::meta_key($settings['unit_meta_quantity'] ?? ''),
            'unit_meta_price'      => self::meta_key($settings['unit_meta_price'] ?? ''),
            'unit_ratio'           => Unit_Price::number($settings['unit_ratio'] ?? '') ?? (float) self::defaults('unit_ratio'),
            'unit_weight_fallback' => !empty($settings['unit_weight_fallback']),
            'unit_fallback_meta'    => self::meta_key($settings['unit_fallback_meta'] ?? ''),
            'unit_fallback_default' => sanitize_text_field($settings['unit_fallback_default'] ?? ''),
            'external_required_columns' => implode(', ', External_Source::parse_columns($settings['external_required_columns'] ?? '')),
            'external_deadline'         => is_string($settings['external_deadline'] ?? null) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $settings['external_deadline']) ? $settings['external_deadline'] : self::defaults('external_deadline'),
            'external_alert_email'      => implode(', ', array_filter(array_map('sanitize_email', array_map('trim', explode(',', (string) ($settings['external_alert_email'] ?? '')))), 'is_email')),
        ];
    }

    public static function settings()
    {
        $settings = self::normalize_settings(get_option(self::SETTINGS, []));
        return is_wp_error($settings) ? self::normalize_settings([]) : $settings;
    }

    public static function status()
    {
        return wp_parse_args(get_option(self::STATUS, []), [
            'generated_at' => '',
            'rows'         => 0,
            'filename'     => '',
            'error'        => '',
            // Per physical store: id => ['rows' => int, 'filename' => string].
            'stores'       => [],
        ]);
    }

    public static function set_status($status)
    {
        update_option(self::STATUS, wp_parse_args($status, self::status()), false);
    }

    /** WooCommerce store address settings, PPH overrides taking precedence (spec §19). */
    public static function get_store_info()
    {
        $settings = self::settings();
        $country_state = (string) get_option('woocommerce_default_country', '');
        $country = strtok($country_state, ':');

        return [
            // No dedicated WooCommerce "store name" option exists; the site title is the closest reliable source.
            'name'      => $settings['store_name'] !== '' ? $settings['store_name'] : get_bloginfo('name'),
            'address'   => $settings['store_address'] !== '' ? $settings['store_address'] : (string) get_option('woocommerce_store_address', ''),
            'address_2' => (string) get_option('woocommerce_store_address_2', ''),
            'city'      => (string) get_option('woocommerce_store_city', ''),
            'postcode'  => (string) get_option('woocommerce_store_postcode', ''),
            'country'   => $country,
            // No reliable WooCommerce source for these; only ever an explicit override or the generic single-outlet default.
            'outlet_type' => $settings['outlet_type'],
            'outlet_id'   => $settings['outlet_id'] !== '' ? $settings['outlet_id'] : '1',
        ];
    }

    public static function get_public_path()
    {
        return self::settings()['path'];
    }

    private static function permalinks_enabled()
    {
        return (string) get_option('permalink_structure', '') !== '';
    }

    public static function get_public_url()
    {
        return self::permalinks_enabled()
            ? home_url('/' . self::get_public_path() . '/')
            : add_query_arg(self::QUERY_VAR, 'index', home_url('/'));
    }

    public static function get_current_url()
    {
        return self::permalinks_enabled()
            ? home_url('/' . self::get_public_path() . '/current.csv')
            : add_query_arg(self::QUERY_VAR, 'current', home_url('/'));
    }

    public static function get_archive_url($filename)
    {
        return self::permalinks_enabled()
            ? home_url('/' . self::get_public_path() . '/archive/' . rawurlencode($filename))
            : add_query_arg([self::QUERY_VAR => 'archive', 'eggl_ph_dpl_file' => $filename], home_url('/'));
    }

    public static function get_filename_pattern()
    {
        $settings = self::settings();
        $pattern = $settings['filename_pattern'];
        // The default (or empty) pattern switches to the all-stores name; a custom pattern is kept as typed.
        if ($settings['all_stores_list'] && ($pattern === '' || $pattern === self::FILENAME_PATTERN)) {
            return self::ALL_STORES_FILENAME_PATTERN;
        }
        return $pattern !== '' ? $pattern : self::defaults('filename_pattern');
    }

    private static function slug($value)
    {
        $value = remove_accents((string) $value);
        $value = strtolower(sanitize_title($value));
        return $value === '' ? '' : $value;
    }

    /** Fills the filename pattern's placeholders; always guarantees a unique, sanitized `name.csv`. */
    public static function build_filename($sequence = 1, $timestamp = null)
    {
        $store = self::get_store_info();
        $timestamp = $timestamp ?: current_time('Ymd-His');
        $replacements = [
            '{store_name}' => self::slug($store['name']),
            '{outlet_type}' => self::slug($store['outlet_type']),
            '{address}' => self::slug($store['address']),
            '{city}' => self::slug($store['city']),
            '{postcode}' => self::slug($store['postcode']),
            '{outlet_id}' => self::slug($store['outlet_id']),
            '{sequence}' => str_pad((string) max(1, (int) $sequence), 3, '0', STR_PAD_LEFT),
            '{timestamp}' => $timestamp,
            // YYYYMMDD_HHMM, the format required by the 2026 Croatian ordinance.
            '{timestamp_hr}' => self::timestamp_hr($timestamp),
            '{oib}' => self::slug(self::settings()['oib']),
            '{price_list_type}' => self::settings()['price_list_type'],
            '{za_sva}' => self::ALL_STORES_LABEL,
        ];

        $name = strtr(self::get_filename_pattern(), $replacements);
        // Collapse the empty placeholders' leftover separators (e.g. missing outlet_type between two underscores).
        $name = trim(preg_replace('/[_\-]{2,}/', '_', $name), '_-');
        $name = sanitize_file_name($name);
        if ($name === '' || (strpos($name, $timestamp) === false && strpos($name, self::timestamp_hr($timestamp)) === false)) {
            // A custom pattern that dropped {timestamp} would risk archive collisions; guarantee uniqueness anyway.
            $name = ($name !== '' ? $name . '-' : '') . $timestamp;
        }

        return $name . '.csv';
    }

    /** "20261116-210326" (Ymd-His) → "20261116_2103". */
    private static function timestamp_hr($timestamp)
    {
        return preg_match('/^(\d{8})-(\d{4})/', (string) $timestamp, $m) ? $m[1] . '_' . $m[2] : (string) $timestamp;
    }

    /**
     * Keeps a value exactly as typed (spaces, diacritics, punctuation such as the "P-01" label), only
     * removing what can't appear in a file name or would break path handling/URL decoding.
     */
    private static function filename_part($value)
    {
        $value = wp_check_invalid_utf8((string) $value, true);
        $value = preg_replace('/[\x00-\x1f\x7f\/\\\\?%*|"<>]+/u', ' ', $value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    public static function get_store_filename_pattern()
    {
        $pattern = self::settings()['store_filename_pattern'];
        return $pattern !== '' ? $pattern : self::defaults('store_filename_pattern');
    }

    /**
     * Croatian physical-store file name, e.g. "prodavaonica_Ilica 150 Zagreb_P-01_104_20261001_0745.csv".
     * Unlike build_filename() (webshop), values are not slugged: the required format uses the address
     * as written plus a dd.mm.yyyy date and HH:MM time.
     */
    public static function build_store_filename($store, $sequence = 1, ?\DateTimeInterface $time = null)
    {
        $time = $time ?: current_datetime();
        $timestamp = $time->format('Ymd-His');
        $replacements = [
            '{store_name}'     => self::filename_part(self::get_store_info()['name']),
            '{outlet_type}'    => self::filename_part($store['outlet_type']),
            '{address}'        => self::filename_part($store['address']),
            '{premises_label}' => self::filename_part($store['premises_label']),
            '{store_number}'   => self::filename_part($store['store_number']),
            '{date}'           => $time->format('d.m.Y'),
            '{time}'           => $time->format('H:i'),
            '{sequence}'       => str_pad((string) max(1, (int) $sequence), 3, '0', STR_PAD_LEFT),
            '{timestamp}'      => $timestamp,
            '{timestamp_hr}'   => $time->format('Ymd_Hi'),
            '{oib}'            => self::filename_part(self::settings()['oib']),
            '{price_list_type}' => $store['price_list_type'] ?? 'roba',
            '{za_sva}'         => self::ALL_STORES_LABEL,
        ];

        $pattern = self::get_store_filename_pattern();
        $name = self::filename_part(strtr($pattern, $replacements));
        $name = trim(preg_replace('/_{2,}/', '_', $name), " _-.");
        if ($name === '' || (strpos($pattern, '{time}') === false && strpos($pattern, '{timestamp}') === false && strpos($pattern, '{timestamp_hr}') === false)) {
            // Without a time component every run would overwrite the previous archive entry.
            $name = ($name !== '' ? $name . '_' : '') . $timestamp;
        }
        // ':' (from {time}) is blocked in URL paths by many firewalls (403) and not allowed in Windows file names.
        $name = str_replace(':', '-', $name);
        // Optional: "Kolodvorska 116" → "Kolodvorska_116", so public links carry no %20.
        if (self::settings()['filename_underscores']) {
            $name = preg_replace('/_{2,}/', '_', preg_replace('/\s+/u', '_', $name));
        }
        // Stay well inside the common 255-byte file name limit without splitting a multibyte character.
        if (strlen($name) > 200) {
            $name = rtrim(function_exists('mb_strcut') ? mb_strcut($name, 0, 200, 'UTF-8') : wp_check_invalid_utf8(substr($name, 0, 200), true));
        }

        return $name . '.csv';
    }

    public static function store_dir($store_id)
    {
        return self::upload_dir() . '/stores/' . $store_id;
    }

    public static function store_archive_dir($store_id)
    {
        return self::store_dir($store_id) . '/archive';
    }

    public static function get_store_current_url($store_id)
    {
        return self::permalinks_enabled()
            ? home_url('/' . self::get_public_path() . '/stores/' . rawurlencode($store_id) . '/current.csv')
            : add_query_arg([self::QUERY_VAR => 'store_current', 'eggl_ph_dpl_store' => rawurlencode($store_id)], home_url('/'));
    }

    public static function get_store_archive_url($store_id, $filename)
    {
        // Older archive names contain ':', which firewalls often block as %3A in a URL path; a query string gets through.
        return self::permalinks_enabled() && strpos($filename, ':') === false
            ? home_url('/' . self::get_public_path() . '/stores/' . rawurlencode($store_id) . '/archive/' . rawurlencode($filename))
            : add_query_arg([self::QUERY_VAR => 'store_archive', 'eggl_ph_dpl_store' => rawurlencode($store_id), 'eggl_ph_dpl_file' => rawurlencode($filename)], home_url('/'));
    }

    public static function upload_dir()
    {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 'eggl-ph-price-list';
    }

    public static function archive_dir()
    {
        return self::upload_dir() . '/archive';
    }
}
