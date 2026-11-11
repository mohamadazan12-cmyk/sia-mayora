<?php
/**
 * Modul Penerimaan: Detail Sales Order (SO)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Detail Sales Order';
$breadcrumbCategory = 'Penerimaan';

$db = getDbConnection();
$user = currentUser();

$soId = (int)($_GET['id'] ?? 0);
if ($soId <= 0) {
    setFlash('error', 'ID Sales Order tidak valid.');
    header('Location: index.php?page=receiving_sales/sales_orders');
    exit;
}

// Fetch SO Header
$stmt = $db->prepare("
    SELECT so.*, c.name AS customer_name, c.code AS customer_code, c.address AS customer_address, 
           c.phone AS customer_phone, c.credit_limit, c.credit_used, c.credit_status,
           u.full_name AS creator_name
    FROM sales_orders so
    JOIN customers c ON so.customer_id = c.id
    LEFT JOIN users u ON so.created_by = u.id
    WHERE so.id = :id
");
$stmt->execute([':id' => $soId]);
$so = $stmt->fetch();

if (!$so) {
    setFlash('error', 'Data Sales Order tidak ditemukan.');
    header('Location: index.php?page=receiving_sales/sales_orders');
    exit;
}

// Handle State Changes: Credit Override, Cancel, & Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    if (isset($_POST['action_credit_override'])) {
        $decision = $_POST['action_credit_override'];
        $reason   = trim($_POST['override_notes'] ?? '');

        if (!hasRole(['Supervisor / Manajer', 'Administrator', 'manager', 'admin'])) {
            setFlash('error', 'Hanya Manajer yang berwenang menyetujui override batas kredit.');
            header("Location: index.php?page=receiving_sales/sales_order_detail&id={$soId}");
            exit;
        }

        try {
            if ($decision === 'approve') {
                $db->prepare("UPDATE sales_orders SET status = 'approved', updated_at = NOW(), updated_by = :uid WHERE id = :id")
                   ->execute([':uid' => $user['id'], ':id' => $soId]);

                $db->prepare("UPDATE credit_overrides SET status = 'approved', decided_by = :uid, decided_at = NOW() WHERE ref_type = 'sales_order' AND ref_id = :id")
                   ->execute([':uid' => $user['id'], ':id' => $soId]);

                ApprovalService::approve('sales_order_credit', $soId, $so['so_number'], $user['id'], $reason ?: 'Override limit kredit disetujui', $db);
                setFlash('success', 'Override batas kredit berhasil disetujui. Sales Order kini siap untuk proses pengiriman.');
            } else {
                if (empty($reason)) {
                    throw new Exception('Alasan penolakan override wajib diisi.');
                }
                $db->prepare("UPDATE sales_orders SET status = 'cancelled', updated_at = NOW(), updated_by = :uid WHERE id = :id")
                   ->execute([':uid' => $user['id'], ':id' => $soId]);

                $db->prepare("UPDATE credit_overrides SET status = 'rejected', decided_by = :uid, decided_at = NOW() WHERE ref_type = 'sales_order' AND ref_id = :id")
                   ->execute([':uid' => $user['id'], ':id' => $soId]);

                ApprovalService::reject('sales_order_credit', $soId, $so['so_number'], $user['id'], $reason, $db);
                setFlash('warning', 'Override batas kredit ditolak. Sales Order dibatalkan.');
            }

            header("Location: index.php?page=receiving_sales/sales_order_detail&id={$soId}");
            exit;
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
            header("Location: index.php?page=receiving_sales/sales_order_detail&id={$soId}");
            exit;
        }
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'cancel_so') {
            if (in_array($so['status'], ['shipped', 'completed', 'cancelled'])) {
                throw new Exception("Sales Order dengan status '{$so['status']}' tidak dapat dibatalkan.");
            }

            $cancelReason = trim($_POST['cancel_reason'] ?? '');
            if (empty($cancelReason)) {
                throw new Exception('Alasan pembatalan Sales Order wajib diisi.');
            }

            $refs = checkRecordReferences('sales_orders', $soId, $db);
            if (!empty($refs)) {
                throw new Exception("Sales Order {$so['so_number']} tidak dapat dibatalkan karena memiliki dokumen terkait: " . implode(', ', $refs));
            }

            $db->beginTransaction();

            $db->prepare("
                UPDATE sales_orders 
                SET status = 'cancelled', notes = CONCAT_WS(' | Batal: ', notes, :reason), 
                    updated_at = NOW(), updated_by = :uid 
                WHERE id = :id
            ")->execute([
                ':reason' => $cancelReason,
                ':uid'    => $user['id'],
                ':id'     => $soId,
            ]);

            // If credit override was pending, update to cancelled
            $db->prepare("
                UPDATE credit_overrides 
                SET status = 'rejected', decided_by = :uid, decided_at = NOW() 
                WHERE ref_type = 'sales_order' AND ref_id = :id AND status = 'pending'
            ")->execute([':uid' => $user['id'], ':id' => $soId]);

            logAudit($user['id'], 'cancel', 'sales_orders', $soId, ['status' => $so['status']], [
                'status' => 'cancelled',
                'reason' => $cancelReason,
            ]);

            $db->commit();
            setFlash('success', "Sales Order {$so['so_number']} berhasil dibatalkan.");
            header("Location: index.php?page=receiving_sales/sales_order_detail&id={$soId}");
            exit;

        } elseif ($action === 'delete_so') {
            if ($so['status'] !== 'draft') {
                throw new Exception("Hanya Sales Order berstatus Draf yang dapat dihapus.");
            }

            $refs = checkRecordReferences('sales_orders', $soId, $db);
            if (!empty($refs)) {
                throw new Exception("Sales Order {$so['so_number']} tidak dapat dihapus karena memiliki keterkaitan data: " . implode(', ', $refs));
            }

            $db->beginTransaction();

            $db->prepare("DELETE FROM sales_order_items WHERE sales_order_id = :id")->execute([':id' => $soId]);
            $db->prepare("DELETE FROM sales_orders WHERE id = :id")->execute([':id' => $soId]);

            logAudit($user['id'], 'delete', 'sales_orders', $soId, $so, null);

            $db->commit();
            setFlash('success', "Draf Sales Order {$so['so_number']} berhasil dihapus.");
            header('Location: index.php?page=receiving_sales/sales_orders');
            exit;
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', $e->getMessage());
        header("Location: index.php?page=receiving_sales/sales_order_detail&id={$soId}");
        exit;
    }
}

// Fetch SO Items
$stmtItems = $db->prepare("
    SELECT soi.*, i.code AS item_code, i.name AS item_name, u.name AS unit_name
    FROM sales_order_items soi
    JOIN items i ON soi.item_id = i.id
    LEFT JOIN units u ON i.unit_id = u.id
    WHERE soi.sales_order_id = :so_id
    ORDER BY soi.id ASC
");
$stmtItems->execute([':so_id' => $soId]);
$items = $stmtItems->fetchAll();

// Fetch Related Delivery Orders
$stmtDo = $db->prepare("
    SELECT do.*, w.name AS warehouse_name
    FROM delivery_orders do
    JOIN warehouses w ON do.warehouse_id = w.id
    WHERE do.sales_order_id = :so_id
    ORDER BY do.id DESC
");
$stmtDo->execute([':so_id' => $soId]);
$deliveries = $stmtDo->fetchAll();

// Fetch Credit Override record
$stmtOvrd = $db->prepare("
    SELECT co.*, u.full_name AS requester_name, d.full_name AS decider_name
    FROM credit_overrides co
    LEFT JOIN users u ON co.requested_by = u.id
    LEFT JOIN users d ON co.decided_by = d.id
    WHERE co.ref_type = 'sales_order' AND co.ref_id = :id
    ORDER BY co.id DESC LIMIT 1
");
$stmtOvrd->execute([':id' => $soId]);
$overrideRecord = $stmtOvrd->fetch();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Detail Sales Order: <?= e($so['so_number']) ?></h1>
        <div class="page-subtitle">Status: <?= getStatusBadge($so['status']) ?> &bull; Tanggal SO: <?= formatDate($so['so_date']) ?></div>
    </div>
    <div class="page-actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="index.php?page=receiving_sales/sales_orders" class="btn btn-secondary">&laquo; Kembali</a>
        
        <?php if (in_array($so['status'], ['draft', 'pending_approval'])): ?>
            <a href="index.php?page=receiving_sales/sales_order_create&id=<?= $so['id'] ?>" class="btn btn-warning">
                ✏️ Edit SO
            </a>
        <?php endif; ?>

        <?php if ($so['status'] === 'draft'): ?>
            <form action="index.php?page=receiving_sales/sales_order_detail&id=<?= $soId ?>" method="POST" style="display:inline;" onsubmit="return confirm('Hapus draf SO <?= e($so['so_number']) ?> secara permanen?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_so">
                <button type="submit" class="btn btn-secondary" style="color: var(--danger); border-color: var(--danger);">
                    🗑 Hapus Draf
                </button>
            </form>
        <?php endif; ?>

        <?php if (in_array($so['status'], ['approved', 'picking', 'packed'])): ?>
            <a href="index.php?page=receiving_sales/delivery_order_create&so_id=<?= $so['id'] ?>" class="btn btn-primary">
                🚚 Siapkan Pengiriman & Surat Jalan (DO)
            </a>
        <?php endif; ?>

        <?php if (in_array($so['status'], ['draft', 'pending_approval', 'approved'])): ?>
            <button type="button" class="btn btn-danger" onclick="openModal('cancel-so-modal')">
                ✕ Batalkan SO
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="detail-grid">
    <div class="detail-item">
        <div class="detail-label">Nomor SO & Pembuat</div>
        <div class="detail-value"><code><?= e($so['so_number']) ?></code> &bull; <?= e($so['creator_name']) ?></div>
    </div>
    <div class="detail-item">
        <div class="detail-label">Pelanggan / Distributor</div>
        <div class="detail-value">
            <?= e($so['customer_name']) ?> (<code><?= e($so['customer_code']) ?></code>)<br>
            <span style="font-size: 11.5px; color: var(--text-muted);"><?= e($so['customer_address']) ?></span>
        </div>
    </div>
    <div class="detail-item">
        <div class="detail-label">Limit Kredit & Posisi Saldo</div>
        <div class="detail-value">
            Limit: <?= formatRupiah($so['credit_limit']) ?><br>
            Terpakai: <?= formatRupiah($so['credit_used']) ?> (<?= getStatusBadge($so['credit_status']) ?>)
        </div>
    </div>
    <div class="detail-item">
        <div class="detail-label">Total Nilai Pesanan</div>
        <div class="detail-value" style="color: var(--primary); font-size: 16px;">
            <?= formatRupiah($so['total_amount']) ?>
        </div>
    </div>
</div>

<?php if (!empty($so['notes'])): ?>
    <div class="card">
        <div class="card-header">
            <span class="card-title">Catatan Tambahan Pesanan</span>
        </div>
        <div class="card-body">
            <?= nl2br(e($so['notes'])) ?>
        </div>
    </div>
<?php endif; ?>

<!-- Warning / Override Section if Pending Approval -->
<?php if ($so['status'] === 'pending_approval' && $overrideRecord): ?>
    <div class="card" style="border-left: 4px solid var(--warning);">
        <div class="card-header">
            <span class="card-title">Permohonan Override Limit Kredit Menunggu Keputusan Manajer</span>
        </div>
        <div class="card-body">
            <p><strong>Alasan Pengajuan oleh <?= e($overrideRecord['requester_name']) ?>:</strong> <?= e($overrideRecord['reason']) ?></p>
            <?php if (hasRole(['Supervisor / Manajer', 'Administrator', 'manager', 'admin'])): ?>
                <form action="index.php?page=receiving_sales/sales_order_detail&id=<?= $soId ?>" method="POST" style="margin-top: 14px;">
                    <?= csrfField() ?>
                    <div class="form-group">
                        <label class="form-label">Catatan Keputusan Manajer (Wajib jika menolak)</label>
                        <input type="text" name="override_notes" class="form-control" placeholder="Contoh: Disetujui berdasarkan konfirmasi pembayaran via transfer">
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="submit" name="action_credit_override" value="approve" class="btn btn-success">
                            ✓ Setujui Override Kredit & Terbitkan SO
                        </button>
                        <button type="submit" name="action_credit_override" value="reject" class="btn btn-danger">
                            ✕ Tolak & Batalkan Pesanan
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <p style="color: var(--text-muted); font-size: 13px; margin-top: 8px;">
                    <em>Menunggu peninjauan dan otorisasi dari Supervisor / Manajer Keuangan.</em>
                </p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <span class="card-title">Daftar Barang Jadi Dipesan & Status Pemenuhan Pengiriman</span>
    </div>
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Kode</th>
                    <th>Nama Barang Jadi</th>
                    <th class="text-right">Kuantitas Pesan</th>
                    <th class="text-right">Sudah Dikirim</th>
                    <th class="text-right">Sisa Belum Kirim</th>
                    <th>Satuan</th>
                    <th class="text-right">Harga Jual Satuan</th>
                    <th class="text-right">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $idx => $it): ?>
                    <?php $remaining = (float)$it['qty_ordered'] - (float)$it['qty_shipped']; ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><code><?= e($it['item_code']) ?></code></td>
                        <td><strong><?= e($it['item_name']) ?></strong></td>
                        <td class="text-right"><?= formatNumber($it['qty_ordered']) ?></td>
                        <td class="text-right" style="color: var(--success); font-weight: 600;">
                            <?= formatNumber($it['qty_shipped']) ?>
                        </td>
                        <td class="text-right" style="color: <?= $remaining > 0 ? 'var(--warning)' : 'var(--text-muted)' ?>; font-weight: 600;">
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
                    <td colspan="8" class="text-right">TOTAL NILAI SALES ORDER:</td>
                    <td class="text-right" style="color: var(--primary); font-size: 14px;"><?= formatRupiah($so['total_amount']) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Riwayat Surat Jalan (Delivery Order) -->
<div class="card">
    <div class="card-header">
        <span class="card-title">Dokumen Pengiriman & Surat Jalan (Delivery Order / DO) Terkait</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>No. Surat Jalan (DO)</th>
                    <th>Tanggal Kirim</th>
                    <th>Gudang Asal</th>
                    <th>Pengemudi & No. Polisi</th>
                    <th>Penerima Barang</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($deliveries)): ?>
                    <tr><td colspan="7" class="text-center" style="padding: 16px; color: var(--text-muted);">Belum ada surat jalan pengiriman untuk pesanan ini.</td></tr>
                <?php else: ?>
                    <?php foreach ($deliveries as $do): ?>
                        <tr>
                            <td><strong><?= e($do['do_number']) ?></strong></td>
                            <td><?= formatDate($do['do_date']) ?></td>
                            <td><?= e($do['warehouse_name']) ?></td>
                            <td><?= e($do['driver_name']) ?> (<code><?= e($do['vehicle_number']) ?></code>)</td>
                            <td><?= e($do['recipient_name'] ?: '-') ?></td>
                            <td class="text-center"><?= getStatusBadge($do['status']) ?></td>
                            <td class="text-center">
                                <a href="index.php?page=receiving_sales/delivery_order_detail&id=<?= $do['id'] ?>" class="btn btn-sm btn-secondary">
                                    Buka DO
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Pembatalan SO -->
<div id="cancel-so-modal" class="modal-overlay">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Konfirmasi Pembatalan Sales Order: <?= e($so['so_number']) ?></h3>
            <button type="button" class="modal-close" onclick="closeModal('cancel-so-modal')">&times;</button>
        </div>
        <form action="index.php?page=receiving_sales/sales_order_detail&id=<?= $soId ?>" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_so">
            <div class="modal-body">
                <p style="color: var(--danger); font-size: 13.5px; margin-bottom: 12px;">
                    Perhatian: Membatalkan Sales Order ini akan membatalkan alokasi pengiriman barang untuk pesanan ini.
                </p>
                <div class="form-group">
                    <label class="form-label required">Alasan Pembatalan Sales Order</label>
                    <textarea name="cancel_reason" class="form-control" rows="3" required placeholder="Jelaskan alasan resmi pembatalan pesanan penjualan ini..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('cancel-so-modal')">Batal</button>
                <button type="submit" class="btn btn-danger">Ya, Batalkan SO</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
