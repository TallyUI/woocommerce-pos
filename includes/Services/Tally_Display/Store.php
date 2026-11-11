<?php
/**
 * The tables of the customer display signalling routes.
 *
 * @package WCPOS\WooCommercePOS\Services\Tally_Display
 */

namespace WCPOS\WooCommercePOS\Services\Tally_Display;

/**
 * Six fork-only tables, created with dbDelta behind their own version option
 * so no upstream migration machinery is touched. Every time is Unix ms; every
 * secret is stored only as its SHA-256 hex.
 */
final class Store {
	/** The fork-only schema version option. */
	const VERSION_OPTION = 'woocommerce_pos_tally_display_db_version';

	/** The schema version this code creates. */
	const VERSION = '1';

	/** The table names, without the prefix. */
	const TABLES = array( 'codes', 'pending', 'displays', 'signals', 'snapshots', 'failures' );

	/**
	 * Whether this request has already checked the schema.
	 *
	 * @var bool
	 */
	private static $checked = false;

	/**
	 * A table's full name.
	 *
	 * @param string $table One of TABLES.
	 */
	public static function table( string $table ): string {
		global $wpdb;

		return $wpdb->prefix . 'wcpos_tally_display_' . $table;
	}

	/** Create or update the tables once per request when the version option is behind. */
	public static function ensure(): void {
		if ( self::$checked ) {
			return;
		}
		self::$checked = true;
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}
		self::install();
	}

	/** Run dbDelta for every table and record the version. */
	public static function install(): void {
		global $wpdb;
		if ( ! \function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$charset = $wpdb->get_charset_collate();
		foreach ( self::schema() as $table => $columns ) {
			dbDelta( 'CREATE TABLE ' . self::table( $table ) . " (\n" . $columns . "\n) " . $charset . ';' );
		}
		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/** Forget the per-request schema check (tests and the CLI reset). */
	public static function reset_check(): void {
		self::$checked = false;
	}

	/**
	 * The column list of each table, in dbDelta's format.
	 *
	 * @return array<string, string>
	 */
	private static function schema(): array {
		return array(
			'codes'     => 'code char(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
device_id varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
cashier_id bigint(20) unsigned NOT NULL,
expires_at bigint(20) NOT NULL,
spent tinyint(1) NOT NULL DEFAULT 0,
PRIMARY KEY  (code),
KEY device_id (device_id)',
			'pending'   => 'claim_id char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
device_id varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
secret_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
display_name varchar(255) NOT NULL,
check_code char(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
expires_at bigint(20) NOT NULL,
moving_display_id char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
PRIMARY KEY  (claim_id),
UNIQUE KEY secret_hash (secret_hash),
KEY device_id (device_id)',
			'displays'  => 'display_id char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
device_id varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
name varchar(255) NOT NULL,
cashier_id bigint(20) unsigned NOT NULL,
confirmed_at bigint(20) NOT NULL,
last_seen_at bigint(20) DEFAULT NULL,
token_hash char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
secret_hash char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
PRIMARY KEY  (display_id),
KEY device_id (device_id),
KEY token_hash (token_hash),
KEY secret_hash (secret_hash)',
			'signals'   => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
display_id char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
to_till tinyint(1) NOT NULL,
type varchar(8) NOT NULL,
payload mediumtext NOT NULL,
PRIMARY KEY  (id),
KEY display_id (display_id)',
			'snapshots' => 'display_id char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
body mediumtext NOT NULL,
posted_at bigint(20) NOT NULL,
PRIMARY KEY  (display_id)',
			'failures'  => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
source_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
failed_at bigint(20) NOT NULL,
PRIMARY KEY  (id),
KEY source_hash (source_hash),
KEY failed_at (failed_at)',
		);
	}
}
