<?php
/**
 * Shared Mayora Dashboard Header Layout
 * PT Mayora Indah Tbk
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

$user = currentUser();
$roleCode = $user['role_code'] ?? 'admin';

// Initials for avatar
$nameParts = preg_split('/\s+/', trim($user['full_name'] ?? 'Pengguna Mayora'));
$initials = '';
foreach ($nameParts as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    if (mb_strlen($initials) >= 2) break;
}
if (empty($initials)) $initials = 'MY';

// Default App Subtitles per Role
$defaultSubtitles = [
    'admin'      => 'Sistem Informasi Akuntansi & Operasional',
    'purchasing' => 'Sistem Informasi SDM dan Penggajian', // Exactly matches prototype HTML
    'ppic'       => 'Sistem Informasi Produksi & PPIC',
    'penjualan'  => 'Sistem Informasi Penerimaan & Penjualan',
    'gudang'     => 'Sistem Informasi Gudang & Persediaan',
    'qc'         => 'Sistem Informasi Pengendalian Mutu (QC)',
    'finance'    => 'Sistem Informasi Keuangan & Akuntansi',
    'hr'         => 'Sistem Informasi SDM dan Penggajian',
    'manager'    => 'Sistem Informasi Manajemen Operasional',
];
$displaySubTitle = $appSubTitle ?? ($defaultSubtitles[$roleCode] ?? 'Sistem Informasi Akuntansi & Operasional');

// Default Breadcrumb Section per Role
$defaultBreadcrumbs = [
    'admin'      => 'Perusahaan',
    'purchasing' => 'Pengeluaran',
    'ppic'       => 'Produksi',
    'penjualan'  => 'Penerimaan',
    'gudang'     => 'Gudang',
    'qc'         => 'Pengendalian Mutu',
    'finance'    => 'Keuangan',
    'hr'         => 'SDM & Penggajian',
    'manager'    => 'Manajemen',
];
$displayBreadcrumb = $breadcrumbSection ?? ($defaultBreadcrumbs[$roleCode] ?? 'Utama');
$notifCount = (int)($notifCount ?? getSystemNotificationCount($user));

if (!function_exists('renderMayoraNavItem')) {
    function renderMayoraNavItem($url, $label, $multiline = false) {
        $icon = '<svg class="w-4 h-4 text-[#8a94a6] fill-none stroke-current stroke-[1.7] shrink-0' . ($multiline ? ' mt-0.5' : '') . '" viewBox="0 0 24 24"><path d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" stroke-linecap="round" stroke-linejoin="round"></path></svg>';
        $flexAlign = $multiline ? 'items-start' : 'items-center';
        $spanClass = $multiline ? 'leading-tight' : '';
        return '<li><a class="flex ' . $flexAlign . ' gap-2.5 px-3 py-1.5 rounded-md text-[#596375] hover:bg-gray-50 transition-colors" href="' . e($url) . '">' . $icon . '<span class="' . $spanClass . '">' . $label . '</span></a></li>';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title><?= e($pageTitle ?? 'Dashboard') ?> - PT Mayora Indah Tbk.</title>
<!-- Tailwind CSS CDN -->
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<!-- Google Fonts: Plus Jakarta Sans for clean enterprise ERP look -->
<link href="https://fonts.googleapis.com" rel="preconnect"/>
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&amp;display=swap" rel="stylesheet"/>
<script data-purpose="tailwind-config">
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            sans: ['"Plus Jakarta Sans"', 'sans-serif'],
          },
          colors: {
            mayora: {
              red: '#EE2726',
              lightRed: '#FFF1F1',
              textDark: '#1E242B',
              textMuted: '#687588',
              borderLight: '#EDEDED',
            }
          }
        }
      }
    }
</script>
<style data-purpose="custom-typography-and-spacing">
    body {
      font-family: 'Plus Jakarta Sans', sans-serif;
      -webkit-font-smoothing: antialiased;
    }
    .menu-item-hover:hover {
      background-color: #fafafa;
    }
    ::-webkit-scrollbar {
      width: 6px;
      height: 6px;
    }
    ::-webkit-scrollbar-track {
      background: #fbfbfb;
    }
    ::-webkit-scrollbar-thumb {
      background: #e2e8f0;
      border-radius: 3px;
    }
    ::-webkit-scrollbar-thumb:hover {
      background: #cbd5e1;
    }
</style>
</head>
<body class="bg-white text-gray-900 flex h-screen overflow-hidden antialiased select-none">
<!-- BEGIN: LeftSidebar -->
<aside class="w-[230px] flex-shrink-0 border-r border-[#ececec] flex flex-col justify-between bg-white h-full z-10" data-purpose="sidebar-navigation">
<!-- Top section of sidebar -->
<div class="flex flex-col flex-1 min-h-0">
  <!-- Mayora Brand Header -->
  <div class="px-5 pt-4 pb-3 border-b border-[#f3f3f3]" data-purpose="brand-header">
    <div class="flex items-center gap-2 mb-2">
      <!-- Mayora Stylized Sun Logo Symbol -->
      <svg class="w-6 h-6 shrink-0 fill-[#EE2726]" viewBox="0 0 32 32">
        <!-- Left fan shape -->
        <path d="M7.5 10C5.5 13.5 4.8 17.5 5 21.5H13C12.8 17.5 10.8 13.5 7.5 10Z"></path>
        <!-- Middle fan shape -->
        <path d="M16 7C14.5 11.5 14.5 16.5 15.5 21.5H18.5C19.5 16.5 19.5 11.5 18 7Z"></path>
        <!-- Right fan shape -->
        <path d="M24.5 10C21.2 13.5 19.2 17.5 19 21.5H27C27.2 17.5 26.5 13.5 24.5 10Z"></path>
      </svg>
      <span class="font-extrabold tracking-wide text-lg text-[#EE2726] leading-none" style="font-family: Arial, Helvetica, sans-serif;">MAYORA</span>
    </div>
    <h1 class="text-[11px] font-bold text-[#2d3139] leading-snug">
      <?= e($displaySubTitle) ?>
    </h1>
    <p class="text-[10px] text-[#9098a5] mt-0.5">
      PT Mayora Indah Tbk.
    </p>
  </div>

  <!-- Navigation Menu -->
  <nav class="px-3 pt-3.5 flex-1 overflow-y-auto" data-purpose="main-nav">
    <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mb-2 px-2.5">
      MENU UTAMA
    </p>
    <ul class="space-y-0.5 text-[11.5px]">
      <!-- Active Item: Dashboard -->
      <li>
        <a class="flex items-center gap-2.5 px-3 py-1.5 rounded-lg bg-[#FFF2F2] text-[#EE2726] font-semibold transition-colors" href="index.php?page=dashboard/<?= e($roleCode) ?>">
          <svg class="w-4 h-4 stroke-[#EE2726] stroke-[2] fill-none" viewBox="0 0 24 24">
            <rect height="7" rx="1.5" width="7" x="3" y="3"></rect>
            <rect height="7" rx="1.5" width="7" x="14" y="3"></rect>
            <rect height="7" rx="1.5" width="7" x="14" y="14"></rect>
            <rect height="7" rx="1.5" width="7" x="3" y="14"></rect>
          </svg>
          <span>Dashboard</span>
        </a>
      </li>

      <?php if ($roleCode === 'purchasing'): ?>
        <!-- Menu Purchasing Sesuai Prototype Final -->
        <?= renderMayoraNavItem('index.php?page=master_data/suppliers', 'Master Vendor') ?>
        <?= renderMayoraNavItem('index.php?page=master_data/items', 'Master Bahan Baku') ?>
        <?= renderMayoraNavItem('index.php?page=expenditure_procurement/pr', 'PR disetujui') ?>
        <?= renderMayoraNavItem('index.php?page=expenditure_procurement/pr', 'Permintaan penawaran<br/>RFQ', true) ?>
        <?= renderMayoraNavItem('index.php?page=expenditure_procurement/po', 'Pesanan Pembelian') ?>
        <?= renderMayoraNavItem('index.php?page=expenditure_procurement/gr', 'Retur Pembelian') ?>

      <?php elseif ($roleCode === 'ppic'): ?>
        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">PRODUKSI & PPIC</p>
        <?= renderMayoraNavItem('index.php?page=production/orders', 'Perintah Produksi (SPK)') ?>
        <?= renderMayoraNavItem('index.php?page=production/material_issues', 'Pemakaian Bahan Baku') ?>
        <?= renderMayoraNavItem('index.php?page=production/machine_logs', 'Aktivitas Mesin') ?>
        <?= renderMayoraNavItem('index.php?page=production/outputs', 'Hasil Produksi & Scrap') ?>
        <?= renderMayoraNavItem('index.php?page=production/hpp_summary', 'Laporan Produksi & HPP') ?>
        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">DATA MASTER</p>
        <?= renderMayoraNavItem('index.php?page=master_data/boms', 'Resep / Formula BOM') ?>
        <?= renderMayoraNavItem('index.php?page=master_data/items', 'Data Barang & Bahan') ?>
        <?= renderMayoraNavItem('index.php?page=inventory/stock', 'Saldo & Mutasi Stok') ?>

      <?php elseif ($roleCode === 'penjualan'): ?>
        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">PENERIMAAN & PENJUALAN</p>
        <?= renderMayoraNavItem('index.php?page=receiving_sales/sales_orders', 'Pesanan & Sales Order') ?>
        <?= renderMayoraNavItem('index.php?page=receiving_sales/delivery_orders', 'Pengiriman & Surat Jalan (DO)') ?>
        <?= renderMayoraNavItem('index.php?page=receiving_sales/invoices', 'Faktur Penjualan') ?>
        <?= renderMayoraNavItem('index.php?page=receiving_sales/receivables', 'Piutang & Penagihan') ?>
        <?= renderMayoraNavItem('index.php?page=receiving_sales/receipts', 'Penerimaan Kas Pelanggan') ?>
        <?= renderMayoraNavItem('index.php?page=receiving_sales/returns', 'Retur & Nota Kredit') ?>
        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">DATA MASTER</p>
        <?= renderMayoraNavItem('index.php?page=master_data/customers', 'Master Pelanggan') ?>
        <?= renderMayoraNavItem('index.php?page=master_data/items', 'Katalog Produk Jadi') ?>

      <?php elseif ($roleCode === 'gudang'): ?>
        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">GUDANG & PERSEDIAAN</p>
        <?= renderMayoraNavItem('index.php?page=inventory/stock', 'Saldo & Mutasi Stok') ?>
        <?= renderMayoraNavItem('index.php?page=inventory/adjustments', 'Penyesuaian Stok (Opname)') ?>
        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">LOGISTIK FISIK</p>
        <?= renderMayoraNavItem('index.php?page=expenditure_procurement/gr', 'Penerimaan Barang (GR)') ?>
        <?= renderMayoraNavItem('index.php?page=receiving_sales/delivery_orders', 'Pengiriman Barang (DO)') ?>
        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">DATA MASTER</p>
        <?= renderMayoraNavItem('index.php?page=master_data/warehouses', 'Master Gudang & Lokasi') ?>
        <?= renderMayoraNavItem('index.php?page=master_data/items', 'Master Barang & Bahan') ?>

      <?php elseif ($roleCode === 'qc'): ?>
        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">PENGENDALIAN MUTU (QC)</p>
        <?= renderMayoraNavItem('index.php?page=production/qc_inspections', 'Pemeriksaan Mutu QC') ?>
        <?= renderMayoraNavItem('index.php?page=production/outputs', 'Hasil Produksi & Scrap') ?>
        <?= renderMayoraNavItem('index.php?page=expenditure_procurement/gr', 'Inspeksi Bahan Datang (GR)') ?>
        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">DATA MASTER</p>
        <?= renderMayoraNavItem('index.php?page=master_data/boms', 'Standar Formula BOM') ?>
        <?= renderMayoraNavItem('index.php?page=master_data/items', 'Spesifikasi Item') ?>

      <?php elseif ($roleCode === 'finance'): ?>
        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">KEUANGAN & AKUNTANSI</p>
        <?= renderMayoraNavItem('index.php?page=receiving_sales/receivables', 'Piutang & Penagihan') ?>
        <?= renderMayoraNavItem('index.php?page=receiving_sales/receipts', 'Penerimaan Kas Pelanggan') ?>
        <?= renderMayoraNavItem('index.php?page=expenditure_procurement/invoices', 'Faktur Pemasok & Utang') ?>
        <?= renderMayoraNavItem('index.php?page=expenditure_procurement/payment_requests', 'Permintaan Pembayaran') ?>
        <?= renderMayoraNavItem('index.php?page=expenditure_procurement/payments', 'Pelunasan Pemasok') ?>
        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">SDM & PAYROLL</p>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/salary_verification', 'Verifikasi Gaji') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/approvals', 'Otorisasi Gaji & Jurnal') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/terminations', 'Hak Akhir Karyawan') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/reports', 'Laporan Pajak & BPJS') ?>
        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">BUKU BESAR & LAPORAN</p>
        <?= renderMayoraNavItem('index.php?page=master_data/accounts', 'Akun Buku Besar') ?>
        <?= renderMayoraNavItem('index.php?page=reports/journals', 'Jurnal Umum Internal') ?>
        <?= renderMayoraNavItem('index.php?page=reports/index', 'Laporan Keuangan') ?>

      <?php elseif ($roleCode === 'hr'): ?>
        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">SDM & PENGGAJIAN</p>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/employees', 'Data Karyawan') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/attendance', 'Absensi & Aktivitas') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/leave_overtime', 'Pengajuan Cuti / Lembur') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/mutations', 'Mutasi & Promosi') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/trainings', 'Pelatihan Karyawan') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/payroll', 'Proses Payroll') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/salary_verification', 'Verifikasi Gaji') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/approvals', 'Persetujuan Gaji') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/payslips', 'Slip Gaji') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/terminations', 'Berkas Hak Akhir') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/reports', 'Laporan SDM & Gaji') ?>

      <?php elseif ($roleCode === 'manager'): ?>
        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">OPERASIONAL UTAMA</p>
        <?= renderMayoraNavItem('index.php?page=receiving_sales/sales_orders', 'Pesanan & Sales Order') ?>
        <?= renderMayoraNavItem('index.php?page=production/orders', 'Perintah Produksi (SPK)') ?>
        <?= renderMayoraNavItem('index.php?page=expenditure_procurement/po', 'Pesanan Pembelian (PO)') ?>
        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">OTORISASI SDM</p>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/mutations', 'Persetujuan Mutasi') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/trainings', 'Persetujuan Pelatihan') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/salary_verification', 'Verifikasi Gaji') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/approvals', 'Otorisasi Gaji (Payroll)') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/terminations', 'Verifikasi Hak Akhir') ?>
        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">LAPORAN & PENGAWASAN</p>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/reports', 'Laporan SDM & Jurnal') ?>
        <?= renderMayoraNavItem('index.php?page=reports/journals', 'Jurnal Akuntansi') ?>
        <?= renderMayoraNavItem('index.php?page=reports/index', 'Laporan Operasional') ?>
        <?= renderMayoraNavItem('index.php?page=reports/audit', 'Jejak Audit') ?>

      <?php else: /* admin */ ?>
        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">1. PENERIMAAN</p>
        <?= renderMayoraNavItem('index.php?page=receiving_sales/sales_orders', 'Pesanan &amp; Sales Order') ?>
        <?= renderMayoraNavItem('index.php?page=receiving_sales/delivery_orders', 'Pengiriman &amp; Surat Jalan') ?>
        <?= renderMayoraNavItem('index.php?page=receiving_sales/invoices', 'Faktur Penjualan') ?>
        <?= renderMayoraNavItem('index.php?page=receiving_sales/receivables', 'Piutang &amp; Penagihan') ?>

        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">2. PRODUKSI</p>
        <?= renderMayoraNavItem('index.php?page=production/orders', 'Rencana &amp; Perintah Produksi') ?>
        <?= renderMayoraNavItem('index.php?page=production/material_issues', 'Pemakaian Bahan Baku') ?>
        <?= renderMayoraNavItem('index.php?page=production/qc_inspections', 'Pemeriksaan QC') ?>
        <?= renderMayoraNavItem('index.php?page=production/outputs', 'Hasil Produksi &amp; Scrap') ?>

        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">3. PENGADAAN</p>
        <?= renderMayoraNavItem('index.php?page=expenditure_procurement/pr', 'Permintaan Pembelian (PR)') ?>
        <?= renderMayoraNavItem('index.php?page=expenditure_procurement/po', 'Purchase Order (PO)') ?>
        <?= renderMayoraNavItem('index.php?page=expenditure_procurement/gr', 'Penerimaan Barang (GR)') ?>

        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">4. SDM &amp; PENGGAJIAN</p>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/employees', 'Data Karyawan') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/mutations', 'Mutasi &amp; Promosi') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/trainings', 'Pelatihan Karyawan') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/payroll', 'Proses Payroll') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/salary_verification', 'Verifikasi Gaji') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/terminations', 'Hak Akhir Karyawan') ?>
        <?= renderMayoraNavItem('index.php?page=hr_payroll/reports', 'Laporan SDM &amp; Gaji') ?>

        <p class="text-[9.5px] font-bold tracking-wider text-[#98a2b3] uppercase mt-3 mb-1 px-2.5">DATA MASTER &amp; AUDIT</p>
        <?= renderMayoraNavItem('index.php?page=master_data/items', 'Master Barang &amp; Bahan') ?>
        <?= renderMayoraNavItem('index.php?page=inventory/stock', 'Saldo &amp; Mutasi Stok') ?>
        <?= renderMayoraNavItem('index.php?page=reports/journals', 'Jurnal Umum Internal') ?>
        <?= renderMayoraNavItem('index.php?page=reports/audit', 'Jejak Audit') ?>
        <?= renderMayoraNavItem('index.php?page=users/index', 'Pengguna &amp; Hak Akses') ?>
      <?php endif; ?>

      <!-- Tombol Keluar / Logout -->
      <li class="pt-3">
        <a class="flex items-center gap-2.5 px-3 py-1.5 rounded-md text-red-500 hover:bg-red-50 transition-colors" href="index.php?page=logout">
          <svg class="w-4 h-4 text-red-500 fill-none stroke-current stroke-[1.7]" viewBox="0 0 24 24">
            <path d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" stroke-linecap="round" stroke-linejoin="round"></path>
          </svg>
          <span>Keluar (Logout)</span>
        </a>
      </li>
    </ul>
  </nav>
</div>

<!-- Bottom footer inside sidebar -->
<div class="px-5 py-4 border-t border-[#f0f0f0]" data-purpose="sidebar-footer">
  <div class="flex items-center gap-2 text-[#596375] mb-1">
    <svg class="w-3.5 h-3.5 text-[#8a94a6] fill-none stroke-current stroke-[1.7]" viewBox="0 0 24 24">
      <path d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" stroke-linecap="round" stroke-linejoin="round"></path>
    </svg>
    <span class="text-[11.5px] font-medium text-[#4b5563]">Pabrik Tangerang</span>
  </div>
  <p class="text-[9.5px] text-[#9ca3af]">
    SISP Mayora - Internal v1.0
  </p>
</div>
</aside>
<!-- END: LeftSidebar -->

<!-- Content Container (Header + Main) -->
<div class="flex-1 flex flex-col h-full overflow-y-auto">
<!-- BEGIN: TopHeader -->
<header class="h-[52px] border-b border-[#ececec] px-6 flex items-center justify-between shrink-0 bg-white" data-purpose="top-navigation">
  <!-- Breadcrumbs navigation -->
  <nav aria-label="Breadcrumb" class="flex items-center gap-2 text-[11.5px] text-[#6b7280]">
    <a class="text-gray-400 hover:text-gray-600" href="index.php?page=dashboard/<?= e($roleCode) ?>">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
        <path d="M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" stroke-linecap="round" stroke-linejoin="round"></path>
      </svg>
    </a>
    <span class="text-gray-400 text-[10px]">&gt;</span>
    <span class="text-[#2b303a] font-normal"><?= e($displayBreadcrumb) ?>/ Dashboard</span>
  </nav>

  <!-- Right Header: Notification & User Info -->
  <div class="flex items-center gap-5">
    <!-- Notification Bell with Count -->
    <a href="index.php?page=dashboard/<?= e($roleCode) ?>" aria-label="Notifications" class="relative text-gray-400 hover:text-gray-600 p-1">
      <svg class="w-5 h-5 text-gray-400 stroke-[1.7]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" stroke-linecap="round" stroke-linejoin="round"></path>
      </svg>
      <?php if ($notifCount > 0): ?>
      <span class="absolute top-0 right-0 w-3.5 h-3.5 bg-[#EE2726] text-white rounded-full text-[9px] font-bold flex items-center justify-center translate-x-1 -translate-y-0.5">
        <?= $notifCount > 9 ? '9+' : $notifCount ?>
      </span>
      <?php endif; ?>
    </a>

    <!-- User Profile -->
    <div class="flex items-center gap-2.5" data-purpose="user-badge">
      <div class="w-7 h-7 rounded-full bg-[#FFE5E5] text-[#EE2726] font-bold text-[11px] flex items-center justify-center shrink-0">
        <?= e($initials) ?>
      </div>
      <div class="flex flex-col text-left">
        <span class="text-[12px] font-bold text-[#1f242e] leading-tight"><?= e($user['full_name'] ?? 'Pengguna') ?></span>
        <span class="text-[10px] text-[#9ca3af] leading-tight"><?= e($user['role_name'] ?? 'Staff') ?></span>
      </div>
      <a href="index.php?page=logout" title="Keluar" class="text-gray-400 hover:text-red-600 transition-colors ml-1">
        <svg class="w-4 h-4 stroke-[1.8]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" stroke-linecap="round" stroke-linejoin="round"></path>
        </svg>
      </a>
    </div>
  </div>
</header>
<!-- END: TopHeader -->

<!-- BEGIN: MainContent -->
<main class="flex-1 px-8 py-7 overflow-x-auto bg-white" data-purpose="dashboard-overview">
  <!-- Page Title -->
  <h2 class="text-[20px] font-bold text-[#111827] tracking-tight mb-8">
    <?= $pageTitle ?? 'Dashboard' ?>
  </h2>
