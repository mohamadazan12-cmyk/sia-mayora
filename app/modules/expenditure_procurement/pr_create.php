<?php
/**
 * Modul Pengeluaran: Buat / Edit Purchase Requisition (PR)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$db = getDbConnection();
$user = currentUser();

$editId = (int)($_GET['id'] ?? ($_POST['pr_id'] ?? 0));
$editPr = null;
$editItems = [];

if ($editId > 0) {
    $stmtPr = $db->prepare("SELECT * FROM purchase_requisitions WHERE id = :id");
    $stmtPr->execute([':id' => $editId]);
    $editPr = $stmtPr->fetch();

    if (!$editPr) {
        setFlash('error', 'Data Permintaan Pembelian tidak ditemukan.');
        header('Location: index.php?page=expenditure_procurement/pr');
        exit;
    }

    if (!in_array($editPr['status'], ['draft', 'revision'])) {
        setFlash('error', "PR dengan status '{$editPr['status']}' tidak dapat diedit.");
        header('Location: index.php?page=expenditure_procurement/pr_detail&id=' . $editId);
        exit;
    }

    $pageTitle = 'Edit Permintaan Pembelian (PR): ' . $editPr['pr_number'];

    $stmtItems = $db->prepare("SELECT * FROM purchase_requisition_items WHERE purchase_requisition_id = :id ORDER BY id ASC");
    $stmtItems->execute([':id' => $editId]);
    $editItems = $stmtItems->fetchAll();
} else {
    $pageTitle = 'Buat Permintaan Pembelian (PR)';
}

$breadcrumbCategory = 'Pengeluaran';

// Fetch departments, active budgets, items, units
$departments = $db->query("SELECT * FROM departments WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$budgets = $db->query("SELECT * FROM budgets WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$items = $db->query("SELECT i.*, u.name AS unit_name FROM items i LEFT JOIN units u ON i.unit_id = u.id WHERE i.is_active = 1 ORDER BY i.name ASC")->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $departmentId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $budgetId     = !empty($_POST['budget_id']) ? (int)$_POST['budget_id'] : null;
    $requestDate  = trim($_POST['request_date'] ?? date('Y-m-d'));
    $reason       = trim($_POST['reason'] ?? '');
    $notes        = trim($_POST['notes'] ?? '');
    $submitAction = $_POST['submit_action'] ?? 'submit'; // 'draft' or 'submit'

    $itemIds     = $_POST['item_id'] ?? [];
    $quantities  = $_POST['qty'] ?? [];
    $prices      = $_POST['estimated_price'] ?? [];
    $itemNotes   = $_POST['item_notes'] ?? [];

    if (empty($reason)) {
        $errors[] = 'Alasan / keperluan pengadaan wajib diisi.';
    }
    if (empty($requestDate)) {
        $errors[] = 'Tanggal permintaan wajib diisi.';
    }

    // Process items
    $validItems = [];
    $totalEstimated = 0.0;

    foreach ($itemIds as $idx => $itemId) {
        $itemId = (int)$itemId;
        $qty = parseDecimal($quantities[$idx] ?? 0);
        $price = parseDecimal($prices[$idx] ?? 0);
        $n = trim($itemNotes[$idx] ?? '');

        if ($itemId > 0 && $qty > 0) {
            $subtotal = $qty * $price;
            $totalEstimated += $subtotal;
            $validItems[] = [
                'item_id'         => $itemId,
                'qty'             => $qty,
                'estimated_price' => $price,
                'subtotal'        => $subtotal,
                'notes'           => $n,
            ];
        }
    }

    if (empty($validItems)) {
        $errors[] = 'Minimal satu barang/bahan dengan kuantitas lebih dari 0 harus ditambahkan.';
    }

    // Check budget availability if budget is selected
    if ($budgetId && empty($errors)) {
        $stmtB = $db->prepare("SELECT total_amount, used_amount, name FROM budgets WHERE id = :id");
        $stmtB->execute([':id' => $budgetId]);
        $budget = $stmtB->fetch();
        if ($budget) {
            $remainingBudget = (float)$budget['total_amount'] - (float)$budget['used_amount'];
            if ($totalEstimated > $remainingBudget) {
                $errors[] = "Total estimasi PR (" . formatRupiah($totalEstimated) . ") melebihi sisa anggaran '{$budget['name']}' yang tersedia (" . formatRupiah($remainingBudget) . ").";
            }
        }
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $status = ($submitAction === 'draft') ? 'draft' : 'pending_approval';

            if ($editPr) {
                $prId = $editPr['id'];
                $prNumber = $editPr['pr_number'];

                $stmtUpd = $db->prepare("
                    UPDATE purchase_requisitions 
                    SET department_id = :dept_id, request_date = :req_date, reason = :reason,
                        budget_id = :budget_id, total_estimated = :total_est, notes = :notes,
                        status = :status, updated_by = :uid, updated_at = NOW()
                    WHERE id = :id
                ");
                $stmtUpd->execute([
                    ':dept_id'   => $departmentId,
                    ':req_date'  => $requestDate,
                    ':reason'    => $reason,
                    ':budget_id' => $budgetId,
                    ':total_est' => $totalEstimated,
                    ':notes'     => $notes,
                    ':status'    => $status,
                    ':uid'       => $user['id'],
                    ':id'        => $prId,
                ]);

                // Delete old items
                $db->prepare("DELETE FROM purchase_requisition_items WHERE purchase_requisition_id = :id")->execute([':id' => $prId]);

                // Insert updated items
                $stmtItem = $db->prepare("
                    INSERT INTO purchase_requisition_items 
                    (purchase_requisition_id, item_id, qty, unit_id, estimated_price, subtotal, notes)
                    VALUES 
                    (:pr_id, :item_id, :qty, (SELECT unit_id FROM items WHERE id = :item_id_lookup), :price, :subtotal, :notes)
                ");

                foreach ($validItems as $vi) {
                    $stmtItem->execute([
                        ':pr_id'           => $prId,
                        ':item_id'         => $vi['item_id'],
                        ':item_id_lookup'  => $vi['item_id'],
                        ':qty'             => $vi['qty'],
                        ':price'           => $vi['estimated_price'],
                        ':subtotal'        => $vi['subtotal'],
                        ':notes'           => $vi['notes'],
                    ]);
                }

                if ($status === 'pending_approval') {
                    ApprovalService::submit('purchase_requisition', $prId, $prNumber, 'Pengajuan ulang PR yang diperbarui', $db);
                }

                logAudit($user['id'], 'update', 'purchase_requisitions', $prId, $editPr, [
                    'pr_number' => $prNumber,
                    'total'     => $totalEstimated,
                    'status'    => $status,
                ]);

                $db->commit();
                setFlash('success', "Permintaan Pembelian {$prNumber} berhasil diperbarui.");
                header('Location: index.php?page=expenditure_procurement/pr_detail&id=' . $prId);
                exit;

            } else {
                $prNumber = generateDocNumber('PR', 'purchase_requisitions', 'pr_number');

                $stmt = $db->prepare("
                    INSERT INTO purchase_requisitions 
                    (pr_number, department_id, requested_by, request_date, reason, budget_id, total_estimated, notes, status, created_by, created_at)
                    VALUES 
                    (:pr_number, :department_id, :requested_by, :request_date, :reason, :budget_id, :total_estimated, :notes, :status, :created_by, NOW())
                ");
                $stmt->execute([
                    ':pr_number'       => $prNumber,
                    ':department_id'   => $departmentId,
                    ':requested_by'    => $user['id'],
                    ':request_date'    => $requestDate,
                    ':reason'          => $reason,
                    ':budget_id'       => $budgetId,
                    ':total_estimated' => $totalEstimated,
                    ':notes'           => $notes,
                    ':status'          => $status,
                    ':created_by'      => $user['id'],
                ]);
                $prId = $db->lastInsertId();

                // Insert items
                $stmtItem = $db->prepare("
                    INSERT INTO purchase_requisition_items 
                    (purchase_requisition_id, item_id, qty, unit_id, estimated_price, subtotal, notes)
                    VALUES 
                    (:pr_id, :item_id, :qty, (SELECT unit_id FROM items WHERE id = :item_id_lookup), :price, :subtotal, :notes)
                ");

                foreach ($validItems as $vi) {
                    $stmtItem->execute([
                        ':pr_id'           => $prId,
                        ':item_id'         => $vi['item_id'],
                        ':item_id_lookup'  => $vi['item_id'],
                        ':qty'             => $vi['qty'],
                        ':price'           => $vi['estimated_price'],
                        ':subtotal'        => $vi['subtotal'],
                        ':notes'           => $vi['notes'],
                    ]);
                }

                // Record workflow approval submission
                if ($status === 'pending_approval') {
                    ApprovalService::submit('purchase_requisition', $prId, $prNumber, 'Pengajuan PR baru', $db);
                }

                logAudit($user['id'], 'create', 'purchase_requisitions', $prId, null, [
                    'pr_number' => $prNumber,
                    'total'     => $totalEstimated,
                    'status'    => $status,
                ]);

                $db->commit();

                setFlash('success', "Permintaan Pembelian {$prNumber} berhasil disimpan dengan status " . ($status === 'draft' ? 'Draf' : 'Menunggu Persetujuan') . ".");
                header('Location: index.php?page=expenditure_procurement/pr_detail&id=' . $prId);
                exit;
            }
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Gagal menyimpan PR: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= $editPr ? 'Edit Permintaan Pembelian: ' . e($editPr['pr_number']) : 'Buat Permintaan Pembelian (PR) Baru' ?></h1>
        <div class="page-subtitle">Formulir Pengajuan Pengadaan Bahan Baku dan Material</div>
    </div>
    <div class="page-actions">
        <a href="<?= $editPr ? 'index.php?page=expenditure_procurement/pr_detail&id=' . $editPr['id'] : 'index.php?page=expenditure_procurement/pr' ?>" class="btn btn-secondary">&laquo; Kembali</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <strong>Terjadi Kesalahan:</strong>
        <ul style="margin-left: 20px; margin-top: 6px;">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form action="index.php?page=expenditure_procurement/pr_create<?= $editPr ? '&id=' . $editPr['id'] : '' ?>" method="POST">
    <?= csrfField() ?>
    <?php if ($editPr): ?>
        <input type="hidden" name="pr_id" value="<?= $editPr['id'] ?>">
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <span class="card-title">Informasi Utama Permintaan</span>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label required">Tanggal Permintaan</label>
                        <input type="date" name="request_date" class="form-control" value="<?= e($editPr['request_date'] ?? date('Y-m-d')) ?>" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label">Departemen Pemohon</label>
                        <select name="department_id" class="form-control">
                            <option value="">-- Pilih Departemen --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= (($editPr['department_id'] ?? '') == $d['id']) ? 'selected' : '' ?>>
                                    <?= e($d['name']) ?> (<?= e($d['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-group">
                        <label class="form-label">Alokasi Anggaran (Budget)</label>
                        <select name="budget_id" class="form-control">
                            <option value="">-- Tanpa / Pilih Anggaran --</option>
                            <?php foreach ($budgets as $b): ?>
                                <?php $rem = (float)$b['total_amount'] - (float)$b['used_amount']; ?>
                                <option value="<?= $b['id'] ?>" <?= (($editPr['budget_id'] ?? '') == $b['id']) ? 'selected' : '' ?>>
                                    <?= e($b['name']) ?> &bull; Sisa: <?= formatRupiah($rem) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Pemeriksaan anggaran otomatis saat pengajuan</div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label required">Keperluan / Alasan Pengadaan</label>
                <textarea name="reason" class="form-control" placeholder="Contoh: Kebutuhan pemenuhan stok bahan baku untuk batch produksi biskuit minggu ke-3" required><?= e($editPr['reason'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Catatan Tambahan</label>
                <input type="text" name="notes" class="form-control" value="<?= e($editPr['notes'] ?? '') ?>" placeholder="Catatan teknis atau spesifikasi khusus bila ada">
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <span class="card-title">Daftar Barang / Bahan yang Diminta</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table" id="pr-items-table">
                    <thead>
                        <tr>
                            <th style="width: 40%;">Barang / Bahan Baku</th>
                            <th style="width: 20%;" class="text-right">Jumlah (Kuantitas)</th>
                            <th style="width: 20%;" class="text-right">Perkiraan Harga Satuan (Rp)</th>
                            <th style="width: 20%;">Catatan Item</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rowCount = max(4, count($editItems));
                        for ($i = 0; $i < $rowCount; $i++): 
                            $itVal = $editItems[$i] ?? null;
                        ?>
                            <tr>
                                <td>
                                    <select name="item_id[]" class="form-control">
                                        <option value="">-- Pilih Barang / Bahan --</option>
                                        <?php foreach ($items as $it): ?>
                                            <option value="<?= $it['id'] ?>" <?= ($itVal && $itVal['item_id'] == $it['id']) ? 'selected' : '' ?>>
                                                <?= e($it['name']) ?> (<?= e($it['code']) ?> - <?= e($it['unit_name']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" name="qty[]" class="form-control text-right" value="<?= $itVal ? formatNumber($itVal['qty']) : '' ?>" placeholder="0.00">
                                </td>
                                <td>
                                    <input type="text" name="estimated_price[]" class="form-control text-right" value="<?= $itVal ? formatNumber($itVal['estimated_price'], 0) : '' ?>" placeholder="0">
                                </td>
                                <td>
                                    <input type="text" name="item_notes[]" class="form-control" value="<?= e($itVal['notes'] ?? '') ?>" placeholder="Keterangan opsional">
                                </td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-text" style="margin-top: 8px;">
                * Isi baris sesuai kebutuhan barang yang ingin diajukan. Baris kosong akan diabaikan secara otomatis.
            </div>
        </div>
        <div class="card-footer" style="display: flex; gap: 10px; justify-content: flex-end;">
            <button type="submit" name="submit_action" value="draft" class="btn btn-secondary">
                Simpan sebagai Draf
            </button>
            <button type="submit" name="submit_action" value="submit" class="btn btn-primary">
                <?= $editPr ? 'Perbarui & Kirim Persetujuan' : 'Kirim untuk Persetujuan (Submit)' ?>
            </button>
        </div>
    </div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
