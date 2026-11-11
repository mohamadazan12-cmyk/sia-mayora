<?php
/**
 * Modul Penerimaan: Pembuatan Faktur Penjualan (Sales Invoice)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Buat Faktur Penjualan Baru';
$breadcrumbCategory = 'Penerimaan';

$db = getDbConnection();
$user = currentUser();

$selectedDoId = (int)($_GET['do_id'] ?? 0);
$selectedSoId = (int)($_GET['so_id'] ?? 0);

// Fetch shipped or delivered DOs that don't have an invoice yet
$availableDOs = $db->query("
    SELECT do_tab.id, do_tab.do_number, do_tab.do_date, so.so_number, c.name AS customer_name, c.code AS customer_code
    FROM delivery_orders do_tab
    JOIN sales_orders so ON do_tab.sales_order_id = so.id
    JOIN customers c ON so.customer_id = c.id
    LEFT JOIN sales_invoices inv ON inv.delivery_order_id = do_tab.id
    WHERE do_tab.status IN ('shipped', 'delivered') AND inv.id IS NULL
    ORDER BY do_tab.id DESC
")->fetchAll();

$selectedDO = null;
$items = [];
$customer = null;

if ($selectedDoId > 0) {
    $stmtDo = $db->prepare("
        SELECT do_tab.*, so.so_number, so.id AS so_id, c.id AS customer_id, c.name AS customer_name,
               c.code AS customer_code, c.address AS customer_address, c.payment_terms_days
        FROM delivery_orders do_tab
        JOIN sales_orders so ON do_tab.sales_order_id = so.id
        JOIN customers c ON so.customer_id = c.id
        WHERE do_tab.id = :id
    ");
    $stmtDo->execute([':id' => $selectedDoId]);
    $selectedDO = $stmtDo->fetch();

    if ($selectedDO) {
        $selectedSoId = (int)$selectedDO['so_id'];
        $customer = [
            'id'                 => $selectedDO['customer_id'],
            'name'               => $selectedDO['customer_name'],
            'code'               => $selectedDO['customer_code'],
            'address'            => $selectedDO['customer_address'],
            'payment_terms_days' => $selectedDO['payment_terms_days'],
        ];

        // Fetch DO Items and their unit price from SO
        $stmtDoItems = $db->prepare("
            SELECT doi.item_id, doi.qty_shipped AS qty, i.name AS item_name, i.code AS item_code, u.name AS unit_name,
                   soi.unit_price
            FROM delivery_order_items doi
            JOIN items i ON doi.item_id = i.id
            LEFT JOIN units u ON i.unit_id = u.id
            JOIN sales_order_items soi ON doi.sales_order_item_id = soi.id
            WHERE doi.delivery_order_id = :do_id
        ");
        $stmtDoItems->execute([':do_id' => $selectedDoId]);
        $items = $stmtDoItems->fetchAll();
    }
}

// Calculate due date (default: +30 days or based on payment_terms_days)
$paymentDays = !empty($customer['payment_terms_days']) ? (int)$customer['payment_terms_days'] : 30;
$defaultDueDate = date('Y-m-d', strtotime("+{$paymentDays} days"));

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $soId = (int)($_POST['sales_order_id'] ?? 0);
    $doId = !empty($_POST['delivery_order_id']) ? (int)$_POST['delivery_order_id'] : null;
    $custId = (int)($_POST['customer_id'] ?? 0);
    $invoiceDate = trim($_POST['invoice_date'] ?? date('Y-m-d'));
    $dueDate = trim($_POST['due_date'] ?? $defaultDueDate);
    $taxInvoiceNo = trim($_POST['tax_invoice_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $applyTax = isset($_POST['apply_tax']);
    $itemInputs = $_POST['items'] ?? [];

    $errors = [];

    if ($soId <= 0 || $custId <= 0) {
        $errors[] = 'Data Pesanan dan Pelanggan tidak valid.';
    }
    if (empty($invoiceDate) || empty($dueDate)) {
        $errors[] = 'Tanggal faktur dan jatuh tempo wajib diisi.';
    }
    if (empty($itemInputs)) {
        $errors[] = 'Item faktur tidak boleh kosong.';
    }

    $subtotal = 0.0;
    $validItems = [];

    foreach ($itemInputs as $itemData) {
        $itemId = (int)($itemData['item_id'] ?? 0);
        $qty = (float)($itemData['qty'] ?? 0);
        $price = (float)($itemData['price'] ?? 0);

        if ($itemId <= 0 || $qty <= 0) {
            continue;
        }

        $lineSubtotal = round($qty * $price, 2);
        $subtotal += $lineSubtotal;

        $validItems[] = [
            'item_id'    => $itemId,
            'qty'        => $qty,
            'unit_price' => $price,
            'subtotal'   => $lineSubtotal,
        ];
    }

    if (empty($validItems)) {
        $errors[] = 'Setidaknya satu baris item dengan kuantitas > 0 wajib diisi.';
    }

    $taxAmount = $applyTax ? round($subtotal * 0.11, 2) : 0.00;
    $totalAmount = $subtotal + $taxAmount;

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // Generate Invoice Number: INV-YYYYMM-XXXX
            $yearMonth = date('Ym', strtotime($invoiceDate));
            $prefix = 'INV-' . $yearMonth;
            $stmtLast = $db->prepare("SELECT invoice_number FROM sales_invoices WHERE invoice_number LIKE :pfx ORDER BY id DESC LIMIT 1");
            $stmtLast->execute([':pfx' => $prefix . '-%']);
            $lastInv = $stmtLast->fetchColumn();
            $seq = 1;
            if ($lastInv) {
                $parts = explode('-', $lastInv);
                $seq = (int)end($parts) + 1;
            }
            $invoiceNumber = sprintf('%s-%04d', $prefix, $seq);

            // Insert Sales Invoice Header
            $stmtInsert = $db->prepare("
                INSERT INTO sales_invoices 
                (invoice_number, sales_order_id, delivery_order_id, customer_id, invoice_date, due_date, subtotal, tax_amount, total_amount, paid_amount, balance, tax_invoice_number, notes, status, created_by, created_at)
                VALUES 
                (:inv_num, :so_id, :do_id, :cust_id, :inv_date, :due_date, :subtotal, :tax_amt, :total_amt, 0.00, :balance, :tax_inv_num, :notes, 'issued', :created_by, NOW())
            ");
            $stmtInsert->execute([
                ':inv_num'     => $invoiceNumber,
                ':so_id'       => $soId,
                ':do_id'       => $doId,
                ':cust_id'     => $custId,
                ':inv_date'    => $invoiceDate,
                ':due_date'    => $dueDate,
                ':subtotal'    => $subtotal,
                ':tax_amt'     => $taxAmount,
                ':total_amt'   => $totalAmount,
                ':balance'     => $totalAmount,
                ':tax_inv_num' => $taxInvoiceNo ?: null,
                ':notes'       => $notes,
                ':created_by'  => $user['id'],
            ]);
            $invoiceId = $db->lastInsertId();

            // Insert Invoice Items
            $stmtItem = $db->prepare("
                INSERT INTO sales_invoice_items (sales_invoice_id, item_id, qty, unit_price, subtotal)
                VALUES (:inv_id, :item_id, :qty, :unit_price, :subtotal)
            ");
            foreach ($validItems as $vItem) {
                $stmtItem->execute([
                    ':inv_id'     => $invoiceId,
                    ':item_id'    => $vItem['item_id'],
                    ':qty'        => $vItem['qty'],
                    ':unit_price' => $vItem['unit_price'],
                    ':subtotal'   => $vItem['subtotal'],
                ]);
            }

            // Update customer credit_used
            $db->prepare("UPDATE customers SET credit_used = credit_used + :amount WHERE id = :cust_id")
               ->execute([':amount' => $totalAmount, ':cust_id' => $custId]);

            // Post Balanced Accounting Journal:
            // Dr: Piutang Usaha Pelanggan Domestik (Account ID 8) = totalAmount
            // Cr: Penjualan Produk Mesin Otomasi Industri (Account ID 38) = subtotal
            // Cr: Utang Perpajakan PPN Keluaran & PPh (Account ID 29) = taxAmount (if > 0)
            $journalLines = [
                [
                    'account_id'  => 8, // Piutang Usaha
                    'description' => "Piutang Faktur Penjualan {$invoiceNumber}",
                    'debit'       => $totalAmount,
                    'credit'      => 0,
                ],
            ];

            if ($taxAmount > 0) {
                $journalLines[] = [
                    'account_id'  => 38, // Penjualan Produk
                    'description' => "Pendapatan Penjualan {$invoiceNumber}",
                    'debit'       => 0,
                    'credit'      => $subtotal,
                ];
                $journalLines[] = [
                    'account_id'  => 29, // PPN Keluaran
                    'description' => "PPN Keluaran 11% Faktur {$invoiceNumber}",
                    'debit'       => 0,
                    'credit'      => $taxAmount,
                ];
            } else {
                $journalLines[] = [
                    'account_id'  => 38, // Penjualan Produk
                    'description' => "Pendapatan Penjualan {$invoiceNumber}",
                    'debit'       => 0,
                    'credit'      => $totalAmount,
                ];
            }

            JournalService::createEntry(
                $invoiceDate,
                'sales_invoice',
                $invoiceId,
                $invoiceNumber,
                "Faktur Penjualan {$invoiceNumber} kepada " . ($customer['name'] ?? 'Pelanggan'),
                $journalLines,
                $user['id'],
                true,
                $db
            );

            logAudit($user['id'], 'create_sales_invoice', 'sales_invoices', $invoiceId, null, [
                'invoice_number' => $invoiceNumber,
                'total_amount'   => $totalAmount,
                'tax_amount'     => $taxAmount,
            ]);

            $db->commit();
            setFlash('success', "Faktur Penjualan {$invoiceNumber} berhasil diterbitkan dan jurnal piutang otomatis diposting.");
            header("Location: index.php?page=receiving_sales/invoice_detail&id={$invoiceId}");
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Gagal menerbitkan Faktur Penjualan: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Buat Faktur Penjualan (Sales Invoice)</h1>
        <div class="page-subtitle">Siklus Penerimaan &bull; Tagihan Penjualan Resmi, Perhitungan PPN 11%, dan Jurnal Otomatis</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=receiving_sales/invoices" class="btn btn-secondary">
            &larr; Kembali ke Daftar Faktur
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

<!-- Step 1: Select DO Reference -->
<div class="card mb-4">
    <div class="card-header">
        <h2 class="card-title">1. Pilih Referensi Surat Jalan (Delivery Order)</h2>
    </div>
    <div class="card-body">
        <form action="index.php" method="GET" style="display: flex; gap: 15px; align-items: flex-end;">
            <input type="hidden" name="page" value="receiving_sales/invoice_create">
            <div class="form-group" style="flex: 1; margin-bottom:0;">
                <label>Surat Jalan Terkirim (Belum Difakturkan)</label>
                <select name="do_id" required>
                    <option value="">-- Pilih Surat Jalan --</option>
                    <?php foreach ($availableDOs as $ado): ?>
                        <option value="<?= $ado['id'] ?>" <?= $selectedDoId === (int)$ado['id'] ? 'selected' : '' ?>>
                            <?= e($ado['do_number']) ?> &bull; <?= e($ado['customer_name']) ?> (SO: <?= e($ado['so_number']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="height: 42px;">Muat Rincian Tagihan &rarr;</button>
        </form>
    </div>
</div>

<?php if ($selectedDO): ?>
<form action="index.php?page=receiving_sales/invoice_create&do_id=<?= $selectedDoId ?>" method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="sales_order_id" value="<?= $selectedSoId ?>">
    <input type="hidden" name="delivery_order_id" value="<?= $selectedDoId ?>">
    <input type="hidden" name="customer_id" value="<?= $customer['id'] ?>">

    <!-- Details Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">2. Data Penagihan & Ketentuan Pembayaran</h2>
        </div>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                <div class="form-group">
                    <label>Pelanggan</label>
                    <input type="text" value="<?= e($customer['name']) ?> (<?= e($customer['code']) ?>)" readonly style="background:#f1f5f9;">
                </div>
                <div class="form-group">
                    <label>No. Sales Order / Surat Jalan</label>
                    <input type="text" value="<?= e($selectedDO['so_number']) ?> / <?= e($selectedDO['do_number']) ?>" readonly style="background:#f1f5f9;">
                </div>
                <div class="form-group">
                    <label>Tanggal Faktur *</label>
                    <input type="date" name="invoice_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label>Tanggal Jatuh Tempo *</label>
                    <input type="date" name="due_date" value="<?= $defaultDueDate ?>" required>
                </div>
                <div class="form-group">
                    <label>Nomor Seri Faktur Pajak (PPN)</label>
                    <input type="text" name="tax_invoice_number" placeholder="Misal: 010.002-26.00012345">
                </div>
                <div class="form-group">
                    <label>Termin Pembayaran</label>
                    <input type="text" value="Net <?= (int)($customer['payment_terms_days'] ?? 30) ?> Hari" readonly style="background:#f1f5f9;">
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label>Catatan Faktur</label>
                    <input type="text" name="notes" placeholder="Catatan syarat pelunasan, nomor rekening pembayaran...">
                </div>
            </div>
        </div>
    </div>

    <!-- Items Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">3. Rincian Item Tagihan</h2>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Kode & Nama Barang</th>
                            <th class="text-right" style="width: 140px;">Qty Dikirim</th>
                            <th class="text-right" style="width: 180px;">Harga Satuan (Rp)</th>
                            <th class="text-right">Subtotal (Rp)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1; 
                        $calcSubtotal = 0.0;
                        foreach ($items as $idx => $item): 
                            $lineTot = (float)$item['qty'] * (float)$item['unit_price'];
                            $calcSubtotal += $lineTot;
                        ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td>
                                    <strong><?= e($item['item_name']) ?></strong>
                                    <div class="text-muted" style="font-size: 11px;"><?= e($item['item_code']) ?></div>
                                    <input type="hidden" name="items[<?= $idx ?>][item_id]" value="<?= $item['item_id'] ?>">
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0.01" name="items[<?= $idx ?>][qty]" 
                                           value="<?= (float)$item['qty'] ?>" style="text-align:right;" required>
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0" name="items[<?= $idx ?>][price]" 
                                           value="<?= (float)$item['unit_price'] ?>" style="text-align:right;" required>
                                </td>
                                <td class="text-right font-medium">
                                    <?= formatRupiah($lineTot) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="padding: 20px; background:#f8fafc; border-top: 1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600;">
                        <input type="checkbox" name="apply_tax" value="1" checked style="width:18px; height:18px;">
                        Kenakan Pajak Pertambahan Nilai (PPN 11%)
                    </label>
                    <small class="text-muted" style="display:block; margin-top:4px;">
                        PPN akan dihitung otomatis 11% dari subtotal dan dibukukan ke Utang Perpajakan PPN Keluaran.
                    </small>
                </div>
                <div style="text-align:right;">
                    <div style="font-size: 14px; color:#64748b;">Subtotal: <strong><?= formatRupiah($calcSubtotal) ?></strong></div>
                    <div style="font-size: 14px; color:#64748b;">Estimasi PPN 11%: <strong><?= formatRupiah($calcSubtotal * 0.11) ?></strong></div>
                    <div style="font-size: 18px; font-weight:bold; color:#0f172a; margin-top:5px;">
                        Total Tagihan: <?= formatRupiah($calcSubtotal * 1.11) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer" style="padding: 15px 20px; background: #fff; display:flex; justify-content:flex-end; gap:10px;">
            <a href="index.php?page=receiving_sales/invoices" class="btn btn-secondary">Batal</a>
            <button type="submit" class="btn btn-primary">
                Terbitkan Faktur &amp; Posting Jurnal Piutang &rarr;
            </button>
        </div>
    </div>
</form>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
