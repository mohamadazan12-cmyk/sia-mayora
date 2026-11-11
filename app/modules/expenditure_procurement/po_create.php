<?php
/**
 * Modul Pengeluaran: Buat & Edit Purchase Order (PO)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$db = getDbConnection();
$user = currentUser();

$editId = (int)($_GET['id'] ?? ($_POST['po_id'] ?? 0));
$editPo = null;
$editItems = [];

if ($editId > 0) {
    $stmtPo = $db->prepare("SELECT * FROM purchase_orders WHERE id = :id");
    $stmtPo->execute([':id' => $editId]);
    $editPo = $stmtPo->fetch();

    if (!$editPo) {
        setFlash('error', 'Data Pesanan Pembelian (PO) tidak ditemukan.');
        header('Location: index.php?page=expenditure_procurement/po');
        exit;
    }

    if ($editPo['status'] !== 'draft') {
        setFlash('error', "PO {$editPo['po_number']} berstatus '{$editPo['status']}' dan tidak dapat diedit.");
        header('Location: index.php?page=expenditure_procurement/po_detail&id=' . $editId);
        exit;
    }

    $pageTitle = 'Edit Pesanan Pembelian: ' . $editPo['po_number'];

    $stmtPoi = $db->prepare("SELECT poi.*, i.name AS item_name, i.code AS item_code, u.name AS unit_name FROM purchase_order_items poi JOIN items i ON poi.item_id = i.id LEFT JOIN units u ON i.unit_id = u.id WHERE poi.purchase_order_id = :po_id ORDER BY poi.id ASC");
    $stmtPoi->execute([':po_id' => $editId]);
    $editItems = $stmtPoi->fetchAll();
} else {
    $pageTitle = 'Buat Pesanan Pembelian (PO)';
}

$breadcrumbCategory = 'Pengeluaran';

$suppliers = $db->query("SELECT * FROM suppliers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$items = $db->query("SELECT i.*, u.name AS unit_name FROM items i LEFT JOIN units u ON i.unit_id = u.id WHERE i.is_active = 1 ORDER BY i.name ASC")->fetchAll();
$approvedPrs = $db->query("SELECT * FROM purchase_requisitions WHERE status = 'approved' ORDER BY id DESC")->fetchAll();

$prId = (int)($_GET['pr_id'] ?? 0);
$prefillPr = null;
$prefillItems = [];

if ($prId > 0 && !$editPo) {
    $stmtPr = $db->prepare("SELECT * FROM purchase_requisitions WHERE id = :id AND status = 'approved'");
    $stmtPr->execute([':id' => $prId]);
    $prefillPr = $stmtPr->fetch();

    if ($prefillPr) {
        $stmtItems = $db->prepare("SELECT * FROM purchase_requisition_items WHERE purchase_requisition_id = :pr_id");
        $stmtItems->execute([':pr_id' => $prId]);
        $prefillItems = $stmtItems->fetchAll();
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $supplierId       = (int)($_POST['supplier_id'] ?? 0);
    $selectedPrId     = !empty($_POST['purchase_requisition_id']) ? (int)$_POST['purchase_requisition_id'] : null;
    $poDate           = trim($_POST['po_date'] ?? date('Y-m-d'));
    $expectedDelivery = !empty($_POST['expected_delivery']) ? trim($_POST['expected_delivery']) : null;
    $notes            = trim($_POST['notes'] ?? '');
    $statusAction     = $_POST['status_action'] ?? 'approved'; // draft or approved

    $itemIds    = $_POST['item_id'] ?? [];
    $quantities = $_POST['qty_ordered'] ?? [];
    $prices     = $_POST['unit_price'] ?? [];
    $itemNotes  = $_POST['item_notes'] ?? [];

    if ($supplierId <= 0) {
        $errors[] = 'Pemasok / Vendor wajib dipilih.';
    }
    if (empty($poDate)) {
        $errors[] = 'Tanggal PO wajib diisi.';
    }

    $validItems = [];
    $totalAmount = 0.0;

    foreach ($itemIds as $idx => $itemId) {
        $itemId = (int)$itemId;
        $qty = parseDecimal($quantities[$idx] ?? 0);
        $price = parseDecimal($prices[$idx] ?? 0);
        $n = trim($itemNotes[$idx] ?? '');

        if ($itemId > 0 && $qty > 0 && $price >= 0) {
            $subtotal = $qty * $price;
            $totalAmount += $subtotal;
            $validItems[] = [
                'item_id'    => $itemId,
                'qty'        => $qty,
                'unit_price' => $price,
                'subtotal'   => $subtotal,
                'notes'      => $n,
            ];
        }
    }

    if (empty($validItems)) {
        $errors[] = 'Minimal satu item barang dengan kuantitas dan harga valid harus diisi.';
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $status = ($statusAction === 'draft') ? 'draft' : 'approved';

            if ($editPo) {
                $poId = $editPo['id'];
                $poNumber = $editPo['po_number'];

                $stmt = $db->prepare("
                    UPDATE purchase_orders 
                    SET purchase_requisition_id = :pr_id, supplier_id = :supplier_id, 
                        po_date = :po_date, expected_delivery = :expected_delivery, 
                        total_amount = :total_amount, notes = :notes, status = :status, 
                        updated_at = NOW(), updated_by = :updated_by
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':pr_id'             => $selectedPrId,
                    ':supplier_id'       => $supplierId,
                    ':po_date'           => $poDate,
                    ':expected_delivery' => $expectedDelivery,
                    ':total_amount'      => $totalAmount,
                    ':notes'             => $notes,
                    ':status'            => $status,
                    ':updated_by'        => $user['id'],
                    ':id'                => $poId,
                ]);

                // Delete old items and insert updated items
                $db->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = :po_id")->execute([':po_id' => $poId]);

                $stmtItem = $db->prepare("
                    INSERT INTO purchase_order_items 
                    (purchase_order_id, item_id, qty_ordered, qty_received, unit_price, subtotal, notes)
                    VALUES 
                    (:po_id, :item_id, :qty, 0, :price, :subtotal, :notes)
                ");
                foreach ($validItems as $vi) {
                    $stmtItem->execute([
                        ':po_id'    => $poId,
                        ':item_id'  => $vi['item_id'],
                        ':qty'      => $vi['qty'],
                        ':price'    => $vi['unit_price'],
                        ':subtotal' => $vi['subtotal'],
                        ':notes'    => $vi['notes'],
                    ]);
                }

                // If updated to approved and has linked PR, update PR status to ordered
                if ($status === 'approved' && $selectedPrId) {
                    $updPr = $db->prepare("UPDATE purchase_requisitions SET status = 'ordered', updated_at = NOW() WHERE id = :id");
                    $updPr->execute([':id' => $selectedPrId]);
                }

                logAudit($user['id'], 'update', 'purchase_orders', $poId, $editPo, [
                    'po_number'    => $poNumber,
                    'supplier_id'  => $supplierId,
                    'total_amount' => $totalAmount,
                    'status'       => $status,
                ]);

                $db->commit();
                setFlash('success', "Pesanan Pembelian (PO) {$poNumber} berhasil diperbarui.");
                header('Location: index.php?page=expenditure_procurement/po_detail&id=' . $poId);
                exit;

            } else {
                $poNumber = generateDocNumber('PO', 'purchase_orders', 'po_number');

                $stmt = $db->prepare("
                    INSERT INTO purchase_orders 
                    (po_number, purchase_requisition_id, supplier_id, po_date, expected_delivery, total_amount, notes, status, created_by, created_at)
                    VALUES 
                    (:po_number, :pr_id, :supplier_id, :po_date, :expected_delivery, :total_amount, :notes, :status, :created_by, NOW())
                ");
                $stmt->execute([
                    ':po_number'         => $poNumber,
                    ':pr_id'             => $selectedPrId,
                    ':supplier_id'       => $supplierId,
                    ':po_date'           => $poDate,
                    ':expected_delivery' => $expectedDelivery,
                    ':total_amount'      => $totalAmount,
                    ':notes'             => $notes,
                    ':status'            => $status,
                    ':created_by'        => $user['id'],
                ]);
                $poId = $db->lastInsertId();

                // Insert PO items
                $stmtItem = $db->prepare("
                    INSERT INTO purchase_order_items 
                    (purchase_order_id, item_id, qty_ordered, qty_received, unit_price, subtotal, notes)
                    VALUES 
                    (:po_id, :item_id, :qty, 0, :price, :subtotal, :notes)
                ");

                foreach ($validItems as $vi) {
                    $stmtItem->execute([
                        ':po_id'    => $poId,
                        ':item_id'  => $vi['item_id'],
                        ':qty'      => $vi['qty'],
                        ':price'    => $vi['unit_price'],
                        ':subtotal' => $vi['subtotal'],
                        ':notes'    => $vi['notes'],
                    ]);
                }

                // Update PR status to 'ordered'
                if ($selectedPrId) {
                    $updPr = $db->prepare("UPDATE purchase_requisitions SET status = 'ordered', updated_at = NOW() WHERE id = :id");
                    $updPr->execute([':id' => $selectedPrId]);
                }

                logAudit($user['id'], 'create', 'purchase_orders', $poId, null, [
                    'po_number'    => $poNumber,
                    'supplier_id'  => $supplierId,
                    'total_amount' => $totalAmount,
                    'status'       => $status,
                ]);

                $db->commit();

                setFlash('success', "Pesanan Pembelian (PO) {$poNumber} berhasil diterbitkan.");
                header('Location: index.php?page=expenditure_procurement/po_detail&id=' . $poId);
                exit;
            }
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Gagal menyimpan PO: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= $editPo ? 'Edit Pesanan Pembelian: ' . e($editPo['po_number']) : 'Buat Pesanan Pembelian (PO) Baru' ?></h1>
        <div class="page-subtitle">Siklus Pengadaan &bull; Pemesanan Resmi ke Pemasok</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=expenditure_procurement/po" class="btn btn-secondary">&laquo; Kembali ke Daftar PO</a>
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

<form action="index.php?page=expenditure_procurement/po_create<?= $editPo ? '&id=' . $editPo['id'] : '' ?>" method="POST">
    <?= csrfField() ?>
    <?php if ($editPo): ?>
        <input type="hidden" name="po_id" value="<?= $editPo['id'] ?>">
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <span class="card-title">Informasi Pesanan & Pemasok</span>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label required">Pemasok / Vendor</label>
                        <select name="supplier_id" class="form-control" required>
                            <option value="">-- Pilih Pemasok --</option>
                            <?php 
                            $currSupp = $editPo['supplier_id'] ?? 0;
                            foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $currSupp == $s['id'] ? 'selected' : '' ?>>
                                    <?= e($s['name']) ?> (<?= e($s['code']) ?> - Tempo: <?= $s['payment_terms_days'] ?> hari)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label">Referensi Permintaan Pembelian (PR)</label>
                        <select name="purchase_requisition_id" class="form-control">
                            <option value="">-- Tanpa Referensi PR --</option>
                            <?php 
                            $currPr = $editPo['purchase_requisition_id'] ?? ($prefillPr['id'] ?? 0);
                            foreach ($approvedPrs as $apr): ?>
                                <option value="<?= $apr['id'] ?>" <?= ($currPr == $apr['id']) ? 'selected' : '' ?>>
                                    <?= e($apr['pr_number']) ?> &bull; <?= formatDate($apr['request_date']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label class="form-label required">Tanggal PO</label>
                        <input type="date" name="po_date" class="form-control" value="<?= e($editPo['po_date'] ?? date('Y-m-d')) ?>" required>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label class="form-label">Target Pengiriman</label>
                        <input type="date" name="expected_delivery" class="form-control" value="<?= e($editPo['expected_delivery'] ?? date('Y-m-d', strtotime('+7 days'))) ?>">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Catatan Pesanan / Syarat Pengiriman</label>
                <input type="text" name="notes" class="form-control" value="<?= e($editPo['notes'] ?? '') ?>" placeholder="Contoh: Pengiriman via truk kontainer ke Gudang Bahan Baku">
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span class="card-title">Daftar Barang & Harga Kesepakatan</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Barang / Bahan Baku</th>
                            <th style="width: 20%;" class="text-right">Kuantitas Dipesan</th>
                            <th style="width: 20%;" class="text-right">Harga Satuan (Rp)</th>
                            <th style="width: 20%;">Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $itemsToRender = !empty($editItems) ? $editItems : (!empty($prefillItems) ? $prefillItems : []);
                        if (!empty($itemsToRender)): ?>
                            <?php foreach ($itemsToRender as $pfi): ?>
                                <tr>
                                    <td>
                                        <select name="item_id[]" class="form-control">
                                            <option value="">-- Pilih Barang / Bahan --</option>
                                            <?php foreach ($items as $it): ?>
                                                <option value="<?= $it['id'] ?>" <?= $pfi['item_id'] == $it['id'] ? 'selected' : '' ?>>
                                                    <?= e($it['name']) ?> (<?= e($it['code']) ?> - <?= e($it['unit_name']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" name="qty_ordered[]" class="form-control text-right" value="<?= formatNumber($pfi['qty_ordered'] ?? ($pfi['qty'] ?? 0)) ?>">
                                    </td>
                                    <td>
                                        <input type="text" name="unit_price[]" class="form-control text-right" value="<?= formatNumber($pfi['unit_price'] ?? ($pfi['estimated_price'] ?? 0)) ?>">
                                    </td>
                                    <td>
                                        <input type="text" name="item_notes[]" class="form-control" value="<?= e($pfi['notes'] ?? '') ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php 
                        $emptyRows = max(1, 4 - count($itemsToRender));
                        for ($i = 0; $i < $emptyRows; $i++): ?>
                            <tr>
                                <td>
                                    <select name="item_id[]" class="form-control">
                                        <option value="">-- Pilih Barang / Bahan --</option>
                                        <?php foreach ($items as $it): ?>
                                            <option value="<?= $it['id'] ?>">
                                                <?= e($it['name']) ?> (<?= e($it['code']) ?> - <?= e($it['unit_name']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" name="qty_ordered[]" class="form-control text-right" placeholder="0.00">
                                </td>
                                <td>
                                    <input type="text" name="unit_price[]" class="form-control text-right" placeholder="0">
                                </td>
                                <td>
                                    <input type="text" name="item_notes[]" class="form-control" placeholder="Keterangan opsional">
                                </td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer" style="display: flex; gap: 10px; justify-content: flex-end;">
            <button type="submit" name="status_action" value="draft" class="btn btn-secondary">Simpan Draf PO</button>
            <button type="submit" name="status_action" value="approved" class="btn btn-primary"><?= $editPo ? 'Perbarui & Terbitkan PO &raquo;' : 'Terbitkan PO Resmi &raquo;' ?></button>
        </div>
    </div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
