<?php
/**
 * Modul Pengeluaran: Daftar Pelunasan / Pembayaran Pemasok
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Pelunasan / Pembayaran Pemasok';
$breadcrumbCategory = 'Pengeluaran';

$db = getDbConnection();
$user = currentUser();

// Handle Void / Cancel Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'void_payment') {
    requireCsrf();

    $canVoid = hasRole(['Supervisor / Manajer', 'Administrator', 'Finance/Treasury', 'manager', 'admin', 'finance']);
    if (!$canVoid) {
        setFlash('error', 'Anda tidak memiliki hak otorisasi untuk membatalkan pembayaran pemasok.');
        header('Location: index.php?page=expenditure_procurement/payments');
        exit;
    }

    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $voidReason = trim($_POST['void_reason'] ?? '');

    if (empty($voidReason)) {
        setFlash('error', 'Alasan pembatalan/void pembayaran wajib diisi.');
        header('Location: index.php?page=expenditure_procurement/payments');
        exit;
    }

    try {
        $stmtP = $db->prepare("SELECT * FROM supplier_payments WHERE id = :id FOR UPDATE");
        $stmtP->execute([':id' => $paymentId]);
        $payment = $stmtP->fetch();

        if (!$payment) {
            throw new Exception("Data pembayaran tidak ditemukan.");
        }

        if ($payment['status'] === 'cancelled') {
            throw new Exception("Pembayaran {$payment['payment_number']} sudah dibatalkan sebelumnya.");
        }

        $db->beginTransaction();

        // 1. Fetch allocations and revert invoice balances
        $stmtAlloc = $db->prepare("SELECT * FROM supplier_payment_allocations WHERE supplier_payment_id = :id");
        $stmtAlloc->execute([':id' => $paymentId]);
        $allocations = $stmtAlloc->fetchAll();

        foreach ($allocations as $alloc) {
            $invId = (int)$alloc['supplier_invoice_id'];
            $amt   = (float)$alloc['allocated_amount'];

            $stmtInv = $db->prepare("SELECT * FROM supplier_invoices WHERE id = :id FOR UPDATE");
            $stmtInv->execute([':id' => $invId]);
            $inv = $stmtInv->fetch();

            if ($inv) {
                $newPaid = max(0, (float)$inv['paid_amount'] - $amt);
                $newBal  = (float)$inv['total_amount'] - $newPaid;
                $newStat = ($newBal >= (float)$inv['total_amount']) ? 'matched' : 'partially_paid';

                $db->prepare("
                    UPDATE supplier_invoices 
                    SET paid_amount = :paid, balance = :bal, status = :status, 
                        updated_at = NOW(), updated_by = :uid 
                    WHERE id = :id
                ")->execute([
                    ':paid'   => $newPaid,
                    ':bal'    => $newBal,
                    ':status' => $newStat,
                    ':uid'    => $user['id'],
                    ':id'     => $invId,
                ]);
            }
        }

        // 2. Reverse accounting journal entry
        JournalService::reverseEntryByRef(
            'supplier_payment',
            $paymentId,
            "Pembalikan/Void Pembayaran Pemasok {$payment['payment_number']}: {$voidReason}",
            $user['id'],
            $db
        );

        // 3. Update payment status to cancelled
        $db->prepare("
            UPDATE supplier_payments 
            SET status = 'cancelled', notes = CONCAT_WS(' | Dibatalkan: ', notes, :reason), 
                updated_at = NOW(), updated_by = :uid 
            WHERE id = :id
        ")->execute([
            ':reason' => $voidReason,
            ':uid'    => $user['id'],
            ':id'     => $paymentId,
        ]);

        logAudit($user['id'], 'cancel', 'supplier_payments', $paymentId, ['status' => $payment['status']], [
            'status' => 'cancelled',
            'reason' => $voidReason,
        ]);

        $db->commit();
        setFlash('success', "Pembayaran {$payment['payment_number']} berhasil dibatalkan. Saldo utang faktur dan jurnal akuntansi telah dipulihkan.");
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', $e->getMessage());
    }

    header('Location: index.php?page=expenditure_procurement/payments');
    exit;
}

$search = trim($_GET['q'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(sp.payment_number LIKE :q OR s.name LIKE :q OR sp.bank_ref LIKE :q)';
    $params[':q'] = "%{$search}%";
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM supplier_payments sp
    JOIN suppliers s ON sp.supplier_id = s.id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$pagination = paginate($totalRows, $pageNo, $perPage);

// List
$stmt = $db->prepare("
    SELECT sp.*, s.name AS supplier_name, s.code AS supplier_code, u.full_name AS creator_name,
           GROUP_CONCAT(CONCAT(si.invoice_number, ' (', FORMAT(spa.allocated_amount, 2), ')') SEPARATOR ', ') AS invoice_allocations
    FROM supplier_payments sp
    JOIN suppliers s ON sp.supplier_id = s.id
    LEFT JOIN users u ON sp.created_by = u.id
    LEFT JOIN supplier_payment_allocations spa ON sp.id = spa.supplier_payment_id
    LEFT JOIN supplier_invoices si ON spa.supplier_invoice_id = si.id
    WHERE {$whereSql}
    GROUP BY sp.id
    ORDER BY sp.id DESC
    LIMIT :offset, :per_page
");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$payments = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Pelunasan & Pengeluaran Kas/Bank (Supplier Payments)</h1>
        <div class="page-subtitle">Siklus Pengeluaran &bull; Bukti Transfer Bank dan Pengurangan Saldo Utang Pemasok</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=expenditure_procurement/payment_create" class="btn btn-primary">
            + Catat Pembayaran Baru
        </a>
    </div>
</div>

<form action="index.php" method="GET" class="filter-bar">
    <input type="hidden" name="page" value="expenditure_procurement/payments">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari nomor pembayaran, vendor, no ref bank..." style="min-width: 250px;">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if ($search !== ''): ?>
        <a href="index.php?page=expenditure_procurement/payments" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>No. Pembayaran</th>
                    <th>Tanggal Bayar</th>
                    <th>Pemasok / Vendor</th>
                    <th>Metode Pembayaran</th>
                    <th>Bank & Referensi Transfer</th>
                    <th class="text-right">Total Pembayaran</th>
                    <th>Petugas Treasury</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="9" class="text-center" style="padding: 24px; color: var(--text-muted);">
                            Belum ada transaksi pembayaran pemasok yang tercatat.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><strong><?= e($p['payment_number']) ?></strong></td>
                            <td><?= formatDate($p['payment_date']) ?></td>
                            <td><?= e($p['supplier_name']) ?></td>
                            <td><span class="badge badge-info"><?= ucfirst(e($p['payment_method'])) ?></span></td>
                            <td>
                                <?= e($p['bank_name']) ?><br>
                                <code><?= e($p['bank_ref'] ?: '-') ?></code>
                            </td>
                            <td class="text-right font-weight-bold" style="color: var(--primary); font-size: 13.5px;">
                                <?= formatRupiah($p['amount']) ?>
                            </td>
                            <td><?= e($p['creator_name']) ?></td>
                            <td class="text-center"><?= getStatusBadge($p['status']) ?></td>
                            <td class="text-center" style="white-space: nowrap;">
                                <button type="button" class="btn btn-sm btn-secondary" 
                                        onclick="showPaymentDetail(<?= htmlspecialchars(json_encode([
                                            'number' => $p['payment_number'],
                                            'date' => formatDate($p['payment_date']),
                                            'supplier' => $p['supplier_name'] . ' (' . $p['supplier_code'] . ')',
                                            'method' => ucfirst($p['payment_method']) . ' - ' . $p['bank_name'],
                                            'bank_ref' => $p['bank_ref'] ?: '-',
                                            'amount' => formatRupiah($p['amount']),
                                            'invoices' => $p['invoice_allocations'] ?: '-',
                                            'creator' => $p['creator_name'] ?: '-',
                                            'status' => $p['status'],
                                            'notes' => $p['notes'] ?: '-'
                                        ]), ENT_QUOTES, 'UTF-8') ?>)">
                                    Detail
                                </button>
                                <?php if ($p['status'] !== 'cancelled'): ?>
                                    <button type="button" class="btn btn-sm btn-danger" 
                                            onclick="openVoidModal(<?= $p['id'] ?>, '<?= e($p['payment_number']) ?>')">
                                        Void
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer">
        <?= renderPagination($pagination, 'index.php?page=expenditure_procurement/payments&q=' . urlencode($search)) ?>
    </div>
</div>

<!-- Modal Detail Pembayaran -->
<div id="payment-detail-modal" class="modal-overlay">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title" id="pdm-title">Detail Pembayaran Pemasok</h3>
            <button type="button" class="modal-close" onclick="closeModal('payment-detail-modal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="detail-grid" style="grid-template-columns: 1fr 1fr; margin-bottom: 16px;">
                <div class="detail-item">
                    <div class="detail-label">Nomor Pembayaran</div>
                    <div class="detail-value"><code id="pdm-number"></code></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Tanggal Pembayaran</div>
                    <div class="detail-value" id="pdm-date"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Pemasok</div>
                    <div class="detail-value" id="pdm-supplier"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Total Pembayaran</div>
                    <div class="detail-value" id="pdm-amount" style="color: var(--primary); font-weight: 700;"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Metode & Bank</div>
                    <div class="detail-value" id="pdm-method"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">No. Referensi Bank</div>
                    <div class="detail-value"><code id="pdm-ref"></code></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Petugas Treasury</div>
                    <div class="detail-value" id="pdm-creator"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Status</div>
                    <div class="detail-value" id="pdm-status"></div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Faktur Pemasok Terbayar</label>
                <div id="pdm-invoices" style="padding: 8px 12px; background: #f8fafc; border-radius: 4px; font-size: 13px;"></div>
            </div>
            <div class="form-group">
                <label class="form-label">Catatan</label>
                <div id="pdm-notes" style="padding: 8px 12px; background: #f8fafc; border-radius: 4px; font-size: 13px;"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('payment-detail-modal')">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal Void / Batalkan Pembayaran -->
<div id="void-payment-modal" class="modal-overlay">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Void / Batalkan Pembayaran: <span id="vpm-number"></span></h3>
            <button type="button" class="modal-close" onclick="closeModal('void-payment-modal')">&times;</button>
        </div>
        <form action="index.php?page=expenditure_procurement/payments" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="void_payment">
            <input type="hidden" name="payment_id" id="vpm-id" value="">
            <div class="modal-body">
                <p style="color: var(--danger); font-size: 13.5px; margin-bottom: 12px;">
                    Perhatian: Membatalkan pembayaran ini akan mengembalikan saldo tagihan faktur pemasok dan secara otomatis menerbitkan Jurnal Pembalik (Reversing Entry) pada buku besar kas/bank.
                </p>
                <div class="form-group">
                    <label class="form-label required">Alasan Pembatalan / Void</label>
                    <textarea name="void_reason" class="form-control" rows="3" required placeholder="Contoh: Kesalahan rekening tujuan transfer, cek gagal kliring..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('void-payment-modal')">Batal</button>
                <button type="submit" class="btn btn-danger">Ya, Void & Balikkan Jurnal</button>
            </div>
        </form>
    </div>
</div>

<script>
function showPaymentDetail(data) {
    document.getElementById('pdm-title').textContent = 'Detail Pembayaran: ' + data.number;
    document.getElementById('pdm-number').textContent = data.number;
    document.getElementById('pdm-date').textContent = data.date;
    document.getElementById('pdm-supplier').textContent = data.supplier;
    document.getElementById('pdm-amount').textContent = data.amount;
    document.getElementById('pdm-method').textContent = data.method;
    document.getElementById('pdm-ref').textContent = data.bank_ref;
    document.getElementById('pdm-creator').textContent = data.creator;
    document.getElementById('pdm-status').innerHTML = '<span class="badge badge-' + (data.status === 'posted' ? 'success' : 'danger') + '">' + data.status + '</span>';
    document.getElementById('pdm-invoices').textContent = data.invoices;
    document.getElementById('pdm-notes').textContent = data.notes;
    openModal('payment-detail-modal');
}

function openVoidModal(id, number) {
    document.getElementById('vpm-id').value = id;
    document.getElementById('vpm-number').textContent = number;
    openModal('void-payment-modal');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
