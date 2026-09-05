<?php
/**
 * PHPUnit bootstrap.
 *
 * Two suites share this file:
 *
 *  - "unit" needs no WordPress. It covers the OAuth 1.0a signer, the weighted
 *    length algorithm, and the secret storage envelope. It must run on a bare
 *    PHP install, because those three are where a subtle bug is both most
 *    likely and least visible, and they should be testable without Docker.
 *
 *  - "integration" needs the WordPress test suite, supplied by wp-env locally
 *    or by bin/install-wp-tests.sh in CI.
 *
 * The suite is chosen by which directory PHPUnit was pointed at, so this file
 * detects rather than being told.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

define( 'SRL_TESTS_DIR', __DIR__ );
// Trailing slash is required: the plugin defines this as plugin_dir_path(),
// which ends in one, and whichever definition lands first wins. Without the
// slash the autoloader builds "...pluginincludes/class-plugin.php" and every
// class silently fails to load.
define( 'SRL_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

require_once SRL_PLUGIN_DIR . '/vendor/autoload.php';

/**
 * Decide whether WordPress is needed for this run.
 *
 * PHPUnit does not expose the selected testsuite to the bootstrap, so this
 * reads the command line. Defaulting to "WordPress needed" would make the unit
 * suite fail on a machine without the test library, which defeats its purpose.
 */
$srl_argv        = $_SERVER['argv'] ?? array();
$srl_wants_unit  = in_array( 'unit', $srl_argv, true );
$srl_wants_intgr = in_array( 'integration', $srl_argv, true );
$srl_needs_wp    = $srl_wants_intgr || ! $srl_wants_unit;

if ( ! $srl_needs_wp ) {
	require_once SRL_TESTS_DIR . '/unit-bootstrap.php';
	return;
}

$srl_tests_lib = getenv( 'WP_TESTS_DIR' );
if ( ! $srl_tests_lib ) {
	$srl_tests_lib = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $srl_tests_lib . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"Could not find the WordPress test suite at {$srl_tests_lib}.\n" .
		"Run: bin/install-wp-tests.sh wordpress_test root '' localhost 6.5\n" .
		"Or set WP_TESTS_DIR. The 'unit' suite runs without any of this:\n" .
		"    vendor/bin/phpunit --testsuite unit\n"
	);
	exit( 1 );
}

// The WordPress test suite requires Yoast's PHPUnit Polyfills and will refuse
// to boot without them. Pointing at the vendored copy is the documented way to
// satisfy that without vendoring WordPress's own dev dependencies.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', SRL_PLUGIN_DIR . '/vendor/yoast/phpunit-polyfills' );
}

require_once $srl_tests_lib . '/includes/functions.php';

/**
 * Load the plugin into the test WordPress instance.
 */
function srl_manually_load_plugin(): void {
	require SRL_PLUGIN_DIR . '/social-relay.php';
}
tests_add_filter( 'muplugins_loaded', 'srl_manually_load_plugin' );

require $srl_tests_lib . '/includes/bootstrap.php';
