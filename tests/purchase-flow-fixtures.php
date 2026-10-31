<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/database.php';

$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$database = medivendDatabase();
$operation = $input['operation'] ?? 'snapshot';
if ($operation === 'create') {
    $used = array_fill_keys($database->query('SELECT compartment_number FROM products')->fetchAll(PDO::FETCH_COLUMN), true);
    $slots = [];
    for ($slot = 1; $slot <= 65535 && count($slots) < 3; $slot++) if (!isset($used[$slot])) $slots[] = $slot;
    if (count($slots) < 3) throw new RuntimeException('Three unused fixture compartments are needed.');
    $tag = 'TEST-AUDIT-' . bin2hex(random_bytes(6));
    $insert = $database->prepare('INSERT INTO products (sku, name, description, image_path, price_centavos, stock_quantity, compartment_number, is_demo)
        VALUES (?, ?, ?, ?, 1000, 12, ?, 1)');
    $fixtures = [['Cetirizine', 'assets/cetirizine.png'], ['Loperamide', 'assets/diatabs.png'], ['Paracetamol', 'assets/biogesic.png']];
    $database->beginTransaction();
    foreach ($fixtures as $index => $fixture) $insert->execute([$tag . '-' . $index, $fixture[0], 'Purchase-flow audit fixture', $fixture[1], $slots[$index]]);
    $database->commit();
    $query = $database->prepare('SELECT id, name FROM products WHERE sku LIKE ? ORDER BY id'); $query->execute([$tag . '-%']);
    echo json_encode(['tag' => $tag, 'products' => $query->fetchAll()], JSON_THROW_ON_ERROR); exit;
}
if ($operation === 'cleanup') {
    $tag = $input['tag'] ?? '';
    if (!preg_match('/\ATEST-AUDIT-[a-f0-9]{12}\z/', $tag)) throw new RuntimeException('Invalid fixture tag.');
    $database->beginTransaction();
    try {
        $verify = $database->prepare('SELECT COUNT(*) FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id
            JOIN products p ON p.id = ti.product_id WHERE t.reference = ? AND p.sku NOT LIKE ?');
        $coins = $database->prepare('DELETE ce FROM demo_coin_events ce JOIN transactions t ON t.id = ce.transaction_id WHERE t.reference = ?');
        $items = $database->prepare('DELETE ti FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id WHERE t.reference = ?');
        $orders = $database->prepare('DELETE FROM transactions WHERE reference = ?');
        foreach ($input['references'] ?? [] as $reference) {
            if (!preg_match('/\AMV-[A-F0-9]{32}\z/', $reference)) throw new RuntimeException('Invalid test reference.');
            $verify->execute([$reference, $tag . '-%']);
            if ((int) $verify->fetchColumn() !== 0) throw new RuntimeException('Cleanup refused: order contains non-fixture products.');
            $coins->execute([$reference]); $items->execute([$reference]); $orders->execute([$reference]);
        }
        $products = $database->prepare('DELETE FROM products WHERE sku LIKE ?'); $products->execute([$tag . '-%']);
        $database->commit();
    } catch (Throwable $error) { if ($database->inTransaction()) $database->rollBack(); throw $error; }
}
$snapshot = [];
foreach (['products', 'transactions', 'transaction_items', 'demo_coin_events'] as $table) {
    $snapshot[$table] = $database->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll();
}
echo json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
