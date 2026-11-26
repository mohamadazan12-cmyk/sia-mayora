<?php
/**
 * Dashboard Produksi & PPIC
 * Sistem Informasi Akuntansi & Operasional PT Mayora Indah Tbk
 * Sesuai Acuan Desain Visual Prototype HTML Mayora
 */

requireRole(['Administrator', 'admin', 'Petugas Produksi', 'Petugas Produksi / PPIC', 'produksi', 'ppic', 'Supervisor / Manajer', 'manager', 'manajer']);

$db = getDbConnection();
$user = currentUser();

// Metrics
// 1. SPK Menunggu Proses (draft, approved, waiting_material)
$spkPending = (int)$db->query("SELECT COUNT(*) FROM production_orders WHERE status IN ('draft', 'pending_approval', 'approved', 'waiting_material')")->fetchColumn();

// 2. Produksi Sedang Berjalan (in_progress)
$prodRunning = (int)$db->query("SELECT COUNT(*) FROM production_orders WHERE status = 'in_progress'")->fetchColumn();

// 3. Produksi Selesai (completed)
$prodDone = (int)$db->query("SELECT COUNT(*) FROM production_orders WHERE status = 'completed'")->fetchColumn();

// 4. Target vs Aktual Output
$targetTotal = (float)$db->query("SELECT COALESCE(SUM(target_qty), 0) FROM production_orders WHERE status NOT IN ('cancelled')")->fetchColumn();
$actualTotal = (float)$db->query("SELECT COALESCE(SUM(actual_qty), 0) FROM production_orders WHERE status NOT IN ('cancelled')")->fetchColumn();
$achievePct  = $targetTotal > 0 ? round(($actualTotal / $targetTotal) * 100, 1) : 0;

// SPK Berjalan & Aktif
$activeOrders = $db->query("
    SELECT pdo.id, pdo.po_number, pdo.planned_start, pdo.planned_end, pdo.target_qty, pdo.actual_qty, pdo.status,
           i.code AS item_code, i.name AS item_name, u.name AS unit_name
    FROM production_orders pdo
    JOIN items i ON pdo.product_item_id = i.id
    LEFT JOIN units u ON i.unit_id = u.id
    ORDER BY pdo.id DESC
    LIMIT 6
")->fetchAll();

// Pemakaian Bahan Baku Terbaru (Material Issues)
$recentMaterialIssues = $db->query("
    SELECT mi.id, mi.issue_number, mi.issue_date, mi.status, pdo.po_number, u.full_name AS issuer_name
    FROM material_issues mi
    LEFT JOIN production_orders pdo ON mi.production_order_id = pdo.id
    LEFT JOIN users u ON mi.created_by = u.id
    ORDER BY mi.id DESC
    LIMIT 4
")->fetchAll();

// Catatan Hasil Produksi & Scrap Afkir
$recentOutputs = $db->query("
    SELECT po.id, po.output_date, (po.qty_good + po.qty_scrap) AS qty_produced, po.qty_good, po.qty_scrap,
           pdo.po_number, i.name AS item_name
    FROM production_outputs po
    JOIN production_orders pdo ON po.production_order_id = pdo.id
    JOIN items i ON pdo.product_item_id = i.id
    ORDER BY po.id DESC
    LIMIT 4
")->fetchAll();

// Tren Bulanan Output Produksi (6 Bulan)
$monthlyProd = $db->query("
    SELECT 
        DATE_FORMAT(output_date, '%b %Y') AS month_label,
        DATE_FORMAT(output_date, '%Y-%m') AS ym,
        COALESCE(SUM(qty_good), 0) AS total_good,
        COALESCE(SUM(qty_scrap), 0) AS total_scrap
    FROM production_outputs
    GROUP BY DATE_FORMAT(output_date, '%Y-%m'), DATE_FORMAT(output_date, '%b %Y')
    ORDER BY ym ASC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$prodLabels = json_encode(array_column($monthlyProd, 'month_label'));
$goodSeries = json_encode(array_map('floatval', array_column($monthlyProd, 'total_good')));
$scrapSeries = json_encode(array_map('floatval', array_column($monthlyProd, 'total_scrap')));

$pageTitle = 'Dashboard Produksi & PPIC';
$breadcrumbSection = 'Produksi';
$appSubTitle = 'Sistem Informasi Produksi & PPIC';
$notifCount = $spkPending;

include __DIR__ . '/../../includes/dashboard_header.php';
?>

<!-- Pintasan Alur Siklus Manufaktur Mayora -->
<div class="max-w-5xl mb-8 p-4 rounded-xl bg-white border border-gray-150 shadow-sm flex flex-wrap gap-2 items-center justify-between">
  <div class="text-[12px] font-bold text-gray-700">Pusat Alur Produksi:</div>
  <div class="flex flex-wrap gap-2">
    <a href="index.php?page=production/mps" class="px-3 py-1.5 rounded-md bg-gray-50 border border-gray-200 text-[11px] font-semibold text-gray-700 hover:border-red-400 hover:text-[#EE2726] transition-colors">
      📅 Jadwal Induk (MPS)
    </a>
    <a href="index.php?page=production/mrp" class="px-3 py-1.5 rounded-md bg-gray-50 border border-gray-200 text-[11px] font-semibold text-gray-700 hover:border-red-400 hover:text-[#EE2726] transition-colors">
      ⚡ Kalkulator MRP &amp; Auto-PR
    </a>
    <a href="index.php?page=production/material_requisitions" class="px-3 py-1.5 rounded-md bg-gray-50 border border-gray-200 text-[11px] font-semibold text-gray-700 hover:border-red-400 hover:text-[#EE2726] transition-colors">
      📦 Permintaan Bahan (MR)
    </a>
    <a href="index.php?page=inventory/batches" class="px-3 py-1.5 rounded-md bg-gray-50 border border-gray-200 text-[11px] font-semibold text-gray-700 hover:border-red-400 hover:text-[#EE2726] transition-colors">
      ⏳ Monitoring FEFO
    </a>
    <a href="index.php?page=inventory/traceability" class="px-3 py-1.5 rounded-md bg-[#EE2726] text-white text-[11px] font-semibold hover:bg-red-700 transition-colors">
      🔍 Traceability Produk &rarr;
    </a>
  </div>
</div>

<!-- Metrics Row 1: 3 Columns -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-4xl mb-12" data-purpose="metrics-summary">
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">SPK Menunggu Proses</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $spkPending ?></span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Produksi Berjalan</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $prodRunning ?></span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Produksi Selesai</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $prodDone ?></span>
  </div>
</div>

<!-- Metric Row 2: Capaian Output Target -->
<div class="max-w-4xl mb-10" data-purpose="single-metric">
  <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Realisasi Output Produksi</p>
  <div class="flex items-baseline gap-3">
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $achievePct ?>%</span>
    <span class="text-[12px] text-[#687588]">(<?= number_format($actualTotal) ?> dari <?= number_format($targetTotal) ?> target)</span>
  </div>
</div>

<!-- Chart: Tren Realisasi Output & Scrap -->
<section class="max-w-5xl mb-12 bg-white rounded-xl border border-gray-150 p-6 shadow-sm">
  <div class="flex items-center justify-between mb-4">
    <div>
      <h3 class="text-[14px] font-bold text-[#111827]">Tren Realisasi Output Pabrikasi (6 Bulan Terakhir)</h3>
      <p class="text-[11.5px] text-[#687588]">Volume barang jadi lulus uji (Good) vs produk afkir/gagal spesifikasi (Scrap)</p>
    </div>
    <div class="flex items-center gap-3">
      <span class="flex items-center gap-1.5 text-[11px] font-semibold text-gray-700">
        <span class="w-2.5 h-2.5 rounded-full bg-[#15803d] inline-block"></span> Lulus Standar (Good)
      </span>
      <span class="flex items-center gap-1.5 text-[11px] font-semibold text-gray-700">
        <span class="w-2.5 h-2.5 rounded-full bg-[#EE2726] inline-block"></span> Scrap / Afkir
      </span>
    </div>
  </div>
  <div class="h-60 w-full">
    <canvas id="ppicProdChart"></canvas>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const ctx = document.getElementById('ppicProdChart');
  if (ctx) {
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: <?= $prodLabels ?>,
        datasets: [
          {
            label: 'Output Lulus (Good)',
            data: <?= $goodSeries ?>,
            backgroundColor: '#15803d',
            borderRadius: 4,
            barPercentage: 0.6
          },
          {
            label: 'Scrap Afkir',
            data: <?= $scrapSeries ?>,
            backgroundColor: '#EE2726',
            borderRadius: 4,
            barPercentage: 0.6
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function(c) { return c.dataset.label + ': ' + c.parsed.y.toLocaleString('id-ID') + ' Dus/Unit'; }
            }
          }
        },
        scales: {
          x: { grid: { display: false } },
          y: {
            grid: { color: '#f1f5f9' },
            ticks: {
              callback: function(v) { return v.toLocaleString('id-ID'); }
            }
          }
        }
      }
    });
  }
});
</script>

<!-- BEGIN: Tabel Perintah Produksi (SPK) -->
<section class="max-w-5xl mb-14" data-purpose="spk-table-section">
  <div class="flex items-center justify-between mb-5">
    <h3 class="text-[14px] font-bold text-[#111827]">
      Perintah Produksi (SPK) Aktif
    </h3>
    <a href="index.php?page=production/orders" class="text-[11.5px] font-semibold text-[#EE2726] hover:underline">
      Kelola Seluruh SPK &rarr;
    </a>
  </div>
  <div class="w-full">
    <div class="grid grid-cols-6 gap-4 text-[12px] font-bold text-[#1e242b] pb-3 border-b border-gray-100">
      <div class="col-span-1 pl-4">No. SPK</div>
      <div class="col-span-2">Produk Jadi</div>
      <div class="col-span-1">Jadwal Selesai</div>
      <div class="col-span-1 text-right">Target / Aktual</div>
      <div class="col-span-1 text-center">Status</div>
    </div>
    <?php if (empty($activeOrders)): ?>
      <div class="w-full py-6 text-center text-xs text-gray-400">
        Belum ada perintah produksi tercatat.
      </div>
    <?php else: ?>
      <?php foreach ($activeOrders as $po): ?>
        <div class="grid grid-cols-6 gap-4 text-[11.5px] text-[#2d3139] py-3 border-b border-gray-50 hover:bg-gray-50/60 transition-colors items-center">
          <div class="col-span-1 pl-4 font-mono font-medium text-[#EE2726]">
            <a href="index.php?page=production/order_detail&id=<?= $po['id'] ?>" class="hover:underline">
              <?= e($po['po_number']) ?>
            </a>
          </div>
          <div class="col-span-2">
            <p class="font-medium"><?= e($po['item_name']) ?></p>
            <p class="text-[10px] text-gray-400"><?= e($po['item_code']) ?></p>
          </div>
          <div class="col-span-1 text-gray-500"><?= formatDate($po['planned_end']) ?></div>
          <div class="col-span-1 text-right font-medium">
            <?= number_format($po['actual_qty']) ?> / <?= number_format($po['target_qty']) ?>
          </div>
          <div class="col-span-1 text-center">
            <?php
            $st = $po['status'];
            $stBadge = 'bg-gray-100 text-gray-600';
            if ($st === 'in_progress') $stBadge = 'bg-blue-50 text-blue-700 border border-blue-200';
            elseif ($st === 'completed') $stBadge = 'bg-green-50 text-green-700 border border-green-200';
            elseif ($st === 'waiting_qc') $stBadge = 'bg-amber-50 text-amber-700 border border-amber-200';
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

<!-- BEGIN: Dual Section (Alokasi Bahan & Hasil Output) -->
<div class="max-w-5xl grid grid-cols-1 md:grid-cols-2 gap-8 mb-12">
  <!-- Pemakaian Bahan Baku (Material Issues) -->
  <div>
    <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-100">
      <h3 class="text-[14px] font-bold text-[#111827]">Pemakaian Bahan Baku (Issue)</h3>
      <a href="index.php?page=production/material_issues" class="text-[11px] font-semibold text-[#EE2726] hover:underline">Semua &rarr;</a>
    </div>
    <div class="space-y-2.5">
      <?php if (empty($recentMaterialIssues)): ?>
        <p class="text-xs text-gray-400 py-3">Belum ada pengeluaran bahan.</p>
      <?php else: ?>
        <?php foreach ($recentMaterialIssues as $mi): ?>
          <div class="flex items-center justify-between p-2.5 rounded-lg border border-gray-100 hover:border-red-100 text-[11.5px] transition-colors">
            <div>
              <p class="font-mono font-medium text-[#EE2726]"><?= e($mi['issue_number']) ?></p>
              <p class="text-[10px] text-gray-400">Untuk SPK: <?= e($mi['po_number'] ?? '-') ?></p>
            </div>
            <div class="text-right">
              <span class="px-2 py-0.5 rounded bg-gray-100 text-gray-700 text-[10px] font-semibold"><?= ucfirst(e($mi['status'])) ?></span>
              <p class="text-[10px] text-gray-400 mt-0.5"><?= formatDate($mi['issue_date']) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Catatan Hasil Produksi & Scrap -->
  <div>
    <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-100">
      <h3 class="text-[14px] font-bold text-[#111827]">Output Lini Pabrikasi &amp; Scrap</h3>
      <a href="index.php?page=production/outputs" class="text-[11px] font-semibold text-[#EE2726] hover:underline">Semua &rarr;</a>
    </div>
    <div class="space-y-2.5">
      <?php if (empty($recentOutputs)): ?>
        <p class="text-xs text-gray-400 py-3">Belum ada pencatatan hasil produksi.</p>
      <?php else: ?>
        <?php foreach ($recentOutputs as $out): ?>
          <div class="flex items-center justify-between p-2.5 rounded-lg border border-gray-100 hover:border-red-100 text-[11.5px] transition-colors">
            <div>
              <p class="font-bold text-gray-800"><?= e($out['item_name']) ?></p>
              <p class="text-[10px] text-gray-400">SPK: <?= e($out['po_number']) ?> &bull; <?= formatDate($out['output_date']) ?></p>
            </div>
            <div class="text-right">
              <p class="font-bold text-green-600"><?= number_format($out['qty_good']) ?> Bagus</p>
              <p class="text-[10px] text-red-500"><?= number_format($out['qty_scrap']) ?> Scrap</p>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
include __DIR__ . '/../../includes/dashboard_footer.php';
