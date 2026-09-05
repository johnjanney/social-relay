<?php
/**
 * Integration test for uninstall.
 *
 * Covers SEC-8 and SPEC.md section 14.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

/**
 * Uninstall must leave nothing behind.
 */
class UninstallTest extends WP_UnitTestCase {

	/** T-630 */
	public function test_uninstall_removes_options_meta_table_and_events(): void {
		global $wpdb;

		SRL_Log::install_table();
		SRL_Settings::install_defaults();
		SRL_Cron_Health::schedule_heartbeat();
		SRL_Log::schedule_prune();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		SRL_Post_Meta::set_status( (int) $post_id, SRL_Post_Meta::STATUS_SCHEDULED );
		SRL_Scheduler::schedule_send( (int) $post_id, time() + 3600 );
		SRL_Log::write( SRL_Log::EVENT_SCHEDULED, (int) $post_id );
		SRL_Usage::record( 'POST /2/tweets' );

		// Preconditions, so a pass cannot be vacuous.
		$this->assertNotEmpty( get_option( SRL_Settings::OPTION ) );
		$this->assertTrue( SRL_Scheduler::has_pending_send( (int) $post_id ) );

		// The WordPress test suite filters every query, rewriting CREATE TABLE
		// to CREATE TEMPORARY TABLE and DROP TABLE to DROP TEMPORARY TABLE. A
		// real table created before those filters applied would therefore
		// survive a DROP and the assertion below would fail for a reason that
		// has nothing to do with the plugin. Removing the filters makes the
		// assertion test what it claims to test.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		SRL_Log::install_table();

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'social-relay/social-relay.php' );
		}
		require SRL_PLUGIN_DIR . 'uninstall.php';

		$this->assertFalse( get_option( SRL_Settings::OPTION ) );
		$this->assertFalse( get_option( SRL_Usage::OPTION ) );
		$this->assertFalse( get_option( SRL_Cron_Health::OPTION ) );
		$this->assertFalse( get_option( SRL_Log::DB_VERSION_OPTION ) );

		$this->assertSame( '', get_post_meta( (int) $post_id, SRL_Post_Meta::META_STATUS, true ) );
		$this->assertFalse( SRL_Scheduler::has_pending_send( (int) $post_id ) );
		$this->assertFalse( (bool) wp_next_scheduled( SRL_Cron_Health::HOOK ) );
		$this->assertFalse( (bool) wp_next_scheduled( SRL_Log::PRUNE_HOOK ) );

		$table = SRL_Log::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Asserting a table is gone requires asking the database directly.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->assertNull( $exists, 'the log table must be dropped' );
	}
}
