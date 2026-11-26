<?php
require_once __DIR__ . '/../app/config/database.php';
$pdo = getDbConnection();

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "Has notifications table? " . (in_array('notifications', $tables) ? 'YES' : 'NO') . PHP_EOL;

$specificTables = [
    'notifications',
    'operations_lists',
    'production_schedules',
    'material_requisitions',
    'material_requisition_items',
    'material_returns',
    'material_return_items',
    'move_tickets',
    'job_time_tickets',
    'debit_memos',
    'batch_tracking',
    'machines',
    'quality_standards'
];

foreach ($specificTables as $t) {
    echo "Table '$t': " . (in_array($t, $tables) ? 'EXISTS' : 'NOT FOUND') . PHP_EOL;
}
