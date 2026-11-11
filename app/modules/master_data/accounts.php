<?php
/**
 * Data Master: Bagan Akun Akuntansi / COA (Chart of Accounts)
 * PT Mayora Indah Tbk
 */

$pageTitle = 'Bagan Akun Buku Besar (Chart of Accounts)';
$breadcrumbCategory = 'Data Master';

$db = getDbConnection();
$user = currentUser();

$search = trim($_GET['q'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 25;

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $action = $_POST['action'] ?? '';

    // 1. Save (Create or Update) Account
    if ($action === 'save_account') {
        if (!hasRole(['Administrator', 'admin', 'finance'])) {
            setFlash('error', 'Akses ditolak.');
            header('Location: index.php?page=master_data/accounts');
            exit;
        }

        $accId = (int)($_POST['account_id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $type = trim($_POST['type'] ?? 'asset');
        $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (!in_array($type, ['asset', 'liability', 'equity', 'revenue', 'expense'])) {
            $type = 'asset';
        }

        if (empty($code) || empty($name)) {
            setFlash('error', 'Nomor kode akun dan nama akun wajib diisi.');
            header('Location: index.php?page=master_data/accounts' . ($accId > 0 ? "&edit_id={$accId}" : ''));
            exit;
        }

        // Prevent self as parent
        if ($accId > 0 && $parentId === $accId) {
            setFlash('error', 'Akun tidak dapat menjadi induk bagi dirinya sendiri.');
            header("Location: index.php?page=master_data/accounts&edit_id={$accId}");
            exit;
        }

        try {
            if ($accId > 0) {
                // Check duplicate code
                $stmtDup = $db->prepare("SELECT id FROM accounts WHERE code = :code AND id != :id");
                $stmtDup->execute([':code' => $code, ':id' => $accId]);
                if ($stmtDup->fetchColumn()) {
                    throw new Exception("Kode akun '{$code}' sudah digunakan oleh akun lain.");
                }

                $stmtOld = $db->prepare("SELECT * FROM accounts WHERE id = :id");
                $stmtOld->execute([':id' => $accId]);
                $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

                if (!$oldData) {
                    throw new Exception("Data akun tidak ditemukan.");
                }

                $stmtUpd = $db->prepare("
                    UPDATE accounts 
                    SET code = :code, name = :name, type = :type, parent_id = :parent_id, 
                        is_active = :active, updated_at = NOW()
                    WHERE id = :id
                ");
                $stmtUpd->execute([
                    ':code'      => $code,
                    ':name'      => $name,
                    ':type'      => $type,
                    ':parent_id' => $parentId,
                    ':active'    => $isActive,
                    ':id'        => $accId,
                ]);

                logAudit($user['id'], 'update_account', 'accounts', $accId, $oldData, [
                    'code' => $code, 'name' => $name, 'type' => $type, 'parent_id' => $parentId, 'is_active' => $isActive
                ]);

                setFlash('success', "Akun buku besar {$code} - {$name} berhasil diperbarui.");
            } else {
                $stmtDup = $db->prepare("SELECT id FROM accounts WHERE code = :code");
                $stmtDup->execute([':code' => $code]);
                if ($stmtDup->fetchColumn()) {
                    throw new Exception("Nomor kode akun '{$code}' sudah terdaftar.");
                }

                $stmtIns = $db->prepare("
                    INSERT INTO accounts (code, name, type, parent_id, is_active, created_at, updated_at)
                    VALUES (:code, :name, :type, :parent_id, :active, NOW(), NOW())
                ");
                $stmtIns->execute([
                    ':code'      => $code,
                    ':name'      => $name,
                    ':type'      => $type,
                    ':parent_id' => $parentId,
                    ':active'    => $isActive,
                ]);
                $newId = $db->lastInsertId();

                logAudit($user['id'], 'create_account', 'accounts', $newId, null, [
                    'code' => $code, 'name' => $name, 'type' => $type, 'is_active' => $isActive
                ]);

                setFlash('success', "Akun buku besar baru {$code} - {$name} berhasil ditambahkan.");
            }

            header('Location: index.php?page=master_data/accounts');
            exit;
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
            header('Location: index.php?page=master_data/accounts' . ($accId > 0 ? "&edit_id={$accId}" : ''));
            exit;
        }
    }

    // 2. Toggle Status (Active / Inactive)
    if ($action === 'toggle_status') {
        if (!hasRole(['Administrator', 'admin', 'finance'])) {
            setFlash('error', 'Akses ditolak.');
            header('Location: index.php?page=master_data/accounts');
            exit;
        }

        $accId = (int)($_POST['account_id'] ?? 0);
        $stmtA = $db->prepare("SELECT * FROM accounts WHERE id = :id");
        $stmtA->execute([':id' => $accId]);
        $account = $stmtA->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            setFlash('error', 'Data akun tidak ditemukan.');
            header('Location: index.php?page=master_data/accounts');
            exit;
        }

        $newStatus = $account['is_active'] ? 0 : 1;
        $statusLabel = $newStatus ? 'diaktifkan' : 'dinonaktifkan';

        $stmtUpd = $db->prepare("UPDATE accounts SET is_active = :act, updated_at = NOW() WHERE id = :id");
        $stmtUpd->execute([':act' => $newStatus, ':id' => $accId]);

        logAudit($user['id'], 'toggle_account_status', 'accounts', $accId, ['is_active' => $account['is_active']], ['is_active' => $newStatus]);

        setFlash('success', "Akun {$account['code']} - {$account['name']} berhasil {$statusLabel}.");
        header('Location: index.php?page=master_data/accounts');
        exit;
    }

    // 3. Delete Account
    if ($action === 'delete_account') {
        if (!hasRole(['Administrator', 'admin', 'finance'])) {
            setFlash('error', 'Hanya Administrator dan Bagian Finance yang berwenang menghapus akun buku besar.');
            header('Location: index.php?page=master_data/accounts');
            exit;
        }

        $accId = (int)($_POST['account_id'] ?? 0);
        $stmtA = $db->prepare("SELECT * FROM accounts WHERE id = :id");
        $stmtA->execute([':id' => $accId]);
        $account = $stmtA->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            setFlash('error', 'Data akun tidak ditemukan.');
            header('Location: index.php?page=master_data/accounts');
            exit;
        }

        // Check if any child accounts exist
        $stmtChildren = $db->prepare("SELECT COUNT(*) FROM accounts WHERE parent_id = :id");
        $stmtChildren->execute([':id' => $accId]);
        $childCount = (int)$stmtChildren->fetchColumn();

        if ($childCount > 0) {
            setFlash('error', "Akun {$account['code']} - {$account['name']} tidak dapat dihapus karena memiliki {$childCount} sub-akun turunan. Pindahkan atau hapus sub-akun terlebih dahulu.");
            header('Location: index.php?page=master_data/accounts');
            exit;
        }

        // Check foreign key dependencies (journal entries, mappings, budgets, payroll)
        $references = checkRecordReferences('accounts', $accId, $db);
        if (!empty($references)) {
            setFlash('error', "Akun {$account['code']} tidak dapat dihapus karena masih tercatat pada transaksi jurnal finansial: " . implode(', ', $references) . ". Silakan nonaktifkan akun.");
            header('Location: index.php?page=master_data/accounts');
            exit;
        }

        try {
            $stmtDel = $db->prepare("DELETE FROM accounts WHERE id = :id");
            $stmtDel->execute([':id' => $accId]);

            logAudit($user['id'], 'delete_account', 'accounts', $accId, $account, null);

            setFlash('success', "Akun {$account['code']} - {$account['name']} berhasil dihapus permanen.");
            header('Location: index.php?page=master_data/accounts');
            exit;
        } catch (Exception $e) {
            setFlash('error', 'Gagal menghapus akun: ' . $e->getMessage());
            header('Location: index.php?page=master_data/accounts');
            exit;
        }
    }
}

// Search & filter
$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(acc.code LIKE :q OR acc.name LIKE :q)';
    $params[':q'] = "%{$search}%";
}

if ($typeFilter !== '') {
    $where[] = 'acc.type = :type';
    $params[':type'] = $typeFilter;
}

if ($statusFilter === 'active') {
    $where[] = 'acc.is_active = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'acc.is_active = 0';
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM accounts acc WHERE {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

// Fetch accounts with journal lines summary
$stmt = $db->prepare("
    SELECT acc.*, p.name AS parent_name, p.code AS parent_code,
           COALESCE(SUM(jel.debit), 0) AS total_debit,
           COALESCE(SUM(jel.credit), 0) AS total_credit,
           COUNT(jel.id) AS journal_line_count
    FROM accounts acc
    LEFT JOIN accounts p ON acc.parent_id = p.id
    LEFT JOIN journal_entry_lines jel ON acc.id = jel.account_id
    WHERE {$whereSql}
    GROUP BY acc.id
    ORDER BY acc.code ASC
    LIMIT :offset, :per_page
");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Parent accounts dropdown
$parentAccounts = $db->query("SELECT id, code, name FROM accounts WHERE parent_id IS NULL ORDER BY code ASC")->fetchAll(PDO::FETCH_ASSOC);

// Edit account check
$editAcc = null;
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    $stmtEA = $db->prepare("SELECT * FROM accounts WHERE id = :id");
    $stmtEA->execute([':id' => $editId]);
    $editAcc = $stmtEA->fetch(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Bagan Akun Buku Besar (Chart of Accounts)</h1>
        <div class="page-subtitle">Data Master &bull; Struktur Klasifikasi Akuntansi Finansial, Akun Kas/Bank, Persediaan, Piutang, Utang, dan Pendapatan PT Mayora Indah Tbk</div>
    </div>
    <div class="page-actions">
        <?php if ($editAcc): ?>
            <a href="index.php?page=master_data/accounts" class="btn btn-secondary">&laquo; Batalkan Edit</a>
        <?php endif; ?>
    </div>
</div>

<!-- Add / Edit Collapsible Form -->
<?php if (hasRole(['Administrator', 'admin', 'finance'])): ?>
<div class="card mb-4">
    <details <?= $editAcc ? 'open' : '' ?>>
        <summary style="cursor:pointer; padding:15px 20px; font-weight:600; font-size:15px; color:#0284c7; background:#f8fafc; border-radius:6px; list-style:none; display:flex; justify-content:space-between; align-items:center;">
            <span><?= $editAcc ? '✏️ Edit Akun: ' . e($editAcc['code']) . ' - ' . e($editAcc['name']) : '+ Tambah Akun Buku Besar Baru' ?></span>
            <span class="btn btn-sm btn-primary"><?= $editAcc ? 'Tutup Form Edit' : 'Buka Formulir Akun &darr;' ?></span>
        </summary>
        <div style="padding:20px; border-top:1px solid #e2e8f0;">
            <form action="index.php?page=master_data/accounts" method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_account">
                <input type="hidden" name="account_id" value="<?= $editAcc['id'] ?? 0 ?>">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:15px;">
                    <div class="form-group">
                        <label>Nomor Kode Akun *</label>
                        <input type="text" name="code" value="<?= e($editAcc['code'] ?? '') ?>" placeholder="Misal: 1-1140" required>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Nama Akun Buku Besar *</label>
                        <input type="text" name="name" value="<?= e($editAcc['name'] ?? '') ?>" placeholder="Misal: Kas Operasional Pabrik Sentul" required>
                    </div>
                    <div class="form-group">
                        <label>Klasifikasi Jenis Akun *</label>
                        <select name="type" required>
                            <option value="asset" <?= ($editAcc['type'] ?? '') === 'asset' ? 'selected' : '' ?>>1. Aset / Aktiva (Asset)</option>
                            <option value="liability" <?= ($editAcc['type'] ?? '') === 'liability' ? 'selected' : '' ?>>2. Kewajiban / Utang (Liability)</option>
                            <option value="equity" <?= ($editAcc['type'] ?? '') === 'equity' ? 'selected' : '' ?>>3. Ekuitas / Modal (Equity)</option>
                            <option value="revenue" <?= ($editAcc['type'] ?? '') === 'revenue' ? 'selected' : '' ?>>4. Pendapatan (Revenue)</option>
                            <option value="expense" <?= ($editAcc['type'] ?? '') === 'expense' ? 'selected' : '' ?>>5 &amp; 6. Beban / Biaya (Expense)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Akun Induk (Header Group)</label>
                        <select name="parent_id">
                            <option value="">-- Tanpa Induk (Akun Header Utama) --</option>
                            <?php foreach ($parentAccounts as $pa): ?>
                                <?php if ($editAcc && (int)$pa['id'] === (int)$editAcc['id']) continue; ?>
                                <option value="<?= $pa['id'] ?>" <?= ((int)($editAcc['parent_id'] ?? 0) === (int)$pa['id']) ? 'selected' : '' ?>>
                                    <?= e($pa['code']) ?> - <?= e($pa['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" name="is_active" value="1" id="acc_active" <?= (!isset($editAcc['is_active']) || $editAcc['is_active']) ? 'checked' : '' ?> style="width:18px; height:18px;">
                        <label for="acc_active" style="margin-bottom:0; font-weight:600; cursor:pointer;">Status Akun Aktif (Dapat Dijurnal)</label>
                    </div>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:15px;">
                    <?php if ($editAcc): ?>
                        <a href="index.php?page=master_data/accounts" class="btn btn-secondary">Batal Edit</a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">
                        <?= $editAcc ? '💾 Simpan Perubahan Akun' : '+ Simpan Akun Buku Besar' ?>
                    </button>
                </div>
            </form>
        </div>
    </details>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="master_data/accounts">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari kode akun, nama akun..." style="min-width:250px;">
    </div>
    <div class="filter-group">
        <select name="type">
            <option value="">-- Semua Jenis Akun --</option>
            <option value="asset" <?= $typeFilter === 'asset' ? 'selected' : '' ?>>1. Aset / Aktiva</option>
            <option value="liability" <?= $typeFilter === 'liability' ? 'selected' : '' ?>>2. Kewajiban / Liabilitas</option>
            <option value="equity" <?= $typeFilter === 'equity' ? 'selected' : '' ?>>3. Ekuitas / Modal</option>
            <option value="revenue" <?= $typeFilter === 'revenue' ? 'selected' : '' ?>>4. Pendapatan</option>
            <option value="expense" <?= $typeFilter === 'expense' ? 'selected' : '' ?>>5 &amp; 6. Beban / HPP / Operasional</option>
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
        <a href="index.php?page=master_data/accounts" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<!-- Table of Accounts -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Kode Akun</th>
                    <th>Nama Akun Buku Besar</th>
                    <th>Tipe Akun</th>
                    <th>Induk (Parent)</th>
                    <th class="text-right">Mutasi Debit (Rp)</th>
                    <th class="text-right">Mutasi Kredit (Rp)</th>
                    <th class="text-right">Saldo Bersih (Rp)</th>
                    <th class="text-center">Status</th>
                    <th class="text-center" style="width:180px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($accounts)): ?>
                    <tr>
                        <td colspan="9" class="text-center" style="padding:30px; color:#64748b;">
                            Tidak ada data akun yang sesuai kriteria pencarian.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($accounts as $acc): ?>
                        <?php 
                            $isHeader = empty($acc['parent_id']);
                            $dr = (float)$acc['total_debit'];
                            $cr = (float)$acc['total_credit'];
                            // Normal balance
                            $net = in_array($acc['type'], ['asset', 'expense']) ? ($dr - $cr) : ($cr - $dr);
                        ?>
                        <tr style="<?= $isHeader ? 'background:#f8fafc; font-weight:bold;' : '' ?>">
                            <td><code><?= e($acc['code']) ?></code></td>
                            <td style="<?= $isHeader ? 'font-size:14px;' : 'padding-left:25px;' ?>">
                                <?= e($acc['name']) ?>
                            </td>
                            <td>
                                <?php if ($acc['type'] === 'asset'): ?>
                                    <span class="badge" style="background:#e0f2fe; color:#0369a1;">Aset</span>
                                <?php elseif ($acc['type'] === 'liability'): ?>
                                    <span class="badge" style="background:#fee2e2; color:#b91c1c;">Kewajiban</span>
                                <?php elseif ($acc['type'] === 'equity'): ?>
                                    <span class="badge" style="background:#f3e8ff; color:#7e22ce;">Ekuitas</span>
                                <?php elseif ($acc['type'] === 'revenue'): ?>
                                    <span class="badge" style="background:#dcfce7; color:#15803d;">Pendapatan</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#ffedd5; color:#c2410c;">Beban / Biaya</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted" style="font-size:12px;"><?= e($acc['parent_code'] ? $acc['parent_code'] . ' ' . $acc['parent_name'] : '-') ?></td>
                            <td class="text-right"><?= $dr > 0 ? formatRupiah($dr) : '-' ?></td>
                            <td class="text-right"><?= $cr > 0 ? formatRupiah($cr) : '-' ?></td>
                            <td class="text-right font-bold" style="color: <?= $net >= 0 ? '#0f172a' : '#dc2626' ?>;">
                                <?= formatRupiah($net) ?>
                            </td>
                            <td class="text-center"><?= $acc['is_active'] ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-danger">Non-Aktif</span>' ?></td>
                            <td class="text-center">
                                <div class="btn-group">
                                    <!-- Detail Modal Trigger -->
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="openModal('modal-detail-acc-<?= $acc['id'] ?>')" title="Lihat Rincian Akun">
                                        👁️ Detail
                                    </button>

                                    <!-- Edit Trigger -->
                                    <?php if (hasRole(['Administrator', 'admin', 'finance'])): ?>
                                        <a href="index.php?page=master_data/accounts&edit_id=<?= $acc['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit Akun">
                                            ✏️ Edit
                                        </a>

                                        <!-- Toggle Status Trigger -->
                                        <form action="index.php?page=master_data/accounts" method="POST" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin <?= $acc['is_active'] ? 'menonaktifkan' : 'mengaktifkan' ?> akun <?= e(addslashes($acc['name'])) ?>?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $acc['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $acc['is_active'] ? 'Nonaktifkan Akun' : 'Aktifkan Akun' ?>">
                                                <?= $acc['is_active'] ? '⏸️' : '▶️' ?>
                                            </button>
                                        </form>

                                        <!-- Delete Trigger -->
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="openModal('modal-delete-acc-<?= $acc['id'] ?>')" title="Hapus Akun">
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
            <?= renderPagination($pagination, 'index.php?page=master_data/accounts&q=' . urlencode($search) . '&type=' . urlencode($typeFilter) . '&status=' . urlencode($statusFilter)) ?>
        </div>
    <?php endif; ?>
</div>

<!-- ========================================================================= -->
<!-- MODALS PLACED OUTSIDE TABLE (Valid HTML5 DOM)                             -->
<!-- ========================================================================= -->
<?php if (!empty($accounts)): ?>
    <?php foreach ($accounts as $acc): ?>
        <?php 
            $dr = (float)$acc['total_debit'];
            $cr = (float)$acc['total_credit'];
            $net = in_array($acc['type'], ['asset', 'expense']) ? ($dr - $cr) : ($cr - $dr);
        ?>
        <!-- Modal Detail Account -->
        <div id="modal-detail-acc-<?= $acc['id'] ?>" class="modal-backdrop">
            <div class="modal-dialog">
                <div class="modal-header">
                    <h3 class="modal-title">👁️ Detail Akun: <?= e($acc['code']) ?> - <?= e($acc['name']) ?></h3>
                    <button type="button" class="modal-close" data-modal-close>&times;</button>
                </div>
                <div class="modal-body">
                    <table class="detail-table">
                        <tr>
                            <th>Nomor Kode Akun</th>
                            <td><strong><?= e($acc['code']) ?></strong></td>
                        </tr>
                        <tr>
                            <th>Nama Akun Buku Besar</th>
                            <td><?= e($acc['name']) ?></td>
                        </tr>
                        <tr>
                            <th>Klasifikasi Akun</th>
                            <td><?= strtoupper($acc['type']) ?></td>
                        </tr>
                        <tr>
                            <th>Akun Induk (Parent)</th>
                            <td><?= e($acc['parent_code'] ? $acc['parent_code'] . ' - ' . $acc['parent_name'] : 'Akun Header Utama (Level 1)') ?></td>
                        </tr>
                        <tr>
                            <th>Akumulasi Mutasi Debit</th>
                            <td><?= formatRupiah($dr) ?></td>
                        </tr>
                        <tr>
                            <th>Akumulasi Mutasi Kredit</th>
                            <td><?= formatRupiah($cr) ?></td>
                        </tr>
                        <tr>
                            <th>Saldo Bersih Saat Ini</th>
                            <td><strong style="color: <?= $net >= 0 ? '#0f172a' : '#dc2626' ?>; font-size:15px;"><?= formatRupiah($net) ?></strong></td>
                        </tr>
                        <tr>
                            <th>Jumlah Baris Jurnal Terkait</th>
                            <td><strong><?= (int)$acc['journal_line_count'] ?> baris transaksi jurnal</strong></td>
                        </tr>
                        <tr>
                            <th>Status Akun</th>
                            <td><?= $acc['is_active'] ? '<span class="bd ok">Aktif (Dapat Menerima Jurnal)</span>' : '<span class="bd no">Non-Aktif (Terkunci)</span>' ?></td>
                        </tr>
                        <tr>
                            <th>Waktu Dibuat</th>
                            <td><?= formatDateTime($acc['created_at']) ?></td>
                        </tr>
                        <tr>
                            <th>Terakhir Diperbarui</th>
                            <td><?= formatDateTime($acc['updated_at']) ?></td>
                        </tr>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-modal-close>Tutup</button>
                    <?php if (hasRole(['Administrator', 'admin', 'finance'])): ?>
                        <a href="index.php?page=master_data/accounts&edit_id=<?= $acc['id'] ?>" class="btn btn-primary">✏️ Edit Akun Ini</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Modal Confirm Delete Account -->
        <?php if (hasRole(['Administrator', 'admin', 'finance'])): ?>
        <?php 
            $refs = checkRecordReferences('accounts', $acc['id'], $db); 
            $hasJournal = (int)$acc['journal_line_count'] > 0;
        ?>
        <div id="modal-delete-acc-<?= $acc['id'] ?>" class="modal-backdrop">
            <div class="modal-dialog modal-sm">
                <div class="modal-header" style="background:#fee2e2;">
                    <h3 class="modal-title" style="color:#b91c1c;">⚠️ Konfirmasi Penghapusan Akun</h3>
                    <button type="button" class="modal-close" data-modal-close>&times;</button>
                </div>
                <div class="modal-body">
                    <p>Anda akan memproses data akun buku besar:</p>
                    <div style="background:#f8fafc; padding:10px; border-radius:6px; margin-bottom:12px; font-size:13px; border:1px solid #e2e8f0;">
                        <strong><?= e($acc['name']) ?></strong><br>
                        <span class="text-muted">Kode: <?= e($acc['code']) ?></span><br>
                        <span class="text-muted">Total Baris Jurnal: <?= (int)$acc['journal_line_count'] ?></span>
                    </div>

                    <?php if ($hasJournal || !empty($refs)): ?>
                        <div class="alert alert-warning" style="font-size:12px; padding:10px;">
                            <strong>Perhatian!</strong> Akun ini tidak dapat dihapus permanen karena:
                            <ul style="margin:6px 0 0 16px; padding:0;">
                                <?php if ($hasJournal): ?>
                                    <li>Telah mencatat <?= (int)$acc['journal_line_count'] ?> baris transaksi jurnal umum/otomatis</li>
                                <?php endif; ?>
                                <?php foreach ($refs as $r): ?>
                                    <li><?= e($r) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <p style="margin-top:6px; margin-bottom:0;">Sesuai standar akuntansi (SAK), akun yang sudah memiliki histori pembukuan <strong>tidak boleh dihapus</strong>. Disarankan untuk <strong>menonaktifkannya</strong>.</p>
                        </div>
                    <?php else: ?>
                        <p style="color:#b91c1c; font-size:12.5px;">Akun ini belum memiliki transaksi jurnal pembukuan dan aman untuk dihapus permanen.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Batal</button>
                    <?php if ($hasJournal || !empty($refs)): ?>
                        <form action="index.php?page=master_data/accounts" method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                            <button type="submit" class="btn btn-warning btn-sm">⏸️ Nonaktifkan Saja</button>
                        </form>
                    <?php else: ?>
                        <form action="index.php?page=master_data/accounts" method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_account">
                            <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
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
