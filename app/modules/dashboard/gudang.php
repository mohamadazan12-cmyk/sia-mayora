<?php
/**
 * Dashboard Gudang dan Persediaan
 * Sistem Informasi Akuntansi & Operasional PT Mayora Indah Tbk
 * Sesuai Acuan Desain Visual Prototype HTML Mayora
 */

requireRole(['Administrator', 'admin', 'Petugas Gudang', 'gudang', 'Supervisor / Manajer', 'manager', 'manajer']);

$db = getDbConnection();
$user = currentUser();

// Metrics
// 1. Total Item Persediaan Aktif
$totalItems = (int)$db->query("SELECT COUNT(*) FROM items WHERE is_active = 1")->fetchColumn();

// 2. Total Kuantitas Fisik Stok
$totalStockQty = (float)$db->query("SELECT COALESCE(SUM(qty_available), 0) FROM stock_balances")->fetchColumn();

// 3. Penerimaan Barang Masuk (GR)
$grActiveCount = (int)$db->query("SELECT COUNT(*) FROM goods_receipts WHERE status IN ('draft', 'received', 'verified')")->fetchColumn();

// 4. Barang di Bawah Batas Minimum
$lowStockCount = (int)$db->query("
    SELECT COUNT(*) FROM (
        SELECT i.id, i.min_stock, COALESCE(SUM(sb.qty_available), 0) AS total_qty
        FROM items i
        LEFT JOIN stock_balances sb ON i.id = sb.item_id
        WHERE i.is_active = 1
        GROUP BY i.id, i.min_stock
        HAVING total_qty <= i.min_stock
    ) AS low_items
")->fetchColumn();

// Daftar Barang Kritis (Low Stock)
$lowStockList = $db->query("
    SELECT i.id, i.code, i.name, i.min_stock, COALESCE(SUM(sb.qty_available), 0) AS total_qty, u.name AS unit_name
    FROM items i
    LEFT JOIN units u ON i.unit_id = u.id
    LEFT JOIN stock_balances sb ON i.id = sb.item_id
    WHERE i.is_active = 1
    GROUP BY i.id, i.code, i.name, i.min_stock, u.name
    HAVING total_qty <= i.min_stock
    ORDER BY total_qty ASC
    LIMIT 6
")->fetchAll();

// Penerimaan Barang (GR) Terbaru
$recentGr = $db->query("
    SELECT gr.id, gr.gr_number, gr.receipt_date, gr.status, s.name AS supplier_name, po.po_number
    FROM goods_receipts gr
    JOIN suppliers s ON gr.supplier_id = s.id
    LEFT JOIN purchase_orders po ON gr.purchase_order_id = po.id
    ORDER BY gr.id DESC
    LIMIT 4
")->fetchAll();

// Riwayat Mutasi Stok Terbaru (Stock Movements)
$recentMovements = $db->query("
    SELECT sm.id, sm.created_at, sm.movement_type, sm.qty_in, sm.qty_out,
           CASE WHEN sm.qty_in > 0 THEN sm.qty_in ELSE sm.qty_out END AS qty,
           sm.ref_type, sm.ref_number,
           i.code AS item_code, i.name AS item_name, u.name AS unit_name, w.name AS warehouse_name
    FROM stock_movements sm
    JOIN items i ON sm.item_id = i.id
    LEFT JOIN units u ON i.unit_id = u.id
    JOIN warehouses w ON sm.warehouse_id = w.id
    ORDER BY sm.id DESC
    LIMIT 4
")->fetchAll();

// 5. Total Batch Aktif Terpantau FEFO
$totalBatches = (int)$db->query("SELECT COUNT(*) FROM product_batches WHERE status IN ('passed', 'released')")->fetchColumn();

// 6. Komposisi Sebaran Stok per Gudang
$warehouseStock = $db->query("
    SELECT w.name AS warehouse_name, COALESCE(SUM(sb.qty_available), 0) AS total_qty
    FROM warehouses w
    LEFT JOIN stock_balances sb ON w.id = sb.warehouse_id
    GROUP BY w.id, w.name
    HAVING total_qty > 0
    ORDER BY total_qty DESC
")->fetchAll(PDO::FETCH_ASSOC);

$whLabels = json_encode(array_column($warehouseStock, 'warehouse_name'));
$whData = json_encode(array_map('floatval', array_column($warehouseStock, 'total_qty')));

$pageTitle = 'Dashboard Gudang & Persediaan';
$breadcrumbSection = 'Gudang';
$appSubTitle = 'Sistem Informasi Gudang & Persediaan';
$notifCount = $lowStockCount;

include __DIR__ . '/../../includes/dashboard_header.php';
?>

<!-- Pintasan Siklus Persediaan & Gudang Mayora -->
<div class="max-w-5xl mb-8 p-4 rounded-xl bg-white border border-gray-150 shadow-sm flex flex-wrap gap-2 items-center justify-between">
  <div class="text-[12px] font-bold text-gray-700">Pusat Alur Pergudangan:</div>
  <div class="flex flex-wrap gap-2">
    <a href="index.php?page=inventory/stock" class="px-3 py-1.5 rounded-md bg-gray-50 border border-gray-200 text-[11px] font-semibold text-gray-700 hover:border-red-400 hover:text-[#EE2726] transition-colors">
      📦 Saldo Fisik Stok
    </a>
    <a href="index.php?page=inventory/batches" class="px-3 py-1.5 rounded-md bg-gray-50 border border-gray-200 text-[11px] font-semibold text-gray-700 hover:border-red-400 hover:text-[#EE2726] transition-colors">
      ⏳ Monitoring FEFO &amp; Batch (<?= $totalBatches ?>)
    </a>
    <a href="index.php?page=inventory/traceability" class="px-3 py-1.5 rounded-md bg-gray-50 border border-gray-200 text-[11px] font-semibold text-gray-700 hover:border-red-400 hover:text-[#EE2726] transition-colors">
      🔍 Traceability Silsilah Lot
    </a>
    <a href="index.php?page=production/material_requisitions" class="px-3 py-1.5 rounded-md bg-gray-50 border border-gray-200 text-[11px] font-semibold text-gray-700 hover:border-red-400 hover:text-[#EE2726] transition-colors">
      🏭 Pengeluaran Bahan (MR)
    </a>
    <a href="index.php?page=inventory/adjustments" class="px-3 py-1.5 rounded-md bg-[#EE2726] text-white text-[11px] font-semibold hover:bg-red-700 transition-colors">
      ⚖️ Stock Opname &rarr;
    </a>
  </div>
</div>

<!-- Metrics Row 1: 3 Columns -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-4xl mb-12" data-purpose="metrics-summary">
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Total Item Persediaan</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $totalItems ?></span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Total Saldo Fisik Stok</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= number_format($totalStockQty) ?></span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Penerimaan Barang (GR)</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $grActiveCount ?></span>
  </div>
</div>

<!-- Metric Row 2: Barang di Bawah Batas Minimum -->
<div class="max-w-4xl mb-10" data-purpose="single-metric">
  <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Item Kritis di Bawah Batas Minimum</p>
  <span class="text-[34px] font-extrabold text-[#EE2726] tracking-tight leading-none"><?= $lowStockCount ?></span>
</div>

<!-- Chart: Komposisi Sebaran Stok per Gudang -->
<section class="max-w-5xl mb-12 bg-white rounded-xl border border-gray-150 p-6 shadow-sm">
  <div class="flex items-center justify-between mb-4">
    <div>
      <h3 class="text-[14px] font-bold text-[#111827]">Sebaran Kuantitas Stok per Lokasi Gudang Manufaktur</h3>
      <p class="text-[11.5px] text-[#687588]">Volume fisik stok yang tersimpan di Gudang Bahan Baku, Gudang Kemasan, Gudang WIP, dan Barang Jadi</p>
    </div>
    <span class="text-[11px] font-semibold text-[#EE2726] bg-red-50 px-2.5 py-1 rounded">
      Fisik &amp; FEFO
    </span>
  </div>
  <div class="h-60 w-full">
    <canvas id="whStockChart"></canvas>
  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const ctx = document.getElementById('whStockChart');
  if (ctx) {
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: <?= $whLabels ?>,
        datasets: [{
          label: 'Kuantitas Stok',
          data: <?= $whData ?>,
          backgroundColor: ['#EE2726', '#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#64748b'],
          borderRadius: 4,
          barPercentage: 0.5
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function(c) { return 'Stok: ' + c.parsed.y.toLocaleString('id-ID') + ' Unit/Kg'; }
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

<!-- BEGIN: Tabel Stok Kritis di Bawah Batas Minimum -->
<section class="max-w-5xl mb-14" data-purpose="low-stock-section">
  <div class="flex items-center justify-between mb-5">
    <h3 class="text-[14px] font-bold text-[#111827]">
      Daftar Bahan &amp; Barang di Bawah Batas Minimum
    </h3>
    <a href="index.php?page=inventory/stock" class="text-[11.5px] font-semibold text-[#EE2726] hover:underline">
      Periksa Semua Stok &rarr;
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
    <?php if (empty($lowStockList)): ?>
      <div class="w-full py-6 text-center text-xs text-gray-400">
        Semua barang persediaan berada di atas batas minimum stok.
      </div>
    <?php else: ?>
      <?php foreach ($lowStockList as $it): ?>
        <div class="grid grid-cols-6 gap-4 text-[11.5px] text-[#2d3139] py-3 border-b border-gray-50 hover:bg-gray-50/60 transition-colors items-center">
          <div class="col-span-1 pl-4 font-mono font-medium text-gray-700"><?= e($it['code']) ?></div>
          <div class="col-span-2 font-medium text-gray-900"><?= e($it['name']) ?></div>
          <div class="col-span-1 text-right text-gray-500"><?= number_format($it['min_stock']) ?> <?= e($it['unit_name']) ?></div>
          <div class="col-span-1 text-right font-bold text-[#EE2726]"><?= number_format($it['total_qty']) ?> <?= e($it['unit_name']) ?></div>
          <div class="col-span-1 text-center">
            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold bg-red-50 text-red-600 border border-red-100">
              Perlu Reorder
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<!-- BEGIN: Dual Section (Penerimaan GR & Mutasi Stok) -->
<div class="max-w-5xl grid grid-cols-1 md:grid-cols-2 gap-8 mb-12">
  <!-- Penerimaan Barang (GR) -->
  <div>
    <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-100">
      <h3 class="text-[14px] font-bold text-[#111827]">Penerimaan Barang (GR)</h3>
      <a href="index.php?page=expenditure_procurement/gr" class="text-[11px] font-semibold text-[#EE2726] hover:underline">Semua &rarr;</a>
    </div>
    <div class="space-y-2.5">
      <?php if (empty($recentGr)): ?>
        <p class="text-xs text-gray-400 py-3">Belum ada penerimaan barang tercatat.</p>
      <?php else: ?>
        <?php foreach ($recentGr as $gr): ?>
          <div class="flex items-center justify-between p-2.5 rounded-lg border border-gray-100 hover:border-red-100 text-[11.5px] transition-colors">
            <div>
              <p class="font-mono font-medium text-[#EE2726]"><?= e($gr['gr_number']) ?></p>
              <p class="text-[10px] text-gray-500"><?= e($gr['supplier_name']) ?> (PO: <?= e($gr['po_number'] ?? '-') ?>)</p>
            </div>
            <div class="text-right">
              <span class="px-2 py-0.5 rounded bg-green-50 text-green-700 text-[10px] font-semibold"><?= ucfirst(e($gr['status'])) ?></span>
              <p class="text-[10px] text-gray-400 mt-0.5"><?= formatDate($gr['receipt_date']) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Riwayat Mutasi Stok -->
  <div>
    <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-100">
      <h3 class="text-[14px] font-bold text-[#111827]">Mutasi Perpindahan Barang</h3>
      <a href="index.php?page=inventory/stock" class="text-[11px] font-semibold text-[#EE2726] hover:underline">Semua &rarr;</a>
    </div>
    <div class="space-y-2.5">
      <?php if (empty($recentMovements)): ?>
        <p class="text-xs text-gray-400 py-3">Belum ada mutasi stok tercatat.</p>
      <?php else: ?>
        <?php foreach ($recentMovements as $mv): ?>
          <div class="flex items-center justify-between p-2.5 rounded-lg border border-gray-100 hover:border-red-100 text-[11.5px] transition-colors">
            <div>
              <p class="font-bold text-gray-800"><?= e($mv['item_name']) ?></p>
              <p class="text-[10px] text-gray-400"><?= e($mv['warehouse_name']) ?> &bull; Ref: <?= e($mv['ref_number'] ?? '-') ?></p>
            </div>
            <div class="text-right">
              <p class="font-bold <?= $mv['qty_in'] > 0 ? 'text-green-600' : 'text-blue-600' ?>">
                <?= $mv['qty_in'] > 0 ? '+' : '-' ?><?= number_format($mv['qty']) ?> <?= e($mv['unit_name']) ?>
              </p>
              <p class="text-[10px] text-gray-400"><?= formatDate($mv['created_at']) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
include __DIR__ . '/../../includes/dashboard_footer.php';
