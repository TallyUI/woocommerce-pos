<?php
/**
 * The REST harness of the customer display signalling tests (TallyUI fork, ADR-085 U7).
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\Services\Tally_Display\Clock;
use WCPOS\WooCommercePOS\Services\Tally_Display\Signalling;
use WCPOS\WooCommercePOS\Services\Tally_Display\Store;
use WP_REST_Request;
use WP_REST_Response;

/**
 * TallyUI's DisplaySignallingHarness over wcpos/v2/tally/display: `advance` is
 * the settable clock, `display(source)` the claim source filter, `storedText`
 * and `storedCounts` the service's dump and counts (which the WP-CLI commands
 * print), and each test starts from emptied tables.
 */
abstract class Test_Tally_Display_Harness extends WCPOS_REST_Unit_Test_Case {
	/** Two tills' device ids: uuidv7, as TallyUI makes them (36 characters, at least the 32 the routes need). */
	const A      = '0192f5a8-3c1e-7a4b-9d2e-5f6a7b8c9d0e';
	const B      = '0192f5a8-3c1e-7c4d-8e1f-0a1b2c3d4e5f';
	const MINUTE = 60000;
	const DAY    = 86400000;
	const SECRET = '/^[A-Za-z0-9_-]{22,}$/D';

	/** The spec's status for each seam error code, written out rather than read from the service. */
	const STATUSES = array(
		'forbidden'       => 403,
		'limit'           => 429,
		'claim_refused'   => 403,
		'collect_refused' => 403,
		'not_found'       => 404,
		'token_refused'   => 401,
		'too_large'       => 413,
	);

	/**
	 * Cashier A, who may pair.
	 *
	 * @var int
	 */
	protected $cashier_a;

	/**
	 * Cashier B, who may pair.
	 *
	 * @var int
	 */
	protected $cashier_b;

	/**
	 * A cashier the can_pair filter refuses (the contract's viewer).
	 *
	 * @var int
	 */
	protected $viewer;

	/**
	 * The source the next claim comes from.
	 *
	 * @var string
	 */
	protected $source = '192.0.2.1';

	/**
	 * Called with each request and its response, when set.
	 *
	 * @var callable(WP_REST_Request, WP_REST_Response): void|null
	 */
	protected $observe;

	/** A fresh world: empty tables, a fixed clock, three cashiers. */
	public function setUp(): void {
		parent::setUp();
		Store::reset_check();
		( new Signalling() )->reset();
		Clock::set( 1800000000000 );
		$this->cashier_a = $this->factory->user->create( array( 'role' => 'cashier' ) );
		$this->cashier_b = $this->factory->user->create( array( 'role' => 'cashier' ) );
		$this->viewer    = $this->factory->user->create( array( 'role' => 'cashier' ) );
		add_filter( 'woocommerce_pos_tally_display_can_pair', array( $this, 'can_pair' ), 10, 2 );
		add_filter( 'woocommerce_pos_tally_display_claim_source', array( $this, 'claim_source' ) );
	}

	/** Restore the clock and the filters. */
	public function tearDown(): void {
		Clock::set( null );
		remove_filter( 'woocommerce_pos_tally_display_can_pair', array( $this, 'can_pair' ), 10 );
		remove_filter( 'woocommerce_pos_tally_display_claim_source', array( $this, 'claim_source' ) );
		parent::tearDown();
	}

	/**
	 * The viewer may not pair.
	 *
	 * @param bool     $can  Whether the user holds the capability.
	 * @param \WP_User $user The user.
	 */
	public function can_pair( bool $can, \WP_User $user ): bool {
		return $can && $user->ID !== $this->viewer;
	}

	/** The harness's claim source. */
	public function claim_source(): string {
		return $this->source;
	}

	/**
	 * Move the clock on.
	 *
	 * @param int $ms Milliseconds.
	 */
	protected function advance( int $ms ): void {
		Clock::advance( $ms );
	}

	/**
	 * Dispatch a request under the namespace.
	 *
	 * @param string      $method  The method.
	 * @param string      $path    The path under tally/display.
	 * @param int         $user    The user, or 0.
	 * @param array|null  $query   Query params.
	 * @param mixed       $body    A JSON body (encoded here), or a raw string when $raw.
	 * @param array       $headers Headers.
	 * @param bool        $raw     Whether $body is sent as given.
	 */
	protected function call( string $method, string $path, int $user, ?array $query = null, $body = null, array $headers = array(), bool $raw = false ): WP_REST_Response {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( $method, '/wcpos/v2/tally/display/' . $path );
		$request->set_header( 'X-WCPOS', '1' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		if ( null !== $query ) {
			$request->set_query_params( $query );
		}
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( $raw ? (string) $body : (string) wp_json_encode( $body ) );
		}

		$response = $this->server->dispatch( $request );
		if ( null !== $this->observe ) {
			( $this->observe )( $request, $response );
		}

		return $response;
	}

	/**
	 * A till call: device_id in the JSON body for a POST, in the query otherwise.
	 *
	 * @param string $method The method.
	 * @param string $path   The path under tally/display.
	 * @param int    $user   The cashier.
	 * @param string $device The device id.
	 * @param array  $body   More body fields.
	 */
	protected function till( string $method, string $path, int $user, string $device, array $body = array() ): WP_REST_Response {
		if ( 'POST' === $method ) {
			return $this->call( $method, $path, $user, null, array_merge( array( 'device_id' => $device ), $body ) );
		}

		return $this->call( $method, $path, $user, array( 'device_id' => $device ) );
	}

	/**
	 * A display call carrying its token.
	 *
	 * @param string     $method The method.
	 * @param string     $path   The path under tally/display.
	 * @param string     $token  The token.
	 * @param array|null $body   The JSON body.
	 */
	protected function display( string $method, string $path, string $token, ?array $body = null ): WP_REST_Response {
		return $this->call( $method, $path, 0, null, $body, array( 'X-Tally-Display-Token' => $token ) );
	}

	/**
	 * Mint.
	 *
	 * @param int    $user   The cashier.
	 * @param string $device The device.
	 */
	protected function mint( int $user, string $device ): WP_REST_Response {
		return $this->till( 'POST', 'codes', $user, $device );
	}

	/**
	 * A minted code.
	 *
	 * @param int    $user   The cashier.
	 * @param string $device The device.
	 */
	protected function code( int $user, string $device ): string {
		return $this->ok( $this->mint( $user, $device ) )['code'];
	}

	/**
	 * Claim from a source.
	 *
	 * @param string      $source The source.
	 * @param string      $code   The code.
	 * @param string      $name   The display name.
	 * @param string|null $token  The display's token.
	 */
	protected function claim( string $source, string $code, string $name, ?string $token = null ): WP_REST_Response {
		$this->source = $source;
		$body         = array(
			'code'         => $code,
			'display_name' => $name,
		);
		if ( null !== $token ) {
			$body['token'] = $token;
		}

		return $this->call( 'POST', 'claim', 0, null, $body );
	}

	/**
	 * Collect.
	 *
	 * @param string $secret The claim secret.
	 */
	protected function collect( string $secret ): WP_REST_Response {
		return $this->call( 'POST', 'collect', 0, null, array( 'claim_secret' => $secret ) );
	}

	/**
	 * Pending pairings.
	 *
	 * @param int    $user   The cashier.
	 * @param string $device The device.
	 */
	protected function pending( int $user, string $device ): array {
		return $this->ok( $this->till( 'GET', 'pending', $user, $device ) );
	}

	/**
	 * Confirm.
	 *
	 * @param int    $user     The cashier.
	 * @param string $device   The device.
	 * @param string $claim_id The claim.
	 */
	protected function confirm( int $user, string $device, string $claim_id ): WP_REST_Response {
		return $this->till( 'POST', 'pending/' . $claim_id . '/confirm', $user, $device );
	}

	/**
	 * The registry.
	 *
	 * @param int    $user   The cashier.
	 * @param string $device The device.
	 */
	protected function list_displays( int $user, string $device ): array {
		return $this->ok( $this->till( 'GET', 'displays', $user, $device ) );
	}

	/**
	 * Post a snapshot, with the device id in the query and the body as given.
	 *
	 * @param int    $user       The cashier.
	 * @param string $device     The device.
	 * @param string $display_id The display.
	 * @param string $json       The body.
	 */
	protected function post_snapshot( int $user, string $device, string $display_id, string $json ): WP_REST_Response {
		return $this->call( 'POST', 'displays/' . $display_id . '/snapshot', $user, array( 'device_id' => $device ), $json, array(), true );
	}

	/**
	 * A till sends a signal.
	 *
	 * @param int    $user       The cashier.
	 * @param string $device     The device.
	 * @param string $display_id The display.
	 * @param string $type       The type.
	 * @param string $payload    The payload.
	 */
	protected function send_signal( int $user, string $device, string $display_id, string $type, string $payload ): WP_REST_Response {
		return $this->till(
			'POST',
			'displays/' . $display_id . '/signals',
			$user,
			$device,
			array(
				'type'    => $type,
				'payload' => $payload,
			)
		);
	}

	/**
	 * A till drains its inbox.
	 *
	 * @param int    $user   The cashier.
	 * @param string $device The device.
	 */
	protected function receive_signals( int $user, string $device ): array {
		return $this->ok( $this->till( 'POST', 'signals/receive', $user, $device ) );
	}

	/**
	 * A display sends a signal.
	 *
	 * @param string $token   The token.
	 * @param string $type    The type.
	 * @param string $payload The payload.
	 */
	protected function display_send_signal( string $token, string $type, string $payload ): WP_REST_Response {
		return $this->display(
			'POST',
			'signals',
			$token,
			array(
				'type'    => $type,
				'payload' => $payload,
			)
		);
	}

	/**
	 * A display drains its inbox.
	 *
	 * @param string $token The token.
	 */
	protected function display_receive_signals( string $token ): WP_REST_Response {
		return $this->display( 'POST', 'signals/receive/display', $token );
	}

	/**
	 * A display reads its snapshot.
	 *
	 * @param string $token The token.
	 */
	protected function read_snapshot( string $token ): WP_REST_Response {
		return $this->display( 'GET', 'snapshot', $token );
	}

	/**
	 * Claim a fresh code: the code, the claim body and the pending claim id.
	 *
	 * @param int         $user   The cashier.
	 * @param string      $device The device.
	 * @param string      $source The display's source.
	 * @param string      $name   The display name.
	 * @param string|null $token  The display's token.
	 *
	 * @return array{code: string, claim: array, claim_id: string}
	 */
	protected function claimed( int $user, string $device, string $source, string $name, ?string $token = null ): array {
		$code  = $this->code( $user, $device );
		$claim = $this->ok( $this->claim( $source, $code, $name, $token ) );
		$entry = null;
		foreach ( $this->pending( $user, $device ) as $row ) {
			if ( $row['check_code'] === $claim['check_code'] ) {
				$entry = $row;
			}
		}
		$this->assertIsArray( $entry, "the pending listing lacks the claim by {$name}" );
		$this->assertSame( $name, $entry['display_name'] );

		return array(
			'code'     => $code,
			'claim'    => $claim,
			'claim_id' => $entry['claim_id'],
		);
	}

	/**
	 * Pair end to end: mint, claim, confirm, collect.
	 *
	 * @param int         $user   The cashier.
	 * @param string      $device The device.
	 * @param string      $source The display's source.
	 * @param string      $name   The display name.
	 * @param string|null $token  The display's token.
	 *
	 * @return array{claim: array, display_id: string, token: string}
	 */
	protected function paired( int $user, string $device, string $source, string $name, ?string $token = null ): array {
		$claimed = $this->claimed( $user, $device, $source, $name, $token );
		$this->assertSame( array( 'confirmed' => true ), $this->ok( $this->confirm( $user, $device, $claimed['claim_id'] ) ) );
		$result = $this->ok( $this->collect( $claimed['claim']['claim_secret'] ) );
		$this->assertSame( 'paired', $result['status'], 'collect after the confirm gave ' . $result['status'] );

		return array(
			'claim'      => $claimed['claim'],
			'display_id' => $result['display_id'],
			'token'      => $result['token'],
		);
	}

	/**
	 * A code of the same length that is not one of $used.
	 *
	 * @param string ...$used Codes to avoid.
	 */
	protected static function unused_code( string ...$used ): string {
		for ( $n = 0; ; ++$n ) {
			$code = str_pad( (string) $n, 6, '0', STR_PAD_LEFT );
			if ( ! \in_array( $code, $used, true ) ) {
				return $code;
			}
		}
	}

	/** The harness's storedCounts. */
	protected function stored_counts(): array {
		return ( new Signalling() )->counts();
	}

	/** The harness's storedText. */
	protected function stored_text(): string {
		return (string) wp_json_encode( ( new Signalling() )->dump() );
	}

	/**
	 * A 200's data.
	 *
	 * @param WP_REST_Response $response The response.
	 *
	 * @return mixed
	 */
	protected function ok( WP_REST_Response $response ) {
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * A refusal's body, after checking its code and status.
	 *
	 * @param WP_REST_Response $response The response.
	 * @param string           $code     The seam error code.
	 * @param string           $what     What was refused.
	 */
	protected function refusal( WP_REST_Response $response, string $code, string $what = '' ): array {
		$data = $response->get_data();
		$this->assertSame( 'tally_display_' . $code, \is_array( $data ) ? ( $data['code'] ?? null ) : null, $what . ': ' . wp_json_encode( $data ) );
		$this->assertSame( self::STATUSES[ $code ], $response->get_status(), $what );

		return $data;
	}
}
