-- ============================================================
-- migrate.sql — Run this on existing databases only
-- Fresh installs: use schema.sql instead
-- ============================================================

USE upi_wallet;

-- 1. users: add password + is_suspicious (skip if already exist)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS password      VARCHAR(255) NOT NULL DEFAULT '' AFTER email,
    ADD COLUMN IF NOT EXISTS is_suspicious TINYINT(1)   NOT NULL DEFAULT 0  AFTER balance,
    ADD KEY IF NOT EXISTS idx_suspicious (is_suspicious);

-- 2. payments: add last_checked_at, drop old FK if it exists
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS last_checked_at DATETIME NULL DEFAULT NULL AFTER status;

-- Safely drop FK only if it exists (won't error if absent)
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME        = 'payments'
      AND CONSTRAINT_NAME   = 'fk_payments_user'
      AND CONSTRAINT_TYPE   = 'FOREIGN KEY'
);
SET @sql = IF(@fk_exists > 0,
    'ALTER TABLE payments DROP FOREIGN KEY fk_payments_user',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. rate_limits: create if not exists
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

SELECT 'Migration complete.' AS result;
