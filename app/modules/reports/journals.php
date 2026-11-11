<?php
/**
 * Modul Laporan & Audit: Buku Jurnal Umum Internal (General Journal Ledger)
 * Menampilkan pencatatan akuntansi double-entry berimbang, verifikasi debit-kredit,
 * dan fitur pembalikan jurnal (reversal voucher).
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Jurnal Umum Internal & Double-Entry';
$breadcrumbCategory = 'Laporan & Audit';

$db = getDbConnection();
$user = currentUser();

// Handle Reversal Action (Finance & Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reverse') {
    requireRole(['Administrator', 'admin', 'Finance/Treasury', 'finance', 'Supervisor/Manajer', 'manajer']);
    requireCsrf();

    $journalId = (int)($_POST['journal_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? 'Pembalikan transaksi pembatalan/koreksi');

    if ($journalId <= 0) {
        setFlash('error', 'ID Jurnal tidak valid.');
        header('Location: index.php?page=reports/journals');
        exit;
    }

    try {
        $reversalId = JournalService::reverseEntry($journalId, $reason, $user['id'], $db);
        setFlash('success', 'Jurnal berhasil dibalik! Reversal voucher telah diterbitkan otomatis.');
        header('Location: index.php?page=reports/journals');
        exit;
    } catch (Exception $e) {
        setFlash('error', 'Gagal membalik jurnal: ' . $e->getMessage());
        header('Location: index.php?page=reports/journals');
        exit;
    }
}

// Filter Parameters
$startDate = trim($_GET['start_date'] ?? date('Y-m-01'));
$endDate = trim($_GET['end_date'] ?? date('Y-m-t'));
$refTypeFilter = trim($_GET['ref_type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$search = trim($_GET['q'] ?? '');

$where = ["1=1"];
$params = [];

if ($startDate !== '') {
    $where[] = "je.entry_date >= :start_date";
    $params[':start_date'] = $startDate;
}
if ($endDate !== '') {
    $where[] = "je.entry_date <= :end_date";
    $params[':end_date'] = $endDate;
}
if ($refTypeFilter !== '') {
    $where[] = "je.ref_type = :ref_type";
    $params[':ref_type'] = $refTypeFilter;
}
if ($statusFilter !== '') {
    $where[] = "je.status = :status";
    $params[':status'] = $statusFilter;
}
if ($search !== '') {
    $where[] = "(je.entry_number LIKE :q OR je.ref_number LIKE :q OR je.description LIKE :q)";
    $params[':q'] = "%{$search}%";
}

$whereSql = implode(' AND ', $where);

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmtExport = $db->prepare("
        SELECT je.entry_number, je.entry_date, je.ref_type, je.ref_number, je.description AS header_desc,
               je.status, a.code AS account_code, a.name AS account_name,
               jel.description AS line_desc, jel.debit, jel.credit
        FROM journal_entries je
        JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
        JOIN accounts a ON jel.account_id = a.id
        WHERE {$whereSql}
        ORDER BY je.entry_date DESC, je.id DESC, jel.id ASC
    ");
    $stmtExport->execute($params);
    $rows = $stmtExport->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=jurnal_umum_' . date('Ymd_His') . '.csv');

    $fp = fopen('php://output', 'w');
    fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($fp, ['No. Jurnal', 'Tanggal', 'Tipe Referensi', 'No. Referensi', 'Keterangan Jurnal', 'Status', 'Kode Akun', 'Nama Akun', 'Keterangan Baris', 'Debit', 'Kredit']);

    foreach ($rows as $r) {
        fputcsv($fp, [
            $r['entry_number'],
            formatDate($r['entry_date']),
            $r['ref_type'],
            $r['ref_number'] ?: '-',
            $r['header_desc'],
            strtoupper($r['status']),
            $r['account_code'],
            $r['account_name'],
            $r['line_desc'],
            $r['debit'],
            $r['credit']
        ]);
    }
    fclose($fp);
    exit;
}

// Summary Metrics
$stmtMetrics = $db->prepare("
    SELECT 
        COUNT(DISTINCT je.id) AS total_entries,
        COALESCE(SUM(je.total_debit), 0) AS total_debit,
        COALESCE(SUM(je.total_credit), 0) AS total_credit
    FROM journal_entries je
    WHERE {$whereSql}
");
$stmtMetrics->execute($params);
$metrics = $stmtMetrics->fetch();

$totalDebitSum = (float)$metrics['total_debit'];
$totalCreditSum = (float)$metrics['total_credit'];
$isBalanced = (abs($totalDebitSum - $totalCreditSum) <= 0.01);

// Pagination
$stmtCount = $db->prepare("SELECT COUNT(DISTINCT je.id) FROM journal_entries je WHERE {$whereSql}");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();

$currentPage = (int)($_GET['p'] ?? 1);
$perPage = 10;
$pagination = paginate($totalRows, $currentPage, $perPage);

// Fetch Journal Headers
$stmtHeaders = $db->prepare("
    SELECT je.*, u.full_name AS creator_name, p_u.full_name AS poster_name
    FROM journal_entries je
    LEFT JOIN users u ON je.created_by = u.id
    LEFT JOIN users p_u ON je.posted_by = p_u.id
    WHERE {$whereSql}
    ORDER BY je.entry_date DESC, je.id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmtHeaders->bindValue($k, $v);
}
$stmtHeaders->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$stmtHeaders->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmtHeaders->execute();
$journals = $stmtHeaders->fetchAll();

// Fetch lines for current page vouchers
$journalIds = array_column($journals, 'id');
$journalLinesMap = [];

if (!empty($journalIds)) {
    $inPlaceholders = implode(',', array_fill(0, count($journalIds), '?'));
    $stmtLines = $db->prepare("
        SELECT jel.*, a.code AS account_code, a.name AS account_name, a.type AS account_type
        FROM journal_entry_lines jel
        JOIN accounts a ON jel.account_id = a.id
        WHERE jel.journal_entry_id IN ({$inPlaceholders})
        ORDER BY jel.journal_entry_id ASC, jel.id ASC
    ");
    $stmtLines->execute($journalIds);
    $allLines = $stmtLines->fetchAll();

    foreach ($allLines as $line) {
        $journalLinesMap[$line['journal_entry_id']][] = $line;
    }
}

// Distinct ref types for filter
$refTypes = $db->query("SELECT DISTINCT ref_type FROM journal_entries WHERE ref_type IS NOT NULL AND ref_type != '' ORDER BY ref_type ASC")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Jurnal Umum Internal &amp; Double-Entry</h1>
        <div class="page-subtitle">Pencatatan Keuangan &bull; Buku Jurnal Umum Otomatis, Rekonsiliasi Keseimbangan Debit/Kredit, dan Reversal Voucher</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=reports/index" class="btn btn-secondary">
            📑 Laporan Operasional
        </a>
        <a href="index.php?page=reports/audit" class="btn btn-secondary">
            🔍 Jejak Audit
        </a>
        <a href="index.php?page=reports/journals&export=csv<?= $startDate ? '&start_date='.$startDate : '' ?><?= $endDate ? '&end_date='.$endDate : '' ?><?= $refTypeFilter ? '&ref_type='.$refTypeFilter : '' ?><?= $statusFilter ? '&status='.$statusFilter : '' ?><?= $search ? '&q='.urlencode($search) : '' ?>" class="btn btn-secondary">
            📥 Unduh Jurnal (CSV)
        </a>
    </div>
</div>

<?= renderFlashMessages() ?>

<!-- Summary Cards -->
<div class="metrics-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
    <div class="metric-card">
        <div class="metric-value"><?= formatNumber($metrics['total_entries'] ?? 0, 0) ?> Voucher</div>
        <div class="metric-label">Jumlah Dokumen Jurnal</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#0284c7;"><?= formatRupiah($totalDebitSum) ?></div>
        <div class="metric-label">Total Debit Terakumulasi</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#0284c7;"><?= formatRupiah($totalCreditSum) ?></div>
        <div class="metric-label">Total Kredit Terakumulasi</div>
    </div>
    <div class="metric-card" style="border: <?= $isBalanced ? '1px solid #bbf7d0; background:#f0fdf4;' : '1px solid #fecaca; background:#fef2f2;' ?>">
        <div class="metric-value" style="color: <?= $isBalanced ? '#16a34a' : '#dc2626' ?>;">
            <?= $isBalanced ? '✓ BERIMBANG' : '✗ TIDAK SEIMBANG' ?>
        </div>
        <div class="metric-label">
            <?= $isBalanced ? 'Double-Entry Terverifikasi Pas' : 'Selisih: ' . formatRupiah(abs($totalDebitSum - $totalCreditSum)) ?>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card mb-4">
    <div style="padding: 15px 20px;">
        <form action="index.php" method="GET" class="filter-bar" style="margin-bottom:0; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
            <input type="hidden" name="page" value="reports/journals">

            <div class="filter-group">
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari No. Jurnal / Keterangan..." style="min-width:220px;">
            </div>

            <div class="filter-group">
                <label style="font-size:12px; margin-right:4px; color:#64748b;">Dari:</label>
                <input type="date" name="start_date" value="<?= e($startDate) ?>">
            </div>

            <div class="filter-group">
                <label style="font-size:12px; margin-right:4px; color:#64748b;">Sampai:</label>
                <input type="date" name="end_date" value="<?= e($endDate) ?>">
            </div>

            <div class="filter-group">
                <select name="ref_type">
                    <option value="">-- Semua Siklus / Ref --</option>
                    <?php foreach ($refTypes as $rt): ?>
                        <option value="<?= e($rt) ?>" <?= $refTypeFilter === $rt ? 'selected' : '' ?>>
                            <?= e(strtoupper(str_replace('_', ' ', $rt))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <select name="status">
                    <option value="">-- Semua Status --</option>
                    <option value="posted" <?= $statusFilter === 'posted' ? 'selected' : '' ?>>Posted</option>
                    <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="reversed" <?= $statusFilter === 'reversed' ? 'selected' : '' ?>>Reversed</option>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary btn-sm">Saring Data</button>

            <?php if ($search !== '' || $refTypeFilter !== '' || $statusFilter !== '' || $startDate !== date('Y-m-01') || $endDate !== date('Y-m-t')): ?>
                <a href="index.php?page=reports/journals" class="btn btn-secondary btn-sm">Reset</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Journal Vouchers List -->
<?php if (empty($journals)): ?>
    <div class="card p-4 text-center" style="padding:40px; color:#94a3b8;">
        Belum ada voucher jurnal umum yang tercatat pada rentang filter ini.
    </div>
<?php else: ?>
    <?php foreach ($journals as $j): ?>
        <?php
        $lines = $journalLinesMap[$j['id']] ?? [];
        $headerBalanced = (abs((float)$j['total_debit'] - (float)$j['total_credit']) <= 0.01);
        ?>
        <div class="card mb-4">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; background:#f8fafc;">
                <div>
                    <span style="font-family:monospace; font-weight:700; font-size:15px; color:#0284c7;"><?= e($j['entry_number']) ?></span>
                    <span style="margin-left:10px; font-size:13px; color:#64748b;">Tanggal: <strong><?= formatDate($j['entry_date']) ?></strong></span>
                    <span style="margin-left:10px;" class="badge badge-secondary"><?= e(strtoupper(str_replace('_', ' ', $j['ref_type'] ?: 'Manual'))) ?></span>
                    <?php if ($j['ref_number']): ?>
                        <span style="margin-left:6px; font-family:monospace; font-size:12px; color:#475569;">Ref: <?= e($j['ref_number']) ?></span>
                    <?php endif; ?>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <?= getStatusBadge($j['status']) ?>
                    <?php if ($j['status'] === 'posted' && hasRole(['Administrator', 'admin', 'Finance/Treasury', 'finance', 'Supervisor/Manajer', 'manajer'])): ?>
                        <button type="button" class="btn btn-danger btn-sm" onclick="openModal('modal-rev-jnl-<?= $j['id'] ?>')">
                            ↺ Balik Jurnal
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Description -->
            <div style="padding:10px 20px; font-size:13px; color:#334155; border-bottom:1px solid #f1f5f9; background:#fff;">
                <strong>Deskripsi / Memo:</strong> <?= e($j['description']) ?>
                <span style="float:right; font-size:12px; color:#94a3b8;">
                    Dibuat oleh: <?= e($j['creator_name'] ?: 'Sistem') ?> &bull; <?= formatDateTime($j['created_at']) ?>
                </span>
            </div>

            <!-- Double-entry lines table -->
            <div class="card-body" style="padding:0;">
                <div class="table-responsive">
                    <table class="table" style="margin-bottom:0;">
                        <thead>
                            <tr style="background:#fafafa;">
                                <th style="width:130px;">Kode Akun</th>
                                <th style="width:240px;">Nama Akun Buku Besar</th>
                                <th>Keterangan Baris Transaksi</th>
                                <th style="width:160px; text-align:right;">Debit (Dr)</th>
                                <th style="width:160px; text-align:right;">Kredit (Cr)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lines as $ln): ?>
                                <tr>
                                    <td style="font-family:monospace; font-weight:600; color:#1e293b;">
                                        <?= e($ln['account_code']) ?>
                                    </td>
                                    <td>
                                        <span style="font-weight:<?= (float)$ln['debit'] > 0 ? '600' : 'normal' ?>; padding-left:<?= (float)$ln['credit'] > 0 ? '20px' : '0' ?>;">
                                            <?= e($ln['account_name']) ?>
                                        </span>
                                    </td>
                                    <td style="font-size:12.5px; color:#64748b;">
                                        <?= e($ln['description'] ?: '-') ?>
                                    </td>
                                    <td style="text-align:right; font-weight:<?= (float)$ln['debit'] > 0 ? '600' : 'normal' ?>;">
                                        <?= (float)$ln['debit'] > 0 ? formatRupiah($ln['debit']) : '-' ?>
                                    </td>
                                    <td style="text-align:right; font-weight:<?= (float)$ln['credit'] > 0 ? '600' : 'normal' ?>;">
                                        <?= (float)$ln['credit'] > 0 ? formatRupiah($ln['credit']) : '-' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:#f8fafc; font-weight:bold; border-top:2px solid #e2e8f0;">
                                <td colspan="3" style="text-align:right;">TOTAL ENTRY:</td>
                                <td style="text-align:right; color:#1e293b;"><?= formatRupiah($j['total_debit']) ?></td>
                                <td style="text-align:right; color:#1e293b;"><?= formatRupiah($j['total_credit']) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($pagination['total_pages'] > 1): ?>
        <div class="card-footer" style="padding:15px 20px;">
            <?php
            $baseUrl = 'index.php?page=reports/journals' .
                ($startDate ? '&start_date='.$startDate : '') .
                ($endDate ? '&end_date='.$endDate : '') .
                ($refTypeFilter ? '&ref_type='.$refTypeFilter : '') .
                ($statusFilter ? '&status='.$statusFilter : '') .
                ($search ? '&q='.urlencode($search) : '');
            echo renderPagination($pagination, $baseUrl);
            ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Modals for Journal Reversal outside cards -->
<?php if (!empty($journals)): ?>
    <?php foreach ($journals as $j): ?>
        <?php if ($j['status'] === 'posted' && hasRole(['Administrator', 'admin', 'Finance/Treasury', 'finance', 'Supervisor/Manajer', 'manajer'])): ?>
            <div id="modal-rev-jnl-<?= $j['id'] ?>" class="modal-backdrop">
                <div class="modal-dialog modal-sm">
                    <div class="modal-header" style="background:#fee2e2;">
                        <h3 class="modal-title" style="color:#b91c1c;">↺ Konfirmasi Reversal Voucher</h3>
                        <button type="button" class="modal-close" data-modal-close>&times;</button>
                    </div>
                    <form action="index.php?page=reports/journals" method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="reverse">
                        <input type="hidden" name="journal_id" value="<?= $j['id'] ?>">
                        <div class="modal-body">
                            <div style="background:#f8fafc; padding:10px; border-radius:6px; margin-bottom:12px; font-size:12.5px; border:1px solid #e2e8f0;">
                                No. Voucher: <strong><?= e($j['entry_number']) ?></strong><br>
                                Tanggal: <strong><?= formatDate($j['entry_date']) ?></strong><br>
                                Total Nilai: <strong style="color:#0284c7;"><?= formatRupiah((float)$j['total_debit']) ?></strong>
                            </div>
                            <p style="font-size:12px; color:#64748b; margin-bottom:12px;">
                                Jurnal pembalik otomatis akan dibuat di Buku Besar dengan posisi debit dan kredit ditukar untuk menganulir transaksi ini.
                            </p>
                            <div class="form-group mb-0">
                                <label style="font-size:12px;" class="form-label">Alasan Pembalikan / Koreksi *</label>
                                <input type="text" name="reason" class="form-control" placeholder="Contoh: Koreksi pembatalan transaksi" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Batal</button>
                            <button type="submit" class="btn btn-danger btn-sm">Proses Balik Jurnal</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
