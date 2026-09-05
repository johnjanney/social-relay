<?php
/**
 * The outcome of one send attempt.
 *
 * Carries enough for the publisher to apply the error matrix in SPEC.md
 * section 9 without knowing anything about the provider's wire format.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Result of a provider send.
 */
class SRL_Send_Result {

	public const ERROR_NONE               = '';
	public const ERROR_AUTH               = 'auth';
	public const ERROR_RATE_LIMIT         = 'rate_limit';
	public const ERROR_SERVER             = 'server';
	public const ERROR_TRANSPORT          = 'transport';
	public const ERROR_DUPLICATE          = 'duplicate';
	public const ERROR_CLIENT             = 'client';
	public const ERROR_MALFORMED_RESPONSE = 'malformed_response';

	/**
	 * Whether the post was created.
	 *
	 * @var bool
	 */
	public bool $success = false;

	/**
	 * Remote post id on success.
	 *
	 * @var string|null
	 */
	public ?string $remote_id = null;

	/**
	 * HTTP status, or null for a transport failure.
	 *
	 * A null here is what distinguishes a transport error from an HTTP error
	 * in the log, so it is never coerced to 0.
	 *
	 * @var int|null
	 */
	public ?int $http_status = null;

	/**
	 * One of the ERROR_* constants.
	 *
	 * @var string
	 */
	public string $error_code = self::ERROR_NONE;

	/**
	 * Human-readable detail, or the response body.
	 *
	 * @var string
	 */
	public string $error_message = '';

	/**
	 * Whether the image was left off.
	 *
	 * @var bool
	 */
	public bool $image_omitted = false;

	/**
	 * Why the image was left off.
	 *
	 * @var string
	 */
	public string $image_omitted_reason = '';

	/**
	 * Media id used or obtained, for reuse on a retry.
	 *
	 * @var string|null
	 */
	public ?string $media_id = null;

	/**
	 * Endpoints called during this attempt, for the usage counter.
	 *
	 * Every call is recorded whether it succeeded or failed, because X bills
	 * for both. FR-4.12.
	 *
	 * @var array<int, string>
	 */
	public array $endpoints_called = array();

	/**
	 * Whether this outcome should be retried.
	 *
	 * SPEC.md section 9. Auth, client, duplicate and malformed-response
	 * outcomes are terminal; a malformed 2xx is terminal specifically to
	 * protect INV-1, because the post was probably created and only its id was
	 * lost.
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		return in_array(
			$this->error_code,
			array( self::ERROR_RATE_LIMIT, self::ERROR_SERVER, self::ERROR_TRANSPORT ),
			true
		);
	}
}
