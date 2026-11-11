<?php
/**
 * Test hooks of the customer display signalling routes (TallyUI fork, ADR-085 U7).
 *
 * @package WCPOS\WooCommercePOS\Services\Tally_Display
 */

namespace WCPOS\WooCommercePOS\Services\Tally_Display;

use WP_REST_Request;

/**
 * The live contract harness's hooks: a clock offset, a claim source header and
 * the WP-CLI commands. Every one is dead unless the site defines
 * WCPOS_TALLY_DISPLAY_TEST_HOOKS as true; the plugin never defines it.
 */
final class Test_Hooks {
	/** The constant a test site defines as true to enable the hooks. */
	const CONSTANT = 'WCPOS_TALLY_DISPLAY_TEST_HOOKS';

	/** Milliseconds added to the clock, as an integer option. */
	const CLOCK_OFFSET_OPTION = 'woocommerce_pos_tally_display_clock_offset_ms';

	/** The request header that names the claim source. */
	const SOURCE_HEADER = 'X-Tally-Test-Source';

	/** The display token header (the controller's TOKEN_HEADER). */
	const TOKEN_HEADER = 'X-Tally-Display-Token';

	/**
	 * The request headers the display routes read, for the CORS allow-list:
	 * the token header always, the source header only under the constant.
	 *
	 * @return string[]
	 */
	public static function cors_headers(): array {
		return self::enabled() ? array( self::TOKEN_HEADER, self::SOURCE_HEADER ) : array( self::TOKEN_HEADER );
	}

	/** Whether the hooks are enabled: the constant is defined and exactly true. */
	public static function enabled(): bool {
		return \defined( self::CONSTANT ) && true === \constant( self::CONSTANT );
	}

	/** The clock offset in milliseconds; 0 unless the hooks are enabled. */
	public static function clock_offset_ms(): int {
		if ( ! self::enabled() ) {
			return 0;
		}

		return (int) get_option( self::CLOCK_OFFSET_OPTION, 0 );
	}

	/**
	 * The claim source the test header names, or null when the hooks are off or the header is absent.
	 *
	 * @param WP_REST_Request $request The claim request.
	 */
	public static function source( WP_REST_Request $request ): ?string {
		if ( ! self::enabled() ) {
			return null;
		}
		$source = $request->get_header( self::SOURCE_HEADER );

		return \is_string( $source ) && '' !== $source ? $source : null;
	}
}
