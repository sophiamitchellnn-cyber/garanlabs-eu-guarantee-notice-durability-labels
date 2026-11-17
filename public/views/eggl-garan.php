<?php if (!defined('ABSPATH')) exit;
/**
 * GARAN durability label (Annex II), in "popup", "full" or "nested" display mode.
 * @var int    $eggl_uid
 * @var string $eggl_mode      'popup' | 'full' | 'nested'
 * @var int    $eggl_years
 * @var string $eggl_brand
 * @var string $eggl_model
 * @var string $eggl_vendor_image_src Optional. A vendor/manufacturer-supplied label image URL
 *             (set via the "Vendor GARAN Labels" admin page) — when present, shown as-is in
 *             place of the generated SVG graphic below. The compact trigger badge (years count)
 *             stays the same either way; only the full-size graphic differs.
 */
$eggl_modal_id  = 'eggl-modal-garan-' . (int) $eggl_uid;
$eggl_panel_id  = 'eggl-nested-garan-' . (int) $eggl_uid;
$eggl_aria      = EGGL_I18N::t('s_label_aria_full');
$eggl_vendor_src = isset($eggl_vendor_image_src) ? $eggl_vendor_image_src : '';

if ($eggl_vendor_src !== '') {
    $eggl_graphic = sprintf(
        '<img src="%s" alt="%s" loading="lazy" decoding="async" class="eggl-graphic-img">',
        esc_url($eggl_vendor_src),
        esc_attr($eggl_aria)
    );
} else {
    $eggl_graphic = EGGL_Frontend::garan_svg_markup($eggl_brand, $eggl_model, $eggl_years, false, $eggl_aria);
}
?>
<?php if ($eggl_mode === 'full'): ?>
<div class="eggl-widget eggl-widget--garan" data-eggl>
  <?php echo $eggl_graphic; ?>
</div>
<?php elseif ($eggl_mode === 'nested'): ?>
<div class="eggl-widget eggl-widget--garan eggl-widget--nested" data-eggl>
  <button type="button" class="eggl-nested__trigger" data-eggl-toggle="<?php echo esc_attr($eggl_panel_id); ?>" aria-expanded="false">
    <?php echo EGGL_Frontend::garan_nested_svg_markup($eggl_years, EGGL_I18N::t('s_label_heading')); ?>
    <span class="eggl-nested__chevron" aria-hidden="true"></span>
  </button>
  <div class="eggl-nested__panel" id="<?php echo esc_attr($eggl_panel_id); ?>" hidden>
    <?php echo $eggl_graphic; ?>
    <p class="eggl-more">
      <?php echo esc_html(EGGL_I18N::t('s_readmore')); ?>
      <a href="<?php echo esc_url(EGGL_I18N::label_learn_more_url()); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(EGGL_I18N::t('s_link')); ?></a>.
    </p>
  </div>
</div>
<?php else: ?>
<div class="eggl-widget eggl-widget--garan" data-eggl>
  <button type="button" class="eggl-nested__trigger" data-eggl-open="<?php echo esc_attr($eggl_modal_id); ?>" data-eggl-tab="label" aria-haspopup="dialog">
    <?php echo EGGL_Frontend::garan_nested_svg_markup($eggl_years, EGGL_I18N::t('s_label_heading')); ?>
  </button>
</div>

<div class="eggl-modal" id="<?php echo esc_attr($eggl_modal_id); ?>" data-eggl-modal hidden>
  <div class="eggl-modal__backdrop" data-eggl-close></div>
  <div class="eggl-modal__dialog" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr(EGGL_I18N::t('s_label_heading')); ?>">
    <button type="button" class="eggl-modal__close" data-eggl-close aria-label="<?php echo esc_attr(EGGL_I18N::t('s_close')); ?>">&times;</button>
    <div class="eggl-modal__panel" data-eggl-panel="label">
      <?php echo $eggl_graphic; ?>
      <p class="eggl-more">
        <?php echo esc_html(EGGL_I18N::t('s_readmore')); ?>
        <a href="<?php echo esc_url(EGGL_I18N::label_learn_more_url()); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(EGGL_I18N::t('s_link')); ?></a>.
      </p>
    </div>
  </div>
</div>
<?php endif; ?>
