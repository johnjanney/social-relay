<?php
/**
 * OAuth 1.0a HMAC-SHA1 request signing.
 *
 * Implements SPEC.md section 8.1 and ADR-001. Deliberately a separate class
 * with no WordPress dependency, for two reasons: it is the piece where a subtle
 * bug is both most likely and least visible, and keeping it WordPress-free lets
 * the unit suite verify it against the RFC 5849 test vector on a bare PHP
 * install, with no Docker and no database.
 *
 * About 80 lines of signing rather than a library, per PROJECTBRIEF section 5.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signs requests with OAuth 1.0a user context.
 */
class SRL_OAuth1 {

	/**
	 * Consumer key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Consumer secret.
	 *
	 * @var string
	 */
	private string $api_secret;

	/**
	 * User access token.
	 *
	 * @var string
	 */
	private string $access_token;

	/**
	 * User access token secret.
	 *
	 * @var string
	 */
	private string $access_token_secret;

	/**
	 * Constructor.
	 *
	 * @param string $api_key             Consumer key.
	 * @param string $api_secret          Consumer secret.
	 * @param string $access_token        Access token.
	 * @param string $access_token_secret Access token secret.
	 */
	public function __construct(
		string $api_key,
		string $api_secret,
		string $access_token,
		string $access_token_secret
	) {
		$this->api_key             = $api_key;
		$this->api_secret          = $api_secret;
		$this->access_token        = $access_token;
		$this->access_token_secret = $access_token_secret;
	}

	/**
	 * RFC 3986 percent-encoding, which is what OAuth 1.0a requires.
	 *
	 * Note that rawurlencode() is already RFC 3986: it leaves A-Z a-z 0-9 - . _ ~
	 * unreserved and encodes everything else. urlencode() is NOT correct here,
	 * because it encodes a space as '+'. That single substitution produces a
	 * valid-looking request that fails with a bare 401 and no explanation.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function encode( string $value ): string {
		return rawurlencode( $value );
	}

	/**
	 * Build the signature base string.
	 *
	 * @param string                $method       Uppercase HTTP method.
	 * @param string                $base_url     URL without query string or fragment.
	 * @param array<string, string> $query_params Query-string parameters.
	 * @param array<string, string> $oauth_params The oauth_* parameters.
	 * @param array<string, string> $body_params  Body parameters. Include these ONLY
	 *                                            when the body is
	 *                                            application/x-www-form-urlencoded.
	 *                                            For JSON and multipart bodies the body
	 *                                            is not signed. This is the single most
	 *                                            common OAuth 1.0a bug, and this plugin
	 *                                            never sends a form-encoded body, so
	 *                                            this argument is always empty in
	 *                                            production and exists for the RFC test
	 *                                            vector.
	 * @return string
	 */
	public static function base_string(
		string $method,
		string $base_url,
		array $query_params,
		array $oauth_params,
		array $body_params = array()
	): string {
		$all = array_merge( $query_params, $oauth_params, $body_params );

		$pairs = array();
		foreach ( $all as $key => $value ) {
			$pairs[] = array( self::encode( (string) $key ), self::encode( (string) $value ) );
		}

		// Sort by encoded key, then by encoded value. Sorting before encoding
		// gives a different order for some inputs and produces a wrong signature.
		usort(
			$pairs,
			static function ( array $a, array $b ): int {
				return $a[0] === $b[0] ? strcmp( $a[1], $b[1] ) : strcmp( $a[0], $b[0] );
			}
		);

		$normalized = implode(
			'&',
			array_map(
				static function ( array $pair ): string {
					return $pair[0] . '=' . $pair[1];
				},
				$pairs
			)
		);

		return strtoupper( $method )
			. '&' . self::encode( $base_url )
			. '&' . self::encode( $normalized );
	}

	/**
	 * Produce the Authorization header value for one request.
	 *
	 * @param string      $method    HTTP method.
	 * @param string      $url       Full URL, query string included.
	 * @param string|null $nonce     Override the nonce. Tests only.
	 * @param int|null    $timestamp Override the timestamp. Tests only.
	 * @return string
	 * @throws InvalidArgumentException When the URL cannot be parsed.
	 */
	public function authorization_header( string $method, string $url, ?string $nonce = null, ?int $timestamp = null ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This class is deliberately WordPress-free so the unit suite can verify the signer without loading WordPress; wp_parse_url() would break that.
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) ) {
			// parse_url returns false only for a seriously malformed URL. The
			// caller always passes a constant-derived URL, so this is a
			// programming error rather than user input, but returning an
			// unsigned header would produce a confusing 401 instead of a clear
			// failure.
			throw new InvalidArgumentException( 'Cannot sign a malformed URL.' );
		}

		$scheme   = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host     = isset( $parts['host'] ) ? $parts['host'] : '';
		$path     = isset( $parts['path'] ) ? $parts['path'] : '';
		$base_url = $scheme . '://' . $host . $path;

		$query_params = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query_params );
		}

		$oauth_params = array(
			'oauth_consumer_key'     => $this->api_key,
			'oauth_nonce'            => null !== $nonce ? $nonce : bin2hex( random_bytes( 16 ) ),
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp'        => (string) ( null !== $timestamp ? $timestamp : time() ),
			'oauth_token'            => $this->access_token,
			'oauth_version'          => '1.0',
		);

		$base = self::base_string( $method, $base_url, $query_params, $oauth_params );

		$signing_key = self::encode( $this->api_secret ) . '&' . self::encode( $this->access_token_secret );
		$signature   = base64_encode( hash_hmac( 'sha1', $base, $signing_key, true ) );

		$oauth_params['oauth_signature'] = $signature;
		ksort( $oauth_params );

		$pairs = array();
		foreach ( $oauth_params as $key => $value ) {
			$pairs[] = self::encode( (string) $key ) . '="' . self::encode( (string) $value ) . '"';
		}

		return 'OAuth ' . implode( ', ', $pairs );
	}

	/**
	 * Whether all four credentials are present.
	 *
	 * Checked before any request is attempted, so a half-configured plugin
	 * fails on the settings page rather than with a 401 hours later.
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return '' !== $this->api_key
			&& '' !== $this->api_secret
			&& '' !== $this->access_token
			&& '' !== $this->access_token_secret;
	}
}
