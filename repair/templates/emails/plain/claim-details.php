<?php
/**
 * Defect claim emails (plain text): claim details.
 *
 * @var int           $claim_id
 * @var WC_Order|null $order
 * @var bool          $sent_to_admin
 */
if (!defined('ABSPATH')) exit;

$eggl_rr_remedy   = (string) EGGL_Repair_Claims::meta($claim_id, 'remedy', 'repair');
$eggl_rr_extended = (array) EGGL_Repair_Claims::meta($claim_id, 'extended', []);
$eggl_rr_noticed  = (string) EGGL_Repair_Claims::meta($claim_id, 'noticed', '');

echo "----------\n";
/* translators: 1: claim number, 2: order number */
echo esc_html(sprintf(__('Defect claim #%1$s — order #%2$s', 'garanlabs-eu-guarantee-notice-durability-labels'), EGGL_Repair_Claims::number($claim_id), $order ? $order->get_order_number() : '')) . "\n\n";
echo esc_html__('Items', 'garanlabs-eu-guarantee-notice-durability-labels') . ":\n";
foreach ((array) EGGL_Repair_Claims::meta($claim_id, 'items', []) as $eggl_rr_line) {
    echo '- ' . esc_html($eggl_rr_line['name'] . ' × ' . (int) $eggl_rr_line['qty']) . "\n";
    if (!empty($eggl_rr_extended[$eggl_rr_line['item_id']])) {
        /* translators: %s: date */
        echo '  ' . esc_html(sprintf(__('Legal guarantee extended until %s', 'garanlabs-eu-guarantee-notice-durability-labels'), date_i18n(get_option('date_format'), strtotime($eggl_rr_extended[$eggl_rr_line['item_id']])))) . "\n";
    }
}
echo "\n" . esc_html__('Requested remedy', 'garanlabs-eu-guarantee-notice-durability-labels') . ': ' . esc_html(EGGL_Repair_Claims::remedy_labels()[$eggl_rr_remedy] ?? '') . "\n";
if ($eggl_rr_noticed !== '') {
    echo esc_html__('Noticed on', 'garanlabs-eu-guarantee-notice-durability-labels') . ': ' . esc_html(date_i18n(get_option('date_format'), strtotime($eggl_rr_noticed))) . "\n";
}
echo esc_html__('Defect', 'garanlabs-eu-guarantee-notice-durability-labels') . ":\n" . esc_html((string) EGGL_Repair_Claims::meta($claim_id, 'description', '')) . "\n";
if ($sent_to_admin) {
    echo "\n" . esc_html__('Customer', 'garanlabs-eu-guarantee-notice-durability-labels') . ': ' . esc_html(implode(' · ', array_filter([(string) EGGL_Repair_Claims::meta($claim_id, 'name', ''), (string) EGGL_Repair_Claims::meta($claim_id, 'email', ''), (string) EGGL_Repair_Claims::meta($claim_id, 'phone', '')]))) . "\n";
    foreach ((array) EGGL_Repair_Claims::meta($claim_id, 'photos', []) as $eggl_rr_photo) {
        echo esc_url_raw((string) wp_get_attachment_url($eggl_rr_photo)) . "\n";
    }
}
echo "----------\n\n";
