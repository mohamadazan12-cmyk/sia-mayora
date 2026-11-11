<?php
/**
 * Modul SDM & Penggajian: Data Karyawan (Employees Directory)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Data Karyawan & Personalia';
$breadcrumbCategory = 'SDM & Penggajian';

$db = getDbConnection();
$user = currentUser();

// Handle POST: Toggle Status or Delete Employee
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (!hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])) {
        setFlash('error', 'Akses ditolak: Hanya administrator atau HR yang berwenang mengubah data karyawan.');
        header('Location: index.php?page=hr_payroll/employees');
        exit;
    }

    $action = $_POST['action'] ?? '';
    $empId = (int)($_POST['employee_id'] ?? 0);

    if ($action === 'toggle_status') {
        $newStatus = trim($_POST['new_status'] ?? 'inactive');
        try {
            $stmtOld = $db->prepare("SELECT * FROM employees WHERE id = :id");
            $stmtOld->execute([':id' => $empId]);
            $emp = $stmtOld->fetch();

            if (!$emp) {
                throw new Exception('Data karyawan tidak ditemukan.');
            }

            $db->prepare("UPDATE employees SET status = :st, updated_at = NOW(), updated_by = :uid WHERE id = :id")
               ->execute([':st' => $newStatus, ':uid' => $user['id'], ':id' => $empId]);

            logAudit($user['id'], 'toggle_status', 'employees', $empId, ['status' => $emp['status']], ['status' => $newStatus]);
            setFlash('success', "Status karyawan {$emp['full_name']} diubah menjadi " . strtoupper($newStatus) . ".");
            header('Location: index.php?page=hr_payroll/employees');
            exit;
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
            header('Location: index.php?page=hr_payroll/employees');
            exit;
        }
    } elseif ($action === 'delete_employee') {
        try {
            $stmtOld = $db->prepare("SELECT * FROM employees WHERE id = :id");
            $stmtOld->execute([':id' => $empId]);
            $emp = $stmtOld->fetch();

            if (!$emp) {
                throw new Exception('Data karyawan tidak ditemukan.');
            }

            $blockers = checkRecordReferences('employees', $empId);
            if (!empty($blockers)) {
                throw new Exception("Karyawan {$emp['full_name']} tidak dapat dihapus permanen karena memiliki data terkait (" . implode(', ', $blockers) . "). Harap ubah status menjadi Non-Aktif / Resign.");
            }

            $db->prepare("DELETE FROM employees WHERE id = :id")->execute([':id' => $empId]);
            logAudit($user['id'], 'delete', 'employees', $empId, $emp, null);
            setFlash('success', "Data karyawan {$emp['full_name']} berhasil dihapus.");
            header('Location: index.php?page=hr_payroll/employees');
            exit;
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
            header('Location: index.php?page=hr_payroll/employees');
            exit;
        }
    }
}

$search = trim($_GET['q'] ?? '');
$deptId = (int)($_GET['dept_id'] ?? 0);
$status = trim($_GET['status'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(e.full_name LIKE :q OR e.code LIKE :q OR p.name LIKE :q)';
    $params[':q'] = "%{$search}%";
}

if ($deptId > 0) {
    $where[] = 'e.department_id = :dept_id';
    $params[':dept_id'] = $deptId;
}

if ($status !== '') {
    $where[] = 'e.status = :status';
    $params[':status'] = $status;
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM employees e
    JOIN departments d ON e.department_id = d.id
    JOIN positions p ON e.position_id = p.id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

// List query
$stmt = $db->prepare("
    SELECT e.*, d.name AS department_name, p.name AS position_name, u.username
    FROM employees e
    JOIN departments d ON e.department_id = d.id
    JOIN positions p ON e.position_id = p.id
    LEFT JOIN users u ON e.user_id = u.id
    WHERE {$whereSql}
    ORDER BY e.id ASC
    LIMIT :offset, :per_page
");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$employees = $stmt->fetchAll();

// Departments list for filter
$departments = $db->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// Summary metrics
$totalActive = (int)$db->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();
$totalPayrollBase = (float)$db->query("SELECT COALESCE(SUM(base_salary), 0) FROM employees WHERE status = 'active'")->fetchColumn();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Data Karyawan &amp; Personalia</h1>
        <div class="page-subtitle">Siklus SDM &bull; Pengelolaan Informasi Pegawai, Struktur Organisasi, Departemen, dan Gaji Pokok</div>
    </div>
    <?php if (hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])): ?>
        <div class="page-actions">
            <a href="index.php?page=hr_payroll/employee_create" class="btn btn-primary">
                + Tambah Karyawan Baru
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="metrics-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
    <div class="metric-card">
        <div class="metric-value"><?= $totalActive ?></div>
        <div class="metric-label">Karyawan Aktif</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#0284c7;"><?= formatRupiah($totalPayrollBase) ?></div>
        <div class="metric-label">Total Gaji Pokok Bulanan</div>
    </div>
</div>

<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="hr_payroll/employees">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari NIK, nama, jabatan..." style="min-width: 250px;">
    </div>
    <div class="filter-group">
        <select name="dept_id">
            <option value="">-- Semua Departemen --</option>
            <?php foreach ($departments as $dept): ?>
                <option value="<?= $dept['id'] ?>" <?= $deptId === (int)$dept['id'] ? 'selected' : '' ?>>
                    <?= e($dept['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <select name="status">
            <option value="">-- Semua Status --</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Aktif</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Non-Aktif</option>
            <option value="resigned" <?= $status === 'resigned' ? 'selected' : '' ?>>Resign / Keluar</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $deptId > 0 || $status !== ''): ?>
        <a href="index.php?page=hr_payroll/employees" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>NIK / Kode</th>
                    <th>Nama Lengkap</th>
                    <th>Departemen</th>
                    <th>Jabatan</th>
                    <th>Tgl Bergabung</th>
                    <th class="text-right">Gaji Pokok (Rp)</th>
                    <th>Rekening Bank</th>
                    <th>Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($employees)): ?>
                    <tr>
                        <td colspan="9" class="text-center" style="padding: 30px; color: #64748b;">
                            Tidak ada data karyawan yang sesuai kriteria pencarian.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($employees as $emp): ?>
                        <tr>
                            <td><strong><?= e($emp['code']) ?></strong></td>
                            <td>
                                <strong><a href="index.php?page=hr_payroll/employee_detail&id=<?= $emp['id'] ?>"><?= e($emp['full_name']) ?></a></strong>
                                <?php if ($emp['username']): ?>
                                    <div class="text-muted" style="font-size:11px;">Akun: <?= e($emp['username']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($emp['department_name']) ?></td>
                            <td><?= e($emp['position_name']) ?></td>
                            <td><?= formatDate($emp['join_date']) ?></td>
                            <td class="text-right font-medium">
                                <?php if (hasRole(['Administrator', 'HR / Payroll', 'Finance/Treasury', 'admin', 'hr', 'finance'])): ?>
                                    <?= formatRupiah((float)$emp['base_salary']) ?>
                                <?php else: ?>
                                    <span class="text-muted">******</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?= e($emp['bank_name'] ?: '-') ?></div>
                                <small class="text-muted"><?= e($emp['bank_account'] ?: '') ?></small>
                            </td>
                            <td><?= statusBadge($emp['status']) ?></td>
                            <td class="text-center" style="white-space:nowrap;">
                                <a href="index.php?page=hr_payroll/employee_detail&id=<?= $emp['id'] ?>" class="btn btn-secondary btn-sm">
                                    Detail
                                </a>
                                <?php if (hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])): ?>
                                    <form action="index.php?page=hr_payroll/employees" method="POST" style="display:inline;" onsubmit="return confirm('Ubah status kepegawaian <?= e(addslashes($emp['full_name'])) ?>?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
                                        <input type="hidden" name="new_status" value="<?= $emp['status'] === 'active' ? 'inactive' : 'active' ?>">
                                        <button type="submit" class="btn btn-sm <?= $emp['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                            <?= $emp['status'] === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?>
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-danger btn-delete-emp"
                                        data-id="<?= $emp['id'] ?>"
                                        data-name="<?= e($emp['full_name']) ?>">
                                        Hapus
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($pagination['total_pages'] > 1): ?>
        <div class="pagination-container" style="display:flex; justify-content:space-between; align-items:center; padding: 12px 18px; border-top:1px solid #e2e8f0;">
            <div class="text-muted" style="font-size:12px;">
                Menampilkan <?= count($employees) ?> dari total <?= $totalRows ?> data
            </div>
            <div class="pagination" style="display:flex; gap:5px;">
                <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                    <a href="index.php?page=hr_payroll/employees&p=<?= $i ?>&q=<?= urlencode($search) ?>&dept_id=<?= $deptId ?>&status=<?= urlencode($status) ?>"
                       class="btn btn-sm <?= $i === $pagination['page'] ? 'btn-primary' : 'btn-secondary' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Hapus Karyawan -->
<div id="modalDeleteEmp" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; border-radius:8px; max-width:440px; width:90%; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:var(--danger);">Hapus Data Karyawan</h3>
        <p style="color:var(--text-muted); font-size:14px;">
            Apakah Anda yakin ingin menghapus data karyawan <strong id="deleteEmpName"></strong>?
            Sistem akan memeriksa integritas referensi riwayat penggajian dan absensi. Jika karyawan telah memiliki riwayat, sistem akan menolak penghapusan dan menyarankan penonaktifan status.
        </p>
        <form action="index.php?page=hr_payroll/employees" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_employee">
            <input type="hidden" name="employee_id" id="deleteEmpId">
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalDeleteEmp').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-danger">Hapus Karyawan</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-delete-emp').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.getElementById('deleteEmpId').value = this.dataset.id;
            document.getElementById('deleteEmpName').innerText = this.dataset.name;
            document.getElementById('modalDeleteEmp').style.display = 'flex';
        });
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
