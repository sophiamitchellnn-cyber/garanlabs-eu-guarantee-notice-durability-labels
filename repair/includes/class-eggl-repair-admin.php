<?php
if (!defined('ABSPATH')) exit;

/**
 * EU Guarantee & GARAN → Right to Repair: a "Claims" tab (list + single-claim view where the
 * merchant changes the status and messages the customer) and a "Settings" tab
 * (EGGL_Repair_Settings::render()), plus a "Defect claims" box on the WooCommerce order screen.
 */
class EGGL_Repair_Admin {

    const PAGE_SLUG = 'eggl-repair';

    /** @var EGGL_Repair_Settings */
    private $settings;

    public function __construct(EGGL_Repair_Settings $settings) {
        $this->settings = $settings;
    }

    public static function page_url($args = []) {
        return add_query_arg(array_merge(['page' => self::PAGE_SLUG], $args), admin_url('admin.php'));
    }

    public static function claim_url($claim_id) {
        return self::page_url(['claim' => (int) $claim_id]);
    }

    public function register_menu() {
        $open  = EGGL_Repair_Claims::count_by_status();
        $count = $open['received'] ?? 0;
        $badge = $count ? ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . (int) $count . '</span></span>' : '';

        add_submenu_page(
            EGGL_Settings::PAGE_SLUG,
            __('Right to Repair', 'garanlabs-eu-guarantee-notice-durability-labels'),
            __('Right to Repair', 'garanlabs-eu-guarantee-notice-durability-labels') . $badge,
            EGGL_Settings::capability(),
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function enqueue_assets($hook) {
        if (strpos((string) $hook, self::PAGE_SLUG) === false) return;
        wp_enqueue_style('eggl-admin', EGGL_URL . 'assets/css/eggl-admin.css', [], eggl_asset_version('assets/css/eggl-admin.css'));
        wp_enqueue_style('eggl-repair-admin', EGGL_Repair_Module::url('assets/repair-admin.css'), ['eggl-admin'], EGGL_Repair_Module::asset_version('assets/repair-admin.css'));
        // Settings tab: WooCommerce's enhanced select for "Orders that can be claimed".
        wp_enqueue_style('woocommerce_admin_styles');
        wp_enqueue_script('wc-enhanced-select');
    }

    /* ----------------------------------------------------------------------------------------
     * Actions (admin_init, before output)
     * ------------------------------------------------------------------------------------- */

    public function maybe_handle_actions() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page check only; each action verifies its nonce.
        if (!isset($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== self::PAGE_SLUG) return;
        if (!current_user_can(EGGL_Settings::capability())) return;

        // Single claim: change status / send message.
        if (isset($_POST['eggl_rr_update']) && isset($_POST['eggl_rr_claim'])) {
            $claim_id = absint($_POST['eggl_rr_claim']);
            check_admin_referer('eggl_rr_update_' . $claim_id);
            $status  = isset($_POST['eggl_rr_status']) ? sanitize_key(wp_unslash($_POST['eggl_rr_status'])) : '';
            $message = isset($_POST['eggl_rr_message']) ? sanitize_textarea_field(wp_unslash($_POST['eggl_rr_message'])) : '';

            if ($status === 'rejected' && $message === '' && EGGL_Repair_Claims::status($claim_id) !== 'rejected') {
                wp_safe_redirect(add_query_arg('eggl_rr_notice', 'reason_required', self::claim_url($claim_id)));
                exit;
            }
            $changed = EGGL_Repair_Claims::set_status($claim_id, $status, $message);
            wp_safe_redirect(add_query_arg('eggl_rr_notice', $changed ? 'updated' : 'unchanged', self::claim_url($claim_id)));
            exit;
        }

        // Single claim: delete.
        if (isset($_GET['eggl_rr_delete'])) {
            $claim_id = absint($_GET['eggl_rr_delete']);
            check_admin_referer('eggl_rr_delete_' . $claim_id);
            $this->delete_claim($claim_id);
            wp_safe_redirect(add_query_arg('eggl_rr_notice', 'deleted', self::page_url()));
            exit;
        }

        // Bulk actions from the list table.
        $action = '';
        if (isset($_POST['action']) && $_POST['action'] !== '-1') $action = sanitize_key(wp_unslash($_POST['action']));
        elseif (isset($_POST['action2']) && $_POST['action2'] !== '-1') $action = sanitize_key(wp_unslash($_POST['action2']));
        if ($action !== '' && !empty($_POST['claims'])) {
            check_admin_referer('bulk-eggl_rr_claims');
            $ids = array_map('absint', (array) wp_unslash($_POST['claims']));
            foreach ($ids as $claim_id) {
                if ($action === 'delete') {
                    $this->delete_claim($claim_id);
                } elseif ($action === 'mark_in_progress') {
                    EGGL_Repair_Claims::set_status($claim_id, 'in_progress');
                }
            }
            wp_safe_redirect(add_query_arg('eggl_rr_notice', 'bulk', self::page_url()));
            exit;
        }
    }

    /** Deletes a claim and its uploaded photos. */
    private function delete_claim($claim_id) {
        if (EGGL_Repair_Claims::status($claim_id) === '') return;
        foreach ((array) EGGL_Repair_Claims::meta($claim_id, 'photos', []) as $attachment_id) {
            wp_delete_attachment((int) $attachment_id, true);
        }
        $order = wc_get_order((int) EGGL_Repair_Claims::meta($claim_id, 'order_id', 0));
        if ($order) {
            /* translators: %s: claim number */
            $order->add_order_note(sprintf(__('Defect claim #%s deleted.', 'garanlabs-eu-guarantee-notice-durability-labels'), EGGL_Repair_Claims::number($claim_id)));
        }
        wp_delete_post($claim_id, true);
    }

    /* ----------------------------------------------------------------------------------------
     * Page
     * ------------------------------------------------------------------------------------- */

    public function render() {
        if (!current_user_can(EGGL_Settings::capability())) return;
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view switches.
        $tab      = (isset($_GET['tab']) && sanitize_key(wp_unslash($_GET['tab'])) === 'settings') ? 'settings' : 'claims';
        $claim_id = isset($_GET['claim']) ? absint($_GET['claim']) : 0;
        $notice   = isset($_GET['eggl_rr_notice']) ? sanitize_key(wp_unslash($_GET['eggl_rr_notice'])) : '';
        // phpcs:enable
        ?>
        <div class="wrap eggl-settings eggl-rr-admin">
            <div class="eggl-page-header">
                <h1><?php esc_html_e('Right to Repair', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h1>
                <p class="eggl-lede"><?php esc_html_e('Directive (EU) 2024/1799: when an item turns out to be defective within the legal guarantee, the customer chooses repair or replacement, and a repair extends the legal guarantee by 12 months. Customers report defects through the claim form; you handle the claims here. This plugin is a helper and does not provide legal advice.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></p>
            </div>

            <?php $this->render_notice($notice); ?>

            <div class="eggl-tabs">
                <a href="<?php echo esc_url(self::page_url()); ?>" class="eggl-tabs__item<?php echo $tab === 'claims' ? ' is-active' : ''; ?>"><?php esc_html_e('Claims', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></a>
                <a href="<?php echo esc_url(self::page_url(['tab' => 'settings'])); ?>" class="eggl-tabs__item<?php echo $tab === 'settings' ? ' is-active' : ''; ?>"><?php esc_html_e('Settings', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></a>
            </div>

            <?php
            if ($tab === 'settings') {
                settings_errors();
                $this->settings->render();
            } elseif ($claim_id && EGGL_Repair_Claims::status($claim_id) !== '') {
                $this->render_claim($claim_id);
            } else {
                $this->render_list();
            }
            ?>
        </div>
        <?php
    }

    private function render_notice($notice) {
        $messages = [
            'updated'         => ['success', __('Claim updated. The customer has been emailed.', 'garanlabs-eu-guarantee-notice-durability-labels')],
            'unchanged'       => ['info', __('Nothing changed: pick a different status or write a message.', 'garanlabs-eu-guarantee-notice-durability-labels')],
            'reason_required' => ['error', __('Please write the reason for the rejection — it is sent to the customer.', 'garanlabs-eu-guarantee-notice-durability-labels')],
            'deleted'         => ['success', __('Claim deleted.', 'garanlabs-eu-guarantee-notice-durability-labels')],
            'bulk'            => ['success', __('Bulk action applied.', 'garanlabs-eu-guarantee-notice-durability-labels')],
        ];
        if (!isset($messages[$notice])) return;
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($messages[$notice][0]), esc_html($messages[$notice][1]));
    }

    private function render_list() {
        require_once __DIR__ . '/class-eggl-repair-list-table.php';
        $table = new EGGL_Repair_List_Table();
        $table->prepare_items();

        if (!EGGL_Repair_Settings::page_is_published()) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Customers cannot report defects yet: publish the "Report a defect" page (see the Settings tab).', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p></div>';
        }
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">';
        $table->views();
        $table->search_box(__('Search claims', 'garanlabs-eu-guarantee-notice-durability-labels'), 'eggl-rr-search');
        echo '</form>';
        echo '<form method="post">';
        $table->display();
        echo '</form>';
    }

    private function render_claim($claim_id) {
        $status   = EGGL_Repair_Claims::status($claim_id);
        $statuses = EGGL_Repair_Claims::status_labels();
        $remedy   = (string) EGGL_Repair_Claims::meta($claim_id, 'remedy', 'repair');
        $order    = wc_get_order((int) EGGL_Repair_Claims::meta($claim_id, 'order_id', 0));
        $extended = (array) EGGL_Repair_Claims::meta($claim_id, 'extended', []);
        $photos   = (array) EGGL_Repair_Claims::meta($claim_id, 'photos', []);
        $noticed  = (string) EGGL_Repair_Claims::meta($claim_id, 'noticed', '');
        $users    = [];
        ?>
        <p><a href="<?php echo esc_url(self::page_url()); ?>">&larr; <?php esc_html_e('All claims', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></a></p>

        <div class="eggl-rr-claim">
            <div class="eggl-card eggl-rr-claim__main">
                <div class="eggl-card__head">
                    <h2>
                        <?php
                        /* translators: %s: claim number */
                        printf(esc_html__('Defect claim #%s', 'garanlabs-eu-guarantee-notice-durability-labels'), esc_html(EGGL_Repair_Claims::number($claim_id)));
                        ?>
                        <mark class="eggl-rr-status eggl-rr-status--<?php echo esc_attr($status); ?>"><?php echo esc_html($statuses[$status]); ?></mark>
                    </h2>
                    <p><?php
                    /* translators: %s: date and time */
                    printf(esc_html__('Received %s', 'garanlabs-eu-guarantee-notice-durability-labels'), esc_html(get_the_date(get_option('date_format') . ' ' . get_option('time_format'), $claim_id)));
                    ?></p>
                </div>
                <div class="eggl-card__body">
                    <dl class="eggl-rr-claim__details">
                        <dt><?php esc_html_e('Order', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></dt>
                        <dd>
                            <?php if ($order) : ?>
                                <a href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html($order->get_order_number()); ?></a>
                                — <?php echo esc_html(wc_format_datetime($order->get_date_created())); ?>
                            <?php else : ?>
                                <?php esc_html_e('(order deleted)', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>
                            <?php endif; ?>
                        </dd>

                        <dt><?php esc_html_e('Items', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></dt>
                        <dd>
                            <?php foreach ((array) EGGL_Repair_Claims::meta($claim_id, 'items', []) as $line) :
                                $item = $order ? $order->get_item((int) $line['item_id']) : null;
                                $end  = ($item && $order) ? EGGL_Repair_Claims::item_end($item, $order) : null;
                                ?>
                                <div class="eggl-rr-claim__item">
                                    <strong><?php echo esc_html($line['name']); ?></strong> × <?php echo (int) $line['qty']; ?>
                                    <?php if ($end) : ?>
                                        <br><span class="description"><?php
                                        /* translators: %s: date */
                                        printf(esc_html__('Legal guarantee until %s', 'garanlabs-eu-guarantee-notice-durability-labels'), esc_html(wp_date(get_option('date_format'), $end->getTimestamp())));
                                        if (!empty($extended[$line['item_id']])) echo ' ' . esc_html__('(extended after repair)', 'garanlabs-eu-guarantee-notice-durability-labels');
                                        ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </dd>

                        <dt><?php esc_html_e('Customer wants', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></dt>
                        <dd><strong><?php echo esc_html(EGGL_Repair_Claims::remedy_labels()[$remedy] ?? ''); ?></strong></dd>

                        <dt><?php esc_html_e('Defect', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></dt>
                        <dd><?php echo wp_kses_post(wpautop(esc_html((string) EGGL_Repair_Claims::meta($claim_id, 'description', '')))); ?></dd>

                        <?php if ($noticed !== '') : ?>
                            <dt><?php esc_html_e('Noticed on', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></dt>
                            <dd><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($noticed))); ?></dd>
                        <?php endif; ?>

                        <?php if ($photos) : ?>
                            <dt><?php esc_html_e('Photos', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></dt>
                            <dd class="eggl-rr-claim__photos">
                                <?php foreach ($photos as $photo) : ?>
                                    <a href="<?php echo esc_url(wp_get_attachment_url($photo)); ?>" target="_blank"><?php echo wp_get_attachment_image($photo, 'thumbnail'); ?></a>
                                <?php endforeach; ?>
                            </dd>
                        <?php endif; ?>

                        <dt><?php esc_html_e('Contact', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></dt>
                        <dd>
                            <?php echo esc_html((string) EGGL_Repair_Claims::meta($claim_id, 'name', '')); ?><br>
                            <?php $email = (string) EGGL_Repair_Claims::meta($claim_id, 'email', ''); ?>
                            <a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a>
                            <?php $phone = (string) EGGL_Repair_Claims::meta($claim_id, 'phone', ''); ?>
                            <?php if ($phone !== '') : ?><br><?php echo esc_html($phone); ?><?php endif; ?>
                        </dd>
                    </dl>
                </div>
            </div>

            <div class="eggl-rr-claim__side">
                <div class="eggl-card">
                    <div class="eggl-card__head"><h2><?php esc_html_e('Update claim', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h2></div>
                    <div class="eggl-card__body">
                        <form method="post" action="<?php echo esc_url(self::claim_url($claim_id)); ?>">
                            <?php wp_nonce_field('eggl_rr_update_' . $claim_id); ?>
                            <input type="hidden" name="eggl_rr_claim" value="<?php echo (int) $claim_id; ?>">
                            <p>
                                <label for="eggl_rr_status"><strong><?php esc_html_e('Status', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></strong></label><br>
                                <select id="eggl_rr_status" name="eggl_rr_status">
                                    <?php foreach ($statuses as $key => $label) : ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($status, $key); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </p>
                            <p class="description">
                                <?php
                                /* translators: %d: number of months */
                                printf(esc_html__('"Repaired" extends the legal guarantee of the claimed items by %d months and records the new end date on the order.', 'garanlabs-eu-guarantee-notice-durability-labels'), (int) EGGL_Repair_Settings::extension_months());
                                ?>
                            </p>
                            <p>
                                <label for="eggl_rr_message"><strong><?php esc_html_e('Message to the customer', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></strong></label>
                                <textarea id="eggl_rr_message" name="eggl_rr_message" rows="5" class="widefat" placeholder="<?php esc_attr_e('Optional — required when rejecting. E.g. how to send the item in.', 'garanlabs-eu-guarantee-notice-durability-labels'); ?>"></textarea>
                            </p>
                            <p><button type="submit" name="eggl_rr_update" value="1" class="button button-primary"><?php esc_html_e('Update and email customer', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></button></p>
                        </form>
                        <p class="eggl-rr-claim__delete">
                            <a class="submitdelete" href="<?php echo esc_url(wp_nonce_url(self::page_url(['eggl_rr_delete' => $claim_id]), 'eggl_rr_delete_' . $claim_id)); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this claim permanently? Its photos are deleted too.', 'garanlabs-eu-guarantee-notice-durability-labels')); ?>');"><?php esc_html_e('Delete claim', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></a>
                        </p>
                    </div>
                </div>

                <div class="eggl-card">
                    <div class="eggl-card__head"><h2><?php esc_html_e('History', 'garanlabs-eu-guarantee-notice-durability-labels'); ?></h2></div>
                    <div class="eggl-card__body">
                        <ol class="eggl-rr-claim__log">
                            <?php foreach (array_reverse((array) EGGL_Repair_Claims::meta($claim_id, 'log', [])) as $entry) :
                                $uid = (int) ($entry['user'] ?? 0);
                                if ($uid && !isset($users[$uid])) {
                                    $user = get_userdata($uid);
                                    $users[$uid] = $user ? $user->display_name : '';
                                }
                                ?>
                                <li>
                                    <strong><?php echo esc_html($statuses[$entry['status']] ?? ''); ?></strong>
                                    <span class="description"><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $entry['time'])); ?><?php echo $uid && $users[$uid] !== '' ? ' — ' . esc_html($users[$uid]) : ''; ?></span>
                                    <?php if (!empty($entry['message'])) : ?>
                                        <div><?php echo wp_kses_post(wpautop(esc_html($entry['message']))); ?></div>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /* ----------------------------------------------------------------------------------------
     * Order screen box
     * ------------------------------------------------------------------------------------- */

    public function add_order_meta_box() {
        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
        add_meta_box('eggl-rr-order-claims', __('Defect claims', 'garanlabs-eu-guarantee-notice-durability-labels'), [$this, 'render_order_meta_box'], $screen, 'side', 'default');
    }

    public function render_order_meta_box($post_or_order) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order instanceof WP_Post ? $post_or_order->ID : 0);
        if (!$order) return;

        $claims = EGGL_Repair_Claims::for_order($order->get_id());
        if (!$claims) {
            echo '<p class="description">' . esc_html__('No defect claims for this order.', 'garanlabs-eu-guarantee-notice-durability-labels') . '</p>';
        } else {
            $statuses = EGGL_Repair_Claims::status_labels();
            echo '<ul>';
            foreach ($claims as $claim) {
                $status = EGGL_Repair_Claims::status($claim);
                printf('<li><a href="%s">#%s</a> — %s <span class="description">(%s)</span></li>',
                    esc_url(self::claim_url($claim->ID)), esc_html(EGGL_Repair_Claims::number($claim->ID)),
                    esc_html($statuses[$status] ?? ''), esc_html(get_the_date('', $claim)));
            }
            echo '</ul>';
        }

        $lines = [];
        foreach ($order->get_items() as $item) {
            $extended = (string) $item->get_meta(EGGL_Repair_Claims::ITEM_META);
            if ($extended !== '') {
                /* translators: 1: product name, 2: date */
                $lines[] = sprintf(__('%1$s: guarantee extended until %2$s', 'garanlabs-eu-guarantee-notice-durability-labels'), $item->get_name(), date_i18n(get_option('date_format'), strtotime($extended)));
            }
        }
        if ($lines) {
            echo '<p><strong>' . esc_html__('Guarantee extensions', 'garanlabs-eu-guarantee-notice-durability-labels') . '</strong></p><ul>';
            foreach ($lines as $line) echo '<li>' . esc_html($line) . '</li>';
            echo '</ul>';
        }
    }
}
