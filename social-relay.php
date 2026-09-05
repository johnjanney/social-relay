<?php
/**
 * Plugin Name:       Social Relay
 * Plugin URI:        https://github.com/johnjanney/social-relay
 * Description:       Publishes the title, featured image, and permalink of each newly published post to one X account, after a configurable delay.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            John Janney
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       social-relay
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

// Direct access is not a supported entry point.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SRL_VERSION', '0.1.0' );
define( 'SRL_PLUGIN_FILE', __FILE__ );
define( 'SRL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SRL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Floors decided in OQ-5 and OQ-16 on 2026-09-05.
define( 'SRL_MIN_PHP', '8.2' );
define( 'SRL_MIN_WP', '6.5' );

/**
 * Refuse to run below the supported floor, loudly rather than fatally.
 *
 * WordPress honours the "Requires PHP" header when updating, but not when a zip
 * is uploaded by hand, which is exactly how this plugin is installed. Without
 * this guard an unsupported host gets a parse error and a white screen, which
 * looks like the site broke rather than like the plugin declined to load.
 *
 * @return bool True when the environment is supported.
 */
function srl_environment_is_supported(): bool {
	global $wp_version;

	if ( version_compare( PHP_VERSION, SRL_MIN_PHP, '<' ) ) {
		return false;
	}
	if ( isset( $wp_version ) && version_compare( $wp_version, SRL_MIN_WP, '<' ) ) {
		return false;
	}
	return true;
}

if ( ! srl_environment_is_supported() ) {
	add_action(
		'admin_notices',
		function (): void {
			global $wp_version;
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: required WP version, 3: current PHP version, 4: current WP version */
						__( 'Social Relay needs PHP %1$s or newer and WordPress %2$s or newer. This site runs PHP %3$s and WordPress %4$s, so the plugin has not loaded.', 'social-relay' ),
						SRL_MIN_PHP,
						SRL_MIN_WP,
						PHP_VERSION,
						isset( $wp_version ) ? $wp_version : '?'
					)
				)
			);
		}
	);
	return;
}

/**
 * Class map.
 *
 * An explicit map rather than a convention-based autoloader. With fewer than
 * fifteen files, a map is less code than the string manipulation needed to
 * derive paths, it cannot silently mis-resolve a name, and it makes the whole
 * file list visible in one place. This is PROJECTBRIEF section 0.5 applied to
 * the loader itself.
 *
 * @return array<string, string> Class name to path, relative to includes/.
 */
function srl_class_map(): array {
	return array(
		'SRL_Plugin'       => 'class-plugin.php',
		'SRL_Settings'     => 'class-settings.php',
		'SRL_Crypto'       => 'class-crypto.php',
		'SRL_Text'         => 'class-text.php',
		'SRL_OAuth1'       => 'class-oauth1.php',
		'SRL_Scheduler'    => 'class-scheduler.php',
		'SRL_Publisher'    => 'class-publisher.php',
		'SRL_Log'          => 'class-log.php',
		'SRL_Post_Meta'    => 'class-post-meta.php',
		'SRL_Cron_Health'  => 'class-cron-health.php',
		'SRL_Usage'        => 'class-usage.php',
		'SRL_Notices'      => 'class-notices.php',
		'SRL_Post_Payload' => 'class-post-payload.php',
		'SRL_Send_Result'  => 'class-send-result.php',
		'SRL_Provider'     => 'providers/interface-provider.php',
		'SRL_X_Provider'   => 'providers/class-x-provider.php',
	);
}

spl_autoload_register(
	function ( string $class_name ): void {
		$map = srl_class_map();
		if ( ! isset( $map[ $class_name ] ) ) {
			return;
		}
		$path = SRL_PLUGIN_DIR . 'includes/' . $map[ $class_name ];
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/*
 * The custom cron schedule is registered here, at file scope, and NOT inside
 * SRL_Plugin::register_hooks().
 *
 * register_hooks() runs on plugins_loaded, and plugins_loaded does not fire
 * again for the plugin being activated in that same request. The activation
 * hook would therefore call wp_schedule_event() with an unregistered schedule
 * name, which returns false silently. The heartbeat would never exist, so the
 * cron health panel could never turn green and the reconciliation scan in
 * SRL_Scheduler would never run -- which would leave INV-7 with no enforcement
 * whatsoever. Caught by activating the plugin on a real site.
 */
add_filter( 'cron_schedules', array( 'SRL_Cron_Health', 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- A one-minute schedule is the point; see SPEC.md 11.3.

/**
 * Activation.
 *
 * Creates the log table, writes defaults for any setting that has none, and
 * registers the recurring events. It deliberately does NOT enable the plugin:
 * FR-1.3 requires the master switch to default off, so that an activated but
 * unconfigured install can never post.
 */
function srl_activate(): void {
	SRL_Log::install_table();
	SRL_Settings::install_defaults();
	SRL_Cron_Health::schedule_heartbeat();
	SRL_Log::schedule_prune();

	// Fail loudly rather than silently: without the heartbeat there is no
	// reconciliation pass and no honest cron health reading.
	if ( ! wp_next_scheduled( SRL_Cron_Health::HOOK ) ) {
		SRL_Log::write(
			SRL_Log::EVENT_FAILED,
			0,
			null,
			null,
			'Could not schedule the heartbeat on activation; cron health and reconciliation will not run.'
		);
	}
}
register_activation_hook( __FILE__, 'srl_activate' );

/**
 * Deactivation.
 *
 * Clears the plugin's recurring events and every pending send. Data is left
 * alone: deactivation is not uninstallation, and a site owner who deactivates
 * to debug something should not lose their log or their settings.
 *
 * Pending sends are cleared because an event that fires while the plugin is
 * inactive would find no handler, and WordPress would leave it in the cron
 * array forever.
 */
function srl_deactivate(): void {
	SRL_Cron_Health::unschedule_heartbeat();
	SRL_Log::unschedule_prune();
	SRL_Scheduler::clear_all_pending_sends();
}
register_deactivation_hook( __FILE__, 'srl_deactivate' );

/**
 * Boot.
 */
function srl_plugin(): SRL_Plugin {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new SRL_Plugin();
	}
	return $instance;
}

// Deferred deliberately: passing array( srl_plugin(), ... ) here would construct the
// plugin at file-load time, before plugins_loaded, which is earlier than any
// hook needs and earlier than translations are available.
add_action(
	'plugins_loaded',
	function (): void {
		srl_plugin()->register_hooks();
	}
);
