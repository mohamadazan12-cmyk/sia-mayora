<?php
/**
 * Modul Pengeluaran: Daftar Goods Receipt (GR) / Penerimaan Barang
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Penerimaan Barang (GR)';
$breadcrumbCategory = 'Pengeluaran';

$db = getDbConnection();

$search = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(gr.gr_number LIKE :q OR s.name LIKE :q OR po.po_number LIKE :q OR gr.do_supplier LIKE :q)';
    $params[':q'] = "%{$search}%";
}

if ($status !== '') {
    $where[] = 'gr.status = :status';
    $params[':status'] = $status;
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM goods_receipts gr
    JOIN suppliers s ON gr.supplier_id = s.id
    JOIN purchase_orders po ON gr.purchase_order_id = po.id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

// List
$stmt = $db->prepare("
    SELECT gr.*, s.name AS supplier_name, po.po_number, w.name AS warehouse_name, u.full_name AS receiver_name
    FROM goods_receipts gr
    JOIN suppliers s ON gr.supplier_id = s.id
    JOIN purchase_orders po ON gr.purchase_order_id = po.id
    JOIN warehouses w ON gr.warehouse_id = w.id
    LEFT JOIN users u ON gr.created_by = u.id
    WHERE {$whereSql}
    ORDER BY gr.id DESC
    LIMIT :offset, :per_page
");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$grList = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Penerimaan Barang dari Pemasok (Goods Receipt)</h1>
        <div class="page-subtitle">Siklus Pengadaan &bull; Pemeriksaan Fisik, Kualitas, dan Penerimaan Stok Bahan</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=expenditure_procurement/gr_create" class="btn btn-primary">
            + Catat Penerimaan Barang Baru
        </a>
    </div>
</div>

<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="expenditure_procurement/gr">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari nomor GR, vendor, no PO..." style="min-width: 240px;">
    </div>
    <div class="filter-group">
        <select name="status">
            <option value="">-- Semua Status --</option>
            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draf</option>
            <option value="inspecting" <?= $status === 'inspecting' ? 'selected' : '' ?>>Inspeksi QC</option>
            <option value="accepted" <?= $status === 'accepted' ? 'selected' : '' ?>>Diterima Penuh</option>
            <option value="partially_accepted" <?= $status === 'partially_accepted' ? 'selected' : '' ?>>Diterima Sebagian</option>
            <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Ditolak Total</option>
            <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Dibatalkan</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $status !== ''): ?>
        <a href="index.php?page=expenditure_procurement/gr" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>No. GR</th>
                    <th>Tanggal Penerimaan</th>
                    <th>No. PO</th>
                    <th>Pemasok</th>
                    <th>Gudang Penerima</th>
                    <th>Surat Jalan Vendor</th>
                    <th>Penerima</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($grList)): ?>
                    <tr>
                        <td colspan="9" class="text-center" style="padding: 24px; color: var(--text-muted);">
                            Tidak ada data penerimaan barang ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($grList as $gr): ?>
                        <tr>
                            <td><strong><?= e($gr['gr_number']) ?></strong></td>
                            <td><?= formatDate($gr['receipt_date']) ?></td>
                            <td><a href="index.php?page=expenditure_procurement/po_detail&id=<?= $gr['purchase_order_id'] ?>"><strong><?= e($gr['po_number']) ?></strong></a></td>
                            <td><?= e($gr['supplier_name']) ?></td>
                            <td><?= e($gr['warehouse_name']) ?></td>
                            <td><?= e($gr['do_supplier'] ?: '-') ?></td>
                            <td><?= e($gr['receiver_name']) ?></td>
                            <td class="text-center"><?= getStatusBadge($gr['status']) ?></td>
                            <td class="text-center">
                                <a href="index.php?page=expenditure_procurement/gr_detail&id=<?= $gr['id'] ?>" class="btn btn-sm btn-secondary">
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
        <?= renderPagination($pagination, 'index.php?page=expenditure_procurement/gr&q=' . urlencode($search) . '&status=' . urlencode($status)) ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
