<?php
if (!defined('ABSPATH')) exit;

/** To the shop: a customer reported a defect. */
class EGGL_RR_Email_New_Claim extends EGGL_RR_Email {

    public function __construct() {
        $this->id             = 'eggl_rr_new_claim';
        $this->title          = __('New defect claim', 'garanlabs-eu-guarantee-notice-durability-labels');
        $this->description    = __('Sent to the shop when a customer reports a defect (right to repair).', 'garanlabs-eu-guarantee-notice-durability-labels');
        $this->template_html  = 'emails/new-claim.php';
        $this->template_plain = 'emails/plain/new-claim.php';
        parent::__construct();
        $this->recipient = $this->get_option('recipient', get_option('admin_email'));
    }

    public function get_default_subject() {
        return __('[{site_title}]: New defect claim #{claim_number} for order #{order_number}', 'garanlabs-eu-guarantee-notice-durability-labels');
    }

    public function get_default_heading() {
        return __('New defect claim', 'garanlabs-eu-guarantee-notice-durability-labels');
    }

    public function trigger($claim_id) {
        if ($this->setup_claim($claim_id) && $this->is_enabled() && $this->get_recipient()) {
            $email = (string) EGGL_Repair_Claims::meta($claim_id, 'email', '');
            $headers = $this->get_headers() . (is_email($email) ? 'Reply-to: ' . $email . "\r\n" : '');
            $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $headers, $this->get_attachments());
        }
    }

    public function init_form_fields() {
        parent::init_form_fields();
        $this->form_fields = array_merge([
            'recipient' => [
                'title'       => __('Recipient(s)', 'garanlabs-eu-guarantee-notice-durability-labels'),
                'type'        => 'text',
                /* translators: %s: admin email */
                'description' => sprintf(__('Enter recipients (comma separated) for this email. Defaults to %s.', 'garanlabs-eu-guarantee-notice-durability-labels'), '<code>' . esc_attr(get_option('admin_email')) . '</code>'),
                'placeholder' => '',
                'default'     => '',
                'desc_tip'    => true,
            ],
        ], $this->form_fields);
    }
}
