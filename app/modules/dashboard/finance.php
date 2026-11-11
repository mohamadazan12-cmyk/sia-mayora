<?php
/**
 * Dashboard Keuangan dan Akuntansi (Finance/Treasury)
 * Sistem Informasi Akuntansi & Operasional PT Mayora Indah Tbk
 * Sesuai Acuan Desain Visual Prototype HTML Mayora
 */

requireRole(['Administrator', 'admin', 'Finance/Treasury', 'finance', 'Petugas Keuangan / Finance', 'Supervisor / Manajer', 'manager', 'manajer']);

$db = getDbConnection();
$user = currentUser();

// Metrics
// 1. Total Piutang Usaha (AR) Belum Lunas
$totalAr = (float)$db->query("SELECT COALESCE(SUM(balance), 0) FROM sales_invoices WHERE status IN ('issued', 'partially_paid', 'overdue')")->fetchColumn();

// 2. Total Utang Usaha (AP) Belum Lunas
$totalAp = (float)$db->query("SELECT COALESCE(SUM(balance), 0) FROM supplier_invoices WHERE status IN ('verified', 'matched', 'partially_paid')")->fetchColumn();

// 3. Total Penerimaan Kas Pelanggan Terverifikasi
$totalCashIn = (float)$db->query("SELECT COALESCE(SUM(total_amount), 0) FROM customer_receipts WHERE status = 'verified'")->fetchColumn();

// 4. Total Pengeluaran Kas Bayar Pemasok
$totalCashOut = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM supplier_payments WHERE status = 'verified'")->fetchColumn();

// Status Keseimbangan Jurnal Akuntansi
$journalTotals = $db->query("
    SELECT COALESCE(SUM(debit), 0) AS total_debit, COALESCE(SUM(credit), 0) AS total_credit
    FROM journal_entry_lines
")->fetch();
$totalDebit = (float)($journalTotals['total_debit'] ?? 0);
$totalCredit = (float)($journalTotals['total_credit'] ?? 0);
$isBalanced = abs($totalDebit - $totalCredit) < 0.01;

// Jurnal Umum Terkini
$recentJournals = $db->query("
    SELECT je.id, je.entry_number, je.entry_date, je.description, je.status,
           COALESCE(SUM(jel.debit), 0) AS entry_amount,
           u.full_name AS creator_name
    FROM journal_entries je
    LEFT JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    LEFT JOIN users u ON je.created_by = u.id
    GROUP BY je.id, je.entry_number, je.entry_date, je.description, je.status, u.full_name
    ORDER BY je.id DESC
    LIMIT 6
")->fetchAll();

// Permintaan Pembayaran Pemasok Menunggu Realisasi
$pendingPayReqs = $db->query("
    SELECT pr.id, pr.request_number, pr.request_date, pr.amount, pr.status,
           s.name AS supplier_name
    FROM payment_requests pr
    LEFT JOIN suppliers s ON pr.supplier_id = s.id
    ORDER BY pr.id DESC
    LIMIT 4
")->fetchAll();

// Tagihan Piutang Jatuh Tempo Terdekat
$dueInvoices = $db->query("
    SELECT inv.id, inv.invoice_number, inv.due_date, inv.balance, c.name AS customer_name
    FROM sales_invoices inv
    JOIN customers c ON inv.customer_id = c.id
    WHERE inv.status IN ('issued', 'partially_paid', 'overdue')
    ORDER BY inv.due_date ASC
    LIMIT 4
")->fetchAll();

// Otorisasi SDM & Payroll Menunggu Finance
$pendingSalaryVerification = (int)$db->query("SELECT COUNT(*) FROM payroll_records WHERE status IN ('calculated', 'draft')")->fetchColumn();
$pendingPayrollApproval = (int)$db->query("SELECT COUNT(*) FROM payroll_periods WHERE status IN ('review', 'pending_finance')")->fetchColumn();
$approvedTerminations = (int)$db->query("SELECT COUNT(*) FROM employee_terminations WHERE status = 'Disetujui'")->fetchColumn();

$pageTitle = 'Dashboard Keuangan & Akuntansi';
$breadcrumbSection = 'Keuangan';
$appSubTitle = 'Sistem Informasi Keuangan & Akuntansi';
$notifCount = count($pendingPayReqs) + $pendingSalaryVerification + $pendingPayrollApproval;

include __DIR__ . '/../../includes/dashboard_header.php';
?>

<!-- Metrics Row 1: 4 Columns (Arus Kas & Buku Besar) -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-8 max-w-5xl mb-12" data-purpose="metrics-summary">
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Piutang Usaha (AR)</p>
    <span class="text-[26px] font-extrabold text-[#000000] tracking-tight leading-none"><?= formatRupiah($totalAr) ?></span>
    <span class="text-[11px] text-[#687588] mt-2">Tagihan belum lunas</span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Utang Usaha (AP)</p>
    <span class="text-[26px] font-extrabold text-[#000000] tracking-tight leading-none"><?= formatRupiah($totalAp) ?></span>
    <span class="text-[11px] text-[#687588] mt-2">Kewajiban ke pemasok</span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Penerimaan Kas (In)</p>
    <span class="text-[26px] font-extrabold text-[#000000] tracking-tight leading-none"><?= formatRupiah($totalCashIn) ?></span>
    <span class="text-[11px] text-[#687588] mt-2">Total kas masuk verified</span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Pengeluaran Kas (Out)</p>
    <span class="text-[26px] font-extrabold text-[#000000] tracking-tight leading-none"><?= formatRupiah($totalCashOut) ?></span>
    <span class="text-[11px] text-[#687588] mt-2">Pelunasan vendor verified</span>
  </div>
</div>

<!-- Metric Row 2: Status Keseimbangan Jurnal Akuntansi -->
<div class="max-w-4xl mb-6 p-4 rounded-lg <?= $isBalanced ? 'bg-green-50/70 border border-green-200' : 'bg-red-50 border border-red-200' ?> flex items-center justify-between">
  <div>
    <p class="text-[13px] font-bold <?= $isBalanced ? 'text-green-800' : 'text-red-800' ?>">
      <?= $isBalanced ? '✓ Neraca Saldo Jurnal Seimbang (Balanced)' : '⚠ Peringatan: Jurnal Belum Seimbang!' ?>
    </p>
    <p class="text-[11.5px] text-gray-600 mt-0.5">
      Total Debit: <?= formatRupiah($totalDebit) ?> &bull; Total Kredit: <?= formatRupiah($totalCredit) ?>
    </p>
  </div>
  <a href="index.php?page=reports/journals" class="text-[11.5px] font-semibold text-[#EE2726] hover:underline">
    Buku Jurnal &rarr;
  </a>
</div>

<!-- Quick Actions: Otorisasi SDM & Payroll Finance -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 max-w-4xl mb-12">
  <a href="index.php?page=hr_payroll/salary_verification" class="p-3.5 rounded-lg border border-gray-100 bg-white hover:border-red-300 transition-all flex flex-col justify-between shadow-sm">
    <div class="text-[11.5px] font-semibold text-gray-500">Verifikasi Gaji (Payroll)</div>
    <div class="flex items-baseline justify-between mt-2">
      <span class="text-[22px] font-bold text-gray-900"><?= $pendingSalaryVerification ?> Baris</span>
      <span class="text-[10px] px-2 py-0.5 rounded <?= $pendingSalaryVerification > 0 ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800' ?>"><?= $pendingSalaryVerification > 0 ? 'Perlu Cek' : 'Terverifikasi' ?></span>
    </div>
  </a>
  <a href="index.php?page=hr_payroll/approvals" class="p-3.5 rounded-lg border border-gray-100 bg-white hover:border-red-300 transition-all flex flex-col justify-between shadow-sm">
    <div class="text-[11.5px] font-semibold text-gray-500">Otorisasi Jurnal Akrual Gaji</div>
    <div class="flex items-baseline justify-between mt-2">
      <span class="text-[22px] font-bold text-gray-900"><?= $pendingPayrollApproval ?> Periode</span>
      <span class="text-[10px] px-2 py-0.5 rounded <?= $pendingPayrollApproval > 0 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-500' ?>"><?= $pendingPayrollApproval > 0 ? 'Menunggu Approval' : 'Diposting' ?></span>
    </div>
  </a>
  <a href="index.php?page=hr_payroll/terminations" class="p-3.5 rounded-lg border border-gray-100 bg-white hover:border-red-300 transition-all flex flex-col justify-between shadow-sm">
    <div class="text-[11.5px] font-semibold text-gray-500">Pembayaran Hak Akhir</div>
    <div class="flex items-baseline justify-between mt-2">
      <span class="text-[22px] font-bold text-gray-900"><?= $approvedTerminations ?> Berkas</span>
      <span class="text-[10px] px-2 py-0.5 rounded <?= $approvedTerminations > 0 ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-500' ?>"><?= $approvedTerminations > 0 ? 'Siap Cair' : 'Nihil' ?></span>
    </div>
  </a>
</div>

<!-- BEGIN: Tabel Jurnal Umum Terkini -->
<section class="max-w-5xl mb-14" data-purpose="journal-table-section">
  <div class="flex items-center justify-between mb-5">
    <h3 class="text-[14px] font-bold text-[#111827]">
      Jurnal Umum & Entri Akuntansi Terkini
    </h3>
    <a href="index.php?page=reports/journals" class="text-[11.5px] font-semibold text-[#EE2726] hover:underline">
      Semua Jurnal &rarr;
    </a>
  </div>
  <div class="w-full">
    <div class="grid grid-cols-6 gap-4 text-[12px] font-bold text-[#1e242b] pb-3 border-b border-gray-100">
      <div class="col-span-1 pl-4">No. Jurnal</div>
      <div class="col-span-2">Keterangan Transaksi</div>
      <div class="col-span-1">Tanggal</div>
      <div class="col-span-1 text-right">Nilai Jurnal</div>
      <div class="col-span-1 text-center">Status</div>
    </div>
    <?php if (empty($recentJournals)): ?>
      <div class="w-full py-6 text-center text-xs text-gray-400">
        Belum ada entri jurnal umum tercatat.
      </div>
    <?php else: ?>
      <?php foreach ($recentJournals as $je): ?>
        <div class="grid grid-cols-6 gap-4 text-[11.5px] text-[#2d3139] py-3 border-b border-gray-50 hover:bg-gray-50/60 transition-colors items-center">
          <div class="col-span-1 pl-4 font-mono font-medium text-[#EE2726]">
            <?= e($je['entry_number']) ?>
          </div>
          <div class="col-span-2">
            <p class="font-medium text-gray-900 truncate"><?= e($je['description']) ?></p>
            <p class="text-[10px] text-gray-400">Oleh: <?= e($je['creator_name'] ?? 'Akuntan') ?></p>
          </div>
          <div class="col-span-1 text-gray-500"><?= formatDate($je['entry_date']) ?></div>
          <div class="col-span-1 text-right font-semibold text-gray-900"><?= formatRupiah($je['entry_amount']) ?></div>
          <div class="col-span-1 text-center">
            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold bg-green-50 text-green-700 border border-green-200">
              <?= ucfirst(e($je['status'])) ?>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<!-- BEGIN: Dual Section (Permintaan Bayar & Piutang Jatuh Tempo) -->
<div class="max-w-5xl grid grid-cols-1 md:grid-cols-2 gap-8 mb-12">
  <!-- Permintaan Pembayaran Pemasok -->
  <div>
    <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-100">
      <h3 class="text-[14px] font-bold text-[#111827]">Permintaan Pembayaran Pemasok</h3>
      <a href="index.php?page=expenditure_procurement/payment_requests" class="text-[11px] font-semibold text-[#EE2726] hover:underline">Semua &rarr;</a>
    </div>
    <div class="space-y-2.5">
      <?php if (empty($pendingPayReqs)): ?>
        <p class="text-xs text-gray-400 py-3">Tidak ada permohonan pembayaran terbuka.</p>
      <?php else: ?>
        <?php foreach ($pendingPayReqs as $pr): ?>
          <div class="flex items-center justify-between p-2.5 rounded-lg border border-gray-100 hover:border-red-100 text-[11.5px] transition-colors">
            <div>
              <p class="font-mono font-medium text-[#EE2726]"><?= e($pr['request_number']) ?></p>
              <p class="text-[10px] text-gray-500"><?= e($pr['supplier_name']) ?></p>
            </div>
            <div class="text-right">
              <p class="font-bold text-gray-900"><?= formatRupiah($pr['amount']) ?></p>
              <span class="px-2 py-0.5 rounded bg-amber-50 text-amber-700 text-[10px] font-semibold"><?= ucfirst(e($pr['status'])) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tagihan Piutang Jatuh Tempo -->
  <div>
    <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-100">
      <h3 class="text-[14px] font-bold text-[#111827]">Piutang Menunggu Penagihan</h3>
      <a href="index.php?page=receiving_sales/invoices" class="text-[11px] font-semibold text-[#EE2726] hover:underline">Semua &rarr;</a>
    </div>
    <div class="space-y-2.5">
      <?php if (empty($dueInvoices)): ?>
        <p class="text-xs text-gray-400 py-3">Semua piutang lancar.</p>
      <?php else: ?>
        <?php foreach ($dueInvoices as $inv): ?>
          <div class="flex items-center justify-between p-2.5 rounded-lg border border-gray-100 hover:border-red-100 text-[11.5px] transition-colors">
            <div>
              <p class="font-mono font-medium text-[#EE2726]"><?= e($inv['invoice_number']) ?></p>
              <p class="text-[10px] text-gray-500"><?= e($inv['customer_name']) ?></p>
            </div>
            <div class="text-right">
              <p class="font-bold text-gray-900"><?= formatRupiah($inv['balance']) ?></p>
              <p class="text-[10px] text-red-500">Tempo: <?= formatDate($inv['due_date']) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
include __DIR__ . '/../../includes/dashboard_footer.php';
