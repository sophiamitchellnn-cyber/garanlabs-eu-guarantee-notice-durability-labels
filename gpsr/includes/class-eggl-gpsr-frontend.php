<?php
if (!defined('ABSPATH')) exit;

/**
 * "Product safety" tab on the single product page, built from the product's GPSR data.
 * Only added when the product has the tab switched on and at least one section has content.
 */
class EGGL_GPSR_Frontend {

    public function add_tab($tabs) {
        global $product;
        if (!$product instanceof WC_Product || !$this->has_content($product->get_id())) return $tabs;

        $tabs['eggl_gpsr'] = [
            'title'    => __('Product safety', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'priority' => 35,
            'callback' => [$this, 'render_tab'],
        ];
        return $tabs;
    }

    public function enqueue_assets() {
        if (!function_exists('is_product') || !is_product()) return;
        wp_enqueue_style('eggl-gpsr-frontend', EGGL_GPSR_Module::url('assets/css/frontend.css'), [], EGGL_GPSR_Module::asset_version('assets/css/frontend.css'));
    }

    private function has_content($pid) {
        if (!EGGL_GPSR_Fields::is_on($pid, 'enabled')) return false;
        foreach (array_keys(EGGL_GPSR_Fields::sections()) as $section) {
            if ($this->contact_rows($pid, $section)) return true;
        }
        return $this->safety_info($pid) !== '' || (bool) $this->documents($pid);
    }

    /** Non-empty [label, value, field] rows of $section, or [] if the section is off or empty. */
    private function contact_rows($pid, $section) {
        if (!EGGL_GPSR_Fields::is_on($pid, $section . '_enabled')) return [];
        $rows = [];
        foreach (EGGL_GPSR_Fields::contact_fields() as $field => $label) {
            $value = EGGL_GPSR_Fields::get($pid, $section . '_' . $field);
            if ($value !== '') $rows[] = [$label, $value, $field];
        }
        return $rows;
    }

    private function safety_info($pid) {
        return EGGL_GPSR_Fields::is_on($pid, 'safety_enabled') ? trim(EGGL_GPSR_Fields::get($pid, 'safety_info')) : '';
    }

    /** [label, url] for each uploaded document, or [] if the section is off. */
    private function documents($pid) {
        if (!EGGL_GPSR_Fields::is_on($pid, 'documents_enabled')) return [];
        $docs = [];
        foreach (EGGL_GPSR_Fields::documents() as $doc => $label) {
            $id  = EGGL_GPSR_Fields::document_id($pid, $doc);
            $url = $id ? wp_get_attachment_url($id) : '';
            if ($url) $docs[] = [$label, $url];
        }
        return $docs;
    }

    public function render_tab() {
        global $product;
        if (!$product instanceof WC_Product) return;
        $pid = $product->get_id();

        echo '<div class="eggl-gpsr">';

        foreach (EGGL_GPSR_Fields::sections() as $section => $info) {
            $rows = $this->contact_rows($pid, $section);
            if (!$rows) continue;

            echo '<section class="eggl-gpsr__block"><h3>' . esc_html($info['title']) . '</h3><table class="eggl-gpsr__table">';
            foreach ($rows as [$label, $value, $field]) {
                if ($field === 'email') {
                    $cell = '<a href="' . esc_url('mailto:' . $value) . '">' . esc_html($value) . '</a>';
                } elseif ($field === 'website') {
                    $cell = '<a href="' . esc_url($value) . '" target="_blank" rel="noopener">' . esc_html($value) . '</a>';
                } else {
                    $cell = esc_html($value);
                }
                echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $cell . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $cell escaped above.
            }
            echo '</table></section>';
        }

        $safety = $this->safety_info($pid);
        if ($safety !== '') {
            echo '<section class="eggl-gpsr__block"><h3>' . esc_html__('Warnings & safety information', 'garanlabs-eu-guarantee-notice-durability-labels') . '</h3>';
            echo wp_kses_post(wpautop(esc_html($safety)));
            echo '</section>';
        }

        $docs = $this->documents($pid);
        if ($docs) {
            echo '<section class="eggl-gpsr__block"><h3>' . esc_html__('Product documents', 'garanlabs-eu-guarantee-notice-durability-labels') . '</h3><ul class="eggl-gpsr__docs">';
            foreach ($docs as [$label, $url]) {
                echo '<li><a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($label) . '</a></li>';
            }
            echo '</ul></section>';
        }

        echo '</div>';
    }
}
