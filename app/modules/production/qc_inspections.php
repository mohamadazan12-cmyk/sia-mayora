<?php
/**
 * Modul Produksi: Pemeriksaan Mutu (Quality Control / QC)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Pemeriksaan Mutu (QC)';
$breadcrumbCategory = 'Produksi';

$db = getDbConnection();
$user = currentUser();

// Handle POST: Edit or Delete QC
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'edit_qc') {
        $qcId = (int)($_POST['qc_id'] ?? 0);
        $parameters = trim($_POST['parameters'] ?? '');
        $qcResult = trim($_POST['result'] ?? 'pass');
        $findings = trim($_POST['findings'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($qcId <= 0 || empty($parameters) || empty($findings)) {
            setFlash('error', 'Parameter pengujian dan temuan wajib diisi.');
            header('Location: index.php?page=production/qc_inspections');
            exit;
        }

        try {
            $stmtOld = $db->prepare("SELECT * FROM qc_inspections WHERE id = :id");
            $stmtOld->execute([':id' => $qcId]);
            $oldQc = $stmtOld->fetch();

            if (!$oldQc) {
                throw new Exception('Data inspeksi QC tidak ditemukan.');
            }

            $db->prepare("
                UPDATE qc_inspections 
                SET parameters = :params, result = :res, findings = :find, notes = :notes,
                    updated_at = NOW(), updated_by = :uid
                WHERE id = :id
            ")->execute([
                ':params' => $parameters,
                ':res'    => $qcResult,
                ':find'   => $findings,
                ':notes'  => $notes,
                ':uid'    => $user['id'],
                ':id'     => $qcId,
            ]);

            logAudit($user['id'], 'update', 'qc_inspections', $qcId, $oldQc, [
                'result'   => $qcResult,
                'findings' => $findings,
            ]);

            setFlash('success', "Inspeksi QC {$oldQc['inspection_number']} berhasil diperbarui.");
            header('Location: index.php?page=production/qc_inspections');
            exit;
        } catch (Exception $e) {
            setFlash('error', 'Gagal memperbarui QC: ' . $e->getMessage());
            header('Location: index.php?page=production/qc_inspections');
            exit;
        }
    } elseif ($action === 'delete_qc') {
        $qcId = (int)($_POST['qc_id'] ?? 0);
        try {
            $stmtOld = $db->prepare("SELECT * FROM qc_inspections WHERE id = :id");
            $stmtOld->execute([':id' => $qcId]);
            $oldQc = $stmtOld->fetch();

            if (!$oldQc) {
                throw new Exception('Data inspeksi QC tidak ditemukan.');
            }

            // Check if referenced by production_outputs
            $outCount = (int)$db->query("SELECT COUNT(*) FROM production_outputs WHERE qc_inspection_id = {$qcId}")->fetchColumn();
            if ($outCount > 0) {
                throw new Exception('Pemeriksaan QC tidak dapat dihapus karena sudah dijadikan dasar persetujuan hasil barang jadi (output).');
            }

            $db->prepare("DELETE FROM qc_inspections WHERE id = :id")->execute([':id' => $qcId]);

            logAudit($user['id'], 'delete', 'qc_inspections', $qcId, $oldQc, null);

            setFlash('success', "Pemeriksaan QC {$oldQc['inspection_number']} berhasil dihapus.");
            header('Location: index.php?page=production/qc_inspections');
            exit;
        } catch (Exception $e) {
            setFlash('error', 'Gagal menghapus QC: ' . $e->getMessage());
            header('Location: index.php?page=production/qc_inspections');
            exit;
        }
    }
}

$search = trim($_GET['q'] ?? '');
$result = trim($_GET['result'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

$where = ["qc.ref_type = 'production'"];
$params = [];

if ($search !== '') {
    $where[] = '(qc.inspection_number LIKE :q OR pdo.po_number LIKE :q OR i.name LIKE :q OR qc.findings LIKE :q)';
    $params[':q'] = "%{$search}%";
}

if ($result !== '') {
    $where[] = 'qc.result = :result';
    $params[':result'] = $result;
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM qc_inspections qc
    JOIN production_orders pdo ON qc.ref_id = pdo.id
    JOIN items i ON pdo.product_item_id = i.id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

// List
$stmt = $db->prepare("
    SELECT qc.*, pdo.po_number, i.name AS product_name, u.full_name AS inspector_name
    FROM qc_inspections qc
    JOIN production_orders pdo ON qc.ref_id = pdo.id
    JOIN items i ON pdo.product_item_id = i.id
    LEFT JOIN users u ON qc.inspector_id = u.id
    WHERE {$whereSql}
    ORDER BY qc.id DESC
    LIMIT :offset, :per_page
");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$inspections = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Pemeriksaan Kualitas & Mutu Produk (QC)</h1>
        <div class="page-subtitle">Siklus Produksi &bull; Pengujian Parameter Fisik, Kimia, CCP, dan Kelayakan Edar Produk Jadi</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=production/orders" class="btn btn-secondary">&laquo; Kembali ke SPK</a>
    </div>
</div>

<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="production/qc_inspections">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari nomor QC, SPK, temuan..." style="min-width: 250px;">
    </div>
    <div class="filter-group">
        <select name="result">
            <option value="">-- Semua Hasil QC --</option>
            <option value="pass" <?= $result === 'pass' ? 'selected' : '' ?>>Lulus (Pass)</option>
            <option value="fail" <?= $result === 'fail' ? 'selected' : '' ?>>Tidak Lulus / Gagal (Fail)</option>
            <option value="conditional" <?= $result === 'conditional' ? 'selected' : '' ?>>Lulus Bersyarat / Rework</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $result !== ''): ?>
        <a href="index.php?page=production/qc_inspections" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>No. Inspeksi QC</th>
                    <th>Tanggal Uji</th>
                    <th>Ref. SPK Produksi</th>
                    <th>Produk Diuji</th>
                    <th>Petugas Inspektur</th>
                    <th>Parameter Pemeriksaan</th>
                    <th class="text-center">Hasil Keputusan</th>
                    <th>Temuan & Catatan</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($inspections)): ?>
                    <tr>
                        <td colspan="9" class="text-center" style="padding: 24px; color: var(--text-muted);">
                            Belum ada riwayat inspeksi mutu QC yang tercatat.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($inspections as $qc): ?>
                        <tr>
                            <td><strong><?= e($qc['inspection_number']) ?></strong></td>
                            <td><?= formatDate($qc['inspection_date']) ?></td>
                            <td><a href="index.php?page=production/order_detail&id=<?= $qc['ref_id'] ?>"><strong><?= e($qc['po_number']) ?></strong></a></td>
                            <td><?= e($qc['product_name']) ?></td>
                            <td><?= e($qc['inspector_name']) ?></td>
                            <td><?= e($qc['parameters']) ?></td>
                            <td class="text-center"><?= getStatusBadge($qc['result']) ?></td>
                            <td>
                                <strong><?= e($qc['findings']) ?></strong><br>
                                <span style="font-size: 11px; color: var(--text-muted);"><?= e($qc['notes'] ?: '-') ?></span>
                            </td>
                            <td class="text-center" style="white-space:nowrap;">
                                <button type="button" class="btn btn-sm btn-secondary btn-detail-qc"
                                    data-num="<?= e($qc['inspection_number']) ?>"
                                    data-date="<?= formatDate($qc['inspection_date']) ?>"
                                    data-po="<?= e($qc['po_number']) ?>"
                                    data-prod="<?= e($qc['product_name']) ?>"
                                    data-inspector="<?= e($qc['inspector_name']) ?>"
                                    data-params="<?= e($qc['parameters']) ?>"
                                    data-result="<?= e($qc['result']) ?>"
                                    data-findings="<?= e($qc['findings']) ?>"
                                    data-notes="<?= e($qc['notes'] ?: '-') ?>">
                                    Detail
                                </button>
                                <button type="button" class="btn btn-sm btn-primary btn-edit-qc"
                                    data-id="<?= $qc['id'] ?>"
                                    data-num="<?= e($qc['inspection_number']) ?>"
                                    data-params="<?= e($qc['parameters']) ?>"
                                    data-result="<?= e($qc['result']) ?>"
                                    data-findings="<?= e($qc['findings']) ?>"
                                    data-notes="<?= e($qc['notes'] ?: '') ?>">
                                    Edit
                                </button>
                                <button type="button" class="btn btn-sm btn-danger btn-delete-qc"
                                    data-id="<?= $qc['id'] ?>"
                                    data-num="<?= e($qc['inspection_number']) ?>">
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
        <?= renderPagination($pagination, 'index.php?page=production/qc_inspections&q=' . urlencode($search) . '&result=' . urlencode($result)) ?>
    </div>
</div>

<!-- Modal Detail QC -->
<div id="modalDetailQc" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; border-radius:8px; max-width:550px; width:90%; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.2);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
            <h3 style="margin:0;" id="dtQcTitle">Detail Pemeriksaan Mutu</h3>
            <button type="button" style="background:none; border:none; font-size:20px; cursor:pointer;" onclick="document.getElementById('modalDetailQc').style.display='none'">&times;</button>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:13.5px; margin-bottom:15px;">
            <div><strong>No. Inspeksi QC:</strong> <span id="dtQcNum"></span></div>
            <div><strong>Tanggal Pengujian:</strong> <span id="dtQcDate"></span></div>
            <div><strong>Ref. SPK:</strong> <span id="dtQcPo"></span></div>
            <div><strong>Produk Diuji:</strong> <span id="dtQcProd"></span></div>
            <div><strong>Inspektur:</strong> <span id="dtQcInspector"></span></div>
            <div><strong>Hasil Keputusan:</strong> <span id="dtQcResult"></span></div>
            <div style="grid-column:1/-1;"><strong>Parameter Diuji:</strong> <span id="dtQcParams"></span></div>
            <div style="grid-column:1/-1;"><strong>Temuan Pengujian:</strong> <span id="dtQcFindings"></span></div>
            <div style="grid-column:1/-1;"><strong>Catatan Tambahan:</strong> <span id="dtQcNotes"></span></div>
        </div>
        <div style="display:flex; justify-content:flex-end;">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalDetailQc').style.display='none'">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal Edit QC -->
<div id="modalEditQc" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; border-radius:8px; max-width:520px; width:90%; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0;">Edit Pemeriksaan Mutu (QC)</h3>
        <form action="index.php?page=production/qc_inspections" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit_qc">
            <input type="hidden" name="qc_id" id="editQcId">
            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label required">Parameter Pemeriksaan</label>
                <input type="text" name="parameters" id="editQcParams" class="form-control" required>
            </div>
            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label required">Keputusan Hasil</label>
                <select name="result" id="editQcResult" class="form-control" required>
                    <option value="pass">Lulus QC (Pass)</option>
                    <option value="fail">Tidak Lulus / Gagal (Fail)</option>
                    <option value="conditional">Lulus Bersyarat (Rework)</option>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:12px;">
                <label class="form-label required">Temuan Pemeriksaan</label>
                <input type="text" name="findings" id="editQcFindings" class="form-control" required>
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label">Catatan</label>
                <textarea name="notes" id="editQcNotes" class="form-control" rows="2"></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalEditQc').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan QC</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Delete QC -->
<div id="modalDeleteQc" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; border-radius:8px; max-width:420px; width:90%; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:var(--danger);">Hapus Pemeriksaan QC</h3>
        <p style="color:var(--text-muted); font-size:14px;">
            Apakah Anda yakin ingin menghapus inspeksi mutu <strong id="deleteQcNum"></strong>?
        </p>
        <form action="index.php?page=production/qc_inspections" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_qc">
            <input type="hidden" name="qc_id" id="deleteQcId">
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalDeleteQc').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-danger">Hapus QC</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-detail-qc').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('dtQcTitle').innerText = 'Detail QC: ' + d.num;
            document.getElementById('dtQcNum').innerText = d.num;
            document.getElementById('dtQcDate').innerText = d.date;
            document.getElementById('dtQcPo').innerText = d.po;
            document.getElementById('dtQcProd').innerText = d.prod;
            document.getElementById('dtQcInspector').innerText = d.inspector;
            document.getElementById('dtQcResult').innerText = d.result.toUpperCase();
            document.getElementById('dtQcParams').innerText = d.params;
            document.getElementById('dtQcFindings').innerText = d.findings;
            document.getElementById('dtQcNotes').innerText = d.notes;
            document.getElementById('modalDetailQc').style.display = 'flex';
        });
    });

    document.querySelectorAll('.btn-edit-qc').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('editQcId').value = d.id;
            document.getElementById('editQcParams').value = d.params;
            document.getElementById('editQcResult').value = d.result;
            document.getElementById('editQcFindings').value = d.findings;
            document.getElementById('editQcNotes').value = d.notes;
            document.getElementById('modalEditQc').style.display = 'flex';
        });
    });

    document.querySelectorAll('.btn-delete-qc').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('deleteQcId').value = d.id;
            document.getElementById('deleteQcNum').innerText = d.num;
            document.getElementById('modalDeleteQc').style.display = 'flex';
        });
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
