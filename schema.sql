-- ============================================================
-- UPI Payment Verification System - Database Schema
-- ============================================================

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100)    NOT NULL DEFAULT '',
    email         VARCHAR(191)    NOT NULL,
    password      VARCHAR(255)    NOT NULL,
    balance       DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
    is_suspicious TINYINT(1)      NOT NULL DEFAULT 0,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email (email),
    KEY idx_suspicious (is_suspicious)
) ENGINE=InnoDB;

-- If upgrading an existing database, run:
-- ALTER TABLE users
--   ADD COLUMN password      VARCHAR(255) NOT NULL DEFAULT '' AFTER email,
--   ADD COLUMN is_suspicious TINYINT(1)   NOT NULL DEFAULT 0  AFTER balance;

-- Payments table
CREATE TABLE IF NOT EXISTS payments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED    NOT NULL,
    utr             VARCHAR(64)     NOT NULL,
    amount          DECIMAL(12, 2)  NOT NULL,
    status          ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
    last_checked_at DATETIME        NULL DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_utr (utr),
    KEY idx_user_id (user_id),
    KEY idx_status (status),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB;

-- If upgrading an existing database, run:
-- ALTER TABLE payments
--   ADD COLUMN last_checked_at DATETIME NULL DEFAULT NULL AFTER status,
--   DROP FOREIGN KEY fk_payments_user;

-- Demo users removed — register via /register.php instead.

-- Rate limiting table
CREATE TABLE IF NOT EXISTS rate_limits (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip         VARCHAR(45)  NOT NULL,
    action     VARCHAR(50)  NOT NULL,
    identifier VARCHAR(191) NOT NULL DEFAULT '',
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lookup     (ip, action, created_at),
    KEY idx_identifier (action, identifier, created_at),
    KEY idx_cleanup    (created_at)
) ENGINE=InnoDB;

-- If upgrading an existing database, run:
-- CREATE TABLE IF NOT EXISTS rate_limits ... (same as above)
