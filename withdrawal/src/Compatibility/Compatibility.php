<?php

namespace EGGL\Withdrawal\Compatibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Compatibility {

	public static function is_active();

	public static function init();
}
