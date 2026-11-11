<?php
require_once __DIR__ . '/../app/config/database.php';
$pdo = getDbConnection();
$sups = $pdo->query("SELECT id, code, name, is_active FROM suppliers ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
echo "Total suppliers: " . count($sups) . "\n";
foreach ($sups as $s) {
    echo "ID: {$s['id']} | Code: {$s['code']} | Name: {$s['name']} | Active: {$s['is_active']}\n";
}
