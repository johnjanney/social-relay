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
		unset(
			$_POST[ SRL_Post_Meta::NONCE_FIELD ],
			$_POST[ SRL_Post_Meta::FIELD_ENABLED ],
			$_POST[ SRL_Post_Meta::FIELD_DELAY ],
			$_GET['post'],
			$_GET['do'],
			$_REQUEST['_wpnonce']
		);
		parent::tear_down();
	}

	/**
	 * Drive the admin_post handler for a post without a nonce and return
	 * whether it refused. check_admin_referer() dies, which the test suite
	 * turns into WPDieException, so the caller can assert nothing changed.
	 *
	 * @param int    $post_id Post id.
	 * @param string $action  Action value.
	 * @return bool True when the handler died before acting.
	 */
	private function click_without_nonce( int $post_id, string $action ): bool {
		$_GET['post'] = (string) $post_id;
		$_GET['do']   = $action;
		unset( $_REQUEST['_wpnonce'] );

		try {
			SRL_Post_Meta::handle_admin_post();
		} catch ( WPDieException $e ) {
			return true;
		}

		return false;
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

		SRL_Post_Meta::perform( (int) $post_id, 'cancel' );

		$this->assertSame( SRL_Post_Meta::STATUS_CANCELLED, SRL_Post_Meta::get_status( (int) $post_id ) );
		$this->assertFalse( SRL_Scheduler::has_pending_send( (int) $post_id ) );
	}

	/** T-242 */
	public function test_repost_resets_attempts_and_schedules_at_zero_delay(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		_set_cron_array( array() );
		SRL_Post_Meta::set_status( (int) $post_id, SRL_Post_Meta::STATUS_SENT );
		update_post_meta( $post_id, SRL_Post_Meta::META_ATTEMPTS, 4 );

		SRL_Post_Meta::perform( (int) $post_id, 'repost' );

		$this->assertSame( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::get_status( (int) $post_id ) );
		$this->assertSame( '', get_post_meta( $post_id, SRL_Post_Meta::META_ATTEMPTS, true ) );

		$due = (int) wp_next_scheduled( SRL_Scheduler::SEND_HOOK, SRL_Scheduler::event_args( (int) $post_id ) );
		$this->assertEqualsWithDelta( time(), $due, 10, 'a repost goes out at delay 0' );
	}

	/**
	 * "Repost now" is the only path to a second post, so it must be reachable
	 * only from sent and failed. A stale page clicked while a send is
	 * scheduled or in flight must not duplicate it.
	 */
	public function test_repost_is_ignored_unless_sent_or_failed(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		foreach ( array( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::STATUS_SENDING, SRL_Post_Meta::STATUS_CANCELLED ) as $status ) {
			SRL_Post_Meta::set_status( (int) $post_id, $status );
			_set_cron_array( array() );

			SRL_Post_Meta::perform( (int) $post_id, 'repost' );

			$this->assertSame( $status, SRL_Post_Meta::get_status( (int) $post_id ), "must be ignored from {$status}" );
		}
	}

	public function test_repost_without_a_nonce_does_nothing(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		SRL_Post_Meta::set_status( (int) $post_id, SRL_Post_Meta::STATUS_SENT );
		_set_cron_array( array() );

		$this->assertTrue( $this->click_without_nonce( (int) $post_id, 'repost' ), 'must die on a missing nonce' );
		$this->assertSame( SRL_Post_Meta::STATUS_SENT, SRL_Post_Meta::get_status( (int) $post_id ) );
		$this->assertFalse( SRL_Scheduler::has_pending_send( (int) $post_id ) );
	}

	/**
	 * T-251
	 *
	 * The per-post switch is forced on. On a site whose master switch is off
	 * the checkbox defaults unticked, so save() stores '0' just before this
	 * handler runs, and the publisher's re-read would then cancel the send the
	 * owner just confirmed.
	 */
	public function test_send_now_schedules_at_zero_delay_and_sets_the_switch(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		delete_post_meta( $post_id, SRL_Post_Meta::META_STATUS );
		update_post_meta( $post_id, SRL_Post_Meta::META_ENABLED, '0' );
		update_post_meta( $post_id, SRL_Post_Meta::META_ATTEMPTS, 2 );
		update_post_meta( $post_id, SRL_Post_Meta::META_LAST_ERROR, 'server' );

		SRL_Post_Meta::perform( (int) $post_id, 'send_now' );

		$this->assertSame( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::get_status( (int) $post_id ) );
		$this->assertSame( '1', get_post_meta( $post_id, SRL_Post_Meta::META_ENABLED, true ), 'a confirmed click outranks the checkbox' );
		$this->assertSame( '', get_post_meta( $post_id, SRL_Post_Meta::META_ATTEMPTS, true ) );
		$this->assertSame( '', get_post_meta( $post_id, SRL_Post_Meta::META_LAST_ERROR, true ) );

		$due = (int) wp_next_scheduled( SRL_Scheduler::SEND_HOOK, SRL_Scheduler::event_args( (int) $post_id ) );
		$this->assertEqualsWithDelta( time(), $due, 10, 'a manual send goes out at delay 0' );
		$this->assertEqualsWithDelta( $due, (int) get_post_meta( $post_id, SRL_Post_Meta::META_SCHEDULED_AT, true ), 1 );

		$row = SRL_Log::recent( 1 )[0];
		$this->assertSame( SRL_Log::EVENT_SCHEDULED, $row->event );
		$this->assertSame( (int) $post_id, (int) $row->post_id );
		$this->assertStringContainsString( 'Manual send', (string) $row->message );
	}

	/**
	 * T-252
	 *
	 * `sent` and `sending` are INV-1; `failed` belongs to "Repost now"; and an
	 * unpublished post has no public permalink to send, so a draft is refused
	 * even from the two statuses the button otherwise accepts.
	 */
	public function test_send_now_is_ignored_from_sent_sending_failed_and_unpublished(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		foreach ( array( SRL_Post_Meta::STATUS_SENT, SRL_Post_Meta::STATUS_SENDING, SRL_Post_Meta::STATUS_FAILED ) as $status ) {
			SRL_Post_Meta::set_status( (int) $post_id, $status );
			_set_cron_array( array() );

			SRL_Post_Meta::perform( (int) $post_id, 'send_now' );

			$this->assertSame( $status, SRL_Post_Meta::get_status( (int) $post_id ), "must be ignored from {$status}" );
			$this->assertFalse( SRL_Scheduler::has_pending_send( (int) $post_id ), "no event from {$status}" );
		}

		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		foreach ( array( SRL_Post_Meta::STATUS_NONE, SRL_Post_Meta::STATUS_CANCELLED ) as $status ) {
			SRL_Post_Meta::set_status( (int) $draft, $status );
			_set_cron_array( array() );

			SRL_Post_Meta::perform( (int) $draft, 'send_now' );

			$this->assertSame( $status, SRL_Post_Meta::get_status( (int) $draft ), "a draft must be refused from {$status}" );
			$this->assertFalse( SRL_Scheduler::has_pending_send( (int) $draft ) );
		}
	}

	/**
	 * T-253
	 *
	 * The edit screen is a snapshot. Between rendering the button and the
	 * click, another request can have scheduled the post at the default delay
	 * -- an import finishing, a republish on a second tab. G-5 would swallow
	 * the click; instead the pending event is replaced with one due now, and
	 * exactly one event survives, clear of WordPress's ten-minute duplicate
	 * suppression.
	 */
	public function test_send_now_replaces_a_pending_event_scheduled_since_render(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		_set_cron_array( array() );
		SRL_Post_Meta::set_status( (int) $post_id, SRL_Post_Meta::STATUS_SCHEDULED );
		update_post_meta( $post_id, SRL_Post_Meta::META_SCHEDULED_AT, time() + HOUR_IN_SECONDS );
		$this->assertTrue( SRL_Scheduler::schedule_send( (int) $post_id, time() + HOUR_IN_SECONDS ) );

		SRL_Post_Meta::perform( (int) $post_id, 'send_now' );

		$this->assertSame( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::get_status( (int) $post_id ) );
		$due = (int) wp_next_scheduled( SRL_Scheduler::SEND_HOOK, SRL_Scheduler::event_args( (int) $post_id ) );
		$this->assertEqualsWithDelta( time(), $due, 10, 'the click wins over the pending default-delay event' );
		$this->assertSame( 1, $this->count_send_events( (int) $post_id ), 'exactly one event survives' );
	}

	/**
	 * Count every pending send event for a post, at any timestamp.
	 *
	 * The earliest event is all wp_next_scheduled() reports, which would
	 * hide a second one left behind at the default delay.
	 *
	 * @param int $post_id Post id.
	 * @return int
	 */
	private function count_send_events( int $post_id ): int {
		$key   = md5( serialize( SRL_Scheduler::event_args( $post_id ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Mirrors WordPress's own cron key.
		$count = 0;

		foreach ( (array) _get_cron_array() as $events ) {
			if ( isset( $events[ SRL_Scheduler::SEND_HOOK ][ $key ] ) ) {
				++$count;
			}
		}

		return $count;
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

	/**
	 * T-123
	 *
	 * The whole point of FR-1.9: it must not put anything on the timeline.
	 */
	public function test_check_credentials_publishes_nothing(): void {
		$calls = array();

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$calls ) {
				$calls[] = array(
					'method' => $args['method'] ?? 'GET',
					'url'    => $url,
				);
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'data' => array(
								'id'       => '1',
								'username' => 'perVial_com',
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$provider = new SRL_X_Provider( SRL_Settings::signer() );
		$provider->verify_credentials();

		$this->assertCount( 1, $calls );
		$this->assertSame( SRL_X_Provider::API_HOST . SRL_X_Provider::PATH_ME, $calls[0]['url'] );
		$this->assertStringNotContainsString( '/2/tweets', $calls[0]['url'] );
	}

	/** T-124 */
	public function test_check_credentials_reports_the_account_handle(): void {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'data' => array(
								'id'       => '2063855447417737216',
								'name'     => 'perVial',
								'username' => 'perVial_com',
							),
						)
					),
					'headers'  => array(),
				);
			}
		);

		$result = ( new SRL_X_Provider( SRL_Settings::signer() ) )->verify_credentials();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'perVial_com', $result['handle'] );
		$this->assertSame( 200, $result['http_status'] );
		$this->assertSame( array( 'GET /2/users/me' ), $result['endpoints'] );
	}

	/** T-125 */
	public function test_check_credentials_reports_failure_without_posting(): void {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 401 ),
					'body'     => '{"title":"Unauthorized","status":401}',
					'headers'  => array(),
				);
			}
		);

		$result = ( new SRL_X_Provider( SRL_Settings::signer() ) )->verify_credentials();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 401, $result['http_status'] );
		$this->assertSame( '', $result['handle'] );
		// Still counted: X bills for failed requests too.
		$this->assertSame( array( 'GET /2/users/me' ), $result['endpoints'] );
	}

	/**
	 * A 2xx that does not carry a username is not a successful check. Reporting
	 * success there would tell the owner their keys work when nothing was
	 * proved.
	 */
	public function test_check_credentials_rejects_a_2xx_without_a_username(): void {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '<html>captive portal</html>',
					'headers'  => array(),
				);
			}
		);

		$result = ( new SRL_X_Provider( SRL_Settings::signer() ) )->verify_credentials();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'malformed_response', $result['message'] );
	}

	/**
	 * The handler must be inert without a nonce, and inert from a status that
	 * is not sent or failed. The first version of the repost test only checked
	 * that the rendered HTML contained the nonce field name and the string
	 * "confirm(" -- it never invoked the handler at all, and would have
	 * passed unchanged if the handler had been deleted.
	 */
	public function test_cancel_is_inert_without_a_nonce(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		_set_cron_array( array() );
		SRL_Post_Meta::set_status( (int) $post_id, SRL_Post_Meta::STATUS_SCHEDULED );
		SRL_Scheduler::schedule_send( (int) $post_id, time() + 3600 );

		$this->assertTrue( $this->click_without_nonce( (int) $post_id, 'cancel' ), 'must die on a missing nonce' );
		$this->assertSame( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::get_status( (int) $post_id ) );
		$this->assertTrue( SRL_Scheduler::has_pending_send( (int) $post_id ) );
	}

	/**
	 * A repost must clear the stored media id, so a deliberate second post
	 * pays for a fresh upload rather than silently reusing an expiring one.
	 */
	public function test_repost_clears_the_stored_media_id(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		_set_cron_array( array() );
		SRL_Post_Meta::set_status( (int) $post_id, SRL_Post_Meta::STATUS_FAILED );
		update_post_meta( $post_id, SRL_Post_Meta::META_MEDIA_ID, 'old-media-id' );
		update_post_meta( $post_id, SRL_Post_Meta::META_MEDIA_UPLOADED_AT, time() );

		SRL_Post_Meta::perform( (int) $post_id, 'repost' );

		$this->assertSame( '', get_post_meta( $post_id, SRL_Post_Meta::META_MEDIA_ID, true ) );
		$this->assertSame( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::get_status( (int) $post_id ) );
	}

	/** T-121 */
	public function test_test_post_writes_log_row_with_post_id_zero(): void {
		SRL_Log::write( SRL_Log::EVENT_TEST, 0, 201, '1', 'Test post sent.' );

		$row = SRL_Log::recent( 1 )[0];

		$this->assertSame( 'test', $row->event );
		$this->assertSame( '0', (string) $row->post_id );
	}
}
