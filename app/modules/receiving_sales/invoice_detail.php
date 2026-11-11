<?php
/**
 * Modul Penerimaan: Detail Faktur Penjualan (Sales Invoice)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Detail Faktur Penjualan';
$breadcrumbCategory = 'Penerimaan';

$db = getDbConnection();
$user = currentUser();

$invId = (int)($_GET['id'] ?? 0);
if ($invId <= 0) {
    setFlash('error', 'ID Faktur tidak valid.');
    header('Location: index.php?page=receiving_sales/invoices');
    exit;
}

// Fetch Invoice Header
$stmt = $db->prepare("
    SELECT inv.*, c.id AS customer_id, c.name AS customer_name, c.code AS customer_code,
           c.address AS customer_address, c.phone AS customer_phone, c.payment_terms_days,
           so.so_number, do_tab.do_number, u.full_name AS creator_name
    FROM sales_invoices inv
    JOIN customers c ON inv.customer_id = c.id
    LEFT JOIN sales_orders so ON inv.sales_order_id = so.id
    LEFT JOIN delivery_orders do_tab ON inv.delivery_order_id = do_tab.id
    LEFT JOIN users u ON inv.created_by = u.id
    WHERE inv.id = :id
");
$stmt->execute([':id' => $invId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    setFlash('error', 'Faktur Penjualan tidak ditemukan.');
    header('Location: index.php?page=receiving_sales/invoices');
    exit;
}

// Fetch Invoice Items
$stmtItems = $db->prepare("
    SELECT sii.*, i.name AS item_name, i.code AS item_code, u.name AS unit_name
    FROM sales_invoice_items sii
    JOIN items i ON sii.item_id = i.id
    LEFT JOIN units u ON i.unit_id = u.id
    WHERE sii.sales_invoice_id = :id
");
$stmtItems->execute([':id' => $invId]);
$invoiceItems = $stmtItems->fetchAll();

// Fetch Payment Allocations
$stmtAlloc = $db->prepare("
    SELECT cra.*, cr.receipt_number, cr.receipt_date, cr.payment_method, cr.status AS receipt_status, cr.bank_name
    FROM customer_receipt_allocations cra
    JOIN customer_receipts cr ON cra.customer_receipt_id = cr.id
    WHERE cra.sales_invoice_id = :id
    ORDER BY cr.receipt_date DESC, cra.id DESC
");
$stmtAlloc->execute([':id' => $invId]);
$allocations = $stmtAlloc->fetchAll();

// Fetch Related Accounting Journal Entry
$stmtJe = $db->prepare("
    SELECT je.*, jel.account_id, jel.description AS line_desc, jel.debit, jel.credit, acc.code AS acc_code, acc.name AS acc_name
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    JOIN accounts acc ON jel.account_id = acc.id
    WHERE je.ref_type = 'sales_invoice' AND je.ref_id = :id
    ORDER BY jel.id ASC
");
$stmtJe->execute([':id' => $invId]);
$journalLines = $stmtJe->fetchAll();

// Handle Invoice Cancellation (reverses journal and credit limit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_cancel'])) {
    requireCsrf();
    $cancelReason = trim($_POST['cancel_reason'] ?? 'Dibatalkan oleh staf');

    if ((float)$invoice['paid_amount'] > 0) {
        setFlash('error', 'Faktur tidak dapat dibatalkan karena sudah memiliki riwayat penerimaan kas.');
        header("Location: index.php?page=receiving_sales/invoice_detail&id={$invId}");
        exit;
    }

    try {
        $db->beginTransaction();

        $db->prepare("UPDATE sales_invoices SET status = 'cancelled', notes = CONCAT_WS(' | Batal: ', notes, :reason), updated_at = NOW(), updated_by = :uid WHERE id = :id")
           ->execute([':reason' => $cancelReason, ':uid' => $user['id'], ':id' => $invId]);

        // Reduce customer credit_used
        $db->prepare("UPDATE customers SET credit_used = GREATEST(0, credit_used - :amount) WHERE id = :cust_id")
           ->execute([':amount' => $invoice['total_amount'], ':cust_id' => $invoice['customer_id']]);

        // Reverse journal if exists
        $stmtFindJe = $db->prepare("SELECT id FROM journal_entries WHERE ref_type = 'sales_invoice' AND ref_id = :id AND status = 'posted' LIMIT 1");
        $stmtFindJe->execute([':id' => $invId]);
        $jeId = $stmtFindJe->fetchColumn();
        if ($jeId) {
            JournalService::reverseEntry($jeId, "Pembatalan Faktur {$invoice['invoice_number']}: {$cancelReason}", $user['id'], $db);
        }

        logAudit($user['id'], 'cancel_sales_invoice', 'sales_invoices', $invId, ['status' => $invoice['status']], ['status' => 'cancelled']);
        $db->commit();
        setFlash('warning', "Faktur {$invoice['invoice_number']} berhasil dibatalkan dan jurnal piutang telah dibalik.");
        header("Location: index.php?page=receiving_sales/invoice_detail&id={$invId}");
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Gagal membatalkan faktur: ' . $e->getMessage());
        header("Location: index.php?page=receiving_sales/invoice_detail&id={$invId}");
        exit;
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<!-- Print-Only Tax/Sales Invoice Slip -->
<div class="print-only" style="display:none; padding: 25px;">
    <div style="border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 20px; display:flex; justify-content:space-between;">
        <div>
            <h2 style="margin:0; font-size:22px; letter-spacing:0.5px;">FAKTUR PENJUALAN (SALES INVOICE)</h2>
            <div style="font-weight:bold; font-size:14px; margin-top:4px;">PT MANUFAKTUR &amp; DISTRIBUSI OPERASIONAL INDONESIA</div>
            <div style="font-size:12px; color:#475569;">Kawasan Industri Sentul Kav. 88, Bogor &bull; NPWP: 01.345.678.9-403.000</div>
        </div>
        <div style="text-align:right;">
            <h3 style="margin:0; font-size:18px; color:#0f172a;"><?= e($invoice['invoice_number']) ?></h3>
            <div>Tgl Faktur: <?= formatDate($invoice['invoice_date']) ?></div>
            <div>Jatuh Tempo: <strong><?= formatDate($invoice['due_date']) ?></strong></div>
            <?php if ($invoice['tax_invoice_number']): ?>
                <div style="font-size:12px;">No Seri Faktur Pajak: <?= e($invoice['tax_invoice_number']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <table style="width:100%; margin-bottom: 25px; font-size:13px;">
        <tr>
            <td style="width:55%; vertical-align:top;">
                <strong>Ditagihkan Kepada:</strong><br>
                <span style="font-size:15px; font-weight:bold;"><?= e($invoice['customer_name']) ?></span><br>
                Kode: <?= e($invoice['customer_code']) ?><br>
                <?= nl2br(e($invoice['customer_address'])) ?><br>
                Telepon: <?= e($invoice['customer_phone'] ?: '-') ?>
            </td>
            <td style="width:45%; vertical-align:top;">
                <strong>Referensi Dokumen &amp; Termin:</strong><br>
                No. Sales Order: <?= e($invoice['so_number'] ?: '-') ?><br>
                No. Surat Jalan (DO): <?= e($invoice['do_number'] ?: '-') ?><br>
                Syarat Pembayaran: Net <?= (int)($invoice['payment_terms_days'] ?? 30) ?> Hari<br>
                Status Pembayaran: <?= strtoupper($invoice['status']) ?>
            </td>
        </tr>
    </table>

    <table class="table" style="width:100%; border-collapse:collapse; margin-bottom:25px;">
        <thead>
            <tr style="border-bottom:2px solid #000; background:#f8fafc;">
                <th style="padding:8px; text-align:left;">No</th>
                <th style="padding:8px; text-align:left;">Kode Barang</th>
                <th style="padding:8px; text-align:left;">Deskripsi Produk</th>
                <th style="padding:8px; text-align:right;">Kuantitas</th>
                <th style="padding:8px; text-align:right;">Harga Satuan</th>
                <th style="padding:8px; text-align:right;">Subtotal (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <?php $no = 1; foreach ($invoiceItems as $item): ?>
                <tr style="border-bottom:1px solid #e2e8f0;">
                    <td style="padding:8px;"><?= $no++ ?></td>
                    <td style="padding:8px;"><?= e($item['item_code']) ?></td>
                    <td style="padding:8px;"><?= e($item['item_name']) ?></td>
                    <td style="padding:8px; text-align:right;"><?= formatNumber((float)$item['qty']) ?> <?= e($item['unit_name'] ?? 'unit') ?></td>
                    <td style="padding:8px; text-align:right;"><?= formatRupiah((float)$item['unit_price']) ?></td>
                    <td style="padding:8px; text-align:right; font-weight:600;"><?= formatRupiah((float)$item['subtotal']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" style="padding:8px; text-align:right; font-weight:600;">Subtotal Penjualan:</td>
                <td style="padding:8px; text-align:right; font-weight:600;"><?= formatRupiah((float)$invoice['subtotal']) ?></td>
            </tr>
            <?php if ((float)$invoice['tax_amount'] > 0): ?>
                <tr>
                    <td colspan="5" style="padding:8px; text-align:right; font-weight:600;">PPN (11%):</td>
                    <td style="padding:8px; text-align:right; font-weight:600;"><?= formatRupiah((float)$invoice['tax_amount']) ?></td>
                </tr>
            <?php endif; ?>
            <tr style="border-top:2px solid #000; font-size:15px;">
                <td colspan="5" style="padding:10px 8px; text-align:right; font-weight:bold;">Total Tagihan:</td>
                <td style="padding:10px 8px; text-align:right; font-weight:bold;"><?= formatRupiah((float)$invoice['total_amount']) ?></td>
            </tr>
            <tr>
                <td colspan="5" style="padding:8px; text-align:right;">Telah Dibayar:</td>
                <td style="padding:8px; text-align:right; color:#16a34a;"><?= formatRupiah((float)$invoice['paid_amount']) ?></td>
            </tr>
            <tr style="font-weight:bold;">
                <td colspan="5" style="padding:8px; text-align:right; color:#dc2626;">Sisa Tagihan (Balance):</td>
                <td style="padding:8px; text-align:right; color:#dc2626;"><?= formatRupiah((float)$invoice['balance']) ?></td>
            </tr>
        </tfoot>
    </table>

    <div style="display:flex; justify-content:space-between; margin-top:30px; font-size:12px;">
        <div style="width:55%;">
            <strong>Instruksi Pembayaran Bank:</strong><br>
            Bank BCA Rekening Giro: <strong>8830-128-999</strong><br>
            A.n. PT Manufaktur &amp; Distribusi Operasional Indonesia<br>
            <em>* Mohon cantumkan nomor faktur (<?= e($invoice['invoice_number']) ?>) pada berita transfer.</em>
        </div>
        <div style="width:35%; text-align:center;">
            <div>Bogor, <?= formatDate($invoice['invoice_date']) ?></div>
            <div>Bagian Keuangan &amp; Piutang,</div>
            <div style="height:70px;"></div>
            <div>( <?= e($invoice['creator_name'] ?: 'Finance Manager') ?> )</div>
        </div>
    </div>
</div>

<!-- Screen Display View -->
<div class="no-print">
    <div class="page-header">
        <div>
            <h1 class="page-title">Faktur Penjualan: <?= e($invoice['invoice_number']) ?></h1>
            <div class="page-subtitle">Pelanggan: <strong><?= e($invoice['customer_name']) ?></strong> &bull; Ref. SO: <?= e($invoice['so_number']) ?></div>
        </div>
        <div class="page-actions" style="display:flex; gap:10px;">
            <button onclick="window.print()" class="btn btn-secondary">
                🖨️ Cetak Faktur Penjualan
            </button>
            <?php if ((float)$invoice['balance'] > 0 && $invoice['status'] !== 'cancelled'): ?>
                <a href="index.php?page=receiving_sales/receipt_create&customer_id=<?= $invoice['customer_id'] ?>&invoice_id=<?= $invId ?>" class="btn btn-primary">
                    💰 Catat Penerimaan Kas / Pelunasan
                </a>
            <?php endif; ?>
            <a href="index.php?page=receiving_sales/invoices" class="btn btn-secondary">
                &larr; Kembali ke Daftar
            </a>
        </div>
    </div>

    <!-- Header Details Card -->
    <div class="card mb-4">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <h2 class="card-title">Rincian Dokumen Tagihan</h2>
            <div><?= statusBadge($invoice['status']) ?></div>
        </div>
        <div class="card-body">
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:20px;">
                <div>
                    <div class="text-muted" style="font-size:12px;">Informasi Pelanggan</div>
                    <strong style="font-size:16px;"><?= e($invoice['customer_name']) ?></strong>
                    <div class="text-muted" style="font-size:12px;"><?= e($invoice['customer_code']) ?></div>
                    <div style="margin-top:6px; font-size:13px;"><?= nl2br(e($invoice['customer_address'])) ?></div>
                    <div style="font-size:12px; color:#475569; margin-top:4px;">Telp: <?= e($invoice['customer_phone'] ?: '-') ?></div>
                </div>
                <div>
                    <div class="text-muted" style="font-size:12px;">Tanggal &amp; Termin</div>
                    <div>Tgl Faktur: <strong><?= formatDate($invoice['invoice_date']) ?></strong></div>
                    <div>Jatuh Tempo: <strong style="color: <?= (strtotime($invoice['due_date']) < time() && (float)$invoice['balance'] > 0) ? '#dc2626' : '#0f172a' ?>;"><?= formatDate($invoice['due_date']) ?></strong></div>
                    <div>Termin: Net <?= (int)($invoice['payment_terms_days'] ?? 30) ?> Hari</div>
                    <div>Dibuat oleh: <?= e($invoice['creator_name'] ?: 'Sistem') ?></div>
                </div>
                <div>
                    <div class="text-muted" style="font-size:12px;">Referensi Pesanan &amp; Pajak</div>
                    <div>No. Sales Order: <a href="index.php?page=receiving_sales/sales_order_detail&id=<?= $invoice['sales_order_id'] ?>"><strong><?= e($invoice['so_number']) ?></strong></a></div>
                    <div>No. Surat Jalan: <?= $invoice['delivery_order_id'] ? "<a href='index.php?page=receiving_sales/delivery_order_detail&id={$invoice['delivery_order_id']}'><strong>" . e($invoice['do_number']) . "</strong></a>" : '-' ?></div>
                    <div>Faktur Pajak Seri: <?= e($invoice['tax_invoice_number'] ?: 'Non-Faktur Standar') ?></div>
                </div>
                <div style="background:#f8fafc; padding:15px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div class="text-muted" style="font-size:12px;">Status Pembayaran</div>
                    <div style="font-size:13px;">Total Tagihan: <strong><?= formatRupiah((float)$invoice['total_amount']) ?></strong></div>
                    <div style="font-size:13px; color:#16a34a;">Telah Dibayar: <strong><?= formatRupiah((float)$invoice['paid_amount']) ?></strong></div>
                    <div style="font-size:15px; color:#ea580c; font-weight:bold; margin-top:5px;">Sisa Piutang: <?= formatRupiah((float)$invoice['balance']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Items Table Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">Daftar Barang yang Ditagihkan</h2>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width:40px;">No</th>
                            <th>Kode &amp; Nama Barang</th>
                            <th class="text-right">Kuantitas</th>
                            <th class="text-right">Harga Satuan (Rp)</th>
                            <th class="text-right">Subtotal (Rp)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; foreach ($invoiceItems as $item): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td>
                                    <strong><?= e($item['item_name']) ?></strong>
                                    <div class="text-muted" style="font-size:11px;"><?= e($item['item_code']) ?></div>
                                </td>
                                <td class="text-right"><?= formatNumber((float)$item['qty']) ?> <?= e($item['unit_name'] ?? 'unit') ?></td>
                                <td class="text-right"><?= formatRupiah((float)$item['unit_price']) ?></td>
                                <td class="text-right font-medium"><?= formatRupiah((float)$item['subtotal']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-right font-bold">Subtotal:</td>
                            <td class="text-right font-bold"><?= formatRupiah((float)$invoice['subtotal']) ?></td>
                        </tr>
                        <?php if ((float)$invoice['tax_amount'] > 0): ?>
                            <tr>
                                <td colspan="4" class="text-right font-bold">PPN (11%):</td>
                                <td class="text-right font-bold"><?= formatRupiah((float)$invoice['tax_amount']) ?></td>
                            </tr>
                        <?php endif; ?>
                        <tr style="background:#f1f5f9; font-size:15px;">
                            <td colspan="4" class="text-right font-bold">Total Faktur Penjualan:</td>
                            <td class="text-right font-bold" style="color:#0f172a;"><?= formatRupiah((float)$invoice['total_amount']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Payment Receipts History Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">Riwayat Pelunasan / Penerimaan Kas (Customer Receipts)</h2>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>No. Kwitansi / Kas Masuk</th>
                            <th>Tanggal Penerimaan</th>
                            <th>Metode Bayar</th>
                            <th>Bank / Rekening</th>
                            <th class="text-right">Alokasi Pembayaran (Rp)</th>
                            <th>Status Penerimaan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allocations)): ?>
                            <tr>
                                <td colspan="6" class="text-center" style="padding:20px; color:#64748b;">
                                    Belum ada catatan penerimaan kas / pelunasan untuk faktur ini.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allocations as $alloc): ?>
                                <tr>
                                    <td>
                                        <strong><a href="index.php?page=receiving_sales/receipt_detail&id=<?= $alloc['customer_receipt_id'] ?>"><?= e($alloc['receipt_number']) ?></a></strong>
                                    </td>
                                    <td><?= formatDate($alloc['receipt_date']) ?></td>
                                    <td><?= strtoupper($alloc['payment_method']) ?></td>
                                    <td><?= e($alloc['bank_name'] ?: '-') ?></td>
                                    <td class="text-right font-medium" style="color:#16a34a;"><?= formatRupiah((float)$alloc['allocated_amount']) ?></td>
                                    <td><?= statusBadge($alloc['receipt_status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Related Accounting Journal Card -->
    <?php if (!empty($journalLines)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h2 class="card-title">Jurnal Akuntansi Otomatis (General Ledger Entry)</h2>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>No. Jurnal</th>
                                <th>Kode Akun</th>
                                <th>Nama Akun</th>
                                <th>Keterangan Jurnal</th>
                                <th class="text-right">Debit (Rp)</th>
                                <th class="text-right">Kredit (Rp)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $totDr = 0.0;
                            $totCr = 0.0;
                            foreach ($journalLines as $jl): 
                                $totDr += (float)$jl['debit'];
                                $totCr += (float)$jl['credit'];
                            ?>
                                <tr>
                                    <td><?= e($jl['entry_number']) ?></td>
                                    <td><code><?= e($jl['acc_code']) ?></code></td>
                                    <td><?= e($jl['acc_name']) ?></td>
                                    <td><?= e($jl['line_desc']) ?></td>
                                    <td class="text-right"><?= (float)$jl['debit'] > 0 ? formatRupiah((float)$jl['debit']) : '-' ?></td>
                                    <td class="text-right"><?= (float)$jl['credit'] > 0 ? formatRupiah((float)$jl['credit']) : '-' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background:#f8fafc; font-weight:bold;">
                                <td colspan="4" class="text-right">Total Debit / Kredit (Berimbang):</td>
                                <td class="text-right" style="color:#16a34a;"><?= formatRupiah($totDr) ?></td>
                                <td class="text-right" style="color:#16a34a;"><?= formatRupiah($totCr) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Actions / Cancellation -->
    <?php if ($invoice['status'] !== 'cancelled' && (float)$invoice['paid_amount'] == 0): ?>
        <div class="card mb-4" style="border:1px dashed #fca5a5;">
            <div class="card-header" style="background:#fef2f2;">
                <h2 class="card-title" style="color:#b91c1c;">Pembatalan Faktur Penjualan</h2>
            </div>
            <div class="card-body">
                <form action="index.php?page=receiving_sales/invoice_detail&id=<?= $invId ?>" method="POST" style="display:flex; gap:15px; align-items:flex-end;" onsubmit="return confirm('Apakah Anda yakin ingin membatalkan Faktur Penjualan ini? Jurnal piutang terkait akan dibalik otomatis.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action_cancel" value="1">
                    <div class="form-group" style="flex:1; margin-bottom:0;">
                        <label>Alasan Pembatalan Faktur *</label>
                        <input type="text" name="cancel_reason" placeholder="Koreksi harga / kesalahan faktur..." required>
                    </div>
                    <button type="submit" class="btn btn-danger">
                        Batalkan Faktur &amp; Balik Jurnal
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
