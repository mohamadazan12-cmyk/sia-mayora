<?php
/**
 * Modul Penerimaan: Daftar Penerimaan Kas Pelanggan (Customer Receipts)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Penerimaan Kas Pelanggan (Customer Receipts)';
$breadcrumbCategory = 'Penerimaan';

$db = getDbConnection();

$search = trim($_GET['q'] ?? '');
$method = trim($_GET['method'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(cr.receipt_number LIKE :q OR c.name LIKE :q OR cr.bank_ref LIKE :q)';
    $params[':q'] = "%{$search}%";
}

if ($method !== '') {
    $where[] = 'cr.payment_method = :method';
    $params[':method'] = $method;
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM customer_receipts cr
    JOIN customers c ON cr.customer_id = c.id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

// List query
$stmt = $db->prepare("
    SELECT cr.*, c.name AS customer_name, c.code AS customer_code,
           u.full_name AS creator_name, uv.full_name AS verifier_name,
           (SELECT COUNT(*) FROM customer_receipt_allocations cra WHERE cra.customer_receipt_id = cr.id) AS inv_count
    FROM customer_receipts cr
    JOIN customers c ON cr.customer_id = c.id
    LEFT JOIN users u ON cr.created_by = u.id
    LEFT JOIN users uv ON cr.verified_by = uv.id
    WHERE {$whereSql}
    ORDER BY cr.id DESC
    LIMIT :offset, :per_page
");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$receipts = $stmt->fetchAll();

// Total collected
$sumTotal = (float)$db->query("SELECT COALESCE(SUM(total_amount), 0) FROM customer_receipts WHERE status != 'cancelled'")->fetchColumn();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Penerimaan Kas Pelanggan (Customer Receipts)</h1>
        <div class="page-subtitle">Siklus Penerimaan &bull; Pencatatan Pembayaran Piutang, Rekonsiliasi Bank &amp; Pembebasan Limit Kredit</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=receiving_sales/receipt_create" class="btn btn-primary">
            + Catat Penerimaan Kas Baru
        </a>
    </div>
</div>

<div class="metrics-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
    <div class="metric-card">
        <div class="metric-value"><?= $totalRows ?></div>
        <div class="metric-label">Total Kwitansi Kas Masuk</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#16a34a;"><?= formatRupiah($sumTotal) ?></div>
        <div class="metric-label">Akumulasi Kas Diterima</div>
    </div>
</div>

<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="receiving_sales/receipts">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari no bukti, pelanggan, ref bank..." style="min-width: 250px;">
    </div>
    <div class="filter-group">
        <select name="method">
            <option value="">-- Semua Metode Bayar --</option>
            <option value="transfer" <?= $method === 'transfer' ? 'selected' : '' ?>>Transfer Bank</option>
            <option value="cash" <?= $method === 'cash' ? 'selected' : '' ?>>Kas Tunai</option>
            <option value="giro" <?= $method === 'giro' ? 'selected' : '' ?>>Giro / Cek</option>
            <option value="cheque" <?= $method === 'cheque' ? 'selected' : '' ?>>Cheque</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $method !== ''): ?>
        <a href="index.php?page=receiving_sales/receipts" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>No. Kwitansi / Bukti</th>
                    <th>Pelanggan</th>
                    <th>Tanggal Terima</th>
                    <th>Metode Pembayaran</th>
                    <th>Bank &amp; No. Referensi</th>
                    <th class="text-right">Total Penerimaan</th>
                    <th>Alokasi Faktur</th>
                    <th>Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($receipts)): ?>
                    <tr>
                        <td colspan="9" class="text-center" style="padding: 30px; color: #64748b;">
                            Tidak ada data Penerimaan Kas yang sesuai kriteria filter.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($receipts as $rc): ?>
                        <tr>
                            <td>
                                <strong><a href="index.php?page=receiving_sales/receipt_detail&id=<?= $rc['id'] ?>"><?= e($rc['receipt_number']) ?></a></strong>
                            </td>
                            <td>
                                <strong><?= e($rc['customer_name']) ?></strong>
                                <div class="text-muted" style="font-size:11px;"><?= e($rc['customer_code']) ?></div>
                            </td>
                            <td><?= formatDate($rc['receipt_date']) ?></td>
                            <td>
                                <span class="badge badge-info"><?= strtoupper($rc['payment_method']) ?></span>
                            </td>
                            <td>
                                <div><?= e($rc['bank_name'] ?: '-') ?></div>
                                <small class="text-muted"><?= e($rc['bank_ref'] ?: '') ?></small>
                            </td>
                            <td class="text-right font-bold" style="color: #16a34a;">
                                <?= formatRupiah((float)$rc['total_amount']) ?>
                            </td>
                            <td>
                                <strong><?= (int)$rc['inv_count'] ?> Faktur</strong>
                            </td>
                            <td><?= statusBadge($rc['status']) ?></td>
                            <td class="text-center">
                                <a href="index.php?page=receiving_sales/receipt_detail&id=<?= $rc['id'] ?>" class="btn btn-secondary btn-sm">
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
                Menampilkan <?= count($receipts) ?> dari total <?= $totalRows ?> data
            </div>
            <div class="pagination" style="display:flex; gap:5px;">
                <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                    <a href="index.php?page=receiving_sales/receipts&p=<?= $i ?>&q=<?= urlencode($search) ?>&method=<?= urlencode($method) ?>"
                       class="btn btn-sm <?= $i === $pagination['page'] ? 'btn-primary' : 'btn-secondary' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
