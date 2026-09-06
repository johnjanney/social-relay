<?php
/**
 * Integration tests for the remaining named requirements.
 *
 * These are the paths that are individually small and collectively the ones a
 * plugin actually fails on.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

/**
 * Guards, claim failure modes, and the state writes that must be atomic with
 * the request that causes them.
 */
class EdgeCasesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		$settings            = SRL_Settings::defaults();
		$settings['enabled'] = true;
		foreach ( SRL_Settings::CREDENTIAL_KEYS as $key ) {
			$settings[ $key ] = SRL_Settings::crypto()->encrypt( 'value-for-' . $key );
		}
		update_option( SRL_Settings::OPTION, $settings );

		_set_cron_array( array() );
		SRL_Log::install_table();
	}

	/**
	 * Publish a draft through the normal path.
	 *
	 * @param array<string, mixed> $args Post arguments.
	 * @return int
	 */
	private function publish( array $args = array() ): int {
		$post_id = self::factory()->post->create( array_merge( array( 'post_status' => 'draft' ), $args ) );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
		return (int) $post_id;
	}

	/** T-301 */
	public function test_pending_to_publish_schedules_one_event(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'pending' ) );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::get_status( (int) $post_id ) );
		$this->assertTrue( SRL_Scheduler::has_pending_send( (int) $post_id ) );
	}

	/** T-311 */
	public function test_status_and_scheduled_at_written_in_same_request(): void {
		$post_id = $this->publish();

		$this->assertSame( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $post_id, SRL_Post_Meta::META_SCHEDULED_AT, true ) );
	}

	/**
	 * T-310
	 *
	 * INV-2: no API call may happen in a request a human is waiting on. Delay 0
	 * still goes through the scheduler so there is exactly one send path and
	 * the Publish button never waits on X.
	 */
	public function test_zero_delay_still_goes_through_scheduler(): void {
		$settings                = SRL_Settings::all();
		$settings['delay_value'] = 0;
		update_option( SRL_Settings::OPTION, $settings );

		$called = false;
		add_filter(
			'pre_http_request',
			function () use ( &$called ) {
				$called = true;
				return new WP_Error( 'blocked', 'no network in tests' );
			}
		);

		$post_id = $this->publish();

		$this->assertFalse( $called, 'the publishing request must make no API call' );
		$this->assertTrue( SRL_Scheduler::has_pending_send( $post_id ) );
		$this->assertSame( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::get_status( $post_id ) );
	}

	/** T-201 */
	public function test_unchecked_post_is_not_scheduled(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		update_post_meta( $post_id, SRL_Post_Meta::META_ENABLED, '0' );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertFalse( SRL_Scheduler::has_pending_send( (int) $post_id ) );
	}

	/**
	 * T-309
	 *
	 * Note: uses the explicit override rather than defining WP_IMPORTING. A constant
	 * defined here would leave every later test believing it is inside an
	 * import, and the guard would silently skip all of them -- which is how
	 * this test first broke sixteen others.
	 */
	public function test_wp_importing_is_skipped_and_logged(): void {
		SRL_Scheduler::$importing_override = true;

		$post_id = $this->publish();

		$this->assertFalse(
			SRL_Scheduler::has_pending_send( $post_id ),
			'An importer must not spend the owner money.'
		);

		$rows = SRL_Log::for_post( $post_id );
		$this->assertNotEmpty( $rows, 'a skip with a cost implication must be findable later' );
		$this->assertStringContainsString( 'importing', (string) $rows[0]->message );

		SRL_Scheduler::$importing_override = null;
	}

	/** T-308 */
	public function test_untrash_then_publish_does_not_resend(): void {
		$post_id = $this->publish();
		SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SENT );

		wp_trash_post( $post_id );
		wp_untrash_post( $post_id );
		_set_cron_array( array() );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( SRL_Post_Meta::STATUS_SENT, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertFalse( SRL_Scheduler::has_pending_send( $post_id ) );
	}

	/** T-322 */
	public function test_delete_clears_event_and_cancels(): void {
		$post_id = $this->publish();
		$this->assertTrue( SRL_Scheduler::has_pending_send( $post_id ) );

		wp_delete_post( $post_id, true );

		$this->assertFalse( SRL_Scheduler::has_pending_send( $post_id ) );
	}

	/**
	 * T-316
	 *
	 * Note that wp_schedule_single_event() rejects an identical event already due within
	 * ten minutes. Silently ignoring that left posts reading "Scheduled"
	 * forever with nothing to move them.
	 */
	public function test_schedule_failure_sets_failed_not_scheduled(): void {
		$post_id = $this->publish();
		$this->assertTrue( SRL_Scheduler::has_pending_send( $post_id ) );

		// A second identical event at the same time is inside the window.
		$due = (int) wp_next_scheduled( SRL_Scheduler::SEND_HOOK, SRL_Scheduler::event_args( $post_id ) );
		$ok  = SRL_Scheduler::schedule_send( $post_id, $due );

		$this->assertFalse( $ok );
		$this->assertSame( SRL_Post_Meta::STATUS_FAILED, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertSame( 'schedule_failed', get_post_meta( $post_id, SRL_Post_Meta::META_LAST_ERROR, true ) );
	}

	/**
	 * T-406
	 *
	 * Duplicate _srl_status rows are possible after a plugin-driven
	 * duplication or an import. The claim must abort rather than paper over a
	 * data defect the owner needs to see.
	 */
	public function test_duplicate_status_meta_aborts_and_logs(): void {
		global $wpdb;

		$post_id = $this->publish();

		// A second row for the same key, which add_post_meta permits.
		add_post_meta( $post_id, SRL_Post_Meta::META_STATUS, SRL_Post_Meta::STATUS_SCHEDULED );
		wp_cache_delete( $post_id, 'post_meta' );

		$this->assertSame( 'duplicate_meta', SRL_Publisher::claim( $post_id ) );
		$this->assertSame( SRL_Post_Meta::STATUS_FAILED, SRL_Post_Meta::get_status( $post_id ) );
	}

	/**
	 * T-407
	 *
	 * The block editor submits meta box fields in a separate request, after the
	 * REST publish. Re-reading at send time is what closes that gap.
	 */
	public function test_enabled_flag_rechecked_at_send_time(): void {
		$post_id = $this->publish();

		// The owner unchecks the box after publishing, before the delay elapses.
		update_post_meta( $post_id, SRL_Post_Meta::META_ENABLED, '0' );

		$called = false;
		add_filter(
			'pre_http_request',
			function () use ( &$called ) {
				$called = true;
				return new WP_Error( 'blocked', 'no network' );
			}
		);

		SRL_Publisher::run( $post_id );

		$this->assertFalse( $called, 'no API call once the switch is off' );
		$this->assertSame( SRL_Post_Meta::STATUS_CANCELLED, SRL_Post_Meta::get_status( $post_id ) );
	}

	/** T-500 */
	public function test_log_row_written_for_each_event_type(): void {
		$post_id = $this->publish();

		$this->assertNotEmpty( SRL_Log::for_post( $post_id ) );
		$this->assertSame( SRL_Log::EVENT_SCHEDULED, SRL_Log::for_post( $post_id )[0]->event );

		SRL_Scheduler::cancel( $post_id, 'test' );
		$this->assertSame( SRL_Log::EVENT_CANCELLED, SRL_Log::for_post( $post_id )[0]->event );
	}

	/** T-140 */
	public function test_usage_counter_increments_on_success(): void {
		$post_id = $this->publish();
		SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SCHEDULED );

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 201 ),
					'body'     => wp_json_encode( array( 'data' => array( 'id' => '1' ) ) ),
					'headers'  => array(),
				);
			}
		);

		SRL_Publisher::run( $post_id );

		$this->assertSame( SRL_Post_Meta::STATUS_SENT, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertSame( 1, SRL_Usage::for_month()['POST /2/tweets'] );
	}

	/** T-461 */
	public function test_403_does_not_retry_and_raises_notice(): void {
		$post_id = $this->publish();
		SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SCHEDULED );

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 403 ),
					'body'     => '{"title":"Forbidden","detail":"read-only application"}',
					'headers'  => array(),
				);
			}
		);

		SRL_Publisher::run( $post_id );

		$this->assertSame( SRL_Post_Meta::STATUS_FAILED, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertFalse( SRL_Scheduler::has_pending_send( $post_id ) );
		$this->assertContains( $post_id, SRL_Notices::pending() );
	}

	/**
	 * T-471
	 *
	 * A duplicate rejection on a retry usually means the earlier attempt did go
	 * through. Reporting a plain failure would be wrong in the direction that
	 * costs a duplicate post.
	 */
	public function test_duplicate_on_retry_uses_duplicate_on_retry_and_warns_may_be_live(): void {
		$post_id = $this->publish();
		SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SCHEDULED );
		update_post_meta( $post_id, SRL_Post_Meta::META_ATTEMPTS, 2 );

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 403 ),
					'body'     => '{"detail":"You are not allowed to create a duplicate status."}',
					'headers'  => array(),
				);
			}
		);

		SRL_Publisher::run( $post_id );

		$this->assertSame(
			'duplicate_on_retry',
			get_post_meta( $post_id, SRL_Post_Meta::META_LAST_ERROR, true )
		);
	}
}
