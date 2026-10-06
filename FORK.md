# TallyUI fork of WCPOS

`TallyUI/woocommerce-pos` is a fork of [`wcpos/woocommerce-pos`](https://github.com/wcpos/woocommerce-pos). It carries the server half that TallyUI's WooCommerce point of sale needs and that upstream does not.

## Branches

- **`main`** mirrors upstream `main`, fast-forward only. Nothing is ever committed to it here.
- **`tally`** is upstream `main` plus the TallyUI changes below. It is the branch the TallyUI dev store runs. Changes reach it as PRs.

## What differs from upstream, and why

TallyUI's connector (`@tallyui/connector-woocommerce`, from 3.5.3) sends fee lines, shipping lines, custom items and split tender on `push/orders` only when `GET /wcpos/v2/status` lists `order_create_v5` in `capabilities`. Upstream merged that work and then reverted it (upstream PR 2140, Paul, 2026-10-06), so this fork is its only home:

1. **order.create v5 on `push/orders`** (upstream #2137):
   - It pins fee, shipping and custom lines.
   - It records the split-tender payments list (`_woocommerce_pos_payments`, `Services/Pos_Payments`, `Pos_Order_Audit`, `Receipt_Data_Builder`, admin `Single_Order`).
   - It adds the `order_payments_list` capability.
2. **`/wcpos/v2/status` advertises `order_create_v5`** and reports `wcpos_version` (upstream #2139).

Both are reapplied as plain `git revert`s of upstream's revert commits (`1a979e12`, `415a6d4a`). `tally` was cut from upstream PR 2140's head. Its tree equals upstream `701c96ba`. The upstream `.claude/specs` notes for that work came along unchanged.

The tests that pin it are `tests/includes/Sync/Test_Rest_Dispatch_Tally_Order_Create_V5.php` and `tests/includes/Sync/Test_Sync_Status.php`.

## Syncing with upstream

Merge upstream `main` into `tally` at every upstream release, and at least weekly:

```sh
git fetch upstream
git checkout main && git merge --ff-only upstream/main && git push origin main
git checkout tally && git merge main
```

Never rebase or force-push `tally`. After each merge, check that `order_create_v5` is still in `Status_Controller::CAPABILITIES`, and run the two test files above.

If upstream lands PR 2140 with commits other than `1a979e12` and `415a6d4a` (a squash, for example), merging it removes the v5 work without a conflict. Fix this on `tally` with `git revert <that upstream commit>`.

## Where a change goes

- **TallyUI-only needs** go to this fork, as PRs into `tally` under the TallyUI family merge rules.
- **A bug or improvement that stands on its own for WCPOS users**, with a reproduction that does not involve TallyUI, is reported to the front desk. It becomes an upstream PR only on Paul's word. Nobody asks upstream to change for TallyUI's sake.
- **WCPOS Pro is not forked.** If a change inside Pro is ever needed, stop and ask first: that would be a private fork.

## The dev store overlay

The TallyUI dev store (the `tallyui-woocommerce` repository, Mac mini only) runs WCPOS Pro, and Pro always loads its own bundled copy of Free from `woocommerce-pos-pro/vendor/wcpos/woocommerce-pos/`. Installing this fork as a separate plugin would change nothing, so `dev/woo-store/wcpos-fork.sh` in that repository overlays the bundle instead:

- `apply` backs up Pro's stock bundle once. It then applies only what changed between the commit Pro bundles (recorded in Pro's `vendor/composer/installed.json`) and `tally`: added and modified files are copied in, leaving out what `.distignore` leaves out, and deleted files are removed. It does not copy the whole tree, because this repository tracks a stale `vendor_prefixed/` that the release build regenerates. It refuses when `tally` changes `composer.json`, `composer.lock`, `php-scoper/`, `vendor/`, `vendor_prefixed/`, `assets/` or `packages/` relative to that commit, since those need a real build.
- `restore` puts the stock bundle back exactly.
- `status` prints `tally <sha>` or `stock`.

A Pro update overwrites the overlay. The dev store's `smoke.sh` then fails until `apply` runs again, and the app meanwhile shows v5 off, which is the safe failure. Nothing from Pro is ever copied into a repository.
