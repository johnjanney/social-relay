<?php
/**
 * Hook wiring.
 *
 * This class does one thing: connect WordPress hooks to the classes that do
 * the work. It contains no logic of its own, so the whole hook surface of the
 * plugin can be read in one screen.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers every hook the plugin uses.
 */
class SRL_Plugin {

	/**
	 * Wire everything up.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Schema upgrades: WordPress does not fire the activation hook on an
		// in-place update, so the check has to run on load.
		add_action( 'plugins_loaded', array( SRL_Log::class, 'maybe_upgrade' ), 20 );

		add_filter( 'cron_schedules', array( SRL_Cron_Health::class, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- A one-minute schedule is the point; see SPEC.md 11.3.

		// Both scheduling and cancellation. accepted_args must be 3, and the
		// order is ( new, old, post ): reversing the first two produces a
		// plugin that fires on unpublish and never on publish.
		add_action( 'transition_post_status', array( SRL_Scheduler::class, 'on_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( SRL_Scheduler::class, 'on_delete' ), 10, 1 );

		add_action( SRL_Scheduler::SEND_HOOK, array( SRL_Publisher::class, 'run' ), 10, 1 );

		add_action( SRL_Cron_Health::HOOK, array( $this, 'heartbeat' ) );
		add_action( SRL_Log::PRUNE_HOOK, array( $this, 'prune' ) );

		if ( is_admin() ) {
			$this->register_admin_hooks();
		}
	}

	/**
	 * Admin-only hooks.
	 *
	 * @return void
	 */
	private function register_admin_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'add_meta_boxes', array( SRL_Post_Meta::class, 'add_meta_box' ) );
		add_action( 'save_post', array( SRL_Post_Meta::class, 'save' ), 10, 1 );
		add_action( 'admin_notices', array( SRL_Notices::class, 'render' ) );
	}

	/**
	 * The one-minute heartbeat: record that cron ran, then reconcile.
	 *
	 * @return void
	 */
	public function heartbeat(): void {
		SRL_Cron_Health::beat();
		SRL_Scheduler::reconcile();
	}

	/**
	 * Daily pruning.
	 *
	 * @return void
	 */
	public function prune(): void {
		SRL_Log::prune();
		SRL_Usage::prune();
	}

	/**
	 * Add Settings → Social Relay.
	 *
	 * @return void
	 */
	public function add_settings_page(): void {
		add_options_page(
			__( 'Social Relay', 'social-relay' ),
			__( 'Social Relay', 'social-relay' ),
			'manage_options',
			'social-relay',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register the settings group.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'srl_settings_group',
			SRL_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( SRL_Settings::class, 'sanitize' ),
				'default'           => SRL_Settings::defaults(),
			)
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'social-relay' ) );
		}

		require SRL_PLUGIN_DIR . 'admin/settings-page.php';
	}
}
