<?php
/**
 * Modul Penerimaan: Daftar Pesanan Pelanggan & Sales Order (SO)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Pesanan Pelanggan & Sales Order (SO)';
$breadcrumbCategory = 'Penerimaan';

$db = getDbConnection();

$search = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(so.so_number LIKE :q OR c.name LIKE :q OR c.code LIKE :q)';
    $params[':q'] = "%{$search}%";
}

if ($status !== '') {
    $where[] = 'so.status = :status';
    $params[':status'] = $status;
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM sales_orders so
    JOIN customers c ON so.customer_id = c.id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

// List
$stmt = $db->prepare("
    SELECT so.*, c.name AS customer_name, c.code AS customer_code, c.credit_status,
           u.full_name AS creator_name
    FROM sales_orders so
    JOIN customers c ON so.customer_id = c.id
    LEFT JOIN users u ON so.created_by = u.id
    WHERE {$whereSql}
    ORDER BY so.id DESC
    LIMIT :offset, :per_page
");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Pesanan Pelanggan & Sales Order (SO)</h1>
        <div class="page-subtitle">Siklus Penerimaan &bull; Pencatatan Order Penjualan, Verifikasi Kredit, dan Stok Barang Jadi</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=receiving_sales/sales_order_create" class="btn btn-primary">
            + Buat Sales Order Baru
        </a>
    </div>
</div>

<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="receiving_sales/sales_orders">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari nomor SO, pelanggan..." style="min-width: 250px;">
    </div>
    <div class="filter-group">
        <select name="status">
            <option value="">-- Semua Status SO --</option>
            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draf</option>
            <option value="pending_approval" <?= $status === 'pending_approval' ? 'selected' : '' ?>>Menunggu Persetujuan / Override</option>
            <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Disetujui</option>
            <option value="picking" <?= $status === 'picking' ? 'selected' : '' ?>>Picking & Packing</option>
            <option value="shipped" <?= $status === 'shipped' ? 'selected' : '' ?>>Terkirim Sebagian / Penuh</option>
            <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Selesai</option>
            <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Dibatalkan</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $status !== ''): ?>
        <a href="index.php?page=receiving_sales/sales_orders" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>No. Sales Order</th>
                    <th>Tanggal SO</th>
                    <th>Pelanggan / Distributor</th>
                    <th>Target Kirim</th>
                    <th>Status Kredit Pelanggan</th>
                    <th class="text-right">Total Nilai Order</th>
                    <th>Sales Rep</th>
                    <th class="text-center">Status SO</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="9" class="text-center" style="padding: 24px; color: var(--text-muted);">
                            Tidak ada data sales order ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $so): ?>
                        <tr>
                            <td><strong><?= e($so['so_number']) ?></strong></td>
                            <td><?= formatDate($so['so_date']) ?></td>
                            <td>
                                <strong><?= e($so['customer_name']) ?></strong><br>
                                <code><?= e($so['customer_code']) ?></code>
                            </td>
                            <td><?= formatDate($so['due_date']) ?></td>
                            <td>
                                <?php if ($so['credit_status'] === 'active'): ?>
                                    <span class="badge badge-success">Limit Aktif</span>
                                <?php elseif ($so['credit_status'] === 'warning'): ?>
                                    <span class="badge badge-warning">Mendekati Limit</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Diblokir</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right font-weight-bold" style="color: var(--primary);">
                                <?= formatRupiah($so['total_amount']) ?>
                            </td>
                            <td><?= e($so['creator_name']) ?></td>
                            <td class="text-center"><?= getStatusBadge($so['status']) ?></td>
                            <td class="text-center" style="white-space: nowrap;">
                                <a href="index.php?page=receiving_sales/sales_order_detail&id=<?= $so['id'] ?>" class="btn btn-sm btn-secondary">
                                    Detail
                                </a>
                                <?php if (in_array($so['status'], ['draft', 'pending_approval'])): ?>
                                    <a href="index.php?page=receiving_sales/sales_order_create&id=<?= $so['id'] ?>" class="btn btn-sm btn-warning">
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
        <?= renderPagination($pagination, 'index.php?page=receiving_sales/sales_orders&q=' . urlencode($search) . '&status=' . urlencode($status)) ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
