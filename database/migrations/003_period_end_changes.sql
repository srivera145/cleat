-- Cleat 0.1.3: price changes at period end, and the trial-ending notice.
-- Runs on MariaDB 10.11+ and MySQL 8.
--
-- Additive only, like 002: two nullable columns at the end of
-- cleat_subscriptions (an instant, metadata-only change on both servers), so
-- it is safe on a live 0.1.2 database. Safe to run twice: each ALTER runs only
-- when information_schema says the column is missing.
--
--   cleat_subscriptions.scheduled_price_id
--       The price the subscription moves to when its current period (or
--       trial) ends: set by swapAtPeriodEnd(), applied by the Runner as it
--       rolls the period, cleared by an immediate swap(), cancel() or
--       cancelNow(). No foreign key: adding one would rebuild the table, and
--       Cleat only ever writes the id of a price it has just looked up.
--   cleat_subscriptions.trial_ending_notified_at
--       When the trial-ending notice went out, so the Runner sends it once.

SET @cleat_ddl = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE cleat_subscriptions ADD COLUMN scheduled_price_id BIGINT UNSIGNED NULL',
    'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cleat_subscriptions' AND column_name = 'scheduled_price_id');
PREPARE cleat_stmt FROM @cleat_ddl;
EXECUTE cleat_stmt;
DEALLOCATE PREPARE cleat_stmt;

SET @cleat_ddl = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE cleat_subscriptions ADD COLUMN trial_ending_notified_at DATETIME NULL',
    'DO 0')
    FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cleat_subscriptions' AND column_name = 'trial_ending_notified_at');
PREPARE cleat_stmt FROM @cleat_ddl;
EXECUTE cleat_stmt;
DEALLOCATE PREPARE cleat_stmt;
