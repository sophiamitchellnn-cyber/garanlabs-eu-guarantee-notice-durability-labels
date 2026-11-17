<?php if (!defined('ABSPATH')) exit;
/**
 * EU legal-guarantee notice (Annex I), in "popup", "full" or "nested" display mode.
 * @var int    $eggl_uid
 * @var string $eggl_mode        'popup' | 'full' | 'nested'
 * @var string $eggl_style       'minimal' | 'classic' | 'custom', row style (unused in "full" mode)
 * @var string $eggl_custom_text Merchant-entered trigger text, used by every row style (falls back to "Your legal guarantee rights")
 * @var int    $eggl_custom_size Font size (px) of the trigger text, every row style; the custom style's icon/chevron scale with it
 * @var string $eggl_notice_src
 * @var string $eggl_notice_alt
 */
$eggl_modal_id  = 'eggl-modal-notice-' . (int) $eggl_uid;
$eggl_panel_id  = 'eggl-nested-notice-' . (int) $eggl_uid;
$eggl_is_custom = isset($eggl_style) && $eggl_style === 'custom';
$eggl_row_text  = (isset($eggl_custom_text) && $eggl_custom_text !== '') ? $eggl_custom_text : EGGL_I18N::t('s_header_notice');
$eggl_row_class = (isset($eggl_style) && $eggl_style === 'classic') ? ' eggl-widget--classic' : '';
// Plain shield icon shown at both ends of the "custom" trigger text; currentColor so it
// always matches the surrounding text (the custom trigger carries no color/decoration of its own).
$eggl_shield_icon = '<svg width="16" height="16" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 2l7 3v5c0 4.4-3 8.2-7 9-4-.8-7-4.6-7-9V5l7-3z" fill="currentColor"/><path d="M8.4 12l2.3 2.3 4.6-4.7" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$eggl_custom_style = 'font-size:' . (int) (isset($eggl_custom_size) ? $eggl_custom_size : 14) . 'px';
?>
<?php if ($eggl_mode === 'full'): ?>
<div class="eggl-widget eggl-widget--notice" data-eggl>
  <img src="<?php echo esc_url($eggl_notice_src); ?>" alt="<?php echo esc_attr($eggl_notice_alt); ?>"
       loading="lazy" decoding="async" class="eggl-graphic-img">
</div>
<?php elseif ($eggl_mode === 'nested'): ?>
<div class="eggl-widget eggl-widget--notice eggl-widget--nested<?php echo esc_attr($eggl_row_class); ?>" data-eggl>
  <?php if ($eggl_is_custom): ?>
  <button type="button" class="eggl-notice-custom-trigger" style="<?php echo esc_attr($eggl_custom_style); ?>" data-eggl-toggle="<?php echo esc_attr($eggl_panel_id); ?>" aria-expanded="false">
    <span class="eggl-notice-custom-trigger__icon" aria-hidden="true"><?php echo $eggl_shield_icon; ?></span>
    <span class="eggl-notice-custom-trigger__text"><?php echo esc_html($eggl_custom_text); ?></span>
    <span class="eggl-notice-custom-trigger__chevron" aria-hidden="true"></span>
  </button>
  <?php else: ?>
  <button type="button" class="eggl-row eggl-row--trigger" data-eggl-toggle="<?php echo esc_attr($eggl_panel_id); ?>" aria-expanded="false">
    <span class="eggl-row__icon" aria-hidden="true">
      <svg width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
        <path d="M12 2l7 3v5c0 4.4-3 8.2-7 9-4-.8-7-4.6-7-9V5l7-3z" fill="var(--eggl-notice-icon,#333c4d)"/>
        <path d="M8.4 12l2.3 2.3 4.6-4.7" stroke="#fff" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </span>
    <span class="eggl-row__text">
      <strong style="<?php echo esc_attr($eggl_custom_style); ?>"><?php echo esc_html($eggl_row_text); ?></strong>
    </span>
    <span class="eggl-row__btn" aria-hidden="true"><span class="eggl-sr-only"><?php echo esc_html(EGGL_I18N::t('s_view')); ?></span></span>
  </button>
  <?php endif; ?>
  <div class="eggl-nested__panel" id="<?php echo esc_attr($eggl_panel_id); ?>" hidden>
    <img src="<?php echo esc_url($eggl_notice_src); ?>" alt="<?php echo esc_attr($eggl_notice_alt); ?>"
         loading="lazy" decoding="async" class="eggl-graphic-img">
    <p class="eggl-more">
      <?php echo esc_html(EGGL_I18N::t('s_readmore')); ?>
      <a href="<?php echo esc_url(EGGL_I18N::notice_learn_more_url()); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(EGGL_I18N::t('s_link')); ?></a>.
    </p>
  </div>
</div>
<?php else: ?>
<div class="eggl-widget eggl-widget--notice<?php echo esc_attr($eggl_row_class); ?>" data-eggl>
  <?php if ($eggl_is_custom): ?>
  <button type="button" class="eggl-notice-custom-trigger" style="<?php echo esc_attr($eggl_custom_style); ?>" data-eggl-open="<?php echo esc_attr($eggl_modal_id); ?>" data-eggl-tab="notice">
    <span class="eggl-notice-custom-trigger__icon" aria-hidden="true"><?php echo $eggl_shield_icon; ?></span>
    <span class="eggl-notice-custom-trigger__text"><?php echo esc_html($eggl_custom_text); ?></span>
    <span class="eggl-notice-custom-trigger__chevron" aria-hidden="true"></span>
  </button>
  <?php else: ?>
  <div class="eggl-row" data-eggl-open="<?php echo esc_attr($eggl_modal_id); ?>" data-eggl-tab="notice">
    <span class="eggl-row__icon" aria-hidden="true">
      <svg width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
        <path d="M12 2l7 3v5c0 4.4-3 8.2-7 9-4-.8-7-4.6-7-9V5l7-3z" fill="var(--eggl-notice-icon,#333c4d)"/>
        <path d="M8.4 12l2.3 2.3 4.6-4.7" stroke="#fff" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </span>
    <span class="eggl-row__text">
      <strong style="<?php echo esc_attr($eggl_custom_style); ?>"><?php echo esc_html($eggl_row_text); ?></strong>
    </span>
    <button type="button" class="eggl-row__btn" data-eggl-open="<?php echo esc_attr($eggl_modal_id); ?>" data-eggl-tab="notice">
      <span class="eggl-sr-only"><?php echo esc_html(EGGL_I18N::t('s_view')); ?></span>
    </button>
  </div>
  <?php endif; ?>
</div>

<div class="eggl-modal" id="<?php echo esc_attr($eggl_modal_id); ?>" data-eggl-modal hidden>
  <div class="eggl-modal__backdrop" data-eggl-close></div>
  <div class="eggl-modal__dialog" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr(EGGL_I18N::t('s_notice_title')); ?>">
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
<?php endif; ?>
