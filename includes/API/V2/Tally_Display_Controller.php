<?php
/**
 * Customer display signalling routes (TallyUI fork, ADR-085 U7).
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WCPOS\WooCommercePOS\Services\Tally_Display\Signalling;
use WCPOS\WooCommercePOS\Services\Tally_Display\Test_Hooks;
use WCPOS\WooCommercePOS\Sync\Api;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The till and display routes under wcpos/v2/tally/display. The route
 * document is docs/tally/customer-display-signalling.md.
 *
 * Till routes keep the namespace's cashier session gate and add
 * `woocommerce_pos_tally_display_can_pair`; display routes have no login and
 * check the `X-Tally-Display-Token` header in their callbacks.
 */
final class Tally_Display_Controller extends WP_REST_Controller {
	const PREFIX = '/tally/display';

	/** The display token header; never Authorization, which the JWT auth parses. */
	const TOKEN_HEADER = Test_Hooks::TOKEN_HEADER;

	/** A till device id. */
	const DEVICE_ID = '/^[A-Za-z0-9_-]{1,64}$/D';

	/** A path id pattern. */
	const ID = '(?P<%s>[A-Za-z0-9_-]+)';

	/**
	 * The REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v2';

	/**
	 * The service.
	 *
	 * @var Signalling
	 */
	private $signalling;

	/** Constructor. */
	public function __construct() {
		$this->signalling = new Signalling();
	}

	/**
	 * The display routes are public (they check the token themselves); the
	 * till routes give their own `tally_display_forbidden` to a logged-in user.
	 *
	 * @return array<string, string[]>
	 */
	public function wcpos_route_classifications(): array {
		$base = '/' . $this->namespace . self::PREFIX;

		return array(
			'public'                       => array( $base . '/claim', $base . '/collect', $base . '/signals', $base . '/signals/receive/display', $base . '/snapshot' ),
			'permission_error_passthrough' => array( $base . '/' ),
		);
	}

	/**
	 * Core answers a JSON body it cannot parse with `rest_invalid_json` before
	 * any route runs; these routes give the seam's `rest_invalid_param` instead.
	 *
	 * @param mixed           $response The response so far.
	 * @param array           $handler  The matched handler.
	 * @param WP_REST_Request $request  The request.
	 *
	 * @return mixed
	 */
	public static function invalid_json_as_invalid_param( $response, $handler, $request ) {
		if ( $response instanceof WP_Error && 'rest_invalid_json' === $response->get_error_code()
			&& 0 === strpos( $request->get_route(), '/' . Api::ROUTE_NAMESPACE . self::PREFIX . '/' ) ) {
			return new WP_Error( 'rest_invalid_param', 'The body is not JSON.', array( 'status' => 400 ) );
		}

		return $response;
	}

	/** Register the routes. */
	public function register_routes(): void {
		if ( false === has_filter( 'rest_request_before_callbacks', array( self::class, 'invalid_json_as_invalid_param' ) ) ) {
			add_filter( 'rest_request_before_callbacks', array( self::class, 'invalid_json_as_invalid_param' ), 10, 3 );
		}
		$claim   = \sprintf( self::ID, 'claim_id' );
		$display = \sprintf( self::ID, 'display_id' );
		$signal  = array(
			'type'    => array(
				'type'     => 'string',
				'enum'     => array( 'offer', 'answer', 'ice' ),
				'required' => true,
			),
			'payload' => array(
				'type'     => 'string',
				'required' => true,
			),
		);
		$till    = array(
			'codes'                        => array( WP_REST_Server::CREATABLE, 'mint', array() ),
			'pending'                      => array( WP_REST_Server::READABLE, 'pending', array() ),
			"pending/{$claim}/confirm"     => array( WP_REST_Server::CREATABLE, 'confirm', array() ),
			"pending/{$claim}/decline"     => array( WP_REST_Server::CREATABLE, 'decline', array() ),
			'displays'                     => array( WP_REST_Server::READABLE, 'list_displays', array() ),
			"displays/{$display}"          => array( WP_REST_Server::DELETABLE, 'forget', array() ),
			"displays/{$display}/signals"  => array( WP_REST_Server::CREATABLE, 'send_signal', $signal ),
			'signals/receive'              => array( WP_REST_Server::CREATABLE, 'receive_signals', array() ),
			"displays/{$display}/snapshot" => array( WP_REST_Server::CREATABLE, 'post_snapshot', array() ),
		);
		foreach ( $till as $path => list( $methods, $callback, $args ) ) {
			$this->route( $path, $methods, array( $this, $callback ), array( $this, 'post_snapshot' === $callback ? 'snapshot_permission' : 'till_permission' ), $args );
		}

		$display_routes = array(
			'claim'                   => array(
				'claim',
				array(
					'code'         => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 64,
					),
					'display_name' => array(
						'type'              => 'string',
						'required'          => true,
						'validate_callback' => array( $this, 'validate_display_name' ),
					),
					'token'        => array(
						'type'      => 'string',
						'maxLength' => 256,
					),
				),
			),
			'collect'                 => array(
				'collect',
				array(
					'claim_secret' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 256,
					),
				),
			),
			'signals'                 => array( 'display_send_signal', $signal ),
			'signals/receive/display' => array( 'display_receive_signals', array() ),
		);
		foreach ( $display_routes as $path => list( $callback, $args ) ) {
			$this->route( $path, WP_REST_Server::CREATABLE, array( $this, $callback ), '__return_true', $args );
		}
		$this->route( 'snapshot', WP_REST_Server::READABLE, array( $this, 'read_snapshot' ), '__return_true', array() );
	}

	/**
	 * A till route's permission: a cashier who may pair, and a well-formed device id.
	 * Refusals are `tally_display_forbidden` and store nothing.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 */
	public function till_permission( WP_REST_Request $request ) {
		return $this->admit( $request, $this->device_id( $request, true ) );
	}

	/**
	 * The snapshot post's permission: as till_permission, with the device id
	 * taken from the query string only, since the body is the snapshot.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 */
	public function snapshot_permission( WP_REST_Request $request ) {
		return $this->admit( $request, $this->device_id( $request, false ) );
	}

	/**
	 * Validate a display name: a string that is not empty once trimmed.
	 *
	 * @param mixed           $value   The value.
	 * @param WP_REST_Request $request The request.
	 * @param string          $param   The param name.
	 *
	 * @return true|WP_Error
	 */
	public function validate_display_name( $value, WP_REST_Request $request, string $param ) {
		$valid = rest_validate_request_arg( $value, $request, $param );
		if ( true !== $valid ) {
			return $valid;
		}

		return '' !== self::display_name( (string) $value ) ? true : new WP_Error( 'rest_invalid_param', 'display_name is empty.', array( 'status' => 400 ) );
	}

	/**
	 * POST codes.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function mint( WP_REST_Request $request ) {
		return self::respond( $this->signalling->mint( (string) $this->device_id( $request, true ), get_current_user_id() ) );
	}

	/**
	 * GET pending.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function pending( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->signalling->pending( (string) $this->device_id( $request, true ) ), 200 );
	}

	/**
	 * POST pending/{claim_id}/confirm.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function confirm( WP_REST_Request $request ) {
		$result = $this->signalling->confirm( (string) $this->device_id( $request, true ), self::path_id( $request, 'claim_id' ), get_current_user_id() );

		return self::respond( $result, array( 'confirmed' => true ) );
	}

	/**
	 * POST pending/{claim_id}/decline.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function decline( WP_REST_Request $request ) {
		$result = $this->signalling->decline( (string) $this->device_id( $request, true ), self::path_id( $request, 'claim_id' ) );

		return self::respond( $result, array( 'declined' => true ) );
	}

	/**
	 * GET displays.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function list_displays( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->signalling->list_displays( (string) $this->device_id( $request, true ) ), 200 );
	}

	/**
	 * DELETE displays/{display_id}.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function forget( WP_REST_Request $request ) {
		$result = $this->signalling->forget( (string) $this->device_id( $request, true ), self::path_id( $request, 'display_id' ) );

		return self::respond( $result, array( 'forgotten' => true ) );
	}

	/**
	 * POST displays/{display_id}/signals.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_signal( WP_REST_Request $request ) {
		$result = $this->signalling->send_signal(
			(string) $this->device_id( $request, true ),
			self::path_id( $request, 'display_id' ),
			(string) $request->get_param( 'type' ),
			(string) $request->get_param( 'payload' )
		);

		return self::respond( $result, array( 'queued' => true ) );
	}

	/**
	 * POST signals/receive.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function receive_signals( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->signalling->receive_signals( (string) $this->device_id( $request, true ) ), 200 );
	}

	/**
	 * POST displays/{display_id}/snapshot: the body is the snapshot JSON.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_snapshot( WP_REST_Request $request ) {
		$raw = (string) $request->get_body();
		json_decode( $raw );
		if ( '' === trim( $raw ) || JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'rest_invalid_param', 'The snapshot body is not JSON.', array( 'status' => 400 ) );
		}
		$result = $this->signalling->post_snapshot( (string) $this->device_id( $request, false ), self::path_id( $request, 'display_id' ), $raw );

		return self::respond( $result, array( 'stored' => true ) );
	}

	/**
	 * POST claim.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function claim( WP_REST_Request $request ) {
		$token  = $request->get_param( 'token' );
		$result = $this->signalling->claim(
			(string) $request->get_param( 'code' ),
			self::display_name( (string) $request->get_param( 'display_name' ) ),
			\is_string( $token ) ? $token : null,
			self::claim_source( $request )
		);

		return self::respond( $result );
	}

	/**
	 * POST collect.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function collect( WP_REST_Request $request ) {
		return self::respond( $this->signalling->collect( (string) $request->get_param( 'claim_secret' ) ) );
	}

	/**
	 * POST signals (display side).
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function display_send_signal( WP_REST_Request $request ) {
		$result = $this->signalling->display_send_signal( self::token( $request ), (string) $request->get_param( 'type' ), (string) $request->get_param( 'payload' ) );

		return self::respond( $result, array( 'queued' => true ) );
	}

	/**
	 * POST signals/receive/display.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function display_receive_signals( WP_REST_Request $request ) {
		return self::respond( $this->signalling->display_receive_signals( self::token( $request ) ) );
	}

	/**
	 * GET snapshot (display side).
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function read_snapshot( WP_REST_Request $request ) {
		$snapshot = $this->signalling->read_snapshot( self::token( $request ) );
		if ( $snapshot instanceof WP_Error ) {
			return $snapshot;
		}

		return new WP_REST_Response( array( 'snapshot' => $snapshot ), 200 );
	}

	/**
	 * The claim source: REMOTE_ADDR (or, under the test hooks, X-Tally-Test-Source),
	 * through `woocommerce_pos_tally_display_claim_source`.
	 *
	 * @param WP_REST_Request $request The claim request.
	 */
	public static function claim_source( WP_REST_Request $request ): string {
		$source = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$source = Test_Hooks::source( $request ) ?? $source;

		/**
		 * Filters the source a failed display claim is counted against, for a store behind a trusted proxy.
		 *
		 * @param string          $source  REMOTE_ADDR by default.
		 * @param WP_REST_Request $request The claim request.
		 */
		return (string) apply_filters( 'woocommerce_pos_tally_display_claim_source', $source, $request );
	}

	/**
	 * Admit a till request.
	 *
	 * @param WP_REST_Request $request   The request.
	 * @param mixed           $device_id The device id param.
	 *
	 * @return true|WP_Error
	 */
	private function admit( WP_REST_Request $request, $device_id ) {
		$user = wp_get_current_user();
		$can  = $user->exists() && current_user_can( 'access_woocommerce_pos' );

		/**
		 * Filters whether a cashier may pair and drive customer displays (ADR-085 `customer_display.pair`).
		 *
		 * @param bool     $can  Whether the user holds access_woocommerce_pos.
		 * @param \WP_User $user The user.
		 */
		$can = $user->exists() && (bool) apply_filters( 'woocommerce_pos_tally_display_can_pair', $can, $user );
		if ( ! $can || ! \is_string( $device_id ) || 1 !== preg_match( self::DEVICE_ID, $device_id ) ) {
			return Signalling::error( 'forbidden' );
		}
		unset( $request );

		return true;
	}

	/**
	 * The device id: the query string's, else (when the body may carry it) the body's.
	 *
	 * @param WP_REST_Request $request   The request.
	 * @param bool            $from_body Whether the body may carry it.
	 *
	 * @return mixed
	 */
	private function device_id( WP_REST_Request $request, bool $from_body ) {
		$query = $request->get_query_params();
		if ( isset( $query['device_id'] ) || ! $from_body ) {
			return $query['device_id'] ?? null;
		}
		$json = $request->get_json_params();
		if ( \is_array( $json ) && isset( $json['device_id'] ) ) {
			return $json['device_id'];
		}

		return $request->get_body_params()['device_id'] ?? null;
	}

	/**
	 * A path id, from the URL only, so a body key cannot replace it.
	 *
	 * @param WP_REST_Request $request The request.
	 * @param string          $name    The path param.
	 */
	private static function path_id( WP_REST_Request $request, string $name ): string {
		return (string) ( $request->get_url_params()[ $name ] ?? '' );
	}

	/**
	 * The display token header, or ''.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	private static function token( WP_REST_Request $request ): string {
		return (string) $request->get_header( self::TOKEN_HEADER );
	}

	/**
	 * A display name trimmed to at most 64 characters.
	 *
	 * @param string $name The name as sent.
	 */
	private static function display_name( string $name ): string {
		return trim( mb_substr( trim( $name ), 0, Signalling::NAME_MAX ) );
	}

	/**
	 * A response for a service result.
	 *
	 * @param mixed      $result The service result or its refusal.
	 * @param array|null $body   The body for a `true` result.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	private static function respond( $result, ?array $body = null ) {
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return new WP_REST_Response( true === $result && null !== $body ? $body : $result, 200 );
	}

	/**
	 * Register one route.
	 *
	 * @param string   $path       The path under the prefix.
	 * @param string   $methods    The methods.
	 * @param callable $callback   The callback.
	 * @param callable $permission The permission callback.
	 * @param array    $args       The args.
	 */
	private function route( string $path, string $methods, callable $callback, callable $permission, array $args ): void {
		register_rest_route(
			$this->namespace,
			self::PREFIX . '/' . $path,
			array(
				'methods'             => $methods,
				'callback'            => $callback,
				'permission_callback' => $permission,
				'args'                => $args,
			)
		);
	}
}
