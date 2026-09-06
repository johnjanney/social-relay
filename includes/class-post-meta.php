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

	/** The admin_post action behind the three buttons; the hook is admin_post_{ACTION}. */
	public const ACTION = 'srl_post_action';
	/** Nonce action prefix; the post id is appended. */
	public const ACTION_NONCE = 'srl_post_action_';

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

		// The notice is raised here, not at each call site. Three of the four
		// paths into `failed` -- schedule_failed, stalled and event_lost --
		// set the status and wrote the log row and never raised it, so exactly
		// the failures that happen while nobody is watching were the ones that
		// stayed silent. Putting it here means no future path can forget.
		SRL_Notices::record_failure( $post_id );
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
			self::reconcile_after_save( $post_id );
			return;
		}

		update_post_meta( $post_id, self::META_DELAY_OVERRIDE, $minutes );
		self::reconcile_after_save( $post_id );
	}

	/**
	 * Re-evaluate scheduling now that the meta box fields have landed.
	 *
	 * In the block editor -- the default since WordPress 5.0 -- publishing
	 * happens over the REST API, which carries no $_POST and no meta box
	 * nonce, and the meta box's own fields arrive afterwards in a separate
	 * post.php?meta-box-loader=1 request. By then transition_post_status has
	 * already run and scheduled with the site default.
	 *
	 * The consequences were not cosmetic: an author who typed 5 in "Delay for
	 * this post" got 60, and an author on a site whose master switch is off who
	 * ticked "Post to X" got nothing scheduled at all, with no feedback that
	 * the tick was ignored. Only the disable direction was rescued, by the
	 * re-read in SRL_Publisher::run().
	 *
	 * This is the seam where the meta is finally known, so it is where the
	 * decision is revisited.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	private static function reconcile_after_save( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		$status = self::get_status( $post_id );

		// Already sent, sending, failed or deliberately cancelled: leave it be.
		if ( ! in_array( $status, array( self::STATUS_NONE, self::STATUS_SCHEDULED ), true ) ) {
			return;
		}

		$wanted = '1' === (string) get_post_meta( $post_id, self::META_ENABLED, true );

		if ( ! $wanted ) {
			if ( self::STATUS_SCHEDULED === $status ) {
				SRL_Scheduler::cancel( $post_id, 'Per-post switch turned off after publishing.' );
			}
			return;
		}

		// The post should go out. Recompute the time from the stored meta and
		// reschedule, so a delay override typed in the editor is honoured
		// whichever editor sent it.
		$when = (int) $post->post_date_gmt ? strtotime( $post->post_date_gmt . ' UTC' ) : time();
		$when = ( is_int( $when ) ? $when : time() ) + self::stored_delay_for( $post_id );

		if ( self::STATUS_SCHEDULED === $status ) {
			$existing = (int) get_post_meta( $post_id, self::META_SCHEDULED_AT, true );

			// Within a minute of the intended time is close enough; churning
			// the cron array on every autosave would be worse than the drift.
			if ( abs( $existing - $when ) <= MINUTE_IN_SECONDS ) {
				return;
			}

			SRL_Scheduler::clear_send( $post_id );
		} elseif ( null !== SRL_Scheduler::guard( $post ) ) {
			// Not schedulable for some other reason.
			return;
		}

		update_post_meta( $post_id, self::META_SCHEDULED_AT, $when );
		self::set_status( $post_id, self::STATUS_SCHEDULED );

		if ( SRL_Scheduler::schedule_send( $post_id, $when ) ) {
			SRL_Log::write( SRL_Log::EVENT_SCHEDULED, $post_id, null, null, 'Rescheduled from the editor.', 'x', $when );
		}
	}

	/**
	 * The delay for a post, read from stored meta only.
	 *
	 * @param int $post_id Post id.
	 * @return int Seconds.
	 */
	private static function stored_delay_for( int $post_id ): int {
		$override = get_post_meta( $post_id, self::META_DELAY_OVERRIDE, true );

		if ( '' !== $override ) {
			return SRL_Settings::resolve_delay( absint( $override ), 'minutes' );
		}

		return SRL_Settings::delay_seconds();
	}

	/**
	 * Build the nonce-protected link behind one of the three action buttons.
	 *
	 * The buttons are links to admin-post.php, not submit buttons. The block
	 * editor wraps every classic meta box in a form with onsubmit="return
	 * false;" and later serialises the fields itself, and a button's name and
	 * value are never part of that serialisation. So a submit button inside
	 * the box does nothing at all there, silently. A link works in both
	 * editors and needs no JavaScript beyond the confirmation.
	 *
	 * @param int    $post_id Post id.
	 * @param string $action  One of cancel, repost, send_now.
	 * @return string URL, HTML-escaped by wp_nonce_url().
	 */
	public static function action_url( int $post_id, string $action ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'post'   => $post_id,
					'do'     => $action,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION_NONCE . $post_id
		);
	}

	/**
	 * The admin_post handler for the three action links.
	 *
	 * Verifies the nonce and the edit_post capability for this specific post,
	 * performs the action, and returns the owner to the editor. Nothing here
	 * calls the X API (INV-2): every action ends in a scheduled event or a
	 * cleared one.
	 *
	 * @return void
	 */
	public static function handle_admin_post(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer() two lines down.
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		$action  = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( (string) $_GET['do'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		check_admin_referer( self::ACTION_NONCE . $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to change this post.', 'social-relay' ) );
		}

		self::perform( $post_id, $action );

		$back = get_edit_post_link( $post_id, 'raw' );
		wp_safe_redirect( is_string( $back ) && '' !== $back ? $back : admin_url( 'edit.php' ) );
		exit;
	}

	/**
	 * Carry out one of the three owner actions.
	 *
	 * Separated from handle_admin_post() so the state change is testable
	 * without a redirect. The caller has already verified the nonce and the
	 * capability.
	 *
	 * @param int    $post_id Post id.
	 * @param string $action  One of cancel, repost, send_now. Anything else is ignored.
	 * @return void
	 */
	public static function perform( int $post_id, string $action ): void {
		if ( 'cancel' === $action ) {
			SRL_Scheduler::cancel( $post_id, 'Cancelled from the post editor.' );
			return;
		}

		$status = self::get_status( $post_id );

		if ( 'repost' === $action ) {
			// The only path to a second post. Reachable from `sent` and
			// `failed` only, so a stale page cannot duplicate a scheduled or
			// in-flight post.
			if ( in_array( $status, array( self::STATUS_SENT, self::STATUS_FAILED ), true ) ) {
				self::schedule_now( $post_id, 'Repost requested by the owner.' );
			}
			return;
		}

		if ( 'send_now' !== $action ) {
			return;
		}

		// A first send for a published post the automatic trigger never
		// reached (TR-16). The two buttons partition the states: `sent`,
		// `sending` and `failed` are refused here, so a page rendered while
		// the post was unsent and clicked after another request sent it
		// cannot produce a second paid post without FR-2.5's confirmation.
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! in_array( $status, array( self::STATUS_NONE, self::STATUS_CANCELLED, self::STATUS_SCHEDULED ), true ) ) {
			return;
		}

		// `scheduled` is accepted because the page is a snapshot: the post can
		// have been scheduled by another request since it was rendered. The
		// owner asked for now, so the pending event is replaced rather than
		// the click swallowed.
		if ( self::STATUS_SCHEDULED === $status ) {
			SRL_Scheduler::clear_send( $post_id );
		}

		self::schedule_now( $post_id, 'Manual send requested by the owner.' );
	}

	/**
	 * Schedule a send at delay 0, from an owner's confirmed click.
	 *
	 * Shared by "Repost now" (TR-10) and "Post to X now" (TR-16). The caller
	 * has already decided the transition is allowed from the current status.
	 *
	 * The per-post switch is forced on. On a site whose master switch is off
	 * the checkbox defaults unticked and an earlier save stored '0', so
	 * without this the publisher's re-read (SPEC 11.5, part 3) would cancel
	 * the send the owner just confirmed. A confirmed click outranks a
	 * checkbox.
	 *
	 * @param int    $post_id Post id.
	 * @param string $message Log message.
	 * @return void
	 */
	private static function schedule_now( int $post_id, string $message ): void {
		update_post_meta( $post_id, self::META_ENABLED, '1' );

		delete_post_meta( $post_id, self::META_ATTEMPTS );
		delete_post_meta( $post_id, self::META_LAST_ERROR );
		delete_post_meta( $post_id, self::META_SENDING_SINCE );
		delete_post_meta( $post_id, self::META_MEDIA_ID );
		delete_post_meta( $post_id, self::META_MEDIA_UPLOADED_AT );

		$when = time();
		self::set_status( $post_id, self::STATUS_SCHEDULED );
		update_post_meta( $post_id, self::META_SCHEDULED_AT, $when );

		if ( SRL_Scheduler::schedule_send( $post_id, $when ) ) {
			SRL_Log::write( SRL_Log::EVENT_SCHEDULED, $post_id, null, null, $message, 'x', $when );
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
