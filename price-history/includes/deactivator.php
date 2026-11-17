<?php

namespace EGGL\PriceHistory\Includes;


if (! defined('ABSPATH')) exit;


class Deactivator
{

	/**	
	 * @since    1.0.0
	 */
	public static function deactivate()
	{
		if (class_exists('EGGL\\PriceHistory\\Modules\\AnchorPrice\\Resolver')) {
			\EGGL\PriceHistory\Modules\AnchorPrice\Resolver::unschedule();
		}
	}
}
