<?php
/**
 * Test Suite: CSRF Protection Verification Across All Forms & POST Handlers
 */

$appDir = realpath(__DIR__ . '/../app/modules');
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir));

$postHandlerWithoutRequireCsrf = [];
$formWithoutCsrfField = [];
$totalForms = 0;
$totalPostHandlers = 0;

foreach ($iter as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());
        $rel = str_replace(realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR, '', $file->getPathname());

        // Check POST handlers
        if (strpos($content, "REQUEST_METHOD'] === 'POST'") !== false || strpos($content, 'REQUEST_METHOD"] === "POST"') !== false || strpos($content, "REQUEST_METHOD'] == 'POST'") !== false) {
            $totalPostHandlers++;
            if (strpos($content, 'requireCsrf()') === false) {
                $postHandlerWithoutRequireCsrf[] = $rel;
            }
        }

        // Check HTML forms
        preg_match_all('/<form[^>]*method=[\'"]post[\'"][^>]*>(.*?)<\/form>/is', $content, $matches);
        if (!empty($matches[0])) {
            foreach ($matches[0] as $formHtml) {
                $totalForms++;
                if (strpos($formHtml, 'csrfField()') === false && strpos($formHtml, 'csrf_token') === false) {
                    $formWithoutCsrfField[] = [
                        'file' => $rel,
                        'snippet' => substr($formHtml, 0, 100)
                    ];
                }
            }
        }
    }
}

echo "========================================================\n";
echo "CSRF PROTECTION AUDIT RESULT\n";
echo "========================================================\n";
echo "Total POST Handlers Checked: {$totalPostHandlers}\n";
echo "POST Handlers Missing requireCsrf(): " . count($postHandlerWithoutRequireCsrf) . "\n";
foreach ($postHandlerWithoutRequireCsrf as $f) {
    echo "  [WARN] {$f}\n";
}

echo "\nTotal POST Forms Checked: {$totalForms}\n";
echo "Forms Missing csrfField(): " . count($formWithoutCsrfField) . "\n";
foreach ($formWithoutCsrfField as $fw) {
    echo "  [WARN] {$fw['file']} -> " . trim(preg_replace('/\s+/', ' ', $fw['snippet'])) . "\n";
}
