<?php

namespace EGGL\Withdrawal\Admin;

use EGGL\Withdrawal\Install;
use EGGL\Withdrawal\Package;
use EGGL\Withdrawal\Settings;
use EGGL\Withdrawal\WithdrawalOrder;

defined( 'ABSPATH' ) || exit;

class Admin {

	protected static $order_item_map = array();

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register_scripts' ) );
		add_action( 'admin_init', array( __CLASS__, 'on_init' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_filter( 'woocommerce_screen_ids', array( __CLASS__, 'register_screen_ids' ), 10 );

		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'order_withdrawal_request_details' ), 100, 1 );
		add_filter( 'woocommerce_admin_order_actions', array( __CLASS__, 'admin_order_actions' ), 1500, 2 );
		add_action( 'woocommerce_admin_order_actions_end', array( __CLASS__, 'inline_order_actions' ) );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'register_hidden_itemmeta' ), 10, 2 );
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'display_custom_itemmeta' ), 10, 3 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'process_withdrawal_rejection' ), 45 );
		add_filter( 'woocommerce_menu_order_count', array( __CLASS__, 'menu_order_count' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_order_meta_box' ), 35 );

		add_action( 'woocommerce_system_status_report', array( __CLASS__, 'status_report' ) );

		add_action( 'admin_post_eggl_wd_create_withdrawal_page', array( __CLASS__, 'create_withdrawal_page' ) );
	}

	public static function create_withdrawal_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( wc_clean( wp_unslash( $_GET['_wpnonce'] ) ), 'eggl_wd_create_withdrawal_page' ) ) {
			wp_die( '', 400 );
		}

		$page_id     = eggl_wd_get_withdrawal_page_id();
		$page_exists = ( -1 !== $page_id && ! empty( $page_id ) ) ? get_post( $page_id ) : false;

		if ( $page_exists && ! Package::page_has_withdrawal_form( $page_id ) ) {
			Install::update_page_content( $page_id, '[eggl_wd_order_withdrawal_request_form]' );
		} elseif ( ! $page_exists ) {
			Install::maybe_create_page();
		}

		wp_safe_redirect( esc_url_raw( wp_get_referer() ? wp_get_referer() : Settings::get_settings_url() ) );
	}

	public static function get_current_withdrawal_page_status() {
		$page_id            = eggl_wd_get_withdrawal_page_id();
		$page_exists        = ( -1 !== $page_id && ! empty( $page_id ) ) ? get_post( $page_id ) : false;
		$page_status        = $page_exists ? get_post_status( $page_id ) : 'does-not-exist';
		$page_has_shortcode = $page_exists && Package::page_has_withdrawal_form( $page_id );
		$page_is_valid      = 'publish' === $page_status && $page_has_shortcode;
		$fix_url            = wp_nonce_url( admin_url( 'admin-post.php?action=eggl_wd_create_withdrawal_page' ), 'eggl_wd_create_withdrawal_page' );
		$edit_url           = $page_exists ? admin_url( 'post.php?post=' . absint( $page_id ) . '&action=edit' ) : $fix_url;
		$invalid_reason     = '';

		// What the merchant should do next, shown as a button next to the status badge.
		if ( ! $page_exists ) {
			$invalid_reason = _x( 'Page is missing', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' );
			$action_label   = _x( 'Create page', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' );
			$action_url     = $fix_url;
		} elseif ( ! $page_has_shortcode ) {
			$invalid_reason = _x( 'Shortcode is missing', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' );
			$action_label   = _x( 'Add form to page', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' );
			$action_url     = $fix_url;
		} elseif ( 'publish' !== $page_status ) {
			$invalid_reason = _x( 'Invisible to visitors', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' );
			$action_label   = _x( 'Edit & publish page', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' );
			$action_url     = $edit_url;
		} else {
			$action_label = _x( 'Edit page', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' );
			$action_url   = $edit_url;
		}

		return array(
			'id'             => $page_id,
			'exists'         => $page_exists,
			'has_shortcode'  => $page_has_shortcode,
			'status'         => $page_is_valid ? 'valid' : 'invalid',
			'invalid_reason' => $invalid_reason,
			'edit_url'       => $action_url,
			'action_label'   => $action_label,
			'view_url'       => $page_is_valid ? get_permalink( $page_id ) : '',
		);
	}

	/**
	 * Status badge (not clickable) plus clearly labelled action buttons for the withdrawal page setting.
	 */
	public static function get_withdrawal_page_status_html() {
		$status = self::get_current_withdrawal_page_status();
		$label  = 'valid' === $status['status'] ? esc_html_x( 'Valid', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) : sprintf( esc_html_x( 'Invalid: %1$s', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ), esc_html( $status['invalid_reason'] ) );

		$html  = '<span class="eggl-wd-page-status-row">';
		$html .= '<span class="withdrawal-page-status withdrawal-page-' . esc_attr( $status['status'] ) . '">' . $label . '</span>';
		$html .= '<a href="' . esc_url( $status['edit_url'] ) . '" class="button button-small eggl-wd-page-action' . ( 'valid' === $status['status'] ? '' : ' eggl-wd-page-action--primary' ) . '">' . esc_html( $status['action_label'] ) . '</a>';

		if ( $status['view_url'] ) {
			$html .= '<a href="' . esc_url( $status['view_url'] ) . '" class="button button-small eggl-wd-page-action" target="_blank" rel="noopener">' . esc_html_x( 'View page', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) . ' <span class="dashicons dashicons-external" aria-hidden="true"></span></a>';
		}

		return $html . '</span>';
	}

	public static function status_report() {
		$template_info = self::get_template_info();
		?>
		<table class="wc_status_table widefat" id="status-table-eggl-withdrawal" cellspacing="0">
			<thead>
			<tr>
				<th colspan="3" data-export-label="Order Withdrawal Button for WooCommerce"><h2><?php echo esc_html_x( 'EU Order Withdrawal', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></h2></th>
			</tr>
			</thead>
			<tbody>
			<tr>
				<td data-export-label="Order withdrawal Button for WooCommerce Database Version"><?php echo esc_html_x( 'Database Version', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></td>
				<td class="help">&nbsp</td>
				<td><?php echo esc_html( get_option( 'eggl_wd_module_db_version' ) ); ?></td>
			</tr>
			<tr>
				<td data-export-label="Order withdrawal Button for WooCommerce Overrides"><?php echo esc_html_x( 'Overrides', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></td>
				<td class="help">&nbsp;</td>
				<td>
					<?php if ( ! empty( $template_info['files'] ) ) : ?>
						<?php foreach ( $template_info['files'] as $file ) : ?>
							<?php printf( '<code>%s</code>', esc_html( str_replace( WP_CONTENT_DIR . '/themes/', '', $file['theme_file'] ) ) ); ?>
							<?php if ( $file['outdated'] ) : ?>
								<?php printf( esc_html_x( 'Version %1$s is out of date. The core version %2$s is available at: %3$s', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ), '<span class="red" style="color:red">' . esc_html( $file['theme_version'] ) . '</span>', esc_html( $file['core_version'] ), '<code>' . esc_html( str_replace( WP_PLUGIN_DIR, '', $file['core_file'] ) ) . '</code>' ); ?>
							<?php endif; ?>
							<br/>
						<?php endforeach; ?>
					<?php else : ?>
						&ndash;
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( true === $template_info['has_outdated_templates'] ) : ?>
				<tr>
					<td data-export-label="Order withdrawal Button for WooCommerce Outdated Templates"><?php echo esc_html_x( 'Outdated templates', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?>:</td>
					<td class="help">&nbsp;</td>
					<td>
						<mark class="error">
							<span class="dashicons dashicons-warning"></span>
						</mark>
					</td>
				</tr>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	public static function get_template_info() {
		$core_path     = Package::get_path( 'templates' );
		$files         = \WC_Admin_Status::scan_template_files( $core_path );
		$template_path = Package::get_template_path();
		$template_data = array(
			'files'                  => array(),
			'has_outdated_templates' => false,
		);

		foreach ( $files as $file ) {
			if ( '.DS_Store' === $file ) {
				continue;
			}

			$theme_file = false;

			if ( file_exists( get_stylesheet_directory() . '/' . $file ) ) {
				$theme_file = get_stylesheet_directory() . '/' . $file;
			} elseif ( file_exists( get_stylesheet_directory() . '/' . $template_path . $file ) ) {
				$theme_file = get_stylesheet_directory() . '/' . $template_path . $file;
			} elseif ( file_exists( get_template_directory() . '/' . $file ) ) {
				$theme_file = get_template_directory() . '/' . $file;
			} elseif ( file_exists( get_template_directory() . '/' . $template_path . $file ) ) {
				$theme_file = get_template_directory() . '/' . $template_path . $file;
			}

			if ( false !== $theme_file ) {
				$core_version  = \WC_Admin_Status::get_file_version( trailingslashit( $core_path ) . $file );
				$theme_version = \WC_Admin_Status::get_file_version( $theme_file );

				if ( ! $theme_version ) {
					$theme_version = '1.0';
				}

				$file_data = array(
					'core_file'     => trailingslashit( $core_path ) . $file,
					'template'      => $file,
					'theme_file'    => $theme_file,
					'theme_version' => $theme_version,
					'core_version'  => $core_version,
					'outdated'      => false,
				);

				if ( $core_version && $theme_version && version_compare( $theme_version, $core_version, '<' ) ) {
					$file_data['outdated']                   = true;
					$template_data['has_outdated_templates'] = true;
				}

				$template_data['files'][] = $file_data;
			}
		}

		return $template_data;
	}

	/**
	 * Reject on the orders list used to open an inline textarea in the actions column;
	 * it now opens the shared popup instead (see output_reject_dialog()).
	 */
	public static function inline_order_actions( $order ) {}

	/**
	 * Reason entered in the reject popup for a bulk rejection (nonce checked by the bulk handler).
	 *
	 * @return string
	 */
	public static function get_bulk_rejection_reason() {
		return isset( $_REQUEST['eggl_wd_bulk_reason'] ) ? sanitize_textarea_field( wp_unslash( $_REQUEST['eggl_wd_bulk_reason'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Popup asking for the rejection reason, used by the Requests table and the orders list.
	 * Printed once in the admin footer of those screens; assets/js/eggl-wd-reject-modal.js drives it.
	 */
	public static function output_reject_dialog() {
		?>
		<dialog id="eggl-wd-reject-dialog" class="eggl-wd-reject-dialog" aria-labelledby="eggl-wd-reject-dialog-title" data-bulk-title="<?php echo esc_attr_x( 'Reject selected withdrawal requests', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?>" data-error-text="<?php echo esc_attr_x( 'The request could not be rejected. Please reload the page and try again.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?>">
			<form method="dialog">
				<h2 id="eggl-wd-reject-dialog-title"><span class="eggl-wd-reject-dialog__title"><?php echo esc_html_x( 'Reject withdrawal request', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></span> <span class="eggl-wd-reject-dialog__number"></span></h2>
				<label for="eggl-wd-reject-reason"><?php echo esc_html_x( 'Reason', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?> <span class="eggl-wd-reject-dialog__optional"><?php echo esc_html_x( '(optional)', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></span></label>
				<textarea id="eggl-wd-reject-reason" rows="5"></textarea>
				<p class="description eggl-wd-reject-dialog__single"><?php echo esc_html_x( 'The customer receives this reason in the rejection email.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></p>
				<p class="description eggl-wd-reject-dialog__bulk" hidden><?php echo esc_html_x( 'The same reason is sent to every selected customer in their rejection email. Requests that are not open any more are skipped.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></p>
				<p class="eggl-wd-reject-dialog__error notice notice-error inline" hidden></p>
				<div class="eggl-wd-reject-dialog__actions">
					<button type="button" class="button eggl-wd-reject-dialog__cancel"><?php echo esc_html_x( 'Cancel', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></button>
					<button type="submit" class="button button-primary eggl-wd-reject-dialog__submit"><?php echo esc_html_x( 'Reject request', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></button>
				</div>
			</form>
		</dialog>
		<?php
	}

	public static function register_screen_ids( $screen_ids ) {
		$screen_ids = array_merge( $screen_ids, self::get_core_screen_ids() );

		return $screen_ids;
	}

	public static function get_core_screen_ids() {
		$screen_ids = array(
			self::get_table_screen_id(),
		);

		return $screen_ids;
	}

	public static function set_screen_option( $new_value, $option, $value ) {
		if ( in_array( $option, array( 'woocommerce_page_wc_stc_shipments_per_page', 'woocommerce_page_wc_stc_return_shipments_per_page' ), true ) ) {
			return absint( $value );
		}

		return $new_value;
	}

	public static function can_view_woocommerce_menu_item() {
		return current_user_can( 'edit_others_shop_orders' );
	}

	/**
	 * Hook suffix returned by add_submenu_page(), used as the screen id.
	 *
	 * @var string
	 */
	protected static $page_hook = '';

	const PAGE_SLUG = 'eggl-withdrawals';

	public static function register_menu() {
		global $submenu;

		$parent_slug = \EGGL_Settings::PAGE_SLUG;
		$post_type   = get_post_type_object( 'eggl_withdrawal' );
		$menu_title  = $post_type ? $post_type->labels->menu_name : _x( 'Withdrawals', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' );
		$page_title  = $post_type ? $post_type->labels->name : $menu_title;

		self::$page_hook = (string) add_submenu_page(
			$parent_slug,
			$page_title,
			$menu_title,
			\EGGL_Settings::capability(),
			self::PAGE_SLUG,
			array( __CLASS__, 'output_table_view' )
		);

		// Add count of open requests to the submenu item.
		if ( isset( $submenu[ $parent_slug ] ) && current_user_can( 'edit_others_shop_orders' ) ) {
			$withdrawal_count = self::get_withdrawal_count( 'requested' );

			if ( $withdrawal_count ) {
				foreach ( $submenu[ $parent_slug ] as $key => $menu_item ) {
					if ( isset( $menu_item[2] ) && self::PAGE_SLUG === $menu_item[2] ) {
						$submenu[ $parent_slug ][ $key ][0] .= ' <span class="awaiting-mod count-' . esc_attr( $withdrawal_count ) . '"><span class="pending-count">' . number_format_i18n( $withdrawal_count ) . '</span></span>'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
						break;
					}
				}
			}
		}

		add_action( 'load-' . self::get_table_screen_id(), array( __CLASS__, 'setup_table_view' ) );
	}

	/**
	 * Settings is the default (first) tab; Requests needs tab=requests.
	 *
	 * @return string 'settings' or 'requests'
	 */
	public static function get_current_tab() {
		return ( isset( $_GET['tab'] ) && 'requests' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) ? 'requests' : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public static function get_tabs_html( $current ) {
		$tabs = array(
			'settings' => array(
				'label' => _x( 'Settings', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'url'   => Settings::get_settings_url(),
			),
			'requests' => array(
				'label' => _x( 'Requests', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'url'   => Package::get_withdrawals_url(),
			),
		);

		$html = '<div class="eggl-tabs">';

		foreach ( $tabs as $key => $tab ) {
			$html .= '<a href="' . esc_url( $tab['url'] ) . '" class="eggl-tabs__item' . ( $key === $current ? ' is-active' : '' ) . '">' . esc_html( $tab['label'] ) . '</a>';
		}

		return $html . '</div>';
	}

	/**
	 * Opens the page in the same layout as the plugin's other admin screens
	 * (see admin/views/eggl-settings-page.php): header, lede, pill tabs.
	 *
	 * @param string $tab
	 * @param string $title
	 * @param string $subtitle Already-escaped HTML (e.g. the search label).
	 */
	public static function render_page_start( $tab, $title = '', $subtitle = '' ) {
		$title = $title ? $title : _x( 'Withdrawals', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' );
		$lede  = 'settings' === $tab
			? _x( 'Configure the withdrawal button and form required by Directive (EU) 2023/2673. Customers use the withdrawal page to withdraw from a contract; each request then appears under Requests.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' )
			: _x( 'Withdrawal requests submitted through your withdrawal form. Confirm or reject each request — the customer is notified by email either way.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' );
		?>
		<div class="wrap eggl-settings eggl-withdrawals eggl-withdrawals--<?php echo esc_attr( $tab ); ?>">
			<div class="eggl-page-header">
				<h1><?php echo esc_html( $title ); ?><?php echo wp_kses_post( $subtitle ); ?></h1>
				<p class="eggl-lede"><?php echo esc_html( $lede ); ?></p>
			</div>
			<?php echo wp_kses_post( self::get_tabs_html( $tab ) ); ?>
		<?php
	}

	public static function render_page_end() {
		echo '</div>';
	}

	public static function setup_table_view() {
		global $wp_list_table;

		if ( 'settings' === self::get_current_tab() ) {
			self::maybe_save_settings();
			return;
		}

		$order_table = new WithdrawalTable();

		$wp_list_table = $order_table; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wp_list_table->setup();

		if ( $wp_list_table->current_action() ) {
			$wp_list_table->handle_bulk_actions();
		}

		$current_url  = esc_url_raw( wp_unslash( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '' ) );
		$stripped_url = remove_query_arg( array( '_wp_http_referer', '_wpnonce' ), $current_url );

		if ( $stripped_url !== $current_url ) {
			wp_safe_redirect( $stripped_url );
			exit;
		}
	}

	protected static function load_wc_admin_settings() {
		if ( ! class_exists( 'WC_Admin_Settings' ) ) {
			include_once WC()->plugin_path() . '/includes/admin/class-wc-admin-settings.php';
		}
	}

	/**
	 * Saves the settings tab (WooCommerce settings API fields) before any output.
	 */
	protected static function maybe_save_settings() {
		if ( empty( $_POST['eggl_wd_save_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		check_admin_referer( 'eggl_wd_save_settings' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html_x( 'You do not have permission to change these settings.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ), 403 );
		}

		self::load_wc_admin_settings();

		Settings::before_save();
		\WC_Admin_Settings::save_fields( Settings::get_settings() );
		Settings::after_save();

		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', Settings::get_settings_url() ) );
		exit;
	}

	/**
	 * Settings grouped into cards, by setting id.
	 */
	protected static function get_settings_sections() {
		return array(
			'page'  => array(
				'title'  => _x( 'Withdrawal page & button', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'intro'  => _x( 'Where customers submit a withdrawal, and where the withdrawal button is shown.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'fields' => array( 'woocommerce_eggl_withdraw_from_contract_page_id', 'eggl_wd_button_text', 'eggl_wd_header_link', 'eggl_wd_enable_embed', 'eggl_wd_embed_everywhere', 'eggl_wd_myaccount_withdrawal_link' ),
			),
			'form'  => array(
				'title'  => _x( 'Withdrawal form', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'intro'  => _x( 'What customers fill in, and how guest requests are checked.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'fields' => array( 'eggl_wd_guest_order_validation', 'eggl_wd_enable_partial_withdrawals', 'eggl_wd_mandatory_fields', 'eggl_wd_enable_additional_information' ),
			),
			'rules' => array(
				'title'  => _x( 'Rules & notifications', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'intro'  => _x( 'Which orders can be withdrawn, and how requests are handled.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'fields' => array( 'eggl_wd_number_of_days_to_withdraw', 'eggl_wd_excluded_product_types', 'eggl_wd_separately_store_unverified_withdrawal_requests', 'eggl_wd_contact_email_address' ),
			),
		);
	}

	protected static function output_settings_view() {
		$settings = array();

		foreach ( Settings::get_settings() as $setting ) {
			if ( ! empty( $setting['id'] ) && ! in_array( $setting['type'], array( 'title', 'sectionend' ), true ) ) {
				$settings[ $setting['id'] ] = $setting;
			}
		}

		self::render_page_start( 'settings' );

		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html_x( 'Your settings have been saved.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( Settings::get_settings_url() ); ?>" class="eggl-form">
			<?php wp_nonce_field( 'eggl_wd_save_settings' ); ?>
			<input type="hidden" name="eggl_wd_save_settings" value="1">
			<?php
			$rendered = array();

			foreach ( self::get_settings_sections() as $section ) {
				echo '<div class="eggl-card">';
				echo '<div class="eggl-card__head"><h2>' . esc_html( $section['title'] ) . '</h2><p>' . esc_html( $section['intro'] ) . '</p></div>';
				echo '<div class="eggl-card__body">';

				foreach ( $section['fields'] as $id ) {
					if ( isset( $settings[ $id ] ) ) {
						self::render_setting_row( $settings[ $id ] );
						$rendered[] = $id;
					}
				}

				echo '</div></div>';
			}

			// Any setting added later (e.g. via filter) that isn't mapped to a card yet.
			$remaining = array_diff_key( $settings, array_flip( $rendered ) );

			if ( ! empty( $remaining ) ) {
				echo '<div class="eggl-card"><div class="eggl-card__head"><h2>' . esc_html_x( 'More', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '</h2></div><div class="eggl-card__body">';
				foreach ( $remaining as $setting ) {
					self::render_setting_row( $setting );
				}
				echo '</div></div>';
			}

			submit_button( _x( 'Save changes', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) );
			?>
		</form>
		<script>
		(function() {
			// Same convention as the main settings page: rows marked data-eggl-show-when="control_id:value"
			// only show while that toggle/select matches.
			document.querySelectorAll('.eggl-withdrawals [data-eggl-show-when]').forEach(function(row) {
				var parts = row.getAttribute('data-eggl-show-when').split(':');
				var control = document.getElementById(parts[0]);
				if (!control) return;
				var sync = function() {
					var current = control.type === 'checkbox' ? (control.checked ? 'yes' : 'no') : control.value;
					row.style.display = (current === parts[1]) ? '' : 'none';
				};
				control.addEventListener('change', sync);
				sync();
			});
		})();
		</script>
		<?php
		self::render_page_end();
	}

	/**
	 * Splits a WooCommerce-style setting description into its main text and the
	 * optional "additional" note block.
	 *
	 * @return string[] array( main, additional )
	 */
	protected static function split_setting_desc( $desc ) {
		$additional = '';

		if ( preg_match( '#<div class="eggl-wd-settings-additional-desc">(.*?)</div>#s', (string) $desc, $m ) ) {
			$additional = $m[1];
			$desc       = str_replace( $m[0], '', $desc );
		}

		return array( trim( (string) $desc ), $additional );
	}

	protected static function render_setting_row( $setting ) {
		$setting = wp_parse_args(
			$setting,
			array(
				'id'                => '',
				'title'             => '',
				'type'              => 'text',
				'desc'              => '',
				'desc_tip'          => '',
				'default'           => '',
				'options'           => array(),
				'placeholder'       => '',
				'custom_attributes' => array(),
			)
		);

		$id    = $setting['id'];
		$value = get_option( $id, $setting['default'] );

		list( $desc, $additional ) = self::split_setting_desc( $setting['desc'] );

		$row_attrs = '';

		foreach ( (array) $setting['custom_attributes'] as $attr => $attr_value ) {
			// WooCommerce's data-show_if_{id} convention → this plugin's data-eggl-show-when.
			if ( 0 === strpos( $attr, 'data-show_if_' ) ) {
				$row_attrs .= ' data-eggl-show-when="' . esc_attr( substr( $attr, strlen( 'data-show_if_' ) ) . ':yes' ) . '"';
			}
		}

		$descriptions = array();

		if ( is_string( $setting['desc_tip'] ) && '' !== $setting['desc_tip'] ) {
			$descriptions[] = $setting['desc_tip'];
		}

		echo '<div class="eggl-row"' . $row_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped above.
		echo '<div class="eggl-row__label"><label for="' . esc_attr( $id ) . '">' . esc_html( $setting['title'] ) . '</label></div>';
		echo '<div class="eggl-row__control">';

		switch ( $setting['type'] ) {
			case 'checkbox':
				echo '<input type="hidden" name="' . esc_attr( $id ) . '" value="no">';
				echo '<label class="eggl-toggle">';
				echo '<input type="checkbox" class="eggl-toggle__input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="yes" ' . checked( $value, 'yes', false ) . '>';
				echo '<span class="eggl-toggle__track"><span class="eggl-toggle__thumb"></span></span>';
				echo '<span class="eggl-toggle__text">' . wp_kses_post( $desc ) . '</span>';
				echo '</label>';
				$desc = '';
				break;

			case 'select':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '">';
				foreach ( (array) $setting['options'] as $key => $label ) {
					echo '<option value="' . esc_attr( $key ) . '" ' . selected( (string) $value, (string) $key, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
				break;

			case 'multiselect':
				$value = (array) $value;
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '[]" class="wc-enhanced-select" multiple="multiple" style="width:520px;max-width:100%">';
				foreach ( (array) $setting['options'] as $key => $label ) {
					echo '<option value="' . esc_attr( $key ) . '" ' . selected( in_array( (string) $key, array_map( 'strval', $value ), true ), true, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
				break;

			case 'number':
				echo '<label class="eggl-dim-field eggl-wd-inline-field"><input type="number" min="0" step="1" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '"> <span>' . esc_html( wp_strip_all_tags( $desc ) ) . '</span></label>';
				$desc = '';
				break;

			case 'single_select_page_with_search':
			case 'single_select_page':
				// Drafts included: the withdrawal page is created as a draft until the merchant publishes it.
				wp_dropdown_pages(
					array(
						'name'              => esc_attr( $id ),
						'id'                => esc_attr( $id ),
						'selected'          => absint( $value ),
						'show_option_none'  => esc_html_x( '— Select a page —', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
						'option_none_value' => '',
						'post_status'       => array( 'publish', 'draft', 'private', 'pending' ),
					)
				);
				break;

			default:
				$input_type = in_array( $setting['type'], array( 'email', 'text', 'url' ), true ) ? $setting['type'] : 'text';
				echo '<input type="' . esc_attr( $input_type ) . '" class="eggl-wd-text-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $setting['placeholder'] ) . '">';
				break;
		}

		if ( '' !== $desc ) {
			array_unshift( $descriptions, $desc );
		}

		if ( '' !== $additional ) {
			$descriptions[] = $additional;
		}

		foreach ( $descriptions as $description ) {
			echo '<p class="description">' . wp_kses_post( $description ) . '</p>';
		}

		echo '</div></div>';
	}

	/**
	 * Count withdrawals by status - need to apply our own logic here as we do not
	 * register our custom order statues via wc_get_order_statuses.
	 *
	 * @return array|integer
	 */
	public static function get_withdrawal_count( $target_status = '' ) {
		$order_type    = 'eggl_withdrawal';
		$core_statuses = array( 'wc-egwd-requested', 'wc-egwd-confirmed', 'wc-egwd-rejected' );
		$needs_reset   = false;
		$counts        = array();

		/**
		 * Backwards compatibility for Woo < 8.7.
		 */
		if ( ! is_callable( array( '\Automattic\WooCommerce\Utilities\OrderUtil', 'get_count_for_type' ) ) ) {
			foreach ( $core_statuses as $status ) {
				$cache_key    = \WC_Cache_Helper::get_cache_prefix( 'orders' ) . $status . $order_type;
				$cached_count = wp_cache_get( $cache_key, 'counts' );

				if ( false !== $cached_count ) {
					$counts[ $status ] = $cached_count;
				} else {
					$data_store = \WC_Data_Store::load( 'eggl-order-withdrawal' );

					if ( $data_store ) {
						$counts[ $status ] = $data_store->get_order_count( $status );

						wp_cache_set( $cache_key, $counts[ $status ], 'counts' );
					} else {
						$counts[ $status ] = 0;
					}
				}
			}
		} else {
			$counts = \Automattic\WooCommerce\Utilities\OrderUtil::get_count_for_type( $order_type );
		}

		foreach ( $core_statuses as $status ) {
			if ( ! isset( $counts[ $status ] ) ) {
				$counts[ $status ] = 0;
				$needs_reset       = true;
			}
		}

		if ( $needs_reset && class_exists( '\Automattic\WooCommerce\Caches\OrderCountCache' ) ) {
			$order_count_cache = new \Automattic\WooCommerce\Caches\OrderCountCache();

			if ( is_callable( array( $order_count_cache, 'set_multiple' ) ) ) {
				$order_count_cache->set_multiple( $order_type, $counts );
			} else {
				foreach ( $counts as $status => $count ) {
					$order_count_cache->set( $order_type, $status, $count );
				}
			}
		}

		if ( ! empty( $target_status ) ) {
			$target_status = Package::maybe_prefix_withdrawal_order_status( $target_status );

			return array_key_exists( $target_status, $counts ) ? $counts[ $target_status ] : 0;
		}

		return $counts;
	}

	public static function get_table_screen_id() {
		if ( ! empty( self::$page_hook ) ) {
			return self::$page_hook;
		}

		return get_plugin_page_hookname( self::PAGE_SLUG, \EGGL_Settings::PAGE_SLUG );
	}

	public static function output_table_view() {
		global $wp_list_table;

		if ( 'settings' === self::get_current_tab() ) {
			self::output_settings_view();
			return;
		}

		$wp_list_table->prepare_items();
		$wp_list_table->display();
	}

	public static function add_order_meta_box() {
		$order_type_screen_ids = array_merge( wc_get_order_types( 'order-meta-boxes' ), array( self::get_order_screen_id() ) );

		// Orders.
		foreach ( $order_type_screen_ids as $type ) {
			add_meta_box(
				'eggl-wd-order-withdrawals',
				_x( 'Withdrawals', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				function ( $post ) {
					global $theorder;

					if ( is_callable( array( '\Automattic\WooCommerce\Utilities\OrderUtil', 'init_theorder_object' ) ) ) {
						\Automattic\WooCommerce\Utilities\OrderUtil::init_theorder_object( $post );
					} else {
						global $post, $thepostid;

						if ( ! is_object( $theorder ) ) {
							if ( ! is_int( $thepostid ) ) {
								$thepostid = $post->ID;
							}

							$theorder = wc_get_order( $thepostid );
						}
					}

					if ( $theorder ) {
						$order       = $theorder;
						$withdrawals = eggl_wd_get_order_withdrawals( $order, array( 'status' => array( 'confirmed', 'rejected' ) ) );

						if ( ! empty( $withdrawals ) ) :
							?>
							<ul class="withdrawals">
								<?php
								foreach ( $withdrawals as $withdrawal ) :
									$item_list = array();
									foreach ( $withdrawal->get_items() as $item ) {
										$item_list[] = sprintf( _x( '%1$s x %2$s', 'owb-item-quantity', 'garanlabs-eu-guarantee-notice-durability-labels' ), wp_kses_post( $item->get_name() ), esc_html( $item->get_quantity() ) );
									}
									?>
									<li class="withdrawal withdrawal-<?php echo esc_attr( Package::maybe_remove_withdrawal_order_status_prefix( $withdrawal->get_status() ) ); ?>">
										<div class="withdrawal-content">
											<p><?php echo wp_kses_post( sprintf( _x( '%1$s received on %2$s @ %3$s by <a href="mailto:%4$s">%5$s</a> %6$s', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ), ( $withdrawal->is_partial() ) ? esc_html_x( 'Partial withdrawal', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) : esc_html_x( 'Full withdrawal', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ), wc_format_datetime( $withdrawal->get_date_received() ), wc_format_datetime( $withdrawal->get_date_received(), get_option( 'time_format' ) ), $withdrawal->get_email(), $withdrawal->get_formatted_full_name( $withdrawal->get_email() ), self::get_withdrawal_email_verified_html( $withdrawal ) ) ); ?></p>
											<p class="withdrawal-items"><?php echo implode( ', ', $item_list ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

											<?php if ( $withdrawal->has_status( 'rejected' ) ) : ?>
												<p class="withdrawal-outcome"><mark class="order-status status-egwd-rejected"><span><?php echo esc_html( Package::get_withdrawal_status_name( 'rejected' ) ); ?></span></mark></p>
												<div class="withdrawal-rejection-reason">
													<strong><?php echo esc_html_x( 'Reason:', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></strong>
													<?php echo '' !== trim( (string) $withdrawal->get_rejection_reason() ) ? wp_kses_post( wpautop( make_clickable( $withdrawal->get_rejection_reason() ) ) ) : '<em>' . esc_html_x( 'No reason given', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '</em>'; ?>
												</div>
											<?php elseif ( $withdrawal->has_status( 'confirmed' ) ) : ?>
												<p class="withdrawal-outcome"><mark class="order-status status-egwd-confirmed"><span><?php echo esc_html( Package::get_withdrawal_status_name( 'confirmed' ) ); ?></span></mark></p>
											<?php endif; ?>
										</div>

										<p class="meta">
											<?php if ( $withdrawal->has_status( 'confirmed' ) && $withdrawal->get_date_confirmed() ) : ?>
												<abbr class="confirmed-date" title=""><?php echo esc_html( sprintf( _x( 'Confirmed %1$s at %2$s', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ), wc_format_datetime( $withdrawal->get_date_confirmed() ), wc_format_datetime( $withdrawal->get_date_confirmed(), get_option( 'time_format' ) ) ) ); ?></abbr>
											<?php elseif ( $withdrawal->has_status( 'rejected' ) && $withdrawal->get_date_rejected() ) : ?>
												<abbr class="rejected-date" title=""><?php echo esc_html( sprintf( _x( 'Rejected %1$s at %2$s', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ), wc_format_datetime( $withdrawal->get_date_rejected() ), wc_format_datetime( $withdrawal->get_date_rejected(), get_option( 'time_format' ) ) ) ); ?></abbr>
											<?php endif; ?>

											<a href="<?php echo esc_url( self::get_edit_withdrawal_url( $withdrawal->get_id(), 'delete' ) ); ?>" class="delete_withdrawal" role="button"><?php echo esc_html_x( 'Delete withdrawal', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></a>
										</p>
									</li>
								<?php endforeach; ?>
							</ul>
							<?php
						endif;
					}
				},
				$type,
				'side',
				'default'
			);
		}
	}

	public static function register_scripts() {
		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';

		wp_register_script( 'eggl-wd-admin-order', Package::get_assets_url( 'static/admin-order.js' ), array( 'jquery', 'woocommerce_admin' ), Package::get_version() ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NotInFooter

		wp_localize_script(
			'eggl-wd-admin-order',
			'eggl_wd_admin_order_params',
			array(
				'ajax_url'            => admin_url( 'admin-ajax.php' ),
				'search_orders_nonce' => wp_create_nonce( 'eggl_wd_search_orders' ),
				'save_order_nonce'    => wp_create_nonce( 'eggl_wd_save_order' ),
			)
		);

		wp_register_script( 'eggl-wd-admin-orders', Package::get_assets_url( 'static/admin-orders.js' ), array( 'jquery', 'wc-backbone-modal' ), Package::get_version(), array( 'in_footer' => false ) );
		wp_localize_script(
			'eggl-wd-admin-orders',
			'eggl_wd_admin_orders_params',
			array(
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'preview_nonce' => wp_create_nonce( 'eggl_wd_get_withdrawal_details' ),
			)
		);

		// Register admin styles.
		wp_register_style( 'eggl-wd-admin-styles', Package::get_assets_url( 'static/admin-styles.css' ), array( 'woocommerce_admin_styles' ), Package::get_version() );

		// Admin styles for WC pages only.
		if ( in_array( $screen_id, self::get_order_screen_ids(), true ) ) {
			wp_enqueue_style( 'eggl-wd-admin-styles' );
			wp_enqueue_script( 'eggl-wd-admin-order' );

			wp_enqueue_style( 'eggl-wd-reject-modal', Package::get_url( 'assets/css/eggl-wd-reject-modal.css' ), array(), eggl_asset_version( 'withdrawal/assets/css/eggl-wd-reject-modal.css' ) );
			wp_enqueue_script( 'eggl-wd-reject-modal', Package::get_url( 'assets/js/eggl-wd-reject-modal.js' ), array( 'jquery', 'eggl-wd-admin-order' ), eggl_asset_version( 'withdrawal/assets/js/eggl-wd-reject-modal.js' ), true );
			add_action( 'admin_footer', array( __CLASS__, 'output_reject_dialog' ) );
		}

		// The Withdrawals screen shares the look of the plugin's other admin pages.
		if ( self::get_table_screen_id() === $screen_id ) {
			wp_enqueue_style( 'eggl-admin', EGGL_URL . 'assets/css/eggl-admin.css', array(), eggl_asset_version( 'assets/css/eggl-admin.css' ) );
		}
	}

	public static function menu_order_count( $count ) {
		$count += wc_orders_count( 'egwd-pending' );

		return $count;
	}

	public static function process_withdrawal_rejection( $order_id ) {
		if ( $order = wc_get_order( $order_id ) ) {
			if ( isset( $_POST['eggl_wd_reject_withdrawal_request'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$reason = isset( $_POST['eggl_wd_reject_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['eggl_wd_reject_reason'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

				eggl_wd_order_reject_withdrawal_request( $order, $reason );
			}
		}
	}

	protected static function get_order_item_map( $order_id ) {
		if ( ! array_key_exists( $order_id, self::$order_item_map ) ) {
			self::$order_item_map[ $order_id ] = array();

			foreach ( eggl_wd_get_order_withdrawals( $order_id ) as $withdrawal ) {
				foreach ( $withdrawal->get_items() as $item ) {
					if ( ! isset( self::$order_item_map[ $order_id ][ $item->get_parent_id() ] ) ) {
						self::$order_item_map[ $order_id ][ $item->get_parent_id() ] = array(
							'requested' => 0,
							'rejected'  => 0,
							'confirmed' => 0,
						);
					}

					$status = Package::maybe_remove_withdrawal_order_status_prefix( $withdrawal->get_status() );

					if ( array_key_exists( $status, self::$order_item_map[ $order_id ][ $item->get_parent_id() ] ) ) {
						self::$order_item_map[ $order_id ][ $item->get_parent_id() ][ $status ] += $item->get_quantity();
					}
				}
			}
		}

		return self::$order_item_map[ $order_id ];
	}

	public static function display_custom_itemmeta( $item_id, $item, $product ) {
		$item_map = self::get_order_item_map( $item->get_order_id() );

		if ( array_key_exists( $item_id, $item_map ) ) {
			if ( $item_map[ $item_id ]['confirmed'] > 0 ) {
				?>
				<mark class="withdrawal-quantity"><?php echo wp_kses_post( sprintf( _x( 'Withdrawn %1$sx', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ), $item_map[ $item_id ]['confirmed'] ) ); ?></mark>
				<?php
			}

			if ( $item_map[ $item_id ]['requested'] > 0 ) {
				?>
				<mark class="withdrawal-quantity withdrawal-requested"><?php echo wp_kses_post( sprintf( _x( 'Requested %1$sx', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ), $item_map[ $item_id ]['requested'] ) ); ?></mark>
				<?php
			}
		}
	}

	public static function register_hidden_itemmeta( $hidden_meta ) {
		return array_merge(
			$hidden_meta,
			array(
				'_withdrawn_quantity',
				'_withdrawn_quantity_refunded',
				'_withdrawn_quantity_refund_ids',
				'_withdrawn_quantities',
				'_withdrawal_request_quantity',
			)
		);
	}

	/**
	 * @param WithdrawalOrder $withdrawal
	 *
	 * @return string
	 */
	public static function get_withdrawal_email_verified_html( $withdrawal ) {
		return ( $withdrawal->has_verified_email() ? '<span class="eggl-wd-verified-status is-verified dashicons dashicons-yes-alt tips" data-tip="' . esc_attr( _x( 'Email address matches', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) ) . '"></span>' : '<span class="eggl-wd-verified-status is-unverified dashicons dashicons-warning tips" data-tip="' . esc_attr( _x( 'E-mail address unknown', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) ) . '"></span>' );
	}

	public static function register_order_bulk_actions( $actions ) {
		$actions['eggl_wd_confirm_withdrawal_requests'] = _x( 'Confirm withdrawal requests', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' );
		$actions['eggl_wd_reject_withdrawal_requests']  = _x( 'Reject withdrawal requests', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' );
		$actions['eggl_wd_delete_withdrawal_requests']  = _x( 'Delete withdrawal requests', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' );

		return $actions;
	}

	public static function handle_order_bulk_actions( $redirect_to, $action, $ids ) {
		$ids           = apply_filters( 'woocommerce_bulk_action_ids', array_reverse( array_map( 'absint', $ids ) ), $action, 'order' );
		$changed       = 0;
		$report_action = '';

		if ( in_array( $action, array( 'eggl_wd_confirm_withdrawal_requests', 'eggl_wd_reject_withdrawal_requests', 'eggl_wd_delete_withdrawal_requests' ), true ) ) {
			foreach ( $ids as $id ) {
				$order         = wc_get_order( $id );
				$report_action = $action;

				if ( $order && eggl_wd_order_has_withdrawal_request( $order ) ) {
					if ( 'eggl_wd_confirm_withdrawal_requests' === $action ) {
						$result = eggl_wd_order_confirm_withdrawal_request( $order );
					} elseif ( 'eggl_wd_reject_withdrawal_requests' === $action ) {
						$result = eggl_wd_order_reject_withdrawal_request( $order, self::get_bulk_rejection_reason() );
					} elseif ( 'eggl_wd_delete_withdrawal_requests' === $action ) {
						$result = eggl_wd_order_delete_withdrawal_request( $order, false, false );
					}

					if ( $result ) {
						++$changed;
					}
				}
			}
		}

		if ( $changed ) {
			$redirect_query_args = array(
				'post_type'   => 'shop_order',
				'bulk_action' => $report_action,
				'changed'     => $changed,
				'ids'         => join( ',', $ids ),
			);

			// Only confirmed withdrawals end up "Withdrawn"; after a reject or delete, stay on the current list.
			if ( 'eggl_wd_confirm_withdrawal_requests' === $report_action ) {
				$redirect_query_args['status'] = 'wc-egwd-withdrawn';
			}

			if ( Package::is_hpos_enabled() ) {
				unset( $redirect_query_args['post_type'] );
				$redirect_query_args['page'] = 'wc-orders';
			}

			$redirect_to = add_query_arg(
				$redirect_query_args,
				$redirect_to
			);

			return esc_url_raw( $redirect_to );
		} else {
			return $redirect_to;
		}
	}

	/**
	 * @param array $actions
	 * @param \WC_Order $order
	 *
	 * @return array
	 */
	public static function admin_order_actions( $actions, $order ) {
		if ( $request = eggl_wd_get_withdrawal_request( $order ) ) {
			$actions = array();

			$actions['eggl_wd_confirm_withdrawal_request'] = array(
				'url'    => self::get_edit_withdrawal_url( $request->get_id() ),
				'name'   => _x( 'Confirm withdrawal request', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'action' => 'complete',
			);

			$actions['eggl_wd_reject_withdrawal_request'] = array(
				'url'    => self::get_edit_withdrawal_url( $request->get_id(), 'reject' ),
				'name'   => _x( 'Reject withdrawal request', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'action' => 'eggl-wd-reject',
			);

			$actions['eggl_wd_delete_withdrawal_request'] = array(
				'url'    => self::get_edit_withdrawal_url( $request->get_id(), 'delete' ),
				'name'   => _x( 'Delete withdrawal request', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'action' => 'eggl-wd-delete',
			);
		}

		return $actions;
	}

	public static function get_edit_withdrawal_url( $order_id, $type = 'confirm', $args = array() ) {
		return esc_url_raw( wp_nonce_url( add_query_arg( $args, admin_url( "admin-ajax.php?action=eggl_wd_{$type}_withdrawal&order_id={$order_id}" ) ), "eggl_wd_{$type}_withdrawal" ) );
	}

	public static function order_withdrawal_request_details( $order ) {
		if ( ! eggl_wd_order_has_withdrawal_request( $order ) ) {
			return;
		}

		$request                    = eggl_wd_get_withdrawal_request( $order );
		$confirmation_needs_confirm = apply_filters( 'eggl_wd_withdrawal_confirmation_needs_confirm', true ) ? 'eggl-wd-needs-confirmation' : '';
		$rejection_needs_confirm    = apply_filters( 'eggl_wd_withdrawal_rejection_needs_confirm', true ) ? 'eggl-wd-needs-confirmation' : '';
		?>
		<div class="eggl-wd-order-withdrawal-request">
			<h3><?php echo ( $request->is_partial() ) ? esc_html_x( 'Partial withdrawal request', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) : esc_html_x( 'Full withdrawal request', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></h3>

			<p><?php echo wp_kses_post( sprintf( _x( 'Received on %1$s @ %2$s by <a href="mailto:%3$s">%4$s</a> %5$s', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ), wc_format_datetime( $request->get_date_received() ), wc_format_datetime( $request->get_date_received(), get_option( 'time_format' ) ), $request->get_email(), $request->get_formatted_full_name( $request->get_email() ), self::get_withdrawal_email_verified_html( $request ) ) ); ?></p>

			<div class="eggl-wd-order-withdrawal-request-buttons">
				<a href="<?php echo esc_url( self::get_edit_withdrawal_url( $request->get_id() ) ); ?>" class="eggl-wd-confirm-withdrawal-request button button-primary tips <?php echo esc_attr( $confirmation_needs_confirm ); ?>" data-confirm="<?php echo esc_attr_x( 'Are you sure to confirm the withdrawal request?', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?>" data-tip="<?php echo esc_attr_x( 'Confirms the withdrawal request to the customer.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?>"><?php echo esc_html_x( 'Confirm withdrawal request', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></a>
				<a href="#" class="eggl-wd-reject-withdrawal-request-start tips" data-tip="<?php echo esc_attr_x( 'Reject the withdrawal request by providing a reason for the rejection.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?>"><?php echo esc_html_x( 'Reject request', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></a>
			</div>
			<div class="eggl-wd-reject-withdrawal-request-form hidden">
				<p class="form-field form-field-wide">
					<label for="eggl_wd_reject_reason"><?php echo esc_html_x( 'Reason', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?>:</label>
					<textarea rows="5" cols="40" name="eggl_wd_reject_reason" tabindex="6" id="eggl_wd_reject_reason" placeholder="<?php echo esc_attr_x( 'Describe why you\'ve rejected the withdrawal request.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?>"></textarea>
				</p>

				<input type="hidden" name="eggl_wd_reject_order_id" value="<?php echo esc_attr( $order->get_id() ); ?>" />
				<?php wp_nonce_field( 'eggl_wd_reject_withdrawal_request', 'eggl_wd_reject_withdrawal_request_nonce' ); ?>

				<p>
					<button type="submit" class="button button-primary <?php echo esc_attr( $rejection_needs_confirm ); ?>" data-confirm="<?php echo esc_attr_x( 'Are you sure to reject the withdrawal request?', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?>" id="eggl-wd-reject-withdrawal-request-submit" name="eggl_wd_reject_withdrawal_request" value="<?php echo esc_attr_x( 'Reject withdrawal request', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?>"><?php echo esc_html_x( 'Reject withdrawal request', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></button>
				</p>
			</div>
		</div>
		<?php
	}

	public static function get_order_screen_id() {
		return function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
	}

	protected static function get_order_screen_ids() {
		$screen_ids = array();

		foreach ( wc_get_order_types() as $type ) {
			$screen_ids[] = $type;
			$screen_ids[] = 'edit-' . $type;
		}

		$screen_ids[] = self::get_order_screen_id();
		$screen_ids   = array_merge( $screen_ids, self::get_core_screen_ids() );

		return array_filter( $screen_ids );
	}

	public static function get_screen_ids() {
		$other_screen_ids = array();

		return array_merge( self::get_order_screen_ids(), $other_screen_ids );
	}

	public static function on_init() {
		add_filter( 'handle_bulk_actions-' . ( 'shop_order' === self::get_order_screen_id() ? 'edit-shop_order' : self::get_order_screen_id() ), array( __CLASS__, 'handle_order_bulk_actions' ), 10, 3 );
		add_filter( 'bulk_actions-' . ( 'shop_order' === self::get_order_screen_id() ? 'edit-shop_order' : self::get_order_screen_id() ), array( __CLASS__, 'register_order_bulk_actions' ), 10, 1 );

		add_filter( 'views_' . self::get_order_screen_id(), array( __CLASS__, 'register_withdrawal_view' ) );
		add_filter( 'views_edit-' . self::get_order_screen_id(), array( __CLASS__, 'register_withdrawal_view' ) );
	}

	public static function register_withdrawal_view( $views ) {
		$base_url             = Package::get_withdrawals_url();
		$requested            = self::get_withdrawal_count( 'requested' );
		$views['eggl_withdrawals'] = '<a class="" href="' . esc_url( $base_url ) . '">' . _x( 'Withdrawals', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) . ' <span class="count">(' . esc_html( $requested ) . ')</span></a>';

		return $views;
	}
}