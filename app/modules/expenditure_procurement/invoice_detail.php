<?php
/**
 * Modul Pengeluaran: Detail Faktur Pemasok & AP Voucher
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Detail Faktur Pemasok & Utang Usaha';
$breadcrumbCategory = 'Pengeluaran';

$db = getDbConnection();
$user = currentUser();

$invId = (int)($_GET['id'] ?? 0);
if ($invId <= 0) {
    setFlash('error', 'ID Faktur tidak valid.');
    header('Location: index.php?page=expenditure_procurement/invoices');
    exit;
}

// Fetch Invoice Header
$stmt = $db->prepare("
    SELECT si.*, s.name AS supplier_name, s.code AS supplier_code, s.phone AS supplier_phone,
           po.po_number, gr.gr_number, apv.voucher_number, apv.status AS voucher_status,
           twm.match_status, twm.qty_diff, twm.value_diff, twm.notes AS match_notes
    FROM supplier_invoices si
    JOIN suppliers s ON si.supplier_id = s.id
    LEFT JOIN purchase_orders po ON si.purchase_order_id = po.id
    LEFT JOIN goods_receipts gr ON si.goods_receipt_id = gr.id
    LEFT JOIN ap_vouchers apv ON si.id = apv.supplier_invoice_id
    LEFT JOIN three_way_matches twm ON si.id = twm.supplier_invoice_id
    WHERE si.id = :id
");
$stmt->execute([':id' => $invId]);
$inv = $stmt->fetch();

if (!$inv) {
    setFlash('error', 'Data Faktur Pemasok tidak ditemukan.');
    header('Location: index.php?page=expenditure_procurement/invoices');
    exit;
}

// Handle Cancellation Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'cancel_invoice') {
            if ($inv['status'] === 'cancelled') {
                throw new Exception("Faktur tagihan pemasok sudah dibatalkan sebelumnya.");
            }

            $cancelReason = trim($_POST['cancel_reason'] ?? '');
            if (empty($cancelReason)) {
                throw new Exception('Alasan pembatalan faktur pemasok wajib diisi.');
            }

            // Check if payment has been made
            if ((float)$inv['paid_amount'] > 0) {
                throw new Exception("Faktur {$inv['invoice_number']} tidak dapat dibatalkan karena telah memiliki pembayaran tercatat sebesar " . formatRupiah($inv['paid_amount']) . ".");
            }

            $refs = checkRecordReferences('supplier_invoices', $invId, $db);
            if (!empty($refs)) {
                throw new Exception("Faktur {$inv['invoice_number']} tidak dapat dibatalkan karena memiliki keterkaitan transaksi: " . implode(', ', $refs));
            }

            $db->beginTransaction();

            // 1. Revert/Cancel AP Voucher linked to this invoice
            $db->prepare("
                UPDATE ap_vouchers 
                SET status = 'cancelled', notes = CONCAT_WS(' | Batal: ', notes, :reason), 
                    updated_at = NOW(), updated_by = :uid 
                WHERE supplier_invoice_id = :id
            ")->execute([
                ':reason' => $cancelReason,
                ':uid'    => $user['id'],
                ':id'     => $invId,
            ]);

            // 2. Reverse Accounting Journal Entry via JournalService
            JournalService::reverseEntryByRef(
                'supplier_invoice',
                $invId,
                "Pembatalan Faktur Pemasok {$inv['invoice_number']}: {$cancelReason}",
                $user['id'],
                $db
            );

            // 3. Update Invoice status to cancelled
            $db->prepare("
                UPDATE supplier_invoices 
                SET status = 'cancelled', notes = CONCAT_WS(' | Batal: ', notes, :reason), 
                    balance = 0, updated_at = NOW(), updated_by = :uid 
                WHERE id = :id
            ")->execute([
                ':reason' => $cancelReason,
                ':uid'    => $user['id'],
                ':id'     => $invId,
            ]);

            logAudit($user['id'], 'cancel', 'supplier_invoices', $invId, ['status' => $inv['status']], [
                'status' => 'cancelled',
                'reason' => $cancelReason,
            ]);

            $db->commit();
            setFlash('success', "Faktur Pemasok {$inv['invoice_number']} berhasil dibatalkan beserta pembalikan voucher utang dan jurnal buku besar.");
            header("Location: index.php?page=expenditure_procurement/invoice_detail&id={$invId}");
            exit;
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        setFlash('error', $e->getMessage());
        header("Location: index.php?page=expenditure_procurement/invoice_detail&id={$invId}");
        exit;
    }
}

// Fetch Payment Allocations
$stmtPayments = $db->prepare("
    SELECT spa.allocated_amount, sp.payment_number, sp.payment_date, sp.payment_method, sp.bank_name, sp.bank_ref
    FROM supplier_payment_allocations spa
    JOIN supplier_payments sp ON spa.supplier_payment_id = sp.id
    WHERE spa.supplier_invoice_id = :id
    ORDER BY sp.id DESC
");
$stmtPayments->execute([':id' => $invId]);
$payments = $stmtPayments->fetchAll();

// Fetch Related Journal Entries
$stmtJv = $db->prepare("
    SELECT je.*, u.full_name AS poster_name
    FROM journal_entries je
    LEFT JOIN users u ON je.created_by = u.id
    WHERE je.ref_type = 'supplier_invoice' AND je.ref_id = :id
    ORDER BY je.id ASC
");
$stmtJv->execute([':id' => $invId]);
$journals = $stmtJv->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Faktur Pemasok: <?= e($inv['invoice_number']) ?></h1>
        <div class="page-subtitle">No. Vendor: <code><?= e($inv['supplier_invoice_number']) ?></code> &bull; Status: <?= getStatusBadge($inv['status']) ?></div>
    </div>
    <div class="page-actions" style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="index.php?page=expenditure_procurement/invoices" class="btn btn-secondary">&laquo; Kembali</a>
        
        <?php if ($inv['status'] !== 'cancelled' && (float)$inv['balance'] > 0): ?>
            <a href="index.php?page=expenditure_procurement/payment_create&invoice_id=<?= $inv['id'] ?>" class="btn btn-success">
                💳 Bayar Utang Pemasok
            </a>
        <?php endif; ?>

        <?php if ($inv['status'] !== 'cancelled' && (float)$inv['paid_amount'] <= 0): ?>
            <button type="button" class="btn btn-danger" onclick="openModal('cancel-inv-modal')">
                ✕ Batalkan Faktur & Jurnal
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="detail-grid">
    <div class="detail-item">
        <div class="detail-label">Nomor AP Voucher & Faktur</div>
        <div class="detail-value">
            <code><?= e($inv['voucher_number'] ?: '-') ?></code> &bull; <code><?= e($inv['invoice_number']) ?></code>
        </div>
    </div>
    <div class="detail-item">
        <div class="detail-label">Pemasok / Vendor</div>
        <div class="detail-value"><?= e($inv['supplier_name']) ?> (<?= e($inv['supplier_code']) ?>)</div>
    </div>
    <div class="detail-item">
        <div class="detail-label">Tanggal Faktur / Jatuh Tempo</div>
        <div class="detail-value">
            <?= formatDate($inv['invoice_date']) ?> &bull; Tempo: <?= formatDate($inv['due_date']) ?>
        </div>
    </div>
    <div class="detail-item">
        <div class="detail-label">Total Tagihan & Sisa Utang</div>
        <div class="detail-value">
            Total: <?= formatRupiah($inv['total_amount']) ?><br>
            <span style="font-size: 15px; color: <?= (float)$inv['balance'] > 0 ? 'var(--danger)' : 'var(--success)' ?>;">
                Sisa: <?= formatRupiah($inv['balance']) ?>
            </span>
        </div>
    </div>
</div>

<!-- Three-Way Match Card -->
<div class="card" style="border-left: 4px solid <?= $inv['match_status'] === 'matched' ? 'var(--success)' : 'var(--danger)' ?>;">
    <div class="card-header">
        <span class="card-title">Hasil Rekonsiliasi Dokumen (Three-Way Matching)</span>
        <?php if ($inv['match_status'] === 'matched'): ?>
            <span class="badge badge-success">✓ Sesuai (Matched)</span>
        <?php else: ?>
            <span class="badge badge-danger">⚠️ <?= e($inv['match_status']) ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p><strong>Catatan Pencocokan:</strong> <?= e($inv['match_notes'] ?: '-') ?></p>
        <div style="margin-top: 10px; font-size: 12.5px; color: var(--text-muted);">
            Referensi Dokumen: PO <a href="index.php?page=expenditure_procurement/po_detail&id=<?= $inv['purchase_order_id'] ?>"><strong><?= e($inv['po_number']) ?></strong></a>
            &bull; GR <a href="index.php?page=expenditure_procurement/gr_detail&id=<?= $inv['goods_receipt_id'] ?>"><strong><?= e($inv['gr_number']) ?></strong></a>
        </div>
    </div>
</div>

<!-- Jurnal Akuntansi Terkait -->
<?php if (!empty($journals)): ?>
    <div class="card">
        <div class="card-header">
            <span class="card-title">Jurnal Akuntansi Buku Besar Terkait</span>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>No. Voucher Jurnal</th>
                        <th>Tanggal Jurnal</th>
                        <th>Keterangan Jurnal</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($journals as $jv): ?>
                        <tr>
                            <td><code><?= e($jv['entry_number']) ?></code></td>
                            <td><?= formatDate($jv['entry_date']) ?></td>
                            <td><?= e($jv['description']) ?></td>
                            <td class="text-center"><?= getStatusBadge($jv['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Riwayat Pembayaran / Pelunasan -->
<div class="card">
    <div class="card-header">
        <span class="card-title">Riwayat Pembayaran ke Pemasok (Disbursement)</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>No. Pembayaran</th>
                    <th>Tanggal Bayar</th>
                    <th>Metode & Bank</th>
                    <th>Nomor Referensi Bank</th>
                    <th class="text-right">Nominal Dibayar</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="5" class="text-center" style="padding: 18px; color: var(--text-muted);">
                            Belum ada riwayat pembayaran yang dialokasikan untuk faktur ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td><strong><?= e($p['payment_number']) ?></strong></td>
                            <td><?= formatDate($p['payment_date']) ?></td>
                            <td><?= ucfirst(e($p['payment_method'])) ?> &bull; <?= e($p['bank_name']) ?></td>
                            <td><code><?= e($p['bank_ref'] ?: '-') ?></code></td>
                            <td class="text-right font-weight-bold" style="color: var(--success);"><?= formatRupiah($p['allocated_amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background-color: #f8fafc; font-weight: 700;">
                    <td colspan="4" class="text-right">TOTAL TELAH DIBAYAR:</td>
                    <td class="text-right" style="color: var(--success); font-size: 14px;"><?= formatRupiah($inv['paid_amount']) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Modal Pembatalan Faktur Pemasok -->
<div id="cancel-inv-modal" class="modal-overlay">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Batalkan Faktur Pemasok: <?= e($inv['invoice_number']) ?></h3>
            <button type="button" class="modal-close" onclick="closeModal('cancel-inv-modal')">&times;</button>
        </div>
        <form action="index.php?page=expenditure_procurement/invoice_detail&id=<?= $invId ?>" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_invoice">
            <div class="modal-body">
                <p style="color: var(--danger); font-size: 13.5px; margin-bottom: 12px;">
                    Perhatian: Pembatalan faktur ini akan membatalkan voucher utang usaha (AP Voucher) dan secara otomatis menerbitkan Jurnal Pembalik (Reversing Entry) pada buku besar akuntansi.
                </p>
                <div class="form-group">
                    <label class="form-label required">Alasan Pembatalan Faktur Pemasok</label>
                    <textarea name="cancel_reason" class="form-control" rows="3" required placeholder="Contoh: Kesalahan nominal tagihan dari vendor atau revisi invoice..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('cancel-inv-modal')">Batal</button>
                <button type="submit" class="btn btn-danger">Ya, Batalkan & Buat Jurnal Pembalik</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
