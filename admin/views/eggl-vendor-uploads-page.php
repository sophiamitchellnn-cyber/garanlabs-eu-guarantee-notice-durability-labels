<?php
if (!defined('ABSPATH')) exit;
/**
 * @var array $eggl_products_with_images (set by EGGL_Vendor_Uploads::render)
 * @var int   $eggl_total_pages
 * @var int   $eggl_current_page
 */
?>
<div class="wrap eggl-settings eggl-vendor-uploads">
  <div class="eggl-page-header">
    <h1><?php echo esc_html__('Vendor GARAN Labels', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h1>
    <p class="eggl-lede"><?php echo esc_html__('If a manufacturer or vendor already supplies you with a ready-made GARAN durability label image for a product, upload it here — it then replaces this plugin\'s own generated label everywhere it would normally show for that product (product page, checkout, cart, and order-confirmation emails). Leave products without one alone; they keep using the label generated from their Brand/Model/Duration fields.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
  </div>

  <?php if (isset($_GET['eggl_saved'])): ?>
    <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Vendor GARAN label image saved.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p></div>
  <?php elseif (isset($_GET['eggl_removed'])): ?>
    <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Vendor GARAN label image removed — this product now uses the generated label again.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p></div>
  <?php elseif (isset($_GET['eggl_error'])): ?>
    <div class="notice notice-error is-dismissible"><p><?php echo esc_html__('Could not save — choose a product and an image, and enter the duration in years.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p></div>
  <?php endif; ?>

  <div class="notice notice-warning inline">
    <p><?php echo esc_html__('If a product has both a vendor image here and its own Brand/Model/Duration filled in under Product data → EU Guarantee, the vendor image wins: it always shows in place of the generated label graphic, wherever the label would normally appear. The compact preview badge (before a shopper clicks to expand it) still shows the duration you entered there, if any — only the full label graphic is replaced.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
  </div>

  <div class="eggl-card">
    <div class="eggl-card__head">
      <h2><?php echo esc_html__('Upload a vendor label for a product', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h2>
    </div>
    <div class="eggl-card__body">
      <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=' . EGGL_Vendor_Uploads::PAGE_SLUG)); ?>">
        <?php wp_nonce_field('eggl_save_vendor_garan_image'); ?>
        <input type="hidden" name="eggl_vendor_save" value="1">

        <div class="eggl-row">
          <div class="eggl-row__label"><label for="eggl_vendor_product_search"><?php echo esc_html__('Product', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></label></div>
          <div class="eggl-row__control">
            <select id="eggl_vendor_product_search" name="eggl_vendor_product_id" class="wc-product-search" style="width:100%"
                    data-placeholder="<?php echo esc_attr__('Search for a product…', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>"
                    data-action="woocommerce_json_search_products_and_variations"></select>
          </div>
        </div>

        <div class="eggl-row">
          <div class="eggl-row__label"><?php echo esc_html__('Label image', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></div>
          <div class="eggl-row__control">
            <input type="hidden" name="eggl_vendor_image_id" id="eggl_vendor_image_id" value="">
            <div id="eggl_vendor_image_preview" class="eggl-vendor-image-preview"></div>
            <button type="button" class="button" id="eggl_vendor_image_button"><?php echo esc_html__('Select or upload image', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></button>
            <button type="button" class="button-link eggl-vendor-image-remove" id="eggl_vendor_image_clear" hidden><?php echo esc_html__('Remove', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></button>
            <p class="description"><?php echo esc_html__('Any image file from your Media Library. It will be shown as-is — full size in "Full" display mode, and inside the popup/nested panel in the other modes — in place of the generated label.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
          </div>
        </div>

        <div class="eggl-row">
          <div class="eggl-row__label"><label for="eggl_vendor_years"><?php echo esc_html__('Duration (years)', 'garanlabs-eu-guarantee-notice-durability-labels'); ?> <span class="required" style="color:#b32d2e" aria-hidden="true">*</span></label></div>
          <div class="eggl-row__control">
            <label class="eggl-dim-field">
              <input type="number" name="eggl_vendor_years" id="eggl_vendor_years" min="1" step="1" required aria-required="true">
            </label>
            <p class="description"><?php echo esc_html__('Required — shown on the compact preview badge before a shopper clicks to see the full image (e.g. "5 | GARAN"). Doesn\'t require also filling in this product\'s own Duration field under Product data → EU Guarantee.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
          </div>
        </div>

        <?php submit_button(__('Save vendor label for this product', 'garanlabs-eu-guarantee-notice-durability-labels')); ?>
      </form>
    </div>
  </div>

  <div class="eggl-card">
    <div class="eggl-card__head">
      <h2><?php echo esc_html__('Products currently using a vendor label', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h2>
    </div>
    <div class="eggl-card__body">
      <?php if (!$eggl_products_with_images): ?>
        <p class="description"><?php echo esc_html__('None yet.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
      <?php else: ?>
        <table class="widefat striped">
          <thead>
            <tr>
              <th><?php echo esc_html__('Image', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></th>
              <th><?php echo esc_html__('Product', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></th>
              <th><?php echo esc_html__('Badge duration', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($eggl_products_with_images as $eggl_p): ?>
              <?php
              $eggl_attachment_id = (int) get_post_meta($eggl_p->ID, EGGL_Vendor_Uploads::META_KEY, true);
              $eggl_thumb         = $eggl_attachment_id ? wp_get_attachment_image($eggl_attachment_id, [60, 60], true) : '';
              $eggl_badge_years   = EGGL_Product_Fields::vendor_years($eggl_p->ID);
              $eggl_remove_url    = wp_nonce_url(
                  add_query_arg(['page' => EGGL_Vendor_Uploads::PAGE_SLUG, 'eggl_remove' => $eggl_p->ID], admin_url('admin.php')),
                  'eggl_remove_vendor_garan_image'
              );
              ?>
              <tr>
                <td><?php echo $eggl_thumb ? $eggl_thumb : '—'; ?></td>
                <td><a href="<?php echo esc_url(get_edit_post_link($eggl_p->post_type === 'product_variation' ? $eggl_p->post_parent : $eggl_p->ID)); ?>"><?php echo esc_html(get_the_title($eggl_p)); ?></a></td>
                <td><?php echo $eggl_badge_years ? esc_html($eggl_badge_years) : '—'; ?></td>
                <td><a href="<?php echo esc_url($eggl_remove_url); ?>" class="eggl-vendor-image-remove"
                       onclick="return confirm('<?php echo esc_js(__('Remove this vendor label? The product will go back to the generated label.', 'garanlabs-eu-guarantee-notice-durability-labels')); ?>');">
                  <?php echo esc_html__('Remove', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>
                </a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <?php if ($eggl_total_pages > 1): ?>
          <div class="tablenav">
            <div class="tablenav-pages">
              <?php
              echo paginate_links([
                  'base'      => add_query_arg(['page' => EGGL_Vendor_Uploads::PAGE_SLUG, 'eggl_paged' => '%#%'], admin_url('admin.php')),
                  'format'    => '',
                  'current'   => $eggl_current_page,
                  'total'     => $eggl_total_pages,
                  'prev_text' => __('&laquo; Previous', 'garanlabs-eu-guarantee-notice-durability-labels'),
                  'next_text' => __('Next &raquo;', 'garanlabs-eu-guarantee-notice-durability-labels'),
              ]);
              ?>
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
