<?php
/**
 * Modul Penerimaan: Detail Surat Jalan & Pengiriman (Delivery Order)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Detail Surat Jalan (DO)';
$breadcrumbCategory = 'Penerimaan';

$db = getDbConnection();
$user = currentUser();

$doId = (int)($_GET['id'] ?? 0);
if ($doId <= 0) {
    setFlash('error', 'ID Surat Jalan tidak valid.');
    header('Location: index.php?page=receiving_sales/delivery_orders');
    exit;
}

// Fetch Delivery Order Header
$stmt = $db->prepare("
    SELECT do_tab.*, so.so_number, so.total_amount AS so_total,
           c.id AS customer_id, c.name AS customer_name, c.code AS customer_code, c.address AS customer_address, c.phone AS customer_phone,
           w.name AS warehouse_name, w.code AS warehouse_code,
           u.full_name AS creator_name
    FROM delivery_orders do_tab
    JOIN sales_orders so ON do_tab.sales_order_id = so.id
    JOIN customers c ON so.customer_id = c.id
    JOIN warehouses w ON do_tab.warehouse_id = w.id
    LEFT JOIN users u ON do_tab.created_by = u.id
    WHERE do_tab.id = :id
");
$stmt->execute([':id' => $doId]);
$do = $stmt->fetch();

if (!$do) {
    setFlash('error', 'Data Surat Jalan tidak ditemukan.');
    header('Location: index.php?page=receiving_sales/delivery_orders');
    exit;
}

// Fetch DO Items
$stmtItems = $db->prepare("
    SELECT doi.*, i.name AS item_name, i.code AS item_code, u.name AS unit_name,
           soi.qty_ordered, soi.qty_shipped AS so_item_shipped_total
    FROM delivery_order_items doi
    JOIN items i ON doi.item_id = i.id
    LEFT JOIN units u ON i.unit_id = u.id
    JOIN sales_order_items soi ON doi.sales_order_item_id = soi.id
    WHERE doi.delivery_order_id = :id
");
$stmtItems->execute([':id' => $doId]);
$doItems = $stmtItems->fetchAll();

// Handle Workflow State Transitions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    try {
        $db->beginTransaction();

        if ($action === 'set_packed' && in_array($do['status'], ['draft', 'picking'])) {
            $db->prepare("UPDATE delivery_orders SET status = 'packed', updated_at = NOW(), updated_by = :uid WHERE id = :id")
               ->execute([':uid' => $user['id'], ':id' => $doId]);

            logAudit($user['id'], 'pack_delivery_order', 'delivery_orders', $doId, ['status' => $do['status']], ['status' => 'packed']);
            $db->commit();
            setFlash('success', "Surat Jalan {$do['do_number']} telah berstatus Packed (Pengepakan Selesai). Siap diberangkatkan.");
            header("Location: index.php?page=receiving_sales/delivery_order_detail&id={$doId}");
            exit;

        } elseif ($action === 'ship_order' && in_array($do['status'], ['draft', 'picking', 'packed'])) {
            // Deduct stock atomically for each item using InventoryService
            foreach ($doItems as $item) {
                InventoryService::reduceStock(
                    $item['item_id'],
                    $do['warehouse_id'],
                    (float)$item['qty_shipped'],
                    'delivery_order',
                    $doId,
                    $do['do_number'],
                    "Pengiriman DO {$do['do_number']} untuk SO {$do['so_number']}",
                    $user['id'],
                    $db
                );

                // Update sales_order_items qty_shipped
                $db->prepare("UPDATE sales_order_items SET qty_shipped = qty_shipped + :qty WHERE id = :soi_id")
                   ->execute([
                       ':qty'    => $item['qty_shipped'],
                       ':soi_id' => $item['sales_order_item_id'],
                   ]);
            }

            // Check if SO is fully delivered
            $stmtCheckSo = $db->prepare("
                SELECT SUM(qty_ordered - qty_shipped) AS remaining
                FROM sales_order_items
                WHERE sales_order_id = :so_id
            ");
            $stmtCheckSo->execute([':so_id' => $do['sales_order_id']]);
            $remainingInSo = (float)$stmtCheckSo->fetchColumn();

            $newSoStatus = ($remainingInSo <= 0.001) ? 'completed' : 'shipped';
            $db->prepare("UPDATE sales_orders SET status = :status, updated_at = NOW(), updated_by = :uid WHERE id = :so_id")
               ->execute([':status' => $newSoStatus, ':uid' => $user['id'], ':so_id' => $do['sales_order_id']]);

            // Update DO status to shipped
            $db->prepare("UPDATE delivery_orders SET status = 'shipped', updated_at = NOW(), updated_by = :uid WHERE id = :id")
               ->execute([':uid' => $user['id'], ':id' => $doId]);

            logAudit($user['id'], 'ship_delivery_order', 'delivery_orders', $doId, ['status' => $do['status']], ['status' => 'shipped']);
            $db->commit();
            setFlash('success', "Surat Jalan {$do['do_number']} berhasil diberangkatkan! Stok persediaan telah dipotong secara atomik dari {$do['warehouse_name']}.");
            header("Location: index.php?page=receiving_sales/delivery_order_detail&id={$doId}");
            exit;

        } elseif ($action === 'confirm_delivered' && $do['status'] === 'shipped') {
            $recipientName = trim($_POST['recipient_name'] ?? $do['recipient_name']);
            $deliveredDate = trim($_POST['delivered_date'] ?? date('Y-m-d H:i:s'));
            $deliveryNotes = trim($_POST['delivery_notes'] ?? '');

            $db->prepare("
                UPDATE delivery_orders 
                SET status = 'delivered', recipient_name = :recipient, delivered_at = :del_at, notes = CONCAT_WS(' | ', notes, :notes), updated_at = NOW(), updated_by = :uid
                WHERE id = :id
            ")->execute([
                ':recipient' => $recipientName,
                ':del_at'    => $deliveredDate,
                ':notes'     => $deliveryNotes ?: 'Diterima oleh pelanggan',
                ':uid'       => $user['id'],
                ':id'        => $doId,
            ]);

            logAudit($user['id'], 'deliver_delivery_order', 'delivery_orders', $doId, ['status' => 'shipped'], ['status' => 'delivered']);
            $db->commit();
            setFlash('success', "Surat Jalan {$do['do_number']} dikonfirmasi telah diterima pelanggan ({$recipientName}). Siap diterbitkan Faktur Penjualan.");
            header("Location: index.php?page=receiving_sales/delivery_order_detail&id={$doId}");
            exit;

        } elseif ($action === 'cancel_order') {
            if ($do['status'] === 'cancelled') {
                throw new Exception("Surat Jalan sudah dibatalkan sebelumnya.");
            }

            // Check if active sales invoice exists
            $refs = checkRecordReferences('delivery_orders', $doId, $db);
            if (!empty($refs)) {
                throw new Exception("Surat Jalan {$do['do_number']} tidak dapat dibatalkan karena telah memiliki tagihan aktif: " . implode(', ', $refs));
            }

            $reason = trim($_POST['cancel_reason'] ?? '');
            if (empty($reason)) {
                throw new Exception('Alasan pembatalan Surat Jalan wajib diisi.');
            }

            // If stock was already deducted (shipped or delivered), restore stock atomically
            if (in_array($do['status'], ['shipped', 'delivered'])) {
                foreach ($doItems as $item) {
                    InventoryService::addStock(
                        $item['item_id'],
                        $do['warehouse_id'],
                        (float)$item['qty_shipped'],
                        'delivery_order',
                        $doId,
                        $do['do_number'],
                        "Pembatalan Surat Jalan DO {$do['do_number']}: {$reason}",
                        $user['id'],
                        $db
                    );

                    // Revert sales_order_items qty_shipped
                    $db->prepare("UPDATE sales_order_items SET qty_shipped = GREATEST(0, qty_shipped - :qty) WHERE id = :soi_id")
                       ->execute([
                           ':qty'    => $item['qty_shipped'],
                           ':soi_id' => $item['sales_order_item_id'],
                       ]);
                }

                // Recalculate SO status
                $stmtCheckSo = $db->prepare("
                    SELECT SUM(qty_ordered) AS tot_ord, SUM(qty_shipped) AS tot_ship
                    FROM sales_order_items
                    WHERE sales_order_id = :so_id
                ");
                $stmtCheckSo->execute([':so_id' => $do['sales_order_id']]);
                $soTotals = $stmtCheckSo->fetch();

                $totShip = (float)($soTotals['tot_ship'] ?? 0);
                $totOrd  = (float)($soTotals['tot_ord'] ?? 0);

                $newSoStatus = ($totShip <= 0.001) ? 'approved' : ($totShip >= $totOrd ? 'completed' : 'shipped');
                $db->prepare("UPDATE sales_orders SET status = :status, updated_at = NOW(), updated_by = :uid WHERE id = :so_id")
                   ->execute([':status' => $newSoStatus, ':uid' => $user['id'], ':so_id' => $do['sales_order_id']]);
            }

            $db->prepare("UPDATE delivery_orders SET status = 'cancelled', notes = CONCAT_WS(' | Batal: ', notes, :reason), updated_at = NOW(), updated_by = :uid WHERE id = :id")
               ->execute([':reason' => $reason, ':uid' => $user['id'], ':id' => $doId]);

            logAudit($user['id'], 'cancel_delivery_order', 'delivery_orders', $doId, ['status' => $do['status']], ['status' => 'cancelled', 'reason' => $reason]);
            $db->commit();
            setFlash('warning', "Surat Jalan {$do['do_number']} telah dibatalkan dan stok persediaan (jika sudah dikirim) telah dipulihkan ke gudang.");
            header("Location: index.php?page=receiving_sales/delivery_order_detail&id={$doId}");
            exit;

        } elseif ($action === 'delete_do') {
            if ($do['status'] !== 'draft') {
                throw new Exception("Hanya Surat Jalan berstatus Draf yang dapat dihapus.");
            }

            $refs = checkRecordReferences('delivery_orders', $doId, $db);
            if (!empty($refs)) {
                throw new Exception("Surat Jalan tidak dapat dihapus karena memiliki keterkaitan data: " . implode(', ', $refs));
            }

            $db->prepare("DELETE FROM delivery_order_items WHERE delivery_order_id = :id")->execute([':id' => $doId]);
            $db->prepare("DELETE FROM delivery_orders WHERE id = :id")->execute([':id' => $doId]);

            logAudit($user['id'], 'delete', 'delivery_orders', $doId, $do, null);
            $db->commit();

            setFlash('success', "Draf Surat Jalan {$do['do_number']} berhasil dihapus.");
            header("Location: index.php?page=receiving_sales/delivery_orders");
            exit;

        } else {
            throw new Exception('Aksi tidak valid atau status dokumen tidak mendukung.');
        }

    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Gagal memproses Surat Jalan: ' . $e->getMessage());
        header("Location: index.php?page=receiving_sales/delivery_order_detail&id={$doId}");
        exit;
    }
}

// Check if an invoice is already created for this DO
$stmtInv = $db->prepare("SELECT id, invoice_number, total_amount, status FROM sales_invoices WHERE delivery_order_id = :do_id");
$stmtInv->execute([':do_id' => $doId]);
$existingInvoice = $stmtInv->fetch();

include __DIR__ . '/../../includes/header.php';
?>

<!-- Print-Only Delivery Slip -->
<div class="print-only" style="display:none; padding: 20px;">
    <div style="border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; display:flex; justify-content:space-between;">
        <div>
            <h2 style="margin:0; font-size:20px;">SURAT JALAN / PENGIRIMAN BARANG</h2>
            <div>PT MANUFAKTUR &amp; DISTRIBUSI OPERASIONAL INDONESIA</div>
            <div style="font-size:12px;">Kawasan Industri Sentul, Bogor, Jawa Barat</div>
        </div>
        <div style="text-align:right;">
            <h3 style="margin:0; font-size:18px;"><?= e($do['do_number']) ?></h3>
            <div>Tanggal: <?= formatDate($do['do_date']) ?></div>
            <div>Ref SO: <?= e($do['so_number']) ?></div>
        </div>
    </div>

    <table style="width:100%; margin-bottom: 20px; font-size:13px;">
        <tr>
            <td style="width:50%; vertical-align:top;">
                <strong>Kepada / Alamat Pengiriman:</strong><br>
                <?= e($do['customer_name']) ?> (<?= e($do['customer_code']) ?>)<br>
                <?= nl2br(e($do['recipient_address'] ?: $do['customer_address'])) ?><br>
                Kontak: <?= e($do['recipient_name'] ?: $do['customer_phone']) ?>
            </td>
            <td style="width:50%; vertical-align:top;">
                <strong>Detail Armada &amp; Pengangkut:</strong><br>
                Gudang Asal: <?= e($do['warehouse_name']) ?><br>
                Supir / Kurir: <?= e($do['driver_name'] ?: '-') ?><br>
                Nomor Polisi: <?= e($do['vehicle_number'] ?: '-') ?><br>
                Status: <?= strtoupper($do['status']) ?>
            </td>
        </tr>
    </table>

    <table class="table" style="width:100%; border-collapse:collapse; margin-bottom:30px;">
        <thead>
            <tr style="border-bottom:1px solid #000; background:#f1f5f9;">
                <th style="padding:6px; text-align:left;">No</th>
                <th style="padding:6px; text-align:left;">Kode Barang</th>
                <th style="padding:6px; text-align:left;">Deskripsi Barang</th>
                <th style="padding:6px; text-align:right;">Qty Dikirim</th>
                <th style="padding:6px; text-align:left;">Satuan</th>
                <th style="padding:6px; text-align:left;">Catatan Kemasan</th>
            </tr>
        </thead>
        <tbody>
            <?php $no = 1; foreach ($doItems as $item): ?>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="padding:6px;"><?= $no++ ?></td>
                    <td style="padding:6px;"><?= e($item['item_code']) ?></td>
                    <td style="padding:6px;"><?= e($item['item_name']) ?></td>
                    <td style="padding:6px; text-align:right; font-weight:bold;"><?= formatNumber((float)$item['qty_shipped']) ?></td>
                    <td style="padding:6px;"><?= e($item['unit_name'] ?? 'unit') ?></td>
                    <td style="padding:6px;"><?= e($item['notes'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="display:flex; justify-content:space-between; margin-top:50px; text-align:center; font-size:13px;">
        <div style="width:30%;">
            <div>Bagian Gudang</div>
            <div style="height:70px;"></div>
            <div>( <?= e($user['full_name']) ?> )</div>
        </div>
        <div style="width:30%;">
            <div>Supir / Pengangkut</div>
            <div style="height:70px;"></div>
            <div>( <?= e($do['driver_name'] ?: '.....................') ?> )</div>
        </div>
        <div style="width:30%;">
            <div>Tanda Terima Pelanggan</div>
            <div style="height:70px;"></div>
            <div>( <?= e($do['recipient_name'] ?: '.....................') ?> )</div>
        </div>
    </div>
</div>

<!-- Screen Display View -->
<div class="no-print">
    <div class="page-header">
        <div>
            <h1 class="page-title">Surat Jalan: <?= e($do['do_number']) ?></h1>
            <div class="page-subtitle">Ref. Sales Order: <a href="index.php?page=receiving_sales/sales_order_detail&id=<?= $do['sales_order_id'] ?>"><strong><?= e($do['so_number']) ?></strong></a> &bull; Pelanggan: <?= e($do['customer_name']) ?></div>
        </div>
        <div class="page-actions" style="display:flex; gap:10px;">
            <button onclick="window.print()" class="btn btn-secondary">
                🖨️ Cetak Surat Jalan
            </button>
            <?php if (in_array($do['status'], ['shipped', 'delivered']) && !$existingInvoice): ?>
                <a href="index.php?page=receiving_sales/invoice_create&do_id=<?= $do['id'] ?>" class="btn btn-primary">
                    + Terbitkan Faktur Penjualan
                </a>
            <?php elseif ($existingInvoice): ?>
                <a href="index.php?page=receiving_sales/invoice_detail&id=<?= $existingInvoice['id'] ?>" class="btn btn-success">
                    Lihat Faktur: <?= e($existingInvoice['invoice_number']) ?>
                </a>
            <?php endif; ?>
            <a href="index.php?page=receiving_sales/delivery_orders" class="btn btn-secondary">
                &larr; Kembali
            </a>
        </div>
    </div>

    <!-- Status & Info Card -->
    <div class="card mb-4">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <h2 class="card-title">Informasi Dokumen Pengiriman</h2>
            <div><?= statusBadge($do['status']) ?></div>
        </div>
        <div class="card-body">
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:20px;">
                <div>
                    <div class="text-muted" style="font-size:12px;">Pelanggan & Tujuan</div>
                    <strong style="font-size:15px;"><?= e($do['customer_name']) ?></strong>
                    <div style="font-size:12px; color:#475569;"><?= e($do['customer_code']) ?></div>
                    <div style="margin-top:5px; font-size:13px;"><?= nl2br(e($do['recipient_address'] ?: $do['customer_address'])) ?></div>
                </div>
                <div>
                    <div class="text-muted" style="font-size:12px;">Tanggal & Gudang</div>
                    <div>Tgl DO: <strong><?= formatDate($do['do_date']) ?></strong></div>
                    <div>Gudang Asal: <strong><?= e($do['warehouse_name']) ?></strong></div>
                    <div>Dibuat oleh: <?= e($do['creator_name'] ?: 'Sistem') ?></div>
                </div>
                <div>
                    <div class="text-muted" style="font-size:12px;">Armada & Pengantaran</div>
                    <div>Supir: <strong><?= e($do['driver_name'] ?: '-') ?></strong></div>
                    <div>No. Kendaraan: <strong><?= e($do['vehicle_number'] ?: '-') ?></strong></div>
                    <div>Kontak Penerima: <strong><?= e($do['recipient_name'] ?: '-') ?></strong></div>
                </div>
                <div>
                    <div class="text-muted" style="font-size:12px;">Status Diterima</div>
                    <?php if ($do['status'] === 'delivered'): ?>
                        <div style="color:#16a34a; font-weight:600;">Sudah Diterima</div>
                        <div style="font-size:12px;"><?= formatDate($do['delivered_at']) ?></div>
                    <?php else: ?>
                        <div style="color:#eab308; font-weight:600;">Belum Selesai</div>
                    <?php endif; ?>
                    <?php if ($do['notes']): ?>
                        <div style="font-size:12px; margin-top:5px; color:#64748b;">Catatan: <?= e($do['notes']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Items Table Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">Barang yang Dikirim</h2>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width:40px;">No</th>
                            <th>Kode & Nama Barang</th>
                            <th class="text-right">Total Dipesan (SO)</th>
                            <th class="text-right">Qty Dikirim (DO Ini)</th>
                            <th class="text-right">Total Terkirim (SO)</th>
                            <th>Catatan Kemasan / Batch</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; foreach ($doItems as $item): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td>
                                    <strong><?= e($item['item_name']) ?></strong>
                                    <div class="text-muted" style="font-size:11px;"><?= e($item['item_code']) ?></div>
                                </td>
                                <td class="text-right"><?= formatNumber((float)$item['qty_ordered']) ?> <?= e($item['unit_name'] ?? 'unit') ?></td>
                                <td class="text-right" style="font-weight:700; color:#0284c7;">
                                    <?= formatNumber((float)$item['qty_shipped']) ?>
                                </td>
                                <td class="text-right"><?= formatNumber((float)$item['so_item_shipped_total']) ?></td>
                                <td><?= e($item['notes'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Workflow Execution Actions Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">Tindakan Alur Kerja (Workflow)</h2>
        </div>
        <div class="card-body">
            <div style="display:flex; flex-wrap:wrap; gap:15px; align-items:center;">
                <?php if (in_array($do['status'], ['draft', 'picking'])): ?>
                    <form action="index.php?page=receiving_sales/delivery_order_detail&id=<?= $doId ?>" method="POST" style="display:inline;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="set_packed">
                        <button type="submit" class="btn btn-secondary">
                            📦 Konfirmasi Selesai Pengepakan (Set Packed)
                        </button>
                    </form>

                    <form action="index.php?page=receiving_sales/delivery_order_detail&id=<?= $doId ?>" method="POST" style="display:inline;" onsubmit="return confirm('Pemberangkatan pesanan akan memotong stok fisik gudang secara atomik. Lanjutkan?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="ship_order">
                        <button type="submit" class="btn btn-primary">
                            🚚 Berangkatkan &amp; Potong Stok Gudang (Ship)
                        </button>
                    </form>
                <?php elseif ($do['status'] === 'packed'): ?>
                    <form action="index.php?page=receiving_sales/delivery_order_detail&id=<?= $doId ?>" method="POST" style="display:inline;" onsubmit="return confirm('Pemberangkatan pesanan akan memotong stok fisik gudang secara atomik. Lanjutkan?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="ship_order">
                        <button type="submit" class="btn btn-primary">
                            🚚 Berangkatkan &amp; Potong Stok Gudang (Ship)
                        </button>
                    </form>
                <?php elseif ($do['status'] === 'shipped'): ?>
                    <!-- Form Delivered -->
                    <form action="index.php?page=receiving_sales/delivery_order_detail&id=<?= $doId ?>" method="POST" style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="confirm_delivered">
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Nama Penerima Barang (Tanda Tangan)</label>
                            <input type="text" name="recipient_name" value="<?= e($do['recipient_name'] ?: $do['customer_name']) ?>" required>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Catatan Serah Terima</label>
                            <input type="text" name="delivery_notes" placeholder="Kondisi baik, barang lengkap...">
                        </div>
                        <button type="submit" class="btn btn-success">
                            ✓ Konfirmasi Telah Diterima (Delivered)
                        </button>
                    </form>
                <?php elseif ($do['status'] === 'delivered'): ?>
                    <div class="alert alert-success" style="margin-bottom:0; width:100%;">
                        ✓ Pengiriman telah selesai dan diterima oleh <strong><?= e($do['recipient_name']) ?></strong> pada <?= formatDate($do['delivered_at']) ?>.
                    </div>
                <?php elseif ($do['status'] === 'cancelled'): ?>
                    <div class="alert alert-danger" style="margin-bottom:0; width:100%;">
                        ✕ Dokumen Surat Jalan ini telah dibatalkan.
                    </div>
                <?php endif; ?>

                <?php if ($do['status'] !== 'cancelled' && !$existingInvoice): ?>
                    <button type="button" class="btn btn-danger btn-sm" style="margin-left:auto;" onclick="openModal('cancel-do-modal')">
                        ✕ Batalkan DO &amp; Pulihkan Stok
                    </button>
                <?php endif; ?>

                <?php if ($do['status'] === 'draft'): ?>
                    <form action="index.php?page=receiving_sales/delivery_order_detail&id=<?= $doId ?>" method="POST" style="display:inline;" onsubmit="return confirm('Hapus draf Surat Jalan ini secara permanen?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete_do">
                        <button type="submit" class="btn btn-secondary btn-sm" style="color:var(--danger); border-color:var(--danger);">
                            🗑 Hapus Draf
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal Pembatalan DO -->
<div id="cancel-do-modal" class="modal-overlay">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Konfirmasi Pembatalan Surat Jalan: <?= e($do['do_number']) ?></h3>
            <button type="button" class="modal-close" onclick="closeModal('cancel-do-modal')">&times;</button>
        </div>
        <form action="index.php?page=receiving_sales/delivery_order_detail&id=<?= $doId ?>" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_order">
            <div class="modal-body">
                <p style="color: var(--danger); font-size: 13.5px; margin-bottom: 12px;">
                    Perhatian: Membatalkan Surat Jalan ini akan secara otomatis mengembalikan (menambah) stok barang jadi ke gudang asal (jika telah dikirim) dan memulihkan kuantitas pada Sales Order terkait.
                </p>
                <div class="form-group">
                    <label class="form-label required">Alasan Pembatalan Surat Jalan</label>
                    <textarea name="cancel_reason" class="form-control" rows="3" required placeholder="Jelaskan alasan resmi pembatalan pengiriman..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('cancel-do-modal')">Tutup</button>
                <button type="submit" class="btn btn-danger">Ya, Batalkan &amp; Pulihkan Stok</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
