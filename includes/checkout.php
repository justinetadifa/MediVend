<?php
declare(strict_types=1);

final class MedivendCheckoutError extends RuntimeException
{
    public int $status;
    public string $errorCode;

    public function __construct(int $status, string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $errorCode;
    }
}

function medivendCheckoutItems($payload): array
{
    if (!$payload instanceof stdClass || array_keys(get_object_vars($payload)) !== ['items']
        || !is_array($payload->items) || count($payload->items) < 1 || count($payload->items) > 100) {
        throw new MedivendCheckoutError(422, 'invalid_items', 'Send a non-empty list of product IDs and quantities.');
    }

    $items = [];
    $seen = [];
    foreach ($payload->items as $item) {
        if (!$item instanceof stdClass || count(get_object_vars($item)) !== 2
            || !isset($item->product_id, $item->quantity)
            || !is_int($item->product_id) || $item->product_id < 1 || $item->product_id > 4294967295
            || !is_int($item->quantity) || $item->quantity < 1 || $item->quantity > 65535
            || isset($seen[$item->product_id])) {
            throw new MedivendCheckoutError(422, 'invalid_items', 'Each product must appear once, with a positive integer quantity of at most 65,535.');
        }
        $seen[$item->product_id] = true;
        $items[] = ['product_id' => $item->product_id, 'quantity' => $item->quantity];
    }
    usort($items, static fn(array $left, array $right): int => $left['product_id'] <=> $right['product_id']);
    return $items;
}

function medivendCheckoutReference(string $key): string
{
    // The same random request key always reaches the same unique database
    // reference, including after a PHP/browser restart. No schema change needed.
    return 'MV-' . strtoupper(substr(hash('sha256', 'medivend-checkout-v1:' . $key), 0, 32));
}

function medivendReadOrder(PDO $database, string $reference): ?array
{
    $statement = $database->prepare(
        'SELECT id, reference, total_centavos, amount_paid_centavos,
                payment_status, dispensing_status, order_status, is_demo, completed_at
         FROM transactions WHERE reference = :reference'
    );
    $statement->execute(['reference' => $reference]);
    $row = $statement->fetch();
    if (!$row) {
        return null;
    }

    $itemsStatement = $database->prepare(
        'SELECT product_id, product_name, quantity, unit_price_centavos, dispensed_quantity
         FROM transaction_items WHERE transaction_id = :transaction_id ORDER BY product_id ASC'
    );
    $itemsStatement->execute(['transaction_id' => $row['id']]);
    $items = [];
    foreach ($itemsStatement as $item) {
        $items[] = [
            'product_id' => (int) $item['product_id'],
            'product_name' => $item['product_name'],
            'quantity' => (int) $item['quantity'],
            'unit_price_centavos' => (int) $item['unit_price_centavos'],
            'dispensed_quantity' => (int) $item['dispensed_quantity'],
        ];
    }

    return [
        'id' => (string) $row['id'],
        'reference' => $row['reference'],
        'total_centavos' => (int) $row['total_centavos'],
        'amount_paid_centavos' => (int) $row['amount_paid_centavos'],
        'payment_status' => $row['payment_status'],
        'dispensing_status' => $row['dispensing_status'],
        'order_status' => $row['order_status'],
        'is_demo' => (bool) $row['is_demo'],
        'completed_at' => $row['completed_at'],
        'items' => $items,
    ];
}

function medivendSaveOrder(PDO $database, array $items, string $key): array
{
    $reference = medivendCheckoutReference($key);
    $database->beginTransaction();
    try {
        // Claim the unique reference first. Concurrent retries wait on this
        // insert until the first request commits or rolls back its entire order.
        $insertOrder = $database->prepare(
            "INSERT INTO transactions
             (reference, total_centavos, amount_paid_centavos, payment_status,
              dispensing_status, order_status, is_demo)
             VALUES (:reference, 0, 0, 'pending', 'not_started', 'pending', 1)"
        );
        try {
            $insertOrder->execute(['reference' => $reference]);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1062) {
                throw $error;
            }
            $database->rollBack();
            $existing = medivendReadOrder($database, $reference);
            if (!$existing) {
                throw new RuntimeException('Duplicate reference could not be recovered.');
            }
            $savedItems = array_map(static fn(array $item): array => [
                'product_id' => $item['product_id'], 'quantity' => $item['quantity'],
            ], $existing['items']);
            if ($savedItems !== $items) {
                throw new MedivendCheckoutError(409, 'idempotency_conflict', 'This checkout request was already used for a different cart. Review your items before trying again.');
            }
            return ['order' => $existing, 'replayed' => true];
        }

        $orderId = $database->lastInsertId();
        $selectProduct = $database->prepare(
            'SELECT id, name, price_centavos, stock_quantity, compartment_number, is_active
             FROM products WHERE id = :product_id FOR UPDATE'
        );
        $insertItem = $database->prepare(
            'INSERT INTO transaction_items
             (transaction_id, product_id, product_name, compartment_number,
              quantity, unit_price_centavos, dispensed_quantity)
             VALUES (:transaction_id, :product_id, :product_name, :compartment_number,
                     :quantity, :unit_price_centavos, 0)'
        );
        $total = 0;
        foreach ($items as $item) {
            // Canonical ID ordering gives concurrent carts a consistent lock order.
            $selectProduct->bindValue(':product_id', $item['product_id'], PDO::PARAM_INT);
            $selectProduct->execute();
            $product = $selectProduct->fetch();
            $selectProduct->closeCursor();
            if (!$product || (int) $product['is_active'] !== 1) {
                throw new MedivendCheckoutError(409, 'product_unavailable', 'One of your medicines is no longer available. Your cart has been kept for editing.');
            }
            if ($item['quantity'] > (int) $product['stock_quantity']) {
                throw new MedivendCheckoutError(409, 'insufficient_stock', $product['name'] . ' has only ' . (int) $product['stock_quantity'] . ' available. Your cart has been kept for editing.');
            }
            $unitPrice = (int) $product['price_centavos'];
            $lineTotal = $unitPrice * $item['quantity'];
            if ($lineTotal > 4294967295 || $total > 4294967295 - $lineTotal) {
                throw new MedivendCheckoutError(422, 'total_too_large', 'This order exceeds the supported total. Please reduce its quantities.');
            }
            $total += $lineTotal;
            $insertItem->execute([
                'transaction_id' => $orderId,
                'product_id' => $item['product_id'],
                'product_name' => $product['name'],
                'compartment_number' => (int) $product['compartment_number'],
                'quantity' => $item['quantity'],
                'unit_price_centavos' => $unitPrice,
            ]);
        }

        $updateTotal = $database->prepare('UPDATE transactions SET total_centavos = :total WHERE id = :id');
        $updateTotal->bindValue(':total', $total, PDO::PARAM_INT);
        $updateTotal->bindValue(':id', $orderId, PDO::PARAM_STR);
        $updateTotal->execute();
        $saved = medivendReadOrder($database, $reference);
        if (!$saved) {
            throw new RuntimeException('Saved order could not be read.');
        }
        $database->commit();
        return ['order' => $saved, 'replayed' => false];
    } catch (Throwable $error) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $error;
    }
}
