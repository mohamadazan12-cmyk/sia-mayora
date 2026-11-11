<?php
/**
 * Modul Administrasi: Pengelolaan Pengguna & Hak Akses (User Management & RBAC)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Manajemen Pengguna & Hak Akses';
$breadcrumbCategory = 'Administrasi';

$db = getDbConnection();
$currentUser = currentUser();

// RBAC: Hanya Administrator
requireRole(['Administrator', 'admin']);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($username) || empty($password) || empty($fullName) || empty($email) || $roleId <= 0) {
            setFlash('error', 'Semua kolom bertanda bintang wajib diisi.');
            header('Location: index.php?page=users/index');
            exit;
        }

        // Check unique username & email
        $stmtCheck = $db->prepare('SELECT COUNT(*) FROM users WHERE username = :u OR email = :e');
        $stmtCheck->execute([':u' => $username, ':e' => $email]);
        if ($stmtCheck->fetchColumn() > 0) {
            setFlash('error', 'Username atau alamat email sudah terdaftar.');
            header('Location: index.php?page=users/index');
            exit;
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        try {
            $stmt = $db->prepare('
                INSERT INTO users (username, password_hash, full_name, email, role_id, is_active, created_by, updated_by, created_at, updated_at)
                VALUES (:u, :p, :fn, :e, :r, :act, :cb, :ub, NOW(), NOW())
            ');
            $stmt->execute([
                ':u'   => $username,
                ':p'   => $passwordHash,
                ':fn'  => $fullName,
                ':e'   => $email,
                ':r'   => $roleId,
                ':act' => $isActive,
                ':cb'  => $currentUser['id'],
                ':ub'  => $currentUser['id']
            ]);
            $newUserId = $db->lastInsertId();

            logAudit($currentUser['id'], 'create_user', 'users', $newUserId, null, [
                'username'  => $username,
                'full_name' => $fullName,
                'email'     => $email,
                'role_id'   => $roleId,
                'is_active' => $isActive
            ]);

            setFlash('success', "Pengguna {$username} berhasil ditambahkan!");
        } catch (Exception $ex) {
            setFlash('error', 'Gagal menambah pengguna: ' . $ex->getMessage());
        }

        header('Location: index.php?page=users/index');
        exit;
    }

    if ($action === 'edit_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($userId <= 0 || empty($fullName) || empty($email) || $roleId <= 0) {
            setFlash('error', 'Data perubahan pengguna tidak lengkap.');
            header('Location: index.php?page=users/index');
            exit;
        }

        // Check if modifying self and trying to deactivate
        if ($userId === (int)$currentUser['id'] && $isActive === 0) {
            setFlash('error', 'Anda tidak dapat menonaktifkan akun Anda sendiri.');
            header('Location: index.php?page=users/index');
            exit;
        }

        // Check email uniqueness excluding current user
        $stmtCheck = $db->prepare('SELECT COUNT(*) FROM users WHERE email = :e AND id != :id');
        $stmtCheck->execute([':e' => $email, ':id' => $userId]);
        if ($stmtCheck->fetchColumn() > 0) {
            setFlash('error', 'Alamat email sudah digunakan oleh pengguna lain.');
            header('Location: index.php?page=users/index');
            exit;
        }

        // Get old values
        $stmtOld = $db->prepare('SELECT full_name, email, role_id, is_active FROM users WHERE id = :id');
        $stmtOld->execute([':id' => $userId]);
        $oldValues = $stmtOld->fetch();

        try {
            $stmt = $db->prepare('
                UPDATE users 
                SET full_name = :fn, email = :e, role_id = :r, is_active = :act, updated_by = :ub, updated_at = NOW()
                WHERE id = :id
            ');
            $stmt->execute([
                ':fn'  => $fullName,
                ':e'   => $email,
                ':r'   => $roleId,
                ':act' => $isActive,
                ':ub'  => $currentUser['id'],
                ':id'  => $userId
            ]);

            logAudit($currentUser['id'], 'update_user', 'users', $userId, $oldValues, [
                'full_name' => $fullName,
                'email'     => $email,
                'role_id'   => $roleId,
                'is_active' => $isActive
            ]);

            setFlash('success', 'Profil pengguna berhasil diperbarui.');
        } catch (Exception $ex) {
            setFlash('error', 'Gagal memperbarui pengguna: ' . $ex->getMessage());
        }

        header('Location: index.php?page=users/index');
        exit;
    }

    if ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPassword = (string)($_POST['new_password'] ?? '');

        if ($userId <= 0 || strlen($newPassword) < 6) {
            setFlash('error', 'Kata sandi baru minimal harus 6 karakter.');
            header('Location: index.php?page=users/index');
            exit;
        }

        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);

        try {
            $stmt = $db->prepare('UPDATE users SET password_hash = :p, updated_by = :ub, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                ':p'  => $passwordHash,
                ':ub' => $currentUser['id'],
                ':id' => $userId
            ]);

            logAudit($currentUser['id'], 'reset_password', 'users', $userId, null, ['status' => 'password_reset_success']);

            setFlash('success', 'Kata sandi pengguna berhasil direset.');
        } catch (Exception $ex) {
            setFlash('error', 'Gagal mereset kata sandi: ' . $ex->getMessage());
        }

        header('Location: index.php?page=users/index');
        exit;
    }

    if ($action === 'toggle_status') {
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($userId === (int)$currentUser['id']) {
            setFlash('error', 'Anda tidak dapat mengubah status akun Anda sendiri.');
            header('Location: index.php?page=users/index');
            exit;
        }

        $stmtCur = $db->prepare('SELECT is_active FROM users WHERE id = :id');
        $stmtCur->execute([':id' => $userId]);
        $currentStatus = $stmtCur->fetchColumn();

        if ($currentStatus !== false) {
            $newStatus = $currentStatus ? 0 : 1;
            $stmtUpd = $db->prepare('UPDATE users SET is_active = :act, updated_by = :ub, updated_at = NOW() WHERE id = :id');
            $stmtUpd->execute([
                ':act' => $newStatus,
                ':ub'  => $currentUser['id'],
                ':id'  => $userId
            ]);

            logAudit($currentUser['id'], 'toggle_user_status', 'users', $userId, ['is_active' => $currentStatus], ['is_active' => $newStatus]);

            setFlash('success', 'Status keaktifan pengguna berhasil diubah.');
        }

        header('Location: index.php?page=users/index');
        exit;
    }
}

// Fetch all roles
$roles = $db->query('SELECT id, role_code, role_name, description FROM roles WHERE is_active = 1 ORDER BY id ASC')->fetchAll();

// Filter & fetch users
$search = trim($_GET['q'] ?? '');
$roleFilter = (int)($_GET['role_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(u.username LIKE :q OR u.full_name LIKE :q OR u.email LIKE :q)';
    $params[':q'] = "%{$search}%";
}
if ($roleFilter > 0) {
    $where[] = 'u.role_id = :role_id';
    $params[':role_id'] = $roleFilter;
}
if ($statusFilter !== '') {
    $where[] = 'u.is_active = :is_active';
    $params[':is_active'] = $statusFilter === 'active' ? 1 : 0;
}

$whereSql = implode(' AND ', $where);

$stmtUsers = $db->prepare("
    SELECT u.*, r.role_name, r.role_code, cb.full_name AS creator_name
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN users cb ON u.created_by = cb.id
    WHERE {$whereSql}
    ORDER BY u.id ASC
");
$stmtUsers->execute($params);
$users = $stmtUsers->fetchAll();

// Summary metrics
$totalUsers = count($users);
$activeUsers = 0;
foreach ($users as $u) {
    if ($u['is_active']) $activeUsers++;
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Manajemen Pengguna &amp; Hak Akses</h1>
        <div class="page-subtitle">Administrasi Sistem &bull; Konfigurasi Akun Pengguna, Penugasan Peran (RBAC), Reset Kata Sandi, dan Keamanan Akses</div>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-primary" onclick="openModal('modal-add-user')">
            + Tambah Pengguna Baru
        </button>
    </div>
</div>

<?= renderFlashMessages() ?>

<!-- Summary Cards -->
<div class="metrics-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
    <div class="metric-card">
        <div class="metric-value"><?= $totalUsers ?> Akun</div>
        <div class="metric-label">Total Akun Terdaftar</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#16a34a;"><?= $activeUsers ?> Akun</div>
        <div class="metric-label">Akun Aktif</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#64748b;"><?= $totalUsers - $activeUsers ?> Akun</div>
        <div class="metric-label">Akun Nonaktif / Ditangguhkan</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#0284c7;"><?= count($roles) ?> Peran</div>
        <div class="metric-label">Tingkat Peran Otorisasi (RBAC)</div>
    </div>
</div>

<!-- Info Demo Accounts Banner -->
<div class="card mb-4" style="background:#f0f9ff; border:1px solid #bae6fd;">
    <div style="padding:15px 20px;">
        <h4 style="margin:0 0 6px 0; color:#0369a1; font-size:14px; font-weight:700;">💡 Informasi Akun Demo Sistem:</h4>
        <p style="margin:0; font-size:13px; color:#0c4a6e; line-height:1.5;">
            Sistem telah menyediakan 12 akun demo untuk pengujian setiap siklus: 
            <code>admin</code>, <code>gudang</code>, <code>purchasing</code>, <code>ppic</code>, <code>qc</code>, <code>penjualan</code>, <code>finance</code>, <code>hr</code>, <code>manager</code>. 
            Kata sandi default untuk seluruh akun demo adalah <strong>admin123</strong> atau <strong>password</strong>.
        </p>
    </div>
</div>

<!-- Filter Bar -->
<div class="card mb-4">
    <div style="padding: 15px 20px;">
        <form action="index.php" method="GET" class="filter-bar" style="margin-bottom:0; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
            <input type="hidden" name="page" value="users/index">

            <div class="filter-group">
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari nama, username, email..." style="min-width:220px;">
            </div>

            <div class="filter-group">
                <select name="role_id">
                    <option value="">-- Semua Peran / Role --</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= $roleFilter === (int)$r['id'] ? 'selected' : '' ?>>
                            <?= e($r['role_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <select name="status">
                    <option value="">-- Semua Status --</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Aktif Saja</option>
                    <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Nonaktif Saja</option>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary btn-sm">Saring Data</button>

            <?php if ($search !== '' || $roleFilter > 0 || $statusFilter !== ''): ?>
                <a href="index.php?page=users/index" class="btn btn-secondary btn-sm">Reset</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h2 class="card-title">Daftar Akun Pengguna Terdaftar</h2>
        <span class="badge badge-info"><?= count($users) ?> Pengguna</span>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Nama Lengkap</th>
                        <th>Alamat Email</th>
                        <th>Peran (Role RBAC)</th>
                        <th>Status</th>
                        <th>Login Terakhir</th>
                        <th style="text-align:right;">Tindakan Administrasi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="7" style="text-align:center; padding:30px; color:#94a3b8;">Tidak ada data pengguna yang sesuai.</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td>
                                    <span style="font-family:monospace; font-weight:700; color:#0284c7;"><?= e($u['username']) ?></span>
                                    <?php if ($u['id'] === $currentUser['id']): ?>
                                        <span class="badge badge-primary" style="margin-left:4px; font-size:10px;">Anda</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= e($u['full_name']) ?></strong></td>
                                <td><?= e($u['email']) ?></td>
                                <td>
                                    <span class="badge badge-info"><?= e($u['role_name']) ?></span>
                                </td>
                                <td>
                                    <?php if ($u['is_active']): ?>
                                        <span class="badge badge-success">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:12px; color:#64748b;">
                                    <?= formatDateTime($u['last_login']) ?>
                                </td>
                                <td style="text-align:right;">
                                    <div style="display:inline-flex; gap:6px; align-items:center;">
                                        <!-- Edit Modal Trigger -->
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="openModal('modal-edit-user-<?= $u['id'] ?>')" style="padding:3px 8px; font-size:11.5px;">
                                            ✏️ Ubah
                                        </button>

                                        <!-- Reset Password Modal Trigger -->
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="openModal('modal-reset-pwd-<?= $u['id'] ?>')" style="padding:3px 8px; font-size:11.5px;">
                                            🔑 Password
                                        </button>

                                        <!-- Toggle Status Button -->
                                        <?php if ($u['id'] !== $currentUser['id']): ?>
                                            <form action="index.php?page=users/index" method="POST" style="margin:0; display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin <?= $u['is_active'] ? 'menonaktifkan' : 'mengaktifkan' ?> akun ini?');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <button type="submit" class="btn btn-<?= $u['is_active'] ? 'danger' : 'success' ?> btn-sm" style="padding:3px 8px; font-size:11.5px;">
                                                    <?= $u['is_active'] ? 'Suspend' : 'Aktifkan' ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Add User -->
<div id="modal-add-user" class="modal-backdrop">
    <div class="modal-dialog modal-sm">
        <div class="modal-header">
            <h3 class="modal-title">+ Tambah Akun Pengguna Baru</h3>
            <button type="button" class="modal-close" data-modal-close>&times;</button>
        </div>
        <form action="index.php?page=users/index" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_user">
            <div class="modal-body">
                <div class="form-group mb-2">
                    <label class="form-label" style="font-size:12px;">Nama Pengguna (Username) *</label>
                    <input type="text" name="username" class="form-control" placeholder="Contoh: agus.ppic" required>
                </div>
                <div class="form-group mb-2">
                    <label class="form-label" style="font-size:12px;">Kata Sandi (Password) *</label>
                    <input type="password" name="password" class="form-control" placeholder="Minimal 6 karakter" required minlength="6">
                </div>
                <div class="form-group mb-2">
                    <label class="form-label" style="font-size:12px;">Nama Lengkap Karyawan *</label>
                    <input type="text" name="full_name" class="form-control" placeholder="Nama lengkap dan gelar" required>
                </div>
                <div class="form-group mb-2">
                    <label class="form-label" style="font-size:12px;">Alamat Email Perusahaan *</label>
                    <input type="email" name="email" class="form-control" placeholder="email@manufaktur.id" required>
                </div>
                <div class="form-group mb-2">
                    <label class="form-label" style="font-size:12px;">Peran &amp; Hak Akses (Role) *</label>
                    <select name="role_id" class="form-control" required>
                        <option value="">-- Pilih Peran / Role --</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= e($r['role_name']) ?> (<?= e($r['role_code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group mb-0" style="display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" name="is_active" value="1" id="new_is_active" checked style="width:16px; height:16px;">
                    <label for="new_is_active" style="margin-bottom:0; font-size:12.5px; cursor:pointer;">Akun Langsung Aktif</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Batal</button>
                <button type="submit" class="btn btn-primary btn-sm">💾 Simpan Akun</button>
            </div>
        </form>
    </div>
</div>

<!-- Modals for Users (Edit & Reset Password) outside table -->
<?php if (!empty($users)): ?>
    <?php foreach ($users as $u): ?>
        <!-- Modal Edit User -->
        <div id="modal-edit-user-<?= $u['id'] ?>" class="modal-backdrop">
            <div class="modal-dialog modal-sm">
                <div class="modal-header">
                    <h3 class="modal-title">✏️ Ubah Akun: <?= e($u['username']) ?></h3>
                    <button type="button" class="modal-close" data-modal-close>&times;</button>
                </div>
                <form action="index.php?page=users/index" method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <div class="modal-body">
                        <div class="form-group mb-2">
                            <label class="form-label" style="font-size:11.5px;">Nama Lengkap *</label>
                            <input type="text" name="full_name" class="form-control" value="<?= e($u['full_name']) ?>" required>
                        </div>
                        <div class="form-group mb-2">
                            <label class="form-label" style="font-size:11.5px;">Alamat Email *</label>
                            <input type="email" name="email" class="form-control" value="<?= e($u['email']) ?>" required>
                        </div>
                        <div class="form-group mb-2">
                            <label class="form-label" style="font-size:11.5px;">Peran (Role RBAC) *</label>
                            <select name="role_id" class="form-control" required>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= $r['id'] ?>" <?= (int)$u['role_id'] === (int)$r['id'] ? 'selected' : '' ?>>
                                        <?= e($r['role_name']) ?> (<?= e($r['role_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group mb-0" style="display:flex; align-items:center; gap:6px;">
                            <input type="checkbox" name="is_active" value="1" id="edit_act_<?= $u['id'] ?>" <?= $u['is_active'] ? 'checked' : '' ?> <?= $u['id'] === $currentUser['id'] ? 'disabled' : '' ?> style="width:16px; height:16px;">
                            <label for="edit_act_<?= $u['id'] ?>" style="margin-bottom:0; font-size:12px; cursor:pointer;">Akun Berstatus Aktif</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Batal</button>
                        <button type="submit" class="btn btn-primary btn-sm">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal Reset Password -->
        <div id="modal-reset-pwd-<?= $u['id'] ?>" class="modal-backdrop">
            <div class="modal-dialog modal-sm">
                <div class="modal-header">
                    <h3 class="modal-title">🔑 Reset Kata Sandi</h3>
                    <button type="button" class="modal-close" data-modal-close>&times;</button>
                </div>
                <form action="index.php?page=users/index" method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <div class="modal-body">
                        <p style="font-size:12px; color:#64748b; margin-bottom:10px;">
                            Pengguna: <strong><?= e($u['username']) ?></strong> (<?= e($u['full_name']) ?>)
                        </p>
                        <div class="form-group mb-0">
                            <label class="form-label" style="font-size:11.5px;">Kata Sandi Baru *</label>
                            <input type="password" name="new_password" class="form-control" placeholder="Min. 6 karakter" required minlength="6">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Batal</button>
                        <button type="submit" class="btn btn-primary btn-sm">Update Kata Sandi</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
