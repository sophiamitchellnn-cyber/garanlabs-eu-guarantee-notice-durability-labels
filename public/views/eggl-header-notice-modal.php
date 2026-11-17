<?php if (!defined('ABSPATH')) exit;
/**
 * The full harmonised notice, shown in a modal dialog on click/tap. Shared by
 * the standalone banner and the nav-menu-item placement.
 * @var string $eggl_notice_src
 * @var string $eggl_notice_alt
 * @var string $eggl_header_text
 * @var string $eggl_modal_id
 */
?>
<div class="eggl-modal" id="<?php echo esc_attr($eggl_modal_id); ?>" data-eggl-modal hidden>
  <div class="eggl-modal__backdrop" data-eggl-close></div>
  <div class="eggl-modal__dialog" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr($eggl_header_text); ?>">
    <button type="button" class="eggl-modal__close" data-eggl-close aria-label="<?php echo esc_attr(EGGL_I18N::t('s_close')); ?>">&times;</button>
    <div class="eggl-modal__panel" data-eggl-panel="notice">
      <img src="<?php echo esc_url($eggl_notice_src); ?>" alt="<?php echo esc_attr($eggl_notice_alt); ?>"
           loading="lazy" decoding="async" class="eggl-graphic-img">
      <p class="eggl-more">
        <?php echo esc_html(EGGL_I18N::t('s_readmore')); ?>
        <a href="<?php echo esc_url(EGGL_I18N::notice_learn_more_url()); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(EGGL_I18N::t('s_link')); ?></a>.
      </p>
    </div>
  </div>
</div>
