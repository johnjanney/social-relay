<?php
/**
 * API request accounting.
 *
 * Implements SPEC.md section 12, FR-1.7 and FR-4.12. The count exists so the
 * owner can reconcile against the X invoice, which is why it counts requests
 * made rather than requests that succeeded: X bills for both.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks how many API requests the plugin has made, by month and endpoint.
 */
class SRL_Usage {

	/**
	 * Option name.
	 */
	public const OPTION = 'srl_usage';

	/**
	 * Months of history kept. SPEC.md section 12.
	 */
	public const RETAIN_MONTHS = 13;

	/**
	 * Record one request.
	 *
	 * Called for every API call whether it succeeded or failed, per FR-4.12.
	 *
	 * @param string $endpoint Method and path, for example 'POST /2/tweets'.
	 * @return void
	 */
	public static function record( string $endpoint ): void {
		$month = gmdate( 'Y-m' );
		$usage = self::all();

		if ( ! isset( $usage[ $month ] ) || ! is_array( $usage[ $month ] ) ) {
			$usage[ $month ] = array();
		}

		$current                      = isset( $usage[ $month ][ $endpoint ] ) ? (int) $usage[ $month ][ $endpoint ] : 0;
		$usage[ $month ][ $endpoint ] = $current + 1;

		update_option( self::OPTION, $usage, false );
	}

	/**
	 * Everything recorded.
	 *
	 * @return array<string, array<string, int>>
	 */
	public static function all(): array {
		$usage = get_option( self::OPTION, array() );
		return is_array( $usage ) ? $usage : array();
	}

	/**
	 * Counts for one month, endpoint to count.
	 *
	 * @param string|null $month Month as YYYY-MM. Defaults to the current UTC month.
	 * @return array<string, int>
	 */
	public static function for_month( ?string $month = null ): array {
		$month = $month ?? gmdate( 'Y-m' );
		$usage = self::all();

		if ( ! isset( $usage[ $month ] ) || ! is_array( $usage[ $month ] ) ) {
			return array();
		}

		return array_map( 'intval', $usage[ $month ] );
	}

	/**
	 * Total requests in one month across all endpoints.
	 *
	 * @param string|null $month Month as YYYY-MM.
	 * @return int
	 */
	public static function total_for_month( ?string $month = null ): int {
		return array_sum( self::for_month( $month ) );
	}

	/**
	 * Drop months beyond the retention window.
	 *
	 * @return void
	 */
	public static function prune(): void {
		$usage = self::all();
		if ( count( $usage ) <= self::RETAIN_MONTHS ) {
			return;
		}

		// Keys are YYYY-MM, so a string sort is a chronological sort.
		ksort( $usage );
		$usage = array_slice( $usage, -self::RETAIN_MONTHS, null, true );

		update_option( self::OPTION, $usage, false );
	}

	/**
	 * Remove all recorded usage. Used by uninstall.php.
	 *
	 * @return void
	 */
	public static function delete_all(): void {
		delete_option( self::OPTION );
	}
}
