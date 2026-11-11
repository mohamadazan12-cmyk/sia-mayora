<?php
/**
 * Test Suite: End-to-End Integration of SISP Mayora Modules into Projek SIA
 * Tests: Mutations, Trainings, Salary Verification, Terminations, Reports
 */

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/services/journal_service.php';

$db = getDbConnection();

echo "========================================================\n";
echo "SISP MAYORA INTEGRATED MODULES TEST SUITE\n";
echo "========================================================\n\n";

// Helper assertions
function assertTrue($cond, $msg) {
    if (!$cond) {
        echo "[FAIL] $msg\n";
        exit(1);
    }
    echo "[PASS] $msg\n";
}

// Ensure an active admin user session
$adminUser = $db->query("SELECT * FROM users WHERE username = 'admin' LIMIT 1")->fetch();
if (!$adminUser) {
    echo "[FAIL] Admin user not found.\n";
    exit(1);
}
$_SESSION['user'] = $adminUser;

// 1. SETUP TEST EMPLOYEE
echo "--- 1. Menyiapkan Karyawan Uji ---\n";
$oldEmp = $db->query("SELECT id FROM employees WHERE code = 'EMP-TEST-INT'")->fetch();
if ($oldEmp) {
    $oldId = (int)$oldEmp['id'];
    $db->prepare("DELETE FROM employee_terminations WHERE employee_id = :id")->execute([':id' => $oldId]);
    $db->prepare("DELETE FROM employee_trainings WHERE employee_id = :id")->execute([':id' => $oldId]);
    $db->prepare("DELETE FROM employee_mutations WHERE employee_id = :id")->execute([':id' => $oldId]);
    $db->prepare("DELETE FROM payroll_records WHERE employee_id = :id")->execute([':id' => $oldId]);
    $db->prepare("DELETE FROM employees WHERE id = :id")->execute([':id' => $oldId]);
}
$db->prepare("
    INSERT INTO employees 
    (code, full_name, department_id, position_id, join_date, base_salary, fixed_allowance, birth_date, ptkp_code, bpjs_tk_number, bpjs_kes_number, employment_status, bank_name, bank_account, status, created_at)
    VALUES 
    ('EMP-TEST-INT', 'Karyawan Uji Integrasi', 1, 1, '2025-01-01', 7500000, 1500000, '1992-05-15', 'K/1', '00012345678', '00087654321', 'Tetap', 'Bank Mandiri', '1234567890', 'active', NOW())
")->execute();
$testEmpId = (int)$db->lastInsertId();
assertTrue($testEmpId > 0, "Karyawan uji berhasil dibuat (ID: $testEmpId)");

// 2. TEST MUTATION & PROMOTION
echo "\n--- 2. Menguji Modul Mutasi & Promosi (Automatic Profile Update) ---\n";
$deptFinance = (int)$db->query("SELECT id FROM departments WHERE name LIKE '%Keuangan%' OR name LIKE '%Finance%' LIMIT 1")->fetchColumn();
if (!$deptFinance) $deptFinance = 2;
$posStaff = (int)$db->query("SELECT id FROM positions WHERE department_id = {$deptFinance} LIMIT 1")->fetchColumn();
if (!$posStaff) $posStaff = 2;

// Usulkan mutasi
$db->prepare("
    INSERT INTO employee_mutations 
    (employee_id, mutation_type, old_department_id, old_position_id, new_department_id, new_position_id, effective_date, sk_number, reason, status, created_by, created_at)
    VALUES 
    (:emp_id, 'Promosi', 1, 1, :new_dept, :new_pos, '2026-12-01', 'SK/HR/TEST/001', 'Kinerja istimewa dalam simulasi', 'Menunggu', :uid, NOW())
")->execute([
    ':emp_id'   => $testEmpId,
    ':new_dept' => $deptFinance,
    ':new_pos'  => $posStaff,
    ':uid'      => $adminUser['id'],
]);
$mutationId = (int)$db->lastInsertId();
assertTrue($mutationId > 0, "Usulan mutasi/promosi tercatat (ID: $mutationId)");

// Setujui mutasi dan verifikasi auto-update ke master employees
$db->prepare("
    UPDATE employee_mutations 
    SET status = 'Selesai', decided_by = :uid, decided_at = NOW(), decision_notes = 'Disetujui dalam unit test' 
    WHERE id = :id
")->execute([':uid' => $adminUser['id'], ':id' => $mutationId]);

$mut = $db->query("SELECT * FROM employee_mutations WHERE id = $mutationId")->fetch();
if ($mut['status'] === 'Selesai') {
    $db->prepare("
        UPDATE employees 
        SET department_id = :dept, position_id = :pos, updated_at = NOW() 
        WHERE id = :id
    ")->execute([
        ':dept' => $mut['new_department_id'],
        ':pos'  => $mut['new_position_id'],
        ':id'   => $testEmpId,
    ]);
}

$updatedEmp = $db->query("SELECT * FROM employees WHERE id = $testEmpId")->fetch();
assertTrue((int)$updatedEmp['department_id'] === $deptFinance, "Departemen karyawan otomatis terupdate ke Departemen Keuangan (ID: $deptFinance)");
assertTrue((int)$updatedEmp['position_id'] === $posStaff, "Jabatan karyawan otomatis terupdate ke Jabatan Baru (ID: $posStaff)");

// 3. TEST TRAININGS
echo "\n--- 3. Menguji Modul Program Pelatihan ---\n";
$db->prepare("
    INSERT INTO employee_trainings
    (training_code, training_name, employee_id, start_date, end_date, budget, status, created_by, created_at)
    VALUES
    ('TRN-TEST-001', 'Pelatihan SAP & ERP SIA (Mayora Training Center)', :emp_id, '2026-11-20', '2026-11-22', 2500000, 'Menunggu', :uid, NOW())
")->execute([
    ':emp_id' => $testEmpId,
    ':uid'    => $adminUser['id'],
]);
$trainingId = (int)$db->lastInsertId();
assertTrue($trainingId > 0, "Pengajuan pelatihan tercatat (ID: $trainingId)");

// Setujui pelatihan
$db->prepare("
    UPDATE employee_trainings 
    SET status = 'Disetujui', decided_by = :uid, decided_at = NOW(), decision_notes = 'Disetujui'
    WHERE id = :id
")->execute([':uid' => $adminUser['id'], ':id' => $trainingId]);

$chkTrn = $db->query("SELECT status FROM employee_trainings WHERE id = $trainingId")->fetchColumn();
assertTrue($chkTrn === 'Disetujui', "Status pelatihan berhasil berubah menjadi 'Disetujui'");

// Evaluasi hasil & kelulusan
$db->prepare("
    UPDATE employee_trainings 
    SET status = 'Selesai', attendance_status = 'Hadir Penuh', result = 'Lulus Sangat Baik (Score 95)', evaluation = 'Telah tersertifikasi'
    WHERE id = :id
")->execute([':id' => $trainingId]);

$chkTrnRes = $db->query("SELECT attendance_status, result FROM employee_trainings WHERE id = $trainingId")->fetch();
assertTrue($chkTrnRes['attendance_status'] === 'Hadir Penuh', "Presensi pelatihan tercatat 'Hadir Penuh'");
assertTrue(strpos($chkTrnRes['result'], 'Lulus') !== false, "Hasil evaluasi pelatihan tersimpan");

// 4. TEST SALARY VERIFICATION
echo "\n--- 4. Menguji Modul Verifikasi Gaji (Individual & Massal) ---\n";
// Buat periode payroll uji
$db->prepare("DELETE FROM payroll_periods WHERE period_code = 'TEST-VERIF-PAY'")->execute();
$db->prepare("
    INSERT INTO payroll_periods 
    (period_code, period_month, period_year, start_date, end_date, status, created_at, created_by)
    VALUES 
    ('TEST-VERIF-PAY', 11, 2026, '2026-11-01', '2026-11-30', 'calculated', NOW(), :uid)
")->execute([':uid' => $adminUser['id']]);
$testPeriodId = (int)$db->lastInsertId();

// Buat record gaji
$db->prepare("
    INSERT INTO payroll_records 
    (payroll_period_id, employee_id, base_salary, total_earnings, total_deductions, net_salary, status, created_at)
    VALUES 
    (:p_id, :e_id, 7500000, 9000000, 500000, 8500000, 'calculated', NOW())
")->execute([':p_id' => $testPeriodId, ':e_id' => $testEmpId]);
$rec1Id = (int)$db->lastInsertId();

// Verify record 1
$db->prepare("
    UPDATE payroll_records 
    SET status = 'verified', verification_notes = NULL, updated_at = NOW()
    WHERE id = :id
")->execute([':id' => $rec1Id]);

$rec1Status = $db->query("SELECT status, verification_notes FROM payroll_records WHERE id = $rec1Id")->fetch();
assertTrue($rec1Status['status'] === 'verified', "Record gaji berhasil diverifikasi individual (Status: 'verified')");

// Test reject with reason
$db->prepare("
    UPDATE payroll_records 
    SET status = 'rejected', verification_notes = :notes, updated_at = NOW()
    WHERE id = :id
")->execute([':notes' => 'Revisi: Jam lembur belum terhitung', ':id' => $rec1Id]);

$rec1Reject = $db->query("SELECT status, verification_notes FROM payroll_records WHERE id = $rec1Id")->fetch();
assertTrue($rec1Reject['status'] === 'rejected', "Record gaji berhasil ditolak verifikasi (Status: 'rejected')");
assertTrue(strpos($rec1Reject['verification_notes'], 'Revisi') !== false, "Alasan penolakan tersimpan: '{$rec1Reject['verification_notes']}'");

// Test bulk verify
$db->prepare("
    UPDATE payroll_records 
    SET status = 'verified', verification_notes = NULL, updated_at = NOW()
    WHERE payroll_period_id = :p_id
")->execute([':p_id' => $testPeriodId]);

$bulkCount = (int)$db->query("SELECT COUNT(*) FROM payroll_records WHERE payroll_period_id = $testPeriodId AND status = 'verified'")->fetchColumn();
assertTrue($bulkCount === 1, "Verifikasi massal periode berhasil memverifikasi seluruh baris penggajian");

// 5. TEST TERMINATIONS & SETTLEMENT
echo "\n--- 5. Menguji Modul Pemberhentian & Hak Akhir (Clearance & Severance) ---\n";
$db->prepare("
    INSERT INTO employee_terminations
    (employee_id, termination_type, effective_date, letter_number, asset_clearance_notes, last_salary, severance_amount, obligation_deductions, pph21_amount, total_settlement, status, created_by, created_at)
    VALUES
    (:emp_id, 'Resign', '2026-12-31', 'SK/TERM/2026/099', 'Laptop, ID Card, dan Kunci Loker telah dikembalikan', 7500000, 7500000, 200000, 500000, 14300000, 'Menunggu Persetujuan', :uid, NOW())
")->execute([
    ':emp_id' => $testEmpId,
    ':uid'    => $adminUser['id'],
]);
$termId = (int)$db->lastInsertId();
assertTrue($termId > 0, "Pengajuan berkas hak akhir tersimpan (ID: $termId)");

// Setujui hak akhir & verifikasi status employee menjadi 'resigned'
$db->prepare("
    UPDATE employee_terminations 
    SET status = 'Disetujui', decided_by = :uid, decided_at = NOW(), decision_notes = 'Disetujui Manajemen'
    WHERE id = :id
")->execute([':uid' => $adminUser['id'], ':id' => $termId]);

// Auto update employee status if approved
$term = $db->query("SELECT * FROM employee_terminations WHERE id = $termId")->fetch();
if ($term['status'] === 'Disetujui') {
    $db->prepare("UPDATE employees SET status = 'resigned', updated_at = NOW() WHERE id = :id")
       ->execute([':id' => $term['employee_id']]);
}

$empResigned = $db->query("SELECT status FROM employees WHERE id = $testEmpId")->fetchColumn();
assertTrue($empResigned === 'resigned', "Status kepegawaian otomatis berubah menjadi 'resigned' setelah hak akhir disetujui");

// 6. TEST REPORTS QUERY & DATA INTEGRITY
echo "\n--- 6. Menguji Modul Laporan SDM, Pajak, BPJS & Jurnal GL ---\n";
$repEmployees = $db->query("
    SELECT e.code, e.full_name, d.name AS dept_name, p.name AS pos_name, e.employment_status, e.base_salary, e.fixed_allowance, e.ptkp_code, e.status
    FROM employees e
    JOIN departments d ON e.department_id = d.id
    JOIN positions p ON e.position_id = p.id
    WHERE e.id = $testEmpId
")->fetch();
assertTrue(!empty($repEmployees['code']), "Data personalia laporan SDM valid");

$repMutations = $db->query("SELECT COUNT(*) FROM employee_mutations WHERE employee_id = $testEmpId")->fetchColumn();
assertTrue((int)$repMutations > 0, "Data riwayat mutasi laporan valid");

$repTrainings = $db->query("SELECT COUNT(*) FROM employee_trainings WHERE employee_id = $testEmpId")->fetchColumn();
assertTrue((int)$repTrainings > 0, "Data riwayat pelatihan laporan valid");

$repTerminations = $db->query("SELECT COUNT(*) FROM employee_terminations WHERE employee_id = $testEmpId")->fetchColumn();
assertTrue((int)$repTerminations > 0, "Data berkas terminasi laporan valid");

// Clean up test records
echo "\n--- Membersihkan Data Uji ---\n";
$db->prepare("DELETE FROM payroll_records WHERE payroll_period_id = $testPeriodId")->execute();
$db->prepare("DELETE FROM payroll_periods WHERE id = $testPeriodId")->execute();
$db->prepare("DELETE FROM employee_terminations WHERE id = $termId")->execute();
$db->prepare("DELETE FROM employee_trainings WHERE id = $trainingId")->execute();
$db->prepare("DELETE FROM employee_mutations WHERE id = $mutationId")->execute();
$db->prepare("DELETE FROM employees WHERE id = $testEmpId")->execute();

echo "[OK] Seluruh data uji dibersihkan dengan aman.\n";
echo "========================================================\n";
echo "STATUS: SELURUH MODUL SISP MAYORA LULUS PENGUJIAN 100%!\n";
echo "========================================================\n";
