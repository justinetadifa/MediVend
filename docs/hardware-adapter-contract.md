# Replaceable hardware adapter boundary

`includes/hardware-adapter.php` defines a **PHP domain interface**, not a wire protocol. `config/machine.php` selects a private administrator-owned adapter file outside DocumentRoot; that file must return a `MedivendHardwareAdapter`. The only built-in implementation is unconfigured and never reports connected or dispatches anything. No browser endpoint accepts coin/sensor observations.

## Adapter responsibilities

| Method | Contract |
| --- | --- |
| `health()` | Fresh verified device observation. Report `connected`, `coin_events_verified`, `sensor_events_verified`, `command_deduplication`, `safe_stop`, `persistent_event_ids` as true only after their physical guarantees have been established. `denominations_centavos` must be exactly `[100,500,1000,2000]`. A hard-coded configuration flag is not a connection check. |
| `verifiedEvents()` | Yield only authenticated/validated observations from the verified device integration. Preserve their stable event IDs and durable journal across boots; never derive payment from a UI animation. |
| `acknowledge(eventId)` | Remove/acknowledge an observation only after the backend commits it. Keep and reconcile unacknowledged, malformed, unassigned or storage-failed accepted-money events; never silently discard them. |
| `dispatch(command)` | Dispatch the stable command `id` for its snapshot `compartment_number`/`unit_number`. The device boundary must persistently deduplicate that ID. No automatic retries of a sent/uncertain command. Actual electrical/protocol mapping is still unspecified. |
| `stop()` | Request the verified stop mechanism. Exceptions mean the physical stop is uncertain; the worker blocks processing and requests staff. Software never infers that a mechanical stop succeeded from a screen update. |

Accepted coin domain observations use `type: accepted_coin`, `reference`, 64-lowercase-hex `event_id`, and integer `coin_centavos`. These are already accepted funds, not attempts: the shared recorder preserves the full value, including overpayment or late credit. Do not route them through `api/demo-payment.php`.

Delivery domain observations use `type: delivery`, unique `event_id`, `command_id`, and `outcome` of `sensor_confirmed`, `jam`, `timeout` or `uncertain`. The adapter must correlate a real sensor-confirmed unit with that exact stable motor command. Sending a motor command or receiving a motor acknowledgement is not sensor confirmation.

These shapes are internal normalized records. They make **no assumption** about pulses, UART frames, USB, GPIO assignments or checksums. Those details must be implemented from the actual equipment documentation and verified on the bench.

## Persistence and failure boundaries

`hardware_machine_state` holds one active hardware order and the worker's health timestamp. New customers cannot replace an unresolved session. A private adapter path, configured calibrated timeout and a fresh ready heartbeat are all required; otherwise purchases fail with Machine unavailable. Database/configuration errors also fail closed.

Any unresolved hardware review also blocks new purchases, including late accepted credit for an already closed session. Verified accepted-money observations continue to be recorded while blocked. Staff reconciliation is deliberately required; no customer API resolves a fault or discards credit. Restart both Apache and the worker after changing private hardware configuration.

The heartbeat is bound to the loaded adapter file digest/path and timeout configuration. An old worker observation cannot enable a changed adapter configuration. Independent device watchdog/fail-safe behavior on OS or power loss still needs verification; a PHP process cannot guarantee a physical stop after it has lost power.

`hardware_commands` has one unique intent per order/product/unit. A claim transitions queued → sent atomically **before** device I/O, so another worker/tap cannot dispatch it again. A crash between claim and I/O sacrifices automatic retry: it is uncertain and requires staff. The device must also deduplicate IDs, since PHP cannot provide physical exactly-once actuation across a device reset by itself.

`hardware_delivery_events` records stable receipt IDs. Order, command and product locks serialize confirmations. A valid timely sensor receipt atomically decrements stock and increments `dispensed_quantity`; duplicate receipts or a second receipt for the same confirmed command cannot do so again. Only all-confirmed orders complete. Every remaining unit is stock-checked before dispatch. Late confirmations after a timeout/review are retained for staff reconciliation and do not automatically claim another delivery or stock adjustment.

On jam, timeout, lost connection, uncertain dispatch, invalid receipt or failed storage, the worker requests stop and flags unfinished credited/processing orders for review. No further automatic command is dispatched. Confirmed quantities and credit remain; no automatic retry, change or refund is claimed. A worker restart with sent commands also requests stop/review. An actual physical safety stop still depends on the verified device integration.

`api/order.php` is read-only capability-based status; `api/session.php` closes only a hardware completed session or a locked zero-credit/not-started session. Credit, processing and review block customer cancellation. All demo endpoints are forbidden in server hardware mode. Hardware events are ingested by the CLI worker, not by customer-supplied JSON.

See [deployment and required equipment details](orange-pi-deployment.md). Until that work is done, leave the adapter unset and the machine unavailable.
