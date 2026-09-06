<?php
/**
 * Integration tests for the per-post meta box.
 *
 * Covers FR-2 and the save path in SPEC.md section 11.5.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

/**
 * The one control the author has to stop a paid post.
 */
class MetaBoxTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		$settings            = SRL_Settings::defaults();
		$settings['enabled'] = true;
		update_option( SRL_Settings::OPTION, $settings );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		_set_cron_array( array() );
	}

	public function tear_down(): void {
		unset(
			$_POST[ SRL_Post_Meta::NONCE_FIELD ],
			$_POST[ SRL_Post_Meta::FIELD_ENABLED ],
			$_POST[ SRL_Post_Meta::FIELD_DELAY ]
		);
		parent::tear_down();
	}

	/** T-200 */
	public function test_meta_box_checkbox_defaults_from_master_switch(): void {
		$post_id = self::factory()->post->create();

		// No stored preference: the master switch decides.
		$this->assertTrue( SRL_Scheduler::per_post_enabled( (int) $post_id ) );

		$settings            = SRL_Settings::all();
		$settings['enabled'] = false;
		update_option( SRL_Settings::OPTION, $settings );

		$this->assertFalse( SRL_Scheduler::per_post_enabled( (int) $post_id ) );
	}

	/**
	 * T-314
	 *
	 * The blocker from the spec review. transition_post_status runs BEFORE
	 * save_post, so at schedule time the stored meta still holds the previous
	 * request's value, or none at all for a first publish. Without the $_POST
	 * read-through, unchecking the box and pressing Publish in the same
	 * request did not stop the send, and the post went out at $0.20.
	 */
	public function test_unchecked_box_in_same_request_prevents_scheduling(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$_POST[ SRL_Post_Meta::NONCE_FIELD ] = wp_create_nonce( SRL_Post_Meta::NONCE_ACTION );
		// The checkbox is absent from $_POST when unchecked, as a browser sends it.
		unset( $_POST[ SRL_Post_Meta::FIELD_ENABLED ] );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertFalse(
			SRL_Scheduler::has_pending_send( (int) $post_id ),
			'Unchecking the box in the publishing request must prevent the send.'
		);
	}

	/** T-315 */
	public function test_delay_override_in_same_request_is_used(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$_POST[ SRL_Post_Meta::NONCE_FIELD ]   = wp_create_nonce( SRL_Post_Meta::NONCE_ACTION );
		$_POST[ SRL_Post_Meta::FIELD_ENABLED ] = '1';
		$_POST[ SRL_Post_Meta::FIELD_DELAY ]   = '5';

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$due = (int) wp_next_scheduled( SRL_Scheduler::SEND_HOOK, SRL_Scheduler::event_args( (int) $post_id ) );

		$this->assertEqualsWithDelta( time() + 300, $due, 15, 'the override, not the 60-minute default' );
	}

	/** T-210 */
	public function test_blank_override_uses_default_delay(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$_POST[ SRL_Post_Meta::NONCE_FIELD ]   = wp_create_nonce( SRL_Post_Meta::NONCE_ACTION );
		$_POST[ SRL_Post_Meta::FIELD_ENABLED ] = '1';
		$_POST[ SRL_Post_Meta::FIELD_DELAY ]   = '';

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$due = (int) wp_next_scheduled( SRL_Scheduler::SEND_HOOK, SRL_Scheduler::event_args( (int) $post_id ) );

		$this->assertEqualsWithDelta( time() + 3600, $due, 15 );
	}

	/** T-211 */
	public function test_override_delay_wins_and_respects_72_hour_bound(): void {
		$post_id = self::factory()->post->create();

		$_POST[ SRL_Post_Meta::NONCE_FIELD ]   = wp_create_nonce( SRL_Post_Meta::NONCE_ACTION );
		$_POST[ SRL_Post_Meta::FIELD_ENABLED ] = '1';
		$_POST[ SRL_Post_Meta::FIELD_DELAY ]   = '99999';

		SRL_Post_Meta::save( (int) $post_id );

		$this->assertSame(
			'',
			get_post_meta( $post_id, SRL_Post_Meta::META_DELAY_OVERRIDE, true ),
			'An out-of-range override falls back to the default rather than being clamped to a value nobody chose.'
		);
	}

	/** T-220 */
	public function test_status_line_renders_each_of_five_states(): void {
		$post_id = self::factory()->post->create();

		$expectations = array(
			SRL_Post_Meta::STATUS_NONE      => 'Not scheduled',
			SRL_Post_Meta::STATUS_SCHEDULED => 'Scheduled for',
			SRL_Post_Meta::STATUS_SENT      => 'Sent',
			SRL_Post_Meta::STATUS_FAILED    => 'Failed',
			SRL_Post_Meta::STATUS_CANCELLED => 'Cancelled',
		);

		update_post_meta( $post_id, SRL_Post_Meta::META_SCHEDULED_AT, time() + 60 );
		update_post_meta( $post_id, SRL_Post_Meta::META_SENT_AT, time() );
		update_post_meta( $post_id, SRL_Post_Meta::META_REMOTE_ID, '123' );
		update_post_meta( $post_id, SRL_Post_Meta::META_LAST_ERROR, 'server' );

		foreach ( $expectations as $status => $needle ) {
			SRL_Post_Meta::set_status( (int) $post_id, $status );
			$this->assertStringContainsString( $needle, SRL_Post_Meta::status_line( (int) $post_id ), "state: {$status}" );
		}
	}

	/** T-230, T-240 */
	public function test_buttons_appear_only_in_their_states(): void {
		$post_id = self::factory()->post->create();

		SRL_Post_Meta::set_status( (int) $post_id, SRL_Post_Meta::STATUS_SCHEDULED );
		ob_start();
		SRL_Post_Meta::render( get_post( $post_id ) );
		$scheduled_html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Cancel scheduled post', $scheduled_html );
		$this->assertStringNotContainsString( 'Repost now', $scheduled_html );

		SRL_Post_Meta::set_status( (int) $post_id, SRL_Post_Meta::STATUS_SENT );
		ob_start();
		SRL_Post_Meta::render( get_post( $post_id ) );
		$sent_html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Repost now', $sent_html );
		$this->assertStringNotContainsString( 'Cancel scheduled post', $sent_html );
		$this->assertStringContainsString( 'only way to post the same article twice', $sent_html );
	}

	/** T-241 */
	public function test_repost_requires_confirmation_and_nonce(): void {
		$post_id = self::factory()->post->create();
		SRL_Post_Meta::set_status( (int) $post_id, SRL_Post_Meta::STATUS_FAILED );

		ob_start();
		SRL_Post_Meta::render( get_post( $post_id ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( SRL_Post_Meta::NONCE_FIELD, $html, 'the field-save nonce is still rendered' );
		$this->assertStringContainsString( 'do=repost', $html );
		$this->assertMatchesRegularExpression( '/do=repost[^"]*_wpnonce=[0-9a-f]+/', $html, 'the repost link carries its own nonce' );
		$this->assertStringContainsString( 'confirm(', $html );
		$this->assertStringContainsString( 'Unsaved edits are not included', $html, 'the link bypasses save_post, so the confirmation must say so' );
	}

	/**
	 * T-250
	 *
	 * "Post to X now" is the path for a post the automatic trigger never
	 * reached, so it renders for a published post in `none` or `cancelled`
	 * and for nothing else: not for a draft, whose permalink is dead, and not
	 * for a sent post, where "Repost now" is the only path to a second post.
	 */
	public function test_send_now_button_visible_only_for_published_unsent_posts(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$draft   = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		delete_post_meta( $post_id, SRL_Post_Meta::META_STATUS );
		$html = $this->render( (int) $post_id );
		$this->assertStringContainsString( 'Post to X now', $html, 'published, no status' );
		$this->assertStringContainsString( 'admin-post.php', $html, 'a link, not a submit button: the block editor swallows submits' );
		$this->assertStringContainsString( 'do=send_now', $html );
		$this->assertStringNotContainsString( 'type="submit"', $html );
		$this->assertStringContainsString( 'confirm(', $html );
		$this->assertStringNotContainsString( 'Repost now', $html );
		$this->assertStringNotContainsString( 'Cancel scheduled post', $html );

		SRL_Post_Meta::set_status( (int) $post_id, SRL_Post_Meta::STATUS_CANCELLED );
		$this->assertStringContainsString( 'Post to X now', $this->render( (int) $post_id ), 'published, cancelled' );

		foreach ( array( SRL_Post_Meta::STATUS_SCHEDULED, SRL_Post_Meta::STATUS_SENDING, SRL_Post_Meta::STATUS_SENT, SRL_Post_Meta::STATUS_FAILED ) as $status ) {
			SRL_Post_Meta::set_status( (int) $post_id, $status );
			$this->assertStringNotContainsString( 'Post to X now', $this->render( (int) $post_id ), "published, {$status}" );
		}

		delete_post_meta( $draft, SRL_Post_Meta::META_STATUS );
		$this->assertStringNotContainsString( 'Post to X now', $this->render( (int) $draft ), 'draft, no status' );
	}

	/**
	 * Render the meta box for a post and return the HTML.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private function render( int $post_id ): string {
		ob_start();
		SRL_Post_Meta::render( get_post( $post_id ) );
		return (string) ob_get_clean();
	}

	/** T-304 */
	public function test_autosave_and_revision_schedule_nothing(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		delete_post_meta( $post_id, SRL_Post_Meta::META_STATUS );
		_set_cron_array( array() );

		$revision_id = wp_save_post_revision( $post_id );

		$this->assertFalse( SRL_Scheduler::has_pending_send( (int) $post_id ) );
		if ( $revision_id ) {
			$this->assertFalse( SRL_Scheduler::has_pending_send( (int) $revision_id ) );
		}
	}
}
