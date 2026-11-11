<?php
require_once __DIR__ . '/../app/config/database.php';
$db = getDbConnection();

echo "Running vendor migration...\n";

function columnExists($db, $table, $column) {
    $stmt = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    return $stmt->fetch() !== false;
}

// 1. suppliers columns
if (!columnExists($db, 'suppliers', 'npwp')) {
    $db->exec("ALTER TABLE `suppliers` ADD COLUMN `npwp` VARCHAR(50) NULL AFTER `email`");
    echo "- Added `npwp` to `suppliers`\n";
}
if (!columnExists($db, 'suppliers', 'bank_name')) {
    $db->exec("ALTER TABLE `suppliers` ADD COLUMN `bank_name` VARCHAR(100) NULL AFTER `payment_terms_days`");
    echo "- Added `bank_name` to `suppliers`\n";
}
if (!columnExists($db, 'suppliers', 'bank_account')) {
    $db->exec("ALTER TABLE `suppliers` ADD COLUMN `bank_account` VARCHAR(100) NULL AFTER `bank_name`");
    echo "- Added `bank_account` to `suppliers`\n";
}
if (!columnExists($db, 'suppliers', 'bank_account_holder')) {
    $db->exec("ALTER TABLE `suppliers` ADD COLUMN `bank_account_holder` VARCHAR(150) NULL AFTER `bank_account`");
    echo "- Added `bank_account_holder` to `suppliers`\n";
}

// 2. customers columns
if (!columnExists($db, 'customers', 'npwp')) {
    $db->exec("ALTER TABLE `customers` ADD COLUMN `npwp` VARCHAR(50) NULL AFTER `email`");
    echo "- Added `npwp` to `customers`\n";
}

echo "Migration finished successfully!\n";
