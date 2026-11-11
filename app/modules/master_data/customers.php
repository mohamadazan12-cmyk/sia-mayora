<?php
/**
 * Data Master: Pelanggan & Distributor (Master Customers)
 * PT Mayora Indah Tbk
 */

$pageTitle = 'Master Pelanggan / Distributor';
$breadcrumbCategory = 'Data Master';

$db = getDbConnection();
$user = currentUser();

$search = trim($_GET['q'] ?? '');
$creditStatusFilter = trim($_GET['credit_status'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $action = $_POST['action'] ?? '';

    // 1. Save (Create or Update) Customer
    if ($action === 'save_customer') {
        if (!hasRole(['Administrator', 'admin', 'penjualan', 'finance'])) {
            setFlash('error', 'Anda tidak memiliki hak akses untuk mengubah data pelanggan.');
            header('Location: index.php?page=master_data/customers');
            exit;
        }

        $custId = (int)($_POST['customer_id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $npwp = trim($_POST['npwp'] ?? '');
        $creditLimit = (float)($_POST['credit_limit'] ?? 0);
        $paymentTermsDays = max(0, (int)($_POST['payment_terms_days'] ?? 30));
        $creditStatus = trim($_POST['credit_status'] ?? 'active');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (!in_array($creditStatus, ['active', 'warning', 'blocked'])) {
            $creditStatus = 'active';
        }

        if (empty($code) || empty($name)) {
            setFlash('error', 'Kode dan nama pelanggan wajib diisi.');
            header('Location: index.php?page=master_data/customers' . ($custId > 0 ? "&edit_id={$custId}" : ''));
            exit;
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Format alamat email pelanggan tidak valid.');
            header('Location: index.php?page=master_data/customers' . ($custId > 0 ? "&edit_id={$custId}" : ''));
            exit;
        }

        try {
            if ($custId > 0) {
                // Check duplicate code excluding current
                $stmtDup = $db->prepare("SELECT id FROM customers WHERE code = :code AND id != :id");
                $stmtDup->execute([':code' => $code, ':id' => $custId]);
                if ($stmtDup->fetchColumn()) {
                    throw new Exception("Kode pelanggan '{$code}' sudah digunakan oleh pelanggan lain.");
                }

                // Old data for audit
                $stmtOld = $db->prepare("SELECT * FROM customers WHERE id = :id");
                $stmtOld->execute([':id' => $custId]);
                $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

                if (!$oldData) {
                    throw new Exception("Data pelanggan tidak ditemukan.");
                }

                $stmtUpd = $db->prepare("
                    UPDATE customers 
                    SET code = :code, name = :name, contact_person = :cp, email = :email, phone = :phone, 
                        address = :addr, npwp = :npwp, credit_limit = :clim, credit_status = :cstat,
                        payment_terms_days = :terms, is_active = :active, 
                        updated_by = :uid, updated_at = NOW()
                    WHERE id = :id
                ");
                $stmtUpd->execute([
                    ':code'   => $code,
                    ':name'   => $name,
                    ':cp'     => $contactPerson ?: null,
                    ':email'  => $email ?: null,
                    ':phone'  => $phone ?: null,
                    ':addr'   => $address ?: null,
                    ':npwp'   => $npwp ?: null,
                    ':clim'   => $creditLimit,
                    ':cstat'  => $creditStatus,
                    ':terms'  => $paymentTermsDays,
                    ':active' => $isActive,
                    ':uid'    => $user['id'],
                    ':id'     => $custId,
                ]);

                logAudit($user['id'], 'update_customer', 'customers', $custId, $oldData, [
                    'code' => $code, 'name' => $name, 'contact_person' => $contactPerson,
                    'email' => $email, 'phone' => $phone, 'credit_limit' => $creditLimit,
                    'credit_status' => $creditStatus, 'payment_terms_days' => $paymentTermsDays,
                    'is_active' => $isActive
                ]);

                setFlash('success', "Data pelanggan {$name} ({$code}) berhasil diperbarui.");
            } else {
                // Check duplicate code
                $stmtDup = $db->prepare("SELECT id FROM customers WHERE code = :code");
                $stmtDup->execute([':code' => $code]);
                if ($stmtDup->fetchColumn()) {
                    throw new Exception("Kode pelanggan '{$code}' sudah terdaftar.");
                }

                $stmtIns = $db->prepare("
                    INSERT INTO customers (code, name, contact_person, email, phone, address, npwp, credit_limit, credit_used, credit_status, payment_terms_days, is_active, created_by, updated_by, created_at, updated_at)
                    VALUES (:code, :name, :cp, :email, :phone, :addr, :npwp, :clim, 0.00, :cstat, :terms, :active, :uid, :uid, NOW(), NOW())
                ");
                $stmtIns->execute([
                    ':code'   => $code,
                    ':name'   => $name,
                    ':cp'     => $contactPerson ?: null,
                    ':email'  => $email ?: null,
                    ':phone'  => $phone ?: null,
                    ':addr'   => $address ?: null,
                    ':npwp'   => $npwp ?: null,
                    ':clim'   => $creditLimit,
                    ':cstat'  => $creditStatus,
                    ':terms'  => $paymentTermsDays,
                    ':active' => $isActive,
                    ':uid'    => $user['id'],
                ]);
                $newId = $db->lastInsertId();

                logAudit($user['id'], 'create_customer', 'customers', $newId, null, [
                    'code' => $code, 'name' => $name, 'credit_limit' => $creditLimit, 'is_active' => $isActive
                ]);

                setFlash('success', "Pelanggan baru {$name} ({$code}) berhasil ditambahkan.");
            }

            header('Location: index.php?page=master_data/customers');
            exit;
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
            header('Location: index.php?page=master_data/customers' . ($custId > 0 ? "&edit_id={$custId}" : ''));
            exit;
        }
    }

    // 2. Toggle Status (Active / Deactivate)
    if ($action === 'toggle_status') {
        if (!hasRole(['Administrator', 'admin', 'penjualan', 'finance'])) {
            setFlash('error', 'Akses ditolak.');
            header('Location: index.php?page=master_data/customers');
            exit;
        }

        $custId = (int)($_POST['customer_id'] ?? 0);
        $stmtC = $db->prepare("SELECT * FROM customers WHERE id = :id");
        $stmtC->execute([':id' => $custId]);
        $customer = $stmtC->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            setFlash('error', 'Data pelanggan tidak ditemukan.');
            header('Location: index.php?page=master_data/customers');
            exit;
        }

        $newStatus = $customer['is_active'] ? 0 : 1;
        $statusLabel = $newStatus ? 'diaktifkan' : 'dinonaktifkan';

        $stmtUpd = $db->prepare("UPDATE customers SET is_active = :act, updated_by = :uid, updated_at = NOW() WHERE id = :id");
        $stmtUpd->execute([':act' => $newStatus, ':uid' => $user['id'], ':id' => $custId]);

        logAudit($user['id'], 'toggle_customer_status', 'customers', $custId, ['is_active' => $customer['is_active']], ['is_active' => $newStatus]);

        setFlash('success', "Pelanggan {$customer['name']} ({$customer['code']}) berhasil {$statusLabel}.");
        header('Location: index.php?page=master_data/customers');
        exit;
    }

    // 3. Delete Customer
    if ($action === 'delete_customer') {
        if (!hasRole(['Administrator', 'admin', 'penjualan'])) {
            setFlash('error', 'Hanya Administrator dan Bagian Penjualan yang berwenang menghapus data pelanggan.');
            header('Location: index.php?page=master_data/customers');
            exit;
        }

        $custId = (int)($_POST['customer_id'] ?? 0);
        $stmtC = $db->prepare("SELECT * FROM customers WHERE id = :id");
        $stmtC->execute([':id' => $custId]);
        $customer = $stmtC->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            setFlash('error', 'Data pelanggan tidak ditemukan.');
            header('Location: index.php?page=master_data/customers');
            exit;
        }

        // Check foreign key dependencies
        $references = checkRecordReferences('customers', $custId, $db);
        if (!empty($references)) {
            setFlash('error', "Pelanggan {$customer['name']} tidak dapat dihapus karena masih terkait dengan data transaksi: " . implode(', ', $references) . ". Silakan nonaktifkan status pelanggan.");
            header('Location: index.php?page=master_data/customers');
            exit;
        }

        try {
            $stmtDel = $db->prepare("DELETE FROM customers WHERE id = :id");
            $stmtDel->execute([':id' => $custId]);

            logAudit($user['id'], 'delete_customer', 'customers', $custId, $customer, null);

            setFlash('success', "Pelanggan {$customer['name']} ({$customer['code']}) berhasil dihapus permanen.");
            header('Location: index.php?page=master_data/customers');
            exit;
        } catch (Exception $e) {
            setFlash('error', 'Gagal menghapus pelanggan: ' . $e->getMessage());
            header('Location: index.php?page=master_data/customers');
            exit;
        }
    }
}

// Search and filter
$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(code LIKE :q OR name LIKE :q OR contact_person LIKE :q OR phone LIKE :q OR email LIKE :q)';
    $params[':q'] = "%{$search}%";
}

if ($creditStatusFilter !== '') {
    $where[] = 'credit_status = :cstat';
    $params[':cstat'] = $creditStatusFilter;
}

if ($statusFilter === 'active') {
    $where[] = 'is_active = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 'is_active = 0';
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

// List query with transaction stats
$stmt = $db->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM sales_orders so WHERE so.customer_id = c.id) AS so_count,
           (SELECT COUNT(*) FROM delivery_orders do_t JOIN sales_orders so_do ON do_t.sales_order_id = so_do.id WHERE so_do.customer_id = c.id) AS do_count,
           (SELECT COUNT(*) FROM sales_invoices si WHERE si.customer_id = c.id) AS si_count
    FROM customers c
    WHERE {$whereSql}
    ORDER BY c.name ASC
    LIMIT :offset, :per_page
");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Edit customer check
$editCust = null;
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    $stmtE = $db->prepare("SELECT * FROM customers WHERE id = :id");
    $stmtE->execute([':id' => $editId]);
    $editCust = $stmtE->fetch(PDO::FETCH_ASSOC);
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Master Pelanggan &amp; Distributor</h1>
        <div class="page-subtitle">Data Master &bull; Daftar Rekanan Bisnis, Plafon Batas Kredit, dan Termin Pembayaran PT Mayora Indah Tbk</div>
    </div>
    <div class="page-actions">
        <?php if ($editCust): ?>
            <a href="index.php?page=master_data/customers" class="btn btn-secondary">&laquo; Batalkan Edit</a>
        <?php endif; ?>
    </div>
</div>

<!-- Add / Edit Collapsible Form -->
<?php if (hasRole(['Administrator', 'admin', 'penjualan', 'finance'])): ?>
<div class="card mb-4">
    <details <?= $editCust ? 'open' : '' ?>>
        <summary style="cursor:pointer; padding:15px 20px; font-weight:600; font-size:15px; color:#0284c7; background:#f8fafc; border-radius:6px; list-style:none; display:flex; justify-content:space-between; align-items:center;">
            <span><?= $editCust ? '✏️ Edit Pelanggan: ' . e($editCust['name']) . ' (' . e($editCust['code']) . ')' : '+ Tambah Pelanggan / Distributor Baru' ?></span>
            <span class="btn btn-sm btn-primary"><?= $editCust ? 'Tutup Form Edit' : 'Buka Formulir Input &darr;' ?></span>
        </summary>
        <div style="padding:20px; border-top:1px solid #e2e8f0;">
            <form action="index.php?page=master_data/customers" method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_customer">
                <input type="hidden" name="customer_id" value="<?= $editCust['id'] ?? 0 ?>">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:15px;">
                    <div class="form-group">
                        <label>Kode Pelanggan *</label>
                        <input type="text" name="code" value="<?= e($editCust['code'] ?? '') ?>" placeholder="Misal: CUST-006" required>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Nama Perusahaan / Distributor *</label>
                        <input type="text" name="name" value="<?= e($editCust['name'] ?? '') ?>" placeholder="Misal: PT Retail Nusantara Mandiri" required>
                    </div>
                    <div class="form-group">
                        <label>Kontak Person (PIC)</label>
                        <input type="text" name="contact_person" value="<?= e($editCust['contact_person'] ?? '') ?>" placeholder="Misal: Ibu Dewi Lestari">
                    </div>
                    <div class="form-group">
                        <label>Email Kontak / Purchasing</label>
                        <input type="email" name="email" value="<?= e($editCust['email'] ?? '') ?>" placeholder="purchasing@distributor.co.id">
                    </div>
                    <div class="form-group">
                        <label>Nomor Telepon / WhatsApp</label>
                        <input type="text" name="phone" value="<?= e($editCust['phone'] ?? '') ?>" placeholder="021-89901234">
                    </div>
                    <div class="form-group">
                        <label>NPWP Pelanggan / Distributor</label>
                        <input type="text" name="npwp" value="<?= e($editCust['npwp'] ?? '') ?>" placeholder="01.234.567.8-901.000">
                    </div>
                    <div class="form-group">
                        <label>Plafon Batas Kredit (Credit Limit Rp) *</label>
                        <input type="number" step="0.01" min="0" name="credit_limit" value="<?= (float)($editCust['credit_limit'] ?? 100000000) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Termin Pembayaran (Hari Kalender)</label>
                        <input type="number" name="payment_terms_days" min="0" max="365" value="<?= (int)($editCust['payment_terms_days'] ?? 30) ?>" placeholder="30">
                        <small class="text-muted" style="font-size:11px;">Jangka waktu jatuh tempo faktur penjualan (TOP)</small>
                    </div>
                    <div class="form-group">
                        <label>Status Kredit</label>
                        <select name="credit_status">
                            <option value="active" <?= ($editCust['credit_status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active (Kredit Lancar)</option>
                            <option value="warning" <?= ($editCust['credit_status'] ?? '') === 'warning' ? 'selected' : '' ?>>Warning (Peringatan Batas Limit)</option>
                            <option value="blocked" <?= ($editCust['credit_status'] ?? '') === 'blocked' ? 'selected' : '' ?>>Blocked (Kredit Ditangguhkan / Macet)</option>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Alamat Pengiriman / Kantor</label>
                        <textarea name="address" rows="2" placeholder="Alamat lengkap lokasi pengiriman distributor"><?= e($editCust['address'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group" style="display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" name="is_active" value="1" id="cust_active" <?= (!isset($editCust['is_active']) || $editCust['is_active']) ? 'checked' : '' ?> style="width:18px; height:18px;">
                        <label for="cust_active" style="margin-bottom:0; font-weight:600; cursor:pointer;">Status Rekanan Aktif (Dapat Menerbitkan Sales Order)</label>
                    </div>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:15px;">
                    <?php if ($editCust): ?>
                        <a href="index.php?page=master_data/customers" class="btn btn-secondary">Batal Edit</a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">
                        <?= $editCust ? '💾 Simpan Perubahan Pelanggan' : '+ Simpan Pelanggan Baru' ?>
                    </button>
                </div>
            </form>
        </div>
    </details>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="master_data/customers">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari kode, nama, kontak, telepon..." style="min-width:250px;">
    </div>
    <div class="filter-group">
        <select name="credit_status">
            <option value="">-- Semua Status Kredit --</option>
            <option value="active" <?= $creditStatusFilter === 'active' ? 'selected' : '' ?>>Active (Lancar)</option>
            <option value="warning" <?= $creditStatusFilter === 'warning' ? 'selected' : '' ?>>Warning (Peringatan)</option>
            <option value="blocked" <?= $creditStatusFilter === 'blocked' ? 'selected' : '' ?>>Blocked (Ditangguhkan)</option>
        </select>
    </div>
    <div class="filter-group">
        <select name="status">
            <option value="">-- Semua Status Aktif --</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Hanya Aktif</option>
            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Hanya Non-Aktif</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $creditStatusFilter !== '' || $statusFilter !== ''): ?>
        <a href="index.php?page=master_data/customers" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<!-- Table of Customers -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Pelanggan</th>
                    <th>Kontak Person</th>
                    <th>Telepon / Email</th>
                    <th class="text-right">Batas Kredit (Limit)</th>
                    <th class="text-right">Kredit Terpakai</th>
                    <th class="text-right">Sisa Kredit</th>
                    <th class="text-center">Termin</th>
                    <th class="text-center">Status Kredit</th>
                    <th class="text-center">Status</th>
                    <th class="text-center" style="width:180px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="11" class="text-center" style="padding:30px; color:#64748b;">
                            Tidak ada data pelanggan yang sesuai kriteria pencarian.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): ?>
                        <?php 
                            $limit = (float)$c['credit_limit'];
                            $used = (float)$c['credit_used'];
                            $avail = max(0, $limit - $used);
                        ?>
                        <tr>
                            <td><strong><?= e($c['code']) ?></strong></td>
                            <td>
                                <strong><?= e($c['name']) ?></strong>
                                <div class="text-muted" style="font-size:11px;">
                                    SO: <?= (int)$c['so_count'] ?> &bull; DO: <?= (int)$c['do_count'] ?> &bull; Inv: <?= (int)$c['si_count'] ?>
                                </div>
                            </td>
                            <td><?= e($c['contact_person'] ?: '-') ?></td>
                            <td>
                                <div><?= e($c['phone'] ?: '-') ?></div>
                                <small class="text-muted"><?= e($c['email'] ?: '') ?></small>
                            </td>
                            <td class="text-right font-medium"><?= formatRupiah($limit) ?></td>
                            <td class="text-right font-medium" style="color:#ea580c;"><?= formatRupiah($used) ?></td>
                            <td class="text-right font-bold" style="color: <?= $avail > 0 ? '#16a34a' : '#dc2626' ?>;">
                                <?= formatRupiah($avail) ?>
                            </td>
                            <td class="text-center"><?= (int)$c['payment_terms_days'] ?> Hari</td>
                            <td class="text-center">
                                <?php if ($c['credit_status'] === 'active'): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php elseif ($c['credit_status'] === 'warning'): ?>
                                    <span class="badge badge-warning">Warning</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Blocked</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?= $c['is_active'] ? '<span class="badge badge-success">Aktif</span>' : '<span class="badge badge-danger">Non-Aktif</span>' ?></td>
                            <td class="text-center">
                                <div class="btn-group">
                                    <!-- Detail Modal Trigger -->
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="openModal('modal-detail-cust-<?= $c['id'] ?>')" title="Lihat Rincian Pelanggan">
                                        👁️ Detail
                                    </button>

                                    <!-- Edit Trigger -->
                                    <?php if (hasRole(['Administrator', 'admin', 'penjualan', 'finance'])): ?>
                                        <a href="index.php?page=master_data/customers&edit_id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit Data Pelanggan">
                                            ✏️ Edit
                                        </a>

                                        <!-- Toggle Status Trigger -->
                                        <form action="index.php?page=master_data/customers" method="POST" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin <?= $c['is_active'] ? 'menonaktifkan' : 'mengaktifkan' ?> pelanggan <?= e(addslashes($c['name'])) ?>?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $c['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $c['is_active'] ? 'Nonaktifkan Pelanggan' : 'Aktifkan Pelanggan' ?>">
                                                <?= $c['is_active'] ? '⏸️' : '▶️' ?>
                                            </button>
                                        </form>

                                        <!-- Delete Trigger -->
                                        <?php if (hasRole(['Administrator', 'admin', 'penjualan'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="openModal('modal-delete-cust-<?= $c['id'] ?>')" title="Hapus Pelanggan">
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
            <?= renderPagination($pagination, 'index.php?page=master_data/customers&q=' . urlencode($search) . '&credit_status=' . urlencode($creditStatusFilter) . '&status=' . urlencode($statusFilter)) ?>
        </div>
    <?php endif; ?>
</div>

<!-- ========================================================================= -->
<!-- MODALS PLACED OUTSIDE TABLE (Valid HTML5 DOM)                             -->
<!-- ========================================================================= -->
<?php if (!empty($customers)): ?>
    <?php foreach ($customers as $c): ?>
        <?php 
            $limit = (float)$c['credit_limit'];
            $used = (float)$c['credit_used'];
            $avail = max(0, $limit - $used);
        ?>
        <!-- Modal Detail Customer -->
        <div id="modal-detail-cust-<?= $c['id'] ?>" class="modal-backdrop">
            <div class="modal-dialog">
                <div class="modal-header">
                    <h3 class="modal-title">👁️ Detail Pelanggan: <?= e($c['name']) ?></h3>
                    <button type="button" class="modal-close" data-modal-close>&times;</button>
                </div>
                <div class="modal-body">
                    <table class="detail-table">
                        <tr>
                            <th>Kode Pelanggan</th>
                            <td><strong><?= e($c['code']) ?></strong></td>
                        </tr>
                        <tr>
                            <th>Nama Perusahaan</th>
                            <td><?= e($c['name']) ?></td>
                        </tr>
                        <tr>
                            <th>Kontak Person (PIC)</th>
                            <td><?= e($c['contact_person'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <th>Nomor Telepon</th>
                            <td><?= e($c['phone'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <th>Email</th>
                            <td><?= e($c['email'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <th>NPWP</th>
                            <td><?= e($c['npwp'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <th>Alamat Pengiriman</th>
                            <td><?= nl2br(e($c['address'] ?: '-')) ?></td>
                        </tr>
                        <tr>
                            <th>Plafon Batas Kredit</th>
                            <td><strong style="color:#0284c7;"><?= formatRupiah($limit) ?></strong></td>
                        </tr>
                        <tr>
                            <th>Kredit Terpakai (Piutang)</th>
                            <td><strong style="color:#ea580c;"><?= formatRupiah($used) ?></strong></td>
                        </tr>
                        <tr>
                            <th>Sisa Plafon Kredit Tersedia</th>
                            <td><strong style="color: <?= $avail > 0 ? '#16a34a' : '#dc2626' ?>;"><?= formatRupiah($avail) ?></strong></td>
                        </tr>
                        <tr>
                            <th>Termin Pembayaran (TOP)</th>
                            <td><?= (int)$c['payment_terms_days'] ?> Hari Kalender</td>
                        </tr>
                        <tr>
                            <th>Status Kredit</th>
                            <td>
                                <?php if ($c['credit_status'] === 'active'): ?>
                                    <span class="bd ok">Active (Lancar)</span>
                                <?php elseif ($c['credit_status'] === 'warning'): ?>
                                    <span class="bd wt">Warning (Peringatan)</span>
                                <?php else: ?>
                                    <span class="bd no">Blocked (Diblokir)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Status Rekanan</th>
                            <td><?= $c['is_active'] ? '<span class="bd ok">Aktif</span>' : '<span class="bd no">Non-Aktif</span>' ?></td>
                        </tr>
                        <tr>
                            <th>Riwayat Transaksi</th>
                            <td>
                                <ul style="padding-left:18px; margin:0;">
                                    <li>Pesanan Penjualan (SO): <strong><?= (int)$c['so_count'] ?></strong> transaksi</li>
                                    <li>Surat Jalan / Delivery Order (DO): <strong><?= (int)$c['do_count'] ?></strong> dokumen</li>
                                    <li>Faktur Penjualan (Sales Invoice): <strong><?= (int)$c['si_count'] ?></strong> faktur</li>
                                </ul>
                            </td>
                        </tr>
                        <tr>
                            <th>Waktu Didaftarkan</th>
                            <td><?= formatDateTime($c['created_at']) ?></td>
                        </tr>
                        <tr>
                            <th>Pembaruan Terakhir</th>
                            <td><?= formatDateTime($c['updated_at']) ?></td>
                        </tr>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-modal-close>Tutup</button>
                    <?php if (hasRole(['Administrator', 'admin', 'penjualan', 'finance'])): ?>
                        <a href="index.php?page=master_data/customers&edit_id=<?= $c['id'] ?>" class="btn btn-primary">✏️ Edit Pelanggan Ini</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Modal Confirm Delete Customer -->
        <?php if (hasRole(['Administrator', 'admin', 'penjualan'])): ?>
        <?php $refs = checkRecordReferences('customers', $c['id'], $db); ?>
        <div id="modal-delete-cust-<?= $c['id'] ?>" class="modal-backdrop">
            <div class="modal-dialog modal-sm">
                <div class="modal-header" style="background:#fee2e2;">
                    <h3 class="modal-title" style="color:#b91c1c;">⚠️ Konfirmasi Penghapusan</h3>
                    <button type="button" class="modal-close" data-modal-close>&times;</button>
                </div>
                <div class="modal-body">
                    <p>Anda akan memproses data pelanggan:</p>
                    <div style="background:#f8fafc; padding:10px; border-radius:6px; margin-bottom:12px; font-size:13px; border:1px solid #e2e8f0;">
                        <strong><?= e($c['name']) ?></strong><br>
                        <span class="text-muted">Kode: <?= e($c['code']) ?></span>
                    </div>

                    <?php if (!empty($refs)): ?>
                        <div class="alert alert-warning" style="font-size:12px; padding:10px;">
                            <strong>Perhatian!</strong> Pelanggan ini memiliki keterkaitan data:
                            <ul style="margin:6px 0 0 16px; padding:0;">
                                <?php foreach ($refs as $r): ?>
                                    <li><?= e($r) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <p style="margin-top:6px; margin-bottom:0;">Sesuai kebijakan integritas data finansial, pelanggan yang sudah direferensikan <strong>tidak dapat dihapus permanen</strong>. Anda disarankan untuk <strong>menonaktifkannya</strong>.</p>
                        </div>
                    <?php else: ?>
                        <p style="color:#b91c1c; font-size:12.5px;">Pelanggan ini belum memiliki riwayat transaksi dan aman untuk dihapus permanen.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Batal</button>
                    <?php if (!empty($refs)): ?>
                        <form action="index.php?page=master_data/customers" method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-warning btn-sm">⏸️ Nonaktifkan Saja</button>
                        </form>
                    <?php else: ?>
                        <form action="index.php?page=master_data/customers" method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_customer">
                            <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
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
