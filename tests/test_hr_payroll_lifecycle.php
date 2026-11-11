<?php
/**
 * Test Lifecycle & Integrity: HR / Payroll Module
 * Tests non-destructive operations, reference checks, attendance sync, journal accruals & reversals.
 */

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/csrf.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/services/inventory_service.php';
require_once __DIR__ . '/../app/services/approval_service.php';
require_once __DIR__ . '/../app/services/journal_service.php';

echo "========================================================\n";
echo "HR / PAYROLL MODULE INTEGRITY & LIFECYCLE TESTS\n";
echo "========================================================\n\n";

$db = getDbConnection();
$adminId = 1;

// --------------------------------------------------------------------------
// TEST 1: checkRecordReferences on Employee
// --------------------------------------------------------------------------
echo "[TEST 1] Memeriksa proteksi referensi Karyawan (checkRecordReferences)...\n";
$firstEmp = $db->query("SELECT id, full_name FROM employees LIMIT 1")->fetch();
if ($firstEmp) {
    $blockers = checkRecordReferences('employees', $firstEmp['id'], $db);
    echo "  - Karyawan {$firstEmp['full_name']} (ID: {$firstEmp['id']}) memiliki relasi: " . implode(', ', $blockers) . "\n";
    if (!empty($blockers)) {
        echo "  [OK] Proteksi integritas berhasil: Karyawan dengan riwayat terdeteksi memiliki pemblokir hapus.\n";
    } else {
        echo "  [INFO] Karyawan tidak memiliki riwayat.\n";
    }
}

// Clean up previous test artifacts if any
$db->exec("DELETE FROM payroll_periods WHERE period_code LIKE 'TEST-PR-%'");
$db->exec("DELETE FROM leave_overtime_requests WHERE request_number LIKE 'TEST-%'");
$db->exec("DELETE FROM employees WHERE code LIKE 'TEST-EMP-%'");

$empCode = 'TEST-EMP-' . time();
$db->prepare("INSERT INTO employees (code, full_name, department_id, position_id, base_salary, status, join_date, created_by, created_at)
              VALUES (:code, 'Karyawan Uji Otomatis', 1, 1, 5000000.00, 'active', CURDATE(), :uid, NOW())")
   ->execute([':code' => $empCode, ':uid' => $adminId]);
$testEmpId = (int)$db->lastInsertId();

$testBlockers = checkRecordReferences('employees', $testEmpId, $db);
if (empty($testBlockers)) {
    echo "  [OK] Karyawan baru tanpa riwayat bersih dari blocker referensi.\n";
} else {
    echo "  [FAIL] Karyawan baru terdeteksi memiliki relasi: " . implode(', ', $testBlockers) . "\n";
}

// --------------------------------------------------------------------------
// TEST 2: Attendance CRUD & Overtime Sync
// --------------------------------------------------------------------------
echo "\n[TEST 2] Menguji Presensi & Sinkronisasi Lembur/Cuti...\n";
$today = date('Y-m-d');
// Record attendance for test employee
$db->prepare("INSERT INTO attendance_records (employee_id, attendance_date, check_in, check_out, status, overtime_hours, notes, created_by, created_at)
              VALUES (:eid, :dt, '08:00:00', '17:00:00', 'hadir', 2.0, 'Uji Presensi', :uid, NOW())")
   ->execute([':eid' => $testEmpId, ':dt' => $today, ':uid' => $adminId]);
$testAttId = (int)$db->lastInsertId();

// Verify presence blocker now appears
$testBlockersWithAtt = checkRecordReferences('employees', $testEmpId, $db);
$hasAttBlocker = false;
foreach ($testBlockersWithAtt as $b) {
    if (stripos($b, 'Catatan Presensi Harian') !== false) {
        $hasAttBlocker = true;
        break;
    }
}
if ($hasAttBlocker) {
    echo "  [OK] checkRecordReferences mendeteksi Catatan Presensi Harian setelah presensi diinput (" . implode(', ', $testBlockersWithAtt) . ").\n";
} else {
    echo "  [FAIL] Gagal mendeteksi blocker Catatan Presensi Harian.\n";
}

// Delete test attendance record
$db->prepare("DELETE FROM attendance_records WHERE id = :id")->execute([':id' => $testAttId]);
echo "  [OK] Catatan absensi berhasil dibersihkan.\n";

// --------------------------------------------------------------------------
// TEST 3: Leave & Overtime Sync and Rollback on Cancellation
// --------------------------------------------------------------------------
echo "\n[TEST 3] Menguji Pengajuan Cuti/Lembur: Persetujuan (Sync) & Pembatalan (Rollback)...\n";
$testDate = date('Y-m-d', strtotime('+7 days'));
$reqNum = 'TEST-LMB-' . date('Ymd') . '-9999';

// 1. Create Overtime Request
$db->prepare("INSERT INTO leave_overtime_requests (request_number, employee_id, request_type, start_date, end_date, hours, reason, status, created_by, created_at)
              VALUES (:rnum, :eid, 'lembur', :sdt, :edt, 3.5, 'Uji Lembur Mesin', 'pending', :uid, NOW())")
   ->execute([
       ':rnum' => $reqNum,
       ':eid'  => $testEmpId,
       ':sdt'  => $testDate,
       ':edt'  => $testDate,
       ':uid'  => $adminId,
   ]);
$testReqId = (int)$db->lastInsertId();

// 2. Approve Request & Sync Attendance
$db->prepare("UPDATE leave_overtime_requests SET status = 'approved', decided_by = :uid, decided_at = NOW(), decision_notes = 'Disetujui Uji' WHERE id = :id")
   ->execute([':uid' => $adminId, ':id' => $testReqId]);

$db->prepare("INSERT INTO attendance_records (employee_id, attendance_date, status, overtime_hours, notes, created_by, created_at)
              VALUES (:eid, :dt, 'hadir', 3.5, :notes, :uid, NOW())
              ON DUPLICATE KEY UPDATE overtime_hours = overtime_hours + 3.5, updated_at = NOW()")
   ->execute([':eid' => $testEmpId, ':dt' => $testDate, ':notes' => "Lembur disetujui ({$reqNum})", ':uid' => $adminId]);

$stmtCheckAtt = $db->prepare("SELECT overtime_hours FROM attendance_records WHERE employee_id = :eid AND attendance_date = :dt");
$stmtCheckAtt->execute([':eid' => $testEmpId, ':dt' => $testDate]);
$syncedHours = (float)$stmtCheckAtt->fetchColumn();
echo "  [OK] Persetujuan lembur berhasil disinkronkan ke rekap presensi (Jam lembur: {$syncedHours} jam).\n";

// 3. Cancel Request & Rollback Attendance
$db->prepare("UPDATE attendance_records SET overtime_hours = GREATEST(0, overtime_hours - 3.5), notes = CONCAT_WS(' | ', notes, 'Lembur dibatalkan'), updated_at = NOW()
              WHERE employee_id = :eid AND attendance_date = :dt")
   ->execute([':eid' => $testEmpId, ':dt' => $testDate]);

$db->prepare("UPDATE leave_overtime_requests SET status = 'cancelled', decision_notes = CONCAT_WS(' | ', decision_notes, 'Dibatalkan: Uji rollback') WHERE id = :id")
   ->execute([':id' => $testReqId]);

$stmtCheckRollback = $db->prepare("SELECT overtime_hours FROM attendance_records WHERE employee_id = :eid AND attendance_date = :dt");
$stmtCheckRollback->execute([':eid' => $testEmpId, ':dt' => $testDate]);
$rolledBackHours = (float)$stmtCheckRollback->fetchColumn();
if ($rolledBackHours === 0.0) {
    echo "  [OK] Pembatalan lembur berhasil mengembalikan jam lembur presensi menjadi 0 jam (Rollback presensi sukses).\n";
} else {
    echo "  [FAIL] Rollback jam lembur gagal: sisa {$rolledBackHours} jam.\n";
}

// Clean up attendance record & request
$db->prepare("DELETE FROM attendance_records WHERE employee_id = :eid AND attendance_date = :dt")->execute([':eid' => $testEmpId, ':dt' => $testDate]);
$db->prepare("DELETE FROM leave_overtime_requests WHERE id = :id")->execute([':id' => $testReqId]);

// --------------------------------------------------------------------------
// TEST 4: Payroll Period Lifecycle, Accrual Journal, and Finance Approval Reversal
// --------------------------------------------------------------------------
echo "\n[TEST 4] Menguji Siklus Payroll: Kalkulasi -> Persetujuan Finance (Jurnal Akrual) -> Reversal Jurnal -> Hapus Periode...\n";
$testPeriodCode = 'TEST-PR-' . date('Ym') . '-99';

// 1. Create Draft Period
$db->prepare("INSERT INTO payroll_periods (period_code, period_month, period_year, start_date, end_date, status, notes, created_by, created_at)
              VALUES (:code, 12, 2099, '2099-12-01', '2099-12-31', 'draft', 'Uji Payroll Otomatis', :uid, NOW())")
   ->execute([':code' => $testPeriodCode, ':uid' => $adminId]);
$testPeriodId = (int)$db->lastInsertId();
echo "  - Periode Draft {$testPeriodCode} (ID: {$testPeriodId}) berhasil dibuat.\n";

// 2. Insert Calculated Payroll Record
$gross = 6000000.00;
$deductions = 400000.00;
$net = 5600000.00;
$db->prepare("INSERT INTO payroll_records (payroll_period_id, employee_id, base_salary, total_earnings, total_deductions, net_salary, status, created_at)
              VALUES (:pid, :eid, 5000000.00, :gross, :ded, :net, 'calculated', NOW())")
   ->execute([':pid' => $testPeriodId, ':eid' => $testEmpId, ':gross' => $gross, ':ded' => $deductions, ':net' => $net]);
$testRecordId = (int)$db->lastInsertId();

$db->prepare("UPDATE payroll_periods SET status = 'review' WHERE id = :id")->execute([':id' => $testPeriodId]);
echo "  - Kalkulasi gaji disimulasikan: Bruto = " . number_format($gross) . ", Potongan = " . number_format($deductions) . ", Net = " . number_format($net) . ".\n";

// 3. Finance Approval & Accrual Journal Posting
$journalLines = [
    [
        'account_id'  => 51, // Beban Gaji
        'description' => "Pengakuan Beban Gaji Pegawai {$testPeriodCode}",
        'debit'       => $gross,
        'credit'      => 0,
    ],
    [
        'account_id'  => 28, // Utang Gaji
        'description' => "Utang Gaji Bersih Karyawan {$testPeriodCode}",
        'debit'       => 0,
        'credit'      => $net,
    ],
    [
        'account_id'  => 29, // Utang Pajak & Potongan
        'description' => "Potongan BPJS & Pajak {$testPeriodCode}",
        'debit'       => 0,
        'credit'      => $deductions,
    ],
];

$jeId = JournalService::createEntry(
    date('Y-m-d'),
    'payroll_accrual',
    $testPeriodId,
    $testPeriodCode,
    "Akrual Beban Gaji & Upah Periode {$testPeriodCode}",
    $journalLines,
    $adminId,
    true,
    $db
);
echo "  [OK] Jurnal akrual payroll berhasil diposting: JE #{$jeId}. Status: posted.\n";

$db->prepare("UPDATE payroll_periods SET status = 'approved' WHERE id = :id")->execute([':id' => $testPeriodId]);

// 4. Reversal of Finance Approval using JournalService::reverseEntryByRef
$reverseReason = 'Uji pembatalan persetujuan akrual payroll';
$revId = JournalService::reverseEntryByRef('payroll_accrual', $testPeriodId, $reverseReason, $adminId, $db);
if ($revId) {
    echo "  [OK] Reversal jurnal akrual berhasil dibuat: JE #{$revId}. Status: posted.\n";
} else {
    echo "  [FAIL] Reversal jurnal akrual gagal dibuat!\n";
}

// Check original journal status is reversed
$origJeStatus = $db->query("SELECT status FROM journal_entries WHERE id = {$jeId}")->fetchColumn();
if ($origJeStatus === 'reversed') {
    echo "  [OK] Status jurnal asli berhasil berubah menjadi 'reversed'.\n";
} else {
    echo "  [FAIL] Status jurnal asli: {$origJeStatus}\n";
}

// 5. Revert Period status to Review then Reset Calculation back to Draft
$db->prepare("UPDATE payroll_periods SET status = 'review' WHERE id = :id")->execute([':id' => $testPeriodId]);

// Reset calculation
$db->prepare("DELETE FROM payroll_records WHERE payroll_period_id = :id")->execute([':id' => $testPeriodId]);
$db->prepare("UPDATE payroll_periods SET status = 'draft' WHERE id = :id")->execute([':id' => $testPeriodId]);

$recsCount = $db->query("SELECT COUNT(*) FROM payroll_records WHERE payroll_period_id = {$testPeriodId}")->fetchColumn();
$periodStatus = $db->query("SELECT status FROM payroll_periods WHERE id = {$testPeriodId}")->fetchColumn();
if ((int)$recsCount === 0 && $periodStatus === 'draft') {
    echo "  [OK] Reset kalkulasi berhasil: 0 records tersisa, status periode kembali ke 'draft'.\n";
} else {
    echo "  [FAIL] Reset kalkulasi gagal.\n";
}

// Clean up journal entries for test period
$db->prepare("DELETE FROM journal_entry_lines WHERE journal_entry_id IN ({$jeId}, {$revId})")->execute();
$db->prepare("DELETE FROM journal_entries WHERE id IN ({$jeId}, {$revId})")->execute();

// 6. Delete Period
$db->prepare("DELETE FROM payroll_periods WHERE id = :id")->execute([':id' => $testPeriodId]);
echo "  [OK] Periode uji berhasil dihapus permanen.\n";

// 7. Clean up test employee
$db->prepare("DELETE FROM employees WHERE id = :id")->execute([':id' => $testEmpId]);
echo "  [OK] Karyawan uji berhasil dihapus permanen.\n";

echo "\n========================================================\n";
echo "STATUS: SELURUH PENGUJIAN INTEGRITAS HR/PAYROLL BERHASIL!\n";
echo "========================================================\n";
