<?php
/**
 * Scanner for broken links, form actions, and route parameters in all PHP files.
 */

$appDir = realpath(__DIR__ . '/../app');
$publicDir = realpath(__DIR__ . '/../public');

$allPhpFiles = [];
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir));
foreach ($iter as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $allPhpFiles[] = $file->getPathname();
    }
}

echo "Scanning " . count($allPhpFiles) . " PHP files for internal links...\n";

$brokenLinks = [];
$checkedLinks = 0;

$routeAliases = [
    'persetujuan_mutasi'    => 'hr_payroll/mutations',
    'persetujuan_pelatihan' => 'hr_payroll/trainings',
    'verifikasi_gaji'       => 'hr_payroll/salary_verification',
    'verifikasi_hak_akhir'  => 'hr_payroll/terminations',
    'laporan'               => 'hr_payroll/reports',
];

foreach ($allPhpFiles as $filePath) {
    $content = file_get_contents($filePath);
    $relFile = str_replace(realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '', $filePath);

    // Match href="index.php?page=..." or action="index.php?page=..."
    preg_match_all('/(?:href|action)=["\']index\.php\?page=([a-zA-Z0-9_\/]+)/i', $content, $matches);
    if (!empty($matches[1])) {
        foreach ($matches[1] as $route) {
            $checkedLinks++;
            $cleanRoute = $route;
            // Handle aliases
            if (isset($routeAliases[$cleanRoute])) {
                $cleanRoute = $routeAliases[$cleanRoute];
            }
            if ($cleanRoute === 'logout' || $cleanRoute === 'login' || $cleanRoute === 'dashboard') {
                continue;
            }

            // Check if file exists
            $parts = explode('/', $cleanRoute);
            $targetFile1 = $appDir . '/modules/' . implode('/', $parts) . '.php';
            $targetFile2 = $appDir . '/modules/' . implode('/', $parts) . '/index.php';
            $targetFile3 = $appDir . '/modules/' . $parts[0] . '/index.php';

            if (!file_exists($targetFile1) && !file_exists($targetFile2) && !file_exists($targetFile3)) {
                $brokenLinks[] = [
                    'source' => $relFile,
                    'route'  => $route,
                    'target1' => $targetFile1
                ];
            }
        }
    }
}

echo "Total links checked: $checkedLinks\n";
echo "Broken links found: " . count($brokenLinks) . "\n";
foreach ($brokenLinks as $bl) {
    echo "  [BROKEN] Source: {$bl['source']} -> Route: {$bl['route']}\n";
}
