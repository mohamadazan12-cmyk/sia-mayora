<?php
/**
 * Modul Produksi: Hasil Produksi (Output) dan Catatan Scrap
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Hasil Produksi & Scrap';
$breadcrumbCategory = 'Produksi';

$db = getDbConnection();
$user = currentUser();

// Handle Void / Cancel Output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_void_output'])) {
    requireCsrf();

    $outputId = (int)($_POST['output_id'] ?? 0);
    $reason = trim($_POST['void_reason'] ?? '');

    if ($outputId <= 0 || empty($reason)) {
        setFlash('error', 'ID hasil produksi dan alasan pembatalan wajib diisi.');
        header('Location: index.php?page=production/outputs');
        exit;
    }

    try {
        $db->beginTransaction();

        $stmtOut = $db->prepare("
            SELECT po.*, pdo.po_number, pdo.product_item_id 
            FROM production_outputs po
            JOIN production_orders pdo ON po.production_order_id = pdo.id
            WHERE po.id = :id FOR UPDATE
        ");
        $stmtOut->execute([':id' => $outputId]);
        $out = $stmtOut->fetch();

        if (!$out) {
            throw new Exception('Data hasil produksi tidak ditemukan.');
        }

        if ($out['status'] !== 'passed') {
            throw new Exception("Hasil produksi tidak dapat dibatalkan karena statusnya '{$out['status']}'.");
        }

        // 1. Deduct finished goods stock atomically
        if ((float)$out['qty_good'] > 0) {
            InventoryService::reduceStock(
                $out['product_item_id'],
                $out['warehouse_id'],
                (float)$out['qty_good'],
                'production_output_cancel',
                $outputId,
                $out['po_number'],
                "Pembatalan output produksi ({$out['po_number']}): {$reason}",
                $user['id'],
                $db
            );
        }

        // 2. Revert production order actuals & set status back to in_progress
        $db->prepare("
            UPDATE production_orders 
            SET actual_qty = GREATEST(0, actual_qty - :good),
                scrap_qty = GREATEST(0, scrap_qty - :scrap),
                status = 'in_progress',
                actual_end = NULL,
                updated_at = NOW(),
                updated_by = :uid
            WHERE id = :pdo_id
        ")->execute([
            ':good'   => $out['qty_good'],
            ':scrap'  => $out['qty_scrap'],
            ':uid'    => $user['id'],
            ':pdo_id' => $out['production_order_id'],
        ]);

        // 3. Reverse Journal Entry
        JournalService::reverseEntryByRef(
            'production_order',
            $out['production_order_id'],
            "Pembatalan hasil output SPK {$out['po_number']}: {$reason}",
            $user['id'],
            $db
        );

        // 4. Update output status to failed
        $db->prepare("
            UPDATE production_outputs 
            SET status = 'failed', notes = CONCAT_WS(' | Dibatalkan: ', notes, :reason), updated_at = NOW(), updated_by = :uid 
            WHERE id = :id
        ")->execute([
            ':reason' => $reason,
            ':uid'    => $user['id'],
            ':id'     => $outputId,
        ]);

        // 5. Clean up scrap records associated with this output
        $db->prepare("DELETE FROM scrap_records WHERE production_output_id = :id")->execute([':id' => $outputId]);

        logAudit($user['id'], 'cancel', 'production_outputs', $outputId, ['status' => 'passed'], [
            'status' => 'failed',
            'reason' => $reason,
        ]);

        $db->commit();
        setFlash('success', "Hasil produksi SPK {$out['po_number']} berhasil dibatalkan. Stok barang jadi di gudang telah dikurangi dan jurnal telah dibalik.");
        header('Location: index.php?page=production/outputs');
        exit;
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', 'Gagal membatalkan hasil produksi: ' . $e->getMessage());
        header('Location: index.php?page=production/outputs');
        exit;
    }
}

// Fetch Good Outputs
$outputs = $db->query("
    SELECT po.*, pdo.po_number, i.name AS product_name, i.code AS product_code, u.name AS unit_name,
           w.name AS warehouse_name, usr.full_name AS recorder_name
    FROM production_outputs po
    JOIN production_orders pdo ON po.production_order_id = pdo.id
    JOIN items i ON pdo.product_item_id = i.id
    LEFT JOIN units u ON i.unit_id = u.id
    JOIN warehouses w ON po.warehouse_id = w.id
    LEFT JOIN users usr ON po.created_by = usr.id
    ORDER BY po.id DESC
    LIMIT 15
")->fetchAll();

// Fetch Scrap Records
$scraps = $db->query("
    SELECT sr.*, pdo.po_number, i.name AS item_name, u.name AS unit_name, usr.full_name AS recorder_name
    FROM scrap_records sr
    JOIN production_orders pdo ON sr.production_order_id = pdo.id
    JOIN items i ON sr.item_id = i.id
    LEFT JOIN units u ON i.unit_id = u.id
    LEFT JOIN users usr ON sr.created_by = usr.id
    ORDER BY sr.id DESC
    LIMIT 15
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Hasil Produksi Selesai & Pencatatan Scrap</h1>
        <div class="page-subtitle">Siklus Produksi &bull; Pemisahan Ketat Barang Jadi Siap Jual vs Produk Gagal / Afkir</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=production/orders" class="btn btn-secondary">&laquo; Kembali ke SPK</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title">Barang Jadi Lulus Uji Mutu (Masuk Persediaan Gudang)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Ref. Perintah Produksi</th>
                    <th>Tanggal Output</th>
                    <th>Produk Jadi</th>
                    <th class="text-right">Kuantitas Lolos</th>
                    <th>Gudang Penyimpanan</th>
                    <th>Petugas Pencatat</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($outputs)): ?>
                    <tr><td colspan="8" class="text-center" style="padding: 20px; color: var(--text-muted);">Belum ada hasil barang jadi.</td></tr>
                <?php else: ?>
                    <?php foreach ($outputs as $out): ?>
                        <tr>
                            <td><a href="index.php?page=production/order_detail&id=<?= $out['production_order_id'] ?>"><strong><?= e($out['po_number']) ?></strong></a></td>
                            <td><?= formatDate($out['output_date']) ?></td>
                            <td>
                                <strong><?= e($out['product_name']) ?></strong><br>
                                <code><?= e($out['product_code']) ?></code>
                            </td>
                            <td class="text-right font-weight-bold" style="color: var(--success); font-size: 13.5px;">
                                <?= formatNumber($out['qty_good']) ?> <?= e($out['unit_name']) ?>
                            </td>
                            <td><?= e($out['warehouse_name']) ?></td>
                            <td><?= e($out['recorder_name'] ?: 'Sistem') ?></td>
                            <td class="text-center"><?= getStatusBadge($out['status']) ?></td>
                            <td class="text-center" style="white-space:nowrap;">
                                <button type="button" class="btn btn-sm btn-secondary btn-detail-output"
                                    data-id="<?= $out['id'] ?>"
                                    data-po="<?= e($out['po_number']) ?>"
                                    data-prod="<?= e($out['product_name']) ?> (<?= e($out['product_code']) ?>)"
                                    data-date="<?= formatDate($out['output_date']) ?>"
                                    data-good="<?= formatNumber($out['qty_good']) ?> <?= e($out['unit_name']) ?>"
                                    data-scrap="<?= formatNumber($out['qty_scrap']) ?>"
                                    data-wh="<?= e($out['warehouse_name']) ?>"
                                    data-rec="<?= e($out['recorder_name'] ?: 'Sistem') ?>"
                                    data-notes="<?= e($out['notes'] ?: '-') ?>"
                                    data-status="<?= e($out['status']) ?>">
                                    Detail
                                </button>
                                <?php if ($out['status'] === 'passed'): ?>
                                    <button type="button" class="btn btn-sm btn-danger btn-void-output"
                                        data-id="<?= $out['id'] ?>"
                                        data-po="<?= e($out['po_number']) ?>"
                                        data-good="<?= formatNumber($out['qty_good']) ?> <?= e($out['unit_name']) ?>">
                                        Batalkan / Void
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

<div class="card" style="border-left: 4px solid var(--danger);">
    <div class="card-header">
        <span class="card-title">Catatan Produk Gagal, Remuk, & Afkir (Scrap Records)</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Ref. SPK Produksi</th>
                    <th>Tanggal Kerusakan</th>
                    <th>Item Bahan / Produk</th>
                    <th class="text-right">Kuantitas Scrap</th>
                    <th>Alasan Kegagalan / Kerusakan</th>
                    <th>Pencatat</th>
                    <th class="text-center">Status Saldo</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($scraps)): ?>
                    <tr><td colspan="7" class="text-center" style="padding: 20px; color: var(--text-muted);">Tidak ada catatan scrap produksi.</td></tr>
                <?php else: ?>
                    <?php foreach ($scraps as $sc): ?>
                        <tr>
                            <td><a href="index.php?page=production/order_detail&id=<?= $sc['production_order_id'] ?>"><strong><?= e($sc['po_number']) ?></strong></a></td>
                            <td><?= formatDate($sc['scrap_date']) ?></td>
                            <td><?= e($sc['item_name']) ?></td>
                            <td class="text-right font-weight-bold" style="color: var(--danger);">
                                <?= formatNumber($sc['qty']) ?> <?= e($sc['unit_name']) ?>
                            </td>
                            <td><?= e($sc['reason']) ?></td>
                            <td><?= e($sc['recorder_name'] ?: 'Sistem') ?></td>
                            <td class="text-center">
                                <span class="badge badge-danger">Tidak Masuk Stok</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Detail Output -->
<div id="modalDetailOutput" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; border-radius:8px; max-width:550px; width:90%; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.2);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
            <h3 style="margin:0;">Detail Hasil Produksi (Output)</h3>
            <button type="button" style="background:none; border:none; font-size:20px; cursor:pointer;" onclick="document.getElementById('modalDetailOutput').style.display='none'">&times;</button>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:13.5px; margin-bottom:15px;">
            <div><strong>Ref. SPK:</strong> <span id="dtOutPo"></span></div>
            <div><strong>Tanggal Output:</strong> <span id="dtOutDate"></span></div>
            <div style="grid-column:1/-1;"><strong>Produk Jadi:</strong> <span id="dtOutProd"></span></div>
            <div><strong>Hasil Lolos (Good):</strong> <span id="dtOutGood" style="color:var(--success); font-weight:700;"></span></div>
            <div><strong>Afkir / Scrap:</strong> <span id="dtOutScrap" style="color:var(--danger); font-weight:700;"></span></div>
            <div><strong>Gudang Masuk:</strong> <span id="dtOutWh"></span></div>
            <div><strong>Petugas Pencatat:</strong> <span id="dtOutRec"></span></div>
            <div style="grid-column:1/-1;"><strong>Status Output:</strong> <span id="dtOutStatus"></span></div>
            <div style="grid-column:1/-1;"><strong>Catatan Tambahan:</strong> <span id="dtOutNotes"></span></div>
        </div>
        <div style="display:flex; justify-content:flex-end;">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalDetailOutput').style.display='none'">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal Void Output -->
<div id="modalVoidOutput" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; border-radius:8px; max-width:480px; width:90%; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:var(--danger);">Pembatalan Hasil Produksi (Void Output)</h3>
        <p style="color:var(--text-muted); font-size:14px;">
            Anda akan membatalkan penyelesaian output untuk SPK <strong id="voidOutPo"></strong> sebesar <strong id="voidOutGood"></strong>.
            Stok barang jadi akan <strong>otomatis dikurangi kembali</strong> dan voucher jurnal akuntansi akan <strong>dibalik (reversed)</strong>.
        </p>
        <form action="index.php?page=production/outputs" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action_void_output" value="1">
            <input type="hidden" name="output_id" id="voidOutId">
            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label required">Alasan Pembatalan Output</label>
                <textarea name="void_reason" class="form-control" rows="3" placeholder="Contoh: Kesalahan input kuantitas batch / penolakan uji lab lanjutan" required></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalVoidOutput').style.display='none'">Tutup</button>
                <button type="submit" class="btn btn-danger">Batalkan &amp; Revert Jurnal</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-detail-output').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('dtOutPo').innerText = d.po;
            document.getElementById('dtOutDate').innerText = d.date;
            document.getElementById('dtOutProd').innerText = d.prod;
            document.getElementById('dtOutGood').innerText = d.good;
            document.getElementById('dtOutScrap').innerText = d.scrap;
            document.getElementById('dtOutWh').innerText = d.wh;
            document.getElementById('dtOutRec').innerText = d.rec;
            document.getElementById('dtOutNotes').innerText = d.notes;
            document.getElementById('dtOutStatus').innerText = d.status;
            document.getElementById('modalDetailOutput').style.display = 'flex';
        });
    });

    document.querySelectorAll('.btn-void-output').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('voidOutId').value = this.dataset.id;
            document.getElementById('voidOutPo').innerText = this.dataset.po;
            document.getElementById('voidOutGood').innerText = this.dataset.good;
            document.getElementById('modalVoidOutput').style.display = 'flex';
        });
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
