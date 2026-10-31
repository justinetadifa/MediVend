# Orange Pi One deployment preparation

## Current result

The project has two **server-controlled** modes. Local XAMPP defaults to `simulation`; the supplied deployment environment selects `hardware`. The welcome design, local logos, medicine images, Poppins/Inter fonts and continuous animation are retained. Hardware mode uses neutral customer wording and backend-only status, with **“Exact payment only. No change.”**

**The real machine is not commissioned.** No GPIO pin, pulse mapping, serial protocol, motor timing or sensor wiring is supplied. With the provided configuration, hardware mode displays **Machine unavailable**, disables Get Started, rejects checkout with 503, and disables all demo endpoints. A boolean or URL parameter cannot pretend that hardware is connected. The worker publishes ready health only after a configured adapter verifies the required capabilities; its heartbeat must stay fresh.

## What was inspected and separated

| Existing behavior | Deployment boundary |
| --- | --- |
| JavaScript demo coin buttons | Remain simulation-only; hardware customer screens have no coin-insertion controls. |
| Tester presses delivery/jam/no-confirmation | Remain simulation-only. Hardware receives verified adapter observations in a CLI worker, never customer JSON. |
| Database product prices, stock and PDO checkout | Shared backend; real checkout requires commissioned `is_demo = 0` inventory at 1000 centavos and acquires the machine's single active-order lease. |
| Persistent coin ledger | Shared integer credit recorder. Accepted physical observations are recorded in full; excess/out-of-stage credit flags review and cannot be reset. |
| Simulated per-unit stock reduction | Separate hardware command/receipt ledger confirms a unit only after a correlated sensor event, in the same transaction as stock/count updates. |
| Browser order recovery | Simulation retains its existing recovery. Hardware polls `api/order.php`, retains its capability in a persistent profile, and protects a previous credited session even after a server mode change. The database lease blocks another customer if browser storage is lost. |

See [the adapter contract](hardware-adapter-contract.md) for the internal domain interfaces and failure rules. They are integration points, **not an implemented device protocol**.

## 1. Board operating system and display

Use an image specifically built for **Orange Pi One**, not One Plus or another Orange Pi model. Verify the exact board revision and power/storage requirements before imaging. Follow the [Armbian image selection/first-boot guide](https://docs.armbian.com/getting-started/) and [image verification instructions](https://docs.armbian.com/getting-started/writing-the-image/). The [board-specific page](https://armbian.com/boards/orangepione) supplies the currently offered images; choose a Debian-based image that supports the exact board and HDMI panel. Do not apply Raspberry Pi boot/GPIO instructions to this board.

The steps below target a Debian-based Armbian installation with systemd, Apache 2.4, PHP 8.x and MariaDB. Use a lightweight supported graphical session for the kiosk; these instructions were **not executed on an Orange Pi**. Check `uname -m`, `/etc/os-release`, `php -v` and `php -r 'echo PHP_INT_SIZE * 8;'` on the actual unit. Monetary arithmetic rejects totals beyond the platform integer range; the preflight refuses imported balances outside it. A 32-bit PHP/ARM installation still needs on-device testing.

Connect HDMI and configure **1024 × 600**, landscape, 100% display scaling and 100% browser zoom using the installed desktop's display settings. Touch transport/model is still unknown: verify it rather than guessing USB/I²C drivers. On X11, `xrandr --query` lists detected display modes; do not invent connector names, pixel clocks or force unsupported timings. Verify touch coordinates at all four corners and the center. Start with motors/coin acceptor disconnected.

## 2. Install the server packages

```sh
sudo apt update
sudo apt install apache2 libapache2-mod-php php php-cli php-mysql php-curl php-opcache mariadb-server mariadb-client git
sudo systemctl enable --now apache2 mariadb
php -m
```

Confirm `PDO`, `pdo_mysql` and `curl` are listed. Debian provides [Apache](https://packages.debian.org/bookworm/apache2) and the [PHP MySQL module](https://packages.debian.org/bookworm/php-mysql) through its repositories; package versions follow the selected OS release. No Composer/npm build is needed to run MediVend. Fonts and pictures are local.

Install Chromium through the selected distribution's repository in its graphical environment. Debian lists an [armhf Chromium package](https://packages.debian.org/trixie/armhf/chromium); verify availability and actual rendering performance on your image. Do not install an x86 Chrome binary on the board.

## 3. Copy code and create private configuration

```sh
sudo git clone https://github.com/justinetadifa/MediVend.git /srv/medivend
sudo adduser --system --group --home /var/lib/medivend medivend
sudo usermod -a -G medivend www-data
sudo install -d -m 0750 -o root -g medivend /etc/medivend
sudo install -m 0640 -o root -g medivend /srv/medivend/deploy/medivend.env.example /etc/medivend/medivend.env
sudoedit /etc/medivend/medivend.env
```

Set a locally generated database password, keep `MEDIVEND_MODE=hardware`, leave the adapter empty and delivery timeout zero. There are no real secrets in the example. `/srv/medivend` stays administrator-owned/read-only to Apache and the worker. Do not copy secrets into the web directory or grant Apache GPIO/serial device permissions.

`config/environment.php` reads `/etc/medivend/medivend.env` for both web and CLI; `MEDIVEND_ENV_FILE` can select another private file. Explicit process environment takes precedence. Do not leave a stale `MEDIVEND_MODE=simulation` in Apache/service configuration. A malformed/missing explicitly selected environment fails closed.

## 4. Create or transfer the database

First make an administrator-only backup/export of the XAMPP `medivend_db`, including products, orders, items and coin events. Copy it privately to the board, outside `/srv/medivend`. [MariaDB's dump documentation](https://mariadb.com/docs/server/clients-and-utilities/backup-restore-and-import-clients/mariadb-dump) covers database transfer/restore. Keep original exports until restored row counts, references, credit and item snapshots have been checked.

Run `sudo mariadb` and adapt the following **placeholder** statements with a private password:

```sql
CREATE DATABASE IF NOT EXISTS medivend_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'medivend_app'@'localhost' IDENTIFIED BY 'REPLACE_WITH_A_LOCAL_SECRET';
GRANT SELECT, INSERT, UPDATE ON medivend_db.* TO 'medivend_app'@'localhost';
```

For a transferred database, import the existing backup first:

```sh
sudo mariadb medivend_db < /private/path/medivend-backup.sql
```

For an empty installation, the migration runner now creates the three inspected base tables from `database/schema.sql`. It inserts **no guessed stock, orders, or compartment assignments**. Import your verified catalog/inventory afterwards. Existing databases keep their base tables.

Apply migrations as the database administrator, using root's local socket authentication where your OS enables it (never use these root settings in Apache):

```sh
cd /srv/medivend
sudo env MEDIVEND_ENV_FILE=/etc/medivend/medivend.env MEDIVEND_DB_USER=root MEDIVEND_DB_PASSWORD= php scripts/migrate.php
sudo -u medivend php scripts/check-deployment.php
```

If your database administration uses another authentication method, run with that deployment account's private credentials instead. The runtime account intentionally lacks DDL/DELETE privileges. The runner applies the base schema, event ledger, current-price update, ₱20/source migration, and `004_hardware_boundaries.sql`. It is re-runnable and preserves saved totals, prices and credits. Migration 004 adds the active-order lease, motor commands and sensor receipts. Current medicine prices are 1000 centavos; new checkout reads the database, never JavaScript prices.

Existing imported products are demo inventory. Do **not** bulk flip `is_demo` or copy demo counts into physical stock. For each physical compartment, staff must verify SKU, medicine, what one dispensing unit means, actual counted stock and the adapter's compartment mapping before commissioning that individual row as `is_demo = 0`. Hardware checkout rejects demo inventory and prices other than 1000. Active real products with incorrect prices make hardware unavailable.

## 5. Configure Apache

```sh
sudo install -m 0644 /srv/medivend/deploy/apache-medivend.conf /etc/apache2/sites-available/medivend.conf
sudo a2enmod env
sudo a2ensite medivend
sudo a2dissite 000-default
sudo apache2ctl configtest
sudo systemctl restart apache2
curl http://localhost/api/machine.php
```

The template uses `/srv/medivend` as DocumentRoot and protects configuration, includes, database files, tests, scripts, deployment files, docs and Git metadata from HTTP. These are Apache 2.4 [directory/access rules](https://httpd.apache.org/docs/current/en/howto/access.html). Keep the kiosk service on a trusted local network; do not expose it publicly without a separate deployment review.

Expected now: JSON mode `hardware`, `available: false`, and the customer screen **Machine unavailable**. `/api/checkout.php` must return 503; `/api/demo-payment.php` and `/api/demo-dispense.php` must return 403. A GET for `/config/database.php`, `/database/schema.sql` or `/.git/config` must be denied. Confirm those rules on the actual Apache installation.

## 6. Kiosk session

Use a separate normal desktop account named `medivend-kiosk` (create with `sudo adduser medivend-kiosk`), with no sudo or hardware-device privileges. Install Chromium and log into its graphical session. For a desktop that supports XDG autostart, copy `deploy/medivend-kiosk.desktop` into that user's `~/.config/autostart/`, adjusting only the browser executable if your distro uses another name. Test launching it manually first.

Keep the persistent browser profile at `/home/medivend-kiosk/.config/medivend-browser`. Do not use incognito, clear site data at startup, or disable the browser sandbox. Restoring the profile helps recover an order; the database lease independently prevents another customer from overwriting unresolved credit. Configure screen blanking, automatic graphical login and touch calibration through the actual desktop's supported settings. Do not assume X11/Wayland configuration is interchangeable.

At 1024 × 600, confirm every button with a finger, text readability, image motion and stable memory/CPU use during a long session. The CSS animation is preserved, but target-board frame rate, thermal behavior and power-loss recovery have not been measured.

## 7. Worker and commissioning

The service template is supplied for later commissioning:

```sh
sudo install -m 0644 /srv/medivend/deploy/medivend-worker.service /etc/systemd/system/medivend-worker.service
sudo systemctl daemon-reload
sudo -u medivend php /srv/medivend/scripts/hardware-worker.php --once
```

With the current config, `--once` exits **2** with **Machine unavailable**. This is expected. Do not enable a pretend-ready adapter to bypass it. No live hardware worker is enabled by these preparation steps. Once the details below are supplied, implement/verify the private adapter, configure its actual timeout and device permissions, and bench-test it before `sudo systemctl enable --now medivend-worker`. The service has separate identity, runtime/state directories and conservative restart handling. A sent-but-unconfirmed command after restart goes to review; it is never resent automatically. Inspect `journalctl -u medivend-worker` and Apache's MediVend error log during commissioning.

## Exact hardware details still needed

| Area | Required information before implementation/commissioning |
| --- | --- |
| Board/software | Exact Orange Pi One PCB revision; OS image/version, kernel, architecture; available RAM/storage; power supply rating; browser/desktop and thermal/power-loss observations. |
| Touchscreen | Manufacturer/model, controller, HDMI EDID/native timing/refresh, orientation, touch transport, USB VID/PID if applicable, driver, calibration and power requirements. |
| Coin acceptor | Manufacturer/model/firmware and manual; supported coins; electrical output type and voltage levels; documented pulse/serial/USB protocol, denomination mapping, framing/checksum/baud/timing if relevant; whether accepted value is authoritative; rejection/inhibit/return capabilities, polarity, latency and actual test results. |
| Event recovery | Stable device identity and event IDs across reconnect/reboot; durable event journal; acknowledgement/replay rules; binding an accepted coin to the active saved reference, including late/unassigned coins and full overpayment. |
| Motor/controller | Motor/driver/controller models, supplies/current/logic isolation; documented command/acknowledgement interface; persistent deduplication of command IDs across controller reset; per-unit command semantics; safe stop/off, interlocks and emergency-stop behavior. |
| Sensor | Model, placement per compartment/output, voltage/type, exact definition of one confirmed unit, correlation to motor command, debounce, jam detection, valid delivery window, calibrated timeout and late/no-confirmation behavior. |
| Inventory | Verified physical compartment-to-SKU map, one-unit meaning (tablet/blister/etc.), counted stock, loading procedure and staff reconciliation process. |
| Wiring | Manufacturer-approved wiring diagram, signal levels, isolation/power/ground plan and qualified verification. No GPIO pin numbers have been invented. |

An acceptor that only emits unidentifiable pulses cannot meet persistent event replay guarantees without an additional verified journal/controller design. Likewise, device-side command deduplication must be established before readiness can be reported. A screen button never inhibits physical coins. All already-accepted value must remain recorded, including excess; unresolved credit requires staff, with no automatic refund claim.

## Implemented, tested, blocked

Implemented: server mode/readiness gates; neutral hardware customer UI and backend polling; private configuration; single active hardware session; full accepted-credit ledger; persistent command intents/claims and sensor receipts; atomic per-unit stock/count changes; stop-for-review logic; migrations, preflight and deployment templates.

Local Windows XAMPP/PHP 8.0/MariaDB validation: all existing four API suites; the 36-check simulation touch-browser audit; hardware boundary tests with an isolated test double (including concurrent claims/receipts, duplicate coins, sensor-only success, jams/timeouts/uncertainty/restart and overpayment); isolated hardware HTTP/browser rejection and credit-recovery tests at 1024 × 600; fresh database/repeated migrations. Fixtures are removed and existing database rows are retained.

Blocked/unverified: actual board installation, Linux Apache/systemd templates on target, 32-bit ARM runtime, real touch calibration/rendering performance, electrical wiring, acceptor protocol/authentication/rejection, controller command deduplication, sensor semantics/timeouts and physical stop behavior. No real adapter or hardware connection is implemented. A staff administration/reconciliation/refund workflow remains outside this customer interface; reviewed orders deliberately stay blocked. These limits prevent commissioning, not further preparation of the software.
