<?php
/**
 * Modul Produksi: Buat Perintah Kerja Produksi Baru (PPIC)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Buat Perintah Produksi (SPK)';
$breadcrumbCategory = 'Produksi';

$db = getDbConnection();
$user = currentUser();

// Fetch finished good products that have a BOM
$productsWithBom = $db->query("
    SELECT i.*, b.id AS bom_id, b.code AS bom_code, b.name AS bom_name, u.name AS unit_name
    FROM items i
    JOIN boms b ON i.id = b.product_item_id
    LEFT JOIN units u ON i.unit_id = u.id
    WHERE i.is_active = 1 AND b.is_active = 1
    ORDER BY i.name ASC
")->fetchAll();

$warehousesMat = $db->query("SELECT * FROM warehouses WHERE type IN ('bahan_baku', 'umum') AND is_active = 1 ORDER BY name ASC")->fetchAll();
$warehousesOut = $db->query("SELECT * FROM warehouses WHERE type IN ('barang_jadi', 'umum') AND is_active = 1 ORDER BY name ASC")->fetchAll();

$selectedProductId = (int)($_GET['product_id'] ?? ($_POST['product_item_id'] ?? 0));
$targetQty = parseDecimal($_GET['target_qty'] ?? ($_POST['target_qty'] ?? 100));
$materialWhId = (int)($_GET['warehouse_material_id'] ?? ($_POST['warehouse_material_id'] ?? 1));

// Edit Mode detection
$editId = (int)($_GET['id'] ?? ($_POST['edit_id'] ?? 0));
$isEdit = false;
$existingOrder = null;
$selectedWhOutId = (int)($_POST['warehouse_output_id'] ?? 0);
$plannedStartVal = trim($_POST['planned_start'] ?? date('Y-m-d'));
$plannedEndVal   = trim($_POST['planned_end'] ?? date('Y-m-d', strtotime('+1 day')));
$notesVal        = trim($_POST['notes'] ?? '');

if ($editId > 0) {
    $stmtExist = $db->prepare("SELECT * FROM production_orders WHERE id = :id");
    $stmtExist->execute([':id' => $editId]);
    $existingOrder = $stmtExist->fetch();
    if ($existingOrder) {
        if (!in_array($existingOrder['status'], ['draft', 'pending_approval'])) {
            setFlash('error', "Perintah produksi ({$existingOrder['po_number']}) tidak dapat diedit karena statusnya '{$existingOrder['status']}'.");
            header("Location: index.php?page=production/order_detail&id={$editId}");
            exit;
        }
        $isEdit = true;
        $pageTitle = 'Edit Perintah Produksi: ' . $existingOrder['po_number'];
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['product_id'])) {
            $selectedProductId = (int)$existingOrder['product_item_id'];
            $targetQty = (float)$existingOrder['target_qty'];
            $materialWhId = (int)$existingOrder['warehouse_material_id'];
            $selectedWhOutId = (int)$existingOrder['warehouse_output_id'];
            $plannedStartVal = $existingOrder['planned_start'];
            $plannedEndVal   = $existingOrder['planned_end'];
            $notesVal        = $existingOrder['notes'] ?? '';
        }
    }
}

$bomItems = [];
$selectedProduct = null;

if ($selectedProductId > 0) {
    foreach ($productsWithBom as $p) {
        if ($p['id'] == $selectedProductId) {
            $selectedProduct = $p;
            break;
        }
    }

    if ($selectedProduct) {
        $stmtBi = $db->prepare("
            SELECT bi.*, i.code AS item_code, i.name AS item_name, u.name AS unit_name,
                   COALESCE(sb.qty_available, 0) AS stock_available
            FROM bom_items bi
            JOIN items i ON bi.item_id = i.id
            LEFT JOIN units u ON bi.unit_id = u.id
            LEFT JOIN stock_balances sb ON (i.id = sb.item_id AND sb.warehouse_id = :wh_id)
            WHERE bi.bom_id = :bom_id
        ");
        $stmtBi->execute([
            ':bom_id' => $selectedProduct['bom_id'],
            ':wh_id'  => $materialWhId,
        ]);
        $bomItems = $stmtBi->fetchAll();
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_order'])) {
    requireCsrf();

    $productId    = (int)($_POST['product_item_id'] ?? 0);
    $target       = parseDecimal($_POST['target_qty'] ?? 0);
    $whMatId      = (int)($_POST['warehouse_material_id'] ?? 0);
    $whOutId      = (int)($_POST['warehouse_output_id'] ?? 0);
    $plannedStart = trim($_POST['planned_start'] ?? date('Y-m-d'));
    $plannedEnd   = trim($_POST['planned_end'] ?? date('Y-m-d', strtotime('+2 days')));
    $notes        = trim($_POST['notes'] ?? '');
    $statusAction = $_POST['status_action'] ?? 'approved'; // PPIC can approve SPK directly

    if ($productId <= 0) {
        $errors[] = 'Produk yang akan diproduksi wajib dipilih.';
    }
    if ($target <= 0) {
        $errors[] = 'Target kuantitas produksi harus lebih besar dari 0.';
    }
    if ($whMatId <= 0 || $whOutId <= 0) {
        $errors[] = 'Gudang asal bahan dan gudang tujuan barang jadi wajib dipilih.';
    }

    if (empty($errors) && $selectedProduct) {
        $db->beginTransaction();
        try {
            if ($isEdit) {
                $status = ($statusAction === 'draft') ? 'draft' : 'approved';
                $stmtUpd = $db->prepare("
                    UPDATE production_orders 
                    SET target_qty = :target, warehouse_material_id = :wh_mat, warehouse_output_id = :wh_out,
                        planned_start = :pstart, planned_end = :pend, notes = :notes, status = :status,
                        updated_at = NOW(), updated_by = :uid
                    WHERE id = :id
                ");
                $stmtUpd->execute([
                    ':target' => $target,
                    ':wh_mat' => $whMatId,
                    ':wh_out' => $whOutId,
                    ':pstart' => $plannedStart,
                    ':pend'   => $plannedEnd,
                    ':notes'  => $notes,
                    ':status' => $status,
                    ':uid'    => $user['id'],
                    ':id'     => $editId,
                ]);

                // Update planned materials from BOM
                $db->prepare("DELETE FROM production_materials WHERE production_order_id = :id")->execute([':id' => $editId]);

                $stmtMat = $db->prepare("
                    INSERT INTO production_materials 
                    (production_order_id, item_id, qty_planned, qty_actual, unit_id, notes)
                    VALUES 
                    (:pdo_id, :item_id, :qty_planned, 0, :unit_id, :notes)
                ");

                foreach ($bomItems as $bi) {
                    $qtyNeeded = (float)$bi['qty_per_unit'] * $target;
                    $stmtMat->execute([
                        ':pdo_id'      => $editId,
                        ':item_id'     => $bi['item_id'],
                        ':qty_planned' => $qtyNeeded,
                        ':unit_id'     => $bi['unit_id'],
                        ':notes'       => "Standar formula BOM: {$bi['qty_per_unit']} per unit produk",
                    ]);
                }

                logAudit($user['id'], 'update', 'production_orders', $editId, $existingOrder, [
                    'po_number'  => $existingOrder['po_number'],
                    'target_qty' => $target,
                    'status'     => $status,
                ]);

                $db->commit();
                setFlash('success', "Perintah Kerja Produksi (SPK) {$existingOrder['po_number']} berhasil diperbarui.");
                header('Location: index.php?page=production/order_detail&id=' . $editId);
                exit;
            } else {
                $spkNumber = generateDocNumber('WO', 'production_orders', 'po_number');
                $status = ($statusAction === 'draft') ? 'draft' : 'approved';

                $stmtPdo = $db->prepare("
                    INSERT INTO production_orders 
                    (po_number, product_item_id, bom_id, warehouse_material_id, warehouse_output_id, 
                     target_qty, actual_qty, scrap_qty, planned_start, planned_end, notes, status, created_by, created_at)
                    VALUES 
                    (:pnum, :pid, :bid, :wh_mat, :wh_out,
                     :target, 0, 0, :pstart, :pend, :notes, :status, :created_by, NOW())
                ");
                $stmtPdo->execute([
                    ':pnum'       => $spkNumber,
                    ':pid'        => $productId,
                    ':bid'        => $selectedProduct['bom_id'],
                    ':wh_mat'     => $whMatId,
                    ':wh_out'     => $whOutId,
                    ':target'     => $target,
                    ':pstart'     => $plannedStart,
                    ':pend'       => $plannedEnd,
                    ':notes'      => $notes,
                    ':status'     => $status,
                    ':created_by' => $user['id'],
                ]);
                $pdoId = $db->lastInsertId();

                // Insert planned materials from BOM
                $stmtMat = $db->prepare("
                    INSERT INTO production_materials 
                    (production_order_id, item_id, qty_planned, qty_actual, unit_id, notes)
                    VALUES 
                    (:pdo_id, :item_id, :qty_planned, 0, :unit_id, :notes)
                ");

                foreach ($bomItems as $bi) {
                    $qtyNeeded = (float)$bi['qty_per_unit'] * $target;
                    $stmtMat->execute([
                        ':pdo_id'      => $pdoId,
                        ':item_id'     => $bi['item_id'],
                        ':qty_planned' => $qtyNeeded,
                        ':unit_id'     => $bi['unit_id'],
                        ':notes'       => "Standar formula BOM: {$bi['qty_per_unit']} per unit produk",
                    ]);
                }

                logAudit($user['id'], 'create', 'production_orders', $pdoId, null, [
                    'po_number'  => $spkNumber,
                    'product_id' => $productId,
                    'target_qty' => $target,
                    'status'     => $status,
                ]);

                $db->commit();

                setFlash('success', "Perintah Kerja Produksi (SPK) {$spkNumber} berhasil diterbitkan.");
                header('Location: index.php?page=production/order_detail&id=' . $pdoId);
                exit;
            }
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Gagal menyimpan perintah produksi: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= $isEdit ? 'Edit Perintah Produksi: ' . e($existingOrder['po_number']) : 'Buat Perintah Kerja Produksi (SPK)' ?></h1>
        <div class="page-subtitle">Siklus Produksi &bull; Perencanaan Kebutuhan Bahan (BOM) dan Penjadwalan Kerja</div>
    </div>
    <div class="page-actions">
        <?php if ($isEdit): ?>
            <a href="index.php?page=production/order_detail&id=<?= $editId ?>" class="btn btn-secondary">&laquo; Kembali ke Detail SPK</a>
        <?php else: ?>
            <a href="index.php?page=production/orders" class="btn btn-secondary">&laquo; Kembali ke Daftar SPK</a>
        <?php endif; ?>
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

<!-- Step 1: Select Product and Target -->
<div class="card">
    <div class="card-header">
        <span class="card-title">Langkah 1: Tentukan Produk Jadi & Target Batch Produksi</span>
    </div>
    <div class="card-body">
        <form action="index.php" method="GET" class="filter-bar" style="margin-bottom: 0;">
            <input type="hidden" name="page" value="production/order_create">
            <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?= $editId ?>">
            <?php endif; ?>
            <div class="filter-group" style="flex: 2;">
                <label style="font-weight: 600; font-size: 13px;">Produk Jadi (Resep BOM):</label>
                <select name="product_id" class="form-control" style="width: 100%;" onchange="this.form.submit()">
                    <option value="">-- Pilih Produk Jadi yang Memiliki Resep --</option>
                    <?php foreach ($productsWithBom as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= ($selectedProductId == $p['id']) ? 'selected' : '' ?>>
                            <?= e($p['name']) ?> (<?= e($p['code']) ?>) &bull; [<?= e($p['bom_code']) ?>]
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label style="font-weight: 600; font-size: 13px;">Target Kuantitas:</label>
                <input type="text" name="target_qty" value="<?= formatNumber($targetQty) ?>" class="form-control text-right" style="width: 110px;">
            </div>
            <div class="filter-group">
                <label style="font-weight: 600; font-size: 13px;">Gudang Bahan:</label>
                <select name="warehouse_material_id" class="form-control">
                    <?php foreach ($warehousesMat as $wm): ?>
                        <option value="<?= $wm['id'] ?>" <?= $materialWhId == $wm['id'] ? 'selected' : '' ?>><?= e($wm['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">Hitung Kebutuhan Bahan (BOM)</button>
        </form>
    </div>
</div>

<?php if ($selectedProduct): ?>
    <form action="index.php?page=production/order_create<?= $isEdit ? '&id=' . $editId : '' ?>&product_id=<?= $selectedProduct['id'] ?>" method="POST">
        <?= csrfField() ?>
        <?php if ($isEdit): ?>
            <input type="hidden" name="edit_id" value="<?= $editId ?>">
        <?php endif; ?>
        <input type="hidden" name="product_item_id" value="<?= $selectedProduct['id'] ?>">
        <input type="hidden" name="target_qty" value="<?= $targetQty ?>">
        <input type="hidden" name="warehouse_material_id" value="<?= $materialWhId ?>">

        <div class="card">
            <div class="card-header">
                <span class="card-title">Langkah 2: Penjadwalan & Gudang Penyimpanan Hasil</span>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label required">Gudang Penyimpanan Barang Jadi</label>
                            <select name="warehouse_output_id" class="form-control" required>
                                <option value="">-- Pilih Gudang Barang Jadi --</option>
                                <?php foreach ($warehousesOut as $wo): ?>
                                    <option value="<?= $wo['id'] ?>" <?= ($selectedWhOutId == $wo['id'] || ($selectedWhOutId == 0 && $wo['type'] === 'barang_jadi')) ? 'selected' : '' ?>>
                                        <?= e($wo['name']) ?> (<?= e($wo['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label required">Rencana Tanggal Mulai</label>
                            <input type="date" name="planned_start" class="form-control" value="<?= e($plannedStartVal) ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label required">Rencana Tanggal Selesai</label>
                            <input type="date" name="planned_end" class="form-control" value="<?= e($plannedEndVal) ?>" required>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Catatan Instruksi Khusus Produksi</label>
                    <input type="text" name="notes" class="form-control" value="<?= e($notesVal) ?>" placeholder="Contoh: Perhatikan suhu oven 180°C dan waktu pengadukan mixer 20 menit">
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Kalkulasi Kebutuhan Bahan Baku Berdasarkan Resep BOM (Target: <?= formatNumber($targetQty) ?> <?= e($selectedProduct['unit_name']) ?>)</span>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Barang / Bahan Baku</th>
                            <th class="text-right">Kebutuhan per Unit</th>
                            <th class="text-right">Total Kebutuhan Bahan</th>
                            <th>Satuan</th>
                            <th class="text-right">Stok Tersedia di Gudang</th>
                            <th class="text-center">Kecukupan Stok</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bomItems as $bi): ?>
                            <?php 
                            $totalNeeded = (float)$bi['qty_per_unit'] * $targetQty; 
                            $isSufficient = (float)$bi['stock_available'] >= $totalNeeded;
                            ?>
                            <tr>
                                <td>
                                    <strong><?= e($bi['item_name']) ?></strong><br>
                                    <code><?= e($bi['item_code']) ?></code>
                                </td>
                                <td class="text-right"><?= formatNumber($bi['qty_per_unit'], 4) ?></td>
                                <td class="text-right font-weight-bold" style="color: var(--primary);">
                                    <?= formatNumber($totalNeeded, 2) ?>
                                </td>
                                <td><?= e($bi['unit_name']) ?></td>
                                <td class="text-right"><?= formatNumber($bi['stock_available'], 2) ?></td>
                                <td class="text-center">
                                    <?php if ($isSufficient): ?>
                                        <span class="badge badge-success">✓ Cukup</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">⚠️ Kurang <?= formatNumber($totalNeeded - (float)$bi['stock_available'], 2) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer" style="display: flex; gap: 10px; justify-content: flex-end;">
                <?php if ($isEdit): ?>
                    <button type="submit" name="submit_order" value="1" class="btn btn-primary">
                        Simpan Perubahan SPK &raquo;
                    </button>
                <?php else: ?>
                    <button type="submit" name="status_action" value="draft" class="btn btn-secondary">Simpan Draf SPK</button>
                    <button type="submit" name="submit_order" value="1" class="btn btn-primary">
                        Terbitkan Perintah Kerja Produksi (SPK) &raquo;
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </form>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
