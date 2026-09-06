<?php
/**
 * Scheduling, cancellation and reconciliation.
 *
 * Implements SPEC.md sections 10.3, 11.2, 11.5, 11.6 and 11.7.
 *
 * Every blocker found in the Phase 2 review lived in this area, and all of
 * them came from the same mistake: treating WordPress as a set of clean state
 * transitions rather than as a request lifecycle. The comments below mark the
 * places where that distinction is load-bearing.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides what to schedule, and keeps the cron array honest.
 */
class SRL_Scheduler {

	/**
	 * The single send event.
	 */
	public const SEND_HOOK = 'srl_send_post';

	/**
	 * How old a post may be, in seconds, and still count as "newly published".
	 *
	 * Brief section 1.5 means new, not merely newly-transitioned. Without this
	 * a 500-post import schedules 500 sends of posts dated years ago.
	 */
	public const FRESHNESS_WINDOW = DAY_IN_SECONDS;

	/**
	 * How long a claim may sit in `sending` before it is treated as crashed.
	 */
	public const STALE_SENDING_AFTER = 900;

	/**
	 * How far past its scheduled time a post may sit before a missing event
	 * is treated as lost.
	 */
	public const LOST_EVENT_AFTER = HOUR_IN_SECONDS;

	/**
	 * Maximum posts examined per reconciliation pass.
	 */
	public const RECONCILE_BATCH = 20;

	/**
	 * Statuses from which a new send may be scheduled.
	 *
	 * NOT `sent`, `sending` or `scheduled`. This is INV-1's guard, and its
	 * absence was the second blocker: unpublish-then-republish, untrash, and
	 * publish-private-publish are all ordinary editorial actions that produced
	 * a duplicate paid post. X's duplicate rejection does not save those
	 * cases, because the title is usually corrected in between.
	 */
	public const SCHEDULABLE_FROM = array( '', 'none', 'cancelled', 'failed' );

	/**
	 * The single place a send event is scheduled.
	 *
	 * @param int $post_id   Post id.
	 * @param int $timestamp UTC timestamp.
	 * @return bool True when an event now exists.
	 */
	public static function schedule_send( int $post_id, int $timestamp ): bool {
		$scheduled = wp_schedule_single_event( $timestamp, self::SEND_HOOK, self::event_args( $post_id ) );

		// wp_schedule_single_event() returns false when an identical event is
		// already due within ten minutes, when pre_schedule_event or
		// schedule_event short-circuits, or when the cron option write fails.
		// Treating this as a statement rather than a call with a result left
		// posts reading "Scheduled" forever with nothing to move them.
		if ( false === $scheduled ) {
			SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_FAILED );
			update_post_meta( $post_id, SRL_Post_Meta::META_LAST_ERROR, 'schedule_failed' );
			SRL_Log::write( SRL_Log::EVENT_FAILED, $post_id, null, null, 'wp_schedule_single_event() returned false.' );
			return false;
		}

		return true;
	}

	/**
	 * Clear any pending send for a post.
	 *
	 * Accepts a loose id on purpose: admin requests supply strings, and the
	 * normalisation belongs here rather than at every call site.
	 *
	 * @param int|string $post_id Post id in any form.
	 * @return void
	 */
	public static function clear_send( $post_id ): void {
		wp_clear_scheduled_hook( self::SEND_HOOK, self::event_args( $post_id ) );
	}

	/**
	 * Whether a send event exists for a post.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function has_pending_send( int $post_id ): bool {
		return false !== wp_next_scheduled( self::SEND_HOOK, self::event_args( $post_id ) );
	}

	/**
	 * The one and only shape of the event argument array.
	 *
	 * WordPress matches events by md5( serialize( $args ) ), which is
	 * type-sensitive: array( 123 ) and array( '123' ) hash differently. A post
	 * id arriving from $_POST or through sanitize_text_field() is a string,
	 * and a single missing cast leaves a live event behind while the meta says
	 * `cancelled`. Every schedule and every clear goes through here so no call
	 * site can get it wrong independently.
	 *
	 * @param int|string $post_id Post id in any form.
	 * @return array<int, int>
	 */
	public static function event_args( $post_id ): array {
		return array( (int) $post_id );
	}

	/**
	 * Handle a post status transition.
	 *
	 * Serves both scheduling and cancellation. The argument order is
	 * ( $new, $old, $post ) — reversing the first two produces a plugin that
	 * fires on unpublish and never on publish.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       The post.
	 * @return void
	 */
	public static function on_transition( string $new_status, string $old_status, WP_Post $post ): void {
		$post_id = (int) $post->ID;

		// Leaving publish cancels any pending send (TR-3).
		if ( 'publish' === $old_status && 'publish' !== $new_status ) {
			self::cancel( $post_id, 'unpublished' );
			return;
		}

		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		$skip = self::guard( $post );
		if ( null !== $skip ) {
			// A skip that has a cost implication must be findable later. "The
			// switch is off" is the normal state of a disabled plugin and
			// would fill the log, so it is not recorded.
			if ( in_array( $skip, array( 'importing', 'bulk_edit', 'stale_post', 'credentials_unreadable' ), true ) ) {
				SRL_Log::write( SRL_Log::EVENT_CANCELLED, $post_id, null, null, 'Skipped: ' . $skip );
			}
			if ( 'credentials_unreadable' === $skip ) {
				SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_FAILED );
				update_post_meta( $post_id, SRL_Post_Meta::META_LAST_ERROR, 'credentials_unreadable' );
			}
			return;
		}

		$delay = self::delay_for( $post_id );
		$when  = time() + $delay;

		// A fresh send gets a fresh budget. `failed` is deliberately in
		// SCHEDULABLE_FROM so unpublish-then-republish is a supported retry,
		// and without this the counter still held 4 from the previous run:
		// the first transient 500 would then be terminal, with no hint why it
		// did not retry.
		delete_post_meta( $post_id, SRL_Post_Meta::META_ATTEMPTS );
		delete_post_meta( $post_id, SRL_Post_Meta::META_LAST_ERROR );
		delete_post_meta( $post_id, SRL_Post_Meta::META_IMAGE_OMITTED );
		delete_post_meta( $post_id, SRL_Post_Meta::META_IMAGE_REASON );

		SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_SCHEDULED );
		update_post_meta( $post_id, SRL_Post_Meta::META_SCHEDULED_AT, $when );

		if ( self::schedule_send( $post_id, $when ) ) {
			SRL_Log::write( SRL_Log::EVENT_SCHEDULED, $post_id, null, null, '', 'x', $when );
		}
	}

	/**
	 * Test-only override for is_importing().
	 *
	 * WP_IMPORTING is a constant, so a test that defines it poisons every
	 * later test in the process -- which is exactly what happened, silently
	 * skipping sixteen of them. Process isolation is not a workable
	 * alternative here because the WordPress test suite itself emits a
	 * constant-redefinition warning that PHPUnit turns into a fatal error in
	 * an isolated process. A single explicit seam is the honest answer, and it
	 * is the pattern the Phase 7 review recommended for the same problem in
	 * SRL_Cron_Health.
	 *
	 * Null in production, always.
	 *
	 * @var bool|null
	 */
	public static ?bool $importing_override = null;

	/**
	 * Whether this request is a WordPress import.
	 *
	 * @return bool
	 */
	public static function is_importing(): bool {
		if ( null !== self::$importing_override ) {
			return self::$importing_override;
		}

		return defined( 'WP_IMPORTING' ) && WP_IMPORTING;
	}

	/**
	 * Apply every scheduling guard.
	 *
	 * @param WP_Post $post The post.
	 * @return string|null Guard name that rejected it, or null to proceed.
	 */
	public static function guard( WP_Post $post ): ?string {
		$post_id = (int) $post->ID;

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return 'revision';
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return 'autosave';
		}

		/**
		 * Post types eligible to be relayed.
		 *
		 * @param array<int, string> $types Post type slugs.
		 */
		$types = apply_filters( 'srl_post_types', array( 'post' ) );
		if ( ! in_array( $post->post_type, (array) $types, true ) ) {
			return 'post_type';
		}

		// Master and per-post switches first, matching SPEC 10.3's G-4-before-G-6
		// order. An activated-but-unconfigured install is the default state
		// (FR-1.3), and importing 5,000 posts into one would otherwise write
		// 5,000 log rows -- each with a get_post() to snapshot the title --
		// explaining skips that need no explanation because the plugin is off.
		if ( ! SRL_Settings::is_enabled() ) {
			return 'master_switch';
		}
		if ( ! self::per_post_enabled( $post_id ) ) {
			return 'post_switch';
		}

		// An importer must not spend the owner's money.
		if ( self::is_importing() ) {
			return 'importing';
		}

		// Bulk-publishing 40 drafts fires 40 transitions in one request, and
		// the meta box is not rendered in bulk edit, so there is no per-post
		// intent to read.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a marker to decline work, not acting on input.
		if ( isset( $_REQUEST['bulk_edit'] ) ) {
			return 'bulk_edit';
		}

		$published = strtotime( (string) $post->post_date_gmt . ' UTC' );
		if ( is_int( $published ) && ( time() - $published ) > self::FRESHNESS_WINDOW ) {
			return 'stale_post';
		}

		$status = SRL_Post_Meta::get_status( $post_id );
		if ( ! in_array( $status, self::SCHEDULABLE_FROM, true ) ) {
			return 'already_' . $status;
		}

		if ( SRL_Settings::CRED_UNREADABLE === SRL_Settings::credentials_state() ) {
			return 'credentials_unreadable';
		}

		/**
		 * Final veto before scheduling.
		 *
		 * @param bool $should  Whether to schedule.
		 * @param int  $post_id Post id.
		 */
		if ( ! apply_filters( 'srl_should_send', true, $post_id ) ) {
			return 'filtered';
		}

		return null;
	}

	/**
	 * Whether this post is marked for relaying.
	 *
	 * Reads $_POST when the meta box nonce is present, because
	 * transition_post_status runs BEFORE save_post. Without this read-through,
	 * unchecking the box and pressing Publish in the same request did not stop
	 * the send: the stored meta still held the previous value, or none at all
	 * for a first publish.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public static function per_post_enabled( int $post_id ): bool {
		if ( SRL_Post_Meta::request_has_meta_box( $post_id ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified inside request_has_meta_box().
			return ! empty( $_POST[ SRL_Post_Meta::FIELD_ENABLED ] );
		}

		$stored = get_post_meta( $post_id, SRL_Post_Meta::META_ENABLED, true );

		// No stored preference yet: fall back to the master switch, per FR-2.1.
		if ( '' === $stored ) {
			return SRL_Settings::is_enabled();
		}

		return '1' === $stored;
	}

	/**
	 * Delay for one post, in seconds.
	 *
	 * @param int $post_id Post id.
	 * @return int
	 */
	public static function delay_for( int $post_id ): int {
		if ( SRL_Post_Meta::request_has_meta_box( $post_id ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified inside request_has_meta_box().
			$raw = isset( $_POST[ SRL_Post_Meta::FIELD_DELAY ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ SRL_Post_Meta::FIELD_DELAY ] ) ) : '';
			if ( '' !== $raw ) {
				return SRL_Settings::resolve_delay( absint( $raw ), 'minutes' );
			}
			return SRL_Settings::delay_seconds();
		}

		$override = get_post_meta( $post_id, SRL_Post_Meta::META_DELAY_OVERRIDE, true );
		if ( '' !== $override ) {
			return SRL_Settings::resolve_delay( absint( $override ), 'minutes' );
		}

		return SRL_Settings::delay_seconds();
	}

	/**
	 * Cancel a pending send.
	 *
	 * @param int    $post_id Post id.
	 * @param string $reason  Why.
	 * @return void
	 */
	public static function cancel( int $post_id, string $reason = '' ): void {
		$status = SRL_Post_Meta::get_status( $post_id );

		if ( SRL_Post_Meta::STATUS_SCHEDULED !== $status ) {
			// A send already in flight is left alone: there is no way to
			// unsend it, and clearing state would lose the record of what
			// happened. TR-14.
			return;
		}

		self::clear_send( $post_id );
		SRL_Post_Meta::set_status( $post_id, SRL_Post_Meta::STATUS_CANCELLED );
		SRL_Log::write( SRL_Log::EVENT_CANCELLED, $post_id, null, null, $reason );
	}

	/**
	 * Clear the event when a post is deleted for good.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function on_delete( int $post_id ): void {
		// Fires for every post type and for every revision deleted during
		// ordinary editing, so guard before doing any work.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		self::clear_send( $post_id );
	}

	/**
	 * Clear every pending send. Used on deactivation.
	 *
	 * @return void
	 */
	public static function clear_all_pending_sends(): void {
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return;
		}

		foreach ( $crons as $timestamp => $hooks ) {
			if ( ! isset( $hooks[ self::SEND_HOOK ] ) ) {
				continue;
			}
			foreach ( $hooks[ self::SEND_HOOK ] as $event ) {
				$args = isset( $event['args'] ) ? (array) $event['args'] : array();
				wp_unschedule_event( (int) $timestamp, self::SEND_HOOK, $args );
			}
		}
	}

	/**
	 * Find and resolve posts that nothing will ever move.
	 *
	 * Runs on the heartbeat. The daily prune is too slow for a 15-minute
	 * staleness rule, and admin_init only fires when someone is looking, which
	 * is the wrong trigger for a condition whose whole point is that nobody is.
	 *
	 * @return int Posts resolved.
	 */
	public static function reconcile(): int {
		$now = time();

		// Two things this query gets right that the obvious version does not.
		//
		// It excludes healthy posts. Matching every `scheduled` post and
		// taking twenty in the default date-DESC order meant that on a site
		// with a long delay publishing more than twenty posts inside the
		// window, the newest healthy not-yet-due posts filled the batch on
		// every heartbeat forever, and the older posts behind them -- which,
		// being older, are exactly the stuck ones -- were never examined.
		//
		// And it orders oldest-first, so the stale end drains rather than
		// starves. `trash` is named explicitly because post_status => 'any'
		// excludes statuses flagged exclude_from_search, so a post trashed
		// mid-send was invisible and stayed in `sending` permanently.
		$query = new WP_Query(
			array(
				'post_type'           => 'any',
				'post_status'         => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'posts_per_page'      => self::RECONCILE_BATCH,
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'orderby'             => 'ID',
				'order'               => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded to 20 rows over an indexed meta_key, and filtered to the stale set.
				'meta_query'          => array(
					'relation' => 'AND',
					array(
						'key'     => SRL_Post_Meta::META_STATUS,
						'value'   => array( SRL_Post_Meta::STATUS_SENDING, SRL_Post_Meta::STATUS_SCHEDULED ),
						'compare' => 'IN',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => SRL_Post_Meta::META_SENDING_SINCE,
							'value'   => $now - self::STALE_SENDING_AFTER,
							'compare' => '<',
							'type'    => 'NUMERIC',
						),
						array(
							'key'     => SRL_Post_Meta::META_SCHEDULED_AT,
							'value'   => $now - self::LOST_EVENT_AFTER,
							'compare' => '<',
							'type'    => 'NUMERIC',
						),
					),
				),
			)
		);

		$resolved = 0;

		foreach ( $query->posts as $post_id ) {
			$post_id = (int) $post_id;
			$status  = SRL_Post_Meta::get_status( $post_id );

			if ( SRL_Post_Meta::STATUS_SENDING === $status ) {
				$since = (int) get_post_meta( $post_id, SRL_Post_Meta::META_SENDING_SINCE, true );

				if ( $since > 0 && ( $now - $since ) > self::STALE_SENDING_AFTER ) {
					// Not retried automatically: a crash mid-send cannot be
					// told apart from a send whose response was lost, and
					// retrying would risk breaking INV-1. The owner decides.
					//
					// Any lingering event is cleared. A failed post that still
					// has one is not just untidy: WordPress suppresses a new
					// event scheduled within ten minutes of an existing
					// identical one, so a stale event would silently swallow
					// the owner's "Repost now".
					self::clear_send( $post_id );
					SRL_Post_Meta::fail( $post_id, 'stalled' );
					SRL_Log::write( SRL_Log::EVENT_FAILED, $post_id, null, null, 'Send stalled and was abandoned.' );
					++$resolved;
				}
				continue;
			}

			$due = (int) get_post_meta( $post_id, SRL_Post_Meta::META_SCHEDULED_AT, true );

			if ( $due > 0 && ( $now - $due ) > self::LOST_EVENT_AFTER && ! self::has_pending_send( $post_id ) ) {
				self::clear_send( $post_id );
				SRL_Post_Meta::fail( $post_id, 'event_lost' );
				SRL_Log::write( SRL_Log::EVENT_FAILED, $post_id, null, null, 'Scheduled event disappeared before it fired.' );
				++$resolved;
			}
		}

		return $resolved;
	}
}
