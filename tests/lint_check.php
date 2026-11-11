<?php
$dir = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
$errors = [];
$checked = 0;

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $path = $file->getPathname();
        if (strpos($path, 'vendor') !== false || strpos($path, '.git') !== false) {
            continue;
        }
        $checked++;
        $output = [];
        $returnVar = 0;
        exec('"C:\\xampp\\php\\php.exe" -l ' . escapeshellarg($path), $output, $returnVar);
        if ($returnVar !== 0) {
            $errors[] = implode("\n", $output);
        }
    }
}

echo "Checked $checked PHP files.\n";
if (empty($errors)) {
    echo "SUCCESS: All PHP files passed syntax linting!\n";
    exit(0);
} else {
    echo "ERRORS:\n" . implode("\n", $errors) . "\n";
    exit(1);
}
