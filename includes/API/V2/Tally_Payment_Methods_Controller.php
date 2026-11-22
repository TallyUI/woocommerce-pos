<?php
/**
 * Payment methods contract, GET only (TallyUI fork, ADR-094).
 *
 * @package WCPOS\WooCommercePOS\API\V2
 */

namespace WCPOS\WooCommercePOS\API\V2;

use WC_Payment_Gateway;
use WC_Payment_Gateways;
use WCPOS\WooCommercePOS\Gateways\Cash;
use WCPOS\WooCommercePOS\Sync\Endpoint_Permissions;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET wcpos/v2/payment-methods` in the payments contract 1.3 shape, cut to the
 * keys `@tallyui/connector-woocommerce` reads (`wooPaymentMethods`). Each value
 * is what upstream WCPOS 2.0's `Payments\Contract\Descriptor_Builder` answers:
 * its Manual_Handler for the two POS gateways and its Webview_Handler for any
 * other gateway. The cash rounding increment is the POS Cash gateway's own
 * `rounding_increment` setting (`Cash::ROUNDING_INCREMENTS`). An upstream merge
 * must keep this shape (FORK.md item 4).
 */
final class Tally_Payment_Methods_Controller extends WP_REST_Controller {
	use Endpoint_Permissions;

	/** Upstream `Descriptor_Builder::resolve_kind()` for the POS gateways; any other gateway is `other`. */
	const POS_KINDS = array(
		'pos_cash' => 'cash',
		'pos_card' => 'card',
	);

	/**
	 * The REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcpos/v2';

	/**
	 * The REST base.
	 *
	 * @var string
	 */
	protected $rest_base = 'payment-methods';

	/** Register the route. */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * One row per gateway the POS payment gateway settings list, in upstream's order.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		// The settings read re-initialises WooCommerce's gateways, so look them up after it.
		$settings  = (array) wcpos_get_settings( 'payment_gateways' );
		$installed = WC_Payment_Gateways::instance()->payment_gateways();
		$methods   = array();
		foreach ( (array) ( $settings['gateways'] ?? array() ) as $id => $row ) {
			if ( isset( $installed[ $id ] ) && $installed[ $id ] instanceof WC_Payment_Gateway ) {
				$methods[] = $this->describe( $installed[ $id ], (array) $row );
			}
		}
		usort(
			$methods,
			static function ( array $left, array $right ): int {
				return $left['order'] === $right['order'] ? strcmp( $left['id'], $right['id'] ) : $left['order'] <=> $right['order'];
			}
		);

		return new WP_REST_Response(
			array(
				'schema'   => 1,
				'contract' => '1.3',
				'methods'  => $methods,
			),
			200
		);
	}

	/**
	 * One descriptor in upstream's wire-key order.
	 *
	 * @param WC_Payment_Gateway $gateway The gateway.
	 * @param array              $row     The gateway's POS settings row.
	 */
	private function describe( WC_Payment_Gateway $gateway, array $row ): array {
		$pos     = isset( self::POS_KINDS[ $gateway->id ] );
		$kind    = $pos ? self::POS_KINDS[ $gateway->id ] : 'other';
		$refunds = (bool) $gateway->supports( 'refunds' );

		return array(
			'schema'       => 1,
			'id'           => $gateway->id,
			'title'        => isset( $row['title'] ) && \is_string( $row['title'] ) && '' !== $row['title'] ? $row['title'] : $gateway->get_title(),
			'kind'         => $kind,
			'pos_enabled'  => (bool) ( $row['enabled'] ?? false ),
			'order'        => (int) ( $row['order'] ?? 999 ),
			'capture'      => array( 'mode' => $pos ? 'manual' : 'webview' ),
			'capabilities' => array(
				'amount'  => array( 'partial' => $pos ),
				'change'  => 'cash' === $kind,
				'refunds' => array(
					'via'     => $pos || ! $refunds ? 'manual' : 'provider',
					'partial' => $pos || $refunds,
				),
				'tips'    => 'none',
				'offline' => $pos ? 'record' : 'none',
				'void'    => $pos,
			),
			'defaults'     => array(
				'rounding'    => 'cash' === $kind ? self::rounding( $gateway ) : null,
				'open_drawer' => 'cash' === $kind,
			),
		);
	}

	/**
	 * The cash gateway's stored increment, only when it is on the list. It reads
	 * the option itself: `$gateway->get_option()` would run `init_settings()`,
	 * which resets `enabled` on WooCommerce's shared gateway instance.
	 *
	 * @param WC_Payment_Gateway $gateway The cash gateway.
	 */
	private static function rounding( WC_Payment_Gateway $gateway ): ?array {
		$stored    = get_option( $gateway->get_option_key(), array() );
		$increment = \is_array( $stored ) ? ( $stored['rounding_increment'] ?? '' ) : '';
		if ( '' === $increment || ! \in_array( $increment, Cash::ROUNDING_INCREMENTS, true ) ) {
			return null;
		}

		return array(
			'increment' => $increment,
			'mode'      => 'nearest',
		);
	}
}
