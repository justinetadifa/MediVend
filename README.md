# MediVend

A touchscreen clinic catalog with **database-backed checkout, demo payment, and simulated dispensing**, using PHP, PDO, and the local MySQL database `medivend_db`. The existing MediVend design, product photos, fonts, and continuous welcome animation are preserved. Designed for **1024 × 600**, with responsive layouts.

Saving an order creates a `transactions` row and its `transaction_items` snapshots, initially payment **pending** and dispensing **not_started**. Demo coin events are stored persistently in `demo_coin_events` and update the balance atomically. Only a newly confirmed simulated delivery changes demo-product stock. **Real payments and physical hardware are never connected.**

## Run through XAMPP

1. Keep this project at `C:\xampp\htdocs\MediVend`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Use the existing `medivend_db` database. On a fresh machine, import your original `medivend_db.sql` in [phpMyAdmin](http://localhost/phpmyadmin/), then apply the additive migration and current-price update below.
4. Check `config/database.php`: host `localhost`, port `3306`, database `medivend_db`, username `root`, and an empty password. XAMPP's `pdo_mysql` extension must be enabled.
5. Open **[http://localhost/MediVend/](http://localhost/MediVend/)**. Tap **Get Started**, choose medicines, open **Review order**, acknowledge the demo, and tap **Save order**.
6. The **Demo payment** screen shows the saved reference, total due, amount inserted, remaining balance, and accepted denominations. Test with **Demo insert ₱1 / ₱5 / ₱10**. It displays **Exact payment only. No change.**
7. After **Demo payment complete**, tap **Simulate dispensing**, then simulate each confirmed unit or a fault. These controls change demo database records only.

Use Apache rather than opening `index.html` directly or using a static-only server. No Composer packages, npm installation, build step, or internet connection is required.

Local credentials are separate from the PDO helper. Optional `MEDIVEND_DB_HOST`, `MEDIVEND_DB_PORT`, `MEDIVEND_DB_NAME`, `MEDIVEND_DB_USER`, and `MEDIVEND_DB_PASSWORD` environment variables override the defaults. Direct Apache access to the config directory is denied.

Apply the migration from PowerShell in the project folder before using coin controls:

```powershell
& 'C:\xampp\php\php.exe' scripts/migrate.php
```

The CLI-only runner uses the configured database and can be run again. It applies `database/migrations/001_demo_coin_events.sql` (adds the event ledger) and `database/updates/002_prices_10_pesos.sql` (updates only the three current medicine SKUs). Both SQL files can also be run in phpMyAdmin with `medivend_db` selected. Neither file deletes data or rewrites historical order totals, credits, or item price snapshots. Existing pre-ledger credit is preserved; no fabricated historical events are inserted.

## Catalog

`GET /MediVend/api/products.php` returns the existing product fields as JSON. Only `is_active = 1` products are returned, ordered by compartment and ID. `price_centavos` and `stock_quantity` are integers; nullable descriptions/images are supported.

All three current medicines cost **₱10 per dispensing unit (1000 centavos)** after the price update. PHP still reads actual database prices and quantities when saving new orders; JavaScript cannot supply an order total. Previously saved orders retain their original totals and unit-price snapshots. Quantity buttons use database stock. Zero-stock active products remain visible with **Out of stock**. Local images have bundled/placeholder fallbacks, and database text is escaped before rendering.

The catalog has loading, empty, connection-error, timeout, and retry states. The welcome animation continues during requests. New sessions and page reloads fetch fresh catalog data. Cart prices are estimates; checkout always reprices on the server.

## Checkout API

`POST /MediVend/api/checkout.php` requires `Content-Type: application/json` and an `Idempotency-Key` header containing 64 lowercase hexadecimal characters. The browser generates a random key for each new checkout intent. The body contains **only** product IDs and quantities:

```json
{"items":[{"product_id":3,"quantity":2},{"product_id":1,"quantity":1}]}
```

Prices, names, totals, compartment numbers, payment status, and dispensing status cannot be supplied by JavaScript. Unknown body fields are rejected.

The endpoint uses PDO prepared statements and a single database transaction. It claims a unique order reference, locks products in ID order, validates active products and available stock, and reads prices/names/compartments from MySQL. It inserts each item snapshot and saves the calculated integer total before committing. Any failure rolls back the order and every item, including items written before a later validation failure.

Quantities must be JSON integers from 1 to 65,535, matching `transaction_items.quantity`. Products cannot appear twice in a request. A request supports up to 100 distinct products, a 64 KiB JSON body, and totals within the existing unsigned integer column. Missing/inactive products and insufficient stock are rejected. Stock is checked at save time; pending orders do not reserve it.

Every newly saved order has:

- `payment_status = 'pending'`, `amount_paid_centavos = 0`.
- `dispensing_status = 'not_started'`, `order_status = 'pending'`.
- `is_demo = 1`, `completed_at = NULL`.
- Item snapshots with `dispensed_quantity = 0`.

A new order returns **201**; a successful retry returns **200** with `replayed: true` and the original saved order. The saved reference is derived from the request key and protected by the existing unique reference index. Concurrent requests with the same key create one order. The same key with a different cart returns **409**. Replays return original snapshots even if current product names, prices, or availability changed.

Other errors return JSON: **400** for malformed JSON/key, **413/415** for body size/content type, **422** for invalid fields/quantities/totals, **409** for unavailable stock/products, and **503** when saving cannot be confirmed. Database diagnostics stay in the server log.

## Retry and failure behavior

The browser saves the retry key, immutable IDs/quantities, and display-only cart information in `sessionStorage` before sending. Only IDs and quantities are posted; cached prices never price the saved order. The save button disables during the request to prevent repeated taps.

A validation failure preserves the cart and allows editing. A network failure or timeout may happen after the database committed, so the browser keeps the original request and offers **Retry save**. Editing and starting over are temporarily disabled until that request is resolved. Reloading the same tab restores this unconfirmed cart and key. Retrying checks or saves the same order rather than creating another one.

The cart clears only after a valid saved-order response. The demo screen uses the server's saved reference, prices, total, balance, and delivery counts. Once any demo credit is recorded, Start over and the home logo cannot discard an unfinished order. They open **Staff assistance**, showing its reference and last confirmed inserted amount. The bottom action also becomes Staff assistance. No refund is claimed. A new session is allowed after the simulated order is fully delivered, or before any credit/event exists.

The current order and any unconfirmed coin ID are saved before sending a coin. Protected credit also has a local-storage recovery marker, so closing/reopening the tab on the same browser restores the saved order. If it cannot continue, keep its reference and contact clinic staff; this stage has no refund or cancellation endpoint.

## Demo payment and dispensing

The tab keeps its current-order capability in `sessionStorage`. `X-Demo-Order-Key` binds simulation requests to that exact saved order; a public reference alone is insufficient. Every simulation endpoint rejects non-demo orders. Stock consumption additionally requires demo, active products. Reloading the same tab retrieves its saved state through `GET api/demo-order.php`.

`POST api/demo-payment.php` accepts only a unique `event_id` (64 lowercase hexadecimal characters) and integer `coin_centavos` (100, 500, or 1000), with the current `X-Demo-Order-Key` capability header. Any combination is accepted while it fits the remaining balance; payment stays `partial` until the saved total is reached, then becomes `paid`. New events after full payment are rejected. Payment alone never changes stock or dispensing.

```json
{"event_id":"0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef","coin_centavos":500}
```

PHP locks the order row, checks the event ledger and database balance, and saves the coin-event result and balance change in **one transaction**. `demo_coin_events.event_id` is globally unique. Repeating the same ID/order/denomination returns its persisted result with `replayed: true`, even if other coins have since been inserted or the order is now fully paid. It never adds credit again. Reusing an ID for a different coin/order returns `409 coin_event_conflict`. Distinct simultaneous events serialize against the remaining balance.

The ledger records accepted coins and valid-but-rejected attempts, including overpayment and payment-closed outcomes, along with before/after amounts and a reason. Accepted events return 200; rejected events return 409 with their saved result. Structurally invalid IDs, types, or denominations return 422 without credit. A retry of a rejected event remains rejected. Old credits from before migration remain part of the saved balance.

Overpayment errors explain the smaller supported **simulated** denominations that fit. Both UI and API display **Exact payment only. No change.** This is a simulator rule, not a claim that a physical acceptor can reject or return a real coin; those hardware capabilities have not been verified. The UI never credits a frontend animation.

`POST api/demo-dispense.php` accepts only `state_token`, `action`, and `product_id`. Actions are `start`, `delivered`, `jam`, or `no_confirmation`; `product_id` is an integer only for `delivered`, otherwise null.

- `start` requires fully paid demo status and rechecks every item's remaining quantity against current stock, under locks. It enters `processing` / `in_progress`; insufficient stock flags `needs_review` without consuming stock.
- `delivered` confirms **one unit** of an ordered item. In one database transaction, PHP locks the order/product, guards stock above zero, decrements product stock, and increases `transaction_items.dispensed_quantity` without exceeding quantity. Partial deliveries remain `processing` / `partial`.
- Only when all units are confirmed does PHP set `order_status = completed`, `dispensing_status = dispensed`, and `completed_at`. This confirms a **simulation**, never physical delivery.
- `jam` or `no_confirmation` stops with order and dispensing status `needs_review`, preserves prior delivery counts, and shows **Please contact clinic staff**. No delivery is inferred from missing confirmation, no stock is changed for that unit, no automatic retry occurs, and payment remains recorded; no refund is claimed.

Delivery responses still use a `state_token` tied to the saved order/counters; stale delivery tokens return **409 stale_event** without consuming another unit. Coin payments instead use the persistent event ledger above, so safe coin retries do not depend on an old balance token.

Coin controls disable while a request or unresolved coin is pending. **Retry same demo coin event** resends exactly the persisted ID/denomination. **Refresh status** uses `X-Demo-Event-ID` to look up that event without resending it: accepted/rejected outcomes are recovered from the ledger; if absent, the same event can be retried explicitly. Only PHP-confirmed state updates the amounts. The order/credit cannot be reset during uncertain results. Delivery events are never automatically resent; an uncertain incomplete delivery still stops for staff and can be flagged with **Simulate no sensor confirmation**.

## Test through XAMPP

With Apache/MySQL running, first check [the catalog JSON](http://localhost/MediVend/api/products.php). From PowerShell in the project folder:

```powershell
& 'C:\xampp\php\php.exe' tests/products-api.test.php
& 'C:\xampp\php\php.exe' tests/checkout-api.test.php
& 'C:\xampp\php\php.exe' tests/demo-flow-api.test.php
& 'C:\xampp\php\php.exe' tests/coin-payment-api.test.php
```

The catalog test is read-only. Checkout, delivery, and coin tests use XAMPP's `curl` extension and their own temporary rows. They cover server prices, snapshots, rollback, mixed coins/multiple items, persistent replay, concurrent identical/distinct events, refresh lookup, overpayment, additional coins after full payment, existing credits, and delivery faults. They remove only their own test rows. Set `MEDIVEND_TEST_BASE_URL` if Apache uses another port or project URL.

For a manual browser check:

1. After migrating, add two Paracetamol items and one Cetirizine. Review shows **₱30.00**.
2. Acknowledge the demo and tap **Save order**, including a quick repeated tap. One saved reference and **₱30.00** should appear on **Demo payment**.
3. In phpMyAdmin, inspect `transactions` and `transaction_items`. Confirm one order for that reference, matching item snapshots, `pending` / `not_started`, zero paid/dispensed amounts, and unchanged product stock.
4. To test a connection failure, stop MySQL **after opening checkout review**, then save. The cart remains. Restart MySQL and tap **Retry save**; the original request key is reused.
5. Insert two **demo ₱10** coins, one **demo ₱5**, and five **demo ₱1** coins. The balance becomes zero and **Demo payment complete** appears. Confirm `amount_paid_centavos = 3000`, payment `paid`, dispensing still `not_started`, and eight accepted ledger rows. A new event after that is rejected; retrying an old event never adds credit.
6. Before full payment, try Start over: staff assistance must appear and the order/inserted credit must remain. Reload, or close/reopen the tab in the same browser, to recover that order. For a ₱4 remainder, only demo ₱1 coins fit; overpayment explains the smaller denomination.
7. After full payment, tap **Simulate dispensing**. Confirm a unit or simulate a jam. Staff review preserves credit and partial delivery; it does not complete or refund the order. A fully delivered simulation can finish the session. Manual delivery tests consume **demo database stock**; use suitable test inventory.

Useful read-only inspection queries in phpMyAdmin:

```sql
SELECT id, reference, total_centavos, amount_paid_centavos,
       payment_status, dispensing_status, order_status, is_demo
FROM transactions ORDER BY id DESC;

SELECT transaction_id, product_id, product_name, compartment_number,
       quantity, unit_price_centavos, dispensed_quantity
FROM transaction_items ORDER BY transaction_id DESC, product_id;

SELECT event_id, transaction_id, coin_centavos, outcome, reason_code,
       amount_before_centavos, amount_after_centavos, created_at
FROM demo_coin_events ORDER BY id DESC;
```

## Files

- `config/database.php`: separate local credentials and optional environment overrides.
- `includes/database.php`: PDO connection with native prepared statements.
- `includes/checkout.php`: validation, atomic order creation, snapshots, and duplicate-request replay.
- `api/products.php`: read-only active-product JSON endpoint.
- `api/checkout.php`: POST-only pending demo-order endpoint.
- `includes/demo-order.php`: locked demo state transitions, event-token guards, and confirmed-unit stock updates.
- `includes/coin-events.php`: persistent exactly-once coin results, integer balance updates, and no-change guidance.
- `database/migrations/001_demo_coin_events.sql`: additive ledger migration.
- `database/updates/002_prices_10_pesos.sql`: non-destructive current-price update; historical orders are not repriced.
- `scripts/migrate.php`: re-runnable CLI migration runner.
- `api/demo-order.php`, `api/demo-payment.php`, `api/demo-dispense.php`: current demo-order status and simulation endpoints.
- `assets/app.js`: catalog/cart, checkout, safe request persistence, and shared rendering.
- `assets/demo.js`: demo coins, per-unit delivery/fault controls, current-order restoration, and uncertain-result handling.
- `assets/style.css`, `assets/welcome.css`, `assets/fonts.css`: shared interface and animations.
- `tests/products-api.test.php`, `tests/checkout-api.test.php`, `tests/demo-flow-api.test.php`, `tests/coin-payment-api.test.php`: XAMPP regression checks.

A saved order is not proof of real payment or delivery. Confirmed simulated delivery changes **demo database stock** only. No real coins, money, motors, sensors, or dispensing hardware are connected.
