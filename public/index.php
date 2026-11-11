<?php
/**
 * Application Entry Point and Router
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

// Error reporting for development
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/csrf.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/services/inventory_service.php';
require_once __DIR__ . '/../app/services/approval_service.php';
require_once __DIR__ . '/../app/services/journal_service.php';

$page = $_GET['page'] ?? 'dashboard';

// Handle Logout
if ($page === 'logout') {
    logoutUser();
    header('Location: index.php?page=login');
    exit;
}

// Handle Login Page
if ($page === 'login') {
    if (isLoggedIn()) {
        header('Location: index.php?page=dashboard');
        exit;
    }

    $loginError = null;
    $username = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrf();
        $username = trim($_POST['username'] ?? $_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if (!empty($username) && !empty($password)) {
            if (loginUser($username, $password)) {
                $user = currentUser();
                $roleCode = $user['role_code'] ?? 'admin';
                setFlash('success', 'Selamat datang kembali, ' . $user['full_name'] . '!');
                header('Location: index.php?page=dashboard/' . urlencode($roleCode));
                exit;
            } else {
                $loginError = 'Nama pengguna atau kata sandi tidak valid.';
            }
        } else {
            $loginError = 'Harap isi nama pengguna dan kata sandi.';
        }
    }

    // Render Login Page View
    include __DIR__ . '/../app/modules/auth/login.php';
    exit;
}

// All other pages require authentication
requireLogin();

// Sanitize requested page path
$page = preg_replace('/[^a-zA-Z0-9_\/]/', '', $page);

// Route aliases for reference app compatibility
$routeAliases = [
    'persetujuan_mutasi'    => 'hr_payroll/mutations',
    'persetujuan_pelatihan' => 'hr_payroll/trainings',
    'verifikasi_gaji'       => 'hr_payroll/salary_verification',
    'verifikasi_hak_akhir'  => 'hr_payroll/terminations',
    'laporan'               => 'hr_payroll/reports',
];
if (isset($routeAliases[$page])) {
    $page = $routeAliases[$page];
}

// Enforce Role-Based Page Access
enforcePageAccess($page);

$pageParts = explode('/', $page);

// Determine module file
if (count($pageParts) === 1) {
    $moduleFile = __DIR__ . '/../app/modules/' . $pageParts[0] . '/index.php';
} else {
    $moduleFile = __DIR__ . '/../app/modules/' . implode('/', $pageParts) . '.php';
    if (!file_exists($moduleFile)) {
        $moduleFile = __DIR__ . '/../app/modules/' . implode('/', $pageParts) . '/index.php';
    }
}

// Check if module file exists
if (file_exists($moduleFile)) {
    include $moduleFile;
} else {
    http_response_code(404);
    $pageTitle = 'Halaman Tidak Ditemukan';
    include __DIR__ . '/../app/includes/header.php';
    echo '<div class="alert alert-warning">
            <h3>404 - Halaman Tidak Ditemukan</h3>
            <p>Halaman yang Anda tuju ("' . e($page) . '") tidak ditemukan atau belum tersedia.</p>
            <a href="index.php?page=dashboard" class="btn btn-primary" style="margin-top:10px;">Kembali ke Dashboard</a>
          </div>';
    include __DIR__ . '/../app/includes/footer.php';
}
