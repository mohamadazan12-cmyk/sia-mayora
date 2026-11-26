<?php
require_once __DIR__ . '/../app/config/database.php';
$pdo = getDbConnection();

echo "=== CATEGORIES ===" . PHP_EOL;
foreach ($pdo->query("SELECT category_code, category_name FROM item_categories") as $r) {
    echo " - " . $r['category_code'] . ": " . $r['category_name'] . PHP_EOL;
}

echo PHP_EOL . "=== ITEM TYPES & COUNTS ===" . PHP_EOL;
foreach ($pdo->query("SELECT item_type, COUNT(*) as c FROM items GROUP BY item_type") as $r) {
    echo " - " . $r['item_type'] . ": " . $r['c'] . PHP_EOL;
}

echo PHP_EOL . "=== SAMPLE ITEMS ===" . PHP_EOL;
foreach ($pdo->query("SELECT item_code, item_name, item_type FROM items LIMIT 20") as $r) {
    echo " - " . $r['item_code'] . ": " . $r['item_name'] . " (" . $r['item_type'] . ")" . PHP_EOL;
}

echo PHP_EOL . "=== MACHINES ===" . PHP_EOL;
$tables = $pdo->query("SHOW TABLES LIKE '%machine%'")->fetchAll(PDO::FETCH_COLUMN);
print_r($tables);
if (in_array('machine_logs', $tables)) {
    echo "Columns of machine_logs: " . PHP_EOL;
    $cols = $pdo->query("DESCRIBE machine_logs")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  " . $c['Field'] . " (" . $c['Type'] . ")" . PHP_EOL;
}
