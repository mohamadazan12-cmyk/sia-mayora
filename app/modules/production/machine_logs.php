<?php
/**
 * Modul Produksi: Log Operasional dan Aktivitas Mesin Pabrik
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Aktivitas Mesin Pabrik';
$breadcrumbCategory = 'Produksi';

$db = getDbConnection();
$user = currentUser();

// Handle POST: Edit or Delete Log
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'edit_log') {
        $logId = (int)($_POST['log_id'] ?? 0);
        $machineName = trim($_POST['machine_name'] ?? '');
        $duration = (int)($_POST['duration_minutes'] ?? 0);
        $operator = trim($_POST['operator'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($logId <= 0 || empty($machineName) || empty($operator)) {
            setFlash('error', 'Semua bidang wajib diisi.');
            header('Location: index.php?page=production/machine_logs');
            exit;
        }

        try {
            $stmtOld = $db->prepare("SELECT * FROM machine_logs WHERE id = :id");
            $stmtOld->execute([':id' => $logId]);
            $oldLog = $stmtOld->fetch();

            if (!$oldLog) {
                throw new Exception('Data log mesin tidak ditemukan.');
            }

            $db->prepare("
                UPDATE machine_logs 
                SET machine_name = :mname, duration_minutes = :dur, operator = :op, notes = :notes,
                    updated_at = NOW(), updated_by = :uid
                WHERE id = :id
            ")->execute([
                ':mname' => $machineName,
                ':dur'   => $duration,
                ':op'    => $operator,
                ':notes' => $notes,
                ':uid'   => $user['id'],
                ':id'    => $logId,
            ]);

            logAudit($user['id'], 'update', 'machine_logs', $logId, $oldLog, [
                'machine_name'     => $machineName,
                'duration_minutes' => $duration,
                'operator'         => $operator,
            ]);

            setFlash('success', 'Catatan aktivitas mesin berhasil diperbarui.');
            header('Location: index.php?page=production/machine_logs');
            exit;
        } catch (Exception $e) {
            setFlash('error', 'Gagal memperbarui log: ' . $e->getMessage());
            header('Location: index.php?page=production/machine_logs');
            exit;
        }
    } elseif ($action === 'delete_log') {
        $logId = (int)($_POST['log_id'] ?? 0);
        try {
            $stmtOld = $db->prepare("SELECT * FROM machine_logs WHERE id = :id");
            $stmtOld->execute([':id' => $logId]);
            $oldLog = $stmtOld->fetch();

            if (!$oldLog) {
                throw new Exception('Data log mesin tidak ditemukan.');
            }

            $db->prepare("DELETE FROM machine_logs WHERE id = :id")->execute([':id' => $logId]);

            logAudit($user['id'], 'delete', 'machine_logs', $logId, $oldLog, null);

            setFlash('success', 'Catatan aktivitas mesin berhasil dihapus.');
            header('Location: index.php?page=production/machine_logs');
            exit;
        } catch (Exception $e) {
            setFlash('error', 'Gagal menghapus log: ' . $e->getMessage());
            header('Location: index.php?page=production/machine_logs');
            exit;
        }
    }
}

$search = trim($_GET['q'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(ml.machine_name LIKE :q OR ml.operator LIKE :q OR pdo.po_number LIKE :q)';
    $params[':q'] = "%{$search}%";
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM machine_logs ml
    JOIN production_orders pdo ON ml.production_order_id = pdo.id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

// List
$stmt = $db->prepare("
    SELECT ml.*, pdo.po_number, i.name AS product_name
    FROM machine_logs ml
    JOIN production_orders pdo ON ml.production_order_id = pdo.id
    JOIN items i ON pdo.product_item_id = i.id
    WHERE {$whereSql}
    ORDER BY ml.id DESC
    LIMIT :offset, :per_page
");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Catatan Operasional & Aktivitas Mesin Pabrik</h1>
        <div class="page-subtitle">Siklus Produksi &bull; Pemantauan Jam Kerja Mesin, Efisiensi Waktu, dan Penanggung Jawab Operator</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=production/orders" class="btn btn-secondary">&laquo; Kembali ke SPK</a>
    </div>
</div>

<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="production/machine_logs">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari mesin, operator, nomor SPK..." style="min-width: 260px;">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== ''): ?>
        <a href="index.php?page=production/machine_logs" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Mesin Produksi</th>
                    <th>Perintah Produksi (SPK)</th>
                    <th>Produk Dibuat</th>
                    <th>Waktu Mulai</th>
                    <th>Waktu Selesai</th>
                    <th class="text-right">Durasi Kerja</th>
                    <th>Operator Mesin</th>
                    <th>Keterangan / Kondisi</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="9" class="text-center" style="padding: 24px; color: var(--text-muted);">
                            Belum ada riwayat aktivitas mesin yang tercatat.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $ml): ?>
                        <tr>
                            <td><strong><?= e($ml['machine_name']) ?></strong></td>
                            <td><a href="index.php?page=production/order_detail&id=<?= $ml['production_order_id'] ?>"><strong><?= e($ml['po_number']) ?></strong></a></td>
                            <td><?= e($ml['product_name']) ?></td>
                            <td><?= formatDateTime($ml['start_time']) ?></td>
                            <td><?= formatDateTime($ml['end_time']) ?></td>
                            <td class="text-right font-weight-bold" style="color: var(--primary);">
                                <?= $ml['duration_minutes'] ?> menit
                            </td>
                            <td><?= e($ml['operator']) ?></td>
                            <td><?= e($ml['notes'] ?: '-') ?></td>
                            <td class="text-center" style="white-space:nowrap;">
                                <button type="button" class="btn btn-sm btn-primary btn-edit-ml"
                                    data-id="<?= $ml['id'] ?>"
                                    data-name="<?= e($ml['machine_name']) ?>"
                                    data-duration="<?= $ml['duration_minutes'] ?>"
                                    data-operator="<?= e($ml['operator']) ?>"
                                    data-notes="<?= e($ml['notes'] ?: '') ?>">
                                    Edit
                                </button>
                                <button type="button" class="btn btn-sm btn-danger btn-delete-ml"
                                    data-id="<?= $ml['id'] ?>"
                                    data-name="<?= e($ml['machine_name']) ?>">
                                    Hapus
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer">
        <?= renderPagination($pagination, 'index.php?page=production/machine_logs&q=' . urlencode($search)) ?>
    </div>
</div>

<!-- Modal Edit Machine Log -->
<div id="modalEditMl" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; border-radius:8px; max-width:480px; width:90%; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0;">Edit Aktivitas Mesin</h3>
        <form action="index.php?page=production/machine_logs" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit_log">
            <input type="hidden" name="log_id" id="editMlId">
            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label required">Nama Mesin</label>
                <input type="text" name="machine_name" id="editMlName" class="form-control" required>
            </div>
            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label required">Durasi Operasi (Menit)</label>
                <input type="number" name="duration_minutes" id="editMlDuration" class="form-control" min="1" required>
            </div>
            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label required">Operator Mesin</label>
                <input type="text" name="operator" id="editMlOperator" class="form-control" required>
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Keterangan / Kendala Mesin</label>
                <textarea name="notes" id="editMlNotes" class="form-control" rows="2"></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalEditMl').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Delete Machine Log -->
<div id="modalDeleteMl" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; border-radius:8px; max-width:420px; width:90%; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:var(--danger);">Hapus Catatan Mesin</h3>
        <p style="color:var(--text-muted); font-size:14px;">
            Apakah Anda yakin ingin menghapus catatan log mesin <strong id="deleteMlName"></strong>?
        </p>
        <form action="index.php?page=production/machine_logs" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_log">
            <input type="hidden" name="log_id" id="deleteMlId">
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalDeleteMl').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-danger">Hapus Log</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-edit-ml').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('editMlId').value = d.id;
            document.getElementById('editMlName').value = d.name;
            document.getElementById('editMlDuration').value = d.duration;
            document.getElementById('editMlOperator').value = d.operator;
            document.getElementById('editMlNotes').value = d.notes;
            document.getElementById('modalEditMl').style.display = 'flex';
        });
    });

    document.querySelectorAll('.btn-delete-ml').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('deleteMlId').value = d.id;
            document.getElementById('deleteMlName').innerText = d.name;
            document.getElementById('modalDeleteMl').style.display = 'flex';
        });
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
