-- ============================================================================
-- MIGRATION: INTEGRASI MODUL SDM & PAYROLL PT MAYORA INDAH TBK
-- Menambahkan tabel Pelatihan, Mutasi & Promosi, Pemberhentian & Hak Akhir,
-- serta memperkaya kolom profil Karyawan dan verifikasi Penggajian.
-- ============================================================================

USE `projek_sia`;

-- 1. Tambah kolom profil pada tabel employees jika belum ada
ALTER TABLE `employees`
    ADD COLUMN IF NOT EXISTS `birth_date` DATE NULL AFTER `full_name`,
    ADD COLUMN IF NOT EXISTS `fixed_allowance` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `base_salary`,
    ADD COLUMN IF NOT EXISTS `ptkp_code` VARCHAR(20) NOT NULL DEFAULT 'TK/0' AFTER `fixed_allowance`,
    ADD COLUMN IF NOT EXISTS `bpjs_tk_number` VARCHAR(50) NULL AFTER `bank_name`,
    ADD COLUMN IF NOT EXISTS `bpjs_kes_number` VARCHAR(50) NULL AFTER `bpjs_tk_number`,
    ADD COLUMN IF NOT EXISTS `employment_status` ENUM('Tetap','Kontrak') NOT NULL DEFAULT 'Tetap' AFTER `status`;

-- 2. Perbarui kolom status dan verifikasi pada tabel payroll_records
ALTER TABLE `payroll_records`
    MODIFY COLUMN `status` ENUM('draft','calculated','verified','approved','transferred','rejected') NOT NULL DEFAULT 'draft',
    ADD COLUMN IF NOT EXISTS `verification_notes` VARCHAR(255) NULL AFTER `status`;

-- 3. Tabel Pelatihan Karyawan (employee_trainings)
CREATE TABLE IF NOT EXISTS `employee_trainings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `training_code` VARCHAR(50) NULL,
    `training_name` VARCHAR(150) NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NULL,
    `budget` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('Menunggu','Disetujui','Ditolak','Selesai') NOT NULL DEFAULT 'Menunggu',
    `attendance_status` VARCHAR(50) NULL DEFAULT 'Belum Dilaksanakan',
    `result` VARCHAR(150) NULL,
    `evaluation` TEXT NULL,
    `certificate_file` VARCHAR(255) NULL,
    `decided_by` INT NULL,
    `decided_at` DATETIME NULL,
    `decision_notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    CONSTRAINT `fk_train_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_train_decided` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_train_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_train_emp_id` (`employee_id`),
    INDEX `idx_train_status` (`status`),
    INDEX `idx_train_start_date` (`start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabel Mutasi & Promosi Karyawan (employee_mutations)
CREATE TABLE IF NOT EXISTS `employee_mutations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `mutation_type` ENUM('Promosi','Mutasi','Rotasi','Demosi') NOT NULL DEFAULT 'Mutasi',
    `old_department_id` INT NULL,
    `old_position_id` INT NULL,
    `new_department_id` INT NOT NULL,
    `new_position_id` INT NOT NULL,
    `effective_date` DATE NOT NULL,
    `reason` TEXT NULL,
    `status` ENUM('Menunggu','Selesai','Ditolak') NOT NULL DEFAULT 'Menunggu',
    `sk_number` VARCHAR(100) NULL,
    `minutes_file` VARCHAR(255) NULL,
    `decided_by` INT NULL,
    `decided_at` DATETIME NULL,
    `decision_notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    CONSTRAINT `fk_mut_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_mut_old_dept` FOREIGN KEY (`old_department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_mut_old_pos` FOREIGN KEY (`old_position_id`) REFERENCES `positions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_mut_new_dept` FOREIGN KEY (`new_department_id`) REFERENCES `departments` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_mut_new_pos` FOREIGN KEY (`new_position_id`) REFERENCES `positions` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_mut_decided` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_mut_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_mut_emp_id` (`employee_id`),
    INDEX `idx_mut_status` (`status`),
    INDEX `idx_mut_effective_date` (`effective_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabel Pemberhentian & Hak Akhir (employee_terminations)
CREATE TABLE IF NOT EXISTS `employee_terminations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `termination_type` ENUM('Pensiun','Resign','PHK','Selesai Kontrak') NOT NULL DEFAULT 'Resign',
    `letter_number` VARCHAR(100) NULL,
    `letter_date` DATE NULL,
    `effective_date` DATE NOT NULL,
    `asset_clearance_notes` TEXT NULL,
    `clearance_date` DATE NULL,
    `last_salary` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `severance_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `obligation_deductions` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `pph21_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `total_settlement` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('Menunggu Persetujuan','Disetujui','Ditolak','Dibayar') NOT NULL DEFAULT 'Menunggu Persetujuan',
    `decided_by` INT NULL,
    `decided_at` DATETIME NULL,
    `decision_notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    CONSTRAINT `fk_term_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_term_decided` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_term_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_term_emp_id` (`employee_id`),
    INDEX `idx_term_status` (`status`),
    INDEX `idx_term_effective_date` (`effective_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. View atau alias untuk kompatibilitas jika query referensi dipanggil
CREATE OR REPLACE VIEW `v_mutasi_promosi` AS
SELECT 
    m.id AS id_mutasi,
    m.employee_id AS id_karyawan,
    m.mutation_type AS jenis_perubahan,
    p_new.name AS nama_jabatan_baru,
    d_new.name AS nama_departemen_baru,
    p_old.name AS jab_lama,
    d_old.name AS dep_lama,
    m.effective_date AS tanggal_efektif,
    m.reason AS alasan,
    m.status AS status_usulan,
    m.sk_number AS no_sk,
    m.minutes_file AS file_berita_acara,
    k.full_name AS nama_karyawan,
    m.created_at
FROM employee_mutations m
JOIN employees k ON m.employee_id = k.id
JOIN departments d_new ON m.new_department_id = d_new.id
JOIN positions p_new ON m.new_position_id = p_new.id
LEFT JOIN departments d_old ON m.old_department_id = d_old.id
LEFT JOIN positions p_old ON m.old_position_id = p_old.id;

CREATE OR REPLACE VIEW `v_pelatihan` AS
SELECT 
    p.id AS id_pelatihan,
    p.employee_id AS id_karyawan,
    p.training_code AS kode_pelatihan,
    p.training_name AS nama_pelatihan,
    p.start_date AS tanggal_mulai,
    p.end_date AS tanggal_selesai,
    p.budget AS anggaran,
    p.status AS status_persetujuan,
    p.attendance_status AS kehadiran,
    p.result AS hasil_pelatihan,
    p.evaluation AS evaluasi_atasan,
    p.certificate_file AS file_sertifikat,
    k.full_name AS nama_karyawan
FROM employee_trainings p
JOIN employees k ON p.employee_id = k.id;

CREATE OR REPLACE VIEW `v_pemberhentian` AS
SELECT 
    t.id AS id_pemberhentian,
    t.employee_id AS id_karyawan,
    t.termination_type AS jenis_pemberhentian,
    t.letter_number AS no_surat,
    t.letter_date AS tanggal_surat,
    t.effective_date AS tanggal_efektif,
    t.asset_clearance_notes AS catatan_aset,
    t.clearance_date AS tanggal_clearance,
    t.last_salary AS gaji_terakhir,
    t.severance_amount AS manfaat_akhir,
    t.obligation_deductions AS potongan_kewajiban,
    t.pph21_amount AS pph21,
    t.total_settlement AS total_hak_akhir,
    t.status AS status_hak_akhir,
    k.full_name AS nama_karyawan,
    d.name AS nama_departemen
FROM employee_terminations t
JOIN employees k ON t.employee_id = k.id
LEFT JOIN departments d ON k.department_id = d.id;
