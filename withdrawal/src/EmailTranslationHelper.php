<?php

namespace EGGL\Withdrawal;

defined( 'ABSPATH' ) || exit;

trait EmailTranslationHelper {

	public function setup_email_locale( $lang = false ) {
		if ( apply_filters( 'eggl_wd_allow_switching_email_locale', true ) ) {
			do_action( 'eggl_wd_switch_email_locale', $this, $lang );
		}
	}

	public function restore_email_locale() {
		if ( apply_filters( 'eggl_wd_allow_restoring_email_locale', true ) ) {
			do_action( 'eggl_wd_restore_email_locale', $this );
		}
	}
}
