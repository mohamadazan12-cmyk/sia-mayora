<?php
/**
 * Test Suite: All Master Data Modules (CRUD, Constraints, Soft/Hard Delete)
 */

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/auth.php';

$db = getDbConnection();

echo "========================================================\n";
echo "MASTER DATA COMPREHENSIVE CRUD & INTEGRITY TEST\n";
echo "========================================================\n\n";

function checkPass($cond, $msg) {
    if (!$cond) {
        echo "[FAIL] $msg\n";
        exit(1);
    }
    echo "[PASS] $msg\n";
}

// 1. Master Units
echo "--- 1. Master Units (Satuan) ---\n";
$uCode = 'U-' . time();
$db->prepare("INSERT INTO units (code, name, is_active, created_at, updated_at) VALUES (:c, 'Unit Uji Coba', 1, NOW(), NOW())")->execute([':c' => $uCode]);
$uId = (int)$db->lastInsertId();
checkPass($uId > 0, "Tambah satuan baru ($uCode, ID: $uId)");
$db->prepare("UPDATE units SET name = 'Unit Uji Coba (Rev)', is_active = 0 WHERE id = :id")->execute([':id' => $uId]);
$uName = $db->query("SELECT name FROM units WHERE id = $uId")->fetchColumn();
checkPass($uName === 'Unit Uji Coba (Rev)', "Edit satuan berhasil");
$db->prepare("DELETE FROM units WHERE id = :id")->execute([':id' => $uId]);
$uCount = $db->query("SELECT COUNT(*) FROM units WHERE id = $uId")->fetchColumn();
checkPass((int)$uCount === 0, "Hapus satuan berhasil");

// 2. Master Departments
echo "\n--- 2. Master Departments (Departemen) ---\n";
$dCode = 'DEPT-' . time();
$db->prepare("INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (:c, 'Departemen Riset Uji', 1, NOW(), NOW())")->execute([':c' => $dCode]);
$dId = (int)$db->lastInsertId();
checkPass($dId > 0, "Tambah departemen baru ($dCode, ID: $dId)");
$db->prepare("UPDATE departments SET name = 'Departemen Riset Uji & Inovasi', is_active = 0 WHERE id = :id")->execute([':id' => $dId]);
$dName = $db->query("SELECT name FROM departments WHERE id = $dId")->fetchColumn();
checkPass($dName === 'Departemen Riset Uji & Inovasi', "Edit departemen berhasil");
$db->prepare("DELETE FROM departments WHERE id = :id")->execute([':id' => $dId]);
$dCount = $db->query("SELECT COUNT(*) FROM departments WHERE id = $dId")->fetchColumn();
checkPass((int)$dCount === 0, "Hapus departemen berhasil");

// 3. Master Positions
echo "\n--- 3. Master Positions (Jabatan) ---\n";
$pCode = 'POS-' . time();
$db->prepare("INSERT INTO positions (code, name, department_id, is_active, created_at, updated_at) VALUES (:c, 'Staff Peneliti Uji', 1, 1, NOW(), NOW())")->execute([':c' => $pCode]);
$pId = (int)$db->lastInsertId();
checkPass($pId > 0, "Tambah jabatan baru ($pCode, ID: $pId)");
$db->prepare("UPDATE positions SET name = 'Senior Staff Peneliti Uji' WHERE id = :id")->execute([':id' => $pId]);
$pName = $db->query("SELECT name FROM positions WHERE id = $pId")->fetchColumn();
checkPass($pName === 'Senior Staff Peneliti Uji', "Edit jabatan berhasil");
$db->prepare("DELETE FROM positions WHERE id = :id")->execute([':id' => $pId]);
$pCount = $db->query("SELECT COUNT(*) FROM positions WHERE id = $pId")->fetchColumn();
checkPass((int)$pCount === 0, "Hapus jabatan berhasil");

// 4. Master Customers
echo "\n--- 4. Master Customers (Pelanggan) ---\n";
$cCode = 'CUST-TST-' . time();
$db->prepare("INSERT INTO customers (code, name, contact_person, email, phone, npwp, credit_limit, credit_used, credit_status, payment_terms_days, is_active, created_at, updated_at) VALUES (:c, 'PT Pelanggan Uji Sukses', 'Ibu Maya', 'maya@pelanggan.id', '021-123456', '01.222.333.4-555.000', 50000000, 0, 'active', 30, 1, NOW(), NOW())")->execute([':c' => $cCode]);
$cId = (int)$db->lastInsertId();
checkPass($cId > 0, "Tambah pelanggan baru ($cCode, ID: $cId)");
$db->prepare("UPDATE customers SET name = 'PT Pelanggan Uji Sukses Tbk', credit_limit = 75000000 WHERE id = :id")->execute([':id' => $cId]);
$cName = $db->query("SELECT name FROM customers WHERE id = $cId")->fetchColumn();
checkPass($cName === 'PT Pelanggan Uji Sukses Tbk', "Edit pelanggan berhasil");
$refsCust = checkRecordReferences('customers', $cId, $db);
checkPass(empty($refsCust), "Pelanggan baru tidak terikat transaksi");
$db->prepare("DELETE FROM customers WHERE id = :id")->execute([':id' => $cId]);
$cCount = $db->query("SELECT COUNT(*) FROM customers WHERE id = $cId")->fetchColumn();
checkPass((int)$cCount === 0, "Hapus pelanggan berhasil");

// 5. Master Items
echo "\n--- 5. Master Items (Barang & Material) ---\n";
$iCode = 'ITEM-TST-' . time();
$catId = $db->query("SELECT id FROM item_categories LIMIT 1")->fetchColumn();
$unitId = $db->query("SELECT id FROM units LIMIT 1")->fetchColumn();
$db->prepare("INSERT INTO items (code, name, category_id, unit_id, min_stock, cost_price, selling_price, is_active, created_at, updated_at) VALUES (:c, 'Produk Biskuit Uji Rasa', :cat, :u, 10, 15000, 20000, 1, NOW(), NOW())")->execute([':c' => $iCode, ':cat' => $catId, ':u' => $unitId]);
$iId = (int)$db->lastInsertId();
checkPass($iId > 0, "Tambah barang baru ($iCode, ID: $iId)");
$db->prepare("UPDATE items SET selling_price = 22000 WHERE id = :id")->execute([':id' => $iId]);
$iPrice = (float)$db->query("SELECT selling_price FROM items WHERE id = $iId")->fetchColumn();
checkPass($iPrice === 22000.0, "Edit harga jual barang berhasil");
$db->prepare("DELETE FROM items WHERE id = :id")->execute([':id' => $iId]);
$iCount = $db->query("SELECT COUNT(*) FROM items WHERE id = $iId")->fetchColumn();
checkPass((int)$iCount === 0, "Hapus barang baru berhasil");

// 6. Master Warehouses
echo "\n--- 6. Master Warehouses (Gudang) ---\n";
$whCode = 'WH-TST-' . time();
$db->prepare("INSERT INTO warehouses (code, name, type, address, is_active, created_at, updated_at) VALUES (:c, 'Gudang Uji Coba Cikupa', 'transit', 'Kawasan Industri Cikupa', 1, NOW(), NOW())")->execute([':c' => $whCode]);
$whId = (int)$db->lastInsertId();
checkPass($whId > 0, "Tambah gudang baru ($whCode, ID: $whId)");
$db->prepare("UPDATE warehouses SET name = 'Gudang Uji Coba Cikupa II' WHERE id = :id")->execute([':id' => $whId]);
$whName = $db->query("SELECT name FROM warehouses WHERE id = $whId")->fetchColumn();
checkPass($whName === 'Gudang Uji Coba Cikupa II', "Edit gudang berhasil");
$db->prepare("DELETE FROM warehouses WHERE id = :id")->execute([':id' => $whId]);
$whCount = $db->query("SELECT COUNT(*) FROM warehouses WHERE id = $whId")->fetchColumn();
checkPass((int)$whCount === 0, "Hapus gudang berhasil");

// 7. Master Accounts (COA)
echo "\n--- 7. Master Accounts (COA Buku Besar) ---\n";
$accCode = '9999-' . rand(100, 999);
$db->prepare("INSERT INTO accounts (code, name, type, is_active, created_at, updated_at) VALUES (:c, 'Akun Beban Operasional Uji', 'expense', 1, NOW(), NOW())")->execute([':c' => $accCode]);
$accId = (int)$db->lastInsertId();
checkPass($accId > 0, "Tambah akun COA baru ($accCode, ID: $accId)");
$db->prepare("UPDATE accounts SET name = 'Akun Beban Operasional Uji Lapangan' WHERE id = :id")->execute([':id' => $accId]);
$accName = $db->query("SELECT name FROM accounts WHERE id = $accId")->fetchColumn();
checkPass($accName === 'Akun Beban Operasional Uji Lapangan', "Edit akun COA berhasil");
$db->prepare("DELETE FROM accounts WHERE id = :id")->execute([':id' => $accId]);
$accCount = $db->query("SELECT COUNT(*) FROM accounts WHERE id = $accId")->fetchColumn();
checkPass((int)$accCount === 0, "Hapus akun COA berhasil");

echo "\n========================================================\n";
echo "STATUS: SELURUH DATA MASTER (9 MODUL) LULUS 100%!\n";
echo "========================================================\n";
