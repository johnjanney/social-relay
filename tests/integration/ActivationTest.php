<?php
/**
 * Integration tests for activation and deactivation.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

/**
 * What must exist after the plugin is switched on, and what must not survive
 * switching it off.
 */
class ActivationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		_set_cron_array( array() );
	}

	/**
	 * The one-minute schedule must be registered whenever the plugin file is
	 * loaded, not only after plugins_loaded.
	 *
	 * Registering it inside SRL_Plugin::register_hooks() looked correct and was
	 * not: plugins_loaded does not fire again for the plugin being activated,
	 * so wp_schedule_event() got an unknown schedule name and returned false
	 * silently. The heartbeat never existed, which meant the cron health panel
	 * could never turn green and the reconciliation scan never ran -- leaving
	 * INV-7 with no enforcement at all. Found by activating on a real site.
	 */
	public function test_minute_schedule_is_registered(): void {
		$schedules = wp_get_schedules();

		$this->assertArrayHasKey( SRL_Cron_Health::SCHEDULE, $schedules );
		$this->assertSame( 60, $schedules[ SRL_Cron_Health::SCHEDULE ]['interval'] );
	}

	public function test_activation_schedules_both_recurring_events(): void {
		srl_activate();

		$this->assertNotFalse(
			wp_next_scheduled( SRL_Cron_Health::HOOK ),
			'Without the heartbeat there is no reconciliation and no honest cron health reading.'
		);
		$this->assertNotFalse( wp_next_scheduled( SRL_Log::PRUNE_HOOK ) );
	}

	/** T-112 */
	public function test_activation_leaves_the_master_switch_off(): void {
		delete_option( SRL_Settings::OPTION );

		srl_activate();

		$this->assertFalse(
			SRL_Settings::is_enabled(),
			'An activated but unconfigured plugin must never be able to post.'
		);
	}

	public function test_deactivation_clears_recurring_events_but_keeps_data(): void {
		srl_activate();
		update_option( SRL_Settings::OPTION, array_merge( SRL_Settings::defaults(), array( 'prefix' => 'keep me' ) ) );

		srl_deactivate();

		$this->assertFalse( wp_next_scheduled( SRL_Cron_Health::HOOK ) );
		$this->assertFalse( wp_next_scheduled( SRL_Log::PRUNE_HOOK ) );
		$this->assertSame(
			'keep me',
			SRL_Settings::get( 'prefix' ),
			'Deactivation is not uninstallation; settings must survive it.'
		);
	}

	public function test_deactivation_clears_pending_sends(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		SRL_Scheduler::schedule_send( (int) $post_id, time() + 3600 );
		$this->assertTrue( SRL_Scheduler::has_pending_send( (int) $post_id ) );

		srl_deactivate();

		$this->assertFalse(
			SRL_Scheduler::has_pending_send( (int) $post_id ),
			'An event firing with no handler would sit in the cron array forever.'
		);
	}
}
