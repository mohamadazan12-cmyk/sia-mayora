<?php
/**
 * Modul Laporan Operasional Terintegrasi
 * Menyediakan rekapitulasi 4 siklus operasional: Penjualan, Pengadaan, Produksi, dan SDM/Payroll.
 * Mendukung filter tanggal, status, dan ekspor data ke format CSV.
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Laporan Operasional Terintegrasi';
$breadcrumbCategory = 'Laporan & Audit';

$db = getDbConnection();
$user = currentUser();

// Tab navigasi aktif
$tab = $_GET['tab'] ?? 'penjualan';
$validTabs = ['penjualan', 'pengadaan', 'produksi', 'payroll', 'persediaan'];
if (!in_array($tab, $validTabs)) {
    $tab = 'penjualan';
}

// Filter Periode Tanggal (Default: Bulan Berjalan)
$defaultStart = date('Y-m-01');
$defaultEnd = date('Y-m-t');
$startDate = trim($_GET['start_date'] ?? $defaultStart);
$endDate = trim($_GET['end_date'] ?? $defaultEnd);
$statusFilter = trim($_GET['status'] ?? '');

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=laporan_' . $tab . '_' . date('Ymd_His') . '.csv');

    $fp = fopen('php://output', 'w');
    // UTF-8 BOM
    fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

    if ($tab === 'penjualan') {
        fputcsv($fp, ['No. Faktur', 'Tanggal', 'Pelanggan', 'Jatuh Tempo', 'Subtotal', 'PPN (11%)', 'Total Faktur', 'Terbayar', 'Sisa Tagihan', 'Status']);
        $sql = "
            SELECT si.invoice_number, si.invoice_date, c.name AS customer_name, si.due_date,
                   si.subtotal, si.tax_amount, si.total_amount, si.paid_amount,
                   (si.total_amount - si.paid_amount) AS balance_due, si.status
            FROM sales_invoices si
            JOIN customers c ON si.customer_id = c.id
            WHERE DATE(si.invoice_date) BETWEEN :start_date AND :end_date
            " . ($statusFilter !== '' ? "AND si.status = :status " : "") . "
            ORDER BY si.invoice_date DESC
        ";
        $stmt = $db->prepare($sql);
        $p = [':start_date' => $startDate, ':end_date' => $endDate];
        if ($statusFilter !== '') $p[':status'] = $statusFilter;
        $stmt->execute($p);
        while ($row = $stmt->fetch()) {
            fputcsv($fp, [
                $row['invoice_number'],
                formatDate($row['invoice_date']),
                $row['customer_name'],
                formatDate($row['due_date']),
                $row['subtotal'],
                $row['tax_amount'],
                $row['total_amount'],
                $row['paid_amount'],
                $row['balance_due'],
                strtoupper($row['status'])
            ]);
        }
    } elseif ($tab === 'pengadaan') {
        fputcsv($fp, ['No. PO', 'Tanggal PO', 'Pemasok', 'Status PO', 'Total Nominal PO', 'No. Faktur Vendor', 'Status Faktur', 'Total Tagihan Vendor', 'Terbayar']);
        $sql = "
            SELECT po.po_number, po.po_date, s.name AS supplier_name, po.status AS po_status, po.total_amount AS po_total,
                   si.invoice_number AS vendor_inv_num, si.status AS inv_status, si.total_amount AS inv_total, si.paid_amount AS inv_paid
            FROM purchase_orders po
            JOIN suppliers s ON po.supplier_id = s.id
            LEFT JOIN supplier_invoices si ON po.id = si.purchase_order_id
            WHERE DATE(po.po_date) BETWEEN :start_date AND :end_date
            " . ($statusFilter !== '' ? "AND po.status = :status " : "") . "
            ORDER BY po.po_date DESC
        ";
        $stmt = $db->prepare($sql);
        $p = [':start_date' => $startDate, ':end_date' => $endDate];
        if ($statusFilter !== '') $p[':status'] = $statusFilter;
        $stmt->execute($p);
        while ($row = $stmt->fetch()) {
            fputcsv($fp, [
                $row['po_number'],
                formatDate($row['po_date']),
                $row['supplier_name'],
                strtoupper($row['po_status']),
                $row['po_total'],
                $row['vendor_inv_num'] ?: '-',
                strtoupper($row['inv_status'] ?: '-'),
                $row['inv_total'] ?: 0,
                $row['inv_paid'] ?: 0
            ]);
        }
    } elseif ($tab === 'produksi') {
        fputcsv($fp, ['No. SPK', 'Tanggal SPK', 'Barang Jadi', 'Rencana Qty', 'Qty Selesai', 'Scrap/Afkir', 'Status', 'Biaya Bahan', 'Biaya Tenaga Kerja', 'Biaya Overhead', 'Total HPP']);
        $sql = "
            SELECT po.po_number AS order_number, po.planned_start AS order_date, i.name AS item_name,
                   po.target_qty AS qty_planned, po.actual_qty AS qty_completed, po.scrap_qty AS qty_scrap, po.status,
                   COALESCE(SUM(pc.material_cost), 0) AS mat_cost,
                   COALESCE(SUM(pc.labor_cost), 0) AS labor_cost,
                   COALESCE(SUM(pc.overhead_cost), 0) AS oh_cost,
                   COALESCE(SUM(pc.total_cost), 0) AS total_hpp
            FROM production_orders po
            JOIN items i ON po.product_item_id = i.id
            LEFT JOIN production_costs pc ON po.id = pc.production_order_id
            WHERE DATE(po.planned_start) BETWEEN :start_date AND :end_date
            " . ($statusFilter !== '' ? "AND po.status = :status " : "") . "
            GROUP BY po.id
            ORDER BY po.planned_start DESC
        ";
        $stmt = $db->prepare($sql);
        $p = [':start_date' => $startDate, ':end_date' => $endDate];
        if ($statusFilter !== '') $p[':status'] = $statusFilter;
        $stmt->execute($p);
        while ($row = $stmt->fetch()) {
            fputcsv($fp, [
                $row['order_number'],
                formatDate($row['order_date']),
                $row['item_name'],
                $row['qty_planned'],
                $row['qty_completed'],
                $row['qty_scrap'],
                strtoupper($row['status']),
                $row['mat_cost'],
                $row['labor_cost'],
                $row['oh_cost'],
                $row['total_hpp']
            ]);
        }
    } elseif ($tab === 'payroll') {
        fputcsv($fp, ['NIK', 'Nama Karyawan', 'Departemen', 'Jabatan', 'Bulan/Tahun', 'Gaji Pokok', 'Tunjangan', 'Lembur', 'Potongan', 'Gaji Bersih (THP)', 'Status']);
        $sql = "
            SELECT e.nik, e.full_name, d.name AS dept_name, pos.title AS pos_name, pp.period_name,
                   pr.basic_salary, pr.allowances_total, pr.overtime_pay, pr.deductions_total, pr.net_salary, pr.status
            FROM payroll_records pr
            JOIN employees e ON pr.employee_id = e.id
            JOIN payroll_periods pp ON pr.payroll_period_id = pp.id
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN positions pos ON e.position_id = pos.id
            WHERE DATE(pp.start_date) >= :start_date AND DATE(pp.end_date) <= :end_date
            ORDER BY pp.id DESC, e.nik ASC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
        while ($row = $stmt->fetch()) {
            fputcsv($fp, [
                $row['nik'],
                $row['full_name'],
                $row['dept_name'] ?: '-',
                $row['pos_name'] ?: '-',
                $row['period_name'],
                $row['basic_salary'],
                $row['allowances_total'],
                $row['overtime_pay'],
                $row['deductions_total'],
                $row['net_salary'],
                strtoupper($row['status'])
            ]);
        }
    } elseif ($tab === 'persediaan') {
        fputcsv($fp, ['Kode Barang', 'Nama Barang', 'Kategori / Tipe', 'Gudang', 'Stok Fisik', 'Satuan', 'Harga Beli Satuan', 'Total Nilai Inventaris', 'Status Stok']);
        $sql = "
            SELECT i.code AS item_code, i.name AS item_name, c.type AS item_type, w.name AS warehouse_name,
                   sb.qty_available, u.code AS unit_symbol, i.cost_price AS buy_price, (sb.qty_available * i.cost_price) AS inv_val,
                   i.min_stock
            FROM stock_balances sb
            JOIN items i ON sb.item_id = i.id
            JOIN item_categories c ON i.category_id = c.id
            JOIN warehouses w ON sb.warehouse_id = w.id
            LEFT JOIN units u ON i.unit_id = u.id
            ORDER BY w.name ASC, c.type ASC, i.name ASC
        ";
        $stmt = $db->query($sql);
        while ($row = $stmt->fetch()) {
            $statusStock = ((float)$row['qty_available'] <= (float)$row['min_stock'] && (float)$row['min_stock'] > 0) ? 'LOW STOCK' : 'AMAN';
            fputcsv($fp, [
                $row['item_code'],
                $row['item_name'],
                $row['item_type'],
                $row['warehouse_name'],
                $row['qty_available'],
                $row['unit_symbol'] ?: 'unit',
                $row['buy_price'],
                $row['inv_val'],
                $statusStock
            ]);
        }
    }
    fclose($fp);
    exit;
}

// Global High-Level Operational Metrics
$metricSales = (float)$db->query("SELECT COALESCE(SUM(total_amount), 0) FROM sales_invoices WHERE status != 'cancelled'")->fetchColumn();
$metricProcurement = (float)$db->query("SELECT COALESCE(SUM(total_amount), 0) FROM purchase_orders WHERE status != 'cancelled'")->fetchColumn();
$metricProductionQty = (float)$db->query("SELECT COALESCE(SUM(actual_qty), 0) FROM production_orders WHERE status = 'completed'")->fetchColumn();
$metricPayroll = (float)$db->query("SELECT COALESCE(SUM(net_salary), 0) FROM payroll_records WHERE status IN ('approved', 'paid')")->fetchColumn();

// Tab Specific Queries
$tableData = [];
if ($tab === 'penjualan') {
    $where = ["DATE(si.invoice_date) BETWEEN :start_date AND :end_date"];
    $p = [':start_date' => $startDate, ':end_date' => $endDate];
    if ($statusFilter !== '') {
        $where[] = "si.status = :status";
        $p[':status'] = $statusFilter;
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT si.*, c.name AS customer_name, c.code AS customer_code
        FROM sales_invoices si
        JOIN customers c ON si.customer_id = c.id
        WHERE {$whereSql}
        ORDER BY si.invoice_date DESC
    ");
    $stmt->execute($p);
    $tableData = $stmt->fetchAll();
} elseif ($tab === 'pengadaan') {
    $where = ["DATE(po.po_date) BETWEEN :start_date AND :end_date"];
    $p = [':start_date' => $startDate, ':end_date' => $endDate];
    if ($statusFilter !== '') {
        $where[] = "po.status = :status";
        $p[':status'] = $statusFilter;
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT po.*, s.name AS supplier_name, s.code AS supplier_code,
               (SELECT COUNT(*) FROM goods_receipts gr WHERE gr.purchase_order_id = po.id) AS gr_count,
               (SELECT COUNT(*) FROM supplier_invoices sinv WHERE sinv.purchase_order_id = po.id) AS inv_count
        FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id = s.id
        WHERE {$whereSql}
        ORDER BY po.po_date DESC
    ");
    $stmt->execute($p);
    $tableData = $stmt->fetchAll();
} elseif ($tab === 'produksi') {
    $where = ["DATE(po.planned_start) BETWEEN :start_date AND :end_date"];
    $p = [':start_date' => $startDate, ':end_date' => $endDate];
    if ($statusFilter !== '') {
        $where[] = "po.status = :status";
        $p[':status'] = $statusFilter;
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $db->prepare("
        SELECT po.*, po.po_number AS order_number, po.planned_start AS order_date,
               po.target_qty AS qty_planned, po.actual_qty AS qty_completed, po.scrap_qty AS qty_scrap,
               i.name AS item_name, i.code AS item_code, u.code AS unit_symbol,
               COALESCE((SELECT SUM(pc.total_cost) FROM production_costs pc WHERE pc.production_order_id = po.id), 0) AS calculated_cost
        FROM production_orders po
        JOIN items i ON po.product_item_id = i.id
        LEFT JOIN units u ON i.unit_id = u.id
        WHERE {$whereSql}
        ORDER BY po.planned_start DESC
    ");
    $stmt->execute($p);
    $tableData = $stmt->fetchAll();
} elseif ($tab === 'payroll') {
    $stmt = $db->prepare("
        SELECT pr.*, e.nik, e.full_name AS employee_name, d.name AS dept_name, pos.title AS pos_name, pp.period_name
        FROM payroll_records pr
        JOIN employees e ON pr.employee_id = e.id
        JOIN payroll_periods pp ON pr.payroll_period_id = pp.id
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN positions pos ON e.position_id = pos.id
        WHERE DATE(pp.start_date) >= :start_date AND DATE(pp.end_date) <= :end_date
        ORDER BY pp.id DESC, e.nik ASC
    ");
    $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
    $tableData = $stmt->fetchAll();
} elseif ($tab === 'persediaan') {
    $stmt = $db->query("
        SELECT sb.*, i.name AS item_name, i.code AS item_code, c.type AS item_type, i.min_stock, i.cost_price AS buy_price,
               w.name AS warehouse_name, u.code AS unit_symbol,
               (sb.qty_available * i.cost_price) AS inv_value
        FROM stock_balances sb
        JOIN items i ON sb.item_id = i.id
        JOIN item_categories c ON i.category_id = c.id
        JOIN warehouses w ON sb.warehouse_id = w.id
        LEFT JOIN units u ON i.unit_id = u.id
        ORDER BY w.name ASC, c.type ASC, i.name ASC
    ");
    $tableData = $stmt->fetchAll();
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Laporan Operasional &amp; Eksekutif</h1>
        <div class="page-subtitle">Sistem Terintegrasi &bull; Konsolidasi Laporan 4 Siklus Bisnis, Analisis Kinerja Pabrik, dan Unduh Data CSV</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=reports/journals" class="btn btn-secondary">
            📑 Jurnal Umum
        </a>
        <a href="index.php?page=reports/audit" class="btn btn-secondary">
            🔍 Jejak Audit
        </a>
        <button onclick="window.print()" class="btn btn-secondary">
            🖨️ Cetak Halaman
        </button>
    </div>
</div>

<!-- Global Operational Dashboard Metrics -->
<div class="metrics-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
    <div class="metric-card">
        <div class="metric-value" style="color:#0284c7;"><?= formatRupiah($metricSales) ?></div>
        <div class="metric-label">Total Omzet Penjualan (Siklus 1)</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#16a34a;"><?= formatNumber($metricProductionQty, 0) ?> unit</div>
        <div class="metric-label">Hasil Produksi Selesai (Siklus 2)</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#d97706;"><?= formatRupiah($metricProcurement) ?></div>
        <div class="metric-label">Total Belanja Pengadaan (Siklus 3)</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#9333ea;"><?= formatRupiah($metricPayroll) ?></div>
        <div class="metric-label">Total Beban Gaji Karyawan (Siklus 4)</div>
    </div>
</div>

<!-- Navigation Tabs -->
<div class="card mb-4">
    <div style="display:flex; border-bottom:1px solid #e2e8f0; background:#f8fafc; overflow-x:auto;">
        <a href="index.php?page=reports/index&tab=penjualan" style="padding:14px 20px; font-weight:600; text-decoration:none; color:<?= $tab === 'penjualan' ? '#0284c7' : '#64748b' ?>; border-bottom: <?= $tab === 'penjualan' ? '3px solid #0284c7' : '3px solid transparent' ?>; white-space:nowrap;">
            1. Penerimaan &amp; Penjualan
        </a>
        <a href="index.php?page=reports/index&tab=produksi" style="padding:14px 20px; font-weight:600; text-decoration:none; color:<?= $tab === 'produksi' ? '#0284c7' : '#64748b' ?>; border-bottom: <?= $tab === 'produksi' ? '3px solid #0284c7' : '3px solid transparent' ?>; white-space:nowrap;">
            2. Siklus Produksi &amp; HPP
        </a>
        <a href="index.php?page=reports/index&tab=pengadaan" style="padding:14px 20px; font-weight:600; text-decoration:none; color:<?= $tab === 'pengadaan' ? '#0284c7' : '#64748b' ?>; border-bottom: <?= $tab === 'pengadaan' ? '3px solid #0284c7' : '3px solid transparent' ?>; white-space:nowrap;">
            3. Pengeluaran &amp; Pengadaan
        </a>
        <a href="index.php?page=reports/index&tab=payroll" style="padding:14px 20px; font-weight:600; text-decoration:none; color:<?= $tab === 'payroll' ? '#0284c7' : '#64748b' ?>; border-bottom: <?= $tab === 'payroll' ? '3px solid #0284c7' : '3px solid transparent' ?>; white-space:nowrap;">
            4. SDM &amp; Penggajian
        </a>
        <a href="index.php?page=reports/index&tab=persediaan" style="padding:14px 20px; font-weight:600; text-decoration:none; color:<?= $tab === 'persediaan' ? '#0284c7' : '#64748b' ?>; border-bottom: <?= $tab === 'persediaan' ? '3px solid #0284c7' : '3px solid transparent' ?>; white-space:nowrap;">
            📦 Posisi Nilai Persediaan
        </a>
    </div>

    <!-- Filter & Action Header -->
    <div style="padding: 15px 20px; background: #fff; border-bottom: 1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <form action="index.php" method="GET" class="filter-bar" style="margin-bottom:0; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
            <input type="hidden" name="page" value="reports/index">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">

            <?php if ($tab !== 'persediaan'): ?>
                <div class="filter-group">
                    <label style="font-size:12px; margin-right:4px; color:#64748b;">Dari:</label>
                    <input type="date" name="start_date" value="<?= e($startDate) ?>">
                </div>

                <div class="filter-group">
                    <label style="font-size:12px; margin-right:4px; color:#64748b;">Sampai:</label>
                    <input type="date" name="end_date" value="<?= e($endDate) ?>">
                </div>

                <?php if ($tab === 'penjualan' || $tab === 'pengadaan' || $tab === 'produksi'): ?>
                    <div class="filter-group">
                        <select name="status">
                            <option value="">-- Semua Status --</option>
                            <?php if ($tab === 'penjualan'): ?>
                                <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                                <option value="issued" <?= $statusFilter === 'issued' ? 'selected' : '' ?>>Issued</option>
                                <option value="partially_paid" <?= $statusFilter === 'partially_paid' ? 'selected' : '' ?>>Lunas Sebagian</option>
                                <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Lunas</option>
                            <?php elseif ($tab === 'pengadaan'): ?>
                                <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                                <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                                <option value="ordered" <?= $statusFilter === 'ordered' ? 'selected' : '' ?>>Ordered</option>
                                <option value="received" <?= $statusFilter === 'received' ? 'selected' : '' ?>>Received</option>
                                <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <?php elseif ($tab === 'produksi'): ?>
                                <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                                <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <?php endif; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
            <?php endif; ?>
        </form>

        <div>
            <a href="index.php?page=reports/index&tab=<?= e($tab) ?>&export=csv&start_date=<?= e($startDate) ?>&end_date=<?= e($endDate) ?>&status=<?= e($statusFilter) ?>" class="btn btn-secondary btn-sm">
                📥 Ekspor CSV
            </a>
        </div>
    </div>

    <!-- Tab Table View -->
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <?php if ($tab === 'penjualan'): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>No. Faktur</th>
                            <th>Tanggal</th>
                            <th>Pelanggan</th>
                            <th>Jatuh Tempo</th>
                            <th style="text-align:right;">Subtotal</th>
                            <th style="text-align:right;">PPN (11%)</th>
                            <th style="text-align:right;">Total Tagihan</th>
                            <th style="text-align:right;">Sudah Dibayar</th>
                            <th style="text-align:right;">Sisa Piutang</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tableData)): ?>
                            <tr><td colspan="10" style="text-align:center; padding:30px; color:#94a3b8;">Tidak ada data penjualan pada periode ini.</td></tr>
                        <?php else: ?>
                            <?php
                            $sumTotal = 0; $sumPaid = 0; $sumRem = 0;
                            foreach ($tableData as $r):
                                $rem = (float)$r['total_amount'] - (float)$r['paid_amount'];
                                $sumTotal += (float)$r['total_amount'];
                                $sumPaid += (float)$r['paid_amount'];
                                $sumRem += $rem;
                            ?>
                                <tr>
                                    <td><a href="index.php?page=receiving_sales/invoice_detail&id=<?= $r['id'] ?>" style="font-weight:600; color:#0284c7; font-family:monospace;"><?= e($r['invoice_number']) ?></a></td>
                                    <td><?= formatDate($r['invoice_date']) ?></td>
                                    <td><strong><?= e($r['customer_name']) ?></strong></td>
                                    <td><?= formatDate($r['due_date']) ?></td>
                                    <td style="text-align:right;"><?= formatRupiah($r['subtotal']) ?></td>
                                    <td style="text-align:right;"><?= formatRupiah($r['tax_amount']) ?></td>
                                    <td style="text-align:right; font-weight:600;"><?= formatRupiah($r['total_amount']) ?></td>
                                    <td style="text-align:right; color:#16a34a;"><?= formatRupiah($r['paid_amount']) ?></td>
                                    <td style="text-align:right; color:<?= $rem > 0 ? '#dc2626' : '#16a34a' ?>; font-weight:600;"><?= formatRupiah($rem) ?></td>
                                    <td><?= getStatusBadge($r['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background:#f8fafc; font-weight:bold;">
                                <td colspan="6" style="text-align:right;">TOTAL PERIODE:</td>
                                <td style="text-align:right;"><?= formatRupiah($sumTotal) ?></td>
                                <td style="text-align:right; color:#16a34a;"><?= formatRupiah($sumPaid) ?></td>
                                <td style="text-align:right; color:#dc2626;"><?= formatRupiah($sumRem) ?></td>
                                <td></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

            <?php elseif ($tab === 'pengadaan'): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>No. PO</th>
                            <th>Tanggal PO</th>
                            <th>Pemasok / Vendor</th>
                            <th>Tgl Janji Kirim</th>
                            <th style="text-align:right;">Total Nilai PO</th>
                            <th>Penerimaan (GR)</th>
                            <th>Faktur Vendor</th>
                            <th>Status PO</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tableData)): ?>
                            <tr><td colspan="8" style="text-align:center; padding:30px; color:#94a3b8;">Tidak ada data pengadaan pada periode ini.</td></tr>
                        <?php else: ?>
                            <?php
                            $sumPo = 0;
                            foreach ($tableData as $r):
                                $sumPo += (float)$r['total_amount'];
                            ?>
                                <tr>
                                    <td><a href="index.php?page=expenditure_procurement/po_detail&id=<?= $r['id'] ?>" style="font-weight:600; color:#0284c7; font-family:monospace;"><?= e($r['po_number']) ?></a></td>
                                    <td><?= formatDate($r['po_date']) ?></td>
                                    <td><strong><?= e($r['supplier_name']) ?></strong></td>
                                    <td><?= formatDate($r['delivery_date_promised']) ?></td>
                                    <td style="text-align:right; font-weight:600;"><?= formatRupiah($r['total_amount']) ?></td>
                                    <td><span class="badge badge-info"><?= $r['gr_count'] ?> Penerimaan</span></td>
                                    <td><span class="badge badge-secondary"><?= $r['inv_count'] ?> Faktur</span></td>
                                    <td><?= getStatusBadge($r['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background:#f8fafc; font-weight:bold;">
                                <td colspan="4" style="text-align:right;">TOTAL NILAI PO:</td>
                                <td style="text-align:right;"><?= formatRupiah($sumPo) ?></td>
                                <td colspan="3"></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

            <?php elseif ($tab === 'produksi'): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>No. SPK</th>
                            <th>Tanggal Mulai</th>
                            <th>Produk Jadi</th>
                            <th style="text-align:right;">Rencana</th>
                            <th style="text-align:right;">Hasil Selesai</th>
                            <th style="text-align:right;">Scrap</th>
                            <th style="text-align:right;">Estimasi HPP</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tableData)): ?>
                            <tr><td colspan="8" style="text-align:center; padding:30px; color:#94a3b8;">Tidak ada data produksi pada periode ini.</td></tr>
                        <?php else: ?>
                            <?php
                            $sumPlan = 0; $sumComp = 0; $sumScrap = 0; $sumHpp = 0;
                            foreach ($tableData as $r):
                                $sumPlan += (float)$r['qty_planned'];
                                $sumComp += (float)$r['qty_completed'];
                                $sumScrap += (float)$r['qty_scrap'];
                                $sumHpp += (float)$r['calculated_cost'];
                            ?>
                                <tr>
                                    <td><a href="index.php?page=production/order_detail&id=<?= $r['id'] ?>" style="font-weight:600; color:#0284c7; font-family:monospace;"><?= e($r['order_number']) ?></a></td>
                                    <td><?= formatDate($r['order_date']) ?></td>
                                    <td><strong><?= e($r['item_name']) ?></strong> <small>(<?= e($r['item_code']) ?>)</small></td>
                                    <td style="text-align:right;"><?= formatNumber($r['qty_planned']) ?> <?= e($r['unit_symbol']) ?></td>
                                    <td style="text-align:right; color:#16a34a; font-weight:600;"><?= formatNumber($r['qty_completed']) ?> <?= e($r['unit_symbol']) ?></td>
                                    <td style="text-align:right; color:<?= (float)$r['qty_scrap'] > 0 ? '#dc2626' : '#64748b' ?>;"><?= formatNumber($r['qty_scrap']) ?> <?= e($r['unit_symbol']) ?></td>
                                    <td style="text-align:right; font-weight:600;"><?= formatRupiah($r['calculated_cost']) ?></td>
                                    <td><?= getStatusBadge($r['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background:#f8fafc; font-weight:bold;">
                                <td colspan="3" style="text-align:right;">TOTAL PRODUKSI:</td>
                                <td style="text-align:right;"><?= formatNumber($sumPlan) ?></td>
                                <td style="text-align:right; color:#16a34a;"><?= formatNumber($sumComp) ?></td>
                                <td style="text-align:right; color:#dc2626;"><?= formatNumber($sumScrap) ?></td>
                                <td style="text-align:right;"><?= formatRupiah($sumHpp) ?></td>
                                <td></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

            <?php elseif ($tab === 'payroll'): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>NIK</th>
                            <th>Nama Karyawan</th>
                            <th>Departemen &amp; Jabatan</th>
                            <th>Periode Gaji</th>
                            <th style="text-align:right;">Gaji Pokok</th>
                            <th style="text-align:right;">Tunjangan</th>
                            <th style="text-align:right;">Lembur</th>
                            <th style="text-align:right;">Potongan</th>
                            <th style="text-align:right;">Gaji Bersih (THP)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tableData)): ?>
                            <tr><td colspan="10" style="text-align:center; padding:30px; color:#94a3b8;">Tidak ada data payroll pada periode ini.</td></tr>
                        <?php else: ?>
                            <?php
                            $sumBasic = 0; $sumAll = 0; $sumOt = 0; $sumDed = 0; $sumNet = 0;
                            foreach ($tableData as $r):
                                $sumBasic += (float)$r['basic_salary'];
                                $sumAll += (float)$r['allowances_total'];
                                $sumOt += (float)$r['overtime_pay'];
                                $sumDed += (float)$r['deductions_total'];
                                $sumNet += (float)$r['net_salary'];
                            ?>
                                <tr>
                                    <td style="font-family:monospace;"><?= e($r['nik']) ?></td>
                                    <td><strong><?= e($r['employee_name']) ?></strong></td>
                                    <td><span class="badge badge-secondary"><?= e($r['dept_name']) ?></span> <small><?= e($r['pos_name']) ?></small></td>
                                    <td><?= e($r['period_name']) ?></td>
                                    <td style="text-align:right;"><?= formatRupiah($r['basic_salary']) ?></td>
                                    <td style="text-align:right; color:#16a34a;"><?= formatRupiah($r['allowances_total']) ?></td>
                                    <td style="text-align:right; color:#0284c7;"><?= formatRupiah($r['overtime_pay']) ?></td>
                                    <td style="text-align:right; color:#dc2626;">(<?= formatRupiah($r['deductions_total']) ?>)</td>
                                    <td style="text-align:right; font-weight:700; color:#1e293b;"><?= formatRupiah($r['net_salary']) ?></td>
                                    <td><?= getStatusBadge($r['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background:#f8fafc; font-weight:bold;">
                                <td colspan="4" style="text-align:right;">TOTAL BEBAN PAYROLL:</td>
                                <td style="text-align:right;"><?= formatRupiah($sumBasic) ?></td>
                                <td style="text-align:right; color:#16a34a;"><?= formatRupiah($sumAll) ?></td>
                                <td style="text-align:right; color:#0284c7;"><?= formatRupiah($sumOt) ?></td>
                                <td style="text-align:right; color:#dc2626;">(<?= formatRupiah($sumDed) ?>)</td>
                                <td style="text-align:right;"><?= formatRupiah($sumNet) ?></td>
                                <td></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

            <?php elseif ($tab === 'persediaan'): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kode Barang</th>
                            <th>Nama Barang</th>
                            <th>Tipe Kategori</th>
                            <th>Gudang Penyimpanan</th>
                            <th style="text-align:right;">Kuantitas Fisik</th>
                            <th style="text-align:right;">Harga Beli Satuan</th>
                            <th style="text-align:right;">Nilai Valuasi Buku</th>
                            <th>Status Posisi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tableData)): ?>
                            <tr><td colspan="8" style="text-align:center; padding:30px; color:#94a3b8;">Tidak ada data persediaan.</td></tr>
                        <?php else: ?>
                            <?php
                            $sumQty = 0; $sumVal = 0;
                            foreach ($tableData as $r):
                                $sumQty += (float)$r['qty_available'];
                                $sumVal += (float)$r['inv_value'];
                                $isLow = ((float)$r['qty_available'] <= (float)$r['min_stock'] && (float)$r['min_stock'] > 0);
                            ?>
                                <tr>
                                    <td><span style="font-family:monospace; font-weight:600;"><?= e($r['item_code']) ?></span></td>
                                    <td><strong><?= e($r['item_name']) ?></strong></td>
                                    <td><span class="badge badge-secondary"><?= e(ucfirst($r['item_type'])) ?></span></td>
                                    <td><?= e($r['warehouse_name']) ?></td>
                                    <td style="text-align:right; font-weight:600;"><?= formatNumber($r['qty_available']) ?> <?= e($r['unit_symbol']) ?></td>
                                    <td style="text-align:right;"><?= formatRupiah($r['buy_price']) ?></td>
                                    <td style="text-align:right; font-weight:600; color:#0284c7;"><?= formatRupiah($r['inv_value']) ?></td>
                                    <td>
                                        <?php if ($isLow): ?>
                                            <span class="badge badge-danger">Stok Menipis</span>
                                        <?php else: ?>
                                            <span class="badge badge-success">Aman</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background:#f8fafc; font-weight:bold;">
                                <td colspan="4" style="text-align:right;">TOTAL KESELURUHAN:</td>
                                <td style="text-align:right;"><?= formatNumber($sumQty) ?></td>
                                <td></td>
                                <td style="text-align:right; color:#0284c7;"><?= formatRupiah($sumVal) ?></td>
                                <td></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
