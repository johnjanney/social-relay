<?php
/**
 * Admin notices.
 *
 * Implements FR-5.2 and FR-5.3.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Surfaces failures where the owner will actually see them.
 */
class SRL_Notices {

	/**
	 * Option holding post ids with an undismissed failure notice.
	 */
	public const OPTION = 'srl_failure_notices';

	/**
	 * Nonce action for dismissal.
	 */
	public const DISMISS_ACTION = 'srl_dismiss_notice';

	/**
	 * Record a failure so a notice appears, and optionally email.
	 *
	 * One notice per failed post, not one per page load.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function record_failure( int $post_id ): void {
		$ids = self::pending();

		if ( ! in_array( $post_id, $ids, true ) ) {
			$ids[] = $post_id;
			update_option( self::OPTION, array_slice( $ids, -50 ), false );
		}

		if ( SRL_Settings::get( 'email_on_failure', false ) ) {
			self::send_email( $post_id );
		}
	}

	/**
	 * Post ids awaiting acknowledgement.
	 *
	 * @return array<int, int>
	 */
	public static function pending(): array {
		$ids = get_option( self::OPTION, array() );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Stop showing the notice for one post.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function dismiss( int $post_id ): void {
		$ids = array_values( array_diff( self::pending(), array( $post_id ) ) );
		update_option( self::OPTION, $ids, false );
	}

	/**
	 * Render the notices.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		if ( SRL_Settings::CRED_UNREADABLE === SRL_Settings::credentials_state() ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Social Relay:', 'social-relay' ),
				esc_html__( 'this site\'s security salts appear to have changed, so the stored X API keys can no longer be read. Re-enter the four keys on the Social Relay settings page. The keys in your X account are fine and do not need regenerating.', 'social-relay' )
			);
		}

		foreach ( self::pending() as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				self::dismiss( $post_id );
				continue;
			}

			$reason = (string) get_post_meta( $post_id, SRL_Post_Meta::META_LAST_ERROR, true );
			$extra  = '';

			// Telling the owner a post failed when it most likely succeeded
			// would be wrong in the direction that costs them a duplicate.
			if ( 'duplicate_on_retry' === $reason ) {
				$extra = ' ' . esc_html__( 'X rejected this as a duplicate on a retry, which usually means the earlier attempt did go through. Check your timeline before reposting.', 'social-relay' );
			}

			// A nonced link rather than core's is-dismissible plus JavaScript.
			// The dismissible class only hides the element for the current
			// page view; without a real endpoint the notice reappeared on
			// every admin page, for every user with edit_posts, for up to
			// fifty posts at once, and the only way to clear it was to delete
			// the post. Administrators learn to ignore a notice like that,
			// which would have defeated the point of raising it at all.
			$dismiss_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'      => 'srl_dismiss_notice',
						'srl_post_id' => (int) $post_id,
					),
					admin_url( 'admin-post.php' )
				),
				self::DISMISS_ACTION . '_' . (int) $post_id
			);

			printf(
				'<div class="notice notice-error srl-failure-notice" data-post="%1$d"><p>%2$s%3$s <a href="%4$s">%5$s</a> &nbsp;|&nbsp; <a href="%6$s">%7$s</a></p></div>',
				(int) $post_id,
				sprintf(
					/* translators: 1: post title, 2: reason */
					esc_html__( 'Social Relay could not post "%1$s" to X: %2$s.', 'social-relay' ),
					esc_html( get_the_title( $post_id ) ),
					esc_html( $reason )
				),
				$extra, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from esc_html__() above.
				esc_url( (string) get_edit_post_link( $post_id ) ),
				esc_html__( 'Open the post', 'social-relay' ),
				esc_url( $dismiss_url ),
				esc_html__( 'Dismiss', 'social-relay' )
			);
		}
	}

	/**
	 * Handle the dismissal link.
	 *
	 * @return void
	 */
	public static function handle_dismiss(): void {
		$post_id = isset( $_GET['srl_post_id'] ) ? absint( wp_unslash( $_GET['srl_post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line.

		check_admin_referer( self::DISMISS_ACTION . '_' . $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to dismiss this notice.', 'social-relay' ) );
		}

		self::dismiss( $post_id );

		wp_safe_redirect( wp_get_referer() ? (string) wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Email the admin about one failure.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	private static function send_email( int $post_id ): void {
		$to = (string) get_option( 'admin_email' );
		if ( '' === $to ) {
			return;
		}

		wp_mail(
			$to,
			sprintf(
				/* translators: %s: site name */
				__( '[%s] A post could not be sent to X', 'social-relay' ),
				(string) get_bloginfo( 'name' )
			),
			sprintf(
				/* translators: 1: post title, 2: reason, 3: edit link */
				__( "Social Relay could not post \"%1\$s\" to X.\n\nReason: %2\$s\n\nOpen the post: %3\$s", 'social-relay' ),
				(string) get_the_title( $post_id ),
				(string) get_post_meta( $post_id, SRL_Post_Meta::META_LAST_ERROR, true ),
				(string) get_edit_post_link( $post_id, 'raw' )
			)
		);
	}
}
