<?php
/**
 * Integration tests for scheduling and its guards.
 *
 * Needs WordPress. Covers SPEC.md sections 10.1, 10.3 and 11.
 * Method names match the T-identifiers in SPEC.md section 16.4.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

/**
 * Every guard, and every transition that schedules or cancels.
 */
class SchedulerTest extends WP_UnitTestCase {

	/**
	 * Turn the plugin on and clear state before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$settings            = SRL_Settings::defaults();
		$settings['enabled'] = true;
		update_option( SRL_Settings::OPTION, $settings );

		_set_cron_array( array() );
	}

	/**
	 * Publish a draft the way WordPress does, so the transition really fires.
	 *
	 * @param array<string, mixed> $args Post arguments.
	 * @return int
	 */
	private function publish_a_draft( array $args = array() ): int {
		$post_id = self::factory()->post->create(
			array_merge( array( 'post_status' => 'draft' ), $args )
		);

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		return (int) $post_id;
	}

	/** T-300 */
	public function test_draft_to_publish_schedules_one_event(): void {
		$post_id = $this->publish_a_draft();

		$this->assertSame( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertTrue( SRL_Scheduler::has_pending_send( $post_id ) );
	}

	/** T-302 */
	public function test_future_to_publish_schedules_one_event(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + 60 ),
			)
		);

		wp_publish_post( $post_id );

		$this->assertSame( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::get_status( (int) $post_id ) );
	}

	/** T-303 */
	public function test_publish_to_publish_schedules_nothing(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		delete_post_meta( $post_id, SRL_Post_Meta::META_STATUS );
		_set_cron_array( array() );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Edited after publishing',
			)
		);

		$this->assertFalse( SRL_Scheduler::has_pending_send( (int) $post_id ) );
	}

	/** T-305 */
	public function test_disabled_post_type_schedules_nothing(): void {
		register_post_type( 'srl_thing', array( 'public' => true ) );

		$post_id = $this->publish_a_draft( array( 'post_type' => 'srl_thing' ) );

		$this->assertFalse( SRL_Scheduler::has_pending_send( $post_id ) );

		unregister_post_type( 'srl_thing' );
	}

	/** T-312 */
	public function test_bulk_edit_is_skipped_and_logged(): void {
		$_REQUEST['bulk_edit'] = 'Update';

		$post_id = $this->publish_a_draft();

		unset( $_REQUEST['bulk_edit'] );

		$this->assertFalse( SRL_Scheduler::has_pending_send( $post_id ) );
		$this->assertNotEmpty( SRL_Log::for_post( $post_id ) );
	}

	/** T-313 */
	public function test_post_older_than_freshness_window_is_skipped(): void {
		$old = gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) );

		$post_id = $this->publish_a_draft(
			array(
				'post_date'     => $old,
				'post_date_gmt' => $old,
			)
		);

		$this->assertFalse(
			SRL_Scheduler::has_pending_send( $post_id ),
			'A post dated 40 days ago is not "newly published"; an import must not schedule it.'
		);
	}

	/**
	 * T-307
	 *
	 * The blocker: publish, unpublish, republish sent the post twice, without
	 * the confirmation click that is meant to be the only path to a second
	 * post. X's duplicate rejection does not save this, because the title is
	 * usually corrected in between.
	 */
	public function test_republish_after_sent_is_a_no_op(): void {
		$post_id = $this->publish_a_draft();

		SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SENT );
		_set_cron_array( array() );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( SRL_Post_Meta::STATUS_SENT, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertFalse( SRL_Scheduler::has_pending_send( $post_id ) );
	}

	/** T-306 */
	public function test_republish_after_cancel_schedules_again(): void {
		$post_id = $this->publish_a_draft();

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( SRL_Post_Meta::STATUS_CANCELLED, SRL_Post_Meta::get_status( $post_id ) );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::get_status( $post_id ) );
	}

	/** T-320 */
	public function test_unpublish_clears_event_and_cancels(): void {
		$post_id = $this->publish_a_draft();

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		$this->assertSame( SRL_Post_Meta::STATUS_CANCELLED, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertFalse( SRL_Scheduler::has_pending_send( $post_id ) );
	}

	/** T-321 */
	public function test_trash_clears_event_and_cancels(): void {
		$post_id = $this->publish_a_draft();

		wp_trash_post( $post_id );

		$this->assertSame( SRL_Post_Meta::STATUS_CANCELLED, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertFalse( SRL_Scheduler::has_pending_send( $post_id ) );
	}

	/**
	 * T-323
	 *
	 * WordPress matches events by md5( serialize( $args ) ), which is
	 * type-sensitive. Scheduling with an int and clearing with a string leaves
	 * a live event behind while the meta says cancelled.
	 */
	public function test_clear_uses_identical_argument_array(): void {
		$post_id = $this->publish_a_draft();
		$this->assertTrue( SRL_Scheduler::has_pending_send( $post_id ) );

		// Clear using the string form, as an admin request would supply it.
		wp_clear_scheduled_hook( SRL_Scheduler::SEND_HOOK, SRL_Scheduler::event_args( (string) $post_id ) );

		$this->assertFalse(
			SRL_Scheduler::has_pending_send( $post_id ),
			'event_args() must normalise to int so a string post id still matches.'
		);
	}

	/** T-317 */
	public function test_lost_event_is_reconciled_to_failed(): void {
		$post_id = $this->publish_a_draft();

		// Simulate the event vanishing: a cron flush, a migration, another
		// process overwriting the cron option.
		_set_cron_array( array() );
		update_post_meta( $post_id, SRL_Post_Meta::META_SCHEDULED_AT, time() - ( 2 * HOUR_IN_SECONDS ) );

		SRL_Scheduler::reconcile();

		$this->assertSame( SRL_Post_Meta::STATUS_FAILED, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertSame( 'event_lost', get_post_meta( $post_id, SRL_Post_Meta::META_LAST_ERROR, true ) );
	}

	/** T-404 */
	public function test_post_stalled_in_sending_becomes_failed_not_retried(): void {
		$post_id = $this->publish_a_draft();

		SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SENDING );
		update_post_meta( $post_id, SRL_Post_Meta::META_SENDING_SINCE, time() - 1800 );

		SRL_Scheduler::reconcile();

		$this->assertSame( SRL_Post_Meta::STATUS_FAILED, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertSame( 'stalled', get_post_meta( $post_id, SRL_Post_Meta::META_LAST_ERROR, true ) );
		$this->assertFalse(
			SRL_Scheduler::has_pending_send( $post_id ),
			'A crashed send is not retried automatically: it cannot be told apart from a lost response.'
		);
	}

	/** T-112 */
	public function test_master_switch_defaults_off_and_blocks_scheduling(): void {
		$settings            = SRL_Settings::defaults();
		$settings['enabled'] = false;
		update_option( SRL_Settings::OPTION, $settings );

		$post_id = $this->publish_a_draft();

		$this->assertFalse( SRL_Scheduler::has_pending_send( $post_id ) );
	}
}
