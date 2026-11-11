<?php
/**
 * Modul SDM & Penggajian: Verifikasi Daftar Gaji Karyawan
 * Sumber Fitur & Desain: SISP Mayora (reference/verifikasi_gaji.php)
 * Terintegrasi penuh dengan siklus Payroll, JournalService, dan RBAC PT Mayora Indah Tbk.
 */

$pageTitle = 'Verifikasi Daftar Gaji';
$breadcrumbCategory = 'SDM & Penggajian';

$db = getDbConnection();
$user = currentUser();

requireRole(['Administrator', 'admin', 'HR / Payroll', 'hr', 'Supervisor / Manajer', 'manager', 'manajer', 'Finance/Treasury', 'finance']);

// Ambil seluruh periode gaji
$semuaPeriode = $db->query("SELECT id, period_code, period_month, period_year, status FROM payroll_periods ORDER BY period_year DESC, period_month DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

$bulanIndo = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

$pid = isset($_GET['pid']) ? (int)$_GET['pid'] : (isset($_GET['period_id']) ? (int)$_GET['period_id'] : ($semuaPeriode[0]['id'] ?? 0));

// -----------------------------------------------------------------------------
// POST HANDLER: VERIFIKASI GAJI (SETUJUI, TOLAK, SETUJUI SEMUA)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $aksi = $_POST['aksi'] ?? $_POST['action'] ?? '';
    $idPenggajian = (int)($_POST['id_penggajian'] ?? $_POST['id_record'] ?? 0);
    $catatan = trim($_POST['catatan_verifikasi'] ?? $_POST['verification_notes'] ?? '');

    // 1. Setujui per Karyawan
    if ($aksi === 'setujui' || $aksi === 'approve') {
        try {
            $stmt = $db->prepare("
                UPDATE payroll_records 
                SET status = 'verified', verification_notes = NULL, updated_at = NOW() 
                WHERE id = :id AND status IN ('draft', 'calculated', 'rejected')
            ");
            $stmt->execute([':id' => $idPenggajian]);

            logAudit($user['id'], 'verify_salary_record', 'payroll_records', $idPenggajian, null, ['status' => 'verified']);
            setFlash('success', 'Rincian gaji karyawan berhasil diverifikasi dan disetujui.');
        } catch (Exception $e) {
            setFlash('error', 'Gagal memproses verifikasi gaji: ' . $e->getMessage());
        }

        header("Location: index.php?page=hr_payroll/salary_verification&pid={$pid}");
        exit;
    }

    // 2. Tolak per Karyawan (Kembalikan ke HR untuk diperbaiki)
    elseif ($aksi === 'tolak' || $aksi === 'reject') {
        if (empty($catatan)) {
            setFlash('error', 'Catatan alasan penolakan wajib diisi untuk memberi instruksi koreksi kepada HR.');
        } else {
            try {
                $stmt = $db->prepare("
                    UPDATE payroll_records 
                    SET status = 'rejected', verification_notes = :notes, updated_at = NOW() 
                    WHERE id = :id
                ");
                $stmt->execute([':notes' => $catatan, ':id' => $idPenggajian]);

                logAudit($user['id'], 'reject_salary_record', 'payroll_records', $idPenggajian, null, ['status' => 'rejected', 'notes' => $catatan]);
                setFlash('error', 'Data gaji ditolak dan dikembalikan ke Payroll untuk diperbaiki.');
            } catch (Exception $e) {
                setFlash('error', 'Gagal menolak gaji: ' . $e->getMessage());
            }
        }

        header("Location: index.php?page=hr_payroll/salary_verification&pid={$pid}");
        exit;
    }

    // 3. Setujui Semua Gaji yang Menunggu di Periode Ini
    elseif ($aksi === 'setujui_semua' || $aksi === 'approve_all') {
        try {
            $stmt = $db->prepare("
                UPDATE payroll_records 
                SET status = 'verified', verification_notes = NULL, updated_at = NOW() 
                WHERE payroll_period_id = :pid AND status IN ('draft', 'calculated', 'rejected')
            ");
            $stmt->execute([':pid' => $pid]);
            $affected = $stmt->rowCount();

            // Cek apakah seluruh baris gaji pada periode ini sudah terverifikasi
            $unverified = (int)$db->prepare("SELECT COUNT(*) FROM payroll_records WHERE payroll_period_id = :pid AND status != 'verified'")->execute([':pid' => $pid]);
            
            // Perbarui status periode menjadi review / pending_finance
            $db->prepare("UPDATE payroll_periods SET status = 'review', updated_at = NOW(), updated_by = :uid WHERE id = :pid")
               ->execute([':uid' => $user['id'], ':pid' => $pid]);

            logAudit($user['id'], 'verify_all_payroll_records', 'payroll_periods', $pid, null, ['verified_count' => $affected]);
            setFlash('success', "Seluruh ({$affected}) rincian gaji pada periode ini berhasil diverifikasi.");
        } catch (Exception $e) {
            setFlash('error', 'Gagal memproses verifikasi massal: ' . $e->getMessage());
        }

        header("Location: index.php?page=hr_payroll/salary_verification&pid={$pid}");
        exit;
    }
}

// -----------------------------------------------------------------------------
// DATA QUERY
// -----------------------------------------------------------------------------
$sqlGaji = "
    SELECT 
        pr.*,
        e.code AS employee_code,
        e.full_name AS employee_name,
        d.name AS department_name,
        p.name AS position_name
    FROM payroll_records pr
    JOIN employees e ON pr.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN positions p ON e.position_id = p.id
    WHERE pr.payroll_period_id = :pid
    ORDER BY e.full_name ASC
";
$stmtGaji = $db->prepare($sqlGaji);
$stmtGaji->execute([':pid' => $pid]);
$dataGaji = $stmtGaji->fetchAll(PDO::FETCH_ASSOC);

// Ringkasan Finansial
$totalKaryawan = count($dataGaji);
$totalKotor = array_sum(array_column($dataGaji, 'total_earnings'));
$totalBersih = array_sum(array_column($dataGaji, 'net_salary'));
$totalPotongan = array_sum(array_column($dataGaji, 'total_deductions'));
$menungguCount = count(array_filter($dataGaji, fn($g) => in_array($g['status'], ['calculated', 'draft'])));
$terverifikasiCount = count(array_filter($dataGaji, fn($g) => in_array($g['status'], ['verified', 'approved', 'transferred'])));
$ditolakCount = count(array_filter($dataGaji, fn($g) => $g['status'] === 'rejected'));

// Cari detail periode terpilih
$currentPeriod = null;
foreach ($semuaPeriode as $p) {
    if ((int)$p['id'] === $pid) {
        $currentPeriod = $p;
        break;
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
    <div>
        <h1 style="font-size:20px; font-weight:700; margin:0 0 4px; color:#111827;">Verifikasi Daftar Gaji Karyawan</h1>
        <p style="margin:0; font-size:12px; color:#6b7280;">Pemeriksaan keabsahan perhitungan upah kotor, lembur, potongan pajak &amp; BPJS sebelum diajukan ke Finance.</p>
    </div>
    <div>
        <a href="index.php?page=hr_payroll/approvals&period_id=<?= $pid ?>" class="btn-outline">
            &rarr; Buka Otorisasi &amp; Jurnal Finance
        </a>
    </div>
</div>

<!-- Filter Pemilihan Periode -->
<div style="margin-bottom:16px; background:#fff; padding:12px 14px; border:1px solid #e5e7eb; border-radius:8px; display:flex; align-items:center; gap:12px;">
    <form method="get" style="display:flex; align-items:center; gap:10px;">
        <input type="hidden" name="page" value="hr_payroll/salary_verification">
        <label style="font-weight:600; font-size:12px; color:#374151;">Pilih Periode Penggajian:</label>
        <select name="pid" onchange="this.form.submit()" style="padding:6px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:12px;">
            <?php foreach ($semuaPeriode as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $p['id'] == $pid ? 'selected' : '' ?>>
                    <?= e($p['period_code']) ?> - <?= e($bulanIndo[$p['period_month']] ?? '') ?> <?= e($p['period_year']) ?> (<?= strtoupper($p['status']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php if ($currentPeriod): ?>
        <span class="bd <?= in_array($currentPeriod['status'], ['approved', 'transferred']) ? 'ok' : 'wt' ?>">
            Status Periode: <?= strtoupper($currentPeriod['status']) ?>
        </span>
    <?php endif; ?>
</div>

<!-- Summary KPI Cards -->
<div class="stats" style="margin-bottom:16px;">
    <div class="card">
        <div class="lb">Total Karyawan Digaji <em class="c1"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></em></div>
        <div class="val"><?= number_format($totalKaryawan, 0, ',', '.') ?></div>
    </div>
    <div class="card">
        <div class="lb">Total Gaji Kotor (Bruto) <em class="c2"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg></em></div>
        <div class="val" style="color:#111827; font-size:20px;"><?= formatRupiah($totalKotor) ?></div>
    </div>
    <div class="card">
        <div class="lb">Total Potongan (PPh + BPJS) <em class="c1"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg></em></div>
        <div class="val" style="color:#dc2626; font-size:20px;"><?= formatRupiah($totalPotongan) ?></div>
    </div>
    <div class="card">
        <div class="lb">Total Gaji Bersih (Take Home) <em class="c4"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></em></div>
        <div class="val" style="color:#16a34a; font-size:20px;"><?= formatRupiah($totalBersih) ?></div>
    </div>
</div>

<!-- Panel Rincian Gaji -->
<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
        <h3 style="margin:0; font-size:13px; font-weight:700;">Rincian Daftar Gaji Karyawan</h3>
        <?php if ($menungguCount > 0 && hasRole(['Administrator', 'admin', 'Supervisor / Manajer', 'manager', 'manajer'])): ?>
            <form method="post" onsubmit="return confirm('Yakin ingin memverifikasi dan menyetujui SELURUH <?= $menungguCount ?> data gaji di periode ini?');">
                <?= csrfField() ?>
                <input type="hidden" name="aksi" value="setujui_semua">
                <button type="submit" class="btn-sm btn-ok" style="padding:7px 16px;">
                    &check; Setujui Semua (<?= $menungguCount ?>)
                </button>
            </form>
        <?php endif; ?>
    </div>

    <div class="in" style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; min-width:920px;">
            <thead>
                <tr>
                    <th>Karyawan</th>
                    <th>Departemen</th>
                    <th style="text-align:right;">Gaji Pokok</th>
                    <th style="text-align:right;">Gaji Kotor</th>
                    <th style="text-align:right;">Potongan</th>
                    <th style="text-align:right;">Gaji Bersih</th>
                    <th style="text-align:center;">Status</th>
                    <th style="text-align:center;">Aksi Verifikasi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dataGaji as $d): ?>
                <tr>
                    <td>
                        <b><?= e($d['employee_name']) ?></b><br>
                        <small style="color:#6b7280;"><?= e($d['employee_code']) ?> &bull; <?= e($d['position_name']) ?></small>
                    </td>
                    <td><?= e($d['department_name']) ?></td>
                    <td style="text-align:right;"><?= formatRupiah($d['base_salary']) ?></td>
                    <td style="text-align:right; font-weight:600; color:#111827;"><?= formatRupiah($d['total_earnings']) ?></td>
                    <td style="text-align:right; color:#dc2626;"><?= formatRupiah($d['total_deductions']) ?></td>
                    <td style="text-align:right; font-weight:700; color:#16a34a;"><?= formatRupiah($d['net_salary']) ?></td>
                    <td style="text-align:center;">
                        <span class="bd <?= in_array($d['status'], ['verified', 'approved', 'transferred']) ? 'ok' : ($d['status'] === 'rejected' ? 'no' : 'wt') ?>">
                            <?= strtoupper($d['status'] === 'calculated' ? 'Menunggu' : $d['status']) ?>
                        </span>
                        <?php if ($d['status'] === 'rejected' && !empty($d['verification_notes'])): ?>
                            <br><small style="color:#ef4444;"><?= e($d['verification_notes']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php if (in_array($d['status'], ['draft', 'calculated', 'rejected'])): ?>
                            <?php if (hasRole(['Administrator', 'admin', 'Supervisor / Manajer', 'manager', 'manajer'])): ?>
                                <div class="act-btns" style="justify-content:center;">
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Verifikasi gaji untuk <?= e(addslashes($d['employee_name'])) ?>?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="aksi" value="setujui">
                                        <input type="hidden" name="id_penggajian" value="<?= $d['id'] ?>">
                                        <button type="submit" class="btn-sm btn-ok">Setujui</button>
                                    </form>
                                    <button type="button" class="btn-sm btn-no" onclick="bukaModalTolakGaji(<?= $d['id'] ?>, '<?= e(addslashes($d['employee_name'])) ?>')">
                                        Tolak
                                    </button>
                                </div>
                            <?php else: ?>
                                <span style="color:#9ca3af; font-size:11px;">Menunggu Manajer</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#16a34a; font-size:11px; font-weight:600;">&check; Terverifikasi</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($dataGaji)): ?>
                <tr>
                    <td colspan="8" style="text-align:center; padding:24px; color:#6b7280;">
                        Belum ada perhitungan gaji pada periode ini. Silakan buat dan hitung gaji terlebih dahulu di modul <a href="index.php?page=hr_payroll/payroll" style="color:#d80000; font-weight:600;">Proses Payroll</a>.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Tolak Gaji Karyawan -->
<div id="modalTolakGaji" class="modal-backdrop">
    <div class="modal-dialog" style="max-width:440px;">
        <div class="modal-header">
            <h3 class="modal-title">Tolak Perhitungan Gaji</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalTolakGaji')">&times;</button>
        </div>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="aksi" value="tolak">
            <input type="hidden" name="id_penggajian" id="tolak_id_penggajian">
            <div class="modal-body">
                <p id="tolak_gaji_text" style="font-size:12px; margin-bottom:12px; color:#4b5563;"></p>
                <div class="form-group">
                    <label class="form-label" for="catatan_gaji">Alasan Penolakan (Wajib Diisi) <span style="color:#dc2626;">*</span></label>
                    <textarea name="catatan_verifikasi" id="catatan_gaji" class="form-control" rows="3" required placeholder="Jelaskan selisih jam lembur, potongan absen, atau ketidaksesuaian nominal..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalTolakGaji')">Batal</button>
                <button type="submit" class="btn btn-danger">Kembalikan ke HR</button>
            </div>
        </form>
    </div>
</div>

<script>
function bukaModalTolakGaji(id, nama) {
    document.getElementById('tolak_id_penggajian').value = id;
    document.getElementById('tolak_gaji_text').innerHTML = 'Apakah Anda yakin ingin menolak perhitungan gaji untuk <b>' + nama + '</b>? Berikan catatan instruksi koreksi.';
    openModal('modalTolakGaji');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
