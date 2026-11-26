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
    'pembelian'  => 'purchasing',   // role_code DB = pembelian
    'ppic'       => 'ppic',
    'produksi'   => 'ppic',         // role_code DB = produksi
    'penjualan'  => 'penjualan',
    'gudang'     => 'gudang',
    'qc'         => 'qc',
    'finance'    => 'finance',
    'hr'         => 'hr',
    'manager'    => 'manager',
    'manajer'    => 'manager',      // role_code DB = manajer
];

$target = $dashboardMap[$roleCode] ?? 'admin';
$file = __DIR__ . '/' . $target . '.php';

if (file_exists($file)) {
    include $file;
} else {
    include __DIR__ . '/admin.php';
}
