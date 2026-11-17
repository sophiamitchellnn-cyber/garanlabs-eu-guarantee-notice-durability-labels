<?php
if (!defined('ABSPATH')) exit;
/** @var string $tab (set by EGGL_Settings::render) */
$eggl_base_url = admin_url('admin.php?page=' . EGGL_Settings::PAGE_SLUG);
$eggl_page     = ($tab === 'advanced') ? 'eggl-tab-advanced' : 'eggl-tab-display';
?>
<div class="wrap eggl-settings">
  <div class="eggl-page-header">
    <h1><?php echo esc_html__('EU Consumer Rights for WooCommerce – Guarantee Notice, GARAN Label, Withdrawal Button, Right to Repair, Omnibus Price & GPSR', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h1>
    <p class="eggl-lede"><?php echo esc_html__('Display the harmonised EU legal-guarantee notice (Regulation (EU) 2025/1960) and the GARAN durability label on your product pages, in 24 languages. This plugin is a display helper and does not provide legal advice or guarantee compliance.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
  </div>

  <?php settings_errors(); ?>

  <?php (new EGGL_Health())->render_panel(); ?>

  <div class="eggl-tabs">
    <a href="<?php echo esc_url($eggl_base_url); ?>" class="eggl-tabs__item <?php echo $tab === 'display' ? 'is-active' : ''; ?>"><?php echo esc_html__('Display', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></a>
    <a href="<?php echo esc_url(add_query_arg('tab', 'advanced', $eggl_base_url)); ?>" class="eggl-tabs__item <?php echo $tab === 'advanced' ? 'is-active' : ''; ?>"><?php echo esc_html__('Advanced', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></a>
  </div>

  <form method="post" action="options.php" class="eggl-form">
    <?php
    // Each tab has its own option group (see the comment on EGGL_Settings for why) —
    // must match whichever group this tab's settings were registered under, or
    // WordPress will refuse to save the form ("Options page not found").
    settings_fields($tab === 'advanced' ? EGGL_Settings::OPTION_GROUP_ADVANCED : EGGL_Settings::OPTION_GROUP_DISPLAY);

    global $wp_settings_sections, $wp_settings_fields;
    $sections = isset($wp_settings_sections[$eggl_page]) ? (array) $wp_settings_sections[$eggl_page] : [];

    foreach ($sections as $section) {
        echo '<div class="eggl-card">';

        echo '<div class="eggl-card__head">';
        echo '<h2>' . esc_html($section['title']) . '</h2>';
        if (!empty($section['callback'])) {
            call_user_func($section['callback'], $section);
        }
        echo '</div>';

        $fields = isset($wp_settings_fields[$eggl_page][$section['id']]) ? (array) $wp_settings_fields[$eggl_page][$section['id']] : [];
        if ($fields) {
            echo '<div class="eggl-card__body">';
            foreach ($fields as $field) {
                // Extra field args (set via add_settings_field()'s $args) may
                // add a class and/or data-* attributes to the row wrapper —
                // used e.g. to mark a row as conditionally shown by JS.
                $eggl_extra_args  = is_array($field['args']) ? $field['args'] : [];
                $eggl_row_classes = 'eggl-row';
                if (!empty($eggl_extra_args['class'])) $eggl_row_classes .= ' ' . $eggl_extra_args['class'];
                $eggl_row_attrs = '';
                foreach ($eggl_extra_args as $eggl_attr_key => $eggl_attr_val) {
                    if (strpos($eggl_attr_key, 'data-') === 0) {
                        $eggl_row_attrs .= ' ' . esc_attr($eggl_attr_key) . '="' . esc_attr($eggl_attr_val) . '"';
                    }
                }
                echo '<div class="' . esc_attr($eggl_row_classes) . '"' . $eggl_row_attrs . '>';
                echo '<div class="eggl-row__label"><label for="' . esc_attr($field['id']) . '">' . esc_html($field['title']) . '</label></div>';
                echo '<div class="eggl-row__control">';
                call_user_func($field['callback'], $field['args']);
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
        }

        echo '</div>'; // eggl-card
    }

    submit_button(__('Save changes', 'garanlabs-eu-guarantee-notice-durability-labels'));
    ?>
  </form>
</div>
<script>
(function() {
    // Rows marked data-eggl-show-when="control_id:value" only show while that
    // control currently matches the given value — e.g. the custom-trigger-text
    // field only makes sense when "Row style" is set to "Custom". Works for a
    // <select> (compares .value) and for our toggle checkboxes (compares
    // .checked against "yes"/"no", matching sanitize_checkbox()'s convention).
    document.querySelectorAll('[data-eggl-show-when]').forEach(function(row) {
        var parts = row.getAttribute('data-eggl-show-when').split(':');
        var control = document.getElementById(parts[0]);
        var wantValue = parts[1];
        if (!control) return;
        var isCheckbox = control.type === 'checkbox';
        var sync = function() {
            var current = isCheckbox ? (control.checked ? 'yes' : 'no') : control.value;
            row.style.display = (current === wantValue) ? '' : 'none';
        };
        control.addEventListener('change', sync);
        sync();
    });
})();
</script>
