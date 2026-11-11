<?php
/**
 * Modul Pengeluaran: Catat Goods Receipt (GR) / Penerimaan Barang
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Catat Penerimaan Barang (GR)';
$breadcrumbCategory = 'Pengeluaran';

$db = getDbConnection();
$user = currentUser();

// Fetch warehouses and active POs
$warehouses = $db->query("SELECT * FROM warehouses WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$openPos = $db->query("
    SELECT po.*, s.name AS supplier_name 
    FROM purchase_orders po 
    JOIN suppliers s ON po.supplier_id = s.id 
    WHERE po.status IN ('approved', 'partially_received') 
    ORDER BY po.id DESC
")->fetchAll();

$selectedPoId = (int)($_GET['po_id'] ?? ($_POST['purchase_order_id'] ?? 0));
$selectedPo = null;
$poItems = [];

if ($selectedPoId > 0) {
    $stmtPo = $db->prepare("
        SELECT po.*, s.name AS supplier_name 
        FROM purchase_orders po 
        JOIN suppliers s ON po.supplier_id = s.id 
        WHERE po.id = :id AND po.status IN ('approved', 'partially_received')
    ");
    $stmtPo->execute([':id' => $selectedPoId]);
    $selectedPo = $stmtPo->fetch();

    if ($selectedPo) {
        $stmtItems = $db->prepare("
            SELECT poi.*, i.code AS item_code, i.name AS item_name, u.name AS unit_name
            FROM purchase_order_items poi
            JOIN items i ON poi.item_id = i.id
            LEFT JOIN units u ON i.unit_id = u.id
            WHERE poi.purchase_order_id = :po_id
        ");
        $stmtItems->execute([':po_id' => $selectedPoId]);
        $poItems = $stmtItems->fetchAll();
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_gr'])) {
    requireCsrf();

    $warehouseId  = (int)($_POST['warehouse_id'] ?? 0);
    $receiptDate  = trim($_POST['receipt_date'] ?? date('Y-m-d'));
    $doSupplier   = trim($_POST['do_supplier'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');

    $poiIds       = $_POST['poi_id'] ?? [];
    $itemIds      = $_POST['item_id'] ?? [];
    $qtyReceived  = $_POST['qty_received'] ?? [];
    $qtyAccepted  = $_POST['qty_accepted'] ?? [];
    $qtyRejected  = $_POST['qty_rejected'] ?? [];
    $qcNotes      = $_POST['item_notes'] ?? [];

    if (!$selectedPo) {
        $errors[] = 'Silakan pilih Purchase Order (PO) yang valid terlebih dahulu.';
    }
    if ($warehouseId <= 0) {
        $errors[] = 'Gudang penerima barang wajib dipilih.';
    }
    if (empty($receiptDate)) {
        $errors[] = 'Tanggal penerimaan wajib diisi.';
    }

    $validLines = [];
    $totalAccepted = 0.0;
    $totalRejected = 0.0;

    foreach ($poiIds as $idx => $poiId) {
        $poiId = (int)$poiId;
        $itemId = (int)($itemIds[$idx] ?? 0);
        $qRec = parseDecimal($qtyReceived[$idx] ?? 0);
        $qAcc = parseDecimal($qtyAccepted[$idx] ?? 0);
        $qRej = parseDecimal($qtyRejected[$idx] ?? 0);
        $n    = trim($qcNotes[$idx] ?? '');

        if ($qRec > 0) {
            // Check remaining PO quantity
            $stmtRem = $db->prepare("SELECT qty_ordered, qty_received FROM purchase_order_items WHERE id = :id");
            $stmtRem->execute([':id' => $poiId]);
            $poiData = $stmtRem->fetch();

            if ($poiData) {
                $remPoi = (float)$poiData['qty_ordered'] - (float)$poiData['qty_received'];
                if ($qRec > $remPoi) {
                    $errors[] = "Kuantitas diterima ({$qRec}) untuk item baris " . ($idx + 1) . " melebihi sisa pesanan PO ({$remPoi}).";
                }
            }

            if (($qAcc + $qRej) > $qRec) {
                $errors[] = "Jumlah diterima ({$qAcc}) + ditolak ({$qRej}) tidak boleh melebihi kuantitas fisik ({$qRec}) pada baris " . ($idx + 1) . ".";
            }

            $totalAccepted += $qAcc;
            $totalRejected += $qRej;

            $validLines[] = [
                'poi_id'       => $poiId,
                'item_id'      => $itemId,
                'qty_received' => $qRec,
                'qty_accepted' => $qAcc,
                'qty_rejected' => $qRej,
                'notes'        => $n,
            ];
        }
    }

    if (empty($validLines)) {
        $errors[] = 'Setidaknya satu item harus memiliki kuantitas barang diterima lebih dari 0.';
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $grNumber = generateDocNumber('GR', 'goods_receipts', 'gr_number');
            $status = ($totalRejected > 0 && $totalAccepted > 0) ? 'partially_accepted' : ($totalRejected > 0 ? 'rejected' : 'accepted');

            // Insert GR Header
            $stmtGr = $db->prepare("
                INSERT INTO goods_receipts 
                (gr_number, purchase_order_id, supplier_id, warehouse_id, receipt_date, do_supplier, notes, status, created_by, created_at)
                VALUES 
                (:gr_number, :po_id, :supplier_id, :warehouse_id, :receipt_date, :do_supplier, :notes, :status, :created_by, NOW())
            ");
            $stmtGr->execute([
                ':gr_number'    => $grNumber,
                ':po_id'        => $selectedPo['id'],
                ':supplier_id'  => $selectedPo['supplier_id'],
                ':warehouse_id' => $warehouseId,
                ':receipt_date' => $receiptDate,
                ':do_supplier'  => $doSupplier,
                ':notes'        => $notes,
                ':status'       => $status,
                ':created_by'   => $user['id'],
            ]);
            $grId = $db->lastInsertId();

            // Insert GR items and update stock & PO items
            $stmtGri = $db->prepare("
                INSERT INTO goods_receipt_items 
                (goods_receipt_id, purchase_order_item_id, item_id, qty_received, qty_accepted, qty_rejected, notes)
                VALUES 
                (:gr_id, :poi_id, :item_id, :qty_rec, :qty_acc, :qty_rej, :notes)
            ");

            $stmtUpdPoi = $db->prepare("
                UPDATE purchase_order_items 
                SET qty_received = qty_received + :qty_acc 
                WHERE id = :id
            ");

            foreach ($validLines as $vl) {
                $stmtGri->execute([
                    ':gr_id'   => $grId,
                    ':poi_id'  => $vl['poi_id'],
                    ':item_id' => $vl['item_id'],
                    ':qty_rec' => $vl['qty_received'],
                    ':qty_acc' => $vl['qty_accepted'],
                    ':qty_rej' => $vl['qty_rejected'],
                    ':notes'   => $vl['notes'],
                ]);

                // Update PO item fulfilled qty with accepted qty
                $stmtUpdPoi->execute([
                    ':qty_acc' => $vl['qty_accepted'],
                    ':id'      => $vl['poi_id'],
                ]);

                // ATOMIC STOCK INCREASE ONLY FOR ACCEPTED QTY (PRD 9.2, 12, 20)
                if ($vl['qty_accepted'] > 0) {
                    InventoryService::addStock(
                        $vl['item_id'],
                        $warehouseId,
                        $vl['qty_accepted'],
                        'goods_receipt',
                        $grId,
                        $grNumber,
                        "Penerimaan barang dari PO {$selectedPo['po_number']}",
                        $user['id'],
                        $db
                    );
                }
            }

            // Check if PO is completely fulfilled
            $stmtCheckPo = $db->prepare("
                SELECT SUM(qty_ordered) AS total_ordered, SUM(qty_received) AS total_rec 
                FROM purchase_order_items 
                WHERE purchase_order_id = :po_id
            ");
            $stmtCheckPo->execute([':po_id' => $selectedPo['id']]);
            $poTotals = $stmtCheckPo->fetch();

            $newPoStatus = ((float)$poTotals['total_rec'] >= (float)$poTotals['total_ordered']) ? 'received' : 'partially_received';
            $updPo = $db->prepare("UPDATE purchase_orders SET status = :status, updated_at = NOW() WHERE id = :id");
            $updPo->execute([':status' => $newPoStatus, ':id' => $selectedPo['id']]);

            logAudit($user['id'], 'create', 'goods_receipts', $grId, null, [
                'gr_number'      => $grNumber,
                'po_id'          => $selectedPo['id'],
                'total_accepted' => $totalAccepted,
                'total_rejected' => $totalRejected,
                'status'         => $status,
            ]);

            $db->commit();

            setFlash('success', "Penerimaan Barang {$grNumber} berhasil dicatat. Stok bertambah untuk barang yang diterima.");
            header('Location: index.php?page=expenditure_procurement/gr_detail&id=' . $grId);
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Gagal menyimpan Penerimaan Barang: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Pencatatan Penerimaan Barang (Goods Receipt)</h1>
        <div class="page-subtitle">Verifikasi Dokumen Surat Jalan, Kondisi Fisik, dan Mutu Barang Masuk</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=expenditure_procurement/gr" class="btn btn-secondary">&laquo; Kembali ke Daftar GR</a>
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

<!-- Step 1: Select PO if not selected -->
<div class="card">
    <div class="card-header">
        <span class="card-title">Langkah 1: Pilih Purchase Order (PO)</span>
    </div>
    <div class="card-body">
        <form action="index.php" method="GET" class="filter-bar" style="margin-bottom: 0;">
            <input type="hidden" name="page" value="expenditure_procurement/gr_create">
            <div class="filter-group" style="flex: 1;">
                <select name="po_id" class="form-control" style="width: 100%;" onchange="this.form.submit()">
                    <option value="">-- Pilih Purchase Order yang Akan Diterima --</option>
                    <?php foreach ($openPos as $op): ?>
                        <option value="<?= $op['id'] ?>" <?= ($selectedPo && $selectedPo['id'] == $op['id']) ? 'selected' : '' ?>>
                            <?= e($op['po_number']) ?> &bull; <?= e($op['supplier_name']) ?> (Tgl: <?= formatDate($op['po_date']) ?>) [<?= e($op['status']) ?>]
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">Pilih PO</button>
        </form>
    </div>
</div>

<?php if ($selectedPo): ?>
    <!-- Step 2: Fill GR details and check items -->
    <form action="index.php?page=expenditure_procurement/gr_create&po_id=<?= $selectedPo['id'] ?>" method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="purchase_order_id" value="<?= $selectedPo['id'] ?>">

        <div class="card">
            <div class="card-header">
                <span class="card-title">Langkah 2: Data Pengiriman & Gudang Penerima</span>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label required">Gudang Penerima</label>
                            <select name="warehouse_id" class="form-control" required>
                                <option value="">-- Pilih Gudang --</option>
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?= $wh['id'] ?>">
                                        <?= e($wh['name']) ?> (<?= e($wh['code']) ?> - <?= e($wh['type']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label required">Tanggal Penerimaan Fisik</label>
                            <input type="date" name="receipt_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">Nomor Surat Jalan Pemasok</label>
                            <input type="text" name="do_supplier" class="form-control" placeholder="Contoh: SJ-BOGASARI-8812">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Catatan Pemeriksaan Penerimaan</label>
                    <input type="text" name="notes" class="form-control" placeholder="Kondisi kemasan, nomor polisi kendaraan, dsb.">
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Langkah 3: Pemeriksaan Kuantitas & Kualitas per Item</span>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Barang / Bahan</th>
                            <th class="text-right">Kuantitas PO</th>
                            <th class="text-right">Sudah Diterima</th>
                            <th class="text-right">Sisa Belum Diterima</th>
                            <th class="text-right" style="width: 140px;">Kuantitas Datang</th>
                            <th class="text-right" style="width: 140px; color: var(--success);">Diterima (Lolos)</th>
                            <th class="text-right" style="width: 140px; color: var(--danger);">Ditolak (Cacat)</th>
                            <th style="width: 200px;">Catatan QC / Temuan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($poItems as $idx => $it): ?>
                            <?php $remaining = (float)$it['qty_ordered'] - (float)$it['qty_received']; ?>
                            <tr>
                                <td>
                                    <strong><?= e($it['item_name']) ?></strong><br>
                                    <code><?= e($it['item_code']) ?></code> (<?= e($it['unit_name']) ?>)
                                    <input type="hidden" name="poi_id[]" value="<?= $it['id'] ?>">
                                    <input type="hidden" name="item_id[]" value="<?= $it['item_id'] ?>">
                                </td>
                                <td class="text-right"><?= formatNumber($it['qty_ordered']) ?></td>
                                <td class="text-right"><?= formatNumber($it['qty_received']) ?></td>
                                <td class="text-right" style="font-weight: 600; color: var(--warning);"><?= formatNumber($remaining) ?></td>
                                <td>
                                    <input type="text" name="qty_received[]" class="form-control text-right" value="<?= formatNumber($remaining) ?>">
                                </td>
                                <td>
                                    <input type="text" name="qty_accepted[]" class="form-control text-right" style="border-color: var(--success-border);" value="<?= formatNumber($remaining) ?>">
                                </td>
                                <td>
                                    <input type="text" name="qty_rejected[]" class="form-control text-right" style="border-color: var(--danger-border);" value="0">
                                </td>
                                <td>
                                    <input type="text" name="item_notes[]" class="form-control" placeholder="Contoh: Kondisi baik, lulus uji">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer" style="display: flex; justify-content: space-between; align-items: center;">
                <span class="form-text" style="color: var(--text-muted); font-size: 12px;">
                    ℹ️ <em>Barang yang ditolak (rejected) tidak akan menambah stok persediaan gudang.</em>
                </span>
                <button type="submit" name="submit_gr" value="1" class="btn btn-success">
                    ✓ Simpan Penerimaan Barang & Perbarui Stok
                </button>
            </div>
        </div>
    </form>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
