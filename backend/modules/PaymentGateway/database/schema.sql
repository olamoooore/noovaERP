-- ============================================
-- Payment Gateway Module - Database Schema
-- ============================================
-- Module: PaymentGateway
-- Version: 1.0
-- Last Updated: January 2026
-- ============================================

-- Table: payment_gateways
-- Purpose: Store payment gateway configurations (Stripe, HitPay)
-- ============================================

CREATE TABLE IF NOT EXISTS `payment_gateways` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `gateway_name` VARCHAR(255) NOT NULL COMMENT 'Gateway identifier: stripe, hitpay',
    `publishable_key` TEXT NULL COMMENT 'Encrypted - Frontend API key (Stripe only)',
    `api_key` TEXT NOT NULL COMMENT 'Encrypted - Backend API key',
    `api_secret` TEXT NULL COMMENT 'Encrypted - Secret key for HMAC (HitPay)',
    `webhook_secret` TEXT NULL COMMENT 'Encrypted - Webhook signature secret',
    `is_active` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Gateway enabled status',
    `is_test_mode` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Test/Production mode',
    `config` JSON NULL COMMENT 'Additional gateway-specific settings',
    `created_by` BIGINT UNSIGNED NULL COMMENT 'User who created this gateway',
    `updated_by` BIGINT UNSIGNED NULL COMMENT 'User who last updated',
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Soft delete timestamp',
    
    -- Indexes
    INDEX `idx_gateway_active` (`gateway_name`, `is_active`),
    INDEX `idx_created_by` (`created_by`),
    INDEX `idx_updated_by` (`updated_by`),
    INDEX `idx_deleted_at` (`deleted_at`),
    
    -- Foreign Keys
    CONSTRAINT `fk_payment_gateways_created_by` 
        FOREIGN KEY (`created_by`) 
        REFERENCES `users` (`id`) 
        ON DELETE SET NULL,
    CONSTRAINT `fk_payment_gateways_updated_by` 
        FOREIGN KEY (`updated_by`) 
        REFERENCES `users` (`id`) 
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Payment gateway configurations with encrypted credentials';


-- ============================================
-- Table: payment_transactions
-- Purpose: Store all payment transactions and their statuses
-- ============================================

CREATE TABLE IF NOT EXISTS `payment_transactions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `payment_gateway_id` BIGINT UNSIGNED NOT NULL COMMENT 'Reference to payment gateway',
    `invoice_id` BIGINT UNSIGNED NULL COMMENT 'Associated invoice',
    `customer_id` BIGINT UNSIGNED NULL COMMENT 'Customer reference',
    `transaction_id` VARCHAR(255) NOT NULL UNIQUE COMMENT 'Gateway transaction ID',
    `amount` DECIMAL(15,2) NOT NULL COMMENT 'Payment amount',
    `currency` VARCHAR(3) NOT NULL DEFAULT 'USD' COMMENT 'Currency code (ISO 4217)',
    `status` ENUM('pending', 'processing', 'completed', 'failed', 'refunded', 'cancelled') 
        NOT NULL DEFAULT 'pending' COMMENT 'Transaction status',
    `payment_method` VARCHAR(255) NULL COMMENT 'Payment type: card, bank_transfer, ewallet, paynow, etc.',
    `gateway_response` JSON NULL COMMENT 'Full response from payment gateway',
    `metadata` JSON NULL COMMENT 'Additional data: customer email, items, custom fields',
    `processed_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'When payment was processed',
    `error_message` TEXT NULL COMMENT 'Error details if payment failed',
    
    -- Webhook tracking (added 2026-01-19)
    `webhook_id` VARCHAR(255) NULL COMMENT 'Webhook event ID for idempotency',
    `last_webhook_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Last webhook processing time',
    
    -- Refund tracking (added 2026-01-19)
    `refund_id` VARCHAR(255) NULL COMMENT 'Gateway refund reference',
    `refunded_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Total refunded amount',
    `refund_reason` TEXT NULL COMMENT 'Reason for refund',
    `refunded_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Refund timestamp',
    
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    
    -- Indexes
    INDEX `idx_transaction_id` (`transaction_id`),
    INDEX `idx_status_created` (`status`, `created_at`),
    INDEX `idx_invoice_id` (`invoice_id`),
    INDEX `idx_customer_id` (`customer_id`),
    INDEX `idx_gateway_status` (`payment_gateway_id`, `status`),
    INDEX `idx_webhook_id` (`webhook_id`),
    
    -- Foreign Keys
    CONSTRAINT `fk_payment_transactions_gateway` 
        FOREIGN KEY (`payment_gateway_id`) 
        REFERENCES `payment_gateways` (`id`) 
        ON DELETE CASCADE,
    CONSTRAINT `fk_payment_transactions_invoice` 
        FOREIGN KEY (`invoice_id`) 
        REFERENCES `invoices` (`id`) 
        ON DELETE SET NULL,
    CONSTRAINT `fk_payment_transactions_customer` 
        FOREIGN KEY (`customer_id`) 
        REFERENCES `customers` (`id`) 
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Payment transaction records with refund and webhook tracking';


-- ============================================
-- Sample Data / Default Values
-- ============================================

-- Note: Run PaymentGatewaySeeder for default gateway configurations
-- php artisan db:seed --class=Modules\\PaymentGateway\\Database\\Seeders\\PaymentGatewayDatabaseSeeder


-- ============================================
-- Migration History
-- ============================================
/*
1. 2025_01_01_000001_create_payment_gateways_table.php
   - Created payment_gateways table with encrypted credentials
   
2. 2025_01_01_000002_create_payment_transactions_table.php
   - Created payment_transactions table with status tracking
   
3. 2025_12_30_060058_add_publishable_key_to_payment_gateways_table.php
   - Added publishable_key for Stripe frontend integration
   
4. 2025_12_31_070000_cleanup_duplicate_payments.php
   - Data cleanup: removed duplicate pending transactions
   
5. 2026_01_19_000001_add_webhook_tracking_to_payment_transactions.php
   - Added webhook_id and last_webhook_at for idempotency
   
6. 2026_01_19_000002_add_refund_columns_to_payment_transactions.php
   - Added refund_id, refunded_amount, refund_reason, refunded_at
*/
