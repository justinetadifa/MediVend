<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['error' => [
        'code' => 'method_not_allowed',
        'message' => 'Use GET to load the medicine catalog.',
    ]]);
    exit;
}

try {
    require_once __DIR__ . '/../includes/database.php';
    $database = medivendDatabase();
    $statement = $database->query(
        'SELECT id, sku, name, description, image_path,
                price_centavos, stock_quantity, is_demo
         FROM products
         WHERE is_active = 1
         ORDER BY compartment_number ASC, id ASC'
    );

    $products = [];
    foreach ($statement as $row) {
        $products[] = [
            'id' => (int) $row['id'],
            'sku' => $row['sku'],
            'name' => $row['name'],
            'description' => $row['description'],
            'image_path' => $row['image_path'],
            'price_centavos' => (int) $row['price_centavos'],
            'stock_quantity' => (int) $row['stock_quantity'],
            'is_demo' => (bool) $row['is_demo'],
        ];
    }

    echo json_encode(['products' => $products], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('[MediVend catalog] ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => [
        'code' => 'catalog_unavailable',
        'message' => 'The medicine catalog is temporarily unavailable. Please try again.',
    ]]);
}
