# Coin-payment contract

All three medicines remain **₱10 per dispensing unit (1000 centavos)**. PHP calculates new totals from database prices. Existing order totals and snapshots are not repriced.

Run `C:\xampp\php\php.exe scripts/migrate.php` before using the new code. The re-runnable XAMPP MariaDB migration `003_twenty_peso_coins.sql` expands the ledger denomination constraint to 100, 500, 1000 and 2000 centavos and adds `event_source`. Existing rows receive `demo`; their values and recorded outcomes are preserved.

## Demo coin attempts

The existing `api/demo-payment.php` accepts exactly `event_id` and `coin_centavos`, with the current demo-order capability header. Denominations are **₱1, ₱5, ₱10 and ₱20**. All amounts are integer centavos. The display shows total due, amount inserted, remaining balance and accepted demo denominations, with:

> Exact payment only. This machine cannot return change.

Buttons greater than the remaining balance disable. PHP independently rejects them, records a persistent rejected event, and leaves credit unchanged. Smaller usable denominations are explained. Any fitting combination can reach the saved total; payment remains partial until exact, then paid. New demo events after full payment fail. No animation alone adds credit.

Every event has a stable, globally unique ID. The order-row lock, unique ledger ID and transaction make retries/concurrent duplicates take effect once. Retrying an accepted/rejected event replays its original result. Changing the order, denomination or source under the same ID conflicts. Existing session/local-storage recovery includes pending ₱20 event IDs; refresh checks their persisted result rather than inventing another coin.

## Coins already accepted

A disabled screen button does **not** block a physical coin. This demo has no enabled acceptor feed, serial connection, motor control or public accepted-money endpoint. Rejection, denomination inhibit and return capabilities remain unverified. Keep hardware simulated/disconnected.

`medivendRecordAcceptedCoin()` in `includes/accepted-coin.php` is the separate trusted-server recorder, tested using simulated observations. Its input is the saved reference, stable event ID and integer accepted denomination. It must only receive an observation that the coin has already been accepted; the browser cannot select this path through JSON.

The recorder locks the order and writes an `accepted_coin` ledger event plus the **full** credit in one transaction. It never caps the coin at the remaining balance, logs it as rejected, or infers that money was returned. Duplicate observations replay once. A fitting coin on a pending order advances partial/paid status. An excess coin, or a new accepted coin after payment/dispensing/session closure, flags both order and dispensing `needs_review`, retaining payment and any confirmed item quantities. Further accepted observations still preserve their full value. Automatic dispensing cannot start or continue through the locked simulation endpoints after that flag.

For example, an accepted ₱20 on a ₱10 order records **₱20 inserted, ₱0 remaining, ₱10 excess**, and requests staff. An accepted ₱20 after ₱5 toward a ₱20 order records **₱25 inserted and ₱5 excess**. The UI restores this state after refresh and Start over cannot clear it. No change/refund is claimed. Payment can be `paid` because the total is covered; `needs_review` prevents fulfillment.

A future verified adapter must authenticate its input, bind every observation to the correct saved order, and durably queue each stable event before acknowledging it. If storage is unavailable, an ID conflicts, an order is unknown or the existing unsigned credit storage limit would overflow, the recorder raises an error rather than falsely acknowledging credit. The adapter must retain that observation, stop accepting/dispensing where verified controls permit, alert staff and retry/reconcile it; an error must never be interpreted as a returned coin. This adapter is **not implemented or enabled** here. An already accepted coin must never be sent through the demo-attempt rejection endpoint.

## Verified tests

Local XAMPP validation passed all four API suites, PHP/JavaScript syntax checks, and 36 browser checks across 26 screen states at 1024 × 600 with touch emulation. The migration was run twice successfully. Original row values, credits and stock were compared before/after and retained; the added source column is `demo` for historical events. There were no uncaught browser errors. The physical panel and acceptor remain untested.

Run the four PHP API suites and optional browser audit as described in [README](../README.md#test-through-xampp). The coin/API and touch-browser tests include:

- One ₱20 exactly paying a ₱20 order, including concurrent retries and refresh after a lost response.
- A mixed ₱40 payment using all four denominations.
- A disabled ₱20 button on a ₱10 order; bypassing the UI still produces a backend rejection with zero credit.
- A disabled/rejected ₱20 after ₱5 toward a ₱20 total, retaining the ₱5 and explaining smaller coins.
- Simulated already-accepted excess value recorded in full, staff-review status, blocked dispensing/Start over, and restored excess credit after reload.
- Duplicate and distinct concurrent accepted observations, additional observations after paid, and stopping a dispensing session while preserving its confirmed unit.

Tests create and remove only isolated demo fixtures. Existing stock, order snapshots, recorded payments and deliveries are retained. Physical acceptor behavior remains untested.
