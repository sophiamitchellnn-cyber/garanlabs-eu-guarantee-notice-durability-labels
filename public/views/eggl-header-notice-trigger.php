<?php if (!defined('ABSPATH')) exit;
/**
 * The clickable/hoverable sentence itself (e.g. "Your legal guarantee
 * rights"), shared by the standalone banner and the nav-menu-item placement.
 * @var string $eggl_notice_src
 * @var string $eggl_notice_alt
 * @var string $eggl_header_text
 * @var string $eggl_modal_id
 */
?>
<span class="eggl-header-notice__wrap">
  <button type="button" class="eggl-header-notice__link" data-eggl-open="<?php echo esc_attr($eggl_modal_id); ?>" data-eggl-tab="notice">
    <?php echo esc_html($eggl_header_text); ?>
  </button>
  <span class="eggl-header-notice__popover" role="tooltip">
    <img src="<?php echo esc_url($eggl_notice_src); ?>" alt="<?php echo esc_attr($eggl_notice_alt); ?>"
         loading="lazy" decoding="async" class="eggl-graphic-img">
  </span>
</span>
