<?php
/**
 * The provider contract.
 *
 * One method that matters. Brief section 3 requires the interface to make a
 * second network cheap to add while shipping only X, and this is the whole of
 * that promise: nothing in the scheduler, the log, the meta box or the settings
 * refers to X directly.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A social network the plugin can publish to.
 */
interface SRL_Provider {

	/**
	 * Publish one post.
	 *
	 * Implementations MUST NOT throw. Every failure is reported through the
	 * result object, because this runs inside a cron event where an uncaught
	 * exception is invisible.
	 *
	 * @param SRL_Post_Payload $payload What to publish.
	 * @return SRL_Send_Result
	 */
	public function send( SRL_Post_Payload $payload ): SRL_Send_Result;

	/**
	 * Stable identifier, stored in the log's provider column.
	 *
	 * @return string
	 */
	public function id(): string;
}
