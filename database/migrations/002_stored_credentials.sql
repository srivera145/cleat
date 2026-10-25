-- Cleat 0.1.2: stored-credential (card-on-file) indicators.
-- Runs on MariaDB 10.11+ and MySQL 8.
--
-- Additive only: two nullable columns, nothing renamed, rewritten or dropped,
-- so it is safe to run on a live 0.1.1 database. Each column is added at the
-- end of its table, which both servers do as an instant, metadata-only change.
--
-- Safe to run twice. MySQL 8 has no ADD COLUMN IF NOT EXISTS, so each ALTER
-- runs only when information_schema says the column is missing.
--
--   cleat_customers.original_network_transaction_id
--       The card network's transaction id from the first stored-credential
--       charge on the saved card. Sent as originalNetworkTransId on
--       merchant-initiated charges (renewals, retries). Cleared when the card
--       changes. NULL for cards saved before 0.1.2 until their next approval.
--   cleat_charges.network_transaction_id
--       The network transaction id Authorize.net returned for this charge.

SET @cleat_ddl = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE cleat_customers ADD COLUMN original_network_transaction_id VARCHAR(255) NULL',
    'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cleat_customers' AND column_name = 'original_network_transaction_id');
PREPARE cleat_stmt FROM @cleat_ddl;
EXECUTE cleat_stmt;
DEALLOCATE PREPARE cleat_stmt;

SET @cleat_ddl = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE cleat_charges ADD COLUMN network_transaction_id VARCHAR(255) NULL',
    'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cleat_charges' AND column_name = 'network_transaction_id');
PREPARE cleat_stmt FROM @cleat_ddl;
EXECUTE cleat_stmt;
DEALLOCATE PREPARE cleat_stmt;
