<?php
/**
 * The send pipeline.
 *
 * Implements SPEC.md sections 9, 11.4, and FR-4.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a fired cron event into one post on X, exactly once.
 */
class SRL_Publisher {

	/**
	 * Backoff after failed attempts 1, 2 and 3, in seconds.
	 *
	 * One initial attempt plus three retries: four attempts in total. Gating
	 * on "attempts < 3" would give three attempts and make the 60-minute step
	 * unreachable.
	 */
	public const BACKOFF = array( 300, 900, 3600 );

	/**
	 * How long an uploaded media id stays valid, measured on the live API.
	 */
	public const MEDIA_TTL = 86400;

	/**
	 * Handle a fired send event.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function run( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			// The post is gone. Writing meta here would create an orphan row
			// for a post id that no longer exists.
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			// Only a scheduled send may be cancelled. Writing `cancelled`
			// unconditionally turned a terminal state back into a schedulable
			// one -- `cancelled` is in SCHEDULABLE_FROM -- so a second worker
			// reaching here while the first was mid-send could overwrite
			// `sending` or `sent`, and untrash-and-republish would then pass
			// G-5 and pay for a second post. TR-14.
			if ( SRL_Post_Meta::STATUS_SCHEDULED === SRL_Post_Meta::get_status( $post_id ) ) {
				SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_CANCELLED );
				SRL_Log::write( SRL_Log::EVENT_CANCELLED, $post_id, null, null, 'No longer published at send time.' );
			}
			return;
		}

		// Re-read the per-post switch. This is what closes the block editor's
		// separate meta-box request: by now the meta has certainly landed.
		if ( '0' === (string) get_post_meta( $post_id, SRL_Post_Meta::META_ENABLED, true ) ) {
			SRL_Scheduler::cancel( $post_id, 'Per-post switch was turned off.' );
			return;
		}

		$claim = self::claim( $post_id );
		if ( 'claimed' !== $claim ) {
			return;
		}

		$signer = SRL_Settings::signer();
		if ( null === $signer ) {
			$state = SRL_Settings::credentials_state();
			SRL_Post_Meta::fail( $post_id, $state );
			SRL_Log::write( SRL_Log::EVENT_FAILED, $post_id, null, null, 'Credentials unusable: ' . $state );
			SRL_Notices::record_failure( $post_id );
			return;
		}

		$provider = new SRL_X_Provider( $signer );
		$result   = $provider->send( self::build_payload( $post ) );

		foreach ( $result->endpoints_called as $endpoint ) {
			SRL_Usage::record( $endpoint );
		}

		self::apply_result( $post_id, $result );
	}

	/**
	 * Take exclusive ownership of this send.
	 *
	 * A single conditional UPDATE, because update_post_meta() is not atomic
	 * and WP-Cron can fire the same event twice under concurrent requests.
	 * This is the mechanism that protects INV-1.
	 *
	 * @param int $post_id Post id.
	 * @return string 'claimed', 'taken', 'error' or 'duplicate_meta'.
	 */
	public static function claim( int $post_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An atomic compare-and-swap has no core API; update_post_meta() is not atomic and would break INV-1.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta}
					SET meta_value = %s
				  WHERE post_id = %d
				    AND meta_key = %s
				    AND meta_value = %s",
				SRL_Post_Meta::STATUS_SENDING,
				$post_id,
				SRL_Post_Meta::META_STATUS,
				SRL_Post_Meta::STATUS_SCHEDULED
			)
		);

		// The UPDATE bypassed the object cache, so stale meta would survive
		// for the rest of the request without this.
		wp_cache_delete( $post_id, 'post_meta' );

		// Strict comparison matters: $claimed == 1 is also true for true and
		// for '1', which is what made the false case invisible.
		if ( 1 === $claimed ) {
			update_post_meta( $post_id, SRL_Post_Meta::META_SENDING_SINCE, time() );
			return 'claimed';
		}

		if ( false === $claimed ) {
			// A query error — deadlock, lock timeout, lost connection. Leave
			// the status at `scheduled` so the reconciliation pass can see it,
			// and record it, because an abandoned send with no evidence is the
			// silent failure INV-5 forbids.
			SRL_Log::write(
				SRL_Log::EVENT_FAILED,
				$post_id,
				null,
				null,
				'Claim query failed: ' . (string) $wpdb->last_error
			);
			return 'error';
		}

		if ( is_int( $claimed ) && $claimed >= 2 ) {
			SRL_Post_Meta::fail( $post_id, 'duplicate_status_meta' );
			SRL_Log::write( SRL_Log::EVENT_FAILED, $post_id, null, null, 'Post has duplicate _srl_status rows.' );
			return 'duplicate_meta';
		}

		// Zero rows: another worker claimed it first, or the status was not
		// `scheduled`. Distinguish the two so only the genuine race is silent.
		if ( SRL_Post_Meta::STATUS_SENDING !== SRL_Post_Meta::get_status( $post_id ) ) {
			SRL_Log::write(
				SRL_Log::EVENT_CANCELLED,
				$post_id,
				null,
				null,
				'Event fired while status was ' . SRL_Post_Meta::get_status( $post_id ) . '; nothing sent.'
			);
		}

		return 'taken';
	}

	/**
	 * Assemble the payload, reading everything at send time.
	 *
	 * @param WP_Post $post The post.
	 * @return SRL_Post_Payload
	 */
	public static function build_payload( WP_Post $post ): SRL_Post_Payload {
		$post_id = (int) $post->ID;

		$image_path = null;
		$image_mime = null;

		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb_id > 0 ) {
			$image      = self::resolve_image( $thumb_id );
			$image_path = $image['path'];
			$image_mime = $image['mime'];
		}

		// Reuse a media id from an earlier attempt while it is still valid, so
		// a retry does not pay for the same image again and does not skew the
		// media-to-post ratio the owner reconciles against the invoice.
		$existing = (string) get_post_meta( $post_id, SRL_Post_Meta::META_MEDIA_ID, true );
		$uploaded = (int) get_post_meta( $post_id, SRL_Post_Meta::META_MEDIA_UPLOADED_AT, true );
		$reusable = ( '' !== $existing && $uploaded > 0 && ( time() - $uploaded ) < self::MEDIA_TTL ) ? $existing : null;

		return new SRL_Post_Payload(
			(string) get_the_title( $post_id ),
			(string) get_permalink( $post_id ),
			$image_path,
			$image_mime,
			(string) SRL_Settings::get( 'prefix', '' ),
			(string) SRL_Settings::get( 'suffix', '' ),
			$reusable
		);
	}

	/**
	 * Find a usable file for the featured image.
	 *
	 * Prefers the `large` intermediate over the original: a camera-sourced
	 * original is routinely 6-12 MB, which would make downscaling the normal
	 * path when WordPress has already produced something under the limit.
	 * Never fetched over HTTP — the site may be behind basic auth, a staging
	 * password, or a CDN.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array{path:string|null, mime:string|null}
	 */
	public static function resolve_image( int $attachment_id ): array {
		$mime = (string) get_post_mime_type( $attachment_id );

		if ( ! in_array( $mime, SRL_X_Provider::ALLOWED_MIME, true ) ) {
			return array(
				'path' => null,
				'mime' => $mime,
			);
		}

		$path = get_attached_file( $attachment_id );

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && isset( $meta['sizes']['large']['file'] ) && is_string( $path ) ) {
			$candidate = dirname( $path ) . '/' . $meta['sizes']['large']['file'];
			if ( is_readable( $candidate ) ) {
				$path = $candidate;
			}
		}

		return array(
			'path' => is_string( $path ) && is_readable( $path ) ? $path : null,
			'mime' => $mime,
		);
	}

	/**
	 * Apply the error matrix to a send result.
	 *
	 * @param int             $post_id Post id.
	 * @param SRL_Send_Result $result  What happened.
	 * @return void
	 */
	public static function apply_result( int $post_id, SRL_Send_Result $result ): void {
		$attempts = (int) get_post_meta( $post_id, SRL_Post_Meta::META_ATTEMPTS, true ) + 1;
		update_post_meta( $post_id, SRL_Post_Meta::META_ATTEMPTS, $attempts );

		if ( null !== $result->media_id ) {
			update_post_meta( $post_id, SRL_Post_Meta::META_MEDIA_ID, $result->media_id );
			update_post_meta( $post_id, SRL_Post_Meta::META_MEDIA_UPLOADED_AT, time() );
		}

		if ( $result->image_omitted ) {
			update_post_meta( $post_id, SRL_Post_Meta::META_IMAGE_OMITTED, '1' );
			update_post_meta( $post_id, SRL_Post_Meta::META_IMAGE_REASON, mb_substr( $result->image_omitted_reason, 0, 200 ) );
		}

		if ( $result->success ) {
			$now = time();
			update_post_meta( $post_id, SRL_Post_Meta::META_REMOTE_ID, (string) $result->remote_id );
			update_post_meta( $post_id, SRL_Post_Meta::META_SENT_AT, $now );
			SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SENT );
			delete_post_meta( $post_id, SRL_Post_Meta::META_SENDING_SINCE );

			SRL_Log::write(
				SRL_Log::EVENT_SENT,
				$post_id,
				$result->http_status,
				$result->remote_id,
				$result->image_omitted ? 'Image omitted: ' . $result->image_omitted_reason : '',
				'x',
				null,
				$now
			);
			return;
		}

		if ( $result->is_retryable() && $attempts <= count( self::BACKOFF ) ) {
			$delay = self::BACKOFF[ $attempts - 1 ];

			// If the server told us when the window opens, honour it when it
			// is further out than our own backoff. Retrying at 5 and 15
			// minutes inside a 15-minute rate limit spends two retries and two
			// billed requests before it can possibly succeed.
			if ( null !== $result->retry_after && $result->retry_after > $delay ) {
				$delay = min( $result->retry_after, 6 * HOUR_IN_SECONDS );
			}

			$when = time() + $delay;

			SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SCHEDULED );
			update_post_meta( $post_id, SRL_Post_Meta::META_SCHEDULED_AT, $when );
			delete_post_meta( $post_id, SRL_Post_Meta::META_SENDING_SINCE );

			if ( SRL_Scheduler::schedule_send( $post_id, $when ) ) {
				SRL_Log::write(
					SRL_Log::EVENT_RETRY,
					$post_id,
					$result->http_status,
					null,
					sprintf( 'Attempt %d failed (%s). Retrying in %d seconds.', $attempts, $result->error_code, $delay ),
					'x',
					$when
				);
			}
			return;
		}

		$reason = $result->error_code;

		// A duplicate rejection on a retry most likely means the earlier
		// attempt actually succeeded and only its response was lost. Saying
		// "failed" there would be wrong in the direction that matters.
		if ( SRL_Send_Result::ERROR_DUPLICATE === $result->error_code && $attempts > 1 ) {
			$reason = 'duplicate_on_retry';
		}

		SRL_Post_Meta::fail( $post_id, $reason );
		delete_post_meta( $post_id, SRL_Post_Meta::META_SENDING_SINCE );

		// Clear any event still associated with this post. A terminally failed
		// post should have none, but if one lingers WordPress will suppress a
		// new event scheduled within ten minutes of it, silently swallowing
		// the owner's "Repost now".
		SRL_Scheduler::clear_send( $post_id );

		SRL_Log::write(
			SRL_Log::EVENT_FAILED,
			$post_id,
			$result->http_status,
			null,
			$result->error_message
		);

		SRL_Notices::record_failure( $post_id );
	}
}
