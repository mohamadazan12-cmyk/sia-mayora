<?php
/**
 * Modul Penerimaan: Daftar Pengiriman & Surat Jalan (Delivery Order)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Pengiriman & Surat Jalan (Delivery Order)';
$breadcrumbCategory = 'Penerimaan';

$db = getDbConnection();

$search = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(do_tab.do_number LIKE :q OR so.so_number LIKE :q OR c.name LIKE :q OR do_tab.driver_name LIKE :q)';
    $params[':q'] = "%{$search}%";
}

if ($status !== '') {
    $where[] = 'do_tab.status = :status';
    $params[':status'] = $status;
}

$whereSql = implode(' AND ', $where);

// Total count
$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM delivery_orders do_tab
    JOIN sales_orders so ON do_tab.sales_order_id = so.id
    JOIN customers c ON so.customer_id = c.id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

// List query
$stmt = $db->prepare("
    SELECT do_tab.*, so.so_number, c.name AS customer_name, c.code AS customer_code,
           w.name AS warehouse_name, u.full_name AS creator_name,
           (SELECT COUNT(*) FROM delivery_order_items doi WHERE doi.delivery_order_id = do_tab.id) AS item_count,
           (SELECT SUM(doi.qty_shipped) FROM delivery_order_items doi WHERE doi.delivery_order_id = do_tab.id) AS total_shipped_qty
    FROM delivery_orders do_tab
    JOIN sales_orders so ON do_tab.sales_order_id = so.id
    JOIN customers c ON so.customer_id = c.id
    JOIN warehouses w ON do_tab.warehouse_id = w.id
    LEFT JOIN users u ON do_tab.created_by = u.id
    WHERE {$whereSql}
    ORDER BY do_tab.id DESC
    LIMIT :offset, :per_page
");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$deliveryOrders = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Pengiriman & Surat Jalan (Delivery Order)</h1>
        <div class="page-subtitle">Siklus Penerimaan &bull; Pengeluaran Barang Jadi dari Gudang, Ekspedisi, dan Bukti Serah Terima</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=receiving_sales/delivery_order_create" class="btn btn-primary">
            + Buat Surat Jalan Baru
        </a>
    </div>
</div>

<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="receiving_sales/delivery_orders">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari nomor DO, SO, pelanggan, supir..." style="min-width: 250px;">
    </div>
    <div class="filter-group">
        <select name="status">
            <option value="">-- Semua Status --</option>
            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draf</option>
            <option value="picking" <?= $status === 'picking' ? 'selected' : '' ?>>Picking (Pengambilan)</option>
            <option value="packed" <?= $status === 'packed' ? 'selected' : '' ?>>Packed (Pengepakan)</option>
            <option value="shipped" <?= $status === 'shipped' ? 'selected' : '' ?>>Shipped (Dalam Pengiriman)</option>
            <option value="delivered" <?= $status === 'delivered' ? 'selected' : '' ?>>Delivered (Telah Diterima)</option>
            <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Dibatalkan</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $status !== ''): ?>
        <a href="index.php?page=receiving_sales/delivery_orders" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>No. Surat Jalan</th>
                    <th>No. Sales Order</th>
                    <th>Pelanggan</th>
                    <th>Gudang Asal</th>
                    <th>Tgl Kirim</th>
                    <th>Supir / Kendaraan</th>
                    <th class="text-right">Total Item / Qty</th>
                    <th>Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($deliveryOrders)): ?>
                    <tr>
                        <td colspan="9" class="text-center" style="padding: 30px; color: #64748b;">
                            Tidak ada data Surat Jalan / Pengiriman yang sesuai kriteria pencarian.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($deliveryOrders as $do): ?>
                        <tr>
                            <td>
                                <strong><a href="index.php?page=receiving_sales/delivery_order_detail&id=<?= $do['id'] ?>"><?= e($do['do_number']) ?></a></strong>
                            </td>
                            <td>
                                <a href="index.php?page=receiving_sales/sales_order_detail&id=<?= $do['sales_order_id'] ?>">
                                    <?= e($do['so_number']) ?>
                                </a>
                            </td>
                            <td>
                                <div><strong><?= e($do['customer_name']) ?></strong></div>
                                <small class="text-muted"><?= e($do['customer_code']) ?></small>
                            </td>
                            <td><?= e($do['warehouse_name']) ?></td>
                            <td><?= formatDate($do['do_date']) ?></td>
                            <td>
                                <?php if ($do['driver_name'] || $do['vehicle_number']): ?>
                                    <div><?= e($do['driver_name'] ?: '-') ?></div>
                                    <small class="text-muted"><?= e($do['vehicle_number'] ?: '') ?></small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <strong><?= (int)$do['item_count'] ?> item</strong>
                                <div class="text-muted" style="font-size: 11px;">(<?= formatNumber((float)$do['total_shipped_qty']) ?> unit)</div>
                            </td>
                            <td><?= statusBadge($do['status']) ?></td>
                            <td class="text-center">
                                <a href="index.php?page=receiving_sales/delivery_order_detail&id=<?= $do['id'] ?>" class="btn btn-secondary btn-sm">
                                    Detail
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($pagination['total_pages'] > 1): ?>
        <div class="pagination-container" style="display:flex; justify-content:space-between; align-items:center; padding: 12px 18px; border-top:1px solid #e2e8f0;">
            <div class="text-muted" style="font-size:12px;">
                Menampilkan <?= count($deliveryOrders) ?> dari total <?= $totalRows ?> data
            </div>
            <div class="pagination" style="display:flex; gap:5px;">
                <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                    <a href="index.php?page=receiving_sales/delivery_orders&p=<?= $i ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>"
                       class="btn btn-sm <?= $i === $pagination['page'] ? 'btn-primary' : 'btn-secondary' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
