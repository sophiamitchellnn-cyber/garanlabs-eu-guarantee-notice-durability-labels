<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
<p><?php echo esc_html_x( 'When you submit a withdrawal request through our withdraw from contract form, we collect and store the following personal data for the sole purpose of fulfilling the legal traceability of consumer rights under EU Directive 2023/2673:', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></p>

<ul>
	<li><?php echo esc_html_x( 'Customer name and email address.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></li>
	<li><?php echo esc_html_x( 'Order reference.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></li>
	<li><?php echo esc_html_x( 'IP address and browser User-Agent string used to submit the request.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></li>
	<li><?php echo esc_html_x( 'Date and time of the submission.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></li>
	<?php if ( eggl_wd_enable_additional_information_field() ) : ?>
		<li><?php echo esc_html_x( 'Additional information in case provided.', 'owb', 'garanlabs-eu-guarantee-notice-durability-labels' ); ?></li>
	<?php endif; ?>
</ul>
