<?php
/**
 * Modul Pengeluaran: Daftar Purchase Order (PO)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Pesanan Pembelian (PO)';
$breadcrumbCategory = 'Pengeluaran';

$db = getDbConnection();

$search = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(po.po_number LIKE :q OR s.name LIKE :q OR pr.pr_number LIKE :q)';
    $params[':q'] = "%{$search}%";
}

if ($status !== '') {
    $where[] = 'po.status = :status';
    $params[':status'] = $status;
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN purchase_requisitions pr ON po.purchase_requisition_id = pr.id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

// List
$stmt = $db->prepare("
    SELECT po.*, s.name AS supplier_name, pr.pr_number, u.full_name AS creator_name
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN purchase_requisitions pr ON po.purchase_requisition_id = pr.id
    LEFT JOIN users u ON po.created_by = u.id
    WHERE {$whereSql}
    ORDER BY po.id DESC
    LIMIT :offset, :per_page
");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$poList = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Pesanan Pembelian (Purchase Order)</h1>
        <div class="page-subtitle">Siklus Pengeluaran &bull; Pemesanan Resmi Bahan ke Pemasok/Vendor</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=expenditure_procurement/po_create" class="btn btn-primary">
            + Buat PO Baru
        </a>
    </div>
</div>

<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="expenditure_procurement/po">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari nomor PO, nama vendor..." style="min-width: 240px;">
    </div>
    <div class="filter-group">
        <select name="status">
            <option value="">-- Semua Status --</option>
            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draf</option>
            <option value="pending_approval" <?= $status === 'pending_approval' ? 'selected' : '' ?>>Menunggu Persetujuan</option>
            <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Disetujui</option>
            <option value="partially_received" <?= $status === 'partially_received' ? 'selected' : '' ?>>Diterima Sebagian</option>
            <option value="received" <?= $status === 'received' ? 'selected' : '' ?>>Diterima Penuh</option>
            <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Dibatalkan</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $status !== ''): ?>
        <a href="index.php?page=expenditure_procurement/po" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>No. PO</th>
                    <th>Tanggal PO</th>
                    <th>Pemasok / Vendor</th>
                    <th>Ref. PR</th>
                    <th>Target Kirim</th>
                    <th class="text-right">Total Nilai</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($poList)): ?>
                    <tr>
                        <td colspan="8" class="text-center" style="padding: 24px; color: var(--text-muted);">
                            Tidak ada data pesanan pembelian (PO) ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($poList as $po): ?>
                        <tr>
                            <td><strong><?= e($po['po_number']) ?></strong></td>
                            <td><?= formatDate($po['po_date']) ?></td>
                            <td><?= e($po['supplier_name']) ?></td>
                            <td>
                                <?php if ($po['pr_number']): ?>
                                    <span class="badge badge-secondary"><?= e($po['pr_number']) ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?= formatDate($po['expected_delivery']) ?></td>
                            <td class="text-right font-weight-bold"><?= formatRupiah($po['total_amount']) ?></td>
                            <td class="text-center"><?= getStatusBadge($po['status']) ?></td>
                            <td class="text-center" style="white-space: nowrap;">
                                <a href="index.php?page=expenditure_procurement/po_detail&id=<?= $po['id'] ?>" class="btn btn-sm btn-secondary">
                                    Detail
                                </a>
                                <?php if ($po['status'] === 'draft'): ?>
                                    <a href="index.php?page=expenditure_procurement/po_create&id=<?= $po['id'] ?>" class="btn btn-sm btn-warning">
                                        Edit
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer">
        <?= renderPagination($pagination, 'index.php?page=expenditure_procurement/po&q=' . urlencode($search) . '&status=' . urlencode($status)) ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
