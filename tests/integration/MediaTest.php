<?php
/**
 * Integration tests for the featured-image path.
 *
 * Covers FR-4.4 and SPEC.md section 8.2.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

/**
 * The image never blocks the post, and the response is parsed defensively.
 */
class MediaTest extends WP_UnitTestCase {

	/**
	 * Queued canned responses.
	 *
	 * @var array<int, mixed>
	 */
	private array $responses = array();

	/**
	 * Intercepted requests.
	 *
	 * @var array<int, array{url:string, args:array<string, mixed>}>
	 */
	private array $requests = array();

	public function set_up(): void {
		parent::set_up();

		$this->responses = array();
		$this->requests  = array();

		$settings            = SRL_Settings::defaults();
		$settings['enabled'] = true;
		foreach ( SRL_Settings::CREDENTIAL_KEYS as $key ) {
			$settings[ $key ] = SRL_Settings::crypto()->encrypt( 'value-for-' . $key );
		}
		update_option( SRL_Settings::OPTION, $settings );

		_set_cron_array( array() );
		SRL_Log::install_table();

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
			return array(
				'response' => array( 'code' => 201 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => '1' ) ) ),
				'headers'  => array(),
			);
		}

		return array_shift( $this->responses );
	}

	/**
	 * A real PNG on disk, attached to a post as its featured image.
	 *
	 * @param int    $post_id Post id.
	 * @param string $mime    MIME type to record.
	 * @return string The file path.
	 */
	private function attach_image( int $post_id, string $mime = 'image/png' ): string {
		$uploads = wp_upload_dir();
		$file    = $uploads['path'] . '/srl-' . wp_generate_password( 6, false ) . '.png';

		file_put_contents(
			$file,
			base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' )
		);

		$attachment = self::factory()->attachment->create_object(
			array(
				'file'           => $file,
				'post_mime_type' => $mime,
			),
			$post_id
		);
		update_post_meta( $attachment, '_wp_attached_file', $file );
		set_post_thumbnail( $post_id, $attachment );

		return $file;
	}

	/**
	 * A media response in the one-shot shape.
	 *
	 * @param string $id Media id.
	 * @return array<string, mixed>
	 */
	private function ok_media( string $id = '2096370095605825536' ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'data' => array(
						'id'                 => $id,
						'expires_after_secs' => 86400,
						// The one-shot path returns h/w; chunked finalize
						// returns height/width. Neither may be relied on.
						'image'              => array(
							'h' => 1,
							'w' => 1,
						),
						'size'               => 100,
					),
				)
			),
			'headers'  => array(),
		);
	}

	/** T-430 */
	public function test_media_ids_included_when_image_present(): void {
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$file    = $this->attach_image( $post_id );
		SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SCHEDULED );

		$this->responses = array( $this->ok_media( '555' ) );

		SRL_Publisher::run( $post_id );

		$create = json_decode( (string) $this->requests[1]['args']['body'], true );

		$this->assertSame( array( '555' ), $create['media']['media_ids'] );

		unlink( $file );
	}

	/**
	 * T-415
	 *
	 * Note that data.id is the only field stable across both upload paths.
	 */
	public function test_media_id_read_from_data_id_only(): void {
		$provider = new SRL_X_Provider( SRL_Settings::signer() );
		$uploads  = wp_upload_dir();
		$file     = $uploads['path'] . '/srl-idtest.png';
		file_put_contents( $file, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );

		$this->responses = array( $this->ok_media( '777' ) );
		$result          = $provider->upload_media( $file, 'image/png' );

		$this->assertSame( '777', $result['media_id'] );

		// A response missing data.id must not be salvaged from other fields.
		$this->responses = array(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'media_key' => '3_888' ) ) ),
				'headers'  => array(),
			),
		);
		$missing         = $provider->upload_media( $file, 'image/png' );

		$this->assertNull( $missing['media_id'] );
		$this->assertSame( 'malformed_media_response', $missing['reason'] );

		unlink( $file );
	}

	/** T-419 */
	public function test_unsupported_mime_is_skipped_with_reason(): void {
		$provider = new SRL_X_Provider( SRL_Settings::signer() );

		$result = $provider->upload_media( '/tmp/whatever.svg', 'image/svg+xml' );

		$this->assertNull( $result['media_id'] );
		$this->assertStringContainsString( 'unsupported_type', $result['reason'] );
		$this->assertEmpty( $this->requests, 'an unsupported type must not reach the network' );
	}

	/** T-414 */
	public function test_missing_image_file_is_skipped_cleanly(): void {
		$provider = new SRL_X_Provider( SRL_Settings::signer() );

		$result = $provider->upload_media( '/tmp/does-not-exist-' . wp_generate_password( 8, false ) . '.png', 'image/png' );

		$this->assertNull( $result['media_id'] );
		$this->assertSame( 'unreadable_file', $result['reason'] );
	}

	/**
	 * T-410
	 *
	 * The file is read from disk, never fetched over HTTP: the site may be
	 * behind basic auth, a staging password, or a CDN.
	 */
	public function test_featured_image_is_read_from_filesystem_not_http(): void {
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$file    = $this->attach_image( $post_id );
		SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SCHEDULED );

		$this->responses = array( $this->ok_media() );
		SRL_Publisher::run( $post_id );

		foreach ( $this->requests as $request ) {
			$this->assertSame(
				'api.x.com',
				wp_parse_url( $request['url'], PHP_URL_HOST ),
				'the only outbound requests are to the API; the image is never self-fetched'
			);
		}

		unlink( $file );
	}

	/** T-411, T-412 */
	public function test_media_failure_still_publishes_text_post(): void {
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$file    = $this->attach_image( $post_id );
		SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SCHEDULED );

		$this->responses = array(
			array(
				'response' => array( 'code' => 500 ),
				'body'     => 'upstream exploded',
				'headers'  => array(),
			),
		);

		SRL_Publisher::run( $post_id );

		$this->assertSame( SRL_Post_Meta::STATUS_SENT, SRL_Post_Meta::get_status( $post_id ) );
		$this->assertSame( '1', get_post_meta( $post_id, SRL_Post_Meta::META_IMAGE_OMITTED, true ) );
		$this->assertStringContainsString(
			'http_500',
			(string) get_post_meta( $post_id, SRL_Post_Meta::META_IMAGE_REASON, true )
		);

		$create = json_decode( (string) $this->requests[1]['args']['body'], true );
		$this->assertArrayNotHasKey( 'media', $create );

		unlink( $file );
	}

	/**
	 * T-418
	 *
	 * Media is valid for 86400 seconds, comfortably longer than the longest
	 * backoff, so a retry must not pay for the same image again.
	 */
	public function test_retry_reuses_media_id_within_expiry(): void {
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$file    = $this->attach_image( $post_id );

		update_post_meta( $post_id, SRL_Post_Meta::META_MEDIA_ID, 'already-uploaded-999' );
		update_post_meta( $post_id, SRL_Post_Meta::META_MEDIA_UPLOADED_AT, time() - 60 );
		SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SCHEDULED );

		SRL_Publisher::run( $post_id );

		$this->assertCount( 1, $this->requests, 'no second upload; only the create call' );

		$create = json_decode( (string) $this->requests[0]['args']['body'], true );
		$this->assertSame( array( 'already-uploaded-999' ), $create['media']['media_ids'] );

		unlink( $file );
	}

	/**
	 * An expired media id must be re-uploaded rather than attached and
	 * rejected.
	 */
	public function test_expired_media_id_is_not_reused(): void {
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$file    = $this->attach_image( $post_id );

		update_post_meta( $post_id, SRL_Post_Meta::META_MEDIA_ID, 'stale-id' );
		update_post_meta( $post_id, SRL_Post_Meta::META_MEDIA_UPLOADED_AT, time() - ( SRL_Publisher::MEDIA_TTL + 60 ) );
		SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SCHEDULED );

		$this->responses = array( $this->ok_media( 'fresh-id' ) );
		SRL_Publisher::run( $post_id );

		$this->assertCount( 2, $this->requests, 'a fresh upload plus the create call' );

		$create = json_decode( (string) $this->requests[1]['args']['body'], true );
		$this->assertSame( array( 'fresh-id' ), $create['media']['media_ids'] );

		unlink( $file );
	}
}
