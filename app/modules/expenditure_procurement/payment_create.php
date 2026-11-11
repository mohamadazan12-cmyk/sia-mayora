<?php
/**
 * Modul Pengeluaran: Catat Pembayaran / Pelunasan Utang Pemasok
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Catat Pembayaran Pemasok';
$breadcrumbCategory = 'Pengeluaran';

$db = getDbConnection();
$user = currentUser();

// Fetch unpaid or partially paid supplier invoices
$unpaidInvoices = $db->query("
    SELECT si.*, s.name AS supplier_name, s.code AS supplier_code
    FROM supplier_invoices si
    JOIN suppliers s ON si.supplier_id = s.id
    WHERE si.status IN ('verified', 'matched', 'partially_paid') AND si.balance > 0
    ORDER BY si.due_date ASC
")->fetchAll();

$selectedInvId = (int)($_GET['invoice_id'] ?? ($_POST['supplier_invoice_id'] ?? 0));
$selectedInvoice = null;

if ($selectedInvId > 0) {
    $stmtInv = $db->prepare("
        SELECT si.*, s.name AS supplier_name, s.code AS supplier_code 
        FROM supplier_invoices si 
        JOIN suppliers s ON si.supplier_id = s.id 
        WHERE si.id = :id AND si.balance > 0
    ");
    $stmtInv->execute([':id' => $selectedInvId]);
    $selectedInvoice = $stmtInv->fetch();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {
    requireCsrf();

    $invoiceId     = (int)($_POST['supplier_invoice_id'] ?? 0);
    $paymentDate   = trim($_POST['payment_date'] ?? date('Y-m-d'));
    $paymentMethod = trim($_POST['payment_method'] ?? 'transfer');
    $bankName      = trim($_POST['bank_name'] ?? 'Bank BCA Operasional');
    $bankRef       = trim($_POST['bank_ref'] ?? '');
    $amount        = parseDecimal($_POST['amount'] ?? 0);
    $notes         = trim($_POST['notes'] ?? '');

    // Refetch invoice to check live balance with lock
    if ($invoiceId <= 0) {
        $errors[] = 'Faktur tagihan pemasok wajib dipilih.';
    }
    if (empty($paymentDate)) {
        $errors[] = 'Tanggal pembayaran wajib diisi.';
    }
    if ($amount <= 0) {
        $errors[] = 'Nominal pembayaran harus lebih besar dari 0.';
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $stmtLock = $db->prepare("SELECT * FROM supplier_invoices WHERE id = :id FOR UPDATE");
            $stmtLock->execute([':id' => $invoiceId]);
            $invRow = $stmtLock->fetch();

            if (!$invRow) {
                throw new Exception('Data faktur pemasok tidak ditemukan.');
            }

            $currentBalance = (float)$invRow['balance'];

            // Validation against over-allocation (PRD 9.3, 20)
            if ($amount > $currentBalance) {
                throw new Exception("Nominal pembayaran (" . formatRupiah($amount) . ") melebihi sisa utang faktur (" . formatRupiah($currentBalance) . "). Sistem menolak kelebihan alokasi.");
            }

            $paymentNumber = generateDocNumber('SP', 'supplier_payments', 'payment_number');

            // Insert Supplier Payment
            $stmtSp = $db->prepare("
                INSERT INTO supplier_payments 
                (payment_number, supplier_id, payment_date, amount, payment_method, bank_ref, bank_name, notes, status, created_by, created_at)
                VALUES 
                (:pnum, :supp_id, :pdate, :amount, :pmethod, :bref, :bname, :notes, 'posted', :created_by, NOW())
            ");
            $stmtSp->execute([
                ':pnum'       => $paymentNumber,
                ':supp_id'    => $invRow['supplier_id'],
                ':pdate'      => $paymentDate,
                ':amount'     => $amount,
                ':pmethod'    => $paymentMethod,
                ':bref'       => $bankRef,
                ':bname'      => $bankName,
                ':notes'      => $notes,
                ':created_by' => $user['id'],
            ]);
            $paymentId = $db->lastInsertId();

            // Insert Allocation
            $stmtAlloc = $db->prepare("
                INSERT INTO supplier_payment_allocations (supplier_payment_id, supplier_invoice_id, allocated_amount)
                VALUES (:sp_id, :inv_id, :alloc_amt)
            ");
            $stmtAlloc->execute([
                ':sp_id'     => $paymentId,
                ':inv_id'    => $invoiceId,
                ':alloc_amt' => $amount,
            ]);

            // Update Supplier Invoice balance and status
            $newPaid = (float)$invRow['paid_amount'] + $amount;
            $newBalance = (float)$invRow['total_amount'] - $newPaid;
            $newStatus = ($newBalance <= 0.01) ? 'paid' : 'partially_paid';

            $stmtUpdInv = $db->prepare("
                UPDATE supplier_invoices 
                SET paid_amount = :paid, balance = :bal, status = :status, updated_at = NOW(), updated_by = :uid 
                WHERE id = :id
            ");
            $stmtUpdInv->execute([
                ':paid'   => $newPaid,
                ':bal'    => max(0, $newBalance),
                ':status' => $newStatus,
                ':uid'    => $user['id'],
                ':id'     => $invoiceId,
            ]);

            // Accounting Journal (PRD 13)
            // Debit: Utang Usaha (Akun 2100 / ID 11)
            // Credit: Kas & Bank (Akun 1120 / Bank BCA / ID 3)
            $accUtang = JournalService::getAccountIdByCode('2100', $db) ?: 11;
            $accBank  = JournalService::getAccountIdByCode('1120', $db) ?: 3;

            $journalLines = [
                [
                    'account_id'  => $accUtang,
                    'description' => "Pelunasan utang pemasok {$invRow['invoice_number']} ({$invRow['supplier_invoice_number']})",
                    'debit'       => $amount,
                    'credit'      => 0.00,
                ],
                [
                    'account_id'  => $accBank,
                    'description' => "Pengeluaran kas/bank ({$bankName} - Ref: {$bankRef})",
                    'debit'       => 0.00,
                    'credit'      => $amount,
                ],
            ];

            JournalService::createEntry(
                $paymentDate,
                'supplier_payment',
                $paymentId,
                $paymentNumber,
                "Pembayaran Utang Pemasok {$paymentNumber} - {$invRow['supplier_invoice_number']}",
                $journalLines,
                $user['id'],
                true,
                $db
            );

            logAudit($user['id'], 'create', 'supplier_payments', $paymentId, null, [
                'payment_number' => $paymentNumber,
                'invoice_id'     => $invoiceId,
                'amount'         => $amount,
                'new_balance'    => $newBalance,
                'new_status'     => $newStatus,
            ]);

            $db->commit();

            setFlash('success', "Pembayaran {$paymentNumber} sebesar " . formatRupiah($amount) . " berhasil dicatat. Sisa saldo utang faktur kini: " . formatRupiah($newBalance) . " (" . ($newStatus === 'paid' ? 'Lunas' : 'Lunas Sebagian') . ").");
            header('Location: index.php?page=expenditure_procurement/invoice_detail&id=' . $invoiceId);
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Gagal memproses pembayaran: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Pencatatan Pelunasan Utang Pemasok</h1>
        <div class="page-subtitle">Siklus Pengeluaran &bull; Transfer Kas/Bank dan Alokasi ke Faktur Pembelian</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=expenditure_procurement/payments" class="btn btn-secondary">&laquo; Kembali ke Daftar Pembayaran</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <strong>Terjadi Kesalahan:</strong>
        <ul style="margin-left: 20px; margin-top: 6px;">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <span class="card-title">Langkah 1: Pilih Faktur Utang yang Akan Dibayar</span>
    </div>
    <div class="card-body">
        <form action="index.php" method="GET" class="filter-bar" style="margin-bottom: 0;">
            <input type="hidden" name="page" value="expenditure_procurement/payment_create">
            <div class="filter-group" style="flex: 1;">
                <select name="invoice_id" class="form-control" style="width: 100%;" onchange="this.form.submit()">
                    <option value="">-- Pilih Faktur Tagihan Pemasok --</option>
                    <?php foreach ($unpaidInvoices as $uinv): ?>
                        <option value="<?= $uinv['id'] ?>" <?= ($selectedInvoice && $selectedInvoice['id'] == $uinv['id']) ? 'selected' : '' ?>>
                            <?= e($uinv['invoice_number']) ?> (No Vendor: <?= e($uinv['supplier_invoice_number']) ?>) &bull; <?= e($uinv['supplier_name']) ?> &bull; Sisa Utang: <?= formatRupiah($uinv['balance']) ?> (Tempo: <?= formatDate($uinv['due_date']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">Pilih Faktur</button>
        </form>
    </div>
</div>

<?php if ($selectedInvoice): ?>
    <form action="index.php?page=expenditure_procurement/payment_create&invoice_id=<?= $selectedInvoice['id'] ?>" method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="supplier_invoice_id" value="<?= $selectedInvoice['id'] ?>">

        <div class="card">
            <div class="card-header">
                <span class="card-title">Langkah 2: Rincian Pembayaran Kas / Bank</span>
            </div>
            <div class="card-body">
                <div class="detail-grid" style="margin-bottom: 18px;">
                    <div class="detail-item">
                        <div class="detail-label">Pemasok Penerima</div>
                        <div class="detail-value"><?= e($selectedInvoice['supplier_name']) ?> (<?= e($selectedInvoice['supplier_code']) ?>)</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Total Tagihan Faktur</div>
                        <div class="detail-value"><?= formatRupiah($selectedInvoice['total_amount']) ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Telah Dibayar Sebelumnya</div>
                        <div class="detail-value"><?= formatRupiah($selectedInvoice['paid_amount']) ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Sisa Saldo Utang Saat Ini</div>
                        <div class="detail-value" style="color: var(--danger); font-size: 16px;">
                            <?= formatRupiah($selectedInvoice['balance']) ?>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label required">Tanggal Pembayaran</label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label required">Metode Pembayaran</label>
                            <select name="payment_method" class="form-control" required>
                                <option value="transfer">Transfer Bank</option>
                                <option value="cash">Kas Tunai</option>
                                <option value="giro">Bilyet Giro</option>
                                <option value="cheque">Cek Bank</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label required">Akun Kas / Bank Pengeluaran</label>
                            <input type="text" name="bank_name" class="form-control" value="Bank BCA Operasional" required>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label required">Nominal Pembayaran (Rp)</label>
                            <input type="text" name="amount" class="form-control text-right" style="font-size: 15px; font-weight: 600;" value="<?= formatNumber($selectedInvoice['balance']) ?>" required>
                            <div class="form-text">Boleh dibayar sebagian atau lunas (Maksimum: <?= formatRupiah($selectedInvoice['balance']) ?>)</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="form-label">Nomor Referensi Bank / No. Bukti Transfer</label>
                            <input type="text" name="bank_ref" class="form-control" placeholder="Contoh: TRF-BCA-202610-9988">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Catatan Pembayaran</label>
                    <input type="text" name="notes" class="form-control" placeholder="Keterangan pengesahan atau nomor rekening tujuan vendor">
                </div>
            </div>
            <div class="card-footer" style="display: flex; justify-content: flex-end;">
                <button type="submit" name="submit_payment" value="1" class="btn btn-success" style="font-size: 14px; font-weight: 600;">
                    ✓ Proses Pelunasan Utang & Catat Jurnal
                </button>
            </div>
        </div>
    </form>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
