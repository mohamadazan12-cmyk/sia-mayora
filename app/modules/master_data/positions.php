<?php
/**
 * Data Master: Jabatan & Posisi Kerja (Master Positions)
 * PT Mayora Indah Tbk. - Sistem Informasi Akuntansi & Operasional
 */

$pageTitle = 'Master Jabatan';
$breadcrumbCategory = 'Data Master';

$db = getDbConnection();
$user = currentUser();

$search = trim($_GET['q'] ?? '');
$deptFilter = (int)($_GET['dept_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

// Fetch active departments for dropdown
$deptList = $db->query("SELECT id, name, code FROM departments WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $action = $_POST['action'] ?? '';

    // 1. Save (Create or Update) Position
    if ($action === 'save_position') {
        if (!hasRole(['Administrator', 'admin', 'hr', 'manager'])) {
            setFlash('error', 'Anda tidak memiliki hak akses untuk mengubah data jabatan.');
            header('Location: index.php?page=master_data/positions');
            exit;
        }

        $posId = (int)($_POST['position_id'] ?? 0);
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $deptId = (int)($_POST['department_id'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($code) || empty($name) || $deptId <= 0) {
            setFlash('error', 'Kode jabatan, nama jabatan, dan departemen wajib diisi.');
            header('Location: index.php?page=master_data/positions' . ($posId > 0 ? "&edit_id={$posId}" : ''));
            exit;
        }

        try {
            if ($posId > 0) {
                $stmtDup = $db->prepare("SELECT id FROM positions WHERE code = :code AND id != :id");
                $stmtDup->execute([':code' => $code, ':id' => $posId]);
                if ($stmtDup->fetchColumn()) {
                    throw new Exception("Kode jabatan '{$code}' sudah digunakan oleh jabatan lain.");
                }

                $stmtOld = $db->prepare("SELECT * FROM positions WHERE id = :id");
                $stmtOld->execute([':id' => $posId]);
                $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

                $stmtUpd = $db->prepare("
                    UPDATE positions 
                    SET code = :code, name = :name, department_id = :dept_id, is_active = :active, updated_at = NOW()
                    WHERE id = :id
                ");
                $stmtUpd->execute([
                    ':code'    => $code,
                    ':name'    => $name,
                    ':dept_id' => $deptId,
                    ':active'  => $isActive,
                    ':id'      => $posId,
                ]);

                logAudit($user['id'], 'update_position', 'positions', $posId, $oldData, [
                    'code' => $code, 'name' => $name, 'department_id' => $deptId, 'is_active' => $isActive
                ]);

                setFlash('success', "Jabatan {$name} ({$code}) berhasil diperbarui.");
            } else {
                $stmtDup = $db->prepare("SELECT id FROM positions WHERE code = :code");
                $stmtDup->execute([':code' => $code]);
                if ($stmtDup->fetchColumn()) {
                    throw new Exception("Kode jabatan '{$code}' sudah terdaftar.");
                }

                $stmtIns = $db->prepare("
                    INSERT INTO positions (code, name, department_id, is_active, created_at, updated_at)
                    VALUES (:code, :name, :dept_id, :active, NOW(), NOW())
                ");
                $stmtIns->execute([
                    ':code'    => $code,
                    ':name'    => $name,
                    ':dept_id' => $deptId,
                    ':active'  => $isActive,
                ]);
                $newId = (int)$db->lastInsertId();

                logAudit($user['id'], 'create_position', 'positions', $newId, null, [
                    'code' => $code, 'name' => $name, 'department_id' => $deptId, 'is_active' => $isActive
                ]);

                setFlash('success', "Jabatan baru {$name} ({$code}) berhasil ditambahkan.");
            }

            header('Location: index.php?page=master_data/positions');
            exit;
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
            header('Location: index.php?page=master_data/positions' . ($posId > 0 ? "&edit_id={$posId}" : ''));
            exit;
        }
    }

    // 2. Toggle Status
    if ($action === 'toggle_status') {
        if (!hasRole(['Administrator', 'admin', 'hr'])) {
            setFlash('error', 'Akses ditolak.');
            header('Location: index.php?page=master_data/positions');
            exit;
        }

        $posId = (int)($_POST['position_id'] ?? 0);
        $stmtP = $db->prepare("SELECT * FROM positions WHERE id = :id");
        $stmtP->execute([':id' => $posId]);
        $pos = $stmtP->fetch(PDO::FETCH_ASSOC);

        if (!$pos) {
            setFlash('error', 'Data jabatan tidak ditemukan.');
            header('Location: index.php?page=master_data/positions');
            exit;
        }

        $newStatus = $pos['is_active'] ? 0 : 1;
        $db->prepare("UPDATE positions SET is_active = :act, updated_at = NOW() WHERE id = :id")
           ->execute([':act' => $newStatus, ':id' => $posId]);

        logAudit($user['id'], 'toggle_position_status', 'positions', $posId, ['is_active' => $pos['is_active']], ['is_active' => $newStatus]);
        setFlash('success', "Jabatan {$pos['name']} berhasil " . ($newStatus ? 'diaktifkan kembali.' : 'dinonaktifkan.'));
        header('Location: index.php?page=master_data/positions');
        exit;
    }

    // 3. Delete Position
    if ($action === 'delete_position') {
        if (!hasRole(['Administrator', 'admin'])) {
            setFlash('error', 'Hanya Administrator yang berwenang menghapus jabatan.');
            header('Location: index.php?page=master_data/positions');
            exit;
        }

        $posId = (int)($_POST['position_id'] ?? 0);
        $refs = checkRecordReferences('positions', $posId, $db);
        if (!empty($refs)) {
            setFlash('error', "Jabatan tidak dapat dihapus karena masih digunakan: " . implode(', ', $refs) . ". Silakan nonaktifkan.");
            header('Location: index.php?page=master_data/positions');
            exit;
        }

        $stmtP = $db->prepare("SELECT * FROM positions WHERE id = :id");
        $stmtP->execute([':id' => $posId]);
        $pos = $stmtP->fetch(PDO::FETCH_ASSOC);

        $db->prepare("DELETE FROM positions WHERE id = :id")->execute([':id' => $posId]);
        logAudit($user['id'], 'delete_position', 'positions', $posId, $pos, null);

        setFlash('success', "Jabatan {$pos['name']} ({$pos['code']}) berhasil dihapus permanen.");
        header('Location: index.php?page=master_data/positions');
        exit;
    }
}

// Search & Filter
$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(p.code LIKE :q OR p.name LIKE :q OR d.name LIKE :q)';
    $params[':q'] = "%{$search}%";
}
if ($deptFilter > 0) {
    $where[] = 'p.department_id = :dept';
    $params[':dept'] = $deptFilter;
}
if ($statusFilter === 'active') {
    $where[] = 'p.is_active = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'p.is_active = 0';
}
$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM positions p LEFT JOIN departments d ON p.department_id = d.id WHERE {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

$stmt = $db->prepare("
    SELECT p.*, d.name AS department_name, d.code AS department_code,
           (SELECT COUNT(*) FROM employees e WHERE e.position_id = p.id) AS employee_count
    FROM positions p
    LEFT JOIN departments d ON p.department_id = d.id
    WHERE {$whereSql}
    ORDER BY d.name ASC, p.name ASC
    LIMIT :offset, :per_page
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$positions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check edit
$editPos = null;
if (isset($_GET['edit_id'])) {
    $stmtE = $db->prepare("SELECT * FROM positions WHERE id = :id");
    $stmtE->execute([':id' => (int)$_GET['edit_id']]);
    $editPos = $stmtE->fetch(PDO::FETCH_ASSOC);
}

// Stats
$statTotal = (int)$db->query("SELECT COUNT(*) FROM positions")->fetchColumn();
$statActive = (int)$db->query("SELECT COUNT(*) FROM positions WHERE is_active = 1")->fetchColumn();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Master Jabatan &amp; Posisi</h1>
        <div class="page-subtitle">Struktur Hierarki dan Jenjang Jabatan PT Mayora Indah Tbk.</div>
    </div>
    <div class="page-actions">
        <?php if ($editPos): ?>
            <a href="index.php?page=master_data/positions" class="btn btn-secondary">&laquo; Kembali</a>
        <?php endif; ?>
    </div>
</div>

<!-- KPI Cards -->
<div class="stats mb-4" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:16px;">
    <div class="card" style="padding:14px 18px;">
        <div class="text-muted" style="font-size:11.5px; font-weight:600; text-transform:uppercase;">Total Jabatan</div>
        <div style="font-size:24px; font-weight:800; color:#111827; margin-top:4px;"><?= $statTotal ?></div>
        <small class="text-muted" style="font-size:11px;">Formasi Posisi Organisasi</small>
    </div>
    <div class="card" style="padding:14px 18px;">
        <div class="text-muted" style="font-size:11.5px; font-weight:600; text-transform:uppercase;">Jabatan Aktif</div>
        <div style="font-size:24px; font-weight:800; color:#16a34a; margin-top:4px;"><?= $statActive ?></div>
        <small class="text-muted" style="font-size:11px;">Tersedia untuk Pengangkatan</small>
    </div>
</div>

<!-- Add / Edit Form -->
<?php if (hasRole(['Administrator', 'admin', 'hr', 'manager'])): ?>
<div class="card mb-4">
    <details <?= $editPos ? 'open' : '' ?>>
        <summary style="cursor:pointer; padding:14px 18px; font-weight:600; font-size:14px; color:#111827; background:#f8fafc; border-radius:6px; list-style:none; display:flex; justify-content:space-between; align-items:center;">
            <span><?= $editPos ? '✏️ Edit Jabatan: <strong>' . e($editPos['name']) . '</strong>' : '+ Tambah Jabatan Baru' ?></span>
            <span class="btn btn-sm <?= $editPos ? 'btn-secondary' : 'btn-primary' ?>" style="pointer-events:none;">
                <?= $editPos ? 'Mode Edit' : 'Buka Formulir &darr;' ?>
            </span>
        </summary>
        <div style="padding:20px; border-top:1px solid #e5e7eb;">
            <form action="index.php?page=master_data/positions" method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_position">
                <input type="hidden" name="position_id" value="<?= $editPos['id'] ?? 0 ?>">
                
                <div style="display:grid; grid-template-columns: 1fr 1.5fr 1.5fr auto; gap:16px; align-items:flex-end;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Kode Jabatan *</label>
                        <input type="text" name="code" value="<?= e($editPos['code'] ?? '') ?>" placeholder="Misal: MGR-PROD, STF-QC" required>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Nama Jabatan *</label>
                        <input type="text" name="name" value="<?= e($editPos['name'] ?? '') ?>" placeholder="Misal: Supervisor Produksi Biskuit" required>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Departemen Terkait *</label>
                        <select name="department_id" required>
                            <option value="">-- Pilih Departemen --</option>
                            <?php foreach ($deptList as $dl): ?>
                                <option value="<?= $dl['id'] ?>" <?= ($editPos['department_id'] ?? 0) == $dl['id'] ? 'selected' : '' ?>>
                                    <?= e($dl['name']) ?> (<?= e($dl['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0; display:flex; gap:10px; align-items:center;">
                        <label style="margin-bottom:0; display:flex; align-items:center; gap:6px; font-weight:600; cursor:pointer;">
                            <input type="checkbox" name="is_active" value="1" <?= (!isset($editPos['is_active']) || $editPos['is_active']) ? 'checked' : '' ?> style="width:18px; height:18px;">
                            Aktif
                        </label>
                        <button type="submit" class="btn btn-primary" style="height:36px;">
                            <?= $editPos ? 'Simpan Perubahan' : '+ Tambah' ?>
                        </button>
                        <?php if ($editPos): ?>
                            <a href="index.php?page=master_data/positions" class="btn btn-secondary" style="height:36px; line-height:22px;">Batal</a>
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
    <input type="hidden" name="page" value="master_data/positions">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari kode atau nama jabatan..." style="min-width:240px;">
    </div>
    <div class="filter-group">
        <select name="dept_id">
            <option value="0">-- Semua Departemen --</option>
            <?php foreach ($deptList as $dl): ?>
                <option value="<?= $dl['id'] ?>" <?= $deptFilter == $dl['id'] ? 'selected' : '' ?>>
                    <?= e($dl['name']) ?>
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
    <?php if ($search !== '' || $deptFilter > 0 || $statusFilter !== ''): ?>
        <a href="index.php?page=master_data/positions" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<!-- Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Jabatan</th>
                    <th>Departemen Unit</th>
                    <th class="text-center">Personil Karyawan</th>
                    <th class="text-center">Status</th>
                    <th class="text-center" style="width:160px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($positions)): ?>
                    <tr>
                        <td colspan="6" class="text-center" style="padding:28px; color:#6b7280;">
                            Tidak ada data jabatan yang sesuai kriteria pencarian.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($positions as $p): ?>
                        <tr>
                            <td><strong><?= e($p['code']) ?></strong></td>
                            <td><strong style="color:#111827;"><?= e($p['name']) ?></strong></td>
                            <td><?= e($p['department_name'] ?: '-') ?></td>
                            <td class="text-center">
                                <span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:700;">
                                    <?= (int)$p['employee_count'] ?> Orang
                                </span>
                            </td>
                            <td class="text-center">
                                <?= $p['is_active'] ? '<span class="bd ok">Aktif</span>' : '<span class="bd no">Non-Aktif</span>' ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group">
                                    <?php if (hasRole(['Administrator', 'admin', 'hr', 'manager'])): ?>
                                        <a href="index.php?page=master_data/positions&edit_id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit Jabatan">
                                            ✏️ Edit
                                        </a>

                                        <form action="index.php?page=master_data/positions" method="POST" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin <?= $p['is_active'] ? 'menonaktifkan' : 'mengaktifkan' ?> jabatan <?= e(addslashes($p['name'])) ?>?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="position_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $p['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $p['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                                <?= $p['is_active'] ? '⏸️' : '▶️' ?>
                                            </button>
                                        </form>

                                        <?php if (hasRole(['Administrator', 'admin'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="openModal('modal-del-pos-<?= $p['id'] ?>')" title="Hapus Jabatan">
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
            <?= renderPagination($pagination, 'index.php?page=master_data/positions&q=' . urlencode($search) . '&dept_id=' . $deptFilter . '&status=' . urlencode($statusFilter)) ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modals outside table -->
<?php if (!empty($positions) && hasRole(['Administrator', 'admin'])): ?>
    <?php foreach ($positions as $p): ?>
        <?php $refs = checkRecordReferences('positions', $p['id'], $db); ?>
        <div id="modal-del-pos-<?= $p['id'] ?>" class="modal-backdrop">
            <div class="modal-dialog modal-sm">
                <div class="modal-header" style="background:#fee2e2;">
                    <h3 class="modal-title" style="color:#b91c1c;">⚠️ Konfirmasi Hapus Jabatan</h3>
                    <button type="button" class="modal-close" data-modal-close>&times;</button>
                </div>
                <div class="modal-body">
                    <p>Anda akan memproses data jabatan:</p>
                    <div style="background:#f8fafc; padding:10px; border-radius:6px; margin-bottom:12px; font-size:13px; border:1px solid #e2e8f0;">
                        <strong><?= e($p['name']) ?></strong><br>
                        <span class="text-muted">Kode: <?= e($p['code']) ?></span><br>
                        <span class="text-muted">Departemen: <?= e($p['department_name'] ?: '-') ?></span>
                    </div>

                    <?php if (!empty($refs)): ?>
                        <div class="alert alert-warning" style="font-size:12px; padding:10px;">
                            <strong>Perhatian!</strong> Jabatan ini tidak dapat dihapus permanen karena masih terhubung dengan:
                            <ul style="margin:6px 0 0 16px; padding:0;">
                                <?php foreach ($refs as $r): ?>
                                    <li><?= e($r) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <p style="margin-top:6px; margin-bottom:0;">Anda disarankan untuk <strong>menonaktifkannya</strong>.</p>
                        </div>
                    <?php else: ?>
                        <p style="color:#b91c1c; font-size:12.5px;">Jabatan ini belum memiliki personil terkait dan aman untuk dihapus permanen.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Batal</button>
                    <?php if (!empty($refs)): ?>
                        <form action="index.php?page=master_data/positions" method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="position_id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn btn-warning btn-sm">⏸️ Nonaktifkan Saja</button>
                        </form>
                    <?php else: ?>
                        <form action="index.php?page=master_data/positions" method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_position">
                            <input type="hidden" name="position_id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">🗑️ Ya, Hapus Permanen</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
