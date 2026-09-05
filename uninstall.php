<?php
/**
 * Uninstall.
 *
 * Removes everything the plugin created: options, post meta, the log table and
 * every scheduled event. Deactivation does not come here — a site owner who
 * deactivates to debug something should not lose their settings.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

// Only WordPress may run this.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-log.php';
require_once __DIR__ . '/includes/class-usage.php';
require_once __DIR__ . '/includes/class-cron-health.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-post-meta.php';
require_once __DIR__ . '/includes/class-scheduler.php';
require_once __DIR__ . '/includes/class-notices.php';

global $wpdb;

// Scheduled events first: an event firing after the code is gone would be
// stranded in the cron array forever.
SRL_Scheduler::clear_all_pending_sends();
SRL_Cron_Health::unschedule_heartbeat();
SRL_Log::unschedule_prune();

foreach ( SRL_Post_Meta::all_keys() as $srl_meta_key ) {
	delete_post_meta_by_key( $srl_meta_key );
}

SRL_Log::drop_table();

SRL_Settings::delete_all();
SRL_Usage::delete_all();
SRL_Cron_Health::delete_all();
delete_option( SRL_Notices::OPTION );
delete_option( SRL_Log::DB_VERSION_OPTION );
