<?php
/**
 * Modul SDM & Penggajian: Persetujuan & Otorisasi Payroll (Payroll Approvals)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Persetujuan Gaji (Payroll Approvals)';
$breadcrumbCategory = 'SDM & Penggajian';

$db = getDbConnection();
$user = currentUser();

// Handle Approvals
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_payroll_approval'])) {
    requireCsrf();

    $periodId = (int)($_POST['period_id'] ?? 0);
    $decision = $_POST['decision'] ?? 'rejected';
    $step = $_POST['step'] ?? 'finance';
    $notes = trim($_POST['notes'] ?? '');

    try {
        $db->beginTransaction();

        $stmtP = $db->prepare("SELECT * FROM payroll_periods WHERE id = :id FOR UPDATE");
        $stmtP->execute([':id' => $periodId]);
        $period = $stmtP->fetch();

        if (!$period) {
            throw new Exception('Periode penggajian tidak ditemukan.');
        }

        // Fetch payroll totals
        $stmtSum = $db->prepare("
            SELECT 
                COALESCE(SUM(total_earnings), 0) AS total_gross,
                COALESCE(SUM(total_deductions), 0) AS total_deductions,
                COALESCE(SUM(net_salary), 0) AS total_net
            FROM payroll_records
            WHERE payroll_period_id = :p_id
        ");
        $stmtSum->execute([':p_id' => $periodId]);
        $totals = $stmtSum->fetch();

        $totalGross = (float)$totals['total_gross'];
        $totalDeductions = (float)$totals['total_deductions'];
        $totalNet = (float)$totals['total_net'];

        if ($decision === 'approved') {
            if ($step === 'finance') {
                if (!hasRole(['Administrator', 'Finance/Treasury', 'Supervisor / Manajer', 'admin', 'finance', 'manager'])) {
                    throw new Exception('Hanya Finance Manager atau Direksi yang berwenang menyetujui anggaran gaji.');
                }

                // 1. Update period status to approved
                $db->prepare("UPDATE payroll_periods SET status = 'approved', updated_at = NOW(), updated_by = :uid WHERE id = :id")
                   ->execute([':uid' => $user['id'], ':id' => $periodId]);
                $db->prepare("UPDATE payroll_records SET status = 'approved', updated_at = NOW() WHERE payroll_period_id = :id")
                   ->execute([':id' => $periodId]);

                // 2. Insert approval log
                $db->prepare("
                    INSERT INTO payroll_approvals (payroll_period_id, approval_type, status, decided_by, decided_at, notes, created_at)
                    VALUES (:p_id, 'finance_approval', 'approved', :uid, NOW(), :notes, NOW())
                ")->execute([
                    ':p_id'  => $periodId,
                    ':uid'   => $user['id'],
                    ':notes' => $notes ?: 'Persetujuan anggaran gaji bulanan oleh Finance Manager',
                ]);

                // 3. Post Accrual Payroll Journal:
                // Dr: Beban Gaji & Tunjangan Pegawai Kantor (51) = totalGross
                // Cr: Beban Akrual Utang Gaji & Upah Karyawan (28) = totalNet
                // Cr: Utang Perpajakan PPN Keluaran & PPh (29) = totalDeductions
                $journalLines = [
                    [
                        'account_id'  => 51, // Beban Gaji
                        'description' => "Pengakuan Beban Gaji Pegawai {$period['period_code']}",
                        'debit'       => $totalGross,
                        'credit'      => 0,
                    ],
                    [
                        'account_id'  => 28, // Utang Gaji
                        'description' => "Utang Gaji Bersih Karyawan {$period['period_code']}",
                        'debit'       => 0,
                        'credit'      => $totalNet,
                    ],
                ];

                if ($totalDeductions > 0) {
                    $journalLines[] = [
                        'account_id'  => 29, // Utang Pajak & Potongan
                        'description' => "Potongan BPJS & Pajak PPh 21 {$period['period_code']}",
                        'debit'       => 0,
                        'credit'      => $totalDeductions,
                    ];
                }

                JournalService::createEntry(
                    date('Y-m-d'),
                    'payroll_accrual',
                    $periodId,
                    $period['period_code'],
                    "Akrual Beban Gaji & Upah Periode {$period['period_code']}",
                    $journalLines,
                    $user['id'],
                    true,
                    $db
                );

                setFlash('success', "Payroll {$period['period_code']} berhasil disetujui Finance Manager dan jurnal akrual gaji telah diposting.");

            } elseif ($step === 'disburse') {
                if (!hasRole(['Administrator', 'Finance/Treasury', 'admin', 'finance'])) {
                    throw new Exception('Hanya bagian Keuangan/Kasir yang berwenang mengonfirmasi transfer bank penggajian.');
                }

                // 1. Update period status to transferred
                $db->prepare("UPDATE payroll_periods SET status = 'transferred', updated_at = NOW(), updated_by = :uid WHERE id = :id")
                   ->execute([':uid' => $user['id'], ':id' => $periodId]);
                $db->prepare("UPDATE payroll_records SET status = 'transferred', updated_at = NOW() WHERE payroll_period_id = :id")
                   ->execute([':id' => $periodId]);

                // 2. Insert approval log
                $db->prepare("
                    INSERT INTO payroll_approvals (payroll_period_id, approval_type, status, decided_by, decided_at, notes, created_at)
                    VALUES (:p_id, 'transfer_confirmation', 'approved', :uid, NOW(), :notes, NOW())
                ")->execute([
                    ':p_id'  => $periodId,
                    ':uid'   => $user['id'],
                    ':notes' => $notes ?: 'Konfirmasi transfer massal payroll via Bank Mandiri Payroll',
                ]);

                // 3. Post Bank Disbursement Journal:
                // Dr: Beban Akrual Utang Gaji & Upah Karyawan (28) = totalNet
                // Cr: Bank Mandiri Giro Payroll (6) = totalNet
                $journalLines = [
                    [
                        'account_id'  => 28, // Utang Gaji
                        'description' => "Pelunasan Utang Gaji {$period['period_code']}",
                        'debit'       => $totalNet,
                        'credit'      => 0,
                    ],
                    [
                        'account_id'  => 6, // Bank Mandiri Payroll
                        'description' => "Transfer Payroll Bank Mandiri {$period['period_code']}",
                        'debit'       => 0,
                        'credit'      => $totalNet,
                    ],
                ];

                JournalService::createEntry(
                    date('Y-m-d'),
                    'payroll_payment',
                    $periodId,
                    $period['period_code'],
                    "Pencairan / Transfer Payroll Periode {$period['period_code']}",
                    $journalLines,
                    $user['id'],
                    true,
                    $db
                );

                // 4. Automatically generate individual payslips in payslips table
                $recs = $db->prepare("SELECT id, employee_id FROM payroll_records WHERE payroll_period_id = :p_id");
                $recs->execute([':p_id' => $periodId]);
                $empRecords = $recs->fetchAll();

                $stmtSlip = $db->prepare("
                    INSERT INTO payslips (payroll_record_id, employee_id, payroll_period_id, slip_number, generated_at, created_by)
                    VALUES (:rec_id, :emp_id, :p_id, :slip_num, NOW(), :uid)
                    ON DUPLICATE KEY UPDATE generated_at = NOW()
                ");

                $seqSlip = 1;
                foreach ($empRecords as $er) {
                    $slipNumber = sprintf('SLIP-%04d%02d-%04d', $period['period_year'], $period['period_month'], $seqSlip++);
                    $stmtSlip->execute([
                        ':rec_id'   => $er['id'],
                        ':emp_id'   => $er['employee_id'],
                        ':p_id'     => $periodId,
                        ':slip_num' => $slipNumber,
                        ':uid'      => $user['id'],
                    ]);
                }

                setFlash('success', "Transfer payroll {$period['period_code']} sebesar " . formatRupiah($totalNet) . " berhasil dikonfirmasi! Slip gaji untuk " . count($empRecords) . " karyawan telah diterbitkan.");
            } elseif ($step === 'reverse') {
                if (!hasRole(['Administrator', 'Finance/Treasury', 'Supervisor / Manajer', 'admin', 'finance', 'manager'])) {
                    throw new Exception('Hanya bagian Keuangan / Finance Manager yang berwenang membatalkan persetujuan payroll.');
                }

                if ($period['status'] !== 'approved') {
                    throw new Exception('Hanya payroll berstatus Disetujui (Approved) yang dapat dibatalkan.');
                }

                if (empty($notes)) {
                    throw new Exception('Alasan pembatalan persetujuan payroll wajib diisi.');
                }

                // Check if disbursement already occurred
                $stmtPay = $db->prepare("SELECT COUNT(*) FROM journal_entries WHERE ref_type = 'payroll_payment' AND ref_id = :id AND status = 'posted'");
                $stmtPay->execute([':id' => $periodId]);
                if ((int)$stmtPay->fetchColumn() > 0) {
                    throw new Exception('Periode ini sudah diproses transfer bank. Reversal tidak dapat dilakukan.');
                }

                // Reverse the payroll_accrual journal entry
                $reverseNote = "Pembatalan persetujuan akrual payroll {$period['period_code']}: {$notes}";
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
                    ':notes' => "Reversal Persetujuan Finance: {$notes}",
                ]);

                logAudit($user['id'], 'reverse_payroll_approval', 'payroll_periods', $periodId, $period, [
                    'status'              => 'review',
                    'reversal_reason'     => $notes,
                    'journal_reversal_id' => $revEntry,
                ]);

                setFlash('success', "Persetujuan payroll {$period['period_code']} berhasil dibatalkan dan jurnal akrual telah dibalik (reversed). Status periode kembali ke Review.");
            }
        } else {
            // Reject / Revision
            if (empty($notes)) {
                throw new Exception('Alasan penolakan / revisi payroll wajib diisi.');
            }

            $db->prepare("UPDATE payroll_periods SET status = 'draft', updated_at = NOW(), updated_by = :uid WHERE id = :id")
               ->execute([':uid' => $user['id'], ':id' => $periodId]);

            $db->prepare("
                INSERT INTO payroll_approvals (payroll_period_id, approval_type, status, decided_by, decided_at, notes, created_at)
                VALUES (:p_id, 'finance_approval', 'revision', :uid, NOW(), :notes, NOW())
            ")->execute([
                ':p_id'  => $periodId,
                ':uid'   => $user['id'],
                ':notes' => $notes,
            ]);

            setFlash('warning', "Payroll {$period['period_code']} dikembalikan ke HR untuk revisi. Catatan: {$notes}");
        }

        $db->commit();
        header('Location: index.php?page=hr_payroll/approvals');
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', $e->getMessage());
        header('Location: index.php?page=hr_payroll/approvals');
        exit;
    }
}

// Fetch periods needing approval or recently processed
$periods = $db->query("
    SELECT pp.*, 
           (SELECT COUNT(*) FROM payroll_records pr WHERE pr.payroll_period_id = pp.id) AS total_employees,
           (SELECT COALESCE(SUM(pr.total_earnings), 0) FROM payroll_records pr WHERE pr.payroll_period_id = pp.id) AS gross_total,
           (SELECT COALESCE(SUM(pr.total_deductions), 0) FROM payroll_records pr WHERE pr.payroll_period_id = pp.id) AS deductions_total,
           (SELECT COALESCE(SUM(pr.net_salary), 0) FROM payroll_records pr WHERE pr.payroll_period_id = pp.id) AS net_total
    FROM payroll_periods pp
    WHERE pp.status IN ('pending_finance', 'approved', 'transferred', 'review')
    ORDER BY pp.id DESC
")->fetchAll();

// Fetch approval history
$approvalsHistory = $db->query("
    SELECT pa.*, pp.period_code, u.full_name AS approver_name
    FROM payroll_approvals pa
    JOIN payroll_periods pp ON pa.payroll_period_id = pp.id
    LEFT JOIN users u ON pa.decided_by = u.id
    ORDER BY pa.id DESC
    LIMIT 15
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Persetujuan &amp; Otorisasi Gaji (Payroll Approvals)</h1>
        <div class="page-subtitle">Siklus SDM &bull; Verifikasi Bertingkat: Verifikasi HR &rarr; Persetujuan Finance Manager &rarr; Konfirmasi Transfer Bank Mandiri</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=hr_payroll/payroll" class="btn btn-secondary">
            &larr; Kembali ke Engine Payroll
        </a>
    </div>
</div>

<!-- Pending Approvals Cards -->
<div class="card mb-4">
    <div class="card-header">
        <h2 class="card-title">Daftar Pengajuan Payroll Menunggu Tindakan Otorisasi</h2>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Kode Periode</th>
                        <th>Rentang Tanggal</th>
                        <th class="text-right">Jml Karyawan</th>
                        <th class="text-right">Total Bruto</th>
                        <th class="text-right">Total Potongan</th>
                        <th class="text-right">Total Gaji Bersih (Net)</th>
                        <th>Status Saat Ini</th>
                        <th class="text-center">Aksi Otorisasi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($periods)): ?>
                        <tr>
                            <td colspan="8" class="text-center" style="padding:30px; color:#64748b;">
                                Tidak ada payroll yang menunggu tindakan otorisasi saat ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($periods as $p): ?>
                            <tr>
                                <td>
                                    <strong><a href="index.php?page=hr_payroll/payroll&period_id=<?= $p['id'] ?>"><?= e($p['period_code']) ?></a></strong>
                                </td>
                                <td><?= formatDate($p['start_date']) ?> s/d <?= formatDate($p['end_date']) ?></td>
                                <td class="text-right"><strong><?= (int)$p['total_employees'] ?> orang</strong></td>
                                <td class="text-right font-medium"><?= formatRupiah((float)$p['gross_total']) ?></td>
                                <td class="text-right font-medium" style="color:#dc2626;"><?= formatRupiah((float)$p['deductions_total']) ?></td>
                                <td class="text-right font-bold" style="color:#16a34a; font-size:15px;"><?= formatRupiah((float)$p['net_total']) ?></td>
                                <td><?= statusBadge($p['status']) ?></td>
                                <td class="text-center" style="white-space:nowrap;">
                                    <?php if ($p['status'] === 'pending_finance'): ?>
                                        <button type="button" class="btn btn-sm btn-primary" onclick="openModal('modal-appr-fin-<?= $p['id'] ?>')">
                                            ✓ Otorisasi Finance
                                        </button>
                                    <?php elseif ($p['status'] === 'approved'): ?>
                                        <div style="display:inline-flex; gap:6px; align-items:center;">
                                            <button type="button" class="btn btn-sm btn-success" onclick="openModal('modal-appr-trf-<?= $p['id'] ?>')">
                                                💳 Transfer
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="openModal('modal-appr-rev-<?= $p['id'] ?>')">
                                                ↩️ Reversal
                                            </button>
                                        </div>
                                    <?php elseif ($p['status'] === 'transferred'): ?>
                                        <span class="badge badge-success">✓ Selesai Ditransfer</span>
                                    <?php else: ?>
                                        <a href="index.php?page=hr_payroll/payroll&period_id=<?= $p['id'] ?>" class="btn btn-sm btn-secondary">Review</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- History of Approvals -->
<div class="card mb-4">
    <div class="card-header">
        <h2 class="card-title">Jejak Rekam Otorisasi Penggajian (Approval Audit Trail)</h2>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Waktu Keputusan</th>
                        <th>Periode</th>
                        <th>Tahap Otorisasi</th>
                        <th>Keputusan</th>
                        <th>Catatan</th>
                        <th>Pejabat yang Menyetujui</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($approvalsHistory)): ?>
                        <tr>
                            <td colspan="6" class="text-center" style="padding:20px; color:#64748b;">
                                Belum ada riwayat otorisasi penggajian yang tercatat.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($approvalsHistory as $ah): ?>
                            <tr>
                                <td><?= formatDate($ah['decided_at'] ?: $ah['created_at']) ?></td>
                                <td><strong><?= e($ah['period_code']) ?></strong></td>
                                <td>
                                    <?php if ($ah['approval_type'] === 'hr_review'): ?>
                                        <span class="badge badge-info">1. Review HR</span>
                                    <?php elseif ($ah['approval_type'] === 'finance_approval'): ?>
                                        <span class="badge badge-primary">2. Finance Manager</span>
                                    <?php else: ?>
                                        <span class="badge badge-success">3. Transfer Bank</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= statusBadge($ah['status']) ?></td>
                                <td><?= e($ah['notes'] ?: '-') ?></td>
                                <td><?= e($ah['approver_name'] ?: 'System') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODALS FOR PAYROLL APPROVAL (Outside Table)                    -->
<!-- ============================================================== -->
<?php if (!empty($periods)): ?>
    <?php foreach ($periods as $p): ?>
        <?php if ($p['status'] === 'pending_finance'): ?>
            <!-- Modal Finance Approval -->
            <div id="modal-appr-fin-<?= $p['id'] ?>" class="modal-backdrop">
                <div class="modal-dialog modal-sm">
                    <div class="modal-header">
                        <h3 class="modal-title">✓ Otorisasi Finance Manager</h3>
                        <button type="button" class="modal-close" data-modal-close>&times;</button>
                    </div>
                    <form action="index.php?page=hr_payroll/approvals" method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action_payroll_approval" value="1">
                        <input type="hidden" name="period_id" value="<?= $p['id'] ?>">
                        <input type="hidden" name="step" value="finance">
                        <div class="modal-body">
                            <div style="background:#f8fafc; padding:10px; border-radius:6px; margin-bottom:12px; font-size:12.5px; border:1px solid #e2e8f0;">
                                Periode: <strong><?= e($p['period_name']) ?></strong> (<?= e($p['period_code']) ?>)<br>
                                Beban Gaji Kotor: <strong><?= formatRupiah((float)$p['gross_total']) ?></strong><br>
                                Total Take-Home Pay: <strong style="color:#16a34a;"><?= formatRupiah((float)$p['net_total']) ?></strong>
                            </div>
                            <p style="font-size:12px; color:#475569; margin-bottom:10px;">
                                Persetujuan ini akan memposting jurnal akrual beban gaji (payroll_accrual) di Buku Besar dan meneruskan status ke siap pencairan.
                            </p>
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="font-size:12px;">Catatan / Komentar Otorisasi</label>
                                <input type="text" name="notes" placeholder="Anggaran payroll telah diverifikasi & disetujui">
                            </div>
                        </div>
                        <div class="modal-footer" style="display:flex; gap:8px;">
                            <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Tutup</button>
                            <button type="submit" name="decision" value="rejected" class="btn btn-danger btn-sm" onclick="return confirm('Apakah Anda yakin ingin menolak & meminta revisi payroll ini?');">
                                ✕ Minta Revisi
                            </button>
                            <button type="submit" name="decision" value="approved" class="btn btn-success btn-sm">
                                ✓ Setujui Payroll
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php elseif ($p['status'] === 'approved'): ?>
            <!-- Modal Bank Transfer -->
            <div id="modal-appr-trf-<?= $p['id'] ?>" class="modal-backdrop">
                <div class="modal-dialog modal-sm">
                    <div class="modal-header">
                        <h3 class="modal-title">💳 Konfirmasi Pencairan Dana Gaji</h3>
                        <button type="button" class="modal-close" data-modal-close>&times;</button>
                    </div>
                    <form action="index.php?page=hr_payroll/approvals" method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action_payroll_approval" value="1">
                        <input type="hidden" name="period_id" value="<?= $p['id'] ?>">
                        <input type="hidden" name="step" value="disburse">
                        <div class="modal-body">
                            <div style="background:#f8fafc; padding:10px; border-radius:6px; margin-bottom:12px; font-size:12.5px; border:1px solid #e2e8f0;">
                                Periode: <strong><?= e($p['period_name']) ?></strong><br>
                                Total Transfer Mandiri: <strong style="color:#16a34a; font-size:14px;"><?= formatRupiah((float)$p['net_total']) ?></strong>
                            </div>
                            <p style="font-size:12px; color:#475569; margin-bottom:10px;">
                                Mengonfirmasi transfer perbankan ke seluruh rekening karyawan dan otomatis menerbitkan slip gaji karyawan yang bersangkutan.
                            </p>
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="font-size:12px;">No. Referensi Batch Transfer *</label>
                                <input type="text" name="notes" placeholder="TRF-MANDIRI-PAYROLL-01" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Batal</button>
                            <button type="submit" name="decision" value="approved" class="btn btn-success btn-sm">
                                Konfirmasi &amp; Terbitkan Slip Gaji
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal Reversal -->
            <div id="modal-appr-rev-<?= $p['id'] ?>" class="modal-backdrop">
                <div class="modal-dialog modal-sm">
                    <div class="modal-header" style="background:#fee2e2;">
                        <h3 class="modal-title" style="color:#b91c1c;">↩️ Batalkan Persetujuan Finance (Reversal)</h3>
                        <button type="button" class="modal-close" data-modal-close>&times;</button>
                    </div>
                    <form action="index.php?page=hr_payroll/approvals" method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action_payroll_approval" value="1">
                        <input type="hidden" name="period_id" value="<?= $p['id'] ?>">
                        <input type="hidden" name="step" value="reverse">
                        <div class="modal-body">
                            <div class="alert alert-warning" style="font-size:12px; padding:10px; margin-bottom:12px;">
                                <strong>Perhatian:</strong> Reversal akan membalikkan jurnal akrual beban gaji (payroll_accrual) di Buku Besar dan mengembalikan status periode penggajian ke Verifikasi HR.
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="font-size:12px;">Alasan Reversal Pembatalan *</label>
                                <input type="text" name="notes" placeholder="Misal: Penyesuaian lembur yang keliru diinput" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Batal</button>
                            <button type="submit" name="decision" value="approved" class="btn btn-danger btn-sm">
                                Ya, Batalkan &amp; Reversal Jurnal
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
