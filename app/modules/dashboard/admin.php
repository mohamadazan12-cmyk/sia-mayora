<?php
/**
 * Dashboard Utama Perusahaan (Administrator)
 * Sistem Informasi Akuntansi & Operasional PT Mayora Indah Tbk
 * Sesuai Acuan Desain Visual Prototype HTML Mayora
 */

requireRole(['Administrator', 'admin']);

$db = getDbConnection();
$user = currentUser();

// 1. Penerimaan & Penjualan
$salesCount = (int)$db->query("SELECT COUNT(*) FROM sales_orders WHERE status NOT IN ('cancelled')")->fetchColumn();
$unpaidInvoicesCount = (int)$db->query("SELECT COUNT(*) FROM sales_invoices WHERE status IN ('issued', 'partially_paid', 'overdue')")->fetchColumn();
$unpaidInvoicesTotal = (float)$db->query("SELECT COALESCE(SUM(balance), 0) FROM sales_invoices WHERE status IN ('issued', 'partially_paid', 'overdue')")->fetchColumn();
$receiptsTotal = (float)$db->query("SELECT COALESCE(SUM(total_amount), 0) FROM customer_receipts WHERE status = 'verified'")->fetchColumn();

// 2. Produksi
$prodPlanningCount = (int)$db->query("SELECT COUNT(*) FROM production_orders WHERE status IN ('draft', 'pending_approval', 'approved', 'waiting_material')")->fetchColumn();
$prodRunningCount  = (int)$db->query("SELECT COUNT(*) FROM production_orders WHERE status = 'in_progress'")->fetchColumn();
$prodQcCount       = (int)$db->query("SELECT COUNT(*) FROM production_orders WHERE status = 'waiting_qc'")->fetchColumn();
$prodDoneCount     = (int)$db->query("SELECT COUNT(*) FROM production_orders WHERE status = 'completed'")->fetchColumn();

// 3. Pengeluaran / Pengadaan
$openPrCount       = (int)$db->query("SELECT COUNT(*) FROM purchase_requisitions WHERE status IN ('draft', 'pending_approval', 'approved')")->fetchColumn();
$openPoCount       = (int)$db->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('approved', 'partially_received')")->fetchColumn();
$unpaidApTotal     = (float)$db->query("SELECT COALESCE(SUM(balance), 0) FROM supplier_invoices WHERE status IN ('verified', 'matched', 'partially_paid')")->fetchColumn();

// 4. SDM
$activeEmpCount    = (int)$db->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();
$pendingPayrollCount = (int)$db->query("SELECT COUNT(*) FROM payroll_periods WHERE status NOT IN ('transferred', 'closed')")->fetchColumn();

// 5. Barang di bawah stok minimum
$lowStockStmt = $db->query("
    SELECT i.code, i.name, i.min_stock, COALESCE(SUM(sb.qty_available), 0) AS total_qty, u.name AS unit_name
    FROM items i
    LEFT JOIN units u ON i.unit_id = u.id
    LEFT JOIN stock_balances sb ON i.id = sb.item_id
    WHERE i.is_active = 1
    GROUP BY i.id, i.code, i.name, i.min_stock, u.name
    HAVING total_qty <= i.min_stock
    ORDER BY total_qty ASC
    LIMIT 6
");
$lowStockItems = $lowStockStmt->fetchAll();
$lowStockCount = count($lowStockItems);

// 6. Transaksi Terbaru Lintas Bagian
$recentSo = $db->query("
    SELECT 'Penerimaan' AS section, so_number AS doc_number, so_date AS doc_date, u.full_name AS creator, status
    FROM sales_orders so
    LEFT JOIN users u ON so.created_by = u.id
    ORDER BY so.id DESC LIMIT 4
")->fetchAll();

$recentPo = $db->query("
    SELECT 'Produksi' AS section, po_number AS doc_number, planned_start AS doc_date, u.full_name AS creator, status
    FROM production_orders pdo
    LEFT JOIN users u ON pdo.created_by = u.id
    ORDER BY pdo.id DESC LIMIT 4
")->fetchAll();

$recentPurchases = $db->query("
    SELECT 'Pengadaan' AS section, po_number AS doc_number, po_date AS doc_date, u.full_name AS creator, status
    FROM purchase_orders po
    LEFT JOIN users u ON po.created_by = u.id
    ORDER BY po.id DESC LIMIT 4
")->fetchAll();

$recentJournals = $db->query("
    SELECT 'Akuntansi' AS section, entry_number AS doc_number, entry_date AS doc_date, u.full_name AS creator, status
    FROM journal_entries je
    LEFT JOIN users u ON je.created_by = u.id
    ORDER BY je.id DESC LIMIT 4
")->fetchAll();

$recentTransactions = array_merge($recentSo, $recentPo, $recentPurchases, $recentJournals);
usort($recentTransactions, function($a, $b) {
    return strcmp($b['doc_date'], $a['doc_date']);
});
$recentTransactions = array_slice($recentTransactions, 0, 7);

// 7. Ringkasan Status Approval Menunggu
$pendingApprovalsCount = (int)$db->query("
    SELECT (
        (SELECT COUNT(*) FROM purchase_requisitions WHERE status = 'pending_approval') +
        (SELECT COUNT(*) FROM purchase_orders WHERE status = 'pending_approval') +
        (SELECT COUNT(*) FROM production_orders WHERE status = 'pending_approval') +
        (SELECT COUNT(*) FROM sales_orders WHERE status = 'pending_approval') +
        (SELECT COUNT(*) FROM employee_mutations WHERE status = 'Menunggu') +
        (SELECT COUNT(*) FROM employee_trainings WHERE status = 'Menunggu') +
        (SELECT COUNT(*) FROM employee_terminations WHERE status = 'Menunggu Persetujuan') +
        (SELECT COUNT(*) FROM payroll_records WHERE status = 'calculated')
    ) AS total_pending
")->fetchColumn();

$pageTitle = 'Dashboard Utama Perusahaan';
$breadcrumbSection = 'Perusahaan';
$appSubTitle = 'Sistem Informasi Akuntansi & Operasional';
$notifCount = $pendingApprovalsCount;

include __DIR__ . '/../../includes/dashboard_header.php';
?>

<?php if ($pendingApprovalsCount > 0): ?>
  <div class="mb-8 p-4 rounded-lg bg-[#FFF2F2] border border-[#fbd5d5] flex items-center justify-between">
    <div class="flex items-center gap-3">
      <div class="w-8 h-8 rounded-full bg-[#FFE5E5] text-[#EE2726] flex items-center justify-center font-bold text-sm shrink-0">!</div>
      <div>
        <p class="text-[13px] font-bold text-[#1e242b]">Perhatian Otorisasi Operasional</p>
        <p class="text-[11.5px] text-[#687588]">Terdapat <?= $pendingApprovalsCount ?> transaksi menunggu persetujuan (approval) berjenjang lintas departemen.</p>
      </div>
    </div>
    <a href="index.php?page=dashboard/manager" class="px-3 py-1.5 rounded bg-[#EE2726] text-white text-[11px] font-semibold hover:bg-red-700 transition-colors">
      Pusat Otorisasi &rarr;
    </a>
  </div>
<?php endif; ?>

<!-- Metrics Row 1: 4 Columns (Siklus Utama) -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-8 max-w-5xl mb-10" data-purpose="metrics-summary">
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Pesanan Penjualan Aktif</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $salesCount ?></span>
    <span class="text-[11px] text-[#687588] mt-2">Sales Order dikonfirmasi</span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">SPK Produksi Berjalan</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $prodRunningCount ?></span>
    <span class="text-[11px] text-[#687588] mt-2">Lini pabrikasi aktif</span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Pengadaan (PO Terbuka)</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $openPoCount ?></span>
    <span class="text-[11px] text-[#687588] mt-2">Dalam pengiriman vendor</span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Karyawan Aktif</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $activeEmpCount ?></span>
    <span class="text-[11px] text-[#687588] mt-2">SDM seluruh divisi</span>
  </div>
</div>

<!-- Metrics Row 2: Keuangan & Kualitas -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-5xl mb-12">
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Piutang Belum Lunas (AR)</p>
    <span class="text-[26px] font-extrabold text-[#000000] tracking-tight leading-none"><?= formatRupiah($unpaidInvoicesTotal) ?></span>
    <span class="text-[11px] text-[#687588] mt-2"><?= $unpaidInvoicesCount ?> faktur jatuh tempo</span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Utang Pemasok (AP)</p>
    <span class="text-[26px] font-extrabold text-[#000000] tracking-tight leading-none"><?= formatRupiah($unpaidApTotal) ?></span>
    <span class="text-[11px] text-[#687588] mt-2">Tagihan vendor terverifikasi</span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Produk Menunggu QC</p>
    <span class="text-[26px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $prodQcCount ?></span>
    <span class="text-[11px] text-[#687588] mt-2">Inspeksi akhir lab mutu</span>
  </div>
</div>

<!-- BEGIN: Tabel Transaksi Terbaru Lintas Bagian -->
<section class="max-w-5xl mb-14" data-purpose="recent-transactions-section">
  <div class="flex items-center justify-between mb-5">
    <h3 class="text-[14px] font-bold text-[#111827]">
      Aktivitas &amp; Transaksi Terbaru Lintas Bagian
    </h3>
    <a href="index.php?page=reports/audit" class="text-[11.5px] font-semibold text-[#EE2726] hover:underline">
      Audit Trail Lengkap &rarr;
    </a>
  </div>
  <div class="w-full">
    <div class="grid grid-cols-6 gap-4 text-[12px] font-bold text-[#1e242b] pb-3 border-b border-gray-100">
      <div class="col-span-1 pl-4">No. Dokumen</div>
      <div class="col-span-1">Siklus / Modul</div>
      <div class="col-span-1">Tanggal</div>
      <div class="col-span-2">Pembuat / Penganggung Jawab</div>
      <div class="col-span-1 text-center">Status</div>
    </div>
    <?php if (empty($recentTransactions)): ?>
      <div class="w-full py-6 text-center text-xs text-gray-400">
        Belum ada riwayat transaksi tercatat.
      </div>
    <?php else: ?>
      <?php foreach ($recentTransactions as $tx): ?>
        <div class="grid grid-cols-6 gap-4 text-[11.5px] text-[#2d3139] py-3 border-b border-gray-50 hover:bg-gray-50/60 transition-colors items-center">
          <div class="col-span-1 pl-4 font-mono font-medium text-[#EE2726]"><?= e($tx['doc_number']) ?></div>
          <div class="col-span-1 font-medium text-gray-800"><?= e($tx['section']) ?></div>
          <div class="col-span-1 text-gray-500"><?= formatDate($tx['doc_date']) ?></div>
          <div class="col-span-2 text-gray-600 truncate"><?= e($tx['creator'] ?? 'Sistem') ?></div>
          <div class="col-span-1 text-center">
            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-700">
              <?= ucfirst(e($tx['status'])) ?>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<!-- BEGIN: Tabel Peringatan Stok Minimum -->
<section class="max-w-5xl mb-12" data-purpose="low-stock-section">
  <div class="flex items-center justify-between mb-5">
    <h3 class="text-[14px] font-bold text-[#111827]">
      Peringatan Stok di Bawah Batas Minimum
    </h3>
    <a href="index.php?page=inventory/stock" class="text-[11.5px] font-semibold text-[#EE2726] hover:underline">
      Periksa Persediaan &rarr;
    </a>
  </div>
  <div class="w-full">
    <div class="grid grid-cols-6 gap-4 text-[12px] font-bold text-[#1e242b] pb-3 border-b border-gray-100">
      <div class="col-span-1 pl-4">Kode Item</div>
      <div class="col-span-2">Nama Bahan / Barang</div>
      <div class="col-span-1 text-right">Batas Min</div>
      <div class="col-span-1 text-right">Stok Fisik</div>
      <div class="col-span-1 text-center">Status</div>
    </div>
    <?php if (empty($lowStockItems)): ?>
      <div class="w-full py-6 text-center text-xs text-gray-400">
        Semua persediaan aman di atas batas minimum stok.
      </div>
    <?php else: ?>
      <?php foreach ($lowStockItems as $it): ?>
        <div class="grid grid-cols-6 gap-4 text-[11.5px] text-[#2d3139] py-3 border-b border-gray-50 hover:bg-gray-50/60 transition-colors items-center">
          <div class="col-span-1 pl-4 font-mono font-medium text-gray-700"><?= e($it['code']) ?></div>
          <div class="col-span-2 font-medium text-gray-900"><?= e($it['name']) ?></div>
          <div class="col-span-1 text-right text-gray-500"><?= number_format($it['min_stock']) ?> <?= e($it['unit_name']) ?></div>
          <div class="col-span-1 text-right font-bold text-[#EE2726]"><?= number_format($it['total_qty']) ?> <?= e($it['unit_name']) ?></div>
          <div class="col-span-1 text-center">
            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold bg-red-50 text-red-600 border border-red-100">
              Kritis
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<?php
include __DIR__ . '/../../includes/dashboard_footer.php';
