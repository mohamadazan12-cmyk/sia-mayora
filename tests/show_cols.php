<?php
require_once __DIR__ . '/../app/config/database.php';
$pdo = getDbConnection();
$tables = ['employees', 'attendance_records', 'leave_overtime_requests', 'payroll_records', 'payroll_periods'];
foreach ($tables as $t) {
    echo "--- $t ---\n";
    $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "{$c['Field']} ({$c['Type']})\n";
    }
}
