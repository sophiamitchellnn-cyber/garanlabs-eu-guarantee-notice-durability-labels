<?php
if (!defined('ABSPATH')) exit;

/**
 * Customer side of the right to repair: the [eggl_repair_claim_form] shortcode (order lookup →
 * defect claim → confirmation), the "Report a defect" button in My account → Orders, and the
 * order's claims and guarantee extensions on its My account page.
 *
 * Access to an order: its owner when logged in, or anyone holding the order key (the link in
 * My account / order emails, or the one the lookup redirects to after matching order number +
 * email — the same proof WooCommerce itself uses for guest order pages).
 */
class EGGL_Repair_Form {

    const MAX_PHOTO_BYTES = 5242880; // 5 MB
    const MIN_SECONDS     = 3;       // a human takes longer than this to fill in the form
    const LOOKUP_LIMIT    = 10;      // order lookups per IP per hour
    const SUBMIT_LIMIT    = 5;       // claims per IP per hour

    /** @var WP_Error|null errors from this request's POST, shown above the form */
    private $errors = null;

    /** Posted values to refill the form after an error. */
    private $posted = [];

    public function register_shortcode() {
        add_shortcode('eggl_repair_claim_form', [$this, 'shortcode']);
    }

    public function enqueue_assets() {
        // My account: spacing for the "Report a defect" order action and the order's claims section.
        if (function_exists('is_account_page') && is_account_page() && EGGL_Repair_Settings::get('account_link') === 'yes') {
            EGGL_Repair_Notice::enqueue_style();
        }
        if (!is_singular() || (int) get_queried_object_id() !== EGGL_Repair_Settings::page_id()) return;
        EGGL_Repair_Notice::enqueue_style();
        wp_enqueue_script('eggl-repair', EGGL_Repair_Module::url('assets/repair.js'), [], EGGL_Repair_Module::asset_version('assets/repair.js'), true);
    }

    /**
     * Marks the claim page as a WooCommerce page, so the theme and WooCommerce style its form
     * fields like the checkout and the withdrawal form (WooCommerce scopes those styles to
     * body.woocommerce-page).
     */
    public function body_class($classes) {
        if (is_singular() && (int) get_queried_object_id() === EGGL_Repair_Settings::page_id()) {
            $classes = array_unique(array_merge($classes, ['woocommerce', 'woocommerce-page', 'eggl-rr-page']));
        }
        return $classes;
    }

    /* ----------------------------------------------------------------------------------------
     * Access
     * ------------------------------------------------------------------------------------- */

    /** The order the current request is allowed to claim for, from ?eggl_rr_order (+ ?eggl_rr_key), or null. */
    private function requested_order() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- access is proven by the order key or ownership below.
        $order_id = isset($_REQUEST['eggl_rr_order']) ? absint($_REQUEST['eggl_rr_order']) : 0;
        $key      = isset($_REQUEST['eggl_rr_key']) ? sanitize_text_field(wp_unslash($_REQUEST['eggl_rr_key'])) : '';
        // phpcs:enable
        if (!$order_id) return null;

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order || $order instanceof WC_Order_Refund) return null;

        $owner = is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id();
        $keyed = $key !== '' && hash_equals($order->get_order_key(), $key);
        return ($owner || $keyed) ? $order : null;
    }

    /**
     * Finds an order by the number and email a guest typed, or null. Uses the withdrawal module's
     * lookup to resolve custom order numbers — but that lookup is fuzzy (it falls back to the
     * number alone or the email alone), so the email is always checked against the order here.
     */
    private function find_order($number, $email) {
        $candidates = [];
        if (function_exists('eggl_wd_find_order') && function_exists('eggl_wd_parse_custom_order_number')) {
            $candidates[] = (int) eggl_wd_find_order(eggl_wd_parse_custom_order_number($number), $email);
        }
        $candidates[] = absint(ltrim(trim($number), '#'));

        foreach (array_unique(array_filter($candidates)) as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order instanceof WC_Order || $order instanceof WC_Order_Refund) continue;
            $number_matches = (string) $order->get_order_number() === ltrim(trim($number), '#') || (string) $order->get_id() === ltrim(trim($number), '#');
            if ($number_matches && $this->email_matches($order, $email)) return $order;
        }
        return null;
    }

    /** The order's billing email, or its customer account's email, case-insensitively. */
    private function email_matches(WC_Order $order, $email) {
        $emails = [$order->get_billing_email()];
        if ($order->get_customer_id() && ($user = get_userdata($order->get_customer_id()))) $emails[] = $user->user_email;
        foreach ($emails as $candidate) {
            if ($candidate !== '' && strcasecmp(trim($candidate), trim($email)) === 0) return true;
        }
        return false;
    }

    /* ----------------------------------------------------------------------------------------
     * Spam protection
     * ------------------------------------------------------------------------------------- */

    private function client_ip() {
        $ip = class_exists('WC_Geolocation') ? WC_Geolocation::get_ip_address() : (isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '');
        return $ip !== '' ? $ip : 'unknown';
    }

    /** Counts one attempt for $bucket from this IP; false once the hourly $limit is reached. */
    private function rate_limit_ok($bucket, $limit) {
        $key   = 'eggl_rr_' . $bucket . '_' . md5($this->client_ip());
        $count = (int) get_transient($key);
        if ($count >= $limit) return false;
        set_transient($key, $count + 1, HOUR_IN_SECONDS);
        return true;
    }

    private function sign_time($time) {
        return $time . '.' . wp_hash('eggl_rr_ts|' . $time, 'nonce');
    }

    /** Whether the signed render time is genuine and at least MIN_SECONDS old. */
    private function time_ok($signed) {
        $parts = explode('.', (string) $signed, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0])) return false;
        if (!hash_equals(wp_hash('eggl_rr_ts|' . $parts[0], 'nonce'), $parts[1])) return false;
        return (time() - (int) $parts[0]) >= self::MIN_SECONDS;
    }

    /* ----------------------------------------------------------------------------------------
     * POST handling (template_redirect, before any output)
     * ------------------------------------------------------------------------------------- */

    public function maybe_handle_post() {
        if (empty($_POST['eggl_rr_action'])) return; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified per action below.
        $action = sanitize_key(wp_unslash($_POST['eggl_rr_action'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $this->errors = new WP_Error();

        if ($action === 'lookup') {
            $this->handle_lookup();
        } elseif ($action === 'submit') {
            $this->handle_submit();
        }
    }

    private function handle_lookup() {
        if (!isset($_POST['eggl_rr_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['eggl_rr_nonce'])), 'eggl_rr_lookup')) {
            $this->errors->add('nonce', __('Your session has expired. Please try again.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            return;
        }
        $number = isset($_POST['eggl_rr_number']) ? sanitize_text_field(wp_unslash($_POST['eggl_rr_number'])) : '';
        $email  = isset($_POST['eggl_rr_email']) ? sanitize_email(wp_unslash($_POST['eggl_rr_email'])) : '';
        $this->posted = ['number' => $number, 'email' => $email];

        if ($number === '' || !is_email($email)) {
            $this->errors->add('fields', __('Please enter your order number and the email address you ordered with.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            return;
        }
        if (!$this->rate_limit_ok('lookup', self::LOOKUP_LIMIT)) {
            $this->errors->add('limit', __('Too many attempts. Please try again in an hour.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            return;
        }

        $order = $this->find_order($number, $email);
        if (!$order) {
            $this->errors->add('not_found', __('We could not find an order with this number and email address.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            return;
        }
        if (!EGGL_Repair_Claims::order_is_claimable($order)) {
            $this->errors->add('not_claimable', __('This order has no items that are still within the legal guarantee period.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            return;
        }
        wp_safe_redirect(EGGL_Repair_Settings::form_url($order));
        exit;
    }

    private function handle_submit() {
        if (!isset($_POST['eggl_rr_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['eggl_rr_nonce'])), 'eggl_rr_submit')) {
            $this->errors->add('nonce', __('Your session has expired. Please try again.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            return;
        }

        $order = $this->requested_order();
        if (!$order) {
            $this->errors->add('order', __('We could not verify your order. Please look it up again.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            return;
        }

        $raw_items = isset($_POST['eggl_rr_items']) && is_array($_POST['eggl_rr_items']) ? wp_unslash($_POST['eggl_rr_items']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ints below
        $raw_qty   = isset($_POST['eggl_rr_qty']) && is_array($_POST['eggl_rr_qty']) ? wp_unslash($_POST['eggl_rr_qty']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ints below
        $this->posted = [
            'items'       => array_map('absint', $raw_items),
            'qty'         => array_map('absint', $raw_qty),
            'description' => isset($_POST['eggl_rr_description']) ? sanitize_textarea_field(wp_unslash($_POST['eggl_rr_description'])) : '',
            'noticed'     => isset($_POST['eggl_rr_noticed']) ? sanitize_text_field(wp_unslash($_POST['eggl_rr_noticed'])) : '',
            'remedy'      => isset($_POST['eggl_rr_remedy']) ? sanitize_key(wp_unslash($_POST['eggl_rr_remedy'])) : '',
            'first_name'  => isset($_POST['eggl_rr_first_name']) ? sanitize_text_field(wp_unslash($_POST['eggl_rr_first_name'])) : '',
            'last_name'   => isset($_POST['eggl_rr_last_name']) ? sanitize_text_field(wp_unslash($_POST['eggl_rr_last_name'])) : '',
            'email'       => isset($_POST['eggl_rr_email']) ? sanitize_email(wp_unslash($_POST['eggl_rr_email'])) : '',
            'phone'       => isset($_POST['eggl_rr_phone']) ? sanitize_text_field(wp_unslash($_POST['eggl_rr_phone'])) : '',
        ];

        // Bots: honeypot filled, or submitted faster than a person could. Fail quietly-but-clearly.
        $honeypot = isset($_POST['eggl_rr_website']) ? trim(sanitize_text_field(wp_unslash($_POST['eggl_rr_website']))) : '';
        $ts       = isset($_POST['eggl_rr_ts']) ? sanitize_text_field(wp_unslash($_POST['eggl_rr_ts'])) : '';
        if ($honeypot !== '' || !$this->time_ok($ts)) {
            $this->errors->add('spam', __('Your claim could not be sent. Please wait a moment and try again.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            return;
        }

        $claimable = EGGL_Repair_Claims::claimable_items($order);
        $open      = EGGL_Repair_Claims::items_with_open_claim($order->get_id());
        $items     = [];
        foreach ($this->posted['items'] as $item_id) {
            if (!isset($claimable[$item_id]) || in_array($item_id, $open, true)) continue;
            $qty = isset($this->posted['qty'][$item_id]) ? (int) $this->posted['qty'][$item_id] : 1;
            $items[$item_id] = max(1, min($qty, (int) $claimable[$item_id]->get_quantity()));
        }

        if (!$items) {
            $this->errors->add('items', __('Please choose the item(s) that are defective.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        if (mb_strlen($this->posted['description']) < 10) {
            $this->errors->add('description', __('Please describe the defect (at least 10 characters).', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        if (!in_array($this->posted['remedy'], ['repair', 'replacement'], true)) {
            $this->errors->add('remedy', __('Please choose whether you would like a repair or a replacement.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        if ($this->posted['first_name'] === '' || $this->posted['last_name'] === '') {
            $this->errors->add('name', __('Please enter your first and last name.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        if (!is_email($this->posted['email'])) {
            $this->errors->add('email', __('Please enter a valid email address.', 'garanlabs-eu-guarantee-notice-durability-labels'));
        }
        $noticed = '';
        if ($this->posted['noticed'] !== '') {
            $date = DateTime::createFromFormat('!Y-m-d', $this->posted['noticed']);
            if ($date && $date->format('Y-m-d') === $this->posted['noticed'] && $date->getTimestamp() <= time()) $noticed = $this->posted['noticed'];
        }
        if ($this->errors->has_errors()) return;

        if (!$this->rate_limit_ok('submit', self::SUBMIT_LIMIT)) {
            $this->errors->add('limit', __('Too many claims sent from your connection. Please try again in an hour.', 'garanlabs-eu-guarantee-notice-durability-labels'));
            return;
        }

        $photos = $this->handle_photos();
        if (is_wp_error($photos)) {
            $this->errors = $photos;
            return;
        }

        $claim_id = EGGL_Repair_Claims::create([
            'order'       => $order,
            'items'       => $items,
            'remedy'      => $this->posted['remedy'],
            'description' => $this->posted['description'],
            'noticed'     => $noticed,
            'email'       => $this->posted['email'],
            'name'        => trim($this->posted['first_name'] . ' ' . $this->posted['last_name']),
            'phone'       => $this->posted['phone'],
            'photos'      => $photos,
        ]);
        if (is_wp_error($claim_id)) {
            foreach ($photos as $attachment_id) wp_delete_attachment($attachment_id, true);
            $this->errors = $claim_id;
            return;
        }

        wp_safe_redirect(add_query_arg([
            'eggl_rr_sent'  => EGGL_Repair_Claims::number($claim_id),
            'eggl_rr_order' => $order->get_id(),
            'eggl_rr_key'   => $order->get_order_key(),
        ], get_permalink(EGGL_Repair_Settings::page_id())));
        exit;
    }

    /**
     * Uploads the photos from eggl_rr_photos[] into the Media Library (images only, size-capped).
     * @return int[]|WP_Error attachment ids
     */
    private function handle_photos() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_submit().
        if (EGGL_Repair_Settings::get('photos') !== 'yes' || empty($_FILES['eggl_rr_photos']['name']) || !is_array($_FILES['eggl_rr_photos']['name'])) return [];

        $files = $_FILES['eggl_rr_photos']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by wp_handle_upload() below.
        // phpcs:enable
        $max   = (int) EGGL_Repair_Settings::get('max_photos');
        $mimes = ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

        $picked = [];
        foreach ($files['name'] as $i => $name) {
            if ((int) $files['error'][$i] === UPLOAD_ERR_NO_FILE || $name === '') continue;
            $picked[] = $i;
        }
        if (count($picked) > $max) {
            /* translators: %d: maximum number of photos */
            return new WP_Error('photos', sprintf(__('Please attach no more than %d photos.', 'garanlabs-eu-guarantee-notice-durability-labels'), $max));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $ids = [];
        foreach ($picked as $i) {
            $file = [
                'name'     => sanitize_file_name($files['name'][$i]),
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => (int) $files['error'][$i],
                'size'     => (int) $files['size'][$i],
            ];
            $error = null;
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = __('A photo could not be uploaded. Please try again.', 'garanlabs-eu-guarantee-notice-durability-labels');
            } elseif ($file['size'] > self::MAX_PHOTO_BYTES) {
                $error = __('Each photo must be 5 MB or smaller.', 'garanlabs-eu-guarantee-notice-durability-labels');
            } else {
                $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $mimes);
                if (empty($check['type']) || !in_array($check['type'], $mimes, true)) {
                    $error = __('Photos must be JPG, PNG or WebP images.', 'garanlabs-eu-guarantee-notice-durability-labels');
                }
            }
            if ($error === null) {
                $_FILES['eggl_rr_photo_single'] = $file;
                $id = media_handle_upload('eggl_rr_photo_single', 0, ['post_title' => __('Defect photo', 'garanlabs-eu-guarantee-notice-durability-labels')], ['test_form' => false, 'mimes' => $mimes]);
                unset($_FILES['eggl_rr_photo_single']);
                if (is_wp_error($id)) {
                    $error = $id->get_error_message();
                } else {
                    $ids[] = (int) $id;
                }
            }
            if ($error !== null) {
                foreach ($ids as $id) wp_delete_attachment($id, true);
                return new WP_Error('photos', $error);
            }
        }
        return $ids;
    }

    /* ----------------------------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------------------------- */

    public function shortcode($atts = []) {
        EGGL_Repair_Notice::enqueue_style();
        if (!EGGL_Repair_Settings::enabled()) {
            return '<div class="eggl-rr"><p>' . esc_html__('Defects cannot be reported online at the moment. Please contact us directly.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p></div>';
        }
        ob_start();
        echo '<div class="eggl-rr">';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        $sent  = isset($_GET['eggl_rr_sent']) ? sanitize_text_field(wp_unslash($_GET['eggl_rr_sent'])) : '';
        $order = $this->requested_order();

        if ($sent !== '' && $order) {
            EGGL_Repair_Module::template('form-sent.php', ['claim_number' => $sent, 'order' => $order]);
        } elseif ($order) {
            $this->render_claim_form($order);
        } else {
            EGGL_Repair_Module::template('form-lookup.php', [
                'errors' => $this->errors,
                'posted' => $this->posted,
                'orders' => $this->customer_orders(),
                'nonce'  => wp_create_nonce('eggl_rr_lookup'),
            ]);
        }

        echo '</div>';
        return ob_get_clean();
    }

    /** Logged-in customer's orders with something left to claim for (most recent 20). */
    private function customer_orders() {
        if (!is_user_logged_in()) return [];
        $orders = wc_get_orders([
            'customer_id' => get_current_user_id(),
            'status'      => array_map(function ($s) { return 'wc-' . $s; }, EGGL_Repair_Claims::claimable_order_statuses()),
            'limit'       => 20,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);
        return array_values(array_filter($orders, ['EGGL_Repair_Claims', 'order_is_claimable']));
    }

    private function render_claim_form(WC_Order $order) {
        $claimable = EGGL_Repair_Claims::claimable_items($order);
        $posted    = $this->posted ?: [
            'items' => [], 'qty' => [], 'description' => '', 'noticed' => '', 'remedy' => '',
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
        ];

        EGGL_Repair_Module::template('form-claim.php', [
            'order'      => $order,
            'items'      => $claimable,
            'open_items' => EGGL_Repair_Claims::items_with_open_claim($order->get_id()),
            'item_ends'  => array_map(function ($item) use ($order) { return EGGL_Repair_Claims::item_end($item, $order); }, $claimable),
            'errors'     => $this->errors,
            'posted'     => $posted,
            'photos'     => EGGL_Repair_Settings::get('photos') === 'yes' ? (int) EGGL_Repair_Settings::get('max_photos') : 0,
            'nonce'      => wp_create_nonce('eggl_rr_submit'),
            'ts'         => $this->sign_time(time()),
            'key'        => $order->get_order_key(),
            'customer_orders' => $this->customer_orders(),
        ]);
    }

    /* ----------------------------------------------------------------------------------------
     * My account
     * ------------------------------------------------------------------------------------- */

    /** "Report a defect" next to orders with something left to claim for. */
    public function my_orders_action($actions, $order) {
        if (EGGL_Repair_Settings::get('account_link') !== 'yes' || !$order instanceof WC_Order) return $actions;
        $url = EGGL_Repair_Settings::form_url($order);
        if ($url === '' || !EGGL_Repair_Claims::order_is_claimable($order)) return $actions;

        $actions['eggl-repair'] = [
            'url'  => $url,
            'name' => __('Report a defect', 'garanlabs-eu-guarantee-notice-durability-labels'),
        ];
        return $actions;
    }

    /** On the order's My account page: its defect claims, guarantee extensions and a "Report a defect" button. */
    public function render_order_claims($order) {
        if (EGGL_Repair_Settings::get('account_link') !== 'yes' || !$order instanceof WC_Order) return;
        if (!function_exists('is_account_page') || !is_account_page()) return;

        $claims = EGGL_Repair_Claims::for_order($order->get_id());
        $url    = EGGL_Repair_Claims::order_is_claimable($order) ? EGGL_Repair_Settings::form_url($order) : '';
        if (!$claims && $url === '') return;

        EGGL_Repair_Notice::enqueue_style();
        EGGL_Repair_Module::template('myaccount-claims.php', ['order' => $order, 'claims' => $claims, 'url' => $url]);
    }
}
