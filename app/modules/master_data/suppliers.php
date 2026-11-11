<?php
/**
 * Data Master: Pemasok & Vendor (Master Suppliers)
 * PT Mayora Indah Tbk. - Sistem Informasi Akuntansi & Operasional
 */

$pageTitle = 'Master Pemasok & Vendor';
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

    // -------------------------------------------------------------------------
    // 1. Save (Create or Update) Supplier
    // -------------------------------------------------------------------------
    if ($action === 'save_supplier') {
        if (!hasRole(['Administrator', 'admin', 'purchasing', 'finance'])) {
            setFlash('error', 'Anda tidak memiliki hak akses untuk mengubah data pemasok.');
            header('Location: index.php?page=master_data/suppliers');
            exit;
        }

        $suppId = (int)($_POST['supplier_id'] ?? 0);
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $npwp = trim($_POST['npwp'] ?? '');
        $paymentTermsDays = max(0, (int)($_POST['payment_terms_days'] ?? 30));
        $bankName = trim($_POST['bank_name'] ?? '');
        $bankAccount = trim($_POST['bank_account'] ?? '');
        $bankAccountHolder = trim($_POST['bank_account_holder'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $errors = [];
        if (empty($code)) {
            $errors[] = 'Kode pemasok wajib diisi.';
        }
        if (empty($name)) {
            $errors[] = 'Nama perusahaan pemasok wajib diisi.';
        }
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Format alamat email tidak valid.';
        }

        if (!empty($errors)) {
            setFlash('error', implode(' ', $errors));
            header('Location: index.php?page=master_data/suppliers' . ($suppId > 0 ? "&edit_id={$suppId}" : ''));
            exit;
        }

        try {
            if ($suppId > 0) {
                // Check duplicate code excluding current
                $stmtDup = $db->prepare("SELECT id FROM suppliers WHERE code = :code AND id != :id");
                $stmtDup->execute([':code' => $code, ':id' => $suppId]);
                if ($stmtDup->fetchColumn()) {
                    throw new Exception("Kode pemasok '{$code}' sudah digunakan oleh pemasok lain.");
                }

                // Get old values for audit
                $stmtOld = $db->prepare("SELECT * FROM suppliers WHERE id = :id");
                $stmtOld->execute([':id' => $suppId]);
                $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

                if (!$oldData) {
                    throw new Exception("Data pemasok tidak ditemukan.");
                }

                $stmtUpd = $db->prepare("
                    UPDATE suppliers 
                    SET code = :code, name = :name, contact_person = :cp, email = :email, phone = :phone, 
                        address = :addr, npwp = :npwp, payment_terms_days = :terms,
                        bank_name = :b_name, bank_account = :b_acc, bank_account_holder = :b_holder,
                        is_active = :active, updated_by = :uid, updated_at = NOW()
                    WHERE id = :id
                ");
                $stmtUpd->execute([
                    ':code'     => $code,
                    ':name'     => $name,
                    ':cp'       => $contactPerson ?: null,
                    ':email'    => $email ?: null,
                    ':phone'    => $phone ?: null,
                    ':addr'     => $address ?: null,
                    ':npwp'     => $npwp ?: null,
                    ':terms'    => $paymentTermsDays,
                    ':b_name'   => $bankName ?: null,
                    ':b_acc'    => $bankAccount ?: null,
                    ':b_holder' => $bankAccountHolder ?: null,
                    ':active'   => $isActive,
                    ':uid'      => $user['id'],
                    ':id'       => $suppId,
                ]);

                logAudit($user['id'], 'update_supplier', 'suppliers', $suppId, $oldData, [
                    'code' => $code, 'name' => $name, 'contact_person' => $contactPerson,
                    'email' => $email, 'phone' => $phone, 'npwp' => $npwp,
                    'payment_terms_days' => $paymentTermsDays, 'bank_name' => $bankName,
                    'bank_account' => $bankAccount, 'bank_account_holder' => $bankAccountHolder,
                    'is_active' => $isActive
                ]);

                setFlash('success', "Data pemasok {$name} ({$code}) berhasil diperbarui.");
            } else {
                // Check duplicate code
                $stmtDup = $db->prepare("SELECT id FROM suppliers WHERE code = :code");
                $stmtDup->execute([':code' => $code]);
                if ($stmtDup->fetchColumn()) {
                    throw new Exception("Kode pemasok '{$code}' sudah terdaftar.");
                }

                $stmtIns = $db->prepare("
                    INSERT INTO suppliers 
                    (code, name, contact_person, email, phone, address, npwp, payment_terms_days, bank_name, bank_account, bank_account_holder, is_active, created_by, updated_by, created_at, updated_at)
                    VALUES 
                    (:code, :name, :cp, :email, :phone, :addr, :npwp, :terms, :b_name, :b_acc, :b_holder, :active, :uid, :uid, NOW(), NOW())
                ");
                $stmtIns->execute([
                    ':code'     => $code,
                    ':name'     => $name,
                    ':cp'       => $contactPerson ?: null,
                    ':email'    => $email ?: null,
                    ':phone'    => $phone ?: null,
                    ':addr'     => $address ?: null,
                    ':npwp'     => $npwp ?: null,
                    ':terms'    => $paymentTermsDays,
                    ':b_name'   => $bankName ?: null,
                    ':b_acc'    => $bankAccount ?: null,
                    ':b_holder' => $bankAccountHolder ?: null,
                    ':active'   => $isActive,
                    ':uid'      => $user['id'],
                ]);
                $newId = (int)$db->lastInsertId();

                logAudit($user['id'], 'create_supplier', 'suppliers', $newId, null, [
                    'code' => $code, 'name' => $name, 'is_active' => $isActive
                ]);

                setFlash('success', "Pemasok baru {$name} ({$code}) berhasil ditambahkan dan langsung aktif.");
            }

            header('Location: index.php?page=master_data/suppliers');
            exit;
        } catch (Exception $e) {
            setFlash('error', $e->getMessage());
            header('Location: index.php?page=master_data/suppliers' . ($suppId > 0 ? "&edit_id={$suppId}" : ''));
            exit;
        }
    }

    // -------------------------------------------------------------------------
    // 2. Toggle Active Status (Deactivate / Activate)
    // -------------------------------------------------------------------------
    if ($action === 'toggle_status') {
        if (!hasRole(['Administrator', 'admin', 'purchasing', 'finance'])) {
            setFlash('error', 'Akses ditolak: Anda tidak memiliki hak akses mengubah status pemasok.');
            header('Location: index.php?page=master_data/suppliers');
            exit;
        }

        $suppId = (int)($_POST['supplier_id'] ?? 0);
        $stmtS = $db->prepare("SELECT * FROM suppliers WHERE id = :id");
        $stmtS->execute([':id' => $suppId]);
        $supplier = $stmtS->fetch(PDO::FETCH_ASSOC);

        if (!$supplier) {
            setFlash('error', 'Data pemasok tidak ditemukan.');
            header('Location: index.php?page=master_data/suppliers');
            exit;
        }

        $newStatus = $supplier['is_active'] ? 0 : 1;
        $statusLabel = $newStatus ? 'diaktifkan kembali' : 'dinonaktifkan';

        $stmtUpd = $db->prepare("UPDATE suppliers SET is_active = :act, updated_by = :uid, updated_at = NOW() WHERE id = :id");
        $stmtUpd->execute([':act' => $newStatus, ':uid' => $user['id'], ':id' => $suppId]);

        logAudit($user['id'], 'toggle_supplier_status', 'suppliers', $suppId, ['is_active' => $supplier['is_active']], ['is_active' => $newStatus]);

        setFlash('success', "Pemasok {$supplier['name']} ({$supplier['code']}) berhasil {$statusLabel}.");
        header('Location: index.php?page=master_data/suppliers');
        exit;
    }

    // -------------------------------------------------------------------------
    // 3. Delete Supplier (Hard delete only if unreferenced)
    // -------------------------------------------------------------------------
    if ($action === 'delete_supplier') {
        if (!hasRole(['Administrator', 'admin', 'purchasing'])) {
            setFlash('error', 'Hanya Administrator dan Bagian Pembelian yang berwenang menghapus data pemasok.');
            header('Location: index.php?page=master_data/suppliers');
            exit;
        }

        $suppId = (int)($_POST['supplier_id'] ?? 0);
        $stmtS = $db->prepare("SELECT * FROM suppliers WHERE id = :id");
        $stmtS->execute([':id' => $suppId]);
        $supplier = $stmtS->fetch(PDO::FETCH_ASSOC);

        if (!$supplier) {
            setFlash('error', 'Data pemasok tidak ditemukan.');
            header('Location: index.php?page=master_data/suppliers');
            exit;
        }

        // Check foreign key dependencies
        $references = checkRecordReferences('suppliers', $suppId, $db);
        if (!empty($references)) {
            setFlash('error', "Pemasok {$supplier['name']} tidak dapat dihapus karena masih digunakan dalam riwayat transaksi: " . implode(', ', $references) . ". Anda disarankan menonaktifkannya.");
            header('Location: index.php?page=master_data/suppliers');
            exit;
        }

        try {
            $stmtDel = $db->prepare("DELETE FROM suppliers WHERE id = :id");
            $stmtDel->execute([':id' => $suppId]);

            logAudit($user['id'], 'delete_supplier', 'suppliers', $suppId, $supplier, null);

            setFlash('success', "Pemasok {$supplier['name']} ({$supplier['code']}) berhasil dihapus permanen.");
            header('Location: index.php?page=master_data/suppliers');
            exit;
        } catch (Exception $e) {
            setFlash('error', 'Gagal menghapus pemasok: ' . $e->getMessage());
            header('Location: index.php?page=master_data/suppliers');
            exit;
        }
    }
}

// -----------------------------------------------------------------------------
// Search & Filter
// -----------------------------------------------------------------------------
$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(s.code LIKE :q OR s.name LIKE :q OR s.phone LIKE :q OR s.contact_person LIKE :q OR s.email LIKE :q OR s.npwp LIKE :q OR s.bank_account LIKE :q)';
    $params[':q'] = "%{$search}%";
}

if ($statusFilter === 'active') {
    $where[] = 's.is_active = 1';
} elseif ($statusFilter === 'inactive') {
    $where[] = 's.is_active = 0';
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM suppliers s WHERE {$whereSql}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

// List query with transaction count metrics
$stmt = $db->prepare("
    SELECT s.*,
           (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.id) AS po_count,
           (SELECT COUNT(*) FROM goods_receipts gr WHERE gr.supplier_id = s.id) AS gr_count,
           (SELECT COUNT(*) FROM supplier_invoices si WHERE si.supplier_id = s.id) AS inv_count,
           (SELECT COUNT(*) FROM supplier_payments sp WHERE sp.supplier_id = s.id) AS pay_count,
           (SELECT COUNT(*) FROM supplier_returns sr WHERE sr.supplier_id = s.id) AS ret_count
    FROM suppliers s
    WHERE {$whereSql}
    ORDER BY s.name ASC
    LIMIT :offset, :per_page
");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check edit supplier
$editSupp = null;
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    $stmtE = $db->prepare("SELECT * FROM suppliers WHERE id = :id");
    $stmtE->execute([':id' => $editId]);
    $editSupp = $stmtE->fetch(PDO::FETCH_ASSOC);
}

// Check detail supplier (from GET or direct view)
$detailSupp = null;
$detailPo = [];
$detailGr = [];
$detailInv = [];
$detailPay = [];
$detailRet = [];

if (isset($_GET['detail_id'])) {
    $detId = (int)$_GET['detail_id'];
    $stmtD = $db->prepare("
        SELECT s.*, u.full_name AS creator_name, u2.full_name AS updater_name,
               (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.id) AS po_count,
               (SELECT COUNT(*) FROM goods_receipts gr WHERE gr.supplier_id = s.id) AS gr_count,
               (SELECT COUNT(*) FROM supplier_invoices si WHERE si.supplier_id = s.id) AS inv_count,
               (SELECT COUNT(*) FROM supplier_payments sp WHERE sp.supplier_id = s.id) AS pay_count,
               (SELECT COUNT(*) FROM supplier_returns sr WHERE sr.supplier_id = s.id) AS ret_count
        FROM suppliers s
        LEFT JOIN users u ON s.created_by = u.id
        LEFT JOIN users u2 ON s.updated_by = u2.id
        WHERE s.id = :id
    ");
    $stmtD->execute([':id' => $detId]);
    $detailSupp = $stmtD->fetch(PDO::FETCH_ASSOC);

    if ($detailSupp) {
        // Fetch recent 5 POs
        $stmtPo = $db->prepare("SELECT * FROM purchase_orders WHERE supplier_id = :id ORDER BY po_date DESC, id DESC LIMIT 5");
        $stmtPo->execute([':id' => $detId]);
        $detailPo = $stmtPo->fetchAll(PDO::FETCH_ASSOC);

        // Fetch recent 5 GRs
        $stmtGr = $db->prepare("SELECT * FROM goods_receipts WHERE supplier_id = :id ORDER BY receipt_date DESC, id DESC LIMIT 5");
        $stmtGr->execute([':id' => $detId]);
        $detailGr = $stmtGr->fetchAll(PDO::FETCH_ASSOC);

        // Fetch recent 5 Invoices
        $stmtInv = $db->prepare("SELECT * FROM supplier_invoices WHERE supplier_id = :id ORDER BY invoice_date DESC, id DESC LIMIT 5");
        $stmtInv->execute([':id' => $detId]);
        $detailInv = $stmtInv->fetchAll(PDO::FETCH_ASSOC);

        // Fetch recent 5 Payments
        $stmtPay = $db->prepare("SELECT * FROM supplier_payments WHERE supplier_id = :id ORDER BY payment_date DESC, id DESC LIMIT 5");
        $stmtPay->execute([':id' => $detId]);
        $detailPay = $stmtPay->fetchAll(PDO::FETCH_ASSOC);

        // Fetch recent 5 Returns
        $stmtRet = $db->prepare("SELECT * FROM supplier_returns WHERE supplier_id = :id ORDER BY return_date DESC, id DESC LIMIT 5");
        $stmtRet->execute([':id' => $detId]);
        $detailRet = $stmtRet->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Next code suggestion
$lastCode = $db->query("SELECT code FROM suppliers ORDER BY id DESC LIMIT 1")->fetchColumn();
$suggestedCode = 'SUPP-001';
if ($lastCode && preg_match('/(\d+)$/', $lastCode, $m)) {
    $nextNum = (int)$m[1] + 1;
    $suggestedCode = 'SUPP-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
}

// Summary Statistics
$statTotal = (int)$db->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
$statActive = (int)$db->query("SELECT COUNT(*) FROM suppliers WHERE is_active = 1")->fetchColumn();
$statInactive = (int)$db->query("SELECT COUNT(*) FROM suppliers WHERE is_active = 0")->fetchColumn();
$statPoCount = (int)$db->query("SELECT COUNT(*) FROM purchase_orders")->fetchColumn();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Master Pemasok &amp; Vendor</h1>
        <div class="page-subtitle">Direktori Vendor Pengadaan Bahan Baku, Bahan Kemas, dan Komponen PT Mayora Indah Tbk.</div>
    </div>
    <div class="page-actions">
        <?php if ($detailSupp || $editSupp): ?>
            <a href="index.php?page=master_data/suppliers" class="btn btn-secondary">&laquo; Kembali ke Daftar Vendor</a>
        <?php endif; ?>
    </div>
</div>

<!-- KPI Summary Cards -->
<div class="stats mb-4" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:16px;">
    <div class="card" style="padding:14px 18px;">
        <div class="text-muted" style="font-size:11.5px; font-weight:600; text-transform:uppercase;">Total Pemasok Terdaftar</div>
        <div style="font-size:24px; font-weight:800; color:#111827; margin-top:4px;"><?= $statTotal ?></div>
        <small class="text-muted" style="font-size:11px;">Mitra Vendor Pengadaan</small>
    </div>
    <div class="card" style="padding:14px 18px;">
        <div class="text-muted" style="font-size:11.5px; font-weight:600; text-transform:uppercase;">Pemasok Aktif</div>
        <div style="font-size:24px; font-weight:800; color:#16a34a; margin-top:4px;"><?= $statActive ?></div>
        <small class="text-muted" style="font-size:11px;">Siap dipilih di PR &amp; PO</small>
    </div>
    <div class="card" style="padding:14px 18px;">
        <div class="text-muted" style="font-size:11.5px; font-weight:600; text-transform:uppercase;">Pemasok Non-Aktif</div>
        <div style="font-size:24px; font-weight:800; color:<?= $statInactive > 0 ? '#dc2626' : '#9ca3af' ?>; margin-top:4px;"><?= $statInactive ?></div>
        <small class="text-muted" style="font-size:11px;">Dinonaktifkan sementara</small>
    </div>
    <div class="card" style="padding:14px 18px;">
        <div class="text-muted" style="font-size:11.5px; font-weight:600; text-transform:uppercase;">Akumulasi Transaksi PO</div>
        <div style="font-size:24px; font-weight:800; color:#0284c7; margin-top:4px;"><?= $statPoCount ?></div>
        <small class="text-muted" style="font-size:11px;">Dokumen Purchase Order</small>
    </div>
</div>

<!-- Dedicated Detail Vendor Section (If requested) -->
<?php if ($detailSupp): ?>
<div class="card mb-4" style="border: 2px solid #e2e8f0; border-radius: 8px;">
    <div class="card-header" style="background:#f8fafc; display:flex; justify-content:space-between; align-items:center; padding:14px 20px;">
        <div>
            <h2 class="card-title" style="margin:0; font-size:16px;">
                👁️ Rincian Pemasok: <strong><?= e($detailSupp['name']) ?></strong> (<?= e($detailSupp['code']) ?>)
            </h2>
            <div class="text-muted" style="font-size:12px; margin-top:2px;">
                Dibuat oleh: <?= e($detailSupp['creator_name'] ?: 'Sistem') ?> &bull; <?= formatDateTime($detailSupp['created_at']) ?>
            </div>
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
            <?= $detailSupp['is_active'] ? '<span class="bd ok">Aktif</span>' : '<span class="bd no">Non-Aktif</span>' ?>
            <?php if (hasRole(['Administrator', 'admin', 'purchasing', 'finance'])): ?>
                <a href="index.php?page=master_data/suppliers&edit_id=<?= $detailSupp['id'] ?>" class="btn btn-sm btn-primary">✏️ Edit Pemasok Ini</a>
            <?php endif; ?>
            <a href="index.php?page=master_data/suppliers" class="btn btn-sm btn-secondary">&times; Tutup Detail</a>
        </div>
    </div>
    <div class="card-body" style="padding:20px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:20px; margin-bottom:24px;">
            <!-- Info Kontak & Identitas -->
            <div style="background:#ffffff; border:1px solid #e5e7eb; border-radius:6px; padding:16px;">
                <h3 style="font-size:13px; font-weight:700; text-transform:uppercase; color:#4b5563; margin-bottom:12px; border-bottom:1px solid #f3f4f6; padding-bottom:6px;">
                    Identitas &amp; Kontak Perusahaan
                </h3>
                <table style="width:100%; font-size:12.5px; line-height:1.8;">
                    <tr>
                        <td style="width:120px; color:#6b7280;">Kode Vendor</td>
                        <td><strong><?= e($detailSupp['code']) ?></strong></td>
                    </tr>
                    <tr>
                        <td style="color:#6b7280;">Nama Vendor</td>
                        <td><strong><?= e($detailSupp['name']) ?></strong></td>
                    </tr>
                    <tr>
                        <td style="color:#6b7280;">Kontak Person</td>
                        <td><?= e($detailSupp['contact_person'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <td style="color:#6b7280;">No. Telepon</td>
                        <td><?= e($detailSupp['phone'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <td style="color:#6b7280;">Alamat Email</td>
                        <td><?= e($detailSupp['email'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <td style="color:#6b7280; vertical-align:top;">Alamat Lengkap</td>
                        <td><?= nl2br(e($detailSupp['address'] ?: '-')) ?></td>
                    </tr>
                </table>
            </div>

            <!-- Info Keuangan, NPWP & Rekening Bank -->
            <div style="background:#ffffff; border:1px solid #e5e7eb; border-radius:6px; padding:16px;">
                <h3 style="font-size:13px; font-weight:700; text-transform:uppercase; color:#4b5563; margin-bottom:12px; border-bottom:1px solid #f3f4f6; padding-bottom:6px;">
                    Perpajakan &amp; Rekening Pembayaran
                </h3>
                <table style="width:100%; font-size:12.5px; line-height:1.8;">
                    <tr>
                        <td style="width:130px; color:#6b7280;">NPWP Perusahaan</td>
                        <td><strong><?= e($detailSupp['npwp'] ?: '-') ?></strong></td>
                    </tr>
                    <tr>
                        <td style="color:#6b7280;">Termin Pembayaran</td>
                        <td><strong><?= (int)$detailSupp['payment_terms_days'] ?> Hari Kalender (TOP)</strong></td>
                    </tr>
                    <tr>
                        <td style="color:#6b7280;">Nama Bank</td>
                        <td><?= e($detailSupp['bank_name'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <td style="color:#6b7280;">Nomor Rekening</td>
                        <td><strong style="color:#0284c7;"><?= e($detailSupp['bank_account'] ?: '-') ?></strong></td>
                    </tr>
                    <tr>
                        <td style="color:#6b7280;">Atas Nama Rekening</td>
                        <td><?= e($detailSupp['bank_account_holder'] ?: '-') ?></td>
                    </tr>
                    <tr>
                        <td style="color:#6b7280;">Status Sistem</td>
                        <td><?= $detailSupp['is_active'] ? '<span class="bd ok">Aktif Digunakan</span>' : '<span class="bd no">Nonaktif</span>' ?></td>
                    </tr>
                </table>
            </div>

            <!-- Ringkasan Statistik Transaksi -->
            <div style="background:#ffffff; border:1px solid #e5e7eb; border-radius:6px; padding:16px;">
                <h3 style="font-size:13px; font-weight:700; text-transform:uppercase; color:#4b5563; margin-bottom:12px; border-bottom:1px solid #f3f4f6; padding-bottom:6px;">
                    Rekapitulasi Transaksi Terkait
                </h3>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; font-size:12px;">
                    <div style="padding:8px 12px; background:#f8fafc; border-radius:4px; border:1px solid #e2e8f0;">
                        <span class="text-muted">Purchase Order:</span><br>
                        <strong style="font-size:16px; color:#111827;"><?= (int)$detailSupp['po_count'] ?></strong> Dokumen
                    </div>
                    <div style="padding:8px 12px; background:#f8fafc; border-radius:4px; border:1px solid #e2e8f0;">
                        <span class="text-muted">Penerimaan (GR):</span><br>
                        <strong style="font-size:16px; color:#111827;"><?= (int)$detailSupp['gr_count'] ?></strong> Dokumen
                    </div>
                    <div style="padding:8px 12px; background:#f8fafc; border-radius:4px; border:1px solid #e2e8f0;">
                        <span class="text-muted">Faktur Tagihan:</span><br>
                        <strong style="font-size:16px; color:#111827;"><?= (int)$detailSupp['inv_count'] ?></strong> Tagihan
                    </div>
                    <div style="padding:8px 12px; background:#f8fafc; border-radius:4px; border:1px solid #e2e8f0;">
                        <span class="text-muted">Pembayaran Kas:</span><br>
                        <strong style="font-size:16px; color:#111827;"><?= (int)$detailSupp['pay_count'] ?></strong> Transaksi
                    </div>
                    <div style="grid-column: span 2; padding:8px 12px; background:#f8fafc; border-radius:4px; border:1px solid #e2e8f0;">
                        <span class="text-muted">Retur Pembelian:</span>
                        <strong style="font-size:14px; color:#111827; float:right;"><?= (int)$detailSupp['ret_count'] ?> Dokumen</strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabel Riwayat Purchase Orders Terkini -->
        <h4 style="font-size:13px; font-weight:700; margin-bottom:8px; color:#111827;">Riwayat Pesanan Pembelian (Purchase Order) Terakhir</h4>
        <div class="table-responsive mb-4" style="border:1px solid #e5e7eb; border-radius:6px;">
            <table class="table" style="font-size:12px; margin:0;">
                <thead>
                    <tr>
                        <th>No. PO</th>
                        <th>Tanggal PO</th>
                        <th>Estimasi Pengiriman</th>
                        <th class="text-right">Total Nilai (Rp)</th>
                        <th>Status</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($detailPo)): ?>
                        <tr><td colspan="6" class="text-center" style="padding:14px; color:#9ca3af;">Belum ada dokumen PO untuk pemasok ini.</td></tr>
                    <?php else: ?>
                        <?php foreach ($detailPo as $po): ?>
                            <tr>
                                <td><strong><?= e($po['po_number']) ?></strong></td>
                                <td><?= formatDate($po['po_date']) ?></td>
                                <td><?= formatDate($po['expected_delivery'] ?? null) ?></td>
                                <td class="text-right font-medium"><?= formatRupiah((float)$po['total_amount']) ?></td>
                                <td><?= statusBadge($po['status']) ?></td>
                                <td class="text-center">
                                    <a href="index.php?page=expenditure_procurement/po&po_id=<?= $po['id'] ?>" class="btn btn-sm btn-secondary">Buka PO</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Tabel Riwayat Faktur & Pembayaran Terkini -->
        <h4 style="font-size:13px; font-weight:700; margin-bottom:8px; color:#111827;">Riwayat Faktur Pemasok (Invoice) &amp; Utang</h4>
        <div class="table-responsive" style="border:1px solid #e5e7eb; border-radius:6px;">
            <table class="table" style="font-size:12px; margin:0;">
                <thead>
                    <tr>
                        <th>No. Faktur Internal</th>
                        <th>No. Faktur Vendor</th>
                        <th>Tgl Faktur</th>
                        <th>Jatuh Tempo</th>
                        <th class="text-right">Total Tagihan</th>
                        <th class="text-right">Sisa Utang</th>
                        <th>Status</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($detailInv)): ?>
                        <tr><td colspan="8" class="text-center" style="padding:14px; color:#9ca3af;">Belum ada dokumen faktur tagihan untuk pemasok ini.</td></tr>
                    <?php else: ?>
                        <?php foreach ($detailInv as $inv): ?>
                            <tr>
                                <td><strong><?= e($inv['invoice_number']) ?></strong></td>
                                <td><?= e($inv['supplier_invoice_number'] ?: '-') ?></td>
                                <td><?= formatDate($inv['invoice_date']) ?></td>
                                <td><?= formatDate($inv['due_date']) ?></td>
                                <td class="text-right"><?= formatRupiah((float)$inv['total_amount']) ?></td>
                                <td class="text-right font-bold" style="color:<?= (float)$inv['balance'] > 0 ? '#dc2626' : '#16a34a' ?>;">
                                    <?= formatRupiah((float)$inv['balance']) ?>
                                </td>
                                <td><?= statusBadge($inv['status']) ?></td>
                                <td class="text-center">
                                    <a href="index.php?page=expenditure_procurement/invoices&inv_id=<?= $inv['id'] ?>" class="btn btn-sm btn-secondary">Buka Faktur</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add / Edit Collapsible Form -->
<?php if (hasRole(['Administrator', 'admin', 'purchasing', 'finance'])): ?>
<div class="card mb-4" id="form-pemasok-section">
    <details <?= $editSupp ? 'open' : '' ?>>
        <summary style="cursor:pointer; padding:14px 18px; font-weight:600; font-size:14px; color:#111827; background:#f8fafc; border-radius:6px; list-style:none; display:flex; justify-content:space-between; align-items:center;">
            <span>
                <?= $editSupp ? '✏️ Edit Data Pemasok: <strong>' . e($editSupp['name']) . '</strong> (' . e($editSupp['code']) . ')' : '+ Tambah Pemasok / Vendor Baru' ?>
            </span>
            <span class="btn btn-sm <?= $editSupp ? 'btn-secondary' : 'btn-primary' ?>" style="pointer-events:none;">
                <?= $editSupp ? 'Formulir Mode Edit' : 'Buka Formulir Input &darr;' ?>
            </span>
        </summary>
        <div style="padding:20px; border-top:1px solid #e5e7eb;">
            <form action="index.php?page=master_data/suppliers" method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_supplier">
                <input type="hidden" name="supplier_id" value="<?= $editSupp['id'] ?? 0 ?>">
                
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:16px;">
                    <div class="form-group">
                        <label>Kode Pemasok *</label>
                        <input type="text" name="code" value="<?= e($editSupp['code'] ?? $suggestedCode) ?>" placeholder="Contoh: SUPP-006" required>
                        <small class="text-muted" style="font-size:11px;">Kode unik vendor (huruf besar/angka)</small>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label>Nama Perusahaan Pemasok *</label>
                        <input type="text" name="name" value="<?= e($editSupp['name'] ?? '') ?>" placeholder="Contoh: PT Mayora Mitra Sukses" required>
                    </div>
                    <div class="form-group">
                        <label>Nama Kontak Person</label>
                        <input type="text" name="contact_person" value="<?= e($editSupp['contact_person'] ?? '') ?>" placeholder="Contoh: Bpk. Bambang Wijaya">
                    </div>
                    <div class="form-group">
                        <label>Nomor Telepon Kantor / Sales</label>
                        <input type="text" name="phone" value="<?= e($editSupp['phone'] ?? '') ?>" placeholder="021-5551234 / 08123456789">
                    </div>
                    <div class="form-group">
                        <label>Alamat Email Resmi</label>
                        <input type="email" name="email" value="<?= e($editSupp['email'] ?? '') ?>" placeholder="sales@vendor.co.id">
                    </div>
                    <div class="form-group">
                        <label>NPWP Vendor (Tax ID)</label>
                        <input type="text" name="npwp" value="<?= e($editSupp['npwp'] ?? '') ?>" placeholder="01.234.567.8-901.000">
                    </div>
                    <div class="form-group">
                        <label>Termin Pembayaran (Hari Kalender)</label>
                        <input type="number" name="payment_terms_days" min="0" max="365" value="<?= (int)($editSupp['payment_terms_days'] ?? 30) ?>" placeholder="30">
                        <small class="text-muted" style="font-size:11px;">Jangka waktu jatuh tempo pembayaran (TOP)</small>
                    </div>
                    <div class="form-group">
                        <label>Nama Bank Pembayaran</label>
                        <input type="text" name="bank_name" value="<?= e($editSupp['bank_name'] ?? 'Bank Mandiri') ?>" placeholder="Bank Mandiri / BCA / BNI / BRI">
                    </div>
                    <div class="form-group">
                        <label>Nomor Rekening Bank</label>
                        <input type="text" name="bank_account" value="<?= e($editSupp['bank_account'] ?? '') ?>" placeholder="Nomor rekening penerima transfer">
                    </div>
                    <div class="form-group">
                        <label>Atas Nama Pemilik Rekening</label>
                        <input type="text" name="bank_account_holder" value="<?= e($editSupp['bank_account_holder'] ?? '') ?>" placeholder="Nama tertera pada buku tabungan / giro">
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Alamat Lengkap Kantor / Pabrik Pemasok</label>
                        <textarea name="address" rows="2" placeholder="Alamat lengkap lokasi kantor / gudang vendor"><?= e($editSupp['address'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group" style="display:flex; align-items:center; gap:8px;">
                        <input type="checkbox" name="is_active" value="1" id="supp_active" <?= (!isset($editSupp['is_active']) || $editSupp['is_active']) ? 'checked' : '' ?> style="width:18px; height:18px;">
                        <label for="supp_active" style="margin-bottom:0; font-weight:600; cursor:pointer;">Status Pemasok Aktif (Dapat Dipilih di PR/PO)</label>
                    </div>
                </div>
                
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:15px; border-top:1px solid #f3f4f6; padding-top:15px;">
                    <?php if ($editSupp): ?>
                        <a href="index.php?page=master_data/suppliers" class="btn btn-secondary">Batal Edit</a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">
                        <?= $editSupp ? '💾 Simpan Perubahan Pemasok' : '+ Simpan Pemasok Baru' ?>
                    </button>
                </div>
            </form>
        </div>
    </details>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="master_data/suppliers">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari kode, nama vendor, kontak, telp, NPWP..." style="min-width:280px;">
    </div>
    <div class="filter-group">
        <select name="status">
            <option value="">-- Semua Status --</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Hanya Aktif</option>
            <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Hanya Nonaktif</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== '' || $statusFilter !== ''): ?>
        <a href="index.php?page=master_data/suppliers" class="btn btn-sm btn-secondary">Reset Filter</a>
    <?php endif; ?>
</form>

<!-- Table of Suppliers -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Pemasok</th>
                    <th>Kontak Person</th>
                    <th>Telepon / Email</th>
                    <th>NPWP &amp; Rekening</th>
                    <th class="text-center">Termin (TOP)</th>
                    <th class="text-center">Status</th>
                    <th class="text-center" style="width:190px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr>
                        <td colspan="8" class="text-center" style="padding:32px; color:#6b7280;">
                            Tidak ada data pemasok yang sesuai kriteria pencarian.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($suppliers as $s): ?>
                        <tr>
                            <td><strong><?= e($s['code']) ?></strong></td>
                            <td>
                                <strong style="color:#111827;"><?= e($s['name']) ?></strong>
                                <div class="text-muted" style="font-size:11px; margin-top:2px;">
                                    PO: <strong><?= (int)$s['po_count'] ?></strong> &bull; 
                                    GR: <strong><?= (int)$s['gr_count'] ?></strong> &bull; 
                                    Faktur: <strong><?= (int)$s['inv_count'] ?></strong> &bull;
                                    Bayar: <strong><?= (int)$s['pay_count'] ?></strong>
                                </div>
                            </td>
                            <td><?= e($s['contact_person'] ?: '-') ?></td>
                            <td>
                                <div><?= e($s['phone'] ?: '-') ?></div>
                                <small class="text-muted"><?= e($s['email'] ?: '') ?></small>
                            </td>
                            <td>
                                <div><small>NPWP: <?= e($s['npwp'] ?: '-') ?></small></div>
                                <small class="text-muted"><?= e($s['bank_name'] ?: '') ?> <?= e($s['bank_account'] ? '(' . $s['bank_account'] . ')' : '') ?></small>
                            </td>
                            <td class="text-center"><?= (int)$s['payment_terms_days'] ?> Hari</td>
                            <td class="text-center">
                                <?= $s['is_active'] ? '<span class="bd ok">Aktif</span>' : '<span class="bd no">Non-Aktif</span>' ?>
                            </td>
                            <td class="text-center">
                                <div class="btn-group">
                                    <!-- Detail View Link -->
                                    <a href="index.php?page=master_data/suppliers&detail_id=<?= $s['id'] ?>" class="btn btn-sm btn-secondary" title="Lihat Rincian & Riwayat Transaksi">
                                        👁️ Detail
                                    </a>

                                    <?php if (hasRole(['Administrator', 'admin', 'purchasing', 'finance'])): ?>
                                        <!-- Edit Trigger -->
                                        <a href="index.php?page=master_data/suppliers&edit_id=<?= $s['id'] ?>#form-pemasok-section" class="btn btn-sm btn-outline-primary" title="Edit Data Pemasok">
                                            ✏️ Edit
                                        </a>

                                        <!-- Toggle Status Trigger -->
                                        <form action="index.php?page=master_data/suppliers" method="POST" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin <?= $s['is_active'] ? 'menonaktifkan' : 'mengaktifkan' ?> pemasok <?= e(addslashes($s['name'])) ?>?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="supplier_id" value="<?= $s['id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $s['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $s['is_active'] ? 'Nonaktifkan Pemasok' : 'Aktifkan Pemasok' ?>">
                                                <?= $s['is_active'] ? '⏸️' : '▶️' ?>
                                            </button>
                                        </form>

                                        <!-- Delete Trigger (opens modal positioned cleanly outside table) -->
                                        <?php if (hasRole(['Administrator', 'admin', 'purchasing'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="openModal('modal-del-supp-<?= $s['id'] ?>')" title="Hapus Pemasok">
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
        <div class="card-footer" style="padding:12px 18px;">
            <?= renderPagination($pagination, 'index.php?page=master_data/suppliers&q=' . urlencode($search) . '&status=' . urlencode($statusFilter)) ?>
        </div>
    <?php endif; ?>
</div>

<!-- ========================================================================= -->
<!-- MODALS PLACED CLEANLY OUTSIDE TABLE CONTAINER (Valid HTML5 DOM)           -->
<!-- ========================================================================= -->
<?php if (!empty($suppliers) && hasRole(['Administrator', 'admin', 'purchasing'])): ?>
    <?php foreach ($suppliers as $s): ?>
        <?php $refs = checkRecordReferences('suppliers', $s['id'], $db); ?>
        <div id="modal-del-supp-<?= $s['id'] ?>" class="modal-backdrop">
            <div class="modal-dialog modal-sm">
                <div class="modal-header" style="background:#fee2e2;">
                    <h3 class="modal-title" style="color:#b91c1c;">⚠️ Konfirmasi Penghapusan Pemasok</h3>
                    <button type="button" class="modal-close" data-modal-close>&times;</button>
                </div>
                <div class="modal-body">
                    <p style="font-size:13px; margin-bottom:8px;">Anda akan memproses data pemasok:</p>
                    <div style="background:#f8fafc; padding:10px 12px; border-radius:6px; margin-bottom:12px; font-size:13px; border:1px solid #e2e8f0;">
                        <strong><?= e($s['name']) ?></strong><br>
                        <span class="text-muted">Kode: <?= e($s['code']) ?></span>
                    </div>

                    <?php if (!empty($refs)): ?>
                        <div class="alert alert-warning" style="font-size:12px; padding:10px; margin-bottom:0;">
                            <strong>Perhatian!</strong> Pemasok ini memiliki keterkaitan data transaksi:
                            <ul style="margin:6px 0 0 16px; padding:0;">
                                <?php foreach ($refs as $r): ?>
                                    <li><?= e($r) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <p style="margin-top:8px; margin-bottom:0; color:#92400e;">
                                Sesuai kebijakan integritas transaksi ERP, pemasok yang sudah memiliki riwayat transaksi <strong>tidak dapat dihapus permanen</strong>. Anda disarankan untuk <strong>menonaktifkannya</strong>.
                            </p>
                        </div>
                    <?php else: ?>
                        <p style="color:#b91c1c; font-size:12.5px; margin:0;">
                            Pemasok ini belum memiliki transaksi terkait dan aman untuk dihapus permanen.
                        </p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Batal</button>
                    <?php if (!empty($refs)): ?>
                        <form action="index.php?page=master_data/suppliers" method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="supplier_id" value="<?= $s['id'] ?>">
                            <button type="submit" class="btn btn-warning btn-sm">⏸️ Nonaktifkan Saja</button>
                        </form>
                    <?php else: ?>
                        <form action="index.php?page=master_data/suppliers" method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_supplier">
                            <input type="hidden" name="supplier_id" value="<?= $s['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">🗑️ Ya, Hapus Permanen</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
