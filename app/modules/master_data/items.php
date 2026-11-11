<?php
/**
 * Data Master: Barang & Bahan Baku (Master Items)
 * PT Mayora Indah Tbk
 */

$pageTitle = 'Master Barang & Bahan Baku';
$breadcrumbCategory = 'Data Master';

$db = getDbConnection();
$user = currentUser();

$search = trim($_GET['q'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$catFilter = (int)($_GET['cat_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $action = $_POST['action'] ?? '';

    // 1. Save Item (Create or Update)
    if ($action === 'save_item') {
        if (!hasRole(['Administrator', 'admin', 'gudang', 'purchasing', 'ppic'])) {
            setFlash('error', 'Anda tidak memiliki hak akses untuk mengubah data barang.');
            header('Location: index.php?page=master_data/items');
            exit;
        }

        $itemId = (int)($_POST['item_id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $unitId = (int)($_POST['unit_id'] ?? 0);
        $minStock = max(0, (float)($_POST['min_stock'] ?? 0));
        $costPrice = max(0, (float)($_POST['cost_price'] ?? 0));
        $sellingPrice = max(0, (float)($_POST['selling_price'] ?? 0));
        $description = trim($_POST['description'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($code) || empty($name) || $categoryId <= 0 || $unitId <= 0) {
            setFlash('error', 'Kode barang, nama barang, kategori, dan satuan wajib diisi.');
            header('Location: index.php?page=master_data/items' . ($itemId > 0 ? "&edit_id={$itemId}" : ''));
            exit;
        }

        try {
            if ($itemId > 0) {
                // Check duplicate code
                $stmtDup = $db->prepare("SELECT id FROM items WHERE code = :code AND id != :id");
                $stmtDup->execute([':code' => $code, ':id' => $itemId]);
                if ($stmtDup->fetchColumn()) {
                    throw new Exception("Kode barang '{$code}' sudah digunakan oleh item lain.");
                }

                // Old data
                $stmtOld = $db->prepare("SELECT * FROM items WHERE id = :id");
                $stmtOld->execute([':id' => $itemId]);
                $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

                if (!$oldData) {
                    throw new Exception("Data barang tidak ditemukan.");
                }

                $stmtUpd = $db->prepare("
                    UPDATE items 
                    SET code = :code, name = :name, category_id = :cat_id, unit_id = :u_id,
                        min_stock = :min_stk, cost_price = :cost_p, selling_price = :sell_p,
                        description = :desc, is_active = :active, updated_by = :uid, updated_at = NOW()
                    WHERE id = :id
                ");
                $stmtUpd->execute([
                    ':code'    => $code,
                    ':name'    => $name,
                    ':cat_id'  => $categoryId,
                    ':u_id'    => $unitId,
                    ':min_stk' => $minStock,
                    ':cost_p'  => $costPrice,
                    ':sell_p'  => $sellingPrice,
                    ':desc'    => $description ?: null,
                    ':active'  => $isActive,
                    ':uid'     => $user['id'],
                    ':id'      => $itemId,
                ]);

                logAudit($user['id'], 'update_item', 'items', $itemId, $oldData, [
                    'code' => $code, 'name' => $name, 'category_id' => $categoryId,
                    'unit_id' => $unitId, 'min_stock' => $minStock, 'cost_price' => $costPrice,
                    'selling_price' => $sellingPrice, 'is_active' => $isActive
                ]);

                setFlash('success', "Data barang {$name} ({$code}) berhasil diperbarui.");
            } else {
                // Check duplicate code
                $stmtDup = $db->prepare("SELECT id FROM items WHERE code = :code");
                $stmtDup->execute([':code' => $code]);
                if ($stmtDup->fetchColumn()) {
                    throw new Exception("Kode barang '{$code}' sudah terdaftar.");
                }

                $stmtIns = $db->prepare("
                    INSERT INTO items (code, name, category_id, unit_id, min_stock, cost_price, selling_price, description, is_active, created_by, updated_by, created_at, updated_at)
                    VALUES (:code, :name, :cat_id, :u_id, :min_stk, :cost_p, :sell_p, :desc, :active, :uid, :uid, NOW(), NOW())
                ");
                $stmtIns->execute([
                    ':code'    => $code,
                    ':name'    => $name,
                    ':cat_id'  => $categoryId,
                    ':u_id'    => $unitId,
                    ':min_stk' => $minStock,
                    ':cost_p'  => $costPrice,
                    ':sell_p'  => $sellingPrice,
                    ':desc'    => $description ?: null,
                    ':active'  => $isActive,
                    ':uid'     => $user['id'],
                ]);
                $newId = $db->lastInsertId();

                logAudit($user['id'], 'create_item', 'items', $newId, null, [
                    'code' => $code, 'name' => $name, 'category_id' => $categoryId, 'is_active' => $isActive
                ]);

                setFlash('success', "Barang baru {$name} ({$code}) berhasil ditambahkan.");
            }

            header('Location: index.php?page=master_data/items');
            exit;
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
            header('Location: index.php?page=master_data/items' . ($itemId > 0 ? "&edit_id={$itemId}" : ''));
            exit;
        }
    }

    // 2. Toggle Status
    if ($action === 'toggle_status') {
        if (!hasRole(['Administrator', 'admin', 'gudang', 'purchasing'])) {
            setFlash('error', 'Akses ditolak.');
            header('Location: index.php?page=master_data/items');
            exit;
        }

        $itemId = (int)($_POST['item_id'] ?? 0);
        $stmtI = $db->prepare("SELECT * FROM items WHERE id = :id");
        $stmtI->execute([':id' => $itemId]);
        $item = $stmtI->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            setFlash('error', 'Data barang tidak ditemukan.');
            header('Location: index.php?page=master_data/items');
            exit;
        }

        $newStatus = $item['is_active'] ? 0 : 1;
        $statusLabel = $newStatus ? 'diaktifkan' : 'dinonaktifkan';

        $stmtUpd = $db->prepare("UPDATE items SET is_active = :act, updated_by = :uid, updated_at = NOW() WHERE id = :id");
        $stmtUpd->execute([':act' => $newStatus, ':uid' => $user['id'], ':id' => $itemId]);

        logAudit($user['id'], 'toggle_item_status', 'items', $itemId, ['is_active' => $item['is_active']], ['is_active' => $newStatus]);

        setFlash('success', "Barang {$item['name']} ({$item['code']}) berhasil {$statusLabel}.");
        header('Location: index.php?page=master_data/items');
        exit;
    }

    // 3. Delete Item
    if ($action === 'delete_item') {
        if (!hasRole(['Administrator', 'admin', 'gudang'])) {
            setFlash('error', 'Hanya Administrator dan Bagian Gudang yang berwenang menghapus data barang.');
            header('Location: index.php?page=master_data/items');
            exit;
        }

        $itemId = (int)($_POST['item_id'] ?? 0);
        $stmtI = $db->prepare("SELECT * FROM items WHERE id = :id");
        $stmtI->execute([':id' => $itemId]);
        $item = $stmtI->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            setFlash('error', 'Data barang tidak ditemukan.');
            header('Location: index.php?page=master_data/items');
            exit;
        }

        // Check stock balances
        $stmtStk = $db->prepare("SELECT COALESCE(SUM(qty_available), 0) FROM stock_balances WHERE item_id = :id");
        $stmtStk->execute([':id' => $itemId]);
        $currStock = (float)$stmtStk->fetchColumn();

        if ($currStock > 0) {
            setFlash('error', "Barang {$item['name']} tidak dapat dihapus karena masih memiliki saldo fisik di gudang sebanyak " . formatNumber($currStock) . ". Silakan lakukan penyesuaian stok atau nonaktifkan status barang.");
            header('Location: index.php?page=master_data/items');
            exit;
        }

        // Check foreign key dependencies
        $references = checkRecordReferences('items', $itemId, $db);
        if (!empty($references)) {
            setFlash('error', "Barang {$item['name']} tidak dapat dihapus karena memiliki keterkaitan transaksi operasional: " . implode(', ', $references) . ". Silakan nonaktifkan status barang.");
            header('Location: index.php?page=master_data/items');
            exit;
        }

        try {
            $stmtDel = $db->prepare("DELETE FROM items WHERE id = :id");
            $stmtDel->execute([':id' => $itemId]);

            logAudit($user['id'], 'delete_item', 'items', $itemId, $item, null);

            setFlash('success', "Barang {$item['name']} ({$item['code']}) berhasil dihapus permanen.");
            header('Location: index.php?page=master_data/items');
            exit;
        } catch (Exception $e) {
            setFlash('error', 'Gagal menghapus barang: ' . $e->getMessage());
            header('Location: index.php?page=master_data/items');
            exit;
        }
    }
}

// Fetch categories and units
$categories = $db->query("SELECT id, name, type FROM item_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$units = $db->query("SELECT id, name, code AS symbol FROM units ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Search & filter
$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(i.code LIKE :q OR i.name LIKE :q OR i.description LIKE :q)';
    $params[':q'] = "%{$search}%";
}

if ($typeFilter !== '') {
    $where[] = 'c.type = :type';
    $params[':type'] = $typeFilter;
}

if ($catFilter > 0) {
    $where[] = 'i.category_id = :cat_id';
    $params[':cat_id'] = $catFilter;
}

if ($statusFilter === 'active') {
    $where[] = 'i.is_active = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'i.is_active = 0';
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM items i JOIN item_categories c ON i.category_id = c.id WHERE {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

// List query with aggregated stock
$stmt = $db->prepare("
    SELECT i.*,
           c.name AS category_name, c.type AS item_type,
           u.name AS unit_name, u.code AS unit_symbol,
           COALESCE(SUM(sb.qty_available), 0) AS total_available_stock
    FROM items i
    JOIN item_categories c ON i.category_id = c.id
    LEFT JOIN units u ON i.unit_id = u.id
    LEFT JOIN stock_balances sb ON i.id = sb.item_id
    WHERE {$whereSql}
    GROUP BY i.id
    ORDER BY c.type ASC, i.name ASC
    LIMIT :offset, :per_page
");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Edit item check
$editItem = null;
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    $stmtE = $db->prepare("SELECT * FROM items WHERE id = :id");
    $stmtE->execute([':id' => $editId]);
    $editItem = $stmtE->fetch(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Master Barang &amp; Bahan Baku</h1>
        <div class="page-subtitle">Data Master &bull; Katalog Material, Bahan Baku, Kemasan, dan Produk Jadi PT Mayora Indah Tbk</div>
    </div>
    <div class="page-actions">
        <?php if ($editItem): ?>
            <a href="index.php?page=master_data/items" class="btn btn-secondary">&laquo; Batalkan Edit</a>
        <?php endif; ?>
    </div>
</div>

<!-- Add / Edit Collapsible Form -->
<?php if (hasRole(['Administrator', 'admin', 'gudang', 'purchasing', 'ppic'])): ?>
<div class="card mb-4">
    <details <?= $editItem ? 'open' : '' ?>>
        <summary style="cursor:pointer; padding:15px 20px; font-weight:600; font-size:15px; color:#0284c7; background:#f8fafc; border-radius:6px; list-style:none; display:flex; justify-content:space-between; align-items:center;">
            <span><?= $editItem ? '✏️ Edit Barang: ' . e($editItem['name']) . ' (' . e($editItem['code']) . ')' : '+ Tambah Barang / Material Baru' ?></span>
            <span class="btn btn-sm btn-primary"><?= $editItem ? 'Tutup Form Edit' : 'Buka Formulir Input &darr;' ?></span>
        </summary>
        <div style="padding:20px; border-top:1px solid #e2e8f0;">
            <form action="index.php?page=master_data/items" method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_item">
                <input type="hidden" name="item_id" value="<?= $editItem['id'] ?? 0 ?>">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:15px;">
                    <div class="form-group">
                        <label>Kode Barang / SKU *</label>
                        <input type="text" name="code" value="<?= e($editItem['code'] ?? '') ?>" placeholder="Misal: BB-001 / BJ-001" required>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Nama Barang / Deskripsi *</label>
                        <input type="text" name="name" value="<?= e($editItem['name'] ?? '') ?>" placeholder="Misal: Terigu Segitiga Biru 25kg" required>
                    </div>
                    <div class="form-group">
                        <label>Kategori Barang *</label>
                        <select name="category_id" required>
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= ((int)($editItem['category_id'] ?? 0) === (int)$cat['id']) ? 'selected' : '' ?>>
                                    <?= e($cat['name']) ?> (<?= e($cat['type']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Satuan Satuan (Unit) *</label>
                        <select name="unit_id" required>
                            <option value="">-- Pilih Satuan --</option>
                            <?php foreach ($units as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= ((int)($editItem['unit_id'] ?? 0) === (int)$u['id']) ? 'selected' : '' ?>>
                                    <?= e($u['name']) ?> (<?= e($u['symbol']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Stok Minimum (Safety Stock)</label>
                        <input type="number" step="0.01" min="0" name="min_stock" value="<?= (float)($editItem['min_stock'] ?? 0) ?>">
                    </div>
                    <div class="form-group">
                        <label>Estimasi Harga Beli / HPP (Rp)</label>
                        <input type="number" step="0.01" min="0" name="cost_price" value="<?= (float)($editItem['cost_price'] ?? 0) ?>">
                    </div>
                    <div class="form-group">
                        <label>Harga Jual Standar (Rp)</label>
                        <input type="number" step="0.01" min="0" name="selling_price" value="<?= (float)($editItem['selling_price'] ?? 0) ?>">
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Keterangan / Spesifikasi Khusus</label>
                        <textarea name="description" rows="2" placeholder="Catatan spesifikasi material, suhu penyimpanan, dsb"><?= e($editItem['description'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group" style="display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" name="is_active" value="1" id="item_active" <?= (!isset($editItem['is_active']) || $editItem['is_active']) ? 'checked' : '' ?> style="width:18px; height:18px;">
                        <label for="item_active" style="margin-bottom:0; font-weight:600; cursor:pointer;">Status Barang Aktif Digunakan</label>
                    </div>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:15px;">
                    <?php if ($editItem): ?>
                        <a href="index.php?page=master_data/items" class="btn btn-secondary">Batal Edit</a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">
                        <?= $editItem ? '💾 Simpan Perubahan Barang' : '+ Simpan Barang Baru' ?>
                    </button>
                </div>
            </form>
        </div>
    </details>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="master_data/items">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari kode, nama, spesifikasi..." style="min-width:250px;">
    </div>
    <div class="filter-group">
        <select name="type">
            <option value="">-- Semua Jenis Tipe --</option>
            <option value="bahan_baku" <?= $typeFilter === 'bahan_baku' ? 'selected' : '' ?>>Bahan Baku</option>
            <option value="barang_jadi" <?= $typeFilter === 'barang_jadi' ? 'selected' : '' ?>>Barang Jadi</option>
            <option value="packaging" <?= $typeFilter === 'packaging' ? 'selected' : '' ?>>Bahan Kemasan</option>
            <option value="lainnya" <?= $typeFilter === 'lainnya' ? 'selected' : '' ?>>Lainnya</option>
        </select>
    </div>
    <div class="filter-group">
        <select name="cat_id">
            <option value="">-- Semua Kategori --</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $catFilter === (int)$cat['id'] ? 'selected' : '' ?>>
                    <?= e($cat['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <select name="status">
            <option value="">-- Semua Status --</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Hanya Aktif</option>
            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Hanya Non-Aktif</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $typeFilter !== '' || $catFilter > 0 || $statusFilter !== ''): ?>
        <a href="index.php?page=master_data/items" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<!-- Table of Items -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Barang / Spesifikasi</th>
                    <th>Tipe</th>
                    <th>Kategori</th>
                    <th>Satuan</th>
                    <th class="text-right">Stok Fisik Tersedia</th>
                    <th class="text-right">Safety Stock</th>
                    <th class="text-right">HPP Standar (Rp)</th>
                    <th class="text-right">Harga Jual (Rp)</th>
                    <th class="text-center">Status</th>
                    <th class="text-center" style="width:180px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="11" class="text-center" style="padding:30px; color:#64748b;">
                            Tidak ada data barang yang sesuai kriteria pencarian.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $it): ?>
                        <?php 
                            $isLowStock = ((float)$it['total_available_stock'] <= (float)$it['min_stock']) && ((float)$it['min_stock'] > 0);
                        ?>
                        <tr>
                            <td><strong><?= e($it['code']) ?></strong></td>
                            <td>
                                <strong><?= e($it['name']) ?></strong>
                                <?php if (!empty($it['description'])): ?>
                                    <div class="text-muted" style="font-size:11px;"><?= e(truncate($it['description'], 45)) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($it['item_type'] === 'barang_jadi'): ?>
                                    <span class="badge" style="background:#dcfce7; color:#15803d;">Barang Jadi</span>
                                <?php elseif ($it['item_type'] === 'bahan_baku'): ?>
                                    <span class="badge" style="background:#e0f2fe; color:#0369a1;">Bahan Baku</span>
                                <?php elseif ($it['item_type'] === 'packaging'): ?>
                                    <span class="badge" style="background:#fef3c7; color:#b45309;">Kemasan</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#f1f5f9; color:#475569;"><?= e($it['item_type']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($it['category_name']) ?></td>
                            <td><?= e($it['unit_name'] ?: '-') ?> (<?= e($it['unit_symbol'] ?: '') ?>)</td>
                            <td class="text-right font-bold" style="color: <?= $isLowStock ? '#dc2626' : '#16a34a' ?>;">
                                <?= formatNumber((float)$it['total_available_stock']) ?>
                                <?php if ($isLowStock): ?>
                                    <span title="Stok berada di bawah safety stock!" style="cursor:help;">⚠️</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right text-muted"><?= formatNumber((float)$it['min_stock']) ?></td>
                            <td class="text-right font-medium">
                                <?= (float)$it['cost_price'] > 0 ? formatRupiah((float)$it['cost_price']) : '-' ?>
                            </td>
                            <td class="text-right font-medium">
                                <?= (float)$it['selling_price'] > 0 ? formatRupiah((float)$it['selling_price']) : '-' ?>
                            </td>
                            <td class="text-center"><?= $it['is_active'] ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-danger">Non-Aktif</span>' ?></td>
                            <td class="text-center">
                                <div class="btn-group">
                                    <!-- Detail Modal Trigger -->
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="openModal('modal-detail-item-<?= $it['id'] ?>')" title="Lihat Rincian Barang">
                                        👁️ Detail
                                    </button>

                                    <!-- Edit Trigger -->
                                    <?php if (hasRole(['Administrator', 'admin', 'gudang', 'purchasing', 'ppic'])): ?>
                                        <a href="index.php?page=master_data/items&edit_id=<?= $it['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit Data Barang">
                                            ✏️ Edit
                                        </a>

                                        <!-- Toggle Status Trigger -->
                                        <form action="index.php?page=master_data/items" method="POST" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin <?= $it['is_active'] ? 'menonaktifkan' : 'mengaktifkan' ?> barang <?= e(addslashes($it['name'])) ?>?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $it['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $it['is_active'] ? 'Nonaktifkan Barang' : 'Aktifkan Barang' ?>">
                                                <?= $it['is_active'] ? '⏸️' : '▶️' ?>
                                            </button>
                                        </form>

                                        <!-- Delete Trigger -->
                                        <?php if (hasRole(['Administrator', 'admin', 'gudang'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="openModal('modal-delete-item-<?= $it['id'] ?>')" title="Hapus Barang">
                                                🗑️
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($pagination['total_pages'] > 1): ?>
        <div class="card-footer">
            <?= renderPagination($pagination, 'index.php?page=master_data/items&q=' . urlencode($search) . '&type=' . urlencode($typeFilter) . '&cat_id=' . $catFilter . '&status=' . urlencode($statusFilter)) ?>
        </div>
    <?php endif; ?>
</div>

<!-- ========================================================================= -->
<!-- MODALS PLACED OUTSIDE TABLE (Valid HTML5 DOM)                             -->
<!-- ========================================================================= -->
<?php if (!empty($items)): ?>
    <?php foreach ($items as $it): ?>
        <?php
            $isLowStock = (float)$it['total_available_stock'] <= (float)$it['min_stock'];
            // Fetch stock balances per warehouse for this item
            $stmtWhStk = $db->prepare("
                SELECT w.code AS wh_code, w.name AS wh_name, sb.qty_available, sb.qty_reserved
                FROM stock_balances sb
                JOIN warehouses w ON sb.warehouse_id = w.id
                WHERE sb.item_id = :id AND (sb.qty_available > 0 OR sb.qty_reserved > 0)
            ");
            $stmtWhStk->execute([':id' => $it['id']]);
            $whBalances = $stmtWhStk->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div id="modal-detail-item-<?= $it['id'] ?>" class="modal-backdrop">
            <div class="modal-dialog">
                <div class="modal-header">
                    <h3 class="modal-title">👁️ Detail Barang: <?= e($it['name']) ?></h3>
                    <button type="button" class="modal-close" data-modal-close>&times;</button>
                </div>
                <div class="modal-body">
                    <table class="detail-table">
                        <tr>
                            <th>Kode SKU</th>
                            <td><strong><?= e($it['code']) ?></strong></td>
                        </tr>
                        <tr>
                            <th>Nama Barang</th>
                            <td><?= e($it['name']) ?></td>
                        </tr>
                        <tr>
                            <th>Kategori & Tipe</th>
                            <td><?= e($it['category_name']) ?> (<?= e($it['item_type']) ?>)</td>
                        </tr>
                        <tr>
                            <th>Satuan Standar</th>
                            <td><?= e($it['unit_name']) ?> (<?= e($it['unit_symbol']) ?>)</td>
                        </tr>
                        <tr>
                            <th>Total Stok Fisik</th>
                            <td><strong style="color: <?= $isLowStock ? '#dc2626' : '#16a34a' ?>; font-size:15px;"><?= formatNumber((float)$it['total_available_stock']) ?> <?= e($it['unit_symbol']) ?></strong></td>
                        </tr>
                        <tr>
                            <th>Batas Stok Minimum</th>
                            <td><?= formatNumber((float)$it['min_stock']) ?> <?= e($it['unit_symbol']) ?></td>
                        </tr>
                        <tr>
                            <th>Harga Pokok Pembelian (HPP)</th>
                            <td><?= formatRupiah((float)$it['cost_price']) ?></td>
                        </tr>
                        <tr>
                            <th>Harga Jual Standar</th>
                            <td><?= formatRupiah((float)$it['selling_price']) ?></td>
                        </tr>
                        <tr>
                            <th>Spesifikasi / Catatan</th>
                            <td><?= nl2br(e($it['description'] ?: '-')) ?></td>
                        </tr>
                        <tr>
                            <th>Status Barang</th>
                            <td><?= $it['is_active'] ? '<span class="bd ok">Aktif</span>' : '<span class="bd no">Non-Aktif</span>' ?></td>
                        </tr>
                        <tr>
                            <th>Waktu Dibuat</th>
                            <td><?= formatDateTime($it['created_at']) ?></td>
                        </tr>
                        <tr>
                            <th>Sebaran Stok per Gudang</th>
                            <td>
                                <?php if (empty($whBalances)): ?>
                                    <span class="text-muted">Tidak ada saldo stok fisik di gudang saat ini.</span>
                                <?php else: ?>
                                    <table style="width:100%; font-size:12px; margin-top:5px; border-collapse:collapse;">
                                        <thead>
                                            <tr style="background:#f1f5f9; text-align:left;">
                                                <th style="padding:4px 8px;">Gudang</th>
                                                <th style="padding:4px 8px; text-align:right;">Tersedia</th>
                                                <th style="padding:4px 8px; text-align:right;">Dialokasikan</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($whBalances as $wb): ?>
                                                <tr style="border-bottom:1px solid #e2e8f0;">
                                                    <td style="padding:4px 8px;"><?= e($wb['wh_name']) ?> (<?= e($wb['wh_code']) ?>)</td>
                                                    <td style="padding:4px 8px; text-align:right; font-weight:bold;"><?= formatNumber((float)$wb['qty_available']) ?></td>
                                                    <td style="padding:4px 8px; text-align:right; color:#ea580c;"><?= formatNumber((float)$wb['qty_reserved']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-modal-close>Tutup</button>
                    <?php if (hasRole(['Administrator', 'admin', 'gudang', 'purchasing', 'ppic'])): ?>
                        <a href="index.php?page=master_data/items&edit_id=<?= $it['id'] ?>" class="btn btn-primary">✏️ Edit Barang Ini</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Modal Confirm Delete Item -->
        <?php if (hasRole(['Administrator', 'admin', 'gudang'])): ?>
        <?php 
            $refs = checkRecordReferences('items', $it['id'], $db); 
            $hasStock = (float)$it['total_available_stock'] > 0;
        ?>
        <div id="modal-delete-item-<?= $it['id'] ?>" class="modal-backdrop">
            <div class="modal-dialog modal-sm">
                <div class="modal-header" style="background:#fee2e2;">
                    <h3 class="modal-title" style="color:#b91c1c;">⚠️ Konfirmasi Penghapusan</h3>
                    <button type="button" class="modal-close" data-modal-close>&times;</button>
                </div>
                <div class="modal-body">
                    <p>Anda akan memproses data barang / material:</p>
                    <div style="background:#f8fafc; padding:10px; border-radius:6px; margin-bottom:12px; font-size:13px; border:1px solid #e2e8f0;">
                        <strong><?= e($it['name']) ?></strong><br>
                        <span class="text-muted">Kode: <?= e($it['code']) ?></span><br>
                        <span class="text-muted">Stok Fisik: <?= formatNumber((float)$it['total_available_stock']) ?> <?= e($it['unit_symbol']) ?></span>
                    </div>

                    <?php if ($hasStock || !empty($refs)): ?>
                        <div class="alert alert-warning" style="font-size:12px; padding:10px;">
                            <strong>Perhatian!</strong> Barang ini tidak dapat dihapus permanen karena:
                            <ul style="margin:6px 0 0 16px; padding:0;">
                                <?php if ($hasStock): ?>
                                    <li>Masih terdapat saldo stok fisik di gudang sebanyak <?= formatNumber((float)$it['total_available_stock']) ?> <?= e($it['unit_symbol']) ?></li>
                                <?php endif; ?>
                                <?php foreach ($refs as $r): ?>
                                    <li><?= e($r) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <p style="margin-top:6px; margin-bottom:0;">Sesuai kebijakan integritas stok &amp; jurnal buku besar, barang yang sudah tercatat transaksi <strong>tidak dapat dihapus permanen</strong>. Anda disarankan untuk <strong>menonaktifkannya</strong>.</p>
                        </div>
                    <?php else: ?>
                        <p style="color:#b91c1c; font-size:12.5px;">Barang ini belum pernah memiliki pergerakan stok atau transaksi terkait dan aman untuk dihapus permanen.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Batal</button>
                    <?php if ($hasStock || !empty($refs)): ?>
                        <form action="index.php?page=master_data/items" method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                            <button type="submit" class="btn btn-warning btn-sm">⏸️ Nonaktifkan Saja</button>
                        </form>
                    <?php else: ?>
                        <form action="index.php?page=master_data/items" method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_item">
                            <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">🗑️ Ya, Hapus Permanen</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
