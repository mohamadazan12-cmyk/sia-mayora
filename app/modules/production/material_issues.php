<?php
/**
 * Modul Produksi: Pengeluaran Bahan Baku (Material Issue Slip)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Pengeluaran Bahan Baku (Material Issue)';
$breadcrumbCategory = 'Produksi';

$db = getDbConnection();
$user = currentUser();

$selectedPdoId = (int)($_GET['pdo_id'] ?? ($_POST['production_order_id'] ?? 0));
$selectedPdo = null;
$plannedMaterials = [];

if ($selectedPdoId > 0) {
    $stmtPdo = $db->prepare("
        SELECT pdo.*, i.name AS product_name, i.code AS product_code, wm.name AS wh_name
        FROM production_orders pdo
        JOIN items i ON pdo.product_item_id = i.id
        JOIN warehouses wm ON pdo.warehouse_material_id = wm.id
        WHERE pdo.id = :id
    ");
    $stmtPdo->execute([':id' => $selectedPdoId]);
    $selectedPdo = $stmtPdo->fetch();

    if ($selectedPdo) {
        $stmtMat = $db->prepare("
            SELECT pm.*, i.code AS item_code, i.name AS item_name, u.name AS unit_name,
                   COALESCE(sb.qty_available, 0) AS stock_available
            FROM production_materials pm
            JOIN items i ON pm.item_id = i.id
            LEFT JOIN units u ON pm.unit_id = u.id
            LEFT JOIN stock_balances sb ON (i.id = sb.item_id AND sb.warehouse_id = :wh_id)
            WHERE pm.production_order_id = :pdo_id
        ");
        $stmtMat->execute([
            ':pdo_id' => $selectedPdoId,
            ':wh_id'  => $selectedPdo['warehouse_material_id'],
        ]);
        $plannedMaterials = $stmtMat->fetchAll();
    }
}

$errors = [];

// Handle Material Issue Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_material_issue'])) {
    requireCsrf();

    $issueDate = trim($_POST['issue_date'] ?? date('Y-m-d'));
    $notes     = trim($_POST['notes'] ?? '');

    $pmIds      = $_POST['pm_id'] ?? [];
    $itemIds    = $_POST['item_id'] ?? [];
    $quantities = $_POST['qty_issue'] ?? [];
    $lotBatches = $_POST['lot_batch'] ?? [];

    if (!$selectedPdo) {
        $errors[] = 'Silakan pilih Perintah Produksi yang valid.';
    }

    $validIssues = [];
    foreach ($pmIds as $idx => $pmId) {
        $pmId = (int)$pmId;
        $itemId = (int)($itemIds[$idx] ?? 0);
        $qty = parseDecimal($quantities[$idx] ?? 0);
        $lot = trim($lotBatches[$idx] ?? '');

        if ($itemId > 0 && $qty > 0) {
            $validIssues[] = [
                'pm_id'     => $pmId,
                'item_id'   => $itemId,
                'qty'       => $qty,
                'lot_batch' => $lot,
            ];
        }
    }

    if (empty($validIssues)) {
        $errors[] = 'Minimal satu bahan baku dengan kuantitas lebih dari 0 harus dikeluarkan.';
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $issueNumber = generateDocNumber('MI', 'material_issues', 'issue_number');

            $stmtMi = $db->prepare("
                INSERT INTO material_issues (issue_number, production_order_id, warehouse_id, issue_date, notes, status, created_at, created_by)
                VALUES (:inum, :pdo_id, :wh_id, :idate, :notes, 'issued', NOW(), :uid)
            ");
            $stmtMi->execute([
                ':inum'   => $issueNumber,
                ':pdo_id' => $selectedPdo['id'],
                ':wh_id'  => $selectedPdo['warehouse_material_id'],
                ':idate'  => $issueDate,
                ':notes'  => $notes,
                ':uid'    => $user['id'],
            ]);
            $miId = $db->lastInsertId();

            $stmtMii = $db->prepare("
                INSERT INTO material_issue_items (material_issue_id, production_material_id, item_id, qty, lot_batch)
                VALUES (:mi_id, :pm_id, :item_id, :qty, :lot)
            ");

            $stmtUpdPm = $db->prepare("
                UPDATE production_materials SET qty_actual = qty_actual + :qty WHERE id = :id
            ");

            // Deduct stock atomically for each material (PRD 8.2, 8.4, 12, 20)
            foreach ($validIssues as $vi) {
                $stmtMii->execute([
                    ':mi_id'   => $miId,
                    ':pm_id'   => $vi['pm_id'],
                    ':item_id' => $vi['item_id'],
                    ':qty'     => $vi['qty'],
                    ':lot'     => $vi['lot_batch'],
                ]);

                $stmtUpdPm->execute([
                    ':qty' => $vi['qty'],
                    ':id'  => $vi['pm_id'],
                ]);

                // Reduce inventory atomically with row lock & stock verification
                InventoryService::reduceStock(
                    $vi['item_id'],
                    $selectedPdo['warehouse_material_id'],
                    $vi['qty'],
                    'material_issue',
                    $miId,
                    $issueNumber,
                    "Pengeluaran bahan untuk SPK {$selectedPdo['po_number']}",
                    $user['id'],
                    $db
                );
            }

            // Update PO status to in_progress if currently waiting_material
            if ($selectedPdo['status'] === 'waiting_material' || $selectedPdo['status'] === 'approved') {
                $db->prepare("UPDATE production_orders SET status = 'in_progress', actual_start = CURDATE(), updated_at = NOW() WHERE id = :id")
                   ->execute([':id' => $selectedPdo['id']]);
            }

            logAudit($user['id'], 'create', 'material_issues', $miId, null, [
                'issue_number' => $issueNumber,
                'pdo_id'       => $selectedPdo['id'],
            ]);

            $db->commit();

            setFlash('success', "Bukti Pengeluaran Bahan (Material Issue) {$issueNumber} berhasil diproses. Stok bahan baku di gudang otomatis terpotong.");
            header('Location: index.php?page=production/order_detail&id=' . $selectedPdo['id']);
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Gagal mengeluarkan bahan: ' . $e->getMessage();
        }
    }
}

// Handle Cancel Material Issue (Atomic stock return)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_cancel_issue'])) {
    requireCsrf();
    $miId = (int)($_POST['issue_id'] ?? 0);
    $cancelReason = trim($_POST['cancel_reason'] ?? '');

    if ($miId <= 0 || empty($cancelReason)) {
        setFlash('error', 'ID pengeluaran dan alasan pembatalan wajib diisi.');
        header('Location: index.php?page=production/material_issues');
        exit;
    }

    try {
        $db->beginTransaction();

        $stmtMi = $db->prepare("SELECT * FROM material_issues WHERE id = :id FOR UPDATE");
        $stmtMi->execute([':id' => $miId]);
        $mi = $stmtMi->fetch();

        if (!$mi) {
            throw new Exception('Data pengeluaran bahan tidak ditemukan.');
        }

        if ($mi['status'] !== 'issued') {
            throw new Exception("Bukti pengeluaran tidak dapat dibatalkan karena berstatus {$mi['status']}.");
        }

        // Fetch issued items
        $stmtItems = $db->prepare("SELECT * FROM material_issue_items WHERE material_issue_id = :id");
        $stmtItems->execute([':id' => $miId]);
        $issuedItems = $stmtItems->fetchAll();

        $stmtUpdPm = $db->prepare("UPDATE production_materials SET qty_actual = GREATEST(0, qty_actual - :qty) WHERE id = :pm_id");

        foreach ($issuedItems as $item) {
            // Restock raw materials back to warehouse atomically
            InventoryService::addStock(
                $item['item_id'],
                $mi['warehouse_id'],
                $item['qty'],
                'material_issue_cancel',
                $miId,
                $mi['issue_number'],
                "Pembatalan pengeluaran bahan ({$mi['issue_number']}): {$cancelReason}",
                $user['id'],
                $db
            );

            // Revert production_materials actual qty
            if (!empty($item['production_material_id'])) {
                $stmtUpdPm->execute([
                    ':qty'   => $item['qty'],
                    ':pm_id' => $item['production_material_id'],
                ]);
            }
        }

        // Update status to cancelled
        $db->prepare("
            UPDATE material_issues 
            SET status = 'cancelled', notes = CONCAT_WS(' | Batal: ', notes, :reason), updated_at = NOW(), updated_by = :uid 
            WHERE id = :id
        ")->execute([
            ':reason' => $cancelReason,
            ':uid'    => $user['id'],
            ':id'     => $miId,
        ]);

        logAudit($user['id'], 'cancel', 'material_issues', $miId, ['status' => 'issued'], [
            'status' => 'cancelled',
            'reason' => $cancelReason,
        ]);

        $db->commit();
        setFlash('success', "Bukti Pengeluaran Bahan {$mi['issue_number']} berhasil dibatalkan dan seluruh bahan telah dikembalikan ke stok gudang.");
        header('Location: index.php?page=production/material_issues');
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', 'Gagal membatalkan pengeluaran bahan: ' . $e->getMessage());
        header('Location: index.php?page=production/material_issues');
        exit;
    }
}

// Fetch open Production Orders
$openOrders = $db->query("
    SELECT pdo.*, i.name AS product_name 
    FROM production_orders pdo 
    JOIN items i ON pdo.product_item_id = i.id 
    WHERE pdo.status IN ('approved', 'waiting_material', 'in_progress')
    ORDER BY pdo.id DESC
")->fetchAll();

// Fetch Recent Material Issues
$issues = $db->query("
    SELECT mi.*, pdo.po_number, i.name AS product_name, w.name AS warehouse_name, u.full_name AS issuer_name
    FROM material_issues mi
    JOIN production_orders pdo ON mi.production_order_id = pdo.id
    JOIN items i ON pdo.product_item_id = i.id
    JOIN warehouses w ON mi.warehouse_id = w.id
    LEFT JOIN users u ON mi.created_by = u.id
    ORDER BY mi.id DESC
    LIMIT 20
")->fetchAll();

// Fetch items for recent material issues for the detail view
$issueItemsMap = [];
if (!empty($issues)) {
    $miIds = array_column($issues, 'id');
    $inClause = implode(',', array_map('intval', $miIds));
    $stmtAllItems = $db->query("
        SELECT mii.*, i.name AS item_name, i.code AS item_code, u.name AS unit_name
        FROM material_issue_items mii
        JOIN items i ON mii.item_id = i.id
        LEFT JOIN units u ON i.unit_id = u.id
        WHERE mii.material_issue_id IN ({$inClause})
    ");
    while ($row = $stmtAllItems->fetch()) {
        $issueItemsMap[$row['material_issue_id']][] = $row;
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Pengeluaran Bahan Baku ke Produksi (Material Issue)</h1>
        <div class="page-subtitle">Siklus Produksi &bull; Bukti Pengambilan Bahan dari Gudang dan Pemotongan Stok</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=production/orders" class="btn btn-secondary">&laquo; Kembali ke SPK</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <strong>Terjadi Kesalahan:</strong>
        <ul style="margin-left: 20px; margin-top: 6px;">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Step 1: Select Production Order -->
<div class="card">
    <div class="card-header">
        <span class="card-title">Pilih Perintah Kerja Produksi (SPK)</span>
    </div>
    <div class="card-body">
        <form action="index.php" method="GET" class="filter-bar" style="margin-bottom: 0;">
            <input type="hidden" name="page" value="production/material_issues">
            <div class="filter-group" style="flex: 1;">
                <select name="pdo_id" class="form-control" style="width: 100%;" onchange="this.form.submit()">
                    <option value="">-- Pilih SPK Produksi yang Membutuhkan Bahan --</option>
                    <?php foreach ($openOrders as $oo): ?>
                        <option value="<?= $oo['id'] ?>" <?= ($selectedPdo && $selectedPdo['id'] == $oo['id']) ? 'selected' : '' ?>>
                            <?= e($oo['po_number']) ?> &bull; <?= e($oo['product_name']) ?> (Target: <?= formatNumber($oo['target_qty']) ?>) [<?= e($oo['status']) ?>]
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">Muat Kebutuhan Bahan</button>
        </form>
    </div>
</div>

<?php if ($selectedPdo): ?>
    <form action="index.php?page=production/material_issues&pdo_id=<?= $selectedPdo['id'] ?>" method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="production_order_id" value="<?= $selectedPdo['id'] ?>">

        <div class="card">
            <div class="card-header">
                <span class="card-title">Formulir Pengeluaran Bahan dari Gudang: <?= e($selectedPdo['wh_name']) ?></span>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label required">Tanggal Pengeluaran Bahan</label>
                            <input type="date" name="issue_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Catatan Pengeluaran / Operator Pabrik</label>
                            <input type="text" name="notes" class="form-control" placeholder="Contoh: Diserahkan ke tim mixer adonan shift pagi">
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Barang / Bahan Baku</th>
                                <th class="text-right">Kebutuhan Rencana</th>
                                <th class="text-right">Sudah Dikeluarkan</th>
                                <th class="text-right">Stok Gudang</th>
                                <th class="text-right" style="width: 160px; color: var(--primary);">Kuantitas Dikeluarkan</th>
                                <th style="width: 160px;">No. Lot / Batch</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($plannedMaterials as $idx => $pm): ?>
                                <?php $neededRemaining = max(0, (float)$pm['qty_planned'] - (float)$pm['qty_actual']); ?>
                                <tr>
                                    <td>
                                        <strong><?= e($pm['item_name']) ?></strong><br>
                                        <code><?= e($pm['item_code']) ?></code> (<?= e($pm['unit_name']) ?>)
                                        <input type="hidden" name="pm_id[]" value="<?= $pm['id'] ?>">
                                        <input type="hidden" name="item_id[]" value="<?= $pm['item_id'] ?>">
                                    </td>
                                    <td class="text-right"><?= formatNumber($pm['qty_planned'], 4) ?></td>
                                    <td class="text-right"><?= formatNumber($pm['qty_actual'], 4) ?></td>
                                    <td class="text-right" style="font-weight: 600; color: <?= (float)$pm['stock_available'] >= $neededRemaining ? 'var(--success)' : 'var(--danger)' ?>;">
                                        <?= formatNumber($pm['stock_available'], 2) ?>
                                    </td>
                                    <td>
                                        <input type="text" name="qty_issue[]" class="form-control text-right" style="font-weight: 700;" value="<?= formatNumber($neededRemaining, 4) ?>">
                                    </td>
                                    <td>
                                        <input type="text" name="lot_batch[]" class="form-control" placeholder="LOT-<?= date('ymd') ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer" style="display: flex; justify-content: flex-end;">
                <button type="submit" name="submit_material_issue" value="1" class="btn btn-warning" style="font-weight: 600;">
                    ✓ Konfirmasi Pengeluaran Bahan & Potong Saldo Stok
                </button>
            </div>
        </div>
    </form>
<?php endif; ?>

<!-- Daftar Riwayat Material Issue Terbaru -->
<div class="card">
    <div class="card-header">
        <span class="card-title">Riwayat Bukti Pengeluaran Bahan (Material Issue Slips)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>No. Pengeluaran (MI)</th>
                    <th>Tanggal</th>
                    <th>Perintah Produksi</th>
                    <th>Produk Jadi</th>
                    <th>Gudang Asal</th>
                    <th>Petugas Pengeluar</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($issues)): ?>
                    <tr><td colspan="8" class="text-center" style="padding: 18px; color: var(--text-muted);">Belum ada bukti pengeluaran bahan.</td></tr>
                <?php else: ?>
                    <?php foreach ($issues as $mi): ?>
                        <tr>
                            <td><strong><?= e($mi['issue_number']) ?></strong></td>
                            <td><?= formatDate($mi['issue_date']) ?></td>
                            <td><a href="index.php?page=production/order_detail&id=<?= $mi['production_order_id'] ?>"><strong><?= e($mi['po_number']) ?></strong></a></td>
                            <td><?= e($mi['product_name']) ?></td>
                            <td><?= e($mi['warehouse_name']) ?></td>
                            <td><?= e($mi['issuer_name'] ?: 'Sistem') ?></td>
                            <td class="text-center"><?= getStatusBadge($mi['status']) ?></td>
                            <td class="text-center" style="white-space:nowrap;">
                                <button type="button" class="btn btn-sm btn-secondary btn-detail-mi" 
                                    data-id="<?= $mi['id'] ?>"
                                    data-num="<?= e($mi['issue_number']) ?>"
                                    data-date="<?= formatDate($mi['issue_date']) ?>"
                                    data-po="<?= e($mi['po_number']) ?>"
                                    data-prod="<?= e($mi['product_name']) ?>"
                                    data-wh="<?= e($mi['warehouse_name']) ?>"
                                    data-issuer="<?= e($mi['issuer_name'] ?: 'Sistem') ?>"
                                    data-notes="<?= e($mi['notes'] ?: '-') ?>"
                                    data-status="<?= e($mi['status']) ?>"
                                    data-items='<?= json_encode($issueItemsMap[$mi['id']] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'>
                                    Detail
                                </button>
                                <?php if ($mi['status'] === 'issued'): ?>
                                    <button type="button" class="btn btn-sm btn-danger btn-cancel-mi"
                                        data-id="<?= $mi['id'] ?>"
                                        data-num="<?= e($mi['issue_number']) ?>">
                                        Batalkan
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Detail Pengeluaran Bahan -->
<div id="modalDetailMi" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; border-radius:8px; max-width:650px; width:90%; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.2); max-height:90vh; overflow-y:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
            <h3 style="margin:0;" id="dtMiTitle">Detail Pengeluaran Bahan</h3>
            <button type="button" style="background:none; border:none; font-size:20px; cursor:pointer;" onclick="closeDetailMi()">&times;</button>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:15px; font-size:13.5px;">
            <div><strong>No. Bukti (MI):</strong> <span id="dtMiNum"></span></div>
            <div><strong>Tanggal Pengeluaran:</strong> <span id="dtMiDate"></span></div>
            <div><strong>Ref. SPK Produksi:</strong> <span id="dtMiPo"></span></div>
            <div><strong>Produk Jadi:</strong> <span id="dtMiProd"></span></div>
            <div><strong>Gudang Asal:</strong> <span id="dtMiWh"></span></div>
            <div><strong>Petugas Pengeluar:</strong> <span id="dtMiIssuer"></span></div>
            <div style="grid-column:1/-1;"><strong>Status:</strong> <span id="dtMiStatus"></span></div>
            <div style="grid-column:1/-1;"><strong>Catatan / Alasan:</strong> <span id="dtMiNotes"></span></div>
        </div>
        <h4 style="margin:15px 0 8px 0; font-size:14px;">Daftar Bahan Baku yang Dikeluarkan</h4>
        <div class="table-responsive">
            <table class="table table-bordered" style="font-size:13px;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th>Kode Bahan</th>
                        <th>Nama Bahan Baku</th>
                        <th class="text-right">Kuantitas</th>
                        <th>Satuan</th>
                        <th>No. Lot / Batch</th>
                    </tr>
                </thead>
                <tbody id="dtMiItemsBody">
                </tbody>
            </table>
        </div>
        <div style="display:flex; justify-content:flex-end; margin-top:15px;">
            <button type="button" class="btn btn-secondary" onclick="closeDetailMi()">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal Pembatalan Pengeluaran Bahan -->
<div id="modalCancelMi" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; border-radius:8px; max-width:480px; width:90%; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:var(--danger);">Pembatalan Pengeluaran Bahan (MI)</h3>
        <p style="color:var(--text-muted); font-size:14px;">
            Anda akan membatalkan bukti pengeluaran <strong id="cancelMiNumText"></strong>. 
            Seluruh saldo bahan baku yang tercatat akan <strong>otomatis dikembalikan ke stok gudang</strong> secara atomik.
        </p>
        <form action="index.php?page=production/material_issues" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action_cancel_issue" value="1">
            <input type="hidden" name="issue_id" id="cancelMiId">
            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label required">Alasan Pembatalan / Pengembalian Bahan</label>
                <textarea name="cancel_reason" class="form-control" rows="3" placeholder="Contoh: Kesalahan penyerahan lot bahan / SPK dijadwalkan ulang" required></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeCancelMi()">Tutup</button>
                <button type="submit" class="btn btn-danger">Batalkan &amp; Kembalikan Stok</button>
            </div>
        </form>
    </div>
</div>

<script>
function closeDetailMi() {
    document.getElementById('modalDetailMi').style.display = 'none';
}
function closeCancelMi() {
    document.getElementById('modalCancelMi').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-detail-mi').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('dtMiTitle').innerText = 'Detail Pengeluaran: ' + d.num;
            document.getElementById('dtMiNum').innerText = d.num;
            document.getElementById('dtMiDate').innerText = d.date;
            document.getElementById('dtMiPo').innerText = d.po;
            document.getElementById('dtMiProd').innerText = d.prod;
            document.getElementById('dtMiWh').innerText = d.wh;
            document.getElementById('dtMiIssuer').innerText = d.issuer;
            document.getElementById('dtMiNotes').innerText = d.notes;
            document.getElementById('dtMiStatus').innerHTML = d.status === 'issued' ? '<span class="badge badge-success">Dikeluarkan (Issued)</span>' : '<span class="badge badge-danger">Dibatalkan (Cancelled)</span>';
            
            var tbody = document.getElementById('dtMiItemsBody');
            tbody.innerHTML = '';
            var items = [];
            try {
                items = JSON.parse(d.items || '[]');
            } catch(e) {}
            
            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Tidak ada rincian item bahan.</td></tr>';
            } else {
                items.forEach(function(it) {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td><code>' + (it.item_code || '-') + '</code></td>' +
                                   '<td><strong>' + (it.item_name || '-') + '</strong></td>' +
                                   '<td class="text-right font-weight-bold" style="color:var(--primary);">' + parseFloat(it.qty || 0).toLocaleString('id-ID', {minimumFractionDigits: 2}) + '</td>' +
                                   '<td>' + (it.unit_name || 'unit') + '</td>' +
                                   '<td>' + (it.lot_batch || '-') + '</td>';
                    tbody.appendChild(tr);
                });
            }
            document.getElementById('modalDetailMi').style.display = 'flex';
        });
    });

    document.querySelectorAll('.btn-cancel-mi').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('cancelMiId').value = this.dataset.id;
            document.getElementById('cancelMiNumText').innerText = this.dataset.num;
            document.getElementById('modalCancelMi').style.display = 'flex';
        });
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
