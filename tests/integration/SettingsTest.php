<?php
/**
 * Integration tests for settings validation and credential handling.
 *
 * Covers SPEC.md sections 3 and 6.3.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

/**
 * Validation, the keep-on-blank rule, and the salt-rotation diagnosis.
 */
class SettingsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( SRL_Settings::OPTION );
	}

	/** T-111 */
	public function test_fresh_install_defaults_to_60_minutes(): void {
		SRL_Settings::install_defaults();

		$this->assertSame( 60, (int) SRL_Settings::get( 'delay_value' ) );
		$this->assertSame( 'minutes', SRL_Settings::get( 'delay_unit' ) );
		$this->assertSame( 3600, SRL_Settings::delay_seconds() );
	}

	/**
	 * T-110
	 *
	 * Validation is on the resolved seconds, not the raw number, so 4321
	 * minutes is correctly rejected as over 72 hours rather than accepted
	 * because 4321 looks small.
	 */
	public function test_delay_above_72_hours_is_rejected(): void {
		$this->assertTrue( SRL_Settings::delay_is_valid( 4320, 'minutes' ) );
		$this->assertFalse( SRL_Settings::delay_is_valid( 4321, 'minutes' ) );
		$this->assertTrue( SRL_Settings::delay_is_valid( 72, 'hours' ) );
		$this->assertFalse( SRL_Settings::delay_is_valid( 73, 'hours' ) );

		SRL_Settings::install_defaults();
		$clean = SRL_Settings::sanitize(
			array(
				'delay_value' => 4321,
				'delay_unit'  => 'minutes',
			)
		);

		$this->assertSame( 60, (int) $clean['delay_value'], 'the previous value must be kept' );
	}

	/** T-113 */
	public function test_prefix_and_suffix_reject_61_weighted_characters(): void {
		SRL_Settings::install_defaults();

		$ok = SRL_Settings::sanitize( array( 'prefix' => str_repeat( 'a', 60 ) ) );
		$this->assertSame( str_repeat( 'a', 60 ), $ok['prefix'] );

		$too_long = SRL_Settings::sanitize( array( 'prefix' => str_repeat( 'a', 61 ) ) );
		$this->assertSame( '', $too_long['prefix'], 'the previous value must be kept' );

		// Weighted, not raw: 31 emoji weigh 62 and must be rejected.
		$emoji = SRL_Settings::sanitize( array( 'prefix' => str_repeat( "\u{1F600}", 31 ) ) );
		$this->assertSame( '', $emoji['prefix'] );
	}

	/**
	 * T-103
	 *
	 * The form never renders a stored secret, so an empty box is the normal
	 * state of a saved credential. Treating blank as "erase" would wipe the
	 * credentials every time the owner changed the delay.
	 */
	public function test_empty_credential_field_keeps_stored_value(): void {
		SRL_Settings::install_defaults();

		$first = SRL_Settings::sanitize( array( 'api_key' => 'a-real-api-key-value' ) );
		update_option( SRL_Settings::OPTION, $first );
		$stored = $first['api_key'];

		$this->assertNotSame( '', $stored );

		$second = SRL_Settings::sanitize( array( 'api_key' => '' ) );

		$this->assertSame( $stored, $second['api_key'] );
	}

	/** T-101, T-102 */
	public function test_secrets_encrypted_at_rest(): void {
		SRL_Settings::install_defaults();

		$clean = SRL_Settings::sanitize( array( 'api_secret' => 'PlainCanary1234567890' ) );

		$this->assertStringStartsWith( 'srl1:', $clean['api_secret'] );
		$this->assertStringNotContainsString( 'PlainCanary1234567890', $clean['api_secret'] );
		$this->assertSame( 'PlainCanary1234567890', SRL_Settings::crypto()->decrypt( $clean['api_secret'] ) );
	}

	public function test_credentials_state_reports_missing_until_all_four_are_set(): void {
		SRL_Settings::install_defaults();
		$this->assertSame( SRL_Settings::CRED_MISSING, SRL_Settings::credentials_state() );

		$settings = SRL_Settings::all();
		foreach ( SRL_Settings::CREDENTIAL_KEYS as $key ) {
			$settings[ $key ] = SRL_Settings::crypto()->encrypt( 'value-for-' . $key );
		}
		update_option( SRL_Settings::OPTION, $settings );

		$this->assertSame( SRL_Settings::CRED_OK, SRL_Settings::credentials_state() );
		$this->assertInstanceOf( SRL_OAuth1::class, SRL_Settings::signer() );
	}

	/**
	 * T-105
	 *
	 * Simulates salt rotation by storing an envelope encrypted under a
	 * different key.
	 */
	public function test_credentials_unreadable_after_salt_rotation(): void {
		SRL_Settings::install_defaults();

		$other    = SRL_Crypto::from_salt( 'a completely different salt' );
		$settings = SRL_Settings::all();
		foreach ( SRL_Settings::CREDENTIAL_KEYS as $key ) {
			$settings[ $key ] = $other->encrypt( 'value-for-' . $key );
		}
		update_option( SRL_Settings::OPTION, $settings );

		$this->assertSame( SRL_Settings::CRED_UNREADABLE, SRL_Settings::credentials_state() );
		$this->assertNull( SRL_Settings::signer() );
	}

	/** T-106 */
	public function test_credentials_unreadable_refuses_to_schedule(): void {
		SRL_Settings::install_defaults();

		$settings            = SRL_Settings::all();
		$settings['enabled'] = true;
		$other               = SRL_Crypto::from_salt( 'a completely different salt' );
		foreach ( SRL_Settings::CREDENTIAL_KEYS as $key ) {
			$settings[ $key ] = $other->encrypt( 'value' );
		}
		update_option( SRL_Settings::OPTION, $settings );

		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertFalse(
			SRL_Scheduler::has_pending_send( (int) $post_id ),
			'Scheduling a send that is certain to fail hours later helps nobody.'
		);
	}

	/** T-108 */
	public function test_credentials_unreadable_marks_affected_post_failed(): void {
		SRL_Settings::install_defaults();

		$settings            = SRL_Settings::all();
		$settings['enabled'] = true;
		$other               = SRL_Crypto::from_salt( 'a completely different salt' );
		foreach ( SRL_Settings::CREDENTIAL_KEYS as $key ) {
			$settings[ $key ] = $other->encrypt( 'value' );
		}
		update_option( SRL_Settings::OPTION, $settings );

		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( SRL_Post_Meta::STATUS_FAILED, SRL_Post_Meta::get_status( (int) $post_id ) );
		$this->assertSame( 'credentials_unreadable', get_post_meta( (int) $post_id, SRL_Post_Meta::META_LAST_ERROR, true ) );
	}

	/**
	 * T-107
	 *
	 * The message must not say the credentials are invalid. They are not: the
	 * keys in the X Console are fine, and sending the owner to regenerate them
	 * is the exact misdiagnosis this design exists to prevent.
	 */
	public function test_credentials_unreadable_notice_does_not_say_invalid_credentials(): void {
		$post_id = self::factory()->post->create();
		SRL_Post_Meta::fail( (int) $post_id, 'credentials_unreadable' );

		$line = SRL_Post_Meta::status_line( (int) $post_id );

		$this->assertStringContainsString( 'salts', $line );
		$this->assertStringNotContainsString( 'invalid', strtolower( $line ) );
	}

	/** T-601 */
	public function test_no_secret_in_rendered_html(): void {
		SRL_Settings::install_defaults();

		$settings            = SRL_Settings::all();
		$settings['api_key'] = SRL_Settings::crypto()->encrypt( 'SuperSecretCanaryValue' );
		update_option( SRL_Settings::OPTION, $settings );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		srl_plugin()->render_settings_page();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'SuperSecretCanaryValue', $html );
		$this->assertStringContainsString( 'Supe******ue', $html, 'a masked form should be shown instead' );
	}
}
