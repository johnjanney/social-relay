<?php
/**
 * Per-post state and the editor meta box.
 *
 * Implements SPEC.md section 4, FR-2, and the save path in section 11.5.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns every _srl_ post meta key and the meta box that edits two of them.
 */
class SRL_Post_Meta {

	public const META_ENABLED           = '_srl_enabled';
	public const META_DELAY_OVERRIDE    = '_srl_delay_override';
	public const META_STATUS            = '_srl_status';
	public const META_SCHEDULED_AT      = '_srl_scheduled_at';
	public const META_SENT_AT           = '_srl_sent_at';
	public const META_SENDING_SINCE     = '_srl_sending_since';
	public const META_REMOTE_ID         = '_srl_remote_id';
	public const META_ATTEMPTS          = '_srl_attempts';
	public const META_LAST_ERROR        = '_srl_last_error';
	public const META_IMAGE_OMITTED     = '_srl_image_omitted';
	public const META_IMAGE_REASON      = '_srl_image_omitted_reason';
	public const META_MEDIA_ID          = '_srl_media_id';
	public const META_MEDIA_UPLOADED_AT = '_srl_media_uploaded_at';

	public const STATUS_NONE      = 'none';
	public const STATUS_SCHEDULED = 'scheduled';
	public const STATUS_SENDING   = 'sending';
	public const STATUS_SENT      = 'sent';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';

	public const NONCE_ACTION  = 'srl_meta_box';
	public const NONCE_FIELD   = 'srl_meta_box_nonce';
	public const FIELD_ENABLED = 'srl_enabled';
	public const FIELD_DELAY   = 'srl_delay_override';

	/**
	 * Current status, treating absent meta as `none`.
	 *
	 * Every guard treats "not set" and "none" identically; the compare-and-swap
	 * in the publisher depends on that equivalence being stated once.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public static function get_status( int $post_id ): string {
		$status = (string) get_post_meta( $post_id, self::META_STATUS, true );
		return '' === $status ? self::STATUS_NONE : $status;
	}

	/**
	 * Set the status.
	 *
	 * @param int    $post_id Post id.
	 * @param string $status  New status.
	 * @return void
	 */
	public static function set_status( int $post_id, string $status ): void {
		update_post_meta( $post_id, self::META_STATUS, $status );
	}

	/**
	 * Move a post to failed with a reason.
	 *
	 * @param int    $post_id Post id.
	 * @param string $reason  Machine-readable reason.
	 * @return void
	 */
	public static function fail( int $post_id, string $reason ): void {
		self::set_status( $post_id, self::STATUS_FAILED );
		update_post_meta( $post_id, self::META_LAST_ERROR, mb_substr( $reason, 0, 500 ) );
	}

	/**
	 * Whether this request carries a valid meta box submission for this post.
	 *
	 * Note that transition_post_status runs before save_post, so at schedule time the
	 * stored meta still holds the previous request's values. When the nonce is
	 * present the scheduler reads $_POST instead. When it is absent — a REST
	 * publish, WP-CLI, a bulk edit — it falls back to stored meta.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function request_has_meta_box( int $post_id ): bool {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Register the meta box.
	 *
	 * @return void
	 */
	public static function add_meta_box(): void {
		$types = (array) apply_filters( 'srl_post_types', array( 'post' ) );

		add_meta_box(
			'srl-meta-box',
			__( 'Social Relay', 'social-relay' ),
			array( __CLASS__, 'render' ),
			$types,
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post The post being edited. Used by the template.
	 * @return void
	 */
	public static function render( WP_Post $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $post is consumed by the required template.
		require SRL_PLUGIN_DIR . 'admin/meta-box.php';
	}

	/**
	 * Save the meta box fields.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function save( int $post_id ): void {
		if ( ! self::request_has_meta_box( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce is verified in request_has_meta_box(), which gates this whole method.
		update_post_meta( $post_id, self::META_ENABLED, empty( $_POST[ self::FIELD_ENABLED ] ) ? '0' : '1' );

		$raw = isset( $_POST[ self::FIELD_DELAY ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD_DELAY ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $raw ) {
			delete_post_meta( $post_id, self::META_DELAY_OVERRIDE );
			return;
		}

		$minutes = absint( $raw );

		if ( ! SRL_Settings::delay_is_valid( $minutes, 'minutes' ) ) {
			// Out of range: keep the default rather than silently clamping to
			// a value the author did not choose.
			delete_post_meta( $post_id, self::META_DELAY_OVERRIDE );
			return;
		}

		update_post_meta( $post_id, self::META_DELAY_OVERRIDE, $minutes );
	}

	/**
	 * Handle "Cancel scheduled post" and "Repost now". FR-2.4, FR-2.5.
	 *
	 * Runs on save_post, because both buttons submit the editor form. The
	 * nonce and the edit_post capability are already checked by
	 * request_has_meta_box().
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function handle_action( int $post_id ): void {
		if ( ! self::request_has_meta_box( $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in request_has_meta_box().
		$action = isset( $_POST['srl_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['srl_action'] ) ) : '';

		if ( 'cancel' === $action ) {
			SRL_Scheduler::cancel( $post_id, 'Cancelled from the post editor.' );
			return;
		}

		if ( 'repost' !== $action ) {
			return;
		}

		// The only path to a second post. Reachable from `sent` and `failed`
		// only, so a scheduled or in-flight post cannot be duplicated by
		// re-submitting the form.
		if ( ! in_array( self::get_status( $post_id ), array( self::STATUS_SENT, self::STATUS_FAILED ), true ) ) {
			return;
		}

		delete_post_meta( $post_id, self::META_ATTEMPTS );
		delete_post_meta( $post_id, self::META_LAST_ERROR );
		delete_post_meta( $post_id, self::META_SENDING_SINCE );
		delete_post_meta( $post_id, self::META_MEDIA_ID );
		delete_post_meta( $post_id, self::META_MEDIA_UPLOADED_AT );

		$when = time();
		self::set_status( $post_id, self::STATUS_SCHEDULED );
		update_post_meta( $post_id, self::META_SCHEDULED_AT, $when );

		if ( SRL_Scheduler::schedule_send( $post_id, $when ) ) {
			SRL_Log::write( SRL_Log::EVENT_SCHEDULED, $post_id, null, null, 'Repost requested by the owner.', 'x', $when );
			SRL_Notices::dismiss( $post_id );
		}
	}

	/**
	 * Human-readable status line for the meta box. FR-2.3.
	 *
	 * @param int $post_id Post id.
	 * @return string Escaped HTML.
	 */
	public static function status_line( int $post_id ): string {
		$status = self::get_status( $post_id );

		switch ( $status ) {
			case self::STATUS_SCHEDULED:
				$when = (int) get_post_meta( $post_id, self::META_SCHEDULED_AT, true );
				return sprintf(
					/* translators: %s: local date and time */
					esc_html__( 'Scheduled for %s (or the first cron run after).', 'social-relay' ),
					esc_html( self::local_time( $when ) )
				);

			case self::STATUS_SENDING:
				return esc_html__( 'Sending now.', 'social-relay' );

			case self::STATUS_SENT:
				$remote = (string) get_post_meta( $post_id, self::META_REMOTE_ID, true );
				$when   = (int) get_post_meta( $post_id, self::META_SENT_AT, true );
				return sprintf(
					/* translators: 1: local date and time, 2: link to the post on X */
					esc_html__( 'Sent %1$s. %2$s', 'social-relay' ),
					esc_html( self::local_time( $when ) ),
					'<a href="' . esc_url( 'https://x.com/i/web/status/' . $remote ) . '" target="_blank" rel="noopener">' . esc_html__( 'View on X', 'social-relay' ) . '</a>'
				);

			case self::STATUS_FAILED:
				$reason = (string) get_post_meta( $post_id, self::META_LAST_ERROR, true );

				if ( 'credentials_unreadable' === $reason ) {
					return esc_html__( 'Not sent: this site\'s security salts appear to have changed, so the stored API keys can no longer be read. Re-enter the four keys on the settings page. The keys in your X account are fine.', 'social-relay' );
				}

				return sprintf(
					/* translators: %s: failure reason */
					esc_html__( 'Failed: %s', 'social-relay' ),
					esc_html( $reason )
				);

			case self::STATUS_CANCELLED:
				return esc_html__( 'Cancelled. Nothing will be sent.', 'social-relay' );

			default:
				return esc_html__( 'Not scheduled.', 'social-relay' );
		}
	}

	/**
	 * Render a UTC timestamp in the site's timezone.
	 *
	 * Times are stored in UTC everywhere and converted only at display.
	 *
	 * @param int $timestamp UTC timestamp.
	 * @return string
	 */
	public static function local_time( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return __( 'an unknown time', 'social-relay' );
		}

		$formatted = wp_date(
			(string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ),
			$timestamp
		);

		return is_string( $formatted ) ? $formatted : '';
	}

	/**
	 * Every meta key this plugin owns. Used by uninstall.php.
	 *
	 * @return array<int, string>
	 */
	public static function all_keys(): array {
		return array(
			self::META_ENABLED,
			self::META_DELAY_OVERRIDE,
			self::META_STATUS,
			self::META_SCHEDULED_AT,
			self::META_SENT_AT,
			self::META_SENDING_SINCE,
			self::META_REMOTE_ID,
			self::META_ATTEMPTS,
			self::META_LAST_ERROR,
			self::META_IMAGE_OMITTED,
			self::META_IMAGE_REASON,
			self::META_MEDIA_ID,
			self::META_MEDIA_UPLOADED_AT,
		);
	}
}
