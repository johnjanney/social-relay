<?php
/**
 * Unit tests for the secret storage envelope.
 *
 * Runs without WordPress. Covers SPEC.md section 6 and ADR-003.
 * Method names match the T-identifiers in SPEC.md section 16.2.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

require_once SRL_PLUGIN_DIR . '/includes/class-crypto.php';

/**
 * Covers encryption, the fingerprint, and the salt-rotation diagnosis.
 */
final class CryptoTest extends TestCase {

	/**
	 * A stand-in for wp_salt( 'auth' ).
	 */
	private const SALT = 'v!2Qm]s#N4*rT^8&wZ0(pL_1yB6+cX3/dG5-hJ7=kM9';

	/** T-104 */
	public function test_envelope_roundtrips_through_encrypt_decrypt(): void {
		$crypto = SRL_Crypto::from_salt( self::SALT );
		$secret = 'AbCdEf1234567890-ZyXwVu';

		$this->assertSame( $secret, $crypto->decrypt( $crypto->encrypt( $secret ) ) );
	}

	/** T-101 */
	public function test_credentials_are_stored_as_srl1_envelope(): void {
		$stored = SRL_Crypto::from_salt( self::SALT )->encrypt( 'secret-value' );

		$this->assertStringStartsWith( 'srl1:', $stored );
		$this->assertSame( SRL_Crypto::STATE_OK, SRL_Crypto::from_salt( self::SALT )->inspect( $stored ) );
	}

	/** T-102 */
	public function test_plaintext_secret_never_appears_in_option_or_html(): void {
		$secret = 'PlainTextCanaryValue12345';
		$stored = SRL_Crypto::from_salt( self::SALT )->encrypt( $secret );

		$this->assertStringNotContainsString( $secret, $stored );
		$this->assertStringNotContainsString( $secret, base64_encode( $stored ) );
	}

	/**
	 * The nonce must be fresh per encryption, or identical secrets produce
	 * identical ciphertext and the database leaks which credentials match.
	 */
	public function test_same_plaintext_encrypts_differently_each_time(): void {
		$crypto = SRL_Crypto::from_salt( self::SALT );

		$this->assertNotSame( $crypto->encrypt( 'same' ), $crypto->encrypt( 'same' ) );
	}

	/**
	 * T-105
	 *
	 * The case ADR-003's mitigation exists for. Rotating wp-config.php salts is
	 * routine incident response; without this the plugin discovers the problem
	 * hours later as an HTTP 401 and tells the owner their credentials are
	 * invalid, sending them to regenerate keys that were fine.
	 */
	public function test_fingerprint_mismatch_enters_credentials_unreadable(): void {
		$stored = SRL_Crypto::from_salt( self::SALT )->encrypt( 'secret' );

		$after_rotation = SRL_Crypto::from_salt( 'a completely different salt after rotation' );

		$this->assertSame( SRL_Crypto::STATE_KEY_MISMATCH, $after_rotation->inspect( $stored ) );
		$this->assertNull( $after_rotation->decrypt( $stored ), 'must not attempt decryption on a key mismatch' );
	}

	/**
	 * A key mismatch and a corrupt value are different diagnoses and must not
	 * be conflated: one is fixed by re-entering keys, the other is not.
	 */
	public function test_corrupt_envelope_is_distinguished_from_key_mismatch(): void {
		$crypto = SRL_Crypto::from_salt( self::SALT );
		$stored = $crypto->encrypt( 'secret' );

		// Flip a byte deep inside the ciphertext, leaving version and
		// fingerprint intact.
		$raw           = base64_decode( substr( $stored, 5 ), true );
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 0xFF );
		$tampered      = 'srl1:' . base64_encode( $raw );

		$this->assertSame( SRL_Crypto::STATE_OK, $crypto->inspect( $tampered ), 'fingerprint still matches' );
		$this->assertNull( $crypto->decrypt( $tampered ), 'authenticated encryption must reject tampering' );
	}

	public function test_absent_and_foreign_values_are_treated_as_absent(): void {
		$crypto = SRL_Crypto::from_salt( self::SALT );

		$this->assertSame( SRL_Crypto::STATE_ABSENT, $crypto->inspect( '' ) );
		// A legacy plaintext value must never be read as a credential.
		$this->assertSame( SRL_Crypto::STATE_ABSENT, $crypto->inspect( 'a-plain-text-api-key' ) );
		$this->assertNull( $crypto->decrypt( 'a-plain-text-api-key' ) );
	}

	public function test_truncated_envelope_is_corrupt_not_ok(): void {
		$crypto = SRL_Crypto::from_salt( self::SALT );

		$this->assertSame( SRL_Crypto::STATE_CORRUPT, $crypto->inspect( 'srl1:' . base64_encode( "\x01abc" ) ) );
		$this->assertSame( SRL_Crypto::STATE_CORRUPT, $crypto->inspect( 'srl1:not valid base64 !!!' ) );
	}

	/**
	 * The fingerprint is domain-separated, so the stored value is not a bare
	 * hash of the key.
	 */
	public function test_fingerprint_is_domain_separated_and_short(): void {
		$crypto = SRL_Crypto::from_salt( self::SALT );
		$key    = sodium_crypto_generichash( self::SALT, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );

		$this->assertSame( 4, strlen( $crypto->fingerprint() ) );
		$this->assertNotSame( substr( sodium_crypto_generichash( $key, '', 32 ), 0, 4 ), $crypto->fingerprint() );
	}

	public function test_key_must_be_exactly_32_bytes(): void {
		$this->expectException( InvalidArgumentException::class );
		new SRL_Crypto( 'too short' );
	}

	/**
	 * The envelope carries a version byte so the format can change later
	 * without stranding stored values. An unknown version must not be decoded.
	 */
	public function test_unknown_format_version_is_rejected(): void {
		$crypto = SRL_Crypto::from_salt( self::SALT );
		$raw    = base64_decode( substr( $crypto->encrypt( 'secret' ), 5 ), true );
		$raw[0] = chr( 99 );

		$this->assertSame( SRL_Crypto::STATE_CORRUPT, $crypto->inspect( 'srl1:' . base64_encode( $raw ) ) );
	}
}
