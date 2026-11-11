<?php
/**
 * Left Navigation Sidebar (Multi-Role RBAC)
 * PT Mayora Indah Tbk. - Corporate ERP Design System
 */
$page = $_GET['page'] ?? 'dashboard';
$user = currentUser();
$roleCode = $user['role_code'] ?? 'admin';

// Helper to determine if a menu link is active
if (!function_exists('isNavActive')) {
    function isNavActive($currentPage, $targetPage) {
        if ($targetPage === 'dashboard' && ($currentPage === 'dashboard' || strpos($currentPage, 'dashboard/') === 0)) {
            return true;
        }
        return strpos($currentPage, $targetPage) === 0;
    }
}
?>
<aside class="side sidebar">
    <div style="padding: 4px 6px 12px; border-bottom: 1px solid #f1f2f6; margin-bottom: 6px;">
        <img src="assets/logo.png" class="slogo" alt="Mayora" onerror="this.style.display='none'">
        <div class="stitle">
            <?= $roleCode === 'hr' ? 'Sistem Informasi SDM &amp; Penggajian' : 'Sistem Informasi Akuntansi' ?>
        </div>
        <div class="ssub">PT Mayora Indah Tbk.</div>
    </div>

    <nav class="sidebar-nav" style="display:flex; flex-direction:column; gap:2px;">
        <!-- 1. Menu Dashboard (Selalu menjadi menu pertama) -->
        <a href="index.php?page=dashboard" class="nav-item <?= isNavActive($page, 'dashboard') ? 'active on' : '' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:2px;"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>

        <!-- ============================================================== -->
        <!-- SIDEBAR ROLE: ADMINISTRATOR SISTEM -->
        <!-- ============================================================== -->
        <?php if ($roleCode === 'admin'): ?>
            <div class="smenu nav-section-title">1. PENERIMAAN</div>
            <a href="index.php?page=receiving_sales/sales_orders" class="nav-subitem <?= isNavActive($page, 'receiving_sales/sales_orders') ? 'active on' : '' ?>">
                &bull; Pesanan &amp; Sales Order
            </a>
            <a href="index.php?page=receiving_sales/delivery_orders" class="nav-subitem <?= isNavActive($page, 'receiving_sales/delivery_orders') ? 'active on' : '' ?>">
                &bull; Pengiriman &amp; Surat Jalan
            </a>
            <a href="index.php?page=receiving_sales/invoices" class="nav-subitem <?= isNavActive($page, 'receiving_sales/invoices') ? 'active on' : '' ?>">
                &bull; Faktur Penjualan
            </a>
            <a href="index.php?page=receiving_sales/receivables" class="nav-subitem <?= isNavActive($page, 'receiving_sales/receivables') ? 'active on' : '' ?>">
                &bull; Piutang &amp; Penagihan
            </a>
            <a href="index.php?page=receiving_sales/receipts" class="nav-subitem <?= isNavActive($page, 'receiving_sales/receipts') ? 'active on' : '' ?>">
                &bull; Penerimaan Kas Pelanggan
            </a>
            <a href="index.php?page=receiving_sales/returns" class="nav-subitem <?= isNavActive($page, 'receiving_sales/returns') ? 'active on' : '' ?>">
                &bull; Retur &amp; Nota Kredit
            </a>

            <div class="smenu nav-section-title">2. PRODUKSI</div>
            <a href="index.php?page=production/orders" class="nav-subitem <?= isNavActive($page, 'production/orders') ? 'active on' : '' ?>">
                &bull; Rencana &amp; Perintah Produksi
            </a>
            <a href="index.php?page=production/material_issues" class="nav-subitem <?= isNavActive($page, 'production/material_issues') ? 'active on' : '' ?>">
                &bull; Pemakaian Bahan Baku
            </a>
            <a href="index.php?page=production/machine_logs" class="nav-subitem <?= isNavActive($page, 'production/machine_logs') ? 'active on' : '' ?>">
                &bull; Aktivitas Mesin
            </a>
            <a href="index.php?page=production/qc_inspections" class="nav-subitem <?= isNavActive($page, 'production/qc_inspections') ? 'active on' : '' ?>">
                &bull; Pemeriksaan QC
            </a>
            <a href="index.php?page=production/outputs" class="nav-subitem <?= isNavActive($page, 'production/outputs') ? 'active on' : '' ?>">
                &bull; Hasil Produksi &amp; Scrap
            </a>
            <a href="index.php?page=production/hpp_summary" class="nav-subitem <?= isNavActive($page, 'production/hpp_summary') ? 'active on' : '' ?>">
                &bull; Laporan Produksi &amp; HPP
            </a>

            <div class="smenu nav-section-title">3. PENGADAAN &amp; KELUAR</div>
            <a href="index.php?page=expenditure_procurement/pr" class="nav-subitem <?= isNavActive($page, 'expenditure_procurement/pr') ? 'active on' : '' ?>">
                &bull; Permintaan Pembelian (PR)
            </a>
            <a href="index.php?page=expenditure_procurement/po" class="nav-subitem <?= isNavActive($page, 'expenditure_procurement/po') ? 'active on' : '' ?>">
                &bull; Purchase Order (PO)
            </a>
            <a href="index.php?page=expenditure_procurement/gr" class="nav-subitem <?= isNavActive($page, 'expenditure_procurement/gr') ? 'active on' : '' ?>">
                &bull; Penerimaan Barang (GR)
            </a>
            <a href="index.php?page=expenditure_procurement/invoices" class="nav-subitem <?= isNavActive($page, 'expenditure_procurement/invoices') ? 'active on' : '' ?>">
                &bull; Faktur Pemasok &amp; Utang
            </a>
            <a href="index.php?page=expenditure_procurement/payment_requests" class="nav-subitem <?= isNavActive($page, 'expenditure_procurement/payment_requests') ? 'active on' : '' ?>">
                &bull; Permintaan Pembayaran
            </a>
            <a href="index.php?page=expenditure_procurement/payments" class="nav-subitem <?= isNavActive($page, 'expenditure_procurement/payments') ? 'active on' : '' ?>">
                &bull; Pelunasan / Bayar Pemasok
            </a>

            <div class="smenu nav-section-title">4. SDM &amp; PENGGAJIAN</div>
            <a href="index.php?page=hr_payroll/employees" class="nav-subitem <?= isNavActive($page, 'hr_payroll/employees') ? 'active on' : '' ?>">
                &bull; Data Karyawan
            </a>
            <a href="index.php?page=hr_payroll/attendance" class="nav-subitem <?= isNavActive($page, 'hr_payroll/attendance') ? 'active on' : '' ?>">
                &bull; Absensi &amp; Aktivitas
            </a>
            <a href="index.php?page=hr_payroll/leave_overtime" class="nav-subitem <?= isNavActive($page, 'hr_payroll/leave_overtime') ? 'active on' : '' ?>">
                &bull; Pengajuan Cuti / Lembur
            </a>
            <a href="index.php?page=hr_payroll/mutations" class="nav-subitem <?= isNavActive($page, 'hr_payroll/mutations') ? 'active on' : '' ?>">
                &bull; Mutasi &amp; Promosi
            </a>
            <a href="index.php?page=hr_payroll/trainings" class="nav-subitem <?= isNavActive($page, 'hr_payroll/trainings') ? 'active on' : '' ?>">
                &bull; Pelatihan Karyawan
            </a>
            <a href="index.php?page=hr_payroll/payroll" class="nav-subitem <?= isNavActive($page, 'hr_payroll/payroll') ? 'active on' : '' ?>">
                &bull; Proses Payroll
            </a>
            <a href="index.php?page=hr_payroll/salary_verification" class="nav-subitem <?= isNavActive($page, 'hr_payroll/salary_verification') ? 'active on' : '' ?>">
                &bull; Verifikasi Gaji
            </a>
            <a href="index.php?page=hr_payroll/approvals" class="nav-subitem <?= isNavActive($page, 'hr_payroll/approvals') ? 'active on' : '' ?>">
                &bull; Otorisasi Gaji &amp; Jurnal
            </a>
            <a href="index.php?page=hr_payroll/payslips" class="nav-subitem <?= isNavActive($page, 'hr_payroll/payslips') ? 'active on' : '' ?>">
                &bull; Slip Gaji
            </a>
            <a href="index.php?page=hr_payroll/terminations" class="nav-subitem <?= isNavActive($page, 'hr_payroll/terminations') ? 'active on' : '' ?>">
                &bull; Hak Akhir / Pemberhentian
            </a>
            <a href="index.php?page=hr_payroll/reports" class="nav-subitem <?= isNavActive($page, 'hr_payroll/reports') ? 'active on' : '' ?>">
                &bull; Laporan SDM &amp; Gaji
            </a>

            <div class="smenu nav-section-title">DATA MASTER &amp; AUDIT</div>
            <a href="index.php?page=master_data/items" class="nav-subitem <?= isNavActive($page, 'master_data/items') ? 'active on' : '' ?>">
                &bull; Barang &amp; Bahan
            </a>
            <a href="index.php?page=master_data/units" class="nav-subitem <?= isNavActive($page, 'master_data/units') ? 'active on' : '' ?>">
                &bull; Satuan Ukuran
            </a>
            <a href="index.php?page=master_data/customers" class="nav-subitem <?= isNavActive($page, 'master_data/customers') ? 'active on' : '' ?>">
                &bull; Pelanggan / Distributor
            </a>
            <a href="index.php?page=master_data/suppliers" class="nav-subitem <?= isNavActive($page, 'master_data/suppliers') ? 'active on' : '' ?>">
                &bull; Pemasok / Vendor
            </a>
            <a href="index.php?page=master_data/warehouses" class="nav-subitem <?= isNavActive($page, 'master_data/warehouses') ? 'active on' : '' ?>">
                &bull; Gudang &amp; Lokasi
            </a>
            <a href="index.php?page=master_data/boms" class="nav-subitem <?= isNavActive($page, 'master_data/boms') ? 'active on' : '' ?>">
                &bull; Resep / Formula BOM
            </a>
            <a href="index.php?page=master_data/departments" class="nav-subitem <?= isNavActive($page, 'master_data/departments') ? 'active on' : '' ?>">
                &bull; Departemen Kerja
            </a>
            <a href="index.php?page=master_data/positions" class="nav-subitem <?= isNavActive($page, 'master_data/positions') ? 'active on' : '' ?>">
                &bull; Jabatan Organisasi
            </a>
            <a href="index.php?page=master_data/accounts" class="nav-subitem <?= isNavActive($page, 'master_data/accounts') ? 'active on' : '' ?>">
                &bull; Akun Buku Besar
            </a>
            <a href="index.php?page=inventory/stock" class="nav-subitem <?= isNavActive($page, 'inventory/stock') ? 'active on' : '' ?>">
                &bull; Saldo &amp; Mutasi Stok
            </a>
            <a href="index.php?page=reports/journals" class="nav-subitem <?= isNavActive($page, 'reports/journals') ? 'active on' : '' ?>">
                &bull; Jurnal Umum Internal
            </a>
            <a href="index.php?page=reports/audit" class="nav-subitem <?= isNavActive($page, 'reports/audit') ? 'active on' : '' ?>">
                &bull; Jejak Audit (Audit Trail)
            </a>
            <a href="index.php?page=users/index" class="nav-subitem <?= isNavActive($page, 'users/index') ? 'active on' : '' ?>">
                &bull; Pengguna &amp; Hak Akses
            </a>

        <!-- ============================================================== -->
        <!-- SIDEBAR ROLE: PURCHASING / PETUGAS PENGADAAN -->
        <!-- ============================================================== -->
        <?php elseif ($roleCode === 'purchasing'): ?>
            <div class="smenu nav-section-title">PENGADAAN</div>
            <a href="index.php?page=expenditure_procurement/pr" class="nav-subitem <?= isNavActive($page, 'expenditure_procurement/pr') ? 'active on' : '' ?>">
                &bull; PR Disetujui
            </a>
            <a href="index.php?page=expenditure_procurement/po" class="nav-subitem <?= isNavActive($page, 'expenditure_procurement/po') ? 'active on' : '' ?>">
                &bull; Pesanan Pembelian (PO)
            </a>
            <a href="index.php?page=expenditure_procurement/gr" class="nav-subitem <?= isNavActive($page, 'expenditure_procurement/gr') ? 'active on' : '' ?>">
                &bull; Penerimaan Barang (GR)
            </a>

            <div class="smenu nav-section-title">DATA MASTER</div>
            <a href="index.php?page=master_data/suppliers" class="nav-subitem <?= isNavActive($page, 'master_data/suppliers') ? 'active on' : '' ?>">
                &bull; Master Vendor
            </a>
            <a href="index.php?page=master_data/items" class="nav-subitem <?= isNavActive($page, 'master_data/items') ? 'active on' : '' ?>">
                &bull; Master Bahan Baku
            </a>

        <!-- ============================================================== -->
        <!-- SIDEBAR ROLE: PPIC / PERENCANA PRODUKSI -->
        <!-- ============================================================== -->
        <?php elseif ($roleCode === 'ppic'): ?>
            <div class="smenu nav-section-title">PRODUKSI &amp; PPIC</div>
            <a href="index.php?page=production/orders" class="nav-subitem <?= isNavActive($page, 'production/orders') ? 'active on' : '' ?>">
                &bull; Perintah Produksi (SPK)
            </a>
            <a href="index.php?page=production/material_issues" class="nav-subitem <?= isNavActive($page, 'production/material_issues') ? 'active on' : '' ?>">
                &bull; Pemakaian Bahan Baku
            </a>
            <a href="index.php?page=production/machine_logs" class="nav-subitem <?= isNavActive($page, 'production/machine_logs') ? 'active on' : '' ?>">
                &bull; Aktivitas Mesin
            </a>
            <a href="index.php?page=production/outputs" class="nav-subitem <?= isNavActive($page, 'production/outputs') ? 'active on' : '' ?>">
                &bull; Hasil Produksi &amp; Scrap
            </a>
            <a href="index.php?page=production/hpp_summary" class="nav-subitem <?= isNavActive($page, 'production/hpp_summary') ? 'active on' : '' ?>">
                &bull; Laporan Produksi &amp; HPP
            </a>

            <div class="smenu nav-section-title">DATA MASTER &amp; STOK</div>
            <a href="index.php?page=master_data/boms" class="nav-subitem <?= isNavActive($page, 'master_data/boms') ? 'active on' : '' ?>">
                &bull; Resep / Formula BOM
            </a>
            <a href="index.php?page=master_data/items" class="nav-subitem <?= isNavActive($page, 'master_data/items') ? 'active on' : '' ?>">
                &bull; Data Barang &amp; Bahan
            </a>
            <a href="index.php?page=inventory/stock" class="nav-subitem <?= isNavActive($page, 'inventory/stock') ? 'active on' : '' ?>">
                &bull; Saldo &amp; Mutasi Stok
            </a>

        <!-- ============================================================== -->
        <!-- SIDEBAR ROLE: PENJUALAN / PETUGAS PENJUALAN -->
        <!-- ============================================================== -->
        <?php elseif ($roleCode === 'penjualan'): ?>
            <div class="smenu nav-section-title">PENERIMAAN &amp; PENJUALAN</div>
            <a href="index.php?page=receiving_sales/sales_orders" class="nav-subitem <?= isNavActive($page, 'receiving_sales/sales_orders') ? 'active on' : '' ?>">
                &bull; Pesanan &amp; Sales Order
            </a>
            <a href="index.php?page=receiving_sales/delivery_orders" class="nav-subitem <?= isNavActive($page, 'receiving_sales/delivery_orders') ? 'active on' : '' ?>">
                &bull; Pengiriman &amp; DO
            </a>
            <a href="index.php?page=receiving_sales/invoices" class="nav-subitem <?= isNavActive($page, 'receiving_sales/invoices') ? 'active on' : '' ?>">
                &bull; Faktur Penjualan
            </a>
            <a href="index.php?page=receiving_sales/receivables" class="nav-subitem <?= isNavActive($page, 'receiving_sales/receivables') ? 'active on' : '' ?>">
                &bull; Piutang &amp; Penagihan
            </a>
            <a href="index.php?page=receiving_sales/receipts" class="nav-subitem <?= isNavActive($page, 'receiving_sales/receipts') ? 'active on' : '' ?>">
                &bull; Penerimaan Kas Pelanggan
            </a>
            <a href="index.php?page=receiving_sales/returns" class="nav-subitem <?= isNavActive($page, 'receiving_sales/returns') ? 'active on' : '' ?>">
                &bull; Retur &amp; Nota Kredit
            </a>

            <div class="smenu nav-section-title">DATA MASTER</div>
            <a href="index.php?page=master_data/customers" class="nav-subitem <?= isNavActive($page, 'master_data/customers') ? 'active on' : '' ?>">
                &bull; Master Pelanggan
            </a>
            <a href="index.php?page=master_data/items" class="nav-subitem <?= isNavActive($page, 'master_data/items') ? 'active on' : '' ?>">
                &bull; Katalog Produk Jadi
            </a>

        <!-- ============================================================== -->
        <!-- SIDEBAR ROLE: GUDANG / PETUGAS GUDANG -->
        <!-- ============================================================== -->
        <?php elseif ($roleCode === 'gudang'): ?>
            <div class="smenu nav-section-title">GUDANG &amp; PERSEDIAAN</div>
            <a href="index.php?page=inventory/stock" class="nav-subitem <?= isNavActive($page, 'inventory/stock') ? 'active on' : '' ?>">
                &bull; Saldo &amp; Mutasi Stok
            </a>
            <a href="index.php?page=inventory/adjustments" class="nav-subitem <?= isNavActive($page, 'inventory/adjustments') ? 'active on' : '' ?>">
                &bull; Penyesuaian Stok (Opname)
            </a>

            <div class="smenu nav-section-title">LOGISTIK FISIK</div>
            <a href="index.php?page=expenditure_procurement/gr" class="nav-subitem <?= isNavActive($page, 'expenditure_procurement/gr') ? 'active on' : '' ?>">
                &bull; Penerimaan Barang (GR)
            </a>
            <a href="index.php?page=receiving_sales/delivery_orders" class="nav-subitem <?= isNavActive($page, 'receiving_sales/delivery_orders') ? 'active on' : '' ?>">
                &bull; Pengiriman Barang (DO)
            </a>

            <div class="smenu nav-section-title">DATA MASTER</div>
            <a href="index.php?page=master_data/warehouses" class="nav-subitem <?= isNavActive($page, 'master_data/warehouses') ? 'active on' : '' ?>">
                &bull; Master Gudang &amp; Lokasi
            </a>
            <a href="index.php?page=master_data/items" class="nav-subitem <?= isNavActive($page, 'master_data/items') ? 'active on' : '' ?>">
                &bull; Master Barang &amp; Bahan
            </a>

        <!-- ============================================================== -->
        <!-- SIDEBAR ROLE: QC / PETUGAS QUALITY CONTROL -->
        <!-- ============================================================== -->
        <?php elseif ($roleCode === 'qc'): ?>
            <div class="smenu nav-section-title">PENGENDALIAN MUTU (QC)</div>
            <a href="index.php?page=production/qc_inspections" class="nav-subitem <?= isNavActive($page, 'production/qc_inspections') ? 'active on' : '' ?>">
                &bull; Pemeriksaan Mutu QC
            </a>
            <a href="index.php?page=production/outputs" class="nav-subitem <?= isNavActive($page, 'production/outputs') ? 'active on' : '' ?>">
                &bull; Hasil Produksi &amp; Scrap
            </a>
            <a href="index.php?page=expenditure_procurement/gr" class="nav-subitem <?= isNavActive($page, 'expenditure_procurement/gr') ? 'active on' : '' ?>">
                &bull; Inspeksi Bahan Datang (GR)
            </a>

            <div class="smenu nav-section-title">DATA REFERENSI</div>
            <a href="index.php?page=master_data/boms" class="nav-subitem <?= isNavActive($page, 'master_data/boms') ? 'active on' : '' ?>">
                &bull; Standar Formula BOM
            </a>
            <a href="index.php?page=master_data/items" class="nav-subitem <?= isNavActive($page, 'master_data/items') ? 'active on' : '' ?>">
                &bull; Spesifikasi Item
            </a>

        <!-- ============================================================== -->
        <!-- SIDEBAR ROLE: FINANCE / KEUANGAN & AKUNTANSI -->
        <!-- ============================================================== -->
        <?php elseif ($roleCode === 'finance'): ?>
            <div class="smenu nav-section-title">KEUANGAN &amp; AKUNTANSI</div>
            <a href="index.php?page=receiving_sales/receivables" class="nav-subitem <?= isNavActive($page, 'receiving_sales/receivables') ? 'active on' : '' ?>">
                &bull; Piutang &amp; Penagihan
            </a>
            <a href="index.php?page=receiving_sales/receipts" class="nav-subitem <?= isNavActive($page, 'receiving_sales/receipts') ? 'active on' : '' ?>">
                &bull; Penerimaan Kas Pelanggan
            </a>
            <a href="index.php?page=expenditure_procurement/invoices" class="nav-subitem <?= isNavActive($page, 'expenditure_procurement/invoices') ? 'active on' : '' ?>">
                &bull; Faktur Pemasok &amp; Utang
            </a>
            <a href="index.php?page=expenditure_procurement/payment_requests" class="nav-subitem <?= isNavActive($page, 'expenditure_procurement/payment_requests') ? 'active on' : '' ?>">
                &bull; Permintaan Pembayaran
            </a>
            <a href="index.php?page=expenditure_procurement/payments" class="nav-subitem <?= isNavActive($page, 'expenditure_procurement/payments') ? 'active on' : '' ?>">
                &bull; Pelunasan Pemasok
            </a>

            <div class="smenu nav-section-title">SDM &amp; PAYROLL</div>
            <a href="index.php?page=hr_payroll/salary_verification" class="nav-subitem <?= isNavActive($page, 'hr_payroll/salary_verification') ? 'active on' : '' ?>">
                &bull; Verifikasi Gaji
            </a>
            <a href="index.php?page=hr_payroll/approvals" class="nav-subitem <?= isNavActive($page, 'hr_payroll/approvals') ? 'active on' : '' ?>">
                &bull; Otorisasi Gaji &amp; Jurnal
            </a>
            <a href="index.php?page=hr_payroll/terminations" class="nav-subitem <?= isNavActive($page, 'hr_payroll/terminations') ? 'active on' : '' ?>">
                &bull; Hak Akhir Karyawan
            </a>
            <a href="index.php?page=hr_payroll/reports" class="nav-subitem <?= isNavActive($page, 'hr_payroll/reports') ? 'active on' : '' ?>">
                &bull; Laporan SDM, Pajak &amp; BPJS
            </a>

            <div class="smenu nav-section-title">BUKU BESAR &amp; LAPORAN</div>
            <a href="index.php?page=master_data/accounts" class="nav-subitem <?= isNavActive($page, 'master_data/accounts') ? 'active on' : '' ?>">
                &bull; Akun Buku Besar
            </a>
            <a href="index.php?page=reports/journals" class="nav-subitem <?= isNavActive($page, 'reports/journals') ? 'active on' : '' ?>">
                &bull; Jurnal Umum Internal
            </a>
            <a href="index.php?page=reports/index" class="nav-subitem <?= isNavActive($page, 'reports/index') ? 'active on' : '' ?>">
                &bull; Laporan Keuangan
            </a>

        <!-- ============================================================== -->
        <!-- SIDEBAR ROLE: HR / PAYROLL -->
        <!-- ============================================================== -->
        <?php elseif ($roleCode === 'hr'): ?>
            <div class="smenu nav-section-title">SDM &amp; PENGGAJIAN</div>
            <a href="index.php?page=hr_payroll/employees" class="nav-subitem <?= isNavActive($page, 'hr_payroll/employees') ? 'active on' : '' ?>">
                &bull; Data Karyawan
            </a>
            <a href="index.php?page=hr_payroll/attendance" class="nav-subitem <?= isNavActive($page, 'hr_payroll/attendance') ? 'active on' : '' ?>">
                &bull; Absensi &amp; Aktivitas
            </a>
            <a href="index.php?page=hr_payroll/leave_overtime" class="nav-subitem <?= isNavActive($page, 'hr_payroll/leave_overtime') ? 'active on' : '' ?>">
                &bull; Pengajuan Cuti / Lembur
            </a>
            <a href="index.php?page=hr_payroll/mutations" class="nav-subitem <?= isNavActive($page, 'hr_payroll/mutations') ? 'active on' : '' ?>">
                &bull; Usulan Mutasi &amp; Promosi
            </a>
            <a href="index.php?page=hr_payroll/trainings" class="nav-subitem <?= isNavActive($page, 'hr_payroll/trainings') ? 'active on' : '' ?>">
                &bull; Program Pelatihan
            </a>
            <a href="index.php?page=hr_payroll/payroll" class="nav-subitem <?= isNavActive($page, 'hr_payroll/payroll') ? 'active on' : '' ?>">
                &bull; Proses Payroll
            </a>
            <a href="index.php?page=hr_payroll/salary_verification" class="nav-subitem <?= isNavActive($page, 'hr_payroll/salary_verification') ? 'active on' : '' ?>">
                &bull; Verifikasi Gaji
            </a>
            <a href="index.php?page=hr_payroll/approvals" class="nav-subitem <?= isNavActive($page, 'hr_payroll/approvals') ? 'active on' : '' ?>">
                &bull; Persetujuan Gaji
            </a>
            <a href="index.php?page=hr_payroll/payslips" class="nav-subitem <?= isNavActive($page, 'hr_payroll/payslips') ? 'active on' : '' ?>">
                &bull; Slip Gaji
            </a>
            <a href="index.php?page=hr_payroll/terminations" class="nav-subitem <?= isNavActive($page, 'hr_payroll/terminations') ? 'active on' : '' ?>">
                &bull; Berkas Hak Akhir
            </a>
            <a href="index.php?page=hr_payroll/reports" class="nav-subitem <?= isNavActive($page, 'hr_payroll/reports') ? 'active on' : '' ?>">
                &bull; Laporan SDM &amp; Gaji
            </a>

            <div class="smenu nav-section-title">STRUKTUR ORGANISASI</div>
            <a href="index.php?page=master_data/departments" class="nav-subitem <?= isNavActive($page, 'master_data/departments') ? 'active on' : '' ?>">
                &bull; Master Departemen
            </a>
            <a href="index.php?page=master_data/positions" class="nav-subitem <?= isNavActive($page, 'master_data/positions') ? 'active on' : '' ?>">
                &bull; Master Jabatan
            </a>

        <!-- ============================================================== -->
        <!-- SIDEBAR ROLE: MANAGER / SUPERVISOR OPERASIONAL -->
        <!-- ============================================================== -->
        <?php elseif ($roleCode === 'manager'): ?>
            <div class="smenu nav-section-title">OPERASIONAL UTAMA</div>
            <a href="index.php?page=receiving_sales/sales_orders" class="nav-subitem <?= isNavActive($page, 'receiving_sales/sales_orders') ? 'active on' : '' ?>">
                &bull; Pesanan &amp; Sales Order
            </a>
            <a href="index.php?page=production/orders" class="nav-subitem <?= isNavActive($page, 'production/orders') ? 'active on' : '' ?>">
                &bull; Perintah Produksi (SPK)
            </a>
            <a href="index.php?page=expenditure_procurement/po" class="nav-subitem <?= isNavActive($page, 'expenditure_procurement/po') ? 'active on' : '' ?>">
                &bull; Pesanan Pembelian (PO)
            </a>

            <div class="smenu nav-section-title">OTORISASI SDM</div>
            <a href="index.php?page=hr_payroll/mutations" class="nav-subitem <?= isNavActive($page, 'hr_payroll/mutations') ? 'active on' : '' ?>">
                &bull; Persetujuan Mutasi
            </a>
            <a href="index.php?page=hr_payroll/trainings" class="nav-subitem <?= isNavActive($page, 'hr_payroll/trainings') ? 'active on' : '' ?>">
                &bull; Persetujuan Pelatihan
            </a>
            <a href="index.php?page=hr_payroll/salary_verification" class="nav-subitem <?= isNavActive($page, 'hr_payroll/salary_verification') ? 'active on' : '' ?>">
                &bull; Verifikasi Gaji
            </a>
            <a href="index.php?page=hr_payroll/approvals" class="nav-subitem <?= isNavActive($page, 'hr_payroll/approvals') ? 'active on' : '' ?>">
                &bull; Otorisasi Gaji (Payroll)
            </a>
            <a href="index.php?page=hr_payroll/terminations" class="nav-subitem <?= isNavActive($page, 'hr_payroll/terminations') ? 'active on' : '' ?>">
                &bull; Verifikasi Hak Akhir
            </a>

            <div class="smenu nav-section-title">LAPORAN &amp; PENGAWASAN</div>
            <a href="index.php?page=hr_payroll/reports" class="nav-subitem <?= isNavActive($page, 'hr_payroll/reports') ? 'active on' : '' ?>">
                &bull; Laporan SDM &amp; Jurnal
            </a>
            <a href="index.php?page=reports/journals" class="nav-subitem <?= isNavActive($page, 'reports/journals') ? 'active on' : '' ?>">
                &bull; Jurnal Akuntansi
            </a>
            <a href="index.php?page=reports/index" class="nav-subitem <?= isNavActive($page, 'reports/index') ? 'active on' : '' ?>">
                &bull; Laporan Operasional
            </a>
            <a href="index.php?page=reports/audit" class="nav-subitem <?= isNavActive($page, 'reports/audit') ? 'active on' : '' ?>">
                &bull; Jejak Audit (Audit Trail)
            </a>
        <?php endif; ?>
    </nav>

    <div class="sfoot sidebar-footer">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21V9l6 4V9l6 4V5h6v16z"/></svg>
        <span>Pabrik Tangerang</span>
        <small>SIA PT Mayora Indah Tbk &bull; v1.0</small>
    </div>
</aside>
