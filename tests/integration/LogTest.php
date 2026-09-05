<?php
/**
 * Integration tests for the log table and its schema.
 *
 * Covers SPEC.md section 5 and FR-1.8, FR-5.1.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

/**
 * The table, its columns, and the things that only fail on a real database.
 */
class LogTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		SRL_Log::install_table();
	}

	/**
	 * T-152
	 *
	 * `credentials_unreadable` is 22 characters. At varchar(20) a strict-mode
	 * MySQL rejects the insert and a non-strict one truncates to a value that
	 * matches no query, so the single audit record of the salt-rotation state
	 * is the one row that cannot be stored. This only fails on a real server,
	 * never against a mocked writer.
	 */
	public function test_every_event_value_round_trips_through_the_column(): void {
		$events = array(
			SRL_Log::EVENT_SCHEDULED,
			SRL_Log::EVENT_SENT,
			SRL_Log::EVENT_FAILED,
			SRL_Log::EVENT_CANCELLED,
			SRL_Log::EVENT_RETRY,
			SRL_Log::EVENT_TEST,
			SRL_Log::EVENT_CREDENTIALS_UNREADABLE,
		);

		foreach ( $events as $event ) {
			SRL_Log::write( $event, 0, null, null, 'round-trip check' );
		}

		$stored = wp_list_pluck( SRL_Log::recent( 20 ), 'event' );

		foreach ( $events as $event ) {
			$this->assertContains( $event, $stored, "Event '{$event}' did not survive the column." );
		}
	}

	/** T-624 */
	public function test_event_column_accepts_credentials_unreadable(): void {
		SRL_Log::write( SRL_Log::EVENT_CREDENTIALS_UNREADABLE, 0 );

		$rows = SRL_Log::recent( 1 );

		$this->assertSame( 'credentials_unreadable', $rows[0]->event );
		$this->assertSame( 22, strlen( $rows[0]->event ) );
	}

	/**
	 * T-151
	 *
	 * A row must survive its post. Reading the title live at render time loses
	 * it for exactly the posts most likely to be investigated.
	 */
	public function test_log_row_is_self_contained_after_post_deletion(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'A Post That Will Be Deleted' ) );

		SRL_Log::write( SRL_Log::EVENT_SENT, (int) $post_id, 201, '999', '' );

		wp_delete_post( $post_id, true );

		$rows = SRL_Log::for_post( (int) $post_id );

		$this->assertNotEmpty( $rows );
		$this->assertSame( 'A Post That Will Be Deleted', $rows[0]->post_title );
	}

	/** T-502 */
	public function test_log_message_truncated_to_2048_bytes_on_char_boundary(): void {
		SRL_Log::write( SRL_Log::EVENT_FAILED, 0, 500, null, str_repeat( 'a', 2040 ) . '😀😀😀😀' );

		$message = (string) SRL_Log::recent( 1 )[0]->message;

		$this->assertLessThanOrEqual( 2048, strlen( $message ) );
		$this->assertTrue( (bool) preg_match( '//u', $message ), 'stored message must be valid UTF-8' );
	}

	/** T-150 */
	public function test_log_panel_returns_last_50_newest_first(): void {
		for ( $i = 0; $i < 55; $i++ ) {
			SRL_Log::write( SRL_Log::EVENT_TEST, 0, null, null, 'row ' . $i );
		}

		$rows = SRL_Log::recent( 50 );

		$this->assertCount( 50, $rows );
		$this->assertSame( 'row 54', $rows[0]->message );
	}

	/** T-153 */
	public function test_dbdelta_upgrade_from_version_1_preserves_rows(): void {
		SRL_Log::write( SRL_Log::EVENT_SENT, 0, 201, '1', 'existing row' );

		// Pretend an older schema is installed, as an in-place plugin update
		// would leave it: the activation hook does not fire on update.
		update_option( SRL_Log::DB_VERSION_OPTION, 1 );
		SRL_Log::maybe_upgrade();

		$this->assertSame( SRL_Log::DB_VERSION, (int) get_option( SRL_Log::DB_VERSION_OPTION ) );
		$this->assertNotEmpty( SRL_Log::recent( 5 ) );
	}

	/** T-141 */
	public function test_usage_counter_increments_on_failure(): void {
		SRL_Usage::record( 'POST /2/tweets' );
		SRL_Usage::record( 'POST /2/tweets' );
		SRL_Usage::record( 'POST /2/media/upload' );

		$this->assertSame( 2, SRL_Usage::for_month()['POST /2/tweets'] );
		$this->assertSame( 3, SRL_Usage::total_for_month() );
	}
}
