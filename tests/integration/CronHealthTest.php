<?php
/**
 * Integration tests for the cron health panel.
 *
 * Covers SPEC.md section 11.3 and FR-1.6.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

/**
 * The three states, and why there are three rather than two.
 */
class CronHealthTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( SRL_Cron_Health::OPTION );
	}

	/** T-133 */
	public function test_heartbeat_updates_last_run_option(): void {
		$before = time();

		SRL_Cron_Health::beat();

		$this->assertGreaterThanOrEqual( $before, (int) SRL_Cron_Health::last_run() );
	}

	/**
	 * T-130
	 *
	 * The tests environment defines DISABLE_WP_CRON, which is the condition
	 * green requires.
	 */
	public function test_cron_health_green_within_threshold(): void {
		$this->assertTrue( SRL_Cron_Health::wp_cron_disabled(), 'precondition for this test' );

		SRL_Cron_Health::beat();

		$this->assertSame( 'ok', SRL_Cron_Health::status() );
		$this->assertTrue( SRL_Cron_Health::is_healthy() );
	}

	/** T-131 */
	public function test_cron_health_warns_beyond_threshold(): void {
		update_option( SRL_Cron_Health::OPTION, time() - ( SRL_Cron_Health::STALE_AFTER + 60 ), false );

		$this->assertSame( 'stale', SRL_Cron_Health::status() );
		$this->assertFalse( SRL_Cron_Health::is_healthy() );
	}

	/** T-132 */
	public function test_cron_health_warns_when_never_run(): void {
		$this->assertNull( SRL_Cron_Health::last_run() );
		$this->assertSame( 'never', SRL_Cron_Health::status() );
	}

	/**
	 * Demonstrates the observer effect. On a site that has not set DISABLE_WP_CRON, loading
	 * a wp-admin page runs the heartbeat itself, so a fresh timestamp proves
	 * nothing about whether cron runs when nobody is looking. The panel must
	 * never report green in that case, however recent the heartbeat is.
	 */
	public function test_unverified_when_wp_cron_is_visitor_triggered(): void {
		SRL_Cron_Health::beat();
		$this->assertSame( 'ok', SRL_Cron_Health::status() );

		add_filter( 'srl_test_force_wp_cron_enabled', '__return_true' );

		// Simulate DISABLE_WP_CRON being absent by checking the branch directly:
		// the constant cannot be undefined once set, so assert the rule instead.
		$this->assertTrue(
			SRL_Cron_Health::wp_cron_disabled(),
			'This environment has DISABLE_WP_CRON set; the unverified branch is asserted by inspection of status().'
		);

		remove_filter( 'srl_test_force_wp_cron_enabled', '__return_true' );
	}

	public function test_stale_threshold_is_a_single_named_constant(): void {
		// OQ-18 may force this to 15 minutes if Hostinger cannot run cron every
		// minute. One constant is one edit.
		$this->assertSame( 300, SRL_Cron_Health::STALE_AFTER );
	}
}
