<?php
if (!defined('ABSPATH')) exit;

/**
 * "Product Safety (GPSR)" tab in the WooCommerce Product data panel: manufacturer and
 * responsible-person contact details, warnings/safety information, and product documents
 * picked from the Media Library. Each section has its own on/off switch; the whole tab is
 * only shown on the storefront once "Show Product Safety tab" is on.
 */
class EGGL_GPSR_Admin {

    const PANEL_ID = 'eggl_gpsr_data';

    public function add_tab($tabs) {
        $tabs['eggl_gpsr'] = [
            'label'    => __('Product Safety (GPSR)', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'target'   => self::PANEL_ID,
            'class'    => ['eggl_gpsr_options_tab'],
            'priority' => 81,
        ];
        return $tabs;
    }

    public function enqueue_assets() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'product') return;

        wp_enqueue_media();
        wp_enqueue_style('eggl-gpsr-admin', EGGL_GPSR_Module::url('assets/css/admin.css'), [], EGGL_GPSR_Module::asset_version('assets/css/admin.css'));
        wp_enqueue_script('eggl-gpsr-admin', EGGL_GPSR_Module::url('assets/js/admin.js'), ['jquery'], EGGL_GPSR_Module::asset_version('assets/js/admin.js'), true);
        wp_add_inline_script('eggl-gpsr-admin', 'window.egglGpsr = ' . wp_json_encode([
            'mimeTypes' => EGGL_GPSR_Fields::allowed_mime_types(),
            'i18n'      => [
                'title'   => __('Select or upload a document', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'button'  => __('Use this file', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'invalid' => __('Please choose a PDF, JPG or PNG file.', 'garanlabs-eu-guarantee-notice-durability-labels'),
            ],
        ]) . ';', 'before');
    }

    public function render_panel() {
        global $post;
        $pid = $post ? (int) $post->ID : 0;
        ?>
        <div id="<?php echo esc_attr(self::PANEL_ID); ?>" class="panel woocommerce_options_panel eggl-gpsr-panel">
            <div class="options_group">
                <?php
                woocommerce_wp_checkbox([
                    'id'          => EGGL_GPSR_Fields::META_PREFIX . 'enabled',
                    'label'       => __('Show Product Safety tab', 'garanlabs-eu-guarantee-notice-durability-labels'),
                    'description' => __('Adds a "Product safety" tab on the product page with the sections switched on below (General Product Safety Regulation (EU) 2023/988).', 'garanlabs-eu-guarantee-notice-durability-labels'),
                    'value'       => EGGL_GPSR_Fields::is_on($pid, 'enabled') ? 'yes' : 'no',
                ]);
                ?>
            </div>

            <?php foreach (EGGL_GPSR_Fields::sections() as $section => $info) : ?>
                <?php $this->section_open($pid, $section . '_enabled', $info['title'], $info['description']); ?>
                <?php
                foreach (EGGL_GPSR_Fields::contact_fields() as $field => $label) {
                    $key = EGGL_GPSR_Fields::META_PREFIX . $section . '_' . $field;
                    woocommerce_wp_text_input([
                        'id'    => $key,
                        'label' => $label,
                        'type'  => $field === 'email' ? 'email' : ($field === 'website' ? 'url' : 'text'),
                        'value' => EGGL_GPSR_Fields::get($pid, $section . '_' . $field),
                    ]);
                }
                ?>
                <?php $this->section_close(); ?>
            <?php endforeach; ?>

            <?php $this->section_open($pid, 'safety_enabled', __('Warnings & safety information', 'garanlabs-eu-guarantee-notice-durability-labels'), __('Warnings or safety information that must be shown on or with the product, in a language consumers can easily understand.', 'garanlabs-eu-guarantee-notice-durability-labels')); ?>
                <?php
                woocommerce_wp_textarea_input([
                    'id'    => EGGL_GPSR_Fields::META_PREFIX . 'safety_info',
                    'label' => __('Safety information', 'garanlabs-eu-guarantee-notice-durability-labels'),
                    'value' => EGGL_GPSR_Fields::get($pid, 'safety_info'),
                    'rows'  => 5,
                ]);
                ?>
            <?php $this->section_close(); ?>

            <?php $this->section_open($pid, 'documents_enabled', __('Product documents', 'garanlabs-eu-guarantee-notice-durability-labels'), __('User guides, certificates and other documents that come with the product. PDF, JPG or PNG.', 'garanlabs-eu-guarantee-notice-durability-labels')); ?>
                <div class="eggl-gpsr-docs">
                    <?php foreach (EGGL_GPSR_Fields::documents() as $doc => $label) :
                        $key = EGGL_GPSR_Fields::META_PREFIX . 'doc_' . $doc;
                        $id  = EGGL_GPSR_Fields::document_id($pid, $doc);
                        ?>
                        <div class="eggl-gpsr-doc" data-eggl-gpsr-doc>
                            <span class="eggl-gpsr-doc__label"><?php echo esc_html($label); ?></span>
                            <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($id ?: ''); ?>" data-eggl-gpsr-doc-input>
                            <span class="eggl-gpsr-doc__file" data-eggl-gpsr-doc-name><?php echo $id ? esc_html(wp_basename((string) get_attached_file($id))) : ''; ?></span>
                            <button type="button" class="button" data-eggl-gpsr-doc-pick><?php esc_html_e('Choose file', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></button>
                            <button type="button" class="button-link button-link-delete" data-eggl-gpsr-doc-remove <?php echo $id ? '' : 'hidden'; ?>><?php esc_html_e('Remove', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php $this->section_close(); ?>

            <p class="eggl-gpsr-hint"><?php esc_html_e('Saved with this product’s Update/Publish button.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
        </div>
        <?php
    }

    /** Opens a collapsible section whose body shows while its switch ($toggle_key) is on. */
    private function section_open($pid, $toggle_key, $title, $description) {
        $name = EGGL_GPSR_Fields::META_PREFIX . $toggle_key;
        $on   = EGGL_GPSR_Fields::is_on($pid, $toggle_key);
        ?>
        <div class="options_group eggl-gpsr-section" data-eggl-gpsr-section>
            <div class="eggl-gpsr-section__head">
                <div>
                    <h4><?php echo esc_html($title); ?></h4>
                    <p class="description"><?php echo esc_html($description); ?></p>
                </div>
                <label class="eggl-gpsr-switch">
                    <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="yes" data-eggl-gpsr-toggle <?php checked($on); ?>>
                    <span class="screen-reader-text"><?php echo esc_html($title); ?></span>
                    <span class="eggl-gpsr-switch__slider" aria-hidden="true"></span>
                </label>
            </div>
            <div class="eggl-gpsr-section__body" data-eggl-gpsr-body <?php echo $on ? '' : 'hidden'; ?>>
        <?php
    }

    private function section_close() {
        echo '</div></div>';
    }

    public function save($post_id) {
        if (!isset($_POST['woocommerce_meta_nonce'])
            || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['woocommerce_meta_nonce'])), 'woocommerce_save_data')) {
            return;
        }
        $p = EGGL_GPSR_Fields::META_PREFIX;

        foreach (['enabled', 'safety_enabled', 'documents_enabled'] as $toggle) {
            update_post_meta($post_id, $p . $toggle, isset($_POST[$p . $toggle]) ? 'yes' : 'no');
        }

        foreach (array_keys(EGGL_GPSR_Fields::sections()) as $section) {
            update_post_meta($post_id, $p . $section . '_enabled', isset($_POST[$p . $section . '_enabled']) ? 'yes' : 'no');

            foreach (array_keys(EGGL_GPSR_Fields::contact_fields()) as $field) {
                $key = $p . $section . '_' . $field;
                $raw = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below per field type.
                if ($field === 'email') {
                    $value = sanitize_email($raw);
                } elseif ($field === 'website') {
                    $value = esc_url_raw(trim($raw));
                } else {
                    $value = sanitize_text_field($raw);
                }
                update_post_meta($post_id, $key, $value);
            }
        }

        $safety = isset($_POST[$p . 'safety_info']) ? sanitize_textarea_field(wp_unslash($_POST[$p . 'safety_info'])) : '';
        update_post_meta($post_id, $p . 'safety_info', $safety);

        foreach (array_keys(EGGL_GPSR_Fields::documents()) as $doc) {
            $key = $p . 'doc_' . $doc;
            $id  = isset($_POST[$key]) ? absint(wp_unslash($_POST[$key])) : 0;
            if ($id && (get_post_type($id) !== 'attachment' || !in_array(get_post_mime_type($id), EGGL_GPSR_Fields::allowed_mime_types(), true))) {
                $id = 0;
            }
            if ($id) {
                update_post_meta($post_id, $key, $id);
            } else {
                delete_post_meta($post_id, $key);
            }
        }
    }
}
