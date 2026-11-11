<?php
/**
 * Modul Penerimaan: Pencatatan Kwitansi Penerimaan Kas Pelanggan
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Catat Penerimaan Kas Pelanggan';
$breadcrumbCategory = 'Penerimaan';

$db = getDbConnection();
$user = currentUser();

$selectedCustomerId = (int)($_GET['customer_id'] ?? 0);
$preselectedInvoiceId = (int)($_GET['invoice_id'] ?? 0);

// Fetch customers who have outstanding invoices
$customersWithDebts = $db->query("
    SELECT DISTINCT c.id, c.code, c.name, c.credit_limit, c.credit_used,
           (SELECT COUNT(*) FROM sales_invoices inv WHERE inv.customer_id = c.id AND inv.balance > 0 AND inv.status IN ('issued','partially_paid','overdue')) AS unpaid_count,
           (SELECT SUM(inv.balance) FROM sales_invoices inv WHERE inv.customer_id = c.id AND inv.balance > 0 AND inv.status IN ('issued','partially_paid','overdue')) AS total_debt
    FROM customers c
    JOIN sales_invoices inv ON inv.customer_id = c.id
    WHERE inv.balance > 0 AND inv.status IN ('issued','partially_paid','overdue')
    ORDER BY c.name ASC
")->fetchAll();

$selectedCustomer = null;
$unpaidInvoices = [];

if ($selectedCustomerId > 0) {
    $stmtCust = $db->prepare("SELECT * FROM customers WHERE id = :id");
    $stmtCust->execute([':id' => $selectedCustomerId]);
    $selectedCustomer = $stmtCust->fetch();

    if ($selectedCustomer) {
        $stmtInvs = $db->prepare("
            SELECT inv.*, so.so_number
            FROM sales_invoices inv
            LEFT JOIN sales_orders so ON inv.sales_order_id = so.id
            WHERE inv.customer_id = :cust_id AND inv.balance > 0 AND inv.status IN ('issued','partially_paid','overdue')
            ORDER BY inv.due_date ASC, inv.id ASC
        ");
        $stmtInvs->execute([':cust_id' => $selectedCustomerId]);
        $unpaidInvoices = $stmtInvs->fetchAll();
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $custId = (int)($_POST['customer_id'] ?? 0);
    $receiptDate = trim($_POST['receipt_date'] ?? date('Y-m-d'));
    $paymentMethod = trim($_POST['payment_method'] ?? 'transfer');
    $bankName = trim($_POST['bank_name'] ?? 'Bank BCA Giro Operasional');
    $bankRef = trim($_POST['bank_ref'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $allocInputs = $_POST['allocations'] ?? [];

    $errors = [];

    if ($custId <= 0) {
        $errors[] = 'Silakan pilih Pelanggan yang melakukan pembayaran.';
    }
    if (empty($receiptDate)) {
        $errors[] = 'Tanggal penerimaan kas wajib diisi.';
    }

    $validAllocations = [];
    $totalAmount = 0.0;

    foreach ($allocInputs as $invId => $allocData) {
        $amount = (float)($allocData['amount'] ?? 0);
        if ($amount <= 0) {
            continue;
        }

        // Verify invoice balance
        $stmtInvCheck = $db->prepare("SELECT id, invoice_number, balance FROM sales_invoices WHERE id = :id AND customer_id = :cust_id FOR UPDATE");
        $stmtInvCheck->execute([':id' => $invId, ':cust_id' => $custId]);
        $invRecord = $stmtInvCheck->fetch();

        if (!$invRecord) {
            $errors[] = "Faktur ID #{$invId} tidak valid.";
            continue;
        }

        $currentBal = (float)$invRecord['balance'];
        if ($amount > $currentBal + 0.01) {
            $errors[] = "Alokasi pembayaran untuk faktur {$invRecord['invoice_number']} (" . formatRupiah($amount) . ") melebihi sisa tagihan (" . formatRupiah($currentBal) . ").";
        }

        $validAllocations[] = [
            'invoice_id'       => (int)$invRecord['id'],
            'invoice_number'   => $invRecord['invoice_number'],
            'allocated_amount' => $amount,
            'current_balance'  => $currentBal,
        ];
        $totalAmount += $amount;
    }

    if (empty($validAllocations) && empty($errors)) {
        $errors[] = 'Jumlah pembayaran pada faktur belum dialokasikan.';
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // Generate Receipt Number: CR-YYYYMM-XXXX
            $yearMonth = date('Ym', strtotime($receiptDate));
            $prefix = 'CR-' . $yearMonth;
            $stmtLast = $db->prepare("SELECT receipt_number FROM customer_receipts WHERE receipt_number LIKE :pfx ORDER BY id DESC LIMIT 1");
            $stmtLast->execute([':pfx' => $prefix . '-%']);
            $lastCr = $stmtLast->fetchColumn();
            $seq = 1;
            if ($lastCr) {
                $parts = explode('-', $lastCr);
                $seq = (int)end($parts) + 1;
            }
            $receiptNumber = sprintf('%s-%04d', $prefix, $seq);

            // Insert Customer Receipt
            $stmtInsertCr = $db->prepare("
                INSERT INTO customer_receipts 
                (receipt_number, customer_id, receipt_date, total_amount, payment_method, bank_ref, bank_name, notes, status, verified_by, verified_at, created_by, created_at)
                VALUES 
                (:r_num, :cust_id, :r_date, :total, :method, :b_ref, :b_name, :notes, 'posted', :v_by, NOW(), :c_by, NOW())
            ");
            $stmtInsertCr->execute([
                ':r_num'   => $receiptNumber,
                ':cust_id' => $custId,
                ':r_date'  => $receiptDate,
                ':total'   => $totalAmount,
                ':method'  => $paymentMethod,
                ':b_ref'   => $bankRef ?: null,
                ':b_name'  => $bankName,
                ':notes'   => $notes,
                ':v_by'    => $user['id'],
                ':c_by'    => $user['id'],
            ]);
            $receiptId = $db->lastInsertId();

            // Insert Allocations & Update Invoices
            $stmtAlloc = $db->prepare("
                INSERT INTO customer_receipt_allocations (customer_receipt_id, sales_invoice_id, allocated_amount)
                VALUES (:cr_id, :inv_id, :amount)
            ");

            $stmtUpdInv = $db->prepare("
                UPDATE sales_invoices 
                SET paid_amount = paid_amount + :amt,
                    balance = GREATEST(0, balance - :amt),
                    status = CASE WHEN (balance - :amt) <= 0.001 THEN 'paid' ELSE 'partially_paid' END,
                    updated_at = NOW(),
                    updated_by = :uid
                WHERE id = :inv_id
            ");

            foreach ($validAllocations as $alloc) {
                $stmtAlloc->execute([
                    ':cr_id'  => $receiptId,
                    ':inv_id' => $alloc['invoice_id'],
                    ':amount' => $alloc['allocated_amount'],
                ]);

                $stmtUpdInv->execute([
                    ':amt'    => $alloc['allocated_amount'],
                    ':uid'    => $user['id'],
                    ':inv_id' => $alloc['invoice_id'],
                ]);
            }

            // Release customer credit_used
            $db->prepare("UPDATE customers SET credit_used = GREATEST(0, credit_used - :amount) WHERE id = :cust_id")
               ->execute([':amount' => $totalAmount, ':cust_id' => $custId]);

            // Balanced Accounting Journal:
            // Debit: Kas Kecil (ID 4) if cash, or Bank BCA Giro (ID 5) if bank/transfer/other
            // Credit: Piutang Usaha Pelanggan Domestik (ID 8) = totalAmount
            $debitAccId = ($paymentMethod === 'cash') ? 4 : 5;
            $debitAccName = ($paymentMethod === 'cash') ? 'Kas Kecil' : 'Bank BCA Giro Operasional';

            $journalLines = [
                [
                    'account_id'  => $debitAccId,
                    'description' => "Penerimaan Kas/Bank Bukti {$receiptNumber} ({$debitAccName})",
                    'debit'       => $totalAmount,
                    'credit'      => 0,
                ],
                [
                    'account_id'  => 8, // Piutang Usaha
                    'description' => "Pelunasan Piutang Pelanggan {$receiptNumber}",
                    'debit'       => 0,
                    'credit'      => $totalAmount,
                ],
            ];

            JournalService::createEntry(
                $receiptDate,
                'customer_receipt',
                $receiptId,
                $receiptNumber,
                "Penerimaan Kas Pelanggan {$receiptNumber} dari " . ($selectedCustomer['name'] ?? 'Pelanggan'),
                $journalLines,
                $user['id'],
                true,
                $db
            );

            logAudit($user['id'], 'create_customer_receipt', 'customer_receipts', $receiptId, null, [
                'receipt_number' => $receiptNumber,
                'total_amount'   => $totalAmount,
                'customer_id'    => $custId,
            ]);

            $db->commit();
            setFlash('success', "Penerimaan Kas {$receiptNumber} sebesar " . formatRupiah($totalAmount) . " berhasil dicatat dan diposting ke jurnal buku besar.");
            header("Location: index.php?page=receiving_sales/receipt_detail&id={$receiptId}");
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Gagal mencatat penerimaan kas: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Catat Penerimaan Kas Pelanggan (Customer Receipt)</h1>
        <div class="page-subtitle">Siklus Penerimaan &bull; Pengakuan Kas/Bank Masuk, Pengurangan Saldo Piutang, dan Pembebasan Limit Kredit</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=receiving_sales/receipts" class="btn btn-secondary">
            &larr; Kembali ke Daftar Bukti Kas Masuk
        </a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul style="margin: 0; padding-left: 20px;">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Step 1: Customer Selection -->
<div class="card mb-4">
    <div class="card-header">
        <h2 class="card-title">1. Pilih Pelanggan &amp; Periksa Saldo Piutang</h2>
    </div>
    <div class="card-body">
        <form action="index.php" method="GET" style="display:flex; gap:15px; align-items:flex-end;">
            <input type="hidden" name="page" value="receiving_sales/receipt_create">
            <div class="form-group" style="flex:1; margin-bottom:0;">
                <label>Pelanggan yang Memiliki Tagihan Aktif</label>
                <select name="customer_id" required>
                    <option value="">-- Pilih Pelanggan --</option>
                    <?php foreach ($customersWithDebts as $cwd): ?>
                        <option value="<?= $cwd['id'] ?>" <?= $selectedCustomerId === (int)$cwd['id'] ? 'selected' : '' ?>>
                            <?= e($cwd['name']) ?> (<?= e($cwd['code']) ?>) &bull; <?= (int)$cwd['unpaid_count'] ?> Faktur &bull; Total Piutang: <?= formatRupiah((float)$cwd['total_debt']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="height:42px;">Muat Faktur &rarr;</button>
        </form>
    </div>
</div>

<?php if ($selectedCustomer): ?>
<form action="index.php?page=receiving_sales/receipt_create&customer_id=<?= $selectedCustomerId ?>" method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="customer_id" value="<?= $selectedCustomerId ?>">

    <!-- Details Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">2. Data Kwitansi &amp; Rekening Kas/Bank Masuk</h2>
        </div>
        <div class="card-body">
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap:15px;">
                <div class="form-group">
                    <label>Pelanggan</label>
                    <input type="text" value="<?= e($selectedCustomer['name']) ?> (<?= e($selectedCustomer['code']) ?>)" readonly style="background:#f1f5f9;">
                </div>
                <div class="form-group">
                    <label>Tanggal Kas Masuk *</label>
                    <input type="date" name="receipt_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>Metode Pembayaran *</label>
                    <select name="payment_method" required>
                        <option value="transfer">Transfer Bank (BCA / Mandiri)</option>
                        <option value="cash">Kas Tunai (Kasir / Kas Kecil)</option>
                        <option value="giro">Bilyet Giro</option>
                        <option value="cheque">Cek Bank</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Rekening Bank / Kas Tujuan</label>
                    <input type="text" name="bank_name" value="Bank BCA Giro Operasional" required>
                </div>
                <div class="form-group">
                    <label>No. Referensi / Ref Mutasi Bank</label>
                    <input type="text" name="bank_ref" placeholder="Misal: TRF-BCA-20261015-8891">
                </div>
                <div class="form-group">
                    <label>Sisa Limit Kredit Saat Ini</label>
                    <input type="text" value="Limit: <?= formatRupiah((float)$selectedCustomer['credit_limit']) ?> | Terpakai: <?= formatRupiah((float)$selectedCustomer['credit_used']) ?>" readonly style="background:#f1f5f9;">
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label>Catatan Kwitansi</label>
                    <input type="text" name="notes" placeholder="Catatan pelunasan, nama penyetor...">
                </div>
            </div>
        </div>
    </div>

    <!-- Invoices Allocation Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">3. Alokasi Pembayaran ke Faktur Penjualan</h2>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>No. Faktur</th>
                            <th>Tgl Faktur</th>
                            <th>Jatuh Tempo</th>
                            <th class="text-right">Total Tagihan</th>
                            <th class="text-right">Sudah Dibayar</th>
                            <th class="text-right">Sisa Tagihan (Balance)</th>
                            <th style="width: 220px;" class="text-right">Jumlah Alokasi Bayar (Rp) *</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($unpaidInvoices)): ?>
                            <tr>
                                <td colspan="7" class="text-center" style="padding:20px; color:#16a34a;">
                                    Pelanggan ini tidak memiliki faktur tertunggak.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($unpaidInvoices as $inv): ?>
                                <?php 
                                    $bal = (float)$inv['balance'];
                                    $defaultAlloc = ($preselectedInvoiceId === (int)$inv['id']) ? $bal : 0;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= e($inv['invoice_number']) ?></strong>
                                        <div class="text-muted" style="font-size:11px;">SO: <?= e($inv['so_number'] ?: '-') ?></div>
                                    </td>
                                    <td><?= formatDate($inv['invoice_date']) ?></td>
                                    <td>
                                        <span style="<?= (strtotime($inv['due_date']) < time()) ? 'color:#dc2626; font-weight:600;' : '' ?>">
                                            <?= formatDate($inv['due_date']) ?>
                                        </span>
                                    </td>
                                    <td class="text-right font-medium"><?= formatRupiah((float)$inv['total_amount']) ?></td>
                                    <td class="text-right" style="color:#16a34a;"><?= formatRupiah((float)$inv['paid_amount']) ?></td>
                                    <td class="text-right font-bold" style="color:#ea580c;"><?= formatRupiah($bal) ?></td>
                                    <td>
                                        <input type="number" step="0.01" min="0" max="<?= $bal ?>" 
                                               name="allocations[<?= $inv['id'] ?>][amount]" 
                                               value="<?= $defaultAlloc ?>" 
                                               style="text-align:right; font-weight:600;" placeholder="0">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer" style="padding: 15px 20px; background:#fff; display:flex; justify-content:flex-end; gap:10px;">
            <a href="index.php?page=receiving_sales/receipts" class="btn btn-secondary">Batal</a>
            <button type="submit" class="btn btn-primary" <?= empty($unpaidInvoices) ? 'disabled' : '' ?>>
                Posting Penerimaan Kas &amp; Alokasikan &rarr;
            </button>
        </div>
    </div>
</form>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
