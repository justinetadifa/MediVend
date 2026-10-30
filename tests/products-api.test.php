<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/database.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function requestCatalog(string $url, string $method = 'GET'): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => "Accept: application/json\r\n",
        'ignore_errors' => true,
        'timeout' => 8,
    ]]);
    $body = file_get_contents($url, false, $context);
    check($body !== false, 'Could not reach the API. Start Apache and MySQL in XAMPP.');
    $headers = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $headers[0] ?? '', $status);
    return [(int) ($status[1] ?? 0), $headers, json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
}

try {
    $baseUrl = rtrim(getenv('MEDIVEND_TEST_BASE_URL') ?: 'http://localhost/MediVend', '/');
    [$status, $headers, $payload] = requestCatalog($baseUrl . '/api/products.php');
    check($status === 200, 'The API must return HTTP 200 when the database is available.');
    check(is_array($payload['products'] ?? null), 'The response must contain a products array.');
    check((bool) preg_grep('/^Content-Type:\s*application\/json/i', $headers), 'The API must return JSON.');
    check((bool) preg_grep('/^Cache-Control:.*no-store/i', $headers), 'Catalog responses must not be cached.');

    // Independent read-only comparison: inactive rows are removed in PHP here,
    // rather than repeating the endpoint's WHERE clause as the test oracle.
    $databaseRows = medivendDatabase()->query('SELECT * FROM products')->fetchAll();
    $active = [];
    foreach ($databaseRows as $row) {
        if ((int) $row['is_active'] === 1) {
            $active[(int) $row['id']] = $row;
        }
    }
    $actualIds = [];
    foreach ($payload['products'] as $product) {
        check(is_int($product['id'] ?? null), 'Product IDs must be integers.');
        $id = $product['id'];
        check(isset($active[$id]), 'The API returned a missing or inactive product.');
        check(!in_array($id, $actualIds, true), 'The API returned a duplicate product.');
        $actualIds[] = $id;
        check(is_int($product['price_centavos'] ?? null), 'Prices must be integer centavos.');
        check($product['price_centavos'] === (int) $active[$id]['price_centavos'], 'A database price was changed.');
        check(is_int($product['stock_quantity'] ?? null), 'Stock quantities must be integers.');
        check($product['stock_quantity'] === (int) $active[$id]['stock_quantity'], 'A database stock quantity was changed.');
        check($product['name'] === $active[$id]['name'], 'A database product name was changed.');
        check($product['image_path'] === $active[$id]['image_path'], 'Nullable image_path must be preserved.');
        check(is_bool($product['is_demo'] ?? null), 'is_demo must be a JSON boolean.');
    }
    $expectedIds = array_keys($active);
    sort($expectedIds);
    sort($actualIds);
    check($actualIds === $expectedIds, 'The API must return every active product exactly once.');

    [$methodStatus, $methodHeaders, $methodPayload] = requestCatalog($baseUrl . '/api/products.php', 'POST');
    check($methodStatus === 405, 'POST must be rejected without creating an order.');
    check((bool) preg_grep('/^Allow:\s*GET/i', $methodHeaders), 'The method response must advertise GET.');
    check(($methodPayload['error']['code'] ?? null) === 'method_not_allowed', 'A method error must return JSON.');

    // An isolated PHP child uses a deliberately missing database. It does not
    // change Apache's configuration, stop MySQL, or create/alter any database.
    $endpoint = var_export(realpath(__DIR__ . '/../api/products.php'), true);
    $code = '$_SERVER["REQUEST_METHOD"]="GET"; ob_start(); require ' . $endpoint . '; '
        . '$body=ob_get_clean(); echo json_encode(["status"=>http_response_code(),"body"=>$body]);';
    $environment = array_merge(getenv(), ['MEDIVEND_DB_NAME' => '__medivend_missing_' . bin2hex(random_bytes(6))]);
    $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    check(is_resource($process), 'Could not launch the isolated connection-error test.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'The connection error must be handled without a PHP crash.');
    $unavailable = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    $errorPayload = json_decode($unavailable['body'], true, 512, JSON_THROW_ON_ERROR);
    check($unavailable['status'] === 503, 'An unavailable database must return HTTP 503.');
    check(($errorPayload['error']['code'] ?? null) === 'catalog_unavailable', 'Connection failures must return a clear JSON error.');
    check(strpos($unavailable['body'], 'SQLSTATE') === false, 'Database diagnostics must not be exposed in the API response.');

    echo 'PASS: active products, database prices/stock, JSON contract, GET-only access, and handled connection errors.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
