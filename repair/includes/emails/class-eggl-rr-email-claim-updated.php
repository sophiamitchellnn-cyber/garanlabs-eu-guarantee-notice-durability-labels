<?php
if (!defined('ABSPATH')) exit;

/** To the customer: their claim moved to a new status (in progress, repaired, replaced, refunded, rejected). */
class EGGL_RR_Email_Claim_Updated extends EGGL_RR_Email {

    /** @var string status key */
    public $new_status = '';

    /** @var string message from the shop */
    public $message = '';

    public function __construct() {
        $this->id             = 'eggl_rr_claim_updated';
        $this->customer_email = true;
        $this->title          = __('Defect claim updated', 'garanlabs-eu-guarantee-notice-durability-labels');
        $this->description    = __('Sent to the customer when you change the status of their defect claim or send them a message.', 'garanlabs-eu-guarantee-notice-durability-labels');
        $this->template_html  = 'emails/claim-updated.php';
        $this->template_plain = 'emails/plain/claim-updated.php';
        $this->placeholders['{claim_status}'] = '';
        parent::__construct();
    }

    public function get_default_subject() {
        return __('[{site_title}]: Update on your defect claim #{claim_number} — {claim_status}', 'garanlabs-eu-guarantee-notice-durability-labels');
    }

    public function get_default_heading() {
        return __('Update on your defect claim', 'garanlabs-eu-guarantee-notice-durability-labels');
    }

    public function trigger($claim_id, $new_status = '', $message = '') {
        $this->setup_locale();
        if ($this->setup_claim($claim_id)) {
            $this->new_status = $new_status ?: EGGL_Repair_Claims::status($claim_id);
            $this->message    = (string) $message;
            $this->placeholders['{claim_status}'] = EGGL_Repair_Claims::status_labels()[$this->new_status] ?? '';
            $this->recipient = (string) EGGL_Repair_Claims::meta($claim_id, 'email', '');
            if ($this->is_enabled() && $this->get_recipient()) {
                $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
            }
        }
        $this->restore_locale();
    }

    protected function template_args($plain_text) {
        return array_merge(parent::template_args($plain_text), [
            'new_status' => $this->new_status,
            'message'    => $this->message,
        ]);
    }
}

/** The status-specific sentence of the "Defect claim updated" email. */
class EGGL_RR_Email_Claim_Updated_Text {

    public static function for_status($status) {
        $months = EGGL_Repair_Settings::extension_months();
        switch ($status) {
            case 'in_progress':
                return __('We are now working on your defect claim.', 'garanlabs-eu-guarantee-notice-durability-labels');
            case 'repaired':
                /* translators: %d: number of months */
                return sprintf(__('Your item has been repaired. As required by EU law, your legal guarantee for the repaired item has been extended by %d months — see the new end date below.', 'garanlabs-eu-guarantee-notice-durability-labels'), $months);
            case 'replaced':
                return __('Your defective item has been replaced.', 'garanlabs-eu-guarantee-notice-durability-labels');
            case 'refunded':
                return __('Your claim has been settled with a refund or a price reduction.', 'garanlabs-eu-guarantee-notice-durability-labels');
            case 'rejected':
                return __('Unfortunately we cannot accept your defect claim.', 'garanlabs-eu-guarantee-notice-durability-labels');
        }
        return '';
    }
}
