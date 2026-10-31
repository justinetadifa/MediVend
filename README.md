# MediVend

A touchscreen clinic catalog with **database-backed checkout, demo payment, and simulated dispensing**, using PHP, PDO, and the local MySQL database `medivend_db`. The existing MediVend design, product photos, fonts, and continuous welcome animation are preserved. Designed for **1024 × 600**, with responsive layouts.

Saving an order creates a `transactions` row and its `transaction_items` snapshots, initially payment **pending** and dispensing **not_started**. Demo coin controls then update the database balance. Only a newly confirmed simulated delivery decrements demo-product stock and increases its item delivery count. **Real payments and physical hardware are never connected.** The existing schema is unchanged.

## Run through XAMPP

1. Keep this project at `C:\xampp\htdocs\MediVend`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Use the existing `medivend_db` database. On a fresh machine, import your original `medivend_db.sql` in [phpMyAdmin](http://localhost/phpmyadmin/). There are no schema migrations or new columns in this integration.
4. Check `config/database.php`: host `localhost`, port `3306`, database `medivend_db`, username `root`, and an empty password. XAMPP's `pdo_mysql` extension must be enabled.
5. Open **[http://localhost/MediVend/](http://localhost/MediVend/)**. Tap **Get Started**, choose medicines, open **Review order**, acknowledge the demo, and tap **Save order**.
6. The **Demo payment** screen shows the saved database reference, total due, amount received, remaining balance, and item snapshots. Test with **Demo insert ₱1 / ₱5 / ₱10**.
7. After **Demo payment complete**, tap **Simulate dispensing**, then simulate each confirmed unit or a fault. These controls change demo database records only.

Use Apache rather than opening `index.html` directly or using a static-only server. No Composer packages, npm installation, build step, or internet connection is required.

Local credentials are separate from the PDO helper. Optional `MEDIVEND_DB_HOST`, `MEDIVEND_DB_PORT`, `MEDIVEND_DB_NAME`, `MEDIVEND_DB_USER`, and `MEDIVEND_DB_PASSWORD` environment variables override the defaults. Direct Apache access to the config directory is denied.

## Catalog

`GET /MediVend/api/products.php` returns the existing product fields as JSON. Only `is_active = 1` products are returned, ordered by compartment and ID. `price_centavos` and `stock_quantity` are integers; nullable descriptions/images are supported.

Prices stay in integer centavos: **500 = ₱5.00**, 800 = ₱8.00, and 1000 = ₱10.00. Quantity buttons use database stock. Zero-stock active products remain visible with **Out of stock**. Local `image_path` values such as `assets/my-product.png` are used when present. Null or broken images use a known bundled photo or `assets/images/medicine-placeholder.svg`. Remote images are not requested, and database text is escaped before rendering.

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

The cart clears only after a valid saved-order response. The demo screen uses the server's reference, prices, names, total, balance, and delivery counts. **Start a new session** starts a new intentional order; it does not delete, refund, or cancel the saved order. There is no real payment form or hardware action.

## Demo payment and dispensing

The tab keeps its current-order capability in `sessionStorage`. `X-Demo-Order-Key` binds simulation requests to that exact saved order; a public reference alone is insufficient. Every simulation endpoint rejects non-demo orders. Stock consumption additionally requires demo, active products. Reloading the same tab retrieves its saved state through `GET api/demo-order.php`.

`POST api/demo-payment.php` accepts only `state_token` and `coin_centavos` (100, 500, or 1000). PHP locks the transaction row, reads its database total and balance, and rejects overpayment; this demo provides no change. It stores integer `amount_paid_centavos` and sets payment to `partial` or `paid`. Coins are accepted only for a pending demo order with dispensing `not_started`. Payment alone never changes stock or dispensing.

`POST api/demo-dispense.php` accepts only `state_token`, `action`, and `product_id`. Actions are `start`, `delivered`, `jam`, or `no_confirmation`; `product_id` is an integer only for `delivered`, otherwise null.

- `start` requires fully paid demo status and rechecks every item's remaining quantity against current stock, under locks. It enters `processing` / `in_progress`; insufficient stock flags `needs_review` without consuming stock.
- `delivered` confirms **one unit** of an ordered item. In one database transaction, PHP locks the order/product, guards stock above zero, decrements product stock, and increases `transaction_items.dispensed_quantity` without exceeding quantity. Partial deliveries remain `processing` / `partial`.
- Only when all units are confirmed does PHP set `order_status = completed`, `dispensing_status = dispensed`, and `completed_at`. This confirms a **simulation**, never physical delivery.
- `jam` or `no_confirmation` stops with order and dispensing status `needs_review`, preserves prior delivery counts, and shows **Please contact clinic staff**. No delivery is inferred from missing confirmation, no stock is changed for that unit, no automatic retry occurs, and payment remains recorded; no refund is claimed.

Every response includes a `state_token` tied to persisted balance, status, and confirmed-delivery counters. Under the order-row lock, only the exact current token can mutate state. Each successful event changes that token. Duplicate or concurrent copies return **409 stale_event** and the current saved state instead of counting a second coin/unit. This durable compare-and-swap guard uses the existing columns, without adding an event table or altering the schema. It is not a separate payment-event audit log.

Simulation events are never automatically resent. After an uncertain coin response, **Refresh status** checks the saved amount before another insertion. After an uncertain delivery response, the flow stops and asks for staff; refreshing reads the confirmed counters, and any incomplete uncertain run can only be stopped with **Simulate no sensor confirmation**. It cannot silently repeat the delivery. The UI also shows **Dispensing…** while processing and disables repeated taps.

## Test through XAMPP

With Apache/MySQL running, first check [the catalog JSON](http://localhost/MediVend/api/products.php). From PowerShell in the project folder:

```powershell
& 'C:\xampp\php\php.exe' tests/products-api.test.php
& 'C:\xampp\php\php.exe' tests/checkout-api.test.php
& 'C:\xampp\php\php.exe' tests/demo-flow-api.test.php
```

The catalog test is read-only. Checkout and simulation tests use XAMPP's `curl` extension and temporary products/orders. They cover server prices, snapshots, rollback, concurrent checkout retries, duplicate coin/delivery events, exact payment, overpayment, stock races, jams, missing confirmation, and completion. They remove only their own test rows and preserve original product stock. Set `MEDIVEND_TEST_BASE_URL` if Apache uses another port or project URL.

For a manual browser check:

1. Add two Paracetamol items and one Cetirizine from the supplied sample data. Review shows **₱18.00**.
2. Acknowledge the demo and tap **Save order**, including a quick repeated tap. One saved reference and **₱18.00** should appear on **Demo payment**.
3. In phpMyAdmin, inspect `transactions` and `transaction_items`. Confirm one order for that reference, matching item snapshots, `pending` / `not_started`, zero paid/dispensed amounts, and unchanged product stock.
4. To test a connection failure, stop MySQL **after opening checkout review**, then save. The cart remains. Restart MySQL and tap **Retry save**; the original request key is reused.
5. Insert a **demo ₱10**, a **demo ₱5**, then three **demo ₱1** coins. The balance becomes zero and **Demo payment complete** appears. Oversized coins disable and are also rejected by PHP. Confirm `amount_paid_centavos = 1800`, payment `paid`, and dispensing still `not_started`.
6. Tap **Simulate dispensing**. Confirm one delivered Paracetamol: its stock decreases by one and its `dispensed_quantity` becomes one. Simulate a jam: staff review appears, prior counts remain, and the order is not completed or refunded.
7. In a fresh test order, pay and confirm every unit to see **Demo delivery complete**. In another order choose **Simulate no sensor confirmation** to verify `needs_review` with no newly confirmed stock movement.
8. Reload a current order to check its saved balance and delivery status. Start a new session to deliberately create a separate order. Manual delivery tests consume **demo database stock**; use suitable demo test inventory.

Useful read-only inspection queries in phpMyAdmin:

```sql
SELECT id, reference, total_centavos, amount_paid_centavos,
       payment_status, dispensing_status, order_status, is_demo
FROM transactions ORDER BY id DESC;

SELECT transaction_id, product_id, product_name, compartment_number,
       quantity, unit_price_centavos, dispensed_quantity
FROM transaction_items ORDER BY transaction_id DESC, product_id;
```

## Files

- `config/database.php`: separate local credentials and optional environment overrides.
- `includes/database.php`: PDO connection with native prepared statements.
- `includes/checkout.php`: validation, atomic order creation, snapshots, and duplicate-request replay.
- `api/products.php`: read-only active-product JSON endpoint.
- `api/checkout.php`: POST-only pending demo-order endpoint.
- `includes/demo-order.php`: locked demo state transitions, event-token guards, and confirmed-unit stock updates.
- `api/demo-order.php`, `api/demo-payment.php`, `api/demo-dispense.php`: current demo-order status and simulation endpoints.
- `assets/app.js`: catalog/cart, checkout, safe request persistence, and shared rendering.
- `assets/demo.js`: demo coins, per-unit delivery/fault controls, current-order restoration, and uncertain-result handling.
- `assets/style.css`, `assets/welcome.css`, `assets/fonts.css`: shared interface and animations.
- `tests/products-api.test.php`, `tests/checkout-api.test.php`, `tests/demo-flow-api.test.php`: XAMPP regression checks.

A saved order is not proof of real payment or delivery. Confirmed simulated delivery changes **demo database stock** only. No real coins, money, motors, sensors, or dispensing hardware are connected.
