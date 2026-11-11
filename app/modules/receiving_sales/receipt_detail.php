<?php
/**
 * Modul Penerimaan: Detail Kwitansi Kas Masuk (Customer Receipt Detail)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Detail Penerimaan Kas Pelanggan';
$breadcrumbCategory = 'Penerimaan';

$db = getDbConnection();
$user = currentUser();

$receiptId = (int)($_GET['id'] ?? 0);
if ($receiptId <= 0) {
    setFlash('error', 'ID Penerimaan Kas tidak valid.');
    header('Location: index.php?page=receiving_sales/receipts');
    exit;
}

// Fetch Receipt Header
$stmt = $db->prepare("
    SELECT cr.*, c.name AS customer_name, c.code AS customer_code, c.address AS customer_address, c.phone AS customer_phone,
           u.full_name AS creator_name, uv.full_name AS verifier_name
    FROM customer_receipts cr
    JOIN customers c ON cr.customer_id = c.id
    LEFT JOIN users u ON cr.created_by = u.id
    LEFT JOIN users uv ON cr.verified_by = uv.id
    WHERE cr.id = :id
");
$stmt->execute([':id' => $receiptId]);
$receipt = $stmt->fetch();

if (!$receipt) {
    setFlash('error', 'Data Penerimaan Kas tidak ditemukan.');
    header('Location: index.php?page=receiving_sales/receipts');
    exit;
}

// Fetch Allocated Invoices
$stmtAlloc = $db->prepare("
    SELECT cra.*, inv.invoice_number, inv.invoice_date, inv.total_amount AS invoice_total, inv.balance AS invoice_balance, inv.status AS invoice_status
    FROM customer_receipt_allocations cra
    JOIN sales_invoices inv ON cra.sales_invoice_id = inv.id
    WHERE cra.customer_receipt_id = :id
");
$stmtAlloc->execute([':id' => $receiptId]);
$allocations = $stmtAlloc->fetchAll();

// Handle Cancellation Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'cancel_receipt') {
            if ($receipt['status'] === 'cancelled') {
                throw new Exception("Kwitansi penerimaan kas sudah dibatalkan sebelumnya.");
            }

            $cancelReason = trim($_POST['cancel_reason'] ?? '');
            if (empty($cancelReason)) {
                throw new Exception('Alasan pembatalan kwitansi kas wajib diisi.');
            }

            $db->beginTransaction();

            // 1. Revert sales invoices balances and statuses
            foreach ($allocations as $alloc) {
                $invId = (int)$alloc['sales_invoice_id'];
                $amt   = (float)$alloc['allocated_amount'];

                $stmtInv = $db->prepare("SELECT * FROM sales_invoices WHERE id = :id FOR UPDATE");
                $stmtInv->execute([':id' => $invId]);
                $inv = $stmtInv->fetch();

                if ($inv) {
                    $newPaid = max(0, (float)$inv['paid_amount'] - $amt);
                    $newBal  = (float)$inv['total_amount'] - $newPaid;
                    $newStat = ($newBal <= 0.01) ? 'paid' : ($newPaid > 0 ? 'partially_paid' : 'issued');

                    $db->prepare("
                        UPDATE sales_invoices 
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

            // 2. Re-apply customer credit_used
            $db->prepare("UPDATE customers SET credit_used = credit_used + :amt WHERE id = :cid")
               ->execute([':amt' => (float)$receipt['total_amount'], ':cid' => $receipt['customer_id']]);

            // 3. Reverse general ledger journal entry
            JournalService::reverseEntryByRef(
                'customer_receipt',
                $receiptId,
                "Pembatalan Kwitansi Kas {$receipt['receipt_number']}: {$cancelReason}",
                $user['id'],
                $db
            );

            // 4. Update customer_receipts status to cancelled
            $db->prepare("
                UPDATE customer_receipts 
                SET status = 'cancelled', notes = CONCAT_WS(' | Batal: ', notes, :reason), 
                    updated_at = NOW(), updated_by = :uid 
                WHERE id = :id
            ")->execute([
                ':reason' => $cancelReason,
                ':uid'    => $user['id'],
                ':id'     => $receiptId,
            ]);

            logAudit($user['id'], 'cancel', 'customer_receipts', $receiptId, ['status' => $receipt['status']], [
                'status' => 'cancelled',
                'reason' => $cancelReason,
            ]);

            $db->commit();
            setFlash('success', "Kwitansi kas {$receipt['receipt_number']} berhasil dibatalkan. Tagihan piutang dan jurnal kas/bank telah dipulihkan.");
            header("Location: index.php?page=receiving_sales/receipt_detail&id={$receiptId}");
            exit;
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', $e->getMessage());
        header("Location: index.php?page=receiving_sales/receipt_detail&id={$receiptId}");
        exit;
    }
}

// Fetch Related Accounting Journal Entry
$stmtJe = $db->prepare("
    SELECT je.*, jel.account_id, jel.description AS line_desc, jel.debit, jel.credit, acc.code AS acc_code, acc.name AS acc_name
    FROM journal_entries je
    JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    JOIN accounts acc ON jel.account_id = acc.id
    WHERE je.ref_type = 'customer_receipt' AND je.ref_id = :id
    ORDER BY jel.id ASC
");
$stmtJe->execute([':id' => $receiptId]);
$journalLines = $stmtJe->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<!-- Print-Only Official Receipt Slip (Kwitansi Kas Masuk) -->
<div class="print-only" style="display:none; padding: 25px;">
    <div style="border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 20px; display:flex; justify-content:space-between;">
        <div>
            <h2 style="margin:0; font-size:22px;">BUKTI PENERIMAAN KAS / BANK (KWITANSI)</h2>
            <div style="font-weight:bold; font-size:14px; margin-top:4px;">PT MANUFAKTUR &amp; DISTRIBUSI OPERASIONAL INDONESIA</div>
            <div style="font-size:12px; color:#475569;">Kawasan Industri Sentul Kav. 88, Bogor, Jawa Barat</div>
        </div>
        <div style="text-align:right;">
            <h3 style="margin:0; font-size:18px;"><?= e($receipt['receipt_number']) ?></h3>
            <div>Tanggal: <?= formatDate($receipt['receipt_date']) ?></div>
            <div>Metode: <?= strtoupper($receipt['payment_method']) ?></div>
        </div>
    </div>

    <table style="width:100%; margin-bottom: 25px; font-size:13px; line-height: 1.8;">
        <tr>
            <td style="width:25%;"><strong>Telah Diterima Dari</strong></td>
            <td style="width:75%;">: <strong><?= e($receipt['customer_name']) ?></strong> (<?= e($receipt['customer_code']) ?>)</td>
        </tr>
        <tr>
            <td><strong>Sejumlah Uang</strong></td>
            <td>: <strong style="font-size:15px; color:#0f172a;"><?= formatRupiah((float)$receipt['total_amount']) ?></strong></td>
        </tr>
        <tr>
            <td><strong>Bank / Rekening Masuk</strong></td>
            <td>: <?= e($receipt['bank_name'] ?: '-') ?> <?php if ($receipt['bank_ref']): ?>(Ref: <?= e($receipt['bank_ref']) ?>)<?php endif; ?></td>
        </tr>
        <tr>
            <td><strong>Untuk Pembayaran</strong></td>
            <td>: Pelunasan Faktur Penjualan terlampir</td>
        </tr>
    </table>

    <table class="table" style="width:100%; border-collapse:collapse; margin-bottom:30px;">
        <thead>
            <tr style="border-bottom:2px solid #000; background:#f8fafc;">
                <th style="padding:6px; text-align:left;">No</th>
                <th style="padding:6px; text-align:left;">Nomor Faktur</th>
                <th style="padding:6px; text-align:left;">Tgl Faktur</th>
                <th style="padding:6px; text-align:right;">Nilai Faktur (Rp)</th>
                <th style="padding:6px; text-align:right;">Alokasi Pelunasan (Rp)</th>
                <th style="padding:6px; text-align:right;">Sisa Tagihan (Rp)</th>
            </tr>
        </thead>
        <tbody>
            <?php $no = 1; foreach ($allocations as $alloc): ?>
                <tr style="border-bottom:1px solid #ddd;">
                    <td style="padding:6px;"><?= $no++ ?></td>
                    <td style="padding:6px; font-weight:bold;"><?= e($alloc['invoice_number']) ?></td>
                    <td style="padding:6px;"><?= formatDate($alloc['invoice_date']) ?></td>
                    <td style="padding:6px; text-align:right;"><?= formatRupiah((float)$alloc['invoice_total']) ?></td>
                    <td style="padding:6px; text-align:right; font-weight:bold;"><?= formatRupiah((float)$alloc['allocated_amount']) ?></td>
                    <td style="padding:6px; text-align:right;"><?= formatRupiah((float)$alloc['invoice_balance']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="border-top:2px solid #000; font-weight:bold;">
                <td colspan="4" style="padding:8px; text-align:right;">Total Penerimaan Kas:</td>
                <td style="padding:8px; text-align:right;"><?= formatRupiah((float)$receipt['total_amount']) ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <div style="display:flex; justify-content:space-between; margin-top:40px; text-align:center; font-size:13px;">
        <div style="width:40%;">
            <div>Disetorkan Oleh (Pelanggan)</div>
            <div style="height:70px;"></div>
            <div>( ..................................... )</div>
        </div>
        <div style="width:40%;">
            <div>Diterima &amp; Diverifikasi Oleh (Kasir / Finance)</div>
            <div style="height:70px;"></div>
            <div>( <?= e($receipt['verifier_name'] ?: $receipt['creator_name']) ?> )</div>
        </div>
    </div>
</div>

<!-- Screen Display View -->
<div class="no-print">
    <div class="page-header">
        <div>
            <h1 class="page-title">Kwitansi Penerimaan Kas: <?= e($receipt['receipt_number']) ?></h1>
            <div class="page-subtitle">Pelanggan: <strong><?= e($receipt['customer_name']) ?></strong> &bull; Jumlah: <strong style="color:#16a34a;"><?= formatRupiah((float)$receipt['total_amount']) ?></strong></div>
        </div>
        <div class="page-actions" style="display:flex; gap:10px; flex-wrap: wrap;">
            <button onclick="window.print()" class="btn btn-secondary">
                🖨️ Cetak Bukti Kwitansi
            </button>
            <?php if ($receipt['status'] !== 'cancelled'): ?>
                <button type="button" class="btn btn-danger" onclick="openModal('cancel-rc-modal')">
                    ✕ Batalkan Kwitansi &amp; Balikkan Jurnal
                </button>
            <?php endif; ?>
            <a href="index.php?page=receiving_sales/receipts" class="btn btn-secondary">
                &larr; Kembali ke Daftar
            </a>
        </div>
    </div>

    <!-- Receipt Header Card -->
    <div class="card mb-4">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <h2 class="card-title">Informasi Kas Masuk</h2>
            <div><?= statusBadge($receipt['status']) ?></div>
        </div>
        <div class="card-body">
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:20px;">
                <div>
                    <div class="text-muted" style="font-size:12px;">Pelanggan</div>
                    <strong style="font-size:16px;"><?= e($receipt['customer_name']) ?></strong>
                    <div class="text-muted" style="font-size:12px;"><?= e($receipt['customer_code']) ?></div>
                    <div style="margin-top:5px; font-size:13px;"><?= nl2br(e($receipt['customer_address'])) ?></div>
                </div>
                <div>
                    <div class="text-muted" style="font-size:12px;">Metode &amp; Bank</div>
                    <div>Metode: <strong class="badge badge-info"><?= strtoupper($receipt['payment_method']) ?></strong></div>
                    <div style="margin-top:4px;">Rekening Masuk: <strong><?= e($receipt['bank_name'] ?: '-') ?></strong></div>
                    <div>No. Ref Mutasi: <strong><?= e($receipt['bank_ref'] ?: '-') ?></strong></div>
                </div>
                <div>
                    <div class="text-muted" style="font-size:12px;">Waktu &amp; Otorisasi</div>
                    <div>Tanggal Terima: <strong><?= formatDate($receipt['receipt_date']) ?></strong></div>
                    <div>Dibuat oleh: <?= e($receipt['creator_name'] ?: 'Sistem') ?></div>
                    <div>Diverifikasi oleh: <?= e($receipt['verifier_name'] ?: 'Staff Keuangan') ?></div>
                </div>
                <div style="background:#f0fdf4; padding:15px; border-radius:6px; border:1px solid #bbf7d0;">
                    <div class="text-muted" style="font-size:12px; color:#166534;">Total Pembayaran</div>
                    <div style="font-size:20px; font-weight:bold; color:#15803d; margin-top:5px;">
                        <?= formatRupiah((float)$receipt['total_amount']) ?>
                    </div>
                    <?php if ($receipt['notes']): ?>
                        <div style="font-size:12px; margin-top:8px; color:#475569;">Catatan: <?= e($receipt['notes']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Allocated Invoices Table -->
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="card-title">Faktur Penjualan yang Dilunasi</h2>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width:40px;">No</th>
                            <th>No. Faktur</th>
                            <th>Tgl Faktur</th>
                            <th class="text-right">Total Faktur</th>
                            <th class="text-right">Alokasi Pelunasan (Rp)</th>
                            <th class="text-right">Sisa Tagihan Saat Ini</th>
                            <th>Status Faktur</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; foreach ($allocations as $alloc): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td>
                                    <strong><a href="index.php?page=receiving_sales/invoice_detail&id=<?= $alloc['sales_invoice_id'] ?>"><?= e($alloc['invoice_number']) ?></a></strong>
                                </td>
                                <td><?= formatDate($alloc['invoice_date']) ?></td>
                                <td class="text-right"><?= formatRupiah((float)$alloc['invoice_total']) ?></td>
                                <td class="text-right font-bold" style="color:#16a34a;"><?= formatRupiah((float)$alloc['allocated_amount']) ?></td>
                                <td class="text-right font-medium" style="color: <?= (float)$alloc['invoice_balance'] > 0 ? '#ea580c' : '#64748b' ?>;">
                                    <?= formatRupiah((float)$alloc['invoice_balance']) ?>
                                </td>
                                <td><?= statusBadge($alloc['invoice_status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#f8fafc; font-weight:bold;">
                            <td colspan="4" class="text-right">Total Terbayar:</td>
                            <td class="text-right" style="color:#16a34a;"><?= formatRupiah((float)$receipt['total_amount']) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Related Accounting Journal Entry -->
    <?php if (!empty($journalLines)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h2 class="card-title">Jurnal Umum Kas Masuk (General Ledger)</h2>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>No. Jurnal</th>
                                <th>Kode Akun</th>
                                <th>Nama Akun</th>
                                <th>Keterangan Baris</th>
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
                                <td colspan="4" class="text-right">Keseimbangan Debit / Kredit:</td>
                                <td class="text-right" style="color:#16a34a;"><?= formatRupiah($totDr) ?></td>
                                <td class="text-right" style="color:#16a34a;"><?= formatRupiah($totCr) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Pembatalan Kwitansi Kas Masuk -->
<div id="cancel-rc-modal" class="modal-overlay">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Konfirmasi Pembatalan Kwitansi: <?= e($receipt['receipt_number']) ?></h3>
            <button type="button" class="modal-close" onclick="closeModal('cancel-rc-modal')">&times;</button>
        </div>
        <form action="index.php?page=receiving_sales/receipt_detail&id=<?= $receiptId ?>" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_receipt">
            <div class="modal-body">
                <p style="color: var(--danger); font-size: 13.5px; margin-bottom: 12px;">
                    Perhatian: Membatalkan bukti penerimaan kas ini akan mengembalikan saldo tagihan pada faktur-faktur terkait, menambah kembali pemakaian limit kredit pelanggan, dan secara otomatis menerbitkan Jurnal Pembalik (Reversing Entry) pada buku besar kas/bank.
                </p>
                <div class="form-group">
                    <label class="form-label required">Alasan Pembatalan / Void Kwitansi</label>
                    <textarea name="cancel_reason" class="form-control" rows="3" required placeholder="Contoh: Cek/giro ditolak bank, salah input rekening atau nominal penerimaan..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('cancel-rc-modal')">Batal</button>
                <button type="submit" class="btn btn-danger">Ya, Batalkan Kwitansi &amp; Balikkan Jurnal</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
