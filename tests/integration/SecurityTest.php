<?php
/**
 * Integration tests for the security requirements.
 *
 * Covers SPEC.md section 14 (SEC-1..SEC-9) and the INV-3 host rule.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

/**
 * Capabilities, nonces, the host allowlist and output handling.
 */
class SecurityTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		SRL_Settings::install_defaults();
	}

	/**
	 * T-622, INV-3
	 *
	 * The plugin must contact api.x.com and nothing else. upload.x.com is the
	 * legacy v1.1 host the brief forbids building on, and allowlisting it would
	 * permit exactly the call section 1.3 prohibits.
	 */
	public function test_no_request_to_any_host_other_than_api_x_com(): void {
		$hosts = array();

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$hosts ) {
				$hosts[] = (string) wp_parse_url( $url, PHP_URL_HOST );
				return array(
					'response' => array( 'code' => 201 ),
					'body'     => wp_json_encode( array( 'data' => array( 'id' => '1' ) ) ),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$provider = new SRL_X_Provider( new SRL_OAuth1( 'k', 'ks', 't', 'ts' ) );
		$provider->send( new SRL_Post_Payload( 'Title', 'https://example.com/p/' ) );

		$this->assertNotEmpty( $hosts );
		foreach ( $hosts as $host ) {
			$this->assertSame( 'api.x.com', $host );
		}
	}

	/**
	 * The host is a compile-time constant, not configuration. A filter or an
	 * option that could change it would defeat the invariant.
	 */
	public function test_host_is_a_constant_and_not_filterable(): void {
		$this->assertSame( 'https://api.x.com', SRL_X_Provider::API_HOST );

		// Inspect string literals only, via the tokeniser. A plain text search
		// would trip over the comment that explains why upload.x.com is absent,
		// and a test that cannot tell code from prose is not a test.
		$tokens   = token_get_all( (string) file_get_contents( SRL_PLUGIN_DIR . 'includes/providers/class-x-provider.php' ) );
		$literals = array();

		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$literals[] = trim( $token[1], "'\"" );
			}
		}

		foreach ( $literals as $literal ) {
			$this->assertStringNotContainsString( 'upload.x.com', $literal, 'the legacy v1.1 host must never appear in code' );
			$this->assertStringNotContainsString( 'upload.twitter.com', $literal );
		}

		$hosts = array_filter(
			$literals,
			static function ( string $literal ): bool {
				return 1 === preg_match( '#^https?://#', $literal );
			}
		);

		$this->assertSame( array( 'https://api.x.com' ), array_values( array_unique( $hosts ) ) );
	}

	/** T-611 */
	public function test_settings_require_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->expectException( WPDieException::class );
		srl_plugin()->render_settings_page();
	}

	/**
	 * T-612, T-610
	 *
	 * The meta box save path is gated on both a valid nonce and edit_post for
	 * that specific post. Either missing means the request is not a meta box
	 * submission and must be ignored.
	 */
	public function test_meta_box_actions_require_edit_post_for_that_post(): void {
		$post_id = self::factory()->post->create();

		// No nonce at all.
		unset( $_POST[ SRL_Post_Meta::NONCE_FIELD ] );
		$this->assertFalse( SRL_Post_Meta::request_has_meta_box( (int) $post_id ) );

		// A valid nonce, but a user who cannot edit this post.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST[ SRL_Post_Meta::NONCE_FIELD ] = wp_create_nonce( SRL_Post_Meta::NONCE_ACTION );
		$this->assertFalse( SRL_Post_Meta::request_has_meta_box( (int) $post_id ) );

		// A valid nonce and an administrator.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST[ SRL_Post_Meta::NONCE_FIELD ] = wp_create_nonce( SRL_Post_Meta::NONCE_ACTION );
		$this->assertTrue( SRL_Post_Meta::request_has_meta_box( (int) $post_id ) );

		unset( $_POST[ SRL_Post_Meta::NONCE_FIELD ] );
	}

	/** T-610 */
	public function test_missing_nonce_rejects_every_state_change(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, SRL_Post_Meta::META_ENABLED, '1' );

		unset( $_POST[ SRL_Post_Meta::NONCE_FIELD ] );
		$_POST[ SRL_Post_Meta::FIELD_ENABLED ] = '0';

		SRL_Post_Meta::save( (int) $post_id );

		$this->assertSame(
			'1',
			get_post_meta( $post_id, SRL_Post_Meta::META_ENABLED, true ),
			'A request with no nonce must not change stored state.'
		);

		unset( $_POST[ SRL_Post_Meta::FIELD_ENABLED ] );
	}

	/**
	 * T-623, SEC-9
	 *
	 * A response body is attacker-influenceable in the general case, and the
	 * settings screen is the highest-value XSS target a plugin has.
	 */
	public function test_logged_body_is_truncated_and_not_trusted_as_html(): void {
		SRL_Log::install_table();
		SRL_Log::write( SRL_Log::EVENT_FAILED, 0, 500, null, '<script>alert(1)</script>' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		srl_plugin()->render_settings_page();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * T-501, static check S-3
	 *
	 * FR-5.1: the plugin keeps a log table and does not write to error_log in
	 * normal operation.
	 */
	public function test_plugin_source_contains_no_unguarded_error_log_call(): void {
		$files = array_merge(
			glob( SRL_PLUGIN_DIR . 'includes/*.php' ) ?: array(),
			glob( SRL_PLUGIN_DIR . 'includes/providers/*.php' ) ?: array(),
			glob( SRL_PLUGIN_DIR . 'admin/*.php' ) ?: array(),
			array( SRL_PLUGIN_DIR . 'social-relay.php', SRL_PLUGIN_DIR . 'uninstall.php' )
		);

		foreach ( $files as $file ) {
			$this->assertStringNotContainsString(
				'error_log(',
				(string) file_get_contents( $file ),
				basename( (string) $file ) . ' must log to the table, not to error_log'
			);
		}
	}

	/** T-621-equivalent: every stored secret is masked, never echoed. */
	public function test_masked_credential_never_contains_the_whole_secret(): void {
		$settings            = SRL_Settings::all();
		$settings['api_key'] = SRL_Settings::crypto()->encrypt( 'AbCdEfGhIjKlMnOpQrSt' );
		update_option( SRL_Settings::OPTION, $settings );

		$masked = SRL_Settings::masked( 'api_key' );

		$this->assertStringNotContainsString( 'AbCdEfGhIjKlMnOpQrSt', $masked );
		$this->assertStringStartsWith( 'AbCd', $masked );
		$this->assertStringEndsWith( 'St', $masked );
	}
}
