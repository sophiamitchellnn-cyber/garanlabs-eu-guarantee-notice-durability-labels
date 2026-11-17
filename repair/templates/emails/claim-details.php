<?php
/**
 * Defect claim emails: claim details (items, requested remedy, description, photos).
 *
 * Override by copying to yourtheme/woocommerce/eggl-repair/emails/claim-details.php.
 *
 * @var int           $claim_id
 * @var WC_Order|null $order
 * @var bool          $sent_to_admin
 */
if (!defined('ABSPATH')) exit;

$eggl_rr_remedy   = (string) EGGL_Repair_Claims::meta($claim_id, 'remedy', 'repair');
$eggl_rr_extended = (array) EGGL_Repair_Claims::meta($claim_id, 'extended', []);
$eggl_rr_noticed  = (string) EGGL_Repair_Claims::meta($claim_id, 'noticed', '');
$eggl_rr_photos   = (array) EGGL_Repair_Claims::meta($claim_id, 'photos', []);
$eggl_rr_cell     = 'text-align:left;padding:12px;border:1px solid #e5e5e5;vertical-align:top;';
?>
<h2>
    <?php
    /* translators: 1: claim number, 2: order number */
    printf(esc_html__('Defect claim #%1$s — order #%2$s', 'garanlabs-eu-guarantee-notice-durability-labels'), esc_html(EGGL_Repair_Claims::number($claim_id)), esc_html($order ? $order->get_order_number() : ''));
    ?>
</h2>
<table cellspacing="0" cellpadding="6" border="1" width="100%" style="border-collapse:collapse;margin:0 0 24px;border:1px solid #e5e5e5;">
    <tr>
        <th scope="row" style="<?php echo esc_attr($eggl_rr_cell); ?>"><?php esc_html_e('Items', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></th>
        <td style="<?php echo esc_attr($eggl_rr_cell); ?>">
            <?php foreach ((array) EGGL_Repair_Claims::meta($claim_id, 'items', []) as $eggl_rr_line) : ?>
                <div>
                    <?php echo esc_html($eggl_rr_line['name'] . ' × ' . (int) $eggl_rr_line['qty']); ?>
                    <?php if (!empty($eggl_rr_extended[$eggl_rr_line['item_id']])) : ?>
                        <br><strong><?php
                        /* translators: %s: date */
                        printf(esc_html__('Legal guarantee extended until %s', 'garanlabs-eu-guarantee-notice-durability-labels'), esc_html(date_i18n(get_option('date_format'), strtotime($eggl_rr_extended[$eggl_rr_line['item_id']]))));
                        ?></strong>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </td>
    </tr>
    <tr>
        <th scope="row" style="<?php echo esc_attr($eggl_rr_cell); ?>"><?php esc_html_e('Requested remedy', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></th>
        <td style="<?php echo esc_attr($eggl_rr_cell); ?>"><?php echo esc_html(EGGL_Repair_Claims::remedy_labels()[$eggl_rr_remedy] ?? ''); ?></td>
    </tr>
    <tr>
        <th scope="row" style="<?php echo esc_attr($eggl_rr_cell); ?>"><?php esc_html_e('Defect', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></th>
        <td style="<?php echo esc_attr($eggl_rr_cell); ?>"><?php echo wp_kses_post(wpautop(esc_html((string) EGGL_Repair_Claims::meta($claim_id, 'description', '')))); ?></td>
    </tr>
    <?php if ($eggl_rr_noticed !== '') : ?>
        <tr>
            <th scope="row" style="<?php echo esc_attr($eggl_rr_cell); ?>"><?php esc_html_e('Noticed on', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></th>
            <td style="<?php echo esc_attr($eggl_rr_cell); ?>"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($eggl_rr_noticed))); ?></td>
        </tr>
    <?php endif; ?>
    <?php if ($sent_to_admin) : ?>
        <tr>
            <th scope="row" style="<?php echo esc_attr($eggl_rr_cell); ?>"><?php esc_html_e('Customer', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></th>
            <td style="<?php echo esc_attr($eggl_rr_cell); ?>">
                <?php echo esc_html(implode(' · ', array_filter([(string) EGGL_Repair_Claims::meta($claim_id, 'name', ''), (string) EGGL_Repair_Claims::meta($claim_id, 'email', ''), (string) EGGL_Repair_Claims::meta($claim_id, 'phone', '')]))); ?>
            </td>
        </tr>
        <?php if ($eggl_rr_photos) : ?>
            <tr>
                <th scope="row" style="<?php echo esc_attr($eggl_rr_cell); ?>"><?php esc_html_e('Photos', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></th>
                <td style="<?php echo esc_attr($eggl_rr_cell); ?>">
                    <?php foreach ($eggl_rr_photos as $eggl_rr_photo) : ?>
                        <a href="<?php echo esc_url(wp_get_attachment_url($eggl_rr_photo)); ?>"><?php echo esc_html(wp_basename((string) wp_get_attachment_url($eggl_rr_photo))); ?></a><br>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endif; ?>
    <?php endif; ?>
</table>
