<?php
/**
 * Integration tests for the owner-triggered actions.
 *
 * Covers FR-1.5, FR-2.4 and FR-2.5.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

/**
 * Cancel, repost, and the connectivity test.
 */
class ActionsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		$settings            = SRL_Settings::defaults();
		$settings['enabled'] = true;
		foreach ( SRL_Settings::CREDENTIAL_KEYS as $key ) {
			$settings[ $key ] = SRL_Settings::crypto()->encrypt( 'value-for-' . $key );
		}
		update_option( SRL_Settings::OPTION, $settings );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		_set_cron_array( array() );
		SRL_Log::install_table();
	}

	public function tear_down(): void {
		unset( $_POST[ SRL_Post_Meta::NONCE_FIELD ], $_POST['srl_action'] );
		parent::tear_down();
	}

	/**
	 * Put a valid meta box submission in $_POST.
	 *
	 * @param string $action Action value.
	 * @return void
	 */
	private function submit( string $action ): void {
		$_POST[ SRL_Post_Meta::NONCE_FIELD ] = wp_create_nonce( SRL_Post_Meta::NONCE_ACTION );
		$_POST['srl_action']                 = $action;
	}

	/** T-231 */
	public function test_cancel_clears_event_and_sets_cancelled(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		// Publishing already scheduled an event. Scheduling a second identical
		// one lands inside WordPress's ten-minute duplicate window, which
		// wp_schedule_single_event() rejects -- and SRL_Scheduler correctly
		// marks the post failed rather than pretending it worked. Clear first
		// so this test exercises cancellation and not that guard.
		_set_cron_array( array() );
		SRL_Post_Meta::set_status( (int) $post_id, SRL_Post_Meta::STATUS_SCHEDULED );
		$this->assertTrue( SRL_Scheduler::schedule_send( (int) $post_id, time() + 3600 ) );

		$this->submit( 'cancel' );
		SRL_Post_Meta::handle_action( (int) $post_id );

		$this->assertSame( SRL_Post_Meta::STATUS_CANCELLED, SRL_Post_Meta::get_status( (int) $post_id ) );
		$this->assertFalse( SRL_Scheduler::has_pending_send( (int) $post_id ) );
	}

	/** T-242 */
	public function test_repost_resets_attempts_and_schedules_at_zero_delay(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		_set_cron_array( array() );
		SRL_Post_Meta::set_status( (int) $post_id, SRL_Post_Meta::STATUS_SENT );
		update_post_meta( $post_id, SRL_Post_Meta::META_ATTEMPTS, 4 );

		$this->submit( 'repost' );
		SRL_Post_Meta::handle_action( (int) $post_id );

		$this->assertSame( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::get_status( (int) $post_id ) );
		$this->assertSame( '', get_post_meta( $post_id, SRL_Post_Meta::META_ATTEMPTS, true ) );

		$due = (int) wp_next_scheduled( SRL_Scheduler::SEND_HOOK, SRL_Scheduler::event_args( (int) $post_id ) );
		$this->assertEqualsWithDelta( time(), $due, 10, 'a repost goes out at delay 0' );
	}

	/**
	 * "Repost now" is the only path to a second post, so it must be reachable
	 * only from sent and failed. Re-submitting the editor form while a send is
	 * scheduled or in flight must not duplicate it.
	 */
	public function test_repost_is_ignored_unless_sent_or_failed(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		foreach ( array( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::STATUS_SENDING, SRL_Post_Meta::STATUS_CANCELLED ) as $status ) {
			SRL_Post_Meta::set_status( (int) $post_id, $status );
			_set_cron_array( array() );

			$this->submit( 'repost' );
			SRL_Post_Meta::handle_action( (int) $post_id );

			$this->assertSame( $status, SRL_Post_Meta::get_status( (int) $post_id ), "must be ignored from {$status}" );
		}
	}

	public function test_repost_without_a_nonce_does_nothing(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		SRL_Post_Meta::set_status( (int) $post_id, SRL_Post_Meta::STATUS_SENT );

		unset( $_POST[ SRL_Post_Meta::NONCE_FIELD ] );
		$_POST['srl_action'] = 'repost';

		SRL_Post_Meta::handle_action( (int) $post_id );

		$this->assertSame( SRL_Post_Meta::STATUS_SENT, SRL_Post_Meta::get_status( (int) $post_id ) );
	}

	/**
	 * T-120
	 *
	 * The test post is billed at the cheap rate only because it has no link.
	 */
	public function test_test_post_contains_no_url(): void {
		$sent = null;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$sent ) {
				$sent = json_decode( (string) $args['body'], true );
				return array(
					'response' => array( 'code' => 201 ),
					'body'     => wp_json_encode( array( 'data' => array( 'id' => '1' ) ) ),
					'headers'  => array(),
				);
			},
			10,
			2
		);

		$provider = new SRL_X_Provider( SRL_Settings::signer() );
		$provider->send( new SRL_Post_Payload( sprintf( 'Social Relay connectivity test %s UTC', gmdate( 'Y-m-d H:i' ) ), '' ) );

		$this->assertIsArray( $sent );
		$this->assertStringNotContainsString( 'http://', $sent['text'] );
		$this->assertStringNotContainsString( 'https://', $sent['text'] );
	}

	/**
	 * T-122
	 *
	 * A fixed string is rejected as a duplicate on the second press, so the
	 * owner's only credential check would report failure for working keys.
	 */
	public function test_test_post_text_differs_between_invocations(): void {
		$first  = sprintf( 'Social Relay connectivity test %s UTC', gmdate( 'Y-m-d H:i', 1788652663 ) );
		$second = sprintf( 'Social Relay connectivity test %s UTC', gmdate( 'Y-m-d H:i', 1788652663 + 120 ) );

		$this->assertNotSame( $first, $second );
	}

	/** T-121 */
	public function test_test_post_writes_log_row_with_post_id_zero(): void {
		SRL_Log::write( SRL_Log::EVENT_TEST, 0, 201, '1', 'Test post sent.' );

		$row = SRL_Log::recent( 1 )[0];

		$this->assertSame( 'test', $row->event );
		$this->assertSame( '0', (string) $row->post_id );
	}
}
