<?php
/**
 * Seeding Data Contoh SDM & Penggajian PT Mayora Indah Tbk
 * Memperkaya profil personalia, mutasi, pelatihan, hak akhir, dan daftar gaji
 */

require_once __DIR__ . '/../app/config/database.php';
$pdo = getDbConnection();

$pdo->beginTransaction();

try {
    echo "1. Memperbarui profil personalia 9 karyawan...\n";
    $empProfiles = [
        1 => ['birth' => '1982-05-14', 'allowance' => 3500000, 'ptkp' => 'K/2', 'tk' => 'TK001928301', 'kes' => 'KS008172601', 'status' => 'Tetap'],
        2 => ['birth' => '1988-11-20', 'allowance' => 1200000, 'ptkp' => 'K/1', 'tk' => 'TK001928302', 'kes' => 'KS008172602', 'status' => 'Tetap'],
        3 => ['birth' => '1992-03-15', 'allowance' => 1000000, 'ptkp' => 'TK/0', 'tk' => 'TK001928303', 'kes' => 'KS008172603', 'status' => 'Tetap'],
        4 => ['birth' => '1986-07-28', 'allowance' => 1500000, 'ptkp' => 'K/1', 'tk' => 'TK001928304', 'kes' => 'KS008172604', 'status' => 'Tetap'],
        5 => ['birth' => '1993-09-10', 'allowance' => 1200000, 'ptkp' => 'TK/0', 'tk' => 'TK001928305', 'kes' => 'KS008172605', 'status' => 'Tetap'],
        6 => ['birth' => '1989-12-05', 'allowance' => 1200000, 'ptkp' => 'K/2', 'tk' => 'TK001928306', 'kes' => 'KS008172606', 'status' => 'Tetap'],
        7 => ['birth' => '1984-02-18', 'allowance' => 2000000, 'ptkp' => 'K/3', 'tk' => 'TK001928307', 'kes' => 'KS008172607', 'status' => 'Tetap'],
        8 => ['birth' => '1994-08-22', 'allowance' => 1000000, 'ptkp' => 'TK/0', 'tk' => 'TK001928308', 'kes' => 'KS008172608', 'status' => 'Kontrak'],
        9 => ['birth' => '1995-10-30', 'allowance' => 800000,  'ptkp' => 'TK/0', 'tk' => 'TK001928309', 'kes' => 'KS008172609', 'status' => 'Kontrak'],
    ];

    $stmtUpdEmp = $pdo->prepare("
        UPDATE employees 
        SET birth_date = :birth, fixed_allowance = :allowance, ptkp_code = :ptkp, 
            bpjs_tk_number = :tk, bpjs_kes_number = :kes, employment_status = :status 
        WHERE id = :id
    ");

    foreach ($empProfiles as $id => $p) {
        $stmtUpdEmp->execute([
            ':birth'     => $p['birth'],
            ':allowance' => $p['allowance'],
            ':ptkp'      => $p['ptkp'],
            ':tk'        => $p['tk'],
            ':kes'       => $p['kes'],
            ':status'    => $p['status'],
            ':id'        => $id,
        ]);
    }
    echo "   [OK] Data profil personalia diperbarui.\n";

    echo "2. Memasukkan data Mutasi & Promosi...\n";
    $chkMut = (int)$pdo->query("SELECT COUNT(*) FROM employee_mutations")->fetchColumn();
    if ($chkMut === 0) {
        $mutations = [
            [
                'employee_id' => 2,
                'mutation_type' => 'Promosi',
                'old_department_id' => 2,
                'old_position_id' => 3,
                'new_department_id' => 2,
                'new_position_id' => 2,
                'effective_date' => '2026-12-01',
                'reason' => 'Kinerja produksi memuaskan dan mencapai target efisiensi lini',
                'status' => 'Menunggu',
                'sk_number' => 'SK/DIR/2026/XI/088',
            ],
            [
                'employee_id' => 3,
                'mutation_type' => 'Rotasi',
                'old_department_id' => 3,
                'old_position_id' => 4,
                'new_department_id' => 4,
                'new_position_id' => 8,
                'effective_date' => '2026-12-01',
                'reason' => 'Penyegaran tim logistik & pengadaan bahan baku',
                'status' => 'Menunggu',
                'sk_number' => 'SK/DIR/2026/XI/089',
            ],
            [
                'employee_id' => 4,
                'mutation_type' => 'Promosi',
                'old_department_id' => 1,
                'old_position_id' => 2,
                'new_department_id' => 2,
                'new_position_id' => 2,
                'effective_date' => '2026-09-01',
                'reason' => 'Pengangkatan manajerial operasional pabrik',
                'status' => 'Selesai',
                'sk_number' => 'SK/DIR/2026/IX/012',
            ]
        ];

        $stmtInsMut = $pdo->prepare("
            INSERT INTO employee_mutations 
            (employee_id, mutation_type, old_department_id, old_position_id, new_department_id, new_position_id, effective_date, reason, status, sk_number, created_by, created_at)
            VALUES 
            (:eid, :type, :odept, :opos, :ndept, :npos, :eff, :reason, :st, :sk, 8, NOW())
        ");
        foreach ($mutations as $m) {
            $stmtInsMut->execute([
                ':eid'    => $m['employee_id'],
                ':type'   => $m['mutation_type'],
                ':odept'  => $m['old_department_id'],
                ':opos'   => $m['old_position_id'],
                ':ndept'  => $m['new_department_id'],
                ':npos'   => $m['new_position_id'],
                ':eff'    => $m['effective_date'],
                ':reason' => $m['reason'],
                ':st'     => $m['status'],
                ':sk'     => $m['sk_number'],
            ]);
        }
        echo "   [OK] " . count($mutations) . " usulan mutasi/promosi ditambahkan.\n";
    } else {
        echo "   [INFO] Mutasi sudah ada ({$chkMut} baris).\n";
    }

    echo "3. Memasukkan data Pelatihan Karyawan...\n";
    $chkTrain = (int)$pdo->query("SELECT COUNT(*) FROM employee_trainings")->fetchColumn();
    if ($chkTrain === 0) {
        $trainings = [
            [
                'employee_id' => 2,
                'training_code' => 'PLT-2026-001',
                'training_name' => 'Pelatihan K3 Manufaktur & Keselamatan Operasional Mesin',
                'start_date' => '2026-11-20',
                'end_date' => '2026-11-22',
                'budget' => 4500000.00,
                'status' => 'Menunggu',
                'attendance_status' => 'Belum Dilaksanakan',
            ],
            [
                'employee_id' => 3,
                'training_code' => 'PLT-2026-002',
                'training_name' => 'Leadership & Tata Kelola Logistik Gudang Modern',
                'start_date' => '2026-11-25',
                'end_date' => '2026-11-27',
                'budget' => 3800000.00,
                'status' => 'Menunggu',
                'attendance_status' => 'Belum Dilaksanakan',
            ],
            [
                'employee_id' => 5,
                'training_code' => 'PLT-2026-003',
                'training_name' => 'Perpajakan Korporasi & Pemotongan PPh 21 TER Terkini',
                'start_date' => '2026-10-10',
                'end_date' => '2026-10-12',
                'budget' => 5200000.00,
                'status' => 'Disetujui',
                'attendance_status' => 'Hadir Penuh',
            ],
            [
                'employee_id' => 8,
                'training_code' => 'PLT-2026-004',
                'training_name' => 'Quality Assurance & HACCP Industri Makanan Minuman',
                'start_date' => '2026-09-15',
                'end_date' => '2026-09-18',
                'budget' => 6000000.00,
                'status' => 'Selesai',
                'attendance_status' => 'Hadir Penuh',
            ],
        ];

        $stmtInsTrain = $pdo->prepare("
            INSERT INTO employee_trainings 
            (employee_id, training_code, training_name, start_date, end_date, budget, status, attendance_status, created_by, created_at)
            VALUES 
            (:eid, :code, :name, :sdt, :edt, :bg, :st, :att, 8, NOW())
        ");
        foreach ($trainings as $t) {
            $stmtInsTrain->execute([
                ':eid'  => $t['employee_id'],
                ':code' => $t['training_code'],
                ':name' => $t['training_name'],
                ':sdt'  => $t['start_date'],
                ':edt'  => $t['end_date'],
                ':bg'   => $t['budget'],
                ':st'   => $t['status'],
                ':att'  => $t['attendance_status'],
            ]);
        }
        echo "   [OK] " . count($trainings) . " data pelatihan ditambahkan.\n";
    } else {
        echo "   [INFO] Pelatihan sudah ada ({$chkTrain} baris).\n";
    }

    echo "4. Memasukkan data Pemberhentian & Hak Akhir Karyawan...\n";
    $chkTerm = (int)$pdo->query("SELECT COUNT(*) FROM employee_terminations")->fetchColumn();
    if ($chkTerm === 0) {
        $terms = [
            [
                'employee_id' => 9,
                'termination_type' => 'Resign',
                'letter_number' => 'SR/HRD/2026/XI/004',
                'letter_date' => '2026-11-01',
                'effective_date' => '2026-11-30',
                'asset_clearance_notes' => 'Peralatan kantor, laptop, dan ID card sudah dikembalikan lengkap.',
                'clearance_date' => '2026-11-10',
                'last_salary' => 5500000.00,
                'severance_amount' => 11000000.00,
                'obligation_deductions' => 500000.00,
                'pph21_amount' => 525000.00,
                'total_settlement' => 15475000.00,
                'status' => 'Menunggu Persetujuan',
            ],
            [
                'employee_id' => 7,
                'termination_type' => 'Pensiun',
                'letter_number' => 'SK/HRD/PENSIUN/2026/X/012',
                'letter_date' => '2026-10-15',
                'effective_date' => '2026-12-31',
                'asset_clearance_notes' => 'Kendaraan dinas dan inventaris operasional dalam proses verifikasi tim aset.',
                'clearance_date' => '2026-12-20',
                'last_salary' => 9000000.00,
                'severance_amount' => 36000000.00,
                'obligation_deductions' => 0.00,
                'pph21_amount' => 1800000.00,
                'total_settlement' => 43200000.00,
                'status' => 'Menunggu Persetujuan',
            ]
        ];

        $stmtInsTerm = $pdo->prepare("
            INSERT INTO employee_terminations 
            (employee_id, termination_type, letter_number, letter_date, effective_date, asset_clearance_notes, clearance_date, last_salary, severance_amount, obligation_deductions, pph21_amount, total_settlement, status, created_by, created_at)
            VALUES 
            (:eid, :type, :lnum, :ldt, :eff, :asset, :cldt, :last, :sev, :ob, :pph, :tot, :st, 8, NOW())
        ");
        foreach ($terms as $tm) {
            $stmtInsTerm->execute([
                ':eid'   => $tm['employee_id'],
                ':type'  => $tm['termination_type'],
                ':lnum'  => $tm['letter_number'],
                ':ldt'   => $tm['letter_date'],
                ':eff'   => $tm['effective_date'],
                ':asset' => $tm['asset_clearance_notes'],
                ':cldt'  => $tm['clearance_date'],
                ':last'  => $tm['last_salary'],
                ':sev'   => $tm['severance_amount'],
                ':ob'    => $tm['obligation_deductions'],
                ':pph'   => $tm['pph21_amount'],
                ':tot'   => $tm['total_settlement'],
                ':st'    => $tm['status'],
            ]);
        }
        echo "   [OK] " . count($terms) . " data hak akhir ditambahkan.\n";
    } else {
        echo "   [INFO] Pemberhentian sudah ada ({$chkTerm} baris).\n";
    }

    echo "5. Memasukkan rincian Penggajian untuk Periode Oktober & November 2026...\n";
    // Check if period 2 (November 2026) exists
    $pNov = $pdo->query("SELECT id FROM payroll_periods WHERE period_code = 'PAY-202611'")->fetchColumn();
    if (!$pNov) {
        $pdo->prepare("
            INSERT INTO payroll_periods (period_code, period_month, period_year, start_date, end_date, status, notes, created_by, created_at)
            VALUES ('PAY-202611', 11, 2026, '2026-11-01', '2026-11-30', 'review', 'Penggajian Periode November 2026 (Menunggu Verifikasi Manajer)', 8, NOW())
        ")->execute();
        $pNovId = (int)$pdo->lastInsertId();
    } else {
        $pNovId = (int)$pNov;
    }

    // Populate payroll_records for PAY-202611 if empty
    $recCountNov = (int)$pdo->query("SELECT COUNT(*) FROM payroll_records WHERE payroll_period_id = {$pNovId}")->fetchColumn();
    if ($recCountNov === 0) {
        $emps = $pdo->query("SELECT * FROM employees WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);

        $stmtInsRec = $pdo->prepare("
            INSERT INTO payroll_records 
            (payroll_period_id, employee_id, base_salary, total_earnings, total_deductions, net_salary, status, created_at)
            VALUES 
            (:pid, :eid, :base, :earn, :ded, :net, 'calculated', NOW())
        ");
        $stmtInsComp = $pdo->prepare("
            INSERT INTO payroll_record_components 
            (payroll_record_id, payroll_component_id, component_type, amount, notes)
            VALUES 
            (:rid, :cid, :type, :amt, :notes)
        ");

        foreach ($emps as $emp) {
            $base = (float)$emp['base_salary'];
            $fixedAllowance = (float)$emp['fixed_allowance'];
            $ot = ($emp['id'] % 2 === 0) ? 600000.00 : 0.00;
            $earnings = $base + $fixedAllowance + $ot;

            $bpjsTk = round($base * 0.03, 2);
            $bpjsKes = round($base * 0.01, 2);
            $taxable = max(0, $earnings - 4500000);
            $pph21 = round($taxable * 0.05, 2);
            $deductions = $bpjsTk + $bpjsKes + $pph21;
            $net = $earnings - $deductions;

            $stmtInsRec->execute([
                ':pid'  => $pNovId,
                ':eid'  => $emp['id'],
                ':base' => $base,
                ':earn' => $earnings,
                ':ded'  => $deductions,
                ':net'  => $net,
            ]);
            $recId = $pdo->lastInsertId();

            $stmtInsComp->execute([':rid' => $recId, ':cid' => 1, ':type' => 'penghasilan', ':amt' => $base, ':notes' => 'Gaji Pokok']);
            $stmtInsComp->execute([':rid' => $recId, ':cid' => 2, ':type' => 'penghasilan', ':amt' => $fixedAllowance, ':notes' => 'Tunjangan Tetap']);
            if ($ot > 0) {
                $stmtInsComp->execute([':rid' => $recId, ':cid' => 4, ':type' => 'penghasilan', ':amt' => $ot, ':notes' => 'Uang Lembur']);
            }
            $stmtInsComp->execute([':rid' => $recId, ':cid' => 6, ':type' => 'potongan', ':amt' => $bpjsTk, ':notes' => 'BPJS Ketenagakerjaan (3%)']);
            $stmtInsComp->execute([':rid' => $recId, ':cid' => 7, ':type' => 'potongan', ':amt' => $bpjsKes, ':notes' => 'BPJS Kesehatan (1%)']);
            if ($pph21 > 0) {
                $stmtInsComp->execute([':rid' => $recId, ':cid' => 8, ':type' => 'potongan', ':amt' => $pph21, ':notes' => 'PPh 21']);
            }
        }
        echo "   [OK] " . count($emps) . " baris gaji berhasil digenerate untuk Periode November 2026 (status: calculated / siap diverifikasi).\n";
    } else {
        echo "   [INFO] Rincian gaji Periode November 2026 sudah ada ({$recCountNov} baris).\n";
    }

    $pdo->commit();
    echo "\n[SELESAI] Seluruh seeding data SDM & Penggajian berhasil dijalankan!\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n[GAGAL] " . $e->getMessage() . "\n";
}
