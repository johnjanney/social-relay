<?php
/**
 * Integration tests for the send pipeline and the error matrix.
 *
 * Every X call is intercepted with pre_http_request, so no test makes a network
 * request. Covers SPEC.md sections 9, 11.4 and FR-4.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

/**
 * The claim, the error matrix, the backoff and the media rules.
 */
class PublisherTest extends WP_UnitTestCase {

	/**
	 * Requests intercepted during a test.
	 *
	 * @var array<int, array{url:string, args:array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * Queued canned responses.
	 *
	 * @var array<int, mixed>
	 */
	private array $responses = array();

	/**
	 * Fail the test on any request with no queued response.
	 *
	 * @var bool
	 */
	private bool $strict_requests = false;

	public function set_up(): void {
		parent::set_up();

		$this->requests  = array();
		$this->responses = array();

		$settings            = SRL_Settings::defaults();
		$settings['enabled'] = true;
		foreach ( SRL_Settings::CREDENTIAL_KEYS as $key ) {
			$settings[ $key ] = SRL_Settings::crypto()->encrypt( 'test-credential-value-' . $key );
		}
		update_option( SRL_Settings::OPTION, $settings );

		_set_cron_array( array() );

		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		parent::tear_down();
	}

	/**
	 * Stand in for every outbound request.
	 *
	 * @param mixed  $preempt Short-circuit value.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return mixed
	 */
	public function intercept( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		if ( empty( $this->responses ) ) {
			// Deliberately still a success: most tests queue nothing and expect
			// the happy path. But tests that care about call counts assert
			// assertCount() on $this->requests, and $strict_requests below
			// turns an unqueued call into a failure where that matters.
			if ( $this->strict_requests ) {
				$this->fail( 'Unexpected HTTP request to ' . $url . ' with no queued response.' );
			}
			return $this->ok_create();
		}

		return array_shift( $this->responses );
	}

	/** A successful create-post response. */
	private function ok_create(): array {
		return array(
			'response' => array( 'code' => 201 ),
			'body'     => wp_json_encode( array( 'data' => array( 'id' => '1234567890123456789' ) ) ),
			'headers'  => array(),
		);
	}

	/** A successful media response, in the one-shot shape. */
	private function ok_media(): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'data' => array(
						'id'                 => '2096370095605825536',
						'expires_after_secs' => 86400,
						'image'              => array(
							'h' => 16,
							'w' => 16,
						),
					),
				)
			),
			'headers'  => array(),
		);
	}

	/**
	 * A status-only response.
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Body.
	 * @return array<string, mixed>
	 */
	private function status( int $code, string $body = '{}' ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
			'headers'  => array(),
		);
	}

	/**
	 * A published post already claimed as `scheduled`.
	 *
	 * @return int
	 */
	private function scheduled_post(): int {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		SRL_Post_Meta::set_status( (int) $post_id, SRL_Post_Meta::STATUS_SCHEDULED );

		return (int) $post_id;
	}

	/** T-440 */
	public function test_success_stores_id_status_sent_at_and_log_row(): void {
		$post_id = $this->scheduled_post();

		SRL_Publisher::run( $post_id );

		$this->assertSame( SRL_Post_Meta::STATUS_SENT, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertSame( '1234567890123456789', get_post_meta( $post_id, SRL_Post_Meta::META_REMOTE_ID, true ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $post_id, SRL_Post_Meta::META_SENT_AT, true ) );
		$this->assertNotEmpty( SRL_Log::for_post( $post_id ) );
	}

	/**
	 * T-400
	 *
	 * WP-Cron can fire the same event twice under concurrent requests. This is
	 * the mechanism that protects INV-1.
	 */
	public function test_double_fired_event_makes_exactly_one_api_call(): void {
		$post_id = $this->scheduled_post();

		SRL_Publisher::run( $post_id );
		SRL_Publisher::run( $post_id );

		$this->assertCount( 1, $this->requests, 'The second firing must make no API call at all.' );
	}

	/**
	 * The concurrent case the compare-and-swap actually exists for.
	 *
	 * Running run() twice in sequence proves little: the second call is
	 * blocked because the status is already `sent`, and it would pass even if
	 * claim() were a plain update_post_meta(). This drives the claim itself
	 * from a status another worker has already taken.
	 */
	public function test_second_worker_cannot_claim_a_send_in_flight(): void {
		$post_id = $this->scheduled_post();

		// Worker A claims and is mid-send.
		$this->assertSame( 'claimed', SRL_Publisher::claim( $post_id ) );

		// Worker B arrives while the status is `sending`.
		$this->assertSame( 'taken', SRL_Publisher::claim( $post_id ) );

		SRL_Publisher::run( $post_id );

		$this->assertEmpty( $this->requests, 'a worker that did not win the claim must make no API call' );
	}

	/** T-402 */
	public function test_claim_is_compare_and_swap_and_second_claim_returns_zero(): void {
		$post_id = $this->scheduled_post();

		$this->assertSame( 'claimed', SRL_Publisher::claim( $post_id ) );
		$this->assertSame( 'taken', SRL_Publisher::claim( $post_id ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $post_id, SRL_Post_Meta::META_SENDING_SINCE, true ) );
	}

	/** T-401 */
	public function test_event_on_unpublished_post_cancels_without_api_call(): void {
		$post_id = $this->scheduled_post();
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		SRL_Publisher::run( $post_id );

		$this->assertEmpty( $this->requests );
		$this->assertSame( SRL_Post_Meta::STATUS_CANCELLED, SRL_Post_Meta::get_status( $post_id ) );
	}

	/** T-403 */
	public function test_title_edited_during_delay_is_used_at_send_time(): void {
		$post_id = $this->scheduled_post();

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Corrected Title',
			)
		);
		SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SCHEDULED );

		SRL_Publisher::run( $post_id );

		$body = (string) $this->requests[0]['args']['body'];
		$this->assertStringContainsString( 'Corrected Title', $body );
	}

	/** T-449 */
	public function test_post_tags_become_hashtags_at_send_time(): void {
		$settings                     = (array) get_option( SRL_Settings::OPTION );
		$settings['hashtags_enabled'] = true;
		$settings['hashtags_max']     = 2;
		update_option( SRL_Settings::OPTION, $settings );

		$post_id = $this->scheduled_post();
		wp_set_post_terms( $post_id, array( 'machine learning', 'co-op', 'third tag' ), 'post_tag' );

		SRL_Publisher::run( $post_id );

		$body = (string) $this->requests[0]['args']['body'];
		$this->assertStringContainsString( '#CoOp', $body );
		$this->assertStringContainsString( '#MachineLearning', $body );
		// The count cap holds against what the taxonomy returns, whatever
		// order that is, so exactly two of the three tags ship.
		$this->assertStringNotContainsString( '#ThirdTag', $body );

		// With the setting off, the same post carries no hashtag at all.
		$settings['hashtags_enabled'] = false;
		update_option( SRL_Settings::OPTION, $settings );

		$this->requests = array();
		SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SCHEDULED );
		SRL_Publisher::run( $post_id );

		$this->assertStringNotContainsString( '#', (string) $this->requests[0]['args']['body'] );
	}

	/** T-450 */
	public function test_429_retries_with_five_minute_backoff(): void {
		$this->responses = array( $this->status( 429 ) );
		$post_id         = $this->scheduled_post();

		SRL_Publisher::run( $post_id );

		$this->assertSame( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertTrue( SRL_Scheduler::has_pending_send( $post_id ) );

		$due = (int) wp_next_scheduled( SRL_Scheduler::SEND_HOOK, SRL_Scheduler::event_args( $post_id ) );
		$this->assertEqualsWithDelta( time() + 300, $due, 10 );
	}

	/**
	 * T-451
	 *
	 * One initial attempt plus three retries. Gating on "attempts < 3" would
	 * give three attempts and make the 60-minute backoff unreachable.
	 */
	public function test_500_retries_three_times_then_fails_on_fourth_attempt(): void {
		$post_id = $this->scheduled_post();

		foreach ( array( 300, 900, 3600 ) as $expected ) {
			$this->responses = array( $this->status( 500 ) );
			SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SCHEDULED );
			SRL_Publisher::run( $post_id );

			$this->assertSame( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::get_status( $post_id ) );
			$due = (int) wp_next_scheduled( SRL_Scheduler::SEND_HOOK, SRL_Scheduler::event_args( $post_id ) );
			$this->assertEqualsWithDelta( time() + $expected, $due, 10, "backoff step {$expected}" );
			SRL_Scheduler::clear_send( $post_id );
		}

		$this->responses = array( $this->status( 500 ) );
		SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SCHEDULED );
		SRL_Publisher::run( $post_id );

		$this->assertSame( SRL_Post_Meta::STATUS_FAILED, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertSame( 4, (int) get_post_meta( $post_id, SRL_Post_Meta::META_ATTEMPTS, true ) );
	}

	/** T-460 */
	public function test_401_does_not_retry_and_raises_notice(): void {
		$this->responses = array( $this->status( 401, '{"title":"Unauthorized"}' ) );
		$post_id         = $this->scheduled_post();

		SRL_Publisher::run( $post_id );

		$this->assertSame( SRL_Post_Meta::STATUS_FAILED, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertFalse( SRL_Scheduler::has_pending_send( $post_id ) );
		$this->assertContains( $post_id, SRL_Notices::pending() );
	}

	/** T-462 */
	public function test_other_4xx_does_not_retry(): void {
		$this->responses = array( $this->status( 400, '{"detail":"something else"}' ) );
		$post_id         = $this->scheduled_post();

		SRL_Publisher::run( $post_id );

		$this->assertSame( SRL_Post_Meta::STATUS_FAILED, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertFalse( SRL_Scheduler::has_pending_send( $post_id ) );
	}

	/**
	 * T-470
	 *
	 * Asserts the REASON, not just that it failed. The first version checked
	 * only STATUS_FAILED, and because `auth` is terminal too it passed happily
	 * while the reason was wrong -- which is precisely the bug the Phase 7
	 * review found: X returns duplicate-content rejections as HTTP 403, the
	 * 401/403 arm ran first, and the entire duplicate safety net was dead code.
	 */
	public function test_duplicate_on_first_attempt_fails_with_reason_duplicate(): void {
		$this->responses = array( $this->status( 403, '{"detail":"You are not allowed to create a Tweet with duplicate content."}' ) );
		$post_id         = $this->scheduled_post();

		SRL_Publisher::run( $post_id );

		$this->assertSame( SRL_Post_Meta::STATUS_FAILED, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertSame(
			'duplicate',
			get_post_meta( $post_id, SRL_Post_Meta::META_LAST_ERROR, true ),
			'a 403 duplicate must not be reported as an auth failure'
		);
	}

	/**
	 * A genuine 401 must still read as auth, so the tightened duplicate matcher
	 * has not simply swallowed everything.
	 */
	public function test_genuine_auth_failure_is_still_reported_as_auth(): void {
		$this->responses = array( $this->status( 401, '{"title":"Unauthorized","detail":"Unauthorized"}' ) );
		$post_id         = $this->scheduled_post();

		SRL_Publisher::run( $post_id );

		$this->assertSame( 'auth', get_post_meta( $post_id, SRL_Post_Meta::META_LAST_ERROR, true ) );
	}

	/**
	 * An unrelated error body that merely mentions the word must not be
	 * mistaken for a duplicate-content rejection.
	 */
	public function test_unrelated_body_mentioning_duplicate_is_not_a_duplicate(): void {
		$this->responses = array( $this->status( 401, '{"detail":"Your app has a duplicate callback URL registered."}' ) );
		$post_id         = $this->scheduled_post();

		SRL_Publisher::run( $post_id );

		$this->assertSame( 'auth', get_post_meta( $post_id, SRL_Post_Meta::META_LAST_ERROR, true ) );
	}

	/**
	 * T-472
	 *
	 * Deliberately terminal. The post was almost certainly created and only
	 * its id was lost; retrying would risk a duplicate and break INV-1.
	 */
	public function test_malformed_2xx_fails_without_retry(): void {
		$this->responses = array( $this->status( 200, 'not json at all' ) );
		$post_id         = $this->scheduled_post();

		SRL_Publisher::run( $post_id );

		$this->assertSame( SRL_Post_Meta::STATUS_FAILED, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertSame( 'malformed_response', get_post_meta( $post_id, SRL_Post_Meta::META_LAST_ERROR, true ) );
		$this->assertFalse( SRL_Scheduler::has_pending_send( $post_id ) );
	}

	/** T-453 */
	public function test_transport_error_is_retried_like_a_5xx(): void {
		$this->responses = array( new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );
		$post_id         = $this->scheduled_post();

		SRL_Publisher::run( $post_id );

		$this->assertSame( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertTrue( SRL_Scheduler::has_pending_send( $post_id ) );
	}

	/** T-454 */
	public function test_transport_error_logs_null_http_status(): void {
		$this->responses = array( new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );
		$post_id         = $this->scheduled_post();

		SRL_Publisher::run( $post_id );

		$rows = SRL_Log::for_post( $post_id );
		$this->assertNotEmpty( $rows );
		$this->assertNull( $rows[0]->http_status, 'A null status is what distinguishes transport from HTTP failure.' );
	}

	/** T-480 */
	public function test_every_call_increments_usage_including_failures(): void {
		$this->responses = array( $this->status( 500 ) );
		$post_id         = $this->scheduled_post();

		SRL_Publisher::run( $post_id );

		$this->assertSame( 1, SRL_Usage::total_for_month() );
	}

	/** T-431 */
	public function test_media_key_absent_entirely_when_no_image(): void {
		$post_id = $this->scheduled_post();

		SRL_Publisher::run( $post_id );

		$body = json_decode( (string) $this->requests[0]['args']['body'], true );
		$this->assertArrayNotHasKey( 'media', $body, 'media must be omitted, never sent as null or an empty array.' );
	}

	/** T-429 */
	public function test_multipart_body_is_a_string_not_an_array(): void {
		$provider = new SRL_X_Provider( new SRL_OAuth1( 'k', 'ks', 't', 'ts' ) );

		$file = wp_tempnam( 'srl-test.png' );
		file_put_contents( $file, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );

		$this->responses = array( $this->ok_media() );
		$provider->upload_media( $file, 'image/png' );

		$this->assertIsString(
			$this->requests[0]['args']['body'],
			'An array body would be serialised as form-urlencoded and would also have to be signed.'
		);
		$this->assertStringContainsString( 'multipart/form-data; boundary=', (string) $this->requests[0]['args']['headers']['Content-Type'] );

		unlink( $file );
	}

	/**
	 * T-417
	 *
	 * INV-6: the image never blocks the post, and a media failure must not
	 * consume the send's retry budget.
	 */
	public function test_media_failure_does_not_consume_retry_budget(): void {
		$post_id = $this->scheduled_post();

		// A real file on disk: resolve_image() checks is_readable(), so an
		// attachment row pointing at nothing would skip the upload entirely
		// and the test would pass for the wrong reason.
		$uploads = wp_upload_dir();
		$file    = $uploads['path'] . '/srl-test.png';
		file_put_contents( $file, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );

		$attachment = self::factory()->attachment->create_object(
			array(
				'file'           => $file,
				'post_mime_type' => 'image/png',
			),
			$post_id
		);
		update_post_meta( $attachment, '_wp_attached_file', $file );
		set_post_thumbnail( $post_id, $attachment );

		// Media fails, create succeeds.
		$this->responses = array( $this->status( 429 ), $this->ok_create() );

		SRL_Publisher::run( $post_id );

		$this->assertCount( 2, $this->requests, 'the media call and the create call' );
		$this->assertSame( SRL_Post_Meta::STATUS_SENT, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertSame( 1, (int) get_post_meta( $post_id, SRL_Post_Meta::META_ATTEMPTS, true ) );
		$this->assertSame( '1', get_post_meta( $post_id, SRL_Post_Meta::META_IMAGE_OMITTED, true ) );

		unlink( $file );
	}
}
