<?php
/**
 * The X provider.
 *
 * Implements SPEC.md section 8. Every request goes to api.x.com and nowhere
 * else (INV-3); the host is a constant and is never read from settings, a
 * filter, or the database.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes to one X account with OAuth 1.0a user context.
 */
class SRL_X_Provider implements SRL_Provider {

	/**
	 * The only host this plugin ever contacts.
	 *
	 * upload.x.com is deliberately absent: it is the legacy v1.1 host that
	 * brief section 1.3 forbids building on, and the Phase 0 probe proved the
	 * whole flow works against api.x.com alone (OQ-13).
	 */
	public const API_HOST = 'https://api.x.com';

	/**
	 * Create-post path.
	 *
	 * Held in one constant because OQ-15 is still open: one secondary source
	 * referred to /2/posts. Settling it is a one-line change here.
	 */
	public const PATH_CREATE = '/2/tweets';

	/**
	 * One-shot media upload path. Both this and the three-step chunked flow
	 * were proven to work; section 0's simplicity constraint chooses this one.
	 */
	public const PATH_MEDIA = '/2/media/upload';

	/**
	 * Media types accepted for upload.
	 */
	public const ALLOWED_MIME = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * Request timeouts in seconds. WordPress defaults to 5, which is too short
	 * for an image upload on a slow connection.
	 */
	public const TIMEOUT_CREATE = 15;
	public const TIMEOUT_MEDIA  = 30;

	/**
	 * The signer.
	 *
	 * @var SRL_OAuth1
	 */
	private SRL_OAuth1 $signer;

	/**
	 * Constructor.
	 *
	 * @param SRL_OAuth1 $signer Configured signer.
	 */
	public function __construct( SRL_OAuth1 $signer ) {
		$this->signer = $signer;
	}

	/**
	 * Provider id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'x';
	}

	/**
	 * Publish one post.
	 *
	 * @param SRL_Post_Payload $payload What to publish.
	 * @return SRL_Send_Result
	 */
	public function send( SRL_Post_Payload $payload ): SRL_Send_Result {
		$result = new SRL_Send_Result();

		if ( ! $this->signer->is_complete() ) {
			$result->error_code    = SRL_Send_Result::ERROR_AUTH;
			$result->error_message = 'Credentials are incomplete.';
			return $result;
		}

		$media_id = $payload->existing_media_id;

		if ( null === $media_id && null !== $payload->image_path ) {
			$media = $this->upload_media( $payload->image_path, (string) $payload->image_mime );

			$result->endpoints_called = array_merge( $result->endpoints_called, $media['endpoints'] );

			if ( null === $media['media_id'] ) {
				// The image never blocks the post (INV-6) and never consumes
				// the send's retry budget (SPEC 9.5). Record and continue.
				$result->image_omitted        = true;
				$result->image_omitted_reason = $media['reason'];
			} else {
				$media_id = $media['media_id'];
			}
		}

		$result->media_id = $media_id;

		$text = SRL_Text::compose( $payload->title, $payload->permalink, $payload->prefix, $payload->suffix );

		return $this->create_post( $text, $media_id, $result );
	}

	/**
	 * Post the text, with media if there is any.
	 *
	 * @param string          $text     Composed text.
	 * @param string|null     $media_id Media id, or null.
	 * @param SRL_Send_Result $result   Result being built.
	 * @return SRL_Send_Result
	 */
	private function create_post( string $text, ?string $media_id, SRL_Send_Result $result ): SRL_Send_Result {
		$url  = self::API_HOST . self::PATH_CREATE;
		$body = array( 'text' => $text );

		if ( null !== $media_id ) {
			$body['media'] = array( 'media_ids' => array( $media_id ) );
		}

		$json = (string) wp_json_encode( $body );

		$response = wp_remote_post(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => self::TIMEOUT_CREATE,
				'headers' => array(
					// A JSON body is not form-encoded, so it is NOT part of
					// the OAuth signature base string. SPEC.md section 8.1.
					'Authorization' => $this->signer->authorization_header( 'POST', $url ),
					'Content-Type'  => 'application/json',
				),
				'body'    => $json,
			)
		);

		$result->endpoints_called[] = 'POST ' . self::PATH_CREATE;

		return $this->interpret_create( $response, $result );
	}

	/**
	 * Apply the error matrix to a create-post response.
	 *
	 * @param array|WP_Error  $response Raw response.
	 * @param SRL_Send_Result $result   Result being built.
	 * @return SRL_Send_Result
	 */
	private function interpret_create( $response, SRL_Send_Result $result ): SRL_Send_Result {
		if ( is_wp_error( $response ) ) {
			// http_status stays null: that is what distinguishes a transport
			// failure from an HTTP failure in the log.
			$result->error_code    = SRL_Send_Result::ERROR_TRANSPORT;
			$result->error_message = $response->get_error_code() . ': ' . $response->get_error_message();
			return $result;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		$result->http_status   = $status;
		$result->error_message = $body;

		if ( $status >= 200 && $status < 300 ) {
			$data = json_decode( $body, true );
			$id   = is_array( $data ) && isset( $data['data']['id'] ) ? (string) $data['data']['id'] : '';

			if ( '' === $id ) {
				// Deliberately terminal. The post was almost certainly created
				// and only its id was lost; retrying would risk a duplicate
				// and break INV-1 for no gain.
				$result->error_code = SRL_Send_Result::ERROR_MALFORMED_RESPONSE;
				return $result;
			}

			$result->success   = true;
			$result->remote_id = $id;
			return $result;
		}

		if ( 429 === $status ) {
			$result->error_code = SRL_Send_Result::ERROR_RATE_LIMIT;
			return $result;
		}
		if ( $status >= 500 ) {
			$result->error_code = SRL_Send_Result::ERROR_SERVER;
			return $result;
		}
		if ( 401 === $status || 403 === $status ) {
			$result->error_code = SRL_Send_Result::ERROR_AUTH;
			return $result;
		}
		if ( self::body_is_duplicate( $body ) ) {
			$result->error_code = SRL_Send_Result::ERROR_DUPLICATE;
			return $result;
		}

		$result->error_code = SRL_Send_Result::ERROR_CLIENT;
		return $result;
	}

	/**
	 * Whether a 4xx body is X's duplicate-content rejection.
	 *
	 * @param string $body Response body.
	 * @return bool
	 */
	public static function body_is_duplicate( string $body ): bool {
		return (bool) preg_match( '/duplicate/i', $body );
	}

	/**
	 * Upload one image.
	 *
	 * @param string $path Absolute filesystem path.
	 * @param string $mime MIME type.
	 * @return array{media_id:string|null, reason:string, endpoints:array<int,string>}
	 */
	public function upload_media( string $path, string $mime ): array {
		$out = array(
			'media_id'  => null,
			'reason'    => '',
			'endpoints' => array(),
		);

		if ( ! in_array( $mime, self::ALLOWED_MIME, true ) ) {
			$out['reason'] = 'unsupported_type: ' . $mime;
			return $out;
		}
		if ( ! is_readable( $path ) ) {
			$out['reason'] = 'unreadable_file';
			return $out;
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file the site already owns; WP_Filesystem is for writes and for remote transports.
		if ( false === $bytes ) {
			$out['reason'] = 'unreadable_file';
			return $out;
		}

		$url      = self::API_HOST . self::PATH_MEDIA;
		$boundary = 'srl' . bin2hex( random_bytes( 12 ) );

		// The body is built by hand and passed as a STRING. WordPress's HTTP
		// API has no multipart support: an array body is serialised with
		// http_build_query() as application/x-www-form-urlencoded, which would
		// send mangled binary and, under SPEC 8.1, would then have to be
		// signed. SPEC.md section 8.2.
		$body = $this->multipart_body(
			array( 'media_category' => 'tweet_image' ),
			array(
				'name'     => 'media',
				'filename' => basename( $path ),
				'type'     => $mime,
				'bytes'    => $bytes,
			),
			$boundary
		);

		$response = wp_remote_post(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => self::TIMEOUT_MEDIA,
				'headers' => array(
					// multipart/form-data is not application/x-www-form-urlencoded,
					// so its parts are never signed either.
					'Authorization' => $this->signer->authorization_header( 'POST', $url ),
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		$out['endpoints'][] = 'POST ' . self::PATH_MEDIA;

		if ( is_wp_error( $response ) ) {
			$out['reason'] = 'transport: ' . $response->get_error_message();
			return $out;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			$out['reason'] = 'http_' . $status;
			return $out;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		// data.id only. The one-shot path returns image.h/image.w while the
		// chunked finalize returns image.height/image.width, and data.size
		// disagreed with the real file size in the Phase 0 probe, so none of
		// those is trusted. SPEC.md section 8.2.
		if ( ! is_array( $data ) || ! isset( $data['data']['id'] ) || '' === (string) $data['data']['id'] ) {
			$out['reason'] = 'malformed_media_response';
			return $out;
		}

		$out['media_id'] = (string) $data['data']['id'];
		return $out;
	}

	/**
	 * Assemble a multipart/form-data body.
	 *
	 * @param array<string, string>                                     $fields Simple fields.
	 * @param array{name:string, filename:string, type:string, bytes:string} $file  File part.
	 * @param string                                                    $boundary Boundary.
	 * @return string
	 */
	private function multipart_body( array $fields, array $file, string $boundary ): string {
		$out = '';

		foreach ( $fields as $name => $value ) {
			$out .= "--{$boundary}\r\n";
			$out .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
			$out .= $value . "\r\n";
		}

		$out .= "--{$boundary}\r\n";
		$out .= "Content-Disposition: form-data; name=\"{$file['name']}\"; filename=\"{$file['filename']}\"\r\n";
		$out .= "Content-Type: {$file['type']}\r\n\r\n";
		$out .= $file['bytes'] . "\r\n";
		$out .= "--{$boundary}--\r\n";

		return $out;
	}
}
