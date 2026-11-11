<?php
/**
 * Modul SDM & Penggajian: Mutasi dan Promosi Karyawan
 * Sumber Fitur & Desain: SISP Mayora (reference/persetujuan_mutasi.php)
 * Terintegrasi penuh dengan RBAC, Audit Trail, dan Master Karyawan SIA PT Mayora Indah Tbk.
 */

$pageTitle = 'Persetujuan Mutasi & Promosi';
$breadcrumbCategory = 'SDM & Penggajian';

$db = getDbConnection();
$user = currentUser();

// Autorisasi: Admin, Manager, dan HR berhak melihat modul ini
requireRole(['Administrator', 'admin', 'HR / Payroll', 'hr', 'Supervisor / Manajer', 'manager', 'manajer']);

// -----------------------------------------------------------------------------
// POST HANDLER: APPROVE / REJECT / CREATE MUTATION
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? $_POST['aksi'] ?? '';

    // 1. Setujui Usulan Mutasi / Promosi (Hanya Admin dan Manajer/Direksi)
    if ($action === 'setujui' || $action === 'approve') {
        if (!hasRole(['Administrator', 'admin', 'Supervisor / Manajer', 'manager', 'manajer'])) {
            setFlash('error', 'Hanya Manajer SDM / Direksi atau Administrator yang berwenang menyetujui mutasi karyawan.');
            header('Location: index.php?page=hr_payroll/mutations');
            exit;
        }

        $idMutasi = (int)($_POST['id_mutasi'] ?? $_POST['id'] ?? 0);

        try {
            $db->beginTransaction();

            // Kunci baris mutasi untuk mencegah race condition
            $stmt = $db->prepare("SELECT * FROM employee_mutations WHERE id = :id AND status = 'Menunggu' FOR UPDATE");
            $stmt->execute([':id' => $idMutasi]);
            $usulan = $stmt->fetch();

            if (!$usulan) {
                throw new Exception('Usulan mutasi tidak ditemukan atau sudah diproses sebelumnya.');
            }

            // Ambil data karyawan saat ini
            $stmtEmp = $db->prepare("SELECT id, full_name, department_id, position_id FROM employees WHERE id = :id FOR UPDATE");
            $stmtEmp->execute([':id' => $usulan['employee_id']]);
            $emp = $stmtEmp->fetch();

            if (!$emp) {
                throw new Exception('Data karyawan master tidak ditemukan.');
            }

            // 1. Ubah status usulan mutasi menjadi Selesai
            $stmtUpdMut = $db->prepare("
                UPDATE employee_mutations 
                SET status = 'Selesai', decided_by = :uid, decided_at = NOW(), updated_at = NOW() 
                WHERE id = :id
            ");
            $stmtUpdMut->execute([':uid' => $user['id'], ':id' => $idMutasi]);

            // 2. Perbarui master data karyawan (departemen & jabatan baru)
            $stmtUpdEmp = $db->prepare("
                UPDATE employees 
                SET department_id = :dept_id, position_id = :pos_id, updated_at = NOW(), updated_by = :uid 
                WHERE id = :id
            ");
            $stmtUpdEmp->execute([
                ':dept_id' => $usulan['new_department_id'],
                ':pos_id'  => $usulan['new_position_id'],
                ':uid'     => $user['id'],
                ':id'      => $usulan['employee_id'],
            ]);

            // 3. Catat ke Audit Trail
            logAudit(
                $user['id'],
                'approve_mutation',
                'employee_mutations',
                $idMutasi,
                ['department_id' => $emp['department_id'], 'position_id' => $emp['position_id']],
                ['department_id' => $usulan['new_department_id'], 'position_id' => $usulan['new_position_id']]
            );

            $db->commit();
            setFlash('success', "Usulan {$usulan['mutation_type']} untuk {$emp['full_name']} berhasil disetujui, dan master personalia telah diperbarui.");
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', 'Gagal memproses persetujuan mutasi: ' . $e->getMessage());
        }

        header('Location: index.php?page=hr_payroll/mutations');
        exit;
    }

    // 2. Tolak Usulan Mutasi / Promosi
    elseif ($action === 'tolak' || $action === 'reject') {
        if (!hasRole(['Administrator', 'admin', 'Supervisor / Manajer', 'manager', 'manajer'])) {
            setFlash('error', 'Hanya Manajer SDM / Direksi atau Administrator yang berwenang menolak mutasi.');
            header('Location: index.php?page=hr_payroll/mutations');
            exit;
        }

        $idMutasi = (int)($_POST['id_mutasi'] ?? $_POST['id'] ?? 0);
        $alasanTolak = trim($_POST['decision_notes'] ?? $_POST['alasan_tolak'] ?? 'Ditolak oleh atasan.');

        try {
            $stmt = $db->prepare("UPDATE employee_mutations SET status = 'Ditolak', decision_notes = :notes, decided_by = :uid, decided_at = NOW(), updated_at = NOW() WHERE id = :id AND status = 'Menunggu'");
            $stmt->execute([':notes' => $alasanTolak, ':uid' => $user['id'], ':id' => $idMutasi]);

            logAudit($user['id'], 'reject_mutation', 'employee_mutations', $idMutasi, null, ['decision_notes' => $alasanTolak]);
            setFlash('error', 'Usulan mutasi/promosi telah ditolak.');
        } catch (Exception $e) {
            setFlash('error', 'Gagal menolak usulan mutasi: ' . $e->getMessage());
        }

        header('Location: index.php?page=hr_payroll/mutations');
        exit;
    }

    // 3. Pengajuan Usulan Mutasi Baru (HR atau Admin)
    elseif ($action === 'create_mutation') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        $type = trim($_POST['mutation_type'] ?? 'Mutasi');
        $newDeptId = (int)($_POST['new_department_id'] ?? 0);
        $newPosId = (int)($_POST['new_position_id'] ?? 0);
        $effDate = trim($_POST['effective_date'] ?? date('Y-m-d'));
        $skNumber = trim($_POST['sk_number'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        try {
            // Ambil posisi lama
            $stmtCur = $db->prepare("SELECT department_id, position_id FROM employees WHERE id = :id");
            $stmtCur->execute([':id' => $empId]);
            $cur = $stmtCur->fetch();

            if (!$cur) {
                throw new Exception('Karyawan yang dipilih tidak valid.');
            }

            $stmtIns = $db->prepare("
                INSERT INTO employee_mutations 
                (employee_id, mutation_type, old_department_id, old_position_id, new_department_id, new_position_id, effective_date, reason, status, sk_number, created_by, created_at)
                VALUES 
                (:eid, :type, :odept, :opos, :ndept, :npos, :eff, :reason, 'Menunggu', :sk, :uid, NOW())
            ");
            $stmtIns->execute([
                ':eid'    => $empId,
                ':type'   => $type,
                ':odept'  => $cur['department_id'],
                ':opos'   => $cur['position_id'],
                ':ndept'  => $newDeptId,
                ':npos'   => $newPosId,
                ':eff'    => $effDate,
                ':reason' => $reason,
                ':sk'     => $skNumber,
                ':uid'    => $user['id'],
            ]);

            $newId = $db->lastInsertId();
            logAudit($user['id'], 'create_mutation', 'employee_mutations', $newId, null, [
                'type' => $type, 'employee_id' => $empId, 'effective_date' => $effDate
            ]);

            setFlash('success', 'Usulan mutasi/promosi baru berhasil diajukan dan menunggu persetujuan Manajer.');
        } catch (Exception $e) {
            setFlash('error', 'Gagal mengajukan mutasi: ' . $e->getMessage());
        }

        header('Location: index.php?page=hr_payroll/mutations');
        exit;
    }
}

// -----------------------------------------------------------------------------
// DATA QUERY
// -----------------------------------------------------------------------------
// Ambil semua data usulan
$sql = "
    SELECT 
        m.*,
        k.full_name AS nama_karyawan,
        k.code AS kode_karyawan,
        d_old.name AS dep_lama,
        p_old.name AS jab_lama,
        d_new.name AS dep_baru,
        p_new.name AS jab_baru,
        u_dec.full_name AS pemutus
    FROM employee_mutations m
    JOIN employees k ON m.employee_id = k.id
    LEFT JOIN departments d_old ON m.old_department_id = d_old.id
    LEFT JOIN positions p_old ON m.old_position_id = p_old.id
    JOIN departments d_new ON m.new_department_id = d_new.id
    JOIN positions p_new ON m.new_position_id = p_new.id
    LEFT JOIN users u_dec ON m.decided_by = u_dec.id
    ORDER BY CASE WHEN m.status = 'Menunggu' THEN 0 ELSE 1 END, m.effective_date DESC, m.id DESC
";
$dataMutasi = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Data dropdown karyawan, departemen, dan jabatan untuk modal form
$karyawanList = $db->query("SELECT id, code, full_name FROM employees WHERE status = 'active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$deptList = $db->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$posList = $db->query("SELECT id, name FROM positions WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$menungguCount = count(array_filter($dataMutasi, fn($m) => $m['status'] === 'Menunggu'));

include __DIR__ . '/../../includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
    <div>
        <h1 style="font-size:20px; font-weight:700; margin:0 0 4px; color:#111827;">Persetujuan Mutasi &amp; Promosi</h1>
        <p style="margin:0; font-size:12px; color:#6b7280;">Daftar permohonan mutasi antar bagian, promosi jabatan, dan rotasi internal karyawan PT Mayora Indah Tbk.</p>
    </div>
    <div>
        <button type="button" class="btn" style="background:#d80000; color:#fff;" onclick="openModal('modalTambahMutasi')">
            + Usulkan Mutasi / Promosi
        </button>
    </div>
</div>

<!-- Stats Ringkas -->
<div class="stats" style="margin-bottom:16px;">
    <div class="card">
        <div class="lb">Total Usulan Diajukan <em class="c1"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7.5" r="4"/></svg></em></div>
        <div class="val"><?= count($dataMutasi) ?></div>
    </div>
    <div class="card">
        <div class="lb">Menunggu Otorisasi <em class="c2"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></em></div>
        <div class="val" style="color:<?= $menungguCount > 0 ? '#ea580c' : '#111827' ?>;"><?= $menungguCount ?></div>
    </div>
    <div class="card">
        <div class="lb">Disetujui / Selesai <em class="c4"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></em></div>
        <div class="val" style="color:#16a34a;"><?= count(array_filter($dataMutasi, fn($m) => $m['status'] === 'Selesai')) ?></div>
    </div>
    <div class="card">
        <div class="lb">Ditolak <em class="c1"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></em></div>
        <div class="val" style="color:#dc2626;"><?= count(array_filter($dataMutasi, fn($m) => $m['status'] === 'Ditolak')) ?></div>
    </div>
</div>

<!-- Panel Tabel Utama -->
<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
        <h3 style="margin:2px 2px 8px; font-size:13px; font-weight:700;">Daftar Usulan Mutasi &amp; Promosi</h3>
        <?php if ($menungguCount > 0): ?>
            <span class="bd wt"><?= $menungguCount ?> Menunggu Persetujuan</span>
        <?php endif; ?>
    </div>

    <div class="in" style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; min-width:850px;">
            <thead>
                <tr>
                    <th>Karyawan</th>
                    <th>Jenis</th>
                    <th>Perubahan Posisi (Lama &rarr; Baru)</th>
                    <th>Tgl Efektif</th>
                    <th>No. SK / Alasan</th>
                    <th>Status</th>
                    <th style="text-align:center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dataMutasi as $d): ?>
                <tr>
                    <td>
                        <b><?= e($d['nama_karyawan']) ?></b><br>
                        <small style="color:#6b7280;"><?= e($d['kode_karyawan']) ?></small>
                    </td>
                    <td>
                        <span class="bd <?= $d['mutation_type'] === 'Promosi' ? 'ok' : 'wt' ?>">
                            <?= e($d['mutation_type']) ?>
                        </span>
                    </td>
                    <td style="font-size:12px; line-height:1.4;">
                        <span style="color:#6b7280;">Jabatan:</span> <?= e($d['jab_lama'] ?: '-') ?> &rarr; <b style="color:#111827;"><?= e($d['jab_baru'] ?: '-') ?></b><br>
                        <span style="color:#6b7280;">Dept:</span> <?= e($d['dep_lama'] ?: '-') ?> &rarr; <b style="color:#111827;"><?= e($d['dep_baru'] ?: '-') ?></b>
                    </td>
                    <td><?= e(date('d M Y', strtotime($d['effective_date']))) ?></td>
                    <td>
                        <?php if (!empty($d['sk_number'])): ?>
                            <small style="font-weight:600; color:#374151; display:block;"><?= e($d['sk_number']) ?></small>
                        <?php endif; ?>
                        <span style="color:#6b7280; font-size:11px;"><?= e($d['reason'] ?: '-') ?></span>
                    </td>
                    <td>
                        <span class="bd <?= $d['status'] === 'Selesai' ? 'ok' : ($d['status'] === 'Ditolak' ? 'no' : 'wt') ?>">
                            <?= e($d['status']) ?>
                        </span>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($d['status'] === 'Menunggu'): ?>
                            <?php if (hasRole(['Administrator', 'admin', 'Supervisor / Manajer', 'manager', 'manajer'])): ?>
                                <div class="act-btns" style="justify-content:center;">
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Yakin ingin menyetujui mutasi karyawan ini? Master personalia akan langsung diperbarui.');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="setujui">
                                        <input type="hidden" name="id_mutasi" value="<?= $d['id'] ?>">
                                        <button type="submit" class="btn-sm btn-ok">Setujui</button>
                                    </form>
                                    <button type="button" class="btn-sm btn-no" onclick="bukaModalTolak(<?= $d['id'] ?>, '<?= e(addslashes($d['nama_karyawan'])) ?>')">Tolak</button>
                                </div>
                            <?php else: ?>
                                <span style="color:#9ca3af; font-size:11px;">Menunggu Manajer</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#9ca3af; font-size:11px;">Selesai Diproses</span>
                            <?php if (!empty($d['decision_notes'])): ?>
                                <br><small style="color:#ef4444;"><?= e($d['decision_notes']) ?></small>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($dataMutasi)): ?>
                <tr>
                    <td colspan="7" style="text-align:center; padding:24px; color:#6b7280;">Belum ada usulan mutasi atau promosi.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Tolak Usulan -->
<div id="modalTolakMutasi" class="modal-backdrop">
    <div class="modal-dialog" style="max-width:440px;">
        <div class="modal-header">
            <h3 class="modal-title">Tolak Usulan Mutasi</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalTolakMutasi')">&times;</button>
        </div>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="tolak">
            <input type="hidden" name="id_mutasi" id="tolak_id_mutasi">
            <div class="modal-body">
                <p id="tolak_karyawan_text" style="font-size:12px; margin-bottom:12px; color:#4b5563;"></p>
                <div class="form-group">
                    <label class="form-label" for="alasan_tolak">Alasan Penolakan <span style="color:#dc2626;">*</span></label>
                    <textarea name="decision_notes" id="alasan_tolak" class="form-control" rows="3" required placeholder="Tuliskan catatan alasan penolakan..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalTolakMutasi')">Batal</button>
                <button type="submit" class="btn btn-danger">Konfirmasi Tolak</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Tambah Usulan Mutasi / Promosi -->
<div id="modalTambahMutasi" class="modal-backdrop">
    <div class="modal-dialog" style="max-width:540px;">
        <div class="modal-header">
            <h3 class="modal-title">+ Pengajuan Mutasi / Promosi Baru</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalTambahMutasi')">&times;</button>
        </div>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_mutation">
            <div class="modal-body">
                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label">Pilih Karyawan <span style="color:#dc2626;">*</span></label>
                    <select name="employee_id" class="form-control" required>
                        <option value="">-- Pilih Karyawan --</option>
                        <?php foreach ($karyawanList as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= e($k['code']) ?> - <?= e($k['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label">Jenis Perubahan <span style="color:#dc2626;">*</span></label>
                    <select name="mutation_type" class="form-control" required>
                        <option value="Promosi">Promosi (Kenaikan Jabatan)</option>
                        <option value="Rotasi">Rotasi (Penyegaran Bagian)</option>
                        <option value="Mutasi">Mutasi Antar Departemen</option>
                        <option value="Demosi">Demosi</option>
                    </select>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div class="form-group">
                        <label class="form-label">Departemen Baru <span style="color:#dc2626;">*</span></label>
                        <select name="new_department_id" class="form-control" required>
                            <option value="">-- Departemen --</option>
                            <?php foreach ($deptList as $d): ?>
                                <option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Jabatan Baru <span style="color:#dc2626;">*</span></label>
                        <select name="new_position_id" class="form-control" required>
                            <option value="">-- Jabatan --</option>
                            <?php foreach ($posList as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div class="form-group">
                        <label class="form-label">Tanggal Efektif <span style="color:#dc2626;">*</span></label>
                        <input type="date" name="effective_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nomor Surat Keputusan (SK)</label>
                        <input type="text" name="sk_number" class="form-control" placeholder="Contoh: SK/DIR/2026/XI/...">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Alasan Pertimbangan <span style="color:#dc2626;">*</span></label>
                    <textarea name="reason" class="form-control" rows="2" required placeholder="Penilaian kinerja, evaluasi, atau kebutuhan operasional..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalTambahMutasi')">Batal</button>
                <button type="submit" class="btn" style="background:#d80000; color:#fff;">Ajukan Usulan</button>
            </div>
        </form>
    </div>
</div>

<script>
function bukaModalTolak(id, nama) {
    document.getElementById('tolak_id_mutasi').value = id;
    document.getElementById('tolak_karyawan_text').innerHTML = 'Apakah Anda yakin ingin menolak usulan mutasi untuk <b>' + nama + '</b>? Harap isi catatan alasan penolakan.';
    openModal('modalTolakMutasi');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
