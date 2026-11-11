<?php
/**
 * Modul SDM & Penggajian: Tambah Karyawan Baru
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Tambah Karyawan Baru';
$breadcrumbCategory = 'SDM & Penggajian';

$db = getDbConnection();
$user = currentUser();

if (!hasRole(['Administrator', 'HR / Payroll', 'admin', 'hr'])) {
    setFlash('error', 'Anda tidak memiliki hak akses untuk menambah karyawan baru.');
    header('Location: index.php?page=hr_payroll/employees');
    exit;
}

// Fetch departments & positions
$departments = $db->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$positions = $db->query("SELECT id, name, department_id FROM positions WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// Fetch unlinked users
$unlinkedUsers = $db->query("
    SELECT u.id, u.username, u.full_name, r.role_name
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN employees e ON e.user_id = u.id
    WHERE e.id IS NULL AND u.is_active = 1
    ORDER BY u.full_name ASC
")->fetchAll();

// Generate next employee code
$lastEmpCode = $db->query("SELECT code FROM employees WHERE code LIKE 'EMP-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
$nextSeq = 1;
if ($lastEmpCode && preg_match('/EMP-(\d+)/', $lastEmpCode, $m)) {
    $nextSeq = (int)$m[1] + 1;
}
$defaultCode = sprintf('EMP-%03d', $nextSeq);

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $code = trim($_POST['code'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $departmentId = (int)($_POST['department_id'] ?? 0);
    $positionId = (int)($_POST['position_id'] ?? 0);
    $joinDate = trim($_POST['join_date'] ?? date('Y-m-d'));
    $baseSalary = (float)($_POST['base_salary'] ?? 0);
    $fixedAllowance = (float)($_POST['fixed_allowance'] ?? 0);
    $birthDate = !empty($_POST['birth_date']) ? trim($_POST['birth_date']) : null;
    $ptkpCode = trim($_POST['ptkp_code'] ?? 'TK/0');
    $bpjsTk = trim($_POST['bpjs_tk_number'] ?? '');
    $bpjsKes = trim($_POST['bpjs_kes_number'] ?? '');
    $employmentStatus = trim($_POST['employment_status'] ?? 'Tetap');
    $bankName = trim($_POST['bank_name'] ?? '');
    $bankAccount = trim($_POST['bank_account'] ?? '');
    $status = trim($_POST['status'] ?? 'active');
    $linkUserId = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;

    $errors = [];

    if (empty($code)) {
        $errors[] = 'NIK / Kode Karyawan wajib diisi.';
    }
    if (empty($fullName)) {
        $errors[] = 'Nama lengkap karyawan wajib diisi.';
    }
    if ($departmentId <= 0) {
        $errors[] = 'Departemen wajib dipilih.';
    }
    if ($positionId <= 0) {
        $errors[] = 'Jabatan wajib dipilih.';
    }
    if (empty($joinDate)) {
        $errors[] = 'Tanggal bergabung wajib diisi.';
    }
    if ($baseSalary < 0) {
        $errors[] = 'Gaji pokok tidak boleh bernilai negatif.';
    }

    // Check duplicate code
    $stmtDup = $db->prepare("SELECT id FROM employees WHERE code = :code LIMIT 1");
    $stmtDup->execute([':code' => $code]);
    if ($stmtDup->fetchColumn()) {
        $errors[] = "NIK / Kode Karyawan '{$code}' sudah digunakan.";
    }

    if (empty($errors)) {
        try {
            $stmtInsert = $db->prepare("
                INSERT INTO employees 
                (code, full_name, department_id, position_id, join_date, base_salary, fixed_allowance, birth_date, ptkp_code, bpjs_tk_number, bpjs_kes_number, employment_status, bank_name, bank_account, status, user_id, created_by, created_at)
                VALUES 
                (:code, :name, :dept_id, :pos_id, :j_date, :salary, :allowance, :b_date, :ptkp, :bpjs_tk, :bpjs_kes, :emp_status, :b_name, :b_acc, :status, :u_id, :uid, NOW())
            ");
            $stmtInsert->execute([
                ':code'        => $code,
                ':name'        => $fullName,
                ':dept_id'     => $departmentId,
                ':pos_id'      => $positionId,
                ':j_date'      => $joinDate,
                ':salary'      => $baseSalary,
                ':allowance'   => $fixedAllowance,
                ':b_date'      => $birthDate,
                ':ptkp'        => $ptkpCode,
                ':bpjs_tk'     => $bpjsTk ?: null,
                ':bpjs_kes'    => $bpjsKes ?: null,
                ':emp_status'  => $employmentStatus,
                ':b_name'      => $bankName ?: null,
                ':b_acc'       => $bankAccount ?: null,
                ':status'      => $status,
                ':u_id'        => $linkUserId,
                ':uid'         => $user['id'],
            ]);
            $empId = $db->lastInsertId();

            logAudit($user['id'], 'create_employee', 'employees', $empId, null, [
                'code'      => $code,
                'full_name' => $fullName,
                'salary'    => $baseSalary,
            ]);

            setFlash('success', "Karyawan baru {$fullName} ({$code}) berhasil ditambahkan ke database.");
            header("Location: index.php?page=hr_payroll/employee_detail&id={$empId}");
            exit;
        } catch (Exception $e) {
            $errors[] = 'Gagal menyimpan data karyawan: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Tambah Karyawan Baru</h1>
        <div class="page-subtitle">Siklus SDM &bull; Registrasi Data Karyawan, Departemen, Jabatan, dan Rekening Payroll</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=hr_payroll/employees" class="btn btn-secondary">
            &larr; Kembali ke Daftar Karyawan
        </a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul style="margin:0; padding-left:20px;">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form action="index.php?page=hr_payroll/employee_create" method="POST">
    <?= csrfField() ?>
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">Formulir Biodata Karyawan</h2>
        </div>
        <div class="card-body">
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:15px;">
                <div class="form-group">
                    <label>NIK / Kode Karyawan *</label>
                    <input type="text" name="code" value="<?= e($_POST['code'] ?? $defaultCode) ?>" required>
                </div>
                <div class="form-group">
                    <label>Nama Lengkap &amp; Gelar *</label>
                    <input type="text" name="full_name" value="<?= e($_POST['full_name'] ?? '') ?>" placeholder="Misal: Ahmad Fauzi, ST" required>
                </div>
                <div class="form-group">
                    <label>Departemen / Divisi *</label>
                    <select name="department_id" required>
                        <option value="">-- Pilih Departemen --</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= (int)($_POST['department_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>>
                                <?= e($d['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Jabatan / Posisi Kerja *</label>
                    <select name="position_id" required>
                        <option value="">-- Pilih Jabatan --</option>
                        <?php foreach ($positions as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= (int)($_POST['position_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= e($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tanggal Bergabung (Join Date) *</label>
                    <input type="date" name="join_date" value="<?= e($_POST['join_date'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="form-group">
                    <label>Gaji Pokok Bulanan (Rp) *</label>
                    <input type="number" step="0.01" min="0" name="base_salary" value="<?= e($_POST['base_salary'] ?? '6500000') ?>" required>
                </div>
                <div class="form-group">
                    <label>Tunjangan Tetap (Rp)</label>
                    <input type="number" step="0.01" min="0" name="fixed_allowance" value="<?= e($_POST['fixed_allowance'] ?? '1000000') ?>">
                </div>
                <div class="form-group">
                    <label>Tanggal Lahir</label>
                    <input type="date" name="birth_date" value="<?= e($_POST['birth_date'] ?? '1995-01-01') ?>">
                </div>
                <div class="form-group">
                    <label>Status Perjanjian Kerja *</label>
                    <select name="employment_status" required>
                        <option value="Tetap" <?= ($_POST['employment_status'] ?? 'Tetap') === 'Tetap' ? 'selected' : '' ?>>Karyawan Tetap (PKWTT)</option>
                        <option value="Kontrak" <?= ($_POST['employment_status'] ?? '') === 'Kontrak' ? 'selected' : '' ?>>Karyawan Kontrak (PKWT)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Kode PTKP (Pajak)</label>
                    <select name="ptkp_code">
                        <?php foreach (['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3'] as $pk): ?>
                            <option value="<?= $pk ?>" <?= ($_POST['ptkp_code'] ?? 'TK/0') === $pk ? 'selected' : '' ?>><?= $pk ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>No. BPJS Ketenagakerjaan</label>
                    <input type="text" name="bpjs_tk_number" value="<?= e($_POST['bpjs_tk_number'] ?? '') ?>" placeholder="Nomor BPJSTK (11 digit)">
                </div>
                <div class="form-group">
                    <label>No. BPJS Kesehatan</label>
                    <input type="text" name="bpjs_kes_number" value="<?= e($_POST['bpjs_kes_number'] ?? '') ?>" placeholder="Nomor BPJS Kesehatan (13 digit)">
                </div>
                <div class="form-group">
                    <label>Nama Bank Payroll</label>
                    <input type="text" name="bank_name" value="<?= e($_POST['bank_name'] ?? 'Bank Mandiri') ?>" placeholder="Bank BCA / Bank Mandiri">
                </div>
                <div class="form-group">
                    <label>Nomor Rekening Bank</label>
                    <input type="text" name="bank_account" value="<?= e($_POST['bank_account'] ?? '') ?>" placeholder="Nomor rekening penerima gaji">
                </div>
                <div class="form-group">
                    <label>Status Kepegawaian</label>
                    <select name="status">
                        <option value="active">Aktif Bekerja</option>
                        <option value="inactive">Non-Aktif / Cuti Panjang</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tautkan ke Akun Sistem (User Login)</label>
                    <select name="user_id">
                        <option value="">-- Belum Memiliki Akun Login --</option>
                        <?php foreach ($unlinkedUsers as $uu): ?>
                            <option value="<?= $uu['id'] ?>">
                                <?= e($uu['full_name']) ?> (<?= e($uu['username']) ?> &bull; <?= e($uu['role_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="card-footer" style="padding:15px 20px; background:#fff; display:flex; justify-content:flex-end; gap:10px;">
            <a href="index.php?page=hr_payroll/employees" class="btn btn-secondary">Batal</a>
            <button type="submit" class="btn btn-primary">
                Simpan Data Karyawan &rarr;
            </button>
        </div>
    </div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
