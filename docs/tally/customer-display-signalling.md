# Customer display signalling (TallyUI fork, ADR-085 U7)

The server half of TallyUI's customer display seam (`packages/core/src/server/display-signalling/` in TallyUI): pairing a second screen to a till, relaying WebRTC signalling between them, and holding the till's latest display snapshot. It follows TallyUI's in-memory reference server (`memory-server.ts`) rule for rule, and passes the 19 cases of its contract (`contract.ts`) as PHPUnit tests.

Code:

- `includes/API/V2/Tally_Display_Controller.php`: the routes. `Sync\Api::register_controllers()` (`includes/Sync/Api.php`) adds `Sync\Api::register_v2_services()` to the `woocommerce_pos_rest_api_v2_controllers` filter, so the controller joins the v2 service map, not the sync controller map, and its responses carry no sync telemetry (`X-Server-Load`, `Server-Timing`).
- `includes/Services/Tally_Display/`: `Signalling` (the rules), `Store` (the tables), `Advertisement` (the status object), `Rate_Limit_Source`, `Clock`, `Test_Hooks`.
- `includes/CLI/Tally_Display_Command.php`: `wp wcpos tally-display`, registered by `Sync\Api::register_cli_commands()` (`includes/Sync/Api.php`), which `Init`'s constructor calls. `Init` runs on both load paths: Free on its own, and Free bundled inside Pro.

## Advertisement

`GET /wcpos/v2/status` lists `customer_display` in `capabilities` and adds a top-level object:

```json
"customer_display": { "v": 1, "iceServers": [ { "urls": "stun:stun.cloudflare.com:3478" }, { "urls": "stun:stun.l.google.com:19302" } ], "snapshot": 1 }
```

`iceServers` comes from the option `woocommerce_pos_tally_display_ice_servers`, which must be a **JSON string** holding a list of `{ urls, username?, credential? }`. `urls` is a non-empty string or list, each with a `stun:`, `stuns:`, `turn:` or `turns:` scheme, and a TURN entry needs both `username` and `credential`. If any entry is refused, or the option is not a JSON list, the default above is served. `[]` is kept as given. The filter `woocommerce_pos_tally_display_advertisement` gets the last word.

## Routes

Namespace `wcpos/v2`, prefix `tally/display`. Till routes need a logged-in user with `access_woocommerce_pos` (narrowed by the filter `woocommerce_pos_tally_display_can_pair`) and a `device_id` matching `^[A-Za-z0-9_-]{32,64}$` (see [The device id](#the-device-id)). Display routes are public: they carry the display token in `X-Tally-Display-Token`, or nothing before pairing.

In the examples, `$S` is the site, `$JWT` a cashier's token, and `$T` a display token.

### Till

| Method and path | Body | 200 |
|---|---|---|
| `POST codes` | `device_id` | `{code, expires_at}` |
| `GET pending?device_id=` | | `[{claim_id, display_name, check_code, expires_at}]` |
| `POST pending/{claim_id}/confirm` | `device_id` | `{confirmed: true}` |
| `POST pending/{claim_id}/decline` | `device_id` | `{declined: true}` |
| `GET displays?device_id=` | | `[{display_id, name, cashier_id, last_seen_at, connected}]` |
| `DELETE displays/{display_id}?device_id=` | | `{forgotten: true}` |
| `POST displays/{display_id}/signals` | `device_id, type, payload` | `{queued: true}` |
| `POST signals/receive` | `device_id` | `[{display_id, type, payload}]` |
| `POST displays/{display_id}/snapshot?device_id=` | the snapshot JSON, raw | `{stored: true}` |

```sh
H=(-H "Authorization: Bearer $JWT" -H 'X-WCPOS: 1' -H 'Content-Type: application/json')
curl -s "${H[@]}" -X POST "$S/wp-json/wcpos/v2/tally/display/codes" -d '{"device_id":"0192f5a8-3c1e-7a4b-9d2e-5f6a7b8c9d0e"}'
curl -s "${H[@]}" "$S/wp-json/wcpos/v2/tally/display/pending?device_id=0192f5a8-3c1e-7a4b-9d2e-5f6a7b8c9d0e"
curl -s "${H[@]}" -X POST "$S/wp-json/wcpos/v2/tally/display/pending/$CLAIM/confirm" -d '{"device_id":"0192f5a8-3c1e-7a4b-9d2e-5f6a7b8c9d0e"}'
curl -s "${H[@]}" -X POST "$S/wp-json/wcpos/v2/tally/display/pending/$CLAIM/decline" -d '{"device_id":"0192f5a8-3c1e-7a4b-9d2e-5f6a7b8c9d0e"}'
curl -s "${H[@]}" "$S/wp-json/wcpos/v2/tally/display/displays?device_id=0192f5a8-3c1e-7a4b-9d2e-5f6a7b8c9d0e"
curl -s "${H[@]}" -X DELETE "$S/wp-json/wcpos/v2/tally/display/displays/$DISPLAY?device_id=0192f5a8-3c1e-7a4b-9d2e-5f6a7b8c9d0e"
curl -s "${H[@]}" -X POST "$S/wp-json/wcpos/v2/tally/display/displays/$DISPLAY/signals" -d '{"device_id":"0192f5a8-3c1e-7a4b-9d2e-5f6a7b8c9d0e","type":"offer","payload":"…"}'
curl -s "${H[@]}" -X POST "$S/wp-json/wcpos/v2/tally/display/signals/receive" -d '{"device_id":"0192f5a8-3c1e-7a4b-9d2e-5f6a7b8c9d0e"}'
curl -s "${H[@]}" -X POST "$S/wp-json/wcpos/v2/tally/display/displays/$DISPLAY/snapshot?device_id=0192f5a8-3c1e-7a4b-9d2e-5f6a7b8c9d0e" -d '{"seq":1}'
```

### Display

| Method and path | Body | 200 |
|---|---|---|
| `POST claim` | `code, display_name, token?` | `{claim_secret, check_code}` |
| `POST collect` | `claim_secret` | `{status: "pending", check_code}`, then once `{status: "paired", display_id, token}` |
| `POST signals` | `type, payload` | `{queued: true}` |
| `POST signals/receive/display` | | `[{type, payload}]` |
| `GET snapshot` | | `{snapshot: <the JSON> \| null}` |

```sh
D=(-H 'X-WCPOS: 1' -H 'Content-Type: application/json')
curl -s "${D[@]}" -X POST "$S/wp-json/wcpos/v2/tally/display/claim" -d '{"code":"123456","display_name":"Counter"}'
curl -s "${D[@]}" -X POST "$S/wp-json/wcpos/v2/tally/display/collect" -d '{"claim_secret":"…"}'
curl -s "${D[@]}" -H "X-Tally-Display-Token: $T" -X POST "$S/wp-json/wcpos/v2/tally/display/signals" -d '{"type":"answer","payload":"…"}'
curl -s "${D[@]}" -H "X-Tally-Display-Token: $T" -X POST "$S/wp-json/wcpos/v2/tally/display/signals/receive/display"
curl -s "${D[@]}" -H "X-Tally-Display-Token: $T" "$S/wp-json/wcpos/v2/tally/display/snapshot"
```

A claim that sends its current `token` moves that display to the confirming till: on confirm its old row, token, snapshot and inboxes go and its `display_id` carries over.

## Rules

- A code is 6 digits and lives 10 minutes. A device holds at most 5 unspent codes. A claim spends its code. The check code is 4 digits and unique among live claims.
- One source may fail 10 claims a minute (IPv6 counted by its /64), and 100 failures store-wide pause every claim. A claim refused by a limit spends nothing.
- Signals are 64 KiB at most each, delivered once and in order. A snapshot is 200 KiB (204800 bytes) at most, kept 60 s, and only the latest.
- A display is `connected` when seen in the last 30 s. Displays unseen for 30 days, expired codes and their claims are pruned at every call.
- Claim secrets and tokens are 128-bit base64url, stored only as SHA-256 hex.

## Signal limits

- A signal lives 5 minutes (`Signalling::SIGNAL_TTL`); every call prunes older ones. A WebRTC offer, answer or ICE candidate that old is useless, and the peers renegotiate.
- An inbox, one direction of one pairing, holds its newest 200 messages (`SIGNAL_INBOX_MAX`). A send past that drops the oldest and still answers `{queued: true}`: signalling recovers from a lost old candidate, not from a refused new offer.
- Schema version 2 added `signals.queued_at`. Rows queued before it read `0`, so the first prune after the upgrade deletes them.
- Uninstall drops the six tables; its option sweep already deletes `woocommerce_pos_tally_display_db_version` and `woocommerce_pos_tally_display_ice_servers`.

## The device id

- A till's `device_id` is a bearer secret: 32 to 64 characters of `A-Z a-z 0-9 _ -`, which is 74 or more random bits, so no one can guess another till's id. TallyUI sends a uuidv7 (36 characters).
- The controller hashes it with SHA-256 before the service runs. The tables and the CLI `dump` hold only the hash, and no response body or header carries the id, plaintext or hashed. Error messages are the fixed strings below.
- A till sees only what its own id paired. Another till, whoever is signed in, gets `tally_display_not_found` for this till's claims and displays, and empty lists.
- The id is not bound to the signed-in user, because a till keeps its id across cashier sign-ins and a binding would darken its display at every shift change.
- Schema version 2 keys rows by the hash, so an upgrade from version 1 deletes every row in `codes`, `pending`, `displays`, `signals` and `snapshots`, and displays pair again once.
- Accepted risk: anyone holding a till's local storage can act as that till until the server-issued device key lands.

## Errors

| Code | Status | When |
|---|---|---|
| `tally_display_forbidden` | 403 | logged in without the capability, or a missing, malformed or too short `device_id` |
| `tally_display_limit` | 429 | a 6th unspent code |
| `tally_display_claim_refused` | 403 | any refused claim: unknown, spent or expired code, a limited source, the store-wide pause |
| `tally_display_collect_refused` | 403 | any refused collect: unknown, declined, expired or already-collected secret |
| `tally_display_not_found` | 404 | a claim or display this device does not own, or that does not exist |
| `tally_display_token_refused` | 401 | a missing, unknown, forgotten or moved token |
| `tally_display_too_large` | 413 | a signal over 64 KiB or a snapshot over 200 KiB |
| `rest_invalid_param` | 400 | a malformed body: a body that is not JSON, a missing or empty `display_name`, a bad `type`, a snapshot that is not JSON |

Each code has one message whatever the cause, so a refusal never tells a guesser which case it hit.

## Departures from the reference, and why

Each was accepted by the TallyUI front desk (2026-11-11).

- **Schema on first use.** The six tables `wp_wcpos_tally_display_{codes,pending,displays,signals,snapshots,failures}` are created by `Store::ensure()` on the first route or CLI call, keyed by the option `woocommerce_pos_tally_display_db_version`, rather than by the activator or an update script, so the fork leaves `Activator.php` and `includes/updates/` untouched.
- **The token is minted at the first collect after the confirm,** as the reference does, so its plaintext never exists in the database. The confirm stores only the claim secret's hash.
- **Sliding failure windows.** The reference opens a fixed window at the first failure. Here a claim counts the failures in the last minute (per source) and the last 10 minutes (store-wide). That is never looser: a pause still lasts until 10 minutes after the failures that caused it.
- **Hashed source keys.** The failure log stores SHA-256 of the rate-limit key, never an address.
- **401 for a logged-out till.** A till route called with no user gets the plugin gate's `woocommerce_pos_rest_unauthorized` (401) before the route runs. A logged-in user without the capability gets `tally_display_forbidden`.
- **The ICE option is a JSON string,** so it can be set with `wp option update` and validated as a whole.
- **The display name is trimmed and cut to 64 characters.** An empty name after trimming is `rest_invalid_param`.
- **`device_id` is read from the query string first, then the JSON body.** On the snapshot route, whose body is the snapshot, it is read from the query only.

Gap-fills made while implementing, also accepted as built (2026-11-11):

- **A body that is not JSON is `rest_invalid_param`.** WordPress refuses it as `rest_invalid_json` before the route runs; `Tally_Display_Controller::invalid_json_as_invalid_param()` (on `rest_request_before_callbacks`, for this prefix only) maps it to the contract's code.
- **A v2 service, not a sync route.** The sync drift test requires every sync-map controller's routes to carry sync telemetry. The display routes are public and polled, so they join the v2 service map instead and get no server-load headers.
- **The gate's classification test lists these routes.** `tests/includes/API/Test_Route_Classifier.php` now expects the five display routes under `public` and `/wcpos/v2/tally/display/` under `permission_error_passthrough`, so a logged-in user without the capability gets `tally_display_forbidden` rather than the gate's refusal.

## Test hooks

Defining `WCPOS_TALLY_DISPLAY_TEST_HOOKS` as `true` (in `wp-config.php`, never in production) turns on:

- `X-Tally-Test-Source`: the claim source, in place of `REMOTE_ADDR`. It is added to the CORS allow-list only then.
- The option `woocommerce_pos_tally_display_clock_offset_ms`: milliseconds added to the clock.
- `wp wcpos tally-display counts` (live rows of codes, pending, displays, snapshots), `dump` (every row, as JSON) and `reset` (empty the tables and delete the clock offset).

Without the constant the header is ignored and every CLI command exits with an error.

The filter `woocommerce_pos_tally_display_claim_source` may replace the claim source in any case, for a site behind a trusted proxy.
