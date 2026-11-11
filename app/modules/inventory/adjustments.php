<?php
/**
 * Modul Persediaan: Penyesuaian Stok & Stock Opname (Inventory Stock Adjustments)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Penyesuaian Stok & Stock Opname';
$breadcrumbCategory = 'Persediaan';

$db = getDbConnection();
$user = currentUser();

// RBAC: Hanya role gudang, produksi/ppic, manager, dan admin
requireRole(['Administrator', 'admin', 'Petugas Gudang', 'gudang', 'Supervisor/Manajer', 'manajer', 'manager', 'ppic', 'Petugas Produksi', 'produksi']);

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $whFilter = (int)($_GET['wh_id'] ?? 0);
    $startDate = trim($_GET['start_date'] ?? '');
    $endDate = trim($_GET['end_date'] ?? '');
    $search = trim($_GET['q'] ?? '');

    $where = ["sm.movement_type = 'adjustment'"];
    $params = [];

    if ($whFilter > 0) {
        $where[] = 'sm.warehouse_id = :wh_id';
        $params[':wh_id'] = $whFilter;
    }
    if ($startDate !== '') {
        $where[] = 'DATE(sm.created_at) >= :start_date';
        $params[':start_date'] = $startDate;
    }
    if ($endDate !== '') {
        $where[] = 'DATE(sm.created_at) <= :end_date';
        $params[':end_date'] = $endDate;
    }
    if ($search !== '') {
        $where[] = '(i.code LIKE :q OR i.name LIKE :q OR sm.ref_number LIKE :q)';
        $params[':q'] = "%{$search}%";
    }

    $whereSql = implode(' AND ', $where);

    $stmtExport = $db->prepare("
        SELECT sm.created_at, sm.ref_number, w.name AS warehouse_name,
               i.code AS item_code, i.name AS item_name, u.code AS unit_symbol,
               sm.qty_in, sm.qty_out, sm.balance_after, sm.notes,
               u_acc.full_name AS operator_name
        FROM stock_movements sm
        JOIN items i ON sm.item_id = i.id
        JOIN warehouses w ON sm.warehouse_id = w.id
        LEFT JOIN units u ON i.unit_id = u.id
        LEFT JOIN users u_acc ON sm.user_id = u_acc.id
        WHERE {$whereSql}
        ORDER BY sm.id DESC
    ");
    $stmtExport->execute($params);
    $rows = $stmtExport->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=laporan_penyesuaian_stok_' . date('Ymd_His') . '.csv');

    $fp = fopen('php://output', 'w');
    // BOM for Indonesian Excel compatibility
    fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($fp, ['Tanggal & Waktu', 'No. Berita Acara / Ref', 'Gudang', 'Kode Barang', 'Nama Barang', 'Satuan', 'Selisih Masuk (+)', 'Selisih Keluar (-)', 'Saldo Fisik Akhir', 'Alasan / Keterangan', 'Petugas']);

    foreach ($rows as $r) {
        fputcsv($fp, [
            formatDateTime($r['created_at']),
            $r['ref_number'] ?: '-',
            $r['warehouse_name'],
            $r['item_code'],
            $r['item_name'],
            $r['unit_symbol'] ?: 'unit',
            (float)$r['qty_in'] > 0 ? (float)$r['qty_in'] : '0',
            (float)$r['qty_out'] > 0 ? (float)$r['qty_out'] : '0',
            (float)$r['balance_after'],
            $r['notes'],
            $r['operator_name'] ?: 'Sistem'
        ]);
    }
    fclose($fp);
    exit;
}

// Handle POST: Reversal / Counter-Adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_reverse_adjustment'])) {
    requireCsrf();
    $movementId = (int)($_POST['movement_id'] ?? 0);
    $reversalReason = trim($_POST['reversal_reason'] ?? '');

    if ($movementId <= 0 || empty($reversalReason)) {
        setFlash('error', 'ID mutasi dan alasan pembalik wajib diisi.');
        header('Location: index.php?page=inventory/adjustments');
        exit;
    }

    try {
        $db->beginTransaction();

        $stmtOrig = $db->prepare("
            SELECT sm.*, i.name AS item_name, i.code AS item_code 
            FROM stock_movements sm 
            JOIN items i ON sm.item_id = i.id 
            WHERE sm.id = :id AND sm.movement_type = 'adjustment' FOR UPDATE
        ");
        $stmtOrig->execute([':id' => $movementId]);
        $orig = $stmtOrig->fetch();

        if (!$orig) {
            throw new Exception('Data penyesuaian stok tidak ditemukan.');
        }

        if (strpos($orig['notes'], '[DIBALIKKAN]') !== false || strpos($orig['ref_number'], 'REV-') === 0) {
            throw new Exception('Penyesuaian stok ini sudah pernah dibalikkan sebelumnya.');
        }

        $curBalance = InventoryService::getBalanceForUpdate($orig['item_id'], $orig['warehouse_id'], $db);
        $curQty = (float)$curBalance['qty_available'];
        $qtyIn = (float)$orig['qty_in'];
        $qtyOut = (float)$orig['qty_out'];

        // Counter-adjustment target: subtract surplus, restore deficit
        $targetQty = $curQty - $qtyIn + $qtyOut;
        if ($targetQty < 0) {
            throw new Exception("Saldo stok fisik saat ini ({$curQty}) tidak mencukupi untuk pengurangan kembali sebesar {$qtyIn}.");
        }

        $revRef = 'REV-' . ($orig['ref_number'] ?: 'ADJ-' . $orig['id']);
        $revReason = "Voucher Koreksi/Pembalik {$orig['ref_number']}: {$reversalReason}";

        // Execute counter-adjustment via InventoryService
        InventoryService::adjustStock($orig['item_id'], $orig['warehouse_id'], $targetQty, $revReason, $revRef, $user['id'], $db);

        // Reverse any journal entry
        JournalService::reverseEntryByRef('stock_adjustment', $curBalance['id'], "Pembalik jurnal penyesuaian {$revRef}: {$reversalReason}", $user['id'], $db);

        // Mark original movement
        $db->prepare("UPDATE stock_movements SET notes = CONCAT_WS(' | ', notes, CONCAT('[DIBALIKKAN] ', :rev_ref)) WHERE id = :id")
           ->execute([':rev_ref' => $revRef, ':id' => $movementId]);

        logAudit($user['id'], 'reverse_adjustment', 'stock_movements', $movementId, $orig, [
            'reversal_ref' => $revRef,
            'reason'       => $reversalReason,
            'target_qty'   => $targetQty,
        ]);

        $db->commit();
        setFlash('success', "Voucher Pembalik Penyesuaian {$revRef} berhasil diterbitkan. Saldo fisik barang telah dinetralkan kembali.");
        header('Location: index.php?page=inventory/adjustments');
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', 'Gagal membalikkan penyesuaian stok: ' . $e->getMessage());
        header('Location: index.php?page=inventory/adjustments');
        exit;
    }
}

// Handle POST: Submit Adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
    $itemId = (int)($_POST['item_id'] ?? 0);
    $actualQty = (float)($_POST['actual_qty'] ?? 0);
    $reasonType = trim($_POST['reason_type'] ?? 'Stock Opname Rutin');
    $refNumber = trim($_POST['ref_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($warehouseId <= 0) {
        setFlash('error', 'Silakan pilih gudang penyimpanan.');
        header('Location: index.php?page=inventory/adjustments');
        exit;
    }

    if ($itemId <= 0) {
        setFlash('error', 'Silakan pilih item / barang yang disesuaikan.');
        header('Location: index.php?page=inventory/adjustments');
        exit;
    }

    if ($actualQty < 0) {
        setFlash('error', 'Kuantitas fisik aktual tidak boleh kurang dari 0.');
        header('Location: index.php?page=inventory/adjustments');
        exit;
    }

    if ($refNumber === '') {
        $refNumber = generateDocNumber('BA-OPN', 'stock_movements', 'ref_number');
    }

    $fullReason = $reasonType . ($notes !== '' ? ' - ' . $notes : '');

    try {
        $db->beginTransaction();

        // 1. Dapatkan saldo awal sebelum update
        $oldBalance = InventoryService::getBalanceForUpdate($itemId, $warehouseId, $db);
        $oldQty = (float)$oldBalance['qty_available'];
        $diff = $actualQty - $oldQty;

        // 2. Eksekusi penyesuaian melalui InventoryService
        InventoryService::adjustStock($itemId, $warehouseId, $actualQty, $fullReason, $refNumber, $user['id'], $db);

        // 3. Catat Jurnal Akuntansi jika ada selisih nilai fisik
        if (abs($diff) > 0.0001) {
            $stmtItem = $db->prepare('
                SELECT i.name, i.code, i.cost_price AS buy_price, c.type AS item_type 
                FROM items i 
                JOIN item_categories c ON i.category_id = c.id 
                WHERE i.id = :id
            ');
            $stmtItem->execute([':id' => $itemId]);
            $itemInfo = $stmtItem->fetch();
            $unitCost = (float)($itemInfo['buy_price'] ?? 0);
            $totalDiscrepancyValue = round(abs($diff) * $unitCost, 2);

            if ($totalDiscrepancyValue > 0) {
                // Tentukan akun persediaan berdasarkan tipe item
                $invAccCode = '1-1310'; // Default Bahan Baku
                if ($itemInfo['item_type'] === 'barang_jadi') {
                    $invAccCode = '1-1330'; // Barang Jadi
                } elseif ($itemInfo['item_type'] === 'wip') {
                    $invAccCode = '1-1320'; // WIP
                } elseif ($itemInfo['item_type'] === 'packaging') {
                    $invAccCode = '1-1340'; // Bahan Kemas
                }

                $invAccId = JournalService::getAccountIdByCode($invAccCode, $db) ?: JournalService::getAccountIdByCode('1-1300', $db);
                // Akun Beban / Selisih Persediaan (BOP - Pemakaian Bahan / Biaya Selisih)
                $adjAccId = JournalService::getAccountIdByCode('5-3100', $db) ?: JournalService::getAccountIdByCode('5-1000', $db);

                if ($invAccId && $adjAccId) {
                    $journalLines = [];
                    if ($diff > 0) {
                        // Surplus: Stok bertambah -> Dr Persediaan, Cr Beban/Selisih Persediaan
                        $journalLines[] = [
                            'account_id'  => $invAccId,
                            'description' => "Penyesuaian surplus stok {$itemInfo['name']} ({$refNumber})",
                            'debit'       => $totalDiscrepancyValue,
                            'credit'      => 0
                        ];
                        $journalLines[] = [
                            'account_id'  => $adjAccId,
                            'description' => "Pengkreditan selisih stok lebih ({$refNumber})",
                            'debit'       => 0,
                            'credit'      => $totalDiscrepancyValue
                        ];
                    } else {
                        // Defisit: Stok berkurang -> Dr Beban Selisih, Cr Persediaan
                        $journalLines[] = [
                            'account_id'  => $adjAccId,
                            'description' => "Beban selisih kurang/rusak {$itemInfo['name']} ({$refNumber})",
                            'debit'       => $totalDiscrepancyValue,
                            'credit'      => 0
                        ];
                        $journalLines[] = [
                            'account_id'  => $invAccId,
                            'description' => "Pengurangan nilai persediaan ({$refNumber})",
                            'debit'       => 0,
                            'credit'      => $totalDiscrepancyValue
                        ];
                    }

                    JournalService::createEntry(
                        date('Y-m-d'),
                        'stock_adjustment',
                        $oldBalance['id'],
                        $refNumber,
                        "Penyesuaian Opname Stok: {$refNumber} ({$itemInfo['code']} - {$itemInfo['name']})",
                        $journalLines,
                        $user['id'],
                        true,
                        $db
                    );
                }
            }
        }

        // 4. Audit Log
        logAudit($user['id'], 'adjust_stock', 'stock_balances', $oldBalance['id'], [
            'qty_available' => $oldQty,
        ], [
            'qty_available' => $actualQty,
            'diff'          => $diff,
            'ref_number'    => $refNumber,
            'reason'        => $fullReason
        ]);

        $db->commit();

        $diffText = ($diff >= 0 ? '+' : '') . formatNumber($diff);
        setFlash('success', "Penyesuaian stok berhasil dicatat dengan No. Berita Acara: {$refNumber}. Saldo sebelumnya: {$oldQty}, Saldo baru: {$actualQty} (Selisih: {$diffText}).");
        header('Location: index.php?page=inventory/adjustments');
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', 'Gagal memproses penyesuaian stok: ' . $e->getMessage());
        header('Location: index.php?page=inventory/adjustments');
        exit;
    }
}

// Fetch Warehouses & Items for dropdown
$warehouses = $db->query("SELECT id, code, name FROM warehouses WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
$items = $db->query("
    SELECT i.id, i.code, i.name, c.type AS item_type, u.code AS unit_symbol,
           COALESCE(SUM(sb.qty_available), 0) AS total_all_wh
    FROM items i
    JOIN item_categories c ON i.category_id = c.id
    LEFT JOIN units u ON i.unit_id = u.id
    LEFT JOIN stock_balances sb ON i.id = sb.item_id
    WHERE i.is_active = 1
    GROUP BY i.id
    ORDER BY c.type ASC, i.name ASC
")->fetchAll();

// Pre-fill item balance lookup if GET params passed
$selectedWhId = (int)($_GET['fill_wh'] ?? ($warehouses[0]['id'] ?? 0));
$selectedItemId = (int)($_GET['fill_item'] ?? 0);
$currentLookupQty = null;

if ($selectedWhId > 0 && $selectedItemId > 0) {
    $stmtCur = $db->prepare("SELECT qty_available FROM stock_balances WHERE warehouse_id = :wh AND item_id = :item");
    $stmtCur->execute([':wh' => $selectedWhId, ':item' => $selectedItemId]);
    $currentLookupQty = $stmtCur->fetchColumn();
    if ($currentLookupQty === false) {
        $currentLookupQty = 0.0;
    }
}

// Filter History Adjustments
$filterWh = (int)($_GET['wh_id'] ?? 0);
$filterStart = trim($_GET['start_date'] ?? '');
$filterEnd = trim($_GET['end_date'] ?? '');
$filterQ = trim($_GET['q'] ?? '');

$histWhere = ["sm.movement_type = 'adjustment'"];
$histParams = [];

if ($filterWh > 0) {
    $histWhere[] = 'sm.warehouse_id = :wh_id';
    $histParams[':wh_id'] = $filterWh;
}
if ($filterStart !== '') {
    $histWhere[] = 'DATE(sm.created_at) >= :start_date';
    $histParams[':start_date'] = $filterStart;
}
if ($filterEnd !== '') {
    $histWhere[] = 'DATE(sm.created_at) <= :end_date';
    $histParams[':end_date'] = $filterEnd;
}
if ($filterQ !== '') {
    $histWhere[] = '(i.code LIKE :q OR i.name LIKE :q OR sm.ref_number LIKE :q)';
    $histParams[':q'] = "%{$filterQ}%";
}

$histWhereSql = implode(' AND ', $histWhere);

// Metrics
$stmtMetrics = $db->prepare("
    SELECT 
        COUNT(sm.id) AS total_count,
        COALESCE(SUM(sm.qty_in), 0) AS total_surplus,
        COALESCE(SUM(sm.qty_out), 0) AS total_deficit
    FROM stock_movements sm
    JOIN items i ON sm.item_id = i.id
    WHERE {$histWhereSql}
");
$stmtMetrics->execute($histParams);
$metrics = $stmtMetrics->fetch();

// Pagination
$stmtCount = $db->prepare("
    SELECT COUNT(sm.id)
    FROM stock_movements sm
    JOIN items i ON sm.item_id = i.id
    WHERE {$histWhereSql}
");
$stmtCount->execute($histParams);
$totalRows = (int)$stmtCount->fetchColumn();

$currentPage = (int)($_GET['p'] ?? 1);
$perPage = 15;
$pagination = paginate($totalRows, $currentPage, $perPage);

$stmtHistory = $db->prepare("
    SELECT sm.*, i.name AS item_name, i.code AS item_code, c.type AS item_type,
           u.code AS unit_symbol, w.name AS warehouse_name,
           u_acc.full_name AS operator_name
    FROM stock_movements sm
    JOIN items i ON sm.item_id = i.id
    JOIN item_categories c ON i.category_id = c.id
    JOIN warehouses w ON sm.warehouse_id = w.id
    LEFT JOIN units u ON i.unit_id = u.id
    LEFT JOIN users u_acc ON sm.user_id = u_acc.id
    WHERE {$histWhereSql}
    ORDER BY sm.id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($histParams as $key => $val) {
    $stmtHistory->bindValue($key, $val);
}
$stmtHistory->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$stmtHistory->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmtHistory->execute();
$historyList = $stmtHistory->fetchAll();

// Default BA Number
$autoBaNumber = generateDocNumber('BA-OPN', 'stock_movements', 'ref_number');

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Penyesuaian Stok &amp; Stock Opname</h1>
        <div class="page-subtitle">Siklus Persediaan &bull; Berita Acara Rekonsiliasi Fisik Gudang, Koreksi Selisih Stok, dan Penyesuaian Nilai Buku</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=inventory/stock" class="btn btn-secondary">
            &larr; Saldo &amp; Mutasi Stok
        </a>
    </div>
</div>

<?= renderFlashMessages() ?>

<!-- Summary Metrics -->
<div class="metrics-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
    <div class="metric-card">
        <div class="metric-value"><?= formatNumber($metrics['total_count'] ?? 0, 0) ?> Dokumen</div>
        <div class="metric-label">Total Transaksi Penyesuaian</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#16a34a;">+ <?= formatNumber($metrics['total_surplus'] ?? 0) ?> unit</div>
        <div class="metric-label">Total Penambahan (Surplus Fisik)</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#dc2626;">- <?= formatNumber($metrics['total_deficit'] ?? 0) ?> unit</div>
        <div class="metric-label">Total Pengurangan (Defisit / Rusak)</div>
    </div>
</div>

<div class="grid grid-2 mb-4" style="grid-template-columns: 1fr 1fr; gap: 20px;">
    <!-- Form Penyesuaian Stok -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Form Berita Acara Penyesuaian Stok</h2>
        </div>
        <div class="card-body">
            <form action="index.php?page=inventory/adjustments" method="POST">
                <?= csrfField() ?>

                <div class="form-group mb-3">
                    <label class="form-label" for="ref_number">No. Berita Acara / Referensi *</label>
                    <input type="text" id="ref_number" name="ref_number" class="form-control" value="<?= e($autoBaNumber) ?>" required style="font-weight:600; font-family:monospace;">
                    <small class="form-text text-muted">Nomor surat Berita Acara Stock Opname atau memo internal</small>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" for="warehouse_id">Lokasi Gudang Penyimpanan *</label>
                    <select id="warehouse_id" name="warehouse_id" class="form-control" required>
                        <option value="">-- Pilih Lokasi Gudang --</option>
                        <?php foreach ($warehouses as $wh): ?>
                            <option value="<?= $wh['id'] ?>" <?= $selectedWhId === (int)$wh['id'] ? 'selected' : '' ?>>
                                <?= e($wh['name']) ?> (<?= e($wh['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" for="item_id">Barang / Bahan yang Disesuaikan *</label>
                    <select id="item_id" name="item_id" class="form-control" required>
                        <option value="">-- Pilih Item / Barang --</option>
                        <?php foreach ($items as $it): ?>
                            <option value="<?= $it['id'] ?>" <?= $selectedItemId === (int)$it['id'] ? 'selected' : '' ?>>
                                [<?= e($it['code']) ?>] <?= e($it['name']) ?> &bull; (Total Global: <?= formatNumber($it['total_all_wh']) ?> <?= e($it['unit_symbol']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" for="actual_qty">Kuantitas Fisik Aktual di Gudang *</label>
                    <input type="number" step="0.01" min="0" id="actual_qty" name="actual_qty" class="form-control" placeholder="Contoh: 150.00" required style="font-size: 16px; font-weight: bold;">
                    <small class="form-text text-muted">Masukkan jumlah fisik nyata hasil penghitungan fisik (stok opname). Sistem akan otomatis menghitung selisih dan memvalidasi batas saldo.</small>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" for="reason_type">Kategori Alasan Penyesuaian *</label>
                    <select id="reason_type" name="reason_type" class="form-control" required>
                        <option value="Stock Opname Rutin">Stock Opname Rutin (Rekonsiliasi Periodik)</option>
                        <option value="Koreksi Kesalahan Input">Koreksi Kesalahan Input Dokumen Sebelumnya</option>
                        <option value="Barang Rusak / Pecah / Kadaluarsa">Barang Rusak / Pecah / Kadaluarsa (Scrap / Waste)</option>
                        <option value="Barang Hilang / Selisih Hitung">Barang Hilang / Selisih Penghitungan Fisik</option>
                        <option value="Penemuan Stok Fisik Tambahan">Penemuan Fisik Tambahan (Surplus Gudang)</option>
                        <option value="Lainnya">Lain-lain / Keperluan Audit Khusus</option>
                    </select>
                </div>

                <div class="form-group mb-4">
                    <label class="form-label" for="notes">Keterangan / Berita Acara Detil</label>
                    <textarea id="notes" name="notes" class="form-control" rows="3" placeholder="Tuliskan keterangan saksi, lokasi rak bin, atau kronologi selisih fisik..."></textarea>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <a href="index.php?page=inventory/adjustments" class="btn btn-secondary">Batal</a>
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Apakah Anda yakin data fisik aktual sudah dihitung dengan benar? Saldo stok gudang dan jurnal selisih akan langsung diperbarui.')">
                        💾 Simpan Penyesuaian Stok
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Informasi Panduan SOP Stock Opname -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Petunjuk Teknis SOP Stock Opname</h2>
        </div>
        <div class="card-body">
            <div style="font-size: 13.5px; line-height: 1.6; color: #475569;">
                <p><strong>Ketentuan Siklus Persediaan Terintegrasi:</strong></p>
                <ol style="padding-left: 20px; margin-bottom: 15px;">
                    <li><strong>Atomic Row Locking:</strong> Setiap penyesuaian dieksekusi menggunakan row lock (<code>FOR UPDATE</code>) untuk mencegah inkonsistensi saat ada mutasi transaksi bersamaan.</li>
                    <li><strong>Larangan Stok Negatif:</strong> Sistem memberlakukan validasi ketat sehingga kuantitas fisik gudang tidak dapat bernilai minus (&lt; 0).</li>
                    <li><strong>Otomasi Jurnal Selisih:</strong> Apabila terdapat selisih (surplus atau defisit), sistem otomatis menerbitkan voucher <strong>Jurnal Umum Berimbang</strong> untuk merefleksikan nilai buku persediaan dan akun beban selisih operasional.</li>
                    <li><strong>Jejak Audit Permanen:</strong> Setiap penyesuaian dicatat di kartu mutasi stok (<code>stock_movements</code>) dan log audit sistem (<code>audit_logs</code>) lengkap dengan identitas petugas dan stempel waktu.</li>
                </ol>

                <div class="alert alert-info" style="margin-bottom:0;">
                    <strong>Tips Cek Saldo Sebelum Opname:</strong><br>
                    Untuk melihat rincian saldo per rak atau histori pergerakan barang tertentu, Anda dapat membuka halaman <a href="index.php?page=inventory/stock" style="color:#0284c7; text-decoration:underline;">Saldo &amp; Mutasi Stok</a>.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- History Table -->
<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div>
            <h2 class="card-title">Riwayat Berita Acara Penyesuaian Stok</h2>
            <div style="font-size:12px; color:#64748b;">Menampilkan riwayat penyesuaian dan stock opname fisik</div>
        </div>
        <div style="display:flex; gap:10px;">
            <a href="index.php?page=inventory/adjustments&export=csv<?= $filterWh ? '&wh_id='.$filterWh : '' ?><?= $filterStart ? '&start_date='.$filterStart : '' ?><?= $filterEnd ? '&end_date='.$filterEnd : '' ?><?= $filterQ ? '&q='.urlencode($filterQ) : '' ?>" class="btn btn-secondary btn-sm">
                📥 Unduh Laporan (CSV)
            </a>
        </div>
    </div>

    <!-- Filter Bar -->
    <div style="padding: 15px 20px; border-bottom: 1px solid #e2e8f0; background: #f8fafc;">
        <form action="index.php" method="GET" class="filter-bar" style="margin-bottom:0; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
            <input type="hidden" name="page" value="inventory/adjustments">

            <div class="filter-group">
                <input type="text" name="q" value="<?= e($filterQ) ?>" placeholder="Cari kode/nama barang/No. BA..." style="min-width:200px;">
            </div>

            <div class="filter-group">
                <select name="wh_id">
                    <option value="">-- Semua Gudang --</option>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= $wh['id'] ?>" <?= $filterWh === (int)$wh['id'] ? 'selected' : '' ?>>
                            <?= e($wh['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <input type="date" name="start_date" value="<?= e($filterStart) ?>" placeholder="Tanggal Mulai">
            </div>

            <div class="filter-group">
                <input type="date" name="end_date" value="<?= e($filterEnd) ?>" placeholder="Tanggal Akhir">
            </div>

            <button type="submit" class="btn btn-secondary btn-sm">Saring Data</button>

            <?php if ($filterQ !== '' || $filterWh > 0 || $filterStart !== '' || $filterEnd !== ''): ?>
                <a href="index.php?page=inventory/adjustments" class="btn btn-secondary btn-sm">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Waktu &amp; Tanggal</th>
                        <th>No. Berita Acara / Ref</th>
                        <th>Gudang</th>
                        <th>Kode &amp; Nama Barang</th>
                        <th style="text-align:right;">Surplus (+)</th>
                        <th style="text-align:right;">Defisit (-)</th>
                        <th style="text-align:right;">Saldo Akhir</th>
                        <th>Alasan &amp; Keterangan</th>
                        <th>Petugas</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($historyList)): ?>
                        <tr>
                            <td colspan="10" style="text-align:center; padding:30px; color:#94a3b8;">
                                Belum ada riwayat penyesuaian stok yang sesuai dengan kriteria filter.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($historyList as $h): ?>
                            <?php 
                                $isReversed = (strpos($h['notes'], '[DIBALIKKAN]') !== false || strpos($h['ref_number'], 'REV-') === 0);
                            ?>
                            <tr style="<?= $isReversed ? 'background:#f8fafc; opacity:0.8;' : '' ?>">
                                <td style="white-space:nowrap; font-size:12.5px;">
                                    <?= formatDateTime($h['created_at']) ?>
                                </td>
                                <td>
                                    <span style="font-family:monospace; font-weight:600; color:#1e293b;">
                                        <?= e($h['ref_number'] ?: '-') ?>
                                    </span>
                                    <?php if ($isReversed): ?>
                                        <span class="badge badge-danger" style="font-size:10px; margin-left:4px;">Dibalikkan</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-secondary"><?= e($h['warehouse_name']) ?></span>
                                </td>
                                <td>
                                    <strong><?= e($h['item_code']) ?></strong><br>
                                    <span style="font-size:12.5px; color:#475569;"><?= e($h['item_name']) ?></span>
                                </td>
                                <td style="text-align:right;">
                                    <?php if ((float)$h['qty_in'] > 0): ?>
                                        <span style="color:#16a34a; font-weight:600;">+<?= formatNumber($h['qty_in']) ?> <?= e($h['unit_symbol']) ?></span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <?php if ((float)$h['qty_out'] > 0): ?>
                                        <span style="color:#dc2626; font-weight:600;">-<?= formatNumber($h['qty_out']) ?> <?= e($h['unit_symbol']) ?></span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right; font-weight:bold;">
                                    <?= formatNumber($h['balance_after']) ?> <?= e($h['unit_symbol']) ?>
                                </td>
                                <td style="font-size:12.5px; max-width:280px;">
                                    <?= e($h['notes']) ?>
                                </td>
                                <td style="font-size:12.5px; color:#475569;">
                                    <?= e($h['operator_name'] ?: 'Sistem') ?>
                                </td>
                                <td class="text-center" style="white-space:nowrap;">
                                    <button type="button" class="btn btn-sm btn-secondary btn-detail-adj"
                                        data-time="<?= formatDateTime($h['created_at']) ?>"
                                        data-ref="<?= e($h['ref_number'] ?: '-') ?>"
                                        data-wh="<?= e($h['warehouse_name']) ?>"
                                        data-item="[<?= e($h['item_code']) ?>] <?= e($h['item_name']) ?>"
                                        data-in="<?= (float)$h['qty_in'] > 0 ? '+' . formatNumber($h['qty_in']) . ' ' . $h['unit_symbol'] : '-' ?>"
                                        data-out="<?= (float)$h['qty_out'] > 0 ? '-' . formatNumber($h['qty_out']) . ' ' . $h['unit_symbol'] : '-' ?>"
                                        data-balance="<?= formatNumber($h['balance_after']) ?> <?= e($h['unit_symbol']) ?>"
                                        data-notes="<?= e($h['notes'] ?: '-') ?>"
                                        data-operator="<?= e($h['operator_name'] ?: 'Sistem') ?>"
                                        data-reversed="<?= $isReversed ? '1' : '0' ?>">
                                        Detail
                                    </button>
                                    <?php if (!$isReversed && hasRole(['Administrator', 'admin', 'Supervisor/Manajer', 'manajer', 'manager', 'Petugas Gudang', 'gudang'])): ?>
                                        <button type="button" class="btn btn-sm btn-danger btn-reverse-adj"
                                            data-id="<?= $h['id'] ?>"
                                            data-ref="<?= e($h['ref_number'] ?: 'ADJ-' . $h['id']) ?>"
                                            data-item="<?= e($h['item_code']) ?> - <?= e($h['item_name']) ?>">
                                            Pembalik
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

    <?php if ($pagination['total_pages'] > 1): ?>
        <div class="card-footer" style="padding:15px 20px;">
            <?php
            $baseUrl = 'index.php?page=inventory/adjustments' .
                ($filterWh ? '&wh_id='.$filterWh : '') .
                ($filterStart ? '&start_date='.$filterStart : '') .
                ($filterEnd ? '&end_date='.$filterEnd : '') .
                ($filterQ ? '&q='.urlencode($filterQ) : '');
            echo renderPagination($pagination, $baseUrl);
            ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Detail Penyesuaian Stok -->
<div id="modalDetailAdj" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; border-radius:8px; max-width:550px; width:90%; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.2);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
            <h3 style="margin:0;" id="dtAdjTitle">Detail Berita Acara Opname</h3>
            <button type="button" style="background:none; border:none; font-size:20px; cursor:pointer;" onclick="document.getElementById('modalDetailAdj').style.display='none'">&times;</button>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:13.5px; margin-bottom:15px;">
            <div><strong>No. Berita Acara / Ref:</strong> <span id="dtAdjRef" style="font-family:monospace; font-weight:700;"></span></div>
            <div><strong>Waktu Transaksi:</strong> <span id="dtAdjTime"></span></div>
            <div style="grid-column:1/-1;"><strong>Barang / Item:</strong> <span id="dtAdjItem"></span></div>
            <div><strong>Lokasi Gudang:</strong> <span id="dtAdjWh"></span></div>
            <div><strong>Petugas Operator:</strong> <span id="dtAdjOperator"></span></div>
            <div><strong>Selisih Masuk (+):</strong> <span id="dtAdjIn" style="color:#16a34a; font-weight:700;"></span></div>
            <div><strong>Selisih Keluar (-):</strong> <span id="dtAdjOut" style="color:#dc2626; font-weight:700;"></span></div>
            <div style="grid-column:1/-1;"><strong>Saldo Fisik Tercatat:</strong> <span id="dtAdjBalance" style="font-weight:700;"></span></div>
            <div style="grid-column:1/-1;"><strong>Status Transaksi:</strong> <span id="dtAdjStatus"></span></div>
            <div style="grid-column:1/-1;"><strong>Alasan &amp; Keterangan:</strong> <span id="dtAdjNotes"></span></div>
        </div>
        <div style="display:flex; justify-content:flex-end;">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalDetailAdj').style.display='none'">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal Pembalik Penyesuaian Stok (Reversal Voucher) -->
<div id="modalReverseAdj" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; border-radius:8px; max-width:480px; width:90%; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:var(--danger);">Penerbitan Voucher Pembalik Penyesuaian</h3>
        <p style="color:var(--text-muted); font-size:14px;">
            Anda akan menerbitkan voucher koreksi/pembalik untuk Berita Acara <strong id="revAdjRefText"></strong> pada item <strong id="revAdjItemText"></strong>.
            Penyesuaian stok akan <strong>dinetralkan kembali secara atomik</strong> dan jurnal selisih akan <strong>dibalik (reversed)</strong>.
        </p>
        <form action="index.php?page=inventory/adjustments" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action_reverse_adjustment" value="1">
            <input type="hidden" name="movement_id" id="revAdjId">
            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label required">Alasan Pembalik / Koreksi Selisih</label>
                <textarea name="reversal_reason" class="form-control" rows="3" placeholder="Contoh: Kesalahan penghitungan fisik tim opname / dokumen ganda" required></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalReverseAdj').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-danger">Terbitkan Voucher Pembalik</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-detail-adj').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('dtAdjTitle').innerText = 'Detail Berita Acara: ' + d.ref;
            document.getElementById('dtAdjRef').innerText = d.ref;
            document.getElementById('dtAdjTime').innerText = d.time;
            document.getElementById('dtAdjItem').innerText = d.item;
            document.getElementById('dtAdjWh').innerText = d.wh;
            document.getElementById('dtAdjOperator').innerText = d.operator;
            document.getElementById('dtAdjIn').innerText = d.in;
            document.getElementById('dtAdjOut').innerText = d.out;
            document.getElementById('dtAdjBalance').innerText = d.balance;
            document.getElementById('dtAdjNotes').innerText = d.notes;
            document.getElementById('dtAdjStatus').innerHTML = d.reversed === '1' 
                ? '<span class="badge badge-danger">Telah Dibalikkan (Reversed)</span>' 
                : '<span class="badge badge-success">Aktif (Tercatat di Buku)</span>';
            document.getElementById('modalDetailAdj').style.display = 'flex';
        });
    });

    document.querySelectorAll('.btn-reverse-adj').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('revAdjId').value = d.id;
            document.getElementById('revAdjRefText').innerText = d.ref;
            document.getElementById('revAdjItemText').innerText = d.item;
            document.getElementById('modalReverseAdj').style.display = 'flex';
        });
    });
});
</script>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
