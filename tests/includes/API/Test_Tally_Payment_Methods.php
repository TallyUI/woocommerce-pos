<?php
/**
 * Pins `GET wcpos/v2/payment-methods` and the cash rounding setting (TallyUI fork, ADR-094).
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

use WCPOS\WooCommercePOS\Gateways\Cash;

/**
 * The route answers the contract 1.3 envelope with the keys the TallyUI
 * connector reads, and `defaults.rounding` only for a listed cash increment.
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Tally_Payment_Methods_Controller
 * @covers \WCPOS\WooCommercePOS\Gateways\Cash
 */
class Test_Tally_Payment_Methods extends WCPOS_REST_Unit_Test_Case {
	const ROUTE = '/wcpos/v2/payment-methods';

	/** The POS manual-capture capabilities, as upstream's Manual_Handler gives them. */
	const MANUAL_CAPABILITIES = array(
		'amount'  => array( 'partial' => true ),
		'change'  => true,
		'refunds' => array(
			'via'     => 'manual',
			'partial' => true,
		),
		'tips'    => 'none',
		'offline' => 'record',
		'void'    => true,
	);

	/**
	 * Dispatch the route and return its rows keyed by id.
	 *
	 * @return array<string, array>
	 */
	private function methods(): array {
		$response = $this->server->dispatch( $this->wp_rest_get_request( self::ROUTE ) );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return array_column( $response->get_data()['methods'], null, 'id' );
	}

	/** The envelope is schema 1, contract 1.3, and every row has the connector's key set. */
	public function test_payment_methods_envelope_has_schema_contract_and_connector_keys(): void {
		// Act.
		$response = $this->server->dispatch( $this->wp_rest_get_request( self::ROUTE ) );
		$data     = $response->get_data();

		// Assert.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'schema', 'contract', 'methods' ), array_keys( $data ) );
		$this->assertSame( 1, $data['schema'] );
		$this->assertSame( '1.3', $data['contract'] );
		$this->assertContains( 'pos_cash', array_column( $data['methods'], 'id' ) );
		$this->assertContains( 'pos_card', array_column( $data['methods'], 'id' ) );
		foreach ( $data['methods'] as $row ) {
			$this->assertSame( array( 'schema', 'id', 'title', 'kind', 'pos_enabled', 'order', 'capture', 'capabilities', 'defaults' ), array_keys( $row ) );
			$this->assertSame( 1, $row['schema'] );
			$this->assertSame( array( 'mode' ), array_keys( $row['capture'] ) );
			$this->assertSame( array( 'amount', 'change', 'refunds', 'tips', 'offline', 'void' ), array_keys( $row['capabilities'] ) );
			$this->assertSame( array( 'partial' ), array_keys( $row['capabilities']['amount'] ) );
			$this->assertSame( array( 'via', 'partial' ), array_keys( $row['capabilities']['refunds'] ) );
			$this->assertSame( array( 'rounding', 'open_drawer' ), array_keys( $row['defaults'] ) );
		}
	}

	/** The POS gateways answer upstream's Manual_Handler descriptors. */
	public function test_payment_methods_pos_cash_and_pos_card_match_upstream_descriptors(): void {
		// Act.
		$methods = $this->methods();

		// Assert.
		$this->assertSame(
			array(
				'schema'       => 1,
				'id'           => 'pos_cash',
				'title'        => 'Cash',
				'kind'         => 'cash',
				'pos_enabled'  => true,
				'order'        => 0,
				'capture'      => array( 'mode' => 'manual' ),
				'capabilities' => self::MANUAL_CAPABILITIES,
				'defaults'     => array(
					'rounding'    => null,
					'open_drawer' => true,
				),
			),
			$methods['pos_cash']
		);
		$this->assertSame(
			array(
				'schema'       => 1,
				'id'           => 'pos_card',
				'title'        => 'Card',
				'kind'         => 'card',
				'pos_enabled'  => true,
				'order'        => 1,
				'capture'      => array( 'mode' => 'manual' ),
				'capabilities' => array_replace( self::MANUAL_CAPABILITIES, array( 'change' => false ) ),
				'defaults'     => array(
					'rounding'    => null,
					'open_drawer' => false,
				),
			),
			$methods['pos_card']
		);
	}

	/** No stored increment means no rounding. */
	public function test_payment_methods_cash_rounding_unset_is_null(): void {
		// Arrange.
		delete_option( 'woocommerce_pos_cash_settings' );

		// Act / Assert.
		$this->assertNull( $this->methods()['pos_cash']['defaults']['rounding'] );
	}

	/** Every listed increment rounds to the nearest. */
	public function test_payment_methods_cash_rounding_listed_increment_rounds_to_nearest(): void {
		foreach ( array( '0.05', '0.10', '0.25', '0.50', '1', '5', '10' ) as $increment ) {
			// Arrange.
			update_option( 'woocommerce_pos_cash_settings', array( 'rounding_increment' => $increment ) );

			// Act / Assert.
			$this->assertSame(
				array(
					'increment' => $increment,
					'mode'      => 'nearest',
				),
				$this->methods()['pos_cash']['defaults']['rounding'],
				$increment
			);
		}
	}

	/** A stored value off the list is served as no rounding. */
	public function test_payment_methods_cash_rounding_off_list_value_is_null(): void {
		// Arrange.
		update_option( 'woocommerce_pos_cash_settings', array( 'rounding_increment' => '0.07' ) );

		// Act / Assert.
		$this->assertNull( $this->methods()['pos_cash']['defaults']['rounding'] );
	}

	/** The settings form stores an off-list value as no rounding. */
	public function test_cash_rounding_field_save_stores_off_list_value_as_no_rounding(): void {
		$cases = array(
			'0.07' => '',
			'0.05' => '0.05',
		);
		foreach ( $cases as $posted => $stored ) {
			// Arrange.
			$cash = new Cash();
			$cash->set_post_data( array( 'woocommerce_pos_cash_rounding_increment' => (string) $posted ) );

			// Act.
			$cash->process_admin_options();

			// Assert.
			$this->assertSame( $stored, get_option( 'woocommerce_pos_cash_settings' )['rounding_increment'], (string) $posted );
		}
		$this->assertSame( '', ( new Cash() )->validate_rounding_increment_field( 'rounding_increment', '0.07' ) );
	}

	/** Only cash rounds, whatever the card gateway's option holds. */
	public function test_payment_methods_pos_card_never_rounds(): void {
		// Arrange.
		update_option( 'woocommerce_pos_card_settings', array( 'rounding_increment' => '0.05' ) );

		// Act / Assert.
		$this->assertNull( $this->methods()['pos_card']['defaults']['rounding'] );
	}

	/** An anonymous request is refused with the code the other v2 GETs answer. */
	public function test_payment_methods_anonymous_request_is_refused_like_status(): void {
		// Arrange.
		wp_set_current_user( 0 );

		// Act.
		$status  = $this->server->dispatch( $this->wp_rest_get_request( '/wcpos/v2/status' ) );
		$methods = $this->server->dispatch( $this->wp_rest_get_request( self::ROUTE ) );

		// Assert.
		$this->assertSame( 401, $methods->get_status() );
		$this->assertSame( $status->get_status(), $methods->get_status() );
	}
}
