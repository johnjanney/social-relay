<?php
/**
 * Settings storage, validation and credential handling.
 *
 * Implements SPEC.md section 3 and section 6.3.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, validates and stores the plugin's settings.
 */
class SRL_Settings {

	/**
	 * Option name.
	 */
	public const OPTION = 'srl_settings';

	/**
	 * Settings-array schema version. Distinct from SRL_Log::DB_VERSION, which
	 * versions the log table.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Maximum resolved delay: 72 hours, per FR-1.2.
	 */
	public const MAX_DELAY_SECONDS = 259200;

	/**
	 * Maximum weighted length of the prefix and of the suffix.
	 *
	 * Weighted, not raw characters, so the field is measured in the same units
	 * as the post. Brief FR-1.4 says "60 characters"; this is OPEN-9.
	 */
	public const MAX_AFFIX_WEIGHT = 60;

	/**
	 * The four credential keys.
	 */
	public const CREDENTIAL_KEYS = array( 'api_key', 'api_secret', 'access_token', 'access_token_secret' );

	/**
	 * Credentials are present and readable.
	 */
	public const CRED_OK = 'ok';

	/**
	 * One or more credentials have not been entered.
	 */
	public const CRED_MISSING = 'missing';

	/**
	 * Credentials exist but cannot be decrypted. Almost always salt rotation.
	 */
	public const CRED_UNREADABLE = 'unreadable';

	/**
	 * Defaults for a fresh install.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'schema_version'      => self::SCHEMA_VERSION,
			// Off after install, so an unconfigured plugin can never fire.
			'enabled'             => false,
			'api_key'             => '',
			'api_secret'          => '',
			'access_token'        => '',
			'access_token_secret' => '',
			'delay_value'         => 60,
			'delay_unit'          => 'minutes',
			'prefix'              => '',
			'suffix'              => '',
			'email_on_failure'    => false,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Value when unset.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Write defaults on activation without clobbering existing values.
	 *
	 * @return void
	 */
	public static function install_defaults(): void {
		$existing = get_option( self::OPTION, null );

		if ( ! is_array( $existing ) ) {
			add_option( self::OPTION, self::defaults(), '', true );
			return;
		}

		update_option( self::OPTION, array_merge( self::defaults(), $existing ), true );
	}

	/**
	 * Master switch.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) self::get( 'enabled', false );
	}

	/**
	 * The configured default delay, in seconds.
	 *
	 * @return int
	 */
	public static function delay_seconds(): int {
		return self::resolve_delay( (int) self::get( 'delay_value', 60 ), (string) self::get( 'delay_unit', 'minutes' ) );
	}

	/**
	 * Convert a value and unit to seconds, clamped to the allowed range.
	 *
	 * Validation happens on the resolved seconds, not the raw number, so
	 * 4321 minutes is correctly rejected as over 72 hours rather than silently
	 * accepted because 4321 looks small.
	 *
	 * @param int    $value Raw value.
	 * @param string $unit  'minutes' or 'hours'.
	 * @return int Seconds.
	 */
	public static function resolve_delay( int $value, string $unit ): int {
		$value    = max( 0, $value );
		$multiple = 'hours' === $unit ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS;

		return min( self::MAX_DELAY_SECONDS, $value * $multiple );
	}

	/**
	 * Whether a value and unit are within the allowed range.
	 *
	 * @param int    $value Raw value.
	 * @param string $unit  Unit.
	 * @return bool
	 */
	public static function delay_is_valid( int $value, string $unit ): bool {
		if ( $value < 0 ) {
			return false;
		}
		$multiple = 'hours' === $unit ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS;
		return ( $value * $multiple ) <= self::MAX_DELAY_SECONDS;
	}

	/**
	 * A crypto instance keyed from this site's auth salt.
	 *
	 * @return SRL_Crypto
	 */
	public static function crypto(): SRL_Crypto {
		return SRL_Crypto::from_salt( wp_salt( 'auth' ) );
	}

	/**
	 * Classify the stored credentials.
	 *
	 * @return string One of the CRED_* constants.
	 */
	public static function credentials_state(): string {
		$all    = self::all();
		$crypto = self::crypto();
		$seen   = 0;

		foreach ( self::CREDENTIAL_KEYS as $key ) {
			$stored = (string) ( $all[ $key ] ?? '' );

			if ( '' === $stored ) {
				continue;
			}

			$state = $crypto->inspect( $stored );

			if ( SRL_Crypto::STATE_KEY_MISMATCH === $state || SRL_Crypto::STATE_CORRUPT === $state ) {
				// One unreadable credential makes the whole set unusable, and
				// the diagnosis must name salt rotation rather than blaming
				// the credentials themselves.
				return self::CRED_UNREADABLE;
			}

			if ( SRL_Crypto::STATE_OK === $state ) {
				++$seen;
			}
		}

		return count( self::CREDENTIAL_KEYS ) === $seen ? self::CRED_OK : self::CRED_MISSING;
	}

	/**
	 * A configured signer, or null when the credentials cannot be used.
	 *
	 * @return SRL_OAuth1|null
	 */
	public static function signer(): ?SRL_OAuth1 {
		if ( self::CRED_OK !== self::credentials_state() ) {
			return null;
		}

		$all    = self::all();
		$crypto = self::crypto();
		$values = array();

		foreach ( self::CREDENTIAL_KEYS as $key ) {
			$plain = $crypto->decrypt( (string) $all[ $key ] );
			if ( null === $plain ) {
				return null;
			}
			$values[] = $plain;
		}

		return new SRL_OAuth1( $values[0], $values[1], $values[2], $values[3] );
	}

	/**
	 * Sanitize a submitted settings array.
	 *
	 * @param mixed $input Raw submission.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$existing = self::all();
		$clean    = $existing;
		$input    = is_array( $input ) ? $input : array();

		$clean['schema_version']   = self::SCHEMA_VERSION;
		$clean['enabled']          = ! empty( $input['enabled'] );
		$clean['email_on_failure'] = ! empty( $input['email_on_failure'] );

		$unit  = isset( $input['delay_unit'] ) && 'hours' === $input['delay_unit'] ? 'hours' : 'minutes';
		$value = isset( $input['delay_value'] ) ? absint( $input['delay_value'] ) : 60;

		if ( self::delay_is_valid( $value, $unit ) ) {
			$clean['delay_value'] = $value;
			$clean['delay_unit']  = $unit;
		} else {
			add_settings_error(
				self::OPTION,
				'srl_delay_range',
				__( 'The delay must be between 0 and 72 hours. The previous value was kept.', 'social-relay' )
			);
		}

		foreach ( array( 'prefix', 'suffix' ) as $affix ) {
			$text = isset( $input[ $affix ] ) ? sanitize_text_field( wp_unslash( (string) $input[ $affix ] ) ) : '';

			if ( SRL_Text::weighted_length( $text ) > self::MAX_AFFIX_WEIGHT ) {
				add_settings_error(
					self::OPTION,
					'srl_affix_length',
					sprintf(
						/* translators: 1: field name, 2: maximum length */
						__( 'The %1$s is longer than %2$d characters as X counts them. The previous value was kept.', 'social-relay' ),
						$affix,
						self::MAX_AFFIX_WEIGHT
					)
				);
				continue;
			}

			$clean[ $affix ] = $text;
		}

		foreach ( self::CREDENTIAL_KEYS as $key ) {
			$submitted = isset( $input[ $key ] ) ? trim( (string) wp_unslash( $input[ $key ] ) ) : '';

			// An empty field means "keep what is stored", never "erase it".
			// The form never renders the real secret, so an empty box is the
			// normal state of a saved credential.
			if ( '' === $submitted ) {
				continue;
			}

			$clean[ $key ] = self::crypto()->encrypt( $submitted );
		}

		return $clean;
	}

	/**
	 * Mask a stored credential for display.
	 *
	 * A stored secret is never rendered into an input value; this is what the
	 * settings page shows instead.
	 *
	 * @param string $key Credential key.
	 * @return string
	 */
	public static function masked( string $key ): string {
		$stored = (string) self::get( $key, '' );

		if ( '' === $stored ) {
			return __( 'not set', 'social-relay' );
		}

		if ( SRL_Crypto::STATE_OK !== self::crypto()->inspect( $stored ) ) {
			return __( 'stored, but unreadable', 'social-relay' );
		}

		$plain = self::crypto()->decrypt( $stored );
		if ( null === $plain || '' === $plain ) {
			return __( 'stored, but unreadable', 'social-relay' );
		}

		$length = strlen( $plain );
		if ( $length <= 8 ) {
			return str_repeat( '*', $length );
		}

		return substr( $plain, 0, 4 ) . str_repeat( '*', 6 ) . substr( $plain, -2 );
	}

	/**
	 * Remove all settings. Used by uninstall.php.
	 *
	 * @return void
	 */
	public static function delete_all(): void {
		delete_option( self::OPTION );
	}
}
