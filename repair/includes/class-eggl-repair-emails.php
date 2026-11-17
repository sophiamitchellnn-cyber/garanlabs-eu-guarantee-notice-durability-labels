<?php
if (!defined('ABSPATH')) exit;

/**
 * Registers the three defect-claim emails with WooCommerce (editable under WooCommerce →
 * Settings → Emails) and sends them when a claim is created or changes status.
 */
class EGGL_Repair_Emails {

    public function register($emails) {
        require_once __DIR__ . '/emails/class-eggl-rr-email.php';
        require_once __DIR__ . '/emails/class-eggl-rr-email-claim-received.php';
        require_once __DIR__ . '/emails/class-eggl-rr-email-new-claim.php';
        require_once __DIR__ . '/emails/class-eggl-rr-email-claim-updated.php';

        $emails['EGGL_RR_Email_Claim_Received'] = new EGGL_RR_Email_Claim_Received();
        $emails['EGGL_RR_Email_New_Claim']      = new EGGL_RR_Email_New_Claim();
        $emails['EGGL_RR_Email_Claim_Updated']  = new EGGL_RR_Email_Claim_Updated();
        return $emails;
    }

    private function email($class) {
        $emails = WC()->mailer()->get_emails();
        return $emails[$class] ?? null;
    }

    /** Hooked to eggl_repair_claim_created. */
    public function claim_created($claim_id) {
        if ($email = $this->email('EGGL_RR_Email_Claim_Received')) $email->trigger($claim_id);
        if ($email = $this->email('EGGL_RR_Email_New_Claim')) $email->trigger($claim_id);
    }

    /** Hooked to eggl_repair_claim_status_changed. */
    public function claim_status_changed($claim_id, $old, $new, $message) {
        if ($new === 'received') return;
        if ($email = $this->email('EGGL_RR_Email_Claim_Updated')) $email->trigger($claim_id, $new, $message);
    }
}
