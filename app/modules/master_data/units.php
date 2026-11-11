<?php
/**
 * Data Master: Satuan Ukuran & Unit (Master Units)
 * PT Mayora Indah Tbk. - Sistem Informasi Akuntansi & Operasional
 */

$pageTitle = 'Master Satuan';
$breadcrumbCategory = 'Data Master';

$db = getDbConnection();
$user = currentUser();

$search = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $action = $_POST['action'] ?? '';

    // 1. Save (Create or Update) Unit
    if ($action === 'save_unit') {
        if (!hasRole(['Administrator', 'admin', 'purchasing', 'gudang', 'ppic'])) {
            setFlash('error', 'Anda tidak memiliki hak akses untuk mengubah data satuan.');
            header('Location: index.php?page=master_data/units');
            exit;
        }

        $unitId = (int)($_POST['unit_id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($code) || empty($name)) {
            setFlash('error', 'Kode simbol dan nama satuan wajib diisi.');
            header('Location: index.php?page=master_data/units' . ($unitId > 0 ? "&edit_id={$unitId}" : ''));
            exit;
        }

        try {
            if ($unitId > 0) {
                $stmtDup = $db->prepare("SELECT id FROM units WHERE code = :code AND id != :id");
                $stmtDup->execute([':code' => $code, ':id' => $unitId]);
                if ($stmtDup->fetchColumn()) {
                    throw new Exception("Simbol satuan '{$code}' sudah digunakan oleh satuan lain.");
                }

                $stmtOld = $db->prepare("SELECT * FROM units WHERE id = :id");
                $stmtOld->execute([':id' => $unitId]);
                $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

                $stmtUpd = $db->prepare("
                    UPDATE units 
                    SET code = :code, name = :name, is_active = :active, updated_at = NOW()
                    WHERE id = :id
                ");
                $stmtUpd->execute([
                    ':code'   => $code,
                    ':name'   => $name,
                    ':active' => $isActive,
                    ':id'     => $unitId,
                ]);

                logAudit($user['id'], 'update_unit', 'units', $unitId, $oldData, [
                    'code' => $code, 'name' => $name, 'is_active' => $isActive
                ]);

                setFlash('success', "Satuan {$name} ({$code}) berhasil diperbarui.");
            } else {
                $stmtDup = $db->prepare("SELECT id FROM units WHERE code = :code");
                $stmtDup->execute([':code' => $code]);
                if ($stmtDup->fetchColumn()) {
                    throw new Exception("Simbol satuan '{$code}' sudah terdaftar.");
                }

                $stmtIns = $db->prepare("
                    INSERT INTO units (code, name, is_active, created_at, updated_at)
                    VALUES (:code, :name, :active, NOW(), NOW())
                ");
                $stmtIns->execute([
                    ':code'   => $code,
                    ':name'   => $name,
                    ':active' => $isActive,
                ]);
                $newId = (int)$db->lastInsertId();

                logAudit($user['id'], 'create_unit', 'units', $newId, null, [
                    'code' => $code, 'name' => $name, 'is_active' => $isActive
                ]);

                setFlash('success', "Satuan baru {$name} ({$code}) berhasil ditambahkan.");
            }

            header('Location: index.php?page=master_data/units');
            exit;
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
            header('Location: index.php?page=master_data/units' . ($unitId > 0 ? "&edit_id={$unitId}" : ''));
            exit;
        }
    }

    // 2. Toggle Status
    if ($action === 'toggle_status') {
        if (!hasRole(['Administrator', 'admin', 'purchasing', 'gudang'])) {
            setFlash('error', 'Akses ditolak.');
            header('Location: index.php?page=master_data/units');
            exit;
        }

        $unitId = (int)($_POST['unit_id'] ?? 0);
        $stmtU = $db->prepare("SELECT * FROM units WHERE id = :id");
        $stmtU->execute([':id' => $unitId]);
        $unit = $stmtU->fetch(PDO::FETCH_ASSOC);

        if (!$unit) {
            setFlash('error', 'Data satuan tidak ditemukan.');
            header('Location: index.php?page=master_data/units');
            exit;
        }

        $newStatus = $unit['is_active'] ? 0 : 1;
        $db->prepare("UPDATE units SET is_active = :act, updated_at = NOW() WHERE id = :id")
           ->execute([':act' => $newStatus, ':id' => $unitId]);

        logAudit($user['id'], 'toggle_unit_status', 'units', $unitId, ['is_active' => $unit['is_active']], ['is_active' => $newStatus]);
        setFlash('success', "Satuan {$unit['name']} berhasil " . ($newStatus ? 'diaktifkan kembali.' : 'dinonaktifkan.'));
        header('Location: index.php?page=master_data/units');
        exit;
    }

    // 3. Delete Unit
    if ($action === 'delete_unit') {
        if (!hasRole(['Administrator', 'admin'])) {
            setFlash('error', 'Hanya Administrator yang berwenang menghapus master satuan.');
            header('Location: index.php?page=master_data/units');
            exit;
        }

        $unitId = (int)($_POST['unit_id'] ?? 0);
        $refs = checkRecordReferences('units', $unitId, $db);
        if (!empty($refs)) {
            setFlash('error', "Satuan tidak dapat dihapus karena masih digunakan: " . implode(', ', $refs) . ". Silakan nonaktifkan.");
            header('Location: index.php?page=master_data/units');
            exit;
        }

        $stmtU = $db->prepare("SELECT * FROM units WHERE id = :id");
        $stmtU->execute([':id' => $unitId]);
        $unit = $stmtU->fetch(PDO::FETCH_ASSOC);

        $db->prepare("DELETE FROM units WHERE id = :id")->execute([':id' => $unitId]);
        logAudit($user['id'], 'delete_unit', 'units', $unitId, $unit, null);

        setFlash('success', "Satuan {$unit['name']} ({$unit['code']}) berhasil dihapus permanen.");
        header('Location: index.php?page=master_data/units');
        exit;
    }
}

// Search & Filter
$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(u.code LIKE :q OR u.name LIKE :q)';
    $params[':q'] = "%{$search}%";
}
if ($statusFilter === 'active') {
    $where[] = 'u.is_active = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'u.is_active = 0';
}
$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM units u WHERE {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

$stmt = $db->prepare("
    SELECT u.*,
           (SELECT COUNT(*) FROM items i WHERE i.unit_id = u.id) AS item_count
    FROM units u
    WHERE {$whereSql}
    ORDER BY u.name ASC
    LIMIT :offset, :per_page
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check edit
$editUnit = null;
if (isset($_GET['edit_id'])) {
    $stmtE = $db->prepare("SELECT * FROM units WHERE id = :id");
    $stmtE->execute([':id' => (int)$_GET['edit_id']]);
    $editUnit = $stmtE->fetch(PDO::FETCH_ASSOC);
}

// Stats
$statTotal = (int)$db->query("SELECT COUNT(*) FROM units")->fetchColumn();
$statActive = (int)$db->query("SELECT COUNT(*) FROM units WHERE is_active = 1")->fetchColumn();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Master Satuan Ukuran</h1>
        <div class="page-subtitle">Standar Satuan Kuantitas Material, Komponen, dan Produk Jadi PT Mayora Indah Tbk.</div>
    </div>
    <div class="page-actions">
        <?php if ($editUnit): ?>
            <a href="index.php?page=master_data/units" class="btn btn-secondary">&laquo; Kembali</a>
        <?php endif; ?>
    </div>
</div>

<!-- KPI Cards -->
<div class="stats mb-4" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:16px;">
    <div class="card" style="padding:14px 18px;">
        <div class="text-muted" style="font-size:11.5px; font-weight:600; text-transform:uppercase;">Total Satuan</div>
        <div style="font-size:24px; font-weight:800; color:#111827; margin-top:4px;"><?= $statTotal ?></div>
        <small class="text-muted" style="font-size:11px;">Standar Konversi Satuan</small>
    </div>
    <div class="card" style="padding:14px 18px;">
        <div class="text-muted" style="font-size:11.5px; font-weight:600; text-transform:uppercase;">Satuan Aktif</div>
        <div style="font-size:24px; font-weight:800; color:#16a34a; margin-top:4px;"><?= $statActive ?></div>
        <small class="text-muted" style="font-size:11px;">Digunakan pada Master Barang</small>
    </div>
</div>

<!-- Add / Edit Form -->
<?php if (hasRole(['Administrator', 'admin', 'purchasing', 'gudang', 'ppic'])): ?>
<div class="card mb-4">
    <details <?= $editUnit ? 'open' : '' ?>>
        <summary style="cursor:pointer; padding:14px 18px; font-weight:600; font-size:14px; color:#111827; background:#f8fafc; border-radius:6px; list-style:none; display:flex; justify-content:space-between; align-items:center;">
            <span><?= $editUnit ? '✏️ Edit Satuan: <strong>' . e($editUnit['name']) . '</strong>' : '+ Tambah Satuan Baru' ?></span>
            <span class="btn btn-sm <?= $editUnit ? 'btn-secondary' : 'btn-primary' ?>" style="pointer-events:none;">
                <?= $editUnit ? 'Mode Edit' : 'Buka Formulir &darr;' ?>
            </span>
        </summary>
        <div style="padding:20px; border-top:1px solid #e5e7eb;">
            <form action="index.php?page=master_data/units" method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_unit">
                <input type="hidden" name="unit_id" value="<?= $editUnit['id'] ?? 0 ?>">
                
                <div style="display:grid; grid-template-columns: 1fr 2fr auto; gap:16px; align-items:flex-end;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Simbol Satuan (Kode) *</label>
                        <input type="text" name="code" value="<?= e($editUnit['code'] ?? '') ?>" placeholder="Misal: KG, DUS, PCS, PACK" required>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Nama Lengkap Satuan *</label>
                        <input type="text" name="name" value="<?= e($editUnit['name'] ?? '') ?>" placeholder="Misal: Kilogram, Dus Karton, Pieces" required>
                    </div>
                    <div class="form-group" style="margin-bottom:0; display:flex; gap:10px; align-items:center;">
                        <label style="margin-bottom:0; display:flex; align-items:center; gap:6px; font-weight:600; cursor:pointer;">
                            <input type="checkbox" name="is_active" value="1" <?= (!isset($editUnit['is_active']) || $editUnit['is_active']) ? 'checked' : '' ?> style="width:18px; height:18px;">
                            Aktif
                        </label>
                        <button type="submit" class="btn btn-primary" style="height:36px;">
                            <?= $editUnit ? 'Simpan Perubahan' : '+ Tambah' ?>
                        </button>
                        <?php if ($editUnit): ?>
                            <a href="index.php?page=master_data/units" class="btn btn-secondary" style="height:36px; line-height:22px;">Batal</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </details>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="master_data/units">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari kode atau nama satuan..." style="min-width:260px;">
    </div>
    <div class="filter-group">
        <select name="status">
            <option value="">-- Semua Status --</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Hanya Aktif</option>
            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Hanya Non-Aktif</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $statusFilter !== ''): ?>
        <a href="index.php?page=master_data/units" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<!-- Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Simbol (Kode)</th>
                    <th>Nama Satuan</th>
                    <th class="text-center">Digunakan di Master Barang</th>
                    <th class="text-center">Status</th>
                    <th class="text-center" style="width:160px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($units)): ?>
                    <tr>
                        <td colspan="5" class="text-center" style="padding:28px; color:#6b7280;">
                            Tidak ada data satuan yang sesuai kriteria pencarian.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($units as $u): ?>
                        <tr>
                            <td><strong><?= e($u['code']) ?></strong></td>
                            <td><strong style="color:#111827;"><?= e($u['name']) ?></strong></td>
                            <td class="text-center">
                                <span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:700;">
                                    <?= (int)$u['item_count'] ?> Barang
                                </span>
                            </td>
                            <td class="text-center">
                                <?= $u['is_active'] ? '<span class="bd ok">Aktif</span>' : '<span class="bd no">Non-Aktif</span>' ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group">
                                    <?php if (hasRole(['Administrator', 'admin', 'purchasing', 'gudang', 'ppic'])): ?>
                                        <a href="index.php?page=master_data/units&edit_id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit Satuan">
                                            ✏️ Edit
                                        </a>

                                        <form action="index.php?page=master_data/units" method="POST" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin <?= $u['is_active'] ? 'menonaktifkan' : 'mengaktifkan' ?> satuan <?= e(addslashes($u['name'])) ?>?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="unit_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $u['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $u['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                                <?= $u['is_active'] ? '⏸️' : '▶️' ?>
                                            </button>
                                        </form>

                                        <?php if (hasRole(['Administrator', 'admin'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="openModal('modal-del-unit-<?= $u['id'] ?>')" title="Hapus Satuan">
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

    <?php if ($pagination['total_pages'] > 1): ?>
        <div class="card-footer">
            <?= renderPagination($pagination, 'index.php?page=master_data/units&q=' . urlencode($search) . '&status=' . urlencode($statusFilter)) ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modals outside table -->
<?php if (!empty($units) && hasRole(['Administrator', 'admin'])): ?>
    <?php foreach ($units as $u): ?>
        <?php $refs = checkRecordReferences('units', $u['id'], $db); ?>
        <div id="modal-del-unit-<?= $u['id'] ?>" class="modal-backdrop">
            <div class="modal-dialog modal-sm">
                <div class="modal-header" style="background:#fee2e2;">
                    <h3 class="modal-title" style="color:#b91c1c;">⚠️ Konfirmasi Hapus Satuan</h3>
                    <button type="button" class="modal-close" data-modal-close>&times;</button>
                </div>
                <div class="modal-body">
                    <p>Anda akan memproses data satuan:</p>
                    <div style="background:#f8fafc; padding:10px; border-radius:6px; margin-bottom:12px; font-size:13px; border:1px solid #e2e8f0;">
                        <strong><?= e($u['name']) ?></strong><br>
                        <span class="text-muted">Simbol: <?= e($u['code']) ?></span>
                    </div>

                    <?php if (!empty($refs)): ?>
                        <div class="alert alert-warning" style="font-size:12px; padding:10px;">
                            <strong>Perhatian!</strong> Satuan ini tidak dapat dihapus permanen karena masih digunakan oleh:
                            <ul style="margin:6px 0 0 16px; padding:0;">
                                <?php foreach ($refs as $r): ?>
                                    <li><?= e($r) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <p style="margin-top:6px; margin-bottom:0;">Anda disarankan untuk <strong>menonaktifkannya</strong>.</p>
                        </div>
                    <?php else: ?>
                        <p style="color:#b91c1c; font-size:12.5px;">Satuan ini belum digunakan oleh master barang manapun dan aman untuk dihapus permanen.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Batal</button>
                    <?php if (!empty($refs)): ?>
                        <form action="index.php?page=master_data/units" method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="unit_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-warning btn-sm">⏸️ Nonaktifkan Saja</button>
                        </form>
                    <?php else: ?>
                        <form action="index.php?page=master_data/units" method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_unit">
                            <input type="hidden" name="unit_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">🗑️ Ya, Hapus Permanen</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
