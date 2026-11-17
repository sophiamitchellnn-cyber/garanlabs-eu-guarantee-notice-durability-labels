<?php
if (!defined('ABSPATH')) exit;

/** To the customer: we received your defect claim. */
class EGGL_RR_Email_Claim_Received extends EGGL_RR_Email {

    public function __construct() {
        $this->id             = 'eggl_rr_claim_received';
        $this->customer_email = true;
        $this->title          = __('Defect claim received', 'garanlabs-eu-guarantee-notice-durability-labels');
        $this->description    = __('Sent to the customer when they report a defect (right to repair).', 'garanlabs-eu-guarantee-notice-durability-labels');
        $this->template_html  = 'emails/claim-received.php';
        $this->template_plain = 'emails/plain/claim-received.php';
        parent::__construct();
    }

    public function get_default_subject() {
        return __('[{site_title}]: We received your defect claim #{claim_number}', 'garanlabs-eu-guarantee-notice-durability-labels');
    }

    public function get_default_heading() {
        return __('Defect claim received', 'garanlabs-eu-guarantee-notice-durability-labels');
    }

    public function trigger($claim_id) {
        $this->setup_locale();
        if ($this->setup_claim($claim_id)) {
            $this->recipient = (string) EGGL_Repair_Claims::meta($claim_id, 'email', '');
            if ($this->is_enabled() && $this->get_recipient()) {
                $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
            }
        }
        $this->restore_locale();
    }
}
