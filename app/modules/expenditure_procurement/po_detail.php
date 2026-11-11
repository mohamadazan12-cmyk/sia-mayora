<?php
/**
 * Modul Pengeluaran: Detail Purchase Order (PO)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Detail Pesanan Pembelian';
$breadcrumbCategory = 'Pengeluaran';

$db = getDbConnection();
$user = currentUser();

$poId = (int)($_GET['id'] ?? 0);
if ($poId <= 0) {
    setFlash('error', 'ID Pesanan Pembelian tidak valid.');
    header('Location: index.php?page=expenditure_procurement/po');
    exit;
}

// Fetch PO Header
$stmt = $db->prepare("
    SELECT po.*, s.name AS supplier_name, s.code AS supplier_code, s.address AS supplier_address, s.phone AS supplier_phone,
           pr.pr_number, u.full_name AS creator_name
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN purchase_requisitions pr ON po.purchase_requisition_id = pr.id
    LEFT JOIN users u ON po.created_by = u.id
    WHERE po.id = :id
");
$stmt->execute([':id' => $poId]);
$po = $stmt->fetch();

if (!$po) {
    setFlash('error', 'Data Pesanan Pembelian tidak ditemukan.');
    header('Location: index.php?page=expenditure_procurement/po');
    exit;
}

// Handle State Changes: Cancel & Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'approve_po' && $po['status'] === 'draft') {
            if (!hasRole(['Supervisor / Manajer', 'Administrator', 'manager', 'admin', 'purchasing'])) {
                throw new Exception("Anda tidak memiliki hak otorisasi untuk menerbitkan PO.");
            }

            $db->beginTransaction();

            $updPo = $db->prepare("UPDATE purchase_orders SET status = 'approved', updated_at = NOW(), updated_by = :uid WHERE id = :id");
            $updPo->execute([':uid' => $user['id'], ':id' => $poId]);

            if ($po['purchase_requisition_id']) {
                $updPr = $db->prepare("UPDATE purchase_requisitions SET status = 'ordered', updated_at = NOW() WHERE id = :id");
                $updPr->execute([':id' => $po['purchase_requisition_id']]);
            }

            ApprovalService::approve('purchase_order', $poId, $po['po_number'], $user['id'], 'PO disetujui & diterbitkan resmi.', $db);
            logAudit($user['id'], 'approve', 'purchase_orders', $poId, ['status' => 'draft'], ['status' => 'approved']);

            $db->commit();
            setFlash('success', "Pesanan Pembelian {$po['po_number']} berhasil disetujui dan diterbitkan secara resmi.");
            header("Location: index.php?page=expenditure_procurement/po_detail&id={$poId}");
            exit;

        } elseif ($action === 'cancel_po') {
            if (!in_array($po['status'], ['draft', 'pending_approval', 'approved'])) {
                throw new Exception("PO berstatus '{$po['status']}' tidak dapat dibatalkan.");
            }

            $cancelReason = trim($_POST['cancel_reason'] ?? '');
            if (empty($cancelReason)) {
                throw new Exception('Alasan pembatalan PO wajib diisi.');
            }

            $refs = checkRecordReferences('purchase_orders', $poId, $db);
            if (!empty($refs)) {
                throw new Exception("PO {$po['po_number']} tidak dapat dibatalkan karena memiliki dokumen turunan: " . implode(', ', $refs));
            }

            $db->beginTransaction();

            // Revert linked PR status to 'approved' so it can be re-ordered if needed
            if ($po['purchase_requisition_id']) {
                $updPr = $db->prepare("UPDATE purchase_requisitions SET status = 'approved', updated_at = NOW(), updated_by = :uid WHERE id = :id");
                $updPr->execute([':uid' => $user['id'], ':id' => $po['purchase_requisition_id']]);
            }

            $updPo = $db->prepare("
                UPDATE purchase_orders 
                SET status = 'cancelled', notes = CONCAT_WS(' | Batal: ', notes, :reason), 
                    updated_at = NOW(), updated_by = :uid 
                WHERE id = :id
            ");
            $updPo->execute([
                ':reason' => $cancelReason,
                ':uid'    => $user['id'],
                ':id'     => $poId,
            ]);

            logAudit($user['id'], 'cancel', 'purchase_orders', $poId, ['status' => $po['status']], [
                'status' => 'cancelled',
                'reason' => $cancelReason,
            ]);

            $db->commit();
            setFlash('success', "Pesanan Pembelian {$po['po_number']} berhasil dibatalkan.");
            header("Location: index.php?page=expenditure_procurement/po_detail&id={$poId}");
            exit;

        } elseif ($action === 'delete_po') {
            if ($po['status'] !== 'draft') {
                throw new Exception("Hanya PO berstatus Draf yang dapat dihapus secara permanen.");
            }

            $refs = checkRecordReferences('purchase_orders', $poId, $db);
            if (!empty($refs)) {
                throw new Exception("PO {$po['po_number']} tidak dapat dihapus karena memiliki keterkaitan data: " . implode(', ', $refs));
            }

            $db->beginTransaction();

            // Revert linked PR status if any
            if ($po['purchase_requisition_id']) {
                $updPr = $db->prepare("UPDATE purchase_requisitions SET status = 'approved', updated_at = NOW(), updated_by = :uid WHERE id = :id");
                $updPr->execute([':uid' => $user['id'], ':id' => $po['purchase_requisition_id']]);
            }

            $db->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = :id")->execute([':id' => $poId]);
            $db->prepare("DELETE FROM purchase_orders WHERE id = :id")->execute([':id' => $poId]);

            logAudit($user['id'], 'delete', 'purchase_orders', $poId, $po, null);

            $db->commit();
            setFlash('success', "Draf Pesanan Pembelian {$po['po_number']} berhasil dihapus.");
            header('Location: index.php?page=expenditure_procurement/po');
            exit;
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', $e->getMessage());
        header("Location: index.php?page=expenditure_procurement/po_detail&id={$poId}");
        exit;
    }
}

// Fetch PO Items
$stmtItems = $db->prepare("
    SELECT poi.*, i.code AS item_code, i.name AS item_name, u.name AS unit_name
    FROM purchase_order_items poi
    JOIN items i ON poi.item_id = i.id
    LEFT JOIN units u ON i.unit_id = u.id
    WHERE poi.purchase_order_id = :po_id
    ORDER BY poi.id ASC
");
$stmtItems->execute([':po_id' => $poId]);
$items = $stmtItems->fetchAll();

// Fetch Related Goods Receipts
$stmtGr = $db->prepare("
    SELECT gr.*, w.name AS warehouse_name
    FROM goods_receipts gr
    JOIN warehouses w ON gr.warehouse_id = w.id
    WHERE gr.purchase_order_id = :po_id
    ORDER BY gr.id DESC
");
$stmtGr->execute([':po_id' => $poId]);
$receipts = $stmtGr->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Detail Pesanan Pembelian: <?= e($po['po_number']) ?></h1>
        <div class="page-subtitle">Status: <?= getStatusBadge($po['status']) ?> &bull; Tanggal PO: <?= formatDate($po['po_date']) ?></div>
    </div>
    <div class="page-actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="index.php?page=expenditure_procurement/po" class="btn btn-secondary">&laquo; Kembali</a>
        
        <?php if ($po['status'] === 'draft'): ?>
            <?php if (hasRole(['Supervisor / Manajer', 'Administrator', 'manager', 'admin', 'purchasing'])): ?>
                <form action="index.php?page=expenditure_procurement/po_detail&id=<?= $poId ?>" method="POST" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin menyetujui dan menerbitkan Pesanan Pembelian <?= e($po['po_number']) ?>?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="approve_po">
                    <button type="submit" class="btn btn-primary">
                        ✓ Terbitkan PO Resmi
                    </button>
                </form>
            <?php endif; ?>
            <a href="index.php?page=expenditure_procurement/po_create&id=<?= $po['id'] ?>" class="btn btn-warning">
                ✏️ Edit PO
            </a>
            <form action="index.php?page=expenditure_procurement/po_detail&id=<?= $poId ?>" method="POST" style="display:inline;" onsubmit="return confirm('Hapus draf PO <?= e($po['po_number']) ?> secara permanen?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_po">
                <button type="submit" class="btn btn-secondary" style="color: var(--danger); border-color: var(--danger);">
                    🗑 Hapus Draf
                </button>
            </form>
        <?php endif; ?>

        <?php if (in_array($po['status'], ['approved', 'partially_received'])): ?>
            <a href="index.php?page=expenditure_procurement/gr_create&po_id=<?= $po['id'] ?>" class="btn btn-success">
                📦 Catat Penerimaan Barang (GR)
            </a>
        <?php endif; ?>

        <?php if (in_array($po['status'], ['draft', 'pending_approval', 'approved'])): ?>
            <button type="button" class="btn btn-danger" onclick="openModal('cancel-po-modal')">
                ✕ Batalkan PO
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="detail-grid">
    <div class="detail-item">
        <div class="detail-label">Nomor PO & Tanggal</div>
        <div class="detail-value"><code><?= e($po['po_number']) ?></code> (<?= formatDate($po['po_date']) ?>)</div>
    </div>
    <div class="detail-item">
        <div class="detail-label">Pemasok / Vendor</div>
        <div class="detail-value"><?= e($po['supplier_name']) ?> (<?= e($po['supplier_code']) ?>)</div>
    </div>
    <div class="detail-item">
        <div class="detail-label">Target Pengiriman</div>
        <div class="detail-value"><?= formatDate($po['expected_delivery']) ?></div>
    </div>
    <div class="detail-item">
        <div class="detail-label">Total Nilai Pembelian</div>
        <div class="detail-value" style="color: var(--primary); font-size: 16px;">
            <?= formatRupiah($po['total_amount']) ?>
        </div>
    </div>
</div>

<?php if (!empty($po['notes'])): ?>
    <div class="card">
        <div class="card-header">
            <span class="card-title">Catatan Tambahan</span>
        </div>
        <div class="card-body">
            <?= nl2br(e($po['notes'])) ?>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <span class="card-title">Daftar Barang Dipesan & Status Pemenuhan</span>
    </div>
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Kode</th>
                    <th>Nama Barang / Bahan</th>
                    <th class="text-right">Kuantitas Pesan</th>
                    <th class="text-right">Kuantitas Diterima</th>
                    <th class="text-right">Sisa Belum Diterima</th>
                    <th>Satuan</th>
                    <th class="text-right">Harga Satuan</th>
                    <th class="text-right">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $idx => $it): ?>
                    <?php $remaining = (float)$it['qty_ordered'] - (float)$it['qty_received']; ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><code><?= e($it['item_code']) ?></code></td>
                        <td><strong><?= e($it['item_name']) ?></strong></td>
                        <td class="text-right"><?= formatNumber($it['qty_ordered']) ?></td>
                        <td class="text-right" style="color: var(--success); font-weight: 600;">
                            <?= formatNumber($it['qty_received']) ?>
                        </td>
                        <td class="text-right" style="color: <?= $remaining > 0 ? 'var(--warning)' : 'var(--text-muted)' ?>;">
                            <?= formatNumber(max(0, $remaining)) ?>
                        </td>
                        <td><?= e($it['unit_name']) ?></td>
                        <td class="text-right"><?= formatRupiah($it['unit_price']) ?></td>
                        <td class="text-right" style="font-weight: 600;"><?= formatRupiah($it['subtotal']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background-color: #f8fafc; font-weight: 700;">
                    <td colspan="8" class="text-right">TOTAL NILAI PO:</td>
                    <td class="text-right" style="color: var(--primary); font-size: 14px;"><?= formatRupiah($po['total_amount']) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Dokumen Penerimaan Barang (GR) Terkait -->
<div class="card">
    <div class="card-header">
        <span class="card-title">Riwayat Penerimaan Barang (Goods Receipt / GR)</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>No. GR</th>
                    <th>Tanggal Penerimaan</th>
                    <th>Surat Jalan Vendor</th>
                    <th>Gudang Tujuan</th>
                    <th class="text-center">Status GR</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($receipts)): ?>
                    <tr>
                        <td colspan="6" class="text-center" style="padding: 18px; color: var(--text-muted);">
                            Belum ada penerimaan barang tercatat untuk PO ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($receipts as $gr): ?>
                        <tr>
                            <td><strong><?= e($gr['gr_number']) ?></strong></td>
                            <td><?= formatDate($gr['receipt_date']) ?></td>
                            <td><?= e($gr['do_supplier'] ?: '-') ?></td>
                            <td><?= e($gr['warehouse_name']) ?></td>
                            <td class="text-center"><?= getStatusBadge($gr['status']) ?></td>
                            <td class="text-center">
                                <a href="index.php?page=expenditure_procurement/gr_detail&id=<?= $gr['id'] ?>" class="btn btn-sm btn-secondary">
                                    Lihat GR
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Pembatalan PO -->
<div id="cancel-po-modal" class="modal-overlay">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Konfirmasi Pembatalan PO: <?= e($po['po_number']) ?></h3>
            <button type="button" class="modal-close" onclick="closeModal('cancel-po-modal')">&times;</button>
        </div>
        <form action="index.php?page=expenditure_procurement/po_detail&id=<?= $poId ?>" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_po">
            <div class="modal-body">
                <p style="color: var(--danger); font-size: 13.5px; margin-bottom: 12px;">
                    Perhatian: Membatalkan PO akan menghentikan proses pengadaan barang untuk dokumen ini. Referensi PR terkait (jika ada) akan dikembalikan statusnya menjadi Disetujui.
                </p>
                <div class="form-group">
                    <label class="form-label required">Alasan Pembatalan PO</label>
                    <textarea name="cancel_reason" class="form-control" rows="3" required placeholder="Jelaskan alasan resmi pembatalan pesanan pembelian ini..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('cancel-po-modal')">Batal</button>
                <button type="submit" class="btn btn-danger">Ya, Batalkan PO</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
