-- Persistent software intents/receipts, NOT GPIO configuration or a protocol.
CREATE TABLE IF NOT EXISTS hardware_machine_state (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    active_transaction_id BIGINT UNSIGNED NULL,
    health ENUM('unconfigured','ready','unavailable') NOT NULL DEFAULT 'unconfigured',
    config_hash CHAR(64) NULL,
    heartbeat_at DATETIME NULL,
    FOREIGN KEY (active_transaction_id) REFERENCES transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO hardware_machine_state (id) VALUES (1);
ALTER TABLE hardware_machine_state ADD COLUMN IF NOT EXISTS config_hash CHAR(64) NULL;

CREATE TABLE IF NOT EXISTS hardware_commands (
    id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    transaction_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    unit_number SMALLINT UNSIGNED NOT NULL,
    compartment_number SMALLINT UNSIGNED NOT NULL,
    status ENUM('queued','sent','confirmed','needs_review') NOT NULL DEFAULT 'queued',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deadline_at DATETIME NULL,
    UNIQUE KEY uq_hardware_unit (transaction_id, product_id, unit_number),
    FOREIGN KEY (transaction_id) REFERENCES transactions(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hardware_delivery_events (
    event_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    command_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outcome ENUM('sensor_confirmed','jam','timeout','uncertain') NOT NULL,
    result ENUM('confirmed','duplicate_command','needs_review') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (command_id) REFERENCES hardware_commands(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
