<?php
require_once __DIR__ . '/../app/config/database.php';
$pdo = getDbConnection();

$roles = $pdo->query("SELECT id, role_name, role_code, description FROM roles ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
echo "=== ROLES IN DATABASE ===\n";
foreach ($roles as $r) {
    echo "ID {$r['id']}: {$r['role_code']} - {$r['role_name']} ({$r['description']})\n";
}

$users = $pdo->query("SELECT u.id, u.username, u.full_name, r.role_code, r.role_name FROM users u JOIN roles r ON u.role_id = r.id ORDER BY u.id")->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== USERS IN DATABASE ===\n";
foreach ($users as $u) {
    echo "ID {$u['id']}: {$u['username']} ({$u['full_name']}) - Role: {$u['role_code']} ({$u['role_name']})\n";
}
