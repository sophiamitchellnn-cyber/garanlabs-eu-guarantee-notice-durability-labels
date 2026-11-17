<?php
if (!defined('ABSPATH')) exit;

/** Shared base for the defect-claim emails: claim lookup, placeholders and template arguments. */
abstract class EGGL_RR_Email extends WC_Email {

    /** @var int */
    public $claim_id = 0;

    /** @var WC_Order|null */
    public $order = null;

    public function __construct() {
        $this->template_base = EGGL_Repair_Module::path('templates/');
        $this->placeholders  = array_merge($this->placeholders, [
            '{order_number}' => '',
            '{claim_number}' => '',
        ]);
        parent::__construct();
    }

    /** Loads the claim and fills the placeholders; false if it doesn't exist. */
    protected function setup_claim($claim_id) {
        if (EGGL_Repair_Claims::status($claim_id) === '') return false;
        $this->claim_id = (int) $claim_id;
        $this->order    = wc_get_order((int) EGGL_Repair_Claims::meta($claim_id, 'order_id', 0)) ?: null;
        $this->object   = $this->order;
        $this->placeholders['{claim_number}'] = EGGL_Repair_Claims::number($claim_id);
        $this->placeholders['{order_number}'] = $this->order ? $this->order->get_order_number() : '';
        return true;
    }

    protected function template_args($plain_text) {
        return [
            'email_heading'      => $this->get_heading(),
            'additional_content' => $this->get_additional_content(),
            'claim_id'           => $this->claim_id,
            'order'              => $this->order,
            'sent_to_admin'      => !$this->is_customer_email(),
            'plain_text'         => $plain_text,
            'email'              => $this,
        ];
    }

    public function get_content_html() {
        return wc_get_template_html($this->template_html, $this->template_args(false), 'woocommerce/eggl-repair/', $this->template_base);
    }

    public function get_content_plain() {
        return wc_get_template_html($this->template_plain, $this->template_args(true), 'woocommerce/eggl-repair/', $this->template_base);
    }

    public function get_default_additional_content() {
        return '';
    }
}
