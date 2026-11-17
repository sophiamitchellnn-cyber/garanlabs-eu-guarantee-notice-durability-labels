<?php
/**
 * Cancel order request form.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/forms/order-withdrawal-request.php.
 *
 * HOWEVER, on occasion EU OWB will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @package EGGL/Withdrawal/Templates
 * @version 2.4.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
$manually_select_items   = apply_filters( 'eggl_wd_manually_select_items_default', $manually_select_items );
$delete_original_request = apply_filters( 'eggl_wd_delete_original_request_default', true );
$show_submit             = true;
?>
<form class="woocommerce-form woocommerce-form-order-withdrawal-request order-withdrawal-request" method="post">
	<?php do_action( 'eggl_wd_return_request_form_start' ); ?>

	<div class="eggl-wd-notice-wrapper"></div>

	<div class="eggl-wd-form-fields">
		<?php if ( ! is_user_logged_in() && ! $order ) : ?>
			<?php
				woocommerce_form_field(
					'order_number',
					apply_filters(
						'eggl_wd_form_field_order_number_args',
						array(
							'label'             => _x( 'Contract Identification, e.g. order number', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
							'class'             => array( 'form-row-full', 'form-row-order-number' ),
							'autocomplete'      => 'off',
							'id'                => 'order-withdrawal-request-order-number',
							'default'           => $withdrawal ? $withdrawal->get_contract_identification() : '',
							'required'          => \EGGL\Withdrawal\Package::get_form_field_required( 'order_number' ),
							'custom_attributes' => array(
								'maxlength' => \EGGL\Withdrawal\Package::get_form_field_maxlength( 'order_number' ),
							),
						)
					)
				);
			?>

			<?php
				woocommerce_form_field(
					'email',
					apply_filters(
						'eggl_wd_form_field_email_args',
						array(
							'label'        => _x( 'Email', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
							'class'        => array( 'form-row-full', 'form-row-email', 'validate-email' ),
							'autocomplete' => 'email',
							'id'           => 'order-withdrawal-request-email',
							'default'      => $withdrawal ? $withdrawal->get_email() : '',
							'required'     => true,
						)
					)
				);
			?>

			<?php
			woocommerce_form_field(
				'email_repeat',
				array(
					'label'        => _x( 'Email (repeat)', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
					'class'        => array( 'form-row-full', 'form-row-email-repeat' ),
					'autocomplete' => 'email',
					'id'           => 'order-withdrawal-request-email-repeat',
					'default'      => '',
					'required'     => true,
				)
			);
			?>

			<?php
			woocommerce_form_field(
				'first_name',
				apply_filters(
					'eggl_wd_form_field_first_name_args',
					array(
						'label'             => _x( 'First name', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
						'class'             => array( 'form-row-first', 'form-row-first-name' ),
						'autocomplete'      => 'off',
						'id'                => 'order-withdrawal-request-first-name',
						'default'           => $withdrawal ? $withdrawal->get_first_name() : '',
						'required'          => \EGGL\Withdrawal\Package::get_form_field_required( 'first_name' ),
						'custom_attributes' => array(
							'maxlength' => \EGGL\Withdrawal\Package::get_form_field_maxlength( 'first_name' ),
						),
					)
				)
			);
			?>

			<?php
			woocommerce_form_field(
				'last_name',
				apply_filters(
					'eggl_wd_form_field_last_name_args',
					array(
						'label'             => _x( 'Last name', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
						'class'             => array( 'form-row-last', 'form-row-last-name' ),
						'autocomplete'      => 'off',
						'id'                => 'order-withdrawal-request-last-name',
						'required'          => \EGGL\Withdrawal\Package::get_form_field_required( 'last_name' ),
						'default'           => $withdrawal ? $withdrawal->get_last_name() : '',
						'custom_attributes' => array(
							'maxlength' => \EGGL\Withdrawal\Package::get_form_field_maxlength( 'last_name' ),
						),
					)
				)
			);
			?>

			<div class="clear"></div>

			<?php
			if ( eggl_wd_enable_additional_information_field() ) :
				woocommerce_form_field(
					'additional_information',
					apply_filters(
						'eggl_wd_form_field_additional_information_args',
						array(
							'label'             => _x( 'Additional information', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
							'class'             => array( 'form-row-full', 'form-row-additional-information' ),
							'autocomplete'      => 'off',
							'id'                => 'order-withdrawal-request-additional-information',
							'default'           => $withdrawal ? $withdrawal->get_additional_information() : '',
							'required'          => \EGGL\Withdrawal\Package::get_form_field_required( 'additional_information' ),
							'type'              => 'textarea',
							'custom_attributes' => array(
								'maxlength' => \EGGL\Withdrawal\Package::get_form_field_maxlength( 'additional_information' ),
							),
						)
					)
				);
				?>
				<div class="clear"></div>
			<?php endif; ?>

			<?php do_action( 'eggl_wd_return_request_guest_form', $withdrawal ); ?>
			<?php
		else :
			$default_email_address = $withdrawal ? $withdrawal->get_email() : ( $order ? $order->get_billing_email() : ( WC()->customer ? WC()->customer->get_billing_email() : '' ) );
			$default_first_name    = $withdrawal ? $withdrawal->get_first_name() : ( $order ? $order->get_billing_first_name() : ( WC()->customer ? WC()->customer->get_billing_first_name() : '' ) );
			$default_last_name     = $withdrawal ? $withdrawal->get_last_name() : ( $order ? $order->get_billing_last_name() : ( WC()->customer ? WC()->customer->get_billing_last_name() : '' ) );
			$orders                = $withdrawal ? eggl_wd_get_orders_for_guest( $withdrawal ) : ( is_user_logged_in() ? eggl_wd_get_orders_for_user() : array( $order ) );
			$default_order_id      = $order ? $order->get_id() : 0;

			/**
			 * Only list orders that can still be withdrawn: withdrawable status, within the
			 * withdrawal period and with items left. Orders with an open request stay listed so
			 * the customer can still edit that request.
			 */
			$orders = array_values(
				array_filter(
					(array) $orders,
					function ( $t_order ) {
						return is_a( $t_order, 'WC_Order' ) && eggl_wd_order_is_withdrawable( $t_order, true );
					}
				)
			);

			// The order the customer arrived with (e.g. an email link) stays selectable only while it is still withdrawable.
			if ( $order && ! $withdrawal && ! eggl_wd_order_is_withdrawable( $order, true ) ) {
				$order            = null;
				$default_order_id = 0;
			}

			if ( $order && ! in_array( $order->get_id(), array_map( function ( $t_order ) { return $t_order->get_id(); }, $orders ), true ) ) {
				$orders[] = $order;
			}

			if ( ! empty( $orders ) ) :
				$orders_select = array(
					'' => _x( 'Please select an order', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
				);

				foreach ( $orders as $t_order ) {
					$orders_select[ absint( $t_order->get_id() ) ] = sprintf( _x( 'Order #%1$s', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ), $t_order->get_order_number() );
				}

				/**
				 * Force existence of the requested order.
				 */
				if ( $order && ! array_key_exists( $order->get_id(), $orders_select ) ) {
					$orders_select[ absint( $order->get_id() ) ] = sprintf( _x( 'Order #%1$s', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ), $order->get_order_number() );
				}
				?>
				<?php
				woocommerce_form_field(
					'order_id',
					apply_filters(
						'eggl_wd_form_field_order_select_args',
						array(
							'label'    => _x( 'Order', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
							'class'    => array( 'form-row-full', 'form-row-order' ),
							'id'       => 'order-withdrawal-request-order',
							'default'  => $default_order_id,
							'type'     => 'select',
							'options'  => $orders_select,
							'required' => true,
						),
						$default_order_id,
						$orders
					)
				);
				?>

				<div class="eggl-wd-order-item-select-wrapper">
					<?php
					if ( $order ) :
						wc_get_template(
							'eggl-withdrawal/forms/order-withdrawal-request-item-select.php',
							array(
								'order'                 => $order,
								'manually_select_items' => $manually_select_items,
							)
						);
					endif;
					?>
				</div>

				<?php
				woocommerce_form_field(
					'email',
					apply_filters(
						'eggl_wd_form_field_email_args',
						array(
							'label'        => _x( 'Email', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
							'class'        => array( 'form-row-full', 'form-row-email', 'validate-email' ),
							'autocomplete' => 'email',
							'id'           => 'order-withdrawal-request-email',
							'default'      => $default_email_address,
							'required'     => true,
						)
					)
				);
				?>

				<?php
				woocommerce_form_field(
					'email_repeat',
					array(
						'label'        => _x( 'Email (repeat)', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
						'class'        => array( 'form-row-full', 'form-row-email-repeat' ),
						'autocomplete' => 'email',
						'id'           => 'order-withdrawal-request-email-repeat',
						'default'      => '',
						'required'     => true,
					)
				);
				?>

				<?php
				woocommerce_form_field(
					'first_name',
					apply_filters(
						'eggl_wd_form_field_first_name_args',
						array(
							'label'             => _x( 'First name', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
							'class'             => array( 'form-row-first', 'form-row-first-name' ),
							'autocomplete'      => 'off',
							'id'                => 'order-withdrawal-request-first-name',
							'default'           => $default_first_name,
							'required'          => \EGGL\Withdrawal\Package::get_form_field_required( 'first_name' ),
							'custom_attributes' => array(
								'maxlength' => \EGGL\Withdrawal\Package::get_form_field_maxlength( 'first_name' ),
							),
						)
					)
				);
				?>

				<?php
				woocommerce_form_field(
					'last_name',
					apply_filters(
						'eggl_wd_form_field_last_name_args',
						array(
							'label'             => _x( 'Last name', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ),
							'class'             => array( 'form-row-last', 'form-row-last-name' ),
							'autocomplete'      => 'off',
							'id'                => 'order-withdrawal-request-last-name',
							'required'          => \EGGL\Withdrawal\Package::get_form_field_required( 'last_name' ),
							'default'           => $default_last_name,
							'custom_attributes' => array(
								'maxlength' => \EGGL\Withdrawal\Package::get_form_field_maxlength( 'last_name' ),
							),
						)
					)
				);
				?>

				<div class="clear"></div>

				<?php if ( $withdrawal ) : ?>
					<?php
					woocommerce_form_field(
						'delete_original_request',
						apply_filters(
							'eggl_wd_form_field_delete_original_request_args',
							array(
								'label'         => sprintf( _x( 'Please delete my original withdrawal request to %1$s.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ), esc_html( $withdrawal->get_contract_identification() ) ),
								'class'         => array( 'form-row-full', 'order-withdrawal-delete-original-request-checkbox', ( $order && $order->get_id() === $withdrawal->get_parent_id() ? 'hidden' : '' ) ),
								'id'            => 'delete-original-request',
								'default'       => $delete_original_request,
								'type'          => 'checkbox',
								'checked_value' => true,
							)
						)
					);
					?>
					<div class="clear"></div>
					<input type="hidden" name="original_order_id" id="original-order-id" value="<?php echo esc_attr( $withdrawal->get_parent_id() ); ?>" />
				<?php endif; ?>
				<?php
			else :
				$show_submit = false;
				?>
				<p class="woocommerce-info notice"><?php echo wp_kses_post( sprintf( _x( 'Sorry, there are currently no orders available to withdraw. If you have questions regarding one of your orders, please <a href="%s">contact support</a> for help.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ), esc_url( eggl_wd_get_contact_support_url() ) ) ); ?></p>
			<?php endif; ?>

			<?php do_action( 'eggl_wd_return_request_customer_form', $order, $withdrawal ); ?>
		<?php endif; ?>
	</div>

	<?php do_action( 'eggl_wd_return_request_form_before_submit', $order, $show_submit ); ?>

	<?php if ( $show_submit ) : ?>
		<?php if ( $withdrawal ) : ?>
			<input type="hidden" name="withdrawal_id" id="withdrawal-id" value="<?php echo esc_attr( $withdrawal->get_id() ); ?>" />
		<?php endif; ?>

		<?php if ( ! empty( $order_key ) ) : ?>
			<input type="hidden" name="order_key" value="<?php echo esc_attr( $order_key ); ?>" />
		<?php endif; ?>

		<<?php echo esc_html( \EGGL\Withdrawal\Package::force_div_form_field() ? 'div' : 'p' ); ?> class="form-row form-row-submit" id="order-withdrawal-request-submit">
			<?php wp_nonce_field( 'eggl_wd_order_withdrawal_request' ); ?>
			<button type="submit" class="woocommerce-button button woocommerce-form-return_request__submit<?php echo esc_attr( eggl_wd_wp_theme_get_element_class_name( 'button' ) ? ' ' . eggl_wd_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="order_withdrawal_request" value="<?php echo esc_attr_x( 'Confirm withdrawal', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?>"><?php echo esc_attr_x( 'Confirm withdrawal', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></button>
		</<?php echo esc_html( \EGGL\Withdrawal\Package::force_div_form_field() ? 'div' : 'p' ); ?>>
	<?php endif; ?>

	<div class="clear"></div>
	<?php do_action( 'eggl_wd_return_request_form_end', $order ); ?>
</form>
