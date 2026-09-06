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

/**
 * Remove everything this plugin created on the current site.
 *
 * @return void
 */
function srl_uninstall_current_site(): void {
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
}

/*
 * WordPress runs uninstall.php ONCE for a network-wide uninstall, so a
 * single-site body would leave every subsite holding its own wp_N_srl_log
 * table, its own srl_settings option -- which contains the encrypted X
 * credentials -- and all its _srl_ post meta. SEC-8 says uninstall leaves
 * nothing behind, and on multisite that was simply false.
 *
 * Brief section 3 excludes multisite *network activation* from the feature set;
 * it does not excuse leaving secrets on disk when someone removes the plugin.
 */
if ( is_multisite() ) {
	$srl_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $srl_sites as $srl_site_id ) {
		switch_to_blog( (int) $srl_site_id );
		srl_uninstall_current_site();
		restore_current_blog();
	}
} else {
	srl_uninstall_current_site();
}
