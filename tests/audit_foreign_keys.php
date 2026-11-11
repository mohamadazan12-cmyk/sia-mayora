<?php
require_once __DIR__ . '/../app/config/database.php';
$pdo = getDbConnection();

// Get all foreign keys
$fks = $pdo->query("
    SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'projek_sia' AND REFERENCED_TABLE_NAME IS NOT NULL
    ORDER BY TABLE_NAME, COLUMN_NAME
")->fetchAll(PDO::FETCH_ASSOC);

echo "=== FOREIGN KEYS (" . count($fks) . ") ===\n";
foreach ($fks as $fk) {
    echo "{$fk['TABLE_NAME']}.{$fk['COLUMN_NAME']} -> {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}\n";
}
