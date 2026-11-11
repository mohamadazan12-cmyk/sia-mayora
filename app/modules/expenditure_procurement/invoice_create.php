<?php
/**
 * Modul Pengeluaran: Catat Faktur Pemasok & Three-Way Matching
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Catat Faktur Pemasok & Three-Way Match';
$breadcrumbCategory = 'Pengeluaran';

$db = getDbConnection();
$user = currentUser();

// Fetch GRs that don't have an invoice yet
$grList = $db->query("
    SELECT gr.*, s.name AS supplier_name, po.po_number, po.total_amount AS po_total
    FROM goods_receipts gr
    JOIN suppliers s ON gr.supplier_id = s.id
    JOIN purchase_orders po ON gr.purchase_order_id = po.id
    LEFT JOIN supplier_invoices si ON gr.id = si.goods_receipt_id
    WHERE gr.status IN ('accepted', 'partially_accepted') AND si.id IS NULL
    ORDER BY gr.id DESC
")->fetchAll();

$selectedGrId = (int)($_GET['gr_id'] ?? ($_POST['goods_receipt_id'] ?? 0));
$selectedGr = null;
$grItems = [];
$expectedTotal = 0.0;

if ($selectedGrId > 0) {
    $stmtGr = $db->prepare("
        SELECT gr.*, s.name AS supplier_name, s.payment_terms_days,
               po.po_number, po.po_date, po.id AS po_id
        FROM goods_receipts gr
        JOIN suppliers s ON gr.supplier_id = s.id
        JOIN purchase_orders po ON gr.purchase_order_id = po.id
        WHERE gr.id = :id
    ");
    $stmtGr->execute([':id' => $selectedGrId]);
    $selectedGr = $stmtGr->fetch();

    if ($selectedGr) {
        $stmtItems = $db->prepare("
            SELECT gri.*, poi.unit_price, poi.qty_ordered, (gri.qty_accepted * poi.unit_price) AS expected_subtotal,
                   i.code AS item_code, i.name AS item_name, u.name AS unit_name
            FROM goods_receipt_items gri
            JOIN purchase_order_items poi ON gri.purchase_order_item_id = poi.id
            JOIN items i ON gri.item_id = i.id
            LEFT JOIN units u ON i.unit_id = u.id
            WHERE gri.goods_receipt_id = :gr_id
        ");
        $stmtItems->execute([':gr_id' => $selectedGrId]);
        $grItems = $stmtItems->fetchAll();

        foreach ($grItems as $gi) {
            $expectedTotal += (float)$gi['expected_subtotal'];
        }
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_invoice'])) {
    requireCsrf();

    $suppInvNum  = trim($_POST['supplier_invoice_number'] ?? '');
    $invoiceDate = trim($_POST['invoice_date'] ?? date('Y-m-d'));
    $dueDate     = trim($_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days')));
    $subtotal    = parseDecimal($_POST['subtotal'] ?? 0);
    $taxAmount   = parseDecimal($_POST['tax_amount'] ?? 0);
    $totalAmount = $subtotal + $taxAmount;
    $taxInvoice  = trim($_POST['tax_invoice_number'] ?? '');
    $notes       = trim($_POST['notes'] ?? '');

    if (!$selectedGr) {
        $errors[] = 'Silakan pilih dokumen Goods Receipt (GR) yang valid.';
    }
    if (empty($suppInvNum)) {
        $errors[] = 'Nomor faktur dari pemasok (Vendor Invoice #) wajib diisi.';
    }
    if ($totalAmount <= 0) {
        $errors[] = 'Nominal tagihan harus lebih besar dari 0.';
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $invoiceNumber = generateDocNumber('VINV', 'supplier_invoices', 'invoice_number');

            // Three-Way Match Calculation
            $valueDiff = abs($totalAmount - $expectedTotal);
            $matchStatus = 'matched';
            $matchNotes = 'Kuantitas dan nilai tagihan sesuai dengan PO dan GR.';

            if ($valueDiff > 1.0) { // Tolerance Rp 1
                $matchStatus = 'price_discrepancy';
                $matchNotes = "Terdapat selisih nilai sebesar " . formatRupiah($valueDiff) . " antara tagihan pemasok (" . formatRupiah($totalAmount) . ") dan nilai barang GR (" . formatRupiah($expectedTotal) . ").";
            }

            // Insert Supplier Invoice
            $stmtInv = $db->prepare("
                INSERT INTO supplier_invoices 
                (invoice_number, supplier_id, purchase_order_id, goods_receipt_id, supplier_invoice_number, 
                 invoice_date, due_date, subtotal, tax_amount, total_amount, paid_amount, balance, tax_invoice_number, notes, status, created_by, created_at)
                VALUES 
                (:inv_num, :supp_id, :po_id, :gr_id, :supp_inv_num,
                 :inv_date, :due_date, :subtotal, :tax_amt, :total_amt, 0, :balance, :tax_inv, :notes, :status, :created_by, NOW())
            ");
            $stmtInv->execute([
                ':inv_num'      => $invoiceNumber,
                ':supp_id'      => $selectedGr['supplier_id'],
                ':po_id'        => $selectedGr['po_id'],
                ':gr_id'        => $selectedGr['id'],
                ':supp_inv_num' => $suppInvNum,
                ':inv_date'     => $invoiceDate,
                ':due_date'     => $dueDate,
                ':subtotal'     => $subtotal,
                ':tax_amt'      => $taxAmount,
                ':total_amt'    => $totalAmount,
                ':balance'      => $totalAmount,
                ':tax_inv'      => $taxInvoice,
                ':notes'        => $notes,
                ':status'       => $matchStatus === 'matched' ? 'matched' : 'disputed',
                ':created_by'   => $user['id'],
            ]);
            $invId = $db->lastInsertId();

            // Insert Three-Way Match record
            $stmtTwm = $db->prepare("
                INSERT INTO three_way_matches 
                (supplier_invoice_id, purchase_order_id, goods_receipt_id, match_status, qty_diff, value_diff, notes, verified_by, verified_at, created_at)
                VALUES 
                (:inv_id, :po_id, :gr_id, :status, 0, :val_diff, :notes, :verified_by, NOW(), NOW())
            ");
            $stmtTwm->execute([
                ':inv_id'      => $invId,
                ':po_id'       => $selectedGr['po_id'],
                ':gr_id'       => $selectedGr['id'],
                ':status'      => $matchStatus,
                ':val_diff'    => $valueDiff,
                ':notes'       => $matchNotes,
                ':verified_by' => $user['id'],
            ]);

            // Create AP Voucher (Pengakuan Utang Resmi)
            $voucherNumber = generateDocNumber('AP', 'ap_vouchers', 'voucher_number');
            $stmtApv = $db->prepare("
                INSERT INTO ap_vouchers 
                (voucher_number, supplier_invoice_id, supplier_id, voucher_date, amount, notes, status, created_by, created_at)
                VALUES 
                (:vouch_num, :inv_id, :supp_id, :vouch_date, :amount, :notes, 'verified', :created_by, NOW())
            ");
            $stmtApv->execute([
                ':vouch_num'   => $voucherNumber,
                ':inv_id'      => $invId,
                ':supp_id'     => $selectedGr['supplier_id'],
                ':vouch_date'  => $invoiceDate,
                ':amount'      => $totalAmount,
                ':notes'       => 'Pencatatan utang usaha pemasok dari ' . $selectedGr['gr_number'],
                ':created_by'  => $user['id'],
            ]);

            // Automatic Internal Accounting Journal (PRD 13: Procurement / GR)
            // Debit: Persediaan Bahan Baku (Akun 1310 / ID 7)
            // Credit: Utang Usaha (Akun 2100 / ID 11)
            $accPersediaan = JournalService::getAccountIdByCode('1310', $db) ?: 7;
            $accUtang      = JournalService::getAccountIdByCode('2100', $db) ?: 11;

            $journalLines = [
                [
                    'account_id'  => $accPersediaan,
                    'description' => "Penerimaan persediaan bahan baku {$selectedGr['gr_number']} ({$suppInvNum})",
                    'debit'       => $totalAmount,
                    'credit'      => 0.00,
                ],
                [
                    'account_id'  => $accUtang,
                    'description' => "Pengakuan utang pemasok {$selectedGr['supplier_name']} ({$voucherNumber})",
                    'debit'       => 0.00,
                    'credit'      => $totalAmount,
                ],
            ];

            JournalService::createEntry(
                $invoiceDate,
                'supplier_invoice',
                $invId,
                $invoiceNumber,
                "Pencatatan Faktur Pemasok {$suppInvNum} & AP Voucher {$voucherNumber}",
                $journalLines,
                $user['id'],
                true,
                $db
            );

            logAudit($user['id'], 'create', 'supplier_invoices', $invId, null, [
                'invoice_number' => $invoiceNumber,
                'total_amount'   => $totalAmount,
                'match_status'   => $matchStatus,
                'voucher_number' => $voucherNumber,
            ]);

            $db->commit();

            setFlash('success', "Faktur Pemasok {$invoiceNumber} dan AP Voucher {$voucherNumber} berhasil dibuat dengan status {$matchStatus}.");
            header('Location: index.php?page=expenditure_procurement/invoice_detail&id=' . $invId);
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Gagal menyimpan Faktur Pemasok: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Pencatatan Faktur Pemasok & Three-Way Matching</h1>
        <div class="page-subtitle">Siklus Pengadaan &bull; Rekonsiliasi Otomatis PO, Penerimaan Barang (GR), dan Invoice</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=expenditure_procurement/invoices" class="btn btn-secondary">&laquo; Kembali ke Daftar Faktur</a>
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

<!-- Step 1: Select Goods Receipt -->
<div class="card">
    <div class="card-header">
        <span class="card-title">Langkah 1: Pilih Dokumen Goods Receipt (GR) yang Sudah Diterima</span>
    </div>
    <div class="card-body">
        <form action="index.php" method="GET" class="filter-bar" style="margin-bottom: 0;">
            <input type="hidden" name="page" value="expenditure_procurement/invoice_create">
            <div class="filter-group" style="flex: 1;">
                <select name="gr_id" class="form-control" style="width: 100%;" onchange="this.form.submit()">
                    <option value="">-- Pilih Penerimaan Barang (GR) --</option>
                    <?php foreach ($grList as $gr): ?>
                        <option value="<?= $gr['id'] ?>" <?= ($selectedGr && $selectedGr['id'] == $gr['id']) ? 'selected' : '' ?>>
                            <?= e($gr['gr_number']) ?> &bull; <?= e($gr['supplier_name']) ?> (Ref: <?= e($gr['po_number']) ?> - Tgl GR: <?= formatDate($gr['receipt_date']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">Muat Dokumen GR</button>
        </form>
    </div>
</div>

<?php if ($selectedGr): ?>
    <!-- Step 2: Review comparison & enter Vendor Invoice -->
    <form action="index.php?page=expenditure_procurement/invoice_create&gr_id=<?= $selectedGr['id'] ?>" method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="goods_receipt_id" value="<?= $selectedGr['id'] ?>">

        <div class="card">
            <div class="card-header">
                <span class="card-title">Langkah 2: Data Faktur Tagihan dari Vendor</span>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label required">Nomor Invoice Pemasok</label>
                            <input type="text" name="supplier_invoice_number" class="form-control" placeholder="Nomor faktur fisik vendor..." required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label required">Tanggal Faktur</label>
                            <input type="date" name="invoice_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label required">Tanggal Jatuh Tempo</label>
                            <?php $dueDefault = date('Y-m-d', strtotime('+' . ($selectedGr['payment_terms_days'] ?: 30) . ' days')); ?>
                            <input type="date" name="due_date" class="form-control" value="<?= $dueDefault ?>" required>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label required">Nilai Tagihan DPP (Subtotal Rp)</label>
                            <input type="text" name="subtotal" class="form-control" value="<?= formatNumber($expectedTotal) ?>" required>
                            <div class="form-text">Nilai acuan dari GR: <?= formatRupiah($expectedTotal) ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">PPN / Pajak (Rp)</label>
                            <input type="text" name="tax_amount" class="form-control" value="0">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label">Nomor Faktur Pajak (Opsional)</label>
                            <input type="text" name="tax_invoice_number" class="form-control" placeholder="010.000-XX.XXXXXXXX">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Catatan Tambahan</label>
                    <input type="text" name="notes" class="form-control" placeholder="Catatan tagihan atau nomor rekening pembayaran vendor">
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">Tabel Rincian Three-Way Matching (PO vs GR vs Tagihan)</span>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Barang / Bahan</th>
                            <th class="text-right">Kuantitas Pesan (PO)</th>
                            <th class="text-right" style="color: var(--success);">Diterima (GR)</th>
                            <th class="text-right">Harga Satuan PO</th>
                            <th class="text-right">Nilai Diharapkan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grItems as $gi): ?>
                            <tr>
                                <td>
                                    <strong><?= e($gi['item_name']) ?></strong><br>
                                    <code><?= e($gi['item_code']) ?></code> (<?= e($gi['unit_name']) ?>)
                                </td>
                                <td class="text-right"><?= formatNumber($gi['qty_ordered']) ?></td>
                                <td class="text-right" style="font-weight: 700; color: var(--success);"><?= formatNumber($gi['qty_accepted']) ?></td>
                                <td class="text-right"><?= formatRupiah($gi['unit_price']) ?></td>
                                <td class="text-right" style="font-weight: 600;"><?= formatRupiah($gi['expected_subtotal']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background-color: #f8fafc; font-weight: 700;">
                            <td colspan="4" class="text-right">TOTAL NILAI GR YANG DIHARAPKAN:</td>
                            <td class="text-right" style="color: var(--primary); font-size: 14px;"><?= formatRupiah($expectedTotal) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="card-footer" style="display: flex; justify-content: space-between; align-items: center;">
                <span class="form-text" style="color: var(--text-muted); font-size: 12px;">
                    ℹ️ <em>Three-way match akan otomatis memeriksa kesesuaian nilai dan menerbitkan AP Voucher serta jurnal akuntansi.</em>
                </span>
                <button type="submit" name="submit_invoice" value="1" class="btn btn-primary">
                    ✓ Validasi Three-Way Match & Terbitkan Utang (AP)
                </button>
            </div>
        </div>
    </form>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
