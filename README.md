# MediVend

A touchscreen clinic catalog powered by PHP, PDO, and the local MySQL database `medivend_db`. The existing MediVend design, local product photos, Poppins/Inter fonts, and continuous welcome animation are preserved. Designed for a **1024 × 600** landscape touchscreen, with responsive layouts.

The catalog comes from the existing `products` table. Payment, checkout completion, and dispensing remain simulated: no order is stored, no payment is recorded, and stock is not decremented or reserved.

## Run through XAMPP

1. Keep the project at `C:\xampp\htdocs\MediVend`.
2. Open the XAMPP Control Panel and start **Apache** and **MySQL**.
3. Use the existing `medivend_db` database. On a fresh machine, import your original `medivend_db.sql` through [phpMyAdmin](http://localhost/phpmyadmin/) once. This integration contains no schema migrations and does not change the supplied SQL file or database schema.
4. Check `config/database.php`: host `localhost`, port `3306`, database `medivend_db`, username `root`, password `''` (empty). PHP needs `pdo_mysql`, enabled in the normal XAMPP installation.
5. Open **[http://localhost/MediVend/](http://localhost/MediVend/)** and tap **Get Started**.

Use Apache rather than opening `index.html` directly or using a static-only live server; PHP must execute for the catalog to load. No Composer packages, npm installation, build step, or external internet connection is required.

The configuration is separate from the PDO connection helper. Optional `MEDIVEND_DB_HOST`, `MEDIVEND_DB_PORT`, `MEDIVEND_DB_NAME`, `MEDIVEND_DB_USER`, and `MEDIVEND_DB_PASSWORD` environment variables override the local defaults. The config directory denies direct Apache access.

## Catalog behavior

`GET /MediVend/api/products.php` returns JSON containing `products`. It selects only `is_active = 1`, ordered by `compartment_number` and `id`, and returns the existing schema fields:

- `id`, `sku`, `name`, `description`, and nullable `image_path`.
- `price_centavos` and `stock_quantity` as JSON integers.
- `is_demo` as a JSON boolean.

Prices stay in integer centavos until display: **500 = ₱5.00**, 800 = ₱8.00, and 1000 = ₱10.00. There is no fixed ten-peso price or ten-item cap in JavaScript. Quantity limits use each row's `stock_quantity`; active products with zero stock remain visible with a disabled **Out of stock** button.

The supplied SQL's current sample rows are Cetirizine (800 centavos), Loperamide (1000), and Paracetamol (500). Names, descriptions, prices, and quantities shown by the app follow the database rather than these examples.

For `image_path`, use a local browser path such as `assets/my-product.png`, relative to the project URL. A null or empty path uses the bundled photo for a recognized sample SKU/name, otherwise `assets/images/medicine-placeholder.svg`. Broken paths also fall back locally. Remote image URLs are not requested. Database text is rendered as text, not executable HTML.

Catalog loading, an empty active-product list, and a connection error each have a clear state. **Try again** / **Refresh catalog** fetches the endpoint again; requests time out after eight seconds. The welcome artwork continues moving while the request is pending or unavailable. Starting a new session reloads the catalog; reloading the page also refreshes prices and availability.

## Test through XAMPP

With Apache and MySQL running, open [the products endpoint](http://localhost/MediVend/api/products.php). Expect HTTP 200 and JSON with the active products. Then:

1. Open MediVend and compare product prices and stock with `medivend_db.products` in phpMyAdmin.
2. With the supplied data, add two Paracetamol items: the total should be **₱10.00**. Add one Cetirizine: **₱18.00**.
3. Increase an item to its database stock limit. Its add/increase buttons should disable. Decrease it, remove it, and verify the total updates.
4. Open **Review order**, return to the catalog, and confirm the cart is preserved. Acknowledge the demo and complete it. **Start over** clears the cart.
5. To exercise a connection error manually, stop MySQL, reload the page, and tap **Get Started**. Restart MySQL and tap **Try again**. An empty catalog state appears when the endpoint returns no active products.

A dependency-free, read-only API regression check is included. From the project directory in PowerShell:

```powershell
& 'C:\xampp\php\php.exe' tests/products-api.test.php
```

It checks the JSON response against the existing database, active-product filtering, unchanged integer prices/stock, GET-only access, and a handled connection failure in an isolated PHP child process. It does not alter schema or data. Set `MEDIVEND_TEST_BASE_URL` if Apache uses another port or project URL.

## Files

- `config/database.php`: separate local database credentials and optional environment overrides.
- `includes/database.php`: PDO connection with UTF-8, exceptions, and native prepares.
- `api/products.php`: read-only active-product endpoint; connection failures return HTTP 503 JSON without database diagnostics.
- `assets/app.js`: asynchronous catalog loading, safe rendering, image fallbacks, cart, and simulated checkout.
- `assets/style.css`, `assets/welcome.css`, `assets/fonts.css`: existing branding, touchscreen layout, state panels, and animations.
- `tests/products-api.test.php`: read-only API regression check.

This remains a clinic demo. Completing a demo order does not prove payment, reserve inventory, or activate dispensing hardware.
