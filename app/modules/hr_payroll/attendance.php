<?php
/**
 * Modul SDM & Penggajian: Presensi & Aktivitas Harian Karyawan (Attendance)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Absensi & Aktivitas Harian';
$breadcrumbCategory = 'SDM & Penggajian';

$db = getDbConnection();
$user = currentUser();

$selectedDate = trim($_GET['date'] ?? date('Y-m-d'));
$deptId = (int)($_GET['dept_id'] ?? 0);

// Handle Single Attendance Record Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_record_attendance'])) {
    requireCsrf();

    $empId = (int)($_POST['employee_id'] ?? 0);
    $attDate = trim($_POST['attendance_date'] ?? date('Y-m-d'));
    $checkIn = trim($_POST['check_in'] ?? '');
    $checkOut = trim($_POST['check_out'] ?? '');
    $status = trim($_POST['status'] ?? 'hadir');
    $overtime = (float)($_POST['overtime_hours'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($empId > 0 && !empty($attDate)) {
        try {
            // Check if record already exists for this date
            $stmtCheck = $db->prepare("SELECT id FROM attendance_records WHERE employee_id = :emp_id AND attendance_date = :att_date");
            $stmtCheck->execute([':emp_id' => $empId, ':att_date' => $attDate]);
            $existingId = $stmtCheck->fetchColumn();

            if ($existingId) {
                $stmtUpd = $db->prepare("
                    UPDATE attendance_records 
                    SET check_in = :ci, check_out = :co, status = :st, overtime_hours = :ot, notes = :notes, updated_at = NOW(), updated_by = :uid
                    WHERE id = :id
                ");
                $stmtUpd->execute([
                    ':ci'    => $checkIn ?: null,
                    ':co'    => $checkOut ?: null,
                    ':st'    => $status,
                    ':ot'    => $overtime,
                    ':notes' => $notes ?: null,
                    ':uid'   => $user['id'],
                    ':id'    => $existingId,
                ]);
                setFlash('success', 'Catatan absensi berhasil diperbarui.');
            } else {
                $stmtIns = $db->prepare("
                    INSERT INTO attendance_records 
                    (employee_id, attendance_date, check_in, check_out, status, overtime_hours, notes, created_by, created_at)
                    VALUES 
                    (:emp_id, :att_date, :ci, :co, :st, :ot, :notes, :uid, NOW())
                ");
                $stmtIns->execute([
                    ':emp_id'   => $empId,
                    ':att_date' => $attDate,
                    ':ci'       => $checkIn ?: null,
                    ':co'       => $checkOut ?: null,
                    ':st'       => $status,
                    ':ot'       => $overtime,
                    ':notes'    => $notes ?: null,
                    ':uid'      => $user['id'],
                ]);
                setFlash('success', 'Absensi harian berhasil dicatat.');
            }

            logAudit($user['id'], 'record_attendance', 'attendance_records', $existingId ?: $db->lastInsertId(), null, [
                'employee_id' => $empId,
                'date'        => $attDate,
                'status'      => $status,
            ]);

            setFlash('success', $existingId ? 'Catatan absensi berhasil diperbarui.' : 'Absensi harian berhasil dicatat.');
            header("Location: index.php?page=hr_payroll/attendance&date={$attDate}&dept_id={$deptId}");
            exit;
        } catch (Exception $e) {
            setFlash('error', 'Gagal mencatat absensi: ' . $e->getMessage());
            header("Location: index.php?page=hr_payroll/attendance&date={$attDate}&dept_id={$deptId}");
            exit;
        }
    }
}

// Handle Edit Single Attendance Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_edit_attendance'])) {
    requireCsrf();

    if (!hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])) {
        setFlash('error', 'Akses ditolak: Hanya administrator atau HR yang berwenang mengubah catatan absensi.');
        header("Location: index.php?page=hr_payroll/attendance&date={$selectedDate}&dept_id={$deptId}");
        exit;
    }

    $attId = (int)($_POST['attendance_id'] ?? 0);
    $checkIn = trim($_POST['check_in'] ?? '');
    $checkOut = trim($_POST['check_out'] ?? '');
    $status = trim($_POST['status'] ?? 'hadir');
    $overtime = (float)($_POST['overtime_hours'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    try {
        $stmtOld = $db->prepare("SELECT * FROM attendance_records WHERE id = :id");
        $stmtOld->execute([':id' => $attId]);
        $oldAtt = $stmtOld->fetch();

        if (!$oldAtt) {
            throw new Exception('Catatan absensi tidak ditemukan.');
        }

        // Check if attendance date falls within an approved or closed payroll period
        $stmtCheckClosed = $db->prepare("
            SELECT pp.period_code 
            FROM payroll_periods pp
            JOIN payroll_records pr ON pr.payroll_period_id = pp.id
            WHERE pr.employee_id = :eid 
              AND :adate BETWEEN pp.start_date AND pp.end_date 
              AND pp.status IN ('approved', 'transferred', 'closed')
            LIMIT 1
        ");
        $stmtCheckClosed->execute([
            ':eid'   => $oldAtt['employee_id'],
            ':adate' => $oldAtt['attendance_date'],
        ]);
        $closedPeriod = $stmtCheckClosed->fetchColumn();
        if ($closedPeriod) {
            throw new Exception("Absensi tidak dapat diubah karena periode penggajian {$closedPeriod} untuk tanggal ini sudah disetujui / ditransfer.");
        }

        $stmtUpd = $db->prepare("
            UPDATE attendance_records 
            SET check_in = :ci, check_out = :co, status = :st, overtime_hours = :ot, notes = :notes, updated_at = NOW(), updated_by = :uid
            WHERE id = :id
        ");
        $stmtUpd->execute([
            ':ci'    => $checkIn ?: null,
            ':co'    => $checkOut ?: null,
            ':st'    => $status,
            ':ot'    => $overtime,
            ':notes' => $notes ?: null,
            ':uid'   => $user['id'],
            ':id'    => $attId,
        ]);

        logAudit($user['id'], 'update_attendance', 'attendance_records', $attId, $oldAtt, [
            'check_in'       => $checkIn,
            'check_out'      => $checkOut,
            'status'         => $status,
            'overtime_hours' => $overtime,
            'notes'          => $notes,
        ]);

        setFlash('success', 'Catatan absensi karyawan berhasil diperbarui.');
        header("Location: index.php?page=hr_payroll/attendance&date={$selectedDate}&dept_id={$deptId}");
        exit;
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        header("Location: index.php?page=hr_payroll/attendance&date={$selectedDate}&dept_id={$deptId}");
        exit;
    }
}

// Handle Delete Attendance Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete_attendance'])) {
    requireCsrf();

    if (!hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])) {
        setFlash('error', 'Akses ditolak: Hanya administrator atau HR yang berwenang menghapus catatan absensi.');
        header("Location: index.php?page=hr_payroll/attendance&date={$selectedDate}&dept_id={$deptId}");
        exit;
    }

    $attId = (int)($_POST['attendance_id'] ?? 0);

    try {
        $stmtOld = $db->prepare("SELECT * FROM attendance_records WHERE id = :id");
        $stmtOld->execute([':id' => $attId]);
        $oldAtt = $stmtOld->fetch();

        if (!$oldAtt) {
            throw new Exception('Catatan absensi tidak ditemukan.');
        }

        // Check if attendance date falls within an approved or closed payroll period
        $stmtCheckClosed = $db->prepare("
            SELECT pp.period_code 
            FROM payroll_periods pp
            JOIN payroll_records pr ON pr.payroll_period_id = pp.id
            WHERE pr.employee_id = :eid 
              AND :adate BETWEEN pp.start_date AND pp.end_date 
              AND pp.status IN ('approved', 'transferred', 'closed')
            LIMIT 1
        ");
        $stmtCheckClosed->execute([
            ':eid'   => $oldAtt['employee_id'],
            ':adate' => $oldAtt['attendance_date'],
        ]);
        $closedPeriod = $stmtCheckClosed->fetchColumn();
        if ($closedPeriod) {
            throw new Exception("Absensi tidak dapat dihapus karena periode penggajian {$closedPeriod} untuk tanggal ini sudah disetujui / ditransfer.");
        }

        $db->prepare("DELETE FROM attendance_records WHERE id = :id")->execute([':id' => $attId]);
        logAudit($user['id'], 'delete_attendance', 'attendance_records', $attId, $oldAtt, null);

        setFlash('success', 'Catatan absensi berhasil dihapus (status kehadiran direset).');
        header("Location: index.php?page=hr_payroll/attendance&date={$selectedDate}&dept_id={$deptId}");
        exit;
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        header("Location: index.php?page=hr_payroll/attendance&date={$selectedDate}&dept_id={$deptId}");
        exit;
    }
}

// Handle Auto-CheckIn All Active Employees
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_quick_hadir_all'])) {
    requireCsrf();

    if (!hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])) {
        setFlash('error', 'Akses ditolak.');
        header("Location: index.php?page=hr_payroll/attendance&date={$selectedDate}");
        exit;
    }

    try {
        $db->beginTransaction();

        $activeEmps = $db->query("SELECT id FROM employees WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        $stmtIns = $db->prepare("
            INSERT INTO attendance_records (employee_id, attendance_date, check_in, check_out, status, overtime_hours, notes, created_by, created_at)
            VALUES (:emp_id, :att_date, '08:00:00', '17:00:00', 'hadir', 0.00, 'Presensi harian terjadwal', :uid, NOW())
            ON DUPLICATE KEY UPDATE check_in = VALUES(check_in), check_out = VALUES(check_out), status = VALUES(status), updated_at = NOW()
        ");

        foreach ($activeEmps as $eid) {
            $stmtIns->execute([
                ':emp_id'   => $eid,
                ':att_date' => $selectedDate,
                ':uid'      => $user['id'],
            ]);
        }

        $db->commit();
        setFlash('success', "Seluruh karyawan aktif (" . count($activeEmps) . " orang) berhasil dicatat hadir untuk tanggal " . formatDate($selectedDate) . ".");
        header("Location: index.php?page=hr_payroll/attendance&date={$selectedDate}");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Gagal mencatat absensi massal: ' . $e->getMessage());
        header("Location: index.php?page=hr_payroll/attendance&date={$selectedDate}");
        exit;
    }
}

// Fetch Attendance records for the selected date
$where = ['e.status = "active"'];
$params = [':att_date' => $selectedDate];

if ($deptId > 0) {
    $where[] = 'e.department_id = :dept_id';
    $params[':dept_id'] = $deptId;
}

$whereSql = implode(' AND ', $where);

$stmtList = $db->prepare("
    SELECT e.id AS employee_id, e.code AS employee_code, e.full_name,
           d.name AS department_name, p.name AS position_name,
           att.id AS attendance_id, att.attendance_date, att.check_in, att.check_out,
           COALESCE(att.status, 'belum_absen') AS status,
           COALESCE(att.overtime_hours, 0) AS overtime_hours,
           att.notes
    FROM employees e
    JOIN departments d ON e.department_id = d.id
    JOIN positions p ON e.position_id = p.id
    LEFT JOIN attendance_records att ON e.id = att.employee_id AND att.attendance_date = :att_date
    WHERE {$whereSql}
    ORDER BY d.name ASC, e.full_name ASC
");
$stmtList->execute($params);
$attendanceList = $stmtList->fetchAll();

// Statistics for the date
$statCounts = [
    'hadir'       => 0,
    'terlambat'   => 0,
    'izin'        => 0,
    'sakit'       => 0,
    'alpha'       => 0,
    'cuti'        => 0,
    'belum_absen' => 0,
    'overtime'    => 0.0,
];

foreach ($attendanceList as $r) {
    $st = $r['status'];
    if (isset($statCounts[$st])) {
        $statCounts[$st]++;
    }
    $statCounts['overtime'] += (float)$r['overtime_hours'];
}

// Department dropdown
$departments = $db->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$allEmployees = $db->query("SELECT id, code, full_name FROM employees WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Presensi &amp; Aktivitas Harian Pegawai</h1>
        <div class="page-subtitle">Siklus SDM &bull; Pencatatan Jam Masuk / Pulang, Status Kehadiran, dan Jam Lembur Operasional</div>
    </div>
    <?php if (hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])): ?>
        <div class="page-actions" style="display:flex; gap:10px;">
            <form action="index.php?page=hr_payroll/attendance&date=<?= $selectedDate ?>" method="POST" onsubmit="return confirm('Catat seluruh karyawan aktif hadir terjadwal (08:00 - 17:00)?');">
                <?= csrfField() ?>
                <input type="hidden" name="action_quick_hadir_all" value="1">
                <button type="submit" class="btn btn-secondary">
                    ⚡ Hadirkan Semua Pegawai Hari Ini
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>

<!-- Daily Stats Grid -->
<div class="metrics-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));">
    <div class="metric-card">
        <div class="metric-value" style="color:#16a34a;"><?= $statCounts['hadir'] ?></div>
        <div class="metric-label">Hadir Tepat Waktu</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#ca8a04;"><?= $statCounts['terlambat'] ?></div>
        <div class="metric-label">Terlambat</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#0284c7;"><?= $statCounts['izin'] + $statCounts['sakit'] + $statCounts['cuti'] ?></div>
        <div class="metric-label">Izin / Sakit / Cuti</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#dc2626;"><?= $statCounts['alpha'] ?></div>
        <div class="metric-label">Alpha / Mangkir</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#64748b;"><?= $statCounts['belum_absen'] ?></div>
        <div class="metric-label">Belum Presensi</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#7c3aed;"><?= formatNumber($statCounts['overtime']) ?> jam</div>
        <div class="metric-label">Total Lembur Hari Ini</div>
    </div>
</div>

<!-- Filter Bar -->
<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="hr_payroll/attendance">
    <div class="filter-group">
        <label style="margin-right:5px; font-size:13px; font-weight:600;">Tanggal:</label>
        <input type="date" name="date" value="<?= e($selectedDate) ?>">
    </div>
    <div class="filter-group">
        <select name="dept_id">
            <option value="">-- Semua Departemen --</option>
            <?php foreach ($departments as $d): ?>
                <option value="<?= $d['id'] ?>" <?= $deptId === (int)$d['id'] ? 'selected' : '' ?>>
                    <?= e($d['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Lihat Presensi</button>
</form>

<!-- Single Attendance Form Collapsible -->
<?php if (hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])): ?>
<div class="card mb-4">
    <details>
        <summary style="cursor:pointer; padding:12px 20px; font-weight:600; font-size:14px; color:#0284c7; background:#f8fafc; border-radius:6px; list-style:none; display:flex; justify-content:space-between; align-items:center;">
            <span>+ Catat / Sesuaikan Absensi Karyawan Tertentu</span>
            <span class="btn btn-sm btn-primary">Buka Form Presensi &darr;</span>
        </summary>
        <div style="padding:20px; border-top:1px solid #e2e8f0;">
            <form action="index.php?page=hr_payroll/attendance&date=<?= $selectedDate ?>&dept_id=<?= $deptId ?>" method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action_record_attendance" value="1">
                <input type="hidden" name="attendance_date" value="<?= e($selectedDate) ?>">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px; align-items:flex-end;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Pilih Karyawan *</label>
                        <select name="employee_id" required>
                            <option value="">-- Pilih Karyawan --</option>
                            <?php foreach ($allEmployees as $emp): ?>
                                <option value="<?= $emp['id'] ?>"><?= e($emp['full_name']) ?> (<?= e($emp['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Jam Masuk (Check-In)</label>
                        <input type="time" name="check_in" value="08:00">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Jam Keluar (Check-Out)</label>
                        <input type="time" name="check_out" value="17:00">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Status Kehadiran *</label>
                        <select name="status" required>
                            <option value="hadir">Hadir</option>
                            <option value="terlambat">Terlambat</option>
                            <option value="izin">Izin</option>
                            <option value="sakit">Sakit</option>
                            <option value="alpha">Alpha (Mangkir)</option>
                            <option value="cuti">Cuti Resmi</option>
                            <option value="libur">Libur / Off</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Jam Lembur (Jam)</label>
                        <input type="number" step="0.5" min="0" max="12" name="overtime_hours" value="0" placeholder="0">
                    </div>
                    <div class="form-group" style="margin-bottom:0; grid-column: 1 / -2;">
                        <label>Catatan / Alasan</label>
                        <input type="text" name="notes" placeholder="Keterangan dinas luar / lembur SPK / sakit surat dokter">
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary" style="height:42px; width:100%;">
                            Simpan Presensi
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </details>
</div>
<?php endif; ?>

<!-- Attendance Table for Selected Date -->
<div class="card mb-4">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h2 class="card-title">Daftar Kehadiran Pegawai Tanggal <?= formatDate($selectedDate) ?></h2>
        <span class="badge badge-info"><?= count($attendanceList) ?> Karyawan Aktif</span>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>NIK</th>
                        <th>Nama Karyawan</th>
                        <th>Departemen / Jabatan</th>
                        <th>Jam Masuk</th>
                        <th>Jam Keluar</th>
                        <th>Status Presensi</th>
                        <th class="text-right">Jam Lembur</th>
                        <th>Catatan</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attendanceList)): ?>
                        <tr>
                            <td colspan="9" class="text-center" style="padding:30px; color:#64748b;">
                                Tidak ada data karyawan aktif untuk kriteria ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($attendanceList as $row): ?>
                            <tr>
                                <td><strong><?= e($row['employee_code']) ?></strong></td>
                                <td>
                                    <strong><a href="index.php?page=hr_payroll/employee_detail&id=<?= $row['employee_id'] ?>"><?= e($row['full_name']) ?></a></strong>
                                </td>
                                <td>
                                    <div><?= e($row['department_name']) ?></div>
                                    <small class="text-muted"><?= e($row['position_name']) ?></small>
                                </td>
                                <td><?= e($row['check_in'] ?: '-') ?></td>
                                <td><?= e($row['check_out'] ?: '-') ?></td>
                                <td>
                                    <?php if ($row['status'] === 'belum_absen'): ?>
                                        <span class="badge" style="background:#e2e8f0; color:#475569;">Belum Presensi</span>
                                    <?php else: ?>
                                        <?= statusBadge($row['status']) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right font-medium">
                                    <?= (float)$row['overtime_hours'] > 0 ? formatNumber((float)$row['overtime_hours']) . ' jam' : '-' ?>
                                </td>
                                <td><?= e($row['notes'] ?: '-') ?></td>
                                <td class="text-center" style="white-space:nowrap;">
                                    <?php if ($row['attendance_id']): ?>
                                        <?php if (hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])): ?>
                                            <button type="button" class="btn btn-sm btn-secondary" onclick='openEditAttendanceModal(<?= json_encode([
                                                "id"             => (int)$row["attendance_id"],
                                                "employee_name"  => $row["full_name"],
                                                "employee_code"  => $row["employee_code"],
                                                "date"           => $selectedDate,
                                                "check_in"       => $row["check_in"] ? substr($row["check_in"], 0, 5) : "",
                                                "check_out"      => $row["check_out"] ? substr($row["check_out"], 0, 5) : "",
                                                "status"         => $row["status"],
                                                "overtime_hours" => (float)$row["overtime_hours"],
                                                "notes"          => $row["notes"] ?? "",
                                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                                ✏️ Edit
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger" onclick="openDeleteAttendanceModal(<?= (int)$row['attendance_id'] ?>, '<?= htmlspecialchars(addslashes($row['full_name'])) ?>')">
                                                🗑️ Hapus
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:12px;">Tercatat</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if (hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="quickFillAttendance(<?= (int)$row['employee_id'] ?>)">
                                                + Presensi
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:12px;">-</span>
                                        <?php endif; ?>
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

<!-- Modal Edit Presensi -->
<?php if (hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])): ?>
<div id="modal-edit-attendance" class="modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:8px; width:90%; max-width:550px; padding:25px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:#0284c7;">✏️ Edit Catatan Presensi Pegawai</h3>
        <p id="edit-att-employee-info" style="font-size:14px; font-weight:600; color:#334155; margin-bottom:15px;"></p>
        
        <form action="index.php?page=hr_payroll/attendance&date=<?= e($selectedDate) ?>&dept_id=<?= $deptId ?>" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action_edit_attendance" value="1">
            <input type="hidden" name="attendance_id" id="edit-att-id" value="">
            
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:12px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label style="font-size:12px; font-weight:600;">Jam Masuk (Check-In)</label>
                    <input type="time" name="check_in" id="edit-att-check-in">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label style="font-size:12px; font-weight:600;">Jam Keluar (Check-Out)</label>
                    <input type="time" name="check_out" id="edit-att-check-out">
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:12px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label style="font-size:12px; font-weight:600;">Status Kehadiran *</label>
                    <select name="status" id="edit-att-status" required>
                        <option value="hadir">Hadir</option>
                        <option value="terlambat">Terlambat</option>
                        <option value="izin">Izin</option>
                        <option value="sakit">Sakit</option>
                        <option value="alpha">Alpha (Mangkir)</option>
                        <option value="cuti">Cuti Resmi</option>
                        <option value="libur">Libur / Off</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label style="font-size:12px; font-weight:600;">Jam Lembur (Jam)</label>
                    <input type="number" step="0.5" min="0" max="12" name="overtime_hours" id="edit-att-overtime" value="0">
                </div>
            </div>

            <div class="form-group" style="margin-bottom:15px;">
                <label style="font-size:12px; font-weight:600;">Catatan / Keterangan</label>
                <input type="text" name="notes" id="edit-att-notes" placeholder="Catatan penyesuaian jam kerja / lembur">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditAttendanceModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Delete Presensi -->
<div id="modal-delete-attendance" class="modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:8px; width:90%; max-width:480px; padding:25px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:#dc2626;">Hapus Catatan Presensi</h3>
        <p style="font-size:14px; color:#475569; line-height:1.6;">
            Hapus catatan presensi tanggal <strong><?= formatDate($selectedDate) ?></strong> untuk karyawan <strong id="delete-att-emp-name"></strong>?
        </p>
        <p style="font-size:12px; color:#64748b; background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
            ℹ️ Catatan absensi akan dihapus dan status kehadiran karyawan ini akan dikembalikan menjadi <em>Belum Presensi</em>.
        </p>
        <form action="index.php?page=hr_payroll/attendance&date=<?= e($selectedDate) ?>&dept_id=<?= $deptId ?>" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action_delete_attendance" value="1">
            <input type="hidden" name="attendance_id" id="delete-att-id" value="">
            
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteAttendanceModal()">Batal</button>
                <button type="submit" class="btn btn-danger">Ya, Hapus Presensi</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditAttendanceModal(data) {
    document.getElementById('edit-att-id').value = data.id;
    document.getElementById('edit-att-employee-info').textContent = data.employee_name + ' (' + data.employee_code + ') - Tanggal: ' + data.date;
    document.getElementById('edit-att-check-in').value = data.check_in || '';
    document.getElementById('edit-att-check-out').value = data.check_out || '';
    document.getElementById('edit-att-status').value = data.status || 'hadir';
    document.getElementById('edit-att-overtime').value = data.overtime_hours || 0;
    document.getElementById('edit-att-notes').value = data.notes || '';

    const modal = document.getElementById('modal-edit-attendance');
    if (modal) modal.style.display = 'flex';
}
function closeEditAttendanceModal() {
    const modal = document.getElementById('modal-edit-attendance');
    if (modal) modal.style.display = 'none';
}

function openDeleteAttendanceModal(id, name) {
    document.getElementById('delete-att-id').value = id;
    document.getElementById('delete-att-emp-name').textContent = name;
    const modal = document.getElementById('modal-delete-attendance');
    if (modal) modal.style.display = 'flex';
}
function closeDeleteAttendanceModal() {
    const modal = document.getElementById('modal-delete-attendance');
    if (modal) modal.style.display = 'none';
}

function quickFillAttendance(empId) {
    const details = document.querySelector('details');
    if (details) {
        details.open = true;
        const sel = details.querySelector('select[name="employee_id"]');
        if (sel) {
            sel.value = empId;
            sel.focus();
        }
        details.scrollIntoView({ behavior: 'smooth' });
    }
}

window.addEventListener('click', function(e) {
    const editM = document.getElementById('modal-edit-attendance');
    if (e.target === editM) closeEditAttendanceModal();
    const delM = document.getElementById('modal-delete-attendance');
    if (e.target === delM) closeDeleteAttendanceModal();
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
