<?php
/**
 * Secret storage envelope.
 *
 * Implements SPEC.md section 6 and ADR-003, including the salt-rotation
 * mitigation accepted on 2026-09-05.
 *
 * The key is injected rather than fetched, so this class has no WordPress
 * dependency and the unit suite can exercise it on a bare PHP install. That
 * matters here more than usual: a bug in this file is invisible until a
 * credential cannot be read, hours later, inside a cron event.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts and decrypts the four X credentials.
 */
class SRL_Crypto {

	/**
	 * Envelope prefix. A stored value not starting with this is treated as absent.
	 */
	public const PREFIX = 'srl1:';

	/**
	 * Envelope format version.
	 */
	public const FORMAT_VERSION = 1;

	/**
	 * Bytes of key fingerprint stored in the clear.
	 */
	public const FINGERPRINT_BYTES = 4;

	/**
	 * Personalisation string for the fingerprint hash.
	 *
	 * Domain separation, so the stored value is not a bare hash of the key.
	 */
	public const FINGERPRINT_CONTEXT = 'srl-fingerprint';

	/**
	 * The 32-byte secretbox key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Constructor.
	 *
	 * @param string $key Raw 32-byte key. Use from_salt() to derive one.
	 * @throws InvalidArgumentException When the key is the wrong length.
	 */
	public function __construct( string $key ) {
		if ( SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
			throw new InvalidArgumentException( 'Key must be exactly 32 bytes.' );
		}
		$this->key = $key;
	}

	/**
	 * Derive an instance from a salt string.
	 *
	 * wp_salt() returns a string of arbitrary length, not a key. It MUST be
	 * hashed to length; truncating or padding it would discard entropy or
	 * invent it.
	 *
	 * @param string $salt Typically wp_salt( 'auth' ).
	 * @return self
	 */
	public static function from_salt( string $salt ): self {
		return new self( sodium_crypto_generichash( $salt, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
	}

	/**
	 * Non-secret check value for the current key.
	 *
	 * This reveals nothing about the credential. It is a 32-bit check value on
	 * the derived key, which does let an attacker holding the database test a
	 * guessed salt cheaply; since wp_salt() is high-entropy that is not a
	 * practical weakening, and it buys an accurate diagnosis of salt rotation,
	 * which is the trade ADR-003 accepts.
	 *
	 * @return string Raw bytes.
	 */
	public function fingerprint(): string {
		// Domain separation by prefixing, not by the second argument:
		// sodium_crypto_generichash()'s second parameter is a KEY and must be
		// 16-64 bytes, so passing a short context string there throws
		// "unsupported key length" rather than personalising the hash.
		return substr(
			sodium_crypto_generichash( self::FINGERPRINT_CONTEXT . $this->key, '', 32 ),
			0,
			self::FINGERPRINT_BYTES
		);
	}

	/**
	 * Encrypt one secret into an envelope.
	 *
	 * @param string $plaintext The secret.
	 * @return string The `srl1:`-prefixed envelope.
	 */
	public function encrypt( string $plaintext ): string {
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $this->key );

		$envelope = chr( self::FORMAT_VERSION ) . $this->fingerprint() . $nonce . $ciphertext;

		return self::PREFIX . base64_encode( $envelope );
	}

	/**
	 * Decrypt an envelope.
	 *
	 * @param string $stored The stored value.
	 * @return string|null The secret, or null when it cannot be read.
	 */
	public function decrypt( string $stored ): ?string {
		if ( self::STATE_OK !== $this->inspect( $stored ) ) {
			return null;
		}

		$envelope = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( ! is_string( $envelope ) ) {
			return null;
		}

		$offset     = 1 + self::FINGERPRINT_BYTES;
		$nonce      = substr( $envelope, $offset, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $envelope, $offset + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $this->key );

		return is_string( $plaintext ) ? $plaintext : null;
	}

	/**
	 * Envelope is absent or not ours.
	 */
	public const STATE_ABSENT = 'absent';

	/**
	 * Envelope is ours and the key matches.
	 */
	public const STATE_OK = 'ok';

	/**
	 * Envelope is ours but the key has changed. Almost always salt rotation.
	 */
	public const STATE_KEY_MISMATCH = 'key_mismatch';

	/**
	 * Envelope is ours, the key matches, but the bytes are damaged.
	 */
	public const STATE_CORRUPT = 'corrupt';

	/**
	 * Classify a stored value without decrypting it.
	 *
	 * The fingerprint is checked before any decryption is attempted, so a
	 * changed salt is reported as a changed salt rather than as a corrupt
	 * value or as invalid credentials. Telling the owner their credentials are
	 * invalid would send them to regenerate keys in the X Console that were
	 * never the problem, which is the precise failure this design exists to
	 * prevent.
	 *
	 * @param string $stored The stored value.
	 * @return string One of the STATE_* constants.
	 */
	public function inspect( string $stored ): string {
		if ( '' === $stored || ! str_starts_with( $stored, self::PREFIX ) ) {
			return self::STATE_ABSENT;
		}

		$envelope = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( ! is_string( $envelope ) ) {
			return self::STATE_CORRUPT;
		}

		$minimum = 1 + self::FINGERPRINT_BYTES + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
		if ( strlen( $envelope ) < $minimum ) {
			return self::STATE_CORRUPT;
		}

		if ( self::FORMAT_VERSION !== ord( $envelope[0] ) ) {
			return self::STATE_CORRUPT;
		}

		$stored_fingerprint = substr( $envelope, 1, self::FINGERPRINT_BYTES );
		if ( ! hash_equals( $this->fingerprint(), $stored_fingerprint ) ) {
			return self::STATE_KEY_MISMATCH;
		}

		return self::STATE_OK;
	}
}
