<?php
/**
 * The rate-limit key of a claim source, ported from TallyUI.
 *
 * @package WCPOS\WooCommercePOS\Services\Tally_Display
 */

namespace WCPOS\WooCommercePOS\Services\Tally_Display;

/**
 * A port of TallyUI's `rateLimitSource` (packages/core/src/customer-display/rate-limit-source.ts):
 * an IPv4 address (also IPv4-mapped `::ffff:a.b.c.d`) is itself; an IPv6
 * address counts by its /64, as its first four hextets expanded plus `::/64`,
 * with a zone id dropped. Anything else is the input, trimmed.
 */
final class Rate_Limit_Source {
	const IPV4   = '/^(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}$/D';
	const HEXTET = '/^[0-9a-f]{1,4}$/D';

	/**
	 * JavaScript's String.prototype.trim set: WhiteSpace and LineTerminator.
	 */
	const JS_SPACE = '[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

	/**
	 * The key that rate-limits failed claims from an address.
	 *
	 * @param string $address An IPv4 or IPv6 address, or anything else.
	 */
	public static function key( string $address ): string {
		$input = self::trim( $address );
		if ( 1 === preg_match( self::IPV4, $input ) ) {
			return $input;
		}
		$zone    = strpos( $input, '%' );
		$hextets = self::expand_ipv6( false !== $zone ? substr( $input, 0, $zone ) : $input );
		if ( null === $hextets ) {
			return $input;
		}
		if ( array( '0000', '0000', '0000', '0000', '0000' ) === \array_slice( $hextets, 0, 5 ) && 'ffff' === $hextets[5] ) {
			$high = (int) hexdec( $hextets[6] );
			$low  = (int) hexdec( $hextets[7] );

			return implode( '.', array( $high >> 8, $high & 255, $low >> 8, $low & 255 ) );
		}

		return implode( ':', \array_slice( $hextets, 0, 4 ) ) . '::/64';
	}

	/**
	 * The eight hextets of an IPv6 address, each 4 lower-case digits, or null when it is not one.
	 *
	 * @param string $address The address, without a zone id.
	 *
	 * @return string[]|null
	 */
	private static function expand_ipv6( string $address ): ?array {
		$text  = strtolower( $address );
		$colon = strrpos( $text, ':' );
		$last  = false === $colon ? $text : substr( $text, $colon + 1 );
		if ( 1 === preg_match( self::IPV4, $last ) ) {
			$parts = array_map( 'intval', explode( '.', $last ) );
			$text  = substr( $text, 0, \strlen( $text ) - \strlen( $last ) )
				. dechex( ( $parts[0] << 8 ) | $parts[1] ) . ':' . dechex( ( $parts[2] << 8 ) | $parts[3] );
		}
		$halves = explode( '::', $text );
		if ( \count( $halves ) > 2 ) {
			return null;
		}
		$head    = '' !== $halves[0] ? explode( ':', $halves[0] ) : array();
		$tail    = 2 === \count( $halves ) && '' !== $halves[1] ? explode( ':', $halves[1] ) : array();
		$missing = 8 - \count( $head ) - \count( $tail );
		if ( 2 === \count( $halves ) ? $missing < 1 : 0 !== $missing ) {
			return null;
		}
		$groups = array_merge( $head, array_fill( 0, $missing, '0' ), $tail );
		foreach ( $groups as $group ) {
			if ( 1 !== preg_match( self::HEXTET, $group ) ) {
				return null;
			}
		}

		return array_map(
			static function ( string $group ): string {
				return str_pad( $group, 4, '0', STR_PAD_LEFT );
			},
			$groups
		);
	}

	/**
	 * Trim as JavaScript does; a string that is not valid UTF-8 falls back to PHP's trim.
	 *
	 * @param string $text The text.
	 */
	private static function trim( string $text ): string {
		$trimmed = preg_replace( '/^' . self::JS_SPACE . '+|' . self::JS_SPACE . '+$/uD', '', $text );

		return null === $trimmed ? trim( $text ) : $trimmed;
	}
}
