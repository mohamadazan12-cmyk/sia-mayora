<?php
/**
 * Modul Batch Tracking & Expiry Monitoring (FEFO)
 * PT Mayora Indah Tbk.
 * Memantau masa simpan (shelf-life), kedaluwarsa bahan baku & barang jadi,
 * serta memastikan kepatuhan First-Expired, First-Out (FEFO) industri F&B.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
enforcePageAccess('inventory/batches');

$db = getDbConnection();
$user = currentUser();

$search = trim($_GET['q'] ?? '');
$filterType = trim($_GET['type'] ?? ''); // all, raw, finished
$filterExpiry = trim($_GET['expiry'] ?? ''); // all, critical (<30), warning (<90), safe (>90)
$filterStatus = trim($_GET['status'] ?? ''); // passed, quarantine, expired
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;

$where = ['pb.current_qty > 0'];
$params = [];

if ($search !== '') {
    $where[] = '(pb.batch_number LIKE :q OR i.name LIKE :q OR i.code LIKE :q)';
    $params[':q'] = "%$search%";
}

if ($filterType === 'raw') {
    $where[] = "c.type IN ('bahan_baku', 'packaging')";
} elseif ($filterType === 'finished') {
    $where[] = "c.type = 'barang_jadi'";
}

if ($filterExpiry === 'critical') {
    $where[] = "DATEDIFF(pb.expiry_date, CURDATE()) <= 30";
} elseif ($filterExpiry === 'warning') {
    $where[] = "DATEDIFF(pb.expiry_date, CURDATE()) > 30 AND DATEDIFF(pb.expiry_date, CURDATE()) <= 90";
} elseif ($filterExpiry === 'safe') {
    $where[] = "DATEDIFF(pb.expiry_date, CURDATE()) > 90";
}

if ($filterStatus !== '') {
    $where[] = "pb.status = :status";
    $params[':status'] = $filterStatus;
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("SELECT COUNT(*) 
    FROM product_batches pb 
    JOIN items i ON pb.item_id = i.id 
    JOIN item_categories c ON i.category_id = c.id 
    WHERE $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$pagination = paginate($totalRows, $pageNo, $perPage);

// Fetch batches sorted by FEFO (expiry_date ASC)
$stmt = $db->prepare("SELECT pb.*, i.name as item_name, i.code as item_code, u.code as unit_code, c.type as item_type,
    w.name as warehouse_name, DATEDIFF(pb.expiry_date, CURDATE()) as days_left,
    s.name as supplier_name, pdo.po_number as production_po_number
    FROM product_batches pb 
    JOIN items i ON pb.item_id = i.id 
    JOIN item_categories c ON i.category_id = c.id 
    JOIN units u ON i.unit_id = u.id 
    LEFT JOIN warehouses w ON pb.warehouse_id = w.id 
    LEFT JOIN suppliers s ON pb.supplier_id = s.id 
    LEFT JOIN production_orders pdo ON pb.production_order_id = pdo.id 
    WHERE $whereSql 
    ORDER BY pb.expiry_date ASC, pb.id ASC 
    LIMIT :offset, :per_page");

foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Overall FEFO Metrics
$totalActiveBatches = (int)$db->query("SELECT COUNT(*) FROM product_batches WHERE current_qty > 0")->fetchColumn();
$criticalExpiryCount = (int)$db->query("SELECT COUNT(*) FROM product_batches WHERE current_qty > 0 AND DATEDIFF(expiry_date, CURDATE()) <= 30")->fetchColumn();
$warningExpiryCount = (int)$db->query("SELECT COUNT(*) FROM product_batches WHERE current_qty > 0 AND DATEDIFF(expiry_date, CURDATE()) > 30 AND DATEDIFF(expiry_date, CURDATE()) <= 90")->fetchColumn();

$pageTitle = 'Monitoring Batch & Kedaluwarsa (FEFO)';
$breadcrumbCategory = 'Persediaan';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 class="page-title" style="margin: 0; font-size: 22px; font-weight: 700;">Pelacakan Batch & Kedaluwarsa (FEFO Control)</h1>
        <div class="page-subtitle" style="color: #64748b; font-size: 13px; margin-top: 4px;">Standar Mutu Pangan Mayora &bull; Pengawasan First-Expired, First-Out seluruh lot bahan dan barang jadi.</div>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="index.php?page=inventory/traceability" class="btn btn-outline" style="border: 1px solid #cbd5e1; background: #fff; color: #1e293b; font-weight: 600; padding: 8px 14px; border-radius: 6px; text-decoration: none;">
            🔍 Telusuri Silsilah Produk (Traceability) &rarr;
        </a>
    </div>
</div>

<!-- KPI Summary Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
        <span style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">Total Batch Aktif</span>
        <div style="font-size: 24px; font-weight: 700; color: #0f172a; margin-top: 6px;"><?= number_format($totalActiveBatches, 0, ',', '.') ?> <span style="font-size: 13px; font-weight: 400; color: #64748b;">Lot</span></div>
        <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Memiliki stok fisik di gudang</div>
    </div>
    <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; border-left: 4px solid #dc2626;">
        <span style="font-size: 12px; font-weight: 600; color: #dc2626; text-transform: uppercase;">Kritis (&le; 30 Hari)</span>
        <div style="font-size: 24px; font-weight: 700; color: #dc2626; margin-top: 6px;"><?= $criticalExpiryCount ?> <span style="font-size: 13px; font-weight: 400; color: #dc2626;">Lot</span></div>
        <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Prioritas utama pemakaian / distribusi</div>
    </div>
    <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; border-left: 4px solid #f59e0b;">
        <span style="font-size: 12px; font-weight: 600; color: #b45309; text-transform: uppercase;">Perhatian (31 - 90 Hari)</span>
        <div style="font-size: 24px; font-weight: 700; color: #b45309; margin-top: 6px;"><?= $warningExpiryCount ?> <span style="font-size: 13px; font-weight: 400; color: #b45309;">Lot</span></div>
        <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Perlu percepatan konsumsi produksi</div>
    </div>
    <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; border-left: 4px solid #15803d;">
        <span style="font-size: 12px; font-weight: 600; color: #15803d; text-transform: uppercase;">Masa Simpan Aman (&gt; 90 Hari)</span>
        <div style="font-size: 24px; font-weight: 700; color: #15803d; margin-top: 6px;">
            <?= max(0, $totalActiveBatches - $criticalExpiryCount - $warningExpiryCount) ?> <span style="font-size: 13px; font-weight: 400; color: #15803d;">Lot</span>
        </div>
        <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Kualitas optimal dan aman disimpan</div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 20px;">
    <form method="GET" action="index.php" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin: 0;">
        <input type="hidden" name="page" value="inventory/batches">

        <div>
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari nomor lot batch, produk..." style="padding: 7px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; min-width: 220px;">
        </div>

        <div>
            <select name="type" onchange="this.form.submit()" style="padding: 7px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
                <option value="">Semua Jenis Item</option>
                <option value="finished" <?= $filterType === 'finished' ? 'selected' : '' ?>>Barang Jadi (Finished Goods)</option>
                <option value="raw" <?= $filterType === 'raw' ? 'selected' : '' ?>>Bahan Baku & Kemasan</option>
            </select>
        </div>

        <div>
            <select name="expiry" onchange="this.form.submit()" style="padding: 7px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
                <option value="">Semua Masa Kedaluwarsa</option>
                <option value="critical" <?= $filterExpiry === 'critical' ? 'selected' : '' ?>>Kritis (&le; 30 Hari)</option>
                <option value="warning" <?= $filterExpiry === 'warning' ? 'selected' : '' ?>>Perhatian (31 - 90 Hari)</option>
                <option value="safe" <?= $filterExpiry === 'safe' ? 'selected' : '' ?>>Aman (&gt; 90 Hari)</option>
            </select>
        </div>

        <button type="submit" class="btn btn-secondary btn-sm" style="padding: 7px 14px; border-radius: 6px;">Filter</button>
        <?php if ($search !== '' || $filterType !== '' || $filterExpiry !== ''): ?>
            <a href="index.php?page=inventory/batches" class="btn btn-sm btn-secondary" style="padding: 7px 14px; border-radius: 6px; text-decoration: none;">Reset</a>
        <?php endif; ?>

        <div style="margin-left: auto; font-size: 12px; color: #64748b;">
            Menampilkan <?= count($batches) ?> dari <?= $totalRows ?> lot aktif (Urut: FEFO)
        </div>
    </form>
</div>

<!-- Batches FEFO Table -->
<div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
    <div class="table-responsive">
        <table class="table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left; font-size: 12px; color: #475569;">
                    <th style="padding: 12px 14px;">Nomor Lot Batch</th>
                    <th style="padding: 12px 14px;">Nama Produk / Bahan</th>
                    <th style="padding: 12px 14px;">Tgl Manufaktur</th>
                    <th style="padding: 12px 14px;">Tgl Kedaluwarsa</th>
                    <th style="padding: 12px 14px; text-align: center;">Sisa Masa Simpan</th>
                    <th style="padding: 12px 14px; text-align: right;">Stok Tersisa</th>
                    <th style="padding: 12px 14px;">Lokasi Gudang</th>
                    <th style="padding: 12px 14px; text-align: center;">Status QC</th>
                    <th style="padding: 12px 14px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($batches)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 36px 16px; color: #94a3b8;">
                            Tidak ada data lot batch pada filter yang dipilih.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($batches as $b): 
                        $days = (int)$b['days_left'];
                        if ($days <= 0) {
                            $expBadge = '<span style="background:#fee2e2; color:#b91c1c; font-weight:700; padding:2px 8px; border-radius:4px; font-size:11px;">KEDALUWARSA</span>';
                        } elseif ($days <= 30) {
                            $expBadge = '<span style="background:#fef2f2; color:#dc2626; font-weight:700; padding:2px 8px; border-radius:4px; font-size:11px;">' . $days . ' hari (Kritis)</span>';
                        } elseif ($days <= 90) {
                            $expBadge = '<span style="background:#fffbeb; color:#b45309; font-weight:600; padding:2px 8px; border-radius:4px; font-size:11px;">' . $days . ' hari</span>';
                        } else {
                            $expBadge = '<span style="background:#f0fdf4; color:#15803d; font-weight:600; padding:2px 8px; border-radius:4px; font-size:11px;">' . $days . ' hari</span>';
                        }

                        $isFG = $b['item_type'] === 'barang_jadi';
                    ?>
                    <tr style="border-bottom: 1px solid #f1f5f9; font-size: 13px; background: <?= $days <= 30 ? '#fff9f9' : '#ffffff' ?>;">
                        <td style="padding: 12px 14px;">
                            <strong style="color: #0f172a; font-family: monospace; font-size: 12.5px;"><?= e($b['batch_number']) ?></strong><br>
                            <span style="font-size: 10.5px; color: #64748b;"><?= $isFG ? 'Produk Jadi (SPK ' . e($b['production_po_number'] ?: '-') . ')' : 'Bahan Baku (' . e($b['supplier_name'] ?: '-') . ')' ?></span>
                        </td>
                        <td style="padding: 12px 14px;">
                            <strong><?= e($b['item_name']) ?></strong><br>
                            <code style="font-size: 11px;"><?= e($b['item_code']) ?></code>
                        </td>
                        <td style="padding: 12px 14px; font-size: 12.5px; color: #475569;">
                            <?= formatDate($b['mfg_date']) ?>
                        </td>
                        <td style="padding: 12px 14px; font-size: 12.5px; font-weight: 600;">
                            <?= formatDate($b['expiry_date']) ?>
                        </td>
                        <td style="padding: 12px 14px; text-align: center;">
                            <?= $expBadge ?>
                        </td>
                        <td style="padding: 12px 14px; text-align: right; font-weight: 700; color: #0f172a;">
                            <?= number_format((float)$b['current_qty'], 1, ',', '.') ?> <?= e($b['unit_code']) ?>
                        </td>
                        <td style="padding: 12px 14px; font-size: 12px; color: #475569;">
                            <?= e($b['warehouse_name'] ?: '-') ?>
                        </td>
                        <td style="padding: 12px 14px; text-align: center;">
                            <?php if ($b['status'] === 'passed'): ?>
                                <span class="badge" style="background:#dcfce7; color:#15803d; font-size:11px;">✓ Lolos QC</span>
                            <?php elseif ($b['status'] === 'quarantine'): ?>
                                <span class="badge" style="background:#fef3c7; color:#b45309; font-size:11px;">Karantina</span>
                            <?php else: ?>
                                <span class="badge" style="background:#fee2e2; color:#dc2626; font-size:11px;"><?= e($b['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px 14px; text-align: center;">
                            <a href="index.php?page=inventory/traceability&batch=<?= urlencode($b['batch_number']) ?>" class="btn btn-sm btn-secondary" style="font-size: 11px; padding: 4px 8px; text-decoration: none;">
                                Telusuri
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer" style="padding: 12px 16px; border-top: 1px solid #e2e8f0; background: #f8fafc;">
        <?= renderPagination($pagination, 'index.php?page=inventory/batches&q=' . urlencode($search) . '&type=' . urlencode($filterType) . '&expiry=' . urlencode($filterExpiry)) ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
