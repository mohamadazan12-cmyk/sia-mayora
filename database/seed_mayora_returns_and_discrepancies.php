<?php
/**
 * Seeder Retur Pemasok (Supplier Returns) & Nota Debit (Debit Memos)
 * PT Mayora Indah Tbk.
 * Menghasilkan data simulasi retur barang cacat QC incoming dan nota debit pemotong utang usaha,
 * lengkap dengan pencatatan jurnal umum penyeimbang.
 */

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/journal_service.php';
require_once __DIR__ . '/../app/services/notification_service.php';

$pdo = getDbConnection();

echo "Starting Supplier Returns & Debit Memos Seeder for Mayora...\n";

// Check users
$userPurchasing = (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON u.role_id = r.id WHERE r.role_code IN ('purchasing', 'pembelian') LIMIT 1")->fetchColumn() ?: 1;
$userFinance    = (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON u.role_id = r.id WHERE r.role_code = 'finance' LIMIT 1")->fetchColumn() ?: 1;
$userQC         = (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON u.role_id = r.id WHERE r.role_code = 'qc' LIMIT 1")->fetchColumn() ?: 1;

// Accounts for Journal
$accAP  = JournalService::getAccountIdByCode('2100', $pdo) ?: 11; // Utang Usaha
$accRaw = JournalService::getAccountIdByCode('1310', $pdo) ?: 7;  // Persediaan Bahan Baku

// Pick 6 distinct Goods Receipts across the months
$sampleGRs = $pdo->query("
    SELECT gr.id as gr_id, gr.gr_number, gr.supplier_id, gr.receipt_date, s.name as supplier_name,
           gri.id as gri_id, gri.item_id, gri.qty_received, gri.batch_number, i.name as item_name, i.cost_price,
           si.id as invoice_id, si.invoice_number, si.total_amount, si.balance
    FROM goods_receipts gr
    JOIN suppliers s ON gr.supplier_id = s.id
    JOIN goods_receipt_items gri ON gr.id = gri.goods_receipt_id
    JOIN items i ON gri.item_id = i.id
    LEFT JOIN supplier_invoices si ON si.goods_receipt_id = gr.id
    WHERE gr.status = 'accepted' AND gri.qty_received >= 200
    GROUP BY gr.id
    ORDER BY gr.id ASC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$reasons = [
    'Kadar air melebihi batas toleransi 14.5% pada uji oven laboratorium',
    'Kemasan sak karung sobek pada 2 pallet saat unloading kontainer',
    'Hasil uji organoleptik aroma apek tidak memenuhi standar resep F&B',
    'Segel foil kaleng bocor mikro saat uji tekanan vakum QC incoming',
    'Kontaminasi fisik serpihan karton pada lot pengiriman vendor',
    'Ketidaksesuaian granulasi gula rafinasi (butiran terlalu kasar)'
];

$pdo->beginTransaction();
try {
    $stmtReturn = $pdo->prepare("
        INSERT INTO supplier_returns 
        (return_number, goods_receipt_id, supplier_id, return_date, reason, status, created_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtReturnItem = $pdo->prepare("
        INSERT INTO supplier_return_items (supplier_return_id, item_id, qty, notes)
        VALUES (?, ?, ?, ?)
    ");
    $stmtUpdGri = $pdo->prepare("
        UPDATE goods_receipt_items 
        SET qty_rejected = ?, qty_accepted = qty_received - ?, notes = CONCAT_WS(' | ', notes, ?)
        WHERE id = ?
    ");
    $stmtDebitMemo = $pdo->prepare("
        INSERT INTO debit_memos 
        (memo_number, supplier_id, supplier_invoice_id, memo_date, amount, reason, status, approved_by, approved_at, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'approved', ?, ?, ?, ?)
    ");

    $retCount = 0;
    foreach ($sampleGRs as $idx => $gr) {
        $retCount++;
        $retDate = date('Y-m-d', strtotime($gr['receipt_date'] . ' +1 days'));
        $retNum  = sprintf('RET-%s-%04d', date('ym', strtotime($retDate)), $retCount);
        $reason  = $reasons[$idx % count($reasons)];
        
        // Reject 10% to 20% of quantity
        $qtyReject = round($gr['qty_received'] * (0.05 + ($idx * 0.02)), 0);
        $costPerUnit = (float)$gr['cost_price'];
        if ($costPerUnit <= 0) $costPerUnit = 25000;
        $totalDebitAmount = round($qtyReject * $costPerUnit, 0);

        // 1. Update goods_receipt_items rejected
        $stmtUpdGri->execute([$qtyReject, $qtyReject, "Retur {$qtyReject} unit: {$reason}", $gr['gri_id']]);

        // 2. Insert supplier_returns
        $stmtReturn->execute([
            $retNum, $gr['gr_id'], $gr['supplier_id'], $retDate, $reason, 'approved', $retDate . ' 14:00:00', $userPurchasing
        ]);
        $returnId = (int)$pdo->lastInsertId();

        // 3. Insert supplier_return_items
        $stmtReturnItem->execute([
            $returnId, $gr['item_id'], $qtyReject, "Lot: {$gr['batch_number']} - {$reason}"
        ]);

        // 4. Insert debit_memos
        $dmNum = sprintf('DM-%s-%04d', date('ym', strtotime($retDate)), $retCount);
        $invId = !empty($gr['invoice_id']) ? (int)$gr['invoice_id'] : null;

        $stmtDebitMemo->execute([
            $dmNum, $gr['supplier_id'], $invId, $retDate, $totalDebitAmount, 
            "Nota Debit atas retur barang {$retNum} ({$gr['item_name']})", 
            $userFinance, $retDate . ' 16:00:00', $userFinance, $retDate . ' 15:30:00'
        ]);
        $dmId = (int)$pdo->lastInsertId();

        // 5. If linked to an invoice, reduce invoice balance
        if ($invId) {
            $pdo->prepare("UPDATE supplier_invoices SET balance = GREATEST(0, balance - ?), updated_at = NOW() WHERE id = ?")
                ->execute([$totalDebitAmount, $invId]);
        }

        // 6. Accounting Journal: Debit Utang Usaha (2100), Kredit Persediaan Bahan Baku (1310)
        $jLines = [
            ['account_id' => $accAP,  'description' => "Pengurangan Utang Usaha {$gr['supplier_name']} (Nota Debit {$dmNum})", 'debit' => $totalDebitAmount, 'credit' => 0],
            ['account_id' => $accRaw, 'description' => "Pengurangan Nilai Persediaan atas Retur Barang ({$dmNum})", 'debit' => 0, 'credit' => $totalDebitAmount]
        ];

        JournalService::createEntry(
            $retDate,
            'debit_memo',
            $dmId,
            $dmNum,
            "Penerbitan Nota Debit {$dmNum} Retur {$retNum} ke {$gr['supplier_name']}",
            $jLines,
            $userFinance,
            true,
            $pdo
        );

        // 7. Notification
        NotificationService::create([
            'role_code' => 'purchasing',
            'title'     => "Retur Diproses: $retNum",
            'message'   => "Retur $retNum atas vendor {$gr['supplier_name']} telah disetujui & Nota Debit $dmNum senilai Rp " . number_format($totalDebitAmount, 0, ',', '.') . " diterbitkan.",
            'type'      => 'info',
            'category'  => 'procurement',
            'link'      => 'index.php?page=expenditure_procurement/returns',
            'ref_type'  => 'debit_memos',
            'ref_id'    => $dmId
        ]);

        echo "Seeded: Return {$retNum} & Debit Memo {$dmNum} (Rp " . number_format($totalDebitAmount, 0, ',', '.') . ") for {$gr['supplier_name']}\n";
    }

    $pdo->commit();
    echo "Successfully seeded " . count($sampleGRs) . " returns and debit memos with balanced journals!\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
