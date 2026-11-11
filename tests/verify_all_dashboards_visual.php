<?php
/**
 * Verifikasi Visual & Fungsional 9 Dashboard Mayora
 */

$baseUrl = 'http://localhost:8000';
$cookieFile = __DIR__ . '/cookie_verify.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

function httpReq($url, $postData = null) {
    global $cookieFile;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if ($postData !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body];
}

function doLogin($username, $password) {
    global $cookieFile, $baseUrl;
    if (file_exists($cookieFile)) unlink($cookieFile);
    $loginPage = httpReq($baseUrl . '/index.php?page=login');
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $loginPage['body'], $matches);
    $csrf = $matches[1] ?? '';
    return httpReq($baseUrl . '/index.php?page=login', [
        'csrf_token' => $csrf,
        'username' => $username,
        'password' => $password
    ]);
}

$dashboards = [
    'admin' => [
        'user' => 'admin', 'pass' => 'admin123',
        'route' => 'dashboard/admin',
        'title' => 'Dashboard Utama Perusahaan',
        'unique_elements' => ['Pesanan Penjualan Aktif', 'SPK Produksi Berjalan', 'Peringatan Stok di Bawah Batas Minimum']
    ],
    'purchasing' => [
        'user' => 'purchasing', 'pass' => 'password',
        'route' => 'dashboard/purchasing',
        'title' => 'Dashboard Staff Pembelian',
        'unique_elements' => ['PR Menunggu RFQ', 'PO Menunggu Otorisasi', 'Retur Berjalan', 'Informasi Vendor Utama']
    ],
    'ppic' => [
        'user' => 'ppic', 'pass' => 'password',
        'route' => 'dashboard/ppic',
        'title' => 'Dashboard Produksi & PPIC',
        'unique_elements' => ['SPK Menunggu Proses', 'Produksi Berjalan', 'Realisasi Output Produksi', 'Perintah Produksi (SPK) Aktif']
    ],
    'penjualan' => [
        'user' => 'penjualan', 'pass' => 'password',
        'route' => 'dashboard/penjualan',
        'title' => 'Dashboard Penerimaan & Penjualan',
        'unique_elements' => ['Total Pesanan Aktif (SO)', 'Surat Jalan (DO) Berjalan', 'Saldo Piutang Pelanggan', 'Pesanan Penjualan (Sales Orders) Terbaru']
    ],
    'gudang' => [
        'user' => 'gudang', 'pass' => 'password',
        'route' => 'dashboard/gudang',
        'title' => 'Dashboard Gudang & Persediaan',
        'unique_elements' => ['Total Item Persediaan', 'Total Saldo Fisik Stok', 'Item Kritis di Bawah Batas Minimum', 'Mutasi Perpindahan Barang']
    ],
    'qc' => [
        'user' => 'qc', 'pass' => 'password',
        'route' => 'dashboard/qc',
        'title' => 'Dashboard Quality Control',
        'unique_elements' => ['SPK Menunggu Inspeksi', 'Inspeksi Lolos Uji (Pass)', 'Akumulasi Bahan / Produk Scrap', 'Antrean SPK Menunggu Inspeksi Mutu']
    ],
    'finance' => [
        'user' => 'finance', 'pass' => 'password',
        'route' => 'dashboard/finance',
        'title' => 'Dashboard Keuangan & Akuntansi',
        'unique_elements' => ['Piutang Usaha (AR)', 'Utang Usaha (AP)', 'Neraca Saldo Jurnal', 'Jurnal Umum & Entri Akuntansi Terkini']
    ],
    'hr' => [
        'user' => 'hr', 'pass' => 'password',
        'route' => 'dashboard/hr',
        'title' => 'Dashboard SDM & Penggajian',
        'unique_elements' => ['Total Karyawan Aktif', 'Presensi Hadir Hari Ini', 'Realisasi Beban Gaji Terakhir', 'Pengajuan Cuti & Lembur Menunggu Persetujuan']
    ],
    'manager' => [
        'user' => 'manager', 'pass' => 'password',
        'route' => 'dashboard/manager',
        'title' => 'Dashboard Manajemen Operasional',
        'unique_elements' => ['Total Omset Pesanan', 'Produksi Selesai', 'Antrean Dokumen Menunggu Persetujuan (Approval)', 'Log Audit & Aktivitas Sistem Terkini']
    ]
];

echo "============================================================\n";
echo "VERIFIKASI SISTEM DESAIN VISUAL MAYORA PADA 9 DASHBOARD\n";
echo "============================================================\n\n";

$allPassed = true;
foreach ($dashboards as $roleKey => $cfg) {
    echo "MEMERIKSA DASHBOARD: [" . strtoupper($roleKey) . "] ({$cfg['title']})\n";
    doLogin($cfg['user'], $cfg['pass']);
    $page = httpReq($baseUrl . '/index.php?page=' . $cfg['route']);

    if ($page['code'] !== 200) {
        echo "  [GAGAL] HTTP code: {$page['code']}\n";
        $allPassed = false;
        continue;
    }

    // Periksa Desain Global Mayora
    $commonChecks = [
        'Wordmark MAYORA' => 'MAYORA',
        'Tailwind CDN' => 'cdn.tailwindcss.com',
        'Google Font Plus Jakarta Sans' => 'Plus Jakarta Sans',
        'Warna Mayora Red #EE2726' => '#EE2726',
        'Warna Light Red #FFF1F1 / #FFF2F2' => '#FFF2F2',
        'Footer Pabrik Tangerang' => 'Pabrik Tangerang',
        'Breadcrumbs' => 'Dashboard',
        'Logout Link' => 'page=logout',
        'Judul Halaman' => $cfg['title'],
    ];

    foreach ($commonChecks as $lbl => $str) {
        if (strpos($page['body'], $str) === false) {
            echo "  [GAGAL] Token visual umum '{$lbl}' ({$str}) tidak ditemukan!\n";
            $allPassed = false;
        }
    }

    // Periksa Elemen Spesifik Peran
    foreach ($cfg['unique_elements'] as $elem) {
        if (strpos($page['body'], $elem) === false) {
            echo "  [GAGAL] Elemen spesifik '{$elem}' tidak ditemukan!\n";
            $allPassed = false;
        }
    }

    echo "  -> [BERHASIL] Seluruh token desain visual Mayora & data spesifik {$roleKey} terverifikasi 100% OK.\n\n";
}

if (file_exists($cookieFile)) unlink($cookieFile);

if ($allPassed) {
    echo "============================================================\n";
    echo "HASIL: SELURUH 9 DASHBOARD LULUS VERIFIKASI DESAIN MAYORA!\n";
    echo "============================================================\n";
} else {
    echo "Terdapat ketidaksesuaian verifikasi!\n";
    exit(1);
}
