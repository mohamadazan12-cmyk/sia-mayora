<?php
/**
 * Test Multi-Dashboard by Role and RBAC Access Security
 * PT Mayora Indah Tbk
 */

$baseUrl = 'http://localhost:8000';
$cookieFile = __DIR__ . '/test_role_cookie.txt';

function loginAndGetRedirect($username, $password) {
    global $baseUrl, $cookieFile;
    if (file_exists($cookieFile)) unlink($cookieFile);

    // 1. GET login page for CSRF token
    $ch = curl_init($baseUrl . '/index.php?page=login');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    $html = curl_exec($ch);
    curl_close($ch);

    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m);
    $csrf = $m[1] ?? '';

    // 2. POST login
    $ch = curl_init($baseUrl . '/index.php?page=login');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'csrf_token' => $csrf,
        'username'   => $username,
        'password'   => $password
    ]));
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    $res = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headers = substr($res, 0, $headerSize);
    curl_close($ch);

    preg_match('/Location:\s*([^\r\n]+)/i', $headers, $loc);
    $redirectUrl = trim($loc[1] ?? '');

    return [
        'code'     => $httpCode,
        'redirect' => $redirectUrl
    ];
}

function requestPage($page) {
    global $baseUrl, $cookieFile;
    $url = $baseUrl . '/index.php?page=' . urlencode($page);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $code,
        'body' => $body
    ];
}

echo "========================================================\n";
echo "PENGUJIAN SISTEM MULTI-DASHBOARD & RBAC PT MAYORA INDAH\n";
echo "========================================================\n\n";

$rolesToTest = [
    'admin' => [
        'user'          => 'admin',
        'pass'          => 'admin123',
        'expected_dest' => 'index.php?page=dashboard/admin',
        'title'         => 'Dashboard Utama Perusahaan',
        'sidebar_check' => '1. PENERIMAAN'
    ],
    'purchasing' => [
        'user'          => 'purchasing',
        'pass'          => 'password',
        'expected_dest' => 'index.php?page=dashboard/purchasing',
        'title'         => 'Dashboard Staff Pembelian',
        'sidebar_check' => 'Master Vendor'
    ],
    'ppic' => [
        'user'          => 'ppic',
        'pass'          => 'password',
        'expected_dest' => 'index.php?page=dashboard/ppic',
        'title'         => 'Dashboard Produksi & PPIC',
        'sidebar_check' => 'PRODUKSI & PPIC'
    ],
    'penjualan' => [
        'user'          => 'penjualan',
        'pass'          => 'password',
        'expected_dest' => 'index.php?page=dashboard/penjualan',
        'title'         => 'Dashboard Penerimaan & Penjualan',
        'sidebar_check' => 'PENERIMAAN & PENJUALAN'
    ],
    'gudang' => [
        'user'          => 'gudang',
        'pass'          => 'password',
        'expected_dest' => 'index.php?page=dashboard/gudang',
        'title'         => 'Dashboard Gudang & Persediaan',
        'sidebar_check' => 'GUDANG & PERSEDIAAN'
    ],
    'qc' => [
        'user'          => 'qc',
        'pass'          => 'password',
        'expected_dest' => 'index.php?page=dashboard/qc',
        'title'         => 'Dashboard Quality Control',
        'sidebar_check' => 'PENGENDALIAN MUTU (QC)'
    ],
    'finance' => [
        'user'          => 'finance',
        'pass'          => 'password',
        'expected_dest' => 'index.php?page=dashboard/finance',
        'title'         => 'Dashboard Keuangan & Akuntansi',
        'sidebar_check' => 'KEUANGAN & AKUNTANSI'
    ],
    'hr' => [
        'user'          => 'hr',
        'pass'          => 'password',
        'expected_dest' => 'index.php?page=dashboard/hr',
        'title'         => 'Dashboard SDM & Penggajian',
        'sidebar_check' => 'SDM & PENGGAJIAN'
    ],
    'manager' => [
        'user'          => 'manager',
        'pass'          => 'password',
        'expected_dest' => 'index.php?page=dashboard/manager',
        'title'         => 'Dashboard Manajemen Operasional',
        'sidebar_check' => 'OTORISASI SDM'
    ]
];

// BAGIAN 1: PENGUJIAN LOGIN & DASHBOARD MASING-MASING ROLE
echo "--- BAGIAN 1: UJI LOGIN, REDIRECT & DASHBOARD SEMUA 9 ROLE ---\n";
foreach ($rolesToTest as $roleKey => $cfg) {
    echo "Menguji Role: [{$roleKey}] (User: {$cfg['user']})...\n";
    $login = loginAndGetRedirect($cfg['user'], $cfg['pass']);

    if ($login['code'] === 302 && strpos($login['redirect'], $cfg['expected_dest']) !== false) {
        echo "  -> [OK] Redirect login sesuai ke: {$login['redirect']}\n";
    } else {
        echo "  -> [GAGAL] Redirect tidak sesuai! Code: {$login['code']}, Dest: {$login['redirect']}\n";
        exit(1);
    }

    // Access specific dashboard
    $res = requestPage('dashboard/' . $roleKey);
    if ($res['code'] === 200 && strpos($res['body'], $cfg['title']) !== false) {
        echo "  -> [OK] Tampilan dashboard '{$cfg['title']}' termuat dengan benar (HTTP 200).\n";
    } else {
        echo "  -> [GAGAL] Konten dashboard tidak sesuai! HTTP: {$res['code']}\n";
        exit(1);
    }

    // Access generic 'dashboard' (should auto dispatch to this role's dashboard)
    $genRes = requestPage('dashboard');
    if ($genRes['code'] === 200 && strpos($genRes['body'], $cfg['title']) !== false) {
        echo "  -> [OK] Dispatcher 'index.php?page=dashboard' otomatis menampilkan dashboard {$roleKey}.\n";
    } else {
        echo "  -> [GAGAL] Dispatcher gagal mengarahkan ke dashboard role!\n";
        exit(1);
    }

    // Check role-specific sidebar
    if (strpos($res['body'], $cfg['sidebar_check']) !== false) {
        echo "  -> [OK] Sidebar role terverifikasi memuat: '{$cfg['sidebar_check']}'.\n";
    } else {
        echo "  -> [GAGAL] Sidebar tidak sesuai untuk role {$roleKey}!\n";
        exit(1);
    }
    echo "\n";
}

// BAGIAN 2: PENGUJIAN DETAIL DASHBOARD PURCHASING SESUAI DESAIN FINAL HTML
echo "--- BAGIAN 2: UJI DETAIL DASHBOARD PURCHASING (DESAIN FINAL HTML) ---\n";
loginAndGetRedirect('purchasing', 'password');
$purchasingPage = requestPage('dashboard/purchasing');

$purchasingChecks = [
    'Logo MAYORA'               => 'MAYORA',
    'Title Staff Pembelian'     => 'Dashboard Staff Pembelian',
    'KPI 1 (PR Menunggu RFQ)'   => 'PR Menunggu RFQ',
    'KPI 2 (PO Otorisasi)'      => 'PO Menunggu Otorisasi',
    'KPI 3 (PO Terbuka)'        => 'PO Terbuka',
    'KPI 4 (Retur Berjalan)'    => 'Retur Berjalan',
    'Tabel PR'                  => 'Tabel Permintaan Pembelian (PR)',
    'Informasi Vendor Utama'    => 'Informasi Vendor Utama',
    'Pesanan Pembelian Terbaru' => 'Pesanan Pembelian Terbaru',
    'Breadcrumbs'               => 'Staff Pembelian',
    'Avatar Inisial User'       => 'SR', // Siti Rahmawati
];

foreach ($purchasingChecks as $label => $needle) {
    if (strpos($purchasingPage['body'], $needle) !== false) {
        echo "  -> [OK] Elemen '{$label}' terverifikasi ada di Dashboard Purchasing.\n";
    } else {
        echo "  -> [GAGAL] Elemen '{$label}' ({$needle}) tidak ditemukan di Dashboard Purchasing!\n";
        exit(1);
    }
}

// BAGIAN 3: PENGUJIAN KEAMANAN RBAC & PEMBATASAN URL BACKEND
echo "\n--- BAGIAN 3: UJI KEAMANAN AKSES URL & PERLINDUNGAN RBAC (403 FORBIDDEN) ---\n";

// Skenario 1: Purchasing mencoba akses modul Produksi
echo "1. Purchasing mengakses 'production/orders'...\n";
loginAndGetRedirect('purchasing', 'password');
$secTest1 = requestPage('production/orders');
if ($secTest1['code'] === 403 && strpos($secTest1['body'], 'Akses Ditolak') !== false) {
    echo "  -> [OK] Berhasil ditolak dengan HTTP 403 (Forbidden).\n";
} else {
    echo "  -> [GAGAL] Keamanan gagal! Purchasing bisa akses modul Produksi! HTTP: {$secTest1['code']}\n";
    exit(1);
}

// Skenario 2: Purchasing mencoba akses dashboard departemen lain (dashboard/ppic)
echo "2. Purchasing mengakses 'dashboard/ppic'...\n";
$secTest2 = requestPage('dashboard/ppic');
if ($secTest2['code'] === 403) {
    echo "  -> [OK] Berhasil ditolak dengan HTTP 403 (Forbidden).\n";
} else {
    echo "  -> [GAGAL] Purchasing bisa membuka dashboard PPIC! HTTP: {$secTest2['code']}\n";
    exit(1);
}

// Skenario 3: PPIC mencoba akses Payroll
echo "3. PPIC mengakses 'hr_payroll/payroll'...\n";
loginAndGetRedirect('ppic', 'password');
$secTest3 = requestPage('hr_payroll/payroll');
if ($secTest3['code'] === 403) {
    echo "  -> [OK] Berhasil ditolak dengan HTTP 403 (Forbidden).\n";
} else {
    echo "  -> [GAGAL] PPIC bisa akses modul Payroll! HTTP: {$secTest3['code']}\n";
    exit(1);
}

// Skenario 4: Penjualan mencoba akses Akuntansi (reports/journals)
echo "4. Penjualan mengakses 'reports/journals'...\n";
loginAndGetRedirect('penjualan', 'password');
$secTest4 = requestPage('reports/journals');
if ($secTest4['code'] === 403) {
    echo "  -> [OK] Berhasil ditolak dengan HTTP 403 (Forbidden).\n";
} else {
    echo "  -> [GAGAL] Penjualan bisa akses modul Jurnal Akuntansi! HTTP: {$secTest4['code']}\n";
    exit(1);
}

// Skenario 5: Gudang mencoba akses Akuntansi (reports/journals)
echo "5. Gudang mengakses 'reports/journals'...\n";
loginAndGetRedirect('gudang', 'password');
$secTest5 = requestPage('reports/journals');
if ($secTest5['code'] === 403) {
    echo "  -> [OK] Berhasil ditolak dengan HTTP 403 (Forbidden).\n";
} else {
    echo "  -> [GAGAL] Gudang bisa akses modul Jurnal Akuntansi! HTTP: {$secTest5['code']}\n";
    exit(1);
}

// Skenario 6: Non-Admin mencoba akses Administrasi Pengguna (users/index)
echo "6. Gudang mengakses modul administrasi 'users/index'...\n";
$secTest6 = requestPage('users/index');
if ($secTest6['code'] === 403) {
    echo "  -> [OK] Berhasil ditolak dengan HTTP 403 (Forbidden).\n";
} else {
    echo "  -> [GAGAL] Non-admin bisa akses modul Administrasi Pengguna! HTTP: {$secTest6['code']}\n";
    exit(1);
}

// Skenario 7: Manager mencoba akses operasional (diizinkan) tapi users/index (ditolak)
echo "7. Manager mengakses modul operasional & users/index...\n";
loginAndGetRedirect('manager', 'password');
$mgrTest1 = requestPage('receiving_sales/sales_orders');
if ($mgrTest1['code'] === 200) {
    echo "  -> [OK] Manager diizinkan membuka modul operasional Sales (HTTP 200).\n";
} else {
    echo "  -> [GAGAL] Manager tidak bisa membuka modul operasional! HTTP: {$mgrTest1['code']}\n";
    exit(1);
}
$mgrTest2 = requestPage('users/index');
if ($mgrTest2['code'] === 403) {
    echo "  -> [OK] Manager ditolak membuka modul administrasi users/index (HTTP 403).\n";
} else {
    echo "  -> [GAGAL] Manager tidak seharusnya bisa membuka users/index! HTTP: {$mgrTest2['code']}\n";
    exit(1);
}

// Skenario 8: Administrator memiliki akses penuh ke seluruh modul
echo "8. Administrator mengakses modul users/index dan dashboard...\n";
loginAndGetRedirect('admin', 'admin123');
$adminTest1 = requestPage('users/index');
$adminTest2 = requestPage('dashboard/admin');
if ($adminTest1['code'] === 200 && $adminTest2['code'] === 200) {
    echo "  -> [OK] Administrator memiliki akses penuh ke users/index dan dashboard/admin (HTTP 200).\n";
} else {
    echo "  -> [GAGAL] Administrator ditolak akses! HTTP 1: {$adminTest1['code']}, HTTP 2: {$adminTest2['code']}\n";
    exit(1);
}

if (file_exists($cookieFile)) unlink($cookieFile);

echo "\n========================================================\n";
echo "HASIL: SELURUH PENGUJIAN MULTI-DASHBOARD & RBAC LULUS (100% OK)!\n";
echo "========================================================\n";
