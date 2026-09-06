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

		// cron_schedules is registered in social-relay.php at file scope, not
		// here: plugins_loaded does not fire for the plugin being activated,
		// and the activation hook needs the schedule to already exist.

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
		// Priority 20: after save(), so a repost reads the meta this request wrote.
		add_action( 'save_post', array( SRL_Post_Meta::class, 'handle_action' ), 20, 1 );
		add_action( 'admin_post_srl_send_test', array( $this, 'handle_test_post' ) );
		add_action( 'admin_post_srl_check_credentials', array( $this, 'handle_check_credentials' ) );
		add_action( 'admin_notices', array( SRL_Notices::class, 'render' ) );
		add_action( 'admin_post_srl_dismiss_notice', array( SRL_Notices::class, 'handle_dismiss' ) );
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
	 * Send the connectivity test post. FR-1.5.
	 *
	 * @return void
	 */
	public function handle_test_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'social-relay' ) );
		}
		check_admin_referer( 'srl_send_test' );

		$signer = SRL_Settings::signer();

		if ( null === $signer ) {
			$state = SRL_Settings::credentials_state();
			SRL_Log::write( SRL_Log::EVENT_TEST, 0, null, null, 'Credentials unusable: ' . $state );
			$this->redirect_after_test( 'credentials_' . $state );
			return;
		}

		$provider = new SRL_X_Provider( $signer );

		// URL-free, so it bills at $0.015 rather than $0.200 -- and
		// timestamped, because X rejects a repeated identical post and a fixed
		// string would report a failure on the second press of the button.
		$payload = new SRL_Post_Payload(
			sprintf( 'Social Relay connectivity test %s UTC', gmdate( 'Y-m-d H:i' ) ),
			''
		);

		$result = $provider->send( $payload );

		foreach ( $result->endpoints_called as $endpoint ) {
			SRL_Usage::record( $endpoint );
		}

		SRL_Log::write(
			SRL_Log::EVENT_TEST,
			0,
			$result->http_status,
			$result->remote_id,
			$result->success ? 'Test post sent.' : $result->error_message
		);

		$this->redirect_after_test( $result->success ? 'ok' : 'failed' );
	}

	/**
	 * Check the credentials without publishing anything. FR-1.9.
	 *
	 * @return void
	 */
	public function handle_check_credentials(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'social-relay' ) );
		}
		check_admin_referer( 'srl_check_credentials' );

		$signer = SRL_Settings::signer();

		if ( null === $signer ) {
			$state = SRL_Settings::credentials_state();
			SRL_Log::write( SRL_Log::EVENT_TEST, 0, null, null, 'Credential check: ' . $state );
			$this->redirect_after_test( 'credentials_' . $state );
			return;
		}

		$provider = new SRL_X_Provider( $signer );
		$result   = $provider->verify_credentials();

		foreach ( $result['endpoints'] as $endpoint ) {
			SRL_Usage::record( $endpoint );
		}

		SRL_Log::write(
			SRL_Log::EVENT_TEST,
			0,
			$result['http_status'],
			null,
			$result['ok'] ? 'Credential check succeeded for @' . $result['handle'] : $result['message']
		);

		if ( ! $result['ok'] ) {
			$this->redirect_after_test( 'check_failed' );
			return;
		}

		set_transient( 'srl_checked_handle', $result['handle'], 60 );
		$this->redirect_after_test( 'check_ok' );
	}

	/**
	 * Return to the settings page with a result marker.
	 *
	 * @param string $outcome Result slug.
	 * @return void
	 */
	private function redirect_after_test( string $outcome ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'social-relay',
					'srl_test' => $outcome,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
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
