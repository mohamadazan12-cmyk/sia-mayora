<?php
/**
 * Modul SDM & Penggajian: Pemrosesan Gaji & Rekap Payroll (Payroll Processing)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Proses Penggajian (Payroll)';
$breadcrumbCategory = 'SDM & Penggajian';

$db = getDbConnection();
$user = currentUser();

$selectedPeriodId = (int)($_GET['period_id'] ?? 0);

// Handle Create New Payroll Period
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_period'])) {
    requireCsrf();

    if (!hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])) {
        setFlash('error', 'Akses ditolak.');
        header('Location: index.php?page=hr_payroll/payroll');
        exit;
    }

    $month = (int)($_POST['period_month'] ?? date('n'));
    $year = (int)($_POST['period_year'] ?? date('Y'));
    $startDate = trim($_POST['start_date'] ?? date('Y-m-01'));
    $endDate = trim($_POST['end_date'] ?? date('Y-m-t'));
    $notes = trim($_POST['notes'] ?? '');

    $periodCode = sprintf('PR-%04d%02d', $year, $month);

    try {
        // Check if period code already exists
        $stmtCheck = $db->prepare("SELECT id FROM payroll_periods WHERE period_code = :code");
        $stmtCheck->execute([':code' => $periodCode]);
        if ($stmtCheck->fetchColumn()) {
            throw new Exception("Periode penggajian {$periodCode} sudah pernah dibuat.");
        }

        $stmtIns = $db->prepare("
            INSERT INTO payroll_periods 
            (period_code, period_month, period_year, start_date, end_date, status, notes, created_by, created_at)
            VALUES 
            (:code, :month, :year, :s_date, :e_date, 'draft', :notes, :uid, NOW())
        ");
        $stmtIns->execute([
            ':code'   => $periodCode,
            ':month'  => $month,
            ':year'   => $year,
            ':s_date' => $startDate,
            ':e_date' => $endDate,
            ':notes'  => $notes,
            ':uid'    => $user['id'],
        ]);
        $newPeriodId = $db->lastInsertId();

        logAudit($user['id'], 'create_payroll_period', 'payroll_periods', $newPeriodId, null, [
            'period_code' => $periodCode,
        ]);

        setFlash('success', "Periode Payroll {$periodCode} berhasil dibuat. Silakan klik 'Hitung Gaji' untuk memulai kalkulasi.");
        header("Location: index.php?page=hr_payroll/payroll&period_id={$newPeriodId}");
        exit;
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        header('Location: index.php?page=hr_payroll/payroll');
        exit;
    }
}

// Handle Calculate / Recalculate Payroll for the Period
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_calculate_payroll'])) {
    requireCsrf();

    $periodId = (int)($_POST['period_id'] ?? 0);

    try {
        $db->beginTransaction();

        $stmtPeriod = $db->prepare("SELECT * FROM payroll_periods WHERE id = :id FOR UPDATE");
        $stmtPeriod->execute([':id' => $periodId]);
        $period = $stmtPeriod->fetch();

        if (!$period || in_array($period['status'], ['approved', 'transferred', 'closed'])) {
            throw new Exception('Periode penggajian tidak valid atau sudah disetujui / ditutup.');
        }

        // Delete existing draft records for this period to allow clean recalculation
        $db->prepare("DELETE FROM payroll_records WHERE payroll_period_id = :id")->execute([':id' => $periodId]);

        // Fetch all active employees
        $employees = $db->query("
            SELECT e.*, d.name AS department_name, p.name AS position_name 
            FROM employees e
            JOIN departments d ON e.department_id = d.id
            JOIN positions p ON e.position_id = p.id
            WHERE e.status = 'active'
        ")->fetchAll();

        // Prepared statements
        $stmtRec = $db->prepare("
            INSERT INTO payroll_records 
            (payroll_period_id, employee_id, base_salary, total_earnings, total_deductions, net_salary, status, created_at)
            VALUES 
            (:p_id, :e_id, :base, :earnings, :deductions, :net, 'calculated', NOW())
        ");

        $stmtComp = $db->prepare("
            INSERT INTO payroll_record_components (payroll_record_id, payroll_component_id, component_type, amount, notes)
            VALUES (:r_id, :c_id, :c_type, :amount, :notes)
        ");

        foreach ($employees as $emp) {
            $baseSalary = (float)$emp['base_salary'];

            // 1. Calculate Overtime Hours from attendance in period
            $stmtOt = $db->prepare("
                SELECT COALESCE(SUM(overtime_hours), 0) AS total_ot,
                       SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) AS alpha_days
                FROM attendance_records 
                WHERE employee_id = :eid AND attendance_date BETWEEN :s_date AND :e_date
            ");
            $stmtOt->execute([
                ':eid'    => $emp['id'],
                ':s_date' => $period['start_date'],
                ':e_date' => $period['end_date'],
            ]);
            $attRow = $stmtOt->fetch();
            $otHours = (float)($attRow['total_ot'] ?? 0);
            $alphaDays = (int)($attRow['alpha_days'] ?? 0);

            // Earnings components
            $tunjJabatan = (stripos($emp['position_name'], 'Manager') !== false || stripos($emp['position_name'], 'Kepala') !== false || stripos($emp['position_name'], 'Supervisor') !== false) ? 1500000.00 : 500000.00;
            $tunjTransport = 800000.00;
            $overtimePay = round(($baseSalary / 173) * 1.5 * $otHours, 2);
            $bonusProd = (stripos($emp['department_name'], 'Produksi') !== false || stripos($emp['department_name'], 'Pabrik') !== false) ? 500000.00 : 0.00;

            $totalEarnings = $baseSalary + $tunjJabatan + $tunjTransport + $overtimePay + $bonusProd;

            // Deductions components
            $bpjsTk = round($baseSalary * 0.03, 2); // 3%
            $bpjsKes = round($baseSalary * 0.01, 2); // 1%
            $potAlpha = round(($baseSalary / 22) * $alphaDays, 2);
            // PPh 21 estimated (if totalEarnings > 4500000)
            $taxableIncome = max(0, $totalEarnings - 4500000);
            $pph21 = round($taxableIncome * 0.05, 2);

            $totalDeductions = $bpjsTk + $bpjsKes + $potAlpha + $pph21;
            $netSalary = $totalEarnings - $totalDeductions;

            // Insert Record
            $stmtRec->execute([
                ':p_id'       => $periodId,
                ':e_id'       => $emp['id'],
                ':base'       => $baseSalary,
                ':earnings'   => $totalEarnings,
                ':deductions' => $totalDeductions,
                ':net'        => $netSalary,
            ]);
            $recordId = $db->lastInsertId();

            // Insert Components:
            // 1. GAPOK (ID 1)
            $stmtComp->execute([':r_id' => $recordId, ':c_id' => 1, ':c_type' => 'penghasilan', ':amount' => $baseSalary, ':notes' => 'Gaji Pokok']);
            // 2. TUNJ_JAB (ID 2)
            $stmtComp->execute([':r_id' => $recordId, ':c_id' => 2, ':c_type' => 'penghasilan', ':amount' => $tunjJabatan, ':notes' => 'Tunjangan Struktural / Jabatan']);
            // 3. TUNJ_TRANS (ID 3)
            $stmtComp->execute([':r_id' => $recordId, ':c_id' => 3, ':c_type' => 'penghasilan', ':amount' => $tunjTransport, ':notes' => 'Uang Makan & Transportasi']);
            // 4. LEMBUR (ID 4)
            if ($overtimePay > 0) {
                $stmtComp->execute([':r_id' => $recordId, ':c_id' => 4, ':c_type' => 'penghasilan', ':amount' => $overtimePay, ':notes' => "Upah Lembur ({$otHours} jam)"]);
            }
            // 5. BONUS_PROD (ID 5)
            if ($bonusProd > 0) {
                $stmtComp->execute([':r_id' => $recordId, ':c_id' => 5, ':c_type' => 'penghasilan', ':amount' => $bonusProd, ':notes' => 'Bonus Produktivitas Manufaktur']);
            }
            // 6. POT_BPJS_TK (ID 6)
            $stmtComp->execute([':r_id' => $recordId, ':c_id' => 6, ':c_type' => 'potongan', ':amount' => $bpjsTk, ':notes' => 'BPJS Ketenagakerjaan JHT (3%)']);
            // 7. POT_BPJS_KES (ID 7)
            $stmtComp->execute([':r_id' => $recordId, ':c_id' => 7, ':c_type' => 'potongan', ':amount' => $bpjsKes, ':notes' => 'BPJS Kesehatan (1%)']);
            // 8. POT_PPH21 (ID 8)
            if ($pph21 > 0) {
                $stmtComp->execute([':r_id' => $recordId, ':c_id' => 8, ':c_type' => 'potongan', ':amount' => $pph21, ':notes' => 'Estimasi PPh Pasal 21']);
            }
            // 9. POT_ALPHA (ID 10)
            if ($potAlpha > 0) {
                $stmtComp->execute([':r_id' => $recordId, ':c_id' => 10, ':c_type' => 'potongan', ':amount' => $potAlpha, ':notes' => "Potongan Ketidakhadiran ({$alphaDays} hari)"]);
            }
        }

        // Advance Period Status to 'review'
        $db->prepare("UPDATE payroll_periods SET status = 'review', updated_at = NOW(), updated_by = :uid WHERE id = :id")
           ->execute([':uid' => $user['id'], ':id' => $periodId]);

        logAudit($user['id'], 'calculate_payroll', 'payroll_periods', $periodId, null, [
            'employees_count' => count($employees),
            'status'          => 'review',
        ]);

        $db->commit();
        setFlash('success', "Kalkulasi payroll periode {$period['period_code']} berhasil diselesaikan untuk " . count($employees) . " karyawan.");
        header("Location: index.php?page=hr_payroll/payroll&period_id={$periodId}");
        exit;

    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Gagal memproses gaji: ' . $e->getMessage());
        header("Location: index.php?page=hr_payroll/payroll&period_id={$periodId}");
        exit;
    }
}

// Handle Submit to Finance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_submit_finance'])) {
    requireCsrf();
    $periodId = (int)($_POST['period_id'] ?? 0);

    $db->prepare("UPDATE payroll_periods SET status = 'pending_finance', updated_at = NOW(), updated_by = :uid WHERE id = :id")
       ->execute([':uid' => $user['id'], ':id' => $periodId]);

    // Insert approval record for HR review
    $db->prepare("
        INSERT INTO payroll_approvals (payroll_period_id, approval_type, status, decided_by, decided_at, notes, created_at)
        VALUES (:p_id, 'hr_review', 'approved', :uid, NOW(), 'Gaji telah direview HR dan diteruskan ke Finance/Direksi', NOW())
    ")->execute([':p_id' => $periodId, ':uid' => $user['id']]);

    setFlash('success', 'Rekap payroll telah diteruskan ke Finance Manager untuk persetujuan pencairan dana.');
    header("Location: index.php?page=hr_payroll/payroll&period_id={$periodId}");
    exit;
}

// Handle Reset Calculation for Period (Back to Draft)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_reset_calculation'])) {
    requireCsrf();

    if (!hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])) {
        setFlash('error', 'Akses ditolak: Hanya administrator atau HR yang berwenang mereset kalkulasi penggajian.');
        header('Location: index.php?page=hr_payroll/payroll');
        exit;
    }

    $periodId = (int)($_POST['period_id'] ?? 0);

    try {
        $db->beginTransaction();

        $stmtPeriod = $db->prepare("SELECT * FROM payroll_periods WHERE id = :id FOR UPDATE");
        $stmtPeriod->execute([':id' => $periodId]);
        $period = $stmtPeriod->fetch();

        if (!$period) {
            throw new Exception('Periode penggajian tidak ditemukan.');
        }

        if (in_array($period['status'], ['approved', 'transferred', 'closed'])) {
            throw new Exception('Periode penggajian sudah disetujui / ditransfer dan tidak dapat direset langsung.');
        }

        // Delete existing payroll records (cascades to payroll_record_components)
        $db->prepare("DELETE FROM payroll_records WHERE payroll_period_id = :id")->execute([':id' => $periodId]);

        // Remove pending approvals if any
        $db->prepare("DELETE FROM payroll_approvals WHERE payroll_period_id = :id AND status = 'pending'")->execute([':id' => $periodId]);

        // Revert status to draft
        $db->prepare("UPDATE payroll_periods SET status = 'draft', updated_at = NOW(), updated_by = :uid WHERE id = :id")
           ->execute([':uid' => $user['id'], ':id' => $periodId]);

        logAudit($user['id'], 'reset_payroll_calculation', 'payroll_periods', $periodId, ['old_status' => $period['status']], ['new_status' => 'draft']);

        $db->commit();
        setFlash('success', "Kalkulasi payroll periode {$period['period_code']} berhasil direset. Status periode kembali ke Draft.");
        header("Location: index.php?page=hr_payroll/payroll&period_id={$periodId}");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', $e->getMessage());
        header("Location: index.php?page=hr_payroll/payroll&period_id={$periodId}");
        exit;
    }
}

// Handle Delete Payroll Period (Draft / Review only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete_period'])) {
    requireCsrf();

    if (!hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])) {
        setFlash('error', 'Akses ditolak: Hanya administrator atau HR yang berwenang menghapus periode penggajian.');
        header('Location: index.php?page=hr_payroll/payroll');
        exit;
    }

    $periodId = (int)($_POST['period_id'] ?? 0);

    try {
        $db->beginTransaction();

        $stmtPeriod = $db->prepare("SELECT * FROM payroll_periods WHERE id = :id FOR UPDATE");
        $stmtPeriod->execute([':id' => $periodId]);
        $period = $stmtPeriod->fetch();

        if (!$period) {
            throw new Exception('Periode penggajian tidak ditemukan.');
        }

        if (!in_array($period['status'], ['draft', 'review'])) {
            throw new Exception('Hanya periode berstatus Draft atau Review yang dapat dihapus.');
        }

        // Check if payslips exist
        $stmtSlips = $db->prepare("SELECT COUNT(*) FROM payslips WHERE payroll_period_id = :id");
        $stmtSlips->execute([':id' => $periodId]);
        if ((int)$stmtSlips->fetchColumn() > 0) {
            throw new Exception('Periode penggajian tidak dapat dihapus karena sudah ada slip gaji yang diterbitkan.');
        }

        // Check if journal entries exist
        $stmtJe = $db->prepare("SELECT COUNT(*) FROM journal_entries WHERE ref_type IN ('payroll_accrual', 'payroll_payment') AND ref_id = :id AND status = 'posted'");
        $stmtJe->execute([':id' => $periodId]);
        if ((int)$stmtJe->fetchColumn() > 0) {
            throw new Exception('Periode penggajian tidak dapat dihapus karena telah memiliki jurnal transaksi di Buku Besar.');
        }

        // Delete records and period
        $db->prepare("DELETE FROM payroll_records WHERE payroll_period_id = :id")->execute([':id' => $periodId]);
        $db->prepare("DELETE FROM payroll_approvals WHERE payroll_period_id = :id")->execute([':id' => $periodId]);
        $db->prepare("DELETE FROM payroll_periods WHERE id = :id")->execute([':id' => $periodId]);

        logAudit($user['id'], 'delete_payroll_period', 'payroll_periods', $periodId, $period, null);

        $db->commit();
        setFlash('success', "Periode penggajian {$period['period_code']} berhasil dihapus permanen.");
        header('Location: index.php?page=hr_payroll/payroll');
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', $e->getMessage());
        header("Location: index.php?page=hr_payroll/payroll&period_id={$periodId}");
        exit;
    }
}

// Handle Reverse Finance Approval (Rollback Journal Accrual)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_reverse_approval'])) {
    requireCsrf();

    if (!hasRole(['Administrator', 'Finance/Treasury', 'Supervisor / Manajer', 'admin', 'finance', 'manager'])) {
        setFlash('error', 'Akses ditolak: Hanya bagian Finance atau Administrator yang berwenang membatalkan persetujuan payroll.');
        header('Location: index.php?page=hr_payroll/payroll');
        exit;
    }

    $periodId = (int)($_POST['period_id'] ?? 0);
    $reversalReason = trim($_POST['reversal_reason'] ?? '');

    if (empty($reversalReason)) {
        setFlash('error', 'Alasan pembatalan persetujuan payroll wajib diisi.');
        header("Location: index.php?page=hr_payroll/payroll&period_id={$periodId}");
        exit;
    }

    try {
        $db->beginTransaction();

        $stmtPeriod = $db->prepare("SELECT * FROM payroll_periods WHERE id = :id FOR UPDATE");
        $stmtPeriod->execute([':id' => $periodId]);
        $period = $stmtPeriod->fetch();

        if (!$period) {
            throw new Exception('Periode penggajian tidak ditemukan.');
        }

        if ($period['status'] !== 'approved') {
            throw new Exception('Hanya periode dengan status Disetujui (Approved) yang dapat dibatalkan melalui fitur ini.');
        }

        // Check if disbursement already occurred
        $stmtPay = $db->prepare("SELECT COUNT(*) FROM journal_entries WHERE ref_type = 'payroll_payment' AND ref_id = :id AND status = 'posted'");
        $stmtPay->execute([':id' => $periodId]);
        if ((int)$stmtPay->fetchColumn() > 0) {
            throw new Exception('Periode ini sudah diproses transfer bank/pembayaran gaji. Reversal persetujuan tidak dapat dilakukan.');
        }

        // Check if payslips already generated
        $stmtSlips = $db->prepare("SELECT COUNT(*) FROM payslips WHERE payroll_period_id = :id");
        $stmtSlips->execute([':id' => $periodId]);
        if ((int)$stmtSlips->fetchColumn() > 0) {
            throw new Exception('Slip gaji untuk periode ini telah diterbitkan. Reversal persetujuan tidak dapat dilakukan.');
        }

        // Reverse the payroll_accrual journal entry
        $reverseNote = "Pembatalan persetujuan akrual payroll {$period['period_code']}: {$reversalReason}";
        $revEntry = JournalService::reverseEntryByRef('payroll_accrual', $periodId, $reverseNote, $user['id'], $db);

        // Revert period status to review
        $db->prepare("UPDATE payroll_periods SET status = 'review', updated_at = NOW(), updated_by = :uid WHERE id = :id")
           ->execute([':uid' => $user['id'], ':id' => $periodId]);

        // Insert approval audit trail
        $db->prepare("
            INSERT INTO payroll_approvals (payroll_period_id, approval_type, status, decided_by, decided_at, notes, created_at)
            VALUES (:p_id, 'finance_approval', 'revision', :uid, NOW(), :notes, NOW())
        ")->execute([
            ':p_id'  => $periodId,
            ':uid'   => $user['id'],
            ':notes' => "Reversal Persetujuan Finance: {$reversalReason}",
        ]);

        logAudit($user['id'], 'reverse_payroll_approval', 'payroll_periods', $periodId, $period, [
            'status'              => 'review',
            'reversal_reason'     => $reversalReason,
            'journal_reversal_id' => $revEntry,
        ]);

        $db->commit();
        setFlash('success', "Persetujuan Finance periode {$period['period_code']} berhasil dibatalkan dan jurnal akrual telah dibalik (reversed). Status periode kembali ke Review.");
        header("Location: index.php?page=hr_payroll/payroll&period_id={$periodId}");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', $e->getMessage());
        header("Location: index.php?page=hr_payroll/payroll&period_id={$periodId}");
        exit;
    }
}

// Fetch all Periods
$periods = $db->query("
    SELECT pp.*, u.full_name AS creator_name,
           (SELECT COUNT(*) FROM payroll_records pr WHERE pr.payroll_period_id = pp.id) AS emp_count,
           (SELECT SUM(pr.net_salary) FROM payroll_records pr WHERE pr.payroll_period_id = pp.id) AS total_net_payroll
    FROM payroll_periods pp
    LEFT JOIN users u ON pp.created_by = u.id
    ORDER BY pp.period_year DESC, pp.period_month DESC
")->fetchAll();

if ($selectedPeriodId <= 0 && !empty($periods)) {
    $selectedPeriodId = (int)$periods[0]['id'];
}

// Fetch selected period details & records
$selectedPeriod = null;
$records = [];
$periodTotals = ['gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0];

if ($selectedPeriodId > 0) {
    $stmtP = $db->prepare("SELECT * FROM payroll_periods WHERE id = :id");
    $stmtP->execute([':id' => $selectedPeriodId]);
    $selectedPeriod = $stmtP->fetch();

    if ($selectedPeriod) {
        $stmtRecs = $db->prepare("
            SELECT pr.*, e.code AS employee_code, e.full_name, e.bank_name, e.bank_account,
                   d.name AS department_name, p.name AS position_name
            FROM payroll_records pr
            JOIN employees e ON pr.employee_id = e.id
            JOIN departments d ON e.department_id = d.id
            JOIN positions p ON e.position_id = p.id
            WHERE pr.payroll_period_id = :p_id
            ORDER BY d.name ASC, e.full_name ASC
        ");
        $stmtRecs->execute([':p_id' => $selectedPeriodId]);
        $records = $stmtRecs->fetchAll();

        foreach ($records as $r) {
            $periodTotals['gross'] += (float)$r['total_earnings'];
            $periodTotals['deductions'] += (float)$r['total_deductions'];
            $periodTotals['net'] += (float)$r['net_salary'];
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Proses Penggajian (Payroll Engine)</h1>
        <div class="page-subtitle">Siklus SDM &bull; Perhitungan Otomatis Komponen Gaji, BPJS Ketenagakerjaan, BPJS Kesehatan, dan Lembur</div>
    </div>
    <div class="page-actions" style="display:flex; gap:10px;">
        <a href="index.php?page=hr_payroll/approvals" class="btn btn-secondary">
            Persetujuan &amp; Otorisasi Gaji &rarr;
        </a>
    </div>
</div>

<!-- Period Selection and Creator Accordion -->
<div class="card mb-4">
    <div class="card-body" style="display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:15px;">
        <!-- Selector -->
        <form action="index.php" method="GET" style="display:flex; gap:10px; align-items:center; flex:1; min-width:280px;">
            <input type="hidden" name="page" value="hr_payroll/payroll">
            <label style="font-weight:600; white-space:nowrap;">Pilih Periode:</label>
            <select name="period_id" onchange="this.form.submit()" style="flex:1;">
                <?php foreach ($periods as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $selectedPeriodId === (int)$p['id'] ? 'selected' : '' ?>>
                        <?= e($p['period_code']) ?> &bull; <?= e(formatDate($p['start_date'])) ?> s/d <?= e(formatDate($p['end_date'])) ?> (<?= $p['status'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary btn-sm">Buka</button>
        </form>

        <!-- Create New Period Collapsible -->
        <?php if (hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])): ?>
            <details style="position:relative;">
                <summary class="btn btn-primary" style="cursor:pointer; list-style:none;">
                    + Buat Periode Gaji Baru
                </summary>
                <div style="position:absolute; right:0; margin-top:8px; background:#fff; border:1px solid #cbd5e1; border-radius:8px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1); padding:20px; width:340px; z-index:50;">
                    <h4 style="margin:0 0 12px 0;">Buat Periode Payroll Baru</h4>
                    <form action="index.php?page=hr_payroll/payroll" method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action_create_period" value="1">
                        <div class="form-group" style="margin-bottom:10px;">
                            <label style="font-size:12px;">Bulan</label>
                            <select name="period_month" style="font-size:12px;">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= $m ?>" <?= date('n') == $m ? 'selected' : '' ?>>Bulan <?= $m ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label style="font-size:12px;">Tahun</label>
                            <input type="number" name="period_year" value="<?= date('Y') ?>" style="font-size:12px;" required>
                        </div>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label style="font-size:12px;">Tanggal Mulai</label>
                            <input type="date" name="start_date" value="<?= date('Y-m-01') ?>" style="font-size:12px;" required>
                        </div>
                        <div class="form-group" style="margin-bottom:10px;">
                            <label style="font-size:12px;">Tanggal Selesai</label>
                            <input type="date" name="end_date" value="<?= date('Y-m-t') ?>" style="font-size:12px;" required>
                        </div>
                        <div class="form-group" style="margin-bottom:15px;">
                            <label style="font-size:12px;">Catatan Periode</label>
                            <input type="text" name="notes" placeholder="Payroll rutin bulanan" style="font-size:12px;">
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary" style="width:100%;">Buat Periode Baru</button>
                    </form>
                </div>
            </details>
        <?php endif; ?>
    </div>
</div>

<?php if ($selectedPeriod): ?>
    <!-- Active Period Summary Metrics -->
    <div class="metrics-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
        <div class="metric-card">
            <div class="metric-value"><?= count($records) ?></div>
            <div class="metric-label">Jumlah Karyawan Terkalkulasi</div>
        </div>
        <div class="metric-card">
            <div class="metric-value" style="color:#0284c7;"><?= formatRupiah($periodTotals['gross']) ?></div>
            <div class="metric-label">Total Penghasilan Bruto</div>
        </div>
        <div class="metric-card">
            <div class="metric-value" style="color:#dc2626;"><?= formatRupiah($periodTotals['deductions']) ?></div>
            <div class="metric-label">Total Potongan (BPJS / PPh / Absen)</div>
        </div>
        <div class="metric-card" style="background:#f0fdf4; border:1px solid #bbf7d0;">
            <div class="metric-value" style="color:#16a34a;"><?= formatRupiah($periodTotals['net']) ?></div>
            <div class="metric-label font-bold" style="color:#166534;">Total Gaji Bersih (Take Home Pay)</div>
        </div>
    </div>

    <!-- Actions & Process Bar -->
    <div class="card mb-4" style="background:#f8fafc;">
        <div class="card-body" style="display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:15px;">
            <div>
                <strong>Status Periode <?= e($selectedPeriod['period_code']) ?>:</strong>
                <span style="margin-left:5px;"><?= statusBadge($selectedPeriod['status']) ?></span>
                <div class="text-muted" style="font-size:12px; margin-top:3px;">
                    Rentang: <?= formatDate($selectedPeriod['start_date']) ?> s/d <?= formatDate($selectedPeriod['end_date']) ?>
                </div>
            </div>

            <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                <?php if (in_array($selectedPeriod['status'], ['draft', 'calculating', 'review'])): ?>
                    <form action="index.php?page=hr_payroll/payroll" method="POST" onsubmit="return confirm('Jalankan kalkulasi ulang komponen gaji untuk seluruh karyawan aktif?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action_calculate_payroll" value="1">
                        <input type="hidden" name="period_id" value="<?= $selectedPeriod['id'] ?>">
                        <button type="submit" class="btn btn-primary">
                            ⚙️ <?= empty($records) ? 'Hitung Gaji Karyawan' : 'Hitung Ulang (Recalculate)' ?>
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($selectedPeriod['status'] === 'review' && !empty($records)): ?>
                    <form action="index.php?page=hr_payroll/payroll" method="POST" onsubmit="return confirm('Kirim rekap gaji ini ke Finance Manager untuk persetujuan pencairan?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action_submit_finance" value="1">
                        <input type="hidden" name="period_id" value="<?= $selectedPeriod['id'] ?>">
                        <button type="submit" class="btn btn-success">
                            ✓ Ajukan ke Finance &amp; Otorisasi &rarr;
                        </button>
                    </form>
                <?php endif; ?>

                <?php if (in_array($selectedPeriod['status'], ['review', 'pending_finance']) && hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])): ?>
                    <form action="index.php?page=hr_payroll/payroll" method="POST" onsubmit="return confirm('Reset hasil kalkulasi gaji periode ini? Seluruh rincian gaji karyawan akan dihapus dan status kembali ke Draft.');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action_reset_calculation" value="1">
                        <input type="hidden" name="period_id" value="<?= $selectedPeriod['id'] ?>">
                        <button type="submit" class="btn btn-warning">
                            🔄 Reset Kalkulasi
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($selectedPeriod['status'] === 'approved' && hasRole(['Administrator', 'Finance/Treasury', 'Supervisor / Manajer', 'admin', 'finance', 'manager'])): ?>
                    <button type="button" class="btn btn-danger" onclick="openReverseApprovalModal(<?= $selectedPeriod['id'] ?>, '<?= htmlspecialchars(addslashes($selectedPeriod['period_code'])) ?>')">
                        ↩️ Batalkan Persetujuan (Reversal)
                    </button>
                <?php endif; ?>

                <?php if (in_array($selectedPeriod['status'], ['draft', 'review']) && hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])): ?>
                    <button type="button" class="btn btn-outline-danger" onclick="openDeletePeriodModal(<?= $selectedPeriod['id'] ?>, '<?= htmlspecialchars(addslashes($selectedPeriod['period_code'])) ?>')">
                        🗑️ Hapus Periode
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Employee Breakdown Table -->
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">Rincian Komponen Gaji per Karyawan</h2>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>NIK</th>
                            <th>Nama Karyawan</th>
                            <th>Departemen</th>
                            <th>Jabatan</th>
                            <th class="text-right">Gaji Pokok</th>
                            <th class="text-right">Total Pendapatan (Bruto)</th>
                            <th class="text-right">Total Potongan</th>
                            <th class="text-right">Gaji Bersih (Net)</th>
                            <th>Rekening Bank</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="9" class="text-center" style="padding:30px; color:#64748b;">
                                    Belum ada data gaji yang dihitung untuk periode ini. Silakan klik tombol <strong>"Hitung Gaji Karyawan"</strong> di atas.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($records as $rec): ?>
                                <tr>
                                    <td><strong><?= e($rec['employee_code']) ?></strong></td>
                                    <td>
                                        <strong><a href="index.php?page=hr_payroll/employee_detail&id=<?= $rec['employee_id'] ?>"><?= e($rec['full_name']) ?></a></strong>
                                    </td>
                                    <td><?= e($rec['department_name']) ?></td>
                                    <td><?= e($rec['position_name']) ?></td>
                                    <td class="text-right font-medium"><?= formatRupiah((float)$rec['base_salary']) ?></td>
                                    <td class="text-right font-medium" style="color:#0284c7;"><?= formatRupiah((float)$rec['total_earnings']) ?></td>
                                    <td class="text-right font-medium" style="color:#dc2626;"><?= formatRupiah((float)$rec['total_deductions']) ?></td>
                                    <td class="text-right font-bold" style="color:#16a34a;"><?= formatRupiah((float)$rec['net_salary']) ?></td>
                                    <td>
                                        <div><?= e($rec['bank_name'] ?: '-') ?></div>
                                        <small class="text-muted"><?= e($rec['bank_account'] ?: '') ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($records)): ?>
                        <tfoot>
                            <tr style="background:#f8fafc; font-weight:bold; font-size:14px;">
                                <td colspan="5" class="text-right">Total Keseluruhan Payroll:</td>
                                <td class="text-right" style="color:#0284c7;"><?= formatRupiah($periodTotals['gross']) ?></td>
                                <td class="text-right" style="color:#dc2626;"><?= formatRupiah($periodTotals['deductions']) ?></td>
                                <td class="text-right" style="color:#16a34a;"><?= formatRupiah($periodTotals['net']) ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Modal Reversal Persetujuan Finance -->
<div id="modal-reverse-approval" class="modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:8px; width:90%; max-width:520px; padding:25px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:#dc2626;">↩️ Batalkan Persetujuan Finance (Reversal Jurnal)</h3>
        <p style="font-size:14px; color:#475569; line-height:1.6;">
            Batalkan persetujuan anggaran payroll untuk periode <strong id="rev-period-code"></strong>?
        </p>
        <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:6px; padding:12px; margin-bottom:15px; font-size:13px; color:#991b1b;">
            ⚠️ <strong>Reversal Otomatis Jurnal Akuntansi:</strong><br>
            Sistem akan secara otomatis membalikkan (reversal entry) jurnal akrual beban gaji (<em>payroll_accrual</em>) di Buku Besar dan mengembalikan status periode payroll ke <strong>Review</strong> untuk penyesuaian ulang.
        </div>
        <form action="index.php?page=hr_payroll/payroll" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action_reverse_approval" value="1">
            <input type="hidden" name="period_id" id="rev-period-id" value="">
            
            <div class="form-group" style="margin-bottom:15px;">
                <label style="font-size:12px; font-weight:600;">Alasan Pembatalan / Reversal *</label>
                <input type="text" name="reversal_reason" placeholder="Misal: Kesalahan kalkulasi lembur divisi produksi" required>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="closeReverseApprovalModal()">Batal</button>
                <button type="submit" class="btn btn-danger">Ya, Batalkan &amp; Reversal Jurnal</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Hapus Periode Payroll -->
<div id="modal-delete-period" class="modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:8px; width:90%; max-width:480px; padding:25px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:#dc2626;">🗑️ Hapus Periode Penggajian</h3>
        <p style="font-size:14px; color:#475569; line-height:1.6;">
            Hapus periode penggajian <strong id="del-period-code"></strong> secara permanen beserta seluruh rincian kalkulasi di dalamnya?
        </p>
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px; margin-bottom:15px; font-size:12px; color:#64748b;">
            ℹ️ Tindakan ini hanya diizinkan untuk periode yang berstatus <strong>Draft</strong> atau <strong>Review</strong> dan belum diposting ke jurnal akuntansi atau diterbitkan slip gaji.
        </div>
        <form action="index.php?page=hr_payroll/payroll" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action_delete_period" value="1">
            <input type="hidden" name="period_id" id="del-period-id" value="">
            
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="closeDeletePeriodModal()">Batal</button>
                <button type="submit" class="btn btn-danger">Ya, Hapus Periode</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReverseApprovalModal(id, code) {
    document.getElementById('rev-period-id').value = id;
    document.getElementById('rev-period-code').textContent = code;
    const modal = document.getElementById('modal-reverse-approval');
    if (modal) modal.style.display = 'flex';
}
function closeReverseApprovalModal() {
    const modal = document.getElementById('modal-reverse-approval');
    if (modal) modal.style.display = 'none';
}

function openDeletePeriodModal(id, code) {
    document.getElementById('del-period-id').value = id;
    document.getElementById('del-period-code').textContent = code;
    const modal = document.getElementById('modal-delete-period');
    if (modal) modal.style.display = 'flex';
}
function closeDeletePeriodModal() {
    const modal = document.getElementById('modal-delete-period');
    if (modal) modal.style.display = 'none';
}

window.addEventListener('click', function(e) {
    const revM = document.getElementById('modal-reverse-approval');
    if (e.target === revM) closeReverseApprovalModal();
    const delM = document.getElementById('modal-delete-period');
    if (e.target === delM) closeDeletePeriodModal();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
