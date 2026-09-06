<?php
/**
 * Regression tests for the Phase 7 code review findings.
 *
 * Each of these reproduces a defect the review found in shipped code. They are
 * grouped here so the mapping from finding to proof stays visible.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

/**
 * One test per finding that was not already covered elsewhere.
 */
class RegressionTest extends WP_UnitTestCase {

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
	 * Finding 1 (blocker). truncate() measured literally while compose()
	 * budgeted with URL weights, so an ordinary headline naming four products
	 * by domain composed to 320 against a limit of 280 — a terminal HTTP 400.
	 */
	public function test_title_full_of_domains_does_not_overflow(): void {
		$title = 'Comparing WordPress.com and Squarespace.com and Shopify.com and Wix.com '
			. 'hosting plans for small business owners in 2026: pricing, performance, '
			. 'support quality, migration paths, and hidden costs nobody mentions until '
			. 'after you have signed the annual contract';

		$out = SRL_Text::compose( $title, 'https://example.com/p/' );

		$this->assertLessThanOrEqual( SRL_Text::MAX_WEIGHTED, SRL_Text::weighted_length( $out ) );
	}

	/**
	 * Finding 1, second half. compose() hard-coded 23 for the permalink, but X
	 * charges literally for a URL whose host it refuses to shorten.
	 */
	public function test_permalink_with_an_unshortenable_host_does_not_overflow(): void {
		$permalink = 'https://' . str_repeat( 'x', 200 ) . '.example.com/post/';

		$out = SRL_Text::compose( str_repeat( 'word ', 100 ), $permalink );

		$this->assertLessThanOrEqual( SRL_Text::MAX_WEIGHTED, SRL_Text::weighted_length( $out ) );
	}

	/**
	 * Finding 4. schedule_failed, stalled and event_lost set the status and
	 * wrote a log row but never raised the notice — so the three failures that
	 * happen while nobody is watching were the silent ones.
	 */
	public function test_every_failure_path_raises_the_notice(): void {
		foreach ( array( 'stalled', 'event_lost', 'schedule_failed' ) as $reason ) {
			delete_option( SRL_Notices::OPTION );
			$post_id = (int) self::factory()->post->create();

			SRL_Post_Meta::fail( $post_id, $reason );

			$this->assertContains( $post_id, SRL_Notices::pending(), "reason: {$reason}" );
		}
	}

	/**
	 * Finding 5. The notice was rendered dismissible but nothing could dismiss
	 * it: no endpoint, no script, and DISMISS_ACTION referenced nowhere.
	 */
	public function test_failure_notice_carries_a_working_dismiss_link(): void {
		$post_id = (int) self::factory()->post->create();
		SRL_Post_Meta::fail( $post_id, 'server' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		SRL_Notices::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'srl_dismiss_notice', $html );
		$this->assertStringContainsString( '_wpnonce', $html );
		$this->assertStringContainsString( 'Dismiss', $html );
	}

	/**
	 * Finding 6. The reconcile batch was filled date-DESC by healthy,
	 * not-yet-due posts, so genuinely stuck older posts were never examined.
	 */
	public function test_reconcile_reaches_a_stuck_post_behind_many_healthy_ones(): void {
		// The stuck one, oldest.
		$stuck = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		SRL_Post_Meta::set_status( $stuck, SRL_Post_Meta::STATUS_SCHEDULED );
		update_post_meta( $stuck, SRL_Post_Meta::META_SCHEDULED_AT, time() - ( 3 * HOUR_IN_SECONDS ) );

		// Thirty healthy, newer, not-yet-due posts in front of it.
		for ( $i = 0; $i < 30; $i++ ) {
			$healthy = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
			SRL_Post_Meta::set_status( $healthy, SRL_Post_Meta::STATUS_SCHEDULED );
			update_post_meta( $healthy, SRL_Post_Meta::META_SCHEDULED_AT, time() + HOUR_IN_SECONDS );
		}

		_set_cron_array( array() );
		SRL_Scheduler::reconcile();

		$this->assertSame(
			SRL_Post_Meta::STATUS_FAILED,
			SRL_Post_Meta::get_status( $stuck ),
			'the oldest unresolved post must be reached, not starved behind healthy ones'
		);
	}

	/**
	 * Finding 6, second half. post_status => 'any' excludes trash, so a post
	 * trashed mid-send was invisible and stayed in `sending` forever.
	 */
	public function test_reconcile_sees_a_post_trashed_while_sending(): void {
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SENDING );
		update_post_meta( $post_id, SRL_Post_Meta::META_SENDING_SINCE, time() - 1800 );

		wp_trash_post( $post_id );

		SRL_Scheduler::reconcile();

		$this->assertSame( SRL_Post_Meta::STATUS_FAILED, SRL_Post_Meta::get_status( $post_id ) );
	}

	/**
	 * Finding 7. run() wrote `cancelled` unconditionally, and `cancelled` is
	 * schedulable — so a second worker could overwrite a terminal state and
	 * untrash-and-republish would then pay for a second post.
	 */
	public function test_run_does_not_overwrite_a_terminal_status(): void {
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );

		foreach ( array( SRL_Post_Meta::STATUS_SENT, SRL_Post_Meta::STATUS_SENDING, SRL_Post_Meta::STATUS_FAILED ) as $status ) {
			SRL_Post_Meta::set_status( $post_id, $status );
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				)
			);
			SRL_Post_Meta::set_status( $post_id, $status );

			SRL_Publisher::run( $post_id );

			$this->assertSame( $status, SRL_Post_Meta::get_status( $post_id ), "must not overwrite {$status}" );

			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			);
		}
	}

	/**
	 * Finding 8. `failed` is deliberately schedulable so unpublish-republish is
	 * a supported retry, but the attempt counter was never cleared — so the
	 * first transient 500 after a republish was terminal.
	 */
	public function test_republish_resets_the_retry_budget(): void {
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		SRL_Post_Meta::fail( $post_id, 'server' );
		update_post_meta( $post_id, SRL_Post_Meta::META_ATTEMPTS, 4 );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);
		_set_cron_array( array() );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertSame( '', get_post_meta( $post_id, SRL_Post_Meta::META_ATTEMPTS, true ) );
	}

	/**
	 * Finding 3. An oversized image was uploaded anyway: a real, billed,
	 * counted request that X rejects, recorded as an opaque http_400.
	 */
	public function test_oversized_image_is_refused_before_the_billed_call(): void {
		$called = false;
		add_filter(
			'pre_http_request',
			function () use ( &$called ) {
				$called = true;
				return new WP_Error( 'unexpected', 'should not be reached' );
			}
		);

		$uploads = wp_upload_dir();
		$file    = $uploads['path'] . '/srl-huge.png';
		file_put_contents( $file, str_repeat( 'x', SRL_X_Provider::MAX_IMAGE_BYTES + 1024 ) );

		$result = ( new SRL_X_Provider( SRL_Settings::signer() ) )->upload_media( $file, 'image/png' );

		$this->assertFalse( $called, 'an image X will reject must not be uploaded' );
		$this->assertNull( $result['media_id'] );
		$this->assertStringContainsString( 'image_too_large', $result['reason'] );

		unlink( $file );
	}

	/**
	 * Finding 13. The filename was interpolated straight into the
	 * Content-Disposition header, where a quote or newline would break out.
	 */
	public function test_multipart_filename_is_fixed_and_cannot_be_injected(): void {
		$body = null;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$body ) {
				$body = (string) $args['body'];
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'data' => array( 'id' => '1' ) ) ),
					'headers'  => array(),
				);
			},
			10,
			2
		);

		$uploads = wp_upload_dir();
		$nasty   = $uploads['path'] . '/evil".png';
		file_put_contents( $nasty, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );

		( new SRL_X_Provider( SRL_Settings::signer() ) )->upload_media( $nasty, 'image/png' );

		$this->assertStringContainsString( 'filename="image.png"', (string) $body );
		$this->assertStringNotContainsString( 'evil', (string) $body );

		unlink( $nasty );
	}

	/**
	 * Finding 14. sanitize() runs on every admin-side update_option, so a
	 * future migration that read all(), changed a key and wrote it back would
	 * double-wrap the envelopes.
	 */
	public function test_sanitize_does_not_double_encrypt_an_existing_envelope(): void {
		$first  = SRL_Settings::sanitize( array( 'api_key' => 'a-real-key-value-here' ) );
		$second = SRL_Settings::sanitize( $first );

		$this->assertSame(
			'a-real-key-value-here',
			SRL_Settings::crypto()->decrypt( $second['api_key'] ),
			'passing a settings array back through sanitize() must not re-wrap it'
		);
	}

	/**
	 * Finding 12. A 429 carrying x-rate-limit-reset was retried on the plugin's
	 * own schedule, burning retries inside a window that could not yet open.
	 */
	public function test_rate_limit_reset_header_extends_the_backoff(): void {
		$reset = time() + 1800;

		add_filter(
			'pre_http_request',
			function () use ( $reset ) {
				return array(
					'response' => array( 'code' => 429 ),
					'body'     => '{"title":"Too Many Requests"}',
					'headers'  => array( 'x-rate-limit-reset' => (string) $reset ),
				);
			}
		);

		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SCHEDULED );
		_set_cron_array( array() );

		SRL_Publisher::run( $post_id );

		$due = (int) wp_next_scheduled( SRL_Scheduler::SEND_HOOK, SRL_Scheduler::event_args( $post_id ) );

		$this->assertGreaterThan(
			time() + 1000,
			$due,
			'the retry must wait for the window, not fire at the 5-minute backoff inside it'
		);
	}

	/**
	 * Finding 9. In the block editor the meta box arrives in a later request,
	 * so a delay override typed by the author was ignored and the site default
	 * used instead.
	 */
	public function test_delay_override_saved_after_publishing_reschedules(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// Publish with no meta box in the request, as the REST API does.
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$default_due = (int) get_post_meta( $post_id, SRL_Post_Meta::META_SCHEDULED_AT, true );
		$this->assertGreaterThan( 0, $default_due );

		// The meta box request arrives afterwards with an override of 5 minutes.
		$_POST[ SRL_Post_Meta::NONCE_FIELD ]   = wp_create_nonce( SRL_Post_Meta::NONCE_ACTION );
		$_POST[ SRL_Post_Meta::FIELD_ENABLED ] = '1';
		$_POST[ SRL_Post_Meta::FIELD_DELAY ]   = '5';

		SRL_Post_Meta::save( $post_id );

		$new_due = (int) get_post_meta( $post_id, SRL_Post_Meta::META_SCHEDULED_AT, true );

		$this->assertLessThan(
			$default_due,
			$new_due,
			'the override typed in the editor must be honoured, not silently replaced by the site default'
		);

		unset(
			$_POST[ SRL_Post_Meta::NONCE_FIELD ],
			$_POST[ SRL_Post_Meta::FIELD_ENABLED ],
			$_POST[ SRL_Post_Meta::FIELD_DELAY ]
		);
	}
}
