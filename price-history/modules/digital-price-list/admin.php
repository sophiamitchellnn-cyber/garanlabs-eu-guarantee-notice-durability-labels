<?php

namespace EGGL\PriceHistory\Modules\DigitalPriceList;

if (!defined('ABSPATH')) {
    exit;
}

class DPL_Admin
{
    const GENERATE_ACTION = 'eggl_ph_dpl_generate_now';
    const STATUS_AJAX_ACTION = 'eggl_ph_dpl_status';

    public function __construct()
    {
        add_filter('eggl_ph_settings_sections', [$this, 'settings_section']);
        add_filter('eggl_ph_settings_fields', [self::class, 'settings_fields']);
        add_filter('sanitize_option_' . Helper::SETTINGS, [self::class, 'sanitize_settings'], 20);
        // The settings form reads the raw option; show a saved earlier default pattern as the current default.
        add_filter('option_' . Helper::SETTINGS, [Helper::class, 'migrate_patterns']);
        add_action('admin_post_' . self::GENERATE_ACTION, [$this, 'generate_now']);
        add_action('wp_ajax_' . self::STATUS_AJAX_ACTION, [self::class, 'ajax_status']);
        add_action('eggl_ph_form_top', [$this, 'panel_description']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets'], 20);
    }

    /**
     * WooCommerce registers its selectWoo-based enhanced select (and the AJAX product search behind
     * .wc-product-search) on every admin screen but only enqueues it on its own; load it here for the
     * physical store pickers.
     */
    public static function enqueue_assets($hook)
    {
        if (strpos((string) $hook, 'eggl-price-history') === false) {
            return;
        }
        if (wp_script_is('wc-enhanced-select', 'registered')) {
            wp_enqueue_script('wc-enhanced-select');
        }
        if (wp_style_is('woocommerce_admin_styles', 'registered')) {
            wp_enqueue_style('woocommerce_admin_styles');
        }
    }

    public function settings_section($sections)
    {
        $sections[] = [
            'id'     => Helper::SETTINGS,
            'title'  => __('Digital Price List', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'parent' => 'eggl_ph_price_compliance'
        ];
        return $sections;
    }

    /**
     * Output description text under panel title.
     */
    public function panel_description($form)
    {
        $id = $form['id'] ?? '';

        if (Helper::SETTINGS === $id) {

            echo '<div class="eggl-ph-plugin-panel-description">';

            echo '<p>';

            echo wp_kses_post(__("Under the regulations currently in force in Croatia, traders and service providers with a website are required to publish current price lists online in a machine-readable CSV or XML format and keep previous versions publicly available for at least 30 days. The Digital Price List module automatically generates and publishes the required product price list for your WooCommerce store.<br><br><em>This information is provided for general guidance only and does not constitute legal advice.</em>", 'garanlabs-eu-guarantee-notice-durability-labels'));

            echo '</p>';

            echo '</div>';
        }
    }

    public static function sanitize_settings($value)
    {
        $settings = Helper::normalize_settings($value);
        if (is_wp_error($settings)) {
            add_settings_error(Helper::SETTINGS, $settings->get_error_code(), $settings->get_error_message());
            return Helper::settings();
        }
        foreach ($settings['stores'] as $store) {
            if (!Helper::store_is_complete($store)) {
                add_settings_error(Helper::SETTINGS, 'eggl_ph_dpl_store_incomplete', sprintf(
                    /* translators: %s: the physical store's address or premises label. */
                    __('Physical store "%s" is missing its address, premises label or store number, so no price list will be generated for it until these are filled in.', 'garanlabs-eu-guarantee-notice-durability-labels'),
                    $store['address'] !== '' ? $store['address'] : $store['premises_label']
                ), 'warning');
            }
        }
        if ($settings['csv_delimiter'] === ',' && $settings['csv_decimal'] === ',') {
            add_settings_error(Helper::SETTINGS, 'eggl_ph_dpl_decimal_delimiter', __('The column separator and the decimal separator are both a comma, so every price will be wrapped in quotes. Use a semicolon as the column separator with a decimal comma.', 'garanlabs-eu-guarantee-notice-durability-labels'), 'warning');
        }
        Generator::reschedule($settings);
        if ($settings['enable']) {
            External_Source::prepare_dirs(array_filter($settings['stores'], static function ($store) {
                return $store['source'] === 'external' && $store['id'] !== '' && Helper::store_is_complete($store);
            }));
        }
        return $settings;
    }

    public static function nonce_action()
    {
        return 'eggl_ph_dpl_generate_now';
    }

    /**
     * Bypasses the settings API's kses'd `desc` output (strips markup) since this block is
     * plugin-authored. The Generate Now button uses `formaction`/`formmethod` to submit to
     * admin-post.php without needing its own nested `<form>` (illegal inside the settings
     * API's `<form action="options.php">`).
     */
    public static function render_status()
    {
        self::render_status_box();
        if (!Generator::is_running()) {
            return;
        }
        // While a run is in progress, refresh the Status box every few seconds until it finishes.
?>
        <script>
        (function() {
            var poll = function() {
                var box = document.querySelector('.eggl-ph-dpl-status-box');
                var nonce = box && box.querySelector('input[name="nonce"]');
                if (!box || !nonce || !window.fetch) return;
                var body = new URLSearchParams({action: <?php echo wp_json_encode(self::STATUS_AJAX_ACTION); ?>, nonce: nonce.value});
                fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, {method: 'POST', credentials: 'same-origin', body: body})
                    .then(function(r) { return r.json(); })
                    .then(function(r) {
                        if (!r || !r.success) return;
                        box.outerHTML = r.data.html;
                        if (r.data.running) setTimeout(poll, 5000);
                    })
                    .catch(function() { setTimeout(poll, 15000); });
            };
            setTimeout(poll, 5000);
        })();
        </script>
<?php
    }

    /** The Status box's current HTML and whether a run is still in progress, for the polling script above. */
    public static function ajax_status()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(null, 403);
        }
        check_ajax_referer(self::nonce_action(), 'nonce');
        ob_start();
        self::render_status_box();
        wp_send_json_success(['html' => ob_get_clean(), 'running' => Generator::is_running()]);
    }

    private static function render_status_box()
    {
        // Before reading the status: releasing a dead run records why, and that message belongs in this render.
        Generator::recover_stale();
        $status = Helper::status();
        $store  = Helper::get_store_info();
        // Generator::reschedule() uses Action Scheduler, not WP-Cron, so read the next run from there.
        $next   = function_exists('as_next_scheduled_action') ? as_next_scheduled_action(Generator::CRON_HOOK) : false;
        $next   = is_int($next) ? $next : false; // true = running now / async; false = not scheduled.

        $location = trim(preg_replace('/\s+/', ' ', $store['address'] . ' ' . $store['city']));
        $resolved = implode(' — ', array_filter([$store['name'], $location], fn($part) => trim((string) $part) !== ''));
?>
        <div class="eggl-ph-plugin-panel-description eggl-ph-dpl-status eggl-ph-dpl-status-box">
            <?php if (empty(Helper::settings()['enable'])): ?>
                <p><strong style="color:#b32d2e;"><?php esc_html_e('Digital Price List is disabled. Tick "Enable" at the top and save changes before generating — nothing is generated or published while it is off.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></strong></p>
            <?php endif; ?>
            <p>
                <strong><?php esc_html_e('Public Price List:', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></strong>
                <a href="<?php echo esc_url(Helper::get_public_url()); ?>" target="_blank"><?php echo esc_html(Helper::get_public_url()); ?></a><br>
                <strong><?php esc_html_e('Current CSV:', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></strong>
                <a href="<?php echo esc_url(Helper::get_current_url()); ?>" target="_blank"><?php echo esc_html(Helper::get_current_url()); ?></a><br>
                <strong><?php esc_html_e('Resolved Store / Outlet:', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></strong>
                <?php echo esc_html($resolved !== '' ? $resolved : __('not set', 'garanlabs-eu-guarantee-notice-durability-labels')); ?><br>
                <strong><?php esc_html_e('Last Generated:', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></strong>
                <?php echo esc_html($status['generated_at'] !== '' ? $status['generated_at'] : __('never', 'garanlabs-eu-guarantee-notice-durability-labels')); ?><br>
                <?php if ($status['rows']): ?>
                    <strong><?php esc_html_e('Rows:', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></strong>
                    <?php echo esc_html(number_format_i18n($status['rows'])); ?><br>
                <?php endif; ?>
                <strong><?php esc_html_e('Next Scheduled Generation:', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></strong>
                <?php echo esc_html($next ? get_date_from_gmt(gmdate('Y-m-d H:i:s', $next), 'Y-m-d H:i') : __('not scheduled', 'garanlabs-eu-guarantee-notice-durability-labels')); ?><br>
                <?php foreach (Helper::stores() as $store_id => $physical_store):
                    $store_status = $status['stores'][$store_id] ?? null; ?>
                    <strong><?php echo esc_html(sprintf(
                        /* translators: 1: store premises label, e.g. P-01; 2: store address. */
                        __('Store %1$s (%2$s):', 'garanlabs-eu-guarantee-notice-durability-labels'),
                        $physical_store['premises_label'],
                        $physical_store['address']
                    )); ?></strong>
                    <?php if (!Helper::store_is_complete($physical_store)): ?>
                        <?php esc_html_e('incomplete — fill in address, premises label and store number', 'garanlabs-eu-guarantee-notice-durability-labels'); ?><br>
                    <?php elseif ($physical_store['source'] === 'external'): ?>
                        <?php self::render_external_status($store_id); ?>
                    <?php elseif (Helper::covered_by_all_stores_list($physical_store)): ?>
                        <?php esc_html_e('covered by the price list for all stores', 'garanlabs-eu-guarantee-notice-durability-labels'); ?><br>
                    <?php elseif ($store_status): ?>
                        <a href="<?php echo esc_url(Helper::get_store_current_url($store_id)); ?>" target="_blank"><?php echo esc_html($store_status['filename']); ?></a>
                        <?php /* translators: %s: number of rows. */ echo esc_html(sprintf(__('(%s rows)', 'garanlabs-eu-guarantee-notice-durability-labels'), number_format_i18n($store_status['rows']))); ?><br>
                    <?php else: ?>
                        <?php esc_html_e('not generated yet', 'garanlabs-eu-guarantee-notice-durability-labels'); ?><br>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php $progress = Generator::progress(); ?>
                <?php if ($progress): ?>
                    <strong><?php esc_html_e('Generation Status:', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></strong>
                    <?php
                    echo esc_html($progress['total']
                        ? sprintf(
                            /* translators: 1: products processed so far; 2: total products. */
                            __('In progress: %1$s / %2$s products', 'garanlabs-eu-guarantee-notice-durability-labels'),
                            number_format_i18n(min($progress['processed'], $progress['total'])),
                            number_format_i18n($progress['total'])
                        )
                        : __('In progress…', 'garanlabs-eu-guarantee-notice-durability-labels'));
                    if ($progress['started']) {
                        echo ' ' . esc_html(sprintf(
                            /* translators: 1: time the run started; 2: time of its last activity (both HH:MM:SS). */
                            __('(started %1$s, last activity %2$s; updates automatically)', 'garanlabs-eu-guarantee-notice-durability-labels'),
                            wp_date('H:i:s', $progress['started']),
                            wp_date('H:i:s', $progress['last_activity'] ?: $progress['started'])
                        ));
                    }
                    ?><br>
                <?php endif; ?>
                <?php if ($status['error'] !== ''): ?>
                    <strong style="color:#b32d2e;"><?php esc_html_e('Last Error:', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></strong>
                    <?php echo esc_html($status['error']); ?><br>
                <?php endif; ?>
            </p>
            <p>
                <?php // No referer field: Generate Now returns to the page via the settings form's own one (or the Referer header), even after an AJAX refresh. ?>
                <?php wp_nonce_field(self::nonce_action(), 'nonce', false); ?>
                <button class="button" type="submit" name="action" value="<?php echo esc_attr(self::GENERATE_ACTION); ?>"
                    formaction="<?php echo esc_url(admin_url('admin-post.php')); ?>" formmethod="post">
                    <?php esc_html_e('Generate Now', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>
                </button>
            </p>
        </div>
<?php
    }

    public function generate_now()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You cannot manage the Digital Price List.', 'garanlabs-eu-guarantee-notice-durability-labels'), '', ['response' => 403]);
        }
        check_admin_referer(self::nonce_action(), 'nonce');

        External_Source::check_all();
        $result   = Generator::generate();
        $redirect = wp_get_referer() ?: admin_url();
        wp_safe_redirect(add_query_arg('eggl_ph_dpl_generated', is_wp_error($result) ? '0' : '1', $redirect));
        exit;
    }

    /** One external store's line in the Status box: published file, when and how it arrived, overdue and rejection warnings. */
    private static function render_external_status($store_id)
    {
        $ext = External_Source::status($store_id);
        if ($ext['filename'] !== '') {
            echo '<a href="' . esc_url(Helper::get_store_current_url($store_id)) . '" target="_blank">' . esc_html($ext['filename']) . '</a> ';
            echo esc_html(sprintf(
                /* translators: 1: number of rows; 2: date and time received; 3: how it arrived, e.g. REST API. */
                __('(%1$s rows, external file received %2$s via %3$s)', 'garanlabs-eu-guarantee-notice-durability-labels'),
                number_format_i18n($ext['rows']),
                wp_date('Y-m-d H:i', (int) $ext['received_at']),
                External_Source::origin_label($ext['origin'])
            ));
        } else {
            esc_html_e('external file — none received yet', 'garanlabs-eu-guarantee-notice-durability-labels');
        }
        echo '<br>';
        if (External_Source::is_overdue($store_id)) {
            echo '<span style="color:#b32d2e;">' . esc_html(sprintf(
                /* translators: %s: expected time HH:MM. */
                __('Warning: no new price list received today (expected by %s). The previous list is still published.', 'garanlabs-eu-guarantee-notice-durability-labels'),
                Helper::settings()['external_deadline']
            )) . '</span><br>';
        }
        if ($ext['error'] !== '') {
            echo '<span style="color:#b32d2e;">' . esc_html(sprintf(
                /* translators: 1: date and time; 2: reason. */
                __('Last file rejected %1$s: %2$s', 'garanlabs-eu-guarantee-notice-durability-labels'),
                wp_date('Y-m-d H:i', (int) $ext['error_at']),
                $ext['error']
            )) . '</span><br>';
        }
    }

    /** Delivery details for a store whose price list comes from an external (ERP) file. */
    private static function render_external_panel($name, $store)
    {
?>
            <div class="eggl-ph-dpl-store__external">
                <label class="eggl-ph-dpl-store__wide">
                    <span><?php esc_html_e('Remote URL (optional)', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></span>
                    <input type="url" class="regular-text" name="<?php echo esc_attr($name . '[remote_url]'); ?>" value="<?php echo esc_attr($store['remote_url']); ?>" placeholder="https://erp.example.com/export/store-104.csv">
                    <small><?php esc_html_e('Fetched every hour. The same file is published only once a day; a changed file is published straight away. The plugin asks for a fresh copy, but if the file sits behind Cloudflare or another CDN, also exclude its path from caching.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></small>
                </label>
                <label class="eggl-ph-dpl-store__wide">
                    <span><?php esc_html_e('Required Columns (optional)', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></span>
                    <input type="text" class="regular-text" name="<?php echo esc_attr($name . '[required_columns]'); ?>" value="<?php echo esc_attr($store['required_columns']); ?>" placeholder="Naziv usluge, MPC, Naziv posebnog oblika prodaje, Bazna cijena">
                    <small><?php esc_html_e('Comma-separated column names this store\'s file must contain. Replaces External File Required Columns for this store; leave empty to use that setting.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></small>
                </label>
                <?php if ($store['id'] === '' || !Helper::store_is_complete($store) || $store['source'] !== 'external'): ?>
                    <p class="description"><?php esc_html_e('Save changes to get this store\'s upload folder, API address and manual upload.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
                <?php else: ?>
                    <dl class="eggl-ph-dpl-store__delivery">
                        <dt><?php esc_html_e('Upload folder (SFTP)', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></dt>
                        <dd><code><?php echo esc_html(External_Source::incoming_dir($store['id'])); ?></code><br>
                            <small><?php esc_html_e('Checked every hour and on Generate Now. The newest .csv is published and removed from the folder; rejected files move to the rejected subfolder.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></small></dd>
                        <dt><?php esc_html_e('REST API (POST)', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></dt>
                        <dd><code><?php echo esc_html(External_Source::rest_url($store['id'])); ?></code><br>
                            <small><?php esc_html_e('Authenticate with an application password (Users → Profile) of a user who can manage WooCommerce. Send the CSV as the request body with Content-Type: text/csv, or as a multipart field named "file".', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></small></dd>
                        <dt><?php esc_html_e('Manual upload', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></dt>
                        <dd>
                            <input type="file" accept=".csv,text/csv" name="eggl_ph_dpl_upload[<?php echo esc_attr($store['id']); ?>]">
                            <button class="button" type="submit" name="action" value="<?php echo esc_attr(External_Source::UPLOAD_ACTION); ?>"
                                formaction="<?php echo esc_url(admin_url('admin-post.php')); ?>" formmethod="post" formenctype="multipart/form-data" formnovalidate>
                                <?php esc_html_e('Upload and publish', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>
                            </button>
                        </dd>
                    </dl>
                <?php endif; ?>
            </div>
<?php
    }

    /** A product taxonomy's terms in hierarchy order with their depth, for the store pickers. */
    private static function term_options($taxonomy)
    {
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name']);
        if (is_wp_error($terms)) {
            return [];
        }
        $children = [];
        foreach ($terms as $term) {
            $children[(int) $term->parent][] = $term;
        }
        $ordered = [];
        // "Parent › Child" paths, so a selected tag in the multiselect still shows where the category sits.
        $walk = static function ($parent, $depth, $prefix) use (&$walk, &$children, &$ordered) {
            foreach ($children[$parent] ?? [] as $term) {
                $path = $prefix . $term->name;
                $ordered[] = ['id' => (int) $term->term_id, 'name' => $path, 'depth' => $depth];
                $walk((int) $term->term_id, $depth + 1, $path . ' › ');
            }
        };
        $walk(0, 0, '');
        return $ordered;
    }

    /** One store's editor; $index is a number for saved stores or the "__i__" placeholder in the add-store template. */
    private static function render_store($index, $store, $options)
    {
        $name = Helper::SETTINGS . '[stores][' . $index . ']';
        $text_fields = [
            'outlet_type'    => [__('Store Type', 'garanlabs-eu-guarantee-notice-durability-labels'), Helper::DEFAULT_STORE_TYPE],
            'address'        => [__('Store Address', 'garanlabs-eu-guarantee-notice-durability-labels'), 'Ilica 150 Zagreb'],
            'premises_label' => [__('Business Premises Label', 'garanlabs-eu-guarantee-notice-durability-labels'), 'P-01'],
            'store_number'   => [__('Store Number', 'garanlabs-eu-guarantee-notice-durability-labels'), '104'],
        ];
        $sources = [
            'generated' => __('Generated from webshop products', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'external'  => __('External file (e.g. from your ERP)', 'garanlabs-eu-guarantee-notice-durability-labels'),
        ];
        $scopes = [
            'all'     => __('All price list products', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'include' => __('Only the selected categories, tags and products', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'exclude' => __('All products except the selected categories, tags and products', 'garanlabs-eu-guarantee-notice-durability-labels'),
        ];
?>
        <fieldset class="eggl-ph-dpl-store">
            <input type="hidden" name="<?php echo esc_attr($name . '[id]'); ?>" value="<?php echo esc_attr($store['id']); ?>">
            <div class="eggl-ph-dpl-store__grid">
                <?php foreach ($text_fields as $key => [$label, $placeholder]): ?>
                    <label>
                        <span><?php echo esc_html($label); ?></span>
                        <input type="text" class="regular-text" name="<?php echo esc_attr($name . '[' . $key . ']'); ?>" value="<?php echo esc_attr($store[$key]); ?>" placeholder="<?php echo esc_attr($placeholder); ?>">
                    </label>
                <?php endforeach; ?>
                <label>
                    <span><?php esc_html_e('Price List Type', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></span>
                    <select name="<?php echo esc_attr($name . '[price_list_type]'); ?>">
                        <option value="roba" <?php selected($store['price_list_type'], 'roba'); ?>><?php esc_html_e('Goods (roba)', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></option>
                        <option value="usluge" <?php selected($store['price_list_type'], 'usluge'); ?>><?php esc_html_e('Services (usluge)', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></option>
                    </select>
                </label>
                <div class="eggl-ph-dpl-store__scope-field" role="radiogroup" aria-label="<?php esc_attr_e('Price List Source', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>">
                    <span><?php esc_html_e('Price List Source', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></span>
                    <div class="eggl-ph-dpl-store__scope-options">
                        <?php foreach ($sources as $value => $label): ?>
                            <label class="eggl-ph-dpl-store__scope-option">
                                <input type="radio" class="eggl-ph-dpl-store__source" name="<?php echo esc_attr($name . '[source]'); ?>" value="<?php echo esc_attr($value); ?>" <?php checked($store['source'], $value); ?>>
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="eggl-ph-dpl-store__scope-field eggl-ph-dpl-store__generated-only" role="radiogroup" aria-label="<?php esc_attr_e('Product Selection', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>">
                    <span><?php esc_html_e('Product Selection', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></span>
                    <div class="eggl-ph-dpl-store__scope-options">
                        <?php foreach ($scopes as $value => $label): ?>
                            <label class="eggl-ph-dpl-store__scope-option">
                                <input type="radio" class="eggl-ph-dpl-store__scope" name="<?php echo esc_attr($name . '[scope]'); ?>" value="<?php echo esc_attr($value); ?>" <?php checked($store['scope'], $value); ?>>
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php // Divs, not labels: a <label> around a selectWoo box retargets clicks (e.g. on a tag's ×) to the hidden select. ?>
            <?php self::render_external_panel($name, $store); ?>
            <div class="eggl-ph-dpl-store__filters eggl-ph-dpl-store__generated-only">
                <div class="eggl-ph-dpl-store__field">
                    <span><?php esc_html_e('Categories', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></span>
                    <select class="wc-enhanced-select" multiple name="<?php echo esc_attr($name . '[categories][]'); ?>" data-placeholder="<?php esc_attr_e('Search categories…', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>">
                        <?php foreach ($options['categories'] as $term): ?>
                            <option value="<?php echo esc_attr($term['id']); ?>" <?php selected(in_array($term['id'], $store['categories'], true)); ?>><?php echo esc_html($term['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small><?php esc_html_e('Subcategories are included automatically.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></small>
                </div>
                <div class="eggl-ph-dpl-store__field">
                    <span><?php esc_html_e('Tags', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></span>
                    <select class="wc-enhanced-select" multiple name="<?php echo esc_attr($name . '[tags][]'); ?>" data-placeholder="<?php esc_attr_e('Search tags…', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>">
                        <?php foreach ($options['tags'] as $term): ?>
                            <option value="<?php echo esc_attr($term['id']); ?>" <?php selected(in_array($term['id'], $store['tags'], true)); ?>><?php echo esc_html($term['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="eggl-ph-dpl-store__field">
                    <span><?php esc_html_e('Products', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></span>
                    <select class="wc-product-search" multiple name="<?php echo esc_attr($name . '[products][]'); ?>" data-action="woocommerce_json_search_products_and_variations" data-placeholder="<?php esc_attr_e('Search for a product…', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>">
                        <?php foreach ($store['products'] as $product_id):
                            $product = wc_get_product($product_id);
                            if (!$product) continue; ?>
                            <option value="<?php echo esc_attr($product_id); ?>" selected><?php echo esc_html(wp_strip_all_tags($product->get_formatted_name())); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small><?php esc_html_e('A variable product covers all of its variations; pick single variations to narrow it down.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></small>
                </div>
            </div>
            <p class="eggl-ph-dpl-store__footer">
                <?php if ($store['id'] !== ''): ?>
                    <span class="description"><?php esc_html_e('Current CSV:', 'garanlabs-eu-guarantee-notice-durability-labels'); ?> <code><?php echo esc_html(Helper::get_store_current_url($store['id'])); ?></code></span>
                <?php endif; ?>
                <button type="button" class="button-link button-link-delete eggl-ph-dpl-store__remove"><?php esc_html_e('Remove store', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></button>
            </p>
        </fieldset>
<?php
    }

    /**
     * Repeatable physical-store editor (the settings API has no repeater field). Rows post as
     * eggl_ph_digital_price_list[stores][n][...] and are normalized by Helper::normalize_stores().
     */
    public static function render_stores()
    {
        $options = ['categories' => self::term_options('product_cat'), 'tags' => self::term_options('product_tag')];
        $blank = ['id' => '', 'outlet_type' => Helper::DEFAULT_STORE_TYPE, 'address' => '', 'premises_label' => '', 'store_number' => '', 'scope' => 'all', 'categories' => [], 'tags' => [], 'products' => [], 'source' => 'generated', 'remote_url' => '', 'price_list_type' => 'roba', 'required_columns' => ''];
        $stores = array_values(Helper::stores());
?>
        <style>
            .eggl-ph-dpl-stores-row .eggl-row__control{width:100%}
            .eggl-ph-dpl-store{border:1px solid #dcdcde;border-radius:4px;padding:12px 16px;margin:0 0 12px;background:#fff;min-width:0;box-sizing:border-box}
            .eggl-ph-dpl-store__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(220px,100%),1fr));gap:12px 16px}
            .eggl-ph-dpl-store__grid label{display:flex;flex-direction:column;gap:4px}
            .eggl-ph-dpl-store__grid label > span{font-weight:600}
            .eggl-ph-dpl-store__grid input[type="text"]{width:100%;max-width:none}
            .eggl-ph-dpl-store__filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(260px,100%),1fr));gap:12px 16px;margin-top:12px}
            .eggl-ph-dpl-store__field{display:flex;flex-direction:column;gap:4px;min-width:0}
            .eggl-ph-dpl-store__field > span{font-weight:600}
            .eggl-ph-dpl-store__field select{width:100%}
            .eggl-ph-dpl-store__field .select2-container{width:100% !important}
            /* The page styles every text input/textarea with a border and padding; inside a multiselect
               that turns selectWoo's inline search box into a second bordered box. Reset it. */
            .eggl-ph-dpl-store .select2-container .select2-search--inline .select2-search__field{
                border:0 !important;box-shadow:none !important;outline:0;background:transparent;
                padding:0 !important;margin:6px 0 0 6px;min-height:0;height:22px;line-height:22px;
                border-radius:0;font-size:13px;width:auto;max-width:100%}
            .eggl-ph-dpl-store .select2-container--default .select2-selection--multiple{
                border:1px solid var(--eggl-field-border,#d7dce2);border-radius:8px;min-height:40px;padding:0 4px 4px}
            .eggl-ph-dpl-store .select2-container--default.select2-container--focus .select2-selection--multiple{
                border-color:var(--eggl-accent,#0f766e);box-shadow:0 0 0 3px rgba(15,118,110,.15)}
            .eggl-ph-dpl-store .select2-selection__choice{margin-top:5px !important}
            .eggl-ph-dpl-store__scope-field{grid-column:1 / -1;display:flex;flex-direction:column;gap:6px}
            .eggl-ph-dpl-store__scope-field > span{font-weight:600}
            .eggl-ph-dpl-store__scope-options{display:flex;flex-wrap:wrap;gap:8px 24px}
            .eggl-ph-dpl-store__grid label.eggl-ph-dpl-store__scope-option{display:inline-flex;flex-direction:row;align-items:center;gap:6px;cursor:pointer}
            .eggl-ph-dpl-store__grid .eggl-ph-dpl-store__scope-option input[type="radio"]{width:16px;height:16px;min-width:16px;margin:0;padding:0;flex:none}
            .eggl-ph-dpl-store__footer .description{flex:1 1 auto;min-width:0;max-width:none}
            .eggl-ph-dpl-store__footer code{overflow-wrap:anywhere;font-size:12px}
            .eggl-ph-dpl-store__footer .button-link-delete{flex:none}
            @media (max-width:600px){
                .eggl-ph-dpl-store{padding:12px}
                .eggl-ph-dpl-store__footer{flex-wrap:wrap}
            }
            .eggl-ph-dpl-store__filters.is-disabled{opacity:.5}
            .eggl-ph-dpl-store.is-external .eggl-ph-dpl-store__generated-only,
            .eggl-ph-dpl-store:not(.is-external) .eggl-ph-dpl-store__external{display:none}
            .eggl-ph-dpl-store__external{margin-top:12px}
            .eggl-ph-dpl-store__external .eggl-ph-dpl-store__wide{display:flex;flex-direction:column;gap:4px;max-width:640px}
            .eggl-ph-dpl-store__external .eggl-ph-dpl-store__wide > span{font-weight:600}
            .eggl-ph-dpl-store__external input[type="url"]{width:100%;max-width:none}
            .eggl-ph-dpl-store__delivery{margin:12px 0 0}
            .eggl-ph-dpl-store__delivery dt{font-weight:600;margin-top:8px}
            .eggl-ph-dpl-store__delivery dd{margin:2px 0 0}
            .eggl-ph-dpl-store__delivery code{overflow-wrap:anywhere;font-size:12px}
            .eggl-ph-dpl-store__footer{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:12px 0 0}
            .eggl-ph-dpl-store__footer .button-link-delete{margin-left:auto}
        </style>
        <div class="eggl-ph-dpl-stores" data-next-index="<?php echo esc_attr(count($stores)); ?>">
            <div class="eggl-ph-dpl-stores__list">
                <?php foreach ($stores as $index => $store) self::render_store($index, $store, $options); ?>
            </div>
            <?php if (!$stores): ?>
                <p class="description eggl-ph-dpl-stores__empty"><?php esc_html_e('No physical stores yet. Only the webshop price list is generated.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
            <?php endif; ?>
            <template class="eggl-ph-dpl-stores__template"><?php self::render_store('__i__', $blank, $options); ?></template>
            <button type="button" class="button eggl-ph-dpl-stores__add"><?php esc_html_e('Add Physical Store', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></button>
            <p class="description"><?php esc_html_e('Save changes, then use Generate Now (or wait for the next scheduled run) to create the store price lists.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
        </div>
        <script>
        (function() {
            var root = document.currentScript.previousElementSibling;
            var list = root.querySelector('.eggl-ph-dpl-stores__list');
            var syncScope = function(store) {
                var scope = store.querySelector('.eggl-ph-dpl-store__scope:checked') || {value: 'all'};
                // Greyed out only: a disabled select wouldn't submit, losing the saved selection when switching back.
                store.querySelector('.eggl-ph-dpl-store__filters').classList.toggle('is-disabled', scope.value === 'all');
            };
            var syncSource = function(store) {
                var source = store.querySelector('.eggl-ph-dpl-store__source:checked') || {value: 'generated'};
                store.classList.toggle('is-external', source.value === 'external');
            };
            root.addEventListener('change', function(e) {
                if (e.target.classList.contains('eggl-ph-dpl-store__scope')) syncScope(e.target.closest('.eggl-ph-dpl-store'));
                if (e.target.classList.contains('eggl-ph-dpl-store__source')) syncSource(e.target.closest('.eggl-ph-dpl-store'));
            });
            root.addEventListener('click', function(e) {
                if (e.target.classList.contains('eggl-ph-dpl-store__remove')) {
                    e.target.closest('.eggl-ph-dpl-store').remove();
                } else if (e.target.classList.contains('eggl-ph-dpl-stores__add')) {
                    var index = parseInt(root.dataset.nextIndex, 10) || 0;
                    root.dataset.nextIndex = index + 1;
                    var html = root.querySelector('.eggl-ph-dpl-stores__template').innerHTML.split('__i__').join(String(index));
                    list.insertAdjacentHTML('beforeend', html);
                    var empty = root.querySelector('.eggl-ph-dpl-stores__empty');
                    if (empty) empty.remove();
                    var added = list.lastElementChild;
                    syncScope(added);
                    syncSource(added);
                    // WooCommerce's enhanced select only initialises selects present at load; re-run it for the new store.
                    if (window.jQuery) window.jQuery(document.body).trigger('wc-enhanced-select-init');
                    added.querySelector('input[type="text"]').focus();
                }
            });
            list.querySelectorAll('.eggl-ph-dpl-store').forEach(function(store) { syncScope(store); syncSource(store); });
        })();
        </script>
<?php
    }

    public static function settings_fields($fields)
    {
        $fields[Helper::SETTINGS] = [
            [
                'name'    => 'enable',
                'type'    => 'checkbox',
                'label'   => __('Enable', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('enable'),
                'desc'    => __('Publishes a public, machine-readable CSV price list.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'enable_schedule',
                'type'    => 'checkbox',
                'label'   => __('Automatic Generation', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('enable_schedule'),
                'desc'    => __('Regenerate the price list automatically every day.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'        => 'schedule_time',
                'type'        => 'text',
                'label'       => __('Scheduled Generation Time', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default'     => Helper::defaults('schedule_time'),
                'placeholder' => 'HH:MM',
                'desc'        => __('Store-timezone time (24h HH:MM) for the daily automatic generation.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'        => 'extra_times',
                'type'        => 'text',
                'label'       => __('Additional Generation Times', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default'     => Helper::defaults('extra_times'),
                'placeholder' => '10:30, 14:00',
                'desc'        => __('Optional extra daily runs (24h HH:MM, comma-separated). Useful when prices are updated by an ERP sync that bypasses WooCommerce, e.g. 10:30 to publish changes by 11:00.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'regenerate_on_change',
                'type'    => 'checkbox',
                'label'   => __('Regenerate After Price Changes', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('regenerate_on_change'),
                'desc'    => __('Rebuild the price lists about 5 minutes after a product price, sale or code changes, so the published list is current the same day. Several changes close together trigger one rebuild.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'  => 'info-store',
                'type'  => 'info',
                'class' => 'info',
                'label' => __('Store / Outlet', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'desc'  => '',
            ],
            [
                'name'    => 'store_name',
                'type'    => 'text',
                'label'   => __('Store / Outlet Name', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('store_name'),
                /* translators: %s: the site title used automatically when this is left empty. */
                'desc'    => sprintf(__('Leave empty to use the site title (%s).', 'garanlabs-eu-guarantee-notice-durability-labels'), get_bloginfo('name'))
            ],
            [
                'name'    => 'store_address',
                'type'    => 'text',
                'label'   => __('Store / Outlet Address', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('store_address'),
                'desc'    => __('Leave empty to use the WooCommerce store address setting.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'outlet_type',
                'type'    => 'text',
                'label'   => __('Outlet Type', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('outlet_type'),
                'desc'    => __('Label for this outlet, e.g. webshop, prodavaonica, servis. Defaults to webshop.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'outlet_id',
                'type'    => 'text',
                'label'   => __('Outlet Identifier', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('outlet_id'),
                'desc'    => __('Short code or number identifying this outlet, used when you run more than one price list. Leave empty to default to 1.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'oib',
                'type'    => 'text',
                'label'   => __('Company OIB', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('oib'),
                'desc'    => __('Your company\'s tax number, available as {oib} in the file name patterns.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'price_list_type',
                'type'    => 'select',
                'label'   => __('Price List Type', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('price_list_type'),
                'options' => [
                    'roba'   => __('Goods (roba)', 'garanlabs-eu-guarantee-notice-durability-labels'),
                    'usluge' => __('Services (usluge)', 'garanlabs-eu-guarantee-notice-durability-labels'),
                ],
                'desc'    => __('For the webshop price list, available as {price_list_type} in the file name pattern. Each physical store has its own setting.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'  => 'info-csv-format',
                'type'  => 'info',
                'class' => 'info',
                'label' => __('CSV Format', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'desc'  => __('Applies to the webshop price list and the generated physical store price lists. The defaults follow the Croatian Pravilnik o načinu objave cjenika proizvoda (in force from 17 November 2026).', 'garanlabs-eu-guarantee-notice-durability-labels'),
            ],
            [
                'name'    => 'csv_delimiter',
                'type'    => 'select',
                'label'   => __('Column Separator', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('csv_delimiter'),
                'options' => [
                    ';' => __('Semicolon (;)', 'garanlabs-eu-guarantee-notice-durability-labels'),
                    ',' => __('Comma (,)', 'garanlabs-eu-guarantee-notice-durability-labels'),
                ],
                'desc'    => '',
            ],
            [
                'name'    => 'csv_decimal',
                'type'    => 'select',
                'label'   => __('Decimal Separator', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('csv_decimal'),
                'options' => [
                    '.' => __('Point (89.99)', 'garanlabs-eu-guarantee-notice-durability-labels'),
                    ',' => __('Comma (89,99)', 'garanlabs-eu-guarantee-notice-durability-labels'),
                ],
                'desc'    => __('For prices, unit prices and net quantities in the generated CSV files. Match it to your external (ERP) files so all price lists of your stores use the same format. External files are published as received.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            ],
            [
                'name'    => 'csv_bom',
                'type'    => 'checkbox',
                'label'   => __('UTF-8 BOM', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('csv_bom'),
                'desc'    => __('Start the file with a byte order mark so Excel shows Croatian characters correctly. Turn off if automated readers see an extra character before the first column name.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'csv_columns',
                'type'    => 'textarea',
                'label'   => __('Columns', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::csv_columns_text(Helper::default_csv_columns()),
                'desc'    => __('One column per line, in file order: field = column header. Fields: product_name, product_code, brand, net_quantity, unit, retail_price, special_sale_flag (DA/NE), special_sale_name, unit_price, barcode, ean, base_price, availability. Clear the field and save to restore the default.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'ean_meta_key',
                'type'    => 'text',
                'label'   => __('EAN Meta Key', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('ean_meta_key'),
                'desc'    => __('Optional product meta key holding the EAN. Leave empty to use the WooCommerce GTIN/EAN field when it is an 8, 12, 13 or 14 digit code. The Barkod column always shows the WooCommerce field.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'  => 'info-unit-price',
                'type'  => 'info',
                'class' => 'info',
                'label' => __('Unit Price', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'desc'  => __('Fills the unit and unit_price columns (price per unit of measure). A product\'s own Price List Unit and Unit Quantity fields always come first; they can also be edited in bulk and imported by CSV in the Price Compliance Manager.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            ],
            [
                'name'    => 'unit_source',
                'type'    => 'select',
                'label'   => __('Unit Price Source', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('unit_source'),
                'options' => [
                    'auto' => __('Automatic (Price Per Unit PRO, then custom meta fields)', 'garanlabs-eu-guarantee-notice-durability-labels'),
                    'ppu'  => __('Price Per Unit PRO', 'garanlabs-eu-guarantee-notice-durability-labels'),
                    'meta' => __('Custom meta fields', 'garanlabs-eu-guarantee-notice-durability-labels'),
                    'none' => __('Product fields only', 'garanlabs-eu-guarantee-notice-durability-labels'),
                ],
                'desc'    => __('Price Per Unit PRO: uses each product\'s custom number of units and its custom unit name or recalculation suffix (e.g. /l becomes l), so the values match the product page.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'unit_meta_unit',
                'type'    => 'text',
                'label'   => __('Unit Meta Key', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('unit_meta_unit'),
                'placeholder' => '_my_unit',
                'desc'    => __('Custom meta fields: product meta key holding the unit of measure, e.g. l or kg. A leading / is removed.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'unit_meta_quantity',
                'type'    => 'text',
                'label'   => __('Unit Quantity Meta Key', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('unit_meta_quantity'),
                'placeholder' => '_my_unit_quantity',
                'desc'    => __('Custom meta fields: product meta key holding the quantity. Unit price = price ÷ quantity × ratio.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'unit_ratio',
                'type'    => 'text',
                'label'   => __('Unit Ratio', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('unit_ratio'),
                'desc'    => __('Custom meta fields: multiplier for the unit price, e.g. 100 for a price per 100 g when the quantity is in grams. Defaults to 1.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'unit_meta_price',
                'type'    => 'text',
                'label'   => __('Unit Price Meta Key', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('unit_meta_price'),
                'desc'    => __('Custom meta fields, optional: product meta key holding a ready-made unit price. When set and filled, it is used instead of the calculation.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'unit_weight_fallback',
                'type'    => 'checkbox',
                'label'   => __('Weight Fallback', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('unit_weight_fallback'),
                'desc'    => __('When no other source has a value, calculate the unit price from the product weight and the shop weight unit. Leave off if your product weights are shipping weights.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'        => 'unit_fallback_meta',
                'type'        => 'text',
                'label'       => __('Fallback Unit Meta Key', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default'     => Helper::defaults('unit_fallback_meta'),
                'placeholder' => 'woodmart_price_unit_of_measure',
                'desc'        => __('Optional. For products whose unit-price source above gives no unit (e.g. sold per piece, pair or pack): product meta key to fill the unit column from. Only the unit column is filled; no unit price is calculated.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'        => 'unit_fallback_default',
                'type'        => 'text',
                'label'       => __('Default Unit', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default'     => Helper::defaults('unit_fallback_default'),
                'placeholder' => 'kom',
                'desc'        => __('Optional. Unit for products that still have none after the fallback meta key, e.g. kom. Leave empty to keep the unit column empty for them.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'  => 'info-stores',
                'type'  => 'info',
                'class' => 'info',
                'label' => __('Physical Stores', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'desc'  => __('Each physical store gets its own copy of the price list on every generation, named in the required format, e.g. prodavaonica_Ilica 150 Zagreb_P-01_104_20261001_0745.csv.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            ],
            [
                'name'     => 'stores',
                'type'     => 'html',
                'class'    => 'eggl-ph-dpl-stores-row',
                'label'    => '',
                'callback' => [self::class, 'render_stores']
            ],
            [
                'name'    => 'external_required_columns',
                'type'    => 'text',
                'label'   => __('External File Required Columns', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('external_required_columns'),
                'desc'    => __('For stores with an external price list: comma-separated column names every file must contain (not case-sensitive). Leave empty to check only the file structure.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'        => 'external_deadline',
                'type'        => 'text',
                'label'       => __('External File Expected By', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default'     => Helper::defaults('external_deadline'),
                'placeholder' => 'HH:MM',
                'desc'        => __('Store-timezone time (24h HH:MM). If no new file has arrived for a store by then, the Status box shows a warning.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'        => 'external_alert_email',
                'type'        => 'text',
                'label'       => __('External File Alert E-mail', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default'     => Helper::defaults('external_alert_email'),
                'placeholder' => get_option('admin_email'),
                'desc'        => __('E-mail address(es), comma-separated, to notify when a file is late or rejected. Leave empty for no e-mails.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'  => 'info-advanced',
                'type'  => 'info',
                'class' => 'info',
                'label' => __('Advanced', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'desc'  => __('Technical settings — the defaults work for most stores.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'path',
                'type'    => 'text',
                'label'   => __('Price List Path', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('path'),
                'desc'    => __('Public URL path, e.g. price-list gives /price-list/current.csv.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'filename_pattern',
                'type'    => 'text',
                'label'   => __('File Name Pattern', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('filename_pattern'),
                'desc'    => __('Placeholders: {store_name} {outlet_type} {address} {city} {postcode} {outlet_id} {oib} {price_list_type} {sequence} {timestamp} (YYYYMMDD-HHMMSS) {timestamp_hr} (YYYYMMDD_HHMM) {za_sva} (ZA_SVA_PRODAJNA_MJESTA). Leave empty for the automatic default.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'all_stores_list',
                'type'    => 'checkbox',
                'label'   => __('One Price List for All Stores', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('all_stores_list'),
                'desc'    => __('Use the webshop price list for all stores. Its default file name becomes {oib}_ZA_SVA_PRODAJNA_MJESTA_{price_list_type}_{sequence}_{timestamp_hr}, and physical stores no longer get their own generated copy. Stores fed by an external (ERP) file keep their own list.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'store_filename_pattern',
                'type'    => 'text',
                'label'   => __('Physical Store File Name Pattern', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('store_filename_pattern'),
                'desc'    => __('Placeholders: {oib} {outlet_type} {address} {premises_label} {store_number} {price_list_type} {sequence} {timestamp_hr} (YYYYMMDD_HHMM) {timestamp} {date} (dd.mm.yyyy) {time} (HH-MM) {store_name}. Values are used exactly as entered. Leave empty for the automatic default.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'    => 'filename_underscores',
                'type'    => 'checkbox',
                'label'   => __('Underscores Instead of Spaces', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'default' => Helper::defaults('filename_underscores'),
                'desc'    => __('Replace spaces in physical store file names with underscores (e.g. Kolodvorska_116), so public links contain no %20. Applies to new files only.', 'garanlabs-eu-guarantee-notice-durability-labels')
            ],
            [
                'name'  => 'info-status',
                'type'  => 'info',
                'class' => 'info',
                'label' => __('Status', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'desc'  => '',
            ],
            [
                'name'     => 'status',
                'type'     => 'html',
                'class'    => 'eggl-ph-dpl-status',
                'label'    => '',
                'callback' => [self::class, 'render_status']
            ]
        ];
        return $fields;
    }
}
