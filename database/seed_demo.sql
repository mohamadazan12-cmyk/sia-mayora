-- ============================================================================
-- SISTEM INFORMASI OPERASIONAL MANUFAKTUR & DISTRIBUSI (SIA)
-- Demo Seed Data Script (Data Awal / Percontohan)
-- File: database/seed_demo.sql
-- Semua teks dalam Bahasa Indonesia | INSERT IGNORE untuk mencegah duplikasi
-- Password semua akun demo: 'password' (atau 'admin123' untuk admin jika disesuaikan)
-- Bcrypt hash standar: $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO,STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;
SET time_zone = '+00:00';

USE `projek_sia`;

-- ============================================================================
-- 1. PERAN / HAK AKSES (ROLES)
-- ============================================================================
INSERT IGNORE INTO `roles` (`id`, `role_code`, `role_name`, `description`, `is_active`) VALUES
(1, 'admin', 'Administrator', 'Akses penuh dan konfigurasi seluruh modul sistem informasi', 1),
(2, 'gudang', 'Petugas Gudang', 'Penerimaan, penyimpanan, stock opname, dan pengeluaran barang gudang', 1),
(3, 'pembelian', 'Petugas Pembelian', 'Pengelolaan permintaan pembelian (PR), pesanan pembelian (PO), dan evaluasi vendor', 1),
(4, 'produksi', 'Petugas Produksi', 'Perencanaan perintah kerja (SPK), alokasi bahan, dan pencatatan output mesin', 1),
(5, 'qc', 'Petugas QC', 'Pemeriksaan mutu bahan baku datang, inspeksi proses produksi, dan rilis barang jadi', 1),
(6, 'penjualan', 'Petugas Penjualan', 'Pengelolaan pesanan pelanggan (SO), surat jalan kirim (DO), dan faktur penjualan', 1),
(7, 'finance', 'Finance/Treasury', 'Pengelolaan kas, bank, verifikasi faktur, pelunasan utang/piutang, dan jurnal umum', 1),
(8, 'hr', 'HR/Payroll', 'Pengelolaan data kepegawaian, absensi, cuti, lembur, dan slip gaji karyawan', 1),
(9, 'manajer', 'Supervisor/Manajer', 'Persetujuan berjenjang (approval), monitoring target kerja, dan analisis laporan', 1);

-- ============================================================================
-- 2. PENGGUNA DEMO (USERS)
-- Catatan: Password default untuk seluruh akun demo adalah: 'password'
-- ============================================================================
INSERT IGNORE INTO `users` (`id`, `username`, `password_hash`, `full_name`, `email`, `role_id`, `is_active`, `created_by`, `updated_by`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator Sistem', 'admin@manufaktur.id', 1, 1, 1, 1),
(2, 'gudang', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Budi Santoso (Gudang)', 'budi.gudang@manufaktur.id', 2, 1, 1, 1),
(3, 'purchasing', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Siti Rahmawati (Purchasing)', 'purchasing@manufaktur.id', 3, 1, 1, 1),
(4, 'ppic', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Joko Prasetyo (PPIC)', 'ppic@manufaktur.id', 4, 1, 1, 1),
(5, 'qc', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dewi Lestari (Quality Control)', 'dewi.qc@manufaktur.id', 5, 1, 1, 1),
(6, 'penjualan', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Rian Hidayat (Sales)', 'rian.penjualan@manufaktur.id', 6, 1, 1, 1),
(7, 'finance', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Maya Indah (Finance/Kasir)', 'maya.finance@manufaktur.id', 7, 1, 1, 1),
(8, 'hr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Agus Setiawan (HR/Payroll)', 'agus.hr@manufaktur.id', 8, 1, 1, 1),
(9, 'manager', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Hendra Kusuma (Manager)', 'manager@manufaktur.id', 9, 1, 1, 1),
(10, 'pembelian', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Siti Rahmawati (Pembelian)', 'siti.pembelian@manufaktur.id', 3, 1, 1, 1),
(11, 'produksi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Joko Prasetyo (Produksi)', 'joko.produksi@manufaktur.id', 4, 1, 1, 1),
(12, 'manajer', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Hendra Kusuma (Manajer Operasional)', 'hendra.manajer@manufaktur.id', 9, 1, 1, 1);

-- ============================================================================
-- 3. DEPARTEMEN PERUSAHAAN (DEPARTMENTS)
-- ============================================================================
INSERT IGNORE INTO `departments` (`id`, `code`, `name`, `is_active`) VALUES
(1, 'DEP-MGT', 'Direksi & Manajemen Eksekutif', 1),
(2, 'DEP-PROD', 'Departemen Produksi & Pabrikasi', 1),
(3, 'DEP-WHS', 'Departemen Gudang & Manajemen Logistik', 1),
(4, 'DEP-PROC', 'Departemen Pengadaan & Pembelian', 1),
(5, 'DEP-SLS', 'Departemen Penjualan & Distribusi', 1),
(6, 'DEP-QC', 'Departemen Pengendalian Mutu (QC)', 1),
(7, 'DEP-FIN', 'Departemen Keuangan, Akuntansi & Pajak', 1),
(8, 'DEP-HRD', 'Departemen Sumber Daya Manusia & Umum', 1);

-- ============================================================================
-- 4. JABATAN PERUSAHAAN (POSITIONS)
-- ============================================================================
INSERT IGNORE INTO `positions` (`id`, `code`, `name`, `department_id`, `is_active`) VALUES
(1, 'POS-DIR-01', 'Direktur Operasional', 1, 1),
(2, 'POS-MGR-PROD', 'Manajer Produksi', 2, 1),
(3, 'POS-SPV-PROD', 'Supervisor Lini Perakitan', 2, 1),
(4, 'POS-OPR-PROD', 'Operator Mesin Bubut & CNC', 2, 1),
(5, 'POS-MGR-WHS', 'Kepala Bagian Gudang', 3, 1),
(6, 'POS-STF-WHS', 'Staf Administrasi & Operator Gudang', 3, 1),
(7, 'POS-STF-PROC', 'Staf Buyer & Pengadaan Bahan', 4, 1),
(8, 'POS-STF-SLS', 'Account Executive Penjualan', 5, 1),
(9, 'POS-STF-QC', 'Inspektur QC Incoming & Outgoing', 6, 1),
(10, 'POS-STF-FIN', 'Staf Akuntansi & Kasir Treasury', 7, 1),
(11, 'POS-STF-HRD', 'Staf Personalia & Kompensasi', 8, 1);

-- ============================================================================
-- 5. KATEGORI BARANG (ITEM CATEGORIES)
-- ============================================================================
INSERT IGNORE INTO `item_categories` (`id`, `code`, `name`, `type`, `is_active`) VALUES
(1, 'CAT-BB-MET', 'Bahan Baku Logam & Besi Baja', 'bahan_baku', 1),
(2, 'CAT-BB-ELC', 'Komponen Kelistrikan & Sensor', 'bahan_baku', 1),
(3, 'CAT-BB-KIM', 'Bahan Kimia Industri, Cat & Pelumas', 'bahan_baku', 1),
(4, 'CAT-BJ-MSN', 'Mesin Otomasi & Peralatan Pabrik', 'barang_jadi', 1),
(5, 'CAT-BJ-PRT', 'Suku Cadang & Komponen Presisi', 'barang_jadi', 1),
(6, 'CAT-PKG-BOX', 'Karton Box & Kemasan Pengiriman', 'packaging', 1),
(7, 'CAT-LNN-UMM', 'Perlengkapan Kerja & Consumable', 'lainnya', 1);

-- ============================================================================
-- 6. SATUAN UKURAN (UNITS)
-- ============================================================================
INSERT IGNORE INTO `units` (`id`, `code`, `name`, `is_active`) VALUES
(1, 'PCS', 'Pieces / Buah', 1),
(2, 'KG', 'Kilogram', 1),
(3, 'MTR', 'Meter Panjang', 1),
(4, 'LTR', 'Liter Cairan', 1),
(5, 'BOX', 'Box / Kardus', 1),
(6, 'ROLL', 'Roll / Gulungan', 1),
(7, 'SET', 'Set / Unit Rakitan', 1);

-- ============================================================================
-- 7. MASTER BARANG (ITEMS - BAHAN BAKU & BARANG JADI)
-- ============================================================================
INSERT IGNORE INTO `items` (`id`, `code`, `name`, `category_id`, `unit_id`, `min_stock`, `description`, `is_active`, `created_by`, `updated_by`) VALUES
-- Bahan Baku Logam
(1, 'BB-001', 'Pelat Baja Stainless SUS304 Tebal 2mm', 1, 2, 250.00, 'Pelat baja stainless tahan korosi untuk bodi mesin utama', 1, 1, 1),
(2, 'BB-002', 'Pipa Besi Seamless OD 2 Inch Tebal 3mm', 1, 3, 100.00, 'Pipa seamless struktural untuk rangka penyangga', 1, 1, 1),
(3, 'BB-003', 'Batang Silinder Aluminium Alloy Dia 25mm', 1, 3, 80.00, 'Aluminium rod presisi tinggi untuk poros mekanis', 1, 1, 1),
(4, 'BB-004', 'Baut Flange Hexagon M10x40 Grade 8.8 Hitam', 1, 1, 1000.00, 'Baut pengikat kekuatan tinggi dengan mur nilon', 1, 1, 1),

-- Bahan Baku Kelistrikan & Kimia
(5, 'BB-005', 'Microcontroller Industrial Board PLC-X32', 2, 1, 30.00, 'Modul kontroler logika digital dengan 16 input / 16 output', 1, 1, 1),
(6, 'BB-006', 'Sensor Proximity Induktif 24V M18 PNP', 2, 1, 40.00, 'Sensor pendeteksi logam otomatis dengan jarak baca 8mm', 1, 1, 1),
(7, 'BB-007', 'Minyak Pelumas Hidrolik ISO VG 68 Drum', 3, 4, 60.00, 'Oli hidrolik anti-wear untuk sistem penekan pemotong', 1, 1, 1),
(8, 'BB-008', 'Cat Primer Epoxy Industrial Abu-abu 5Kg', 3, 4, 25.00, 'Pelapis dasar pencegah karat untuk struktur besi mesin', 1, 1, 1),

-- Packaging & Kemasan
(9, 'PKG-001', 'Karton Box Heavy Duty Double Wall 60x40x40', 6, 1, 150.00, 'Kardus kokoh pelindung barang jadi saat distribusi', 1, 1, 1),
(10, 'PKG-002', 'Plastik Bubble Wrap Tebal 1.25m x 50m', 6, 6, 15.00, 'Gulungan gelembung udara peredam guncangan kargo', 1, 1, 1),

-- Barang Jadi
(11, 'BJ-001', 'Mesin Pemotong Otomatis CNC Cut-500 Pro', 4, 1, 5.00, 'Unit mesin pemotong pelat logam lembaran berbasis otomasi', 1, 1, 1),
(12, 'BJ-002', 'Unit Konveyor Pengumpan Logam CV-200', 4, 1, 8.00, 'Konveyor pengumpan otomatis dengan sabuk karet tahan panas', 1, 1, 1),
(13, 'BJ-003', 'Rakitan Poros Transmisi Presisi DS-80', 5, 1, 15.00, 'Modul transmisi putaran sudut dengan kopling fleksibel', 1, 1, 1);

-- ============================================================================
-- 8. GUDANG PENYIMPANAN (WAREHOUSES)
-- ============================================================================
INSERT IGNORE INTO `warehouses` (`id`, `code`, `name`, `type`, `address`, `is_active`) VALUES
(1, 'GUD-BB', 'Gudang Utama Bahan Baku', 'bahan_baku', 'Kawasan Industri Sentul Blok A-12, Citeureup, Bogor', 1),
(2, 'GUD-BJ', 'Gudang Distribusi Barang Jadi', 'barang_jadi', 'Kawasan Industri Sentul Blok B-05, Citeureup, Bogor', 1),
(3, 'GUD-WIP', 'Gudang Transit Work-In-Process (WIP)', 'wip', 'Lantai 1 Hall Perakitan Pabrik Utama Sentul', 1),
(4, 'GUD-UMUM', 'Gudang Perlengkapan & Material Umum', 'umum', 'Kawasan Industri Sentul Blok C-02, Citeureup, Bogor', 1);

-- ============================================================================
-- 9. SALDO AWAL STOK (STOCK BALANCES)
-- ============================================================================
INSERT IGNORE INTO `stock_balances` (`id`, `item_id`, `warehouse_id`, `qty_available`, `qty_reserved`, `qty_on_hold`, `updated_at`) VALUES
-- Bahan Baku di Gudang Utama Bahan Baku (id=1)
(1, 1, 1, 1250.00, 150.00, 0.00, NOW()),  -- Pelat Baja Stainless
(2, 2, 1, 420.00, 40.00, 0.00, NOW()),    -- Pipa Besi Seamless
(3, 3, 1, 310.00, 25.00, 0.00, NOW()),    -- Batang Aluminium Alloy
(4, 4, 1, 5200.00, 400.00, 0.00, NOW()),  -- Baut Flange Hexagon M10
(5, 5, 1, 120.00, 20.00, 0.00, NOW()),    -- PLC Board
(6, 6, 1, 145.00, 15.00, 0.00, NOW()),    -- Sensor Proximity
(7, 7, 1, 240.00, 0.00, 0.00, NOW()),     -- Oli Hidrolik
(8, 8, 1, 85.00, 0.00, 0.00, NOW()),      -- Cat Primer Epoxy
(9, 9, 1, 450.00, 30.00, 0.00, NOW()),    -- Karton Box
(10, 10, 1, 35.00, 5.00, 0.00, NOW()),    -- Bubble Wrap

-- Barang Jadi di Gudang Distribusi Barang Jadi (id=2)
(11, 11, 2, 16.00, 2.00, 0.00, NOW()),    -- Mesin CNC Cut-500 Pro
(12, 12, 2, 24.00, 3.00, 0.00, NOW()),    -- Konveyor CV-200
(13, 13, 2, 50.00, 6.00, 0.00, NOW()),    -- Poros Transmisi DS-80

-- Material Transit di Gudang WIP (id=3)
(14, 1, 3, 60.00, 0.00, 0.00, NOW()),
(15, 3, 3, 20.00, 0.00, 0.00, NOW()),
(16, 13, 3, 12.00, 0.00, 0.00, NOW());

-- Log mutasi saldo awal
INSERT IGNORE INTO `stock_movements` (`id`, `item_id`, `warehouse_id`, `movement_type`, `qty_in`, `qty_out`, `balance_after`, `ref_type`, `ref_id`, `ref_number`, `notes`, `user_id`, `created_at`) VALUES
(1, 1, 1, 'in', 1400.00, 0.00, 1400.00, 'saldo_awal', 1, 'SALDO-AWAL-001', 'Pencatatan saldo awal pelat baja stainless SUS304', 1, NOW()),
(2, 2, 1, 'in', 460.00, 0.00, 460.00, 'saldo_awal', 2, 'SALDO-AWAL-002', 'Pencatatan saldo awal pipa besi seamless 2 inch', 1, NOW()),
(3, 3, 1, 'in', 335.00, 0.00, 335.00, 'saldo_awal', 3, 'SALDO-AWAL-003', 'Pencatatan saldo awal batang aluminium alloy 25mm', 1, NOW()),
(4, 4, 1, 'in', 5600.00, 0.00, 5600.00, 'saldo_awal', 4, 'SALDO-AWAL-004', 'Pencatatan saldo awal baut flange hexagon M10x40', 1, NOW()),
(5, 5, 1, 'in', 140.00, 0.00, 140.00, 'saldo_awal', 5, 'SALDO-AWAL-005', 'Pencatatan saldo awal PLC Board Industrial X32', 1, NOW()),
(6, 6, 1, 'in', 160.00, 0.00, 160.00, 'saldo_awal', 6, 'SALDO-AWAL-006', 'Pencatatan saldo awal sensor proximity induktif', 1, NOW()),
(7, 7, 1, 'in', 240.00, 0.00, 240.00, 'saldo_awal', 7, 'SALDO-AWAL-007', 'Pencatatan saldo awal minyak pelumas hidrolik VG68', 1, NOW()),
(8, 8, 1, 'in', 85.00, 0.00, 85.00, 'saldo_awal', 8, 'SALDO-AWAL-008', 'Pencatatan saldo awal cat primer epoxy anti karat', 1, NOW()),
(9, 11, 2, 'in', 18.00, 0.00, 18.00, 'saldo_awal', 9, 'SALDO-AWAL-009', 'Pencatatan saldo awal barang jadi Mesin CNC Cut-500', 1, NOW()),
(10, 12, 2, 'in', 27.00, 0.00, 27.00, 'saldo_awal', 10, 'SALDO-AWAL-010', 'Pencatatan saldo awal unit konveyor pengumpan CV-200', 1, NOW()),
(11, 13, 2, 'in', 56.00, 0.00, 56.00, 'saldo_awal', 11, 'SALDO-AWAL-011', 'Pencatatan saldo awal modul poros transmisi DS-80', 1, NOW());

-- ============================================================================
-- 10. PELANGGAN (CUSTOMERS) DENGAN BATAS KREDIT
-- ============================================================================
INSERT IGNORE INTO `customers` (`id`, `code`, `name`, `address`, `phone`, `email`, `contact_person`, `credit_limit`, `credit_used`, `credit_status`, `payment_terms_days`, `is_active`, `created_by`, `updated_by`) VALUES
(1, 'CUST-001', 'PT Sinar Logam Abadi', 'Kawasan Rungkut Industri Blok C No. 15, Surabaya', '031-8439900', 'procurement@sinarlogam.co.id', 'Ir. Bambang Trihatmojo', 250000000.00, 48500000.00, 'active', 30, 1, 1, 1),
(2, 'CUST-002', 'CV Mitra Mandiri Teknik', 'Jl. Raya Narogong Km 14, Cileungsi, Bogor', '021-8249880', 'admin@mitramandiriteknik.com', 'Hj. Ratna Sari Dewi', 120000000.00, 114500000.00, 'warning', 14, 1, 1, 1),
(3, 'CUST-003', 'PT Tri Tunggal Manufaktur', 'Kawasan Industri KIIC Lot C-4, Karawang Barat', '0267-640111', 'purchasing@tritunggal.co.id', 'Albertus Kurniawan, ST', 500000000.00, 0.00, 'active', 45, 1, 1, 1),
(4, 'CUST-004', 'PT Mega Cipta Fabrikasi', 'Kawasan Industri Wijayakusuma Blok B-8, Semarang', '024-7605544', 'contact@megacipta.co.id', 'Drs. Hendro Wibowo', 90000000.00, 90000000.00, 'blocked', 14, 1, 1, 1),
(5, 'CUST-005', 'UD Sukses Perkasa Motor', 'Jl. Soekarno-Hatta No. 420, Bandung', '022-7561122', 'suksesperkasa@gmail.com', 'Asep Saepullah', 60000000.00, 15000000.00, 'active', 30, 1, 1, 1);

-- ============================================================================
-- 11. PEMASOK (SUPPLIERS)
-- ============================================================================
INSERT IGNORE INTO `suppliers` (`id`, `code`, `name`, `address`, `phone`, `email`, `contact_person`, `payment_terms_days`, `is_active`, `created_by`, `updated_by`) VALUES
(1, 'SUP-001', 'PT Krakatau Steelindo Perkasa', 'Kawasan Industri Krakatau Steel Kav. 18, Cilegon', '0254-392111', 'sales@krakataubaja.co.id', 'Agus Supriadi', 30, 1, 1, 1),
(2, 'SUP-002', 'CV Duta Otomasi Mandiri', 'Pusat Niaga Glodok Gedung Lindeteves Lt. 2, Jakarta Barat', '021-6288820', 'order@dutaotomasi.com', 'William Gunawan', 30, 1, 1, 1),
(3, 'SUP-003', 'PT Pertamina Lubricants Industrial', 'Gedung Oil Centre Lt. 5, Jl. MH Thamrin No. 55, Jakarta Pusat', '021-3190888', 'industrial@pertaminalube.com', 'Fitri Handayani', 45, 1, 1, 1),
(4, 'SUP-004', 'PT Karton Surya Kemasindo', 'Jl. Industri Raya Km 19, Balaraja, Tangerang', '021-5950123', 'corporate@suryakemasindo.co.id', 'Denny Rahardjo', 14, 1, 1, 1),
(5, 'SUP-005', 'PT Fastener Logam Nusantara', 'Kawasan Industri Jababeka V Blok G-7, Cikarang', '021-8934567', 'sales@fastenerlogam.co.id', 'Rudy Hermawan', 30, 1, 1, 1);

-- ============================================================================
-- 12. BILL OF MATERIALS (BOM) & KOMPONEN PERAKITAN
-- ============================================================================
INSERT IGNORE INTO `boms` (`id`, `product_item_id`, `code`, `name`, `version`, `is_active`, `notes`, `created_by`, `updated_by`) VALUES
(1, 11, 'BOM-CNC-500', 'Formula Perakitan Mesin Pemotong CNC Cut-500 Standar', 1, 1, 'Formula resmi perakitan 1 unit mesin potong otomatis CNC', 1, 1),
(2, 13, 'BOM-DS-80', 'Formula Perakitan Rakitan Poros Transmisi DS-80', 1, 1, 'Spesifikasi bubut dan assembling modul poros penggerak', 1, 1);

INSERT IGNORE INTO `bom_items` (`id`, `bom_id`, `item_id`, `qty_per_unit`, `unit_id`, `notes`) VALUES
-- Rincian komponen BOM-1 (Mesin Pemotong Otomatis)
(1, 1, 1, 16.5000, 2, 'Pelat Baja Stainless SUS304 untuk bodi meja potong dan pelindung'),
(2, 1, 2, 4.0000, 3, 'Pipa Besi Seamless untuk rangka penopang sumbu geser X-Y'),
(3, 1, 5, 1.0000, 1, 'PLC Board Industrial X32 pengendali stepper dan limit'),
(4, 1, 6, 4.0000, 1, 'Sensor Proximity Induktif batas lintasan aman'),
(5, 1, 7, 2.5000, 4, 'Minyak Pelumas Hidrolik silinder penjepit lembaran'),
(6, 1, 8, 1.5000, 4, 'Cat Primer Epoxy anti karat pelapis rangka'),
(7, 1, 4, 32.0000, 1, 'Baut Flange Hexagon M10x40 pengikat mekanik utama'),
(8, 1, 9, 1.0000, 1, 'Karton Box Heavy Duty kemasan produk final'),
(9, 1, 10, 0.2500, 6, 'Bubble wrap bantalan pelindung cat dan kaca kontrol'),

-- Rincian komponen BOM-2 (Poros Transmisi DS-80)
(10, 2, 3, 1.2000, 3, 'Batang Aluminium Alloy 25mm bahan bubut poros'),
(11, 2, 7, 0.1500, 4, 'Pelumas gemuk anti-aus bantalan shaft'),
(12, 2, 4, 8.0000, 1, 'Baut Flange Hexagon M10 pengikat flensa kopling');

-- ============================================================================
-- 13. KARYAWAN (EMPLOYEES)
-- ============================================================================
INSERT IGNORE INTO `employees` (`id`, `code`, `full_name`, `department_id`, `position_id`, `join_date`, `resign_date`, `base_salary`, `bank_account`, `bank_name`, `status`, `user_id`, `created_by`, `updated_by`) VALUES
(1, 'EMP-001', 'Hendra Kusuma, MM', 1, 1, '2020-01-05', NULL, 24000000.00, '1230009876543', 'Bank BCA', 'active', 9, 1, 1),
(2, 'EMP-002', 'Joko Prasetyo, ST', 2, 3, '2021-02-10', NULL, 10500000.00, '1230001122334', 'Bank BCA', 'active', 4, 1, 1),
(3, 'EMP-003', 'Budi Santoso', 3, 6, '2021-04-15', NULL, 6500000.00, '5432009876111', 'Bank Mandiri', 'active', 2, 1, 1),
(4, 'EMP-004', 'Siti Rahmawati, SE', 4, 7, '2021-05-02', NULL, 7800000.00, '5432008877665', 'Bank Mandiri', 'active', 3, 1, 1),
(5, 'EMP-005', 'Rian Hidayat, SM', 5, 8, '2021-08-15', NULL, 8200000.00, '1230005544332', 'Bank BCA', 'active', 6, 1, 1),
(6, 'EMP-006', 'Dewi Lestari, ST', 6, 9, '2022-01-10', NULL, 7500000.00, '1230006677889', 'Bank BCA', 'active', 5, 1, 1),
(7, 'EMP-007', 'Maya Indah, SE, Ak', 7, 10, '2020-09-01', NULL, 9500000.00, '5432004433221', 'Bank Mandiri', 'active', 7, 1, 1),
(8, 'EMP-008', 'Agus Setiawan, SH', 8, 11, '2021-03-01', NULL, 8500000.00, '5432001122998', 'Bank Mandiri', 'active', 8, 1, 1);

-- ============================================================================
-- 14. ANGGARAN PERUSAHAAN (BUDGETS & BUDGET LINES)
-- ============================================================================
INSERT IGNORE INTO `budgets` (`id`, `code`, `name`, `period_year`, `period_month`, `department_id`, `total_amount`, `used_amount`, `status`, `created_by`, `updated_by`) VALUES
(1, 'BDG-2026-PROD', 'Anggaran Operasional Pabrik & Produksi Tahun 2026', 2026, NULL, 2, 1500000000.00, 345000000.00, 'active', 1, 1),
(2, 'BDG-2026-PROC', 'Anggaran Pengadaan Bahan Baku & Logistik 2026', 2026, NULL, 4, 3000000000.00, 715000000.00, 'active', 1, 1),
(3, 'BDG-2026-WHS', 'Anggaran Operasional Gudang & Distribusi 2026', 2026, NULL, 3, 400000000.00, 92000000.00, 'active', 1, 1),
(4, 'BDG-2026-HRD', 'Anggaran Pelatihan, K3 & Kesejahteraan SDM 2026', 2026, NULL, 8, 350000000.00, 68000000.00, 'active', 1, 1);

INSERT IGNORE INTO `budget_lines` (`id`, `budget_id`, `category`, `amount`, `used_amount`, `notes`) VALUES
(1, 1, 'Pemeliharaan dan Servis Rutin Mesin Pabrik', 500000000.00, 120000000.00, 'Perawatan berkala mesin bubut, CNC, kompresor dan sistem hidrolik'),
(2, 1, 'Tagihan Listrik Industri & Utilitas Pabrik', 650000000.00, 160000000.00, 'Listrik PLN gardu industri 197 kVA dan solar genset cadangan'),
(3, 1, 'Perlengkapan Alat Pelindung Diri (APD) & K3', 150000000.00, 35000000.00, 'Sepatu safety, helm pelindung, sarung tangan anti panas, kacamata las'),
(4, 1, 'Consumable Perkakas, Mata Bor & Insert Bubut', 200000000.00, 30000000.00, 'Mata potong karbida, batu gerinda, pendingin coolant'),
(5, 2, 'Pengadaan Pelat Logam & Besi Baja Struktural', 1800000000.00, 440000000.00, 'Kontrak pengadaan pelat baja stainless SUS304 dan pipa seamless'),
(6, 2, 'Pengadaan Modul Kontroler & Sensor Otomasi', 800000000.00, 190000000.00, 'Pembelian modul PLC industri, power supply 24V dan sensor jarak'),
(7, 2, 'Pengadaan Minyak Pelumas & Kimia Pelapis', 400000000.00, 85000000.00, 'Drum oli hidrolik VG68, tiner, dan cat dasar epoxy'),
(8, 3, 'Sewa & Pemeliharaan Forklift 3 Ton', 160000000.00, 42000000.00, 'Sewa bulanan unit forklift elektrik dan bahan bakar'),
(9, 3, 'Palet Kayu Standar Ekspor & Plastik Wrapping', 240000000.00, 50000000.00, 'Pengadaan palet kayu ISPM-15 dan tali strapping'),
(10, 4, 'Pelatihan & Sertifikasi Operator CNC/K3', 200000000.00, 40000000.00, 'Kursus pemrograman G-code dan sertifikasi K3 Kemenaker'),
(11, 4, 'Kesejahteraan, Asuransi & MCU Karyawan', 150000000.00, 28000000.00, 'Medical check-up tahunan karyawan pabrik');

-- ============================================================================
-- 15. KOMPONEN PENGGAJIAN (PAYROLL COMPONENTS)
-- ============================================================================
INSERT IGNORE INTO `payroll_components` (`id`, `code`, `name`, `type`, `calculation_type`, `default_value`, `percentage_base`, `is_active`, `sort_order`) VALUES
(1, 'GAPOK', 'Gaji Pokok Karyawan', 'penghasilan', 'fixed', 0.00, NULL, 1, 1),
(2, 'TUNJ_JAB', 'Tunjangan Jabatan & Struktural', 'penghasilan', 'fixed', 1500000.00, NULL, 1, 2),
(3, 'TUNJ_TRANS', 'Tunjangan Transportasi & Uang Makan', 'penghasilan', 'fixed', 800000.00, NULL, 1, 3),
(4, 'LEMBUR', 'Upah Kerja Lembur (Overtime)', 'penghasilan', 'formula', 0.00, NULL, 1, 4),
(5, 'BONUS_PROD', 'Bonus Produktivitas / Insentif Target', 'penghasilan', 'fixed', 500000.00, NULL, 1, 5),
(6, 'POT_BPJS_TK', 'Potongan BPJS Ketenagakerjaan (3%)', 'potongan', 'percentage', 3.00, 'GAPOK', 1, 6),
(7, 'POT_BPJS_KES', 'Potongan BPJS Kesehatan (1%)', 'potongan', 'percentage', 1.00, 'GAPOK', 1, 7),
(8, 'POT_PPH21', 'Potongan PPh Pasal 21 Terutang', 'potongan', 'formula', 0.00, NULL, 1, 8),
(9, 'POT_KASBON', 'Potongan Kasbon / Pinjaman Pegawai', 'potongan', 'fixed', 0.00, NULL, 1, 9),
(10, 'POT_ALPHA', 'Potongan Ketidakhadiran / Mangkir', 'potongan', 'formula', 0.00, NULL, 1, 10);

-- ============================================================================
-- 16. BAGAN AKUN AKUNTANSI MANUFAKTUR (CHART OF ACCOUNTS)
-- ============================================================================
INSERT IGNORE INTO `accounts` (`id`, `code`, `name`, `type`, `parent_id`, `is_active`) VALUES
-- Header 1: ASET
(1, '1-0000', 'ASET / AKTIVA', 'asset', NULL, 1),
(2, '1-1000', 'Aset Lancar', 'asset', 1, 1),
(3, '1-1100', 'Kas dan Setara Kas', 'asset', 2, 1),
(4, '1-1110', 'Kas Kecil Kantor Sentul', 'asset', 3, 1),
(5, '1-1120', 'Bank BCA Giro Operasional', 'asset', 3, 1),
(6, '1-1130', 'Bank Mandiri Giro Payroll & Perpajakan', 'asset', 3, 1),
(7, '1-1200', 'Piutang Usaha Dagang', 'asset', 2, 1),
(8, '1-1210', 'Piutang Usaha Pelanggan Domestik', 'asset', 7, 1),
(9, '1-1290', 'Cadangan Kerugian Piutang Tak Tertagih', 'asset', 7, 1),
(10, '1-1300', 'Persediaan Barang', 'asset', 2, 1),
(11, '1-1310', 'Persediaan Bahan Baku Logam & Kimia', 'asset', 10, 1),
(12, '1-1320', 'Persediaan Barang Dalam Proses (WIP)', 'asset', 10, 1),
(13, '1-1330', 'Persediaan Barang Jadi Mesin & Peralatan', 'asset', 10, 1),
(14, '1-1340', 'Persediaan Bahan Kemas & Consumable', 'asset', 10, 1),
(15, '1-1400', 'Pajak & Biaya Dibayar di Muka', 'asset', 2, 1),
(16, '1-1410', 'Pajak Pertambahan Nilai (PPN) Masukan', 'asset', 15, 1),
(17, '1-1420', 'Sewa Gudang & Pabrik Dibayar di Muka', 'asset', 15, 1),

-- Header Aset Tetap
(18, '1-2000', 'Aset Tetap & Peralatan Produksi', 'asset', 1, 1),
(19, '1-2100', 'Mesin Perkakas & Mesin Pabrikasi', 'asset', 18, 1),
(20, '1-2190', 'Akumulasi Penyusutan Mesin Perkakas', 'asset', 18, 1),
(21, '1-2200', 'Kendaraan Operasional & Armada Distribusi', 'asset', 18, 1),
(22, '1-2290', 'Akumulasi Penyusutan Kendaraan Armada', 'asset', 18, 1),
(23, '1-2300', 'Inventaris Peralatan Komputer & Kantor', 'asset', 18, 1),
(24, '1-2390', 'Akumulasi Penyusutan Peralatan Kantor', 'asset', 18, 1),

-- Header 2: KEWAJIBAN / LIABILITAS
(25, '2-0000', 'KEWAJIBAN / LIABILITAS', 'liability', NULL, 1),
(26, '2-1000', 'Kewajiban Jangka Pendek', 'liability', 25, 1),
(27, '2-1100', 'Utang Usaha Dagang ke Pemasok', 'liability', 26, 1),
(28, '2-1200', 'Beban Akrual Utang Gaji & Upah Karyawan', 'liability', 26, 1),
(29, '2-1300', 'Utang Perpajakan PPN Keluaran & PPh', 'liability', 26, 1),
(30, '2-1400', 'Uang Muka Pesanan Pelanggan (Deposit)', 'liability', 26, 1),
(31, '2-2000', 'Kewajiban Jangka Panjang', 'liability', 25, 1),
(32, '2-2100', 'Utang Bank Investasi Mesin Jangka Panjang', 'liability', 31, 1),

-- Header 3: EKUITAS / MODAL
(33, '3-0000', 'EKUITAS PEMILIK / MODAL', 'equity', NULL, 1),
(34, '3-1000', 'Modal Saham Ditempatkan dan Disetor Penuh', 'equity', 33, 1),
(35, '3-2000', 'Saldo Laba Ditahan (Retained Earnings)', 'equity', 33, 1),
(36, '3-3000', 'Laba / Rugi Bersih Tahun Berjalan', 'equity', 33, 1),

-- Header 4: PENDAPATAN
(37, '4-0000', 'PENDAPATAN USAHA / PENJUALAN', 'revenue', NULL, 1),
(38, '4-1000', 'Penjualan Produk Mesin Otomasi Industri', 'revenue', 37, 1),
(39, '4-2000', 'Penjualan Komponen Suku Cadang Presisi', 'revenue', 37, 1),
(40, '4-3000', 'Pendapatan Jasa Fabrikasi, Servis & Instalasi', 'revenue', 37, 1),
(41, '4-9000', 'Retur dan Potongan Rabat Penjualan', 'revenue', 37, 1),

-- Header 5: BEBAN POKOK PRODUKSI / PENJUALAN (HPP/COGS)
(42, '5-0000', 'BEBAN POKOK PRODUKSI & PENJUALAN (HPP)', 'expense', NULL, 1),
(43, '5-1000', 'Biaya Pemakaian Bahan Baku Langsung', 'expense', 42, 1),
(44, '5-2000', 'Biaya Upah Tenaga Kerja Langsung Pabrik', 'expense', 42, 1),
(45, '5-3000', 'Biaya Overhead Pabrik (BOP)', 'expense', 42, 1),
(46, '5-3100', 'BOP - Pemakaian Pelumas, Cat & Bahan Pembantu', 'expense', 45, 1),
(47, '5-3200', 'BOP - Listrik, Air & Bahan Bakar Pabrik', 'expense', 45, 1),
(48, '5-3300', 'BOP - Beban Penyusutan Mesin Pabrik', 'expense', 45, 1),
(49, '5-3400', 'BOP - Pemeliharaan Mesin & Peralatan Produksi', 'expense', 45, 1),

-- Header 6: BEBAN OPERASIONAL, UMUM & DISTRIBUSI
(50, '6-0000', 'BEBAN OPERASIONAL, UMUM & PEMASARAN', 'expense', NULL, 1),
(51, '6-1000', 'Beban Gaji & Tunjangan Pegawai Kantor', 'expense', 50, 1),
(52, '6-2000', 'Beban Pengiriman Kargo & Distribusi Barang', 'expense', 50, 1),
(53, '6-3000', 'Beban Pemasaran, Iklan & Representasi', 'expense', 50, 1),
(54, '6-4000', 'Beban Keperluan Kantor, ATK & Layanan IT', 'expense', 50, 1),
(55, '6-5000', 'Beban Penyusutan Kendaraan & Aset Kantor', 'expense', 50, 1);

-- ============================================================================
-- 17. PEMETAAN AKUN AKUNTANSI OTOMATIS (ACCOUNT MAPPINGS)
-- ============================================================================
INSERT IGNORE INTO `account_mappings` (`id`, `transaction_type`, `debit_account_id`, `credit_account_id`, `description`, `is_active`) VALUES
(1, 'penjualan_barang_jadi', 8, 38, 'Penerbitan Faktur Penjualan Produk Mesin (Dr Piutang Usaha, Cr Penjualan)', 1),
(2, 'penerimaan_piutang_bank', 5, 8, 'Penerimaan Pelunasan Piutang via Transfer Bank (Dr Bank BCA, Cr Piutang Usaha)', 1),
(3, 'pembelian_bahan_baku', 11, 27, 'Penerimaan Bahan Baku & Faktur Pemasok (Dr Persediaan Bahan Baku, Cr Utang Usaha)', 1),
(4, 'pembayaran_utang_supplier', 27, 5, 'Pelunasan Utang Faktur Pemasok via Bank (Dr Utang Usaha, Cr Bank BCA)', 1),
(5, 'pengeluaran_bahan_ke_spk', 12, 11, 'Pengeluaran Bahan Baku ke Perintah Kerja (Dr Persediaan WIP, Cr Persediaan Bahan Baku)', 1),
(6, 'penyelesaian_produksi_ke_gudang', 13, 12, 'Penyelesaian Perakitan Masuk Gudang (Dr Persediaan Barang Jadi, Cr Persediaan WIP)', 1),
(7, 'beban_upah_langsung_pabrik', 44, 28, 'Pengakuan Akrual Upah Tenaga Kerja Produksi (Dr Beban Upah, Cr Utang Gaji)', 1),
(8, 'beban_gaji_operasional', 51, 28, 'Pengakuan Akrual Gaji Karyawan Kantor (Dr Beban Gaji Kantor, Cr Utang Gaji)', 1),
(9, 'pembayaran_gaji_karyawan', 28, 6, 'Pembayaran Payroll Bulanan Pegawai via Bank (Dr Utang Gaji, Cr Bank Mandiri Payroll)', 1);

SET FOREIGN_KEY_CHECKS = 1;
