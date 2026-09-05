<?php
/**
 * Post text composition and X's weighted length algorithm.
 *
 * Implements SPEC.md section 7 and FR-4.5. WordPress-free, so the unit suite
 * can exercise it on a bare PHP install. This is the piece most likely to be
 * subtly wrong: an off-by-one here produces an HTTP 400 from X and a failed
 * post, days after publishing, on exactly the posts whose titles are longest.
 *
 * Weights come from X's own twitter-text configuration v3, read 2026-09-05 from
 * https://raw.githubusercontent.com/twitter/twitter-text/master/config/v3.json
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composes and measures post text the way X counts it.
 */
class SRL_Text {

	/**
	 * Maximum weighted length of a post.
	 */
	public const MAX_WEIGHTED = 280;

	/**
	 * Weight of any URL, whatever its real length. X wraps every link in t.co.
	 */
	public const URL_WEIGHT = 23;

	/**
	 * Ellipsis appended to a truncated title. One character, weight 1.
	 *
	 * Three periods would weigh 3 and look the same, which is why this is a
	 * single code point.
	 */
	public const ELLIPSIS = "\u{2026}";

	/**
	 * How far back to look for a word boundary when truncating, in weighted units.
	 */
	public const WORD_BOUNDARY_LOOKBACK = 12;

	/**
	 * Code point ranges that weigh 1. Everything else weighs 2.
	 *
	 * From twitter-text v3: ranges with weight 100 against a defaultWeight of
	 * 200, at scale 100.
	 *
	 * @var array<int, array{0:int, 1:int}>
	 */
	private const WEIGHT_ONE_RANGES = array(
		array( 0x0000, 0x10FF ),
		array( 0x2000, 0x200D ),
		array( 0x2010, 0x201F ),
		array( 0x2032, 0x2037 ),
	);

	/**
	 * Measure a string the way X does.
	 *
	 * @param string $text Text to measure.
	 * @return int Weighted length.
	 */
	public static function weighted_length( string $text ): int {
		if ( '' === $text ) {
			return 0;
		}

		$total = 0;

		foreach ( self::segment( $text ) as $segment ) {
			if ( $segment['is_url'] ) {
				$total += $segment['weight'];
				continue;
			}
			$total += self::weigh_plain( $segment['text'] );
		}

		return $total;
	}

	/**
	 * Pictographic code point ranges, an explicit stand-in for
	 * \p{Extended_Pictographic}.
	 *
	 * The Unicode property is not compiled into every PCRE2 build — it is
	 * absent from 10.39, which ships with Ubuntu 22.04 — and a missing
	 * property is a run-time compilation error, not a graceful miss.
	 */
	private const PICTOGRAPHIC = '\x{00A9}\x{00AE}\x{203C}\x{2049}\x{2122}\x{2139}'
		. '\x{2194}-\x{21AA}\x{231A}-\x{231B}\x{2328}\x{23CF}\x{23E9}-\x{23FA}\x{24C2}'
		. '\x{25AA}-\x{25FE}\x{2600}-\x{27BF}\x{2934}-\x{2935}\x{2B00}-\x{2BFF}'
		. '\x{3030}\x{303D}\x{3297}\x{3299}\x{1F000}-\x{1FAFF}';

	/**
	 * Regex matching one whole emoji sequence.
	 *
	 * Built explicitly rather than relying on \X. PCRE2 10.39's \X merges
	 * *adjacent* ZWJ emoji into a single cluster: 140 consecutive family
	 * emoji come back as one grapheme, so a cluster-based count charged 2
	 * where X charges 280. That is a 278-unit under-count, and an under-count
	 * overflows the limit and produces the HTTP 400 this class exists to
	 * prevent. Verified against X's own conformance fixtures.
	 *
	 * @return string
	 */
	private static function emoji_pattern(): string {
		$pict = '[' . self::PICTOGRAPHIC . ']';
		$tone = '[\x{1F3FB}-\x{1F3FF}]';
		$vs   = '\x{FE0F}';
		$tag  = '[\x{E0020}-\x{E007F}]';

		// One emoji, with optional presentation selector, skin tone and tag
		// sequence (tags carry the subdivision flags such as the England flag).
		$atom = '(?:' . $pict . '(?:' . $vs . ')?(?:' . $tone . ')?(?:' . $tag . ')*)';

		return '(?:'
			// Keycap: a digit or # or * plus the enclosing keycap mark.
			. '[0-9#*](?:' . $vs . ')?\x{20E3}'
			// Flag: exactly two regional indicators.
			. '|[\x{1F1E6}-\x{1F1FF}]{2}'
			// Anything else, including ZWJ sequences.
			. '|' . $atom . '(?:\x{200D}' . $atom . ')*'
			. ')';
	}

	/**
	 * Split text into URL and non-URL segments.
	 *
	 * A URL inside the title matters: X counts a link it shortens as 23
	 * regardless of length, so measuring it literally would waste budget, and
	 * assuming every URL-shaped token is shortened under-counts the ones X
	 * refuses to shorten.
	 *
	 * @param string $text Text to split.
	 * @return array<int, array{text:string, is_url:bool, weight:int}>
	 */
	private static function segment( string $text ): array {
		$full = '(?:https?://\S+)';
		// Bare host.tld with an optional path. The two-letter minimum on the
		// TLD keeps "i.e." and "e.g." out; without it both would cost 23.
		$bare = '(?:(?<![@\w.])[a-z0-9\x{00A1}-\x{FFFF}](?:[a-z0-9\x{00A1}-\x{FFFF}-]*[a-z0-9\x{00A1}-\x{FFFF}])?'
			. '(?:\.[a-z0-9\x{00A1}-\x{FFFF}-]+)*\.[a-z]{2,24}(?:/\S*)?)';

		$parts = preg_split( '#(' . $full . '|' . $bare . ')#iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) ) {
			return array(
				array(
					'text'   => $text,
					'is_url' => false,
					'weight' => 0,
				),
			);
		}

		$segments = array();
		foreach ( $parts as $part ) {
			$is_full = (bool) preg_match( '#^' . $full . '$#iu', $part );
			$is_bare = ! $is_full && (bool) preg_match( '#^' . $bare . '$#iu', $part );

			if ( ! $is_full && ! $is_bare ) {
				$segments[] = array(
					'text'   => $part,
					'is_url' => false,
					'weight' => 0,
				);
				continue;
			}

			$literal = self::weigh_plain( $part );

			if ( ! self::host_is_shortenable( $part ) ) {
				// X refuses to shorten a URL whose host is not valid, and
				// charges for every character instead. Counting 23 here is
				// the under-count that overflows.
				$segments[] = array(
					'text'   => $part,
					'is_url' => true,
					'weight' => $literal,
				);
				continue;
			}

			// A scheme-qualified URL with a valid host is definitely
			// shortened, so it costs exactly 23. A bare domain may or may not
			// be linkified, so take the larger of the two possibilities: that
			// can only truncate early, never overflow.
			$segments[] = array(
				'text'   => $part,
				'is_url' => true,
				'weight' => $is_full ? self::URL_WEIGHT : max( self::URL_WEIGHT, $literal ),
			);
		}

		return $segments;
	}

	/**
	 * Whether X would shorten this URL, judged by its host.
	 *
	 * A DNS label may not exceed 63 octets and a host may not exceed 253. X
	 * rejects URLs that break those limits and charges for the literal text.
	 * Internationalised hosts are measured after IDNA encoding, because it is
	 * the punycode length that counts — a short-looking CJK host can encode to
	 * far more than 63 octets.
	 *
	 * @param string $url Candidate URL or bare domain.
	 * @return bool
	 */
	private static function host_is_shortenable( string $url ): bool {
		$candidate = preg_replace( '#^https?://#i', '', $url );
		if ( ! is_string( $candidate ) ) {
			return false;
		}

		$host = (string) preg_split( '#[/?\#]#', $candidate )[0];
		if ( '' === $host ) {
			return false;
		}

		$ascii = $host;
		if ( function_exists( 'idn_to_ascii' ) && preg_match( '/[^\x20-\x7E]/', $host ) ) {
			$converted = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			// A host that cannot be encoded at all is certainly not shortened.
			$ascii = is_string( $converted ) && '' !== $converted ? $converted : str_repeat( 'x', 300 );
		}

		if ( strlen( $ascii ) > 253 ) {
			return false;
		}
		foreach ( explode( '.', $ascii ) as $label ) {
			if ( strlen( $label ) > 63 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Weigh text containing no URLs.
	 *
	 * NFC-normalize first, then walk code points, except that a whole emoji
	 * sequence counts as one unit of weight 2.
	 *
	 * Counting whole grapheme clusters instead under-counts twice over: "a"
	 * followed by five combining marks is one cluster but six charged code
	 * points, and PCRE2's \X merges adjacent emoji. Both under-counts
	 * overflow. See SPEC.md section 7.4.
	 *
	 * @param string $text Plain text.
	 * @return int
	 */
	private static function weigh_plain( string $text ): int {
		$text = self::normalize( $text );
		if ( '' === $text ) {
			return 0;
		}

		$emoji = self::emoji_pattern();
		$parts = preg_split( '/(' . $emoji . ')/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) ) {
			return strlen( $text ); // Invalid UTF-8: over-estimate, never under.
		}

		$total = 0;
		foreach ( $parts as $part ) {
			if ( preg_match( '/^' . $emoji . '$/u', $part ) ) {
				$total += 2;
				continue;
			}
			foreach ( self::code_points( $part ) as $code_point ) {
				$total += self::weigh_code_point( $code_point );
			}
		}

		return $total;
	}

	/**
	 * NFC-normalize, when the intl Normalizer is available.
	 *
	 * Without intl the string is used as-is, which can only over-count a
	 * decomposed sequence relative to X's normalized count. Over-counting
	 * truncates early rather than overflowing, which is the safe direction.
	 *
	 * @param string $text Text to normalize.
	 * @return string
	 */
	public static function normalize( string $text ): string {
		if ( ! class_exists( 'Normalizer' ) ) {
			return $text;
		}
		$normalized = Normalizer::normalize( $text, Normalizer::FORM_C );
		return is_string( $normalized ) ? $normalized : $text;
	}

	/**
	 * Split into units that must not be cut apart when truncating: whole
	 * emoji sequences, and otherwise grapheme clusters.
	 *
	 * @param string $text Text to split.
	 * @return array<int, string>
	 */
	public static function units( string $text ): array {
		if ( '' === $text ) {
			return array();
		}

		$emoji = self::emoji_pattern();
		$parts = preg_split( '/(' . $emoji . ')/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) ) {
			return array( $text );
		}

		$units = array();
		foreach ( $parts as $part ) {
			if ( preg_match( '/^' . $emoji . '$/u', $part ) ) {
				$units[] = $part;
				continue;
			}
			$matches = array();
			if ( preg_match_all( '/\X/u', $part, $matches ) && isset( $matches[0] ) ) {
				foreach ( $matches[0] as $cluster ) {
					$units[] = $cluster;
				}
			}
		}

		return $units;
	}

	/**
	 * Weight of one unit from units().
	 *
	 * @param string $unit One emoji sequence or grapheme cluster.
	 * @return int
	 */
	private static function weigh_unit( string $unit ): int {
		return self::weigh_plain( $unit );
	}

	/**
	 * Code points of a string.
	 *
	 * @param string $text Text.
	 * @return array<int, int>
	 */
	private static function code_points( string $text ): array {
		$points = array();
		$chars  = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $chars ) ) {
			return $points;
		}

		foreach ( $chars as $char ) {
			$converted = mb_convert_encoding( $char, 'UTF-32BE', 'UTF-8' );
			$unpacked  = unpack( 'N', $converted );
			if ( is_array( $unpacked ) && isset( $unpacked[1] ) ) {
				$points[] = (int) $unpacked[1];
			}
		}

		return $points;
	}

	/**
	 * Weight of one code point.
	 *
	 * @param int $code_point Unicode code point.
	 * @return int 1 or 2.
	 */
	private static function weigh_code_point( int $code_point ): int {
		foreach ( self::WEIGHT_ONE_RANGES as $range ) {
			if ( $code_point >= $range[0] && $code_point <= $range[1] ) {
				return 1;
			}
		}
		return 2;
	}

	/**
	 * Compose the post text.
	 *
	 * Format: "{prefix} {title} {suffix}\n{permalink}". An absent prefix or
	 * suffix takes its joining space with it, so the result never carries a
	 * leading space or a double space.
	 *
	 * @param string $title     Post title.
	 * @param string $permalink Post permalink. Never truncated.
	 * @param string $prefix    Optional prefix.
	 * @param string $suffix    Optional suffix.
	 * @return string
	 */
	public static function compose( string $title, string $permalink, string $prefix = '', string $suffix = '' ): string {
		$prefix = trim( $prefix );
		$suffix = trim( $suffix );
		$title  = trim( $title );

		$budget = self::MAX_WEIGHTED - self::URL_WEIGHT - 1; // 1 for the newline.

		$fixed = self::weighted_length( $prefix ) + self::weighted_length( $suffix );
		if ( '' !== $prefix ) {
			++$fixed; // Joining space.
		}
		if ( '' !== $suffix ) {
			++$fixed; // Joining space.
		}

		$title_budget = $budget - $fixed;
		if ( $title_budget < 1 ) {
			// Only reachable if the bounds in SPEC.md section 3 were not
			// enforced. Keep the URL rather than the decoration.
			$title_budget = 0;
		}

		if ( self::weighted_length( $title ) > $title_budget ) {
			$title = self::truncate( $title, $title_budget );
		}

		$line = trim( implode( ' ', array_filter( array( $prefix, $title, $suffix ), 'strlen' ) ) );

		return '' === $line ? $permalink : $line . "\n" . $permalink;
	}

	/**
	 * Truncate to a weighted budget, appending an ellipsis.
	 *
	 * @param string $text   Text to truncate.
	 * @param int    $budget Weighted budget including the ellipsis.
	 * @return string
	 */
	public static function truncate( string $text, int $budget ): string {
		if ( $budget <= 0 ) {
			return '';
		}
		if ( self::weighted_length( $text ) <= $budget ) {
			return $text;
		}


		// Reserve the ellipsis by measuring it, never by assuming.
		//
		// U+2026 weighs 2, not 1: it sits in the gap between twitter-text's
		// weight-one ranges (which end at 8223 and resume at 8242), so it
		// takes the default weight of 200. Hard-coding 1 here overflowed the
		// limit by exactly one unit on every truncated post.
		$reserve   = self::weighted_length( self::ELLIPSIS );
		$allowance = $budget - $reserve;

		if ( $allowance < 1 ) {
			return self::ELLIPSIS;
		}

		$kept   = '';
		$used   = 0;
		$marks  = array();

		foreach ( self::units( $text ) as $cluster ) {
			$weight = self::weigh_unit( $cluster );
			if ( $used + $weight > $allowance ) {
				break;
			}
			$kept .= $cluster;
			$used += $weight;

			// Remember where words end, so the cut can prefer a boundary.
			if ( preg_match( '/^\s$/u', $cluster ) ) {
				$marks[] = array( strlen( $kept ), $used );
			}
		}

		// Prefer a word boundary if one is close to the cut.
		for ( $i = count( $marks ) - 1; $i >= 0; $i-- ) {
			if ( $used - $marks[ $i ][1] <= self::WORD_BOUNDARY_LOOKBACK ) {
				$kept = substr( $kept, 0, $marks[ $i ][0] );
				break;
			}
		}

		return rtrim( $kept ) . self::ELLIPSIS;
	}
}
