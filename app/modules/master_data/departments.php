<?php
/**
 * Data Master: Departemen Perusahaan (Master Departments)
 * PT Mayora Indah Tbk. - Sistem Informasi Akuntansi & Operasional
 */

$pageTitle = 'Master Departemen';
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

    // 1. Save (Create or Update) Department
    if ($action === 'save_department') {
        if (!hasRole(['Administrator', 'admin', 'hr', 'manager'])) {
            setFlash('error', 'Anda tidak memiliki hak akses untuk mengubah data departemen.');
            header('Location: index.php?page=master_data/departments');
            exit;
        }

        $deptId = (int)($_POST['department_id'] ?? 0);
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($code) || empty($name)) {
            setFlash('error', 'Kode dan nama departemen wajib diisi.');
            header('Location: index.php?page=master_data/departments' . ($deptId > 0 ? "&edit_id={$deptId}" : ''));
            exit;
        }

        try {
            if ($deptId > 0) {
                // Duplicate check
                $stmtDup = $db->prepare("SELECT id FROM departments WHERE code = :code AND id != :id");
                $stmtDup->execute([':code' => $code, ':id' => $deptId]);
                if ($stmtDup->fetchColumn()) {
                    throw new Exception("Kode departemen '{$code}' sudah digunakan oleh departemen lain.");
                }

                $stmtOld = $db->prepare("SELECT * FROM departments WHERE id = :id");
                $stmtOld->execute([':id' => $deptId]);
                $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

                $stmtUpd = $db->prepare("
                    UPDATE departments 
                    SET code = :code, name = :name, is_active = :active, updated_at = NOW()
                    WHERE id = :id
                ");
                $stmtUpd->execute([
                    ':code'   => $code,
                    ':name'   => $name,
                    ':active' => $isActive,
                    ':id'     => $deptId,
                ]);

                logAudit($user['id'], 'update_department', 'departments', $deptId, $oldData, [
                    'code' => $code, 'name' => $name, 'is_active' => $isActive
                ]);

                setFlash('success', "Data departemen {$name} ({$code}) berhasil diperbarui.");
            } else {
                $stmtDup = $db->prepare("SELECT id FROM departments WHERE code = :code");
                $stmtDup->execute([':code' => $code]);
                if ($stmtDup->fetchColumn()) {
                    throw new Exception("Kode departemen '{$code}' sudah terdaftar.");
                }

                $stmtIns = $db->prepare("
                    INSERT INTO departments (code, name, is_active, created_at, updated_at)
                    VALUES (:code, :name, :active, NOW(), NOW())
                ");
                $stmtIns->execute([
                    ':code'   => $code,
                    ':name'   => $name,
                    ':active' => $isActive,
                ]);
                $newId = (int)$db->lastInsertId();

                logAudit($user['id'], 'create_department', 'departments', $newId, null, [
                    'code' => $code, 'name' => $name, 'is_active' => $isActive
                ]);

                setFlash('success', "Departemen baru {$name} ({$code}) berhasil ditambahkan.");
            }

            header('Location: index.php?page=master_data/departments');
            exit;
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
            header('Location: index.php?page=master_data/departments' . ($deptId > 0 ? "&edit_id={$deptId}" : ''));
            exit;
        }
    }

    // 2. Toggle Active Status
    if ($action === 'toggle_status') {
        if (!hasRole(['Administrator', 'admin', 'hr'])) {
            setFlash('error', 'Akses ditolak.');
            header('Location: index.php?page=master_data/departments');
            exit;
        }

        $deptId = (int)($_POST['department_id'] ?? 0);
        $stmtD = $db->prepare("SELECT * FROM departments WHERE id = :id");
        $stmtD->execute([':id' => $deptId]);
        $dept = $stmtD->fetch(PDO::FETCH_ASSOC);

        if (!$dept) {
            setFlash('error', 'Departemen tidak ditemukan.');
            header('Location: index.php?page=master_data/departments');
            exit;
        }

        $newStatus = $dept['is_active'] ? 0 : 1;
        $db->prepare("UPDATE departments SET is_active = :act, updated_at = NOW() WHERE id = :id")
           ->execute([':act' => $newStatus, ':id' => $deptId]);

        logAudit($user['id'], 'toggle_department_status', 'departments', $deptId, ['is_active' => $dept['is_active']], ['is_active' => $newStatus]);
        setFlash('success', "Departemen {$dept['name']} berhasil " . ($newStatus ? 'diaktifkan kembali.' : 'dinonaktifkan.'));
        header('Location: index.php?page=master_data/departments');
        exit;
    }

    // 3. Delete Department
    if ($action === 'delete_department') {
        if (!hasRole(['Administrator', 'admin'])) {
            setFlash('error', 'Hanya Administrator yang berwenang menghapus departemen.');
            header('Location: index.php?page=master_data/departments');
            exit;
        }

        $deptId = (int)($_POST['department_id'] ?? 0);
        $refs = checkRecordReferences('departments', $deptId, $db);
        if (!empty($refs)) {
            setFlash('error', "Departemen tidak dapat dihapus karena masih digunakan: " . implode(', ', $refs) . ". Silakan nonaktifkan.");
            header('Location: index.php?page=master_data/departments');
            exit;
        }

        $stmtD = $db->prepare("SELECT * FROM departments WHERE id = :id");
        $stmtD->execute([':id' => $deptId]);
        $dept = $stmtD->fetch(PDO::FETCH_ASSOC);

        $db->prepare("DELETE FROM departments WHERE id = :id")->execute([':id' => $deptId]);
        logAudit($user['id'], 'delete_department', 'departments', $deptId, $dept, null);

        setFlash('success', "Departemen {$dept['name']} ({$dept['code']}) berhasil dihapus permanen.");
        header('Location: index.php?page=master_data/departments');
        exit;
    }
}

// Search & Filter
$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(d.code LIKE :q OR d.name LIKE :q)';
    $params[':q'] = "%{$search}%";
}
if ($statusFilter === 'active') {
    $where[] = 'd.is_active = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'd.is_active = 0';
}
$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM departments d WHERE {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

$stmt = $db->prepare("
    SELECT d.*,
           (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id) AS employee_count,
           (SELECT COUNT(*) FROM positions p WHERE p.department_id = d.id) AS position_count
    FROM departments d
    WHERE {$whereSql}
    ORDER BY d.name ASC
    LIMIT :offset, :per_page
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check edit
$editDept = null;
if (isset($_GET['edit_id'])) {
    $stmtE = $db->prepare("SELECT * FROM departments WHERE id = :id");
    $stmtE->execute([':id' => (int)$_GET['edit_id']]);
    $editDept = $stmtE->fetch(PDO::FETCH_ASSOC);
}

// Stats
$statTotal = (int)$db->query("SELECT COUNT(*) FROM departments")->fetchColumn();
$statActive = (int)$db->query("SELECT COUNT(*) FROM departments WHERE is_active = 1")->fetchColumn();
$statEmpTotal = (int)$db->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Master Departemen</h1>
        <div class="page-subtitle">Struktur Organisasi Unit Kerja PT Mayora Indah Tbk.</div>
    </div>
    <div class="page-actions">
        <?php if ($editDept): ?>
            <a href="index.php?page=master_data/departments" class="btn btn-secondary">&laquo; Kembali</a>
        <?php endif; ?>
    </div>
</div>

<!-- KPI Cards -->
<div class="stats mb-4" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:16px;">
    <div class="card" style="padding:14px 18px;">
        <div class="text-muted" style="font-size:11.5px; font-weight:600; text-transform:uppercase;">Total Departemen</div>
        <div style="font-size:24px; font-weight:800; color:#111827; margin-top:4px;"><?= $statTotal ?></div>
        <small class="text-muted" style="font-size:11px;">Unit Kerja Operasional</small>
    </div>
    <div class="card" style="padding:14px 18px;">
        <div class="text-muted" style="font-size:11.5px; font-weight:600; text-transform:uppercase;">Departemen Aktif</div>
        <div style="font-size:24px; font-weight:800; color:#16a34a; margin-top:4px;"><?= $statActive ?></div>
        <small class="text-muted" style="font-size:11px;">Status Aktif</small>
    </div>
    <div class="card" style="padding:14px 18px;">
        <div class="text-muted" style="font-size:11.5px; font-weight:600; text-transform:uppercase;">Karyawan Aktif</div>
        <div style="font-size:24px; font-weight:800; color:#0284c7; margin-top:4px;"><?= $statEmpTotal ?></div>
        <small class="text-muted" style="font-size:11px;">Personalia Pabrik</small>
    </div>
</div>

<!-- Add / Edit Form -->
<?php if (hasRole(['Administrator', 'admin', 'hr', 'manager'])): ?>
<div class="card mb-4">
    <details <?= $editDept ? 'open' : '' ?>>
        <summary style="cursor:pointer; padding:14px 18px; font-weight:600; font-size:14px; color:#111827; background:#f8fafc; border-radius:6px; list-style:none; display:flex; justify-content:space-between; align-items:center;">
            <span><?= $editDept ? '✏️ Edit Departemen: <strong>' . e($editDept['name']) . '</strong>' : '+ Tambah Departemen Baru' ?></span>
            <span class="btn btn-sm <?= $editDept ? 'btn-secondary' : 'btn-primary' ?>" style="pointer-events:none;">
                <?= $editDept ? 'Mode Edit' : 'Buka Formulir &darr;' ?>
            </span>
        </summary>
        <div style="padding:20px; border-top:1px solid #e5e7eb;">
            <form action="index.php?page=master_data/departments" method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_department">
                <input type="hidden" name="department_id" value="<?= $editDept['id'] ?? 0 ?>">
                
                <div style="display:grid; grid-template-columns: 1fr 2fr auto; gap:16px; align-items:flex-end;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Kode Departemen *</label>
                        <input type="text" name="code" value="<?= e($editDept['code'] ?? '') ?>" placeholder="Misal: PROD, FIN, HRD" required>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Nama Departemen *</label>
                        <input type="text" name="name" value="<?= e($editDept['name'] ?? '') ?>" placeholder="Misal: Produksi Biskuit & Wafer" required>
                    </div>
                    <div class="form-group" style="margin-bottom:0; display:flex; gap:10px; align-items:center;">
                        <label style="margin-bottom:0; display:flex; align-items:center; gap:6px; font-weight:600; cursor:pointer;">
                            <input type="checkbox" name="is_active" value="1" <?= (!isset($editDept['is_active']) || $editDept['is_active']) ? 'checked' : '' ?> style="width:18px; height:18px;">
                            Aktif
                        </label>
                        <button type="submit" class="btn btn-primary" style="height:36px;">
                            <?= $editDept ? 'Simpan Perubahan' : '+ Tambah' ?>
                        </button>
                        <?php if ($editDept): ?>
                            <a href="index.php?page=master_data/departments" class="btn btn-secondary" style="height:36px; line-height:22px;">Batal</a>
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
    <input type="hidden" name="page" value="master_data/departments">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari kode atau nama departemen..." style="min-width:260px;">
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
        <a href="index.php?page=master_data/departments" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<!-- Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Departemen</th>
                    <th class="text-center">Jumlah Jabatan</th>
                    <th class="text-center">Jumlah Personil Karyawan</th>
                    <th class="text-center">Status</th>
                    <th class="text-center" style="width:160px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($departments)): ?>
                    <tr>
                        <td colspan="6" class="text-center" style="padding:28px; color:#6b7280;">
                            Tidak ada data departemen yang sesuai kriteria pencarian.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($departments as $d): ?>
                        <tr>
                            <td><strong><?= e($d['code']) ?></strong></td>
                            <td><strong style="color:#111827;"><?= e($d['name']) ?></strong></td>
                            <td class="text-center"><?= (int)$d['position_count'] ?> Jabatan</td>
                            <td class="text-center">
                                <span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:700;">
                                    <?= (int)$d['employee_count'] ?> Orang
                                </span>
                            </td>
                            <td class="text-center">
                                <?= $d['is_active'] ? '<span class="bd ok">Aktif</span>' : '<span class="bd no">Non-Aktif</span>' ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group">
                                    <?php if (hasRole(['Administrator', 'admin', 'hr', 'manager'])): ?>
                                        <a href="index.php?page=master_data/departments&edit_id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit Departemen">
                                            ✏️ Edit
                                        </a>

                                        <form action="index.php?page=master_data/departments" method="POST" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin <?= $d['is_active'] ? 'menonaktifkan' : 'mengaktifkan' ?> departemen <?= e(addslashes($d['name'])) ?>?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="department_id" value="<?= $d['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $d['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $d['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                                <?= $d['is_active'] ? '⏸️' : '▶️' ?>
                                            </button>
                                        </form>

                                        <?php if (hasRole(['Administrator', 'admin'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="openModal('modal-del-dept-<?= $d['id'] ?>')" title="Hapus Departemen">
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
            <?= renderPagination($pagination, 'index.php?page=master_data/departments&q=' . urlencode($search) . '&status=' . urlencode($statusFilter)) ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modals outside table -->
<?php if (!empty($departments) && hasRole(['Administrator', 'admin'])): ?>
    <?php foreach ($departments as $d): ?>
        <?php $refs = checkRecordReferences('departments', $d['id'], $db); ?>
        <div id="modal-del-dept-<?= $d['id'] ?>" class="modal-backdrop">
            <div class="modal-dialog modal-sm">
                <div class="modal-header" style="background:#fee2e2;">
                    <h3 class="modal-title" style="color:#b91c1c;">⚠️ Konfirmasi Hapus Departemen</h3>
                    <button type="button" class="modal-close" data-modal-close>&times;</button>
                </div>
                <div class="modal-body">
                    <p>Anda akan memproses data departemen:</p>
                    <div style="background:#f8fafc; padding:10px; border-radius:6px; margin-bottom:12px; font-size:13px; border:1px solid #e2e8f0;">
                        <strong><?= e($d['name']) ?></strong><br>
                        <span class="text-muted">Kode: <?= e($d['code']) ?></span>
                    </div>

                    <?php if (!empty($refs)): ?>
                        <div class="alert alert-warning" style="font-size:12px; padding:10px;">
                            <strong>Perhatian!</strong> Departemen ini tidak dapat dihapus permanen karena masih terhubung dengan:
                            <ul style="margin:6px 0 0 16px; padding:0;">
                                <?php foreach ($refs as $r): ?>
                                    <li><?= e($r) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <p style="margin-top:6px; margin-bottom:0;">Anda disarankan untuk <strong>menonaktifkannya</strong>.</p>
                        </div>
                    <?php else: ?>
                        <p style="color:#b91c1c; font-size:12.5px;">Departemen ini belum memiliki personil/jabatan terkait dan aman untuk dihapus permanen.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Batal</button>
                    <?php if (!empty($refs)): ?>
                        <form action="index.php?page=master_data/departments" method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="department_id" value="<?= $d['id'] ?>">
                            <button type="submit" class="btn btn-warning btn-sm">⏸️ Nonaktifkan Saja</button>
                        </form>
                    <?php else: ?>
                        <form action="index.php?page=master_data/departments" method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_department">
                            <input type="hidden" name="department_id" value="<?= $d['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">🗑️ Ya, Hapus Permanen</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
