<?php
/**
 * The activity log.
 *
 * Implements SPEC.md section 5 and FR-5.1. A custom table rather than post meta
 * or an option, because the log is append-only, is queried by time and by post,
 * and must be prunable without rewriting a serialised blob.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the srl_log table.
 */
class SRL_Log {

	/**
	 * Event names. Anything not in this list is a programming error.
	 */
	public const EVENT_SCHEDULED              = 'scheduled';
	public const EVENT_SENT                   = 'sent';
	public const EVENT_FAILED                 = 'failed';
	public const EVENT_CANCELLED              = 'cancelled';
	public const EVENT_RETRY                  = 'retry';
	public const EVENT_TEST                   = 'test';
	public const EVENT_CREDENTIALS_UNREADABLE = 'credentials_unreadable';

	/**
	 * Maximum stored message size, in bytes. SPEC.md section 5.
	 */
	public const MAX_MESSAGE_BYTES = 2048;

	/**
	 * Schema version of the table below.
	 *
	 * WordPress does NOT fire the activation hook when a plugin is updated in
	 * place, so "runs dbDelta on upgrade" needs an explicit stored version to
	 * compare against. Without this the varchar(20) to varchar(32) widening
	 * would never reach existing installs — the very sites with data worth
	 * keeping. Incremented whenever the DDL changes.
	 */
	public const DB_VERSION = 2;

	/**
	 * Option holding the installed schema version.
	 */
	public const DB_VERSION_OPTION = 'srl_db_version';

	/**
	 * The daily pruning event.
	 */
	public const PRUNE_HOOK = 'srl_prune_log';

	/**
	 * How long a row is kept, in days. SPEC.md section 5.1.
	 */
	public const RETENTION_DAYS = 90;

	/**
	 * Rows deleted per prune run. Bounded so a neglected install cannot
	 * produce one enormous DELETE; the next day's run continues.
	 */
	public const PRUNE_BATCH = 1000;

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'srl_log';
	}

	/**
	 * Create or update the table.
	 *
	 * Note that dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, one space
	 * around each definition, KEY rather than INDEX. Deviating makes dbDelta
	 * believe the schema changed and reissue ALTER statements on every check.
	 *
	 * @return void
	 */
	public static function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// event is varchar(32), not varchar(20): 'credentials_unreadable' is 22
		// characters. At 20 a strict-mode MySQL rejects the insert and a
		// non-strict one silently truncates to a value matching no query, so
		// the single audit record of the salt-rotation state would have been
		// the one row that could not be stored.
		//
		// post_title, scheduled_at and sent_at are denormalised snapshots so a
		// row is self-contained. Reading them live at render time loses them
		// for a deleted post and shows a reposted post's new sent time against
		// every historical row.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  post_title varchar(255) NOT NULL DEFAULT '',
  provider varchar(32) NOT NULL DEFAULT 'x',
  event varchar(32) NOT NULL,
  http_status smallint(5) unsigned DEFAULT NULL,
  remote_id varchar(64) DEFAULT NULL,
  message text DEFAULT NULL,
  scheduled_at datetime DEFAULT NULL,
  sent_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY post_id (post_id),
  KEY created_at (created_at)
) {$charset_collate};";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Creating this plugin's own table is the point of this method.
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Run the schema upgrade when the stored version is behind.
	 *
	 * Called on plugins_loaded. Cheap in the common case: one autoloaded
	 * option read and an integer comparison.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed = (int) get_option( self::DB_VERSION_OPTION, 0 );

		if ( $installed >= self::DB_VERSION ) {
			return;
		}

		self::install_table();
	}

	/**
	 * Append one row.
	 *
	 * @param string      $event       One of the EVENT_* constants.
	 * @param int         $post_id     Post id, or 0 for events not about a post.
	 * @param int|null    $http_status HTTP status, or null for a transport error.
	 * @param string|null $remote_id   The X post id, when there is one.
	 * @param string      $message      Free text or a response body. Truncated.
	 * @param string      $provider     Provider id.
	 * @param int|null    $scheduled_at UTC timestamp the send was scheduled for.
	 * @param int|null    $sent_at      UTC timestamp the send completed.
	 * @return void
	 */
	public static function write(
		string $event,
		int $post_id = 0,
		?int $http_status = null,
		?string $remote_id = null,
		string $message = '',
		string $provider = 'x',
		?int $scheduled_at = null,
		?int $sent_at = null
	): void {
		global $wpdb;

		// Snapshot the title now. Reading it at render time returns nothing
		// once the post is deleted, and the log outlives the post by design.
		$title = '';
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post ) {
				$title = mb_substr( (string) $post->post_title, 0, 255 );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; there is no core API for it, and a log write must not be cached.
		$wpdb->insert(
			self::table_name(),
			array(
				'post_id'      => $post_id,
				'post_title'   => $title,
				'provider'     => $provider,
				'event'        => $event,
				'http_status'  => $http_status,
				'remote_id'    => $remote_id,
				'message'      => self::truncate( $message ),
				'scheduled_at' => null === $scheduled_at ? null : gmdate( 'Y-m-d H:i:s', $scheduled_at ),
				'sent_at'      => null === $sent_at ? null : gmdate( 'Y-m-d H:i:s', $sent_at ),
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Truncate a message to the stored maximum without splitting a character.
	 *
	 * A naive substr() on a UTF-8 string can cut a multi-byte sequence in half,
	 * which produces a column value that is not valid UTF-8. MySQL may then
	 * reject the row or silently store a replacement character, and the log
	 * entry describing a failure would itself become a failure.
	 *
	 * @param string $message Raw message.
	 * @return string
	 */
	public static function truncate( string $message ): string {
		if ( strlen( $message ) <= self::MAX_MESSAGE_BYTES ) {
			return $message;
		}

		// mb_strcut cuts to a byte length but never inside a character, which
		// is exactly the requirement. mb_substr counts characters, not bytes,
		// and substr ignores encoding entirely; neither is correct here.
		return mb_strcut( $message, 0, self::MAX_MESSAGE_BYTES, 'UTF-8' );
	}

	/**
	 * The most recent rows, newest first. FR-1.8.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, object>
	 */
	public static function recent( int $limit = 50 ): array {
		global $wpdb;

		$limit = max( 1, min( 500, $limit ) );
		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name cannot be a placeholder; $limit is an integer bounded above.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rows for one post, newest first.
	 *
	 * @param int $post_id Post id.
	 * @param int $limit   Maximum rows.
	 * @return array<int, object>
	 */
	public static function for_post( int $post_id, int $limit = 20 ): array {
		global $wpdb;

		$limit = max( 1, min( 500, $limit ) );
		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name cannot be a placeholder; both values are prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE post_id = %d ORDER BY id DESC LIMIT %d",
				$post_id,
				$limit
			)
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete rows older than the retention window.
	 *
	 * @return int Rows deleted.
	 */
	public static function prune(): int {
		global $wpdb;

		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name cannot be a placeholder; both values are prepared.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s LIMIT %d",
				$cutoff,
				self::PRUNE_BATCH
			)
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Event names that represent a send attempt, for the FR-1.8 filter.
	 *
	 * "The last 50 attempts" and "the last 50 rows" are different sets: a busy
	 * site that schedules and cancels would otherwise fill the window with
	 * non-attempts and hide the failures the panel exists to surface.
	 *
	 * @return array<int, string>
	 */
	public static function attempt_events(): array {
		return array( self::EVENT_SENT, self::EVENT_FAILED, self::EVENT_RETRY );
	}

	/**
	 * Drop the table. Used by uninstall.php only.
	 *
	 * @return void
	 */
	public static function drop_table(): void {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping this plugin's own table on uninstall is the point of this method.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Register the daily prune event.
	 *
	 * @return void
	 */
	public static function schedule_prune(): void {
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Remove the daily prune event.
	 *
	 * @return void
	 */
	public static function unschedule_prune(): void {
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
	}
}
