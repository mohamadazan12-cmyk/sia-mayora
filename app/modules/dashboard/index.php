<?php
/**
 * Multi-Dashboard Dispatcher
 * Sistem Informasi Akuntansi & Operasional PT Mayora Indah Tbk
 */

$user = currentUser();
$roleCode = $user['role_code'] ?? 'admin';

// Map role code to valid dashboard file
$dashboardMap = [
    'admin'      => 'admin',
    'purchasing' => 'purchasing',
    'ppic'       => 'ppic',
    'penjualan'  => 'penjualan',
    'gudang'     => 'gudang',
    'qc'         => 'qc',
    'finance'    => 'finance',
    'hr'         => 'hr',
    'manager'    => 'manager',
];

$target = $dashboardMap[$roleCode] ?? 'admin';
$file = __DIR__ . '/' . $target . '.php';

if (file_exists($file)) {
    include $file;
} else {
    include __DIR__ . '/admin.php';
}
