<?php
/**
 * Dashboard SDM dan Penggajian (HR / Payroll)
 * Sistem Informasi Akuntansi & Operasional PT Mayora Indah Tbk
 * Sesuai Acuan Desain Visual Prototype HTML Mayora
 */

requireRole(['Administrator', 'admin', 'HR / Payroll', 'hr', 'Supervisor / Manajer', 'manager', 'manajer']);

$db = getDbConnection();
$user = currentUser();

// Metrics
// 1. Total Karyawan Aktif
$totalEmployees = (int)$db->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();

// 2. Kehadiran Karyawan Hari Ini
$todayAttendance = (int)$db->query("SELECT COUNT(*) FROM attendance_records WHERE attendance_date = CURRENT_DATE() AND status = 'present'")->fetchColumn();

// 3. Pengajuan Cuti & Lembur Menunggu Otorisasi
$pendingLeaves = (int)$db->query("SELECT COUNT(*) FROM leave_overtime_requests WHERE status = 'pending'")->fetchColumn();

// 4. Usulan Mutasi & Promosi Menunggu Otorisasi
$pendingMutations = (int)$db->query("SELECT COUNT(*) FROM employee_mutations WHERE status = 'Menunggu'")->fetchColumn();

// 5. Usulan Pelatihan Menunggu Otorisasi
$pendingTrainings = (int)$db->query("SELECT COUNT(*) FROM employee_trainings WHERE status = 'Menunggu'")->fetchColumn();

// 6. Usulan Hak Akhir Menunggu Persetujuan
$pendingTerminations = (int)$db->query("SELECT COUNT(*) FROM employee_terminations WHERE status = 'Menunggu Persetujuan'")->fetchColumn();

// 7. Gaji Menunggu Verifikasi
$pendingSalaryVerification = (int)$db->query("SELECT COUNT(*) FROM payroll_records WHERE status IN ('calculated', 'draft')")->fetchColumn();

// 8. Periode Payroll Menunggu Persetujuan
$pendingPayroll = (int)$db->query("SELECT COUNT(*) FROM payroll_periods WHERE status = 'calculated'")->fetchColumn();

// 9. Total Beban Penggajian Periode Terakhir
$latestPayrollTotal = (float)$db->query("
    SELECT COALESCE(SUM(pr.net_salary), 0)
    FROM payroll_records pr
    JOIN (
        SELECT id FROM payroll_periods ORDER BY id DESC LIMIT 1
    ) lp ON pr.payroll_period_id = lp.id
")->fetchColumn();

// Daftar Pengajuan Cuti & Lembur Menunggu Persetujuan
$pendingRequests = $db->query("
    SELECT lor.id, lor.request_number, lor.request_type, lor.start_date, lor.end_date, lor.hours, lor.reason, lor.status,
           e.code, e.full_name AS employee_name, d.name AS department_name
    FROM leave_overtime_requests lor
    JOIN employees e ON lor.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE lor.status = 'pending'
    ORDER BY lor.id DESC
    LIMIT 6
")->fetchAll();

// Periode Penggajian Terbaru
$recentPeriods = $db->query("
    SELECT pp.id, pp.period_code, pp.period_month, pp.period_year, pp.start_date, pp.end_date, pp.status,
           COUNT(pr.id) AS total_processed,
           COALESCE(SUM(pr.net_salary), 0) AS total_payroll
    FROM payroll_periods pp
    LEFT JOIN payroll_records pr ON pp.id = pr.payroll_period_id
    GROUP BY pp.id, pp.period_code, pp.period_month, pp.period_year, pp.start_date, pp.end_date, pp.status
    ORDER BY pp.id DESC
    LIMIT 4
")->fetchAll();

// Daftar Karyawan Baru / Terkini
$recentEmployees = $db->query("
    SELECT e.id, e.code, e.full_name AS name, e.status, e.join_date,
           d.name AS department_name, p.name AS position_title
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN positions p ON e.position_id = p.id
    WHERE e.status = 'active'
    ORDER BY e.id DESC
    LIMIT 4
")->fetchAll();

$pageTitle = 'Dashboard SDM & Penggajian';
$breadcrumbSection = 'SDM & Penggajian';
$appSubTitle = 'Sistem Informasi SDM dan Penggajian';
$notifCount = $pendingLeaves + $pendingPayroll;

include __DIR__ . '/../../includes/dashboard_header.php';
?>

<!-- Metrics Row 1: 3 Columns -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-4xl mb-12" data-purpose="metrics-summary">
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Total Karyawan Aktif</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $totalEmployees ?></span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Presensi Hadir Hari Ini</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $todayAttendance ?></span>
  </div>
  <div class="flex flex-col">
    <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Cuti &amp; Lembur Menunggu</p>
    <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= $pendingLeaves ?></span>
  </div>
</div>

<!-- Metric Row 2: Beban Gaji Periode Terakhir -->
<div class="max-w-4xl mb-14" data-purpose="single-metric">
  <p class="text-[14px] font-semibold text-[#1e242b] mb-4">Realisasi Beban Gaji Terakhir</p>
  <span class="text-[34px] font-extrabold text-[#000000] tracking-tight leading-none"><?= formatRupiah($latestPayrollTotal) ?></span>
</div>

<!-- Quick Access / SDM Action Cards (Mayora SISP Integration) -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 max-w-5xl mb-10">
  <a href="index.php?page=hr_payroll/mutations" class="p-4 rounded-lg border border-gray-100 bg-white hover:border-red-300 transition-all flex flex-col justify-between shadow-sm">
    <div class="text-[12px] font-semibold text-gray-500">Mutasi &amp; Promosi</div>
    <div class="flex items-baseline justify-between mt-2">
      <span class="text-[24px] font-bold text-gray-900"><?= $pendingMutations ?></span>
      <span class="text-[10.5px] px-2 py-0.5 rounded <?= $pendingMutations > 0 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600' ?>">Menunggu</span>
    </div>
  </a>
  <a href="index.php?page=hr_payroll/trainings" class="p-4 rounded-lg border border-gray-100 bg-white hover:border-red-300 transition-all flex flex-col justify-between shadow-sm">
    <div class="text-[12px] font-semibold text-gray-500">Program Pelatihan</div>
    <div class="flex items-baseline justify-between mt-2">
      <span class="text-[24px] font-bold text-gray-900"><?= $pendingTrainings ?></span>
      <span class="text-[10.5px] px-2 py-0.5 rounded <?= $pendingTrainings > 0 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600' ?>">Menunggu</span>
    </div>
  </a>
  <a href="index.php?page=hr_payroll/salary_verification" class="p-4 rounded-lg border border-gray-100 bg-white hover:border-red-300 transition-all flex flex-col justify-between shadow-sm">
    <div class="text-[12px] font-semibold text-gray-500">Verifikasi Gaji</div>
    <div class="flex items-baseline justify-between mt-2">
      <span class="text-[24px] font-bold text-gray-900"><?= $pendingSalaryVerification ?></span>
      <span class="text-[10.5px] px-2 py-0.5 rounded <?= $pendingSalaryVerification > 0 ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800' ?>">Periksa</span>
    </div>
  </a>
  <a href="index.php?page=hr_payroll/terminations" class="p-4 rounded-lg border border-gray-100 bg-white hover:border-red-300 transition-all flex flex-col justify-between shadow-sm">
    <div class="text-[12px] font-semibold text-gray-500">Hak Akhir Karyawan</div>
    <div class="flex items-baseline justify-between mt-2">
      <span class="text-[24px] font-bold text-gray-900"><?= $pendingTerminations ?></span>
      <span class="text-[10.5px] px-2 py-0.5 rounded <?= $pendingTerminations > 0 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600' ?>">Menunggu</span>
    </div>
  </a>
</div>

<!-- BEGIN: Tabel Pengajuan Cuti & Lembur -->
<section class="max-w-5xl mb-14" data-purpose="leave-table-section">
  <div class="flex items-center justify-between mb-5">
    <h3 class="text-[14px] font-bold text-[#111827]">
      Pengajuan Cuti & Lembur Menunggu Persetujuan
    </h3>
    <a href="index.php?page=hr_payroll/leave_overtime" class="text-[11.5px] font-semibold text-[#EE2726] hover:underline">
      Kelola Semua Pengajuan &rarr;
    </a>
  </div>
  <div class="w-full">
    <div class="grid grid-cols-6 gap-4 text-[12px] font-bold text-[#1e242b] pb-3 border-b border-gray-100">
      <div class="col-span-1 pl-4">No. Pengajuan</div>
      <div class="col-span-2">Nama Karyawan</div>
      <div class="col-span-1">Jenis / Durasi</div>
      <div class="col-span-1">Tanggal Mulai</div>
      <div class="col-span-1 text-center">Status</div>
    </div>
    <?php if (empty($pendingRequests)): ?>
      <div class="w-full py-6 text-center text-xs text-gray-400">
        Tidak ada permohonan cuti atau lembur yang menunggu persetujuan.
      </div>
    <?php else: ?>
      <?php foreach ($pendingRequests as $req): ?>
        <div class="grid grid-cols-6 gap-4 text-[11.5px] text-[#2d3139] py-3 border-b border-gray-50 hover:bg-gray-50/60 transition-colors items-center">
          <div class="col-span-1 pl-4 font-mono font-medium text-[#EE2726]"><?= e($req['request_number']) ?></div>
          <div class="col-span-2">
            <p class="font-medium text-gray-900"><?= e($req['employee_name']) ?></p>
            <p class="text-[10px] text-gray-400"><?= e($req['department_name'] ?? 'Pabrik') ?> (<?= e($req['code']) ?>)</p>
          </div>
          <div class="col-span-1 text-gray-700">
            <span class="font-semibold"><?= ucfirst(e($req['request_type'])) ?></span>
            <span class="text-[10px] text-gray-400 block"><?= $req['hours'] ? $req['hours'] . ' Jam' : '-' ?></span>
          </div>
          <div class="col-span-1 text-gray-500"><?= formatDate($req['start_date']) ?></div>
          <div class="col-span-1 text-center">
            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">
              <?= ucfirst(e($req['status'])) ?>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<!-- BEGIN: Dual Section (Periode Payroll & Data Karyawan) -->
<div class="max-w-5xl grid grid-cols-1 md:grid-cols-2 gap-8 mb-12">
  <!-- Periode Penggajian Terbaru -->
  <div>
    <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-100">
      <h3 class="text-[14px] font-bold text-[#111827]">Periode Penggajian (Payroll)</h3>
      <a href="index.php?page=hr_payroll/payroll" class="text-[11px] font-semibold text-[#EE2726] hover:underline">Semua &rarr;</a>
    </div>
    <div class="space-y-2.5">
      <?php if (empty($recentPeriods)): ?>
        <p class="text-xs text-gray-400 py-3">Belum ada periode penggajian.</p>
      <?php else: ?>
        <?php foreach ($recentPeriods as $pp): ?>
          <div class="flex items-center justify-between p-2.5 rounded-lg border border-gray-100 hover:border-red-100 text-[11.5px] transition-colors">
            <div>
              <p class="font-bold text-gray-800"><?= e($pp['period_code']) ?></p>
              <p class="text-[10px] text-gray-400">Bulan <?= e($pp['period_month']) ?>/<?= e($pp['period_year']) ?> &bull; <?= (int)$pp['total_processed'] ?> Karyawan</p>
            </div>
            <div class="text-right">
              <p class="font-bold text-gray-900"><?= formatRupiah($pp['total_payroll']) ?></p>
              <span class="px-2 py-0.5 rounded bg-blue-50 text-blue-700 text-[10px] font-semibold"><?= ucfirst(e($pp['status'])) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Data Karyawan Terkini -->
  <div>
    <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-100">
      <h3 class="text-[14px] font-bold text-[#111827]">Karyawan Baru Terdaftar</h3>
      <a href="index.php?page=hr_payroll/employees" class="text-[11px] font-semibold text-[#EE2726] hover:underline">Semua &rarr;</a>
    </div>
    <div class="space-y-2.5">
      <?php if (empty($recentEmployees)): ?>
        <p class="text-xs text-gray-400 py-3">Belum ada karyawan terdaftar.</p>
      <?php else: ?>
        <?php foreach ($recentEmployees as $em): ?>
          <div class="flex items-center justify-between p-2.5 rounded-lg border border-gray-100 hover:border-red-100 text-[11.5px] transition-colors">
            <div>
              <p class="font-bold text-gray-800"><?= e($em['name']) ?></p>
              <p class="text-[10px] text-gray-400"><?= e($em['position_title'] ?? 'Staff') ?> (<?= e($em['department_name'] ?? 'Pabrik') ?>)</p>
            </div>
            <div class="text-right">
              <span class="px-2 py-0.5 rounded bg-green-50 text-green-700 text-[10px] font-semibold">Aktif</span>
              <p class="text-[10px] text-gray-400 mt-0.5">Gabung: <?= formatDate($em['join_date']) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php
include __DIR__ . '/../../includes/dashboard_footer.php';
