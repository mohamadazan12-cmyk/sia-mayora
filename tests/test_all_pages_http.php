<?php
$port = 8000;
$baseUrl = "http://localhost:{$port}";
$cookieFile = __DIR__ . '/test_cookie.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

// 1. GET login page and extract CSRF token
$ch = curl_init($baseUrl . '/index.php?page=login');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$html = curl_exec($ch);
curl_close($ch);

preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $matches);
$token = $matches[1] ?? '';

// 2. Login as admin
$ch = curl_init($baseUrl . '/index.php?page=login');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'csrf_token' => $token,
    'username'   => 'admin',
    'password'   => 'admin123',
]));
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$res = curl_exec($ch);
curl_close($ch);

// 3. Test list of pages
$pages = [
    'dashboard',
    'receiving_sales/sales_orders',
    'receiving_sales/delivery_orders',
    'receiving_sales/invoices',
    'receiving_sales/receivables',
    'receiving_sales/receipts',
    'receiving_sales/returns',
    'production/orders',
    'production/material_issues',
    'production/machine_logs',
    'production/qc_inspections',
    'production/outputs',
    'production/hpp_summary',
    'expenditure_procurement/pr',
    'expenditure_procurement/po',
    'expenditure_procurement/gr',
    'expenditure_procurement/invoices',
    'expenditure_procurement/payment_requests',
    'expenditure_procurement/payments',
    'hr_payroll/employees',
    'hr_payroll/attendance',
    'hr_payroll/leave_overtime',
    'hr_payroll/payroll',
    'hr_payroll/approvals',
    'hr_payroll/payslips',
    'master_data/items',
    'master_data/customers',
    'master_data/suppliers',
    'master_data/warehouses',
    'master_data/boms',
    'master_data/accounts',
    'master_data/departments',
    'master_data/positions',
    'master_data/units',
    'inventory/stock',
    'inventory/adjustments',
    'reports/index',
    'reports/journals',
    'reports/audit',
    'users/index',
];

$passed = 0;
$failed = 0;

foreach ($pages as $p) {
    $ch = curl_init($baseUrl . '/index.php?page=' . $p);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && strpos($body, 'Koneksi ke basis data gagal') === false && strpos($body, 'Fatal error') === false) {
        $passed++;
        echo "[OK 200] $p\n";
    } else {
        $failed++;
        echo "[FAIL $code] $p\n";
    }
}

echo "\nSummary: $passed passed, $failed failed out of " . count($pages) . " pages.\n";

if (file_exists($cookieFile)) unlink($cookieFile);
