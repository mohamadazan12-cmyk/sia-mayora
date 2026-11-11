<?php
/**
 * Modul SDM & Penggajian: Pemberhentian & Verifikasi Hak Akhir Karyawan
 * Sumber Fitur & Desain: SISP Mayora (reference/verifikasi_hak_akhir.php)
 * Terintegrasi penuh dengan RBAC, Clearance Aset, dan Master Karyawan SIA PT Mayora Indah Tbk.
 */

$pageTitle = 'Verifikasi Hak Akhir Karyawan';
$breadcrumbCategory = 'SDM & Penggajian';

$db = getDbConnection();
$user = currentUser();

requireRole(['Administrator', 'admin', 'HR / Payroll', 'hr', 'Supervisor / Manajer', 'manager', 'manajer', 'Finance/Treasury', 'finance']);

// -----------------------------------------------------------------------------
// POST HANDLER: APPROVE / REJECT / CREATE TERMINATION
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? $_POST['aksi'] ?? '';

    // 1. Setujui Hak Akhir Karyawan (Manager & Admin)
    if ($action === 'setujui' || $action === 'approve') {
        if (!hasRole(['Administrator', 'admin', 'Supervisor / Manajer', 'manager', 'manajer'])) {
            setFlash('error', 'Hanya Manajer SDM / Direksi atau Administrator yang berwenang menyetujui hak akhir karyawan.');
            header('Location: index.php?page=hr_payroll/terminations');
            exit;
        }

        $idTerm = (int)($_POST['id_pemberhentian'] ?? $_POST['id'] ?? 0);

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT * FROM employee_terminations WHERE id = :id AND status = 'Menunggu Persetujuan' FOR UPDATE");
            $stmt->execute([':id' => $idTerm]);
            $term = $stmt->fetch();

            if (!$term) {
                throw new Exception('Data pemberhentian tidak ditemukan atau sudah diproses.');
            }

            // 1. Update status pemberhentian menjadi Disetujui
            $stmtUpd = $db->prepare("
                UPDATE employee_terminations 
                SET status = 'Disetujui', decided_by = :uid, decided_at = NOW(), updated_at = NOW() 
                WHERE id = :id
            ");
            $stmtUpd->execute([':uid' => $user['id'], ':id' => $idTerm]);

            // 2. Update status karyawan menjadi resigned / inactive jika tanggal efektif sudah tiba
            $today = date('Y-m-d');
            if ($term['effective_date'] <= $today) {
                $db->prepare("UPDATE employees SET status = 'resigned', resign_date = :eff, updated_at = NOW(), updated_by = :uid WHERE id = :eid")
                   ->execute([':eff' => $term['effective_date'], ':uid' => $user['id'], ':eid' => $term['employee_id']]);
            }

            logAudit($user['id'], 'approve_termination', 'employee_terminations', $idTerm, null, [
                'status' => 'Disetujui', 'total_settlement' => $term['total_settlement']
            ]);

            $db->commit();
            setFlash('success', 'Hak akhir karyawan berhasil disetujui dan siap diproses pembayarannya oleh Finance.');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', 'Gagal menyetujui hak akhir: ' . $e->getMessage());
        }

        header('Location: index.php?page=hr_payroll/terminations');
        exit;
    }

    // 2. Tolak Hak Akhir
    elseif ($action === 'tolak' || $action === 'reject') {
        if (!hasRole(['Administrator', 'admin', 'Supervisor / Manajer', 'manager', 'manajer'])) {
            setFlash('error', 'Hanya Manajer SDM / Direksi atau Administrator yang berwenang menolak permohonan hak akhir.');
            header('Location: index.php?page=hr_payroll/terminations');
            exit;
        }

        $idTerm = (int)($_POST['id_pemberhentian'] ?? $_POST['id'] ?? 0);
        $catatan = trim($_POST['decision_notes'] ?? 'Ditolak untuk peninjauan kembali.');

        try {
            $stmt = $db->prepare("
                UPDATE employee_terminations 
                SET status = 'Ditolak', decision_notes = :notes, decided_by = :uid, decided_at = NOW(), updated_at = NOW() 
                WHERE id = :id AND status = 'Menunggu Persetujuan'
            ");
            $stmt->execute([':notes' => $catatan, ':uid' => $user['id'], ':id' => $idTerm]);

            logAudit($user['id'], 'reject_termination', 'employee_terminations', $idTerm, null, ['status' => 'Ditolak', 'notes' => $catatan]);
            setFlash('error', 'Usulan hak akhir karyawan telah ditolak.');
        } catch (Exception $e) {
            setFlash('error', 'Gagal menolak hak akhir: ' . $e->getMessage());
        }

        header('Location: index.php?page=hr_payroll/terminations');
        exit;
    }

    // 3. Tambah Usulan Pemberhentian Baru (HR)
    elseif ($action === 'create_termination') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        $type = trim($_POST['termination_type'] ?? 'Resign');
        $letterNum = trim($_POST['letter_number'] ?? '');
        $letterDate = trim($_POST['letter_date'] ?? date('Y-m-d'));
        $effDate = trim($_POST['effective_date'] ?? date('Y-m-d'));
        $assetNotes = trim($_POST['asset_clearance_notes'] ?? '');
        $clearanceDate = trim($_POST['clearance_date'] ?? date('Y-m-d'));
        $lastSalary = (float)($_POST['last_salary'] ?? 0);
        $severance = (float)($_POST['severance_amount'] ?? 0);
        $deductions = (float)($_POST['obligation_deductions'] ?? 0);
        $pph21 = (float)($_POST['pph21_amount'] ?? 0);
        $total = ($lastSalary + $severance) - ($deductions + $pph21);

        try {
            $stmtIns = $db->prepare("
                INSERT INTO employee_terminations 
                (employee_id, termination_type, letter_number, letter_date, effective_date, asset_clearance_notes, clearance_date, last_salary, severance_amount, obligation_deductions, pph21_amount, total_settlement, status, created_by, created_at)
                VALUES 
                (:eid, :type, :lnum, :ldt, :eff, :asset, :cldt, :last, :sev, :ob, :pph, :tot, 'Menunggu Persetujuan', :uid, NOW())
            ");
            $stmtIns->execute([
                ':eid'   => $empId,
                ':type'  => $type,
                ':lnum'  => $letterNum,
                ':ldt'   => $letterDate,
                ':eff'   => $effDate,
                ':asset' => $assetNotes,
                ':cldt'  => $clearanceDate,
                ':last'  => $lastSalary,
                ':sev'   => $severance,
                ':ob'    => $deductions,
                ':pph'   => $pph21,
                ':tot'   => $total,
                ':uid'   => $user['id'],
            ]);

            $newId = $db->lastInsertId();
            logAudit($user['id'], 'create_termination', 'employee_terminations', $newId, null, [
                'employee_id' => $empId, 'total_settlement' => $total
            ]);

            setFlash('success', 'Pengajuan hak akhir karyawan berhasil dibuat dan menunggu persetujuan Manajer.');
        } catch (Exception $e) {
            setFlash('error', 'Gagal membuat pengajuan hak akhir: ' . $e->getMessage());
        }

        header('Location: index.php?page=hr_payroll/terminations');
        exit;
    }
}

// -----------------------------------------------------------------------------
// DATA QUERY
// -----------------------------------------------------------------------------
$sqlTerm = "
    SELECT 
        t.*,
        k.full_name AS nama_karyawan,
        k.code AS kode_karyawan,
        d.name AS nama_departemen,
        pos.name AS nama_jabatan,
        u_dec.full_name AS pemutus
    FROM employee_terminations t
    JOIN employees k ON t.employee_id = k.id
    LEFT JOIN departments d ON k.department_id = d.id
    LEFT JOIN positions pos ON k.position_id = pos.id
    LEFT JOIN users u_dec ON t.decided_by = u_dec.id
    ORDER BY CASE WHEN t.status = 'Menunggu Persetujuan' THEN 0 ELSE 1 END, t.effective_date DESC, t.id DESC
";
$dataTerm = $db->query($sqlTerm)->fetchAll(PDO::FETCH_ASSOC);

$karyawanList = $db->query("SELECT id, code, full_name, base_salary FROM employees ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$totalKewajibanHak = array_sum(array_column($dataTerm, 'total_settlement'));
$menungguCount = count(array_filter($dataTerm, fn($t) => $t['status'] === 'Menunggu Persetujuan'));
$disetujuiCount = count(array_filter($dataTerm, fn($t) => in_array($t['status'], ['Disetujui', 'Dibayar'])));

include __DIR__ . '/../../includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
    <div>
        <h1 style="font-size:20px; font-weight:700; margin:0 0 4px; color:#111827;">Verifikasi Hak Akhir Karyawan</h1>
        <p style="margin:0; font-size:12px; color:#6b7280;">Pemeriksaan clearance aset inventaris, perhitungan pesangon pensiun / resign, dan otorisasi hak akhir.</p>
    </div>
    <div>
        <button type="button" class="btn" style="background:#d80000; color:#fff;" onclick="openModal('modalTambahPemberhentian')">
            + Pengajuan Hak Akhir
        </button>
    </div>
</div>

<!-- Stats Ringkas -->
<div class="stats" style="margin-bottom:16px;">
    <div class="card">
        <div class="lb">Total Berkas Pemberhentian <em class="c1"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7.5" r="4"/></svg></em></div>
        <div class="val"><?= count($dataTerm) ?></div>
    </div>
    <div class="card">
        <div class="lb">Menunggu Otorisasi <em class="c2"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></em></div>
        <div class="val" style="color:<?= $menungguCount > 0 ? '#ea580c' : '#111827' ?>;"><?= $menungguCount ?></div>
    </div>
    <div class="card">
        <div class="lb">Disetujui / Selesai <em class="c4"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></em></div>
        <div class="val" style="color:#16a34a;"><?= $disetujuiCount ?></div>
    </div>
    <div class="card">
        <div class="lb">Total Hak Akhir Terhitung <em class="c3"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></em></div>
        <div class="val" style="font-size:20px;"><?= formatRupiah($totalKewajibanHak) ?></div>
    </div>
</div>

<!-- Panel Tabel Utama -->
<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
        <h3 style="margin:2px 2px 8px; font-size:13px; font-weight:700;">Daftar Hak Akhir Karyawan (Pensiun / Resign / PHK)</h3>
        <?php if ($menungguCount > 0): ?>
            <span class="bd wt"><?= $menungguCount ?> Menunggu Persetujuan</span>
        <?php endif; ?>
    </div>

    <div class="in" style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; min-width:920px;">
            <thead>
                <tr>
                    <th>Karyawan</th>
                    <th>Jenis Berhenti</th>
                    <th>Tgl Efektif</th>
                    <th style="text-align:right;">Gaji Terakhir</th>
                    <th style="text-align:right;">Pesangon / Manfaat</th>
                    <th style="text-align:right;">Potongan / PPh 21</th>
                    <th style="text-align:right;">Total Hak Akhir</th>
                    <th>Status</th>
                    <th style="text-align:center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dataTerm as $d): ?>
                <tr>
                    <td>
                        <b><?= e($d['nama_karyawan']) ?></b><br>
                        <small style="color:#6b7280;"><?= e($d['nama_departemen']) ?> &bull; <?= e($d['kode_karyawan']) ?></small>
                        <?php if (!empty($d['asset_clearance_notes'])): ?>
                            <br><small style="color:#0284c7;">Clearance: <?= e($d['asset_clearance_notes']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="bd <?= $d['termination_type'] === 'Pensiun' ? 'ok' : 'wt' ?>">
                            <?= e($d['termination_type']) ?>
                        </span>
                        <?php if (!empty($d['letter_number'])): ?>
                            <br><small style="color:#6b7280;"><?= e($d['letter_number']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= e(date('d M Y', strtotime($d['effective_date']))) ?></td>
                    <td style="text-align:right;"><?= formatRupiah($d['last_salary']) ?></td>
                    <td style="text-align:right; font-weight:600; color:#16a34a;">+<?= formatRupiah($d['severance_amount']) ?></td>
                    <td style="text-align:right; color:#dc2626;">
                        -<?= formatRupiah($d['obligation_deductions']) ?><br>
                        <small>-<?= formatRupiah($d['pph21_amount']) ?> (PPh)</small>
                    </td>
                    <td style="text-align:right; font-weight:700; font-size:13px; color:#111827;">
                        <?= formatRupiah($d['total_settlement']) ?>
                    </td>
                    <td>
                        <span class="bd <?= $d['status'] === 'Disetujui' || $d['status'] === 'Dibayar' ? 'ok' : ($d['status'] === 'Ditolak' ? 'no' : 'wt') ?>">
                            <?= e($d['status']) ?>
                        </span>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($d['status'] === 'Menunggu Persetujuan'): ?>
                            <?php if (hasRole(['Administrator', 'admin', 'Supervisor / Manajer', 'manager', 'manajer'])): ?>
                                <div class="act-btns" style="justify-content:center;">
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Setujui pembayaran hak akhir untuk <?= e(addslashes($d['nama_karyawan'])) ?>?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="setujui">
                                        <input type="hidden" name="id_pemberhentian" value="<?= $d['id'] ?>">
                                        <button type="submit" class="btn-sm btn-ok">Setujui</button>
                                    </form>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Tolak permohonan hak akhir ini?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="tolak">
                                        <input type="hidden" name="id_pemberhentian" value="<?= $d['id'] ?>">
                                        <button type="submit" class="btn-sm btn-no">Tolak</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <span style="color:#9ca3af; font-size:11px;">Menunggu Manajer</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#9ca3af; font-size:11px;">Selesai Diproses</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($dataTerm)): ?>
                <tr>
                    <td colspan="9" style="text-align:center; padding:24px; color:#6b7280;">Belum ada pengajuan pemberhentian karyawan.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Tambah Pemberhentian -->
<div id="modalTambahPemberhentian" class="modal-backdrop">
    <div class="modal-dialog" style="max-width:540px;">
        <div class="modal-header">
            <h3 class="modal-title">+ Pengajuan Hak Akhir Karyawan Baru</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalTambahPemberhentian')">&times;</button>
        </div>
        <form method="post" id="formPemberhentian">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_termination">
            <div class="modal-body">
                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label">Pilih Karyawan <span style="color:#dc2626;">*</span></label>
                    <select name="employee_id" id="term_employee_id" class="form-control" required onchange="updateSalaryHelper(this)">
                        <option value="">-- Pilih Karyawan --</option>
                        <?php foreach ($karyawanList as $k): ?>
                            <option value="<?= $k['id'] ?>" data-salary="<?= $k['base_salary'] ?>"><?= e($k['code']) ?> - <?= e($k['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div class="form-group">
                        <label class="form-label">Jenis Pemberhentian <span style="color:#dc2626;">*</span></label>
                        <select name="termination_type" class="form-control" required>
                            <option value="Resign">Pengunduran Diri (Resign)</option>
                            <option value="Pensiun">Masa Persiapan Pensiun</option>
                            <option value="PHK">Pemutusan Hubungan Kerja (PHK)</option>
                            <option value="Selesai Kontrak">Habis Masa Kontrak</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal Efektif <span style="color:#dc2626;">*</span></label>
                        <input type="date" name="effective_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div class="form-group">
                        <label class="form-label">Nomor Surat Keputusan / Referensi</label>
                        <input type="text" name="letter_number" class="form-control" placeholder="Contoh: SR/HRD/2026/XI/001">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal Surat</label>
                        <input type="date" name="letter_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom:12px;">
                    <label class="form-label">Catatan Pengembalian Aset / Serah Terima</label>
                    <textarea name="asset_clearance_notes" class="form-control" rows="2" placeholder="ID Card, seragam, laptop, dokumen inventarisasi..."></textarea>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div class="form-group">
                        <label class="form-label">Gaji Terakhir / Prorata (Rp)</label>
                        <input type="number" name="last_salary" id="calc_last_salary" class="form-control" value="0" min="0" oninput="hitungTotalHak()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Pesangon / Penghargaan (Rp)</label>
                        <input type="number" name="severance_amount" id="calc_severance" class="form-control" value="0" min="0" oninput="hitungTotalHak()">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                    <div class="form-group">
                        <label class="form-label">Potongan Pinjaman / Kasbon (Rp)</label>
                        <input type="number" name="obligation_deductions" id="calc_deductions" class="form-control" value="0" min="0" oninput="hitungTotalHak()">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Potongan PPh 21 Final Pesangon (Rp)</label>
                        <input type="number" name="pph21_amount" id="calc_pph" class="form-control" value="0" min="0" oninput="hitungTotalHak()">
                    </div>
                </div>

                <div style="background:#f8fafc; padding:10px 14px; border:1px solid #e2e8f0; border-radius:6px; display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-weight:600; font-size:12px; color:#374151;">Estimasi Hak Akhir Bersih:</span>
                    <span id="preview_total_hak" style="font-weight:800; font-size:16px; color:#16a34a;">Rp 0</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalTambahPemberhentian')">Batal</button>
                <button type="submit" class="btn" style="background:#d80000; color:#fff;">Ajukan Hak Akhir</button>
            </div>
        </form>
    </div>
</div>

<script>
function updateSalaryHelper(selectEl) {
    let opt = selectEl.options[selectEl.selectedIndex];
    let sal = parseFloat(opt.getAttribute('data-salary') || 0);
    document.getElementById('calc_last_salary').value = sal;
    hitungTotalHak();
}

function hitungTotalHak() {
    let last = parseFloat(document.getElementById('calc_last_salary').value || 0);
    let sev = parseFloat(document.getElementById('calc_severance').value || 0);
    let ded = parseFloat(document.getElementById('calc_deductions').value || 0);
    let pph = parseFloat(document.getElementById('calc_pph').value || 0);
    let total = Math.max(0, (last + sev) - (ded + pph));
    document.getElementById('preview_total_hak').innerText = 'Rp ' + total.toLocaleString('id-ID');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
