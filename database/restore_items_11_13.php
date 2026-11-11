<?php
require_once __DIR__ . '/../app/config/database.php';
$pdo = getDbConnection();

$stmt = $pdo->prepare("
    INSERT INTO items (id, code, name, category_id, unit_id, min_stock, cost_price, selling_price, description, is_active, created_at, updated_at)
    VALUES 
    (11, 'MSN-001', 'Mesin Pemotong Otomatis CNC Cut-500 Pro', 1, 5, 5.00, 45000000.00, 0.00, 'Unit mesin pemotong kemasan dan biskuit otomatis', 1, NOW(), NOW()),
    (12, 'MSN-002', 'Unit Konveyor Pengumpan Logam CV-200', 1, 5, 8.00, 25000000.00, 0.00, 'Konveyor pengumpan kemasan biskuit otomatis', 1, NOW(), NOW()),
    (13, 'PRT-001', 'Rakitan Poros Transmisi Presisi DS-80', 1, 5, 15.00, 3500000.00, 0.00, 'Modul transmisi putaran sudut conveyor pabrik', 1, NOW(), NOW())
    ON DUPLICATE KEY UPDATE name = VALUES(name), code = VALUES(code), is_active = 1
");
$stmt->execute();
echo "Inserted/Updated items 11, 12, 13 successfully.\n";
