<?php
/**
 * Modul Persediaan: Saldo Stok & Kartu Mutasi Persediaan (Stock Balances & Movements)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Saldo & Mutasi Stok Persediaan';
$breadcrumbCategory = 'Persediaan';

$db = getDbConnection();
$user = currentUser();

$search = trim($_GET['q'] ?? '');
$whFilter = (int)($_GET['wh_id'] ?? 0);
$lowStockOnly = isset($_GET['low_stock']);
$selectedItemId = (int)($_GET['item_id'] ?? 0);

// Fetch Warehouses & Categories
$warehouses = $db->query("SELECT id, code, name FROM warehouses WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

// Build Stock Balances Query
$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(i.code LIKE :q OR i.name LIKE :q)';
    $params[':q'] = "%{$search}%";
}
if ($whFilter > 0) {
    $where[] = 'sb.warehouse_id = :wh_id';
    $params[':wh_id'] = $whFilter;
}
if ($lowStockOnly) {
    $where[] = 'sb.qty_available <= i.min_stock AND i.min_stock > 0';
}

$whereSql = implode(' AND ', $where);

$stmtStock = $db->prepare("
    SELECT sb.*, i.name AS item_name, i.code AS item_code, c.type AS item_type, i.min_stock, i.cost_price AS buy_price,
           u.name AS unit_name, u.code AS unit_symbol,
           w.name AS warehouse_name, w.code AS warehouse_code,
           (sb.qty_available * i.cost_price) AS inventory_value
    FROM stock_balances sb
    JOIN items i ON sb.item_id = i.id
    JOIN item_categories c ON i.category_id = c.id
    JOIN warehouses w ON sb.warehouse_id = w.id
    LEFT JOIN units u ON i.unit_id = u.id
    WHERE {$whereSql}
    ORDER BY w.name ASC, c.type ASC, i.name ASC
");
$stmtStock->execute($params);
$stockBalances = $stmtStock->fetchAll();

// Summary Totals
$totalQty = 0.0;
$totalValuation = 0.0;
$lowStockCount = 0;

foreach ($stockBalances as $sb) {
    $totalQty += (float)$sb['qty_available'];
    $totalValuation += (float)$sb['inventory_value'];
    if ((float)$sb['qty_available'] <= (float)$sb['min_stock'] && (float)$sb['min_stock'] > 0) {
        $lowStockCount++;
    }
}

// Fetch Stock Movements Log (Recent 25 movements or filtered by item)
$movWhere = ['1=1'];
$movParams = [];

if ($selectedItemId > 0) {
    $movWhere[] = 'sm.item_id = :item_id';
    $movParams[':item_id'] = $selectedItemId;
}
if ($whFilter > 0) {
    $movWhere[] = 'sm.warehouse_id = :mov_wh_id';
    $movParams[':mov_wh_id'] = $whFilter;
}

$movWhereSql = implode(' AND ', $movWhere);

$stmtMov = $db->prepare("
    SELECT sm.*, i.name AS item_name, i.code AS item_code, u.code AS unit_symbol,
           w.name AS warehouse_name, u_acc.full_name AS operator_name
    FROM stock_movements sm
    JOIN items i ON sm.item_id = i.id
    JOIN warehouses w ON sm.warehouse_id = w.id
    LEFT JOIN units u ON i.unit_id = u.id
    LEFT JOIN users u_acc ON sm.user_id = u_acc.id
    WHERE {$movWhereSql}
    ORDER BY sm.id DESC
    LIMIT 30
");
$stmtMov->execute($movParams);
$movements = $stmtMov->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Saldo &amp; Mutasi Stok Persediaan</h1>
        <div class="page-subtitle">Siklus Persediaan &bull; Posisi Stok Fisik Multi-Gudang, Valuasi Inventaris, Kartu Mutasi dan Peringatan Minimum Stock</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=inventory/adjustments" class="btn btn-primary">
            + Penyesuaian Stok (Opname)
        </a>
    </div>
</div>

<!-- Valuation Metrics -->
<div class="metrics-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
    <div class="metric-card">
        <div class="metric-value"><?= count($stockBalances) ?> Baris</div>
        <div class="metric-label">Jumlah Posisi SKU Terdaftar</div>
    </div>
    <div class="metric-card">
        <div class="metric-value"><?= formatNumber($totalQty) ?> unit</div>
        <div class="metric-label">Total Kuantitas Fisik Barang</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#0284c7;"><?= formatRupiah($totalValuation) ?></div>
        <div class="metric-label">Total Valuasi Nilai Persediaan</div>
    </div>
    <div class="metric-card" style="border: <?= $lowStockCount > 0 ? '1px solid #fca5a5; background:#fef2f2;' : '1px solid #e2e8f0;' ?>">
        <div class="metric-value" style="color: <?= $lowStockCount > 0 ? '#dc2626' : '#16a34a' ?>;"><?= $lowStockCount ?> SKU</div>
        <div class="metric-label">Di Bawah Safety Stock</div>
    </div>
</div>

<!-- Filter Bar -->
<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="inventory/stock">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari kode atau nama barang..." style="min-width:240px;">
    </div>
    <div class="filter-group">
        <select name="wh_id">
            <option value="">-- Semua Gudang Penyimpanan --</option>
            <?php foreach ($warehouses as $wh): ?>
                <option value="<?= $wh['id'] ?>" <?= $whFilter === (int)$wh['id'] ? 'selected' : '' ?>>
                    <?= e($wh['name']) ?> (<?= e($wh['code']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group" style="display:flex; align-items:center; gap:6px;">
        <input type="checkbox" name="low_stock" value="1" <?= $lowStockOnly ? 'checked' : '' ?> id="chk_low">
        <label for="chk_low" style="margin-bottom:0; font-size:13px; cursor:pointer;">Hanya Stok Rendah</label>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $whFilter > 0 || $lowStockOnly): ?>
        <a href="index.php?page=inventory/stock" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<!-- Stock Balances Table -->
<div class="card mb-4">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h2 class="card-title">Saldo Fisik Barang di Setiap Gudang</h2>
        <span class="badge badge-info"><?= count($stockBalances) ?> Data</span>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Kode Barang</th>
                        <th>Nama Barang</th>
                        <th>Gudang Lokasi</th>
                        <th>Satuan</th>
                        <th class="text-right">Stok Tersedia</th>
                        <th class="text-right">Safety Stock</th>
                        <th class="text-right">Harga Pokok (Rp)</th>
                        <th class="text-right">Total Nilai Valuasi (Rp)</th>
                        <th class="text-center">Riwayat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stockBalances)): ?>
                        <tr>
                            <td colspan="9" class="text-center" style="padding:30px; color:#64748b;">
                                Tidak ada data saldo persediaan yang sesuai filter pencarian.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($stockBalances as $sb): ?>
                            <?php 
                                $isLow = ((float)$sb['qty_available'] <= (float)$sb['min_stock']) && ((float)$sb['min_stock'] > 0);
                            ?>
                            <tr style="<?= $isLow ? 'background:#fef2f2;' : '' ?>">
                                <td><strong><?= e($sb['item_code']) ?></strong></td>
                                <td><strong><?= e($sb['item_name']) ?></strong></td>
                                <td><?= e($sb['warehouse_name']) ?></td>
                                <td><?= e($sb['unit_symbol'] ?: $sb['unit_name']) ?></td>
                                <td class="text-right font-bold" style="color: <?= $isLow ? '#dc2626' : '#16a34a' ?>;">
                                    <?= formatNumber((float)$sb['qty_available']) ?>
                                    <?= $isLow ? '⚠️' : '' ?>
                                </td>
                                <td class="text-right text-muted"><?= formatNumber((float)$sb['min_stock']) ?></td>
                                <td class="text-right"><?= formatRupiah((float)$sb['buy_price']) ?></td>
                                <td class="text-right font-medium" style="color:#0284c7;"><?= formatRupiah((float)$sb['inventory_value']) ?></td>
                                <td class="text-center">
                                    <a href="index.php?page=inventory/stock&item_id=<?= $sb['item_id'] ?>&wh_id=<?= $sb['warehouse_id'] ?>#kartu_mutasi" class="btn btn-sm btn-secondary">
                                        Kartu Stok
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Stock Movements (Kartu Mutasi) -->
<div class="card mb-4" id="kartu_mutasi">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h2 class="card-title">Kartu Mutasi Fisik Persediaan (Stock Movements Audit)</h2>
        <?php if ($selectedItemId > 0): ?>
            <a href="index.php?page=inventory/stock" class="btn btn-sm btn-secondary">Tampilkan Semua Barang</a>
        <?php endif; ?>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Waktu Mutasi</th>
                        <th>Kode &amp; Nama Barang</th>
                        <th>Gudang</th>
                        <th>Jenis Mutasi</th>
                        <th class="text-right">Kuantitas Masuk</th>
                        <th class="text-right">Kuantitas Keluar</th>
                        <th class="text-right">Saldo Akhir</th>
                        <th>Referensi Dokumen</th>
                        <th>Keterangan / Alasan</th>
                        <th>Operator</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($movements)): ?>
                        <tr>
                            <td colspan="11" class="text-center" style="padding:25px; color:#64748b;">
                                Belum ada riwayat mutasi persediaan yang tercatat.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($movements as $m): ?>
                            <tr>
                                <td><?= formatDateTime($m['created_at']) ?></td>
                                <td>
                                    <strong><?= e($m['item_name']) ?></strong>
                                    <div class="text-muted" style="font-size:11px;"><?= e($m['item_code']) ?></div>
                                </td>
                                <td><?= e($m['warehouse_name']) ?></td>
                                <td>
                                    <?php if ($m['movement_type'] === 'in'): ?>
                                        <span class="badge badge-success">⬇️ Masuk (In)</span>
                                    <?php elseif ($m['movement_type'] === 'out'): ?>
                                        <span class="badge badge-danger">⬆️ Keluar (Out)</span>
                                    <?php else: ?>
                                        <span class="badge badge-info">⚖️ <?= e($m['movement_type']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right font-medium" style="color:#16a34a;">
                                    <?= (float)$m['qty_in'] > 0 ? '+' . formatNumber((float)$m['qty_in']) : '-' ?>
                                </td>
                                <td class="text-right font-medium" style="color:#dc2626;">
                                    <?= (float)$m['qty_out'] > 0 ? '-' . formatNumber((float)$m['qty_out']) : '-' ?>
                                </td>
                                <td class="text-right font-bold"><?= formatNumber((float)$m['balance_after']) ?></td>
                                <td>
                                    <strong><?= e($m['ref_number'] ?: '-') ?></strong>
                                    <small class="text-muted" style="display:block;"><?= e($m['ref_type']) ?></small>
                                </td>
                                <td><?= e($m['notes'] ?: '-') ?></td>
                                <td><?= e($m['operator_name'] ?: 'System') ?></td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-secondary btn-detail-mov"
                                        data-time="<?= formatDateTime($m['created_at']) ?>"
                                        data-item="[<?= e($m['item_code']) ?>] <?= e($m['item_name']) ?>"
                                        data-wh="<?= e($m['warehouse_name']) ?>"
                                        data-type="<?= e($m['movement_type']) ?>"
                                        data-in="<?= (float)$m['qty_in'] > 0 ? '+' . formatNumber((float)$m['qty_in']) : '-' ?>"
                                        data-out="<?= (float)$m['qty_out'] > 0 ? '-' . formatNumber((float)$m['qty_out']) : '-' ?>"
                                        data-balance="<?= formatNumber((float)$m['balance_after']) ?>"
                                        data-ref="<?= e($m['ref_number'] ?: '-') ?> (<?= e($m['ref_type']) ?>)"
                                        data-notes="<?= e($m['notes'] ?: '-') ?>"
                                        data-operator="<?= e($m['operator_name'] ?: 'System') ?>">
                                        Detail
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Detail Mutasi Stok -->
<div id="modalDetailMovement" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; border-radius:8px; max-width:520px; width:90%; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.2);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
            <h3 style="margin:0;">Detail Mutasi Persediaan</h3>
            <button type="button" style="background:none; border:none; font-size:20px; cursor:pointer;" onclick="document.getElementById('modalDetailMovement').style.display='none'">&times;</button>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:13.5px; margin-bottom:15px;">
            <div style="grid-column:1/-1;"><strong>Barang:</strong> <span id="dtMovItem"></span></div>
            <div><strong>Waktu:</strong> <span id="dtMovTime"></span></div>
            <div><strong>Gudang:</strong> <span id="dtMovWh"></span></div>
            <div><strong>Tipe Mutasi:</strong> <span id="dtMovType"></span></div>
            <div><strong>Saldo Akhir:</strong> <span id="dtMovBalance" style="font-weight:700;"></span></div>
            <div><strong>Jumlah Masuk:</strong> <span id="dtMovIn" style="color:#16a34a; font-weight:700;"></span></div>
            <div><strong>Jumlah Keluar:</strong> <span id="dtMovOut" style="color:#dc2626; font-weight:700;"></span></div>
            <div style="grid-column:1/-1;"><strong>Referensi Dokumen:</strong> <span id="dtMovRef"></span></div>
            <div style="grid-column:1/-1;"><strong>Keterangan / Alasan:</strong> <span id="dtMovNotes"></span></div>
            <div style="grid-column:1/-1;"><strong>Petugas Operator:</strong> <span id="dtMovOperator"></span></div>
        </div>
        <div style="display:flex; justify-content:flex-end;">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalDetailMovement').style.display='none'">Tutup</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-detail-mov').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('dtMovItem').innerText = d.item;
            document.getElementById('dtMovTime').innerText = d.time;
            document.getElementById('dtMovWh').innerText = d.wh;
            document.getElementById('dtMovType').innerText = d.type.toUpperCase();
            document.getElementById('dtMovBalance').innerText = d.balance;
            document.getElementById('dtMovIn').innerText = d.in;
            document.getElementById('dtMovOut').innerText = d.out;
            document.getElementById('dtMovRef').innerText = d.ref;
            document.getElementById('dtMovNotes').innerText = d.notes;
            document.getElementById('dtMovOperator').innerText = d.operator;
            document.getElementById('modalDetailMovement').style.display = 'flex';
        });
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
