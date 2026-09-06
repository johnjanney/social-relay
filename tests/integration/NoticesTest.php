<?php
/**
 * Integration tests for admin notices and failure email.
 *
 * Covers FR-5.2 and FR-5.3.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

/**
 * How a failure reaches the owner.
 */
class NoticesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( SRL_Notices::OPTION );
		SRL_Settings::install_defaults();
	}

	/** T-510 */
	public function test_one_dismissible_notice_per_failed_post(): void {
		$post_id = self::factory()->post->create();

		SRL_Notices::record_failure( (int) $post_id );
		SRL_Notices::record_failure( (int) $post_id );

		$this->assertSame(
			array( (int) $post_id ),
			SRL_Notices::pending(),
			'One notice per failed post, not one per failure event.'
		);
	}

	/** T-511 */
	public function test_notice_dismissal_persists(): void {
		$post_id = self::factory()->post->create();
		SRL_Notices::record_failure( (int) $post_id );

		SRL_Notices::dismiss( (int) $post_id );

		$this->assertNotContains( (int) $post_id, SRL_Notices::pending() );
		$this->assertSame( array(), get_option( SRL_Notices::OPTION ) );
	}

	/** T-520 */
	public function test_no_email_when_disabled(): void {
		$sent = 0;
		add_filter(
			'pre_wp_mail',
			function () use ( &$sent ) {
				++$sent;
				return true;
			}
		);

		SRL_Notices::record_failure( (int) self::factory()->post->create() );

		$this->assertSame( 0, $sent );
	}

	/** T-521 */
	public function test_one_email_per_failure_when_enabled(): void {
		$settings                     = SRL_Settings::all();
		$settings['email_on_failure'] = true;
		update_option( SRL_Settings::OPTION, $settings );

		$sent = 0;
		add_filter(
			'pre_wp_mail',
			function () use ( &$sent ) {
				++$sent;
				return true;
			}
		);

		SRL_Notices::record_failure( (int) self::factory()->post->create() );

		$this->assertSame( 1, $sent );
	}

	/**
	 * A duplicate rejection on a retry usually means the earlier attempt did
	 * go through. Telling the owner it failed would be wrong in the direction
	 * that costs them a duplicate post.
	 */
	public function test_duplicate_on_retry_notice_warns_the_post_may_be_live(): void {
		$post_id = self::factory()->post->create();
		SRL_Post_Meta::fail( (int) $post_id, 'duplicate_on_retry' );
		SRL_Notices::record_failure( (int) $post_id );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		SRL_Notices::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'did go through', $html, 'the notice must say the post probably succeeded' );
		$this->assertStringContainsString( 'Check your timeline', $html, 'and tell the owner how to confirm' );
		$this->assertStringNotContainsString( 'Try again', $html, 'it must not invite a duplicate' );
	}

	/**
	 * A notice for a post that no longer exists must clean itself up rather
	 * than rendering an empty row forever.
	 */
	public function test_notice_for_deleted_post_is_dropped(): void {
		$post_id = self::factory()->post->create();
		SRL_Notices::record_failure( (int) $post_id );
		wp_delete_post( $post_id, true );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		SRL_Notices::render();
		ob_end_clean();

		$this->assertNotContains( (int) $post_id, SRL_Notices::pending() );
	}
}
