<?php
/**
 * Route-level tests of the customer display signalling routes (TallyUI fork, ADR-085 U7).
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\Services\Tally_Display\Advertisement;
use WCPOS\WooCommercePOS\Services\Tally_Display\Signalling;

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
}
