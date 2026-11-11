<?php
/**
 * Database Configuration and PDO Connection
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

// Load .env file if exists
function loadEnv($path) {
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Load env from project root
$envPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
if (!file_exists($envPath)) {
    $envPath = dirname(__DIR__, 2) . '/.env';
}
loadEnv($envPath);

function getDbConnection() {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $getHost = $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? (getenv('DB_HOST') !== false ? getenv('DB_HOST') : '127.0.0.1');
    $port = $_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? (getenv('DB_PORT') !== false ? getenv('DB_PORT') : '3306');
    $dbname = $_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'] ?? (getenv('DB_NAME') !== false ? getenv('DB_NAME') : 'projek_sia');
    $user = $_ENV['DB_USER'] ?? $_SERVER['DB_USER'] ?? (getenv('DB_USER') !== false ? getenv('DB_USER') : 'root');
    $pass = $_ENV['DB_PASS'] ?? $_SERVER['DB_PASS'] ?? (getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 5,
    ];

    // Priority connection order
    $hostsToTry = array_unique([$getHost, '127.0.0.1', 'localhost']);

    $lastException = null;

    foreach ($hostsToTry as $host) {
        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, $options);
            return $pdo;
        } catch (PDOException $e) {
            $lastException = $e;
        }
    }

    // If all connection attempts failed
    $errMsg = $lastException ? $lastException->getMessage() : 'Unknown error';
    error_log(sprintf(
        '[SIA Database Connection Error] %s (Hosts attempted: %s, Port: %s, Database: %s, User: %s)',
        $errMsg,
        implode(', ', $hostsToTry),
        $port,
        $dbname,
        $user
    ));

    http_response_code(500);
    echo '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kesalahan Koneksi Database - SIA Manufaktur</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
        .error-card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; max-width: 580px; width: 100%; padding: 32px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); }
        h1 { color: #f87171; font-size: 20px; margin-top: 0; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        p { color: #cbd5e1; font-size: 14px; line-height: 1.6; margin: 0 0 16px 0; }
        .diagnostic-box { background: #090d16; border: 1px solid #b91c1c; border-radius: 6px; padding: 12px; margin-bottom: 16px; font-family: Consolas, monospace; font-size: 12px; color: #fca5a5; word-break: break-all; }
        .diagnostic-box strong { color: #f87171; }
        .diagnostic-meta { margin-top: 6px; font-size: 11px; color: #94a3b8; }
        .instructions { background: #0f172a; border-left: 4px solid #38bdf8; padding: 14px 16px; border-radius: 4px; font-size: 13px; color: #94a3b8; line-height: 1.5; margin-bottom: 20px; }
        .instructions strong { color: #f1f5f9; }
        .btn-retry { display: inline-block; background: #0284c7; color: #fff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 13px; }
        .btn-retry:hover { background: #0369a1; }
    </style>
</head>
<body>
    <div class="error-card">
        <h1>⚠️ Koneksi ke Basis Data Gagal</h1>
        <p>Aplikasi tidak dapat terhubung ke server basis data MySQL/MariaDB. Silakan pastikan server database aktif dan dapat diakses.</p>
        <div class="diagnostic-box">
            <strong>Detail Galat Teknis (PDOException):</strong><br>
            ' . htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8') . '
            <div class="diagnostic-meta">
                Host Target: <code>' . htmlspecialchars(implode(', ', $hostsToTry), ENT_QUOTES, 'UTF-8') . '</code> &bull;
                Port: <code>' . htmlspecialchars((string)$port, ENT_QUOTES, 'UTF-8') . '</code> &bull;
                Database: <code>' . htmlspecialchars($dbname, ENT_QUOTES, 'UTF-8') . '</code> &bull;
                User: <code>' . htmlspecialchars($user, ENT_QUOTES, 'UTF-8') . '</code>
            </div>
        </div>
        <div class="instructions">
            <strong>Petunjuk Pemecahan Masalah (XAMPP):</strong><br>
            1. Buka <strong>XAMPP Control Panel</strong>.<br>
            2. Pastikan modul <strong>MySQL</strong> dalam status <strong>Running</strong> (Port 3306).<br>
            3. Pastikan database <code>' . htmlspecialchars($dbname, ENT_QUOTES, 'UTF-8') . '</code> telah dibuat dan diimpor.<br>
            4. Periksa pengaturan koneksi pada berkas konfigurasi <code>.env</code> di direktori utama aplikasi.
        </div>
        <a href="index.php" class="btn-retry">🔄 Muat Ulang Halaman</a>
    </div>
</body>
</html>';
    exit;
}
