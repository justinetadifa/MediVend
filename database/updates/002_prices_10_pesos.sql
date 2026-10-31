-- Non-destructive price update for the three existing medicine SKUs only.
-- price_centavos counts dispensing units, not the pictured medicine boxes.
-- Do not update transactions.total_centavos or item price snapshots.
START TRANSACTION;
UPDATE products
SET price_centavos = 1000
WHERE sku IN ('DEMO-CETI', 'DEMO-LOPE', 'DEMO-PARA')
  AND price_centavos <> 1000;
COMMIT;
