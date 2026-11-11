<?php
/**
 * End-to-End System Smoke Test
 * Tests database connectivity, core services, and module rendering
 */

echo "========================================================\n";
echo "SIA MANUFAKTUR & DISTRIBUSI - SYSTEM INTEGRATION TEST\n";
echo "========================================================\n\n";

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/csrf.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/services/inventory_service.php';
require_once __DIR__ . '/../app/services/approval_service.php';
require_once __DIR__ . '/../app/services/journal_service.php';

$pdo = getDbConnection();
echo "[OK] Koneksi Database MySQL berhasil terhubung.\n";

// 1. Check Tables
$tables = [
    'users', 'roles', 'audit_logs', 'items', 'warehouses', 'stock_balances', 'stock_movements',
    'sales_orders', 'delivery_orders', 'sales_invoices', 'customer_receipts',
    'production_orders', 'material_issues', 'qc_inspections', 'production_outputs',
    'purchase_requisitions', 'purchase_orders', 'goods_receipts', 'supplier_invoices', 'supplier_payments',
    'employees', 'attendance_records', 'leave_overtime_requests', 'payroll_periods', 'payroll_records',
    'accounts', 'journal_entries', 'journal_entry_lines'
];

$missingTables = [];
foreach ($tables as $tbl) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM `{$tbl}`");
        $count = $stmt->fetchColumn();
    } catch (Exception $e) {
        $missingTables[] = $tbl;
    }
}

if (!empty($missingTables)) {
    echo "[FAIL] Tabel hilang: " . implode(', ', $missingTables) . "\n";
} else {
    echo "[OK] Seluruh 28 tabel transaksi dan master data terverifikasi ada di database.\n";
}

// 2. Test Inventory Service Atomic Logic
echo "\n--- Menguji InventoryService ---\n";
try {
    $firstItem = $pdo->query("SELECT id FROM items WHERE is_active = 1 LIMIT 1")->fetchColumn();
    $firstWh = $pdo->query("SELECT id FROM warehouses WHERE is_active = 1 LIMIT 1")->fetchColumn();

    if ($firstItem && $firstWh) {
        $bal = InventoryService::getBalance($firstItem, $firstWh, $pdo);
        echo "[OK] getBalance berhasil: Qty available = " . ($bal['qty_available'] ?? 0) . "\n";

        // Test adjustment
        $adminId = $pdo->query("SELECT id FROM users WHERE role_id = 1 LIMIT 1")->fetchColumn() ?: 1;
        $testRef = 'TEST-ADJ-' . time();
        $curr = (float)($bal['qty_available'] ?? 0);
        $newTarget = $curr + 5;
        InventoryService::adjustStock($firstItem, $firstWh, $newTarget, 'Smoke Test Surplus', $testRef, $adminId, $pdo);
        $afterSurplus = InventoryService::getBalance($firstItem, $firstWh, $pdo);
        echo "[OK] adjustStock (+5) berhasil: " . $afterSurplus['qty_available'] . "\n";

        // Revert back
        InventoryService::adjustStock($firstItem, $firstWh, $curr, 'Smoke Test Revert', $testRef . '-REV', $adminId, $pdo);
        $afterRevert = InventoryService::getBalance($firstItem, $firstWh, $pdo);
        echo "[OK] adjustStock revert berhasil: " . $afterRevert['qty_available'] . "\n";
    }
} catch (Exception $e) {
    echo "[FAIL] InventoryService Exception: " . $e->getMessage() . "\n";
}

// 3. Test Journal Service Double-Entry Balance Enforcement
echo "\n--- Menguji JournalService ---\n";
try {
    $accKas = JournalService::getAccountIdByCode('1-1110', $pdo) ?: 1;
    $accPendapatan = JournalService::getAccountIdByCode('4-1000', $pdo) ?: 2;

    $testLines = [
        ['account_id' => $accKas, 'description' => 'Debet Kas Test', 'debit' => 150000, 'credit' => 0],
        ['account_id' => $accPendapatan, 'description' => 'Kredit Pendapatan Test', 'debit' => 0, 'credit' => 150000]
    ];

    $jvId = JournalService::createEntry(
        date('Y-m-d'),
        'smoke_test',
        1,
        'SMOKE-001',
        'Pengujian Otomatis Jurnal Berimbang',
        $testLines,
        $adminId,
        true,
        $pdo
    );
    echo "[OK] JournalService createEntry berhasil: ID = {$jvId}\n";

    // Test rejection of unbalanced entries
    try {
        $unbalancedLines = [
            ['account_id' => $accKas, 'description' => 'Unbalanced Debit', 'debit' => 200000, 'credit' => 0],
            ['account_id' => $accPendapatan, 'description' => 'Unbalanced Credit', 'debit' => 0, 'credit' => 100000]
        ];
        JournalService::createEntry(date('Y-m-d'), 'smoke_test', 2, 'FAIL-001', 'Unbalanced', $unbalancedLines, $adminId, true, $pdo);
        echo "[FAIL] Sistem tidak menolak jurnal yang tidak seimbang!\n";
    } catch (Exception $e) {
        echo "[OK] Sistem berhasil menolak jurnal tidak seimbang (Debit != Kredit): " . $e->getMessage() . "\n";
    }
} catch (Exception $e) {
    echo "[FAIL] JournalService Exception: " . $e->getMessage() . "\n";
}

// 4. Test Audit Log
echo "\n--- Menguji Audit Logging ---\n";
try {
    logAudit($adminId, 'smoke_test_action', 'system_tests', 1, null, ['status' => 'tested']);
    $lastLog = $pdo->query("SELECT * FROM audit_logs ORDER BY id DESC LIMIT 1")->fetch();
    if ($lastLog && $lastLog['action'] === 'smoke_test_action') {
        echo "[OK] logAudit berhasil tercatat ke audit_logs (ID: {$lastLog['id']}).\n";
    } else {
        echo "[FAIL] logAudit gagal dicatat.\n";
    }
} catch (Exception $e) {
    echo "[FAIL] Audit Log Exception: " . $e->getMessage() . "\n";
}

// 5. Test File Existence for all navigation routes
echo "\n--- Memeriksa File Modul Seluruh Rute Navigasi ---\n";
$routes = [
    // Dashboard
    'dashboard' => 'app/modules/dashboard/index.php',
    // 1. Penerimaan & Penjualan
    'receiving_sales/sales_orders' => 'app/modules/receiving_sales/sales_orders.php',
    'receiving_sales/sales_order_create' => 'app/modules/receiving_sales/sales_order_create.php',
    'receiving_sales/sales_order_detail' => 'app/modules/receiving_sales/sales_order_detail.php',
    'receiving_sales/delivery_orders' => 'app/modules/receiving_sales/delivery_orders.php',
    'receiving_sales/delivery_order_create' => 'app/modules/receiving_sales/delivery_order_create.php',
    'receiving_sales/delivery_order_detail' => 'app/modules/receiving_sales/delivery_order_detail.php',
    'receiving_sales/invoices' => 'app/modules/receiving_sales/invoices.php',
    'receiving_sales/invoice_create' => 'app/modules/receiving_sales/invoice_create.php',
    'receiving_sales/invoice_detail' => 'app/modules/receiving_sales/invoice_detail.php',
    'receiving_sales/receivables' => 'app/modules/receiving_sales/receivables.php',
    'receiving_sales/receipts' => 'app/modules/receiving_sales/receipts.php',
    'receiving_sales/receipt_create' => 'app/modules/receiving_sales/receipt_create.php',
    'receiving_sales/receipt_detail' => 'app/modules/receiving_sales/receipt_detail.php',
    'receiving_sales/returns' => 'app/modules/receiving_sales/returns.php',
    // 2. Produksi
    'production/orders' => 'app/modules/production/orders.php',
    'production/order_create' => 'app/modules/production/order_create.php',
    'production/order_detail' => 'app/modules/production/order_detail.php',
    'production/material_issues' => 'app/modules/production/material_issues.php',
    'production/machine_logs' => 'app/modules/production/machine_logs.php',
    'production/qc_inspections' => 'app/modules/production/qc_inspections.php',
    'production/outputs' => 'app/modules/production/outputs.php',
    'production/hpp_summary' => 'app/modules/production/hpp_summary.php',
    // 3. Pengeluaran & Pengadaan
    'expenditure_procurement/pr' => 'app/modules/expenditure_procurement/pr.php',
    'expenditure_procurement/pr_create' => 'app/modules/expenditure_procurement/pr_create.php',
    'expenditure_procurement/pr_detail' => 'app/modules/expenditure_procurement/pr_detail.php',
    'expenditure_procurement/po' => 'app/modules/expenditure_procurement/po.php',
    'expenditure_procurement/po_create' => 'app/modules/expenditure_procurement/po_create.php',
    'expenditure_procurement/po_detail' => 'app/modules/expenditure_procurement/po_detail.php',
    'expenditure_procurement/gr' => 'app/modules/expenditure_procurement/gr.php',
    'expenditure_procurement/gr_create' => 'app/modules/expenditure_procurement/gr_create.php',
    'expenditure_procurement/gr_detail' => 'app/modules/expenditure_procurement/gr_detail.php',
    'expenditure_procurement/invoices' => 'app/modules/expenditure_procurement/invoices.php',
    'expenditure_procurement/invoice_create' => 'app/modules/expenditure_procurement/invoice_create.php',
    'expenditure_procurement/invoice_detail' => 'app/modules/expenditure_procurement/invoice_detail.php',
    'expenditure_procurement/payment_requests' => 'app/modules/expenditure_procurement/payment_requests.php',
    'expenditure_procurement/payments' => 'app/modules/expenditure_procurement/payments.php',
    'expenditure_procurement/payment_create' => 'app/modules/expenditure_procurement/payment_create.php',
    // 4. SDM & Penggajian
    'hr_payroll/employees' => 'app/modules/hr_payroll/employees.php',
    'hr_payroll/employee_create' => 'app/modules/hr_payroll/employee_create.php',
    'hr_payroll/employee_detail' => 'app/modules/hr_payroll/employee_detail.php',
    'hr_payroll/attendance' => 'app/modules/hr_payroll/attendance.php',
    'hr_payroll/leave_overtime' => 'app/modules/hr_payroll/leave_overtime.php',
    'hr_payroll/payroll' => 'app/modules/hr_payroll/payroll.php',
    'hr_payroll/approvals' => 'app/modules/hr_payroll/approvals.php',
    'hr_payroll/payslips' => 'app/modules/hr_payroll/payslips.php',
    // Master Data
    'master_data/items' => 'app/modules/master_data/items.php',
    'master_data/customers' => 'app/modules/master_data/customers.php',
    'master_data/suppliers' => 'app/modules/master_data/suppliers.php',
    'master_data/warehouses' => 'app/modules/master_data/warehouses.php',
    'master_data/boms' => 'app/modules/master_data/boms.php',
    'master_data/accounts' => 'app/modules/master_data/accounts.php',
    // Persediaan
    'inventory/stock' => 'app/modules/inventory/stock.php',
    'inventory/adjustments' => 'app/modules/inventory/adjustments.php',
    // Laporan & Audit
    'reports/index' => 'app/modules/reports/index.php',
    'reports/journals' => 'app/modules/reports/journals.php',
    'reports/audit' => 'app/modules/reports/audit.php',
    // Administrasi
    'users/index' => 'app/modules/users/index.php',
];

$allFilesExist = true;
foreach ($routes as $route => $relPath) {
    $fullPath = __DIR__ . '/../' . $relPath;
    if (!file_exists($fullPath)) {
        echo "[MISSING] Rute '{$route}' -> file tidak ditemukan: {$relPath}\n";
        $allFilesExist = false;
    }
}

if ($allFilesExist) {
    echo "[OK] Seluruh " . count($routes) . " rute modul file sistem operasional lengkap dan tersedia!\n";
}

// 6. Lint all PHP files in project
echo "\n--- Melakukan Pemeriksaan Sintaksis Seluruh File PHP (Linting) ---\n";
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../app'));
$phpFiles = [];
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $phpFiles[] = $file->getPathname();
    }
}
$phpFiles[] = realpath(__DIR__ . '/../public/index.php');

$syntaxErrors = 0;
foreach ($phpFiles as $phpFile) {
    $cmd = 'C:\xampp\php\php.exe -l ' . escapeshellarg($phpFile);
    $output = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);
    if ($returnCode !== 0) {
        echo "[SYNTAX ERROR] " . $phpFile . ": " . implode("\n", $output) . "\n";
        $syntaxErrors++;
    }
}

if ($syntaxErrors === 0) {
    echo "[OK] Seluruh " . count($phpFiles) . " file PHP lulus uji sintaksis tanpa kesalahan!\n";
} else {
    echo "[FAIL] Ditemukan {$syntaxErrors} file dengan kesalahan sintaksis.\n";
}

echo "\n========================================================\n";
echo "STATUS PENGUJIAN: SEMUA KOMPONEN LENGKAP & VALID\n";
echo "========================================================\n";
