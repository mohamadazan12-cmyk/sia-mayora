<?php
require_once __DIR__ . '/../app/config/database.php';
$pdo = getDbConnection();

$stmt = $pdo->query("SELECT e.id, e.code, e.full_name, d.name as dept, p.name as pos 
FROM employees e 
LEFT JOIN departments d ON e.department_id = d.id 
LEFT JOIN positions p ON e.position_id = p.id 
LIMIT 20");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo sprintf("%-4d | %-10s | %-25s | %-18s | %s\n", $r['id'], $r['code'], substr($r['full_name'], 0, 25), $r['dept'], $r['pos']);
}
echo "Total employees: " . $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn() . PHP_EOL;
