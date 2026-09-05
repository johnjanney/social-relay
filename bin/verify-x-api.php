#!/usr/bin/env php
<?php
/**
 * verify-x-api.php — Phase 0 discovery probe for OPENQUESTIONS.md OQ-2.
 *
 * Primary question (OQ-2, BLOCKING):
 *     Does POST /2/media/upload accept OAuth 1.0a user context?
 *
 * The official reference (https://docs.x.com/x-api/media/media-upload-initialize,
 * read 2026-09-05) lists the endpoint's authorization schemes as
 * `OAuth2UserToken: [media.write]` and `UserToken: []`, where `UserToken` is
 * OAuth 1.0a User Context. Developer-forum thread titles suggest OAuth 1.0a is
 * rejected with 403 in practice; those threads could not be read
 * (devcommunity.x.com returned HTTP 403). Documentation alone cannot settle a
 * question about whether the documentation is accurate. Hence a real call.
 *
 * This script also answers, from the same run:
 *     OQ-13  Is api.x.com the correct (and only needed) host for v2 upload?
 *     OQ-14  Does a one-shot POST /2/media/upload exist, or is chunked the only path?
 *     OQ-12  Is the OAuth 1.0a signer correct? (proved end-to-end, see below)
 *
 * It deliberately does NOT create a post. Creating a post costs money
 * ($0.015, or $0.200 with a URL) and publishes publicly. OQ-15 (/2/tweets vs
 * /2/posts) is therefore left to Phase 6 acceptance.
 *
 * WHY STEP 1 EXISTS
 * -----------------
 * A bare 401/403 from the media endpoint is ambiguous: it could mean "OAuth 1.0a
 * is not accepted here" or "your signer is broken" or "your App is not in a
 * Project". Step 1 signs a request to an endpoint that is known to accept
 * OAuth 1.0a. If step 1 succeeds and the media steps fail, the signer and the
 * credentials are exonerated and the failure is the endpoint's. If step 1 fails,
 * nothing after it is interpretable. No hard-coded signature vector is embedded,
 * because OQ-12 records that I could not confirm X's published signing example
 * is still online, and a fabricated vector is worse than none.
 *
 * REQUIREMENTS
 *     PHP 8.1+ with the curl and hash extensions. No WordPress. No Composer.
 *
 *     The plugin's own floor is PHP 8.2 (OQ-5, decided 2026-09-05). This probe
 *     deliberately keeps a lower floor of its own: it is a throwaway diagnostic
 *     meant to run wherever it is convenient, including a laptop or a shell that
 *     is behind the production runtime. It is not plugin code and ships with
 *     nothing.
 *
 * CREDENTIALS — from the environment only. Nothing is read from a file and
 * nothing is written to disk.
 *
 *     export X_API_KEY='...'              # Consumer key / API Key
 *     export X_API_SECRET='...'           # Consumer secret / API Key Secret
 *     export X_ACCESS_TOKEN='...'         # Access Token
 *     export X_ACCESS_TOKEN_SECRET='...'  # Access Token Secret
 *
 * The App must sit inside a Project and have Read and Write permissions.
 * If you changed permissions after generating the tokens, regenerate the
 * tokens — permission level is baked in at generation time.
 *
 * USAGE
 *     php bin/verify-x-api.php --dry-run   # print the plan and signature base
 *                                          # strings, make no network calls
 *     php bin/verify-x-api.php             # make the calls
 *
 * COST — step 1 is a user read, listed at about $0.010 on
 * https://docs.x.com/x-api/getting-started/pricing (read 2026-09-05). Media
 * upload pricing is not listed on that page at all; that unknown is OQ-1b and
 * is part of what this run measures. Expect a few cents, not dollars. Check the
 * Developer Console usage report after running — that reading is itself useful
 * data for OQ-1b.
 *
 * SECURITY — secrets are never printed. Every value echoed from the
 * environment is masked. Response bodies are printed raw, because the raw
 * response is the artifact being collected; X does not echo credentials back.
 *
 * OUTPUT — everything printed is intended to be pasted back verbatim.
 */

declare( strict_types = 1 );

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script runs on the command line only.\n" );
	exit( 2 );
}

const API_HOST     = 'https://api.x.com';
const USER_AGENT   = 'social-relay-phase0-probe/0.1';
const HTTP_TIMEOUT = 45;

$dry_run = in_array( '--dry-run', $argv, true );

/* ------------------------------------------------------------------ */
/* Output helpers                                                      */
/* ------------------------------------------------------------------ */

function out( string $line = '' ): void {
	fwrite( STDOUT, $line . "\n" );
}

function rule( string $title ): void {
	out( '' );
	out( str_repeat( '=', 72 ) );
	out( $title );
	out( str_repeat( '=', 72 ) );
}

/** Mask a secret so its presence and shape are visible but its value is not. */
function mask( string $secret ): string {
	$len = strlen( $secret );
	if ( $len === 0 ) {
		return '(empty)';
	}
	if ( $len <= 8 ) {
		return str_repeat( '*', $len ) . " (len={$len})";
	}
	return substr( $secret, 0, 4 ) . str_repeat( '*', 6 ) . substr( $secret, -2 ) . " (len={$len})";
}

/* ------------------------------------------------------------------ */
/* OAuth 1.0a HMAC-SHA1 signing                                        */
/*                                                                     */
/* This is the same algorithm class-x-provider.php will implement, kept */
/* standalone here so the Phase 0 answer does not depend on plugin code */
/* that does not exist yet.                                            */
/* ------------------------------------------------------------------ */

/**
 * RFC 3986 percent-encoding, which is what OAuth 1.0a requires.
 *
 * PHP's rawurlencode() is already RFC 3986: it leaves A-Z a-z 0-9 - . _ ~
 * unreserved and encodes everything else. urlencode() is NOT correct here —
 * it encodes a space as '+'. Getting this wrong produces a valid-looking
 * request that fails with a bare 401 and no explanation.
 */
function oauth_encode( string $value ): string {
	return rawurlencode( $value );
}

/**
 * Build the signature base string.
 *
 * @param string               $method       Uppercase HTTP method.
 * @param string               $base_url     URL with no query string and no fragment.
 * @param array<string,string> $query_params Parameters from the query string.
 * @param array<string,string> $oauth_params The oauth_* parameters.
 * @param array<string,string> $body_params  Body parameters. Include these ONLY when
 *                                           the request body is
 *                                           application/x-www-form-urlencoded.
 *                                           For a JSON or multipart body the body is
 *                                           NOT part of the signature. This distinction
 *                                           is the single most common OAuth 1.0a bug.
 */
function signature_base_string(
	string $method,
	string $base_url,
	array $query_params,
	array $oauth_params,
	array $body_params = array()
): string {
	$all = array_merge( $query_params, $oauth_params, $body_params );

	// Encode every key and value, then sort by encoded key, then by encoded value.
	$pairs = array();
	foreach ( $all as $key => $value ) {
		$pairs[] = array( oauth_encode( (string) $key ), oauth_encode( (string) $value ) );
	}
	usort(
		$pairs,
		static function ( array $a, array $b ): int {
			return $a[0] === $b[0] ? strcmp( $a[1], $b[1] ) : strcmp( $a[0], $b[0] );
		}
	);

	$normalized = implode(
		'&',
		array_map(
			static function ( array $p ): string {
				return $p[0] . '=' . $p[1];
			},
			$pairs
		)
	);

	return strtoupper( $method )
		. '&' . oauth_encode( $base_url )
		. '&' . oauth_encode( $normalized );
}

/**
 * Sign a request and return both the Authorization header and the base string.
 *
 * The base string is returned so it can be printed under --dry-run and
 * inspected by eye. It contains no secret: the secrets are only ever used as
 * the HMAC key, never as signed content.
 *
 * @return array{header:string, base_string:string}
 */
function oauth_header(
	string $method,
	string $url,
	array $body_params = array(),
	?string $forced_nonce = null,
	?int $forced_timestamp = null
): array {
	$creds = credentials();

	$parts    = parse_url( $url );
	$base_url = $parts['scheme'] . '://' . $parts['host'] . ( $parts['path'] ?? '' );

	$query_params = array();
	if ( ! empty( $parts['query'] ) ) {
		parse_str( $parts['query'], $query_params );
	}

	$oauth_params = array(
		'oauth_consumer_key'     => $creds['api_key'],
		'oauth_nonce'            => $forced_nonce ?? bin2hex( random_bytes( 16 ) ),
		'oauth_signature_method' => 'HMAC-SHA1',
		'oauth_timestamp'        => (string) ( $forced_timestamp ?? time() ),
		'oauth_token'            => $creds['access_token'],
		'oauth_version'          => '1.0',
	);

	$base_string = signature_base_string( $method, $base_url, $query_params, $oauth_params, $body_params );

	$signing_key = oauth_encode( $creds['api_secret'] ) . '&' . oauth_encode( $creds['access_token_secret'] );
	$signature   = base64_encode( hash_hmac( 'sha1', $base_string, $signing_key, true ) );

	$oauth_params['oauth_signature'] = $signature;
	ksort( $oauth_params );

	$header_pairs = array();
	foreach ( $oauth_params as $key => $value ) {
		$header_pairs[] = oauth_encode( (string) $key ) . '="' . oauth_encode( (string) $value ) . '"';
	}

	return array(
		'header'      => 'OAuth ' . implode( ', ', $header_pairs ),
		'base_string' => $base_string,
	);
}

/* ------------------------------------------------------------------ */
/* Credentials                                                         */
/* ------------------------------------------------------------------ */

/**
 * Reject a value that cannot possibly be a real credential.
 *
 * This exists because the first live run of this script was made with all four
 * values set to the literal string "..." — the placeholder from the pasted
 * instructions. Every call failed with 401, which looked exactly like a signing
 * bug or a permissions problem and was neither. A diagnostic that can be
 * misread that badly is not a diagnostic. Catch it here, before the network.
 *
 * @return string|null Reason the value is rejected, or null if it looks usable.
 */
function reject_reason( string $value ): ?string {
	$lower = strtolower( $value );

	if ( preg_match( '/\s/', $value ) ) {
		return 'it contains a space, tab or newline';
	}
	if ( preg_match( '/^[.\-_*x]+$/', $lower ) ) {
		return 'it is only punctuation or x characters, so it is a placeholder';
	}
	foreach ( array( 'paste', 'your_', 'your-', 'yourkey', 'placeholder', 'here', 'changeme', 'todo', 'xxxx' ) as $needle ) {
		if ( str_contains( $lower, $needle ) ) {
			return "it contains \"{$needle}\", so it is placeholder text";
		}
	}
	if ( strlen( $value ) < 15 ) {
		return 'it is only ' . strlen( $value ) . ' characters; real X credentials are far longer';
	}
	return null;
}

/**
 * Ask for one credential on the terminal, hiding the typed characters if the
 * platform allows it. Returns an empty string when there is no terminal to ask.
 */
function prompt_for( string $env_name ): string {
	if ( ! function_exists( 'stream_isatty' ) || ! @stream_isatty( STDIN ) ) {
		return '';
	}

	$hidden = false;
	if ( DIRECTORY_SEPARATOR === '/' && @is_readable( '/dev/tty' ) ) {
		// Suppress echo so the pasted secret does not stay on screen or in a
		// scrollback buffer that later gets pasted into a chat window.
		@shell_exec( 'stty -echo 2>/dev/null' );
		$hidden = ( trim( (string) @shell_exec( 'stty -a 2>/dev/null | grep -o "\-echo" | head -1' ) ) === '-echo' );
	}

	fwrite( STDOUT, "  {$env_name}: " );
	$value = fgets( STDIN );

	if ( $hidden ) {
		@shell_exec( 'stty echo 2>/dev/null' );
		fwrite( STDOUT, "\n" );
	}

	return trim( (string) $value );
}

/** @return array{api_key:string,api_secret:string,access_token:string,access_token_secret:string} */
function credentials(): array {
	static $cache = null;
	if ( $cache !== null ) {
		return $cache;
	}

	$map = array(
		'api_key'             => 'X_API_KEY',
		'api_secret'          => 'X_API_SECRET',
		'access_token'        => 'X_ACCESS_TOKEN',
		'access_token_secret' => 'X_ACCESS_TOKEN_SECRET',
	);

	$creds    = array();
	$problems = array();

	foreach ( $map as $field => $env ) {
		$value = getenv( $env );
		// Trailing newlines from `export X=$(cat file)` are a real and very
		// confusing failure: they change the HMAC key and produce a bare 401.
		$value = $value === false ? '' : trim( (string) $value );

		$reason = $value === '' ? 'it is not set' : reject_reason( $value );

		if ( $reason !== null ) {
			$typed = prompt_needed( $env, $reason );
			if ( $typed !== null ) {
				$value  = $typed;
				$reason = reject_reason( $value );
			}
		}

		if ( $reason !== null ) {
			$problems[ $env ] = $reason;
			continue;
		}

		$creds[ $field ] = $value;
	}

	if ( $problems ) {
		out( '' );
		out( 'STOPPED before making any request. No credits were spent.' );
		out( '' );
		out( 'These values cannot be real credentials:' );
		foreach ( $problems as $env => $reason ) {
			out( "  {$env} — {$reason}" );
		}
		out( '' );
		out( 'Get the four values from console.x.com, in the Keys and tokens tab of' );
		out( 'your App. You need exactly these four, and none of the others:' );
		out( '' );
		out( '  X_API_KEY              = API Key          (also called Consumer Key)' );
		out( '  X_API_SECRET           = API Key Secret   (also called Consumer Secret)' );
		out( '  X_ACCESS_TOKEN         = Access Token' );
		out( '  X_ACCESS_TOKEN_SECRET  = Access Token Secret' );
		out( '' );
		out( 'The Bearer Token, Client ID and Client Secret are NOT used here.' );
		out( '' );
		out( 'Then either export them and re-run, replacing every placeholder with a' );
		out( 'real value, or just re-run with none of them set and type them in when' );
		out( 'this script asks.' );
		exit( 2 );
	}

	$cache = $creds;
	return $cache;
}

/**
 * Explain why a value was refused, then offer to take it from the keyboard.
 *
 * @return string|null The typed value, or null when there is no terminal.
 */
function prompt_needed( string $env, string $reason ): ?string {
	static $explained = false;

	if ( ! function_exists( 'stream_isatty' ) || ! @stream_isatty( STDIN ) ) {
		return null;
	}

	if ( ! $explained ) {
		out( '' );
		out( 'One or more credentials are missing or are still placeholders.' );
		out( 'Type or paste each one below. Input is hidden where the terminal allows it.' );
		out( 'Nothing is written to disk and nothing enters your shell history.' );
		out( '' );
		$explained = true;
	}

	out( "  {$env} was rejected because {$reason}." );
	$typed = prompt_for( $env );

	return $typed === '' ? null : $typed;
}

/* ------------------------------------------------------------------ */
/* HTTP                                                                */
/* ------------------------------------------------------------------ */

/**
 * Perform one request and print the whole exchange.
 *
 * @return array{status:int,headers:string,body:string,error:string}
 */
function request( string $label, string $method, string $url, string $auth_header, array $extra_headers = array(), ?string $body = null ): array {
	out( '' );
	out( "--- {$label} ---" );
	out( "{$method} {$url}" );
	foreach ( $extra_headers as $h ) {
		out( '> ' . $h );
	}
	out( '> Authorization: OAuth <signed, redacted>' );
	if ( $body !== null ) {
		$preview = strlen( $body ) > 300 ? substr( $body, 0, 300 ) . '... [' . strlen( $body ) . ' bytes total]' : $body;
		out( '> body: ' . str_replace( array( "\r", "\n" ), array( '\r', '\n' ), $preview ) );
	}

	$headers = array_merge( array( 'Authorization: ' . $auth_header ), $extra_headers );

	$ch = curl_init();
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_URL            => $url,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_USERAGENT      => USER_AGENT,
			CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		)
	);
	if ( $body !== null ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
	}

	$raw   = curl_exec( $ch );
	$errno = curl_errno( $ch );
	$error = $errno ? curl_error( $ch ) : '';

	if ( $raw === false ) {
		curl_close( $ch );
		out( "TRANSPORT ERROR: {$error} (curl errno {$errno})" );
		return array(
			'status'  => 0,
			'headers' => '',
			'body'    => '',
			'error'   => $error,
		);
	}

	$status      = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$header_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	curl_close( $ch );

	$resp_headers = substr( (string) $raw, 0, $header_size );
	$resp_body    = substr( (string) $raw, $header_size );

	out( '' );
	out( "HTTP {$status}" );
	out( 'Response headers:' );
	foreach ( preg_split( '/\r?\n/', trim( $resp_headers ) ) as $line ) {
		if ( trim( $line ) !== '' ) {
			out( '  ' . $line );
		}
	}
	out( 'Raw response body:' );
	out( $resp_body === '' ? '  (empty)' : $resp_body );

	return array(
		'status'  => $status,
		'headers' => $resp_headers,
		'body'    => $resp_body,
		'error'   => $error,
	);
}

/** Build a multipart/form-data body by hand, so the exact bytes are known. */
function multipart_body( array $fields, ?array $file, string $boundary ): string {
	$out = '';
	foreach ( $fields as $name => $value ) {
		$out .= "--{$boundary}\r\n";
		$out .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
		$out .= $value . "\r\n";
	}
	if ( $file !== null ) {
		$out .= "--{$boundary}\r\n";
		$out .= "Content-Disposition: form-data; name=\"{$file['name']}\"; filename=\"{$file['filename']}\"\r\n";
		$out .= "Content-Type: {$file['type']}\r\n\r\n";
		$out .= $file['bytes'] . "\r\n";
	}
	$out .= "--{$boundary}--\r\n";
	return $out;
}

/** json_encode with the flags used for every JSON body below. */
function json_body( array $data ): string {
	return (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/** Pull a media id out of a v2 or v1.1-shaped response, whichever came back. */
function extract_media_id( string $json ): ?string {
	$data = json_decode( $json, true );
	if ( ! is_array( $data ) ) {
		return null;
	}
	foreach ( array( array( 'data', 'id' ), array( 'data', 'media_id_string' ), array( 'id' ), array( 'media_id_string' ) ) as $path ) {
		$node = $data;
		foreach ( $path as $key ) {
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
				$node = null;
				break;
			}
			$node = $node[ $key ];
		}
		if ( is_string( $node ) && $node !== '' ) {
			return $node;
		}
		if ( is_int( $node ) ) {
			return (string) $node;
		}
	}
	return null;
}

/* ------------------------------------------------------------------ */
/* Test image — a 16x16 PNG, 89 bytes, embedded so the script needs no  */
/* external file and no image extension.                               */
/* ------------------------------------------------------------------ */

const TEST_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAIElEQVR42mO4gwTkkAAu'
	. 'cYZBqIEYRcjig1HDaDwMCg0AXLJ3EMpY8ZMAAAAASUVORK5CYII=';

/* ================================================================== */
/* Run                                                                 */
/* ================================================================== */

$png       = base64_decode( TEST_PNG_B64, true );
$png_bytes = strlen( (string) $png );

rule( 'Social Relay — Phase 0 probe for OQ-2' );
out( 'Script:    bin/verify-x-api.php' );
out( 'PHP:       ' . PHP_VERSION );
out( 'curl:      ' . ( function_exists( 'curl_version' ) ? ( curl_version()['version'] ?? 'unknown' ) : 'MISSING' ) );
out( 'Host:      ' . API_HOST . '   (OQ-13: upload.x.com is deliberately not contacted)' );
out( 'Test image: ' . $png_bytes . '-byte PNG, embedded, 16x16' );
out( 'Mode:      ' . ( $dry_run ? 'DRY RUN — no network calls' : 'LIVE — real API calls, real charges' ) );

if ( $png === false || substr( (string) $png, 0, 8 ) !== "\x89PNG\r\n\x1a\n" ) {
	out( 'FATAL: embedded test PNG failed to decode. The script is corrupt.' );
	exit( 2 );
}

$creds = credentials();
out( '' );
out( 'Credentials read from the environment (masked):' );
out( '  X_API_KEY             = ' . mask( $creds['api_key'] ) );
out( '  X_API_SECRET          = ' . mask( $creds['api_secret'] ) );
out( '  X_ACCESS_TOKEN        = ' . mask( $creds['access_token'] ) );
out( '  X_ACCESS_TOKEN_SECRET = ' . mask( $creds['access_token_secret'] ) );

if ( str_contains( $creds['access_token'], '-' ) ) {
	out( '' );
	out( '  note: access token contains "-", which is the usual OAuth 1.0a shape' );
	out( '        (numeric user id, hyphen, token). This looks right.' );
} else {
	out( '' );
	out( '  WARNING: the access token does not contain "-". OAuth 1.0a access tokens' );
	out( '           normally look like 1234567890-AbCdEf... . If you pasted an' );
	out( '           OAuth 2.0 bearer token here, every call below will fail and the' );
	out( '           result will say nothing about OQ-2.' );
}

if ( $dry_run ) {
	rule( 'DRY RUN — signature base strings, no calls made' );
	foreach (
		array(
			array( 'GET', API_HOST . '/2/users/me' ),
			array( 'POST', API_HOST . '/2/media/upload/initialize' ),
			array( 'POST', API_HOST . '/2/media/upload/1234567890/append' ),
			array( 'POST', API_HOST . '/2/media/upload/1234567890/finalize' ),
			array( 'POST', API_HOST . '/2/media/upload' ),
		) as $probe
	) {
		$signed = oauth_header( $probe[0], $probe[1], array(), 'FIXEDNONCEFORDRYRUN', 1757030400 );
		out( '' );
		out( $probe[0] . ' ' . $probe[1] );
		out( '  base string: ' . $signed['base_string'] );
	}
	out( '' );
	out( 'Nonce and timestamp above are fixed placeholders so the output is stable.' );
	out( 'Re-run without --dry-run to make the real calls.' );
	exit( 0 );
}

$results = array();

/* ---- Step 1: prove the signer and the credentials ----------------- */

rule( 'STEP 1 — GET /2/users/me  (does OAuth 1.0a work at all?)' );
out( 'Purpose: exonerate or convict the signer before touching media.' );
out( 'If this fails, every later result is uninterpretable.' );
out( 'Cost: about $0.010 (user read).' );

$url    = API_HOST . '/2/users/me';
$signed = oauth_header( 'GET', $url );
$r1     = request( 'step 1', 'GET', $url, $signed['header'] );
$results['step1_users_me'] = $r1['status'];

if ( $r1['status'] !== 200 ) {
	rule( 'STOPPED — step 1 did not return 200' );
	out( 'The OAuth 1.0a signer, the credentials, or the App configuration is wrong.' );
	out( 'Because of that, a failure on the media endpoints would prove nothing about' );
	out( 'OQ-2, so the media steps are skipped rather than run and misread.' );
	out( '' );
	out( 'Check, in this order:' );
	out( '  1. All four values are from the SAME App, copied with no trailing newline.' );
	out( '  2. If your console still uses Projects, the App must sit inside one.' );
	out( '     The current console may not have Projects at all — see OQ-19.' );
	out( '  3. App permissions are Read and Write.' );
	out( '  4. The access token was regenerated AFTER permissions were set to Read and Write.' );
	out( '  5. The system clock is correct. OAuth 1.0a rejects a skewed oauth_timestamp.' );
	out( '     Local time now: ' . gmdate( 'c' ) . ' (UTC)' );
	out( '' );
	out( 'Paste everything above back. A step-1 failure is still a useful Phase 0 result.' );
	exit( 1 );
}

out( '' );
out( 'Step 1 passed. The signer is correct and the credentials are valid.' );
out( 'Anything that fails below is the endpoint rejecting OAuth 1.0a, not a signing bug.' );

/* ---- Step 2: chunked INITIALIZE ----------------------------------- */

rule( 'STEP 2 — POST /2/media/upload/initialize   (OQ-2, the blocking question)' );
out( 'JSON body. Note: for a JSON body the body is NOT included in the OAuth' );
out( 'signature base string. Only the oauth_* parameters are signed.' );

$url  = API_HOST . '/2/media/upload/initialize';
$init = json_body( array(
	'media_type'     => 'image/png',
	'total_bytes'    => $png_bytes,
	'media_category' => 'tweet_image',
) );

$signed = oauth_header( 'POST', $url );
$r2     = request(
	'step 2',
	'POST',
	$url,
	$signed['header'],
	array( 'Content-Type: application/json', 'Content-Length: ' . strlen( $init ) ),
	$init
);
$results['step2_initialize'] = $r2['status'];

$media_id = $r2['status'] >= 200 && $r2['status'] < 300 ? extract_media_id( $r2['body'] ) : null;

/* ---- Steps 3 and 4: APPEND then FINALIZE -------------------------- */

if ( $media_id === null ) {
	rule( 'STEPS 3 and 4 — SKIPPED' );
	out( 'No media id came back from initialize, so append and finalize cannot run.' );
	$results['step3_append']   = 'skipped';
	$results['step4_finalize'] = 'skipped';
} else {
	out( '' );
	out( 'Media id from initialize: ' . $media_id );

	rule( 'STEP 3 — POST /2/media/upload/{id}/append' );
	out( 'multipart/form-data. As with JSON, a multipart body is NOT signed.' );

	$boundary = 'srlboundary' . bin2hex( random_bytes( 12 ) );
	$url      = API_HOST . '/2/media/upload/' . rawurlencode( $media_id ) . '/append';
	$body     = multipart_body(
		array( 'segment_index' => '0' ),
		array(
			'name'     => 'media',
			'filename' => 'test.png',
			'type'     => 'image/png',
			'bytes'    => (string) $png,
		),
		$boundary
	);

	$signed = oauth_header( 'POST', $url );
	$r3     = request(
		'step 3',
		'POST',
		$url,
		$signed['header'],
		array( 'Content-Type: multipart/form-data; boundary=' . $boundary, 'Content-Length: ' . strlen( $body ) ),
		$body
	);
	$results['step3_append'] = $r3['status'];

	rule( 'STEP 4 — POST /2/media/upload/{id}/finalize' );
	$url    = API_HOST . '/2/media/upload/' . rawurlencode( $media_id ) . '/finalize';
	$signed = oauth_header( 'POST', $url );
	$r4     = request( 'step 4', 'POST', $url, $signed['header'], array( 'Content-Length: 0' ), '' );
	$results['step4_finalize'] = $r4['status'];
}

/* ---- Step 5: one-shot upload (OQ-14) ------------------------------ */

rule( 'STEP 5 — POST /2/media/upload as a single multipart call   (OQ-14)' );
out( 'Brief 1.3 assumes a "simple upload for images" exists. The chunked' );
out( 'quickstart does not mention one. This call settles it. A 404 or 405 means' );
out( 'there is no one-shot path and FR-4.4 must use the three-step flow.' );

$boundary = 'srlboundary' . bin2hex( random_bytes( 12 ) );
$url      = API_HOST . '/2/media/upload';
$body     = multipart_body(
	array( 'media_category' => 'tweet_image' ),
	array(
		'name'     => 'media',
		'filename' => 'test.png',
		'type'     => 'image/png',
		'bytes'    => (string) $png,
	),
	$boundary
);
$signed = oauth_header( 'POST', $url );
$r5     = request(
	'step 5',
	'POST',
	$url,
	$signed['header'],
	array( 'Content-Type: multipart/form-data; boundary=' . $boundary, 'Content-Length: ' . strlen( $body ) ),
	$body
);
$results['step5_oneshot'] = $r5['status'];

/* ---- Summary ------------------------------------------------------ */

rule( 'SUMMARY — paste this whole output back' );
out( 'Status codes:' );
foreach ( $results as $step => $status ) {
	out( sprintf( '  %-20s %s', $step, (string) $status ) );
}

out( '' );
out( 'How to read this:' );
out( '' );
out( '  OQ-2  Does POST /2/media/upload accept OAuth 1.0a?' );
if ( ( $results['step2_initialize'] ?? 0 ) >= 200 && ( $results['step2_initialize'] ?? 0 ) < 300 ) {
	out( '        -> YES for initialize. The docs are accurate and the forum titles' );
	out( '           are stale or describe a different case. Check steps 3 and 4 too:' );
	out( '           all three must pass for the image path to work end to end.' );
	out( '        -> If 3 and 4 also passed, ADR-001 is safe and OQ-2 closes VERIFIED.' );
} elseif ( ( $results['step2_initialize'] ?? 0 ) === 403 ) {
	out( '        -> NO. 403 with a signer already proved correct by step 1 means the' );
	out( '           endpoint rejects OAuth 1.0a user context. The docs are wrong and' );
	out( '           the forum reports are right.' );
	out( '        -> ADR-001 and ADR-002 are now in conflict. See the contingency' );
	out( '           options recorded in ADR-001 -> Alternatives. This needs a decision' );
	out( '           before the Specification Gate.' );
} elseif ( ( $results['step2_initialize'] ?? 0 ) === 401 ) {
	out( '        -> 401 after step 1 returned 200 is unusual. Most likely the App has' );
	out( '           Read-only permissions, or the token predates a permissions change.' );
	out( '           Regenerate the access token and re-run before concluding anything.' );
} else {
	out( '        -> INCONCLUSIVE. Read the raw body above; do not close OQ-2 on this.' );
}
out( '' );
out( '  OQ-13 Host. Every call above went to ' . API_HOST . ' and none to upload.x.com.' );
out( '        If the media steps succeeded, api.x.com alone is sufficient and the' );
out( '        allowlist in brief section 8 should drop upload.x.com.' );
out( '' );
out( '  OQ-14 One-shot upload. Step 5 returned ' . (string) ( $results['step5_oneshot'] ?? 'n/a' ) . '.' );
out( '        2xx means a single-call path exists and FR-4.4 can use it.' );
out( '        404 or 405 means the three-step flow is the only v2 path.' );
out( '' );
out( '  OQ-1b Media pricing. Open the Developer Console usage report now and record' );
out( '        what this run cost. That number is the only source for OQ-1b.' );
out( '' );
out( 'No post was created. Nothing was published to the timeline.' );
out( 'Run finished at ' . gmdate( 'c' ) . ' (UTC).' );
