<?php
/**
 * Test Suite: Master Vendor (Suppliers) Full Functional & CRUD Test
 * Tests: List, Create, Read/Detail, Update, Toggle Status, Reference Blocker, Hard Delete
 */

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/auth.php';

$db = getDbConnection();

echo "========================================================\n";
echo "MASTER VENDOR (SUPPLIERS) CRUD & INTEGRATION TEST\n";
echo "========================================================\n\n";

function assertOk($cond, $msg) {
    if (!$cond) {
        echo "[FAIL] $msg\n";
        exit(1);
    }
    echo "[PASS] $msg\n";
}

// 1. Check existing suppliers
echo "--- 1. Memeriksa Data Vendor Eksisting ---\n";
$stmt = $db->query("SELECT * FROM suppliers ORDER BY id ASC");
$existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
assertOk(count($existing) >= 5, "Ditemukan " . count($existing) . " vendor di database");

// 2. Test Create Vendor
echo "\n--- 2. Menguji Tambah Vendor Baru ---\n";
$testCode = 'SUPP-TEST-' . time();
$db->prepare("
    INSERT INTO suppliers 
    (code, name, contact_person, email, phone, address, npwp, payment_terms_days, bank_name, bank_account, bank_account_holder, is_active, created_at, updated_at)
    VALUES 
    (:code, 'PT Vendor Uji Sukses Mandiri', 'Bpk. Hendra Gunawan', 'hendra@vendortest.co.id', '021-889977', 'Jl. Industri Manufaktur No. 99, Tangerang', '01.999.888.7-412.000', 45, 'Bank BCA', '8880192837', 'PT Vendor Uji Sukses Mandiri', 1, NOW(), NOW())
")->execute([':code' => $testCode]);
$newSuppId = (int)$db->lastInsertId();
assertOk($newSuppId > 0, "Vendor baru berhasil ditambahkan (ID: $newSuppId, Code: $testCode)");

// Verify newly created vendor data
$stmtNew = $db->prepare("SELECT * FROM suppliers WHERE id = :id");
$stmtNew->execute([':id' => $newSuppId]);
$newSupp = $stmtNew->fetch(PDO::FETCH_ASSOC);
assertOk($newSupp['name'] === 'PT Vendor Uji Sukses Mandiri', "Nama vendor tersimpan sesuai input");
assertOk($newSupp['npwp'] === '01.999.888.7-412.000', "NPWP vendor tersimpan");
assertOk($newSupp['bank_name'] === 'Bank BCA', "Nama Bank vendor tersimpan");
assertOk($newSupp['bank_account'] === '8880192837', "Nomor rekening vendor tersimpan");
assertOk((int)$newSupp['payment_terms_days'] === 45, "Termin pembayaran (TOP 45 hari) tersimpan");
assertOk((int)$newSupp['is_active'] === 1, "Status vendor aktif secara default");

// 3. Test Detail Vendor View & Relations
echo "\n--- 3. Menguji Rincian Detail Vendor & Relasi ---\n";
$stmtD = $db->prepare("
    SELECT s.*,
           (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.id) AS po_count,
           (SELECT COUNT(*) FROM goods_receipts gr WHERE gr.supplier_id = s.id) AS gr_count,
           (SELECT COUNT(*) FROM supplier_invoices si WHERE si.supplier_id = s.id) AS inv_count,
           (SELECT COUNT(*) FROM supplier_payments sp WHERE sp.supplier_id = s.id) AS pay_count,
           (SELECT COUNT(*) FROM supplier_returns sr WHERE sr.supplier_id = s.id) AS ret_count
    FROM suppliers s
    WHERE s.id = :id
");
$stmtD->execute([':id' => $newSuppId]);
$detail = $stmtD->fetch(PDO::FETCH_ASSOC);
assertOk($detail['id'] == $newSuppId, "Detail vendor ID $newSuppId berhasil diakses");
assertOk((int)$detail['po_count'] === 0, "Vendor baru belum memiliki PO (po_count = 0)");

// 4. Test Edit / Update Vendor
echo "\n--- 4. Menguji Edit & Pembaruan Data Vendor ---\n";
$db->prepare("
    UPDATE suppliers 
    SET name = 'PT Vendor Uji Sukses Mandiri (Tbk)', contact_person = 'Ibu Siti Rahmawati', phone = '081299988877', payment_terms_days = 60, updated_at = NOW()
    WHERE id = :id
")->execute([':id' => $newSuppId]);

$stmtUpd = $db->prepare("SELECT * FROM suppliers WHERE id = :id");
$stmtUpd->execute([':id' => $newSuppId]);
$updated = $stmtUpd->fetch(PDO::FETCH_ASSOC);
assertOk($updated['name'] === 'PT Vendor Uji Sukses Mandiri (Tbk)', "Nama vendor berhasil diubah");
assertOk($updated['contact_person'] === 'Ibu Siti Rahmawati', "Kontak person berhasil diubah");
assertOk((int)$updated['payment_terms_days'] === 60, "Termin pembayaran berhasil diubah menjadi 60 hari");

// 5. Test Toggle Status (Deactivate & Activate)
echo "\n--- 5. Menguji Nonaktifkan & Aktifkan Vendor ---\n";
$db->prepare("UPDATE suppliers SET is_active = 0, updated_at = NOW() WHERE id = :id")->execute([':id' => $newSuppId]);
$st1 = (int)$db->query("SELECT is_active FROM suppliers WHERE id = $newSuppId")->fetchColumn();
assertOk($st1 === 0, "Vendor berhasil dinonaktifkan (is_active = 0)");

$db->prepare("UPDATE suppliers SET is_active = 1, updated_at = NOW() WHERE id = :id")->execute([':id' => $newSuppId]);
$st2 = (int)$db->query("SELECT is_active FROM suppliers WHERE id = $newSuppId")->fetchColumn();
assertOk($st2 === 1, "Vendor berhasil diaktifkan kembali (is_active = 1)");

// 6. Test Delete Protection (Referenced Supplier)
echo "\n--- 6. Menguji Proteksi Integritas Relasi (checkRecordReferences) ---\n";
// Vendor 1 (PT Bogasari Flour Mills) has existing PO/GR/Invoices in demo data
$refsVendor1 = checkRecordReferences('suppliers', 1, $db);
assertOk(!empty($refsVendor1), "Vendor #1 terdeteksi memiliki keterkaitan transaksi: " . implode(', ', $refsVendor1));

// 7. Test Safe Delete (Unreferenced Supplier)
echo "\n--- 7. Menguji Penghapusan Permanen Vendor Tanpa Transaksi ---\n";
$refsNewVendor = checkRecordReferences('suppliers', $newSuppId, $db);
assertOk(empty($refsNewVendor), "Vendor baru bersih dari relasi transaksi");

$db->prepare("DELETE FROM suppliers WHERE id = :id")->execute([':id' => $newSuppId]);
$checkDeleted = $db->query("SELECT COUNT(*) FROM suppliers WHERE id = $newSuppId")->fetchColumn();
assertOk((int)$checkDeleted === 0, "Vendor uji berhasil dihapus permanen dari database");

echo "\n========================================================\n";
echo "STATUS: SELURUH PENGUJIAN MASTER VENDOR LULUS 100%!\n";
echo "========================================================\n";
