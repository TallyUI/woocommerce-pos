<?php
/**
 * The customer display signalling service (TallyUI fork, ADR-085 U7).
 *
 * @package WCPOS\WooCommercePOS\Services\Tally_Display
 */

namespace WCPOS\WooCommercePOS\Services\Tally_Display;

use WP_Error;

/**
 * Every rule of TallyUI's display signalling seam over the Store tables,
 * following TallyUI's in-memory reference server
 * (packages/core/src/server/display-signalling/memory-server.ts). Each public
 * method prunes first, and gives its result or a `tally_display_<code>` error.
 *
 * Secrets (claim secrets, tokens) are stored only as SHA-256 hex and looked
 * up through indexed columns. The token is made at the first collect after
 * the confirm, as the reference does, so its plaintext is never stored.
 */
final class Signalling {
	const MINUTE             = 60000;
	const CODE_TTL           = 600000;  // 10 minutes.
	const MAX_UNSPENT        = 5;
	const SNAPSHOT_TTL       = 60000;
	const SNAPSHOT_MAX_BYTES = 204800;
	const PAYLOAD_MAX_BYTES  = 65536;
	const SOURCE_WINDOW      = 60000;
	const SOURCE_MAX         = 10;
	const STORE_WINDOW       = 600000;  // 10 minutes.
	const STORE_MAX          = 100;
	const CONNECTED_MS       = 30000;
	const ROW_TTL            = 2592000000;  // 30 days.
	const NAME_MAX           = 64;

	/** Each seam error code's HTTP status. */
	const STATUSES = array(
		'forbidden'       => 403,
		'limit'           => 429,
		'claim_refused'   => 403,
		'collect_refused' => 403,
		'not_found'       => 404,
		'token_refused'   => 401,
		'too_large'       => 413,
	);

	/** Each error's one message, the same whatever the cause. */
	const MESSAGES = array(
		'forbidden'       => 'This session may not pair customer displays.',
		'limit'           => 'This device holds the most unspent pairing codes allowed.',
		'claim_refused'   => 'The pairing code was refused.',
		'collect_refused' => 'The claim was refused.',
		'not_found'       => 'No such pairing or display on this device.',
		'token_refused'   => 'The display token was refused.',
		'too_large'       => 'The body is too large.',
	);

	/**
	 * The refusal for a seam error code.
	 *
	 * @param string $code A key of STATUSES.
	 */
	public static function error( string $code ): WP_Error {
		return new WP_Error( 'tally_display_' . $code, self::MESSAGES[ $code ], array( 'status' => self::STATUSES[ $code ] ) );
	}

	/** 16 random bytes, base64url without padding: 22 characters. */
	public static function new_secret(): string {
		return rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url of random bytes.
	}

	/**
	 * SHA-256 hex.
	 *
	 * @param string $text The text.
	 */
	public static function hash( string $text ): string {
		return hash( 'sha256', $text );
	}

	/**
	 * Delete whatever has expired; gives the time the call runs at.
	 */
	public function prune(): int {
		global $wpdb;
		Store::ensure();
		$now = Clock::now_ms();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Store::table( 'codes' ) . ' WHERE expires_at <= %d', $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Store::table( 'pending' ) . ' WHERE expires_at <= %d', $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Store::table( 'snapshots' ) . ' WHERE posted_at <= %d', $now - self::SNAPSHOT_TTL ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Store::table( 'failures' ) . ' WHERE failed_at <= %d', $now - self::STORE_WINDOW ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$stale = $wpdb->get_col( $wpdb->prepare( 'SELECT display_id FROM ' . Store::table( 'displays' ) . ' WHERE COALESCE(last_seen_at, confirmed_at) <= %d', $now - self::ROW_TTL ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( $stale as $display_id ) {
			$this->drop_display( (string) $display_id );
		}

		return $now;
	}

	/**
	 * Mint a pairing code for a device.
	 *
	 * @param string $device_id  The till device.
	 * @param int    $cashier_id The minting cashier.
	 *
	 * @return array|WP_Error
	 */
	public function mint( string $device_id, int $cashier_id ) {
		global $wpdb;
		$now     = $this->prune();
		$unspent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Store::table( 'codes' ) . ' WHERE device_id = %s AND spent = 0', $device_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( $unspent >= self::MAX_UNSPENT ) {
			return self::error( 'limit' );
		}
		$expires_at = $now + self::CODE_TTL;
		// A collision with a live code is one in 10^6 per attempt; a bound keeps the loop finite.
		for ( $attempt = 0; $attempt < 50; ++$attempt ) {
			$code     = \sprintf( '%06d', random_int( 0, 999999 ) );
			$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . Store::table( 'codes' ) . ' (code, device_id, cashier_id, expires_at, spent) VALUES (%s, %s, %d, %d, 0)', $code, $device_id, $cashier_id, $expires_at ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( 1 === $inserted ) {
				return array(
					'code'       => $code,
					'expires_at' => Clock::iso( $expires_at ),
				);
			}
		}

		return self::error( 'limit' );
	}

	/**
	 * The live claims on codes a device minted.
	 *
	 * @param string $device_id The till device.
	 */
	public function pending( string $device_id ): array {
		global $wpdb;
		$this->prune();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT claim_id, display_name, check_code, expires_at FROM ' . Store::table( 'pending' ) . ' WHERE device_id = %s ORDER BY expires_at, claim_id', $device_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

		return array_map(
			static function ( array $row ): array {
				return array(
					'claim_id'     => $row['claim_id'],
					'display_name' => $row['display_name'],
					'check_code'   => $row['check_code'],
					'expires_at'   => Clock::iso( (int) $row['expires_at'] ),
				);
			},
			(array) $rows
		);
	}

	/**
	 * Confirm a claim on a code this device minted.
	 *
	 * @param string $device_id  The till device.
	 * @param string $claim_id   The pending pairing.
	 * @param int    $cashier_id The confirming cashier.
	 *
	 * @return true|WP_Error
	 */
	public function confirm( string $device_id, string $claim_id, int $cashier_id ) {
		global $wpdb;
		$now   = $this->prune();
		$entry = $this->owned_pending( $device_id, $claim_id );
		if ( null === $entry ) {
			return self::error( 'not_found' );
		}
		// The delete decides a race between two confirms of one claim.
		$taken = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Store::table( 'pending' ) . ' WHERE claim_id = %s AND device_id = %s', $claim_id, $device_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( 1 !== $taken ) {
			return self::error( 'not_found' );
		}
		// A moving display: its old row, token, snapshot and inbox go in this same step, and its id carries over.
		$moving = null;
		if ( null !== $entry['moving_display_id'] && null !== $this->display( (string) $entry['moving_display_id'] ) ) {
			$moving = (string) $entry['moving_display_id'];
			$this->drop_display( $moving );
		}
		// The token is made at the first collect, so only its hash is ever stored; the claim secret's hash waits here.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Store::table( 'displays' ),
			array(
				'display_id'   => $moving ?? bin2hex( random_bytes( 16 ) ),
				'device_id'    => $device_id,
				'name'         => $entry['display_name'],
				'cashier_id'   => $cashier_id,
				'confirmed_at' => $now,
				'secret_hash'  => $entry['secret_hash'],
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		return true;
	}

	/**
	 * Decline a claim on a code this device minted.
	 *
	 * @param string $device_id The till device.
	 * @param string $claim_id  The pending pairing.
	 *
	 * @return true|WP_Error
	 */
	public function decline( string $device_id, string $claim_id ) {
		global $wpdb;
		$this->prune();
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Store::table( 'pending' ) . ' WHERE claim_id = %s AND device_id = %s', $claim_id, $device_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

		return 1 === $deleted ? true : self::error( 'not_found' );
	}

	/**
	 * The displays a device owns.
	 *
	 * @param string $device_id The till device.
	 */
	public function list_displays( string $device_id ): array {
		global $wpdb;
		$now  = $this->prune();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT display_id, name, cashier_id, last_seen_at FROM ' . Store::table( 'displays' ) . ' WHERE device_id = %s ORDER BY confirmed_at, display_id', $device_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

		return array_map(
			static function ( array $row ) use ( $now ): array {
				$seen = null === $row['last_seen_at'] ? null : (int) $row['last_seen_at'];

				return array(
					'display_id'   => $row['display_id'],
					'name'         => $row['name'],
					'cashier_id'   => (string) $row['cashier_id'],
					'last_seen_at' => null === $seen ? null : Clock::iso( $seen ),
					'connected'    => null !== $seen && $now - $seen <= self::CONNECTED_MS,
				);
			},
			(array) $rows
		);
	}

	/**
	 * Forget a display this device owns: its row, token hash, snapshot and inbox at once.
	 *
	 * @param string $device_id  The till device.
	 * @param string $display_id The display.
	 *
	 * @return true|WP_Error
	 */
	public function forget( string $device_id, string $display_id ) {
		$this->prune();
		if ( ! $this->owns( $device_id, $display_id ) ) {
			return self::error( 'not_found' );
		}
		$this->drop_display( $display_id );

		return true;
	}

	/**
	 * Queue a message in an owned display's inbox.
	 *
	 * @param string $device_id  The till device.
	 * @param string $display_id The display.
	 * @param string $type       offer, answer or ice.
	 * @param string $payload    Opaque.
	 *
	 * @return true|WP_Error
	 */
	public function send_signal( string $device_id, string $display_id, string $type, string $payload ) {
		$this->prune();
		if ( ! $this->owns( $device_id, $display_id ) ) {
			return self::error( 'not_found' );
		}

		return $this->queue( $display_id, false, $type, $payload );
	}

	/**
	 * Drain a device's inbox: each message once, in send order, only from displays it owns.
	 *
	 * @param string $device_id The till device.
	 */
	public function receive_signals( string $device_id ): array {
		global $wpdb;
		$this->prune();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT s.id, s.display_id, s.type, s.payload FROM ' . Store::table( 'signals' ) . ' s INNER JOIN ' . Store::table( 'displays' ) . ' d ON d.display_id = s.display_id WHERE s.to_till = 1 AND d.device_id = %s ORDER BY s.id', $device_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$this->delete_signals( (array) $rows );

		return array_map(
			static function ( array $row ): array {
				return array(
					'display_id' => $row['display_id'],
					'type'       => $row['type'],
					'payload'    => $row['payload'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Keep a body as an owned display's only snapshot.
	 *
	 * @param string $device_id  The till device.
	 * @param string $display_id The display.
	 * @param string $raw_body   The JSON body as received.
	 *
	 * @return true|WP_Error
	 */
	public function post_snapshot( string $device_id, string $display_id, string $raw_body ) {
		global $wpdb;
		$now = $this->prune();
		if ( ! $this->owns( $device_id, $display_id ) ) {
			return self::error( 'not_found' );
		}
		if ( \strlen( $raw_body ) > self::SNAPSHOT_MAX_BYTES ) {
			return self::error( 'too_large' );
		}
		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Store::table( 'snapshots' ),
			array(
				'display_id' => $display_id,
				'body'       => $raw_body,
				'posted_at'  => $now,
			),
			array( '%s', '%s', '%d' )
		);

		return true;
	}

	/**
	 * Claim a live code. Every refusal is the one claim_refused.
	 *
	 * @param string      $code         The pairing code.
	 * @param string      $display_name The display's name, already trimmed.
	 * @param string|null $token        The display's current token, if any.
	 * @param string      $source       The claim source (an IP address).
	 *
	 * @return array|WP_Error
	 */
	public function claim( string $code, string $display_name, ?string $token, string $source ) {
		global $wpdb;
		$claim_secret = self::new_secret();
		$now          = $this->prune();
		$source_hash  = self::hash( Rate_Limit_Source::key( $source ) );
		$failures     = Store::table( 'failures' );
		$store_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$failures} WHERE failed_at > %d", $now - self::STORE_WINDOW ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$source_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$failures} WHERE source_hash = %s AND failed_at > %d", $source_hash, $now - self::SOURCE_WINDOW ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		// Over either limit, every claim is refused and spends nothing.
		if ( $store_count >= self::STORE_MAX || $source_count >= self::SOURCE_MAX ) {
			return self::error( 'claim_refused' );
		}
		// The affected-row count of this one update decides a race between two claims of one code.
		$spent = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Store::table( 'codes' ) . ' SET spent = 1 WHERE code = %s AND spent = 0 AND expires_at > %d', $code, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$entry = 1 === $spent ? $wpdb->get_row( $wpdb->prepare( 'SELECT device_id, expires_at FROM ' . Store::table( 'codes' ) . ' WHERE code = %s', $code ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! \is_array( $entry ) ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$failures,
				array(
					'source_hash' => $source_hash,
					'failed_at'   => $now,
				),
				array( '%s', '%d' )
			);

			return self::error( 'claim_refused' );
		}
		$moving = null;
		if ( null !== $token && '' !== $token ) {
			$moving = $wpdb->get_var( $wpdb->prepare( 'SELECT display_id FROM ' . Store::table( 'displays' ) . ' WHERE token_hash = %s', self::hash( $token ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		$check_code = $this->fresh_check_code();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Store::table( 'pending' ),
			array(
				'claim_id'          => bin2hex( random_bytes( 16 ) ),
				'device_id'         => $entry['device_id'],
				'secret_hash'       => self::hash( $claim_secret ),
				'display_name'      => $display_name,
				'check_code'        => $check_code,
				'expires_at'        => (int) $entry['expires_at'],
				'moving_display_id' => null === $moving ? null : (string) $moving,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return array(
			'claim_secret' => $claim_secret,
			'check_code'   => $check_code,
		);
	}

	/**
	 * Collect a claim: pending with its check code, then paired with the token once.
	 *
	 * @param string $claim_secret The claim secret.
	 *
	 * @return array|WP_Error
	 */
	public function collect( string $claim_secret ) {
		global $wpdb;
		$this->prune();
		$secret_hash = self::hash( $claim_secret );
		$check_code  = $wpdb->get_var( $wpdb->prepare( 'SELECT check_code FROM ' . Store::table( 'pending' ) . ' WHERE secret_hash = %s', $secret_hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( null !== $check_code ) {
			return array(
				'status'     => 'pending',
				'check_code' => (string) $check_code,
			);
		}
		$displays   = Store::table( 'displays' );
		$display_id = $wpdb->get_var( $wpdb->prepare( "SELECT display_id FROM {$displays} WHERE secret_hash = %s AND token_hash IS NULL", $secret_hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( null === $display_id ) {
			return self::error( 'collect_refused' );
		}
		$token = self::new_secret();
		// The update decides a race between two collects: only one issues the token.
		$issued = $wpdb->query( $wpdb->prepare( "UPDATE {$displays} SET token_hash = %s, secret_hash = NULL WHERE display_id = %s AND secret_hash = %s AND token_hash IS NULL", self::hash( $token ), $display_id, $secret_hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( 1 !== $issued ) {
			return self::error( 'collect_refused' );
		}

		return array(
			'status'     => 'paired',
			'display_id' => (string) $display_id,
			'token'      => $token,
		);
	}

	/**
	 * Queue a message in the owning till's inbox.
	 *
	 * @param string $token   The display token.
	 * @param string $type    offer, answer or ice.
	 * @param string $payload Opaque.
	 *
	 * @return true|WP_Error
	 */
	public function display_send_signal( string $token, string $type, string $payload ) {
		$display_id = $this->by_token( $token );
		if ( null === $display_id ) {
			return self::error( 'token_refused' );
		}

		return $this->queue( $display_id, true, $type, $payload );
	}

	/**
	 * Drain a display's own inbox: each message once, in send order.
	 *
	 * @param string $token The display token.
	 *
	 * @return array|WP_Error
	 */
	public function display_receive_signals( string $token ) {
		global $wpdb;
		$display_id = $this->by_token( $token );
		if ( null === $display_id ) {
			return self::error( 'token_refused' );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, type, payload FROM ' . Store::table( 'signals' ) . ' WHERE display_id = %s AND to_till = 0 ORDER BY id', $display_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$this->delete_signals( (array) $rows );

		return array_map(
			static function ( array $row ): array {
				return array(
					'type'    => $row['type'],
					'payload' => $row['payload'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * The display's latest snapshot, or null when none is held.
	 *
	 * @param string $token The display token.
	 *
	 * @return mixed|WP_Error
	 */
	public function read_snapshot( string $token ) {
		global $wpdb;
		$display_id = $this->by_token( $token );
		if ( null === $display_id ) {
			return self::error( 'token_refused' );
		}
		$body = $wpdb->get_var( $wpdb->prepare( 'SELECT body FROM ' . Store::table( 'snapshots' ) . ' WHERE display_id = %s', $display_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

		return null === $body ? null : json_decode( (string) $body );
	}

	/**
	 * The live rows of the four counted tables (the test harness's storedCounts).
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		global $wpdb;
		$this->prune();
		$counts = array();
		foreach ( array( 'codes', 'pending', 'displays', 'snapshots' ) as $table ) {
			$counts[ $table ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Store::table( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}

		return $counts;
	}

	/**
	 * Every stored row of every table (the test harness's storedText).
	 *
	 * @return array<string, array>
	 */
	public function dump(): array {
		global $wpdb;
		$this->prune();
		$dump = array();
		foreach ( Store::TABLES as $table ) {
			$dump[ $table ] = (array) $wpdb->get_results( 'SELECT * FROM ' . Store::table( $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}

		return $dump;
	}

	/** Empty every table (the test harness's fresh world). */
	public function reset(): void {
		global $wpdb;
		Store::ensure();
		foreach ( Store::TABLES as $table ) {
			$wpdb->query( 'DELETE FROM ' . Store::table( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * The display a token names, marked seen; null when it names none.
	 *
	 * @param string $token The display token.
	 */
	private function by_token( string $token ): ?string {
		global $wpdb;
		$now = $this->prune();
		if ( '' === $token ) {
			return null;
		}
		$displays   = Store::table( 'displays' );
		$display_id = $wpdb->get_var( $wpdb->prepare( "SELECT display_id FROM {$displays} WHERE token_hash = %s", self::hash( $token ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( null === $display_id ) {
			return null;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$displays} SET last_seen_at = %d WHERE display_id = %s", $now, $display_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		return (string) $display_id;
	}

	/**
	 * Queue one message.
	 *
	 * @param string $display_id The display.
	 * @param bool   $to_till    Whether the message goes to the owning till.
	 * @param string $type       offer, answer or ice.
	 * @param string $payload    Opaque.
	 *
	 * @return true|WP_Error
	 */
	private function queue( string $display_id, bool $to_till, string $type, string $payload ) {
		global $wpdb;
		if ( \strlen( $payload ) > self::PAYLOAD_MAX_BYTES ) {
			return self::error( 'too_large' );
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Store::table( 'signals' ),
			array(
				'display_id' => $display_id,
				'to_till'    => $to_till ? 1 : 0,
				'type'       => $type,
				'payload'    => $payload,
			),
			array( '%s', '%d', '%s', '%s' )
		);

		return true;
	}

	/**
	 * Delete drained messages.
	 *
	 * @param array $rows Rows with an id.
	 */
	private function delete_signals( array $rows ): void {
		global $wpdb;
		$ids = array_map( 'intval', array_column( $rows, 'id' ) );
		if ( array() === $ids ) {
			return;
		}
		$wpdb->query( 'DELETE FROM ' . Store::table( 'signals' ) . ' WHERE id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete a display's row (with its token hash), snapshot and both inboxes.
	 *
	 * @param string $display_id The display.
	 */
	private function drop_display( string $display_id ): void {
		global $wpdb;
		foreach ( array( 'displays', 'snapshots', 'signals' ) as $table ) {
			$wpdb->delete( Store::table( $table ), array( 'display_id' => $display_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * A display's row, or null.
	 *
	 * @param string $display_id The display.
	 */
	private function display( string $display_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT display_id, device_id FROM ' . Store::table( 'displays' ) . ' WHERE display_id = %s', $display_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

		return \is_array( $row ) ? $row : null;
	}

	/**
	 * Whether a device owns a display.
	 *
	 * @param string $device_id  The till device.
	 * @param string $display_id The display.
	 */
	private function owns( string $device_id, string $display_id ): bool {
		$row = $this->display( $display_id );

		return null !== $row && hash_equals( (string) $row['device_id'], $device_id );
	}

	/**
	 * A pending pairing a device owns, or null.
	 *
	 * @param string $device_id The till device.
	 * @param string $claim_id  The pending pairing.
	 */
	private function owned_pending( string $device_id, string $claim_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Store::table( 'pending' ) . ' WHERE claim_id = %s AND device_id = %s', $claim_id, $device_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

		return \is_array( $row ) ? $row : null;
	}

	/** A 4-digit check code no live pending pairing holds. */
	private function fresh_check_code(): string {
		global $wpdb;
		$taken = array_flip( (array) $wpdb->get_col( 'SELECT check_code FROM ' . Store::table( 'pending' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$full  = \count( $taken ) >= 10000;
		do {
			$check_code = \sprintf( '%04d', random_int( 0, 9999 ) );
		} while ( isset( $taken[ $check_code ] ) && ! $full );

		return $check_code;
	}
}
