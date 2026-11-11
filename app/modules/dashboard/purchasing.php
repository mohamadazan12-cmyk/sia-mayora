<?php
/**
 * Dashboard Staff Pembelian - PT Mayora Indah Tbk
 * Sesuai Acuan Desain Visual Prototype HTML Mayora
 */

requireRole(['Administrator', 'admin', 'Petugas Pembelian', 'purchasing', 'Supervisor / Manajer', 'manager', 'manajer']);

$db = getDbConnection();
$user = currentUser();

// KPI Metrics from Database
// 1. PR menunggu RFQ
$prWaitingRfq = (int)$db->query("SELECT COUNT(*) FROM purchase_requisitions WHERE status IN ('approved', 'pending_approval')")->fetchColumn();

// 2. PO menunggu otorisasi
$poWaitingAuth = (int)$db->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('draft', 'pending_approval')")->fetchColumn();

// 3. PO terbuka (dalam pengiriman)
$poOpen = (int)$db->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('approved', 'partially_received')")->fetchColumn();

// 4. Retur berjalan
$returnsActive = (int)$db->query("SELECT COUNT(*) FROM supplier_returns WHERE status NOT IN ('completed', 'cancelled')")->fetchColumn();

// Total Notifikasi
$notifCount = $prWaitingRfq + $poWaitingAuth;

// Table: Permintaan Pembelian (PR)
$prList = $db->query("
    SELECT pr.id, pr.pr_number, pr.request_date, pr.total_estimated, pr.status,
           d.name AS department_name, u.full_name AS requester_name
    FROM purchase_requisitions pr
    LEFT JOIN departments d ON pr.department_id = d.id
    LEFT JOIN users u ON pr.requested_by = u.id
    ORDER BY pr.id DESC
    LIMIT 6
")->fetchAll();

// Data Retur Berjalan
$returnsList = $db->query("
    SELECT sr.id, sr.return_number, sr.return_date, sr.status, sr.reason,
           s.name AS supplier_name
    FROM supplier_returns sr
    LEFT JOIN suppliers s ON sr.supplier_id = s.id
    WHERE sr.status NOT IN ('completed', 'cancelled')
    ORDER BY sr.id DESC
    LIMIT 5
")->fetchAll();

// Widget: Informasi Vendor Utama
$vendorList = $db->query("
    SELECT id, code, name, contact_person, phone, payment_terms_days
    FROM suppliers
    WHERE is_active = 1
    ORDER BY id ASC
    LIMIT 4
")->fetchAll();

// Widget: Aktivitas Pesanan Pembelian Terbaru
$recentPoList = $db->query("
    SELECT po.id, po.po_number, po.po_date, po.total_amount, po.status, s.name AS supplier_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    ORDER BY po.id DESC
    LIMIT 4
")->fetchAll();

$pageTitle = 'Dashboard Staff Pembelian';
$breadcrumbSection = 'Pengeluaran';
$appSubTitle = 'Sistem Informasi SDM dan Penggajian'; // As shown in the prototype brand-header

include __DIR__ . '/../../includes/dashboard_header.php';
?>

<!-- Metrics Row 1: 3 Columns (Persis Prototype HTML) -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-4xl mb-12" data-purpose="metrics-summary">
  <!-- Metric 1 -->
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">
      PR Menunggu RFQ
    </p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none">
      <?= $prWaitingRfq ?>
    </span>
  </div>
  <!-- Metric 2 -->
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">
      PO Menunggu Otorisasi
    </p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none">
      <?= $poWaitingAuth ?>
    </span>
  </div>
  <!-- Metric 3 -->
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">
      PO Terbuka (Dalam Pengiriman)
    </p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none">
      <?= $poOpen ?>
    </span>
  </div>
</div>

<!-- Metric Row 2: Retur Berjalan (Persis Prototype HTML) -->
<div class="max-w-4xl mb-14" data-purpose="single-metric">
  <p class="text-[14px] font-semibold text-[#1e242b] mb-4">
    Retur Berjalan
  </p>
  <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none">
    <?= $returnsActive ?>
  </span>
</div>

<!-- BEGIN: ReturBerjalanTableSection (Persis Prototype HTML) -->
<section class="max-w-5xl mb-14" data-purpose="retur-table-section">
  <!-- Section Header -->
  <h3 class="text-[14px] font-bold text-[#111827] mb-5">
    Retur Berjalan
  </h3>
  <!-- Table Columns Headers -->
  <div class="w-full">
    <div class="grid grid-cols-6 gap-4 text-[12px] font-bold text-[#1e242b] pb-3 border-b border-gray-100">
      <div class="col-span-1 pl-4">No. Dokumen</div>
      <div class="col-span-2">Pemasok / Vendor</div>
      <div class="col-span-1">Tanggal</div>
      <div class="col-span-1">Keterangan / Alasan</div>
      <div class="col-span-1 text-center">Status</div>
    </div>
    <!-- Rows / Empty State -->
    <?php if (empty($returnsList)): ?>
      <div class="w-full py-6 text-center text-xs text-gray-400">
        Belum ada retur pembelian berjalan yang aktif.
      </div>
    <?php else: ?>
      <?php foreach ($returnsList as $ret): ?>
        <div class="grid grid-cols-6 gap-4 text-[11.5px] text-[#2d3139] py-3 border-b border-gray-50 hover:bg-gray-50/60 transition-colors items-center">
          <div class="col-span-1 pl-4 font-mono font-medium text-[#EE2726]"><?= e($ret['return_number']) ?></div>
          <div class="col-span-2 font-medium"><?= e($ret['supplier_name'] ?? 'Pemasok') ?></div>
          <div class="col-span-1 text-gray-500"><?= formatDate($ret['return_date']) ?></div>
          <div class="col-span-1 text-gray-600 truncate"><?= e($ret['reason'] ?? '-') ?></div>
          <div class="col-span-1 text-center">
            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold bg-red-50 text-red-600 border border-red-100">
              <?= e(ucfirst($ret['status'])) ?>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>
<!-- END: ReturBerjalanTableSection -->

<!-- BEGIN: Tabel Permintaan Pembelian (PR) -->
<section class="max-w-5xl mb-14" data-purpose="pr-table-section">
  <div class="flex items-center justify-between mb-5">
    <h3 class="text-[14px] font-bold text-[#111827]">
      Tabel Permintaan Pembelian (PR)
    </h3>
    <a href="index.php?page=expenditure_procurement/pr" class="text-[11.5px] font-semibold text-[#EE2726] hover:underline">
      Kelola Semua PR &rarr;
    </a>
  </div>
  <div class="w-full">
    <div class="grid grid-cols-6 gap-4 text-[12px] font-bold text-[#1e242b] pb-3 border-b border-gray-100">
      <div class="col-span-1 pl-4">No. PR</div>
      <div class="col-span-1">Departemen</div>
      <div class="col-span-1">Tanggal</div>
      <div class="col-span-1 text-right">Estimasi Nilai</div>
      <div class="col-span-1 text-center">Status</div>
      <div class="col-span-1 text-center">Aksi</div>
    </div>
    <?php if (empty($prList)): ?>
      <div class="w-full py-6 text-center text-xs text-gray-400">
        Belum ada permintaan pembelian tercatat.
      </div>
    <?php else: ?>
      <?php foreach ($prList as $pr): ?>
        <div class="grid grid-cols-6 gap-4 text-[11.5px] text-[#2d3139] py-3 border-b border-gray-50 hover:bg-gray-50/60 transition-colors items-center">
          <div class="col-span-1 pl-4 font-mono font-medium text-[#EE2726]">
            <a href="index.php?page=expenditure_procurement/pr_detail&id=<?= $pr['id'] ?>" class="hover:underline">
              <?= e($pr['pr_number']) ?>
            </a>
          </div>
          <div class="col-span-1">
            <span class="font-medium"><?= e($pr['department_name'] ?? 'Umum') ?></span>
            <div class="text-[10px] text-gray-400"><?= e($pr['requester_name'] ?? 'Staff') ?></div>
          </div>
          <div class="col-span-1 text-gray-500"><?= formatDate($pr['request_date']) ?></div>
          <div class="col-span-1 text-right font-semibold text-gray-900"><?= formatRupiah($pr['total_estimated']) ?></div>
          <div class="col-span-1 text-center">
            <?php
            $st = $pr['status'];
            $stBadge = 'bg-gray-100 text-gray-600';
            if ($st === 'approved') $stBadge = 'bg-green-50 text-green-700 border border-green-200';
            elseif ($st === 'pending_approval') $stBadge = 'bg-amber-50 text-amber-700 border border-amber-200';
            elseif ($st === 'ordered') $stBadge = 'bg-blue-50 text-blue-700 border border-blue-200';
            ?>
            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold <?= $stBadge ?>">
              <?= ucfirst(e($st)) ?>
            </span>
          </div>
          <div class="col-span-1 text-center">
            <a href="index.php?page=expenditure_procurement/pr_detail&id=<?= $pr['id'] ?>" class="text-[11px] font-medium text-gray-600 hover:text-[#EE2726] transition-colors">
              Detail &rarr;
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<!-- BEGIN: Dual Info Section (Informasi Vendor Utama & Pesanan Pembelian Terbaru) -->
<div class="max-w-5xl grid grid-cols-1 md:grid-cols-2 gap-8 mb-12">
  <!-- Informasi Vendor Utama -->
  <div>
    <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-100">
      <h3 class="text-[14px] font-bold text-[#111827]">Informasi Vendor Utama</h3>
      <a href="index.php?page=master_data/suppliers" class="text-[11px] font-semibold text-[#EE2726] hover:underline">Semua &rarr;</a>
    </div>
    <div class="space-y-2.5">
      <?php if (empty($vendorList)): ?>
        <p class="text-xs text-gray-400 py-3">Belum ada vendor terdaftar.</p>
      <?php else: ?>
        <?php foreach ($vendorList as $v): ?>
          <div class="flex items-center justify-between p-2.5 rounded-lg border border-gray-100 hover:border-red-100 text-[11.5px] transition-colors">
            <div>
              <p class="font-bold text-gray-800"><?= e($v['name']) ?></p>
              <p class="text-[10px] text-gray-400">Kontak: <?= e($v['contact_person'] ?? '-') ?> (<?= e($v['phone'] ?? '-') ?>)</p>
            </div>
            <span class="px-2 py-0.5 rounded bg-blue-50 text-blue-700 text-[10px] font-semibold">
              TOP <?= (int)$v['payment_terms_days'] ?> Hari
            </span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Pesanan Pembelian Terbaru -->
  <div>
    <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-100">
      <h3 class="text-[14px] font-bold text-[#111827]">Pesanan Pembelian Terbaru</h3>
      <a href="index.php?page=expenditure_procurement/po" class="text-[11px] font-semibold text-[#EE2726] hover:underline">Semua &rarr;</a>
    </div>
    <div class="space-y-2.5">
      <?php if (empty($recentPoList)): ?>
        <p class="text-xs text-gray-400 py-3">Belum ada transaksi PO.</p>
      <?php else: ?>
        <?php foreach ($recentPoList as $po): ?>
          <div class="flex items-center justify-between p-2.5 rounded-lg border border-gray-100 hover:border-red-100 text-[11.5px] transition-colors">
            <div>
              <a href="index.php?page=expenditure_procurement/po_detail&id=<?= $po['id'] ?>" class="font-mono font-medium text-[#EE2726] hover:underline">
                <?= e($po['po_number']) ?>
              </a>
              <p class="text-[10px] text-gray-500"><?= e($po['supplier_name'] ?? 'Pemasok') ?></p>
            </div>
            <div class="text-right">
              <div class="font-bold text-gray-900"><?= formatRupiah($po['total_amount']) ?></div>
              <span class="text-[10px] text-gray-400"><?= formatDate($po['po_date']) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
include __DIR__ . '/../../includes/dashboard_footer.php';
