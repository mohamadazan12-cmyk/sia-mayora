<?php
/**
 * Modul Penerimaan: Pembuatan Surat Jalan / Delivery Order (DO) Baru
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Buat Surat Jalan (DO) Baru';
$breadcrumbCategory = 'Penerimaan';

$db = getDbConnection();
$user = currentUser();

$selectedSoId = (int)($_GET['so_id'] ?? 0);
$selectedWarehouseId = (int)($_GET['warehouse_id'] ?? 0);

// Fetch approved / in-progress Sales Orders that have unfulfilled items
$soQuery = $db->query("
    SELECT so.id, so.so_number, so.so_date, c.name AS customer_name, c.code AS customer_code,
           COUNT(soi.id) AS total_items,
           SUM(soi.qty_ordered - soi.qty_shipped) AS remaining_qty
    FROM sales_orders so
    JOIN customers c ON so.customer_id = c.id
    JOIN sales_order_items soi ON so.id = soi.sales_order_id
    WHERE so.status IN ('approved', 'picking', 'shipped')
    GROUP BY so.id
    HAVING remaining_qty > 0
    ORDER BY so.id DESC
");
$availableSOs = $soQuery->fetchAll();

// Fetch warehouses (default to Gudang Barang Jadi or first warehouse)
$warehouses = $db->query("SELECT id, code, name FROM warehouses WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
if ($selectedWarehouseId <= 0 && !empty($warehouses)) {
    // Try to find warehouse with 'Jadi' in name or fallback to index 1
    foreach ($warehouses as $wh) {
        if (stripos($wh['name'], 'Jadi') !== false) {
            $selectedWarehouseId = $wh['id'];
            break;
        }
    }
    if ($selectedWarehouseId <= 0) {
        $selectedWarehouseId = $warehouses[0]['id'];
    }
}

// If SO is selected, fetch SO details & items with remaining quantities
$selectedSO = null;
$soItems = [];
if ($selectedSoId > 0) {
    $stmtSo = $db->prepare("
        SELECT so.*, c.name AS customer_name, c.code AS customer_code, c.address AS customer_address, c.phone AS customer_phone
        FROM sales_orders so
        JOIN customers c ON so.customer_id = c.id
        WHERE so.id = :id
    ");
    $stmtSo->execute([':id' => $selectedSoId]);
    $selectedSO = $stmtSo->fetch();

    if ($selectedSO) {
        $stmtItems = $db->prepare("
            SELECT soi.*, i.name AS item_name, i.code AS item_code, u.name AS unit_name,
                   COALESCE(sb.qty_available, 0) AS stock_available
            FROM sales_order_items soi
            JOIN items i ON soi.item_id = i.id
            LEFT JOIN units u ON i.unit_id = u.id
            LEFT JOIN stock_balances sb ON sb.item_id = i.id AND sb.warehouse_id = :wh_id
            WHERE soi.sales_order_id = :so_id AND (soi.qty_ordered - soi.qty_shipped) > 0
        ");
        $stmtItems->execute([
            ':so_id' => $selectedSoId,
            ':wh_id' => $selectedWarehouseId,
        ]);
        $soItems = $stmtItems->fetchAll();
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $soId = (int)($_POST['sales_order_id'] ?? 0);
    $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
    $doDate = trim($_POST['do_date'] ?? date('Y-m-d'));
    $recipientName = trim($_POST['recipient_name'] ?? '');
    $recipientAddress = trim($_POST['recipient_address'] ?? '');
    $driverName = trim($_POST['driver_name'] ?? '');
    $vehicleNumber = trim($_POST['vehicle_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $itemsInput = $_POST['items'] ?? [];

    $errors = [];

    if ($soId <= 0) {
        $errors[] = 'Silakan pilih Sales Order yang valid.';
    }
    if ($warehouseId <= 0) {
        $errors[] = 'Silakan pilih Gudang Pengeluaran Barang.';
    }
    if (empty($doDate)) {
        $errors[] = 'Tanggal pengiriman wajib diisi.';
    }
    if (empty($itemsInput)) {
        $errors[] = 'Tidak ada item yang dipilih untuk dikirim.';
    }

    // Verify SO status
    $stmtCheck = $db->prepare("SELECT * FROM sales_orders WHERE id = :id FOR UPDATE");
    $stmtCheck->execute([':id' => $soId]);
    $soRecord = $stmtCheck->fetch();
    if (!$soRecord || !in_array($soRecord['status'], ['approved', 'picking', 'shipped'])) {
        $errors[] = 'Sales Order belum disetujui atau sudah selesai.';
    }

    $validItems = [];
    foreach ($itemsInput as $soItemId => $itemData) {
        $qtyShip = (float)($itemData['qty'] ?? 0);
        if ($qtyShip <= 0) {
            continue;
        }

        // Fetch original SO item
        $stmtSoItem = $db->prepare("
            SELECT soi.*, i.name AS item_name, i.code AS item_code 
            FROM sales_order_items soi
            JOIN items i ON soi.item_id = i.id
            WHERE soi.id = :id AND soi.sales_order_id = :so_id
        ");
        $stmtSoItem->execute([':id' => $soItemId, ':so_id' => $soId]);
        $origItem = $stmtSoItem->fetch();

        if (!$origItem) {
            $errors[] = "Item SO ID #{$soItemId} tidak valid.";
            continue;
        }

        $remainingQty = (float)$origItem['qty_ordered'] - (float)$origItem['qty_shipped'];
        if ($qtyShip > $remainingQty + 0.0001) {
            $errors[] = "Kuantitas kirim untuk {$origItem['item_name']} ({$qtyShip}) melebihi sisa pesanan ({$remainingQty}).";
        }

        // Check stock availability in selected warehouse
        $stockBalance = InventoryService::getBalance($origItem['item_id'], $warehouseId, $db);
        $availableStock = (float)$stockBalance['qty_available'];
        if ($qtyShip > $availableStock) {
            $errors[] = "Stok tidak mencukupi untuk {$origItem['item_name']} di gudang terpilih! Tersedia: {$availableStock}, dibutuhkan: {$qtyShip}.";
        }

        $validItems[] = [
            'so_item_id' => $origItem['id'],
            'item_id'    => $origItem['item_id'],
            'qty'        => $qtyShip,
            'notes'      => trim($itemData['notes'] ?? ''),
        ];
    }

    if (empty($validItems) && empty($errors)) {
        $errors[] = 'Kuantitas kirim setidaknya harus lebih dari 0 pada satu item.';
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // Generate DO Number: DO-YYYYMM-XXXX
            $yearMonth = date('Ym', strtotime($doDate));
            $prefix = 'DO-' . $yearMonth;
            $stmtLast = $db->prepare("SELECT do_number FROM delivery_orders WHERE do_number LIKE :pfx ORDER BY id DESC LIMIT 1");
            $stmtLast->execute([':pfx' => $prefix . '-%']);
            $lastDo = $stmtLast->fetchColumn();
            $seq = 1;
            if ($lastDo) {
                $parts = explode('-', $lastDo);
                $seq = (int)end($parts) + 1;
            }
            $doNumber = sprintf('%s-%04d', $prefix, $seq);

            // Insert Delivery Order
            $stmtInsert = $db->prepare("
                INSERT INTO delivery_orders 
                (do_number, sales_order_id, warehouse_id, do_date, recipient_name, recipient_address, driver_name, vehicle_number, notes, status, created_by, created_at)
                VALUES 
                (:do_number, :so_id, :warehouse_id, :do_date, :recipient_name, :recipient_address, :driver_name, :vehicle_number, :notes, 'picking', :created_by, NOW())
            ");
            $stmtInsert->execute([
                ':do_number'         => $doNumber,
                ':so_id'             => $soId,
                ':warehouse_id'      => $warehouseId,
                ':do_date'           => $doDate,
                ':recipient_name'    => $recipientName,
                ':recipient_address' => $recipientAddress,
                ':driver_name'       => $driverName,
                ':vehicle_number'    => $vehicleNumber,
                ':notes'             => $notes,
                ':created_by'        => $user['id'],
            ]);
            $doId = $db->lastInsertId();

            // Insert Delivery Order Items
            $stmtInsertItem = $db->prepare("
                INSERT INTO delivery_order_items (delivery_order_id, sales_order_item_id, item_id, qty_shipped, notes)
                VALUES (:do_id, :so_item_id, :item_id, :qty_shipped, :notes)
            ");

            foreach ($validItems as $vItem) {
                $stmtInsertItem->execute([
                    ':do_id'             => $doId,
                    ':so_item_id'        => $vItem['so_item_id'],
                    ':item_id'           => $vItem['item_id'],
                    ':qty_shipped'       => $vItem['qty'],
                    ':notes'             => $vItem['notes'],
                ]);
            }

            // Update SO status to picking if currently approved
            $db->prepare("UPDATE sales_orders SET status = 'picking', updated_at = NOW(), updated_by = :uid WHERE id = :so_id AND status = 'approved'")
               ->execute([':uid' => $user['id'], ':so_id' => $soId]);

            logAudit($user['id'], 'create_delivery_order', 'delivery_orders', $doId, null, [
                'do_number' => $doNumber,
                'so_id'     => $soId,
                'items'     => count($validItems),
            ]);

            $db->commit();
            setFlash('success', "Surat Jalan / Delivery Order {$doNumber} berhasil dibuat dengan status Picking.");
            header("Location: index.php?page=receiving_sales/delivery_order_detail&id={$doId}");
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Gagal menyimpan Surat Jalan: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Buat Surat Jalan (Delivery Order) Baru</h1>
        <div class="page-subtitle">Siklus Penerimaan &bull; Alokasikan Barang Jadi dari Gudang ke Pesanan Pelanggan</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=receiving_sales/delivery_orders" class="btn btn-secondary">
            &larr; Kembali ke Daftar DO
        </a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul style="margin: 0; padding-left: 20px;">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Step 1: Select Sales Order & Warehouse -->
<div class="card mb-4">
    <div class="card-header">
        <h2 class="card-title">1. Pilih Sales Order & Gudang Pengeluaran</h2>
    </div>
    <div class="card-body">
        <form action="index.php" method="GET" style="display: grid; grid-template-columns: 2fr 1fr auto; gap: 15px; align-items: flex-end;">
            <input type="hidden" name="page" value="receiving_sales/delivery_order_create">
            <div class="form-group" style="margin-bottom:0;">
                <label>Sales Order (SO) Disetujui</label>
                <select name="so_id" required>
                    <option value="">-- Pilih Sales Order yang Siap Dikirim --</option>
                    <?php foreach ($availableSOs as $aso): ?>
                        <option value="<?= $aso['id'] ?>" <?= $selectedSoId === (int)$aso['id'] ? 'selected' : '' ?>>
                            <?= e($aso['so_number']) ?> &bull; <?= e($aso['customer_name']) ?> (Sisa: <?= formatNumber((float)$aso['remaining_qty']) ?> unit)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>Gudang Pengeluaran</label>
                <select name="warehouse_id">
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?= $wh['id'] ?>" <?= $selectedWarehouseId === (int)$wh['id'] ? 'selected' : '' ?>>
                            <?= e($wh['name']) ?> (<?= e($wh['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-primary" style="height: 42px;">Muat Item SO &rarr;</button>
            </div>
        </form>
    </div>
</div>

<?php if ($selectedSO): ?>
<form action="index.php?page=receiving_sales/delivery_order_create&so_id=<?= $selectedSoId ?>&warehouse_id=<?= $selectedWarehouseId ?>" method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="sales_order_id" value="<?= $selectedSoId ?>">
    <input type="hidden" name="warehouse_id" value="<?= $selectedWarehouseId ?>">

    <!-- Details Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">2. Informasi Pengiriman & Ekspedisi</h2>
        </div>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px;">
                <div class="form-group">
                    <label>Pelanggan</label>
                    <input type="text" value="<?= e($selectedSO['customer_name']) ?> (<?= e($selectedSO['customer_code']) ?>)" readonly style="background:#f1f5f9;">
                </div>
                <div class="form-group">
                    <label>Tanggal Surat Jalan *</label>
                    <input type="date" name="do_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>Nama Penerima / Kontak Tujuan</label>
                    <input type="text" name="recipient_name" value="<?= e($selectedSO['customer_name']) ?>" placeholder="Nama PIC Penerima di lokasi tujuan">
                </div>
                <div class="form-group">
                    <label>Nama Supir / Kurir</label>
                    <input type="text" name="driver_name" placeholder="Misal: Budi Santoso (Ekspedisi Internal)">
                </div>
                <div class="form-group">
                    <label>Nomor Polisi / Armada Kendaraan</label>
                    <input type="text" name="vehicle_number" placeholder="Misal: B 9812 SXY (Truk Engkel)">
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label>Alamat Pengiriman</label>
                    <textarea name="recipient_address" rows="2" placeholder="Alamat lengkap tujuan pengiriman"><?= e($selectedSO['customer_address'] ?? '') ?></textarea>
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label>Catatan Pengiriman / Instruksi Khusus</label>
                    <input type="text" name="notes" placeholder="Misal: Harap bawa tanda terima rangkap 3, hubungi sebelum sampai">
                </div>
            </div>
        </div>
    </div>

    <!-- Items Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">3. Daftar Item yang Dikirim</h2>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kode & Nama Barang</th>
                            <th class="text-right">Dipesan</th>
                            <th class="text-right">Telah Dikirim</th>
                            <th class="text-right">Sisa Belum Kirim</th>
                            <th class="text-right">Stok Gudang</th>
                            <th style="width: 160px;" class="text-right">Qty Kirim Saat Ini *</th>
                            <th>Keterangan Item</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($soItems)): ?>
                            <tr>
                                <td colspan="7" class="text-center" style="padding: 20px; color:#64748b;">
                                    Semua item pada Sales Order ini telah terpenuhi/dikirim secara penuh.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($soItems as $item): ?>
                                <?php 
                                    $remQty = (float)$item['qty_ordered'] - (float)$item['qty_shipped']; 
                                    $stock = (float)$item['stock_available'];
                                    $defaultShip = min($remQty, $stock);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= e($item['item_name']) ?></strong>
                                        <div class="text-muted" style="font-size: 11px;"><?= e($item['item_code']) ?></div>
                                    </td>
                                    <td class="text-right"><?= formatNumber((float)$item['qty_ordered']) ?> <?= e($item['unit_name'] ?? 'unit') ?></td>
                                    <td class="text-right"><?= formatNumber((float)$item['qty_shipped']) ?></td>
                                    <td class="text-right" style="color: #0284c7; font-weight: 600;">
                                        <?= formatNumber($remQty) ?>
                                    </td>
                                    <td class="text-right" style="color: <?= $stock < $remQty ? '#dc2626' : '#16a34a' ?>; font-weight: 600;">
                                        <?= formatNumber($stock) ?>
                                        <?php if ($stock < $remQty): ?>
                                            <div style="font-size:10px; color:#dc2626;">(Stok Kurang)</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0" max="<?= min($remQty, $stock) ?>" 
                                               name="items[<?= $item['id'] ?>][qty]" 
                                               value="<?= $defaultShip > 0 ? $defaultShip : 0 ?>" 
                                               style="text-align: right; font-weight: 600;" required>
                                    </td>
                                    <td>
                                        <input type="text" name="items[<?= $item['id'] ?>][notes]" placeholder="Catatan kemasan/batch...">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer" style="padding: 15px 20px; background: #f8fafc; display: flex; justify-content: flex-end; gap: 10px;">
            <a href="index.php?page=receiving_sales/delivery_orders" class="btn btn-secondary">Batal</a>
            <button type="submit" class="btn btn-primary" <?= empty($soItems) ? 'disabled' : '' ?>>
                Simpan & Lanjutkan ke Picking &rarr;
            </button>
        </div>
    </div>
</form>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
