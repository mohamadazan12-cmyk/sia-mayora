<?php
/**
 * Modul Retur Pembelian & Nota Debit (Debit Memo)
 * PT Mayora Indah Tbk.
 * Mengelola pengembalian barang cacat/rusak ke vendor dan penerbitan Nota Debit (pengurang utang).
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../services/journal_service.php';
require_once __DIR__ . '/../../services/notification_service.php';

requireLogin();
enforcePageAccess('expenditure_procurement/returns');

$db = getDbConnection();
$user = currentUser();

// Handle Create Debit Memo Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_debit_memo') {
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $invoiceId  = !empty($_POST['supplier_invoice_id']) ? (int)$_POST['supplier_invoice_id'] : null;
        $amount     = parseDecimal($_POST['amount'] ?? 0);
        $reason     = trim($_POST['reason'] ?? '');
        $memoDate   = trim($_POST['memo_date'] ?? date('Y-m-d'));

        if ($supplierId <= 0 || $amount <= 0 || empty($reason)) {
            setFlash('error', 'Pemasok, nominal debit (>0), dan alasan nota debit wajib diisi.');
        } else {
            $db->beginTransaction();
            try {
                $memoNum = generateDocNumber('DM', 'debit_memos', 'memo_number');

                // Insert Debit Memo
                $stmtDm = $db->prepare("INSERT INTO debit_memos 
                    (memo_number, supplier_id, supplier_invoice_id, memo_date, amount, reason, status, approved_by, approved_at, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'approved', ?, NOW(), ?, NOW())");
                $stmtDm->execute([$memoNum, $supplierId, $invoiceId, $memoDate, $amount, $reason, $user['id'], $user['id']]);
                $dmId = (int)$db->lastInsertId();

                // If linked to an invoice, reduce invoice balance
                if ($invoiceId > 0) {
                    $inv = $db->query("SELECT invoice_number, balance FROM supplier_invoices WHERE id = $invoiceId")->fetch(PDO::FETCH_ASSOC);
                    if ($inv) {
                        $newBalance = max(0, (float)$inv['balance'] - $amount);
                        $db->prepare("UPDATE supplier_invoices SET balance = ?, updated_at = NOW() WHERE id = ?")->execute([$newBalance, $invoiceId]);
                    }
                }

                // Accounting Journal: Debit Utang Usaha (2100), Kredit Persediaan Bahan Baku (1310) / Retur Pembelian
                $accAP = JournalService::getAccountIdByCode('2100', $db) ?: 11;
                $accRaw = JournalService::getAccountIdByCode('1310', $db) ?: 7;

                $suppName = $db->query("SELECT name FROM suppliers WHERE id = $supplierId")->fetchColumn() ?: 'Vendor';

                $jLines = [
                    ['account_id' => $accAP,  'description' => "Pengurangan Utang Usaha {$suppName} (Nota Debit $memoNum)", 'debit' => $amount, 'credit' => 0],
                    ['account_id' => $accRaw, 'description' => "Pengurangan Persediaan atas Retur Barang ($memoNum)", 'debit' => 0, 'credit' => $amount]
                ];

                JournalService::createEntry(
                    $memoDate,
                    'debit_memo',
                    $dmId,
                    $memoNum,
                    "Penerbitan Nota Debit (Debit Memo) {$memoNum} ke {$suppName}",
                    $jLines,
                    $user['id'],
                    true,
                    $db
                );

                // Notification
                NotificationService::create([
                    'role_code' => 'purchasing',
                    'title'     => "Nota Debit Diterbitkan: $memoNum",
                    'message'   => "Finance menerbitkan Nota Debit $memoNum senilai Rp " . number_format($amount, 0, ',', '.') . " ke vendor $suppName.",
                    'type'      => 'info',
                    'category'  => 'finance',
                    'link'      => 'index.php?page=expenditure_procurement/returns',
                    'ref_type'  => 'debit_memos',
                    'ref_id'    => $dmId
                ]);

                $db->commit();
                setFlash('success', "Nota Debit {$memoNum} berhasil diterbitkan dan memotong saldo utang usaha!");
                header('Location: index.php?page=expenditure_procurement/returns');
                exit;

            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                setFlash('error', 'Gagal memproses Nota Debit: ' . $e->getMessage());
                header('Location: index.php?page=expenditure_procurement/returns');
                exit;
            }
        }
    }
}

// Fetch Suppliers
$suppliers = $db->query("SELECT id, code, name FROM suppliers WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Open Invoices for dropdown
$openInvoices = $db->query("SELECT si.id, si.invoice_number, si.supplier_invoice_number, si.balance, s.name as supplier_name 
    FROM supplier_invoices si 
    JOIN suppliers s ON si.supplier_id = s.id 
    WHERE si.balance > 0 AND si.status != 'cancelled'
    ORDER BY si.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Debit Memos List
$debitMemos = $db->query("SELECT dm.*, s.name as supplier_name, s.code as supplier_code, si.invoice_number as linked_invoice_number,
    u.full_name as creator_name
    FROM debit_memos dm 
    JOIN suppliers s ON dm.supplier_id = s.id 
    LEFT JOIN supplier_invoices si ON dm.supplier_invoice_id = si.id 
    LEFT JOIN users u ON dm.created_by = u.id 
    ORDER BY dm.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Rejected GR Items for reference
$rejectedReceipts = $db->query("SELECT gr.id as gr_id, gr.gr_number, gr.receipt_date, s.name as supplier_name, s.id as supplier_id,
    i.name as item_name, gri.qty_rejected, u.code as unit_code, gri.notes
    FROM goods_receipt_items gri 
    JOIN goods_receipts gr ON gri.goods_receipt_id = gr.id 
    JOIN suppliers s ON gr.supplier_id = s.id 
    JOIN items i ON gri.item_id = i.id 
    JOIN units u ON i.unit_id = u.id 
    WHERE gri.qty_rejected > 0 
    ORDER BY gr.id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

$totalDebitAmount = 0;
foreach ($debitMemos as $dm) {
    $totalDebitAmount += (float)$dm['amount'];
}

$pageTitle = 'Retur Pemasok & Nota Debit (Debit Memo)';
$breadcrumbCategory = 'Pengeluaran';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 class="page-title" style="margin: 0; font-size: 22px; font-weight: 700;">Retur Pemasok & Nota Debit (Debit Memo)</h1>
        <div class="page-subtitle" style="color: #64748b; font-size: 13px; margin-top: 4px;">Siklus Pengeluaran &bull; Dokumen pemotongan utang usaha atas pengembalian barang cacat atau selisih tagihan vendor.</div>
    </div>
    <div style="display: flex; gap: 8px;">
        <button type="button" onclick="openModal('addDebitMemoModal')" class="btn btn-primary" style="background: #EE2726; color: #fff; font-weight: 600; padding: 8px 14px; border-radius: 6px; border: none; cursor: pointer;">
            + Terbitkan Nota Debit Baru
        </button>
    </div>
</div>

<!-- KPI Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
        <span style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">Total Nota Debit Diterbitkan</span>
        <div style="font-size: 24px; font-weight: 700; color: #0f172a; margin-top: 6px;"><?= count($debitMemos) ?> <span style="font-size: 13px; font-weight: 400; color: #64748b;">Dokumen</span></div>
        <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Tercatat resmi dalam buku besar</div>
    </div>
    <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
        <span style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">Akumulasi Potongan Utang</span>
        <div style="font-size: 24px; font-weight: 700; color: #15803d; margin-top: 6px;">Rp <?= number_format($totalDebitAmount, 0, ',', '.') ?></div>
        <div style="font-size: 11.5px; color: #15803d; margin-top: 4px;">Mengurangi kewajiban AP perusahaan</div>
    </div>
    <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
        <span style="font-size: 12px; font-weight: 600; color: #dc2626; text-transform: uppercase;">Penerimaan Ditolak QC (Incoming)</span>
        <div style="font-size: 24px; font-weight: 700; color: #dc2626; margin-top: 6px;"><?= count($rejectedReceipts) ?> <span style="font-size: 13px; font-weight: 400; color: #dc2626;">Item Rusak</span></div>
        <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Barang cacat siap di-debit memo</div>
    </div>
</div>

<!-- Rejected GR Items Banner (if any) -->
<?php if (!empty($rejectedReceipts)): ?>
<div class="card" style="background: #fff9f9; border: 1px solid #fecaca; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <h4 style="margin: 0; font-size: 14px; font-weight: 700; color: #b91c1c;">⚠️ Barang Ditolak QC yang Membutuhkan Nota Debit</h4>
        <span style="font-size: 11.5px; color: #7f1d1d;">Penerimaan Gudang dengan kuantitas ditolak (reject)</span>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 10px;">
        <?php foreach ($rejectedReceipts as $rej): ?>
            <div style="background: #fff; border: 1px solid #fecaca; border-radius: 6px; padding: 10px 12px; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <strong><?= e($rej['supplier_name']) ?></strong><br>
                    <span style="font-size: 12px; color: #64748b;"><?= e($rej['gr_number']) ?> &bull; <?= e($rej['item_name']) ?></span><br>
                    <span style="font-size: 11.5px; color: #dc2626; font-weight: 600;">Ditolak: <?= number_format((float)$rej['qty_rejected'], 0, ',', '.') ?> <?= e($rej['unit_code']) ?></span>
                </div>
                <button type="button" onclick="prefillDebitMemo(<?= (int)$rej['supplier_id'] ?>, 'Klaim retur barang ditolak QC pada <?= e($rej['gr_number']) ?> (<?= e($rej['notes']) ?>)')" class="btn btn-sm btn-primary" style="background: #EE2726; color: #fff; font-size: 11px; padding: 4px 8px; border-radius: 4px; border: none; cursor: pointer;">
                    Klaim Debit
                </button>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Debit Memos Table -->
<div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
    <div style="padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
        <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: #0f172a;">Daftar Nota Debit (Debit Memos) yang Diterbitkan</h3>
    </div>
    <div class="table-responsive">
        <table class="table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f1f5f9; border-bottom: 2px solid #cbd5e1; text-align: left; font-size: 12px; color: #334155;">
                    <th style="padding: 12px 16px;">No. Nota Debit</th>
                    <th style="padding: 12px 16px;">Tanggal</th>
                    <th style="padding: 12px 16px;">Pemasok / Vendor</th>
                    <th style="padding: 12px 16px;">Faktur Terkait</th>
                    <th style="padding: 12px 16px;">Alasan Pemotongan</th>
                    <th style="padding: 12px 16px; text-align: right;">Nilai Potongan</th>
                    <th style="padding: 12px 16px; text-align: center;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($debitMemos)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 36px 16px; color: #94a3b8;">
                            Belum ada dokumen Nota Debit (Debit Memo) yang diterbitkan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($debitMemos as $dm): ?>
                    <tr style="border-bottom: 1px solid #f1f5f9; font-size: 13px;">
                        <td style="padding: 12px 16px;">
                            <strong style="font-family: monospace; color: #0f172a;"><?= e($dm['memo_number']) ?></strong>
                        </td>
                        <td style="padding: 12px 16px; color: #475569;">
                            <?= formatDate($dm['memo_date']) ?>
                        </td>
                        <td style="padding: 12px 16px;">
                            <strong><?= e($dm['supplier_name']) ?></strong><br>
                            <span style="font-size: 11px; color: #64748b;"><?= e($dm['supplier_code']) ?></span>
                        </td>
                        <td style="padding: 12px 16px; font-size: 12px;">
                            <?= !empty($dm['linked_invoice_number']) ? '<code>' . e($dm['linked_invoice_number']) . '</code>' : '<span style="color:#94a3b8;">Umum</span>' ?>
                        </td>
                        <td style="padding: 12px 16px; color: #475569; font-size: 12.5px; max-width: 320px;">
                            <?= e($dm['reason']) ?>
                        </td>
                        <td style="padding: 12px 16px; text-align: right; font-weight: 700; color: #15803d;">
                            Rp <?= number_format((float)$dm['amount'], 0, ',', '.') ?>
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <span class="badge" style="background: #dcfce7; color: #15803d; font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 4px;">
                                ✓ Approved & Posted
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Terbitkan Nota Debit -->
<div id="addDebitMemoModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: #fff; border-radius: 10px; max-width: 520px; width: 90%; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="margin: 0; font-size: 18px; font-weight: 700; color: #1e293b;">Terbitkan Nota Debit (Debit Memo)</h3>
            <button type="button" onclick="closeModal('addDebitMemoModal')" style="background: none; border: none; font-size: 18px; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>

        <form method="POST" action="index.php?page=expenditure_procurement/returns">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_debit_memo">

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Pemasok / Vendor *</label>
                <select id="modal_supplier_id" name="supplier_id" required style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
                    <option value="">-- Pilih Pemasok --</option>
                    <?php foreach ($suppliers as $sp): ?>
                        <option value="<?= (int)$sp['id'] ?>"><?= e($sp['name']) ?> (<?= e($sp['code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Faktur Pemasok Terkait (Opsional)</label>
                <select name="supplier_invoice_id" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
                    <option value="">-- Tidak dikaitkan spesifik / Umum --</option>
                    <?php foreach ($openInvoices as $oi): ?>
                        <option value="<?= (int)$oi['id'] ?>"><?= e($oi['invoice_number']) ?> - <?= e($oi['supplier_name']) ?> (Sisa Utang: Rp <?= number_format((float)$oi['balance'], 0, ',', '.') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Tanggal Nota Debit</label>
                    <input type="date" name="memo_date" value="<?= date('Y-m-d') ?>" required style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Nominal Pemotongan (Rp) *</label>
                    <input type="number" name="amount" min="1" step="1000" required placeholder="Contoh: 1500000" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
                </div>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Alasan Penerbitan Nota Debit *</label>
                <textarea id="modal_reason" name="reason" rows="3" required placeholder="Contoh: Pengembalian bahan baku cacat uji QC / Selisih harga faktur vs PO" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;"></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeModal('addDebitMemoModal')" class="btn btn-secondary" style="padding: 8px 16px; border-radius: 6px;">Batal</button>
                <button type="submit" class="btn btn-primary" style="background: #EE2726; color: #fff; font-weight: 600; padding: 8px 16px; border-radius: 6px; border: none; cursor: pointer;">Terbitkan & Kurangi Utang</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'flex';
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}
function prefillDebitMemo(supplierId, reason) {
    document.getElementById('modal_supplier_id').value = supplierId;
    document.getElementById('modal_reason').value = reason;
    openModal('addDebitMemoModal');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
