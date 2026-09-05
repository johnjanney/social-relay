<?php
/**
 * Unit tests for the weighted length algorithm and text composition.
 *
 * Runs without WordPress. Covers SPEC.md section 7 and FR-4.5. Method names
 * match the T-identifiers in SPEC.md section 16.6; SPEC.md section 16.11
 * requires that correspondence and CI enforces it.
 *
 * @package Social_Relay
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

require_once SRL_PLUGIN_DIR . '/includes/class-text.php';

/**
 * Covers the counting rules taken from twitter-text config v3.
 */
final class TextTest extends TestCase {

	/** T-421 */
	public function test_url_weighs_23_regardless_of_real_length(): void {
		$this->assertSame( 23, SRL_Text::weighted_length( 'https://a.co/b' ) );
		$this->assertSame( 23, SRL_Text::weighted_length( 'https://example.com/' . str_repeat( 'segment/', 30 ) ) );
	}

	/** T-422 */
	public function test_cjk_and_emoji_weigh_two(): void {
		$this->assertSame( 5, SRL_Text::weighted_length( 'hello' ) );
		$this->assertSame( 6, SRL_Text::weighted_length( '日本語' ) );
		$this->assertSame( 2, SRL_Text::weighted_length( "\u{1F600}" ) );
		$this->assertSame( 6, SRL_Text::weighted_length( 'héllo!' ) );
		$this->assertSame( 6, SRL_Text::weighted_length( 'Привет' ) );
	}

	/** T-423 */
	public function test_zwj_emoji_sequence_weighs_two_not_per_codepoint(): void {
		$family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}";
		$this->assertSame( 7, mb_strlen( $family, 'UTF-8' ), 'fixture must be a 7-codepoint sequence' );
		$this->assertSame( 2, SRL_Text::weighted_length( $family ) );
	}

	/**
	 * T-439
	 *
	 * PCRE2 10.39's \X merges adjacent ZWJ emoji into one cluster, so a
	 * cluster-based counter charged 2 for 140 family emoji where X charges
	 * 280. An under-count overflows the limit and produces the HTTP 400 this
	 * class exists to prevent, so this is a regression test, not a curiosity.
	 */
	public function test_adjacent_zwj_emoji_are_not_merged(): void {
		$family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}";

		$this->assertSame( 1, preg_match_all( '/\X/u', str_repeat( $family, 140 ) ), 'documents the PCRE2 behaviour this guards against' );
		$this->assertSame( 280, SRL_Text::weighted_length( str_repeat( $family, 140 ) ) );
	}

	/** T-433 */
	public function test_url_inside_the_title_is_weighed_as_23(): void {
		$this->assertSame( 4 + 23, SRL_Text::weighted_length( 'see https://example.com/a/very/long/path' ) );
	}

	/** T-434 */
	public function test_bare_domain_in_title_is_weighed_conservatively(): void {
		$this->assertGreaterThanOrEqual( 23, SRL_Text::weighted_length( 'a.co' ) );
	}

	/** T-435 */
	public function test_abbreviations_are_not_treated_as_domains(): void {
		$this->assertSame( 4, SRL_Text::weighted_length( 'i.e.' ) );
		$this->assertSame( 4, SRL_Text::weighted_length( 'e.g.' ) );
	}

	/**
	 * T-440b
	 *
	 * X shortens a URL only if its host is valid. A DNS label over 63 octets
	 * is not, and X then charges for every character. Counting 23 here was a
	 * 12,000-unit under-count.
	 */
	public function test_invalid_url_host_is_weighed_literally_not_as_23(): void {
		$long_label = str_repeat( 'x', 200 );
		$url        = 'https://' . $long_label . '.com/';

		$this->assertGreaterThan( 100, SRL_Text::weighted_length( $url ) );
	}

	/** T-436 */
	public function test_combining_marks_are_counted_per_codepoint_after_nfc(): void {
		// "q" has no precomposed form with an acute, so NFC changes nothing and
		// all four code points are charged. A grapheme-cluster count would
		// charge 1 — a three-unit under-count, and under-counting overflows.
		$this->assertSame( 4, SRL_Text::weighted_length( "q\u{0301}\u{0302}\u{0303}" ) );

		// "a" + U+0301 does compose, to U+00E1, so NFC folds that pair and the
		// remaining two marks are charged separately: 1 + 2 = 3. The same input
		// shape as above costs one less purely because a composition exists,
		// which is why normalization has to happen before counting.
		$this->assertSame( 3, SRL_Text::weighted_length( "a\u{0301}\u{0302}\u{0303}" ) );

		// The simplest case of the same rule: composition makes two code
		// points into one, and X counts the composed form.
		$this->assertSame( 1, SRL_Text::weighted_length( "e\u{0301}" ) );
	}

	/** T-438 */
	public function test_thai_and_devanagari_weigh_one(): void {
		// Both sit inside the 0x0000-0x10FF weight-one range. The first draft
		// of SPEC.md section 7.1 wrongly glossed them as weight 2.
		$this->assertSame( 4, SRL_Text::weighted_length( 'ไทย' . 'ก' ) );
		$this->assertSame( 6, SRL_Text::weighted_length( 'हिन्दी' ) ); // Six code points, each weight 1.
	}

	/**
	 * T-437
	 *
	 * The decisive test: measured against X's own published conformance
	 * fixtures rather than against this plugin's counter. A test that measures
	 * the counter with the counter cannot catch a counting error, which is how
	 * two under-counts survived the first implementation.
	 */
	public function test_counter_matches_twitter_text_published_fixtures(): void {
		$path = SRL_PLUGIN_DIR . '/tests/fixtures/twitter-text-weighted.json';
		$this->assertFileExists( $path );

		$cases = json_decode( (string) file_get_contents( $path ), true );
		$this->assertIsArray( $cases );
		$this->assertGreaterThan( 15, count( $cases ) );

		foreach ( $cases as $case ) {
			$this->assertSame(
				$case['weightedLength'],
				SRL_Text::weighted_length( $case['text'] ),
				'twitter-text fixture: ' . $case['description']
			);
		}
	}

	/** T-420 */
	public function test_composition_order_is_prefix_title_suffix_newline_url(): void {
		$out = SRL_Text::compose( 'My Title', 'https://example.com/p/', 'New:', '#blog' );
		$this->assertSame( "New: My Title #blog\nhttps://example.com/p/", $out );
	}

	/** T-428 */
	public function test_empty_prefix_and_suffix_produce_no_double_spaces(): void {
		$out = SRL_Text::compose( 'My Title', 'https://example.com/p/' );
		$this->assertSame( "My Title\nhttps://example.com/p/", $out );
		$this->assertStringNotContainsString( '  ', $out );
	}

	/** T-424 */
	public function test_long_title_is_truncated_with_single_ellipsis_character(): void {
		$out = SRL_Text::compose( str_repeat( 'word ', 200 ), 'https://example.com/p/' );

		$this->assertLessThanOrEqual( SRL_Text::MAX_WEIGHTED, SRL_Text::weighted_length( $out ) );
		$this->assertStringContainsString( "\u{2026}", $out );
		$this->assertStringNotContainsString( '...', $out );
	}

	/** T-425 */
	public function test_url_is_never_truncated(): void {
		$permalink = 'https://example.com/a-very-long-permalink-that-must-survive-intact/';
		$this->assertStringEndsWith( $permalink, SRL_Text::compose( str_repeat( 'x', 5000 ), $permalink ) );
	}

	/** T-427 */
	public function test_result_never_exceeds_280_weighted_at_maximum_prefix_and_suffix(): void {
		foreach ( array( str_repeat( 'title ', 300 ), str_repeat( "\u{1F600}", 400 ), str_repeat( '日', 400 ) ) as $title ) {
			$out = SRL_Text::compose( $title, 'https://example.com/some/post/', str_repeat( 'p', 60 ), str_repeat( 's', 60 ) );
			$this->assertLessThanOrEqual( SRL_Text::MAX_WEIGHTED, SRL_Text::weighted_length( $out ) );
		}
	}

	/** T-426 */
	public function test_truncation_never_splits_a_grapheme_cluster(): void {
		$family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}\u{200D}\u{1F466}";
		$out    = SRL_Text::compose( str_repeat( 'a', 250 ) . $family . str_repeat( 'b', 50 ), 'https://example.com/p/' );

		$this->assertTrue( (bool) preg_match( '//u', $out ), 'output must be valid UTF-8' );

		// Either the whole sequence survives or none of it does, never a fragment.
		$fragment = "\u{1F468}\u{200D}\u{1F469}";
		if ( str_contains( $out, $fragment ) ) {
			$this->assertStringContainsString( $family, $out );
		}
		$this->assertLessThanOrEqual( SRL_Text::MAX_WEIGHTED, SRL_Text::weighted_length( $out ) );
	}
}
