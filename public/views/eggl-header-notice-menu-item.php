<?php if (!defined('ABSPATH')) exit;
/**
 * Nav-menu-item placement: appended to a theme_location's menu output so the
 * notice sits inline with items like Home / Cart / Checkout. The modal is
 * rendered separately, once, in the footer (EGGL_Frontend::render_header_notice_modal()).
 *
 * Rendered as a real menu link (<a class="menu-link">, directly inside the <li>) rather than
 * the shared <button> trigger, so every theme spaces and styles it exactly like its own menu
 * items. role="button" + the data-eggl-open handler in eggl-frontend.js make it open the modal.
 * @var string $eggl_notice_src
 * @var string $eggl_notice_alt
 * @var string $eggl_header_text
 * @var string $eggl_modal_id
 */
?>
<li class="menu-item eggl-header-notice-menu-item">
  <a href="#<?php echo esc_attr($eggl_modal_id); ?>" class="menu-link eggl-header-notice__menu-link" role="button" aria-haspopup="dialog" data-eggl-open="<?php echo esc_attr($eggl_modal_id); ?>" data-eggl-tab="notice"><?php echo esc_html($eggl_header_text); ?></a>
  <span class="eggl-header-notice__popover" role="tooltip">
    <img src="<?php echo esc_url($eggl_notice_src); ?>" alt="<?php echo esc_attr($eggl_notice_alt); ?>"
         loading="lazy" decoding="async" class="eggl-graphic-img">
  </span>
</li>
