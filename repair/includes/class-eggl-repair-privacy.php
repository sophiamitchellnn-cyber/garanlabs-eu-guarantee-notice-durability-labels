<?php
if (!defined('ABSPATH')) exit;

/**
 * Defect claims in WordPress's personal-data tools (Tools → Export / Erase Personal Data),
 * matched by the email address on the claim.
 */
class EGGL_Repair_Privacy {

    const PER_PAGE = 20;

    public function register_exporter($exporters) {
        $exporters['eggl-repair-claims'] = [
            'exporter_friendly_name' => __('Defect claims (right to repair)', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'callback'               => [$this, 'export'],
        ];
        return $exporters;
    }

    public function register_eraser($erasers) {
        $erasers['eggl-repair-claims'] = [
            'eraser_friendly_name' => __('Defect claims (right to repair)', 'garanlabs-eu-guarantee-notice-durability-labels'),
            'callback'             => [$this, 'erase'],
        ];
        return $erasers;
    }

    private function claims($email, $page) {
        return get_posts([
            'post_type'      => EGGL_Repair_Claims::POST_TYPE,
            'post_status'    => array_values(EGGL_Repair_Claims::statuses()),
            'posts_per_page' => self::PER_PAGE,
            'paged'          => max(1, (int) $page),
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_key'       => '_eggl_rr_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        ]);
    }

    public function export($email, $page = 1) {
        $data   = [];
        $claims = $this->claims($email, $page);
        foreach ($claims as $claim) {
            $order  = wc_get_order((int) EGGL_Repair_Claims::meta($claim->ID, 'order_id', 0));
            $items  = array_map(function ($line) { return $line['name'] . ' × ' . (int) $line['qty']; }, (array) EGGL_Repair_Claims::meta($claim->ID, 'items', []));
            $remedy = (string) EGGL_Repair_Claims::meta($claim->ID, 'remedy', 'repair');
            $status = EGGL_Repair_Claims::status($claim);
            $data[] = [
                'group_id'    => 'eggl-repair-claims',
                'group_label' => __('Defect claims', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'item_id'     => 'eggl-repair-claim-' . $claim->ID,
                'data'        => [
                    ['name' => __('Claim', 'garanlabs-eu-guarantee-notice-durability-labels'), 'value' => '#' . EGGL_Repair_Claims::number($claim->ID)],
                    ['name' => __('Date', 'garanlabs-eu-guarantee-notice-durability-labels'), 'value' => get_the_date('', $claim)],
                    ['name' => __('Order', 'garanlabs-eu-guarantee-notice-durability-labels'), 'value' => $order ? '#' . $order->get_order_number() : ''],
                    ['name' => __('Items', 'garanlabs-eu-guarantee-notice-durability-labels'), 'value' => implode(', ', $items)],
                    ['name' => __('Requested remedy', 'garanlabs-eu-guarantee-notice-durability-labels'), 'value' => EGGL_Repair_Claims::remedy_labels()[$remedy] ?? ''],
                    ['name' => __('Defect', 'garanlabs-eu-guarantee-notice-durability-labels'), 'value' => (string) EGGL_Repair_Claims::meta($claim->ID, 'description', '')],
                    ['name' => __('Status', 'garanlabs-eu-guarantee-notice-durability-labels'), 'value' => EGGL_Repair_Claims::status_labels()[$status] ?? ''],
                    ['name' => __('Name', 'garanlabs-eu-guarantee-notice-durability-labels'), 'value' => (string) EGGL_Repair_Claims::meta($claim->ID, 'name', '')],
                    ['name' => __('Email address', 'garanlabs-eu-guarantee-notice-durability-labels'), 'value' => (string) EGGL_Repair_Claims::meta($claim->ID, 'email', '')],
                    ['name' => __('Phone', 'garanlabs-eu-guarantee-notice-durability-labels'), 'value' => (string) EGGL_Repair_Claims::meta($claim->ID, 'phone', '')],
                ],
            ];
        }
        return ['data' => $data, 'done' => count($claims) < self::PER_PAGE];
    }

    /**
     * Anonymises claims (contact details, description, photos) but keeps the claim itself, its
     * status and its guarantee extension — the seller may need that record for the guarantee.
     * Always page 1: anonymised claims no longer match the email.
     */
    public function erase($email, $page = 1) {
        $claims = $this->claims($email, 1);
        foreach ($claims as $claim) {
            foreach ((array) EGGL_Repair_Claims::meta($claim->ID, 'photos', []) as $attachment_id) {
                wp_delete_attachment((int) $attachment_id, true);
            }
            update_post_meta($claim->ID, '_eggl_rr_email', wp_privacy_anonymize_data('email', $email));
            update_post_meta($claim->ID, '_eggl_rr_name', wp_privacy_anonymize_data('text'));
            update_post_meta($claim->ID, '_eggl_rr_phone', '');
            update_post_meta($claim->ID, '_eggl_rr_description', wp_privacy_anonymize_data('longtext'));
            update_post_meta($claim->ID, '_eggl_rr_photos', []);
        }
        return [
            'items_removed'  => false,
            'items_retained' => count($claims) > 0,
            'messages'       => $claims ? [__('Defect claims were anonymised but kept, as records of the legal guarantee.', 'garanlabs-eu-guarantee-notice-durability-labels')] : [],
            'done'           => count($claims) < self::PER_PAGE,
        ];
    }
}
