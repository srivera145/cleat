-- Cleat: billing tables.
-- Runs on MariaDB 10.11+ and MySQL 8.
--
-- Conventions:
--   * Every amount is BIGINT minor units (cents). No DECIMAL money, no floats.
--   * Every timestamp is DATETIME written in UTC by Cleat. DATETIME, not
--     TIMESTAMP, so the connection's time_zone can never shift a stored value.
--   * InnoDB, utf8mb4, foreign keys enforced, ON DELETE RESTRICT throughout:
--     billing history is never deleted out from under an invoice.
--
-- Additions beyond the original spec, each marked "extra" below:
--   * cleat_customers.phone             checkout can collect a phone number
--   * cleat_invoices.number NULL        drafts get a number when finalized, so
--                                       abandoned drafts never burn one
--   * cleat_invoice_items.invoice_id NULL + subscription_id
--                                       pending proration credits wait here
--                                       (invoice_id NULL) for the next renewal
--   * cleat_refunds                     partial refunds and refund/void history

CREATE TABLE IF NOT EXISTS cleat_sequences (
    name  VARCHAR(64)     NOT NULL,
    value BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cleat_sequences (name, value) VALUES ('invoice', 0);

CREATE TABLE IF NOT EXISTS cleat_customers (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             BIGINT          NULL,
    email               VARCHAR(255)    NOT NULL,
    name                VARCHAR(255)    NULL,
    phone               VARCHAR(32)     NULL,              -- extra
    gateway_customer_id VARCHAR(64)     NULL,
    gateway_payment_id  VARCHAR(64)     NULL,
    card_brand          VARCHAR(32)     NULL,
    card_last_four      CHAR(4)         NULL,
    card_exp            CHAR(7)         NULL,              -- YYYY-MM
    created_at          DATETIME        NOT NULL,
    updated_at          DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY cleat_customers_user_id_unique (user_id),
    KEY cleat_customers_email_index (email),
    KEY cleat_customers_gateway_customer_index (gateway_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cleat_products (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(255)    NOT NULL,
    description TEXT            NULL,
    is_active   TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  DATETIME        NOT NULL,
    updated_at  DATETIME        NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cleat_prices (
    id               BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    product_id       BIGINT UNSIGNED   NOT NULL,
    lookup_key       VARCHAR(191)      NOT NULL,
    nickname         VARCHAR(255)      NOT NULL DEFAULT '',
    amount           BIGINT            NOT NULL,
    currency         CHAR(3)           NOT NULL DEFAULT 'USD',
    billing_type     ENUM('one_time','recurring') NOT NULL,
    billing_interval ENUM('day','week','month','year') NULL,
    interval_count   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    trial_days       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active        TINYINT(1)        NOT NULL DEFAULT 1,
    created_at       DATETIME          NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY cleat_prices_lookup_key_unique (lookup_key),
    KEY cleat_prices_product_index (product_id),
    CONSTRAINT cleat_prices_product_fk FOREIGN KEY (product_id) REFERENCES cleat_products (id),
    CONSTRAINT cleat_prices_amount_check CHECK (amount >= 0),
    CONSTRAINT cleat_prices_interval_check CHECK (billing_type = 'one_time' OR billing_interval IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Connect stub: created now so nothing has to be migrated when Connect lands.
CREATE TABLE IF NOT EXISTS cleat_connected_accounts (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_type         VARCHAR(191)    NOT NULL,
    owner_id           BIGINT          NOT NULL,
    gateway            VARCHAR(32)     NOT NULL,
    gateway_account_id VARCHAR(191)    NULL,
    status             ENUM('pending','onboarding','active','restricted','disabled') NOT NULL DEFAULT 'pending',
    charges_enabled    TINYINT(1)      NOT NULL DEFAULT 0,
    payouts_enabled    TINYINT(1)      NOT NULL DEFAULT 0,
    business_name      VARCHAR(255)    NULL,
    email              VARCHAR(255)    NULL,
    country            CHAR(2)         NOT NULL DEFAULT 'US',
    default_currency   CHAR(3)         NOT NULL DEFAULT 'USD',
    capabilities       JSON            NULL,
    requirements       JSON            NULL,
    created_at         DATETIME        NOT NULL,
    updated_at         DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY cleat_connected_accounts_gateway_account_unique (gateway_account_id),
    KEY cleat_connected_accounts_owner_index (owner_type, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cleat_subscriptions (
    id                      BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    customer_id             BIGINT UNSIGNED  NOT NULL,
    price_id                BIGINT UNSIGNED  NOT NULL,
    status                  ENUM('incomplete','trialing','active','past_due','canceled','unpaid') NOT NULL,
    quantity                INT UNSIGNED     NOT NULL DEFAULT 1,
    anchor_day              TINYINT UNSIGNED NOT NULL,
    trial_ends_at           DATETIME         NULL,
    current_period_start    DATETIME         NOT NULL,
    current_period_end      DATETIME         NOT NULL,
    cancel_at_period_end    TINYINT(1)       NOT NULL DEFAULT 0,
    canceled_at             DATETIME         NULL,
    ends_at                 DATETIME         NULL,
    connected_account_id    BIGINT UNSIGNED  NULL,
    application_fee_percent DECIMAL(5,2)     NULL,
    created_at              DATETIME         NOT NULL,
    updated_at              DATETIME         NOT NULL,
    PRIMARY KEY (id),
    KEY cleat_subscriptions_status_period_index (status, current_period_end),
    KEY cleat_subscriptions_customer_index (customer_id),
    KEY cleat_subscriptions_price_index (price_id),
    KEY cleat_subscriptions_connected_account_index (connected_account_id),
    CONSTRAINT cleat_subscriptions_customer_fk FOREIGN KEY (customer_id) REFERENCES cleat_customers (id),
    CONSTRAINT cleat_subscriptions_price_fk FOREIGN KEY (price_id) REFERENCES cleat_prices (id),
    CONSTRAINT cleat_subscriptions_connected_account_fk FOREIGN KEY (connected_account_id) REFERENCES cleat_connected_accounts (id),
    CONSTRAINT cleat_subscriptions_quantity_check CHECK (quantity >= 1),
    CONSTRAINT cleat_subscriptions_anchor_check CHECK (anchor_day BETWEEN 1 AND 31)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cleat_payment_links (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_token            CHAR(64)        NOT NULL,
    price_id                BIGINT UNSIGNED NOT NULL,
    quantity                INT UNSIGNED    NOT NULL DEFAULT 1,
    allow_quantity_change   TINYINT(1)      NOT NULL DEFAULT 0,
    max_quantity            INT UNSIGNED    NULL,
    collect_name            TINYINT(1)      NOT NULL DEFAULT 1,
    collect_phone           TINYINT(1)      NOT NULL DEFAULT 0,
    success_url             VARCHAR(2048)   NULL,
    is_active               TINYINT(1)      NOT NULL DEFAULT 1,
    expires_at              DATETIME        NULL,
    max_uses                INT UNSIGNED    NULL,
    use_count               INT UNSIGNED    NOT NULL DEFAULT 0,
    metadata                JSON            NULL,
    connected_account_id    BIGINT UNSIGNED NULL,
    application_fee_percent DECIMAL(5,2)    NULL,
    created_at              DATETIME        NOT NULL,
    updated_at              DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY cleat_payment_links_token_unique (public_token),
    KEY cleat_payment_links_price_index (price_id),
    KEY cleat_payment_links_active_expiry_index (is_active, expires_at),
    KEY cleat_payment_links_connected_account_index (connected_account_id),
    CONSTRAINT cleat_payment_links_price_fk FOREIGN KEY (price_id) REFERENCES cleat_prices (id),
    CONSTRAINT cleat_payment_links_connected_account_fk FOREIGN KEY (connected_account_id) REFERENCES cleat_connected_accounts (id),
    CONSTRAINT cleat_payment_links_quantity_check CHECK (quantity >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cleat_invoices (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    number                 VARCHAR(32)     NULL,           -- extra: NULL until finalized
    public_token           CHAR(64)        NOT NULL,
    customer_id            BIGINT UNSIGNED NOT NULL,
    subscription_id        BIGINT UNSIGNED NULL,
    payment_link_id        BIGINT UNSIGNED NULL,
    status                 ENUM('draft','open','paid','void','uncollectible') NOT NULL DEFAULT 'draft',
    currency               CHAR(3)         NOT NULL,
    subtotal               BIGINT          NOT NULL DEFAULT 0,
    tax                    BIGINT          NOT NULL DEFAULT 0,
    total                  BIGINT          NOT NULL DEFAULT 0,
    amount_paid            BIGINT          NOT NULL DEFAULT 0,
    memo                   TEXT            NULL,
    attempt_count          INT UNSIGNED    NOT NULL DEFAULT 0,
    next_attempt_at        DATETIME        NULL,
    period_start           DATETIME        NULL,
    period_end             DATETIME        NULL,
    due_at                 DATETIME        NOT NULL,
    sent_at                DATETIME        NULL,
    paid_at                DATETIME        NULL,
    link_expires_at        DATETIME        NULL,
    connected_account_id   BIGINT UNSIGNED NULL,
    application_fee_amount BIGINT          NULL,
    created_at             DATETIME        NOT NULL,
    updated_at             DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY cleat_invoices_number_unique (number),
    UNIQUE KEY cleat_invoices_token_unique (public_token),
    KEY cleat_invoices_status_next_attempt_index (status, next_attempt_at),
    KEY cleat_invoices_customer_index (customer_id),
    KEY cleat_invoices_subscription_index (subscription_id),
    KEY cleat_invoices_payment_link_index (payment_link_id),
    KEY cleat_invoices_connected_account_index (connected_account_id),
    CONSTRAINT cleat_invoices_customer_fk FOREIGN KEY (customer_id) REFERENCES cleat_customers (id),
    CONSTRAINT cleat_invoices_subscription_fk FOREIGN KEY (subscription_id) REFERENCES cleat_subscriptions (id),
    CONSTRAINT cleat_invoices_payment_link_fk FOREIGN KEY (payment_link_id) REFERENCES cleat_payment_links (id),
    CONSTRAINT cleat_invoices_connected_account_fk FOREIGN KEY (connected_account_id) REFERENCES cleat_connected_accounts (id),
    CONSTRAINT cleat_invoices_paid_check CHECK (amount_paid >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cleat_invoice_items (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id      BIGINT UNSIGNED NULL,                  -- extra: NULL = pending item
    subscription_id BIGINT UNSIGNED NULL,                  -- extra: owner of a pending item
    price_id        BIGINT UNSIGNED NULL,
    description     VARCHAR(500)    NOT NULL,
    quantity        INT             NOT NULL DEFAULT 1,
    unit_amount     BIGINT          NOT NULL,
    amount          BIGINT          NOT NULL,
    is_proration    TINYINT(1)      NOT NULL DEFAULT 0,
    period_start    DATETIME        NULL,
    period_end      DATETIME        NULL,
    created_at      DATETIME        NOT NULL,              -- extra
    PRIMARY KEY (id),
    KEY cleat_invoice_items_invoice_index (invoice_id),
    KEY cleat_invoice_items_pending_index (subscription_id, invoice_id),
    KEY cleat_invoice_items_price_index (price_id),
    CONSTRAINT cleat_invoice_items_invoice_fk FOREIGN KEY (invoice_id) REFERENCES cleat_invoices (id),
    CONSTRAINT cleat_invoice_items_subscription_fk FOREIGN KEY (subscription_id) REFERENCES cleat_subscriptions (id),
    CONSTRAINT cleat_invoice_items_price_fk FOREIGN KEY (price_id) REFERENCES cleat_prices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cleat_charges (
    id                     BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    invoice_id             BIGINT UNSIGNED  NOT NULL,
    customer_id            BIGINT UNSIGNED  NOT NULL,
    amount                 BIGINT           NOT NULL,
    status                 ENUM('pending','succeeded','failed','held','refunded','voided') NOT NULL DEFAULT 'pending',
    source                 ENUM('stored_profile','one_time_token') NOT NULL,
    gateway_transaction_id VARCHAR(64)      NULL,
    response_code          TINYINT UNSIGNED NULL,
    reason_code            VARCHAR(32)      NULL,
    reason_text            VARCHAR(500)     NULL,
    idempotency_key        VARCHAR(191)     NOT NULL,
    ip_address             VARCHAR(45)      NULL,
    connected_account_id   BIGINT UNSIGNED  NULL,
    application_fee_amount BIGINT           NULL,
    raw_response           JSON             NULL,
    created_at             DATETIME         NOT NULL,
    updated_at             DATETIME         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY cleat_charges_idempotency_unique (idempotency_key),
    KEY cleat_charges_invoice_status_index (invoice_id, status),
    KEY cleat_charges_customer_index (customer_id),
    KEY cleat_charges_transaction_index (gateway_transaction_id),
    KEY cleat_charges_connected_account_index (connected_account_id),
    CONSTRAINT cleat_charges_invoice_fk FOREIGN KEY (invoice_id) REFERENCES cleat_invoices (id),
    CONSTRAINT cleat_charges_customer_fk FOREIGN KEY (customer_id) REFERENCES cleat_customers (id),
    CONSTRAINT cleat_charges_connected_account_fk FOREIGN KEY (connected_account_id) REFERENCES cleat_connected_accounts (id),
    CONSTRAINT cleat_charges_amount_check CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- extra: one row per refund or void attempt against a charge.
CREATE TABLE IF NOT EXISTS cleat_refunds (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    charge_id              BIGINT UNSIGNED NOT NULL,
    amount                 BIGINT          NOT NULL,
    method                 ENUM('refund','void') NOT NULL,
    status                 ENUM('pending','succeeded','failed') NOT NULL DEFAULT 'pending',
    gateway_transaction_id VARCHAR(64)     NULL,
    reason_code            VARCHAR(32)     NULL,
    reason_text            VARCHAR(500)    NULL,
    idempotency_key        VARCHAR(191)    NOT NULL,
    raw_response           JSON            NULL,
    created_at             DATETIME        NOT NULL,
    updated_at             DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY cleat_refunds_idempotency_unique (idempotency_key),
    KEY cleat_refunds_charge_index (charge_id),
    KEY cleat_refunds_transaction_index (gateway_transaction_id),
    CONSTRAINT cleat_refunds_charge_fk FOREIGN KEY (charge_id) REFERENCES cleat_charges (id),
    CONSTRAINT cleat_refunds_amount_check CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cleat_webhook_events (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    gateway_event_id VARCHAR(191)    NOT NULL,
    event_type       VARCHAR(191)    NOT NULL,
    payload          JSON            NOT NULL,
    processed_at     DATETIME        NULL,
    created_at       DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY cleat_webhook_events_gateway_event_unique (gateway_event_id),
    KEY cleat_webhook_events_type_index (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cleat_rate_limits (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bucket       VARCHAR(191)    NOT NULL,
    window_start DATETIME        NOT NULL,
    hits         INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY cleat_rate_limits_bucket_window_unique (bucket, window_start),
    KEY cleat_rate_limits_window_index (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Connect stubs, unused until the Connect driver ships.
CREATE TABLE IF NOT EXISTS cleat_transfers (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    connected_account_id BIGINT UNSIGNED NOT NULL,
    charge_id            BIGINT UNSIGNED NULL,
    amount               BIGINT          NOT NULL,
    currency             CHAR(3)         NOT NULL,
    status               ENUM('pending','paid','failed','reversed') NOT NULL DEFAULT 'pending',
    gateway_transfer_id  VARCHAR(191)    NULL,
    description          VARCHAR(500)    NULL,
    created_at           DATETIME        NOT NULL,
    updated_at           DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY cleat_transfers_account_index (connected_account_id),
    KEY cleat_transfers_charge_index (charge_id),
    CONSTRAINT cleat_transfers_account_fk FOREIGN KEY (connected_account_id) REFERENCES cleat_connected_accounts (id),
    CONSTRAINT cleat_transfers_charge_fk FOREIGN KEY (charge_id) REFERENCES cleat_charges (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cleat_payouts (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    connected_account_id BIGINT UNSIGNED NOT NULL,
    amount               BIGINT          NOT NULL,
    currency             CHAR(3)         NOT NULL,
    method               ENUM('ach','rtp','card') NOT NULL,
    status               ENUM('pending','in_transit','paid','failed','canceled') NOT NULL DEFAULT 'pending',
    gateway_payout_id    VARCHAR(191)    NULL,
    arrival_date         DATE            NULL,
    failure_reason       VARCHAR(500)    NULL,
    created_at           DATETIME        NOT NULL,
    updated_at           DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY cleat_payouts_account_index (connected_account_id),
    CONSTRAINT cleat_payouts_account_fk FOREIGN KEY (connected_account_id) REFERENCES cleat_connected_accounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
