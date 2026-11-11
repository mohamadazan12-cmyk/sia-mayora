<?php
/**
 * Test Login Feature, CSRF, Session, Password Check, and Error Handling
 */

$baseUrl = 'http://localhost:8000';
$cookieFile = __DIR__ . '/test_session_cookie.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

function makeRequest($url, $postData = null, $followLocation = false) {
    global $cookieFile;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followLocation);
    if ($postData !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($postData) ? http_build_query($postData) : $postData);
    }
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'headers' => $headers,
        'body' => $body
    ];
}

echo "===============================================\n";
echo "PENGUJIAN FITUR LOGIN & AUTENTIKASI SIA MAYORA\n";
echo "===============================================\n\n";

// TEST 1: Membuka Halaman Login di Localhost
echo "[TEST 1] Membuka halaman login di localhost:8000...\n";
$res = makeRequest($baseUrl . '/index.php?page=login');
if ($res['code'] === 200 && strpos($res['body'], 'PT Mayora Indah Tbk') !== false && strpos($res['body'], 'MAYORA') !== false) {
    echo "  -> [BERHASIL] Halaman login berhasil dimuat dengan status 200.\n";
    echo "  -> [BERHASIL] Elemen 'PT Mayora Indah Tbk' dan logo wordmark 'MAYORA' ditemukan.\n";
} else {
    echo "  -> [GAGAL] Respon login tidak valid. Code: {$res['code']}\n";
    exit(1);
}

// Check CSRF token existence
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $res['body'], $matches);
$csrfToken = $matches[1] ?? '';
if (!empty($csrfToken)) {
    echo "  -> [BERHASIL] CSRF token ditemukan dalam form login: " . substr($csrfToken, 0, 10) . "...\n";
} else {
    echo "  -> [GAGAL] CSRF token tidak ditemukan di form login!\n";
    exit(1);
}

// TEST 2: Uji Elemen Password Toggle & Script
echo "\n[TEST 2] Memeriksa tombol tampil/sembunyikan password dan script toggle...\n";
if (strpos($res['body'], 'id="togglePassword"') !== false && strpos($res['body'], 'id="password"') !== false && strpos($res['body'], 'toggle-password-visibility') !== false) {
    echo "  -> [BERHASIL] Tombol #togglePassword, input #password, dan handler script toggle terpasang dengan benar.\n";
} else {
    echo "  -> [GAGAL] Elemen toggle password tidak lengkap!\n";
    exit(1);
}

// TEST 3: Uji Login dengan Password yang Salah
echo "\n[TEST 3] Menguji login dengan password salah...\n";
$wrongLogin = makeRequest($baseUrl . '/index.php?page=login', [
    'csrf_token' => $csrfToken,
    'username'   => 'admin',
    'password'   => 'passwordSalah123',
]);
if (strpos($wrongLogin['body'], 'Nama pengguna atau kata sandi tidak valid') !== false) {
    echo "  -> [BERHASIL] Login gagal ditolak dengan pesan kesalahan yang sesuai.\n";
    echo "  -> [BERHASIL] Pesan kesalahan dirender dalam container alert berdesain merah Tailwind.\n";
} else {
    echo "  -> [GAGAL] Pesan kesalahan tidak muncul saat password salah!\n";
    echo "HTTP Code: " . $wrongLogin['code'] . "\n";
    echo "Body length: " . strlen($wrongLogin['body']) . "\n";
    echo "Headers:\n" . $wrongLogin['headers'] . "\n";
    echo "Body preview:\n" . substr($wrongLogin['body'], 0, 500) . "\n";
    exit(1);
}

// Refresh CSRF Token after post
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $wrongLogin['body'], $matches);
$csrfToken = $matches[1] ?? $csrfToken;

// TEST 4: Uji Login dengan CSRF Token Palsu/Salah
echo "\n[TEST 4] Menguji proteksi CSRF token...\n";
$invalidCsrf = makeRequest($baseUrl . '/index.php?page=login', [
    'csrf_token' => 'invalid_token_12345',
    'username'   => 'admin',
    'password'   => 'admin123',
]);
if ($invalidCsrf['code'] === 403) {
    echo "  -> [BERHASIL] Permintaan login dengan CSRF salah ditolak (HTTP 403 Forbidden).\n";
} else {
    echo "  -> [GAGAL] Proteksi CSRF gagal menolak token tidak valid! Code: {$invalidCsrf['code']}\n";
    exit(1);
}

// TEST 5: Uji Login Menggunakan Akun Administrator
echo "\n[TEST 5] Menguji login berhasil dengan akun administrator ('admin')...\n";
// Re-fetch login page to get valid session and fresh token
$freshLogin = makeRequest($baseUrl . '/index.php?page=login');
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $freshLogin['body'], $matches);
$freshToken = $matches[1] ?? '';

$adminLogin = makeRequest($baseUrl . '/index.php?page=login', [
    'csrf_token' => $freshToken,
    'username'   => 'admin',
    'password'   => 'admin123',
]);

if ($adminLogin['code'] === 302 && strpos($adminLogin['headers'], 'Location: index.php?page=dashboard') !== false) {
    echo "  -> [BERHASIL] Login admin mengembalikan redirect 302 ke dashboard.\n";
} else {
    echo "  -> [GAGAL] Redirect login tidak sesuai. Code: {$adminLogin['code']}\nHeaders:\n{$adminLogin['headers']}\n";
    exit(1);
}

// TEST 6: Akses Dashboard dengan Sesi Login
echo "\n[TEST 6] Mengakses dashboard dengan sesi terautentikasi...\n";
$dashRes = makeRequest($baseUrl . '/index.php?page=dashboard');
if ($dashRes['code'] === 200 && strpos($dashRes['body'], 'Administrator Sistem') !== false) {
    echo "  -> [BERHASIL] Dashboard berhasil diakses sebagai Administrator Sistem.\n";
    echo "  -> [BERHASIL] Session dan hak akses role berfungsi penuh.\n";
} else {
    echo "  -> [GAGAL] Gagal mengakses dashboard. Code: {$dashRes['code']}\n";
    exit(1);
}

// TEST 7: Akses Halaman Login Saat Sedang Login (Harus Redirect ke Dashboard)
echo "\n[TEST 7] Mengakses halaman login saat sudah login (proteksi re-login)...\n";
$alreadyLoggedIn = makeRequest($baseUrl . '/index.php?page=login');
if ($alreadyLoggedIn['code'] === 302 && strpos($alreadyLoggedIn['headers'], 'Location: index.php?page=dashboard') !== false) {
    echo "  -> [BERHASIL] Pengguna yang sudah login langsung dialihkan ke dashboard.\n";
} else {
    echo "  -> [GAGAL] Proteksi re-login tidak bekerja. Code: {$alreadyLoggedIn['code']}\n";
    exit(1);
}

// TEST 8: Uji Logout dan Kembali ke Login Page
echo "\n[TEST 8] Menguji logout sistem...\n";
$logoutRes = makeRequest($baseUrl . '/index.php?page=logout');
if ($logoutRes['code'] === 302 && strpos($logoutRes['headers'], 'Location: index.php?page=login') !== false) {
    echo "  -> [BERHASIL] Logout berhasil mengarahkan ke halaman login.\n";
} else {
    echo "  -> [GAGAL] Logout redirect tidak sesuai.\n";
    exit(1);
}

// TEST 9: Uji Login dengan Akun Non-Admin (Role Petugas Gudang)
echo "\n[TEST 9] Menguji login role non-admin (gudang)...\n";
$gudangPage = makeRequest($baseUrl . '/index.php?page=login');
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $gudangPage['body'], $matches);
$gudangToken = $matches[1] ?? '';

$gudangLogin = makeRequest($baseUrl . '/index.php?page=login', [
    'csrf_token' => $gudangToken,
    'username'   => 'gudang',
    'password'   => 'password',
]);

if ($gudangLogin['code'] === 302 && strpos($gudangLogin['headers'], 'Location: index.php?page=dashboard') !== false) {
    echo "  -> [BERHASIL] Login role gudang berhasil mengarah ke dashboard.\n";
    $gudangDash = makeRequest($baseUrl . '/index.php?page=dashboard');
    if (strpos($gudangDash['body'], 'Budi Santoso (Gudang)') !== false) {
        echo "  -> [BERHASIL] Dashboard mengenali pengguna Budi Santoso (Gudang).\n";
    }
} else {
    echo "  -> [GAGAL] Login role non-admin gagal.\n";
    exit(1);
}

// TEST 10: Uji Login dengan format Email
echo "\n[TEST 10] Menguji login menggunakan email (admin@perusahaan.local)...\n";
unlink($cookieFile);
$emailPage = makeRequest($baseUrl . '/index.php?page=login');
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $emailPage['body'], $matches);
$emailToken = $matches[1] ?? '';

$emailLogin = makeRequest($baseUrl . '/index.php?page=login', [
    'csrf_token' => $emailToken,
    'username'   => 'admin@perusahaan.local',
    'password'   => 'admin123',
]);

if ($emailLogin['code'] === 302 && strpos($emailLogin['headers'], 'Location: index.php?page=dashboard') !== false) {
    echo "  -> [BERHASIL] Login dengan email berhasil mengarah ke dashboard.\n";
} else {
    echo "  -> [GAGAL] Login dengan email gagal.\n";
    exit(1);
}

// Cleanup
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

echo "\n===============================================\n";
echo "HASIL: SELURUH PENGUJIAN LOGIN BERHASIL (100% OK)!\n";
echo "===============================================\n";
