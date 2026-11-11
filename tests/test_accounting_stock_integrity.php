<?php
/**
 * Test Suite: Double-Entry Accounting, Inventory Balances, and Financial Integrity
 */

require_once __DIR__ . '/../app/config/database.php';
$db = getDbConnection();

echo "========================================================\n";
echo "FINANCIAL & INVENTORY INTEGRITY AUDIT TEST\n";
echo "========================================================\n\n";

function check($cond, $msg) {
    if (!$cond) {
        echo "  [FAIL] $msg\n";
        return false;
    }
    echo "  [PASS] $msg\n";
    return true;
}

$allPass = true;

// 1. Journal Entries Balance Check
echo "--- 1. Double-Entry General Journal Balance Check ---\n";
$unbalancedEntries = $db->query("
    SELECT je.id, je.entry_number, je.total_debit, je.total_credit,
           COALESCE(SUM(jel.debit), 0) AS line_debit,
           COALESCE(SUM(jel.credit), 0) AS line_credit
    FROM journal_entries je
    LEFT JOIN journal_entry_lines jel ON je.id = jel.journal_entry_id
    GROUP BY je.id
    HAVING ABS(je.total_debit - je.total_credit) > 0.01 
        OR ABS(line_debit - line_credit) > 0.01
        OR ABS(je.total_debit - line_debit) > 0.01
")->fetchAll(PDO::FETCH_ASSOC);

$allPass = check(empty($unbalancedEntries), "Semua voucher jurnal umum seimbang (Debit = Kredit)") && $allPass;
if (!empty($unbalancedEntries)) {
    foreach ($unbalancedEntries as $ue) {
        echo "    Unbalanced: {$ue['entry_number']} (Dr: {$ue['total_debit']}, Cr: {$ue['total_credit']})\n";
    }
}

// 2. Stock Balances Non-Negative Check
echo "\n--- 2. Stock Balances Non-Negative & Integrity Check ---\n";
$negativeStock = $db->query("
    SELECT sb.*, i.code AS item_code, i.name AS item_name, w.name AS wh_name
    FROM stock_balances sb
    JOIN items i ON sb.item_id = i.id
    JOIN warehouses w ON sb.warehouse_id = w.id
    WHERE sb.qty_available < 0 OR sb.qty_reserved < 0
")->fetchAll(PDO::FETCH_ASSOC);

$allPass = check(empty($negativeStock), "Tidak ada saldo stok fisik atau cadangan bernilai negatif") && $allPass;
if (!empty($negativeStock)) {
    foreach ($negativeStock as $ns) {
        echo "    Negative Stock: {$ns['item_name']} at {$ns['wh_name']} (Avail: {$ns['qty_available']}, Res: {$ns['qty_reserved']})\n";
    }
}

// 3. Stock Movements Audit Trail Integrity
echo "\n--- 3. Stock Movements Audit Trail Check ---\n";
$totalMovements = (int)$db->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();
$orphanMovements = (int)$db->query("
    SELECT COUNT(*) FROM stock_movements sm 
    LEFT JOIN items i ON sm.item_id = i.id 
    LEFT JOIN warehouses w ON sm.warehouse_id = w.id 
    WHERE i.id IS NULL OR w.id IS NULL
")->fetchColumn();

$allPass = check($totalMovements > 0, "Catatan kartu stok mutasi terisi ({$totalMovements} baris)") && $allPass;
$allPass = check($orphanMovements === 0, "Tidak ada mutasi stok tanpa relasi barang/gudang") && $allPass;

// 4. Accounts Payable & Receivable Integrity
echo "\n--- 4. Accounts Payable (AP) & Receivable (AR) Integrity ---\n";
$invWithNegativeBalance = $db->query("
    SELECT COUNT(*) FROM sales_invoices WHERE balance < 0
")->fetchColumn();
$allPass = check((int)$invWithNegativeBalance === 0, "Tidak ada sisa piutang faktur bernilai negatif") && $allPass;

$suppInvWithNegativeBalance = $db->query("
    SELECT COUNT(*) FROM supplier_invoices WHERE balance < 0
")->fetchColumn();
$allPass = check((int)$suppInvWithNegativeBalance === 0, "Tidak ada sisa utang faktur pemasok bernilai negatif") && $allPass;

// 5. Payroll Records Gross/Net Math Check
echo "\n--- 5. Payroll Records Math Check ---\n";
$payrollMath = $db->query("
    SELECT pr.id, pr.employee_id, pr.base_salary, pr.total_earnings, pr.total_deductions, pr.net_salary,
           e.full_name
    FROM payroll_records pr
    JOIN employees e ON pr.employee_id = e.id
    WHERE ABS((pr.total_earnings - pr.total_deductions) - pr.net_salary) > 0.05
")->fetchAll(PDO::FETCH_ASSOC);

$allPass = check(empty($payrollMath), "Perhitungan seluruh rincian slip gaji akurat (Earnings - Deductions = Net)") && $allPass;

echo "\n========================================================\n";
echo "STATUS: " . ($allPass ? "SEMUA AUDIT AKUNTANSI & STOK LULUS 100%!" : "ADA KEGAGALAN AUDIT!") . "\n";
echo "========================================================\n";

if (!$allPass) {
    exit(1);
}
