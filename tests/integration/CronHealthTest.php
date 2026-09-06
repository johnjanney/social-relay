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
	 * The observer effect, exercised rather than asserted by inspection.
	 *
	 * On a site that has not set DISABLE_WP_CRON, loading any wp-admin page
	 * runs the heartbeat itself, so a fresh timestamp proves nothing about
	 * whether cron runs when nobody is looking. The panel must never report
	 * green in that case, however recent the heartbeat is.
	 *
	 * The first version of this test added a filter no production code read,
	 * then asserted the opposite of its own name, leaving the state most real
	 * sites are in with zero coverage.
	 */
	public function test_unverified_when_wp_cron_is_visitor_triggered(): void {
		SRL_Cron_Health::beat();
		$this->assertSame( 'ok', SRL_Cron_Health::status(), 'precondition: a fresh heartbeat' );

		SRL_Cron_Health::$wp_cron_disabled_override = false;

		$this->assertSame(
			'unverified',
			SRL_Cron_Health::status(),
			'a fresh heartbeat must not read as green when the page load could have caused it'
		);
		$this->assertFalse( SRL_Cron_Health::is_healthy() );

		SRL_Cron_Health::$wp_cron_disabled_override = null;
	}

	/**
	 * Whichever way the panel reads, the settings page must render it.
	 */
	public function test_settings_page_renders_each_cron_state(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$expectations = array(
			'unverified' => 'triggered by visitors',
			'never'      => 'has not run',
			'ok'         => 'Real cron is running',
		);

		foreach ( $expectations as $state => $needle ) {
			if ( 'unverified' === $state ) {
				SRL_Cron_Health::$wp_cron_disabled_override = false;
				SRL_Cron_Health::beat();
			} elseif ( 'never' === $state ) {
				SRL_Cron_Health::$wp_cron_disabled_override = true;
				delete_option( SRL_Cron_Health::OPTION );
			} else {
				SRL_Cron_Health::$wp_cron_disabled_override = true;
				SRL_Cron_Health::beat();
			}

			ob_start();
			srl_plugin()->render_settings_page();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( $needle, $html, "state: {$state}" );
		}

		SRL_Cron_Health::$wp_cron_disabled_override = null;
	}
}
