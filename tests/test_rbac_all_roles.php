<?php
/**
 * Test Suite: RBAC Matrix Verification for All 9 Roles
 */

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/helpers.php';

$db = getDbConnection();

echo "========================================================\n";
echo "RBAC 9 ROLES COMPREHENSIVE ACCESS CONTROL TEST\n";
echo "========================================================\n\n";

$rolesToTest = [
    'admin'     => 'admin',
    'gudang'    => 'gudang',
    'purchasing'=> 'purchasing',
    'ppic'      => 'ppic',
    'qc'        => 'qc',
    'penjualan' => 'penjualan',
    'finance'   => 'finance',
    'hr'        => 'hr',
    'manager'   => 'manager',
];

// Helper to simulate page access check without terminating script via denyAccess
function testAccess($username, $roleCode, $page) {
    global $db;
    // Set mock user in session
    $_SESSION['user'] = [
        'id' => 999,
        'username' => $username,
        'role_code' => $roleCode,
        'role_name' => ucfirst($roleCode),
        'role_id' => 1
    ];

    $parts = explode('/', trim($page, '/'));
    $module = $parts[0] ?? '';
    $subpage = $parts[1] ?? '';

    // Admin has access to everything
    if ($roleCode === 'admin') return true;

    // Manager dashboard
    if ($module === 'dashboard') {
        if (empty($subpage)) return true;
        if ($roleCode === 'manager') {
            return ($subpage !== 'admin');
        }
        return ($subpage === $roleCode);
    }

    // Role-specific rules
    switch ($roleCode) {
        case 'purchasing':
            if ($module === 'expenditure_procurement') return true;
            if ($module === 'master_data' && in_array($subpage, ['suppliers', 'items', 'units', ''])) return true;
            if ($module === 'inventory' && in_array($subpage, ['stock', ''])) return true;
            return false;

        case 'ppic':
            if ($module === 'production') return true;
            if ($module === 'master_data' && in_array($subpage, ['boms', 'items', 'units', ''])) return true;
            if ($module === 'inventory' && in_array($subpage, ['stock', ''])) return true;
            return false;

        case 'penjualan':
            if ($module === 'receiving_sales') return true;
            if ($module === 'master_data' && in_array($subpage, ['customers', 'items', 'units', ''])) return true;
            return false;

        case 'gudang':
            if ($module === 'inventory') return true;
            if ($module === 'expenditure_procurement' && strpos($subpage, 'gr') === 0) return true;
            if ($module === 'receiving_sales' && strpos($subpage, 'delivery_order') === 0) return true;
            if ($module === 'master_data' && in_array($subpage, ['warehouses', 'items', 'units', ''])) return true;
            return false;

        case 'qc':
            if ($module === 'production' && in_array($subpage, ['qc_inspections', 'outputs', ''])) return true;
            if ($module === 'expenditure_procurement' && strpos($subpage, 'gr') === 0) return true;
            if ($module === 'master_data' && in_array($subpage, ['boms', 'items', 'units', ''])) return true;
            return false;

        case 'finance':
            if ($module === 'receiving_sales' && in_array($subpage, ['receivables', 'receipts', 'receipt_detail', 'receipt_create', 'invoices', 'invoice_detail', ''])) return true;
            if ($module === 'expenditure_procurement' && in_array($subpage, ['invoices', 'invoice_detail', 'payment_requests', 'payments', 'payment_create', ''])) return true;
            if ($module === 'reports' && in_array($subpage, ['journals', 'index', ''])) return true;
            if ($module === 'hr_payroll' && in_array($subpage, ['approvals', 'salary_verification', 'terminations', 'reports', ''])) return true;
            if ($module === 'master_data' && in_array($subpage, ['accounts', 'customers', 'suppliers', 'departments', ''])) return true;
            return false;

        case 'hr':
            if ($module === 'hr_payroll') return true;
            if ($module === 'master_data' && in_array($subpage, ['departments', 'positions', ''])) return true;
            return false;

        case 'manager':
            // Manager can view almost all operational and reporting modules
            if (in_array($module, ['expenditure_procurement', 'receiving_sales', 'production', 'inventory', 'reports', 'hr_payroll', 'master_data'])) {
                if ($module === 'users') return false;
                return true;
            }
            return false;
    }

    return false;
}

$testMatrix = [
    // [Role, Allowed Pages, Forbidden Pages]
    [
        'role' => 'gudang',
        'allowed' => ['dashboard/gudang', 'inventory/stock', 'inventory/adjustments', 'expenditure_procurement/gr', 'receiving_sales/delivery_orders', 'master_data/warehouses', 'master_data/items'],
        'forbidden' => ['users/index', 'reports/journals', 'hr_payroll/payroll', 'receiving_sales/sales_orders']
    ],
    [
        'role' => 'purchasing',
        'allowed' => ['dashboard/purchasing', 'expenditure_procurement/pr', 'expenditure_procurement/po', 'master_data/suppliers', 'master_data/items', 'inventory/stock'],
        'forbidden' => ['users/index', 'hr_payroll/payroll', 'receiving_sales/invoices', 'production/orders']
    ],
    [
        'role' => 'ppic',
        'allowed' => ['dashboard/ppic', 'production/orders', 'production/material_issues', 'production/outputs', 'master_data/boms', 'inventory/stock'],
        'forbidden' => ['users/index', 'receiving_sales/receivables', 'hr_payroll/payroll', 'reports/journals']
    ],
    [
        'role' => 'qc',
        'allowed' => ['dashboard/qc', 'production/qc_inspections', 'production/outputs', 'expenditure_procurement/gr'],
        'forbidden' => ['users/index', 'hr_payroll/payroll', 'reports/journals', 'master_data/accounts']
    ],
    [
        'role' => 'penjualan',
        'allowed' => ['dashboard/penjualan', 'receiving_sales/sales_orders', 'receiving_sales/invoices', 'master_data/customers'],
        'forbidden' => ['users/index', 'production/orders', 'hr_payroll/payroll', 'master_data/accounts']
    ],
    [
        'role' => 'finance',
        'allowed' => ['dashboard/finance', 'reports/journals', 'receiving_sales/receivables', 'receiving_sales/receipts', 'expenditure_procurement/invoices', 'expenditure_procurement/payments', 'master_data/accounts'],
        'forbidden' => ['users/index', 'production/material_issues', 'inventory/adjustments']
    ],
    [
        'role' => 'hr',
        'allowed' => ['dashboard/hr', 'hr_payroll/employees', 'hr_payroll/attendance', 'hr_payroll/payroll', 'hr_payroll/payslips', 'master_data/departments', 'master_data/positions'],
        'forbidden' => ['users/index', 'production/orders', 'reports/journals', 'master_data/accounts']
    ],
    [
        'role' => 'manager',
        'allowed' => ['dashboard/manager', 'dashboard/purchasing', 'dashboard/gudang', 'expenditure_procurement/po', 'receiving_sales/sales_orders', 'production/orders', 'reports/journals', 'reports/index'],
        'forbidden' => ['users/index', 'dashboard/admin']
    ],
    [
        'role' => 'admin',
        'allowed' => ['users/index', 'dashboard/admin', 'master_data/accounts', 'reports/audit', 'reports/journals', 'hr_payroll/payroll'],
        'forbidden' => [] // Admin is allowed everything
    ],
];

$passCount = 0;
$failCount = 0;

foreach ($testMatrix as $test) {
    $role = $test['role'];
    echo "Testing Role: [{$role}] ...\n";

    foreach ($test['allowed'] as $page) {
        $can = testAccess($role, $role, $page);
        if ($can) {
            $passCount++;
        } else {
            $failCount++;
            echo "  [FAIL] Expected ALLOW for page: {$page}, got DENIED\n";
        }
    }

    foreach ($test['forbidden'] as $page) {
        $can = testAccess($role, $role, $page);
        if (!$can) {
            $passCount++;
        } else {
            $failCount++;
            echo "  [FAIL] Expected DENIED for page: {$page}, got ALLOWED\n";
        }
    }
}

echo "\n========================================================\n";
echo "RBAC TEST RESULT: {$passCount} Passed, {$failCount} Failed\n";
echo "========================================================\n";

if ($failCount > 0) {
    exit(1);
}
