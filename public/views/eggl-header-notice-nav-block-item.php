<?php if (!defined('ABSPATH')) exit;
/**
 * Block-theme nav-menu-item placement: appended inside a rendered core/navigation
 * block's <ul class="wp-block-navigation__container">, using the same
 * wp-block-navigation-item/__content classes WP core's own nav-link block uses
 * (see wp-includes/blocks/navigation-link.php) so it inherits the theme's nav
 * item spacing/typography. The modal is rendered separately, once, in the
 * footer (EGGL_Frontend::render_header_notice_modal()).
 * @var string $eggl_notice_src
 * @var string $eggl_notice_alt
 * @var string $eggl_header_text
 * @var string $eggl_modal_id
 */
?>
<li class="wp-block-navigation-item eggl-header-notice-menu-item">
  <div class="wp-block-navigation-item__content">
    <?php include EGGL_PATH . 'public/views/eggl-header-notice-trigger.php'; ?>
  </div>
</li>
