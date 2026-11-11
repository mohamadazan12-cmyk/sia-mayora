-- ============================================================================
-- SISTEM INFORMASI OPERASIONAL MANUFAKTUR & DISTRIBUSI (SIA)
-- Database Schema Script for MySQL 8.0+ / MariaDB 10.4+
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- File: database/schema.sql
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Pastikan database tersedia
CREATE DATABASE IF NOT EXISTS `projek_sia`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `projek_sia`;

-- ============================================================================
-- GROUP 1: USERS AND AUDIT
-- ============================================================================

-- 1. roles
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `role_code` VARCHAR(50) NOT NULL UNIQUE,
    `role_name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_roles_role_code` (`role_code`),
    INDEX `idx_roles_role_name` (`role_name`),
    INDEX `idx_roles_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. users
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `role_id` INT NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_users_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_users_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_users_username` (`username`),
    INDEX `idx_users_email` (`email`),
    INDEX `idx_users_role_id` (`role_id`),
    INDEX `idx_users_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. audit_logs
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `action` VARCHAR(50) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` INT NULL,
    `old_values` LONGTEXT NULL,
    `new_values` LONGTEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_audit_logs_user_id` (`user_id`),
    INDEX `idx_audit_logs_action` (`action`),
    INDEX `idx_audit_logs_entity` (`entity_type`, `entity_id`),
    INDEX `idx_audit_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. approvals
CREATE TABLE IF NOT EXISTS `approvals` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` INT NOT NULL,
    `ref_number` VARCHAR(50) NULL,
    `decision` ENUM('pending','approved','rejected','revision') NOT NULL DEFAULT 'pending',
    `reason` TEXT NULL,
    `approved_by` INT NULL,
    `decided_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_approvals_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_approvals_entity` (`entity_type`, `entity_id`),
    INDEX `idx_approvals_ref_number` (`ref_number`),
    INDEX `idx_approvals_decision` (`decision`),
    INDEX `idx_approvals_approved_by` (`approved_by`),
    INDEX `idx_approvals_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- GROUP 2: MASTER AND STOCK
-- ============================================================================

-- 5. departments
CREATE TABLE IF NOT EXISTS `departments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_departments_code` (`code`),
    INDEX `idx_departments_is_active` (`is_active`),
    INDEX `idx_departments_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. positions
CREATE TABLE IF NOT EXISTS `positions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `department_id` INT NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_positions_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON UPDATE CASCADE,
    INDEX `idx_positions_code` (`code`),
    INDEX `idx_positions_department_id` (`department_id`),
    INDEX `idx_positions_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. item_categories
CREATE TABLE IF NOT EXISTS `item_categories` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `type` ENUM('bahan_baku','barang_jadi','packaging','lainnya') NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_item_categories_code` (`code`),
    INDEX `idx_item_categories_type` (`type`),
    INDEX `idx_item_categories_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. units
CREATE TABLE IF NOT EXISTS `units` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(20) NOT NULL UNIQUE,
    `name` VARCHAR(50) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_units_code` (`code`),
    INDEX `idx_units_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. items
CREATE TABLE IF NOT EXISTS `items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(150) NOT NULL,
    `category_id` INT NOT NULL,
    `unit_id` INT NOT NULL,
    `min_stock` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `cost_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `selling_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `description` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_items_category` FOREIGN KEY (`category_id`) REFERENCES `item_categories` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_items_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_items_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_items_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_items_min_stock` CHECK (`min_stock` >= 0),
    INDEX `idx_items_code` (`code`),
    INDEX `idx_items_name` (`name`),
    INDEX `idx_items_category_id` (`category_id`),
    INDEX `idx_items_unit_id` (`unit_id`),
    INDEX `idx_items_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. warehouses
CREATE TABLE IF NOT EXISTS `warehouses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `type` ENUM('bahan_baku','barang_jadi','wip','umum') NOT NULL DEFAULT 'umum',
    `address` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_warehouses_code` (`code`),
    INDEX `idx_warehouses_type` (`type`),
    INDEX `idx_warehouses_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. stock_balances
CREATE TABLE IF NOT EXISTS `stock_balances` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `item_id` INT NOT NULL,
    `warehouse_id` INT NOT NULL,
    `qty_available` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `qty_reserved` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `qty_on_hold` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `uq_stock_balances_item_wh` UNIQUE (`item_id`, `warehouse_id`),
    CONSTRAINT `fk_stock_balances_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_stock_balances_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `chk_stock_balances_qty_available` CHECK (`qty_available` >= 0),
    CONSTRAINT `chk_stock_balances_qty_reserved` CHECK (`qty_reserved` >= 0),
    CONSTRAINT `chk_stock_balances_qty_on_hold` CHECK (`qty_on_hold` >= 0),
    INDEX `idx_stock_balances_item_id` (`item_id`),
    INDEX `idx_stock_balances_wh_id` (`warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. stock_movements
CREATE TABLE IF NOT EXISTS `stock_movements` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `item_id` INT NOT NULL,
    `warehouse_id` INT NOT NULL,
    `movement_type` ENUM('in','out','adjustment','transfer') NOT NULL,
    `qty_in` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `qty_out` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `balance_after` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `ref_type` VARCHAR(50) NULL,
    `ref_id` INT NULL,
    `ref_number` VARCHAR(50) NULL,
    `notes` TEXT NULL,
    `user_id` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_stock_movements_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_stock_movements_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_stock_movements_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_stock_movements_qty_in` CHECK (`qty_in` >= 0),
    CONSTRAINT `chk_stock_movements_qty_out` CHECK (`qty_out` >= 0),
    INDEX `idx_stock_movements_item_id` (`item_id`),
    INDEX `idx_stock_movements_wh_id` (`warehouse_id`),
    INDEX `idx_stock_movements_type` (`movement_type`),
    INDEX `idx_stock_movements_ref` (`ref_type`, `ref_id`),
    INDEX `idx_stock_movements_ref_number` (`ref_number`),
    INDEX `idx_stock_movements_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. customers
CREATE TABLE IF NOT EXISTS `customers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(150) NOT NULL,
    `address` TEXT NULL,
    `phone` VARCHAR(50) NULL,
    `email` VARCHAR(100) NULL,
    `contact_person` VARCHAR(100) NULL,
    `credit_limit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `credit_used` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `credit_status` ENUM('active','blocked','warning') NOT NULL DEFAULT 'active',
    `payment_terms_days` INT NOT NULL DEFAULT 30,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_customers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_customers_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_customers_credit_limit` CHECK (`credit_limit` >= 0),
    CONSTRAINT `chk_customers_credit_used` CHECK (`credit_used` >= 0),
    INDEX `idx_customers_code` (`code`),
    INDEX `idx_customers_name` (`name`),
    INDEX `idx_customers_credit_status` (`credit_status`),
    INDEX `idx_customers_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. suppliers
CREATE TABLE IF NOT EXISTS `suppliers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(150) NOT NULL,
    `address` TEXT NULL,
    `phone` VARCHAR(50) NULL,
    `email` VARCHAR(100) NULL,
    `contact_person` VARCHAR(100) NULL,
    `payment_terms_days` INT NOT NULL DEFAULT 30,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_suppliers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_suppliers_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_suppliers_code` (`code`),
    INDEX `idx_suppliers_name` (`name`),
    INDEX `idx_suppliers_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. boms (Bill of Materials)
CREATE TABLE IF NOT EXISTS `boms` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_item_id` INT NOT NULL UNIQUE,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(150) NOT NULL,
    `version` INT NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_boms_product_item` FOREIGN KEY (`product_item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_boms_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_boms_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_boms_code` (`code`),
    INDEX `idx_boms_product_item_id` (`product_item_id`),
    INDEX `idx_boms_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. bom_items
CREATE TABLE IF NOT EXISTS `bom_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `bom_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `qty_per_unit` DECIMAL(15,4) NOT NULL,
    `unit_id` INT NOT NULL,
    `notes` TEXT NULL,
    CONSTRAINT `fk_bom_items_bom` FOREIGN KEY (`bom_id`) REFERENCES `boms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_bom_items_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_bom_items_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_bom_items_qty_per_unit` CHECK (`qty_per_unit` > 0),
    INDEX `idx_bom_items_bom_id` (`bom_id`),
    INDEX `idx_bom_items_item_id` (`item_id`),
    INDEX `idx_bom_items_unit_id` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. employees
CREATE TABLE IF NOT EXISTS `employees` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `full_name` VARCHAR(150) NOT NULL,
    `department_id` INT NOT NULL,
    `position_id` INT NOT NULL,
    `join_date` DATE NOT NULL,
    `resign_date` DATE NULL,
    `base_salary` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `bank_account` VARCHAR(50) NULL,
    `bank_name` VARCHAR(100) NULL,
    `status` ENUM('active','inactive','resigned') NOT NULL DEFAULT 'active',
    `user_id` INT NULL UNIQUE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_employees_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_employees_pos` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_employees_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_employees_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_employees_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_employees_base_salary` CHECK (`base_salary` >= 0),
    INDEX `idx_employees_code` (`code`),
    INDEX `idx_employees_department_id` (`department_id`),
    INDEX `idx_employees_position_id` (`position_id`),
    INDEX `idx_employees_status` (`status`),
    INDEX `idx_employees_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- GROUP 3: BUDGET
-- ============================================================================

-- 18. budgets
CREATE TABLE IF NOT EXISTS `budgets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(150) NOT NULL,
    `period_year` INT NOT NULL,
    `period_month` INT NULL,
    `department_id` INT NULL,
    `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `used_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_budgets_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_budgets_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_budgets_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_budgets_total_amount` CHECK (`total_amount` >= 0),
    CONSTRAINT `chk_budgets_used_amount` CHECK (`used_amount` >= 0),
    INDEX `idx_budgets_code` (`code`),
    INDEX `idx_budgets_period` (`period_year`, `period_month`),
    INDEX `idx_budgets_department_id` (`department_id`),
    INDEX `idx_budgets_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. budget_lines
CREATE TABLE IF NOT EXISTS `budget_lines` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `budget_id` INT NOT NULL,
    `category` VARCHAR(100) NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `used_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_budget_lines_budget` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `chk_budget_lines_amount` CHECK (`amount` >= 0),
    CONSTRAINT `chk_budget_lines_used_amount` CHECK (`used_amount` >= 0),
    INDEX `idx_budget_lines_budget_id` (`budget_id`),
    INDEX `idx_budget_lines_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- GROUP 4: SALES / RECEIVING (PENERIMAAN)
-- ============================================================================

-- 20. customer_orders
CREATE TABLE IF NOT EXISTS `customer_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_number` VARCHAR(50) NOT NULL UNIQUE,
    `customer_id` INT NOT NULL,
    `order_date` DATE NOT NULL,
    `notes` TEXT NULL,
    `status` ENUM('draft','confirmed','processing','completed','cancelled') NOT NULL DEFAULT 'draft',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_cust_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_cust_orders_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_cust_orders_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_customer_orders_number` (`order_number`),
    INDEX `idx_customer_orders_customer_id` (`customer_id`),
    INDEX `idx_customer_orders_date` (`order_date`),
    INDEX `idx_customer_orders_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. customer_order_items
CREATE TABLE IF NOT EXISTS `customer_order_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_order_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `qty` DECIMAL(15,2) NOT NULL,
    `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    CONSTRAINT `fk_co_items_order` FOREIGN KEY (`customer_order_id`) REFERENCES `customer_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_co_items_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_co_items_qty` CHECK (`qty` > 0),
    CONSTRAINT `chk_co_items_price` CHECK (`unit_price` >= 0),
    CONSTRAINT `chk_co_items_subtotal` CHECK (`subtotal` >= 0),
    INDEX `idx_co_items_order_id` (`customer_order_id`),
    INDEX `idx_co_items_item_id` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22. credit_overrides
CREATE TABLE IF NOT EXISTS `credit_overrides` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT NOT NULL,
    `ref_type` VARCHAR(50) NOT NULL,
    `ref_id` INT NOT NULL,
    `requested_by` INT NOT NULL,
    `reason` TEXT NOT NULL,
    `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `decided_by` INT NULL,
    `decided_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_credit_overrides_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_credit_overrides_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_credit_overrides_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_credit_overrides_customer_id` (`customer_id`),
    INDEX `idx_credit_overrides_ref` (`ref_type`, `ref_id`),
    INDEX `idx_credit_overrides_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 23. sales_orders
CREATE TABLE IF NOT EXISTS `sales_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `so_number` VARCHAR(50) NOT NULL UNIQUE,
    `customer_order_id` INT NULL,
    `customer_id` INT NOT NULL,
    `so_date` DATE NOT NULL,
    `due_date` DATE NULL,
    `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `status` ENUM('draft','pending_approval','approved','picking','packed','shipped','completed','cancelled') NOT NULL DEFAULT 'draft',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_so_customer_order` FOREIGN KEY (`customer_order_id`) REFERENCES `customer_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_so_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_so_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_so_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_so_total_amount` CHECK (`total_amount` >= 0),
    INDEX `idx_sales_orders_number` (`so_number`),
    INDEX `idx_sales_orders_customer_id` (`customer_id`),
    INDEX `idx_sales_orders_co_id` (`customer_order_id`),
    INDEX `idx_sales_orders_date` (`so_date`),
    INDEX `idx_sales_orders_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 24. sales_order_items
CREATE TABLE IF NOT EXISTS `sales_order_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sales_order_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `qty_ordered` DECIMAL(15,2) NOT NULL,
    `qty_shipped` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    CONSTRAINT `fk_so_items_order` FOREIGN KEY (`sales_order_id`) REFERENCES `sales_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_so_items_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_so_items_qty_ordered` CHECK (`qty_ordered` > 0),
    CONSTRAINT `chk_so_items_qty_shipped` CHECK (`qty_shipped` >= 0),
    CONSTRAINT `chk_so_items_price` CHECK (`unit_price` >= 0),
    CONSTRAINT `chk_so_items_subtotal` CHECK (`subtotal` >= 0),
    INDEX `idx_so_items_so_id` (`sales_order_id`),
    INDEX `idx_so_items_item_id` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 25. delivery_orders
CREATE TABLE IF NOT EXISTS `delivery_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `do_number` VARCHAR(50) NOT NULL UNIQUE,
    `sales_order_id` INT NOT NULL,
    `warehouse_id` INT NOT NULL,
    `do_date` DATE NOT NULL,
    `recipient_name` VARCHAR(150) NULL,
    `recipient_address` TEXT NULL,
    `driver_name` VARCHAR(100) NULL,
    `vehicle_number` VARCHAR(50) NULL,
    `notes` TEXT NULL,
    `status` ENUM('draft','picking','packed','shipped','delivered','cancelled') NOT NULL DEFAULT 'draft',
    `delivered_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_do_sales_order` FOREIGN KEY (`sales_order_id`) REFERENCES `sales_orders` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_do_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_do_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_do_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_do_number` (`do_number`),
    INDEX `idx_do_so_id` (`sales_order_id`),
    INDEX `idx_do_wh_id` (`warehouse_id`),
    INDEX `idx_do_date` (`do_date`),
    INDEX `idx_do_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 26. delivery_order_items
CREATE TABLE IF NOT EXISTS `delivery_order_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `delivery_order_id` INT NOT NULL,
    `sales_order_item_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `qty_shipped` DECIMAL(15,2) NOT NULL,
    `notes` TEXT NULL,
    CONSTRAINT `fk_doi_delivery_order` FOREIGN KEY (`delivery_order_id`) REFERENCES `delivery_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_doi_so_item` FOREIGN KEY (`sales_order_item_id`) REFERENCES `sales_order_items` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_doi_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_doi_qty_shipped` CHECK (`qty_shipped` > 0),
    INDEX `idx_doi_do_id` (`delivery_order_id`),
    INDEX `idx_doi_so_item_id` (`sales_order_item_id`),
    INDEX `idx_doi_item_id` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 27. delivery_proofs
CREATE TABLE IF NOT EXISTS `delivery_proofs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `delivery_order_id` INT NOT NULL,
    `proof_type` VARCHAR(50) NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `notes` TEXT NULL,
    `uploaded_by` INT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_dp_delivery_order` FOREIGN KEY (`delivery_order_id`) REFERENCES `delivery_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_dp_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
    INDEX `idx_dp_do_id` (`delivery_order_id`),
    INDEX `idx_dp_uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 28. sales_invoices
CREATE TABLE IF NOT EXISTS `sales_invoices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
    `sales_order_id` INT NOT NULL,
    `delivery_order_id` INT NULL,
    `customer_id` INT NOT NULL,
    `invoice_date` DATE NOT NULL,
    `due_date` DATE NOT NULL,
    `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `paid_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `tax_invoice_number` VARCHAR(50) NULL,
    `notes` TEXT NULL,
    `status` ENUM('draft','issued','partially_paid','paid','overdue','cancelled') NOT NULL DEFAULT 'draft',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_sales_inv_so` FOREIGN KEY (`sales_order_id`) REFERENCES `sales_orders` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_sales_inv_do` FOREIGN KEY (`delivery_order_id`) REFERENCES `delivery_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_sales_inv_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_sales_inv_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_sales_inv_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_sales_inv_subtotal` CHECK (`subtotal` >= 0),
    CONSTRAINT `chk_sales_inv_tax` CHECK (`tax_amount` >= 0),
    CONSTRAINT `chk_sales_inv_total` CHECK (`total_amount` >= 0),
    CONSTRAINT `chk_sales_inv_paid` CHECK (`paid_amount` >= 0),
    INDEX `idx_sales_inv_number` (`invoice_number`),
    INDEX `idx_sales_inv_so_id` (`sales_order_id`),
    INDEX `idx_sales_inv_do_id` (`delivery_order_id`),
    INDEX `idx_sales_inv_customer_id` (`customer_id`),
    INDEX `idx_sales_inv_date` (`invoice_date`),
    INDEX `idx_sales_inv_due_date` (`due_date`),
    INDEX `idx_sales_inv_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 29. sales_invoice_items
CREATE TABLE IF NOT EXISTS `sales_invoice_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sales_invoice_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `qty` DECIMAL(15,2) NOT NULL,
    `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT `fk_sii_invoice` FOREIGN KEY (`sales_invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sii_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_sii_qty` CHECK (`qty` > 0),
    CONSTRAINT `chk_sii_price` CHECK (`unit_price` >= 0),
    CONSTRAINT `chk_sii_subtotal` CHECK (`subtotal` >= 0),
    INDEX `idx_sii_invoice_id` (`sales_invoice_id`),
    INDEX `idx_sii_item_id` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 30. collection_reminders
CREATE TABLE IF NOT EXISTS `collection_reminders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sales_invoice_id` INT NOT NULL,
    `reminder_date` DATE NOT NULL,
    `reminder_type` VARCHAR(50) NOT NULL,
    `notes` TEXT NULL,
    `sent_by` INT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_reminders_invoice` FOREIGN KEY (`sales_invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_reminders_sent_by` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
    INDEX `idx_reminders_invoice_id` (`sales_invoice_id`),
    INDEX `idx_reminders_date` (`reminder_date`),
    INDEX `idx_reminders_sent_by` (`sent_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 31. customer_receipts
CREATE TABLE IF NOT EXISTS `customer_receipts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `receipt_number` VARCHAR(50) NOT NULL UNIQUE,
    `customer_id` INT NOT NULL,
    `receipt_date` DATE NOT NULL,
    `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `payment_method` ENUM('transfer','cash','giro','cheque','other') NOT NULL DEFAULT 'transfer',
    `bank_ref` VARCHAR(100) NULL,
    `bank_name` VARCHAR(100) NULL,
    `notes` TEXT NULL,
    `status` ENUM('draft','verified','posted','cancelled') NOT NULL DEFAULT 'draft',
    `verified_by` INT NULL,
    `verified_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_cust_receipts_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_cust_receipts_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_cust_receipts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_cust_receipts_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_cust_receipts_total` CHECK (`total_amount` >= 0),
    INDEX `idx_cust_receipts_number` (`receipt_number`),
    INDEX `idx_cust_receipts_customer_id` (`customer_id`),
    INDEX `idx_cust_receipts_date` (`receipt_date`),
    INDEX `idx_cust_receipts_status` (`status`),
    INDEX `idx_cust_receipts_method` (`payment_method`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 32. customer_receipt_allocations
CREATE TABLE IF NOT EXISTS `customer_receipt_allocations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_receipt_id` INT NOT NULL,
    `sales_invoice_id` INT NOT NULL,
    `allocated_amount` DECIMAL(15,2) NOT NULL,
    CONSTRAINT `fk_cra_receipt` FOREIGN KEY (`customer_receipt_id`) REFERENCES `customer_receipts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_cra_invoice` FOREIGN KEY (`sales_invoice_id`) REFERENCES `sales_invoices` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_cra_amount` CHECK (`allocated_amount` > 0),
    INDEX `idx_cra_receipt_id` (`customer_receipt_id`),
    INDEX `idx_cra_invoice_id` (`sales_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 33. customer_returns
CREATE TABLE IF NOT EXISTS `customer_returns` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `return_number` VARCHAR(50) NOT NULL UNIQUE,
    `customer_id` INT NOT NULL,
    `sales_invoice_id` INT NULL,
    `return_date` DATE NOT NULL,
    `reason` TEXT NULL,
    `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('draft','pending_approval','approved','processed','rejected','cancelled') NOT NULL DEFAULT 'draft',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_cust_returns_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_cust_returns_invoice` FOREIGN KEY (`sales_invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_cust_returns_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_cust_returns_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_cust_returns_total` CHECK (`total_amount` >= 0),
    INDEX `idx_cust_returns_number` (`return_number`),
    INDEX `idx_cust_returns_customer_id` (`customer_id`),
    INDEX `idx_cust_returns_invoice_id` (`sales_invoice_id`),
    INDEX `idx_cust_returns_date` (`return_date`),
    INDEX `idx_cust_returns_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 34. customer_return_items
CREATE TABLE IF NOT EXISTS `customer_return_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_return_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `qty` DECIMAL(15,2) NOT NULL,
    `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `condition_notes` TEXT NULL,
    CONSTRAINT `fk_cri_return` FOREIGN KEY (`customer_return_id`) REFERENCES `customer_returns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_cri_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_cri_qty` CHECK (`qty` > 0),
    CONSTRAINT `chk_cri_price` CHECK (`unit_price` >= 0),
    CONSTRAINT `chk_cri_subtotal` CHECK (`subtotal` >= 0),
    INDEX `idx_cri_return_id` (`customer_return_id`),
    INDEX `idx_cri_item_id` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 35. credit_notes
CREATE TABLE IF NOT EXISTS `credit_notes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `cn_number` VARCHAR(50) NOT NULL UNIQUE,
    `customer_return_id` INT NULL,
    `customer_id` INT NOT NULL,
    `sales_invoice_id` INT NULL,
    `cn_date` DATE NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `reason` TEXT NULL,
    `status` ENUM('draft','pending_approval','approved','applied','cancelled') NOT NULL DEFAULT 'draft',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_cn_customer_return` FOREIGN KEY (`customer_return_id`) REFERENCES `customer_returns` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_cn_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_cn_sales_invoice` FOREIGN KEY (`sales_invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_cn_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_cn_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_cn_amount` CHECK (`amount` >= 0),
    INDEX `idx_cn_number` (`cn_number`),
    INDEX `idx_cn_customer_id` (`customer_id`),
    INDEX `idx_cn_return_id` (`customer_return_id`),
    INDEX `idx_cn_invoice_id` (`sales_invoice_id`),
    INDEX `idx_cn_date` (`cn_date`),
    INDEX `idx_cn_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- GROUP 5: PRODUCTION
-- ============================================================================

-- 36. production_orders
CREATE TABLE IF NOT EXISTS `production_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `po_number` VARCHAR(50) NOT NULL UNIQUE,
    `product_item_id` INT NOT NULL,
    `bom_id` INT NULL,
    `warehouse_material_id` INT NOT NULL,
    `warehouse_output_id` INT NOT NULL,
    `target_qty` DECIMAL(15,2) NOT NULL,
    `actual_qty` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `scrap_qty` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `planned_start` DATE NOT NULL,
    `planned_end` DATE NOT NULL,
    `actual_start` DATE NULL,
    `actual_end` DATE NULL,
    `notes` TEXT NULL,
    `status` ENUM('draft','pending_approval','approved','waiting_material','in_progress','waiting_qc','rework','completed','rejected','cancelled') NOT NULL DEFAULT 'draft',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_prod_orders_product_item` FOREIGN KEY (`product_item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_prod_orders_bom` FOREIGN KEY (`bom_id`) REFERENCES `boms` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_prod_orders_wh_mat` FOREIGN KEY (`warehouse_material_id`) REFERENCES `warehouses` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_prod_orders_wh_out` FOREIGN KEY (`warehouse_output_id`) REFERENCES `warehouses` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_prod_orders_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_prod_orders_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_prod_orders_target_qty` CHECK (`target_qty` > 0),
    CONSTRAINT `chk_prod_orders_actual_qty` CHECK (`actual_qty` >= 0),
    CONSTRAINT `chk_prod_orders_scrap_qty` CHECK (`scrap_qty` >= 0),
    INDEX `idx_prod_orders_number` (`po_number`),
    INDEX `idx_prod_orders_product_id` (`product_item_id`),
    INDEX `idx_prod_orders_bom_id` (`bom_id`),
    INDEX `idx_prod_orders_wh_mat` (`warehouse_material_id`),
    INDEX `idx_prod_orders_wh_out` (`warehouse_output_id`),
    INDEX `idx_prod_orders_status` (`status`),
    INDEX `idx_prod_orders_planned_start` (`planned_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 37. production_materials
CREATE TABLE IF NOT EXISTS `production_materials` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `production_order_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `qty_planned` DECIMAL(15,4) NOT NULL,
    `qty_actual` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    `unit_id` INT NOT NULL,
    `notes` TEXT NULL,
    CONSTRAINT `fk_pm_production_order` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pm_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_pm_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_pm_qty_planned` CHECK (`qty_planned` > 0),
    CONSTRAINT `chk_pm_qty_actual` CHECK (`qty_actual` >= 0),
    INDEX `idx_pm_production_order_id` (`production_order_id`),
    INDEX `idx_pm_item_id` (`item_id`),
    INDEX `idx_pm_unit_id` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 38. material_issues
CREATE TABLE IF NOT EXISTS `material_issues` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `issue_number` VARCHAR(50) NOT NULL UNIQUE,
    `production_order_id` INT NOT NULL,
    `warehouse_id` INT NOT NULL,
    `issue_date` DATE NOT NULL,
    `notes` TEXT NULL,
    `status` ENUM('draft','issued','cancelled') NOT NULL DEFAULT 'draft',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT NULL,
    CONSTRAINT `fk_mat_issues_po` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_mat_issues_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_mat_issues_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_mat_issues_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_mat_issues_number` (`issue_number`),
    INDEX `idx_mat_issues_po_id` (`production_order_id`),
    INDEX `idx_mat_issues_wh_id` (`warehouse_id`),
    INDEX `idx_mat_issues_date` (`issue_date`),
    INDEX `idx_mat_issues_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 39. material_issue_items
CREATE TABLE IF NOT EXISTS `material_issue_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `material_issue_id` INT NOT NULL,
    `production_material_id` INT NULL,
    `item_id` INT NOT NULL,
    `qty` DECIMAL(15,4) NOT NULL,
    `lot_batch` VARCHAR(50) NULL,
    CONSTRAINT `fk_mii_issue` FOREIGN KEY (`material_issue_id`) REFERENCES `material_issues` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_mii_pm` FOREIGN KEY (`production_material_id`) REFERENCES `production_materials` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_mii_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_mii_qty` CHECK (`qty` > 0),
    INDEX `idx_mii_issue_id` (`material_issue_id`),
    INDEX `idx_mii_pm_id` (`production_material_id`),
    INDEX `idx_mii_item_id` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 40. machine_logs
CREATE TABLE IF NOT EXISTS `machine_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `production_order_id` INT NOT NULL,
    `machine_name` VARCHAR(100) NOT NULL,
    `start_time` DATETIME NOT NULL,
    `end_time` DATETIME NULL,
    `duration_minutes` INT NOT NULL DEFAULT 0,
    `operator` VARCHAR(100) NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT NULL,
    CONSTRAINT `fk_machine_logs_po` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_machine_logs_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_machine_logs_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_machine_logs_duration` CHECK (`duration_minutes` >= 0),
    INDEX `idx_machine_logs_po_id` (`production_order_id`),
    INDEX `idx_machine_logs_machine` (`machine_name`),
    INDEX `idx_machine_logs_start` (`start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 41. qc_inspections
CREATE TABLE IF NOT EXISTS `qc_inspections` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `inspection_number` VARCHAR(50) NOT NULL UNIQUE,
    `ref_type` ENUM('production','goods_receipt') NOT NULL,
    `ref_id` INT NOT NULL,
    `inspection_date` DATE NOT NULL,
    `inspector_id` INT NOT NULL,
    `parameters` TEXT NULL,
    `result` ENUM('pass','fail','conditional','pending') NOT NULL DEFAULT 'pending',
    `findings` TEXT NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_qc_inspector` FOREIGN KEY (`inspector_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_qc_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_qc_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_qc_number` (`inspection_number`),
    INDEX `idx_qc_ref` (`ref_type`, `ref_id`),
    INDEX `idx_qc_result` (`result`),
    INDEX `idx_qc_inspector_id` (`inspector_id`),
    INDEX `idx_qc_date` (`inspection_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 42. production_outputs
CREATE TABLE IF NOT EXISTS `production_outputs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `production_order_id` INT NOT NULL,
    `output_date` DATE NOT NULL,
    `qty_good` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `qty_rework` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `qty_scrap` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `qc_inspection_id` INT NULL,
    `warehouse_id` INT NOT NULL,
    `notes` TEXT NULL,
    `status` ENUM('pending_qc','passed','failed','rework') NOT NULL DEFAULT 'pending_qc',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT NULL,
    CONSTRAINT `fk_prod_outputs_po` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_prod_outputs_qc` FOREIGN KEY (`qc_inspection_id`) REFERENCES `qc_inspections` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_prod_outputs_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_prod_outputs_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_prod_outputs_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_prod_outputs_qty_good` CHECK (`qty_good` >= 0),
    CONSTRAINT `chk_prod_outputs_qty_rework` CHECK (`qty_rework` >= 0),
    CONSTRAINT `chk_prod_outputs_qty_scrap` CHECK (`qty_scrap` >= 0),
    INDEX `idx_prod_outputs_po_id` (`production_order_id`),
    INDEX `idx_prod_outputs_qc_id` (`qc_inspection_id`),
    INDEX `idx_prod_outputs_wh_id` (`warehouse_id`),
    INDEX `idx_prod_outputs_date` (`output_date`),
    INDEX `idx_prod_outputs_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 43. scrap_records
CREATE TABLE IF NOT EXISTS `scrap_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `production_order_id` INT NOT NULL,
    `production_output_id` INT NULL,
    `item_id` INT NOT NULL,
    `qty` DECIMAL(15,2) NOT NULL,
    `reason` TEXT NULL,
    `scrap_date` DATE NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT NULL,
    CONSTRAINT `fk_scrap_records_po` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_scrap_records_output` FOREIGN KEY (`production_output_id`) REFERENCES `production_outputs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_scrap_records_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_scrap_records_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_scrap_records_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_scrap_records_qty` CHECK (`qty` > 0),
    INDEX `idx_scrap_records_po_id` (`production_order_id`),
    INDEX `idx_scrap_records_output_id` (`production_output_id`),
    INDEX `idx_scrap_records_item_id` (`item_id`),
    INDEX `idx_scrap_records_date` (`scrap_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 44. production_costs
CREATE TABLE IF NOT EXISTS `production_costs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `production_order_id` INT NOT NULL,
    `cost_type` ENUM('material','labor','overhead','other') NOT NULL,
    `description` VARCHAR(200) NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT NULL,
    CONSTRAINT `fk_prod_costs_po` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_prod_costs_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_prod_costs_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_prod_costs_amount` CHECK (`amount` >= 0),
    INDEX `idx_prod_costs_po_id` (`production_order_id`),
    INDEX `idx_prod_costs_type` (`cost_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- GROUP 6: PROCUREMENT / EXPENDITURE (PENGELUARAN)
-- ============================================================================

-- 45. purchase_requisitions
CREATE TABLE IF NOT EXISTS `purchase_requisitions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `pr_number` VARCHAR(50) NOT NULL UNIQUE,
    `department_id` INT NULL,
    `requested_by` INT NOT NULL,
    `request_date` DATE NOT NULL,
    `reason` TEXT NULL,
    `budget_id` INT NULL,
    `total_estimated` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `status` ENUM('draft','pending_approval','approved','rejected','revision','ordered','cancelled') NOT NULL DEFAULT 'draft',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_pr_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_pr_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_pr_budget` FOREIGN KEY (`budget_id`) REFERENCES `budgets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_pr_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_pr_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_pr_total_estimated` CHECK (`total_estimated` >= 0),
    INDEX `idx_pr_number` (`pr_number`),
    INDEX `idx_pr_department_id` (`department_id`),
    INDEX `idx_pr_requested_by` (`requested_by`),
    INDEX `idx_pr_budget_id` (`budget_id`),
    INDEX `idx_pr_date` (`request_date`),
    INDEX `idx_pr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 46. purchase_requisition_items
CREATE TABLE IF NOT EXISTS `purchase_requisition_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `purchase_requisition_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `qty` DECIMAL(15,2) NOT NULL,
    `unit_id` INT NOT NULL,
    `estimated_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    CONSTRAINT `fk_pri_pr` FOREIGN KEY (`purchase_requisition_id`) REFERENCES `purchase_requisitions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pri_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_pri_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_pri_qty` CHECK (`qty` > 0),
    CONSTRAINT `chk_pri_price` CHECK (`estimated_price` >= 0),
    CONSTRAINT `chk_pri_subtotal` CHECK (`subtotal` >= 0),
    INDEX `idx_pri_pr_id` (`purchase_requisition_id`),
    INDEX `idx_pri_item_id` (`item_id`),
    INDEX `idx_pri_unit_id` (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 47. purchase_orders
CREATE TABLE IF NOT EXISTS `purchase_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `po_number` VARCHAR(50) NOT NULL UNIQUE,
    `purchase_requisition_id` INT NULL,
    `supplier_id` INT NOT NULL,
    `po_date` DATE NOT NULL,
    `expected_delivery` DATE NULL,
    `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `status` ENUM('draft','pending_approval','approved','partially_received','received','cancelled') NOT NULL DEFAULT 'draft',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_po_pr` FOREIGN KEY (`purchase_requisition_id`) REFERENCES `purchase_requisitions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_po_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_po_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_po_total_amount` CHECK (`total_amount` >= 0),
    INDEX `idx_purchase_orders_number` (`po_number`),
    INDEX `idx_purchase_orders_pr_id` (`purchase_requisition_id`),
    INDEX `idx_purchase_orders_supplier_id` (`supplier_id`),
    INDEX `idx_purchase_orders_date` (`po_date`),
    INDEX `idx_purchase_orders_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 48. purchase_order_items
CREATE TABLE IF NOT EXISTS `purchase_order_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `purchase_order_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `qty_ordered` DECIMAL(15,2) NOT NULL,
    `qty_received` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    CONSTRAINT `fk_poi_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_poi_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_poi_qty_ordered` CHECK (`qty_ordered` > 0),
    CONSTRAINT `chk_poi_qty_received` CHECK (`qty_received` >= 0),
    CONSTRAINT `chk_poi_price` CHECK (`unit_price` >= 0),
    CONSTRAINT `chk_poi_subtotal` CHECK (`subtotal` >= 0),
    INDEX `idx_poi_po_id` (`purchase_order_id`),
    INDEX `idx_poi_item_id` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 49. goods_receipts
CREATE TABLE IF NOT EXISTS `goods_receipts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `gr_number` VARCHAR(50) NOT NULL UNIQUE,
    `purchase_order_id` INT NOT NULL,
    `supplier_id` INT NOT NULL,
    `warehouse_id` INT NOT NULL,
    `receipt_date` DATE NOT NULL,
    `do_supplier` VARCHAR(100) NULL,
    `notes` TEXT NULL,
    `status` ENUM('draft','inspecting','accepted','partially_accepted','rejected','cancelled') NOT NULL DEFAULT 'draft',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_gr_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_gr_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_gr_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_gr_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_gr_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_goods_receipts_number` (`gr_number`),
    INDEX `idx_goods_receipts_po_id` (`purchase_order_id`),
    INDEX `idx_goods_receipts_supplier_id` (`supplier_id`),
    INDEX `idx_goods_receipts_wh_id` (`warehouse_id`),
    INDEX `idx_goods_receipts_date` (`receipt_date`),
    INDEX `idx_goods_receipts_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 50. goods_receipt_items
CREATE TABLE IF NOT EXISTS `goods_receipt_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `goods_receipt_id` INT NOT NULL,
    `purchase_order_item_id` INT NULL,
    `item_id` INT NOT NULL,
    `qty_received` DECIMAL(15,2) NOT NULL,
    `qty_accepted` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `qty_rejected` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `qc_inspection_id` INT NULL,
    `notes` TEXT NULL,
    CONSTRAINT `fk_gri_gr` FOREIGN KEY (`goods_receipt_id`) REFERENCES `goods_receipts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_gri_poi` FOREIGN KEY (`purchase_order_item_id`) REFERENCES `purchase_order_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_gri_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_gri_qc` FOREIGN KEY (`qc_inspection_id`) REFERENCES `qc_inspections` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_gri_qty_received` CHECK (`qty_received` > 0),
    CONSTRAINT `chk_gri_qty_accepted` CHECK (`qty_accepted` >= 0),
    CONSTRAINT `chk_gri_qty_rejected` CHECK (`qty_rejected` >= 0),
    INDEX `idx_gri_gr_id` (`goods_receipt_id`),
    INDEX `idx_gri_poi_id` (`purchase_order_item_id`),
    INDEX `idx_gri_item_id` (`item_id`),
    INDEX `idx_gri_qc_id` (`qc_inspection_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 51. supplier_returns
CREATE TABLE IF NOT EXISTS `supplier_returns` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `return_number` VARCHAR(50) NOT NULL UNIQUE,
    `goods_receipt_id` INT NOT NULL,
    `supplier_id` INT NOT NULL,
    `return_date` DATE NOT NULL,
    `reason` TEXT NULL,
    `status` ENUM('draft','pending','processed','cancelled') NOT NULL DEFAULT 'draft',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_supp_returns_gr` FOREIGN KEY (`goods_receipt_id`) REFERENCES `goods_receipts` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_supp_returns_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_supp_returns_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_supp_returns_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_supp_returns_number` (`return_number`),
    INDEX `idx_supp_returns_gr_id` (`goods_receipt_id`),
    INDEX `idx_supp_returns_supplier_id` (`supplier_id`),
    INDEX `idx_supp_returns_date` (`return_date`),
    INDEX `idx_supp_returns_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 52. supplier_return_items
CREATE TABLE IF NOT EXISTS `supplier_return_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `supplier_return_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `qty` DECIMAL(15,2) NOT NULL,
    `notes` TEXT NULL,
    CONSTRAINT `fk_sri_return` FOREIGN KEY (`supplier_return_id`) REFERENCES `supplier_returns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sri_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_sri_qty` CHECK (`qty` > 0),
    INDEX `idx_sri_return_id` (`supplier_return_id`),
    INDEX `idx_sri_item_id` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 53. supplier_invoices
CREATE TABLE IF NOT EXISTS `supplier_invoices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
    `supplier_id` INT NOT NULL,
    `purchase_order_id` INT NULL,
    `goods_receipt_id` INT NULL,
    `supplier_invoice_number` VARCHAR(100) NOT NULL,
    `invoice_date` DATE NOT NULL,
    `due_date` DATE NOT NULL,
    `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `paid_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `tax_invoice_number` VARCHAR(50) NULL,
    `notes` TEXT NULL,
    `status` ENUM('draft','verified','matched','partially_paid','paid','disputed','cancelled') NOT NULL DEFAULT 'draft',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_supp_inv_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_supp_inv_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_supp_inv_gr` FOREIGN KEY (`goods_receipt_id`) REFERENCES `goods_receipts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_supp_inv_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_supp_inv_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_supp_inv_subtotal` CHECK (`subtotal` >= 0),
    CONSTRAINT `chk_supp_inv_tax` CHECK (`tax_amount` >= 0),
    CONSTRAINT `chk_supp_inv_total` CHECK (`total_amount` >= 0),
    CONSTRAINT `chk_supp_inv_paid` CHECK (`paid_amount` >= 0),
    INDEX `idx_supp_inv_number` (`invoice_number`),
    INDEX `idx_supp_inv_supplier_id` (`supplier_id`),
    INDEX `idx_supp_inv_po_id` (`purchase_order_id`),
    INDEX `idx_supp_inv_gr_id` (`goods_receipt_id`),
    INDEX `idx_supp_inv_date` (`invoice_date`),
    INDEX `idx_supp_inv_due_date` (`due_date`),
    INDEX `idx_supp_inv_status` (`status`),
    INDEX `idx_supp_inv_supp_inv_num` (`supplier_invoice_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 54. three_way_matches
CREATE TABLE IF NOT EXISTS `three_way_matches` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `supplier_invoice_id` INT NOT NULL,
    `purchase_order_id` INT NOT NULL,
    `goods_receipt_id` INT NOT NULL,
    `match_status` ENUM('matched','qty_discrepancy','price_discrepancy','both_discrepancy','pending') NOT NULL DEFAULT 'pending',
    `qty_diff` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `value_diff` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `verified_by` INT NULL,
    `verified_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_twm_invoice` FOREIGN KEY (`supplier_invoice_id`) REFERENCES `supplier_invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_twm_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_twm_gr` FOREIGN KEY (`goods_receipt_id`) REFERENCES `goods_receipts` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_twm_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_twm_invoice_id` (`supplier_invoice_id`),
    INDEX `idx_twm_po_id` (`purchase_order_id`),
    INDEX `idx_twm_gr_id` (`goods_receipt_id`),
    INDEX `idx_twm_status` (`match_status`),
    INDEX `idx_twm_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 55. ap_vouchers
CREATE TABLE IF NOT EXISTS `ap_vouchers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `voucher_number` VARCHAR(50) NOT NULL UNIQUE,
    `supplier_invoice_id` INT NOT NULL,
    `supplier_id` INT NOT NULL,
    `voucher_date` DATE NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `status` ENUM('draft','verified','approved','paid','cancelled') NOT NULL DEFAULT 'draft',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_apv_invoice` FOREIGN KEY (`supplier_invoice_id`) REFERENCES `supplier_invoices` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_apv_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_apv_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_apv_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_apv_amount` CHECK (`amount` >= 0),
    INDEX `idx_apv_number` (`voucher_number`),
    INDEX `idx_apv_invoice_id` (`supplier_invoice_id`),
    INDEX `idx_apv_supplier_id` (`supplier_id`),
    INDEX `idx_apv_date` (`voucher_date`),
    INDEX `idx_apv_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 56. payment_requests
CREATE TABLE IF NOT EXISTS `payment_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `request_number` VARCHAR(50) NOT NULL UNIQUE,
    `ap_voucher_id` INT NULL,
    `supplier_id` INT NOT NULL,
    `request_date` DATE NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `reason` TEXT NULL,
    `notes` TEXT NULL,
    `status` ENUM('draft','pending_approval','approved','rejected','paid','cancelled') NOT NULL DEFAULT 'draft',
    `requested_by` INT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_prq_voucher` FOREIGN KEY (`ap_voucher_id`) REFERENCES `ap_vouchers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_prq_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_prq_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_prq_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_prq_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_prq_amount` CHECK (`amount` >= 0),
    INDEX `idx_prq_number` (`request_number`),
    INDEX `idx_prq_voucher_id` (`ap_voucher_id`),
    INDEX `idx_prq_supplier_id` (`supplier_id`),
    INDEX `idx_prq_date` (`request_date`),
    INDEX `idx_prq_status` (`status`),
    INDEX `idx_prq_requested_by` (`requested_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 57. supplier_payments
CREATE TABLE IF NOT EXISTS `supplier_payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `payment_number` VARCHAR(50) NOT NULL UNIQUE,
    `payment_request_id` INT NULL,
    `supplier_id` INT NOT NULL,
    `payment_date` DATE NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `payment_method` ENUM('transfer','cash','giro','cheque','other') NOT NULL DEFAULT 'transfer',
    `bank_ref` VARCHAR(100) NULL,
    `bank_name` VARCHAR(100) NULL,
    `notes` TEXT NULL,
    `status` ENUM('draft','verified','posted','cancelled') NOT NULL DEFAULT 'draft',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_spay_request` FOREIGN KEY (`payment_request_id`) REFERENCES `payment_requests` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_spay_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_spay_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_spay_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_spay_amount` CHECK (`amount` >= 0),
    INDEX `idx_spay_number` (`payment_number`),
    INDEX `idx_spay_request_id` (`payment_request_id`),
    INDEX `idx_spay_supplier_id` (`supplier_id`),
    INDEX `idx_spay_date` (`payment_date`),
    INDEX `idx_spay_status` (`status`),
    INDEX `idx_spay_method` (`payment_method`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 58. supplier_payment_allocations
CREATE TABLE IF NOT EXISTS `supplier_payment_allocations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `supplier_payment_id` INT NOT NULL,
    `supplier_invoice_id` INT NOT NULL,
    `allocated_amount` DECIMAL(15,2) NOT NULL,
    CONSTRAINT `fk_spa_payment` FOREIGN KEY (`supplier_payment_id`) REFERENCES `supplier_payments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_spa_invoice` FOREIGN KEY (`supplier_invoice_id`) REFERENCES `supplier_invoices` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_spa_amount` CHECK (`allocated_amount` > 0),
    INDEX `idx_spa_payment_id` (`supplier_payment_id`),
    INDEX `idx_spa_invoice_id` (`supplier_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- GROUP 7: HR AND PAYROLL
-- ============================================================================

-- 59. attendance_records
CREATE TABLE IF NOT EXISTS `attendance_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `attendance_date` DATE NOT NULL,
    `check_in` TIME NULL,
    `check_out` TIME NULL,
    `status` ENUM('hadir','terlambat','izin','sakit','alpha','cuti','libur') NOT NULL DEFAULT 'hadir',
    `overtime_hours` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_att_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_att_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_att_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_att_overtime_hours` CHECK (`overtime_hours` >= 0),
    INDEX `idx_att_emp_id` (`employee_id`),
    INDEX `idx_att_date` (`attendance_date`),
    INDEX `idx_att_status` (`status`),
    INDEX `idx_att_emp_date` (`employee_id`, `attendance_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 60. leave_overtime_requests
CREATE TABLE IF NOT EXISTS `leave_overtime_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `request_number` VARCHAR(50) NOT NULL UNIQUE,
    `employee_id` INT NOT NULL,
    `request_type` ENUM('cuti','lembur') NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `hours` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `reason` TEXT NULL,
    `status` ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft',
    `decided_by` INT NULL,
    `decided_at` DATETIME NULL,
    `decision_notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_lot_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_lot_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_lot_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_lot_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_lot_hours` CHECK (`hours` >= 0),
    INDEX `idx_lot_number` (`request_number`),
    INDEX `idx_lot_emp_id` (`employee_id`),
    INDEX `idx_lot_type` (`request_type`),
    INDEX `idx_lot_status` (`status`),
    INDEX `idx_lot_date` (`start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 61. employee_change_requests
CREATE TABLE IF NOT EXISTS `employee_change_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `change_type` ENUM('jabatan','bagian','gaji','mutasi','resign','lainnya') NOT NULL,
    `old_value` TEXT NULL,
    `new_value` TEXT NULL,
    `reason` TEXT NULL,
    `supporting_doc` VARCHAR(255) NULL,
    `status` ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft',
    `decided_by` INT NULL,
    `decided_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_ecr_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_ecr_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_ecr_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_ecr_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_ecr_emp_id` (`employee_id`),
    INDEX `idx_ecr_type` (`change_type`),
    INDEX `idx_ecr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 62. payroll_components
CREATE TABLE IF NOT EXISTS `payroll_components` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `type` ENUM('penghasilan','potongan') NOT NULL,
    `calculation_type` ENUM('fixed','percentage','formula') NOT NULL DEFAULT 'fixed',
    `default_value` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `percentage_base` VARCHAR(50) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_pay_comp_code` (`code`),
    INDEX `idx_pay_comp_type` (`type`),
    INDEX `idx_pay_comp_is_active` (`is_active`),
    INDEX `idx_pay_comp_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 63. payroll_periods
CREATE TABLE IF NOT EXISTS `payroll_periods` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `period_code` VARCHAR(50) NOT NULL UNIQUE,
    `period_month` INT NOT NULL,
    `period_year` INT NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `status` ENUM('draft','calculating','review','pending_finance','approved','transferred','closed') NOT NULL DEFAULT 'draft',
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_payroll_periods_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_payroll_periods_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_payroll_periods_code` (`period_code`),
    INDEX `idx_payroll_periods_my` (`period_year`, `period_month`),
    INDEX `idx_payroll_periods_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 64. payroll_records
CREATE TABLE IF NOT EXISTS `payroll_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `payroll_period_id` INT NOT NULL,
    `employee_id` INT NOT NULL,
    `base_salary` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `total_earnings` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `total_deductions` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `net_salary` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('draft','calculated','verified','approved','transferred') NOT NULL DEFAULT 'draft',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `uq_payroll_period_employee` UNIQUE (`payroll_period_id`, `employee_id`),
    CONSTRAINT `fk_pr_records_period` FOREIGN KEY (`payroll_period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pr_records_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_pr_records_base_salary` CHECK (`base_salary` >= 0),
    CONSTRAINT `chk_pr_records_earnings` CHECK (`total_earnings` >= 0),
    CONSTRAINT `chk_pr_records_deductions` CHECK (`total_deductions` >= 0),
    INDEX `idx_pr_records_period_id` (`payroll_period_id`),
    INDEX `idx_pr_records_employee_id` (`employee_id`),
    INDEX `idx_pr_records_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 65. payroll_record_components
CREATE TABLE IF NOT EXISTS `payroll_record_components` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `payroll_record_id` INT NOT NULL,
    `payroll_component_id` INT NOT NULL,
    `component_type` ENUM('penghasilan','potongan') NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `notes` TEXT NULL,
    CONSTRAINT `fk_prc_record` FOREIGN KEY (`payroll_record_id`) REFERENCES `payroll_records` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_prc_component` FOREIGN KEY (`payroll_component_id`) REFERENCES `payroll_components` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_prc_amount` CHECK (`amount` >= 0),
    INDEX `idx_prc_record_id` (`payroll_record_id`),
    INDEX `idx_prc_component_id` (`payroll_component_id`),
    INDEX `idx_prc_type` (`component_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 66. payroll_approvals
CREATE TABLE IF NOT EXISTS `payroll_approvals` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `payroll_period_id` INT NOT NULL,
    `approval_type` ENUM('hr_review','finance_approval','transfer_confirmation') NOT NULL,
    `status` ENUM('pending','approved','rejected','revision') NOT NULL DEFAULT 'pending',
    `decided_by` INT NULL,
    `decided_at` DATETIME NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_pa_period` FOREIGN KEY (`payroll_period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_pa_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_pa_period_id` (`payroll_period_id`),
    INDEX `idx_pa_type` (`approval_type`),
    INDEX `idx_pa_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 67. payslips
CREATE TABLE IF NOT EXISTS `payslips` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `payroll_record_id` INT NOT NULL,
    `employee_id` INT NOT NULL,
    `payroll_period_id` INT NOT NULL,
    `slip_number` VARCHAR(50) NOT NULL UNIQUE,
    `generated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    CONSTRAINT `fk_payslips_record` FOREIGN KEY (`payroll_record_id`) REFERENCES `payroll_records` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_payslips_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_payslips_period` FOREIGN KEY (`payroll_period_id`) REFERENCES `payroll_periods` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_payslips_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_payslips_number` (`slip_number`),
    INDEX `idx_payslips_record_id` (`payroll_record_id`),
    INDEX `idx_payslips_employee_id` (`employee_id`),
    INDEX `idx_payslips_period_id` (`payroll_period_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- GROUP 8: JOURNAL AND ACCOUNTING
-- ============================================================================

-- 68. accounts
CREATE TABLE IF NOT EXISTS `accounts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(150) NOT NULL,
    `type` ENUM('asset','liability','equity','revenue','expense') NOT NULL,
    `parent_id` INT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_accounts_parent` FOREIGN KEY (`parent_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_accounts_code` (`code`),
    INDEX `idx_accounts_type` (`type`),
    INDEX `idx_accounts_parent_id` (`parent_id`),
    INDEX `idx_accounts_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 69. account_mappings
CREATE TABLE IF NOT EXISTS `account_mappings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `transaction_type` VARCHAR(50) NOT NULL,
    `debit_account_id` INT NOT NULL,
    `credit_account_id` INT NOT NULL,
    `description` VARCHAR(255) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_am_debit_acc` FOREIGN KEY (`debit_account_id`) REFERENCES `accounts` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_am_credit_acc` FOREIGN KEY (`credit_account_id`) REFERENCES `accounts` (`id`) ON UPDATE CASCADE,
    INDEX `idx_am_transaction_type` (`transaction_type`),
    INDEX `idx_am_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 70. journal_entries
CREATE TABLE IF NOT EXISTS `journal_entries` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `entry_number` VARCHAR(50) NOT NULL UNIQUE,
    `entry_date` DATE NOT NULL,
    `ref_type` VARCHAR(50) NULL,
    `ref_id` INT NULL,
    `ref_number` VARCHAR(50) NULL,
    `description` TEXT NULL,
    `total_debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `total_credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft',
    `posted_by` INT NULL,
    `posted_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    CONSTRAINT `fk_je_posted_by` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_je_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_je_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `chk_je_total_debit` CHECK (`total_debit` >= 0),
    CONSTRAINT `chk_je_total_credit` CHECK (`total_credit` >= 0),
    INDEX `idx_journal_entries_number` (`entry_number`),
    INDEX `idx_journal_entries_date` (`entry_date`),
    INDEX `idx_journal_entries_ref` (`ref_type`, `ref_id`),
    INDEX `idx_journal_entries_ref_number` (`ref_number`),
    INDEX `idx_journal_entries_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 71. journal_entry_lines
CREATE TABLE IF NOT EXISTS `journal_entry_lines` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `journal_entry_id` INT NOT NULL,
    `account_id` INT NOT NULL,
    `description` VARCHAR(255) NULL,
    `debit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `credit` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT `fk_jel_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_jel_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `chk_jel_debit` CHECK (`debit` >= 0),
    CONSTRAINT `chk_jel_credit` CHECK (`credit` >= 0),
    INDEX `idx_jel_entry_id` (`journal_entry_id`),
    INDEX `idx_jel_account_id` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- GROUP 9: CSRF TOKENS
-- ============================================================================

-- 72. csrf_tokens
CREATE TABLE IF NOT EXISTS `csrf_tokens` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `token` VARCHAR(64) NOT NULL UNIQUE,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME NOT NULL,
    CONSTRAINT `fk_csrf_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_csrf_user_id` (`user_id`),
    INDEX `idx_csrf_token` (`token`),
    INDEX `idx_csrf_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
