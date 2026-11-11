<?php
/**
 * TallyUI's displaySignallingContract, one test per case (TallyUI fork, ADR-085 U7).
 *
 * @package WCPOS\WooCommercePOS\Tests\API
 */

namespace WCPOS\WooCommercePOS\Tests\API;

/**
 * The 19 cases of packages/core/src/server/display-signalling/contract.ts,
 * each named after its case and driven through the REST routes.
 *
 * @internal
 *
 * @covers \WCPOS\WooCommercePOS\API\V2\Tally_Display_Controller
 * @covers \WCPOS\WooCommercePOS\Services\Tally_Display\Signalling
 */
class Test_Tally_Display_Contract extends Test_Tally_Display_Harness {
	/** 'mint without the capability is forbidden and makes no code' */
	public function test_mint_without_the_capability_is_forbidden_and_makes_no_code(): void {
		$this->refusal( $this->mint( $this->viewer, self::A ), 'forbidden', 'mint without the capability' );
		$this->assertSame( 0, $this->stored_counts()['codes'], 'a refused mint stored a code' );
	}

	/** 'a minted code is at least 6 digits and expires within 10 minutes' */
	public function test_a_minted_code_is_at_least_6_digits_and_expires_within_10_minutes(): void {
		$minted = $this->ok( $this->mint( $this->cashier_a, self::A ) );
		$this->assertMatchesRegularExpression( '/^\d{6,}$/D', $minted['code'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/D', $minted['expires_at'] );
		$this->assertSame( '2027-01-15T08:10:00.000Z', $minted['expires_at'] );
		$this->advance( 10 * self::MINUTE );
		$this->refusal( $this->claim( '192.0.2.1', $minted['code'], 'Late' ), 'claim_refused', 'a claim 10 minutes after the mint' );
	}

	/** 'a device holds at most 5 unspent codes, and expired codes stop counting' */
	public function test_a_device_holds_at_most_5_unspent_codes_and_expired_codes_stop_counting(): void {
		for ( $i = 0; $i < 5; ++$i ) {
			$this->ok( $this->mint( $this->cashier_a, self::A ) );
		}
		$this->refusal( $this->mint( $this->cashier_a, self::A ), 'limit', 'the 6th unspent code' );
		$this->advance( 10 * self::MINUTE );
		$this->ok( $this->mint( $this->cashier_a, self::A ) );
	}

	/** 'spent, expired and unknown codes get the same claim refusal' */
	public function test_spent_expired_and_unknown_codes_get_the_same_claim_refusal(): void {
		$spent = $this->code( $this->cashier_a, self::A );
		$this->ok( $this->claim( '192.0.2.1', $spent, 'First' ) );
		$again   = $this->refusal( $this->claim( '192.0.2.2', $spent, 'Second' ), 'claim_refused', 'a spent code' );
		$expired = $this->code( $this->cashier_a, self::A );
		$this->advance( 10 * self::MINUTE );
		$late    = $this->refusal( $this->claim( '192.0.2.3', $expired, 'Late' ), 'claim_refused', 'an expired code' );
		$unknown = $this->refusal( $this->claim( '192.0.2.4', self::unused_code( $spent, $expired ), 'Guess' ), 'claim_refused', 'an unknown code' );
		$this->assertSame( $again, $late, 'spent and expired: the refusals differ' );
		$this->assertSame( $again, $unknown, 'spent and unknown: the refusals differ' );
	}

	/** 'a claim issues no token: collect gives pending with its check code' */
	public function test_a_claim_issues_no_token_collect_gives_pending_with_its_check_code(): void {
		$claimed = $this->claimed( $this->cashier_a, self::A, '192.0.2.1', 'Counter' );
		$this->assertSame( array( 'claim_secret', 'check_code' ), array_keys( $claimed['claim'] ) );
		$result = $this->ok( $this->collect( $claimed['claim']['claim_secret'] ) );
		$this->assertSame(
			array(
				'status'     => 'pending',
				'check_code' => $claimed['claim']['check_code'],
			),
			$result
		);
	}

	/** 'the check code matches on both screens, and two live claims never share one' */
	public function test_the_check_code_matches_on_both_screens_and_two_live_claims_never_share_one(): void {
		$first = $this->claimed( $this->cashier_a, self::A, '192.0.2.1', 'One' );
		$this->assertMatchesRegularExpression( '/^\d{4}$/D', $first['claim']['check_code'] );
		$listed = null;
		foreach ( $this->pending( $this->cashier_a, self::A ) as $row ) {
			if ( $row['claim_id'] === $first['claim_id'] ) {
				$listed = $row;
			}
		}
		$collected = $this->ok( $this->collect( $first['claim']['claim_secret'] ) );
		$this->assertSame( $first['claim']['check_code'], $listed['check_code'] ?? null, 'the till lists another check code' );
		$this->assertSame( 'pending', $collected['status'] );
		$this->assertSame( $first['claim']['check_code'], $collected['check_code'], 'collect gives another check code' );
		$second = $this->claimed( $this->cashier_a, self::A, '192.0.2.2', 'Two' );
		$this->assertNotSame( $first['claim']['check_code'], $second['claim']['check_code'], 'two live claims share a check code' );
	}

	/** 'a confirm issues the token once, then collect is refused like an unknown secret' */
	public function test_a_confirm_issues_the_token_once_then_collect_is_refused_like_an_unknown_secret(): void {
		$paired = $this->paired( $this->cashier_a, self::A, '192.0.2.1', 'Counter' );
		$this->assertMatchesRegularExpression( self::SECRET, $paired['token'] );
		$again   = $this->refusal( $this->collect( $paired['claim']['claim_secret'] ), 'collect_refused', 'a second collect' );
		$unknown = $this->refusal( $this->collect( 'AAAAAAAAAAAAAAAAAAAAAA' ), 'collect_refused', 'an unknown secret' );
		$this->assertSame( $again, $unknown, 'second collect and unknown secret: the refusals differ' );
	}

	/** 'a decline, or an unconfirmed claim whose code expires, issues nothing' */
	public function test_a_decline_or_an_unconfirmed_claim_whose_code_expires_issues_nothing(): void {
		$declined = $this->claimed( $this->cashier_a, self::A, '192.0.2.1', 'Declined' );
		$this->assertSame( array( 'declined' => true ), $this->ok( $this->till( 'POST', 'pending/' . $declined['claim_id'] . '/decline', $this->cashier_a, self::A ) ) );
		$this->refusal( $this->collect( $declined['claim']['claim_secret'] ), 'collect_refused', 'collect after a decline' );
		$lapsed = $this->claimed( $this->cashier_a, self::A, '192.0.2.1', 'Lapsed' );
		$this->advance( 10 * self::MINUTE );
		$this->refusal( $this->collect( $lapsed['claim']['claim_secret'] ), 'collect_refused', 'collect after the code expired' );
		$this->assertSame( 0, $this->stored_counts()['pending'], 'a pending pairing outlived its decline or its code' );
	}

	/** 'a confirm needs the capability and the minting device' */
	public function test_a_confirm_needs_the_capability_and_the_minting_device(): void {
		$claimed = $this->claimed( $this->cashier_a, self::A, '192.0.2.1', 'Counter' );
		$this->refusal( $this->confirm( $this->viewer, self::A, $claimed['claim_id'] ), 'forbidden', 'a confirm without the capability' );
		$this->refusal( $this->confirm( $this->cashier_b, self::B, $claimed['claim_id'] ), 'not_found', 'a confirm from another device' );
		$this->assertSame( 'pending', $this->ok( $this->collect( $claimed['claim']['claim_secret'] ) )['status'], 'a refused confirm changed the claim' );
	}

	/** 'one source is limited to 10 failed claims a minute, and a limited claim spends nothing' */
	public function test_one_source_is_limited_to_10_failed_claims_a_minute_and_a_limited_claim_spends_nothing(): void {
		$code = $this->code( $this->cashier_a, self::A );
		for ( $i = 0; $i < 10; ++$i ) {
			$this->refusal( $this->claim( '198.51.100.7', self::unused_code( $code ), 'Guess' ), 'claim_refused', 'a wrong code' );
		}
		$this->refusal( $this->claim( '198.51.100.7', $code, 'Guess' ), 'claim_refused', 'a valid code from a limited source' );
		$this->ok( $this->claim( '198.51.100.8', $code, 'Other' ) );
		$this->advance( self::MINUTE );
		$this->ok( $this->claim( '198.51.100.7', $this->code( $this->cashier_a, self::A ), 'Later' ) );
	}

	/** 'two IPv6 addresses in one /64 share a limit' */
	public function test_two_ipv6_addresses_in_one_64_share_a_limit(): void {
		$code = $this->code( $this->cashier_a, self::A );
		for ( $i = 0; $i < 10; ++$i ) {
			$this->refusal( $this->claim( $i % 2 ? '2001:db8:0:1:ffff::9' : '2001:db8:0:1::5', self::unused_code( $code ), 'Guess' ), 'claim_refused', 'a wrong code' );
		}
		$this->refusal( $this->claim( '2001:db8:0:1::5', $code, 'Guess' ), 'claim_refused', 'a valid code from a limited /64' );
		$this->refusal( $this->claim( '2001:db8:0:1:ffff::9', $code, 'Guess' ), 'claim_refused', 'a valid code from a limited /64' );
		$this->ok( $this->claim( '2001:db8:0:2::5', $code, 'Other' ) );
	}

	/** '100 failed claims store-wide pause every claim for 10 minutes' */
	public function test_100_failed_claims_store_wide_pause_every_claim_for_10_minutes(): void {
		$code = $this->code( $this->cashier_a, self::A );
		// 9 failures per address, so no source reaches its own limit of 10.
		for ( $i = 0; $i < 100; ++$i ) {
			$this->refusal( $this->claim( '203.0.113.' . ( intdiv( $i, 9 ) + 1 ), self::unused_code( $code ), 'Guess' ), 'claim_refused', 'a wrong code' );
		}
		$this->refusal( $this->claim( '203.0.113.200', $code, 'Shop' ), 'claim_refused', 'a valid code while paused' );
		$this->advance( 10 * self::MINUTE );
		$this->ok( $this->claim( '203.0.113.201', $this->code( $this->cashier_a, self::A ), 'Shop' ) );
	}

	/** 'the claim secret and the token are stored only as hashes' */
	public function test_the_claim_secret_and_the_token_are_stored_only_as_hashes(): void {
		$paired = $this->paired( $this->cashier_a, self::A, '192.0.2.1', 'Counter' );
		$text   = $this->stored_text();
		$this->assertMatchesRegularExpression( self::SECRET, $paired['claim']['claim_secret'] );
		$this->assertStringNotContainsString( $paired['claim']['claim_secret'], $text, 'the claim secret is stored' );
		$this->assertStringNotContainsString( $paired['token'], $text, 'the token is stored' );
		$this->assertStringContainsString( hash( 'sha256', $paired['token'] ), $text, "the token's SHA-256 hex is not stored" );
	}

	/** 'the registry lists a display on its owning device only, and tracks connected' */
	public function test_the_registry_lists_a_display_on_its_owning_device_only_and_tracks_connected(): void {
		$paired = $this->paired( $this->cashier_a, self::A, '192.0.2.1', 'Counter' );
		$rows   = $this->list_displays( $this->cashier_a, self::A );
		$this->assertCount( 1, $rows );
		$this->assertSame(
			array(
				'display_id'   => $paired['display_id'],
				'name'         => 'Counter',
				'cashier_id'   => (string) $this->cashier_a,
				'last_seen_at' => null,
				'connected'    => false,
			),
			$rows[0]
		);
		$this->assertSame( array(), $this->list_displays( $this->cashier_b, self::B ), 'another device lists the display' );
		$this->ok( $this->display_receive_signals( $paired['token'] ) );
		$this->assertTrue( $this->list_displays( $this->cashier_a, self::A )[0]['connected'], 'a display just seen is not connected' );
		$this->assertSame( '2027-01-15T08:00:00.000Z', $this->list_displays( $this->cashier_a, self::A )[0]['last_seen_at'] );
		$this->advance( 31000 );
		$this->assertFalse( $this->list_displays( $this->cashier_a, self::A )[0]['connected'], 'a display unseen for 31 s is connected' );
	}

	/** 'forget revokes the token, the row and the snapshot, for the owner only' */
	public function test_forget_revokes_the_token_the_row_and_the_snapshot_for_the_owner_only(): void {
		$paired = $this->paired( $this->cashier_a, self::A, '192.0.2.1', 'Counter' );
		$id     = $paired['display_id'];
		$this->ok( $this->post_snapshot( $this->cashier_a, self::A, $id, '{"v":1}' ) );
		$this->ok( $this->display_send_signal( $paired['token'], 'answer', 'a1' ) );
		$this->refusal( $this->till( 'DELETE', 'displays/' . $id, $this->cashier_b, self::B ), 'not_found', 'forget from another device' );
		$this->assertCount( 1, $this->list_displays( $this->cashier_a, self::A ), 'a refused forget removed the display' );
		$this->assertSame( array( 'forgotten' => true ), $this->ok( $this->till( 'DELETE', 'displays/' . $id, $this->cashier_a, self::A ) ) );
		$this->refusal( $this->display_receive_signals( $paired['token'] ), 'token_refused', 'a forgotten token' );
		$this->assertSame( array(), $this->list_displays( $this->cashier_a, self::A ), 'the owner still lists a forgotten display' );
		$this->assertNotContains( $id, array_column( $this->receive_signals( $this->cashier_a, self::A ), 'display_id' ), 'the till received from a forgotten display' );
		$counts = $this->stored_counts();
		$this->assertSame( 0, $counts['displays'], 'forget left a display' );
		$this->assertSame( 0, $counts['snapshots'], 'forget left a snapshot' );
	}

	/** 'a display confirmed by a second till moves to it' */
	public function test_a_display_confirmed_by_a_second_till_moves_to_it(): void {
		$first = $this->paired( $this->cashier_a, self::A, '192.0.2.1', 'Counter' );
		$this->ok( $this->post_snapshot( $this->cashier_a, self::A, $first['display_id'], '{"v":1}' ) );
		$this->ok( $this->send_signal( $this->cashier_a, self::A, $first['display_id'], 'offer', 'o1' ) );
		$this->ok( $this->display_send_signal( $first['token'], 'answer', 'a1' ) );
		$moved = $this->paired( $this->cashier_b, self::B, '192.0.2.1', 'Counter', $first['token'] );
		$this->assertSame( array( 'snapshot' => null ), $this->ok( $this->read_snapshot( $moved['token'] ) ), "the first till's snapshot survived the move" );
		$this->assertSame( array(), $this->ok( $this->display_receive_signals( $moved['token'] ) ), "the first till's signal survived the move" );
		$this->assertSame( 0, $this->stored_counts()['snapshots'], 'a snapshot is stored after the move' );
		$this->assertNotContains( $first['display_id'], array_column( $this->receive_signals( $this->cashier_a, self::A ), 'display_id' ), 'the first till received from a moved display' );
		$this->assertSame( array(), $this->list_displays( $this->cashier_a, self::A ), 'the first till still lists a moved display' );
		$this->assertContains( $first['display_id'], array_column( $this->list_displays( $this->cashier_b, self::B ), 'display_id' ) );
		$this->assertSame( $first['display_id'], $moved['display_id'], 'the moved display has a new id' );
		$this->refusal( $this->read_snapshot( $first['token'] ), 'token_refused', 'the old token after a move' );
	}

	/** 'signals arrive once and in order, and only at their own display or till' */
	public function test_signals_arrive_once_and_in_order_and_only_at_their_own_display_or_till(): void {
		$x = $this->paired( $this->cashier_a, self::A, '192.0.2.1', 'X' );
		$y = $this->paired( $this->cashier_a, self::A, '192.0.2.2', 'Y' );
		$z = $this->paired( $this->cashier_b, self::B, '192.0.2.3', 'Z' );
		$this->assertSame( array( 'queued' => true ), $this->ok( $this->send_signal( $this->cashier_a, self::A, $x['display_id'], 'offer', 'o1' ) ) );
		$this->ok( $this->send_signal( $this->cashier_a, self::A, $x['display_id'], 'ice', 'i1' ) );
		$at_x = $this->ok( $this->display_receive_signals( $x['token'] ) );
		$this->assertSame(
			array(
				array(
					'type'    => 'offer',
					'payload' => 'o1',
				),
				array(
					'type'    => 'ice',
					'payload' => 'i1',
				),
			),
			$at_x
		);
		$this->assertSame( array(), $this->ok( $this->display_receive_signals( $x['token'] ) ), 'display X received a message twice' );
		$this->assertSame( array(), $this->ok( $this->display_receive_signals( $y['token'] ) ), "display Y received display X's messages" );
		$this->assertSame( array( 'queued' => true ), $this->ok( $this->display_send_signal( $x['token'], 'answer', 'a1' ) ) );
		$this->ok( $this->display_send_signal( $x['token'], 'ice', 'i2' ) );
		$this->ok( $this->display_send_signal( $z['token'], 'answer', 'z1' ) );
		foreach ( $this->receive_signals( $this->cashier_b, self::B ) as $message ) {
			$this->assertSame( $z['display_id'], $message['display_id'], "device B received device A's display" );
		}
		$at_a = array_map(
			static function ( array $m ) use ( $x ): string {
				return ( $m['display_id'] === $x['display_id'] ? 'true' : 'false' ) . ':' . $m['payload'];
			},
			$this->receive_signals( $this->cashier_a, self::A )
		);
		$this->assertSame( 'true:a1,true:i2', implode( ',', $at_a ) );
		$this->assertSame( array(), $this->receive_signals( $this->cashier_a, self::A ), 'till A received a message twice' );
	}

	/** 'the snapshot needs the token, keeps the latest body for 60 s, and refuses over 200 KiB' */
	public function test_the_snapshot_needs_the_token_keeps_the_latest_body_for_60_s_and_refuses_over_200_kib(): void {
		$paired = $this->paired( $this->cashier_a, self::A, '192.0.2.1', 'Counter' );
		$id     = $paired['display_id'];
		$this->refusal( $this->read_snapshot( 'not-a-token-not-a-token' ), 'token_refused', 'a read without the token' );
		$this->assertSame( array( 'stored' => true ), $this->ok( $this->post_snapshot( $this->cashier_a, self::A, $id, '{"seq":1}' ) ) );
		$this->ok( $this->post_snapshot( $this->cashier_a, self::A, $id, '{"seq":2}' ) );
		$this->assertSame( '{"snapshot":{"seq":2}}', wp_json_encode( $this->ok( $this->read_snapshot( $paired['token'] ) ) ), 'the latest snapshot is not served' );
		$this->advance( 61000 );
		$this->assertSame( array( 'snapshot' => null ), $this->ok( $this->read_snapshot( $paired['token'] ) ), 'a snapshot outlived 60 s' );
		$this->ok( $this->post_snapshot( $this->cashier_a, self::A, $id, '{"seq":3}' ) );
		$this->refusal( $this->post_snapshot( $this->cashier_a, self::A, $id, (string) wp_json_encode( array( 'pad' => str_repeat( 'x', 204800 ) ) ) ), 'too_large', 'a snapshot over 200 KiB' );
		$this->assertSame( '{"snapshot":{"seq":3}}', wp_json_encode( $this->ok( $this->read_snapshot( $paired['token'] ) ) ), 'a refused snapshot replaced the previous one' );
	}

	/** 'expired codes and displays unseen for 30 days are pruned' */
	public function test_expired_codes_and_displays_unseen_for_30_days_are_pruned(): void {
		$paired = $this->paired( $this->cashier_a, self::A, '192.0.2.1', 'Counter' );
		$this->ok( $this->mint( $this->cashier_a, self::A ) );
		$this->advance( 10 * self::MINUTE );
		$this->list_displays( $this->cashier_a, self::A );
		$this->assertSame( 0, $this->stored_counts()['codes'], 'an expired code is still stored' );
		$this->advance( 30 * self::DAY );
		$this->list_displays( $this->cashier_a, self::A );
		$this->assertSame( 0, $this->stored_counts()['displays'], 'a display unseen for 30 days is still stored' );
		$this->refusal( $this->read_snapshot( $paired['token'] ), 'token_refused', 'a pruned display' );
	}
}
