<?php
/**
 * Modul Pengeluaran: Daftar Faktur Pemasok & Utang Usaha (AP)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Faktur Pemasok & Utang Usaha (AP)';
$breadcrumbCategory = 'Pengeluaran';

$db = getDbConnection();

$search = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(si.invoice_number LIKE :q OR si.supplier_invoice_number LIKE :q OR s.name LIKE :q)';
    $params[':q'] = "%{$search}%";
}

if ($status !== '') {
    $where[] = 'si.status = :status';
    $params[':status'] = $status;
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM supplier_invoices si
    JOIN suppliers s ON si.supplier_id = s.id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

// List
$stmt = $db->prepare("
    SELECT si.*, s.name AS supplier_name, po.po_number, gr.gr_number,
           twm.match_status, apv.voucher_number
    FROM supplier_invoices si
    JOIN suppliers s ON si.supplier_id = s.id
    LEFT JOIN purchase_orders po ON si.purchase_order_id = po.id
    LEFT JOIN goods_receipts gr ON si.goods_receipt_id = gr.id
    LEFT JOIN three_way_matches twm ON si.id = twm.supplier_invoice_id
    LEFT JOIN ap_vouchers apv ON si.id = apv.supplier_invoice_id
    WHERE {$whereSql}
    ORDER BY si.id DESC
    LIMIT :offset, :per_page
");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$invoices = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Faktur Tagihan Pemasok & Utang (Accounts Payable)</h1>
        <div class="page-subtitle">Siklus Pengeluaran &bull; Pencocokan 3 Dokumen (Three-Way Matching) dan Pengakuan Utang</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=expenditure_procurement/invoice_create" class="btn btn-primary">
            + Catat Faktur Pemasok Baru
        </a>
    </div>
</div>

<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="expenditure_procurement/invoices">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari invoice, vendor..." style="min-width: 220px;">
    </div>
    <div class="filter-group">
        <select name="status">
            <option value="">-- Semua Status --</option>
            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draf</option>
            <option value="verified" <?= $status === 'verified' ? 'selected' : '' ?>>Terverifikasi</option>
            <option value="matched" <?= $status === 'matched' ? 'selected' : '' ?>>Matched (Cocok)</option>
            <option value="partially_paid" <?= $status === 'partially_paid' ? 'selected' : '' ?>>Lunas Sebagian</option>
            <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Lunas</option>
            <option value="disputed" <?= $status === 'disputed' ? 'selected' : '' ?>>Sengketa / Beda</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $status !== ''): ?>
        <a href="index.php?page=expenditure_procurement/invoices" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>No. Sistem</th>
                    <th>No. Invoice Vendor</th>
                    <th>Pemasok</th>
                    <th>Tanggal / Tempo</th>
                    <th>Ref. PO / GR</th>
                    <th>Hasil Matching</th>
                    <th class="text-right">Total Tagihan</th>
                    <th class="text-right">Sisa Utang</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                    <tr>
                        <td colspan="10" class="text-center" style="padding: 24px; color: var(--text-muted);">
                            Tidak ada faktur pemasok ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td><strong><?= e($inv['invoice_number']) ?></strong></td>
                            <td><code><?= e($inv['supplier_invoice_number']) ?></code></td>
                            <td><?= e($inv['supplier_name']) ?></td>
                            <td>
                                <?= formatDate($inv['invoice_date']) ?><br>
                                <span style="font-size: 11px; color: var(--text-muted);">Jatuh tempo: <?= formatDate($inv['due_date']) ?></span>
                            </td>
                            <td>
                                <?= e($inv['po_number'] ?: '-') ?><br>
                                <span style="font-size: 11px; color: var(--text-muted);"><?= e($inv['gr_number'] ?: '-') ?></span>
                            </td>
                            <td>
                                <?php if ($inv['match_status'] === 'matched'): ?>
                                    <span class="badge badge-success">✓ Matched</span>
                                <?php elseif ($inv['match_status']): ?>
                                    <span class="badge badge-danger"><?= e($inv['match_status']) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right font-weight-bold"><?= formatRupiah($inv['total_amount']) ?></td>
                            <td class="text-right" style="color: <?= (float)$inv['balance'] > 0 ? 'var(--danger)' : 'var(--success)' ?>; font-weight: 600;">
                                <?= formatRupiah($inv['balance']) ?>
                            </td>
                            <td class="text-center"><?= getStatusBadge($inv['status']) ?></td>
                            <td class="text-center">
                                <a href="index.php?page=expenditure_procurement/invoice_detail&id=<?= $inv['id'] ?>" class="btn btn-sm btn-secondary">
                                    Detail
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer">
        <?= renderPagination($pagination, 'index.php?page=expenditure_procurement/invoices&q=' . urlencode($search) . '&status=' . urlencode($status)) ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
