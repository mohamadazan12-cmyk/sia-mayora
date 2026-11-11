<?php
/**
 * Modul SDM & Penggajian: Profil Detail & Riwayat Karyawan
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Profil Karyawan';
$breadcrumbCategory = 'SDM & Penggajian';

$db = getDbConnection();
$user = currentUser();

$empId = (int)($_GET['id'] ?? 0);
if ($empId <= 0) {
    setFlash('error', 'ID Karyawan tidak valid.');
    header('Location: index.php?page=hr_payroll/employees');
    exit;
}

// Handle Update Employee Info (Admin / HR only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_employee'])) {
    requireCsrf();

    if (!hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])) {
        setFlash('error', 'Hanya administrator atau tim HR yang dapat mengubah profil karyawan.');
        header("Location: index.php?page=hr_payroll/employee_detail&id={$empId}");
        exit;
    }

    $fullName = trim($_POST['full_name'] ?? '');
    $departmentId = (int)($_POST['department_id'] ?? 0);
    $positionId = (int)($_POST['position_id'] ?? 0);
    $baseSalary = (float)($_POST['base_salary'] ?? 0);
    $fixedAllowance = (float)($_POST['fixed_allowance'] ?? 0);
    $birthDate = !empty($_POST['birth_date']) ? trim($_POST['birth_date']) : null;
    $ptkpCode = trim($_POST['ptkp_code'] ?? 'TK/0');
    $bpjsTk = trim($_POST['bpjs_tk_number'] ?? '');
    $bpjsKes = trim($_POST['bpjs_kes_number'] ?? '');
    $employmentStatus = trim($_POST['employment_status'] ?? 'Tetap');
    $bankName = trim($_POST['bank_name'] ?? '');
    $bankAccount = trim($_POST['bank_account'] ?? '');
    $status = trim($_POST['status'] ?? 'active');

    try {
        $stmtUpd = $db->prepare("
            UPDATE employees 
            SET full_name = :name, department_id = :dept_id, position_id = :pos_id,
                base_salary = :salary, fixed_allowance = :allowance, birth_date = :b_date,
                ptkp_code = :ptkp, bpjs_tk_number = :bpjs_tk, bpjs_kes_number = :bpjs_kes,
                employment_status = :emp_status, bank_name = :b_name, bank_account = :b_acc,
                status = :status, updated_at = NOW(), updated_by = :uid
            WHERE id = :id
        ");
        $stmtUpd->execute([
            ':name'       => $fullName,
            ':dept_id'    => $departmentId,
            ':pos_id'     => $positionId,
            ':salary'     => $baseSalary,
            ':allowance'  => $fixedAllowance,
            ':b_date'     => $birthDate,
            ':ptkp'       => $ptkpCode,
            ':bpjs_tk'    => $bpjsTk ?: null,
            ':bpjs_kes'   => $bpjsKes ?: null,
            ':emp_status' => $employmentStatus,
            ':b_name'     => $bankName,
            ':b_acc'      => $bankAccount,
            ':status'     => $status,
            ':uid'        => $user['id'],
            ':id'         => $empId,
        ]);

        logAudit($user['id'], 'update_employee', 'employees', $empId, null, [
            'name'   => $fullName,
            'salary' => $baseSalary,
            'status' => $status,
        ]);

        setFlash('success', 'Profil personalia karyawan berhasil diperbarui.');
        header("Location: index.php?page=hr_payroll/employee_detail&id={$empId}");
        exit;
    } catch (Exception $e) {
        setFlash('error', 'Gagal memperbarui profil karyawan: ' . $e->getMessage());
        header("Location: index.php?page=hr_payroll/employee_detail&id={$empId}");
        exit;
    }
}

// Handle Toggle Employee Status (Active / Inactive / Resigned)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_toggle_status'])) {
    requireCsrf();

    if (!hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])) {
        setFlash('error', 'Hanya administrator atau tim HR yang berwenang mengubah status karyawan.');
        header("Location: index.php?page=hr_payroll/employee_detail&id={$empId}");
        exit;
    }

    $newStatus = trim($_POST['new_status'] ?? 'inactive');
    try {
        $stmtOld = $db->prepare("SELECT * FROM employees WHERE id = :id");
        $stmtOld->execute([':id' => $empId]);
        $empRow = $stmtOld->fetch();

        if (!$empRow) {
            throw new Exception('Data karyawan tidak ditemukan.');
        }

        $db->prepare("UPDATE employees SET status = :st, updated_at = NOW(), updated_by = :uid WHERE id = :id")
           ->execute([':st' => $newStatus, ':uid' => $user['id'], ':id' => $empId]);

        logAudit($user['id'], 'toggle_status', 'employees', $empId, ['status' => $empRow['status']], ['status' => $newStatus]);
        setFlash('success', "Status karyawan {$empRow['full_name']} berhasil diubah menjadi " . strtoupper($newStatus) . ".");
        header("Location: index.php?page=hr_payroll/employee_detail&id={$empId}");
        exit;
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        header("Location: index.php?page=hr_payroll/employee_detail&id={$empId}");
        exit;
    }
}

// Handle Delete Employee with Reference Check
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete_employee'])) {
    requireCsrf();

    if (!hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])) {
        setFlash('error', 'Hanya administrator atau tim HR yang berwenang menghapus data karyawan.');
        header("Location: index.php?page=hr_payroll/employee_detail&id={$empId}");
        exit;
    }

    try {
        $stmtOld = $db->prepare("SELECT * FROM employees WHERE id = :id");
        $stmtOld->execute([':id' => $empId]);
        $empRow = $stmtOld->fetch();

        if (!$empRow) {
            throw new Exception('Data karyawan tidak ditemukan.');
        }

        $blockers = checkRecordReferences('employees', $empId);
        if (!empty($blockers)) {
            throw new Exception("Karyawan {$empRow['full_name']} tidak dapat dihapus permanen karena memiliki data terkait (" . implode(', ', $blockers) . "). Harap ubah status menjadi Non-Aktif / Resign.");
        }

        $db->prepare("DELETE FROM employees WHERE id = :id")->execute([':id' => $empId]);
        logAudit($user['id'], 'delete', 'employees', $empId, $empRow, null);
        setFlash('success', "Data karyawan {$empRow['full_name']} berhasil dihapus permanen.");
        header('Location: index.php?page=hr_payroll/employees');
        exit;
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        header("Location: index.php?page=hr_payroll/employee_detail&id={$empId}");
        exit;
    }
}

// Fetch Employee Header
$stmt = $db->prepare("
    SELECT e.*, d.name AS department_name, p.name AS position_name,
           u.username, u.email, r.role_name
    FROM employees e
    JOIN departments d ON e.department_id = d.id
    JOIN positions p ON e.position_id = p.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE e.id = :id
");
$stmt->execute([':id' => $empId]);
$emp = $stmt->fetch();

if (!$emp) {
    setFlash('error', 'Karyawan tidak ditemukan.');
    header('Location: index.php?page=hr_payroll/employees');
    exit;
}

// Fetch Attendance Stats
$attStats = $db->prepare("
    SELECT 
        SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) AS count_hadir,
        SUM(CASE WHEN status = 'terlambat' THEN 1 ELSE 0 END) AS count_terlambat,
        SUM(CASE WHEN status = 'izin' THEN 1 ELSE 0 END) AS count_izin,
        SUM(CASE WHEN status = 'sakit' THEN 1 ELSE 0 END) AS count_sakit,
        SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) AS count_alpha,
        COALESCE(SUM(overtime_hours), 0) AS total_overtime_hours
    FROM attendance_records
    WHERE employee_id = :id
");
$attStats->execute([':id' => $empId]);
$stats = $attStats->fetch();

// Fetch Recent Attendance
$stmtAtt = $db->prepare("
    SELECT * FROM attendance_records WHERE employee_id = :id ORDER BY attendance_date DESC LIMIT 10
");
$stmtAtt->execute([':id' => $empId]);
$recentAttendance = $stmtAtt->fetchAll();

// Fetch Recent Leave & Overtime Requests
$stmtLot = $db->prepare("
    SELECT * FROM leave_overtime_requests WHERE employee_id = :id ORDER BY id DESC LIMIT 10
");
$stmtLot->execute([':id' => $empId]);
$recentRequests = $stmtLot->fetchAll();

// Fetch Payslips
$stmtSlips = $db->prepare("
    SELECT ps.*, pp.period_code, pp.period_month, pp.period_year, pr.net_salary, pr.total_earnings, pr.total_deductions
    FROM payslips ps
    JOIN payroll_periods pp ON ps.payroll_period_id = pp.id
    JOIN payroll_records pr ON ps.payroll_record_id = pr.id
    WHERE ps.employee_id = :id
    ORDER BY pp.period_year DESC, pp.period_month DESC
");
$stmtSlips->execute([':id' => $empId]);
$payslips = $stmtSlips->fetchAll();

// Fetch Mutations & Promotions
$stmtMut = $db->prepare("
    SELECT m.*, d_old.name AS old_dept, p_old.name AS old_pos, d_new.name AS new_dept, p_new.name AS new_pos
    FROM employee_mutations m
    LEFT JOIN departments d_old ON m.old_department_id = d_old.id
    LEFT JOIN positions p_old ON m.old_position_id = p_old.id
    LEFT JOIN departments d_new ON m.new_department_id = d_new.id
    LEFT JOIN positions p_new ON m.new_position_id = p_new.id
    WHERE m.employee_id = :id
    ORDER BY m.effective_date DESC
");
$stmtMut->execute([':id' => $empId]);
$mutations = $stmtMut->fetchAll();

// Fetch Trainings
$stmtTrain = $db->prepare("
    SELECT * FROM employee_trainings WHERE employee_id = :id ORDER BY start_date DESC
");
$stmtTrain->execute([':id' => $empId]);
$trainings = $stmtTrain->fetchAll();

// Fetch Terminations
$stmtTerm = $db->prepare("
    SELECT * FROM employee_terminations WHERE employee_id = :id ORDER BY effective_date DESC
");
$stmtTerm->execute([':id' => $empId]);
$terminations = $stmtTerm->fetchAll();

// Departments & Positions for Edit
$departments = $db->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$positions = $db->query("SELECT id, name FROM positions WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= e($emp['full_name']) ?> (<?= e($emp['code']) ?>)</h1>
        <div class="page-subtitle"><?= e($emp['department_name']) ?> &bull; <?= e($emp['position_name']) ?> &bull; Bergabung sejak <?= formatDate($emp['join_date']) ?></div>
    </div>
    <div class="page-actions" style="display:flex; gap:8px; align-items:center;">
        <a href="index.php?page=hr_payroll/employees" class="btn btn-secondary">
            &larr; Kembali ke Direktori
        </a>
        <?php if (hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])): ?>
            <?php if ($emp['status'] === 'active'): ?>
                <form action="index.php?page=hr_payroll/employee_detail&id=<?= $empId ?>" method="POST" style="margin:0;" onsubmit="return confirm('Nonaktifkan status karyawan ini? Karyawan non-aktif tidak akan diikutsertakan dalam kalkulasi payroll bulan berikutnya.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action_toggle_status" value="1">
                    <input type="hidden" name="new_status" value="inactive">
                    <button type="submit" class="btn btn-warning btn-sm">
                        🔒 Nonaktifkan Karyawan
                    </button>
                </form>
            <?php else: ?>
                <form action="index.php?page=hr_payroll/employee_detail&id=<?= $empId ?>" method="POST" style="margin:0;" onsubmit="return confirm('Aktifkan kembali status karyawan ini?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action_toggle_status" value="1">
                    <input type="hidden" name="new_status" value="active">
                    <button type="submit" class="btn btn-success btn-sm">
                        ✓ Aktifkan Karyawan
                    </button>
                </form>
            <?php endif; ?>
            <button type="button" class="btn btn-danger btn-sm" onclick="openDeleteEmployeeModal()">
                🗑️ Hapus Karyawan
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Employee Profile Card -->
<div class="card mb-4">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h2 class="card-title">Profil &amp; Informasi Personalia</h2>
        <div><?= statusBadge($emp['status']) ?></div>
    </div>
    <div class="card-body">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:18px;">
            <div>
                <div class="text-muted" style="font-size:11px; text-transform:uppercase; font-weight:700;">Identitas Pegawai</div>
                <strong style="font-size:15px; color:#111827;"><?= e($emp['full_name']) ?></strong>
                <div class="text-muted" style="font-size:11.5px;">NIK: <b><?= e($emp['code']) ?></b></div>
                <div style="margin-top:4px; font-size:12px;">Status: <span class="bd ok"><?= e($emp['employment_status'] ?? 'Tetap') ?></span></div>
                <div style="font-size:12px; margin-top:3px;">Tgl Lahir: <b><?= formatDate($emp['birth_date'] ?? null) ?></b></div>
                <div style="font-size:12px;">Dept / Bagian: <b><?= e($emp['department_name']) ?></b></div>
                <div style="font-size:12px;">Jabatan: <b><?= e($emp['position_name']) ?></b></div>
            </div>
            <div>
                <div class="text-muted" style="font-size:11px; text-transform:uppercase; font-weight:700;">Kompensasi &amp; Pajak</div>
                <div style="font-size:12px;">Gaji Pokok: 
                    <strong>
                        <?php if (hasRole(['Administrator', 'HR / Payroll', 'Finance/Treasury', 'admin', 'hr', 'finance'])): ?>
                            <?= formatRupiah((float)$emp['base_salary']) ?>
                        <?php else: ?>
                            Rp ******
                        <?php endif; ?>
                    </strong>
                </div>
                <div style="font-size:12px;">Tunjangan Tetap: 
                    <strong>
                        <?php if (hasRole(['Administrator', 'HR / Payroll', 'Finance/Treasury', 'admin', 'hr', 'finance'])): ?>
                            <?= formatRupiah((float)($emp['fixed_allowance'] ?? 0)) ?>
                        <?php else: ?>
                            Rp ******
                        <?php endif; ?>
                    </strong>
                </div>
                <div style="font-size:12px;">Kode PTKP: <b><?= e($emp['ptkp_code'] ?? 'TK/0') ?></b></div>
                <div style="font-size:12px;">Bank Payroll: <b><?= e($emp['bank_name'] ?: '-') ?></b></div>
                <div style="font-size:12px;">No. Rekening: <b><?= e($emp['bank_account'] ?: '-') ?></b></div>
            </div>
            <div>
                <div class="text-muted" style="font-size:11px; text-transform:uppercase; font-weight:700;">Jaminan Sosial &amp; Akun</div>
                <div style="font-size:12px;">BPJS Ketenagakerjaan: <b><?= e($emp['bpjs_tk_number'] ?: '-') ?></b></div>
                <div style="font-size:12px;">BPJS Kesehatan: <b><?= e($emp['bpjs_kes_number'] ?: '-') ?></b></div>
                <div style="font-size:12px; margin-top:6px;">Join Date: <b><?= formatDate($emp['join_date']) ?></b></div>
                <?php if ($emp['username']): ?>
                    <div style="margin-top:6px; font-size:11.5px; color:#16a34a; font-weight:600;">✓ User Sistem: <?= e($emp['username']) ?> (<?= e($emp['role_name']) ?>)</div>
                <?php else: ?>
                    <div class="text-muted" style="font-size:11px; margin-top:6px;">Belum memiliki akun login sistem</div>
                <?php endif; ?>
            </div>
            <div style="background:#f8fafc; padding:12px 14px; border-radius:6px; border:1px solid #e2e8f0;">
                <div class="text-muted" style="font-size:11px; text-transform:uppercase; font-weight:700;">Ringkasan Presensi</div>
                <div style="font-size:12px; margin-top:4px;">Hadir: <strong style="color:#16a34a;"><?= (int)$stats['count_hadir'] ?> hari</strong></div>
                <div style="font-size:12px;">Terlambat: <strong style="color:#eab308;"><?= (int)$stats['count_terlambat'] ?> hari</strong></div>
                <div style="font-size:12px;">Izin / Sakit: <strong><?= (int)$stats['count_izin'] + (int)$stats['count_sakit'] ?> hari</strong></div>
                <div style="font-size:12px;">Alpha / Mangkir: <strong style="color:#dc2626;"><?= (int)$stats['count_alpha'] ?> hari</strong></div>
                <div style="font-size:12px; margin-top:3px; color:#0284c7;">Total Lembur: <strong><?= formatNumber((float)$stats['total_overtime_hours']) ?> jam</strong></div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Employee Details Collapsible (HR/Admin Only) -->
<?php if (hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])): ?>
<div class="card mb-4">
    <details>
        <summary style="cursor:pointer; padding:15px 20px; font-weight:600; font-size:14px; color:#0284c7; background:#f8fafc; border-radius:6px; list-style:none; display:flex; justify-content:space-between; align-items:center;">
            <span>✏️ Perbarui / Edit Data Pegawai</span>
            <span class="btn btn-sm btn-secondary">Buka Formulir Edit &darr;</span>
        </summary>
        <div style="padding:20px; border-top:1px solid #e2e8f0;">
            <form action="index.php?page=hr_payroll/employee_detail&id=<?= $empId ?>" method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action_update_employee" value="1">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:15px;">
                    <div class="form-group">
                        <label>Nama Lengkap</label>
                        <input type="text" name="full_name" value="<?= e($emp['full_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Departemen</label>
                        <select name="department_id" required>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= (int)$emp['department_id'] === (int)$d['id'] ? 'selected' : '' ?>>
                                    <?= e($d['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Jabatan</label>
                        <select name="position_id" required>
                            <?php foreach ($positions as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= (int)$emp['position_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                                    <?= e($p['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Gaji Pokok (Rp)</label>
                        <input type="number" step="0.01" min="0" name="base_salary" value="<?= (float)$emp['base_salary'] ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Tunjangan Tetap (Rp)</label>
                        <input type="number" step="0.01" min="0" name="fixed_allowance" value="<?= (float)($emp['fixed_allowance'] ?? 0) ?>">
                    </div>
                    <div class="form-group">
                        <label>Tanggal Lahir</label>
                        <input type="date" name="birth_date" value="<?= e($emp['birth_date'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Status Perjanjian Kerja</label>
                        <select name="employment_status">
                            <option value="Tetap" <?= ($emp['employment_status'] ?? '') === 'Tetap' ? 'selected' : '' ?>>Karyawan Tetap (PKWTT)</option>
                            <option value="Kontrak" <?= ($emp['employment_status'] ?? '') === 'Kontrak' ? 'selected' : '' ?>>Karyawan Kontrak (PKWT)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Kode Golongan PTKP</label>
                        <select name="ptkp_code">
                            <?php foreach (['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3'] as $pk): ?>
                                <option value="<?= $pk ?>" <?= ($emp['ptkp_code'] ?? '') === $pk ? 'selected' : '' ?>><?= $pk ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>No. BPJS Ketenagakerjaan</label>
                        <input type="text" name="bpjs_tk_number" value="<?= e($emp['bpjs_tk_number'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>No. BPJS Kesehatan</label>
                        <input type="text" name="bpjs_kes_number" value="<?= e($emp['bpjs_kes_number'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Nama Bank</label>
                        <input type="text" name="bank_name" value="<?= e($emp['bank_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label>Nomor Rekening</label>
                        <input type="text" name="bank_account" value="<?= e($emp['bank_account']) ?>">
                    </div>
                    <div class="form-group">
                        <label>Status Aktif Sistem</label>
                        <select name="status">
                            <option value="active" <?= $emp['status'] === 'active' ? 'selected' : '' ?>>Aktif</option>
                            <option value="inactive" <?= $emp['status'] === 'inactive' ? 'selected' : '' ?>>Non-Aktif</option>
                            <option value="resigned" <?= $emp['status'] === 'resigned' ? 'selected' : '' ?>>Resign</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:15px;">
                    <button type="submit" class="btn btn-primary">Simpan Perubahan Data</button>
                </div>
            </form>
        </div>
    </details>
</div>
<?php endif; ?>

<!-- Recent Attendance Table -->
<div class="card mb-4">
    <div class="card-header">
        <h2 class="card-title">Riwayat Absensi Terakhir (10 Hari Kerja)</h2>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Tanggal Absen</th>
                        <th>Jam Masuk (Check-In)</th>
                        <th>Jam Keluar (Check-Out)</th>
                        <th>Status</th>
                        <th class="text-right">Jam Lembur</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentAttendance)): ?>
                        <tr>
                            <td colspan="6" class="text-center" style="padding:20px; color:#64748b;">
                                Belum ada catatan absensi untuk karyawan ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentAttendance as $att): ?>
                            <tr>
                                <td><?= formatDate($att['attendance_date']) ?></td>
                                <td><?= e($att['check_in'] ?: '-') ?></td>
                                <td><?= e($att['check_out'] ?: '-') ?></td>
                                <td><?= statusBadge($att['status']) ?></td>
                                <td class="text-right font-medium"><?= (float)$att['overtime_hours'] > 0 ? formatNumber((float)$att['overtime_hours']) . ' jam' : '-' ?></td>
                                <td><?= e($att['notes'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Payslips History Table -->
<div class="card mb-4">
    <div class="card-header">
        <h2 class="card-title">Riwayat Slip Gaji (Payslips)</h2>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>No. Slip Gaji</th>
                        <th>Periode Penggajian</th>
                        <th class="text-right">Total Pendapatan</th>
                        <th class="text-right">Total Potongan</th>
                        <th class="text-right">Gaji Bersih (Take Home Pay)</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payslips)): ?>
                        <tr>
                            <td colspan="6" class="text-center" style="padding:20px; color:#64748b;">
                                Belum ada slip gaji yang diterbitkan untuk karyawan ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payslips as $ps): ?>
                            <tr>
                                <td><strong><?= e($ps['slip_number']) ?></strong></td>
                                <td><?= e($ps['period_code']) ?> (Bulan <?= $ps['period_month'] ?> / <?= $ps['period_year'] ?>)</td>
                                <td class="text-right"><?= formatRupiah((float)$ps['total_earnings']) ?></td>
                                <td class="text-right" style="color:#dc2626;"><?= formatRupiah((float)$ps['total_deductions']) ?></td>
                                <td class="text-right font-bold" style="color:#16a34a;"><?= formatRupiah((float)$ps['net_salary']) ?></td>
                                <td class="text-center">
                                    <a href="index.php?page=hr_payroll/payslips&id=<?= $ps['id'] ?>" class="btn btn-sm btn-secondary">
                                        Lihat &amp; Cetak Slip
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

<!-- Riwayat Mutasi & Promosi Table -->
<div class="card mb-4">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h2 class="card-title">Riwayat Mutasi &amp; Promosi Jabatan</h2>
        <a href="index.php?page=hr_payroll/mutations" class="btn btn-sm btn-secondary">+ Buka Modul Mutasi</a>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Jenis Perubahan</th>
                        <th>Posisi Lama &rarr; Posisi Baru</th>
                        <th>Tgl Efektif</th>
                        <th>No. SK / Alasan</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mutations)): ?>
                        <tr>
                            <td colspan="5" class="text-center" style="padding:18px; color:#64748b;">
                                Belum ada riwayat mutasi atau promosi untuk karyawan ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($mutations as $m): ?>
                            <tr>
                                <td><span class="bd <?= $m['mutation_type'] === 'Promosi' ? 'ok' : 'wt' ?>"><?= e($m['mutation_type']) ?></span></td>
                                <td style="font-size:12px;">
                                    <?= e($m['old_pos'] ?: '-') ?> (<?= e($m['old_dept'] ?: '-') ?>)<br>
                                    &rarr; <b><?= e($m['new_pos'] ?: '-') ?> (<?= e($m['new_dept'] ?: '-') ?>)</b>
                                </td>
                                <td><?= formatDate($m['effective_date']) ?></td>
                                <td>
                                    <?php if (!empty($m['sk_number'])): ?>
                                        <small style="font-weight:600; display:block;"><?= e($m['sk_number']) ?></small>
                                    <?php endif; ?>
                                    <small class="text-muted"><?= e($m['reason'] ?: '-') ?></small>
                                </td>
                                <td>
                                    <span class="bd <?= $m['status'] === 'Selesai' ? 'ok' : ($m['status'] === 'Ditolak' ? 'no' : 'wt') ?>">
                                        <?= e($m['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Riwayat Program Pelatihan Table -->
<div class="card mb-4">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h2 class="card-title">Riwayat Program Pelatihan &amp; Sertifikasi</h2>
        <a href="index.php?page=hr_payroll/trainings" class="btn btn-sm btn-secondary">+ Buka Modul Pelatihan</a>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Kode &amp; Nama Pelatihan</th>
                        <th>Jadwal Pelaksanaan</th>
                        <th class="text-right">Anggaran</th>
                        <th>Presensi / Hasil</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($trainings)): ?>
                        <tr>
                            <td colspan="5" class="text-center" style="padding:18px; color:#64748b;">
                                Belum ada riwayat pelatihan untuk karyawan ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($trainings as $t): ?>
                            <tr>
                                <td>
                                    <b><?= e($t['training_name']) ?></b><br>
                                    <small class="text-muted" style="font-family:monospace;"><?= e($t['training_code']) ?></small>
                                </td>
                                <td><?= formatDate($t['start_date']) ?> s/d <?= formatDate($t['end_date']) ?></td>
                                <td class="text-right"><?= formatRupiah((float)$t['budget']) ?></td>
                                <td>
                                    <?= e($t['attendance_status']) ?>
                                    <?php if (!empty($t['result'])): ?>
                                        <br><small style="color:#16a34a; font-weight:600;"><?= e($t['result']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="bd <?= in_array($t['status'], ['Disetujui', 'Selesai']) ? 'ok' : ($t['status'] === 'Ditolak' ? 'no' : 'wt') ?>">
                                        <?= e($t['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Riwayat Hak Akhir / Terminasi Table -->
<?php if (!empty($terminations)): ?>
<div class="card mb-4">
    <div class="card-header">
        <h2 class="card-title">Berkas Hak Akhir &amp; Penyelesaian Hubungan Kerja</h2>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Jenis Terminasi</th>
                        <th>Tgl Efektif</th>
                        <th class="text-right">Gaji Terakhir</th>
                        <th class="text-right">Pesangon</th>
                        <th class="text-right">Hak Akhir Bersih</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($terminations as $tm): ?>
                        <tr>
                            <td>
                                <b><?= e($tm['termination_type']) ?></b><br>
                                <small class="text-muted"><?= e($tm['letter_number'] ?: '-') ?></small>
                            </td>
                            <td><?= formatDate($tm['effective_date']) ?></td>
                            <td class="text-right"><?= formatRupiah((float)$tm['last_salary']) ?></td>
                            <td class="text-right" style="color:#16a34a; font-weight:600;">+<?= formatRupiah((float)$tm['severance_amount']) ?></td>
                            <td class="text-right font-bold"><?= formatRupiah((float)$tm['total_settlement']) ?></td>
                            <td>
                                <span class="bd <?= in_array($tm['status'], ['Disetujui', 'Dibayar']) ? 'ok' : 'wt' ?>">
                                    <?= e($tm['status']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Delete Employee Confirmation Modal -->
<?php if (hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])): ?>
<div id="modal-delete-employee" class="modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:8px; width:90%; max-width:500px; padding:25px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:#dc2626;">Konfirmasi Hapus Data Karyawan</h3>
        <p style="font-size:14px; color:#475569; line-height:1.6;">
            Apakah Anda yakin ingin menghapus data karyawan <strong><?= e($emp['full_name']) ?> (<?= e($emp['code']) ?>)</strong>?
        </p>
        <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:6px; padding:12px; margin-bottom:15px; font-size:13px; color:#991b1b;">
            ⚠️ <strong>Perlindungan Integritas Referensi:</strong><br>
            Sistem secara otomatis memverifikasi bahwa karyawan tidak memiliki riwayat absensi, pengajuan cuti/lembur, slip gaji, atau riwayat payroll. Karyawan yang telah memiliki riwayat operasional tidak dapat dihapus, dan harus diubah statusnya menjadi <em>Non-Aktif / Resign</em>.
        </div>
        <form action="index.php?page=hr_payroll/employee_detail&id=<?= $empId ?>" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action_delete_employee" value="1">
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteEmployeeModal()">Batal</button>
                <button type="submit" class="btn btn-danger">Ya, Hapus Karyawan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openDeleteEmployeeModal() {
    const modal = document.getElementById('modal-delete-employee');
    if (modal) modal.style.display = 'flex';
}
function closeDeleteEmployeeModal() {
    const modal = document.getElementById('modal-delete-employee');
    if (modal) modal.style.display = 'none';
}
window.addEventListener('click', function(e) {
    const modal = document.getElementById('modal-delete-employee');
    if (e.target === modal) closeDeleteEmployeeModal();
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
