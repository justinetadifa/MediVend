# MediVend purchase-flow audit

This records the audit before the ₱20 extension. For current denominations, accepted-money handling and added tests, see [the coin-payment contract](coin-payments.md).

Audited locally on **31 October 2026**, through XAMPP Apache/PHP 8.0.30 and the live `medivend_db` MariaDB database. The existing interface, branding, medicine images, typography and welcome animation were preserved. All payment and delivery operations used demo endpoints; no hardware integration was added or activated.

## Inspection and fixes

Inspected the catalog/cart, PDO checkout, persistent coin ledger, demo-order transitions, browser recovery storage, and live database schema. The original `medivend_db.sql` is not in this checkout, so the imported database tables and constraints were inspected directly. The existing current-price update already targets all three medicine SKUs at 1000 centavos. No price, historical snapshot or schema change was needed during this audit.

The audit found and fixed these issues:

1. **Start over could race with a coin.** Reading a zero balance and then clearing browser state left a gap in which another request could record credit. New `POST api/demo-session.php` closes only an unpaid pending order under the same row lock as payment. Credit wins and prevents closing, or closing wins and later coins are rejected. Saved rows remain; partial/paid/processing/review orders cannot be cancelled by this action. Repeated unpaid closing is safe.
2. **A lost delivery response could leave the database processing.** Recovery now reads saved delivery counts, then flags an incomplete uncertain run `needs_review` using `no_confirmation`. It never resends a delivery confirmation or consumes another unit. Completed orders remain completed. If the status/stop request fails, the UI retains the error rather than claiming a successful stop.
3. **The checkout Back button was only 34 pixels high at 1024 × 600.** It is now at least 44 pixels high, with nearby spacing adjusted so the original layout still fits.
4. **The completed-session action needed a clear handoff label.** It now says **Finish session**. That action clears customer browser state before returning to welcome; saved orders and delivery records remain in the database. It does not introduce a timer that discards unresolved credit.

## Verification results

| Requested behavior | Result and evidence |
| --- | --- |
| ₱10 per unit for new orders | **PASS.** Live `DEMO-CETI`, `DEMO-LOPE` and `DEMO-PARA` all have `price_centavos = 1000`. PHP calculates authoritative totals. Checkout tests confirm old snapshots survive product-price changes. |
| Cart quantities and totals | **PASS.** Add, decrease, remove and stock limits exercised with touch. Twelve units total ₱120; a four-unit, three-product cart totals ₱40 in review and the saved order. |
| Mixed ₱1, ₱5 and ₱10 coins | **PASS.** A ₱40 order accepts ₱10 + ₱5 + five ₱1 + two ₱10 events. Each confirmed response updates inserted amount/balance. Payment remains partial until exact total; prices and balances remain integer centavos. |
| Duplicate checkout | **PASS.** Repeated taps issue one browser save. HTTP retries and concurrent requests with the same key return one saved order. Changed carts with the same key are rejected. |
| Duplicate coin events | **PASS.** Persistent same-ID replay and simultaneous duplicates add credit once. Distinct simultaneous coins serialize against the saved balance. Changed ID payloads conflict. Lost-response retry uses the original event ID. |
| Overpayment and paid-order coins | **PASS.** Overpayment adds no credit and explains smaller simulated denominations. New events after full payment fail. An old accepted event can still replay without adding credit. |
| Refresh recovery | **PASS.** Reload restores the same reference, saved total, inserted payment and recorded units. Lost coin-response recovery and reload after staff review preserve recorded state. |
| Only paid demo orders dispense | **PASS.** Unpaid requests fail; non-demo orders fail. Stock is rechecked at start and at each unit. Simulation cannot consume non-demo product stock. |
| Each delivery decrements once | **PASS.** Each confirmed unit atomically decreases stock and increases `dispensed_quantity`. Concurrent duplicate delivery requests consume one unit. Stale tokens, zero stock and attempts beyond ordered quantity cannot consume stock. |
| Jam or uncertain delivery | **PASS.** Jam after one confirmed unit preserves that unit/payment and stops for staff. Missing confirmation consumes no unit. A lost response recovers its recorded unit, stops an incomplete run for review, and never retries delivery. Failed recovery stop keeps an error visible. |
| Start over protects credit/delivery | **PASS.** Partial, paid and unresolved dispensing sessions cannot close. Touching Start over/home opens staff assistance or stays disabled during processing. Six concurrent unpaid-close/coin races each preserve an accepted coin or reject it after closing; they never both succeed. |
| Next customer starts clean | **PASS.** Finish session clears cart, last order, checkout retry state, current-order session storage and the protected-credit marker. Reload stays at welcome. The next checkout gets a different reference and zero credit. |
| 1024 × 600 touch usability | **PASS in emulation.** Chrome touch taps exercised 22 screen/dialog states. No page overflow, broken images or enabled visible buttons smaller than 44 × 44 pixels were found after the fix. Local fonts load; the continuous medicine track moves. No uncaught JavaScript errors occurred. |

Delivery duplicates use a state-token guard: one request succeeds and stale copies return 409. Coin duplicates replay their persisted result. Both prevent a second financial/stock effect.

## Tests run

All commands below passed against local Apache/MySQL after the fixes:

```powershell
& 'C:\xampp\php\php.exe' tests/products-api.test.php
& 'C:\xampp\php\php.exe' tests/checkout-api.test.php
& 'C:\xampp\php\php.exe' tests/demo-flow-api.test.php
& 'C:\xampp\php\php.exe' tests/coin-payment-api.test.php
node tests/purchase-flow-browser.test.mjs
```

The browser runner used the already available Puppeteer module and installed Chrome, with `MEDIVEND_PUPPETEER_MODULE` pointing to that module. See [README](../README.md#test-through-xampp) for configuration. Its final run produced 31 recorded passed groups/layout checks and zero runtime errors. Screenshots and `results.json` are written to the temporary artifact directory printed by the runner.

PHP syntax checks passed for all 17 PHP files. Node syntax checks passed for both application scripts and the browser test. `git diff --check` passed.

Tests created uniquely tagged temporary products/orders/coin events, exercised their stock, then removed only those fixtures. All original rows in `products`, `transactions`, `transaction_items` and `demo_coin_events` were compared before/after. Existing stock, historical totals, credits and delivery records were unchanged. Auto-increment counters advance normally when test rows are inserted; ID sequences are not reset.

## Remaining manual checks and scope

- **Physical touchscreen:** Confirm touch calibration, readability, reach and finger accuracy on the actual 1024 × 600 display at 100% scaling/zoom and full screen. Automated Chrome touch emulation cannot verify the physical panel or mounting position.
- **Deployment recovery:** Actual machine power loss, Apache/MySQL restart and kiosk-browser storage policies were not exercised by stopping shared services. Reload, dropped responses, HTTP failures and retries were tested. Manually check restart recovery on an isolated demo setup with the intended browser profile/storage policy.
- **Staff resolution:** The app preserves credit and partial deliveries and displays the saved reference, but has no staff administration/refund workflow. A `needs_review` order deliberately remains blocked. Staff must handle it; the app never claims a refund occurred.
- **Customer handoff:** A completed order stays on its confirmation screen until **Finish session** (or Start over) is tapped. Confirm that explicit handoff with testers before the next customer uses the kiosk. No automatic idle reset was added.
- **Real hardware:** Coin acceptance/rejection/return, motors, sensors and physical jams were not tested or connected. Keep them disconnected. The demonstrated stock effects and deliveries are database simulations only.
