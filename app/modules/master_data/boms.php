<?php
/**
 * Data Master: Resep / Formula BOM (Bill of Materials)
 * PT Mayora Indah Tbk
 */

$pageTitle = 'Resep / Formula BOM (Bill of Materials)';
$breadcrumbCategory = 'Data Master';

$db = getDbConnection();
$user = currentUser();

$selectedBomId = (int)($_GET['id'] ?? 0);
$editBomId = (int)($_GET['edit_bom_id'] ?? 0);

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $action = $_POST['action'] ?? '';

    // 1. Save (Create or Update) BOM Header
    if ($action === 'save_bom') {
        if (!hasRole(['Administrator', 'admin', 'ppic'])) {
            setFlash('error', 'Akses ditolak.');
            header('Location: index.php?page=master_data/boms');
            exit;
        }

        $bomId = (int)($_POST['bom_id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $itemId = (int)($_POST['product_item_id'] ?? 0);
        $version = max(1, (int)($_POST['version'] ?? 1));
        $notes = trim($_POST['notes'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($code) || $itemId <= 0) {
            setFlash('error', 'Kode BOM dan produk jadi hasil produksi wajib diisi.');
            header("Location: index.php?page=master_data/boms" . ($bomId > 0 ? "&edit_bom_id={$bomId}" : ''));
            exit;
        }

        try {
            // Get product name if name is empty
            if (empty($name)) {
                $stmtItem = $db->prepare("SELECT name FROM items WHERE id = :id");
                $stmtItem->execute([':id' => $itemId]);
                $name = 'Formula ' . ($stmtItem->fetchColumn() ?: $code);
            }

            if ($bomId > 0) {
                // Check duplicate code
                $stmtDup = $db->prepare("SELECT id FROM boms WHERE code = :code AND id != :id");
                $stmtDup->execute([':code' => $code, ':id' => $bomId]);
                if ($stmtDup->fetchColumn()) {
                    throw new Exception("Kode BOM '{$code}' sudah digunakan oleh formula lain.");
                }

                $stmtOld = $db->prepare("SELECT * FROM boms WHERE id = :id");
                $stmtOld->execute([':id' => $bomId]);
                $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

                if (!$oldData) {
                    throw new Exception("Data BOM tidak ditemukan.");
                }

                $stmtUpd = $db->prepare("
                    UPDATE boms 
                    SET code = :code, name = :name, product_item_id = :item_id, version = :ver,
                        notes = :notes, is_active = :act, updated_by = :uid, updated_at = NOW()
                    WHERE id = :id
                ");
                $stmtUpd->execute([
                    ':code'    => $code,
                    ':name'    => $name,
                    ':item_id' => $itemId,
                    ':ver'     => $version,
                    ':notes'   => $notes ?: null,
                    ':act'     => $isActive,
                    ':uid'     => $user['id'],
                    ':id'      => $bomId,
                ]);

                logAudit($user['id'], 'update_bom', 'boms', $bomId, $oldData, [
                    'code' => $code, 'name' => $name, 'version' => $version, 'is_active' => $isActive
                ]);

                setFlash('success', "Formula BOM {$name} ({$code}) berhasil diperbarui.");
                header("Location: index.php?page=master_data/boms&id={$bomId}");
                exit;
            } else {
                // Check duplicate code
                $stmtDup = $db->prepare("SELECT id FROM boms WHERE code = :code");
                $stmtDup->execute([':code' => $code]);
                if ($stmtDup->fetchColumn()) {
                    throw new Exception("Kode BOM '{$code}' sudah terdaftar.");
                }

                $stmtIns = $db->prepare("
                    INSERT INTO boms (code, name, product_item_id, version, notes, is_active, created_by, updated_by, created_at, updated_at)
                    VALUES (:code, :name, :item_id, :ver, :notes, :act, :uid, :uid, NOW(), NOW())
                ");
                $stmtIns->execute([
                    ':code'    => $code,
                    ':name'    => $name,
                    ':item_id' => $itemId,
                    ':ver'     => $version,
                    ':notes'   => $notes ?: null,
                    ':act'     => $isActive,
                    ':uid'     => $user['id'],
                ]);
                $newBomId = $db->lastInsertId();

                logAudit($user['id'], 'create_bom', 'boms', $newBomId, null, [
                    'code' => $code, 'name' => $name, 'version' => $version, 'is_active' => $isActive
                ]);

                setFlash('success', "Formula BOM {$name} ({$code}) berhasil dibuat. Silakan tambahkan rincian komponen.");
                header("Location: index.php?page=master_data/boms&id={$newBomId}");
                exit;
            }
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
            header("Location: index.php?page=master_data/boms" . ($bomId > 0 ? "&edit_bom_id={$bomId}" : ''));
            exit;
        }
    }

    // 2. Toggle BOM Status (Active / Inactive)
    if ($action === 'toggle_bom_status') {
        if (!hasRole(['Administrator', 'admin', 'ppic'])) {
            setFlash('error', 'Akses ditolak.');
            header('Location: index.php?page=master_data/boms');
            exit;
        }

        $bomId = (int)($_POST['bom_id'] ?? 0);
        $stmtB = $db->prepare("SELECT * FROM boms WHERE id = :id");
        $stmtB->execute([':id' => $bomId]);
        $bom = $stmtB->fetch(PDO::FETCH_ASSOC);

        if (!$bom) {
            setFlash('error', 'Data BOM tidak ditemukan.');
            header('Location: index.php?page=master_data/boms');
            exit;
        }

        $newStatus = $bom['is_active'] ? 0 : 1;
        $statusLabel = $newStatus ? 'diaktifkan' : 'dinonaktifkan';

        $stmtUpd = $db->prepare("UPDATE boms SET is_active = :act, updated_by = :uid, updated_at = NOW() WHERE id = :id");
        $stmtUpd->execute([':act' => $newStatus, ':uid' => $user['id'], ':id' => $bomId]);

        logAudit($user['id'], 'toggle_bom_status', 'boms', $bomId, ['is_active' => $bom['is_active']], ['is_active' => $newStatus]);

        setFlash('success', "Formula BOM {$bom['name']} ({$bom['code']}) berhasil {$statusLabel}.");
        header("Location: index.php?page=master_data/boms&id={$bomId}");
        exit;
    }

    // 3. Delete BOM
    if ($action === 'delete_bom') {
        if (!hasRole(['Administrator', 'admin', 'ppic'])) {
            setFlash('error', 'Hanya Administrator dan PPIC yang berwenang menghapus formula BOM.');
            header('Location: index.php?page=master_data/boms');
            exit;
        }

        $bomId = (int)($_POST['bom_id'] ?? 0);
        $stmtB = $db->prepare("SELECT * FROM boms WHERE id = :id");
        $stmtB->execute([':id' => $bomId]);
        $bom = $stmtB->fetch(PDO::FETCH_ASSOC);

        if (!$bom) {
            setFlash('error', 'Data BOM tidak ditemukan.');
            header('Location: index.php?page=master_data/boms');
            exit;
        }

        // Check foreign key dependencies
        $references = checkRecordReferences('boms', $bomId, $db);
        if (!empty($references)) {
            setFlash('error', "Formula BOM {$bom['name']} tidak dapat dihapus karena masih digunakan pada dokumen SPK produksi: " . implode(', ', $references) . ". Silakan gunakan tindakan Nonaktifkan.");
            header("Location: index.php?page=master_data/boms&id={$bomId}");
            exit;
        }

        try {
            $db->beginTransaction();

            // Delete child items first
            $stmtDelItems = $db->prepare("DELETE FROM bom_items WHERE bom_id = :id");
            $stmtDelItems->execute([':id' => $bomId]);

            // Delete bom header
            $stmtDel = $db->prepare("DELETE FROM boms WHERE id = :id");
            $stmtDel->execute([':id' => $bomId]);

            $db->commit();

            logAudit($user['id'], 'delete_bom', 'boms', $bomId, $bom, null);

            setFlash('success', "Formula BOM {$bom['name']} ({$bom['code']}) berhasil dihapus permanen.");
            header('Location: index.php?page=master_data/boms');
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', 'Gagal menghapus formula BOM: ' . $e->getMessage());
            header("Location: index.php?page=master_data/boms&id={$bomId}");
            exit;
        }
    }

    // 4. Add BOM Component Item
    if ($action === 'add_bom_item') {
        if (!hasRole(['Administrator', 'admin', 'ppic'])) {
            setFlash('error', 'Akses ditolak.');
            header('Location: index.php?page=master_data/boms');
            exit;
        }

        $bomId = (int)($_POST['bom_id'] ?? 0);
        $matId = (int)($_POST['item_id'] ?? 0);
        $qtyReq = (float)($_POST['qty_per_unit'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if ($bomId <= 0 || $matId <= 0 || $qtyReq <= 0) {
            setFlash('error', 'Material komponen dan kuantitas pemakaian per unit wajib diisi dengan benar.');
            header("Location: index.php?page=master_data/boms&id={$bomId}");
            exit;
        }

        try {
            $stmtItemUnit = $db->prepare("SELECT unit_id FROM items WHERE id = :id");
            $stmtItemUnit->execute([':id' => $matId]);
            $matUnitId = $stmtItemUnit->fetchColumn() ?: 1;

            $stmtInsItem = $db->prepare("
                INSERT INTO bom_items (bom_id, item_id, qty_per_unit, unit_id, notes)
                VALUES (:b_id, :it_id, :qty, :u_id, :notes)
            ");
            $stmtInsItem->execute([
                ':b_id'  => $bomId,
                ':it_id' => $matId,
                ':qty'   => $qtyReq,
                ':u_id'  => $matUnitId,
                ':notes' => $notes ?: null,
            ]);

            setFlash('success', 'Komponen bahan baku berhasil ditambahkan ke formula BOM.');
            header("Location: index.php?page=master_data/boms&id={$bomId}");
            exit;
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
            header("Location: index.php?page=master_data/boms&id={$bomId}");
            exit;
        }
    }

    // 5. Update BOM Component Item
    if ($action === 'update_bom_item') {
        if (!hasRole(['Administrator', 'admin', 'ppic'])) {
            setFlash('error', 'Akses ditolak.');
            header('Location: index.php?page=master_data/boms');
            exit;
        }

        $bomItemId = (int)($_POST['bom_item_id'] ?? 0);
        $bomId = (int)($_POST['bom_id'] ?? 0);
        $qtyReq = (float)($_POST['qty_per_unit'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if ($bomItemId <= 0 || $qtyReq <= 0) {
            setFlash('error', 'Kuantitas kebutuhan komponen harus lebih dari 0.');
            header("Location: index.php?page=master_data/boms&id={$bomId}");
            exit;
        }

        try {
            $stmtUpdItem = $db->prepare("
                UPDATE bom_items 
                SET qty_per_unit = :qty, notes = :notes 
                WHERE id = :id AND bom_id = :b_id
            ");
            $stmtUpdItem->execute([
                ':qty'   => $qtyReq,
                ':notes' => $notes ?: null,
                ':id'    => $bomItemId,
                ':b_id'  => $bomId,
            ]);

            setFlash('success', 'Komponen bahan baku berhasil diperbarui.');
            header("Location: index.php?page=master_data/boms&id={$bomId}");
            exit;
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
            header("Location: index.php?page=master_data/boms&id={$bomId}");
            exit;
        }
    }

    // 6. Delete BOM Component Item
    if ($action === 'delete_bom_item') {
        if (!hasRole(['Administrator', 'admin', 'ppic'])) {
            setFlash('error', 'Akses ditolak.');
            header('Location: index.php?page=master_data/boms');
            exit;
        }

        $bomItemId = (int)($_POST['bom_item_id'] ?? 0);
        $bomId = (int)($_POST['bom_id'] ?? 0);

        try {
            $stmtDelItem = $db->prepare("DELETE FROM bom_items WHERE id = :id AND bom_id = :b_id");
            $stmtDelItem->execute([':id' => $bomItemId, ':b_id' => $bomId]);

            setFlash('success', 'Komponen bahan berhasil dihapus dari formula BOM.');
            header("Location: index.php?page=master_data/boms&id={$bomId}");
            exit;
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
            header("Location: index.php?page=master_data/boms&id={$bomId}");
            exit;
        }
    }
}

// Fetch all BOMs
$boms = $db->query("
    SELECT b.*, i.name AS product_name, i.code AS product_code, u.name AS unit_name, u.code AS unit_symbol,
           (SELECT COUNT(*) FROM bom_items bi WHERE bi.bom_id = b.id) AS component_count
    FROM boms b
    JOIN items i ON b.product_item_id = i.id
    LEFT JOIN units u ON i.unit_id = u.id
    ORDER BY b.code ASC
")->fetchAll(PDO::FETCH_ASSOC);

if ($selectedBomId <= 0 && !empty($boms)) {
    $selectedBomId = (int)$boms[0]['id'];
}

// Check edit BOM
$editBom = null;
if ($editBomId > 0) {
    $stmtEB = $db->prepare("SELECT * FROM boms WHERE id = :id");
    $stmtEB->execute([':id' => $editBomId]);
    $editBom = $stmtEB->fetch(PDO::FETCH_ASSOC);
    if ($editBom) {
        $selectedBomId = $editBomId;
    }
}

// Fetch Selected BOM Detail & Components
$selectedBom = null;
$bomComponents = [];

if ($selectedBomId > 0) {
    $stmtB = $db->prepare("
        SELECT b.*, i.name AS product_name, i.code AS product_code, u.name AS unit_name, u.code AS unit_symbol,
               (SELECT COUNT(*) FROM production_orders po WHERE po.bom_id = b.id) AS po_count
        FROM boms b
        JOIN items i ON b.product_item_id = i.id
        LEFT JOIN units u ON i.unit_id = u.id
        WHERE b.id = :id
    ");
    $stmtB->execute([':id' => $selectedBomId]);
    $selectedBom = $stmtB->fetch(PDO::FETCH_ASSOC);

    if ($selectedBom) {
        $stmtC = $db->prepare("
            SELECT bi.*,
                   i.name AS item_name, i.code AS item_code, i.cost_price,
                   u.name AS unit_name, u.code AS unit_symbol
            FROM bom_items bi
            JOIN items i ON bi.item_id = i.id
            LEFT JOIN units u ON bi.unit_id = u.id
            WHERE bi.bom_id = :id
            ORDER BY bi.id ASC
        ");
        $stmtC->execute([':id' => $selectedBomId]);
        $bomComponents = $stmtC->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Finished goods & Raw materials dropdowns
$finishedGoods = $db->query("
    SELECT i.id, i.code, i.name 
    FROM items i 
    JOIN item_categories c ON i.category_id = c.id 
    WHERE c.type IN ('barang_jadi', 'lainnya') AND i.is_active = 1 
    ORDER BY i.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$rawMaterials = $db->query("
    SELECT i.id, i.code, i.name, u.code AS unit_symbol, i.cost_price
    FROM items i 
    JOIN item_categories c ON i.category_id = c.id 
    LEFT JOIN units u ON i.unit_id = u.id
    WHERE c.type IN ('bahan_baku', 'packaging', 'lainnya') AND i.is_active = 1 
    ORDER BY i.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Resep &amp; Formula BOM (Bill of Materials)</h1>
        <div class="page-subtitle">Data Master &bull; Standar Komposisi Bahan Baku, Kemasan, dan Biaya Formula Produk PT Mayora Indah Tbk</div>
    </div>
    <div class="page-actions">
        <?php if ($editBom): ?>
            <a href="index.php?page=master_data/boms&id=<?= $selectedBomId ?>" class="btn btn-secondary">&laquo; Batalkan Edit BOM</a>
        <?php endif; ?>
    </div>
</div>

<!-- Add / Edit BOM Header Collapsible -->
<?php if (hasRole(['Administrator', 'admin', 'ppic'])): ?>
<div class="card mb-4">
    <details <?= $editBom ? 'open' : '' ?>>
        <summary style="cursor:pointer; padding:15px 20px; font-weight:600; font-size:15px; color:#0284c7; background:#f8fafc; border-radius:6px; list-style:none; display:flex; justify-content:space-between; align-items:center;">
            <span><?= $editBom ? '✏️ Edit Formula BOM: ' . e($editBom['name']) . ' (' . e($editBom['code']) . ')' : '+ Buat Resep / Formula BOM Baru' ?></span>
            <span class="btn btn-sm btn-primary"><?= $editBom ? 'Tutup Form Edit' : 'Buka Formulir BOM &darr;' ?></span>
        </summary>
        <div style="padding:20px; border-top:1px solid #e2e8f0;">
            <form action="index.php?page=master_data/boms" method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_bom">
                <input type="hidden" name="bom_id" value="<?= $editBom['id'] ?? 0 ?>">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:15px;">
                    <div class="form-group">
                        <label>Kode BOM Formula *</label>
                        <input type="text" name="code" value="<?= e($editBom['code'] ?? '') ?>" placeholder="Misal: BOM-BENG-01" required>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Produk Jadi yang Dihasilkan *</label>
                        <select name="product_item_id" required>
                            <option value="">-- Pilih Produk Jadi --</option>
                            <?php foreach ($finishedGoods as $fg): ?>
                                <option value="<?= $fg['id'] ?>" <?= ((int)($editBom['product_item_id'] ?? 0) === (int)$fg['id']) ? 'selected' : '' ?>>
                                    <?= e($fg['name']) ?> (<?= e($fg['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nama Formula</label>
                        <input type="text" name="name" value="<?= e($editBom['name'] ?? '') ?>" placeholder="Misal: Formula Standar Beng-Beng Reguler">
                    </div>
                    <div class="form-group">
                        <label>Versi Formula (Nomor Revisi)</label>
                        <input type="number" name="version" min="1" max="99" value="<?= (int)($editBom['version'] ?? 1) ?>" required>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Catatan Proses Produksi / Instruksi</label>
                        <textarea name="notes" rows="2" placeholder="Catatan proses mixing, baking, packaging..."><?= e($editBom['notes'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group" style="display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" name="is_active" value="1" id="bom_active" <?= (!isset($editBom['is_active']) || $editBom['is_active']) ? 'checked' : '' ?> style="width:18px; height:18px;">
                        <label for="bom_active" style="margin-bottom:0; font-weight:600; cursor:pointer;">Formula BOM Aktif Digunakan di SPK</label>
                    </div>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:15px;">
                    <?php if ($editBom): ?>
                        <a href="index.php?page=master_data/boms&id=<?= $selectedBomId ?>" class="btn btn-secondary">Batal Edit</a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">
                        <?= $editBom ? '💾 Simpan Perubahan BOM' : '+ Simpan Formula BOM Baru' ?>
                    </button>
                </div>
            </form>
        </div>
    </details>
</div>
<?php endif; ?>

<!-- Main Display: BOM Selector & Component Breakdown -->
<div style="display:grid; grid-template-columns: 1fr 2fr; gap:20px;">
    <!-- Left: List of BOMs -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Daftar Formula BOM (<?= count($boms) ?>)</h2>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-responsive">
                <table class="table table-hover">
                    <tbody>
                        <?php if (empty($boms)): ?>
                            <tr>
                                <td colspan="2" class="text-center" style="padding:20px; color:#64748b;">
                                    Belum ada formula BOM terdaftar.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($boms as $b): ?>
                                <tr style="<?= $selectedBomId === (int)$b['id'] ? 'background:#f0f9ff; border-left:4px solid #0284c7;' : '' ?>">
                                    <td style="padding:12px 15px;">
                                        <strong><a href="index.php?page=master_data/boms&id=<?= $b['id'] ?>"><?= e($b['code']) ?></a></strong>
                                        <div style="font-size:12px; color:#475569; margin-top:2px;">
                                            <?= e($b['product_name']) ?> <span class="badge" style="font-size:10px;">v<?= (int)$b['version'] ?></span>
                                        </div>
                                        <small class="text-muted"><?= (int)$b['component_count'] ?> komponen &bull; <?= $b['is_active'] ? '<span class="text-success">Aktif</span>' : '<span class="text-danger">Nonaktif</span>' ?></small>
                                    </td>
                                    <td class="text-right" style="padding:12px 15px; vertical-align:middle;">
                                        <a href="index.php?page=master_data/boms&id=<?= $b['id'] ?>" class="btn btn-sm <?= $selectedBomId === (int)$b['id'] ? 'btn-primary' : 'btn-secondary' ?>">
                                            Buka &rarr;
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Right: Active BOM Components -->
    <div>
        <?php if ($selectedBom): ?>
            <div class="card mb-4">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div>
                        <h2 class="card-title"><?= e($selectedBom['code']) ?> &bull; <?= e($selectedBom['name']) ?></h2>
                        <div class="text-muted" style="font-size:12px;">
                            Produk Jadi: <strong><?= e($selectedBom['product_name']) ?></strong> (<?= e($selectedBom['product_code']) ?>) &bull;
                            Versi: <strong>v<?= (int)$selectedBom['version'] ?></strong> &bull;
                            Basis Output: <strong>1 <?= e($selectedBom['unit_symbol'] ?: 'Unit') ?></strong>
                        </div>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <?= $selectedBom['is_active'] ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-danger">Non-Aktif</span>' ?>

                        <?php if (hasRole(['Administrator', 'admin', 'ppic'])): ?>
                            <a href="index.php?page=master_data/boms&edit_bom_id=<?= $selectedBom['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit Formula Header">
                                ✏️ Edit
                            </a>

                            <!-- Toggle Status Trigger -->
                            <form action="index.php?page=master_data/boms&id=<?= $selectedBom['id'] ?>" method="POST" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin <?= $selectedBom['is_active'] ? 'menonaktifkan' : 'mengaktifkan' ?> formula BOM ini?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_bom_status">
                                <input type="hidden" name="bom_id" value="<?= $selectedBom['id'] ?>">
                                <button type="submit" class="btn btn-sm <?= $selectedBom['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $selectedBom['is_active'] ? 'Nonaktifkan BOM' : 'Aktifkan BOM' ?>">
                                    <?= $selectedBom['is_active'] ? '⏸️' : '▶️' ?>
                                </button>
                            </form>

                            <!-- Delete Trigger -->
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="openModal('modal-delete-bom-<?= $selectedBom['id'] ?>')" title="Hapus Formula BOM">
                                🗑️
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-body" style="padding:0;">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Material / Komponen Bahan Baku</th>
                                    <th class="text-right">Kebutuhan per Unit</th>
                                    <th>Satuan</th>
                                    <th class="text-right">Estimasi Biaya Satuan</th>
                                    <th class="text-right">Subtotal Biaya Bahan</th>
                                    <th>Catatan</th>
                                    <th class="text-center" style="width:100px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($bomComponents)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center" style="padding:25px; color:#64748b;">
                                            Belum ada komponen material yang ditambahkan ke formula ini. Gunakan formulir di bawah untuk menambahkan bahan baku.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php 
                                    $no = 1; 
                                    $estMaterialCost = 0.0;
                                    foreach ($bomComponents as $bc): 
                                        $cost = (float)$bc['qty_per_unit'] * (float)$bc['cost_price'];
                                        $estMaterialCost += $cost;
                                    ?>
                                        <tr>
                                            <td><?= $no++ ?></td>
                                            <td>
                                                <strong><?= e($bc['item_name']) ?></strong>
                                                <div class="text-muted" style="font-size:11px;"><?= e($bc['item_code']) ?></div>
                                            </td>
                                            <td class="text-right font-bold"><?= formatNumber((float)$bc['qty_per_unit']) ?></td>
                                            <td><?= e($bc['unit_symbol'] ?: 'unit') ?></td>
                                            <td class="text-right text-muted"><?= formatRupiah((float)$bc['cost_price']) ?></td>
                                            <td class="text-right font-medium"><?= formatRupiah($cost) ?></td>
                                            <td><?= e($bc['notes'] ?: '-') ?></td>
                                            <td class="text-center">
                                                <?php if (hasRole(['Administrator', 'admin', 'ppic'])): ?>
                                                    <div class="btn-group">
                                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="openModal('modal-edit-bom-item-<?= $bc['id'] ?>')" title="Edit Kebutuhan">
                                                            ✏️
                                                        </button>
                                                        <form action="index.php?page=master_data/boms&id=<?= $selectedBomId ?>" method="POST" style="display:inline;" onsubmit="return confirm('Hapus komponen <?= e(addslashes($bc['item_name'])) ?> dari formula?');">
                                                            <?= csrfField() ?>
                                                            <input type="hidden" name="action" value="delete_bom_item">
                                                            <input type="hidden" name="bom_item_id" value="<?= $bc['id'] ?>">
                                                            <input type="hidden" name="bom_id" value="<?= $selectedBomId ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus Komponen">
                                                                🗑️
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <?php if (!empty($bomComponents)): ?>
                                <tfoot>
                                    <tr style="background:#f8fafc; font-weight:bold;">
                                        <td colspan="5" class="text-right">Total Estimasi Biaya Bahan Standar per Unit:</td>
                                        <td class="text-right" style="color:#0284c7; font-size:14px;"><?= formatRupiah($estMaterialCost) ?></td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>

                <!-- Add Material Form -->
                <?php if (hasRole(['Administrator', 'admin', 'ppic'])): ?>
                    <div class="card-footer" style="padding:15px 20px; background:#f8fafc; border-top:1px solid #e2e8f0;">
                        <h4 style="margin:0 0 10px 0; font-size:13px; color:#475569;">+ Tambah Komponen Material ke Formula BOM</h4>
                        <form action="index.php?page=master_data/boms&id=<?= $selectedBomId ?>" method="POST" style="display:grid; grid-template-columns: 2fr 1fr 1fr auto; gap:10px; align-items:flex-end;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="add_bom_item">
                            <input type="hidden" name="bom_id" value="<?= $selectedBomId ?>">
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="font-size:11px;">Bahan Baku / Material Kemasan *</label>
                                <select name="item_id" required style="font-size:12px; padding:6px;">
                                    <option value="">-- Pilih Material --</option>
                                    <?php foreach ($rawMaterials as $rm): ?>
                                        <option value="<?= $rm['id'] ?>">
                                            <?= e($rm['name']) ?> (<?= e($rm['code']) ?>) [<?= e($rm['unit_symbol'] ?: 'unit') ?>]
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="font-size:11px;">Kebutuhan per Unit *</label>
                                <input type="number" step="0.0001" min="0.0001" name="qty_per_unit" placeholder="Misal: 0.250" required style="font-size:12px; padding:6px; text-align:right;">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="font-size:11px;">Catatan Proses</label>
                                <input type="text" name="notes" placeholder="Misal: toleransi 1%" style="font-size:12px; padding:6px;">
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary" style="height:35px;">+ Tambah Bahan</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Modal Delete BOM Confirmation -->
            <?php if (hasRole(['Administrator', 'admin', 'ppic'])): ?>
                <?php if (!empty($bomComponents)): ?>
                    <?php foreach ($bomComponents as $bc): ?>
                        <!-- Modal Edit BOM Item -->
                        <div id="modal-edit-bom-item-<?= $bc['id'] ?>" class="modal-backdrop">
                            <div class="modal-dialog modal-sm">
                                <div class="modal-header">
                                    <h3 class="modal-title">Edit Kebutuhan Material</h3>
                                    <button type="button" class="modal-close" data-modal-close>&times;</button>
                                </div>
                                <form action="index.php?page=master_data/boms&id=<?= $selectedBomId ?>" method="POST">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="update_bom_item">
                                    <input type="hidden" name="bom_item_id" value="<?= $bc['id'] ?>">
                                    <input type="hidden" name="bom_id" value="<?= $selectedBomId ?>">
                                    <div class="modal-body">
                                        <div class="form-group">
                                            <label>Komponen Material</label>
                                            <input type="text" value="<?= e($bc['item_name']) ?> (<?= e($bc['item_code']) ?>)" disabled>
                                        </div>
                                        <div class="form-group">
                                            <label>Kebutuhan per Unit (<?= e($bc['unit_symbol']) ?>) *</label>
                                            <input type="number" step="0.0001" min="0.0001" name="qty_per_unit" value="<?= (float)$bc['qty_per_unit'] ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Catatan Teknis</label>
                                            <input type="text" name="notes" value="<?= e($bc['notes'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Batal</button>
                                        <button type="submit" class="btn btn-primary btn-sm">Simpan Perubahan</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php 
                $refs = checkRecordReferences('boms', $selectedBom['id'], $db); 
            ?>
            <div id="modal-delete-bom-<?= $selectedBom['id'] ?>" class="modal-backdrop">
                <div class="modal-dialog modal-sm">
                    <div class="modal-header" style="background:#fee2e2;">
                        <h3 class="modal-title" style="color:#b91c1c;">⚠️ Konfirmasi Penghapusan Formula</h3>
                        <button type="button" class="modal-close" data-modal-close>&times;</button>
                    </div>
                    <div class="modal-body">
                        <p>Anda akan memproses formula BOM:</p>
                        <div style="background:#f8fafc; padding:10px; border-radius:6px; margin-bottom:12px; font-size:13px; border:1px solid #e2e8f0;">
                            <strong><?= e($selectedBom['name']) ?></strong><br>
                            <span class="text-muted">Kode: <?= e($selectedBom['code']) ?> (v<?= (int)$selectedBom['version'] ?>)</span><br>
                            <span class="text-muted">Produk: <?= e($selectedBom['product_name']) ?></span>
                        </div>

                        <?php if (!empty($refs)): ?>
                            <div class="alert alert-warning" style="font-size:12px; padding:10px;">
                                <strong>Perhatian!</strong> Formula BOM ini tidak dapat dihapus permanen karena masih direferensikan oleh dokumen produksi:
                                <ul style="margin:6px 0 0 16px; padding:0;">
                                    <?php foreach ($refs as $r): ?>
                                        <li><?= e($r) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <p style="margin-top:6px; margin-bottom:0;">Disarankan untuk <strong>menonaktifkan</strong> formula BOM ini agar tidak dapat dipilih lagi pada perintah kerja (SPK) baru.</p>
                            </div>
                        <?php else: ?>
                            <p style="color:#b91c1c; font-size:12.5px;">Formula BOM ini belum pernah digunakan pada proses SPK manufaktur dan aman untuk dihapus permanen beserta seluruh daftar komponen bahannya.</p>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Batal</button>
                        <?php if (!empty($refs)): ?>
                            <form action="index.php?page=master_data/boms&id=<?= $selectedBom['id'] ?>" method="POST" style="display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_bom_status">
                                <input type="hidden" name="bom_id" value="<?= $selectedBom['id'] ?>">
                                <button type="submit" class="btn btn-warning btn-sm">⏸️ Nonaktifkan Saja</button>
                            </form>
                        <?php else: ?>
                            <form action="index.php?page=master_data/boms" method="POST" style="display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_bom">
                                <input type="hidden" name="bom_id" value="<?= $selectedBom['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">🗑️ Ya, Hapus Permanen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="card" style="padding:40px; text-align:center; color:#64748b;">
                Pilih salah satu formula BOM di sebelah kiri atau buat formula BOM baru.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
