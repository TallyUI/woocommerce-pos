<?php
/**
 * The clock of the customer display signalling routes.
 *
 * @package WCPOS\WooCommercePOS\Services\Tally_Display
 */

namespace WCPOS\WooCommercePOS\Services\Tally_Display;

/**
 * Every "now" of the signalling routes, in Unix milliseconds. PHPUnit sets it
 * directly; a test site moves it with the clock-offset option (Test_Hooks).
 */
final class Clock {
	/**
	 * A fixed time set by a test, or null for the real clock.
	 *
	 * @var int|null
	 */
	private static $fixed_ms = null;

	/** Now, in Unix milliseconds. */
	public static function now_ms(): int {
		$base = self::$fixed_ms ?? (int) floor( microtime( true ) * 1000 );

		return $base + Test_Hooks::clock_offset_ms();
	}

	/**
	 * Fix the clock at a time (tests), or give it back to the real clock with null.
	 *
	 * @param int|null $ms Unix milliseconds.
	 */
	public static function set( ?int $ms ): void {
		self::$fixed_ms = $ms;
	}

	/**
	 * Move a fixed clock forward (tests). A real clock is fixed at now first.
	 *
	 * @param int $ms Milliseconds.
	 */
	public static function advance( int $ms ): void {
		self::$fixed_ms = ( self::$fixed_ms ?? (int) floor( microtime( true ) * 1000 ) ) + $ms;
	}

	/**
	 * A time as ISO 8601 UTC with milliseconds, e.g. 2026-11-11T10:00:00.000Z.
	 *
	 * @param int $ms Unix milliseconds.
	 */
	public static function iso( int $ms ): string {
		$seconds = intdiv( $ms, 1000 );
		$millis  = $ms - $seconds * 1000;
		if ( $millis < 0 ) {
			--$seconds;
			$millis += 1000;
		}

		return gmdate( 'Y-m-d\TH:i:s', $seconds ) . \sprintf( '.%03dZ', $millis );
	}
}
