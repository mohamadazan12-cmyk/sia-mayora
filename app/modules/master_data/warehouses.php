<?php
/**
 * Data Master: Gudang & Lokasi Penyimpanan (Master Warehouses)
 * PT Mayora Indah Tbk
 */

$pageTitle = 'Master Gudang & Lokasi Penyimpanan';
$breadcrumbCategory = 'Data Master';

$db = getDbConnection();
$user = currentUser();

$search = trim($_GET['q'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $action = $_POST['action'] ?? '';

    // 1. Save (Create or Update) Warehouse
    if ($action === 'save_warehouse') {
        if (!hasRole(['Administrator', 'admin', 'gudang'])) {
            setFlash('error', 'Akses ditolak.');
            header('Location: index.php?page=master_data/warehouses');
            exit;
        }

        $whId = (int)($_POST['warehouse_id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $type = trim($_POST['type'] ?? 'umum');
        $address = trim($_POST['address'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (!in_array($type, ['bahan_baku', 'barang_jadi', 'wip', 'umum'])) {
            $type = 'umum';
        }

        if (empty($code) || empty($name)) {
            setFlash('error', 'Kode dan nama gudang wajib diisi.');
            header('Location: index.php?page=master_data/warehouses' . ($whId > 0 ? "&edit_id={$whId}" : ''));
            exit;
        }

        try {
            if ($whId > 0) {
                // Check duplicate code
                $stmtDup = $db->prepare("SELECT id FROM warehouses WHERE code = :code AND id != :id");
                $stmtDup->execute([':code' => $code, ':id' => $whId]);
                if ($stmtDup->fetchColumn()) {
                    throw new Exception("Kode gudang '{$code}' sudah digunakan oleh gudang lain.");
                }

                // Old data
                $stmtOld = $db->prepare("SELECT * FROM warehouses WHERE id = :id");
                $stmtOld->execute([':id' => $whId]);
                $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

                if (!$oldData) {
                    throw new Exception("Data gudang tidak ditemukan.");
                }

                $stmtUpd = $db->prepare("
                    UPDATE warehouses 
                    SET code = :code, name = :name, type = :type, address = :addr, is_active = :active, updated_at = NOW()
                    WHERE id = :id
                ");
                $stmtUpd->execute([
                    ':code'   => $code,
                    ':name'   => $name,
                    ':type'   => $type,
                    ':addr'   => $address ?: null,
                    ':active' => $isActive,
                    ':id'     => $whId,
                ]);

                logAudit($user['id'], 'update_warehouse', 'warehouses', $whId, $oldData, [
                    'code' => $code, 'name' => $name, 'type' => $type, 'address' => $address, 'is_active' => $isActive
                ]);

                setFlash('success', "Data gudang {$name} ({$code}) berhasil diperbarui.");
            } else {
                // Check duplicate code
                $stmtDup = $db->prepare("SELECT id FROM warehouses WHERE code = :code");
                $stmtDup->execute([':code' => $code]);
                if ($stmtDup->fetchColumn()) {
                    throw new Exception("Kode gudang '{$code}' sudah digunakan.");
                }

                $stmtIns = $db->prepare("
                    INSERT INTO warehouses (code, name, type, address, is_active, created_at, updated_at)
                    VALUES (:code, :name, :type, :addr, :active, NOW(), NOW())
                ");
                $stmtIns->execute([
                    ':code'   => $code,
                    ':name'   => $name,
                    ':type'   => $type,
                    ':addr'   => $address ?: null,
                    ':active' => $isActive,
                ]);
                $newId = $db->lastInsertId();

                logAudit($user['id'], 'create_warehouse', 'warehouses', $newId, null, [
                    'code' => $code, 'name' => $name, 'type' => $type, 'is_active' => $isActive
                ]);

                setFlash('success', "Gudang baru {$name} ({$code}) berhasil ditambahkan.");
            }

            header('Location: index.php?page=master_data/warehouses');
            exit;
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
            header('Location: index.php?page=master_data/warehouses' . ($whId > 0 ? "&edit_id={$whId}" : ''));
            exit;
        }
    }

    // 2. Toggle Status (Active / Deactivate)
    if ($action === 'toggle_status') {
        if (!hasRole(['Administrator', 'admin', 'gudang'])) {
            setFlash('error', 'Akses ditolak.');
            header('Location: index.php?page=master_data/warehouses');
            exit;
        }

        $whId = (int)($_POST['warehouse_id'] ?? 0);
        $stmtW = $db->prepare("SELECT * FROM warehouses WHERE id = :id");
        $stmtW->execute([':id' => $whId]);
        $warehouse = $stmtW->fetch(PDO::FETCH_ASSOC);

        if (!$warehouse) {
            setFlash('error', 'Data gudang tidak ditemukan.');
            header('Location: index.php?page=master_data/warehouses');
            exit;
        }

        $newStatus = $warehouse['is_active'] ? 0 : 1;
        $statusLabel = $newStatus ? 'diaktifkan' : 'dinonaktifkan';

        $stmtUpd = $db->prepare("UPDATE warehouses SET is_active = :act, updated_at = NOW() WHERE id = :id");
        $stmtUpd->execute([':act' => $newStatus, ':id' => $whId]);

        logAudit($user['id'], 'toggle_warehouse_status', 'warehouses', $whId, ['is_active' => $warehouse['is_active']], ['is_active' => $newStatus]);

        setFlash('success', "Gudang {$warehouse['name']} ({$warehouse['code']}) berhasil {$statusLabel}.");
        header('Location: index.php?page=master_data/warehouses');
        exit;
    }

    // 3. Delete Warehouse
    if ($action === 'delete_warehouse') {
        if (!hasRole(['Administrator', 'admin', 'gudang'])) {
            setFlash('error', 'Hanya Administrator dan Kepala Gudang yang berwenang menghapus data gudang.');
            header('Location: index.php?page=master_data/warehouses');
            exit;
        }

        $whId = (int)($_POST['warehouse_id'] ?? 0);
        $stmtW = $db->prepare("SELECT * FROM warehouses WHERE id = :id");
        $stmtW->execute([':id' => $whId]);
        $warehouse = $stmtW->fetch(PDO::FETCH_ASSOC);

        if (!$warehouse) {
            setFlash('error', 'Data gudang tidak ditemukan.');
            header('Location: index.php?page=master_data/warehouses');
            exit;
        }

        // Check stock balances
        $stmtStk = $db->prepare("SELECT COALESCE(SUM(qty_available), 0) FROM stock_balances WHERE warehouse_id = :id");
        $stmtStk->execute([':id' => $whId]);
        $currStock = (float)$stmtStk->fetchColumn();

        if ($currStock > 0) {
            setFlash('error', "Gudang {$warehouse['name']} tidak dapat dihapus karena masih menyimpan stok fisik sebanyak " . formatNumber($currStock) . " unit. Silakan kosongkan atau pindahkan stok terlebih dahulu.");
            header('Location: index.php?page=master_data/warehouses');
            exit;
        }

        // Check foreign key dependencies
        $references = checkRecordReferences('warehouses', $whId, $db);
        if (!empty($references)) {
            setFlash('error', "Gudang {$warehouse['name']} tidak dapat dihapus karena masih tercatat dalam transaksi operasional: " . implode(', ', $references) . ". Silakan gunakan opsi Nonaktifkan.");
            header('Location: index.php?page=master_data/warehouses');
            exit;
        }

        try {
            $stmtDel = $db->prepare("DELETE FROM warehouses WHERE id = :id");
            $stmtDel->execute([':id' => $whId]);

            logAudit($user['id'], 'delete_warehouse', 'warehouses', $whId, $warehouse, null);

            setFlash('success', "Gudang {$warehouse['name']} ({$warehouse['code']}) berhasil dihapus permanen.");
            header('Location: index.php?page=master_data/warehouses');
            exit;
        } catch (Exception $e) {
            setFlash('error', 'Gagal menghapus gudang: ' . $e->getMessage());
            header('Location: index.php?page=master_data/warehouses');
            exit;
        }
    }
}

// Search & filter
$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(w.code LIKE :q OR w.name LIKE :q OR w.address LIKE :q)';
    $params[':q'] = "%{$search}%";
}

if ($typeFilter !== '') {
    $where[] = 'w.type = :type';
    $params[':type'] = $typeFilter;
}

if ($statusFilter === 'active') {
    $where[] = 'w.is_active = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'w.is_active = 0';
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM warehouses w WHERE {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

// Fetch warehouses with item counts and total stock
$stmt = $db->prepare("
    SELECT w.*,
           COUNT(DISTINCT sb.item_id) AS total_sku_count,
           COALESCE(SUM(sb.qty_available), 0) AS total_physical_stock
    FROM warehouses w
    LEFT JOIN stock_balances sb ON w.id = sb.warehouse_id
    WHERE {$whereSql}
    GROUP BY w.id
    ORDER BY w.id ASC
    LIMIT :offset, :per_page
");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Edit check
$editWh = null;
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    $stmtE = $db->prepare("SELECT * FROM warehouses WHERE id = :id");
    $stmtE->execute([':id' => $editId]);
    $editWh = $stmtE->fetch(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Master Gudang &amp; Lokasi Penyimpanan</h1>
        <div class="page-subtitle">Data Master &bull; Gudang Bahan Baku, Gudang WIP, Gudang Barang Jadi, dan Bahan Kemas PT Mayora Indah Tbk</div>
    </div>
    <div class="page-actions">
        <?php if ($editWh): ?>
            <a href="index.php?page=master_data/warehouses" class="btn btn-secondary">&laquo; Batalkan Edit</a>
        <?php endif; ?>
    </div>
</div>

<!-- Add / Edit Collapsible Form -->
<?php if (hasRole(['Administrator', 'admin', 'gudang'])): ?>
<div class="card mb-4">
    <details <?= $editWh ? 'open' : '' ?>>
        <summary style="cursor:pointer; padding:15px 20px; font-weight:600; font-size:15px; color:#0284c7; background:#f8fafc; border-radius:6px; list-style:none; display:flex; justify-content:space-between; align-items:center;">
            <span><?= $editWh ? '✏️ Edit Gudang: ' . e($editWh['name']) . ' (' . e($editWh['code']) . ')' : '+ Tambah Gudang Baru' ?></span>
            <span class="btn btn-sm btn-primary"><?= $editWh ? 'Tutup Form Edit' : 'Buka Formulir Input &darr;' ?></span>
        </summary>
        <div style="padding:20px; border-top:1px solid #e2e8f0;">
            <form action="index.php?page=master_data/warehouses" method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_warehouse">
                <input type="hidden" name="warehouse_id" value="<?= $editWh['id'] ?? 0 ?>">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:15px;">
                    <div class="form-group">
                        <label>Kode Gudang *</label>
                        <input type="text" name="code" value="<?= e($editWh['code'] ?? '') ?>" placeholder="Misal: WH-01" required>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Nama Gudang *</label>
                        <input type="text" name="name" value="<?= e($editWh['name'] ?? '') ?>" placeholder="Misal: Gudang Sentul Bahan Baku" required>
                    </div>
                    <div class="form-group">
                        <label>Tipe / Kategori Gudang *</label>
                        <select name="type" required>
                            <option value="bahan_baku" <?= ($editWh['type'] ?? '') === 'bahan_baku' ? 'selected' : '' ?>>Gudang Bahan Baku (Raw Material)</option>
                            <option value="barang_jadi" <?= ($editWh['type'] ?? '') === 'barang_jadi' ? 'selected' : '' ?>>Gudang Barang Jadi (Finished Goods)</option>
                            <option value="wip" <?= ($editWh['type'] ?? '') === 'wip' ? 'selected' : '' ?>>Gudang WIP (Work In Progress)</option>
                            <option value="umum" <?= ($editWh['type'] ?? 'umum') === 'umum' ? 'selected' : '' ?>>Gudang Umum / Transit</option>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Alamat / Lokasi Fasilitas</label>
                        <textarea name="address" rows="2" placeholder="Plant, Gedung, Zona, atau Alamat Lengkap Gudang"><?= e($editWh['address'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group" style="display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" name="is_active" value="1" id="wh_active" <?= (!isset($editWh['is_active']) || $editWh['is_active']) ? 'checked' : '' ?> style="width:18px; height:18px;">
                        <label for="wh_active" style="margin-bottom:0; font-weight:600; cursor:pointer;">Status Gudang Aktif Beroperasi</label>
                    </div>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:15px;">
                    <?php if ($editWh): ?>
                        <a href="index.php?page=master_data/warehouses" class="btn btn-secondary">Batal Edit</a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">
                        <?= $editWh ? '💾 Simpan Perubahan Gudang' : '+ Simpan Gudang Baru' ?>
                    </button>
                </div>
            </form>
        </div>
    </details>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="master_data/warehouses">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari kode, nama, lokasi..." style="min-width:250px;">
    </div>
    <div class="filter-group">
        <select name="type">
            <option value="">-- Semua Tipe Gudang --</option>
            <option value="bahan_baku" <?= $typeFilter === 'bahan_baku' ? 'selected' : '' ?>>Bahan Baku</option>
            <option value="barang_jadi" <?= $typeFilter === 'barang_jadi' ? 'selected' : '' ?>>Barang Jadi</option>
            <option value="wip" <?= $typeFilter === 'wip' ? 'selected' : '' ?>>WIP</option>
            <option value="umum" <?= $typeFilter === 'umum' ? 'selected' : '' ?>>Umum</option>
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
    <?php if ($search !== '' || $typeFilter !== '' || $statusFilter !== ''): ?>
        <a href="index.php?page=master_data/warehouses" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<!-- Table of Warehouses -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Gudang</th>
                    <th>Tipe Gudang</th>
                    <th>Alamat / Lokasi</th>
                    <th class="text-right">Total Item SKU</th>
                    <th class="text-right">Total Fisik Unit</th>
                    <th class="text-center">Status</th>
                    <th class="text-center" style="width:180px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($warehouses)): ?>
                    <tr>
                        <td colspan="8" class="text-center" style="padding:30px; color:#64748b;">
                            Tidak ada data gudang yang sesuai kriteria pencarian.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($warehouses as $wh): ?>
                        <tr>
                            <td><strong><?= e($wh['code']) ?></strong></td>
                            <td><strong><?= e($wh['name']) ?></strong></td>
                            <td>
                                <?php if ($wh['type'] === 'bahan_baku'): ?>
                                    <span class="badge" style="background:#e0f2fe; color:#0369a1;">Bahan Baku</span>
                                <?php elseif ($wh['type'] === 'barang_jadi'): ?>
                                    <span class="badge" style="background:#dcfce7; color:#15803d;">Barang Jadi</span>
                                <?php elseif ($wh['type'] === 'wip'): ?>
                                    <span class="badge" style="background:#fef3c7; color:#b45309;">WIP</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#f1f5f9; color:#475569;">Umum / Transit</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($wh['address'] ?: '-') ?></td>
                            <td class="text-right font-medium"><?= number_format((int)$wh['total_sku_count']) ?> SKU</td>
                            <td class="text-right font-bold" style="color:#0284c7;"><?= formatNumber((float)$wh['total_physical_stock']) ?> Unit</td>
                            <td class="text-center"><?= $wh['is_active'] ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-danger">Non-Aktif</span>' ?></td>
                            <td class="text-center">
                                <div class="btn-group">
                                    <!-- Detail Modal Trigger -->
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="openModal('modal-detail-wh-<?= $wh['id'] ?>')" title="Lihat Rincian Gudang">
                                        👁️ Detail
                                    </button>

                                    <!-- Edit Trigger -->
                                    <?php if (hasRole(['Administrator', 'admin', 'gudang'])): ?>
                                        <a href="index.php?page=master_data/warehouses&edit_id=<?= $wh['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit Data Gudang">
                                            ✏️ Edit
                                        </a>

                                        <!-- Toggle Status Trigger -->
                                        <form action="index.php?page=master_data/warehouses" method="POST" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin <?= $wh['is_active'] ? 'menonaktifkan' : 'mengaktifkan' ?> gudang <?= e(addslashes($wh['name'])) ?>?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="warehouse_id" value="<?= $wh['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $wh['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $wh['is_active'] ? 'Nonaktifkan Gudang' : 'Aktifkan Gudang' ?>">
                                                <?= $wh['is_active'] ? '⏸️' : '▶️' ?>
                                            </button>
                                        </form>

                                        <!-- Delete Trigger -->
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="openModal('modal-delete-wh-<?= $wh['id'] ?>')" title="Hapus Gudang">
                                            🗑️
                                        </button>
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
            <?= renderPagination($pagination, 'index.php?page=master_data/warehouses&q=' . urlencode($search) . '&type=' . urlencode($typeFilter) . '&status=' . urlencode($statusFilter)) ?>
        </div>
    <?php endif; ?>
</div>

<!-- ========================================================================= -->
<!-- MODALS PLACED OUTSIDE TABLE (Valid HTML5 DOM)                             -->
<!-- ========================================================================= -->
<?php if (!empty($warehouses)): ?>
    <?php foreach ($warehouses as $wh): ?>
        <?php
            // Fetch top inventory items stored in this warehouse
            $stmtTopItems = $db->prepare("
                SELECT i.code, i.name, u.code AS unit_symbol, sb.qty_available, sb.qty_reserved
                FROM stock_balances sb
                JOIN items i ON sb.item_id = i.id
                LEFT JOIN units u ON i.unit_id = u.id
                WHERE sb.warehouse_id = :id AND (sb.qty_available > 0 OR sb.qty_reserved > 0)
                ORDER BY sb.qty_available DESC
                LIMIT 10
            ");
            $stmtTopItems->execute([':id' => $wh['id']]);
            $topItems = $stmtTopItems->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div id="modal-detail-wh-<?= $wh['id'] ?>" class="modal-backdrop">
            <div class="modal-dialog">
                <div class="modal-header">
                    <h3 class="modal-title">👁️ Detail Gudang: <?= e($wh['name']) ?></h3>
                    <button type="button" class="modal-close" data-modal-close>&times;</button>
                </div>
                <div class="modal-body">
                    <table class="detail-table">
                        <tr>
                            <th>Kode Gudang</th>
                            <td><strong><?= e($wh['code']) ?></strong></td>
                        </tr>
                        <tr>
                            <th>Nama Fasilitas</th>
                            <td><?= e($wh['name']) ?></td>
                        </tr>
                        <tr>
                            <th>Tipe Gudang</th>
                            <td><?= strtoupper(str_replace('_', ' ', $wh['type'])) ?></td>
                        </tr>
                        <tr>
                            <th>Lokasi / Alamat</th>
                            <td><?= nl2br(e($wh['address'] ?: '-')) ?></td>
                        </tr>
                        <tr>
                            <th>Total Ragam SKU</th>
                            <td><strong><?= number_format((int)$wh['total_sku_count']) ?> macam barang</strong></td>
                        </tr>
                        <tr>
                            <th>Total Kuantitas Fisik</th>
                            <td><strong style="color:#0284c7; font-size:15px;"><?= formatNumber((float)$wh['total_physical_stock']) ?> unit</strong></td>
                        </tr>
                        <tr>
                            <th>Status Operasional</th>
                            <td><?= $wh['is_active'] ? '<span class="bd ok">Aktif</span>' : '<span class="bd no">Non-Aktif</span>' ?></td>
                        </tr>
                        <tr>
                            <th>Waktu Dibuat</th>
                            <td><?= formatDateTime($wh['created_at']) ?></td>
                        </tr>
                        <tr>
                            <th>Daftar Stok Terbanyak (Top 10)</th>
                            <td>
                                <?php if (empty($topItems)): ?>
                                    <span class="text-muted">Tidak ada stok tersimpan di gudang ini saat ini.</span>
                                <?php else: ?>
                                    <table style="width:100%; font-size:12px; margin-top:5px; border-collapse:collapse;">
                                        <thead>
                                            <tr style="background:#f1f5f9; text-align:left;">
                                                <th style="padding:4px 8px;">Barang</th>
                                                <th style="padding:4px 8px; text-align:right;">Tersedia</th>
                                                <th style="padding:4px 8px; text-align:right;">Alokasi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($topItems as $ti): ?>
                                                <tr style="border-bottom:1px solid #e2e8f0;">
                                                    <td style="padding:4px 8px;"><strong><?= e($ti['name']) ?></strong> <span class="text-muted">(<?= e($ti['code']) ?>)</span></td>
                                                    <td style="padding:4px 8px; text-align:right; font-weight:bold;"><?= formatNumber((float)$ti['qty_available']) ?> <?= e($ti['unit_symbol']) ?></td>
                                                    <td style="padding:4px 8px; text-align:right; color:#ea580c;"><?= formatNumber((float)$ti['qty_reserved']) ?></td>
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
                    <?php if (hasRole(['Administrator', 'admin', 'gudang'])): ?>
                        <a href="index.php?page=master_data/warehouses&edit_id=<?= $wh['id'] ?>" class="btn btn-primary">✏️ Edit Gudang Ini</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Modal Confirm Delete Warehouse -->
        <?php if (hasRole(['Administrator', 'admin', 'gudang'])): ?>
        <?php 
            $refs = checkRecordReferences('warehouses', $wh['id'], $db); 
            $hasStock = (float)$wh['total_physical_stock'] > 0;
        ?>
        <div id="modal-delete-wh-<?= $wh['id'] ?>" class="modal-backdrop">
            <div class="modal-dialog modal-sm">
                <div class="modal-header" style="background:#fee2e2;">
                    <h3 class="modal-title" style="color:#b91c1c;">⚠️ Konfirmasi Penghapusan</h3>
                    <button type="button" class="modal-close" data-modal-close>&times;</button>
                </div>
                <div class="modal-body">
                    <p>Anda akan memproses data gudang:</p>
                    <div style="background:#f8fafc; padding:10px; border-radius:6px; margin-bottom:12px; font-size:13px; border:1px solid #e2e8f0;">
                        <strong><?= e($wh['name']) ?></strong><br>
                        <span class="text-muted">Kode: <?= e($wh['code']) ?></span><br>
                        <span class="text-muted">Total Stok Fisik: <?= formatNumber((float)$wh['total_physical_stock']) ?> Unit</span>
                    </div>

                    <?php if ($hasStock || !empty($refs)): ?>
                        <div class="alert alert-warning" style="font-size:12px; padding:10px;">
                            <strong>Perhatian!</strong> Gudang ini tidak dapat dihapus permanen karena:
                            <ul style="margin:6px 0 0 16px; padding:0;">
                                <?php if ($hasStock): ?>
                                    <li>Masih terdapat saldo persediaan fisik sebanyak <?= formatNumber((float)$wh['total_physical_stock']) ?> unit</li>
                                <?php endif; ?>
                                <?php foreach ($refs as $r): ?>
                                    <li><?= e($r) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <p style="margin-top:6px; margin-bottom:0;">Sesuai kebijakan integritas data persediaan, gudang yang memiliki riwayat pergerakan <strong>tidak dapat dihapus</strong>. Silakan <strong>nonaktifkan</strong> gudang.</p>
                        </div>
                    <?php else: ?>
                        <p style="color:#b91c1c; font-size:12.5px;">Gudang ini kosong dan belum pernah mencatat transaksi pergerakan stok, aman untuk dihapus permanen.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Batal</button>
                    <?php if ($hasStock || !empty($refs)): ?>
                        <form action="index.php?page=master_data/warehouses" method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="warehouse_id" value="<?= $wh['id'] ?>">
                            <button type="submit" class="btn btn-warning btn-sm">⏸️ Nonaktifkan Saja</button>
                        </form>
                    <?php else: ?>
                        <form action="index.php?page=master_data/warehouses" method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_warehouse">
                            <input type="hidden" name="warehouse_id" value="<?= $wh['id'] ?>">
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
