<?php
/**
 * Dashboard Manajemen Operasional (Supervisor / Manajer)
 * Sistem Informasi Akuntansi & Operasional PT Mayora Indah Tbk
 * Sesuai Acuan Desain Visual Prototype HTML Mayora
 */

requireRole(['Administrator', 'admin', 'Supervisor / Manajer', 'manager', 'manajer']);

$db = getDbConnection();
$user = currentUser();

// Metrics Lintas Departemen
// 1. Omset Penjualan (Revenue)
$totalRevenue = (float)$db->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales_orders WHERE status NOT IN ('cancelled')")->fetchColumn();

// 2. Total Produksi Selesai
$totalProductionDone = (float)$db->query("SELECT COALESCE(SUM(actual_qty), 0) FROM production_orders WHERE status = 'completed'")->fetchColumn();

// 3. Realisasi Belanja Pengadaan (Purchasing)
$totalPurchases = (float)$db->query("SELECT COALESCE(SUM(total_amount), 0) FROM purchase_orders WHERE status NOT IN ('cancelled')")->fetchColumn();

// 4. Karyawan & Payroll
$activeEmployees = (int)$db->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();

// Antrean Persetujuan Transaksi (Approval Queue) Lintas Modul
// SDM Approvals
$pendingMutations = (int)$db->query("SELECT COUNT(*) FROM employee_mutations WHERE status = 'Menunggu'")->fetchColumn();
$pendingTrainings = (int)$db->query("SELECT COUNT(*) FROM employee_trainings WHERE status = 'Menunggu'")->fetchColumn();
$pendingTerminations = (int)$db->query("SELECT COUNT(*) FROM employee_terminations WHERE status = 'Menunggu Persetujuan'")->fetchColumn();
$pendingSalaryVerification = (int)$db->query("SELECT COUNT(*) FROM payroll_records WHERE status IN ('calculated', 'draft')")->fetchColumn();

// PR Pending
$pendingPrList = $db->query("
    SELECT pr.id, 'Permintaan Pembelian (PR)' AS doc_type, pr.pr_number AS doc_number, pr.request_date AS doc_date,
           pr.total_estimated AS amount, d.name AS origin, 'expenditure_procurement/pr_detail' AS route_link
    FROM purchase_requisitions pr
    LEFT JOIN departments d ON pr.department_id = d.id
    WHERE pr.status = 'pending_approval'
    ORDER BY pr.id DESC LIMIT 4
")->fetchAll();

// PO Pending
$pendingPoList = $db->query("
    SELECT po.id, 'Pesanan Pembelian (PO)' AS doc_type, po.po_number AS doc_number, po.po_date AS doc_date,
           po.total_amount AS amount, s.name AS origin, 'expenditure_procurement/po_detail' AS route_link
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    WHERE po.status = 'pending_approval'
    ORDER BY po.id DESC LIMIT 4
")->fetchAll();

// SPK Pending
$pendingSpkList = $db->query("
    SELECT pdo.id, 'Perintah Produksi (SPK)' AS doc_type, pdo.po_number AS doc_number, pdo.planned_start AS doc_date,
           pdo.target_qty AS amount, i.name AS origin, 'production/order_detail' AS route_link
    FROM production_orders pdo
    LEFT JOIN items i ON pdo.product_item_id = i.id
    WHERE pdo.status = 'pending_approval'
    ORDER BY pdo.id DESC LIMIT 4
")->fetchAll();

$approvalQueue = array_merge($pendingPrList, $pendingPoList, $pendingSpkList);
usort($approvalQueue, function($a, $b) {
    return strcmp($b['doc_date'], $a['doc_date']);
});

$totalPendingApprovals = count($approvalQueue);

// Aktivitas Audit & Operasional Terkini
$recentAudit = $db->query("
    SELECT al.id, al.action, al.entity_type, al.new_values AS notes, al.created_at, u.full_name AS user_name
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    ORDER BY al.id DESC
    LIMIT 5
")->fetchAll();

$pageTitle = 'Dashboard Manajemen Operasional';
$breadcrumbSection = 'Manajemen';
$appSubTitle = 'Sistem Informasi Manajemen Operasional';
$notifCount = $totalPendingApprovals;

include __DIR__ . '/../../includes/dashboard_header.php';
?>

<!-- Metrics Row 1: 4 Columns (Eksekutif) -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-8 max-w-5xl mb-12" data-purpose="metrics-summary">
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Total Omset Pesanan</p>
    <span class="text-[26px] font-extrabold text-[#000000] tracking-tight leading-none"><?= formatRupiah($totalRevenue) ?></span>
    <span class="text-[11px] text-[#687588] mt-2">Akumulasi Sales Order</span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Produksi Selesai</p>
    <span class="text-[26px] font-extrabold text-[#000000] tracking-tight leading-none"><?= number_format($totalProductionDone) ?> Unit</span>
    <span class="text-[11px] text-[#687588] mt-2">Hasil output pabrikasi</span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Realisasi Pengadaan</p>
    <span class="text-[26px] font-extrabold text-[#000000] tracking-tight leading-none"><?= formatRupiah($totalPurchases) ?></span>
    <span class="text-[11px] text-[#687588] mt-2">Purchase Order vendor</span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Total Karyawan</p>
    <span class="text-[26px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $activeEmployees ?> Orang</span>
    <span class="text-[11px] text-[#687588] mt-2">Sumber daya aktif</span>
  </div>
</div>

<!-- Otorisasi SDM & Personalia (SISP Mayora Integration) -->
<div class="max-w-5xl mb-10">
  <div class="flex items-center justify-between mb-3">
    <h3 class="text-[14px] font-bold text-[#111827]">Otorisasi SDM &amp; Personalia</h3>
    <span class="text-[11px] text-gray-400">Persetujuan usulan mutasi, pelatihan, verifikasi gaji &amp; hak akhir</span>
  </div>
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <a href="index.php?page=hr_payroll/mutations" class="p-3.5 rounded-lg border border-gray-100 bg-white hover:border-red-300 transition-all flex flex-col justify-between shadow-sm">
      <div class="text-[11.5px] font-semibold text-gray-500">Mutasi &amp; Promosi</div>
      <div class="flex items-baseline justify-between mt-2">
        <span class="text-[22px] font-bold text-gray-900"><?= $pendingMutations ?></span>
        <span class="text-[10px] px-2 py-0.5 rounded <?= $pendingMutations > 0 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-500' ?>"><?= $pendingMutations > 0 ? 'Menunggu' : 'Nihil' ?></span>
      </div>
    </a>
    <a href="index.php?page=hr_payroll/trainings" class="p-3.5 rounded-lg border border-gray-100 bg-white hover:border-red-300 transition-all flex flex-col justify-between shadow-sm">
      <div class="text-[11.5px] font-semibold text-gray-500">Program Pelatihan</div>
      <div class="flex items-baseline justify-between mt-2">
        <span class="text-[22px] font-bold text-gray-900"><?= $pendingTrainings ?></span>
        <span class="text-[10px] px-2 py-0.5 rounded <?= $pendingTrainings > 0 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-500' ?>"><?= $pendingTrainings > 0 ? 'Menunggu' : 'Nihil' ?></span>
      </div>
    </a>
    <a href="index.php?page=hr_payroll/salary_verification" class="p-3.5 rounded-lg border border-gray-100 bg-white hover:border-red-300 transition-all flex flex-col justify-between shadow-sm">
      <div class="text-[11.5px] font-semibold text-gray-500">Verifikasi Gaji</div>
      <div class="flex items-baseline justify-between mt-2">
        <span class="text-[22px] font-bold text-gray-900"><?= $pendingSalaryVerification ?></span>
        <span class="text-[10px] px-2 py-0.5 rounded <?= $pendingSalaryVerification > 0 ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800' ?>"><?= $pendingSalaryVerification > 0 ? 'Perlu Otorisasi' : 'Selesai' ?></span>
      </div>
    </a>
    <a href="index.php?page=hr_payroll/terminations" class="p-3.5 rounded-lg border border-gray-100 bg-white hover:border-red-300 transition-all flex flex-col justify-between shadow-sm">
      <div class="text-[11.5px] font-semibold text-gray-500">Hak Akhir Karyawan</div>
      <div class="flex items-baseline justify-between mt-2">
        <span class="text-[22px] font-bold text-gray-900"><?= $pendingTerminations ?></span>
        <span class="text-[10px] px-2 py-0.5 rounded <?= $pendingTerminations > 0 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-500' ?>"><?= $pendingTerminations > 0 ? 'Menunggu' : 'Nihil' ?></span>
      </div>
    </a>
  </div>
</div>

<!-- BEGIN: Tabel Antrean Otorisasi Transaksi -->
<section class="max-w-5xl mb-14" data-purpose="approval-queue-section">
  <div class="flex items-center justify-between mb-5">
    <div>
      <h3 class="text-[14px] font-bold text-[#111827]">
        Antrean Dokumen Menunggu Persetujuan (Approval)
      </h3>
      <p class="text-[11px] text-[#687588]">Dokumen operasional yang membutuhkan otorisasi manajerial</p>
    </div>
    <span class="px-2.5 py-1 rounded-full bg-[#FFF2F2] text-[#EE2726] text-[11px] font-bold">
      <?= $totalPendingApprovals ?> Dokumen
    </span>
  </div>
  <div class="w-full">
    <div class="grid grid-cols-6 gap-4 text-[12px] font-bold text-[#1e242b] pb-3 border-b border-gray-100">
      <div class="col-span-1 pl-4">No. Dokumen</div>
      <div class="col-span-2">Jenis Dokumen</div>
      <div class="col-span-1">Asal / Entitas</div>
      <div class="col-span-1">Tanggal</div>
      <div class="col-span-1 text-center">Tindakan</div>
    </div>
    <?php if (empty($approvalQueue)): ?>
      <div class="w-full py-6 text-center text-xs text-gray-400">
        Semua transaksi telah disetujui. Tidak ada dokumen tertunda.
      </div>
    <?php else: ?>
      <?php foreach ($approvalQueue as $doc): ?>
        <div class="grid grid-cols-6 gap-4 text-[11.5px] text-[#2d3139] py-3 border-b border-gray-50 hover:bg-gray-50/60 transition-colors items-center">
          <div class="col-span-1 pl-4 font-mono font-medium text-[#EE2726]">
            <?= e($doc['doc_number']) ?>
          </div>
          <div class="col-span-2 font-medium text-gray-900">
            <?= e($doc['doc_type']) ?>
          </div>
          <div class="col-span-1 text-gray-600 truncate"><?= e($doc['origin'] ?? 'Internal') ?></div>
          <div class="col-span-1 text-gray-500"><?= formatDate($doc['doc_date']) ?></div>
          <div class="col-span-1 text-center">
            <a href="index.php?page=<?= e($doc['route_link']) ?>&id=<?= $doc['id'] ?>" class="inline-block px-2.5 py-1 rounded bg-[#EE2726] text-white text-[10px] font-semibold hover:bg-red-700 transition-colors">
              Tinjau &rarr;
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<!-- BEGIN: Aktivitas Audit & Operasional Terkini -->
<section class="max-w-5xl mb-12" data-purpose="audit-trail-section">
  <div class="flex items-center justify-between mb-5">
    <h3 class="text-[14px] font-bold text-[#111827]">
      Log Audit & Aktivitas Sistem Terkini
    </h3>
    <a href="index.php?page=reports/audit" class="text-[11.5px] font-semibold text-[#EE2726] hover:underline">
      Log Lengkap &rarr;
    </a>
  </div>
  <div class="w-full">
    <div class="grid grid-cols-6 gap-4 text-[12px] font-bold text-[#1e242b] pb-3 border-b border-gray-100">
      <div class="col-span-1 pl-4">Waktu</div>
      <div class="col-span-1">Pengguna</div>
      <div class="col-span-1">Tindakan</div>
      <div class="col-span-1">Entitas</div>
      <div class="col-span-2">Catatan Perubahan</div>
    </div>
    <?php if (empty($recentAudit)): ?>
      <div class="w-full py-6 text-center text-xs text-gray-400">
        Belum ada catatan aktivitas sistem.
      </div>
    <?php else: ?>
      <?php foreach ($recentAudit as $log): ?>
        <div class="grid grid-cols-6 gap-4 text-[11.5px] text-[#2d3139] py-3 border-b border-gray-50 hover:bg-gray-50/60 transition-colors items-center">
          <div class="col-span-1 pl-4 text-gray-500"><?= formatDate($log['created_at']) ?></div>
          <div class="col-span-1 font-medium text-gray-900"><?= e($log['user_name'] ?? 'Sistem') ?></div>
          <div class="col-span-1">
            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-700">
              <?= e($log['action']) ?>
            </span>
          </div>
          <div class="col-span-1 font-mono text-gray-600 text-[10.5px]"><?= e($log['entity_type']) ?></div>
          <div class="col-span-2 text-gray-500 truncate text-[11px]"><?= e($log['notes'] ?? '-') ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<?php
include __DIR__ . '/../../includes/dashboard_footer.php';
