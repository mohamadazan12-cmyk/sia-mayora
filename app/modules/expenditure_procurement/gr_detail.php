<?php
/**
 * Modul Pengeluaran: Detail Goods Receipt (GR)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Detail Penerimaan Barang';
$breadcrumbCategory = 'Pengeluaran';

$db = getDbConnection();
$user = currentUser();

$grId = (int)($_GET['id'] ?? 0);
if ($grId <= 0) {
    setFlash('error', 'ID Penerimaan Barang tidak valid.');
    header('Location: index.php?page=expenditure_procurement/gr');
    exit;
}

// Fetch GR Header
$stmt = $db->prepare("
    SELECT gr.*, s.name AS supplier_name, s.code AS supplier_code,
           po.po_number, po.po_date, w.name AS warehouse_name, u.full_name AS receiver_name
    FROM goods_receipts gr
    JOIN suppliers s ON gr.supplier_id = s.id
    JOIN purchase_orders po ON gr.purchase_order_id = po.id
    JOIN warehouses w ON gr.warehouse_id = w.id
    LEFT JOIN users u ON gr.created_by = u.id
    WHERE gr.id = :id
");
$stmt->execute([':id' => $grId]);
$gr = $stmt->fetch();

if (!$gr) {
    setFlash('error', 'Data Penerimaan Barang tidak ditemukan.');
    header('Location: index.php?page=expenditure_procurement/gr');
    exit;
}

// Fetch Items
$stmtItems = $db->prepare("
    SELECT gri.*, i.code AS item_code, i.name AS item_name, un.name AS unit_name
    FROM goods_receipt_items gri
    JOIN items i ON gri.item_id = i.id
    LEFT JOIN units un ON i.unit_id = un.id
    WHERE gri.goods_receipt_id = :gr_id
    ORDER BY gri.id ASC
");
$stmtItems->execute([':gr_id' => $grId]);
$items = $stmtItems->fetchAll();

// Check if Supplier Invoice exists for this GR
$stmtInv = $db->prepare("SELECT id, invoice_number, status, total_amount FROM supplier_invoices WHERE goods_receipt_id = :gr_id");
$stmtInv->execute([':gr_id' => $grId]);
$existingInvoice = $stmtInv->fetch();

// Handle Cancellation with Physical Inventory Reversal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'cancel_gr') {
            if ($gr['status'] === 'cancelled') {
                throw new Exception("Penerimaan barang {$gr['gr_number']} sudah dibatalkan sebelumnya.");
            }

            $cancelReason = trim($_POST['cancel_reason'] ?? '');
            if (empty($cancelReason)) {
                throw new Exception('Alasan pembatalan Penerimaan Barang wajib diisi.');
            }

            // Verify dependencies: Active invoices or returns
            $refs = checkRecordReferences('goods_receipts', $grId, $db);
            if (!empty($refs)) {
                throw new Exception("Penerimaan Barang {$gr['gr_number']} tidak dapat dibatalkan karena memiliki keterkaitan dokumen aktif: " . implode(', ', $refs));
            }

            $db->beginTransaction();

            // Revert inventory stock for accepted items
            foreach ($items as $it) {
                $qtyAcc = (float)$it['qty_accepted'];
                if ($qtyAcc > 0) {
                    InventoryService::reduceStock(
                        $it['item_id'],
                        $gr['warehouse_id'],
                        $qtyAcc,
                        'goods_receipt',
                        $grId,
                        $gr['gr_number'],
                        "Pembatalan Penerimaan Barang GR {$gr['gr_number']}: {$cancelReason}",
                        $user['id'],
                        $db
                    );
                }

                // Revert PO item qty_received
                $db->prepare("
                    UPDATE purchase_order_items 
                    SET qty_received = GREATEST(0, qty_received - :qty_acc) 
                    WHERE id = :poi_id
                ")->execute([
                    ':qty_acc' => $qtyAcc,
                    ':poi_id'  => $it['purchase_order_item_id'],
                ]);
            }

            // Recalculate PO status
            $stmtPoCheck = $db->prepare("
                SELECT SUM(qty_ordered) AS total_ordered, SUM(qty_received) AS total_rec 
                FROM purchase_order_items 
                WHERE purchase_order_id = :po_id
            ");
            $stmtPoCheck->execute([':po_id' => $gr['purchase_order_id']]);
            $poTotals = $stmtPoCheck->fetch();

            $totRec = (float)($poTotals['total_rec'] ?? 0);
            $totOrd = (float)($poTotals['total_ordered'] ?? 0);

            if ($totRec <= 0.001) {
                $newPoStatus = 'approved';
            } elseif ($totRec < $totOrd) {
                $newPoStatus = 'partially_received';
            } else {
                $newPoStatus = 'received';
            }

            $db->prepare("UPDATE purchase_orders SET status = :status, updated_at = NOW(), updated_by = :uid WHERE id = :id")->execute([
                ':status' => $newPoStatus,
                ':uid'    => $user['id'],
                ':id'     => $gr['purchase_order_id'],
            ]);

            // Update GR status to cancelled
            $db->prepare("
                UPDATE goods_receipts 
                SET status = 'cancelled', notes = CONCAT_WS(' | Batal: ', notes, :reason), 
                    updated_at = NOW(), updated_by = :uid 
                WHERE id = :id
            ")->execute([
                ':reason' => $cancelReason,
                ':uid'    => $user['id'],
                ':id'     => $grId,
            ]);

            logAudit($user['id'], 'cancel', 'goods_receipts', $grId, ['status' => $gr['status']], [
                'status' => 'cancelled',
                'reason' => $cancelReason,
            ]);

            $db->commit();
            setFlash('success', "Penerimaan Barang {$gr['gr_number']} berhasil dibatalkan. Kuantitas stok gudang dan progres PO telah dipulihkan.");
            header("Location: index.php?page=expenditure_procurement/gr_detail&id={$grId}");
            exit;
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', $e->getMessage());
        header("Location: index.php?page=expenditure_procurement/gr_detail&id={$grId}");
        exit;
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Detail Penerimaan Barang: <?= e($gr['gr_number']) ?></h1>
        <div class="page-subtitle">Status: <?= getStatusBadge($gr['status']) ?> &bull; Tanggal: <?= formatDate($gr['receipt_date']) ?></div>
    </div>
    <div class="page-actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="index.php?page=expenditure_procurement/gr" class="btn btn-secondary">&laquo; Kembali</a>
        
        <?php if (!$existingInvoice && in_array($gr['status'], ['accepted', 'partially_accepted'])): ?>
            <a href="index.php?page=expenditure_procurement/invoice_create&gr_id=<?= $gr['id'] ?>" class="btn btn-primary">
                📄 Proses Tagihan Pemasok & Three-Way Match
            </a>
        <?php endif; ?>

        <?php if ($gr['status'] !== 'cancelled'): ?>
            <button type="button" class="btn btn-danger" onclick="openModal('cancel-gr-modal')">
                ✕ Batalkan Penerimaan (Reversal)
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="detail-grid">
    <div class="detail-item">
        <div class="detail-label">Nomor Dokumen GR</div>
        <div class="detail-value"><code><?= e($gr['gr_number']) ?></code></div>
    </div>
    <div class="detail-item">
        <div class="detail-label">Pemasok / Vendor</div>
        <div class="detail-value"><?= e($gr['supplier_name']) ?> (<?= e($gr['supplier_code']) ?>)</div>
    </div>
    <div class="detail-item">
        <div class="detail-label">Referensi PO & Surat Jalan</div>
        <div class="detail-value">
            <a href="index.php?page=expenditure_procurement/po_detail&id=<?= $gr['purchase_order_id'] ?>"><strong><?= e($gr['po_number']) ?></strong></a>
            &bull; SJ: <?= e($gr['do_supplier'] ?: '-') ?>
        </div>
    </div>
    <div class="detail-item">
        <div class="detail-label">Gudang Penerima & Petugas</div>
        <div class="detail-value"><?= e($gr['warehouse_name']) ?> &bull; <?= e($gr['receiver_name']) ?></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title">Daftar Barang Masuk & Hasil Pemeriksaan Kualitas (QC)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Kode</th>
                    <th>Nama Barang / Bahan</th>
                    <th class="text-right">Kuantitas Datang</th>
                    <th class="text-right" style="color: var(--success);">Diterima (Stok Masuk)</th>
                    <th class="text-right" style="color: var(--danger);">Ditolak (Reject)</th>
                    <th>Satuan</th>
                    <th>Catatan Kondisi / QC</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $idx => $it): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><code><?= e($it['item_code']) ?></code></td>
                        <td><strong><?= e($it['item_name']) ?></strong></td>
                        <td class="text-right"><?= formatNumber($it['qty_received']) ?></td>
                        <td class="text-right" style="font-weight: 700; color: var(--success);">
                            <?= formatNumber($it['qty_accepted']) ?>
                        </td>
                        <td class="text-right" style="color: <?= (float)$it['qty_rejected'] > 0 ? 'var(--danger)' : 'var(--text-muted)' ?>; font-weight: 600;">
                            <?= formatNumber($it['qty_rejected']) ?>
                        </td>
                        <td><?= e($it['unit_name']) ?></td>
                        <td><?= e($it['notes'] ?: 'Baik / Sesuai') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (!empty($gr['notes'])): ?>
        <div class="card-footer">
            <strong>Catatan Tambahan:</strong> <?= e($gr['notes']) ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($existingInvoice): ?>
    <div class="card">
        <div class="card-header">
            <span class="card-title">Faktur Tagihan Pemasok Terkait</span>
        </div>
        <div class="card-body" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                Nomor Invoice: <strong><?= e($existingInvoice['invoice_number']) ?></strong> &bull; Total: <?= formatRupiah($existingInvoice['total_amount']) ?> &bull; Status: <?= getStatusBadge($existingInvoice['status']) ?>
            </div>
            <a href="index.php?page=expenditure_procurement/invoice_detail&id=<?= $existingInvoice['id'] ?>" class="btn btn-sm btn-secondary">
                Buka Faktur Pemasok &raquo;
            </a>
        </div>
    </div>
<?php endif; ?>

<!-- Modal Pembatalan GR -->
<div id="cancel-gr-modal" class="modal-overlay">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Konfirmasi Pembatalan Penerimaan Barang: <?= e($gr['gr_number']) ?></h3>
            <button type="button" class="modal-close" onclick="closeModal('cancel-gr-modal')">&times;</button>
        </div>
        <form action="index.php?page=expenditure_procurement/gr_detail&id=<?= $grId ?>" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_gr">
            <div class="modal-body">
                <p style="color: var(--danger); font-size: 13.5px; margin-bottom: 12px;">
                    Peringatan: Membatalkan penerimaan barang ini akan secara otomatis menarik kembali (mengurangi) stok fisik dari gudang <strong><?= e($gr['warehouse_name']) ?></strong> dan mengembalikan kuantitas sisa pesanan pada PO terkait.
                </p>
                <div class="form-group">
                    <label class="form-label required">Alasan Pembatalan / Retur ke Vendor</label>
                    <textarea name="cancel_reason" class="form-control" rows="3" required placeholder="Jelaskan alasan resmi pembatalan penerimaan barang..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('cancel-gr-modal')">Tutup</button>
                <button type="submit" class="btn btn-danger">Ya, Batalkan & Tarik Stok</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
