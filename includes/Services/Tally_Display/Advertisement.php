<?php
/**
 * The customer display advertisement on GET /wcpos/v2/status.
 *
 * @package WCPOS\WooCommercePOS\Services\Tally_Display
 */

namespace WCPOS\WooCommercePOS\Services\Tally_Display;

/**
 * Builds `customer_display` for the status route. Its inner keys are camelCase
 * because the connector hands the object to TallyUI's parseDisplayAdvertisement
 * unchanged; the ICE list is validated as that parser's parseIceServer does.
 */
final class Advertisement {
	/** A JSON array that replaces the default ICE list; `[]` is LAN-only. */
	const ICE_OPTION = 'woocommerce_pos_tally_display_ice_servers';

	/** ADR-085 d7's default: two operators' public STUN servers. */
	const DEFAULT_ICE_SERVERS = array(
		array( 'urls' => 'stun:stun.cloudflare.com:3478' ),
		array( 'urls' => 'stun:stun.l.google.com:19302' ),
	);

	const SCHEMES = array( 'stun:', 'stuns:', 'turn:', 'turns:' );

	/**
	 * The advertisement object.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$advertisement = array(
			'v'          => 1,
			'iceServers' => self::ice_servers(),
			'snapshot'   => 1,
		);

		/**
		 * Filters the customer display advertisement on GET /wcpos/v2/status (TallyUI fork).
		 *
		 * @param array $advertisement `{ v: 1, iceServers: [...], snapshot: 1 }`.
		 */
		return (array) apply_filters( 'woocommerce_pos_tally_display_advertisement', $advertisement );
	}

	/**
	 * The ICE list: the option when it is a valid JSON array, the default otherwise.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function ice_servers(): array {
		$option = get_option( self::ICE_OPTION, null );
		if ( ! \is_string( $option ) ) {
			return self::DEFAULT_ICE_SERVERS;
		}
		$list = json_decode( $option );
		if ( ! \is_array( $list ) ) {
			return self::DEFAULT_ICE_SERVERS;
		}
		$servers = array();
		foreach ( $list as $entry ) {
			$server = self::parse_server( $entry );
			if ( null === $server ) {
				return self::DEFAULT_ICE_SERVERS;
			}
			$servers[] = $server;
		}

		return $servers;
	}

	/**
	 * One entry, keeping only urls, username and credential; null refuses the whole list.
	 *
	 * @param mixed $entry A decoded JSON value.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function parse_server( $entry ): ?array {
		if ( ! $entry instanceof \stdClass ) {
			return null;
		}
		$urls       = $entry->urls ?? null;
		$username   = $entry->username ?? null;
		$credential = $entry->credential ?? null;
		$list       = \is_string( $urls ) ? array( $urls ) : $urls;
		if ( ! \is_array( $list ) || array() === $list ) {
			return null;
		}
		$turn = false;
		foreach ( $list as $url ) {
			if ( ! \is_string( $url ) || '' === $url || ! self::has_scheme( $url ) ) {
				return null;
			}
			$turn = $turn || 0 === strpos( $url, 'turn:' ) || 0 === strpos( $url, 'turns:' );
		}
		if ( $turn && ! ( \is_string( $username ) && '' !== $username && \is_string( $credential ) && '' !== $credential ) ) {
			return null;
		}
		$server = array( 'urls' => \is_string( $urls ) ? $urls : array_values( $list ) );
		if ( \is_string( $username ) ) {
			$server['username'] = $username;
		}
		if ( \is_string( $credential ) ) {
			$server['credential'] = $credential;
		}

		return $server;
	}

	/**
	 * Whether a URL starts with one of SCHEMES.
	 *
	 * @param string $url The URL.
	 */
	private static function has_scheme( string $url ): bool {
		foreach ( self::SCHEMES as $scheme ) {
			if ( 0 === strpos( $url, $scheme ) ) {
				return true;
			}
		}

		return false;
	}
}
