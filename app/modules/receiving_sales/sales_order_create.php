<?php
/**
 * Modul Penerimaan: Buat & Edit Sales Order (SO)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$db = getDbConnection();
$user = currentUser();

$editId = (int)($_GET['id'] ?? ($_POST['so_id'] ?? 0));
$editSo = null;
$editItems = [];

if ($editId > 0) {
    $stmtSo = $db->prepare("SELECT * FROM sales_orders WHERE id = :id");
    $stmtSo->execute([':id' => $editId]);
    $editSo = $stmtSo->fetch();

    if (!$editSo) {
        setFlash('error', 'Data Sales Order tidak ditemukan.');
        header('Location: index.php?page=receiving_sales/sales_orders');
        exit;
    }

    if (!in_array($editSo['status'], ['draft', 'pending_approval'])) {
        setFlash('error', "Sales Order {$editSo['so_number']} berstatus '{$editSo['status']}' dan tidak dapat diedit.");
        header('Location: index.php?page=receiving_sales/sales_order_detail&id=' . $editId);
        exit;
    }

    $pageTitle = 'Edit Sales Order: ' . $editSo['so_number'];

    $stmtSoi = $db->prepare("SELECT * FROM sales_order_items WHERE sales_order_id = :so_id ORDER BY id ASC");
    $stmtSoi->execute([':so_id' => $editId]);
    $editItems = $stmtSoi->fetchAll();
} else {
    $pageTitle = 'Buat Sales Order (SO) Baru';
}

$breadcrumbCategory = 'Penerimaan';

$customers = $db->query("SELECT * FROM customers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$products = $db->query("
    SELECT i.*, u.name AS unit_name, COALESCE(SUM(sb.qty_available), 0) AS stock_available
    FROM items i
    JOIN item_categories ic ON i.category_id = ic.id
    LEFT JOIN units u ON i.unit_id = u.id
    LEFT JOIN stock_balances sb ON i.id = sb.item_id
    WHERE ic.type = 'barang_jadi' AND i.is_active = 1
    GROUP BY i.id, i.name, i.code, u.name
    ORDER BY i.name ASC
")->fetchAll();

$errors = [];
$warnings = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $customerId   = (int)($_POST['customer_id'] ?? 0);
    $soDate       = trim($_POST['so_date'] ?? date('Y-m-d'));
    $dueDate      = !empty($_POST['due_date']) ? trim($_POST['due_date']) : null;
    $notes        = trim($_POST['notes'] ?? '');
    $custPoRef    = trim($_POST['customer_po_ref'] ?? '');
    $requestOvrd  = isset($_POST['request_credit_override']) ? 1 : 0;
    $overrideNote = trim($_POST['override_reason'] ?? '');

    $itemIds    = $_POST['item_id'] ?? [];
    $quantities = $_POST['qty'] ?? [];
    $prices     = $_POST['unit_price'] ?? [];
    $itemNotes  = $_POST['item_notes'] ?? [];

    if ($customerId <= 0) {
        $errors[] = 'Pelanggan / Distributor wajib dipilih.';
    }

    // Fetch customer credit info
    $stmtCust = $db->prepare("SELECT * FROM customers WHERE id = :id");
    $stmtCust->execute([':id' => $customerId]);
    $customer = $stmtCust->fetch();

    if ($customer && $customer['credit_status'] === 'blocked') {
        $errors[] = "Pelanggan '{$customer['name']}' dalam status DIBLOKIR. Tidak dapat membuat pesanan baru.";
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
        $errors[] = 'Minimal satu produk dengan kuantitas dan harga valid harus diisi.';
    }

    // Check Credit Limit (PRD 7.2 & 7.3)
    $creditLimitExceeded = false;
    if ($customer && empty($errors)) {
        $currentUsed = (float)$customer['credit_used'];
        if ($editSo && (int)$editSo['customer_id'] === $customerId) {
            // Subtract old SO amount if editing
            $currentUsed = max(0, $currentUsed - (float)$editSo['total_amount']);
        }
        $projectedCredit = $currentUsed + $totalAmount;
        if ($projectedCredit > (float)$customer['credit_limit']) {
            $creditLimitExceeded = true;
            if (!$requestOvrd) {
                $errors[] = "Total pesanan (" . formatRupiah($totalAmount) . ") bersama penggunaan kredit saat ini (" . formatRupiah($currentUsed) . ") melampaui limit kredit pelanggan (" . formatRupiah($customer['credit_limit']) . "). Centang 'Minta Pengabaian Kredit (Credit Override)' untuk mengajukan ke Manajer.";
            } elseif (empty($overrideNote)) {
                $errors[] = "Alasan pengajuan override kredit wajib diisi.";
            }
        }
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $status = $creditLimitExceeded ? 'pending_approval' : 'approved';

            if ($editSo) {
                $soId = $editSo['id'];
                $soNumber = $editSo['so_number'];

                $stmtSo = $db->prepare("
                    UPDATE sales_orders 
                    SET customer_id = :cid, so_date = :sdate, due_date = :ddate, 
                        total_amount = :total, notes = :notes, status = :status, 
                        updated_at = NOW(), updated_by = :uid 
                    WHERE id = :id
                ");
                $stmtSo->execute([
                    ':cid'    => $customerId,
                    ':sdate'  => $soDate,
                    ':ddate'  => $dueDate,
                    ':total'  => $totalAmount,
                    ':notes'  => $notes . ($custPoRef ? " (Ref PO: {$custPoRef})" : ""),
                    ':status' => $status,
                    ':uid'    => $user['id'],
                    ':id'     => $soId,
                ]);

                // Delete old items and insert updated items
                $db->prepare("DELETE FROM sales_order_items WHERE sales_order_id = :id")->execute([':id' => $soId]);

                $stmtItem = $db->prepare("
                    INSERT INTO sales_order_items 
                    (sales_order_id, item_id, qty_ordered, qty_shipped, unit_price, subtotal, notes)
                    VALUES 
                    (:so_id, :item_id, :qty, 0, :price, :subtotal, :notes)
                ");

                foreach ($validItems as $vi) {
                    $stmtItem->execute([
                        ':so_id'    => $soId,
                        ':item_id'  => $vi['item_id'],
                        ':qty'      => $vi['qty'],
                        ':price'    => $vi['unit_price'],
                        ':subtotal' => $vi['subtotal'],
                        ':notes'    => $vi['notes'],
                    ]);
                }

                // Handle credit override
                if ($creditLimitExceeded) {
                    $stmtOvrd = $db->prepare("
                        INSERT INTO credit_overrides (customer_id, ref_type, ref_id, requested_by, reason, status, created_at)
                        VALUES (:cid, 'sales_order', :so_id, :uid, :reason, 'pending', NOW())
                    ");
                    $stmtOvrd->execute([
                        ':cid'    => $customerId,
                        ':so_id'  => $soId,
                        ':uid'    => $user['id'],
                        ':reason' => $overrideNote,
                    ]);

                    ApprovalService::submit('sales_order_credit', $soId, $soNumber, 'Permohonan override kredit untuk SO ' . $soNumber, $db);
                }

                logAudit($user['id'], 'update', 'sales_orders', $soId, $editSo, [
                    'so_number'    => $soNumber,
                    'customer_id'  => $customerId,
                    'total_amount' => $totalAmount,
                    'status'       => $status,
                ]);

                $db->commit();
                setFlash('success', "Sales Order {$soNumber} berhasil diperbarui dengan status: " . ($status === 'approved' ? 'Disetujui' : 'Menunggu Approval Override Kredit') . ".");
                header('Location: index.php?page=receiving_sales/sales_order_detail&id=' . $soId);
                exit;

            } else {
                $soNumber = generateDocNumber('SO', 'sales_orders', 'so_number');

                // Insert Sales Order Header
                $stmtSo = $db->prepare("
                    INSERT INTO sales_orders 
                    (so_number, customer_id, so_date, due_date, total_amount, notes, status, created_by, created_at)
                    VALUES 
                    (:snum, :cid, :sdate, :ddate, :total, :notes, :status, :uid, NOW())
                ");
                $stmtSo->execute([
                    ':snum'   => $soNumber,
                    ':cid'    => $customerId,
                    ':sdate'  => $soDate,
                    ':ddate'  => $dueDate,
                    ':total'  => $totalAmount,
                    ':notes'  => $notes . ($custPoRef ? " (Ref PO: {$custPoRef})" : ""),
                    ':status' => $status,
                    ':uid'    => $user['id'],
                ]);
                $soId = $db->lastInsertId();

                // Insert Items
                $stmtItem = $db->prepare("
                    INSERT INTO sales_order_items 
                    (sales_order_id, item_id, qty_ordered, qty_shipped, unit_price, subtotal, notes)
                    VALUES 
                    (:so_id, :item_id, :qty, 0, :price, :subtotal, :notes)
                ");

                foreach ($validItems as $vi) {
                    $stmtItem->execute([
                        ':so_id'    => $soId,
                        ':item_id'  => $vi['item_id'],
                        ':qty'      => $vi['qty'],
                        ':price'    => $vi['unit_price'],
                        ':subtotal' => $vi['subtotal'],
                        ':notes'    => $vi['notes'],
                    ]);
                }

                // If credit override requested, record in credit_overrides table
                if ($creditLimitExceeded) {
                    $stmtOvrd = $db->prepare("
                        INSERT INTO credit_overrides (customer_id, ref_type, ref_id, requested_by, reason, status, created_at)
                        VALUES (:cid, 'sales_order', :so_id, :uid, :reason, 'pending', NOW())
                    ");
                    $stmtOvrd->execute([
                        ':cid'    => $customerId,
                        ':so_id'  => $soId,
                        ':uid'    => $user['id'],
                        ':reason' => $overrideNote,
                    ]);

                    ApprovalService::submit('sales_order_credit', $soId, $soNumber, 'Permohonan override kredit untuk SO ' . $soNumber, $db);
                }

                logAudit($user['id'], 'create', 'sales_orders', $soId, null, [
                    'so_number'    => $soNumber,
                    'customer_id'  => $customerId,
                    'total_amount' => $totalAmount,
                    'status'       => $status,
                ]);

                $db->commit();

                setFlash('success', "Sales Order {$soNumber} berhasil dibuat dengan status: " . ($status === 'approved' ? 'Disetujui (Siap Kirim)' : 'Menunggu Approval Override Kredit') . ".");
                header('Location: index.php?page=receiving_sales/sales_order_detail&id=' . $soId);
                exit;
            }
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Gagal menyimpan Sales Order: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= $editSo ? 'Edit Sales Order: ' . e($editSo['so_number']) : 'Buat Sales Order (SO) Baru' ?></h1>
        <div class="page-subtitle">Siklus Penerimaan &bull; Pencatatan Order Penjualan Resmi</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=receiving_sales/sales_orders" class="btn btn-secondary">&laquo; Kembali ke Daftar SO</a>
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

<form action="index.php?page=receiving_sales/sales_order_create<?= $editSo ? '&id=' . $editSo['id'] : '' ?>" method="POST">
    <?= csrfField() ?>
    <?php if ($editSo): ?>
        <input type="hidden" name="so_id" value="<?= $editSo['id'] ?>">
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <span class="card-title">Informasi Pelanggan & Pesanan</span>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label required">Pelanggan / Distributor</label>
                        <select name="customer_id" class="form-control" required>
                            <option value="">-- Pilih Pelanggan --</option>
                            <?php 
                            $currCust = $editSo['customer_id'] ?? 0;
                            foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $currCust == $c['id'] ? 'selected' : '' ?>>
                                    <?= e($c['name']) ?> (Limit: <?= formatRupiah($c['credit_limit']) ?>, Terpakai: <?= formatRupiah($c['credit_used']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="form-label">No. PO Pelanggan (Bila ada)</label>
                        <input type="text" name="customer_po_ref" class="form-control" placeholder="PO-CUST-XXXX">
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="form-group">
                        <label class="form-label required">Tanggal SO</label>
                        <input type="date" name="so_date" class="form-control" value="<?= e($editSo['so_date'] ?? date('Y-m-d')) ?>" required>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        <label class="form-label">Target Pengiriman (Jatuh Tempo)</label>
                        <input type="date" name="due_date" class="form-control" value="<?= e($editSo['due_date'] ?? date('Y-m-d', strtotime('+3 days'))) ?>">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Catatan Pesanan / Instruksi Khusus Pengiriman</label>
                <input type="text" name="notes" class="form-control" value="<?= e($editSo['notes'] ?? '') ?>" placeholder="Contoh: Kirim via armada truk distributor, bongkar pagi hari">
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span class="card-title">Daftar Barang Jadi yang Dipesan</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Produk Barang Jadi (Stok Tersedia)</th>
                            <th style="width: 20%;" class="text-right">Kuantitas Pesan</th>
                            <th style="width: 20%;" class="text-right">Harga Jual Satuan (Rp)</th>
                            <th style="width: 20%;">Catatan Item</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($editItems)): ?>
                            <?php foreach ($editItems as $ei): ?>
                                <tr>
                                    <td>
                                        <select name="item_id[]" class="form-control">
                                            <option value="">-- Pilih Barang Jadi --</option>
                                            <?php foreach ($products as $pr): ?>
                                                <option value="<?= $pr['id'] ?>" <?= $ei['item_id'] == $pr['id'] ? 'selected' : '' ?>>
                                                    <?= e($pr['name']) ?> (Stok: <?= formatNumber($pr['stock_available']) ?> <?= e($pr['unit_name']) ?> - Std: <?= formatRupiah($pr['selling_price']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" name="qty[]" class="form-control text-right" value="<?= formatNumber($ei['qty_ordered']) ?>">
                                    </td>
                                    <td>
                                        <input type="text" name="unit_price[]" class="form-control text-right" value="<?= formatNumber($ei['unit_price']) ?>">
                                    </td>
                                    <td>
                                        <input type="text" name="item_notes[]" class="form-control" value="<?= e($ei['notes']) ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php 
                        $emptyRows = max(1, 4 - count($editItems));
                        for ($i = 0; $i < $emptyRows; $i++): ?>
                            <tr>
                                <td>
                                    <select name="item_id[]" class="form-control">
                                        <option value="">-- Pilih Barang Jadi --</option>
                                        <?php foreach ($products as $pr): ?>
                                            <option value="<?= $pr['id'] ?>">
                                                <?= e($pr['name']) ?> (Stok: <?= formatNumber($pr['stock_available']) ?> <?= e($pr['unit_name']) ?> - Std: <?= formatRupiah($pr['selling_price']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" name="qty[]" class="form-control text-right" placeholder="0">
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
    </div>

    <!-- Opsi Override Limit Kredit -->
    <div class="card" style="border-left: 4px solid var(--warning);">
        <div class="card-header">
            <span class="card-title">Opsi Permohonan Override Limit Kredit (Jika Diperlukan)</span>
        </div>
        <div class="card-body">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                <input type="checkbox" id="request_ovrd" name="request_credit_override" value="1">
                <label for="request_ovrd" style="font-weight: 600; cursor: pointer;">
                    Ajukan Override Kredit ke Manajer jika pesanan ini melebihi limit kredit pelanggan
                </label>
            </div>
            <div class="form-group">
                <label class="form-label">Alasan Pengajuan Override Kredit</label>
                <input type="text" name="override_reason" class="form-control" placeholder="Contoh: Distributor prioritas, pembayaran PO sebelumnya sedang dalam proses kliring">
            </div>
        </div>
        <div class="card-footer" style="display: flex; justify-content: flex-end;">
            <button type="submit" class="btn btn-primary" style="font-weight: 600;">
                <?= $editSo ? '✓ Simpan Perubahan Sales Order' : '✓ Terbitkan Sales Order Resmi' ?>
            </button>
        </div>
    </div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
