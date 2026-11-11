<?php
/**
 * Modul Pengeluaran: Detail Purchase Requisition (PR)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Detail Permintaan Pembelian';
$breadcrumbCategory = 'Pengeluaran';

$db = getDbConnection();
$user = currentUser();

$prId = (int)($_GET['id'] ?? 0);
if ($prId <= 0) {
    setFlash('error', 'ID Permintaan Pembelian tidak valid.');
    header('Location: index.php?page=expenditure_procurement/pr');
    exit;
}

// Fetch PR Header
$stmt = $db->prepare("
    SELECT pr.*, u.full_name AS requester_name, d.name AS dept_name, 
           b.code AS budget_code, b.name AS budget_name, b.total_amount AS budget_total, b.used_amount AS budget_used
    FROM purchase_requisitions pr
    LEFT JOIN users u ON pr.requested_by = u.id
    LEFT JOIN departments d ON pr.department_id = d.id
    LEFT JOIN budgets b ON pr.budget_id = b.id
    WHERE pr.id = :id
");
$stmt->execute([':id' => $prId]);
$pr = $stmt->fetch();

if (!$pr) {
    setFlash('error', 'Data Permintaan Pembelian tidak ditemukan.');
    header('Location: index.php?page=expenditure_procurement/pr');
    exit;
}

// Fetch Items
$stmtItems = $db->prepare("
    SELECT pri.*, i.code AS item_code, i.name AS item_name, u.name AS unit_name
    FROM purchase_requisition_items pri
    JOIN items i ON pri.item_id = i.id
    LEFT JOIN units u ON pri.unit_id = u.id
    WHERE pri.purchase_requisition_id = :pr_id
    ORDER BY pri.id ASC
");
$stmtItems->execute([':pr_id' => $prId]);
$items = $stmtItems->fetchAll();

// Handle Approval / Rejection Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $reason = trim($_POST['approval_reason'] ?? '');

    // Authorization check: Only Manager or Admin can approve/reject
    $canApprove = hasRole(['Supervisor / Manajer', 'Administrator', 'manager', 'admin']);

    if (!$canApprove && in_array($action, ['approve', 'reject', 'revision'])) {
        setFlash('error', 'Anda tidak memiliki hak otorisasi untuk menyetujui atau menolak dokumen ini.');
        header("Location: index.php?page=expenditure_procurement/pr_detail&id={$prId}");
        exit;
    }

    try {
        if ($action === 'submit_approval' && $pr['status'] === 'draft') {
            $upd = $db->prepare("UPDATE purchase_requisitions SET status = 'pending_approval', updated_at = NOW(), updated_by = :uid WHERE id = :id");
            $upd->execute([':uid' => $user['id'], ':id' => $prId]);
            ApprovalService::submit('purchase_requisition', $prId, $pr['pr_number'], 'Diajukan untuk persetujuan', $db);
            setFlash('success', 'Permintaan Pembelian berhasil diajukan untuk persetujuan.');
        } elseif ($action === 'approve' && $pr['status'] === 'pending_approval') {
            // Update budget used amount if budget assigned
            if ($pr['budget_id']) {
                $updB = $db->prepare("UPDATE budgets SET used_amount = used_amount + :amt, updated_at = NOW() WHERE id = :bid");
                $updB->execute([':amt' => $pr['total_estimated'], ':bid' => $pr['budget_id']]);
            }

            $upd = $db->prepare("UPDATE purchase_requisitions SET status = 'approved', updated_at = NOW(), updated_by = :uid WHERE id = :id");
            $upd->execute([':uid' => $user['id'], ':id' => $prId]);
            ApprovalService::approve('purchase_requisition', $prId, $pr['pr_number'], $user['id'], $reason, $db);
            setFlash('success', 'Permintaan Pembelian berhasil disetujui.');
        } elseif ($action === 'reject' && $pr['status'] === 'pending_approval') {
            if (empty($reason)) {
                throw new Exception('Alasan penolakan wajib diisi.');
            }
            $upd = $db->prepare("UPDATE purchase_requisitions SET status = 'rejected', updated_at = NOW(), updated_by = :uid WHERE id = :id");
            $upd->execute([':uid' => $user['id'], ':id' => $prId]);
            ApprovalService::reject('purchase_requisition', $prId, $pr['pr_number'], $user['id'], $reason, $db);
            setFlash('warning', 'Permintaan Pembelian telah ditolak.');
        } elseif ($action === 'revision' && $pr['status'] === 'pending_approval') {
            if (empty($reason)) {
                throw new Exception('Alasan permintaan revisi wajib diisi.');
            }
            $upd = $db->prepare("UPDATE purchase_requisitions SET status = 'revision', updated_at = NOW(), updated_by = :uid WHERE id = :id");
            $upd->execute([':uid' => $user['id'], ':id' => $prId]);
            ApprovalService::requestRevision('purchase_requisition', $prId, $pr['pr_number'], $user['id'], $reason, $db);
            setFlash('warning', 'Permintaan revisi berhasil dikirim.');
        } elseif ($action === 'cancel_pr') {
            $cancelReason = trim($_POST['cancel_reason'] ?? '');
            if (empty($cancelReason)) {
                throw new Exception('Alasan pembatalan PR wajib diisi.');
            }

            // Check if PO exists
            $refs = checkRecordReferences('purchase_requisitions', $prId, $db);
            if (!empty($refs)) {
                throw new Exception("PR {$pr['pr_number']} tidak dapat dibatalkan karena sudah diproses ke: " . implode(', ', $refs));
            }

            $db->beginTransaction();

            // Revert budget if was approved and had budget
            if ($pr['status'] === 'approved' && $pr['budget_id']) {
                $updB = $db->prepare("UPDATE budgets SET used_amount = GREATEST(0, used_amount - :amt), updated_at = NOW() WHERE id = :bid");
                $updB->execute([':amt' => $pr['total_estimated'], ':bid' => $pr['budget_id']]);
            }

            $upd = $db->prepare("UPDATE purchase_requisitions SET status = 'cancelled', notes = CONCAT_WS(' | Batal: ', notes, :reason), updated_at = NOW(), updated_by = :uid WHERE id = :id");
            $upd->execute([':reason' => $cancelReason, ':uid' => $user['id'], ':id' => $prId]);

            logAudit($user['id'], 'cancel', 'purchase_requisitions', $prId, ['status' => $pr['status']], [
                'status' => 'cancelled',
                'reason' => $cancelReason,
            ]);

            $db->commit();
            setFlash('success', "Permintaan Pembelian {$pr['pr_number']} berhasil dibatalkan.");

        } elseif ($action === 'delete_pr') {
            if ($pr['status'] !== 'draft') {
                throw new Exception("Hanya PR berstatus Draf yang dapat dihapus.");
            }

            $refs = checkRecordReferences('purchase_requisitions', $prId, $db);
            if (!empty($refs)) {
                throw new Exception("PR {$pr['pr_number']} tidak dapat dihapus karena memiliki keterkaitan: " . implode(', ', $refs));
            }

            $db->beginTransaction();

            $db->prepare("DELETE FROM purchase_requisition_items WHERE purchase_requisition_id = :id")->execute([':id' => $prId]);
            $db->prepare("DELETE FROM purchase_requisitions WHERE id = :id")->execute([':id' => $prId]);

            logAudit($user['id'], 'delete', 'purchase_requisitions', $prId, $pr, null);

            $db->commit();
            setFlash('success', "Draf Permintaan Pembelian {$pr['pr_number']} berhasil dihapus.");
            header('Location: index.php?page=expenditure_procurement/pr');
            exit;
        }

        header("Location: index.php?page=expenditure_procurement/pr_detail&id={$prId}");
        exit;
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        header("Location: index.php?page=expenditure_procurement/pr_detail&id={$prId}");
        exit;
    }
}

// Fetch approval history
$approvalHistory = ApprovalService::getHistory('purchase_requisition', $prId);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Detail Permintaan Pembelian: <?= e($pr['pr_number']) ?></h1>
        <div class="page-subtitle">Status: <?= getStatusBadge($pr['status']) ?> &bull; Tanggal: <?= formatDate($pr['request_date']) ?></div>
    </div>
    <div class="page-actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="index.php?page=expenditure_procurement/pr" class="btn btn-secondary">&laquo; Kembali</a>
        <?php if (in_array($pr['status'], ['draft', 'revision'])): ?>
            <a href="index.php?page=expenditure_procurement/pr_create&id=<?= $pr['id'] ?>" class="btn btn-warning">
                ✏️ Edit PR
            </a>
        <?php endif; ?>
        <?php if ($pr['status'] === 'approved'): ?>
            <a href="index.php?page=expenditure_procurement/po_create&pr_id=<?= $pr['id'] ?>" class="btn btn-success">
                + Buat PO dari PR Ini
            </a>
        <?php endif; ?>
        <?php if (in_array($pr['status'], ['draft', 'pending_approval', 'approved']) && $pr['status'] !== 'ordered'): ?>
            <button type="button" class="btn btn-danger" onclick="openModal('cancel-pr-modal')">
                ✕ Batalkan PR
            </button>
        <?php endif; ?>
        <?php if ($pr['status'] === 'draft'): ?>
            <form action="index.php?page=expenditure_procurement/pr_detail&id=<?= $prId ?>" method="POST" style="display:inline;" onsubmit="return confirm('Hapus draf PR <?= e($pr['pr_number']) ?> secara permanen?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_pr">
                <button type="submit" class="btn btn-secondary" style="color: var(--danger); border-color: var(--danger);">
                    🗑 Hapus
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="detail-grid">
    <div class="detail-item">
        <div class="detail-label">Nomor Dokumen PR</div>
        <div class="detail-value"><code><?= e($pr['pr_number']) ?></code></div>
    </div>
    <div class="detail-item">
        <div class="detail-label">Pemohon & Departemen</div>
        <div class="detail-value"><?= e($pr['requester_name']) ?> &bull; <?= e($pr['dept_name'] ?: '-') ?></div>
    </div>
    <div class="detail-item">
        <div class="detail-label">Anggaran Terkait</div>
        <div class="detail-value">
            <?php if ($pr['budget_id']): ?>
                <?= e($pr['budget_name']) ?> (Sisa: <?= formatRupiah((float)$pr['budget_total'] - (float)$pr['budget_used']) ?>)
            <?php else: ?>
                <span style="color: var(--text-muted);">Tidak menggunakan alokasi anggaran</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="detail-item">
        <div class="detail-label">Total Estimasi Nilai</div>
        <div class="detail-value" style="color: var(--primary); font-size: 16px;">
            <?= formatRupiah($pr['total_estimated']) ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title">Keperluan / Alasan Pengadaan</span>
    </div>
    <div class="card-body">
        <p><?= nl2br(e($pr['reason'])) ?></p>
        <?php if (!empty($pr['notes'])): ?>
            <p style="margin-top: 8px; color: var(--text-muted);"><em>Catatan: <?= e($pr['notes']) ?></em></p>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title">Daftar Bahan / Barang Pengadaan</span>
    </div>
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Kode</th>
                    <th>Nama Barang / Bahan</th>
                    <th class="text-right">Kuantitas</th>
                    <th>Satuan</th>
                    <th class="text-right">Estimasi Harga</th>
                    <th class="text-right">Subtotal</th>
                    <th>Keterangan</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $idx => $it): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><code><?= e($it['item_code']) ?></code></td>
                        <td><strong><?= e($it['item_name']) ?></strong></td>
                        <td class="text-right"><?= formatNumber($it['qty']) ?></td>
                        <td><?= e($it['unit_name']) ?></td>
                        <td class="text-right"><?= formatRupiah($it['estimated_price']) ?></td>
                        <td class="text-right" style="font-weight: 600;"><?= formatRupiah($it['subtotal']) ?></td>
                        <td><?= e($it['notes'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background-color: #f8fafc; font-weight: 700;">
                    <td colspan="6" class="text-right">TOTAL ESTIMASI:</td>
                    <td class="text-right" style="color: var(--primary); font-size: 14px;"><?= formatRupiah($pr['total_estimated']) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Workflow Approval Section -->
<?php if ($pr['status'] === 'pending_approval' && hasRole(['Supervisor / Manajer', 'Administrator', 'manager', 'admin'])): ?>
    <div class="card" style="border-left: 4px solid var(--warning);">
        <div class="card-header">
            <span class="card-title">Persetujuan Manajerial (Approval)</span>
        </div>
        <div class="card-body">
            <form action="index.php?page=expenditure_procurement/pr_detail&id=<?= $prId ?>" method="POST">
                <?= csrfField() ?>
                <div class="form-group">
                    <label class="form-label">Catatan / Alasan (Wajib jika menolak atau meminta revisi)</label>
                    <textarea name="approval_reason" class="form-control" placeholder="Masukkan alasan atau persetujuan..."></textarea>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 14px;">
                    <button type="submit" name="action" value="approve" class="btn btn-success">
                        ✓ Setujui Permintaan (Approve)
                    </button>
                    <button type="submit" name="action" value="revision" class="btn btn-warning">
                        ✏️ Minta Revisi
                    </button>
                    <button type="submit" name="action" value="reject" class="btn btn-danger">
                        ✕ Tolak Permintaan (Reject)
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php elseif ($pr['status'] === 'draft'): ?>
    <div class="card">
        <div class="card-body" style="display: flex; justify-content: flex-end;">
            <form action="index.php?page=expenditure_procurement/pr_detail&id=<?= $prId ?>" method="POST">
                <?= csrfField() ?>
                <button type="submit" name="action" value="submit_approval" class="btn btn-primary">
                    Ajukan ke Manajer untuk Persetujuan &raquo;
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- Riwayat Persetujuan / Audit Log -->
<?php if (!empty($approvalHistory)): ?>
    <div class="card">
        <div class="card-header">
            <span class="card-title">Riwayat Persetujuan & Keputusan</span>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Penyetuju / Keputusan</th>
                        <th>Peran</th>
                        <th>Keputusan</th>
                        <th>Catatan / Alasan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approvalHistory as $h): ?>
                        <tr>
                            <td><?= formatDateTime($h['decided_at'] ?: $h['created_at']) ?></td>
                            <td><strong><?= e($h['approver_name'] ?: 'Sistem') ?></strong></td>
                            <td><?= e($h['approver_role'] ?: '-') ?></td>
                            <td><?= getStatusBadge($h['decision']) ?></td>
                            <td><?= e($h['reason'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Modal Batalkan PR -->
<div id="cancel-pr-modal" class="modal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title">Konfirmasi Pembatalan PR: <?= e($pr['pr_number']) ?></span>
                <button type="button" class="close" onclick="closeModal('cancel-pr-modal')">&times;</button>
            </div>
            <form action="index.php?page=expenditure_procurement/pr_detail&id=<?= $prId ?>" method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="cancel_pr">
                <div class="modal-body">
                    <p style="color: var(--text-muted); margin-bottom: 12px;">
                        Tindakan pembatalan akan mengubah status dokumen menjadi <strong>Dibatalkan</strong> dan mengembalikan alokasi anggaran (jika ada).
                    </p>
                    <div class="form-group">
                        <label class="form-label required">Alasan Pembatalan</label>
                        <textarea name="cancel_reason" class="form-control" rows="3" placeholder="Masukkan alasan pembatalan dokumen pengadaan ini..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('cancel-pr-modal')">Tutup</button>
                    <button type="submit" class="btn btn-danger">✓ Konfirmasi Batalkan PR</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
