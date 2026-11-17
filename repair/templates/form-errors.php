<?php
/**
 * Right to repair: form errors.
 *
 * Override by copying to yourtheme/woocommerce/eggl-repair/form-errors.php.
 *
 * @var WP_Error|null $errors
 */
if (!defined('ABSPATH')) exit;

if (!is_wp_error($errors) || !$errors->has_errors()) return;
?>
<ul class="woocommerce-error eggl-rr__errors" role="alert">
    <?php foreach ($errors->get_error_messages() as $eggl_rr_message) : ?>
        <li><?php echo esc_html($eggl_rr_message); ?></li>
    <?php endforeach; ?>
</ul>
