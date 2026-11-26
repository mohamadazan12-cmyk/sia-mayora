<?php
require_once __DIR__ . '/../app/config/database.php';
$pdo = getDbConnection();

$stmt = $pdo->query("SELECT i.id, i.code, i.name, c.name as category, u.name as unit, i.cost_price, i.selling_price 
FROM items i 
JOIN item_categories c ON i.category_id = c.id 
JOIN units u ON i.unit_id = u.id 
ORDER BY i.id ASC LIMIT 30");

while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo sprintf("[%d] %-12s | %-30s | %-15s | %-8s | Cost: %10s | Sell: %10s\n", 
        $r['id'], $r['code'], substr($r['name'], 0, 30), $r['category'], $r['unit'], number_format($r['cost_price']), number_format($r['selling_price']));
}
echo "Total items: " . $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn() . PHP_EOL;
