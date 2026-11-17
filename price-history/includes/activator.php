<?php

namespace EGGL\PriceHistory\Includes;


if (! defined('ABSPATH')) exit;


class Activator
{

	/**
	 * @since    1.0.0
	 */
	public static function activate()
	{
		self::setup_db_table();
		// Picked up by Digital_Price_List\Router::maybe_flush() on the next request, once its rewrite rules are registered.
		update_option('eggl_ph_dpl_flush_rewrite', 1, false);
	}

	/**
	 * Create custom table.
	 *  
	 * @since     1.1.0
	 */
	private static function db_table_columns()
	{
		$columns = "product_id bigint(20) UNSIGNED NOT NULL,
			parent_product_id varchar(20) NULL,
			regular_price varchar(20) NULL,
			sale_price varchar(20) NULL,
			price DECIMAL(10,2) NOT NULL,
			currency varchar(3) NULL,
			product_type varchar(20) NULL,
			hidden TINYINT(1) UNSIGNED NULL,
			date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP";

		return $columns;
	}

	/**
	 * Create custom table.
	 *  
	 * @since     1.0.0
	 */
	private static function setup_db_table()
	{

		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		$table_name = $wpdb->prefix . 'eggl_ph_price_history';

		$columns = self::db_table_columns();

		$sql = "CREATE TABLE $table_name (
			  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,        
			  $columns,
			  PRIMARY KEY (id),
			  INDEX (product_id)
			) $charset_collate;";

		if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
			dbDelta($sql);
		}
	}
}
