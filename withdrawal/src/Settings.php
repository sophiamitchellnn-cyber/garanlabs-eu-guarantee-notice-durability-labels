<?php

namespace EGGL\Withdrawal;

use EGGL\Withdrawal\Admin\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Main package class.
 */
class Settings {

	public static function get_sections() {
		return array(
			'' => _x( 'General', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
		);
	}

	public static function get_description() {
		return _x( 'Configure the EU-compliant order withdrawal button (Directive (EU) 2023/2673). Customers use the withdrawal form page to withdraw from a contract; requests appear under the Requests tab.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' );
	}

	public static function get_settings( $current_section = '' ) {
		$default_email = get_option( 'admin_email' );
		$woo_mail      = sanitize_email( get_option( 'woocommerce_email_from_address' ) );

		if ( $woo_mail ) {
			$default_email = $woo_mail;
		}

		$settings = array(
			array(
				'title' => '',
				'type'  => 'title',
				'id'    => 'eggl_wd_options',
				'desc'  => self::get_description(),
			),

			array(
				'title'    => _x( 'Withdrawal page', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'id'       => 'woocommerce_eggl_withdraw_from_contract_page_id',
				'type'     => 'single_select_page_with_search',
				'class'    => 'wc-page-search',
				'desc_tip' => _x( 'This page should contain your withdrawal form shortcode.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'args'     => array(
					'exclude' => array(),
				),
				'default'  => '',
				'css'      => 'min-width:300px;',
				'autoload' => false,
				'desc'     => Admin::get_withdrawal_page_status_html(),
			),

			array(
				'title'    => _x( 'Embed in footer', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'desc'     => _x( 'Embed the withdrawal button directly in the footer. Disable this option if you plan to embed the page manually.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'id'       => 'eggl_wd_enable_embed',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'autoload' => true,
			),

			array(
				'title'             => _x( 'Display everywhere', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'desc'              => _x( 'Display the button on every page, not just on shop-related pages.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'id'                => 'eggl_wd_embed_everywhere',
				'type'              => 'checkbox',
				'default'           => 'no',
				'autoload'          => true,
				'custom_attributes' => array(
					'data-show_if_eggl_wd_enable_embed' => '',
				),
			),

			array(
				'title'       => _x( 'Button text', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'desc'        => _x( 'Text of the withdrawal button in the footer and header menu, and of the [eggl_wd_order_withdrawal_button] shortcode. Leave empty to use "Withdraw from contract" in each visitor\'s language. The Directive asks for wording such as "withdraw from contract here" or an equally unambiguous phrase.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'id'          => 'eggl_wd_button_text',
				'type'        => 'text',
				'default'     => '',
				'placeholder' => _x( 'Withdraw from contract', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'autoload'    => true,
			),

			array(
				'title'    => _x( 'Header menu', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'desc'     => _x( 'Show a "Withdraw from contract" link in the site\'s header menu.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '<div class="eggl-wd-settings-additional-desc">' . _x( 'Added to the main header menu (desktop and mobile). While off, the withdrawal page is also kept out of the automatic page list some themes show when no menu has been set up.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '</div>',
				'id'       => 'eggl_wd_header_link',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'autoload' => true,
			),

			array(
				'title'    => _x( 'Guest validation', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'desc'     => _x( 'Choose how to validate guest withdrawal requests. Spam protection via a built-in honeypot field and IP limits apply.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) . ( 'DE' === Package::get_base_country() ? '<div class="eggl-wd-settings-additional-desc">' . _x( 'Please note: As to current legal interpretation a validation lock would mean that a consumer who makes a typo might no longer be able to submit their withdrawal within the prescribed time limit—which directly contradicts the protective purpose of Section 356a of the German Civil Code (BGB). Please seek legal advise before using.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '</div>' : '' ),
				'id'       => 'eggl_wd_guest_order_validation',
				'default'  => 'DE' === Package::get_base_country() ? 'none' : 'order_or_email',
				'autoload' => false,
				'type'     => 'select',
				'options'  => array(
					'order_existence'   => _x( 'Enforce that the order number entered exists.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
					'order_email_match' => _x( 'Order must exist and email must match the one from the original order.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
					'order_or_email'    => _x( 'Either order number or email address must exist.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
					'none'              => _x( 'Do not validate', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				),
			),

			array(
				'title'    => _x( 'Partial withdrawals', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'desc'     => _x( 'Allow customers and verified guests to select which order items to withdraw.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'id'       => 'eggl_wd_enable_partial_withdrawals',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'autoload' => false,
			),

			array(
				'title'    => _x( 'Mandatory fields', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'id'       => 'eggl_wd_mandatory_fields',
				'desc_tip' => _x( 'Select fields that should be mandatory while submitting a new withdrawal', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'type'     => 'multiselect',
				'class'    => 'wc-enhanced-select',
				'css'      => 'width: 400px;',
				'options'  => array(
					'first_name'             => _x( 'First name', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
					'last_name'              => _x( 'Last name', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
					'order_number'           => _x( 'Contract Identification', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
					'additional_information' => _x( 'Additional information', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				),
				'default'  => array(
					'order_number',
					'first_name',
					'last_name',
				),
				'autoload' => false,
			),

			array(
				'title'    => _x( 'Additional information', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'desc'     => _x( 'Allow guests to enter additional information in a separate text field.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'id'       => 'eggl_wd_enable_additional_information',
				'type'     => 'checkbox',
				'default'  => 'no',
				'autoload' => false,
			),

			array(
				'title'    => _x( 'Non-refundable', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'desc'     => _x( 'Choose certain product types to exclude from being withdrawn.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'desc_tip' => true,
				'id'       => 'eggl_wd_excluded_product_types',
				'class'    => 'wc-enhanced-select',
				'type'     => 'multiselect',
				'options'  => self::get_product_type_options(),
				'default'  => array( 'virtual' ),
				'autoload' => false,
			),

			array(
				'title'     => _x( 'Withdrawal period', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'desc_tip'  => _x( 'Choose the number of days, starting with the orders\' completed date, to accept withdrawals for orders.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'desc'      => _x( 'Days', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '<div class="eggl-wd-settings-additional-desc">' . sprintf( _x( 'Keep in mind that the withdrawal period does not begin until the customer receives the order. If necessary add a buffer period depending on your shipping process. Use 0 to make withdrawals available at any time.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ), esc_url( get_admin_url( null, 'admin.php?page=wc-orders&unverified_withdrawals=yes' ) ) ) . '</div>',
				'css'       => 'max-width: 60px;',
				'row_class' => 'withdrawal-period',
				'type'      => 'number',
				'id'        => 'eggl_wd_number_of_days_to_withdraw',
				'default'   => '14',
				'autoload'  => false,
			),

			array(
				'title'    => _x( 'Unverified requests', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'desc'     => _x( 'Do not adjust order status for unverified requests.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '<div class="eggl-wd-settings-additional-desc">' . _x( 'For some requests, the email address differs from the original stored within the order. Make sure these requests do not automatically set the pending withdrawal order status.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '</div>',
				'id'       => 'eggl_wd_separately_store_unverified_withdrawal_requests',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'autoload' => false,
			),

			array(
				'title'        => _x( 'Contact email', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'desc_tip'     => _x( 'Please provide an email address where customers can contact you if they have any issues.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'id'           => 'eggl_wd_contact_email_address',
				'type'         => 'email',
				'placeholder'  => $default_email,
				'default'      => '',
				'skip_install' => true,
				'autoload'     => false,
			),

			array(
				'title'    => _x( 'My account page', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'desc'     => _x( 'On the my account page, add a link to create a new withdrawal request for the order in case available.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '<div class="eggl-wd-settings-additional-desc">' . _x( 'Shown as a "Withdraw" button next to "View" in My account → Orders, and under "Actions" when the customer opens an order. It only appears for orders that can still be withdrawn: processing or completed, within the withdrawal period, with items left to withdraw, and once the withdrawal page is published.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ) . '</div>',
				'id'       => 'eggl_wd_myaccount_withdrawal_link',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'autoload' => false,
			),

			array(
				'type' => 'sectionend',
				'id'   => 'eggl_wd_options',
			),
		);

		return $settings;
	}

	protected static function get_product_type_options() {
		$product_types        = wc_get_product_types();
		$product_type_options = array_merge(
			array(
				'virtual'      => _x( 'Virtual Product', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				'downloadable' => _x( 'Downloadable Product', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
			),
			$product_types
		);

		return apply_filters( 'eggl_wd_product_type_options', $product_type_options );
	}

	public static function before_save() {
	}

	public static function after_save() {
	}

	public static function get_settings_url() {
		return admin_url( 'admin.php?page=' . Admin::PAGE_SLUG . '&tab=settings' );
	}
}
