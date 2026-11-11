-- ==============================================================================
-- MIGRATION: Master Vendor (Suppliers) & Customer Enhancement
-- PT Mayora Indah Tbk. - SIA
-- Safe & Idempotent Migration
-- ==============================================================================

-- 1. Tambah kolom NPWP dan Informasi Rekening Bank pada tabel suppliers
ALTER TABLE `suppliers`
    ADD COLUMN IF NOT EXISTS `npwp` VARCHAR(50) NULL AFTER `email`,
    ADD COLUMN IF NOT EXISTS `bank_name` VARCHAR(100) NULL AFTER `payment_terms_days`,
    ADD COLUMN IF NOT EXISTS `bank_account` VARCHAR(100) NULL AFTER `bank_name`,
    ADD COLUMN IF NOT EXISTS `bank_account_holder` VARCHAR(150) NULL AFTER `bank_account`;

-- 2. Tambah kolom NPWP pada tabel customers jika belum ada
ALTER TABLE `customers`
    ADD COLUMN IF NOT EXISTS `npwp` VARCHAR(50) NULL AFTER `email`;

-- 3. Pastikan index untuk pencarian suppliers
ALTER TABLE `suppliers`
    ADD INDEX IF NOT EXISTS `idx_supp_contact` (`contact_person`),
    ADD INDEX IF NOT EXISTS `idx_supp_phone` (`phone`);
