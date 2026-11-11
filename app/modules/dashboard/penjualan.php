<?php
/**
 * Dashboard Penerimaan dan Penjualan (Sales)
 * Sistem Informasi Akuntansi & Operasional PT Mayora Indah Tbk
 * Sesuai Acuan Desain Visual Prototype HTML Mayora
 */

requireRole(['Administrator', 'admin', 'Petugas Penjualan / Piutang', 'penjualan', 'Supervisor / Manajer', 'manager', 'manajer']);

$db = getDbConnection();
$user = currentUser();

// Metrics
// 1. Total Pesanan Penjualan (SO Aktif)
$soTotalCount = (int)$db->query("SELECT COUNT(*) FROM sales_orders WHERE status NOT IN ('cancelled')")->fetchColumn();

// 2. Pesanan Menunggu Pemrosesan (draft / pending_approval)
$soPendingCount = (int)$db->query("SELECT COUNT(*) FROM sales_orders WHERE status IN ('draft', 'pending_approval')")->fetchColumn();

// 3. Pengiriman Barang (DO)
$doActiveCount = (int)$db->query("SELECT COUNT(*) FROM delivery_orders WHERE status IN ('draft', 'sent', 'partially_delivered')")->fetchColumn();

// 4. Total Piutang Pelanggan (AR)
$totalReceivables = (float)$db->query("SELECT COALESCE(SUM(balance), 0) FROM sales_invoices WHERE status IN ('issued', 'partially_paid', 'overdue')")->fetchColumn();

// 5. Total Penerimaan Kas Terverifikasi
$receiptsThisMonth = (float)$db->query("SELECT COALESCE(SUM(total_amount), 0) FROM customer_receipts WHERE status = 'verified'")->fetchColumn();

// Tabel Sales Orders Terbaru
$recentOrders = $db->query("
    SELECT so.id, so.so_number, so.so_date, so.total_amount, so.status,
           c.name AS customer_name, c.code AS customer_code
    FROM sales_orders so
    JOIN customers c ON so.customer_id = c.id
    ORDER BY so.id DESC
    LIMIT 6
")->fetchAll();

// Tabel Pengiriman & Surat Jalan (DO) Terbaru
$recentDeliveries = $db->query("
    SELECT do.id, do.do_number, do.do_date, do.status, do.vehicle_number, do.driver_name,
           so.so_number, c.name AS customer_name
    FROM delivery_orders do
    JOIN sales_orders so ON do.sales_order_id = so.id
    JOIN customers c ON so.customer_id = c.id
    ORDER BY do.id DESC
    LIMIT 4
")->fetchAll();

// Tabel Faktur Penjualan Belum Lunas
$unpaidInvoices = $db->query("
    SELECT inv.id, inv.invoice_number, inv.invoice_date, inv.due_date, inv.total_amount, inv.balance, inv.status,
           c.name AS customer_name
    FROM sales_invoices inv
    JOIN customers c ON inv.customer_id = c.id
    WHERE inv.status IN ('issued', 'partially_paid', 'overdue')
    ORDER BY inv.due_date ASC
    LIMIT 4
")->fetchAll();

$pageTitle = 'Dashboard Penerimaan & Penjualan';
$breadcrumbSection = 'Penerimaan';
$appSubTitle = 'Sistem Informasi Penerimaan & Penjualan';
$notifCount = $soPendingCount;

include __DIR__ . '/../../includes/dashboard_header.php';
?>

<!-- Metrics Row 1: 3 Columns -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-4xl mb-12" data-purpose="metrics-summary">
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Total Pesanan Aktif (SO)</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $soTotalCount ?></span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Pesanan Menunggu Proses</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $soPendingCount ?></span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Surat Jalan (DO) Berjalan</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $doActiveCount ?></span>
  </div>
</div>

<!-- Metric Row 2: Piutang & Kas Masuk -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-4xl mb-14" data-purpose="finance-metrics">
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Saldo Piutang Pelanggan</p>
    <span class="text-[30px] font-extrabold text-[#000000] tracking-tight leading-none"><?= formatRupiah($totalReceivables) ?></span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Total Kas Masuk Terverifikasi</p>
    <span class="text-[30px] font-extrabold text-[#000000] tracking-tight leading-none"><?= formatRupiah($receiptsThisMonth) ?></span>
  </div>
</div>

<!-- BEGIN: Tabel Pesanan Penjualan (SO) -->
<section class="max-w-5xl mb-14" data-purpose="so-table-section">
  <div class="flex items-center justify-between mb-5">
    <h3 class="text-[14px] font-bold text-[#111827]">
      Pesanan Penjualan (Sales Orders) Terbaru
    </h3>
    <a href="index.php?page=receiving_sales/sales_orders" class="text-[11.5px] font-semibold text-[#EE2726] hover:underline">
      Kelola Semua SO &rarr;
    </a>
  </div>
  <div class="w-full">
    <div class="grid grid-cols-6 gap-4 text-[12px] font-bold text-[#1e242b] pb-3 border-b border-gray-100">
      <div class="col-span-1 pl-4">No. SO</div>
      <div class="col-span-2">Pelanggan / Distributor</div>
      <div class="col-span-1">Tanggal</div>
      <div class="col-span-1 text-right">Nilai Pesanan</div>
      <div class="col-span-1 text-center">Status</div>
    </div>
    <?php if (empty($recentOrders)): ?>
      <div class="w-full py-6 text-center text-xs text-gray-400">
        Belum ada pesanan penjualan tercatat.
      </div>
    <?php else: ?>
      <?php foreach ($recentOrders as $so): ?>
        <div class="grid grid-cols-6 gap-4 text-[11.5px] text-[#2d3139] py-3 border-b border-gray-50 hover:bg-gray-50/60 transition-colors items-center">
          <div class="col-span-1 pl-4 font-mono font-medium text-[#EE2726]">
            <a href="index.php?page=receiving_sales/sales_order_detail&id=<?= $so['id'] ?>" class="hover:underline">
              <?= e($so['so_number']) ?>
            </a>
          </div>
          <div class="col-span-2">
            <p class="font-medium"><?= e($so['customer_name']) ?></p>
            <p class="text-[10px] text-gray-400"><?= e($so['customer_code']) ?></p>
          </div>
          <div class="col-span-1 text-gray-500"><?= formatDate($so['so_date']) ?></div>
          <div class="col-span-1 text-right font-semibold text-gray-900"><?= formatRupiah($so['total_amount']) ?></div>
          <div class="col-span-1 text-center">
            <?php
            $st = $so['status'];
            $stBadge = 'bg-gray-100 text-gray-600';
            if ($st === 'approved' || $st === 'confirmed') $stBadge = 'bg-green-50 text-green-700 border border-green-200';
            elseif ($st === 'invoiced') $stBadge = 'bg-blue-50 text-blue-700 border border-blue-200';
            elseif ($st === 'pending_approval') $stBadge = 'bg-amber-50 text-amber-700 border border-amber-200';
            ?>
            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold <?= $stBadge ?>">
              <?= ucfirst(e($st)) ?>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<!-- BEGIN: Dual Section (Pengiriman DO & Faktur Jatuh Tempo) -->
<div class="max-w-5xl grid grid-cols-1 md:grid-cols-2 gap-8 mb-12">
  <!-- Pengiriman Barang & DO -->
  <div>
    <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-100">
      <h3 class="text-[14px] font-bold text-[#111827]">Pengiriman &amp; Surat Jalan (DO)</h3>
      <a href="index.php?page=receiving_sales/delivery_orders" class="text-[11px] font-semibold text-[#EE2726] hover:underline">Semua &rarr;</a>
    </div>
    <div class="space-y-2.5">
      <?php if (empty($recentDeliveries)): ?>
        <p class="text-xs text-gray-400 py-3">Belum ada pengiriman aktif.</p>
      <?php else: ?>
        <?php foreach ($recentDeliveries as $do): ?>
          <div class="flex items-center justify-between p-2.5 rounded-lg border border-gray-100 hover:border-red-100 text-[11.5px] transition-colors">
            <div>
              <p class="font-mono font-medium text-[#EE2726]"><?= e($do['do_number']) ?></p>
              <p class="text-[10px] text-gray-400">Armada: <?= e($do['vehicle_number'] ?? '-') ?> (<?= e($do['driver_name'] ?? 'Driver') ?>)</p>
            </div>
            <div class="text-right">
              <span class="px-2 py-0.5 rounded bg-blue-50 text-blue-700 text-[10px] font-semibold"><?= ucfirst(e($do['status'])) ?></span>
              <p class="text-[10px] text-gray-400 mt-0.5"><?= formatDate($do['do_date']) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Faktur Piutang Belum Lunas -->
  <div>
    <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-100">
      <h3 class="text-[14px] font-bold text-[#111827]">Faktur Piutang Belum Lunas</h3>
      <a href="index.php?page=receiving_sales/invoices" class="text-[11px] font-semibold text-[#EE2726] hover:underline">Semua &rarr;</a>
    </div>
    <div class="space-y-2.5">
      <?php if (empty($unpaidInvoices)): ?>
        <p class="text-xs text-gray-400 py-3">Tidak ada piutang jatuh tempo.</p>
      <?php else: ?>
        <?php foreach ($unpaidInvoices as $inv): ?>
          <div class="flex items-center justify-between p-2.5 rounded-lg border border-gray-100 hover:border-red-100 text-[11.5px] transition-colors">
            <div>
              <p class="font-mono font-medium text-[#EE2726]"><?= e($inv['invoice_number']) ?></p>
              <p class="text-[10px] text-gray-500"><?= e($inv['customer_name']) ?></p>
            </div>
            <div class="text-right">
              <p class="font-bold text-gray-900"><?= formatRupiah($inv['balance']) ?></p>
              <p class="text-[10px] text-red-500">Jatuh Tempo: <?= formatDate($inv['due_date']) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
include __DIR__ . '/../../includes/dashboard_footer.php';
