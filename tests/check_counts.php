<?php
require_once __DIR__ . '/../app/config/database.php';
$pdo = getDbConnection();

$tables = [
    'purchase_requisitions', 'purchase_orders', 'goods_receipts', 'supplier_invoices',
    'three_way_matches', 'ap_vouchers', 'payment_requests', 'supplier_payments',
    'production_orders', 'material_issues', 'machine_logs', 'qc_inspections',
    'production_outputs', 'scrap_records', 'production_costs', 'boms',
    'sales_orders', 'delivery_orders', 'sales_invoices', 'customer_receipts',
    'journal_entries', 'stock_movements', 'stock_balances'
];

foreach ($tables as $t) {
    try {
        $c = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo sprintf("%-30s: %d rows\n", $t, $c);
    } catch (Exception $e) {
        echo sprintf("%-30s: ERROR (%s)\n", $t, $e->getMessage());
    }
}
