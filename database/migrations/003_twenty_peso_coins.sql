-- Re-runnable on local XAMPP MariaDB. Existing credits/snapshots are retained.
-- The historical ledger name is kept. event_source separates attempted demo
-- coins (which may be rejected) from money already accepted by an acceptor.
ALTER TABLE demo_coin_events
    ADD COLUMN IF NOT EXISTS event_source ENUM('demo', 'accepted_coin') NOT NULL DEFAULT 'demo',
    DROP CONSTRAINT chk_demo_coin_denomination,
    ADD CONSTRAINT chk_demo_coin_denomination CHECK (coin_centavos IN (100, 500, 1000, 2000));
