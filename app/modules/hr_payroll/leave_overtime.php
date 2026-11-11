<?php
/**
 * Modul SDM & Penggajian: Pengajuan Cuti & Lembur (Leave & Overtime Requests)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Pengajuan Cuti & Lembur';
$breadcrumbCategory = 'SDM & Penggajian';

$db = getDbConnection();
$user = currentUser();

$typeFilter = trim($_GET['type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

// Handle Create Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_request'])) {
    requireCsrf();

    $empId = (int)($_POST['employee_id'] ?? 0);
    $reqType = trim($_POST['request_type'] ?? 'cuti');
    $startDate = trim($_POST['start_date'] ?? date('Y-m-d'));
    $endDate = trim($_POST['end_date'] ?? $startDate);
    $hours = (float)($_POST['hours'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($empId <= 0 || empty($startDate) || empty($reason)) {
        setFlash('error', 'Karyawan, tanggal, dan alasan pengajuan wajib diisi.');
        header('Location: index.php?page=hr_payroll/leave_overtime');
        exit;
    }

    try {
        $yearMonth = date('Ym', strtotime($startDate));
        $prefix = ($reqType === 'cuti' ? 'CUTI-' : 'LMB-') . $yearMonth;
        $stmtLast = $db->prepare("SELECT request_number FROM leave_overtime_requests WHERE request_number LIKE :pfx ORDER BY id DESC LIMIT 1");
        $stmtLast->execute([':pfx' => $prefix . '-%']);
        $lastReq = $stmtLast->fetchColumn();
        $seq = 1;
        if ($lastReq) {
            $parts = explode('-', $lastReq);
            $seq = (int)end($parts) + 1;
        }
        $reqNumber = sprintf('%s-%04d', $prefix, $seq);

        $stmtIns = $db->prepare("
            INSERT INTO leave_overtime_requests 
            (request_number, employee_id, request_type, start_date, end_date, hours, reason, status, created_by, created_at)
            VALUES 
            (:r_num, :emp_id, :r_type, :s_date, :e_date, :hrs, :reason, 'pending', :uid, NOW())
        ");
        $stmtIns->execute([
            ':r_num'   => $reqNumber,
            ':emp_id'  => $empId,
            ':r_type'  => $reqType,
            ':s_date'  => $startDate,
            ':e_date'  => $endDate,
            ':hrs'     => ($reqType === 'lembur' ? $hours : 0),
            ':reason'  => $reason,
            ':uid'     => $user['id'],
        ]);
        $reqId = $db->lastInsertId();

        ApprovalService::submit('leave_overtime', $reqId, $reqNumber, "Pengajuan {$reqType} {$reqNumber}: {$reason}", $db);
        logAudit($user['id'], 'create_leave_overtime_request', 'leave_overtime_requests', $reqId, null, [
            'request_number' => $reqNumber,
            'type'           => $reqType,
            'employee_id'    => $empId,
        ]);

        setFlash('success', "Pengajuan {$reqType} {$reqNumber} berhasil dikirim dan menunggu persetujuan atasan/HR.");
        header('Location: index.php?page=hr_payroll/leave_overtime');
        exit;
    } catch (Exception $e) {
        setFlash('error', 'Gagal membuat pengajuan: ' . $e->getMessage());
        header('Location: index.php?page=hr_payroll/leave_overtime');
        exit;
    }
}

// Handle Approval / Rejection by Supervisor or HR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_decide_request'])) {
    requireCsrf();

    if (!hasRole(['Administrator', 'HR / Payroll', 'Supervisor / Manajer', 'admin', 'hr', 'manager'])) {
        setFlash('error', 'Hanya manajer atau tim HR yang berwenang memproses persetujuan cuti/lembur.');
        header('Location: index.php?page=hr_payroll/leave_overtime');
        exit;
    }

    $reqId = (int)($_POST['request_id'] ?? 0);
    $decision = $_POST['decision'] ?? 'rejected';
    $notes = trim($_POST['decision_notes'] ?? '');

    try {
        $db->beginTransaction();

        $stmtReq = $db->prepare("SELECT * FROM leave_overtime_requests WHERE id = :id FOR UPDATE");
        $stmtReq->execute([':id' => $reqId]);
        $req = $stmtReq->fetch();

        if (!$req || $req['status'] !== 'pending') {
            throw new Exception('Permintaan tidak ditemukan atau sudah diproses.');
        }

        if ($decision === 'approved') {
            $db->prepare("
                UPDATE leave_overtime_requests 
                SET status = 'approved', decided_by = :uid, decided_at = NOW(), decision_notes = :notes, updated_at = NOW(), updated_by = :uid
                WHERE id = :id
            ")->execute([
                ':notes' => $notes ?: 'Disetujui',
                ':uid'   => $user['id'],
                ':id'    => $reqId,
            ]);

            // Sync with attendance_records:
            if ($req['request_type'] === 'cuti') {
                // Mark attendance as 'cuti' for the date range
                $cur = strtotime($req['start_date']);
                $end = strtotime($req['end_date']);
                while ($cur <= $end) {
                    $dStr = date('Y-m-d', $cur);
                    $db->prepare("
                        INSERT INTO attendance_records (employee_id, attendance_date, status, notes, created_by, created_at)
                        VALUES (:emp_id, :att_date, 'cuti', :notes, :uid, NOW())
                        ON DUPLICATE KEY UPDATE status = 'cuti', notes = CONCAT_WS(' | ', notes, 'Cuti resmi disetujui'), updated_at = NOW()
                    ")->execute([
                        ':emp_id'   => $req['employee_id'],
                        ':att_date' => $dStr,
                        ':notes'    => "Cuti disetujui ({$req['request_number']})",
                        ':uid'      => $user['id'],
                    ]);
                    $cur = strtotime('+1 day', $cur);
                }
            } elseif ($req['request_type'] === 'lembur') {
                // Add overtime hours to attendance_records for start_date
                $db->prepare("
                    INSERT INTO attendance_records (employee_id, attendance_date, status, overtime_hours, notes, created_by, created_at)
                    VALUES (:emp_id, :att_date, 'hadir', :ot, :notes, :uid, NOW())
                    ON DUPLICATE KEY UPDATE overtime_hours = overtime_hours + :ot, notes = CONCAT_WS(' | ', notes, 'Lembur disetujui'), updated_at = NOW()
                ")->execute([
                    ':emp_id'   => $req['employee_id'],
                    ':att_date' => $req['start_date'],
                    ':ot'       => $req['hours'],
                    ':notes'    => "Lembur disetujui ({$req['request_number']})",
                    ':uid'      => $user['id'],
                ]);
            }

            ApprovalService::approve('leave_overtime', $reqId, $req['request_number'], $user['id'], $notes ?: 'Disetujui', $db);
            setFlash('success', "Pengajuan {$req['request_number']} berhasil disetujui dan telah disinkronkan ke rekap presensi.");
        } else {
            if (empty($notes)) {
                throw new Exception('Alasan penolakan pengajuan wajib diisi.');
            }
            $db->prepare("
                UPDATE leave_overtime_requests 
                SET status = 'rejected', decided_by = :uid, decided_at = NOW(), decision_notes = :notes, updated_at = NOW(), updated_by = :uid
                WHERE id = :id
            ")->execute([
                ':notes' => $notes,
                ':uid'   => $user['id'],
                ':id'    => $reqId,
            ]);

            ApprovalService::reject('leave_overtime', $reqId, $req['request_number'], $user['id'], $notes, $db);
            setFlash('warning', "Pengajuan {$req['request_number']} ditolak.");
        }

        $db->commit();
        header('Location: index.php?page=hr_payroll/leave_overtime');
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', $e->getMessage());
        header('Location: index.php?page=hr_payroll/leave_overtime');
        exit;
    }
}

// Handle Cancel Request (by Requester, Supervisor, or HR)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_cancel_request'])) {
    requireCsrf();

    $reqId = (int)($_POST['request_id'] ?? 0);
    $cancelReason = trim($_POST['cancel_reason'] ?? '');

    if (empty($cancelReason)) {
        setFlash('error', 'Alasan pembatalan permohonan wajib diisi.');
        header('Location: index.php?page=hr_payroll/leave_overtime');
        exit;
    }

    try {
        $db->beginTransaction();

        $stmtReq = $db->prepare("SELECT * FROM leave_overtime_requests WHERE id = :id FOR UPDATE");
        $stmtReq->execute([':id' => $reqId]);
        $req = $stmtReq->fetch();

        if (!$req) {
            throw new Exception('Data pengajuan tidak ditemukan.');
        }

        // Check permission
        $canCancel = hasRole(['Administrator', 'HR / Payroll', 'Supervisor / Manajer', 'admin', 'hr', 'manager']) || ($req['created_by'] == $user['id']);
        if (!$canCancel) {
            throw new Exception('Anda tidak memiliki izin untuk membatalkan pengajuan ini.');
        }

        if (!in_array($req['status'], ['pending', 'approved'])) {
            throw new Exception('Hanya pengajuan dengan status Pending atau Disetujui yang dapat dibatalkan.');
        }

        // If previously approved, rollback attendance records
        if ($req['status'] === 'approved') {
            // Check if period is closed/approved
            $stmtClosedPeriod = $db->prepare("
                SELECT pp.period_code 
                FROM payroll_periods pp
                JOIN payroll_records pr ON pr.payroll_period_id = pp.id
                WHERE pr.employee_id = :eid 
                  AND :req_date BETWEEN pp.start_date AND pp.end_date 
                  AND pp.status IN ('approved', 'transferred', 'closed')
                LIMIT 1
            ");
            $stmtClosedPeriod->execute([':eid' => $req['employee_id'], ':req_date' => $req['start_date']]);
            $closedPeriod = $stmtClosedPeriod->fetchColumn();
            if ($closedPeriod) {
                throw new Exception("Pengajuan {$req['request_number']} tidak dapat dibatalkan karena periode penggajian ({$closedPeriod}) untuk tanggal terkait sudah disetujui / ditransfer.");
            }

            if ($req['request_type'] === 'cuti') {
                $cur = strtotime($req['start_date']);
                $end = strtotime($req['end_date']);
                while ($cur <= $end) {
                    $dStr = date('Y-m-d', $cur);
                    $stmtAtt = $db->prepare("SELECT id, check_in, check_out FROM attendance_records WHERE employee_id = :eid AND attendance_date = :dt");
                    $stmtAtt->execute([':eid' => $req['employee_id'], ':dt' => $dStr]);
                    $attRow = $stmtAtt->fetch();
                    if ($attRow) {
                        if (empty($attRow['check_in']) && empty($attRow['check_out'])) {
                            // Delete record entirely
                            $db->prepare("DELETE FROM attendance_records WHERE id = :id")->execute([':id' => $attRow['id']]);
                        } else {
                            // Revert status to hadir
                            $db->prepare("UPDATE attendance_records SET status = 'hadir', notes = CONCAT_WS(' | ', notes, 'Cuti dibatalkan'), updated_at = NOW(), updated_by = :uid WHERE id = :id")
                               ->execute([':uid' => $user['id'], ':id' => $attRow['id']]);
                        }
                    }
                    $cur = strtotime('+1 day', $cur);
                }
            } elseif ($req['request_type'] === 'lembur') {
                // Deduct overtime hours
                $db->prepare("
                    UPDATE attendance_records 
                    SET overtime_hours = GREATEST(0, overtime_hours - :hrs),
                        notes = CONCAT_WS(' | ', notes, 'Lembur dibatalkan'),
                        updated_at = NOW(),
                        updated_by = :uid
                    WHERE employee_id = :eid AND attendance_date = :dt
                ")->execute([
                    ':hrs' => $req['hours'],
                    ':uid' => $user['id'],
                    ':eid' => $req['employee_id'],
                    ':dt'  => $req['start_date'],
                ]);
            }
        }

        $newNotes = 'Dibatalkan: ' . $cancelReason;
        if (!empty($req['decision_notes'])) {
            $newNotes = $req['decision_notes'] . ' | ' . $newNotes;
        }

        $db->prepare("
            UPDATE leave_overtime_requests 
            SET status = 'cancelled', updated_at = NOW(), updated_by = :uid, decision_notes = :notes 
            WHERE id = :id
        ")->execute([
            ':notes' => $newNotes,
            ':uid'   => $user['id'],
            ':id'    => $reqId,
        ]);

        logAudit($user['id'], 'cancel_leave_overtime_request', 'leave_overtime_requests', $reqId, $req, [
            'status'        => 'cancelled',
            'cancel_reason' => $cancelReason,
        ]);

        $db->commit();
        setFlash('success', "Pengajuan {$req['request_number']} berhasil dibatalkan" . ($req['status'] === 'approved' ? ' dan sinkronisasi presensi/lembur telah dipulihkan.' : '.'));
        header('Location: index.php?page=hr_payroll/leave_overtime');
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', $e->getMessage());
        header('Location: index.php?page=hr_payroll/leave_overtime');
        exit;
    }
}

// Fetch list of requests
$where = ['1=1'];
$params = [];

if ($typeFilter !== '') {
    $where[] = 'lot.request_type = :type';
    $params[':type'] = $typeFilter;
}
if ($statusFilter !== '') {
    $where[] = 'lot.status = :status';
    $params[':status'] = $statusFilter;
}

$whereSql = implode(' AND ', $where);

$stmtList = $db->prepare("
    SELECT lot.*, e.code AS employee_code, e.full_name AS employee_name,
           d.name AS department_name, p.name AS position_name,
           ud.full_name AS approver_name, uc.full_name AS creator_name
    FROM leave_overtime_requests lot
    JOIN employees e ON lot.employee_id = e.id
    JOIN departments d ON e.department_id = d.id
    JOIN positions p ON e.position_id = p.id
    LEFT JOIN users ud ON lot.decided_by = ud.id
    LEFT JOIN users uc ON lot.created_by = uc.id
    WHERE {$whereSql}
    ORDER BY lot.id DESC
");
$stmtList->execute($params);
$requests = $stmtList->fetchAll();

// Active employees dropdown
$employees = $db->query("SELECT id, code, full_name FROM employees WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Pengajuan Cuti &amp; Lembur Karyawan</h1>
        <div class="page-subtitle">Siklus SDM &bull; Pengelolaan Izin Cuti Tahunan, Surat Perintah Lembur, dan Otorisasi Berjenjang</div>
    </div>
</div>

<!-- Submit New Request Collapsible -->
<div class="card mb-4">
    <details>
        <summary style="cursor:pointer; padding:15px 20px; font-weight:600; font-size:15px; color:#0284c7; background:#f8fafc; border-radius:6px; list-style:none; display:flex; justify-content:space-between; align-items:center;">
            <span>+ Buat Permohonan Cuti / Lembur Baru</span>
            <span class="btn btn-sm btn-primary">Buka Formulir Pengajuan &darr;</span>
        </summary>
        <div style="padding:20px; border-top:1px solid #e2e8f0;">
            <form action="index.php?page=hr_payroll/leave_overtime" method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action_create_request" value="1">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:15px;">
                    <div class="form-group">
                        <label>Nama Karyawan *</label>
                        <select name="employee_id" required>
                            <option value="">-- Pilih Karyawan --</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['id'] ?>"><?= e($emp['full_name']) ?> (<?= e($emp['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Jenis Pengajuan *</label>
                        <select name="request_type" required>
                            <option value="cuti">Pengajuan Cuti / Izin Tidak Masuk</option>
                            <option value="lembur">Surat Perintah Kerja Lembur (SPKL)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Tanggal Mulai *</label>
                        <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Tanggal Selesai (Khusus Cuti)</label>
                        <input type="date" name="end_date" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>Jumlah Jam Lembur (Khusus Lembur)</label>
                        <input type="number" step="0.5" min="0.5" max="12" name="hours" value="2" placeholder="Jumlah jam lembur">
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Alasan Cuti / Penugasan Lembur *</label>
                        <input type="text" name="reason" placeholder="Misal: Cuti tahunan urusan keluarga / Lembur target perakitan mesin CNC SPK-001" required>
                    </div>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:15px;">
                    <button type="submit" class="btn btn-primary">
                        Kirim Pengajuan Cuti / Lembur &rarr;
                    </button>
                </div>
            </form>
        </div>
    </details>
</div>

<!-- Filter Bar -->
<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="hr_payroll/leave_overtime">
    <div class="filter-group">
        <select name="type">
            <option value="">-- Semua Jenis Pengajuan --</option>
            <option value="cuti" <?= $typeFilter === 'cuti' ? 'selected' : '' ?>>Cuti / Izin</option>
            <option value="lembur" <?= $typeFilter === 'lembur' ? 'selected' : '' ?>>Lembur (SPKL)</option>
        </select>
    </div>
    <div class="filter-group">
        <select name="status">
            <option value="">-- Semua Status --</option>
            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Menunggu Persetujuan</option>
            <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Disetujui</option>
            <option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Ditolak</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($typeFilter !== '' || $statusFilter !== ''): ?>
        <a href="index.php?page=hr_payroll/leave_overtime" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<!-- Table of Requests -->
<div class="card mb-4">
    <div class="card-header">
        <h2 class="card-title">Daftar Permohonan Cuti &amp; Surat Perintah Lembur</h2>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>No. Pengajuan</th>
                        <th>Karyawan</th>
                        <th>Departemen</th>
                        <th>Jenis</th>
                        <th>Tanggal Pelaksanaan</th>
                        <th class="text-right">Jam Lembur</th>
                        <th>Alasan &amp; Keperluan</th>
                        <th>Status</th>
                        <th class="text-center">Aksi / Otorisasi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                        <tr>
                            <td colspan="9" class="text-center" style="padding:30px; color:#64748b;">
                                Tidak ada pengajuan cuti atau lembur yang ditemukan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $req): ?>
                            <tr>
                                <td><strong><?= e($req['request_number']) ?></strong></td>
                                <td>
                                    <strong><?= e($req['employee_name']) ?></strong>
                                    <div class="text-muted" style="font-size:11px;"><?= e($req['employee_code']) ?></div>
                                </td>
                                <td><?= e($req['department_name']) ?></td>
                                <td>
                                    <?php if ($req['request_type'] === 'cuti'): ?>
                                        <span class="badge" style="background:#e0f2fe; color:#0369a1;">🏖️ Cuti</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#fef3c7; color:#b45309;">⏱️ Lembur</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= formatDate($req['start_date']) ?>
                                    <?php if ($req['start_date'] !== $req['end_date'] && $req['request_type'] === 'cuti'): ?>
                                        s/d <?= formatDate($req['end_date']) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right font-medium">
                                    <?= (float)$req['hours'] > 0 ? formatNumber((float)$req['hours']) . ' jam' : '-' ?>
                                </td>
                                <td>
                                    <div><?= e($req['reason']) ?></div>
                                    <?php if ($req['decision_notes']): ?>
                                        <small class="text-muted" style="display:block; margin-top:2px;">Catatan: <?= e($req['decision_notes']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= statusBadge($req['status']) ?></td>
                                <td class="text-center" style="white-space:nowrap;">
                                    <button type="button" class="btn btn-sm btn-secondary" onclick='openDetailModal(<?= json_encode([
                                        "request_number" => $req["request_number"],
                                        "request_type"   => $req["request_type"],
                                        "employee_name"  => $req["employee_name"],
                                        "employee_code"  => $req["employee_code"],
                                        "department"     => $req["department_name"],
                                        "position"       => $req["position_name"],
                                        "start_date"     => formatDate($req["start_date"]),
                                        "end_date"       => formatDate($req["end_date"]),
                                        "hours"          => (float)$req["hours"],
                                        "reason"         => $req["reason"],
                                        "status"         => $req["status"],
                                        "status_badge"   => statusBadge($req["status"]),
                                        "creator_name"   => $req["creator_name"] ?: '-',
                                        "created_at"     => formatDateTime($req["created_at"]),
                                        "approver_name"  => $req["approver_name"] ?: '-',
                                        "decided_at"     => $req["decided_at"] ? formatDateTime($req["decided_at"]) : '-',
                                        "decision_notes" => $req["decision_notes"] ?: '-',
                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                        👁️ Detail
                                    </button>

                                    <?php if ($req['status'] === 'pending' && hasRole(['Administrator', 'HR / Payroll', 'Supervisor / Manajer', 'admin', 'hr', 'manager'])): ?>
                                        <details style="display:inline-block; text-align:left;">
                                            <summary class="btn btn-sm btn-primary" style="cursor:pointer; list-style:none;">
                                                Otorisasi &darr;
                                            </summary>
                                            <div style="position:absolute; right:30px; margin-top:5px; background:#fff; border:1px solid #cbd5e1; border-radius:8px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1); padding:15px; width:300px; z-index:50;">
                                                <h4 style="margin:0 0 10px 0; font-size:14px;">Persetujuan / Penolakan</h4>
                                                <form action="index.php?page=hr_payroll/leave_overtime" method="POST">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action_decide_request" value="1">
                                                    <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                                    <div class="form-group" style="margin-bottom:10px;">
                                                        <label style="font-size:11px;">Catatan Persetujuan / Alasan Penolakan</label>
                                                        <input type="text" name="decision_notes" placeholder="Opsional / Wajib jika ditolak" style="font-size:12px; padding:6px;">
                                                    </div>
                                                    <div style="display:flex; gap:8px;">
                                                        <button type="submit" name="decision" value="approved" class="btn btn-sm btn-success" style="flex:1;">
                                                            ✓ Setujui
                                                        </button>
                                                        <button type="submit" name="decision" value="rejected" class="btn btn-sm btn-danger" style="flex:1;">
                                                            ✕ Tolak
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </details>
                                    <?php endif; ?>

                                    <?php 
                                    $canCancelRow = in_array($req['status'], ['pending', 'approved']) && 
                                                    (hasRole(['Administrator', 'HR / Payroll', 'Supervisor / Manajer', 'admin', 'hr', 'manager']) || $req['created_by'] == $user['id']);
                                    ?>
                                    <?php if ($canCancelRow): ?>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="openCancelModal(<?= (int)$req['id'] ?>, '<?= htmlspecialchars(addslashes($req['request_number'])) ?>', '<?= htmlspecialchars(addslashes($req['employee_name'])) ?>', '<?= $req['status'] ?>', '<?= $req['request_type'] ?>')">
                                            ✕ Batalkan
                                        </button>
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

<!-- Modal Detail Pengajuan -->
<div id="modal-detail-request" class="modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:8px; width:90%; max-width:600px; padding:25px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2); max-height:90vh; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
            <div>
                <h3 style="margin:0; font-size:18px;" id="dtl-req-number"></h3>
                <span id="dtl-req-type" class="badge" style="margin-top:4px;"></span>
            </div>
            <div id="dtl-req-status"></div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:15px; font-size:13px;">
            <div>
                <span class="text-muted" style="display:block; font-size:11px;">Karyawan:</span>
                <strong id="dtl-emp-name" style="font-size:14px;"></strong>
                <div class="text-muted" id="dtl-emp-code" style="font-size:11px;"></div>
            </div>
            <div>
                <span class="text-muted" style="display:block; font-size:11px;">Departemen / Jabatan:</span>
                <strong id="dtl-dept-pos"></strong>
            </div>
            <div>
                <span class="text-muted" style="display:block; font-size:11px;">Tanggal Pelaksanaan:</span>
                <strong id="dtl-dates"></strong>
            </div>
            <div>
                <span class="text-muted" style="display:block; font-size:11px;">Durasi / Jam:</span>
                <strong id="dtl-duration"></strong>
            </div>
        </div>

        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px; margin-bottom:15px;">
            <div class="text-muted" style="font-size:11px; margin-bottom:4px; font-weight:600;">ALASAN / KEPERLUAN PENUGASAN:</div>
            <div id="dtl-reason" style="font-size:13px; line-height:1.5; color:#1e293b;"></div>
        </div>

        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px; margin-bottom:20px; font-size:12px;">
            <div class="text-muted" style="font-size:11px; margin-bottom:6px; font-weight:600;">STATUS &amp; JEJAK OTORISASI:</div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                <div>Diajukan Oleh: <strong id="dtl-creator"></strong></div>
                <div>Waktu Pengajuan: <strong id="dtl-created-at"></strong></div>
                <div>Diproses Oleh: <strong id="dtl-approver"></strong></div>
                <div>Waktu Keputusan: <strong id="dtl-decided-at"></strong></div>
            </div>
            <div style="margin-top:8px; padding-top:8px; border-top:1px dashed #cbd5e1;">
                Catatan Otorisasi: <span id="dtl-decision-notes" style="font-style:italic;"></span>
            </div>
        </div>

        <div style="display:flex; justify-content:flex-end;">
            <button type="button" class="btn btn-secondary" onclick="closeDetailModal()">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal Pembatalan Pengajuan -->
<div id="modal-cancel-request" class="modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:8px; width:90%; max-width:500px; padding:25px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:#dc2626;">Pembatalan Pengajuan Cuti / Lembur</h3>
        <p style="font-size:14px; color:#475569; line-height:1.6;">
            Batalkan pengajuan <strong id="cancel-req-num"></strong> untuk karyawan <strong id="cancel-emp-name"></strong>?
        </p>
        <div id="cancel-rollback-notice" style="display:none; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; padding:12px; margin-bottom:15px; font-size:13px; color:#991b1b;">
            ⚠️ <strong>Pemulihan Otomatis Data Presensi:</strong><br>
            Pengajuan ini berstatus <strong>Disetujui</strong>. Pembatalan akan otomatis mengembalikan sinkronisasi presensi harian karyawan (menghapus status cuti atau mengurangkan jam lembur yang telah tercatat).
        </div>
        <form action="index.php?page=hr_payroll/leave_overtime" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action_cancel_request" value="1">
            <input type="hidden" name="request_id" id="cancel-req-id" value="">
            
            <div class="form-group" style="margin-bottom:15px;">
                <label style="font-size:12px; font-weight:600;">Alasan Pembatalan *</label>
                <input type="text" name="cancel_reason" placeholder="Misal: Salah jadwal / Dibatalkan oleh yang bersangkutan" required>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="closeCancelModal()">Batal</button>
                <button type="submit" class="btn btn-danger">Ya, Batalkan Pengajuan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openDetailModal(data) {
    document.getElementById('dtl-req-number').textContent = data.request_number;
    const typeEl = document.getElementById('dtl-req-type');
    if (data.request_type === 'cuti') {
        typeEl.textContent = '🏖️ Cuti Tahunan / Izin';
        typeEl.style.background = '#e0f2fe';
        typeEl.style.color = '#0369a1';
    } else {
        typeEl.textContent = '⏱️ Surat Perintah Lembur (SPKL)';
        typeEl.style.background = '#fef3c7';
        typeEl.style.color = '#b45309';
    }
    document.getElementById('dtl-req-status').innerHTML = data.status_badge;
    document.getElementById('dtl-emp-name').textContent = data.employee_name;
    document.getElementById('dtl-emp-code').textContent = 'NIK: ' + data.employee_code;
    document.getElementById('dtl-dept-pos').textContent = data.department + ' - ' + data.position;
    
    if (data.request_type === 'cuti') {
        document.getElementById('dtl-dates').textContent = data.start_date + (data.start_date !== data.end_date ? ' s/d ' + data.end_date : '');
        document.getElementById('dtl-duration').textContent = 'Hari Kerja Cuti';
    } else {
        document.getElementById('dtl-dates').textContent = data.start_date;
        document.getElementById('dtl-duration').textContent = data.hours + ' jam kerja';
    }

    document.getElementById('dtl-reason').textContent = data.reason;
    document.getElementById('dtl-creator').textContent = data.creator_name;
    document.getElementById('dtl-created-at').textContent = data.created_at;
    document.getElementById('dtl-approver').textContent = data.approver_name;
    document.getElementById('dtl-decided-at').textContent = data.decided_at;
    document.getElementById('dtl-decision-notes').textContent = data.decision_notes;

    const modal = document.getElementById('modal-detail-request');
    if (modal) modal.style.display = 'flex';
}
function closeDetailModal() {
    const modal = document.getElementById('modal-detail-request');
    if (modal) modal.style.display = 'none';
}

function openCancelModal(id, reqNum, empName, status, reqType) {
    document.getElementById('cancel-req-id').value = id;
    document.getElementById('cancel-req-num').textContent = reqNum;
    document.getElementById('cancel-emp-name').textContent = empName;

    const notice = document.getElementById('cancel-rollback-notice');
    if (notice) {
        notice.style.display = (status === 'approved') ? 'block' : 'none';
    }

    const modal = document.getElementById('modal-cancel-request');
    if (modal) modal.style.display = 'flex';
}
function closeCancelModal() {
    const modal = document.getElementById('modal-cancel-request');
    if (modal) modal.style.display = 'none';
}

window.addEventListener('click', function(e) {
    const dtlM = document.getElementById('modal-detail-request');
    if (e.target === dtlM) closeDetailModal();
    const canM = document.getElementById('modal-cancel-request');
    if (e.target === canM) closeCancelModal();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
