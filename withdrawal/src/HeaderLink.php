<?php

namespace EGGL\Withdrawal;

defined( 'ABSPATH' ) || exit;

/**
 * Optional "Withdraw from contract" link in the site's header menu (setting: header_link).
 *
 * Uses the same header menu locations as the plugin's header notice
 * (\EGGL_Frontend::resolve_menu_locations()): classic menus, the automatic
 * "list all pages" fallback menu, and block-theme Navigation blocks.
 */
class HeaderLink {

	public static function init() {
		add_filter( 'wp_nav_menu_items', array( __CLASS__, 'nav_menu_items' ), 20, 2 );
		add_filter( 'wp_page_menu_args', array( __CLASS__, 'page_menu_args' ), 20 );
		add_filter( 'wp_page_menu', array( __CLASS__, 'page_menu' ), 20, 2 );
		add_filter( 'render_block_core/navigation', array( __CLASS__, 'navigation_block' ), 20, 2 );
	}

	public static function is_enabled() {
		return 'yes' === Package::get_setting( 'header_link', 'yes' ) && eggl_wd_has_public_withdrawal_page();
	}

	protected static function url() {
		return eggl_wd_get_withdrawal_page_permalink();
	}

	protected static function is_header_location( $theme_location ) {
		return ! empty( $theme_location ) && in_array( $theme_location, \EGGL_Frontend::resolve_menu_locations(), true );
	}

	/**
	 * Whether the menu markup already links to the withdrawal page (e.g. the merchant added it by hand).
	 */
	protected static function menu_has_link( $html ) {
		$page_id = eggl_wd_get_withdrawal_page_id();
		$url     = self::url();

		return ( $url && false !== strpos( $html, esc_url( $url ) ) ) || ( $page_id > 0 && false !== strpos( $html, 'page-item-' . $page_id . '"' ) ) || ( $page_id > 0 && false !== strpos( $html, 'page-item-' . $page_id . ' ' ) );
	}

	protected static function item_html( $block_theme = false ) {
		$is_current = is_page( eggl_wd_get_withdrawal_page_id() );
		$text       = eggl_wd_get_withdrawal_button_text();

		if ( $block_theme ) {
			return '<li class="wp-block-navigation-item eggl-wd-header-link' . ( $is_current ? ' current-menu-item' : '' ) . '"><a class="wp-block-navigation-item__content" href="' . esc_url( self::url() ) . '"' . ( $is_current ? ' aria-current="page"' : '' ) . '><span class="wp-block-navigation-item__label">' . esc_html( $text ) . '</span></a></li>';
		}

		// "menu-link": the class many themes (Astra and others) use to space and style their own menu links.
		return '<li class="menu-item eggl-wd-header-link' . ( $is_current ? ' current-menu-item' : '' ) . '"><a class="menu-link" href="' . esc_url( self::url() ) . '"' . ( $is_current ? ' aria-current="page"' : '' ) . '>' . esc_html( $text ) . '</a></li>';
	}

	/** Classic theme with a menu assigned to the header location. */
	public static function nav_menu_items( $items, $args ) {
		if ( is_admin() || ! self::is_enabled() || empty( $args->theme_location ) || ! self::is_header_location( $args->theme_location ) ) {
			return $items;
		}

		return self::menu_has_link( $items ) ? $items : $items . self::item_html();
	}

	/**
	 * The automatic "list all pages" fallback menu lists every published page, including the
	 * withdrawal page under its page title. Always keep it out of that list: when the header
	 * link is on, page_menu() adds it back as our own item using the "Button text" setting.
	 */
	public static function page_menu_args( $args ) {
		if ( is_admin() ) {
			return $args;
		}

		$page_id = eggl_wd_get_withdrawal_page_id();

		if ( $page_id > 0 ) {
			$exclude         = array_filter( array_map( 'absint', explode( ',', isset( $args['exclude'] ) ? (string) $args['exclude'] : '' ) ) );
			$exclude[]       = $page_id;
			$args['exclude'] = implode( ',', array_unique( $exclude ) );
		}

		return $args;
	}

	/** Fallback page menu on the header location: add the link if the page list didn't include it. */
	public static function page_menu( $menu, $args ) {
		$theme_location = is_array( $args ) && ! empty( $args['theme_location'] ) ? $args['theme_location'] : '';

		if ( is_admin() || ! self::is_enabled() || ! self::is_header_location( $theme_location ) || self::menu_has_link( $menu ) ) {
			return $menu;
		}

		$pos = strripos( $menu, '</ul>' );

		return false === $pos ? $menu . self::item_html() : substr( $menu, 0, $pos ) . self::item_html() . substr( $menu, $pos );
	}

	/** Block themes: append to the header Navigation block's top-level list. */
	public static function navigation_block( $block_content, $block ) {
		if ( is_admin() || ! self::is_enabled() || self::menu_has_link( $block_content ) ) {
			return $block_content;
		}

		if ( ! preg_match( '/<ul\b[^>]*\bclass="[^"]*\bwp-block-navigation__container\b[^"]*"[^>]*>/i', $block_content, $match, PREG_OFFSET_CAPTURE ) ) {
			return $block_content;
		}

		// Append as the last top-level item: find the container's matching </ul>.
		$start = $match[0][1] + strlen( $match[0][0] );
		$depth = 1;
		$pos   = $start;

		while ( $depth > 0 && preg_match( '#<ul\b|</ul>#i', $block_content, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
			$depth += ( '</ul>' === strtolower( $m[0][0] ) ) ? -1 : 1;
			$pos    = $m[0][1] + ( $depth > 0 ? strlen( $m[0][0] ) : 0 );
		}

		if ( 0 !== $depth ) {
			return substr( $block_content, 0, $start ) . self::item_html( true ) . substr( $block_content, $start );
		}

		return substr( $block_content, 0, $pos ) . self::item_html( true ) . substr( $block_content, $pos );
	}
}
