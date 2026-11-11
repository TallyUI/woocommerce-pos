<?php
/**
 * Sync REST API registration.
 *
 * @package WCPOS\WooCommercePOS\Sync
 */

namespace WCPOS\WooCommercePOS\Sync;

use WCPOS\WooCommercePOS\API\V2\Catalog_Proxy_Controller;
use WCPOS\WooCommercePOS\API\V2\Changes_Controller;
use WCPOS\WooCommercePOS\API\V2\Digests_Controller;
use WCPOS\WooCommercePOS\API\V2\Integrity_Controller;
use WCPOS\WooCommercePOS\API\V2\Orders_Controller;
use WCPOS\WooCommercePOS\API\V2\Resolve_Controller;
use WCPOS\WooCommercePOS\API\V2\Status_Controller;
use WCPOS\WooCommercePOS\API\V2\Tally_Display_Controller;
use WCPOS\WooCommercePOS\API\V2\Uuid_Backfill_Controller;
use WCPOS\WooCommercePOS\API\V2\Variations_Controller;
use WCPOS\WooCommercePOS\API\V2\Write_Controller;

/**
 * Sync REST API configuration and controller registration.
 */
final class Api {
	public const ROUTE_NAMESPACE = 'wcpos/v2';
	public const ADMIN_OP_PATHS  = array( 'uuid/backfill', 'orders/index/backfill', 'integrity/rebuild' );
	public const UUID_META_KEY   = '_woocommerce_pos_uuid';
	// 5: (status, created_at) index on the mutations table for retention pruning.
	public const SCHEMA_VERSION  = '5';
	public const SCHEMA_OPTION   = 'wcpos_sync_schema_version';

	/**
	 * Declare routes with special permission-gate handling.
	 *
	 * @return array<string, string[]> Route classifications.
	 */
	public static function route_classifications(): array {
		$admin_routes = array();

		foreach ( self::ADMIN_OP_PATHS as $path ) {
			$admin_routes[] = '/' . self::ROUTE_NAMESPACE . '/' . $path;
		}

		return array(
			'admin_op'       => $admin_routes,
			'rewrite_exempt' => array( '/' . self::ROUTE_NAMESPACE . '/' ),
		);
	}

	/**
	 * Register sync REST API controllers.
	 *
	 * @param array $controllers REST API controllers.
	 *
	 * @return array REST API controllers.
	 */
	public static function register_controllers( array $controllers ): array {
		// Reason: Init::hook_rows() retains these registrars here, not at plugins_loaded.
		// Moving them earlier changes hook visibility and same-priority extension ordering
		// before woocommerce_pos_rest_api_controllers runs (including unmarked requests).
		Response_Telemetry::register_hooks();
		Retry_After_Mirror::register_hooks();
		Response_Envelope::register_hooks();
		$controllers['sync-status']     = Status_Controller::class;
		$controllers['sync-catalog']    = Catalog_Proxy_Controller::class;
		$controllers['sync-orders']     = Orders_Controller::class;
		$controllers['sync-changes']    = Changes_Controller::class;
		$controllers['sync-digests']    = Digests_Controller::class;
		$controllers['sync-integrity']  = Integrity_Controller::class;
		$controllers['sync-uuid']       = Uuid_Backfill_Controller::class;
		$controllers['sync-variations'] = Variations_Controller::class;
		$controllers['sync-resolve']    = Resolve_Controller::class;
		$controllers['sync-write']      = Write_Controller::class;
		// TallyUI fork (ADR-085 U7): the display routes are a wcpos/v2 service, not a
		// sync-lane controller, so they join the v2 map and get no sync telemetry.
		if ( false === has_filter( 'woocommerce_pos_rest_api_v2_controllers', array( self::class, 'register_v2_services' ) ) ) {
			add_filter( 'woocommerce_pos_rest_api_v2_controllers', array( self::class, 'register_v2_services' ) );
		}

		return $controllers;
	}

	/**
	 * TallyUI fork (ADR-085 U7): add the customer display routes to the v2 service
	 * map, ahead of the other v2-native services.
	 *
	 * @param array $controllers The v2 service map.
	 *
	 * @return array The v2 service map.
	 */
	public static function register_v2_services( array $controllers ): array {
		return array( 'tally_display' => Tally_Display_Controller::class ) + $controllers;
	}

	/**
	 * TallyUI fork (ADR-085 U7): register `wp wcpos tally-display` from Init, which
	 * both the standalone and the Pro-bundled load paths construct.
	 */
	public static function register_cli_commands(): void {
		if ( \defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wcpos tally-display', \WCPOS\WooCommercePOS\CLI\Tally_Display_Command::class ); // @phpstan-ignore-line -- WP_CLI exists only under wp-cli.
		}
	}
}
