<?php
/**
 * Modul Produksi: Daftar Perintah Kerja Produksi (Production Orders)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Perintah Kerja Produksi';
$breadcrumbCategory = 'Produksi';

$db = getDbConnection();

$search = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(pdo.po_number LIKE :q OR i.name LIKE :q OR i.code LIKE :q)';
    $params[':q'] = "%{$search}%";
}

if ($status !== '') {
    $where[] = 'pdo.status = :status';
    $params[':status'] = $status;
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM production_orders pdo
    JOIN items i ON pdo.product_item_id = i.id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

// List
$stmt = $db->prepare("
    SELECT pdo.*, i.name AS product_name, i.code AS product_code, u.name AS unit_name,
           wm.name AS wh_material_name, wo.name AS wh_output_name,
           usr.full_name AS creator_name
    FROM production_orders pdo
    JOIN items i ON pdo.product_item_id = i.id
    LEFT JOIN units u ON i.unit_id = u.id
    JOIN warehouses wm ON pdo.warehouse_material_id = wm.id
    JOIN warehouses wo ON pdo.warehouse_output_id = wo.id
    LEFT JOIN users usr ON pdo.created_by = usr.id
    WHERE {$whereSql}
    ORDER BY pdo.id DESC
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
        <h1 class="page-title">Perintah Kerja Produksi (Production Order / SPK)</h1>
        <div class="page-subtitle">Siklus Produksi &bull; Perencanaan PPIC, Pemakaian Bahan, Eksekusi Mesin, dan Kontrol Mutu</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=production/order_create" class="btn btn-primary">
            + Buat Perintah Produksi Baru
        </a>
    </div>
</div>

<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="production/orders">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari nomor SPK, nama produk..." style="min-width: 250px;">
    </div>
    <div class="filter-group">
        <select name="status">
            <option value="">-- Semua Status Produksi --</option>
            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draf</option>
            <option value="pending_approval" <?= $status === 'pending_approval' ? 'selected' : '' ?>>Menunggu Persetujuan</option>
            <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Disetujui</option>
            <option value="waiting_material" <?= $status === 'waiting_material' ? 'selected' : '' ?>>Menunggu Pengeluaran Bahan</option>
            <option value="in_progress" <?= $status === 'in_progress' ? 'selected' : '' ?>>Sedang Berjalan</option>
            <option value="waiting_qc" <?= $status === 'waiting_qc' ? 'selected' : '' ?>>Menunggu QC</option>
            <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Selesai</option>
            <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Dibatalkan</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $status !== ''): ?>
        <a href="index.php?page=production/orders" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>No. Perintah (SPK)</th>
                    <th>Produk Jadi</th>
                    <th>Jadwal Rencana</th>
                    <th class="text-right">Target Kuantitas</th>
                    <th class="text-right">Hasil Aktual</th>
                    <th class="text-right">Scrap</th>
                    <th>Gudang Bahan / Hasil</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="9" class="text-center" style="padding: 24px; color: var(--text-muted);">
                            Belum ada perintah produksi yang tercatat.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $ord): ?>
                        <tr>
                            <td><strong><?= e($ord['po_number']) ?></strong></td>
                            <td>
                                <strong><?= e($ord['product_name']) ?></strong><br>
                                <code><?= e($ord['product_code']) ?></code>
                            </td>
                            <td>
                                <?= formatDate($ord['planned_start']) ?> s/d <?= formatDate($ord['planned_end']) ?>
                            </td>
                            <td class="text-right font-weight-bold">
                                <?= formatNumber($ord['target_qty']) ?> <?= e($ord['unit_name']) ?>
                            </td>
                            <td class="text-right" style="color: var(--success); font-weight: 600;">
                                <?= formatNumber($ord['actual_qty']) ?> <?= e($ord['unit_name']) ?>
                            </td>
                            <td class="text-right" style="color: <?= (float)$ord['scrap_qty'] > 0 ? 'var(--danger)' : 'var(--text-muted)' ?>;">
                                <?= formatNumber($ord['scrap_qty']) ?>
                            </td>
                            <td>
                                <span style="font-size: 11.5px;"><?= e($ord['wh_material_name']) ?> &rarr; <?= e($ord['wh_output_name']) ?></span>
                            </td>
                            <td class="text-center"><?= getStatusBadge($ord['status']) ?></td>
                            <td class="text-center" style="white-space:nowrap;">
                                <a href="index.php?page=production/order_detail&id=<?= $ord['id'] ?>" class="btn btn-sm btn-secondary">
                                    Detail
                                </a>
                                <?php if (in_array($ord['status'], ['draft', 'pending_approval'])): ?>
                                    <a href="index.php?page=production/order_create&id=<?= $ord['id'] ?>" class="btn btn-sm btn-primary">
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
        <?= renderPagination($pagination, 'index.php?page=production/orders&q=' . urlencode($search) . '&status=' . urlencode($status)) ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
