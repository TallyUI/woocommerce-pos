<?php
/**
 * Route-level tests of the customer display signalling routes (TallyUI fork, ADR-085 U7).
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\Logger;
use WCPOS\WooCommercePOS\Services\Tally_Display\Advertisement;
use WCPOS\WooCommercePOS\Services\Tally_Display\Signalling;
use WCPOS\WooCommercePOS\Services\Tally_Display\Store;

/**
 * Each refusal's code and status, malformed bodies, identical refusals across
 * causes, a concurrent claim, the test header, and the advertisement.
 *
 * @internal
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Tally_Display_Controller
 * @covers \WCPOS\WooCommercePOS\Services\Tally_Display\Advertisement
 */
class Test_Tally_Display_Routes extends Test_Tally_Display_Harness {
	/**
	 * REMOTE_ADDR before the test.
	 *
	 * @var string|null
	 */
	private $remote_addr;

	/** Keep REMOTE_ADDR. */
	public function setUp(): void {
		parent::setUp();
		$this->remote_addr = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/** Restore REMOTE_ADDR and the option. */
	public function tearDown(): void {
		if ( null === $this->remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->remote_addr;
		}
		delete_option( Advertisement::ICE_OPTION );
		parent::tearDown();
	}

	/**
	 * Till routes refuse without the capability or a valid device id.
	 */
	public function test_till_routes_refuse_without_the_capability_or_a_valid_device_id(): void {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$this->refusal( $this->mint( $subscriber, self::A ), 'forbidden', 'a subscriber' );
		$this->refusal( $this->mint( $this->viewer, self::A ), 'forbidden', 'the can_pair filter' );
		$this->refusal( $this->mint( $this->cashier_a, 'bad id!' ), 'forbidden', 'a malformed device id' );
		$this->refusal( $this->mint( $this->cashier_a, str_repeat( 'a', 65 ) ), 'forbidden', 'a 65-character device id' );
		$this->refusal( $this->call( 'POST', 'codes', $this->cashier_a, null, array() ), 'forbidden', 'no device id' );
		$this->refusal( $this->call( 'GET', 'displays', $this->viewer, array( 'device_id' => self::A ) ), 'forbidden', 'list without the capability' );
		$this->refusal( $this->post_snapshot( $this->viewer, self::A, 'abc', '{}' ), 'forbidden', 'snapshot without the capability' );
		$this->assertSame( 0, $this->stored_counts()['codes'] );
	}

	/**
	 * A logged out till request gets the gates 401.
	 */
	public function test_a_logged_out_till_request_gets_the_gates_401(): void {
		$response = $this->call( 'POST', 'codes', 0, null, array( 'device_id' => self::A ) );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'woocommerce_pos_rest_unauthorized', $response->get_data()['code'] );
	}

	/**
	 * Every till route refuses a logged out caller with the gate's 401 and changes nothing.
	 */
	public function test_every_till_route_refuses_a_logged_out_caller_with_401(): void {
		$world  = $this->till_world();
		$before = array( $this->stored_counts(), $this->stored_text() );
		foreach ( $this->till_routes( $world['claim_id'], $world['display_id'] ) as $route => $send ) {
			$response = $send( 0 );
			$this->assertSame( 401, $response->get_status(), "{$route} as a logged out caller" );
			$this->assertSame( 'woocommerce_pos_rest_unauthorized', $response->get_data()['code'] ?? null, "{$route} as a logged out caller" );
		}
		$this->assertSame( $before, array( $this->stored_counts(), $this->stored_text() ), 'a logged out caller changed the store' );
	}

	/**
	 * Every till route refuses a caller without the capability and changes nothing.
	 */
	public function test_every_till_route_refuses_a_caller_without_the_capability(): void {
		$world   = $this->till_world();
		$before  = array( $this->stored_counts(), $this->stored_text() );
		$callers = array(
			'a subscriber'                => $this->factory->user->create( array( 'role' => 'subscriber' ) ),
			'a cashier the filter refuses' => $this->viewer,
		);
		foreach ( $callers as $who => $user ) {
			foreach ( $this->till_routes( $world['claim_id'], $world['display_id'] ) as $route => $send ) {
				$this->refusal( $send( $user ), 'forbidden', "{$route} as {$who}" );
			}
		}
		$this->assertSame( $before, array( $this->stored_counts(), $this->stored_text() ), 'a caller without the capability changed the store' );
	}

	/**
	 * An inbox keeps its newest 200 signals, one inbox per direction.
	 */
	public function test_an_inbox_keeps_its_newest_200_signals_one_per_direction(): void {
		$paired = $this->paired( $this->cashier_a, self::A, '192.0.2.1', 'Counter' );
		for ( $i = 0; $i < 250; ++$i ) {
			$this->ok( $this->send_signal( $this->cashier_a, self::A, $paired['display_id'], 'ice', 's' . $i ) );
			$this->ok( $this->display_send_signal( $paired['token'], 'ice', 'd' . $i ) );
		}
		$this->assertSame( Signalling::SIGNAL_INBOX_MAX, $this->inbox_count( $paired['display_id'], false ), 'the till to display inbox' );
		$this->assertSame( Signalling::SIGNAL_INBOX_MAX, $this->inbox_count( $paired['display_id'], true ), 'the display to till inbox' );
		$expected = array();
		$back     = array();
		for ( $i = 50; $i < 250; ++$i ) {
			$expected[] = 's' . $i;
			$back[]     = 'd' . $i;
		}
		$this->assertSame( $expected, array_column( $this->ok( $this->display_receive_signals( $paired['token'] ) ), 'payload' ) );
		$this->assertSame( $back, array_column( $this->receive_signals( $this->cashier_a, self::A ), 'payload' ) );
	}

	/**
	 * A full inbox in one direction trims only that direction.
	 */
	public function test_a_full_inbox_trims_only_its_own_direction(): void {
		$paired = $this->paired( $this->cashier_a, self::A, '192.0.2.1', 'Counter' );
		for ( $i = 0; $i < 3; ++$i ) {
			$this->ok( $this->send_signal( $this->cashier_a, self::A, $paired['display_id'], 'ice', 'q' . $i ) );
		}
		for ( $i = 0; $i < 250; ++$i ) {
			$this->ok( $this->display_send_signal( $paired['token'], 'ice', 'd' . $i ) );
		}
		$this->assertSame( 3, $this->inbox_count( $paired['display_id'], false ), 'the till to display inbox lost messages to the other direction\'s trim' );
		$back = array();
		for ( $i = 50; $i < 250; ++$i ) {
			$back[] = 'd' . $i;
		}
		$this->assertSame( array( 'q0', 'q1', 'q2' ), array_column( $this->ok( $this->display_receive_signals( $paired['token'] ) ), 'payload' ) );
		$this->assertSame( $back, array_column( $this->receive_signals( $this->cashier_a, self::A ), 'payload' ) );
	}

	/**
	 * Signals older than the TTL are pruned, and a fresh one is delivered.
	 */
	public function test_signals_older_than_the_ttl_are_pruned(): void {
		$paired = $this->paired( $this->cashier_a, self::A, '192.0.2.1', 'Counter' );
		for ( $i = 0; $i < 3; ++$i ) {
			$this->ok( $this->send_signal( $this->cashier_a, self::A, $paired['display_id'], 'ice', 'old' . $i ) );
		}
		$this->assertSame( 3, $this->inbox_count( $paired['display_id'], false ) );
		$this->advance( Signalling::SIGNAL_TTL );
		$this->list_displays( $this->cashier_a, self::A );
		$this->assertSame( 0, $this->inbox_count( $paired['display_id'], false ), 'a signal outlived the TTL' );
		$this->ok( $this->send_signal( $this->cashier_a, self::A, $paired['display_id'], 'offer', 'fresh' ) );
		$this->assertSame( array( 'fresh' ), array_column( $this->ok( $this->display_receive_signals( $paired['token'] ) ), 'payload' ) );
	}

	/**
	 * A version 1 store gains the signal age column and keys.
	 */
	public function test_a_version_1_store_gains_the_signal_age_column_and_keys(): void {
		global $wpdb;
		$table = Store::table( 'signals' );
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "CREATE TABLE {$table} ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, display_id char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, to_till tinyint(1) NOT NULL, type varchar(8) NOT NULL, payload mediumtext NOT NULL, PRIMARY KEY  (id), KEY display_id (display_id) ) " . $wpdb->get_charset_collate() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$v1_signal = array(
			'display_id' => str_repeat( 'd', 32 ),
			'to_till'    => 1,
			'type'       => 'offer',
			'payload'    => 'v1',
		);
		$v1_code   = array(
			'code'       => '123456',
			'device_id'  => 'till-a',
			'cashier_id' => $this->cashier_a,
			'expires_at' => 1900000000000,
			'spent'      => 0,
		);
		$this->assertSame( 1, $wpdb->insert( $table, $v1_signal ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 1, $wpdb->insert( Store::table( 'codes' ), $v1_code ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		update_option( Store::VERSION_OPTION, '1' );
		Store::reset_check();
		Store::ensure();
		foreach ( array( 'signals', 'codes' ) as $keyed ) {
			$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Store::table( $keyed ) ), "a version 1 {$keyed} row survived the upgrade" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		$this->assertContains( 'queued_at', $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$keys = $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$this->assertContains( 'queued_at', $keys );
		$this->assertContains( 'inbox', $keys );
		$this->assertSame( Store::VERSION, get_option( Store::VERSION_OPTION ) );
	}

	/**
	 * A device id needs at least 32 characters.
	 */
	public function test_a_device_id_shorter_than_32_characters_is_forbidden(): void {
		$this->refusal( $this->mint( $this->cashier_a, str_repeat( 'a', 31 ) ), 'forbidden', 'a 31-character device id' );
		$this->assertMatchesRegularExpression( '/^\d{6}$/', $this->ok( $this->mint( $this->cashier_a, str_repeat( 'a', 32 ) ) )['code'] );
	}

	/**
	 * The device id is stored only as its SHA-256 hex.
	 */
	public function test_the_device_id_is_stored_only_as_its_hash(): void {
		global $wpdb;
		$this->till_world();
		foreach ( Store::TABLES as $table ) {
			$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . Store::table( $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$this->assertStringNotContainsString( self::A, (string) wp_json_encode( $rows ), "the {$table} table holds the plaintext device id" );
		}
		foreach ( array( 'codes', 'pending', 'displays' ) as $table ) {
			$this->assertSame( array( Signalling::hash( self::A ) ), array_values( array_unique( $wpdb->get_col( 'SELECT device_id FROM ' . Store::table( $table ) ) ) ), "the {$table} rows are not keyed by the hash" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		$this->assertStringNotContainsString( self::A, $this->stored_text(), 'the CLI dump holds the plaintext device id' );
	}

	/**
	 * Another till is refused on every till route, whoever is signed in.
	 */
	public function test_another_till_is_refused_on_every_till_route(): void {
		$world  = $this->till_world();
		$a_rows = function (): array {
			$dump          = ( new Signalling() )->dump();
			$dump['codes'] = array_values(
				array_filter(
					$dump['codes'],
					function ( array $row ): bool {
						return Signalling::hash( self::B ) !== $row['device_id'];
					}
				)
			);

			return $dump;
		};
		$before = $a_rows();
		$empty  = array( 'GET pending', 'GET displays', 'POST signals/receive' );
		$callers = array(
			'cashier_b'           => $this->cashier_b,
			'cashier_a on till B' => $this->cashier_a,
		);
		foreach ( $callers as $who => $user ) {
			foreach ( $this->till_routes( $world['claim_id'], $world['display_id'], self::B ) as $route => $send ) {
				$what = "{$route} as {$who}";
				if ( 'POST codes' === $route ) {
					$this->assertMatchesRegularExpression( '/^\d{6}$/', $this->ok( $send( $user ) )['code'], $what );
				} elseif ( \in_array( $route, $empty, true ) ) {
					$this->assertSame( array(), $this->ok( $send( $user ) ), $what );
				} else {
					$this->refusal( $send( $user ), 'not_found', $what );
				}
			}
			$this->assertSame( $before, $a_rows(), "till A's claim, display, inboxes or snapshot changed after {$who}" );
		}
		$this->assertSame( 2, \count( ( new Signalling() )->dump()['codes'] ) - \count( $before['codes'] ), "till B's two codes" );
	}

	/**
	 * A device id, plaintext or hashed, never leaves the server: no response, log, meta or option carries it.
	 */
	public function test_the_device_id_is_in_no_response_log_meta_or_option(): void {
		global $wpdb;
		$leak     = str_pad( 'LEAKCHECK', 40, '-' );
		$served   = array();
		$logged   = array();
		$log_file = (string) tempnam( sys_get_temp_dir(), 'tally-leak' );
		$old_log  = ini_set( 'error_log', $log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$plugin   = function ( $allowed, $message ) use ( &$logged ) {
			$logged[] = (string) $message;

			return $allowed;
		};
		$woo      = function ( $message ) use ( &$logged ) {
			$logged[] = (string) $message;

			return $message;
		};
		add_filter( 'woocommerce_pos_logging', $plugin, 10, 2 );
		add_filter( 'woocommerce_logger_log_message', $woo, 10, 1 );
		$this->observe = function ( \WP_REST_Request $request, \WP_REST_Response $response ) use ( &$served ): void {
			$out      = apply_filters( 'rest_post_dispatch', $response, $this->server, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core hook: the headers and body as served.
			$served[] = $request->get_method() . ' ' . $request->get_route() . ' ' . wp_json_encode( array( $out->get_status(), $out->get_headers(), $out->get_data() ) );
		};
		try {
			$u      = $this->cashier_a;
			$paired = $this->paired( $u, $leak, '192.0.2.9', 'Leak' );
			$wait   = $this->claimed( $u, $leak, '192.0.2.9', 'Waiting' );
			for ( $i = 0; $i < 5; ++$i ) {
				$this->ok( $this->mint( $u, $leak ) );
			}
			$this->refusal( $this->mint( $u, $leak ), 'limit', 'a 6th code' );
			$this->ok( $this->send_signal( $u, $leak, $paired['display_id'], 'offer', 'o' ) );
			$this->ok( $this->display_send_signal( $paired['token'], 'answer', 'a' ) );
			$this->assertCount( 1, $this->receive_signals( $u, $leak ) );
			$this->assertCount( 1, $this->list_displays( $u, $leak ) );
			$this->ok( $this->post_snapshot( $u, $leak, $paired['display_id'], '{"seq":1}' ) );
			$this->refusal( $this->send_signal( $u, $leak, $paired['display_id'], 'offer', str_repeat( 'x', Signalling::PAYLOAD_MAX_BYTES + 1 ) ), 'too_large', 'a payload over 64 KiB' );
			$this->assertSame( 400, $this->send_signal( $u, $leak, $paired['display_id'], 'hello', 'x' )->get_status(), 'a bad signal type' );
			$this->assertSame( 400, $this->post_snapshot( $u, $leak, $paired['display_id'], '{"a":' )->get_status(), 'a snapshot that is not JSON' );
			$this->assertSame( 400, $this->call( 'POST', 'codes', $u, array( 'device_id' => $leak ), '{"device_id":', array(), true )->get_status(), 'a body that is not JSON' );
			$this->refusal( $this->confirm( $u, $leak, 'nope' ), 'not_found', 'confirm an unknown claim' );
			$this->refusal( $this->till( 'POST', 'pending/nope/decline', $u, $leak ), 'not_found', 'decline an unknown claim' );
			$this->refusal( $this->till( 'DELETE', 'displays/nope', $u, $leak ), 'not_found', 'forget an unknown display' );
			$this->refusal( $this->send_signal( $u, $leak, 'nope', 'offer', 'o' ), 'not_found', 'signal an unknown display' );
			$this->refusal( $this->post_snapshot( $u, $leak, 'nope', '{}' ), 'not_found', 'snapshot an unknown display' );
			$this->refusal( $this->mint( $u, $leak . '!' ), 'forbidden', 'a malformed device id' );
			$this->refusal( $this->mint( $this->viewer, $leak ), 'forbidden', 'a cashier the filter refuses' );
			$this->assertSame( 401, $this->mint( 0, $leak )->get_status(), 'a logged-out till' );
			$this->assertSame( array( 'declined' => true ), $this->ok( $this->till( 'POST', 'pending/' . $wait['claim_id'] . '/decline', $u, $leak ) ) );
			$this->assertSame( array( 'forgotten' => true ), $this->ok( $this->till( 'DELETE', 'displays/' . $paired['display_id'], $u, $leak ) ) );
			error_log( 'tally-leak canary' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			Logger::log( 'tally-leak canary' );
		} finally {
			$this->observe = null;
			remove_filter( 'woocommerce_pos_logging', $plugin, 10 );
			remove_filter( 'woocommerce_logger_log_message', $woo, 10 );
			ini_set( 'error_log', (string) $old_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
		$log = (string) file_get_contents( $log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		unlink( $log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertStringContainsString( 'tally-leak canary', $log, 'the error log capture is not live' );
		$this->assertContains( 'tally-leak canary', $logged, 'the plugin logger capture is not live' );
		$this->assertGreaterThan( 25, \count( $served ), 'the response capture missed routes' );
		$meta = array(
			$wpdb->postmeta => array( 'meta_key', 'meta_value' ),
			$wpdb->usermeta => array( 'meta_key', 'meta_value' ),
			$wpdb->options  => array( 'option_name', 'option_value' ),
		);
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders_meta' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$meta[ $wpdb->prefix . 'wc_orders_meta' ] = array( 'meta_key', 'meta_value' );
		}
		$needles = array(
			'the plaintext' => $leak,
			'the hash'      => Signalling::hash( $leak ),
		);
		foreach ( $needles as $what => $needle ) {
			foreach ( $served as $response ) {
				$this->assertStringNotContainsString( $needle, $response, "a response carries {$what}" );
			}
			$this->assertStringNotContainsString( $needle, implode( "\n", $logged ), "the plugin logger got {$what}" );
			$this->assertStringNotContainsString( $needle, $log, "the PHP error log got {$what}" );
			foreach ( $meta as $table => $columns ) {
				$like = '%' . $wpdb->esc_like( $needle ) . '%';
				$hits = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$columns[0]} LIKE %s OR {$columns[1]} LIKE %s", $like, $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				$this->assertSame( '0', $hits, "{$table} holds {$what}" );
			}
		}
	}

	/**
	 * Each refusal has its code and status.
	 */
	public function test_each_refusal_has_its_code_and_status(): void {
		for ( $i = 0; $i < 5; ++$i ) {
			$this->ok( $this->mint( $this->cashier_a, self::A ) );
		}
		$this->refusal( $this->mint( $this->cashier_a, self::A ), 'limit', 'a 6th code' );
		$this->refusal( $this->claim( '192.0.2.1', '999999x', 'Guess' ), 'claim_refused', 'an unknown code' );
		$this->refusal( $this->collect( 'AAAAAAAAAAAAAAAAAAAAAA' ), 'collect_refused', 'an unknown secret' );
		$this->refusal( $this->confirm( $this->cashier_a, self::A, 'nope' ), 'not_found', 'confirm an unknown claim' );
		$this->refusal( $this->till( 'POST', 'pending/nope/decline', $this->cashier_a, self::A ), 'not_found', 'decline an unknown claim' );
		$this->refusal( $this->till( 'DELETE', 'displays/nope', $this->cashier_a, self::A ), 'not_found', 'forget an unknown display' );
		$this->refusal( $this->send_signal( $this->cashier_a, self::A, 'nope', 'offer', 'o' ), 'not_found', 'signal an unknown display' );
		$this->refusal( $this->post_snapshot( $this->cashier_a, self::A, 'nope', '{}' ), 'not_found', 'snapshot an unknown display' );
		$this->refusal( $this->display_send_signal( 'not-a-token-not-a-token', 'answer', 'a' ), 'token_refused', 'send with a bad token' );
		$this->refusal( $this->display_receive_signals( 'not-a-token-not-a-token' ), 'token_refused', 'receive with a bad token' );
		$this->refusal( $this->call( 'GET', 'snapshot', 0 ), 'token_refused', 'a read with no token header' );
		$paired = $this->paired( $this->cashier_b, self::B, '192.0.2.2', 'Counter' );
		$big    = str_repeat( 'x', Signalling::PAYLOAD_MAX_BYTES + 1 );
		$this->refusal( $this->send_signal( $this->cashier_b, self::B, $paired['display_id'], 'offer', $big ), 'too_large', 'a till payload over 64 KiB' );
		$this->refusal( $this->display_send_signal( $paired['token'], 'answer', $big ), 'too_large', 'a display payload over 64 KiB' );
		$this->ok( $this->send_signal( $this->cashier_b, self::B, $paired['display_id'], 'offer', str_repeat( 'x', Signalling::PAYLOAD_MAX_BYTES ) ) );
		$snapshot = (string) wp_json_encode( array( 'pad' => str_repeat( 'x', 204800 ) ) );
		$this->refusal( $this->post_snapshot( $this->cashier_b, self::B, $paired['display_id'], $snapshot ), 'too_large', 'a snapshot over 200 KiB' );
	}

	/**
	 * Malformed bodies are rest invalid param.
	 */
	public function test_malformed_bodies_are_rest_invalid_param(): void {
		$code = $this->code( $this->cashier_a, self::A );
		foreach ( array( null, '', '   ' ) as $name ) {
			$body = array( 'code' => $code );
			if ( null !== $name ) {
				$body['display_name'] = $name;
			}
			$this->assertSame( 400, $this->call( 'POST', 'claim', 0, null, $body )->get_status(), 'display_name ' . wp_json_encode( $name ) );
		}
		$this->assertSame(
			'rest_invalid_param',
			$this->call(
				'POST',
				'claim',
				0,
				null,
				array(
					'code' => $code,
					'display_name' => ' ',
				)
			)->get_data()['code']
		);
		$this->assertSame( 400, $this->call( 'POST', 'collect', 0, null, array() )->get_status(), 'collect without a secret' );
		$broken = $this->call( 'POST', 'claim', 0, null, '{"code":', array(), true );
		$this->assertSame( array( 400, 'rest_invalid_param' ), array( $broken->get_status(), $broken->get_data()['code'] ), 'a claim body that is not JSON' );
		$paired = $this->paired( $this->cashier_a, self::A, '192.0.2.1', 'Counter' );
		$this->assertSame( 'rest_invalid_param', $this->send_signal( $this->cashier_a, self::A, $paired['display_id'], 'hello', 'x' )->get_data()['code'] );
		$this->assertSame( 'rest_invalid_param', $this->display_send_signal( $paired['token'], 'hello', 'x' )->get_data()['code'] );
		foreach ( array( '', 'not json', '{"a":' ) as $raw ) {
			$response = $this->post_snapshot( $this->cashier_a, self::A, $paired['display_id'], $raw );
			$this->assertSame( 400, $response->get_status(), 'snapshot ' . $raw );
			$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		}
		$this->assertSame( 0, $this->stored_counts()['snapshots'] );
	}

	/**
	 * The display name is trimmed and cut to 64 characters.
	 */
	public function test_the_display_name_is_trimmed_and_cut_to_64_characters(): void {
		$code = $this->code( $this->cashier_a, self::A );
		$this->ok( $this->claim( '192.0.2.1', $code, '  ' . str_repeat( 'é', 70 ) . '  ' ) );
		$names = array_column( $this->pending( $this->cashier_a, self::A ), 'display_name' );
		$this->assertContains( str_repeat( 'é', 64 ), $names );
		$code = $this->code( $this->cashier_a, self::A );
		$this->ok( $this->claim( '192.0.2.1', $code, "\t Till 2 \n" ) );
		$this->assertContains( 'Till 2', array_column( $this->pending( $this->cashier_a, self::A ), 'display_name' ) );
	}

	/**
	 * Claim refusals are identical whatever the cause.
	 */
	public function test_claim_refusals_are_identical_whatever_the_cause(): void {
		$spent = $this->code( $this->cashier_a, self::A );
		$this->ok( $this->claim( '192.0.2.1', $spent, 'First' ) );
		$expired = $this->code( $this->cashier_a, self::A );
		// The spent code is refused before the advance: prune deletes it once it expires.
		$bodies = array( 'spent' => $this->refusal( $this->claim( '192.0.2.2', $spent, 'X' ), 'claim_refused' ) );
		$this->advance( 10 * self::MINUTE );
		$bodies['expired'] = $this->refusal( $this->claim( '192.0.2.2', $expired, 'X' ), 'claim_refused' );
		$bodies['unknown'] = $this->refusal( $this->claim( '192.0.2.2', self::unused_code( $spent, $expired ), 'X' ), 'claim_refused' );
		$live = $this->code( $this->cashier_a, self::A );
		for ( $i = 0; $i < 10; ++$i ) {
			$this->claim( '198.51.100.9', self::unused_code( $live ), 'X' );
		}
		$bodies['source-limited'] = $this->refusal( $this->claim( '198.51.100.9', $live, 'X' ), 'claim_refused' );
		for ( $i = 0; $i < 100; ++$i ) {
			$this->claim( '203.0.113.' . ( intdiv( $i, 9 ) + 1 ), self::unused_code( $live ), 'X' );
		}
		$bodies['store-paused'] = $this->refusal( $this->claim( '203.0.113.250', $live, 'X' ), 'claim_refused' );
		foreach ( $bodies as $cause => $body ) {
			$this->assertSame( $bodies['spent'], $body, "the {$cause} refusal differs" );
		}
	}

	/**
	 * Collect refusals are identical whatever the cause.
	 */
	public function test_collect_refusals_are_identical_whatever_the_cause(): void {
		$paired   = $this->paired( $this->cashier_a, self::A, '192.0.2.1', 'One' );
		$declined = $this->claimed( $this->cashier_a, self::A, '192.0.2.1', 'Two' );
		$this->ok( $this->till( 'POST', 'pending/' . $declined['claim_id'] . '/decline', $this->cashier_a, self::A ) );
		$bodies = array(
			'collected' => $this->refusal( $this->collect( $paired['claim']['claim_secret'] ), 'collect_refused' ),
			'declined'  => $this->refusal( $this->collect( $declined['claim']['claim_secret'] ), 'collect_refused' ),
			'unknown'   => $this->refusal( $this->collect( 'AAAAAAAAAAAAAAAAAAAAAA' ), 'collect_refused' ),
		);
		foreach ( $bodies as $cause => $body ) {
			$this->assertSame( $bodies['collected'], $body, "the {$cause} refusal differs" );
		}
	}

	/**
	 * Two concurrent claims of one code make one pending pairing.
	 */
	public function test_two_concurrent_claims_of_one_code_make_one_pending_pairing(): void {
		$code   = $this->code( $this->cashier_a, self::A );
		$nested = null;
		$race   = function ( string $query ) use ( $code, &$nested ): string {
			// The second claim lands between the first claim's limit check and its spend.
			if ( null === $nested && false !== strpos( $query, 'SET spent = 1' ) ) {
				$nested = false;
				$nested = ( new Signalling() )->claim( $code, 'Nested', null, '192.0.2.9' );
			}

			return $query;
		};
		add_filter( 'query', $race );
		$outer = $this->claim( '192.0.2.8', $code, 'Outer' );
		remove_filter( 'query', $race );
		$this->assertIsArray( $nested, 'the nested claim was refused' );
		$this->refusal( $outer, 'claim_refused', 'the claim that lost the race' );
		$this->assertSame( 1, $this->stored_counts()['pending'] );
		$this->assertSame( array( 'Nested' ), array_column( $this->pending( $this->cashier_a, self::A ), 'display_name' ) );
	}

	/**
	 * The test source header is ignored without the constant.
	 */
	public function test_the_test_source_header_is_ignored_without_the_constant(): void {
		$this->assertFalse( \defined( 'WCPOS_TALLY_DISPLAY_TEST_HOOKS' ) );
		remove_filter( 'woocommerce_pos_tally_display_claim_source', array( $this, 'claim_source' ) );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$code                   = $this->code( $this->cashier_a, self::A );
		for ( $i = 0; $i < 10; ++$i ) {
			$response = $this->call(
				'POST',
				'claim',
				0,
				null,
				array(
					'code' => self::unused_code( $code ),
					'display_name' => 'X',
				),
				array( 'X-Tally-Test-Source' => '192.0.2.' . ( $i + 1 ) )
			);
			$this->refusal( $response, 'claim_refused' );
		}
		$limited = $this->call(
			'POST',
			'claim',
			0,
			null,
			array(
				'code' => $code,
				'display_name' => 'X',
			),
			array( 'X-Tally-Test-Source' => '192.0.2.200' )
		);
		$this->refusal( $limited, 'claim_refused', 'a fresh test source header escaped the limit on REMOTE_ADDR' );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.21';
		$this->ok(
			$this->call(
				'POST',
				'claim',
				0,
				null,
				array(
					'code' => $code,
					'display_name' => 'X',
				)
			)
		);
	}

	/**
	 * The advertisement defaults and validates the ice option.
	 */
	public function test_the_advertisement_defaults_and_validates_the_ice_option(): void {
		$default = array(
			array( 'urls' => 'stun:stun.cloudflare.com:3478' ),
			array( 'urls' => 'stun:stun.l.google.com:19302' ),
		);
		$this->assertSame(
			array(
				'v'          => 1,
				'iceServers' => $default,
				'snapshot'   => 1,
			),
			Advertisement::get()
		);
		$valid = array(
			array( 'urls' => 'stun:stun.example.com:3478' ),
			array(
				'urls'       => array( 'turn:turn.example.com:3478', 'turns:turn.example.com:5349' ),
				'username'   => 'user',
				'credential' => 'secret',
			),
		);
		update_option( Advertisement::ICE_OPTION, wp_json_encode( $valid ) );
		$this->assertSame( $valid, Advertisement::get()['iceServers'], 'a valid option' );
		$refused = array(
			'TURN without a credential' => array(
				array(
					'urls' => 'turn:turn.example.com:3478',
					'username' => 'user',
				),
			),
			'a bad scheme'              => array( array( 'urls' => 'https://stun.example.com' ) ),
			'one bad entry of two'      => array( array( 'urls' => 'stun:ok.example.com' ), array( 'urls' => '' ) ),
			'no urls'                   => array( array( 'username' => 'user' ) ),
			'not a list'                => array( 'urls' => 'stun:stun.example.com' ),
		);
		foreach ( $refused as $why => $option ) {
			update_option( Advertisement::ICE_OPTION, wp_json_encode( $option ) );
			$this->assertSame( $default, Advertisement::get()['iceServers'], $why );
		}
		update_option( Advertisement::ICE_OPTION, 'not json' );
		$this->assertSame( $default, Advertisement::get()['iceServers'], 'non-JSON' );
		update_option( Advertisement::ICE_OPTION, array( array( 'urls' => 'stun:stun.example.com' ) ) );
		$this->assertSame( $default, Advertisement::get()['iceServers'], 'an array option rather than a JSON string' );
		update_option( Advertisement::ICE_OPTION, '[]' );
		$this->assertSame( array(), Advertisement::get()['iceServers'], '[] is kept' );
	}

	/**
	 * The status route lists the capability and the advertisement.
	 */
	public function test_the_status_route_lists_the_capability_and_the_advertisement(): void {
		update_option( Advertisement::ICE_OPTION, '[{"urls":"stun:stun.example.com"}]' );
		$filter = static function ( array $advertisement ): array {
			$advertisement['snapshot'] = 0;

			return $advertisement;
		};
		add_filter( 'woocommerce_pos_tally_display_advertisement', $filter );
		wp_set_current_user( $this->cashier_a );
		$data = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/status' ) )->get_data();
		remove_filter( 'woocommerce_pos_tally_display_advertisement', $filter );
		$this->assertContains( 'customer_display', $data['capabilities'] );
		$this->assertSame(
			array(
				'v'          => 1,
				'iceServers' => array( array( 'urls' => 'stun:stun.example.com' ) ),
				'snapshot'   => 0,
			),
			$data['customer_display']
		);
	}

	/**
	 * Cashier A's live claim and display, with a message in each inbox and a snapshot.
	 *
	 * @return array{claim_id: string, display_id: string}
	 */
	private function till_world(): array {
		$paired = $this->paired( $this->cashier_a, self::A, '192.0.2.1', 'Counter' );
		$claim  = $this->claimed( $this->cashier_a, self::A, '192.0.2.1', 'Waiting' );
		$this->ok( $this->send_signal( $this->cashier_a, self::A, $paired['display_id'], 'offer', 'to-display' ) );
		$this->ok( $this->display_send_signal( $paired['token'], 'answer', 'to-till' ) );
		$this->ok( $this->post_snapshot( $this->cashier_a, self::A, $paired['display_id'], '{"seq":1}' ) );

		return array(
			'claim_id'   => $claim['claim_id'],
			'display_id' => $paired['display_id'],
		);
	}

	/**
	 * The 9 till routes, each a well-formed call for a device (A unless given) as a given user.
	 *
	 * @param string $claim_id   A real claim.
	 * @param string $display_id A real display.
	 * @param string $device     The device id sent.
	 *
	 * @return array<string, callable(int): \WP_REST_Response>
	 */
	private function till_routes( string $claim_id, string $display_id, string $device = self::A ): array {
		$till = function ( string $method, string $path, array $body = array() ) use ( $device ): callable {
			return function ( int $user ) use ( $method, $path, $body, $device ) {
				return $this->till( $method, $path, $user, $device, $body );
			};
		};
		$signal = array(
			'type'    => 'offer',
			'payload' => 'o',
		);

		return array(
			'POST codes'                            => $till( 'POST', 'codes' ),
			'GET pending'                           => $till( 'GET', 'pending' ),
			'POST pending/{claim_id}/confirm'       => $till( 'POST', "pending/{$claim_id}/confirm" ),
			'POST pending/{claim_id}/decline'       => $till( 'POST', "pending/{$claim_id}/decline" ),
			'GET displays'                          => $till( 'GET', 'displays' ),
			'DELETE displays/{display_id}'          => $till( 'DELETE', "displays/{$display_id}" ),
			'POST displays/{display_id}/signals'    => $till( 'POST', "displays/{$display_id}/signals", $signal ),
			'POST signals/receive'                  => $till( 'POST', 'signals/receive' ),
			'POST displays/{display_id}/snapshot'   => function ( int $user ) use ( $display_id, $device ) {
				return $this->post_snapshot( $user, $device, $display_id, '{"seq":2}' );
			},
		);
	}

	/**
	 * The stored messages of one inbox.
	 *
	 * @param string $display_id The display.
	 * @param bool   $to_till    The display to till direction, else till to display.
	 */
	private function inbox_count( string $display_id, bool $to_till ): int {
		$count = 0;
		foreach ( ( new Signalling() )->dump()['signals'] as $row ) {
			if ( $display_id === $row['display_id'] && ( $to_till ? 1 : 0 ) === (int) $row['to_till'] ) {
				++$count;
			}
		}

		return $count;
	}
}
