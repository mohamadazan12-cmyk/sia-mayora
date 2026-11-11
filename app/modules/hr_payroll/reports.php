<?php
/**
 * Modul SDM & Penggajian: Laporan Biaya Tenaga Kerja, Pajak & BPJS
 * Sumber Fitur & Desain: SISP Mayora (reference/laporan.php)
 * Terintegrasi penuh dengan Buku Besar Akuntansi (JournalService), Ekspor CSV, dan Print Layout.
 */

$db = getDbConnection();
$user = currentUser();

requireRole(['Administrator', 'admin', 'HR / Payroll', 'hr', 'Supervisor / Manajer', 'manager', 'manajer', 'Finance/Treasury', 'finance']);

$bulanIndo = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

// Ambil seluruh periode gaji
$semuaPeriode = $db->query("SELECT id, period_code, period_month, period_year FROM payroll_periods ORDER BY period_year DESC, period_month DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

$pid = isset($_GET['pid']) ? (int)$_GET['pid'] : ($semuaPeriode[0]['id'] ?? 0);
$jenis = $_GET['jenis'] ?? 'biaya_tk';

$namaPeriode = 'Periode Tidak Diketahui';
foreach ($semuaPeriode as $p) {
    if ((int)$p['id'] === $pid) {
        $namaPeriode = ($bulanIndo[$p['period_month']] ?? '') . ' ' . $p['period_year'];
        break;
    }
}

// Persiapkan data berdasarkan jenis laporan
if ($jenis === 'biaya_tk') {
    $judulLaporan = "Laporan Biaya Tenaga Kerja ({$namaPeriode})";

    // Data rincian per karyawan
    $stmtRinci = $db->prepare("
        SELECT 
            e.full_name AS nama_karyawan,
            d.name AS departemen,
            pr.base_salary AS gaji_pokok,
            (pr.total_earnings - pr.base_salary - COALESCE(ot.amount, 0)) AS tunjangan,
            COALESCE(ot.amount, 0) AS uang_lembur,
            pr.total_earnings AS gaji_kotor
        FROM payroll_records pr
        JOIN employees e ON pr.employee_id = e.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN payroll_record_components ot ON ot.payroll_record_id = pr.id AND ot.payroll_component_id = 4
        WHERE pr.payroll_period_id = :pid
        ORDER BY e.full_name ASC
    ");
    $stmtRinci->execute([':pid' => $pid]);
    $dataRinci = $stmtRinci->fetchAll(PDO::FETCH_ASSOC);
    $headerCsv = ['Nama Karyawan', 'Departemen', 'Gaji Pokok', 'Tunjangan', 'Uang Lembur', 'Total Gaji Kotor'];

    // Ringkasan Jurnal Buku Besar (Akun Beban Gaji & Kas/Utang Gaji)
    $stmtJurnal = $db->prepare("
        SELECT a.code AS kode_akun, a.name AS nama_akun, SUM(jel.debit) AS debit, SUM(jel.credit) AS kredit
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        JOIN accounts a ON jel.account_id = a.id
        WHERE je.ref_type IN ('payroll_accrual', 'payroll_payment') AND je.ref_id = :pid
        GROUP BY a.id, a.code, a.name
    ");
    $stmtJurnal->execute([':pid' => $pid]);
    $dataJurnal = $stmtJurnal->fetchAll(PDO::FETCH_ASSOC);

} else {
    $judulLaporan = "Laporan Kewajiban Pajak dan BPJS ({$namaPeriode})";

    // Data rincian per karyawan
    $stmtRinci = $db->prepare("
        SELECT 
            e.full_name AS nama_karyawan,
            d.name AS departemen,
            COALESCE(pph.amount, 0) AS pph21,
            COALESCE(tk.amount, 0) AS bpjs_tk,
            COALESCE(kes.amount, 0) AS bpjs_kes,
            pr.total_deductions AS total_potongan
        FROM payroll_records pr
        JOIN employees e ON pr.employee_id = e.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN payroll_record_components pph ON pph.payroll_record_id = pr.id AND pph.payroll_component_id = 8
        LEFT JOIN payroll_record_components tk ON tk.payroll_record_id = pr.id AND tk.payroll_component_id = 6
        LEFT JOIN payroll_record_components kes ON kes.payroll_record_id = pr.id AND kes.payroll_component_id = 7
        WHERE pr.payroll_period_id = :pid
        ORDER BY e.full_name ASC
    ");
    $stmtRinci->execute([':pid' => $pid]);
    $dataRinci = $stmtRinci->fetchAll(PDO::FETCH_ASSOC);
    $headerCsv = ['Nama Karyawan', 'Departemen', 'PPh Pasal 21', 'BPJS Ketenagakerjaan', 'BPJS Kesehatan', 'Total Kewajiban Karyawan'];

    // Ringkasan Jurnal Liabilitas Pajak & BPJS
    $stmtJurnal = $db->prepare("
        SELECT a.code AS kode_akun, a.name AS nama_akun, SUM(jel.debit) AS debit, SUM(jel.credit) AS kredit
        FROM journal_entry_lines jel
        JOIN journal_entries je ON jel.journal_entry_id = je.id
        JOIN accounts a ON jel.account_id = a.id
        WHERE je.ref_type IN ('payroll_accrual', 'payroll_payment') AND je.ref_id = :pid
        GROUP BY a.id, a.code, a.name
    ");
    $stmtJurnal->execute([':pid' => $pid]);
    $dataJurnal = $stmtJurnal->fetchAll(PDO::FETCH_ASSOC);
}

// -----------------------------------------------------------------------------
// FITUR EKSPOR CSV (Sesuai reference/laporan.php)
// -----------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan_' . $jenis . '_' . str_replace(' ', '_', strtolower($namaPeriode)) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headerCsv);
    foreach ($dataRinci as $row) {
        fputcsv($out, array_values($row));
    }
    fclose($out);
    exit;
}

$pageTitle = 'Laporan SDM & Penggajian';
$breadcrumbCategory = 'SDM & Penggajian';

include __DIR__ . '/../../includes/header.php';
?>

<!-- Form Filter & Aksi Ekspor -->
<div class="form-filter" style="margin-bottom:16px; background:#fff; padding:14px 16px; border-radius:8px; border:1px solid #e5e7eb;">
    <form method="get" style="display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end;">
        <input type="hidden" name="page" value="hr_payroll/reports">
        <div>
            <label style="font-weight:600; font-size:12px; display:block; margin-bottom:5px; color:#374151;">Jenis Laporan:</label>
            <select name="jenis" style="padding:7px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:12px; min-width:180px;">
                <option value="biaya_tk" <?= $jenis === 'biaya_tk' ? 'selected' : '' ?>>Biaya Tenaga Kerja</option>
                <option value="kewajiban" <?= $jenis === 'kewajiban' ? 'selected' : '' ?>>Kewajiban Pajak &amp; BPJS</option>
            </select>
        </div>

        <div>
            <label style="font-weight:600; font-size:12px; display:block; margin-bottom:5px; color:#374151;">Periode Gaji:</label>
            <select name="pid" style="padding:7px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:12px; min-width:160px;">
                <?php foreach ($semuaPeriode as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $p['id'] == $pid ? 'selected' : '' ?>>
                        <?= e($bulanIndo[$p['period_month']] ?? '') ?> <?= e($p['period_year']) ?> (<?= e($p['period_code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <button type="submit" class="btn" style="background:#d80000; color:#fff; height:34px; padding:0 16px;">
                Tampilkan Laporan
            </button>
        </div>
    </form>
</div>

<!-- Container Laporan Utama -->
<div class="panel">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; border-bottom:1px solid #e5e7eb; padding-bottom:12px; flex-wrap:wrap; gap:10px;">
        <div>
            <h2 style="margin:0; font-size:16px; font-weight:700; color:#111827;"><?= e($judulLaporan) ?></h2>
            <small style="color:#6b7280;">PT Mayora Indah Tbk &bull; Sistem Informasi Akuntansi &amp; Operasional</small>
        </div>
        <div class="act-btns">
            <!-- Tombol Cetak / PDF via Browser -->
            <button type="button" onclick="window.print()" class="btn-outline">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                Cetak Laporan
            </button>

            <!-- Tombol Ekspor CSV -->
            <a href="index.php?page=hr_payroll/reports&jenis=<?= $jenis ?>&pid=<?= $pid ?>&export=csv" class="btn-outline">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                Unduh CSV
            </a>
        </div>
    </div>

    <!-- Ringkasan Buku Besar Akuntansi (Jika ada posting jurnal) -->
    <?php if (!empty($dataJurnal)): ?>
    <div style="margin-bottom:20px; background:#f9fafb; padding:12px; border-radius:6px; border:1px solid #e5e7eb;">
        <h4 style="margin:0 0 8px; font-size:12.5px; font-weight:700; color:#374151;">Ringkasan Buku Besar Jurnal (General Ledger)</h4>
        <table style="width:100%; max-width:650px; border-collapse:collapse; font-size:12px;">
            <thead>
                <tr style="background:#f3f4f6;">
                    <th style="padding:6px 10px;">Kode Akun</th>
                    <th style="padding:6px 10px;">Nama Rekening Akuntansi</th>
                    <th style="padding:6px 10px; text-align:right;">Debit</th>
                    <th style="padding:6px 10px; text-align:right;">Kredit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dataJurnal as $j): ?>
                <tr>
                    <td style="padding:6px 10px; font-family:monospace;"><?= e($j['kode_akun']) ?></td>
                    <td style="padding:6px 10px;"><?= e($j['nama_akun']) ?></td>
                    <td style="padding:6px 10px; text-align:right; font-weight:600; color:<?= $j['debit'] > 0 ? '#16a34a' : '#6b7280' ?>;">
                        <?= $j['debit'] > 0 ? formatRupiah($j['debit']) : '-' ?>
                    </td>
                    <td style="padding:6px 10px; text-align:right; font-weight:600; color:<?= $j['kredit'] > 0 ? '#dc2626' : '#6b7280' ?>;">
                        <?= $j['kredit'] > 0 ? formatRupiah($j['kredit']) : '-' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Rincian Data Karyawan -->
    <h4 style="margin:14px 2px 8px; font-size:13px; font-weight:700;">Rincian Gaji &amp; Potongan per Karyawan</h4>
    <div class="in" style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; min-width:850px;">
            <thead>
                <tr>
                    <?php foreach ($headerCsv as $idx => $h): ?>
                        <th style="<?= $idx > 1 ? 'text-align:right;' : '' ?>"><?= e($h) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php 
                $grandTotal = 0;
                foreach ($dataRinci as $row): 
                    $vals = array_values($row);
                    $lastVal = end($vals);
                    $grandTotal += (float)$lastVal;
                ?>
                <tr>
                    <td><b><?= e($vals[0]) ?></b></td>
                    <td><?= e($vals[1]) ?></td>
                    <?php for ($i = 2; $i < count($vals); $i++): ?>
                        <td style="text-align:right; font-weight:<?= $i === count($vals) - 1 ? '700' : 'normal' ?>;">
                            <?= formatRupiah($vals[$i]) ?>
                        </td>
                    <?php endfor; ?>
                </tr>
                <?php endforeach; ?>
                <?php if (!empty($dataRinci)): ?>
                <tr style="background:#f8fafc; font-weight:800; border-top:2px solid #e5e7eb;">
                    <td colspan="<?= count($headerCsv) - 1 ?>" style="text-align:right; padding:10px 12px;">TOTAL KESELURUHAN:</td>
                    <td style="text-align:right; padding:10px 12px; color:#111827; font-size:13px;">
                        <?= formatRupiah($grandTotal) ?>
                    </td>
                </tr>
                <?php else: ?>
                <tr>
                    <td colspan="<?= count($headerCsv) ?>" style="text-align:center; padding:24px; color:#6b7280;">
                        Tidak ada data rincian penggajian untuk periode terpilih.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
