-- Additive, re-runnable migration. Run against the existing medivend_db.
-- Existing orders and pre-migration credits are deliberately not rewritten.
CREATE TABLE IF NOT EXISTS demo_coin_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    transaction_id BIGINT UNSIGNED NOT NULL,
    coin_centavos INT UNSIGNED NOT NULL,
    outcome ENUM('accepted', 'rejected') NOT NULL,
    reason_code VARCHAR(40) DEFAULT NULL,
    message VARCHAR(255) NOT NULL,
    amount_before_centavos INT UNSIGNED NOT NULL,
    amount_after_centavos INT UNSIGNED NOT NULL,
    payment_status_after VARCHAR(24) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_demo_coin_event_id (event_id),
    KEY idx_demo_coin_order (transaction_id, id),
    CONSTRAINT fk_demo_coin_order FOREIGN KEY (transaction_id)
        REFERENCES transactions (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_demo_coin_denomination CHECK (coin_centavos IN (100, 500, 1000)),
    CONSTRAINT chk_demo_coin_amount CHECK (
        (outcome = 'accepted' AND amount_after_centavos = amount_before_centavos + coin_centavos)
        OR (outcome = 'rejected' AND amount_after_centavos = amount_before_centavos)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
