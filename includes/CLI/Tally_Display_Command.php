<?php
/**
 * WP-CLI test hooks of the customer display signalling routes (TallyUI fork, ADR-085 U7).
 *
 * @package WCPOS\WooCommercePOS\CLI
 */

namespace WCPOS\WooCommercePOS\CLI;

use WCPOS\WooCommercePOS\Services\Tally_Display\Signalling;
use WCPOS\WooCommercePOS\Services\Tally_Display\Store;
use WCPOS\WooCommercePOS\Services\Tally_Display\Test_Hooks;

/**
 * The live contract harness's storedCounts, storedText and fresh world.
 * Every command refuses unless WCPOS_TALLY_DISPLAY_TEST_HOOKS is defined as true.
 */
class Tally_Display_Command {
	/**
	 * Prints the live rows of codes, pending, displays and snapshots as JSON.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcpos tally-display counts
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function counts( array $args, array $assoc_args ): void {
		if ( $this->refused() ) {
			return;
		}
		$this->line( (string) wp_json_encode( ( new Signalling() )->counts() ) );
	}

	/**
	 * Prints every stored row of every table as JSON.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcpos tally-display dump
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function dump( array $args, array $assoc_args ): void {
		if ( $this->refused() ) {
			return;
		}
		$this->line( (string) wp_json_encode( ( new Signalling() )->dump() ) );
	}

	/**
	 * Empties every table and deletes the clock offset.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wcpos tally-display reset
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 */
	public function reset( array $args, array $assoc_args ): void {
		if ( $this->refused() ) {
			return;
		}
		Store::reset_check();
		( new Signalling() )->reset();
		delete_option( Test_Hooks::CLOCK_OFFSET_OPTION );
		$this->line( 'reset' );
	}

	/** Whether the test hooks are off, after reporting so. */
	private function refused(): bool {
		if ( Test_Hooks::enabled() ) {
			return false;
		}
		$this->error( Test_Hooks::CONSTANT . ' is not defined as true; the customer display test hooks are off.' );

		return true;
	}

	/**
	 * Output seam — WP_CLI is absent in the unit-test container.
	 *
	 * @param string $text The line.
	 */
	protected function line( string $text ): void {
		// @phpstan-ignore-next-line -- WP_CLI exists only under the wp-cli runtime; unit tests override this seam.
		\WP_CLI::line( $text );
	}

	/**
	 * Error seam; WP_CLI::error exits non-zero.
	 *
	 * @param string $message The message.
	 */
	protected function error( string $message ): void {
		// @phpstan-ignore-next-line -- WP_CLI exists only under the wp-cli runtime; unit tests override this seam.
		\WP_CLI::error( $message );
	}
}
