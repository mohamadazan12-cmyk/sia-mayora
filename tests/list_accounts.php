<?php
require_once __DIR__ . '/../app/config/database.php';
$pdo = getDbConnection();

$stmt = $pdo->query("SELECT id, code, name, type FROM accounts ORDER BY code ASC");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo sprintf("%-4d | %-12s | %-40s | %s\n", $r['id'], $r['code'], $r['name'], $r['type']);
}
