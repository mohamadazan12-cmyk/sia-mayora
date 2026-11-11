<?php
/**
 * Modul Penerimaan: Retur Penjualan & Nota Kredit (Sales Returns & Credit Notes)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Retur Penjualan & Nota Kredit';
$breadcrumbCategory = 'Penerimaan';

$db = getDbConnection();
$user = currentUser();

// Handle Create Customer Return
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_return'])) {
    requireCsrf();

    $custId = (int)($_POST['customer_id'] ?? 0);
    $invId = !empty($_POST['sales_invoice_id']) ? (int)$_POST['sales_invoice_id'] : null;
    $returnDate = trim($_POST['return_date'] ?? date('Y-m-d'));
    $reason = trim($_POST['reason'] ?? '');
    $itemId = (int)($_POST['item_id'] ?? 0);
    $qty = (float)($_POST['qty'] ?? 0);
    $unitPrice = (float)($_POST['unit_price'] ?? 0);
    $conditionNotes = trim($_POST['condition_notes'] ?? '');

    if ($custId <= 0 || $itemId <= 0 || $qty <= 0) {
        setFlash('error', 'Pelanggan, barang, dan kuantitas retur wajib diisi dengan benar.');
        header('Location: index.php?page=receiving_sales/returns');
        exit;
    }

    try {
        $db->beginTransaction();

        $subtotal = round($qty * $unitPrice, 2);

        // Generate return number: RET-YYYYMM-XXXX
        $yearMonth = date('Ym', strtotime($returnDate));
        $prefix = 'RET-' . $yearMonth;
        $stmtLast = $db->prepare("SELECT return_number FROM customer_returns WHERE return_number LIKE :pfx ORDER BY id DESC LIMIT 1");
        $stmtLast->execute([':pfx' => $prefix . '-%']);
        $lastRet = $stmtLast->fetchColumn();
        $seq = 1;
        if ($lastRet) {
            $parts = explode('-', $lastRet);
            $seq = (int)end($parts) + 1;
        }
        $returnNumber = sprintf('%s-%04d', $prefix, $seq);

        $stmtIns = $db->prepare("
            INSERT INTO customer_returns 
            (return_number, customer_id, sales_invoice_id, return_date, reason, total_amount, status, created_by, created_at)
            VALUES 
            (:r_num, :c_id, :inv_id, :r_date, :reason, :total, 'pending_approval', :c_by, NOW())
        ");
        $stmtIns->execute([
            ':r_num'   => $returnNumber,
            ':c_id'    => $custId,
            ':inv_id'  => $invId,
            ':r_date'  => $returnDate,
            ':reason'  => $reason,
            ':total'   => $subtotal,
            ':c_by'    => $user['id'],
        ]);
        $returnId = $db->lastInsertId();

        $stmtItem = $db->prepare("
            INSERT INTO customer_return_items (customer_return_id, item_id, qty, unit_price, subtotal, condition_notes)
            VALUES (:ret_id, :item_id, :qty, :price, :subtotal, :notes)
        ");
        $stmtItem->execute([
            ':ret_id'   => $returnId,
            ':item_id'  => $itemId,
            ':qty'      => $qty,
            ':price'    => $unitPrice,
            ':subtotal' => $subtotal,
            ':notes'    => $conditionNotes,
        ]);

        ApprovalService::submit('customer_return', $returnId, $returnNumber, "Pengajuan Retur {$returnNumber}: {$reason}", $db);
        logAudit($user['id'], 'create_customer_return', 'customer_returns', $returnId, null, [
            'return_number' => $returnNumber,
            'total_amount'  => $subtotal,
        ]);

        $db->commit();
        setFlash('success', "Pengajuan Retur Penjualan {$returnNumber} berhasil diajukan dan menunggu persetujuan.");
        header('Location: index.php?page=receiving_sales/returns');
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Gagal membuat retur penjualan: ' . $e->getMessage());
        header('Location: index.php?page=receiving_sales/returns');
        exit;
    }
}

// Handle Cancel Return
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_cancel_return'])) {
    requireCsrf();
    $returnId = (int)($_POST['return_id'] ?? 0);
    $cancelReason = trim($_POST['cancel_reason'] ?? 'Dibatalkan oleh staf');

    try {
        $stmtRet = $db->prepare("SELECT * FROM customer_returns WHERE id = :id");
        $stmtRet->execute([':id' => $returnId]);
        $ret = $stmtRet->fetch();

        if (!$ret || $ret['status'] !== 'pending_approval') {
            throw new Exception('Hanya retur berstatus pending approval yang dapat dibatalkan.');
        }

        $db->prepare("UPDATE customer_returns SET status = 'cancelled', reason = CONCAT_WS(' | Batal: ', reason, :reason), updated_at = NOW(), updated_by = :uid WHERE id = :id")
           ->execute([':reason' => $cancelReason, ':uid' => $user['id'], ':id' => $returnId]);

        ApprovalService::reject('customer_return', $returnId, $ret['return_number'], $user['id'], $cancelReason, $db);
        logAudit($user['id'], 'cancel_customer_return', 'customer_returns', $returnId, ['status' => 'pending_approval'], ['status' => 'cancelled', 'reason' => $cancelReason]);

        setFlash('warning', "Pengajuan Retur {$ret['return_number']} telah dibatalkan.");
        header('Location: index.php?page=receiving_sales/returns');
        exit;
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        header('Location: index.php?page=receiving_sales/returns');
        exit;
    }
}

// Handle Approve / Process Return and Generate Credit Note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_process_return'])) {
    requireCsrf();

    $returnId = (int)($_POST['return_id'] ?? 0);
    $restockWarehouseId = (int)($_POST['warehouse_id'] ?? 0);
    $doRestock = isset($_POST['do_restock']);

    try {
        $db->beginTransaction();

        $stmtRet = $db->prepare("
            SELECT cr.*, cri.item_id, cri.qty, cri.unit_price, c.name AS customer_name
            FROM customer_returns cr
            JOIN customer_return_items cri ON cr.id = cri.customer_return_id
            JOIN customers c ON cr.customer_id = c.id
            WHERE cr.id = :id FOR UPDATE
        ");
        $stmtRet->execute([':id' => $returnId]);
        $ret = $stmtRet->fetch();

        if (!$ret || $ret['status'] !== 'pending_approval') {
            throw new Exception('Data retur tidak ditemukan atau tidak dalam status pending_approval.');
        }

        // 1. Restock to finished goods warehouse if in good condition
        if ($doRestock && $restockWarehouseId > 0) {
            InventoryService::addStock(
                $ret['item_id'],
                $restockWarehouseId,
                (float)$ret['qty'],
                'customer_return',
                $returnId,
                $ret['return_number'],
                "Penerimaan kembali barang retur {$ret['return_number']}",
                $user['id'],
                $db
            );
        }

        // 2. Generate Credit Note
        $yearMonth = date('Ym', strtotime($ret['return_date']));
        $prefixCn = 'CN-' . $yearMonth;
        $stmtLastCn = $db->prepare("SELECT cn_number FROM credit_notes WHERE cn_number LIKE :pfx ORDER BY id DESC LIMIT 1");
        $stmtLastCn->execute([':pfx' => $prefixCn . '-%']);
        $lastCn = $stmtLastCn->fetchColumn();
        $seqCn = 1;
        if ($lastCn) {
            $parts = explode('-', $lastCn);
            $seqCn = (int)end($parts) + 1;
        }
        $cnNumber = sprintf('%s-%04d', $prefixCn, $seqCn);

        $stmtCn = $db->prepare("
            INSERT INTO credit_notes 
            (cn_number, customer_return_id, customer_id, sales_invoice_id, cn_date, amount, reason, status, created_by, created_at)
            VALUES 
            (:cn_num, :ret_id, :c_id, :inv_id, :cn_date, :amt, :reason, 'applied', :c_by, NOW())
        ");
        $stmtCn->execute([
            ':cn_num'  => $cnNumber,
            ':ret_id'  => $returnId,
            ':c_id'    => $ret['customer_id'],
            ':inv_id'  => $ret['sales_invoice_id'],
            ':cn_date' => date('Y-m-d'),
            ':amt'     => $ret['total_amount'],
            ':reason'  => "Nota Kredit atas Retur {$ret['return_number']}: {$ret['reason']}",
            ':c_by'    => $user['id'],
        ]);
        $cnId = $db->lastInsertId();

        // 3. If referenced invoice exists, apply credit note to balance
        if ($ret['sales_invoice_id']) {
            $db->prepare("
                UPDATE sales_invoices 
                SET balance = GREATEST(0, balance - :amt),
                    status = CASE WHEN (balance - :amt) <= 0.001 THEN 'paid' ELSE status END,
                    notes = CONCAT_WS(' | ', notes, :cn_note),
                    updated_at = NOW(),
                    updated_by = :uid
                WHERE id = :inv_id
            ")->execute([
                ':amt'     => $ret['total_amount'],
                ':cn_note' => "Dikurangi Nota Kredit {$cnNumber} (" . formatRupiah((float)$ret['total_amount']) . ")",
                ':uid'     => $user['id'],
                ':inv_id'  => $ret['sales_invoice_id'],
            ]);
        }

        // 4. Reduce customer credit_used
        $db->prepare("UPDATE customers SET credit_used = GREATEST(0, credit_used - :amt) WHERE id = :c_id")
           ->execute([':amt' => $ret['total_amount'], ':c_id' => $ret['customer_id']]);

        // 5. Update customer_returns status
        $db->prepare("UPDATE customer_returns SET status = 'processed', updated_at = NOW(), updated_by = :uid WHERE id = :id")
           ->execute([':uid' => $user['id'], ':id' => $returnId]);

        // 6. Balanced Accounting Journal:
        // Dr: Retur dan Potongan Rabat Penjualan (Account ID 41, code 4-9000) = amount
        // Cr: Piutang Usaha Pelanggan Domestik (Account ID 8) = amount
        $journalLines = [
            [
                'account_id'  => 41, // Retur Penjualan
                'description' => "Retur Penjualan {$ret['return_number']} ({$cnNumber})",
                'debit'       => (float)$ret['total_amount'],
                'credit'      => 0,
            ],
            [
                'account_id'  => 8, // Piutang Usaha
                'description' => "Pengurangan Piutang Nota Kredit {$cnNumber}",
                'debit'       => 0,
                'credit'      => (float)$ret['total_amount'],
            ],
        ];

        JournalService::createEntry(
            date('Y-m-d'),
            'credit_note',
            $cnId,
            $cnNumber,
            "Penerbitan Nota Kredit {$cnNumber} atas Retur {$ret['return_number']}",
            $journalLines,
            $user['id'],
            true,
            $db
        );

        ApprovalService::approve('customer_return', $returnId, $ret['return_number'], $user['id'], 'Retur disetujui dan Nota Kredit diterbitkan.', $db);
        logAudit($user['id'], 'process_customer_return', 'customer_returns', $returnId, ['status' => 'pending_approval'], ['status' => 'processed', 'cn_number' => $cnNumber]);

        $db->commit();
        setFlash('success', "Retur {$ret['return_number']} telah diproses! Nota Kredit {$cnNumber} sebesar " . formatRupiah((float)$ret['total_amount']) . " berhasil diterbitkan.");
        header('Location: index.php?page=receiving_sales/returns');
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        setFlash('error', 'Gagal memproses retur: ' . $e->getMessage());
        header('Location: index.php?page=receiving_sales/returns');
        exit;
    }
}

// Fetch list of Returns
$returns = $db->query("
    SELECT cr.*, c.name AS customer_name, c.code AS customer_code,
           inv.invoice_number, i.name AS item_name, i.code AS item_code, cri.qty, cri.unit_price,
           u.full_name AS creator_name,
           cn.cn_number, cn.amount AS cn_amount
    FROM customer_returns cr
    JOIN customers c ON cr.customer_id = c.id
    LEFT JOIN sales_invoices inv ON cr.sales_invoice_id = inv.id
    LEFT JOIN customer_return_items cri ON cr.id = cri.customer_return_id
    LEFT JOIN items i ON cri.item_id = i.id
    LEFT JOIN users u ON cr.created_by = u.id
    LEFT JOIN credit_notes cn ON cn.customer_return_id = cr.id
    ORDER BY cr.id DESC
")->fetchAll();

// Fetch auxiliary data for Create Return Form
$customers = $db->query("SELECT id, code, name FROM customers ORDER BY name ASC")->fetchAll();
$itemsList = $db->query("SELECT id, code, name, selling_price AS sell_price FROM items WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$invoicesList = $db->query("SELECT id, invoice_number, total_amount, balance FROM sales_invoices WHERE status != 'cancelled' ORDER BY id DESC LIMIT 50")->fetchAll();
$warehouses = $db->query("SELECT id, code, name FROM warehouses WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Retur Penjualan &amp; Nota Kredit</h1>
        <div class="page-subtitle">Siklus Penerimaan &bull; Pengembalian Barang Rusak/Klaim, Pengembalian Fisik ke Gudang, dan Penerbitan Nota Kredit (Credit Note)</div>
    </div>
</div>

<!-- Create Return Form Collapsible -->
<div class="card mb-4">
    <details>
        <summary style="cursor:pointer; padding:15px 20px; font-weight:600; font-size:15px; color:#0284c7; background:#f8fafc; border-radius:6px; list-style:none; display:flex; justify-content:space-between; align-items:center;">
            <span>+ Buat / Ajukan Retur Penjualan Baru</span>
            <span class="btn btn-sm btn-primary">Buka Form Input &darr;</span>
        </summary>
        <div style="padding:20px; border-top:1px solid #e2e8f0;">
            <form action="index.php?page=receiving_sales/returns" method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action_create_return" value="1">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:15px;">
                    <div class="form-group">
                        <label>Pelanggan *</label>
                        <select name="customer_id" required>
                            <option value="">-- Pilih Pelanggan --</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= e($c['name']) ?> (<?= e($c['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Referensi Faktur Penjualan (Opsional)</label>
                        <select name="sales_invoice_id">
                            <option value="">-- Tidak Terikat Faktur Tertentu --</option>
                            <?php foreach ($invoicesList as $inv): ?>
                                <option value="<?= $inv['id'] ?>"><?= e($inv['invoice_number']) ?> (Saldo: <?= formatRupiah((float)$inv['balance']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Tanggal Retur *</label>
                        <input type="date" name="return_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Produk / Barang yang Diretur *</label>
                        <select name="item_id" required>
                            <option value="">-- Pilih Barang --</option>
                            <?php foreach ($itemsList as $it): ?>
                                <option value="<?= $it['id'] ?>"><?= e($it['name']) ?> (<?= e($it['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Jumlah Kuantitas Retur *</label>
                        <input type="number" step="0.01" min="0.01" name="qty" placeholder="Misal: 1" required>
                    </div>
                    <div class="form-group">
                        <label>Nilai Harga Satuan (Rp) *</label>
                        <input type="number" step="0.01" min="0" name="unit_price" placeholder="Harga satuan faktur" required>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Alasan Pengembalian / Klaim Garansi *</label>
                        <input type="text" name="reason" placeholder="Misal: Cacat manufaktur pada panel kontrol / komponen pecah saat ekspedisi" required>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Kondisi Fisik Barang &amp; Catatan QC</label>
                        <input type="text" name="condition_notes" placeholder="Segel masih utuh / kemasan penyok / perlu rekondisi teknis">
                    </div>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:15px;">
                    <button type="submit" class="btn btn-primary">
                        Ajukan Retur Penjualan &rarr;
                    </button>
                </div>
            </form>
        </div>
    </details>
</div>

<!-- List of Customer Returns -->
<div class="card mb-4">
    <div class="card-header">
        <h2 class="card-title">Daftar Retur Penjualan &amp; Penerbitan Nota Kredit</h2>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>No. Retur</th>
                        <th>Pelanggan</th>
                        <th>Barang &amp; Kuantitas</th>
                        <th>Tgl Retur</th>
                        <th class="text-right">Total Nilai Retur</th>
                        <th>Alasan &amp; Kondisi</th>
                        <th>Status</th>
                        <th>Nota Kredit (CN)</th>
                        <th class="text-center">Aksi / Otorisasi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($returns)): ?>
                        <tr>
                            <td colspan="9" class="text-center" style="padding:30px; color:#64748b;">
                                Belum ada transaksi retur penjualan yang tercatat.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($returns as $ret): ?>
                            <tr>
                                <td><strong><?= e($ret['return_number']) ?></strong></td>
                                <td>
                                    <strong><?= e($ret['customer_name']) ?></strong>
                                    <div class="text-muted" style="font-size:11px;"><?= e($ret['customer_code']) ?></div>
                                </td>
                                <td>
                                    <strong><?= e($ret['item_name'] ?: 'Barang') ?></strong>
                                    <div class="text-muted" style="font-size:11px;"><?= formatNumber((float)$ret['qty']) ?> unit &bull; <?= e($ret['item_code'] ?: '') ?></div>
                                </td>
                                <td><?= formatDate($ret['return_date']) ?></td>
                                <td class="text-right font-bold" style="color:#dc2626;">
                                    <?= formatRupiah((float)$ret['total_amount']) ?>
                                </td>
                                <td>
                                    <div><?= e($ret['reason'] ?: '-') ?></div>
                                </td>
                                <td><?= statusBadge($ret['status']) ?></td>
                                <td>
                                    <?php if ($ret['cn_number']): ?>
                                        <span class="badge badge-success"><?= e($ret['cn_number']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($ret['status'] === 'pending_approval'): ?>
                                        <button type="button" class="btn btn-sm btn-primary" onclick="openModal('modal-process-return-<?= $ret['id'] ?>')">
                                            ✓ Proses &amp; CN
                                        </button>
                                        <form action="index.php?page=receiving_sales/returns" method="POST" style="display:inline;" onsubmit="return confirm('Batalkan pengajuan retur <?= e($ret['return_number']) ?>?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action_cancel_return" value="1">
                                            <input type="hidden" name="return_id" value="<?= $ret['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                ✕ Batalkan
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:12px;">Selesai</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modals outside table -->
<?php if (!empty($returns)): ?>
    <?php foreach ($returns as $ret): ?>
        <?php if ($ret['status'] === 'pending_approval'): ?>
            <div id="modal-process-return-<?= $ret['id'] ?>" class="modal-backdrop">
                <div class="modal-dialog modal-sm">
                    <div class="modal-header">
                        <h3 class="modal-title">✓ Persetujuan &amp; Penerbitan CN</h3>
                        <button type="button" class="modal-close" data-modal-close>&times;</button>
                    </div>
                    <form action="index.php?page=receiving_sales/returns" method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action_process_return" value="1">
                        <input type="hidden" name="return_id" value="<?= $ret['id'] ?>">
                        <div class="modal-body">
                            <p style="font-size:13px; margin-bottom:10px;">
                                Proses retur <strong><?= e($ret['return_number']) ?></strong> untuk pelanggan <strong><?= e($ret['customer_name']) ?></strong>:
                            </p>
                            <div style="background:#f8fafc; padding:10px; border-radius:6px; margin-bottom:12px; font-size:12.5px; border:1px solid #e2e8f0;">
                                Barang: <strong><?= e($ret['item_name']) ?></strong><br>
                                Jumlah: <strong><?= formatNumber((float)$ret['qty']) ?> unit</strong><br>
                                Total Nilai CN: <strong style="color:#dc2626;"><?= formatRupiah((float)$ret['total_amount']) ?></strong>
                            </div>
                            <div style="margin-bottom:10px;">
                                <label style="display:flex; align-items:center; gap:8px; font-size:12.5px; cursor:pointer; font-weight:600;">
                                    <input type="checkbox" name="do_restock" value="1" checked style="width:16px; height:16px;">
                                    Kembalikan stok fisik ke gudang
                                </label>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label style="font-size:11px;">Gudang Tujuan Masuk *</label>
                                <select name="warehouse_id" required>
                                    <?php foreach ($warehouses as $wh): ?>
                                        <option value="<?= $wh['id'] ?>"><?= e($wh['name']) ?> (<?= e($wh['code']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Batal</button>
                            <button type="submit" class="btn btn-success btn-sm">
                                Setujui &amp; Terbitkan Nota Kredit
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
