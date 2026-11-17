<?php if (!defined('ABSPATH')) exit;
/**
 * Standalone banner placement: sentence + modal, shown once via wp_body_open.
 * @var string $eggl_notice_src
 * @var string $eggl_notice_alt
 * @var string $eggl_header_text
 * @var string $eggl_modal_id
 */
?>
<div class="eggl-header-notice" data-eggl>
  <?php include EGGL_PATH . 'public/views/eggl-header-notice-trigger.php'; ?>
</div>
<?php include EGGL_PATH . 'public/views/eggl-header-notice-modal.php'; ?>
