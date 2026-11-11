<?php
/**
 * Modul Pengeluaran: Daftar Purchase Requisition (PR)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Permintaan Pembelian (PR)';
$breadcrumbCategory = 'Pengeluaran';

$db = getDbConnection();
$user = currentUser();

// Handle POST actions (Cancel, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $prId = (int)($_POST['pr_id'] ?? 0);

    if ($prId > 0) {
        $stmtPr = $db->prepare("SELECT * FROM purchase_requisitions WHERE id = :id");
        $stmtPr->execute([':id' => $prId]);
        $prData = $stmtPr->fetch();

        if ($prData) {
            if ($action === 'cancel_pr') {
                $cancelReason = trim($_POST['cancel_reason'] ?? '');
                if (empty($cancelReason)) {
                    setFlash('error', 'Alasan pembatalan PR wajib diisi.');
                } else {
                    $refs = checkRecordReferences('purchase_requisitions', $prId, $db);
                    if (!empty($refs)) {
                        setFlash('error', "PR {$prData['pr_number']} tidak dapat dibatalkan karena sudah diproses ke: " . implode(', ', $refs));
                    } else {
                        $db->beginTransaction();
                        if ($prData['status'] === 'approved' && $prData['budget_id']) {
                            $updB = $db->prepare("UPDATE budgets SET used_amount = GREATEST(0, used_amount - :amt), updated_at = NOW() WHERE id = :bid");
                            $updB->execute([':amt' => $prData['total_estimated'], ':bid' => $prData['budget_id']]);
                        }
                        $upd = $db->prepare("UPDATE purchase_requisitions SET status = 'cancelled', notes = CONCAT_WS(' | Batal: ', notes, :reason), updated_at = NOW(), updated_by = :uid WHERE id = :id");
                        $upd->execute([':reason' => $cancelReason, ':uid' => $user['id'], ':id' => $prId]);

                        logAudit($user['id'], 'cancel', 'purchase_requisitions', $prId, ['status' => $prData['status']], [
                            'status' => 'cancelled',
                            'reason' => $cancelReason,
                        ]);
                        $db->commit();
                        setFlash('success', "Permintaan Pembelian {$prData['pr_number']} berhasil dibatalkan.");
                    }
                }
            } elseif ($action === 'delete_pr') {
                if ($prData['status'] !== 'draft') {
                    setFlash('error', "Hanya PR berstatus Draf yang dapat dihapus.");
                } else {
                    $refs = checkRecordReferences('purchase_requisitions', $prId, $db);
                    if (!empty($refs)) {
                        setFlash('error', "PR {$prData['pr_number']} tidak dapat dihapus karena memiliki keterkaitan: " . implode(', ', $refs));
                    } else {
                        $db->beginTransaction();
                        $db->prepare("DELETE FROM purchase_requisition_items WHERE purchase_requisition_id = :id")->execute([':id' => $prId]);
                        $db->prepare("DELETE FROM purchase_requisitions WHERE id = :id")->execute([':id' => $prId]);
                        logAudit($user['id'], 'delete', 'purchase_requisitions', $prId, $prData, null);
                        $db->commit();
                        setFlash('success', "Draf Permintaan Pembelian {$prData['pr_number']} berhasil dihapus.");
                    }
                }
            }
        }
    }
    header('Location: index.php?page=expenditure_procurement/pr');
    exit;
}

// Search & Filter parameters
$search = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(pr.pr_number LIKE :q OR u.full_name LIKE :q OR d.name LIKE :q OR pr.reason LIKE :q)';
    $params[':q'] = "%{$search}%";
}

if ($status !== '') {
    $where[] = 'pr.status = :status';
    $params[':status'] = $status;
}

$whereSql = implode(' AND ', $where);

// Count total
$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM purchase_requisitions pr
    LEFT JOIN users u ON pr.requested_by = u.id
    LEFT JOIN departments d ON pr.department_id = d.id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

// Query data
$queryStmt = $db->prepare("
    SELECT pr.*, u.full_name AS requester_name, d.name AS dept_name, b.code AS budget_code, b.total_amount AS budget_total, b.used_amount AS budget_used
    FROM purchase_requisitions pr
    LEFT JOIN users u ON pr.requested_by = u.id
    LEFT JOIN departments d ON pr.department_id = d.id
    LEFT JOIN budgets b ON pr.budget_id = b.id
    WHERE {$whereSql}
    ORDER BY pr.id DESC
    LIMIT :offset, :per_page
");

foreach ($params as $k => $v) {
    $queryStmt->bindValue($k, $v);
}
$queryStmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$queryStmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$queryStmt->execute();
$prList = $queryStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Permintaan Pembelian (Purchase Requisition)</h1>
        <div class="page-subtitle">Siklus Pengeluaran &bull; Pengajuan Kebutuhan Bahan dan Barang</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=expenditure_procurement/pr_create" class="btn btn-primary">
            + Buat PR Baru
        </a>
    </div>
</div>

<!-- Filter Bar -->
<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="expenditure_procurement/pr">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari nomor PR, departemen..." style="min-width: 220px;">
    </div>
    <div class="filter-group">
        <select name="status">
            <option value="">-- Semua Status --</option>
            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draf</option>
            <option value="pending_approval" <?= $status === 'pending_approval' ? 'selected' : '' ?>>Menunggu Persetujuan</option>
            <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Disetujui</option>
            <option value="ordered" <?= $status === 'ordered' ? 'selected' : '' ?>>Sudah Dipesan (PO)</option>
            <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Ditolak</option>
            <option value="revision" <?= $status === 'revision' ? 'selected' : '' ?>>Revisi</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $status !== ''): ?>
        <a href="index.php?page=expenditure_procurement/pr" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>No. PR</th>
                    <th>Tanggal</th>
                    <th>Departemen</th>
                    <th>Pemohon</th>
                    <th>Keperluan / Alasan</th>
                    <th class="text-right">Estimasi Biaya</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($prList)): ?>
                    <tr>
                        <td colspan="8" class="text-center" style="padding: 24px; color: var(--text-muted);">
                            Tidak ada data permintaan pembelian yang ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($prList as $pr): ?>
                        <tr>
                            <td><strong><?= e($pr['pr_number']) ?></strong></td>
                            <td><?= formatDate($pr['request_date']) ?></td>
                            <td><?= e($pr['dept_name'] ?: '-') ?></td>
                            <td><?= e($pr['requester_name']) ?></td>
                            <td><?= e($pr['reason'] ?: '-') ?></td>
                            <td class="text-right font-weight-bold"><?= formatRupiah($pr['total_estimated']) ?></td>
                            <td class="text-center"><?= getStatusBadge($pr['status']) ?></td>
                            <td class="text-center" style="white-space: nowrap;">
                                <a href="index.php?page=expenditure_procurement/pr_detail&id=<?= $pr['id'] ?>" class="btn btn-sm btn-secondary" title="Detail Dokumen">
                                    Detail
                                </a>
                                <?php if (in_array($pr['status'], ['draft', 'revision'])): ?>
                                    <a href="index.php?page=expenditure_procurement/pr_create&id=<?= $pr['id'] ?>" class="btn btn-sm btn-warning" title="Edit PR">
                                        Edit
                                    </a>
                                <?php endif; ?>
                                <?php if (in_array($pr['status'], ['draft', 'pending_approval', 'approved']) && $pr['status'] !== 'ordered'): ?>
                                    <button type="button" class="btn btn-sm btn-danger" onclick="openCancelModal(<?= $pr['id'] ?>, '<?= e($pr['pr_number']) ?>')" title="Batalkan PR">
                                        Batal
                                    </button>
                                <?php endif; ?>
                                <?php if ($pr['status'] === 'draft'): ?>
                                    <form action="index.php?page=expenditure_procurement/pr" method="POST" style="display:inline;" onsubmit="return confirm('Hapus draf PR <?= e($pr['pr_number']) ?>?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_pr">
                                        <input type="hidden" name="pr_id" value="<?= $pr['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-secondary" style="color: var(--danger);" title="Hapus Draf">
                                            🗑
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer">
        <?= renderPagination($pagination, 'index.php?page=expenditure_procurement/pr&q=' . urlencode($search) . '&status=' . urlencode($status)) ?>
    </div>
</div>

<!-- Modal Batalkan PR -->
<div id="modal-cancel-pr" class="modal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title">Konfirmasi Pembatalan PR: <span id="cancel-pr-num"></span></span>
                <button type="button" class="close" onclick="closeModal('modal-cancel-pr')">&times;</button>
            </div>
            <form action="index.php?page=expenditure_procurement/pr" method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="cancel_pr">
                <input type="hidden" name="pr_id" id="cancel-pr-id" value="">
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
                    <button type="button" class="btn btn-secondary" onclick="closeModal('modal-cancel-pr')">Tutup</button>
                    <button type="submit" class="btn btn-danger">✓ Konfirmasi Batalkan PR</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openCancelModal(prId, prNum) {
    document.getElementById('cancel-pr-id').value = prId;
    document.getElementById('cancel-pr-num').innerText = prNum;
    openModal('modal-cancel-pr');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
