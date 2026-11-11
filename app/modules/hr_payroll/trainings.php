<?php
/**
 * Modul SDM & Penggajian: Program & Persetujuan Pelatihan Karyawan
 * Sumber Fitur & Desain: SISP Mayora (reference/persetujuan_pelatihan.php)
 * Terintegrasi penuh dengan RBAC, Audit Trail, dan Anggaran Pelatihan PT Mayora Indah Tbk.
 */

$pageTitle = 'Persetujuan Pelatihan Karyawan';
$breadcrumbCategory = 'SDM & Penggajian';

$db = getDbConnection();
$user = currentUser();

requireRole(['Administrator', 'admin', 'HR / Payroll', 'hr', 'Supervisor / Manajer', 'manager', 'manajer']);

// -----------------------------------------------------------------------------
// POST HANDLER: APPROVE / REJECT / CREATE TRAINING
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? $_POST['aksi'] ?? '';

    // 1. Setujui Pelatihan (Manager & Admin)
    if ($action === 'setujui' || $action === 'approve') {
        if (!hasRole(['Administrator', 'admin', 'Supervisor / Manajer', 'manager', 'manajer'])) {
            setFlash('error', 'Hanya Manajer SDM / Direksi atau Administrator yang berwenang menyetujui anggaran pelatihan.');
            header('Location: index.php?page=hr_payroll/trainings');
            exit;
        }

        $idPelatihan = (int)($_POST['id_pelatihan'] ?? $_POST['id'] ?? 0);

        try {
            $stmt = $db->prepare("UPDATE employee_trainings SET status = 'Disetujui', decided_by = :uid, decided_at = NOW(), updated_at = NOW() WHERE id = :id AND status = 'Menunggu'");
            $stmt->execute([':uid' => $user['id'], ':id' => $idPelatihan]);

            logAudit($user['id'], 'approve_training', 'employee_trainings', $idPelatihan, null, ['status' => 'Disetujui']);
            setFlash('success', 'Rencana pelatihan karyawan berhasil disetujui.');
        } catch (Exception $e) {
            setFlash('error', 'Gagal memproses persetujuan: ' . $e->getMessage());
        }

        header('Location: index.php?page=hr_payroll/trainings');
        exit;
    }

    // 2. Tolak Pelatihan
    elseif ($action === 'tolak' || $action === 'reject') {
        if (!hasRole(['Administrator', 'admin', 'Supervisor / Manajer', 'manager', 'manajer'])) {
            setFlash('error', 'Hanya Manajer SDM / Direksi atau Administrator yang berwenang menolak usulan pelatihan.');
            header('Location: index.php?page=hr_payroll/trainings');
            exit;
        }

        $idPelatihan = (int)($_POST['id_pelatihan'] ?? $_POST['id'] ?? 0);
        $catatan = trim($_POST['decision_notes'] ?? 'Ditolak.');

        try {
            $stmt = $db->prepare("UPDATE employee_trainings SET status = 'Ditolak', decision_notes = :notes, decided_by = :uid, decided_at = NOW(), updated_at = NOW() WHERE id = :id AND status = 'Menunggu'");
            $stmt->execute([':notes' => $catatan, ':uid' => $user['id'], ':id' => $idPelatihan]);

            logAudit($user['id'], 'reject_training', 'employee_trainings', $idPelatihan, null, ['status' => 'Ditolak', 'notes' => $catatan]);
            setFlash('error', 'Usulan pelatihan telah ditolak.');
        } catch (Exception $e) {
            setFlash('error', 'Gagal menolak pelatihan: ' . $e->getMessage());
        }

        header('Location: index.php?page=hr_payroll/trainings');
        exit;
    }

    // 3. Tambah Rencana Pelatihan Baru (HR & Admin)
    elseif ($action === 'create_training') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        $code = trim($_POST['training_code'] ?? 'PLT-' . date('Ymd-His'));
        $name = trim($_POST['training_name'] ?? '');
        $startDate = trim($_POST['start_date'] ?? date('Y-m-d'));
        $endDate = trim($_POST['end_date'] ?? $startDate);
        $budget = (float)($_POST['budget'] ?? 0);

        try {
            if (empty($name)) {
                throw new Exception('Nama program pelatihan wajib diisi.');
            }

            $stmtIns = $db->prepare("
                INSERT INTO employee_trainings 
                (employee_id, training_code, training_name, start_date, end_date, budget, status, attendance_status, created_by, created_at)
                VALUES 
                (:eid, :code, :name, :sdt, :edt, :bg, 'Menunggu', 'Belum Dilaksanakan', :uid, NOW())
            ");
            $stmtIns->execute([
                ':eid'  => $empId,
                ':code' => $code,
                ':name' => $name,
                ':sdt'  => $startDate,
                ':edt'  => $endDate,
                ':bg'   => $budget,
                ':uid'  => $user['id'],
            ]);

            $newId = $db->lastInsertId();
            logAudit($user['id'], 'create_training', 'employee_trainings', $newId, null, [
                'employee_id' => $empId, 'name' => $name, 'budget' => $budget
            ]);

            setFlash('success', 'Usulan program pelatihan berhasil diajukan.');
        } catch (Exception $e) {
            setFlash('error', 'Gagal menambahkan usulan pelatihan: ' . $e->getMessage());
        }

        header('Location: index.php?page=hr_payroll/trainings');
        exit;
    }

    // 4. Update Evaluasi & Hasil Pelatihan (HR)
    elseif ($action === 'update_eval') {
        $idPelatihan = (int)($_POST['id_pelatihan'] ?? 0);
        $attStatus = trim($_POST['attendance_status'] ?? 'Hadir Penuh');
        $result = trim($_POST['result'] ?? '');
        $eval = trim($_POST['evaluation'] ?? '');

        try {
            $stmt = $db->prepare("
                UPDATE employee_trainings 
                SET attendance_status = :att, result = :res, evaluation = :eval, status = 'Selesai', updated_at = NOW() 
                WHERE id = :id
            ");
            $stmt->execute([
                ':att'  => $attStatus,
                ':res'  => $result,
                ':eval' => $eval,
                ':id'   => $idPelatihan,
            ]);

            logAudit($user['id'], 'update_training_eval', 'employee_trainings', $idPelatihan, null, [
                'attendance' => $attStatus, 'result' => $result
            ]);
            setFlash('success', 'Evaluasi hasil pelatihan berhasil diperbarui.');
        } catch (Exception $e) {
            setFlash('error', 'Gagal memperbarui evaluasi: ' . $e->getMessage());
        }

        header('Location: index.php?page=hr_payroll/trainings');
        exit;
    }
}

// -----------------------------------------------------------------------------
// DATA QUERY
// -----------------------------------------------------------------------------
$sql = "
    SELECT 
        p.*,
        k.full_name AS nama_karyawan,
        k.code AS kode_karyawan,
        d.name AS nama_departemen,
        pos.name AS nama_jabatan
    FROM employee_trainings p
    JOIN employees k ON p.employee_id = k.id
    LEFT JOIN departments d ON k.department_id = d.id
    LEFT JOIN positions pos ON k.position_id = pos.id
    ORDER BY CASE WHEN p.status = 'Menunggu' THEN 0 ELSE 1 END, p.start_date DESC, p.id DESC
";
$dataPelatihan = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$karyawanList = $db->query("SELECT id, code, full_name FROM employees WHERE status = 'active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$totalAnggaran = array_sum(array_column($dataPelatihan, 'budget'));
$menungguCount = count(array_filter($dataPelatihan, fn($p) => $p['status'] === 'Menunggu'));
$disetujuiCount = count(array_filter($dataPelatihan, fn($p) => in_array($p['status'], ['Disetujui', 'Selesai'])));

include __DIR__ . '/../../includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
    <div>
        <h1 style="font-size:20px; font-weight:700; margin:0 0 4px; color:#111827;">Persetujuan &amp; Program Pelatihan Karyawan</h1>
        <p style="margin:0; font-size:12px; color:#6b7280;">Pengembangan kompetensi, sertifikasi teknis, dan manajemen pelatihan PT Mayora Indah Tbk.</p>
    </div>
    <div>
        <button type="button" class="btn" style="background:#d80000; color:#fff;" onclick="openModal('modalTambahPelatihan')">
            + Usulkan Pelatihan
        </button>
    </div>
</div>

<!-- Stats Ringkas -->
<div class="stats" style="margin-bottom:16px;">
    <div class="card">
        <div class="lb">Total Program Pelatihan <em class="c3"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></em></div>
        <div class="val"><?= count($dataPelatihan) ?></div>
    </div>
    <div class="card">
        <div class="lb">Menunggu Otorisasi <em class="c2"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></em></div>
        <div class="val" style="color:<?= $menungguCount > 0 ? '#ea580c' : '#111827' ?>;"><?= $menungguCount ?></div>
    </div>
    <div class="card">
        <div class="lb">Disetujui / Terlaksana <em class="c4"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></em></div>
        <div class="val" style="color:#16a34a;"><?= $disetujuiCount ?></div>
    </div>
    <div class="card">
        <div class="lb">Total Anggaran Pelatihan <em class="c1"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></em></div>
        <div class="val" style="font-size:20px;"><?= formatRupiah($totalAnggaran) ?></div>
    </div>
</div>

<!-- Panel Tabel Utama -->
<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
        <h3 style="margin:2px 2px 8px; font-size:13px; font-weight:700;">Daftar Rencana &amp; Realisasi Pelatihan Karyawan</h3>
        <?php if ($menungguCount > 0): ?>
            <span class="bd wt"><?= $menungguCount ?> Menunggu Persetujuan</span>
        <?php endif; ?>
    </div>

    <div class="in" style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; min-width:800px;">
            <thead>
                <tr>
                    <th>Karyawan</th>
                    <th>Nama Program Pelatihan</th>
                    <th>Jadwal Pelaksanaan</th>
                    <th style="text-align:right;">Anggaran</th>
                    <th>Status Presensi / Hasil</th>
                    <th>Status Usulan</th>
                    <th style="text-align:center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dataPelatihan as $d): ?>
                <tr>
                    <td>
                        <b><?= e($d['nama_karyawan']) ?></b><br>
                        <small style="color:#6b7280;"><?= e($d['nama_departemen']) ?> &bull; <?= e($d['nama_jabatan']) ?></small>
                    </td>
                    <td>
                        <b style="color:#111827;"><?= e($d['training_name'] ?? $d['nama_pelatihan'] ?? '') ?></b><br>
                        <small style="color:#6b7280; font-family:monospace;"><?= e($d['training_code'] ?: '-') ?></small>
                    </td>
                    <td>
                        <?= e(date('d M Y', strtotime($d['start_date']))) ?>
                        <?php if (!empty($d['end_date']) && $d['end_date'] !== $d['start_date']): ?>
                            <br><small style="color:#6b7280;">s/d <?= e(date('d M Y', strtotime($d['end_date']))) ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right; font-weight:600; color:#111827;">
                        <?= formatRupiah($d['budget']) ?>
                    </td>
                    <td>
                        <span style="font-size:12px; color:#374151;"><?= e($d['attendance_status'] ?: 'Belum Terlaksana') ?></span>
                        <?php if (!empty($d['result'])): ?>
                            <br><small style="color:#16a34a; font-weight:600;"><?= e($d['result']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="bd <?= $d['status'] === 'Disetujui' || $d['status'] === 'Selesai' ? 'ok' : ($d['status'] === 'Ditolak' ? 'no' : 'wt') ?>">
                            <?= e($d['status']) ?>
                        </span>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($d['status'] === 'Menunggu'): ?>
                            <?php if (hasRole(['Administrator', 'admin', 'Supervisor / Manajer', 'manager', 'manajer'])): ?>
                                <div class="act-btns" style="justify-content:center;">
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Yakin ingin menyetujui program pelatihan dan alokasi anggaran ini?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="setujui">
                                        <input type="hidden" name="id_pelatihan" value="<?= $d['id'] ?>">
                                        <button type="submit" class="btn-sm btn-ok">Setujui</button>
                                    </form>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Yakin ingin menolak program pelatihan ini?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="tolak">
                                        <input type="hidden" name="id_pelatihan" value="<?= $d['id'] ?>">
                                        <button type="submit" class="btn-sm btn-no">Tolak</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <span style="color:#9ca3af; font-size:11px;">Menunggu Manajer</span>
                            <?php endif; ?>
                        <?php elseif ($d['status'] === 'Disetujui'): ?>
                            <button type="button" class="btn-outline btn-sm" onclick="bukaModalEval(<?= $d['id'] ?>, '<?= e(addslashes($d['training_name'] ?? $d['nama_pelatihan'] ?? '')) ?>', '<?= e(addslashes($d['attendance_status'] ?? '')) ?>', '<?= e(addslashes($d['result'] ?? '')) ?>')">
                                Input Evaluasi
                            </button>
                        <?php else: ?>
                            <span style="color:#9ca3af; font-size:11px;">Selesai Diproses</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($dataPelatihan)): ?>
                <tr>
                    <td colspan="7" style="text-align:center; padding:24px; color:#6b7280;">Belum ada rencana pelatihan karyawan.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Tambah Pelatihan -->
<div id="modalTambahPelatihan" class="modal-backdrop">
    <div class="modal-dialog" style="max-width:520px;">
        <div class="modal-header">
            <h3 class="modal-title">+ Usulan Program Pelatihan Baru</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalTambahPelatihan')">&times;</button>
        </div>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_training">
            <div class="modal-body">
                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label">Peserta Karyawan <span style="color:#dc2626;">*</span></label>
                    <select name="employee_id" class="form-control" required>
                        <option value="">-- Pilih Karyawan --</option>
                        <?php foreach ($karyawanList as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= e($k['code']) ?> - <?= e($k['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label">Kode Pelatihan</label>
                    <input type="text" name="training_code" class="form-control" value="PLT-<?= date('Ym') ?>-<?= rand(100, 999) ?>">
                </div>

                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label">Nama Program Pelatihan <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="training_name" class="form-control" required placeholder="Contoh: Sertifikasi K3 Umum / Pelatihan HACCP">
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div class="form-group">
                        <label class="form-label">Tanggal Mulai <span style="color:#dc2626;">*</span></label>
                        <input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal Selesai <span style="color:#dc2626;">*</span></label>
                        <input type="date" name="end_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Estimasi Anggaran (Rp) <span style="color:#dc2626;">*</span></label>
                    <input type="number" name="budget" class="form-control" value="0" min="0" step="50000" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalTambahPelatihan')">Batal</button>
                <button type="submit" class="btn" style="background:#d80000; color:#fff;">Kirim Usulan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Evaluasi Hasil Pelatihan -->
<div id="modalEvalPelatihan" class="modal-backdrop">
    <div class="modal-dialog" style="max-width:480px;">
        <div class="modal-header">
            <h3 class="modal-title">Input Evaluasi Pelatihan</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalEvalPelatihan')">&times;</button>
        </div>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_eval">
            <input type="hidden" name="id_pelatihan" id="eval_id_pelatihan">
            <div class="modal-body">
                <p id="eval_pelatihan_title" style="font-weight:600; font-size:13px; color:#111827; margin-bottom:12px;"></p>
                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label">Status Kehadiran</label>
                    <select name="attendance_status" id="eval_attendance" class="form-control">
                        <option value="Hadir Penuh">Hadir Penuh (100%)</option>
                        <option value="Hadir Sebagian">Hadir Sebagian</option>
                        <option value="Tidak Hadir">Tidak Hadir (Alpa / Izin)</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label">Hasil / Predikat Kelulusan</label>
                    <input type="text" name="result" id="eval_result" class="form-control" placeholder="Contoh: Lulus Kompeten Nilai A / Sertifikat Diterbitkan">
                </div>
                <div class="form-group">
                    <label class="form-label">Catatan Evaluasi Kinerja Pasca Pelatihan</label>
                    <textarea name="evaluation" class="form-control" rows="3" placeholder="Peningkatan keterampilan dan aplikasi teknis di lini kerja..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalEvalPelatihan')">Batal</button>
                <button type="submit" class="btn btn-success">Simpan Evaluasi</button>
            </div>
        </form>
    </div>
</div>

<script>
function bukaModalEval(id, nama, att, res) {
    document.getElementById('eval_id_pelatihan').value = id;
    document.getElementById('eval_pelatihan_title').innerText = nama;
    if (att) document.getElementById('eval_attendance').value = att;
    if (res) document.getElementById('eval_result').value = res;
    openModal('modalEvalPelatihan');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
