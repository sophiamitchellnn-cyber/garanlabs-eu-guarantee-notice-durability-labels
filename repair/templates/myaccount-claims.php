<?php
/**
 * Right to repair: an order's defect claims and guarantee extensions, on its My account page.
 *
 * Override by copying to yourtheme/woocommerce/eggl-repair/myaccount-claims.php.
 *
 * @var WC_Order  $order
 * @var WP_Post[] $claims
 * @var string    $url    claim form link, '' when nothing can be claimed any more
 */
if (!defined('ABSPATH')) exit;

$eggl_rr_statuses = EGGL_Repair_Claims::status_labels();
$eggl_rr_remedies = EGGL_Repair_Claims::remedy_labels();
?>
<section class="eggl-rr-account">
    <h2><?php esc_html_e('Defects and repairs', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h2>

    <?php if ($claims) : ?>
        <table class="woocommerce-table shop_table eggl-rr-account__table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Claim', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></th>
                    <th><?php esc_html_e('Items', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></th>
                    <th><?php esc_html_e('Requested', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></th>
                    <th><?php esc_html_e('Status', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($claims as $eggl_rr_claim) :
                    $eggl_rr_status   = EGGL_Repair_Claims::status($eggl_rr_claim);
                    $eggl_rr_extended = (array) EGGL_Repair_Claims::meta($eggl_rr_claim->ID, 'extended', []);
                    $eggl_rr_remedy   = (string) EGGL_Repair_Claims::meta($eggl_rr_claim->ID, 'remedy', 'repair');
                    ?>
                    <tr>
                        <td>#<?php echo esc_html(EGGL_Repair_Claims::number($eggl_rr_claim->ID)); ?><br><small><?php echo esc_html(get_the_date('', $eggl_rr_claim)); ?></small></td>
                        <td>
                            <?php foreach ((array) EGGL_Repair_Claims::meta($eggl_rr_claim->ID, 'items', []) as $eggl_rr_line) : ?>
                                <div>
                                    <?php echo esc_html($eggl_rr_line['name'] . ' × ' . (int) $eggl_rr_line['qty']); ?>
                                    <?php if (!empty($eggl_rr_extended[$eggl_rr_line['item_id']])) : ?>
                                        <br><small><?php
                                        /* translators: %s: date */
                                        printf(esc_html__('Guarantee extended until %s', 'garanlabs-eu-guarantee-notice-durability-labels'), esc_html(date_i18n(get_option('date_format'), strtotime($eggl_rr_extended[$eggl_rr_line['item_id']]))));
                                        ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </td>
                        <td><?php echo esc_html($eggl_rr_remedies[$eggl_rr_remedy] ?? ''); ?></td>
                        <td>
                            <mark class="eggl-rr-status eggl-rr-status--<?php echo esc_attr($eggl_rr_status); ?>"><?php echo esc_html($eggl_rr_statuses[$eggl_rr_status] ?? ''); ?></mark>
                            <?php $eggl_rr_reason = (string) EGGL_Repair_Claims::meta($eggl_rr_claim->ID, 'reason', ''); ?>
                            <?php if ($eggl_rr_reason !== '') : ?>
                                <div class="eggl-rr-account__message"><?php echo wp_kses_post(wpautop($eggl_rr_reason)); ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($url !== '') : ?>
        <p><a class="button" href="<?php echo esc_url($url); ?>"><?php esc_html_e('Report a defect', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></a></p>
    <?php endif; ?>
</section>
