-- ============================================================================
-- HYBRID AI ACCOUNTING & TAX PREPARATION WEB APPLICATION
-- MODULE 1: DATABASE SCHEMA (SQL)
-- Enhanced with Enterprise Admin, Analytics, Subscriptions & Coupons
-- Compatible with MySQL 8.x / MariaDB & ANSI SQL
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `accounting_db` 
  DEFAULT CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci;

USE `accounting_db`;

-- Drop existing tables in reverse FK dependency order
DROP TABLE IF EXISTS `coupon_redemptions`;
DROP TABLE IF EXISTS `subscriptions`;
DROP TABLE IF EXISTS `coupons`;
DROP TABLE IF EXISTS `subscription_plans`;
DROP TABLE IF EXISTS `web_activity_logs`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `transactions`;
DROP TABLE IF EXISTS `chart_of_accounts`;
DROP TABLE IF EXISTS `users`;

-- ----------------------------------------------------------------------------
-- 1. USERS TABLE
-- Stores credentials, role, status, subscription tier, and GSTIN
-- ----------------------------------------------------------------------------
CREATE TABLE `users` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `gst_number` VARCHAR(15) NULL DEFAULT NULL,
    `business_name` VARCHAR(255) NULL DEFAULT 'My Enterprise',
    `role` ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    `status` ENUM('active', 'suspended', 'pending') NOT NULL DEFAULT 'active',
    `tier` ENUM('free', 'starter', 'pro', 'enterprise') NOT NULL DEFAULT 'free',
    `last_login_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_users_email` (`email`),
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. CHART OF ACCOUNTS TABLE
-- Standard double-entry accounts categorized by statutory account type
-- ----------------------------------------------------------------------------
CREATE TABLE `chart_of_accounts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(20) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `type` ENUM('asset', 'liability', 'equity', 'revenue', 'expense') NOT NULL,
    `gst_applicable` TINYINT(1) NOT NULL DEFAULT 0,
    `description` VARCHAR(255) NULL DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_coa_type` (`type`),
    INDEX `idx_coa_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. TRANSACTIONS TABLE
-- Deterministic accounting ledger entries with split GST
-- ----------------------------------------------------------------------------
CREATE TABLE `transactions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `account_id` INT UNSIGNED NOT NULL,
    `type` ENUM('credit', 'debit') NOT NULL,
    `amount` DECIMAL(15, 2) NOT NULL,
    `date` DATE NOT NULL,
    `description` TEXT NOT NULL,
    `gst_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    `cgst` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    `sgst` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    `igst` DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    `supply_type` ENUM('inward', 'outward') NOT NULL DEFAULT 'inward',
    `status` ENUM('pending', 'posted', 'flagged', 'void') NOT NULL DEFAULT 'posted',
    `raw_ai_input` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    CONSTRAINT `fk_transactions_user` 
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
        
    CONSTRAINT `fk_transactions_account` 
        FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`) 
        ON DELETE RESTRICT ON UPDATE CASCADE,
        
    INDEX `idx_transactions_user_date` (`user_id`, `date`),
    INDEX `idx_transactions_account_id` (`account_id`),
    INDEX `idx_transactions_supply_type` (`supply_type`),
    INDEX `idx_transactions_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. WEB ACTIVITY & TELEMETRY LOGS
-- ----------------------------------------------------------------------------
CREATE TABLE `web_activity_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `ip_address` VARCHAR(45) NOT NULL DEFAULT '127.0.0.1',
    `method` VARCHAR(10) NOT NULL,
    `endpoint` VARCHAR(255) NOT NULL,
    `status_code` INT NOT NULL DEFAULT 200,
    `response_time_ms` DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    `user_agent` TEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_wal_created_at` (`created_at`),
    INDEX `idx_wal_endpoint` (`endpoint`),
    INDEX `idx_wal_status_code` (`status_code`),
    INDEX `idx_wal_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. SUBSCRIPTION PLANS TABLE
-- ----------------------------------------------------------------------------
CREATE TABLE `subscription_plans` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `price_monthly` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `price_annual` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `max_transactions` INT NOT NULL DEFAULT 100,
    `ai_queries_limit` INT NOT NULL DEFAULT 50,
    `features_json` TEXT NULL DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. SUBSCRIPTIONS TABLE
-- ----------------------------------------------------------------------------
CREATE TABLE `subscriptions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `plan_id` INT UNSIGNED NOT NULL,
    `billing_cycle` ENUM('monthly', 'annual') NOT NULL DEFAULT 'monthly',
    `status` ENUM('active', 'trial', 'past_due', 'canceled') NOT NULL DEFAULT 'active',
    `current_period_start` DATE NOT NULL,
    `current_period_end` DATE NOT NULL,
    `amount_paid` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `coupon_id` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sub_user_id` (`user_id`),
    INDEX `idx_sub_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. COUPONS TABLE
-- ----------------------------------------------------------------------------
CREATE TABLE `coupons` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `discount_type` ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
    `discount_value` DECIMAL(10, 2) NOT NULL,
    `min_order_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `max_uses` INT NOT NULL DEFAULT 100,
    `used_count` INT NOT NULL DEFAULT 0,
    `valid_from` DATE NOT NULL,
    `valid_until` DATE NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_coupons_code` (`code`),
    INDEX `idx_coupons_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8. COUPON REDEMPTIONS TABLE
-- ----------------------------------------------------------------------------
CREATE TABLE `coupon_redemptions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `coupon_id` INT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `discount_applied` DECIMAL(10, 2) NOT NULL,
    `order_amount` DECIMAL(10, 2) NOT NULL,
    `redeemed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_cr_coupon_id` (`coupon_id`),
    INDEX `idx_cr_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 9. SYSTEM SETTINGS & ANNOUNCEMENTS TABLE
-- ----------------------------------------------------------------------------
CREATE TABLE `system_settings` (
    `setting_key` VARCHAR(100) PRIMARY KEY,
    `setting_value` TEXT NULL DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
