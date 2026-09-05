<?php
/**
 * Bootstrap for the WordPress-free "unit" suite.
 *
 * The classes under test in this suite must not call WordPress functions. Where
 * one legitimately needs a WordPress-supplied value — the crypto key derived
 * from wp_salt() — the value is injected through the constructor rather than
 * fetched, which is why this file defines only a couple of shims rather than a
 * WordPress emulation layer. If this file starts growing, that is a signal the
 * production code has taken a hidden dependency on WordPress and should be
 * refactored, not that the shim list is short.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

// Loaded so the classes can be required individually without the plugin
// bootstrap, which does need WordPress.
define( 'SRL_UNIT_TESTS', true );

if ( ! defined( 'ABSPATH' ) ) {
	// Several files guard on ABSPATH to prevent direct web access. Defining it
	// lets those files load under test without weakening the guard in production.
	define( 'ABSPATH', SRL_PLUGIN_DIR . '/' );
}

/**
 * Minimal stand-ins for the two WordPress helpers the pure classes may touch
 * for input hygiene. Both are deliberately faithful to the real behaviour that
 * matters here, and neither is used for anything security-critical in the unit
 * suite.
 */
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) { // phpcs:ignore
		return $text;
	}
}
