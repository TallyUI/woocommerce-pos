<?php
/**
 * The vectors of TallyUI's rate-limit-source.test.ts (TallyUI fork, ADR-085 U7).
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\WooCommercePOS\Services\Tally_Display\Rate_Limit_Source;
use WP_UnitTestCase;

/**
 * Rate_Limit_Source::key against the reference vectors.
 *
 * @covers \WCPOS\WooCommercePOS\Services\Tally_Display\Rate_Limit_Source
 */
class Test_Tally_Display_Rate_Limit_Source extends WP_UnitTestCase {
	/**
	 * The reference vectors.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function vectors(): array {
		return array(
			'IPv4 as given'          => array( '192.0.2.10', '192.0.2.10' ),
			'IPv4-mapped IPv6'       => array( '::ffff:192.0.2.10', '192.0.2.10' ),
			'IPv6 to its /64'        => array( '2001:db8:0:1::5', '2001:0db8:0000:0001::/64' ),
			'loopback'               => array( '::1', '0000:0000:0000:0000::/64' ),
			'zone index dropped'     => array( 'fe80::1%eth0', 'fe80:0000:0000:0000::/64' ),
			'upper case lowered'     => array( '2001:DB8::1', '2001:0db8:0000:0000::/64' ),
			'anything else trimmed'  => array( ' unknown ', 'unknown' ),
		);
	}

	/**
	 * Each vector.
	 *
	 * @dataProvider vectors
	 *
	 * @param string $source The source.
	 * @param string $key    The expected key.
	 */
	public function test_key_matches_the_reference_vector( string $source, string $key ): void {
		$this->assertSame( $key, Rate_Limit_Source::key( $source ) );
	}

	/** Two addresses in one /64 share a key; the next /64 does not. */
	public function test_one_64_shares_a_key(): void {
		$this->assertSame( Rate_Limit_Source::key( '2001:db8:0:1::5' ), Rate_Limit_Source::key( '2001:db8:0:1:ffff:ffff:ffff:ffff' ) );
		$this->assertNotSame( Rate_Limit_Source::key( '2001:db8:0:1::5' ), Rate_Limit_Source::key( '2001:db8:0:2::5' ) );
	}
}
