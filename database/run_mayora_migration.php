<?php
/**
 * Database Migration Script: Mayora SIA Enhancements
 * Adds tables for Manufacturing Operations, Quality Standards, Machines, 
 * Notifications, Batches, and Expenditure/Procurement enhancements.
 * Safe & Idempotent.
 */

require_once __DIR__ . '/../app/config/database.php';
$pdo = getDbConnection();

$queries = [
    // 1. machines table
    "CREATE TABLE IF NOT EXISTS `machines` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `code` VARCHAR(50) NOT NULL UNIQUE,
        `name` VARCHAR(150) NOT NULL,
        `type` VARCHAR(100) NOT NULL,
        `location` VARCHAR(100) NULL,
        `capacity_per_hour` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `unit_capacity` VARCHAR(50) NOT NULL DEFAULT 'kg/jam',
        `hourly_rate` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `status` ENUM('active','maintenance','idle','offline') NOT NULL DEFAULT 'active',
        `notes` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_machines_code` (`code`),
        INDEX `idx_machines_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 2. operations_lists (Standard operations routing for products)
    "CREATE TABLE IF NOT EXISTS `operations_lists` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `product_item_id` INT NOT NULL,
        `bom_id` INT NULL,
        `sequence` INT NOT NULL DEFAULT 1,
        `operation_name` VARCHAR(150) NOT NULL,
        `machine_id` INT NULL,
        `standard_time_minutes` INT NOT NULL DEFAULT 60,
        `operator_needed` INT NOT NULL DEFAULT 1,
        `instructions` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT `fk_op_product_item` FOREIGN KEY (`product_item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_op_bom` FOREIGN KEY (`bom_id`) REFERENCES `boms` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT `fk_op_machine` FOREIGN KEY (`machine_id`) REFERENCES `machines` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
        INDEX `idx_op_product` (`product_item_id`),
        INDEX `idx_op_sequence` (`sequence`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 3. production_schedules (MPS / Jadwal Produksi)
    "CREATE TABLE IF NOT EXISTS `production_schedules` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `schedule_number` VARCHAR(50) NOT NULL UNIQUE,
        `product_item_id` INT NOT NULL,
        `period_year` INT NOT NULL,
        `period_month` INT NOT NULL,
        `planned_week` INT NOT NULL DEFAULT 1,
        `planned_qty` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `scheduled_start` DATE NOT NULL,
        `scheduled_end` DATE NOT NULL,
        `status` ENUM('draft','submitted','approved','in_production','completed','cancelled') NOT NULL DEFAULT 'draft',
        `approved_by` INT NULL,
        `approved_at` DATETIME NULL,
        `notes` TEXT NULL,
        `created_by` INT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT `fk_ps_product` FOREIGN KEY (`product_item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_ps_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT `fk_ps_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
        INDEX `idx_ps_number` (`schedule_number`),
        INDEX `idx_ps_product` (`product_item_id`),
        INDEX `idx_ps_period` (`period_year`, `period_month`),
        INDEX `idx_ps_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 4. quality_standards (Standar Kualitas F&B)
    "CREATE TABLE IF NOT EXISTS `quality_standards` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `item_id` INT NOT NULL,
        `inspection_stage` ENUM('incoming','in_process','final') NOT NULL,
        `parameter_name` VARCHAR(150) NOT NULL,
        `target_value` VARCHAR(100) NOT NULL,
        `tolerance` VARCHAR(100) NULL,
        `method` VARCHAR(150) NULL,
        `is_mandatory` TINYINT(1) NOT NULL DEFAULT 1,
        `notes` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT `fk_qs_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
        INDEX `idx_qs_item` (`item_id`),
        INDEX `idx_qs_stage` (`inspection_stage`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 5. product_batches (Batch tracking F&B for Raw Materials and Finished Goods)
    "CREATE TABLE IF NOT EXISTS `product_batches` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `batch_number` VARCHAR(100) NOT NULL UNIQUE,
        `item_id` INT NOT NULL,
        `warehouse_id` INT NOT NULL,
        `supplier_id` INT NULL,
        `production_order_id` INT NULL,
        `goods_receipt_id` INT NULL,
        `mfg_date` DATE NOT NULL,
        `expiry_date` DATE NOT NULL,
        `initial_qty` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `current_qty` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `status` ENUM('quarantine','passed','failed','rework','scrap','expired') NOT NULL DEFAULT 'quarantine',
        `qc_inspection_id` INT NULL,
        `notes` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT `fk_pb_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_pb_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_pb_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT `fk_pb_po` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT `fk_pb_gr` FOREIGN KEY (`goods_receipt_id`) REFERENCES `goods_receipts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT `fk_pb_qc` FOREIGN KEY (`qc_inspection_id`) REFERENCES `qc_inspections` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
        INDEX `idx_pb_number` (`batch_number`),
        INDEX `idx_pb_item` (`item_id`),
        INDEX `idx_pb_expiry` (`expiry_date`),
        INDEX `idx_pb_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 6. material_requisitions (Permintaan bahan baku dari PPIC ke Gudang untuk SPK)
    "CREATE TABLE IF NOT EXISTS `material_requisitions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `requisition_number` VARCHAR(50) NOT NULL UNIQUE,
        `production_order_id` INT NOT NULL,
        `warehouse_id` INT NOT NULL,
        `request_date` DATE NOT NULL,
        `status` ENUM('draft','submitted','approved','issued','rejected','cancelled') NOT NULL DEFAULT 'draft',
        `notes` TEXT NULL,
        `requested_by` INT NOT NULL,
        `approved_by` INT NULL,
        `approved_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT `fk_mr_po` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_mr_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_mr_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_mr_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
        INDEX `idx_mr_number` (`requisition_number`),
        INDEX `idx_mr_po` (`production_order_id`),
        INDEX `idx_mr_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 7. material_requisition_items
    "CREATE TABLE IF NOT EXISTS `material_requisition_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `material_requisition_id` INT NOT NULL,
        `item_id` INT NOT NULL,
        `unit_id` INT NOT NULL,
        `qty_requested` DECIMAL(15,4) NOT NULL,
        `qty_issued` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
        `notes` TEXT NULL,
        CONSTRAINT `fk_mri_mr` FOREIGN KEY (`material_requisition_id`) REFERENCES `material_requisitions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_mri_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_mri_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON UPDATE CASCADE,
        INDEX `idx_mri_mr` (`material_requisition_id`),
        INDEX `idx_mri_item` (`item_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 8. material_returns (Pengembalian sisa bahan dari Produksi ke Gudang)
    "CREATE TABLE IF NOT EXISTS `material_returns` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `return_number` VARCHAR(50) NOT NULL UNIQUE,
        `production_order_id` INT NOT NULL,
        `warehouse_id` INT NOT NULL,
        `return_date` DATE NOT NULL,
        `reason` TEXT NULL,
        `status` ENUM('draft','submitted','accepted','rejected') NOT NULL DEFAULT 'draft',
        `returned_by` INT NOT NULL,
        `received_by` INT NULL,
        `received_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT `fk_mret_po` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_mret_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_mret_returned_by` FOREIGN KEY (`returned_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_mret_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
        INDEX `idx_mret_number` (`return_number`),
        INDEX `idx_mret_po` (`production_order_id`),
        INDEX `idx_mret_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 9. material_return_items
    "CREATE TABLE IF NOT EXISTS `material_return_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `material_return_id` INT NOT NULL,
        `item_id` INT NOT NULL,
        `unit_id` INT NOT NULL,
        `qty_returned` DECIMAL(15,4) NOT NULL,
        `batch_number` VARCHAR(100) NULL,
        `condition_notes` TEXT NULL,
        CONSTRAINT `fk_mreti_mr` FOREIGN KEY (`material_return_id`) REFERENCES `material_returns` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_mreti_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_mreti_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON UPDATE CASCADE,
        INDEX `idx_mreti_mr` (`material_return_id`),
        INDEX `idx_mreti_item` (`item_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 10. move_tickets (Perpindahan barang antar proses/stasiun kerja)
    "CREATE TABLE IF NOT EXISTS `move_tickets` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `ticket_number` VARCHAR(50) NOT NULL UNIQUE,
        `production_order_id` INT NOT NULL,
        `from_station` VARCHAR(100) NOT NULL,
        `to_station` VARCHAR(100) NOT NULL,
        `move_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `qty_moved` DECIMAL(15,2) NOT NULL,
        `status` ENUM('in_transit','received','rejected') NOT NULL DEFAULT 'received',
        `transferred_by` INT NOT NULL,
        `received_by` INT NULL,
        `notes` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT `fk_mt_po` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_mt_transferred_by` FOREIGN KEY (`transferred_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_mt_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
        INDEX `idx_mt_number` (`ticket_number`),
        INDEX `idx_mt_po` (`production_order_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 11. job_time_tickets (Pencatatan jam kerja operator pada SPK produksi)
    "CREATE TABLE IF NOT EXISTS `job_time_tickets` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `ticket_number` VARCHAR(50) NOT NULL UNIQUE,
        `production_order_id` INT NOT NULL,
        `employee_id` INT NOT NULL,
        `operation_id` INT NULL,
        `work_date` DATE NOT NULL,
        `start_time` TIME NOT NULL,
        `end_time` TIME NOT NULL,
        `hours_worked` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        `hourly_rate` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `labor_cost` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `status` ENUM('draft','submitted','verified','approved') NOT NULL DEFAULT 'approved',
        `verified_by` INT NULL,
        `notes` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT `fk_jtt_po` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_jtt_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_jtt_op` FOREIGN KEY (`operation_id`) REFERENCES `operations_lists` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT `fk_jtt_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
        INDEX `idx_jtt_number` (`ticket_number`),
        INDEX `idx_jtt_po` (`production_order_id`),
        INDEX `idx_jtt_emp` (`employee_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 12. debit_memos (Nota Debit untuk potongan tagihan supplier atas barang retur/reject)
    "CREATE TABLE IF NOT EXISTS `debit_memos` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `memo_number` VARCHAR(50) NOT NULL UNIQUE,
        `supplier_id` INT NOT NULL,
        `supplier_return_id` INT NULL,
        `supplier_invoice_id` INT NULL,
        `memo_date` DATE NOT NULL,
        `amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `reason` TEXT NULL,
        `status` ENUM('draft','approved','applied','cancelled') NOT NULL DEFAULT 'draft',
        `approved_by` INT NULL,
        `approved_at` DATETIME NULL,
        `created_by` INT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT `fk_dm_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_dm_return` FOREIGN KEY (`supplier_return_id`) REFERENCES `supplier_returns` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT `fk_dm_invoice` FOREIGN KEY (`supplier_invoice_id`) REFERENCES `supplier_invoices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT `fk_dm_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT `fk_dm_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
        INDEX `idx_dm_number` (`memo_number`),
        INDEX `idx_dm_supplier` (`supplier_id`),
        INDEX `idx_dm_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 13. notifications (Sistem notifikasi terpusat untuk 9 role)
    "CREATE TABLE IF NOT EXISTS `notifications` (
        `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT NULL,
        `role_code` VARCHAR(50) NULL,
        `title` VARCHAR(150) NOT NULL,
        `message` TEXT NOT NULL,
        `type` ENUM('info','warning','success','danger') NOT NULL DEFAULT 'info',
        `category` VARCHAR(50) NOT NULL DEFAULT 'system',
        `link` VARCHAR(255) NULL,
        `is_read` TINYINT(1) NOT NULL DEFAULT 0,
        `read_at` DATETIME NULL,
        `ref_type` VARCHAR(50) NULL,
        `ref_id` INT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
        INDEX `idx_notif_user` (`user_id`),
        INDEX `idx_notif_role` (`role_code`),
        INDEX `idx_notif_read` (`is_read`),
        INDEX `idx_notif_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];

echo "Running migrations..." . PHP_EOL;
foreach ($queries as $idx => $sql) {
    try {
        $pdo->exec($sql);
        echo "Query " . ($idx + 1) . " executed successfully." . PHP_EOL;
    } catch (PDOException $e) {
        echo "Query " . ($idx + 1) . " error: " . $e->getMessage() . PHP_EOL;
    }
}

// Ensure additional columns on existing tables if not present
$alterations = [
    // Add columns to boms for approval and target costing
    "ALTER TABLE `boms` ADD COLUMN IF NOT EXISTS `target_cost` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `version`",
    "ALTER TABLE `boms` ADD COLUMN IF NOT EXISTS `effective_date` DATE NULL AFTER `target_cost`",
    "ALTER TABLE `boms` ADD COLUMN IF NOT EXISTS `approval_status` ENUM('draft','pending_approval','approved','rejected') NOT NULL DEFAULT 'approved' AFTER `effective_date`",
    "ALTER TABLE `boms` ADD COLUMN IF NOT EXISTS `approved_by` INT NULL AFTER `approval_status`",

    // Add shrinkage rate to bom_items
    "ALTER TABLE `bom_items` ADD COLUMN IF NOT EXISTS `shrinkage_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `qty_per_unit`",

    // Add machine_id to machine_logs
    "ALTER TABLE `machine_logs` ADD COLUMN IF NOT EXISTS `machine_id` INT NULL AFTER `production_order_id`",

    // Add batch number and expiry to goods_receipt_items
    "ALTER TABLE `goods_receipt_items` ADD COLUMN IF NOT EXISTS `batch_number` VARCHAR(100) NULL AFTER `item_id`",
    "ALTER TABLE `goods_receipt_items` ADD COLUMN IF NOT EXISTS `mfg_date` DATE NULL AFTER `batch_number`",
    "ALTER TABLE `goods_receipt_items` ADD COLUMN IF NOT EXISTS `expiry_date` DATE NULL AFTER `mfg_date`",

    // Add batch number to production_outputs
    "ALTER TABLE `production_outputs` ADD COLUMN IF NOT EXISTS `batch_number` VARCHAR(100) NULL AFTER `production_order_id`",
    "ALTER TABLE `production_outputs` ADD COLUMN IF NOT EXISTS `expiry_date` DATE NULL AFTER `batch_number`",

    // Add batch number to stock_movements
    "ALTER TABLE `stock_movements` ADD COLUMN IF NOT EXISTS `batch_number` VARCHAR(100) NULL AFTER `ref_number`",

    // Add discrepancy details to supplier_invoices
    "ALTER TABLE `supplier_invoices` ADD COLUMN IF NOT EXISTS `discrepancy_reason` TEXT NULL AFTER `status`",

    // Add blind_po_received to goods_receipts
    "ALTER TABLE `goods_receipts` ADD COLUMN IF NOT EXISTS `is_blind_receipt` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`"
];

echo PHP_EOL . "Applying column enhancements..." . PHP_EOL;
foreach ($alterations as $alterSql) {
    try {
        $pdo->exec($alterSql);
        echo "Alteration executed: " . substr($alterSql, 0, 45) . "..." . PHP_EOL;
    } catch (PDOException $e) {
        // Some MySQL versions don't support 'ADD COLUMN IF NOT EXISTS', let's handle if duplicate column
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "Column already exists, skipped." . PHP_EOL;
        } else {
            echo "Alter error: " . $e->getMessage() . PHP_EOL;
        }
    }
}

echo PHP_EOL . "Migration complete!" . PHP_EOL;
