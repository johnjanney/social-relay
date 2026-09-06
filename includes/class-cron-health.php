<?php
/**
 * Cron health.
 *
 * Implements SPEC.md section 11.3 and FR-1.6.
 *
 * The panel must answer "is WP-Cron executing?", not "did something request
 * wp-cron.php?". Those differ in exactly the case that matters: if a caching
 * layer serves wp-cron.php from cache, requests succeed, nothing executes, and
 * a request-time metric would show green while every scheduled post silently
 * stalls. A heartbeat can only be written by code that actually ran, so that is
 * what is measured.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records and reports whether WP-Cron is running.
 */
class SRL_Cron_Health {

	/**
	 * Option holding the last observed run time, as a UTC timestamp.
	 */
	public const OPTION = 'srl_cron_last_run';

	/**
	 * The recurring heartbeat event.
	 */
	public const HOOK = 'srl_heartbeat';

	/**
	 * Custom schedule name.
	 */
	public const SCHEDULE = 'srl_minute';

	/**
	 * How stale the last run may be before the panel warns, in seconds.
	 *
	 * A single named constant rather than a literal, because SPEC.md section
	 * 11.3 records that this may need to become 15 minutes if the host cannot
	 * run cron every minute (OQ-18). One constant is one edit.
	 */
	public const STALE_AFTER = 300;

	/**
	 * Add the one-minute schedule.
	 *
	 * @param array<string, array{interval:int, display:string}> $schedules Existing schedules.
	 * @return array<string, array{interval:int, display:string}>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (Social Relay)', 'social-relay' ),
		);
		return $schedules;
	}

	/**
	 * Register the heartbeat.
	 *
	 * @return void
	 */
	public static function schedule_heartbeat(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Remove the heartbeat.
	 *
	 * @return void
	 */
	public static function unschedule_heartbeat(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The heartbeat itself.
	 *
	 * @return void
	 */
	public static function beat(): void {
		update_option( self::OPTION, time(), false );
	}

	/**
	 * When cron last ran, or null if it never has.
	 *
	 * @return int|null UTC timestamp.
	 */
	public static function last_run(): ?int {
		$value = get_option( self::OPTION, null );
		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * Seconds since the last run, or null if it never ran.
	 *
	 * @return int|null
	 */
	public static function seconds_since_last_run(): ?int {
		$last = self::last_run();
		if ( null === $last ) {
			return null;
		}
		return max( 0, time() - $last );
	}

	/**
	 * Test-only override for wp_cron_disabled().
	 *
	 * DISABLE_WP_CRON is a constant and the test environment always defines it
	 * as true, so without a seam the `unverified` branch -- the state most
	 * real sites are actually in, and the whole reason this panel has three
	 * states rather than two -- could never be exercised. Null in production.
	 *
	 * @var bool|null
	 */
	public static ?bool $wp_cron_disabled_override = null;

	/**
	 * Whether the site has handed cron over to a real scheduler.
	 *
	 * @return bool
	 */
	public static function wp_cron_disabled(): bool {
		if ( null !== self::$wp_cron_disabled_override ) {
			return self::$wp_cron_disabled_override;
		}

		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/**
	 * Health state: 'ok', 'unverified', 'stale', or 'never'.
	 *
	 * `unverified` exists because of the observer effect. On a site that has
	 * NOT set DISABLE_WP_CRON — exactly the misconfigured population this panel
	 * is meant to detect — loading any wp-admin page calls wp_cron(), which
	 * spawns a loopback request that runs due events including the heartbeat.
	 * The owner sees a warning, refreshes, and sees green, because their own
	 * page load caused the heartbeat. Their posts still stall for hours between
	 * visitors, which is the real condition, and a two-state panel would deny
	 * it. A metric a refresh can turn green teaches the owner to distrust it.
	 *
	 * @return string
	 */
	public static function status(): string {
		if ( ! self::wp_cron_disabled() ) {
			return 'unverified';
		}

		$since = self::seconds_since_last_run();

		if ( null === $since ) {
			return 'never';
		}
		if ( $since > self::STALE_AFTER ) {
			return 'stale';
		}
		return 'ok';
	}

	/**
	 * Whether the delay can currently be trusted.
	 *
	 * @return bool
	 */
	public static function is_healthy(): bool {
		return 'ok' === self::status();
	}

	/**
	 * Remove the recorded value. Used by uninstall.php.
	 *
	 * @return void
	 */
	public static function delete_all(): void {
		delete_option( self::OPTION );
	}
}
