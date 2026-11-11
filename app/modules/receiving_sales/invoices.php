<?php
/**
 * Modul Penerimaan: Daftar Faktur Penjualan (Sales Invoices)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Faktur Penjualan (Sales Invoices)';
$breadcrumbCategory = 'Penerimaan';

$db = getDbConnection();

$search = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(inv.invoice_number LIKE :q OR c.name LIKE :q OR so.so_number LIKE :q)';
    $params[':q'] = "%{$search}%";
}

if ($status !== '') {
    $where[] = 'inv.status = :status';
    $params[':status'] = $status;
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM sales_invoices inv
    JOIN customers c ON inv.customer_id = c.id
    LEFT JOIN sales_orders so ON inv.sales_order_id = so.id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

// List query
$stmt = $db->prepare("
    SELECT inv.*, c.name AS customer_name, c.code AS customer_code,
           so.so_number, do_tab.do_number, u.full_name AS creator_name
    FROM sales_invoices inv
    JOIN customers c ON inv.customer_id = c.id
    LEFT JOIN sales_orders so ON inv.sales_order_id = so.id
    LEFT JOIN delivery_orders do_tab ON inv.delivery_order_id = do_tab.id
    LEFT JOIN users u ON inv.created_by = u.id
    WHERE {$whereSql}
    ORDER BY inv.id DESC
    LIMIT :offset, :per_page
");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$invoices = $stmt->fetchAll();

// Summary metric cards
$summaryStmt = $db->query("
    SELECT 
        COUNT(id) AS total_invoices,
        COALESCE(SUM(total_amount), 0) AS grand_total_invoiced,
        COALESCE(SUM(paid_amount), 0) AS grand_total_paid,
        COALESCE(SUM(balance), 0) AS grand_total_outstanding
    FROM sales_invoices
    WHERE status != 'cancelled'
");
$summary = $summaryStmt->fetch();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Faktur Penjualan (Sales Invoices)</h1>
        <div class="page-subtitle">Siklus Penerimaan &bull; Penerbitan Tagihan, Perhitungan Pajak PPN 11%, dan Pemantauan Piutang</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=receiving_sales/invoice_create" class="btn btn-primary">
            + Buat Faktur Penjualan Baru
        </a>
    </div>
</div>

<!-- Summary Metrics -->
<div class="metrics-grid mb-4">
    <div class="metric-card">
        <div class="metric-value"><?= (int)$summary['total_invoices'] ?></div>
        <div class="metric-label">Total Faktur Diterbitkan</div>
    </div>
    <div class="metric-card">
        <div class="metric-value"><?= formatRupiah((float)$summary['grand_total_invoiced']) ?></div>
        <div class="metric-label">Nilai Penjualan Bruto</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color: #16a34a;"><?= formatRupiah((float)$summary['grand_total_paid']) ?></div>
        <div class="metric-label">Total Kas Diterima</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color: #ea580c;"><?= formatRupiah((float)$summary['grand_total_outstanding']) ?></div>
        <div class="metric-label">Sisa Saldo Piutang Usaha</div>
    </div>
</div>

<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="receiving_sales/invoices">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari no faktur, pelanggan, no SO..." style="min-width: 250px;">
    </div>
    <div class="filter-group">
        <select name="status">
            <option value="">-- Semua Status Faktur --</option>
            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draf</option>
            <option value="issued" <?= $status === 'issued' ? 'selected' : '' ?>>Issued (Belum Lunas)</option>
            <option value="partially_paid" <?= $status === 'partially_paid' ? 'selected' : '' ?>>Sebagian Dibayar</option>
            <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Lunas (Paid)</option>
            <option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>Jatuh Tempo (Overdue)</option>
            <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Dibatalkan</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $status !== ''): ?>
        <a href="index.php?page=receiving_sales/invoices" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>No. Faktur</th>
                    <th>Pelanggan</th>
                    <th>Ref. SO / DO</th>
                    <th>Tgl Faktur</th>
                    <th>Jatuh Tempo</th>
                    <th class="text-right">Total Tagihan</th>
                    <th class="text-right">Sudah Dibayar</th>
                    <th class="text-right">Sisa Piutang</th>
                    <th>Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                    <tr>
                        <td colspan="10" class="text-center" style="padding: 30px; color: #64748b;">
                            Tidak ada data Faktur Penjualan yang sesuai kriteria filter.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td>
                                <strong><a href="index.php?page=receiving_sales/invoice_detail&id=<?= $inv['id'] ?>"><?= e($inv['invoice_number']) ?></a></strong>
                                <?php if ($inv['tax_invoice_number']): ?>
                                    <div class="text-muted" style="font-size: 11px;">Faktur Pajak: <?= e($inv['tax_invoice_number']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= e($inv['customer_name']) ?></strong>
                                <div class="text-muted" style="font-size: 11px;"><?= e($inv['customer_code']) ?></div>
                            </td>
                            <td>
                                <div><a href="index.php?page=receiving_sales/sales_order_detail&id=<?= $inv['sales_order_id'] ?>"><?= e($inv['so_number']) ?></a></div>
                                <?php if ($inv['do_number']): ?>
                                    <div class="text-muted" style="font-size: 11px;">DO: <?= e($inv['do_number']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= formatDate($inv['invoice_date']) ?></td>
                            <td>
                                <?php 
                                    $isOverdue = (strtotime($inv['due_date']) < time()) && ((float)$inv['balance'] > 0);
                                ?>
                                <span style="<?= $isOverdue ? 'color:#dc2626; font-weight:600;' : '' ?>">
                                    <?= formatDate($inv['due_date']) ?>
                                    <?= $isOverdue ? '⚠️' : '' ?>
                                </span>
                            </td>
                            <td class="text-right font-medium"><?= formatRupiah((float)$inv['total_amount']) ?></td>
                            <td class="text-right" style="color: #16a34a;"><?= formatRupiah((float)$inv['paid_amount']) ?></td>
                            <td class="text-right font-bold" style="color: <?= (float)$inv['balance'] > 0 ? '#ea580c' : '#64748b' ?>;">
                                <?= formatRupiah((float)$inv['balance']) ?>
                            </td>
                            <td><?= statusBadge($inv['status']) ?></td>
                            <td class="text-center">
                                <a href="index.php?page=receiving_sales/invoice_detail&id=<?= $inv['id'] ?>" class="btn btn-secondary btn-sm">
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
                Menampilkan <?= count($invoices) ?> dari total <?= $totalRows ?> data
            </div>
            <div class="pagination" style="display:flex; gap:5px;">
                <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                    <a href="index.php?page=receiving_sales/invoices&p=<?= $i ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>"
                       class="btn btn-sm <?= $i === $pagination['page'] ? 'btn-primary' : 'btn-secondary' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
