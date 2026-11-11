<?php
require_once __DIR__ . '/../app/config/database.php';
$pdo = getDbConnection();

$tables = [
    'suppliers', 'customers', 'items', 'warehouses', 'boms', 'accounts',
    'purchase_requisitions', 'purchase_orders', 'goods_receipts', 'supplier_invoices', 'payment_requests', 'supplier_payments',
    'sales_orders', 'delivery_orders', 'sales_invoices', 'customer_receipts', 'customer_returns',
    'production_orders', 'material_issues', 'qc_inspections', 'production_outputs', 'machine_logs', 'scrap_records',
    'stock_balances', 'stock_movements',
    'employees', 'attendance_records', 'leave_overtime_requests', 'payroll_periods', 'payroll_records',
    'journal_entries', 'users'
];

foreach ($tables as $t) {
    $cols = $pdo->query("DESCRIBE `{$t}`")->fetchAll(PDO::FETCH_ASSOC);
    $statusCol = null;
    $activeCol = null;
    $colNames = [];
    foreach ($cols as $c) {
        $colNames[] = $c['Field'];
        if ($c['Field'] === 'status') $statusCol = $c['Type'];
        if ($c['Field'] === 'is_active') $activeCol = $c['Type'];
    }
    echo "=== Table: {$t} ===\n";
    echo "  status: " . ($statusCol ?: 'none') . "\n";
    echo "  is_active: " . ($activeCol ?: 'none') . "\n";
    echo "  cols: " . implode(', ', $colNames) . "\n";
}
