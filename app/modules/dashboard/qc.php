<?php
/**
 * Dashboard Quality Control (QC)
 * Sistem Informasi Akuntansi & Operasional PT Mayora Indah Tbk
 * Sesuai Acuan Desain Visual Prototype HTML Mayora
 */

requireRole(['Administrator', 'admin', 'Petugas QC', 'qc', 'Supervisor / Manajer', 'manager', 'manajer']);

$db = getDbConnection();
$user = currentUser();

// Metrics
// 1. Antrean Pemeriksaan QC (pending)
$pendingQcCount = (int)$db->query("SELECT COUNT(*) FROM qc_inspections WHERE result = 'pending'")->fetchColumn();

// 2. Produksi Menunggu QC (SPK status waiting_qc)
$prodWaitingQc = (int)$db->query("SELECT COUNT(*) FROM production_orders WHERE status = 'waiting_qc'")->fetchColumn();

// 3. Inspeksi Lolos QC (pass)
$passCount = (int)$db->query("SELECT COUNT(*) FROM qc_inspections WHERE result = 'pass'")->fetchColumn();

// 4. Inspeksi Ditolak (fail / conditional)
$failCount = (int)$db->query("SELECT COUNT(*) FROM qc_inspections WHERE result IN ('fail', 'conditional')")->fetchColumn();

// 5. Total Akumulasi Produk Scrap/Afkir
$totalScrapQty = (float)$db->query("SELECT COALESCE(SUM(qty), 0) FROM scrap_records")->fetchColumn();

// Antrean SPK Menunggu Inspeksi Mutu
$waitingOrders = $db->query("
    SELECT pdo.id, pdo.po_number, pdo.planned_end, pdo.target_qty, pdo.actual_qty,
           i.code AS item_code, i.name AS item_name, u.name AS unit_name
    FROM production_orders pdo
    JOIN items i ON pdo.product_item_id = i.id
    LEFT JOIN units u ON i.unit_id = u.id
    WHERE pdo.status = 'waiting_qc'
    ORDER BY pdo.id DESC
    LIMIT 6
")->fetchAll();

// Riwayat Pemeriksaan QC Terkini
$recentInspections = $db->query("
    SELECT qc.id, qc.inspection_number, qc.ref_type, qc.ref_id, qc.inspection_date, qc.result, qc.findings,
           u.full_name AS inspector_name
    FROM qc_inspections qc
    LEFT JOIN users u ON qc.inspector_id = u.id
    ORDER BY qc.id DESC
    LIMIT 4
")->fetchAll();

// Catatan Scrap & Afkir Terkini
$recentScraps = $db->query("
    SELECT sr.id, sr.qty, sr.reason, sr.scrap_date,
           i.code AS item_code, i.name AS item_name, u.name AS unit_name,
           pdo.po_number
    FROM scrap_records sr
    JOIN items i ON sr.item_id = i.id
    LEFT JOIN units u ON i.unit_id = u.id
    LEFT JOIN production_orders pdo ON sr.production_order_id = pdo.id
    ORDER BY sr.id DESC
    LIMIT 4
")->fetchAll();

$pageTitle = 'Dashboard Quality Control';
$breadcrumbSection = 'Pengendalian Mutu';
$appSubTitle = 'Sistem Informasi Pengendalian Mutu (QC)';
$notifCount = $prodWaitingQc + $pendingQcCount;

include __DIR__ . '/../../includes/dashboard_header.php';
?>

<!-- Metrics Row 1: 3 Columns -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-4xl mb-12" data-purpose="metrics-summary">
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">SPK Menunggu Inspeksi</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $prodWaitingQc ?></span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Inspeksi Lolos Uji (Pass)</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $passCount ?></span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Ditolak / Afkir (Fail)</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $failCount ?></span>
  </div>
</div>

<!-- Metric Row 2: Total Akumulasi Scrap Afkir -->
<div class="max-w-4xl mb-14" data-purpose="single-metric">
  <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Akumulasi Bahan / Produk Scrap</p>
  <span class="text-[34px] font-extrabold text-[#EE2726] tracking-tight leading-none"><?= number_format($totalScrapQty) ?> Unit</span>
</div>

<!-- BEGIN: Tabel Antrean SPK Menunggu Inspeksi -->
<section class="max-w-5xl mb-14" data-purpose="waiting-qc-section">
  <div class="flex items-center justify-between mb-5">
    <h3 class="text-[14px] font-bold text-[#111827]">
      Antrean SPK Menunggu Inspeksi Mutu
    </h3>
    <a href="index.php?page=production/qc_inspections" class="text-[11.5px] font-semibold text-[#EE2726] hover:underline">
      Input Hasil Uji &rarr;
    </a>
  </div>
  <div class="w-full">
    <div class="grid grid-cols-6 gap-4 text-[12px] font-bold text-[#1e242b] pb-3 border-b border-gray-100">
      <div class="col-span-1 pl-4">No. SPK</div>
      <div class="col-span-2">Produk Jadi</div>
      <div class="col-span-1">Jadwal Selesai</div>
      <div class="col-span-1 text-right">Qty Output</div>
      <div class="col-span-1 text-center">Tindakan</div>
    </div>
    <?php if (empty($waitingOrders)): ?>
      <div class="w-full py-6 text-center text-xs text-gray-400">
        Tidak ada batch produksi yang menunggu uji mutu saat ini.
      </div>
    <?php else: ?>
      <?php foreach ($waitingOrders as $wo): ?>
        <div class="grid grid-cols-6 gap-4 text-[11.5px] text-[#2d3139] py-3 border-b border-gray-50 hover:bg-gray-50/60 transition-colors items-center">
          <div class="col-span-1 pl-4 font-mono font-medium text-[#EE2726]">
            <?= e($wo['po_number']) ?>
          </div>
          <div class="col-span-2">
            <p class="font-medium"><?= e($wo['item_name']) ?></p>
            <p class="text-[10px] text-gray-400"><?= e($wo['item_code']) ?></p>
          </div>
          <div class="col-span-1 text-gray-500"><?= formatDate($wo['planned_end']) ?></div>
          <div class="col-span-1 text-right font-medium"><?= number_format($wo['actual_qty']) ?> <?= e($wo['unit_name']) ?></div>
          <div class="col-span-1 text-center">
            <a href="index.php?page=production/qc_inspections" class="inline-block px-2.5 py-1 rounded bg-[#EE2726] text-white text-[10px] font-semibold hover:bg-red-700 transition-colors">
              Uji Sampel
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<!-- BEGIN: Dual Section (Riwayat Inspeksi & Catatan Scrap) -->
<div class="max-w-5xl grid grid-cols-1 md:grid-cols-2 gap-8 mb-12">
  <!-- Riwayat Pemeriksaan QC Terkini -->
  <div>
    <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-100">
      <h3 class="text-[14px] font-bold text-[#111827]">Riwayat Hasil Inspeksi QC</h3>
      <a href="index.php?page=production/qc_inspections" class="text-[11px] font-semibold text-[#EE2726] hover:underline">Semua &rarr;</a>
    </div>
    <div class="space-y-2.5">
      <?php if (empty($recentInspections)): ?>
        <p class="text-xs text-gray-400 py-3">Belum ada riwayat inspeksi.</p>
      <?php else: ?>
        <?php foreach ($recentInspections as $insp): ?>
          <div class="flex items-center justify-between p-2.5 rounded-lg border border-gray-100 hover:border-red-100 text-[11.5px] transition-colors">
            <div>
              <p class="font-mono font-medium text-[#EE2726]"><?= e($insp['inspection_number']) ?></p>
              <p class="text-[10px] text-gray-400">Inspektor: <?= e($insp['inspector_name'] ?? 'Laboran') ?> &bull; <?= formatDate($insp['inspection_date']) ?></p>
            </div>
            <div class="text-right">
              <?php
              $res = $insp['result'];
              $resBadge = 'bg-gray-100 text-gray-700';
              if ($res === 'pass') $resBadge = 'bg-green-50 text-green-700 border border-green-200';
              elseif ($res === 'fail') $resBadge = 'bg-red-50 text-red-600 border border-red-200';
              elseif ($res === 'conditional') $resBadge = 'bg-amber-50 text-amber-700 border border-amber-200';
              ?>
              <span class="px-2 py-0.5 rounded text-[10px] font-semibold <?= $resBadge ?>"><?= ucfirst(e($res)) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Catatan Scrap & Afkir Terkini -->
  <div>
    <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-100">
      <h3 class="text-[14px] font-bold text-[#111827]">Catatan Scrap &amp; Afkir Produksi</h3>
      <a href="index.php?page=production/outputs" class="text-[11px] font-semibold text-[#EE2726] hover:underline">Semua &rarr;</a>
    </div>
    <div class="space-y-2.5">
      <?php if (empty($recentScraps)): ?>
        <p class="text-xs text-gray-400 py-3">Tidak ada catatan pembuangan scrap.</p>
      <?php else: ?>
        <?php foreach ($recentScraps as $sc): ?>
          <div class="flex items-center justify-between p-2.5 rounded-lg border border-gray-100 hover:border-red-100 text-[11.5px] transition-colors">
            <div>
              <p class="font-bold text-gray-800"><?= e($sc['item_name']) ?></p>
              <p class="text-[10px] text-gray-400">SPK: <?= e($sc['po_number'] ?? '-') ?> &bull; <?= e($sc['reason'] ?? 'Reject Mutu') ?></p>
            </div>
            <div class="text-right">
              <p class="font-bold text-red-600"><?= number_format($sc['qty']) ?> <?= e($sc['unit_name']) ?></p>
              <p class="text-[10px] text-gray-400"><?= formatDate($sc['scrap_date']) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
include __DIR__ . '/../../includes/dashboard_footer.php';
