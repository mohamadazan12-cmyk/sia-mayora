<?php
/**
 * Modul SDM & Penggajian: Slip Gaji Karyawan (Official Payslips)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Slip Gaji Pegawai (Payslips)';
$breadcrumbCategory = 'SDM & Penggajian';

$db = getDbConnection();
$user = currentUser();

$selectedSlipId = (int)($_GET['id'] ?? 0);
$selectedPeriodId = (int)($_GET['period_id'] ?? 0);

// Determine employee ID for current logged-in user
$userEmployeeId = null;
$stmtUserEmp = $db->prepare("SELECT id FROM employees WHERE user_id = :uid LIMIT 1");
$stmtUserEmp->execute([':uid' => $user['id']]);
$userEmployeeId = (int)$stmtUserEmp->fetchColumn();

// Role Check: Can user view all payslips?
$canViewAll = hasRole(['Administrator', 'HR / Payroll', 'Finance/Treasury', 'Supervisor / Manajer', 'admin', 'hr', 'finance', 'manager']);

// Fetch Payslips List with RBAC enforcement
$where = ['1=1'];
$params = [];

if (!$canViewAll) {
    if (!$userEmployeeId) {
        $where[] = '1=0'; // No employee record linked to this user
    } else {
        $where[] = 'ps.employee_id = :user_emp_id';
        $params[':user_emp_id'] = $userEmployeeId;
    }
} else {
    if ($selectedPeriodId > 0) {
        $where[] = 'ps.payroll_period_id = :p_id';
        $params[':p_id'] = $selectedPeriodId;
    }
}

$whereSql = implode(' AND ', $where);

$stmtList = $db->prepare("
    SELECT ps.*, e.code AS employee_code, e.full_name AS employee_name,
           d.name AS department_name, p.name AS position_name,
           pp.period_code, pp.period_month, pp.period_year,
           pr.base_salary, pr.total_earnings, pr.total_deductions, pr.net_salary
    FROM payslips ps
    JOIN employees e ON ps.employee_id = e.id
    JOIN departments d ON e.department_id = d.id
    JOIN positions p ON e.position_id = p.id
    JOIN payroll_periods pp ON ps.payroll_period_id = pp.id
    JOIN payroll_records pr ON ps.payroll_record_id = pr.id
    WHERE {$whereSql}
    ORDER BY pp.period_year DESC, pp.period_month DESC, e.full_name ASC
");
$stmtList->execute($params);
$payslips = $stmtList->fetchAll();

// If slip is selected, fetch full details & components with RBAC check
$activeSlip = null;
$earningsComponents = [];
$deductionsComponents = [];

if ($selectedSlipId > 0) {
    $stmtDetail = $db->prepare("
        SELECT ps.*, e.id AS emp_id, e.code AS employee_code, e.full_name AS employee_name, e.bank_name, e.bank_account,
               d.name AS department_name, p.name AS position_name,
               pp.period_code, pp.period_month, pp.period_year, pp.start_date, pp.end_date,
               pr.base_salary, pr.total_earnings, pr.total_deductions, pr.net_salary
        FROM payslips ps
        JOIN employees e ON ps.employee_id = e.id
        JOIN departments d ON e.department_id = d.id
        JOIN positions p ON e.position_id = p.id
        JOIN payroll_periods pp ON ps.payroll_period_id = pp.id
        JOIN payroll_records pr ON ps.payroll_record_id = pr.id
        WHERE ps.id = :id
    ");
    $stmtDetail->execute([':id' => $selectedSlipId]);
    $activeSlip = $stmtDetail->fetch();

    if ($activeSlip) {
        // Enforce RBAC for viewing this specific payslip
        if (!$canViewAll && (int)$activeSlip['emp_id'] !== $userEmployeeId) {
            setFlash('error', 'Akses ditolak. Anda hanya diperbolehkan melihat slip gaji milik Anda sendiri.');
            header('Location: index.php?page=hr_payroll/payslips');
            exit;
        }

        // Fetch Components
        $stmtComps = $db->prepare("
            SELECT prc.*, pc.name AS component_name, pc.code AS component_code
            FROM payroll_record_components prc
            JOIN payroll_components pc ON prc.payroll_component_id = pc.id
            WHERE prc.payroll_record_id = :rec_id
            ORDER BY pc.sort_order ASC
        ");
        $stmtComps->execute([':rec_id' => $activeSlip['payroll_record_id']]);
        $allComps = $stmtComps->fetchAll();

        foreach ($allComps as $c) {
            if ($c['component_type'] === 'penghasilan') {
                $earningsComponents[] = $c;
            } else {
                $deductionsComponents[] = $c;
            }
        }
    }
}

// Available periods for filter (HR/Admin)
$periods = $db->query("SELECT id, period_code FROM payroll_periods ORDER BY id DESC")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<!-- Print-Only Official Payslip Slip -->
<?php if ($activeSlip): ?>
<div class="print-only" style="display:none; padding: 25px; font-family: 'Segoe UI', Tahoma, sans-serif;">
    <div style="border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; display:flex; justify-content:space-between; align-items:flex-start;">
        <div>
            <h2 style="margin:0; font-size:22px; text-transform:uppercase;">SLIP GAJI KARYAWAN (CONFIDENTIAL)</h2>
            <div style="font-weight:bold; font-size:14px; margin-top:3px;">PT MANUFAKTUR &amp; DISTRIBUSI OPERASIONAL INDONESIA</div>
            <div style="font-size:11px; color:#475569;">Kawasan Industri Sentul, Bogor, Jawa Barat</div>
        </div>
        <div style="text-align:right;">
            <h3 style="margin:0; font-size:16px;"><?= e($activeSlip['slip_number']) ?></h3>
            <div>Periode: <strong><?= e($activeSlip['period_code']) ?> (Bulan <?= $activeSlip['period_month'] ?>/<?= $activeSlip['period_year'] ?>)</strong></div>
            <div style="font-size:11px;">Tanggal Cetak: <?= date('d-m-Y H:i') ?></div>
        </div>
    </div>

    <!-- Employee Info Box -->
    <table style="width:100%; margin-bottom: 20px; font-size:13px; line-height: 1.6;">
        <tr>
            <td style="width:20%;"><strong>NIK / Kode</strong></td>
            <td style="width:30%;">: <?= e($activeSlip['employee_code']) ?></td>
            <td style="width:20%;"><strong>Departemen</strong></td>
            <td style="width:30%;">: <?= e($activeSlip['department_name']) ?></td>
        </tr>
        <tr>
            <td><strong>Nama Lengkap</strong></td>
            <td>: <strong><?= e($activeSlip['employee_name']) ?></strong></td>
            <td><strong>Jabatan / Posisi</strong></td>
            <td>: <?= e($activeSlip['position_name']) ?></td>
        </tr>
        <tr>
            <td><strong>Rekening Payroll</strong></td>
            <td>: <?= e($activeSlip['bank_name'] ?: 'Bank Mandiri') ?> (<?= e($activeSlip['bank_account'] ?: '-') ?>)</td>
            <td><strong>Status Dokumen</strong></td>
            <td>: RESMI / TERTUTUP</td>
        </tr>
    </table>

    <!-- Two-column Earnings vs Deductions Table -->
    <div style="display:flex; gap:20px; margin-bottom:20px;">
        <!-- Earnings Column -->
        <div style="flex:1;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#f1f5f9; border-bottom:1px solid #000;">
                        <th style="padding:6px; text-align:left;">KOMPONEN PENGHASILAN</th>
                        <th style="padding:6px; text-align:right;">JUMLAH (RP)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($earningsComponents as $ec): ?>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:6px;">
                                <?= e($ec['component_name']) ?>
                                <?php if ($ec['notes'] && $ec['notes'] !== $ec['component_name']): ?>
                                    <div style="font-size:10px; color:#64748b;"><?= e($ec['notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="padding:6px; text-align:right; font-weight:600;"><?= formatRupiah((float)$ec['amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="border-top:1px solid #000; font-weight:bold; background:#f8fafc;">
                        <td style="padding:8px 6px;">TOTAL PENDAPATAN (BRUTO)</td>
                        <td style="padding:8px 6px; text-align:right; color:#0284c7;"><?= formatRupiah((float)$activeSlip['total_earnings']) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Deductions Column -->
        <div style="flex:1;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#f1f5f9; border-bottom:1px solid #000;">
                        <th style="padding:6px; text-align:left;">KOMPONEN POTONGAN</th>
                        <th style="padding:6px; text-align:right;">JUMLAH (RP)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($deductionsComponents)): ?>
                        <tr>
                            <td colspan="2" style="padding:15px; text-align:center; color:#64748b;">Tidak ada potongan</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($deductionsComponents as $dc): ?>
                            <tr style="border-bottom:1px solid #eee;">
                                <td style="padding:6px;">
                                    <?= e($dc['component_name']) ?>
                                    <?php if ($dc['notes'] && $dc['notes'] !== $dc['component_name']): ?>
                                        <div style="font-size:10px; color:#64748b;"><?= e($dc['notes']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:6px; text-align:right; font-weight:600; color:#dc2626;"><?= formatRupiah((float)$dc['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="border-top:1px solid #000; font-weight:bold; background:#f8fafc;">
                        <td style="padding:8px 6px;">TOTAL POTONGAN</td>
                        <td style="padding:8px 6px; text-align:right; color:#dc2626;"><?= formatRupiah((float)$activeSlip['total_deductions']) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Take Home Pay Box -->
    <div style="border:2px solid #000; background:#f0fdf4; padding:15px 20px; display:flex; justify-content:space-between; align-items:center; margin-bottom:30px;">
        <div>
            <div style="font-size:13px; font-weight:bold; text-transform:uppercase;">GAJI BERSIH DITERIMA (TAKE HOME PAY):</div>
            <div style="font-size:11px; color:#475569; margin-top:2px;">Ditransfer langsung ke rekening terdaftar karyawan</div>
        </div>
        <div style="font-size:22px; font-weight:bold; color:#15803d;">
            <?= formatRupiah((float)$activeSlip['net_salary']) ?>
        </div>
    </div>

    <!-- Signatures -->
    <div style="display:flex; justify-content:space-between; text-align:center; font-size:12px; margin-top:40px;">
        <div style="width:35%;">
            <div>Bagian SDM &amp; Penggajian</div>
            <div style="height:60px;"></div>
            <div>( HR &amp; Payroll Manager )</div>
        </div>
        <div style="width:35%;">
            <div>Penerima Gaji (Karyawan)</div>
            <div style="height:60px;"></div>
            <div>( <?= e($activeSlip['employee_name']) ?> )</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Screen Display View -->
<div class="no-print">
    <div class="page-header">
        <div>
            <h1 class="page-title">Slip Gaji Pegawai (Payslips)</h1>
            <div class="page-subtitle">Siklus SDM &bull; Dokumen Rincian Gaji Resmi Karyawan, Komponen Tunjangan, Potongan BPJS &amp; Pajak</div>
        </div>
        <?php if ($activeSlip): ?>
            <div class="page-actions" style="display:flex; gap:10px;">
                <button onclick="window.print()" class="btn btn-primary">
                    🖨️ Cetak Slip Gaji Ini
                </button>
                <a href="index.php?page=hr_payroll/payslips" class="btn btn-secondary">
                    &larr; Tutup / Daftar Slip
                </a>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$canViewAll && !$userEmployeeId): ?>
        <div class="alert alert-warning">
            Akun Anda belum ditautkan ke data profil karyawan di sistem. Silakan hubungi tim HR untuk menautkan akun Anda.
        </div>
    <?php endif; ?>

    <!-- If Active Slip Selected: Display Preview Card -->
    <?php if ($activeSlip): ?>
        <div class="card mb-4" style="border: 2px solid #0284c7;">
            <div class="card-header" style="background:#f0f9ff; display:flex; justify-content:space-between; align-items:center;">
                <h2 class="card-title" style="color:#0369a1;">Pratinjau Slip Gaji: <?= e($activeSlip['slip_number']) ?></h2>
                <span class="badge badge-success">✓ Resmi Diterbitkan</span>
            </div>
            <div class="card-body">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:15px; margin-bottom:20px;">
                    <div>
                        <div class="text-muted" style="font-size:12px;">Karyawan</div>
                        <strong style="font-size:16px;"><?= e($activeSlip['employee_name']) ?></strong>
                        <div class="text-muted" style="font-size:12px;">NIK: <?= e($activeSlip['employee_code']) ?></div>
                    </div>
                    <div>
                        <div class="text-muted" style="font-size:12px;">Departemen &amp; Jabatan</div>
                        <div><?= e($activeSlip['department_name']) ?></div>
                        <small class="text-muted"><?= e($activeSlip['position_name']) ?></small>
                    </div>
                    <div>
                        <div class="text-muted" style="font-size:12px;">Periode Penggajian</div>
                        <strong><?= e($activeSlip['period_code']) ?></strong>
                        <div class="text-muted" style="font-size:12px;"><?= formatDate($activeSlip['start_date']) ?> s/d <?= formatDate($activeSlip['end_date']) ?></div>
                    </div>
                    <div>
                        <div class="text-muted" style="font-size:12px;">Rekening Payroll</div>
                        <div><?= e($activeSlip['bank_name'] ?: 'Bank Mandiri') ?></div>
                        <small class="text-muted"><?= e($activeSlip['bank_account'] ?: '-') ?></small>
                    </div>
                </div>

                <!-- Components Side-by-Side Grid -->
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:20px;">
                    <!-- Earnings -->
                    <div style="border:1px solid #e2e8f0; border-radius:6px; overflow:hidden;">
                        <div style="background:#f8fafc; padding:10px 15px; font-weight:bold; border-bottom:1px solid #e2e8f0; color:#0284c7;">
                            + Komponen Pendapatan (Earnings)
                        </div>
                        <table class="table" style="margin-bottom:0;">
                            <tbody>
                                <?php foreach ($earningsComponents as $ec): ?>
                                    <tr>
                                        <td>
                                            <?= e($ec['component_name']) ?>
                                            <?php if ($ec['notes'] && $ec['notes'] !== $ec['component_name']): ?>
                                                <small class="text-muted" style="display:block;"><?= e($ec['notes']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-right font-medium"><?= formatRupiah((float)$ec['amount']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:#f8fafc; font-weight:bold;">
                                    <td>Total Bruto:</td>
                                    <td class="text-right" style="color:#0284c7;"><?= formatRupiah((float)$activeSlip['total_earnings']) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Deductions -->
                    <div style="border:1px solid #e2e8f0; border-radius:6px; overflow:hidden;">
                        <div style="background:#f8fafc; padding:10px 15px; font-weight:bold; border-bottom:1px solid #e2e8f0; color:#dc2626;">
                            - Komponen Potongan (Deductions)
                        </div>
                        <table class="table" style="margin-bottom:0;">
                            <tbody>
                                <?php if (empty($deductionsComponents)): ?>
                                    <tr>
                                        <td colspan="2" class="text-center" style="padding:15px; color:#64748b;">Tidak ada potongan</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($deductionsComponents as $dc): ?>
                                        <tr>
                                            <td>
                                                <?= e($dc['component_name']) ?>
                                                <?php if ($dc['notes'] && $dc['notes'] !== $dc['component_name']): ?>
                                                    <small class="text-muted" style="display:block;"><?= e($dc['notes']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right font-medium" style="color:#dc2626;"><?= formatRupiah((float)$dc['amount']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:#f8fafc; font-weight:bold;">
                                    <td>Total Potongan:</td>
                                    <td class="text-right" style="color:#dc2626;"><?= formatRupiah((float)$activeSlip['total_deductions']) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Grand Total Net Salary -->
                <div style="margin-top:20px; background:#f0fdf4; border:1px solid #bbf7d0; padding:18px 25px; border-radius:8px; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div style="font-size:14px; font-weight:bold; color:#166534;">GAJI BERSIH DITERIMA (TAKE HOME PAY)</div>
                        <small style="color:#15803d;">Sudah dipotong BPJS TK 3%, BPJS Kesehatan 1%, PPh 21, dan ketidakhadiran</small>
                    </div>
                    <div style="font-size:24px; font-weight:bold; color:#15803d;">
                        <?= formatRupiah((float)$activeSlip['net_salary']) ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Payslips List Table -->
    <div class="card mb-4">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <h2 class="card-title">Daftar Dokumen Slip Gaji</h2>
            <?php if ($canViewAll): ?>
                <form action="index.php" method="GET" style="display:flex; gap:8px; align-items:center;">
                    <input type="hidden" name="page" value="hr_payroll/payslips">
                    <select name="period_id" onchange="this.form.submit()" style="font-size:12px; padding:4px 8px;">
                        <option value="">-- Semua Periode Penggajian --</option>
                        <?php foreach ($periods as $per): ?>
                            <option value="<?= $per['id'] ?>" <?= $selectedPeriodId === (int)$per['id'] ? 'selected' : '' ?>>
                                <?= e($per['period_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>No. Slip Gaji</th>
                            <th>Karyawan</th>
                            <th>Departemen / Jabatan</th>
                            <th>Periode Gaji</th>
                            <th class="text-right">Penghasilan Bruto</th>
                            <th class="text-right">Potongan</th>
                            <th class="text-right">Gaji Bersih (Net)</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payslips)): ?>
                            <tr>
                                <td colspan="8" class="text-center" style="padding:30px; color:#64748b;">
                                    Tidak ada dokumen slip gaji yang tersedia untuk Anda.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($payslips as $ps): ?>
                                <tr>
                                    <td><strong><?= e($ps['slip_number']) ?></strong></td>
                                    <td>
                                        <strong><?= e($ps['employee_name']) ?></strong>
                                        <div class="text-muted" style="font-size:11px;"><?= e($ps['employee_code']) ?></div>
                                    </td>
                                    <td>
                                        <div><?= e($ps['department_name']) ?></div>
                                        <small class="text-muted"><?= e($ps['position_name']) ?></small>
                                    </td>
                                    <td><?= e($ps['period_code']) ?></td>
                                    <td class="text-right font-medium"><?= formatRupiah((float)$ps['total_earnings']) ?></td>
                                    <td class="text-right font-medium" style="color:#dc2626;"><?= formatRupiah((float)$ps['total_deductions']) ?></td>
                                    <td class="text-right font-bold" style="color:#16a34a;"><?= formatRupiah((float)$ps['net_salary']) ?></td>
                                    <td class="text-center">
                                        <a href="index.php?page=hr_payroll/payslips&id=<?= $ps['id'] ?>" class="btn btn-sm btn-secondary">
                                            📄 Buka &amp; Cetak
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
