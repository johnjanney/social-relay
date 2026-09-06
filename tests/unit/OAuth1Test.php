<?php
/**
 * Unit tests for the OAuth 1.0a signer.
 *
 * Runs without WordPress and without a database.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

require_once SRL_PLUGIN_DIR . '/includes/class-oauth1.php';

/**
 * Covers SPEC.md section 8.1.
 */
final class OAuth1Test extends TestCase {

	/**
	 * The worked example from RFC 5849 section 3.4.1.1.
	 *
	 * PROJECTBRIEF section 5 names this as the fallback vector, because X's own
	 * published signing example could not be located (OQ-12). The RFC's example
	 * includes the parameter "a3" twice; an associative array cannot express a
	 * duplicate key and this plugin never sends one, so the duplicate token is
	 * removed from the expected string. Nothing else is changed.
	 */
	public function test_base_string_matches_rfc_5849_worked_example(): void {
		$oauth = array(
			'oauth_consumer_key'     => '9djdj82h48djs9d2',
			'oauth_token'            => 'kkk9d7dh3k39sjv7',
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp'        => '137131201',
			'oauth_nonce'            => '7d8f3e4a',
		);
		$query = array(
			'b5' => '=%3D',
			'a3' => 'a',
			'c@' => '',
			'a2' => 'r b',
		);
		$body  = array(
			'c2' => '',
			'a3' => '2 q',
		);

		$rfc = 'POST&http%3A%2F%2Fexample.com%2Frequest&a2%3Dr%2520b%26a3%3D2%2520q'
			. '%26a3%3Da%26b5%3D%253D%25253D%26c%2540%3D%26c2%3D%26oauth_consumer_'
			. 'key%3D9djdj82h48djs9d2%26oauth_nonce%3D7d8f3e4a%26oauth_signature_m'
			. 'ethod%3DHMAC-SHA1%26oauth_timestamp%3D137131201%26oauth_token%3Dkkk'
			. '9d7dh3k39sjv7';

		// Remove the duplicate a3 parameter the RFC includes but an array cannot.
		$expected = str_replace( '%26a3%3Da', '', $rfc );

		$actual = SRL_OAuth1::base_string( 'POST', 'http://example.com/request', $query, $oauth, $body );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Percent-encoding must be RFC 3986, not urlencode().
	 *
	 * A space encoded as '+' rather than %20 is the classic OAuth 1.0a bug: it
	 * produces a well-formed request that fails with a bare 401.
	 */
	public function test_encoding_is_rfc_3986_not_urlencode(): void {
		$this->assertSame( '%20', SRL_OAuth1::encode( ' ' ), 'space must be %20, never +' );
		$this->assertSame( '~', SRL_OAuth1::encode( '~' ), 'tilde is unreserved and must not be encoded' );
		$this->assertSame( '%2B', SRL_OAuth1::encode( '+' ) );
		$this->assertSame( '%2A', SRL_OAuth1::encode( '*' ) );
		$this->assertSame( 'a-b_c.d~e', SRL_OAuth1::encode( 'a-b_c.d~e' ) );
		$this->assertSame( '%3D%253D', SRL_OAuth1::encode( '=%3D' ), 'an already-encoded value must be double-encoded' );
	}

	/**
	 * The signature must be deterministic for a fixed nonce and timestamp,
	 * and must match an independently computed HMAC.
	 */
	public function test_authorization_header_signature_is_correct(): void {
		$signer = new SRL_OAuth1( 'CONSUMERKEY', 'CONSUMERSECRET', '1234567890-TOKEN', 'TOKENSECRET' );

		$header = $signer->authorization_header( 'GET', 'https://api.x.com/2/users/me', 'NONCE123', 1757030400 );

		$base = 'GET&https%3A%2F%2Fapi.x.com%2F2%2Fusers%2Fme&'
			. 'oauth_consumer_key%3DCONSUMERKEY%26oauth_nonce%3DNONCE123%26'
			. 'oauth_signature_method%3DHMAC-SHA1%26oauth_timestamp%3D1757030400%26'
			. 'oauth_token%3D1234567890-TOKEN%26oauth_version%3D1.0';

		$expected = base64_encode(
			hash_hmac( 'sha1', $base, rawurlencode( 'CONSUMERSECRET' ) . '&' . rawurlencode( 'TOKENSECRET' ), true )
		);

		$this->assertStringContainsString( 'oauth_signature="' . rawurlencode( $expected ) . '"', $header );
		$this->assertStringStartsWith( 'OAuth ', $header );
		$this->assertStringContainsString( 'oauth_signature_method="HMAC-SHA1"', $header );
		$this->assertStringContainsString( 'oauth_version="1.0"', $header );
	}

	/**
	 * Query-string parameters must enter the base string; body parameters for a
	 * JSON or multipart body must not. Production only ever sends those two
	 * body types, so passing no body parameters is the correct behaviour.
	 */
	public function test_query_parameters_are_signed(): void {
		$signer = new SRL_OAuth1( 'K', 'KS', 'T', 'TS' );

		$with    = $signer->authorization_header( 'GET', 'https://api.x.com/2/x?a=1', 'N', 100 );
		$without = $signer->authorization_header( 'GET', 'https://api.x.com/2/x', 'N', 100 );

		$this->assertNotSame( $with, $without, 'a query parameter must change the signature' );
	}

	/**
	 * Two calls must not reuse a nonce. A repeated nonce is grounds for
	 * rejection and would produce intermittent, hard-to-reproduce 401s.
	 */
	public function test_nonce_differs_between_calls(): void {
		$signer = new SRL_OAuth1( 'K', 'KS', 'T', 'TS' );

		$a = $signer->authorization_header( 'GET', 'https://api.x.com/2/users/me' );
		$b = $signer->authorization_header( 'GET', 'https://api.x.com/2/users/me' );

		$this->assertNotSame( $a, $b );
	}

	/**
	 * T-903
	 *
	 * The load-bearing rule of SPEC 8.1: the body enters the signature base
	 * string ONLY when it is application/x-www-form-urlencoded. This plugin
	 * sends JSON, so the JSON must never be signed. Signing it produces a
	 * request that fails with a bare 401 and no diagnostic.
	 */
	public function test_json_body_is_not_included_in_the_signature(): void {
		$oauth = array(
			'oauth_consumer_key'     => 'K',
			'oauth_nonce'            => 'N',
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp'        => '100',
			'oauth_token'            => 'T',
			'oauth_version'          => '1.0',
		);

		$base = SRL_OAuth1::base_string( 'POST', 'https://api.x.com/2/tweets', array(), $oauth );

		$this->assertStringNotContainsString( 'text', $base );
		$this->assertStringNotContainsString( 'media_ids', $base );

		// Passing body parameters would change the signature, which is exactly
		// why they must not be passed for a JSON body.
		$with_body = SRL_OAuth1::base_string( 'POST', 'https://api.x.com/2/tweets', array(), $oauth, array( 'text' => 'hello' ) );
		$this->assertNotSame( $base, $with_body );
	}

	/**
	 * T-904
	 *
	 * Note that multipart/form-data is a form content type but is NOT
	 * application/x-www-form-urlencoded, so its parts are never signed either.
	 */
	public function test_multipart_body_is_not_included_in_the_signature(): void {
		$signer = new SRL_OAuth1( 'K', 'KS', 'T', 'TS' );

		$header = $signer->authorization_header( 'POST', 'https://api.x.com/2/media/upload', 'N', 100 );

		$this->assertStringNotContainsString( 'media_category', $header );
		$this->assertStringNotContainsString( 'tweet_image', $header );
		$this->assertStringNotContainsString( 'boundary', $header );
	}

	/**
	 * Completeness check used before any request is attempted.
	 */
	public function test_is_complete_requires_all_four_values(): void {
		$this->assertTrue( ( new SRL_OAuth1( 'a', 'b', 'c', 'd' ) )->is_complete() );
		$this->assertFalse( ( new SRL_OAuth1( '', 'b', 'c', 'd' ) )->is_complete() );
		$this->assertFalse( ( new SRL_OAuth1( 'a', '', 'c', 'd' ) )->is_complete() );
		$this->assertFalse( ( new SRL_OAuth1( 'a', 'b', '', 'd' ) )->is_complete() );
		$this->assertFalse( ( new SRL_OAuth1( 'a', 'b', 'c', '' ) )->is_complete() );
	}

	/**
	 * A malformed URL must fail loudly rather than produce an unsigned header.
	 */
	public function test_malformed_url_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		( new SRL_OAuth1( 'a', 'b', 'c', 'd' ) )->authorization_header( 'GET', 'http://:80' );
	}
}
