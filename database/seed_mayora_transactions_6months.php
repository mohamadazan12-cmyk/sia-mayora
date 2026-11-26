<?php
/**
 * Mayora 6-Month Comprehensive Transactional Seeder
 * Generates realistic enterprise ERP transactions with balanced double-entry accounting journals,
 * FEFO batch tracking, and multi-role notification pipeline.
 */

ini_set('memory_limit', '1024M');
set_time_limit(600);

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/journal_service.php';
require_once __DIR__ . '/../app/services/notification_service.php';

$pdo = getDbConnection();

echo "========================================================\n";
echo "GENERATING 6-MONTH HISTORICAL SIMULATION (PT MAYORA)\n";
echo "========================================================\n\n";

try {
    // -------------------------------------------------------------------------
    // 1. SEED ADDITIONAL EMPLOYEES (Target: 85 Employees)
    // -------------------------------------------------------------------------
    echo "1. Seeding Additional Employees up to 85...\n";
    $curEmpCount = (int)$pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
    $depts = $pdo->query("SELECT id, name FROM departments")->fetchAll(PDO::FETCH_KEY_PAIR);
    $positions = $pdo->query("SELECT id, name, department_id FROM positions")->fetchAll(PDO::FETCH_ASSOC);

    $posByDept = [];
    foreach ($positions as $p) {
        $posByDept[$p['department_id']][] = $p['id'];
    }

    $firstNames = ['Ahmad', 'Budi', 'Candra', 'Deni', 'Eko', 'Fajar', 'Gunawan', 'Hadi', 'Indra', 'Joko', 'Kurniawan', 'Lukman', 'Mulyadi', 'Nur', 'Oki', 'Prasetyo', 'Rian', 'Santoso', 'Teguh', 'Wahyu', 'Yusuf', 'Zainal', 'Agus', 'Bambang', 'Dedi', 'Endang', 'Ginanjar', 'Heru', 'Irfan', 'Joni', 'Krisna', 'Muhammad', 'Rizky', 'Sigit', 'Tri', 'Wawan'];
    $lastNames = ['Pratama', 'Saputra', 'Wijaya', 'Kusuma', 'Santoso', 'Hidayat', 'Firmansyah', 'Nugroho', 'Purnomo', 'Setiawan', 'Wibowo', 'Siregar', 'Lubis', 'Pasaribu', 'Nasution', 'Harahap', 'Ginting', 'Sembiring', 'Tarigan', 'Sinaga', 'Simanjuntak', 'Sitorus', 'Hutapea', 'Aritonang', 'Tambunan'];

    $stmtNewEmp = $pdo->prepare("INSERT INTO employees (code, full_name, department_id, position_id, join_date, base_salary, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");

    $deptKeys = array_keys($depts);
    for ($i = $curEmpCount + 1; $i <= 85; $i++) {
        $code = sprintf('EMP-%03d', $i);
        $name = $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
        // Bias towards production department (Dept ID 2/Produksi)
        $dId = (rand(1, 10) <= 6) ? ($deptKeys[1] ?? $deptKeys[0]) : $deptKeys[array_rand($deptKeys)];
        $posList = $posByDept[$dId] ?? [$positions[0]['id']];
        $pId = $posList[array_rand($posList)];
        $joinDate = date('Y-m-d', strtotime('-' . rand(180, 1200) . ' days'));
        $salary = rand(4900, 8500) * 1000;

        $stmtNewEmp->execute([$code, $name, $dId, $pId, $joinDate, $salary]);
    }
    echo "Total employees now: " . $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn() . "\n";

    // -------------------------------------------------------------------------
    // 2. SETUP CACHES & REFERENCES
    // -------------------------------------------------------------------------
    $suppliers = $pdo->query("SELECT id, code, name, payment_terms_days FROM suppliers")->fetchAll(PDO::FETCH_ASSOC);
    $customers = $pdo->query("SELECT id, code, name, payment_terms_days FROM customers")->fetchAll(PDO::FETCH_ASSOC);
    $machines = $pdo->query("SELECT id, code, name, hourly_rate FROM machines")->fetchAll(PDO::FETCH_ASSOC);
    $rawItems = $pdo->query("SELECT i.id, i.code, i.name, i.unit_id, i.cost_price, u.code as unit_code 
        FROM items i JOIN units u ON i.unit_id = u.id 
        JOIN item_categories c ON i.category_id = c.id 
        WHERE c.type IN ('bahan_baku', 'packaging') AND i.is_active = 1 AND i.cost_price > 0")->fetchAll(PDO::FETCH_ASSOC);
    $fgItems = $pdo->query("SELECT i.id, i.code, i.name, i.unit_id, i.cost_price, i.selling_price, u.code as unit_code, b.id as bom_id, b.target_cost
        FROM items i JOIN units u ON i.unit_id = u.id 
        JOIN item_categories c ON i.category_id = c.id 
        LEFT JOIN boms b ON b.product_item_id = i.id
        WHERE c.type = 'barang_jadi' AND i.is_active = 1 AND i.cost_price > 0")->fetchAll(PDO::FETCH_ASSOC);
    $employees = $pdo->query("SELECT id, full_name FROM employees")->fetchAll(PDO::FETCH_ASSOC);
    $warehouses = $pdo->query("SELECT id, code, type FROM warehouses")->fetchAll(PDO::FETCH_ASSOC);
    $whByCode = [];
    foreach ($warehouses as $w) $whByCode[$w['code']] = $w['id'];

    $users = $pdo->query("SELECT id, username, role_id FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $userByRole = [
        'admin'      => 1,
        'gudang'     => 2,
        'purchasing' => 3,
        'ppic'       => 4,
        'qc'         => 5,
        'penjualan'  => 6,
        'finance'    => 7,
        'hr'         => 8,
        'manager'    => 9,
    ];

    // Accounts for Journal Entries
    $accMap = [];
    foreach ($pdo->query("SELECT id, code FROM accounts")->fetchAll() as $a) {
        $accMap[$a['code']] = (int)$a['id'];
    }
    $accCash     = $accMap['1120'] ?? 3; // Bank BCA
    $accAR       = $accMap['1200'] ?? 5; // Piutang Usaha
    $accRawInv   = $accMap['1310'] ?? 7; // Persediaan Bahan Baku
    $accWIP      = $accMap['1330'] ?? 9; // Persediaan BDP (WIP)
    $accFGInv    = $accMap['1340'] ?? 10; // Persediaan Barang Jadi
    $accAP       = $accMap['2100'] ?? 11; // Utang Usaha
    $accSales    = $accMap['4100'] ?? 17; // Penjualan Barang Jadi
    $accCOGS     = $accMap['5100'] ?? 19; // HPP
    $accLabor    = $accMap['5300'] ?? 21; // BTKL
    $accOverhead = $accMap['5400'] ?? 22; // Overhead Dibebankan

    // Prepared statements for quick batch execution
    $stmtPR = $pdo->prepare("INSERT INTO purchase_requisitions (pr_number, department_id, requested_by, request_date, reason, total_estimated, status, created_at) VALUES (?, 1, ?, ?, ?, ?, ?, ?)");
    $stmtPRI = $pdo->prepare("INSERT INTO purchase_requisition_items (purchase_requisition_id, item_id, qty, unit_id, estimated_price, subtotal) VALUES (?, ?, ?, ?, ?, ?)");

    $stmtPO = $pdo->prepare("INSERT INTO purchase_orders (po_number, purchase_requisition_id, supplier_id, po_date, expected_delivery, total_amount, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtPOI = $pdo->prepare("INSERT INTO purchase_order_items (purchase_order_id, item_id, qty_ordered, qty_received, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?)");

    $stmtGR = $pdo->prepare("INSERT INTO goods_receipts (gr_number, purchase_order_id, supplier_id, warehouse_id, receipt_date, do_supplier, notes, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtGRI = $pdo->prepare("INSERT INTO goods_receipt_items (goods_receipt_id, purchase_order_item_id, item_id, qty_received, qty_accepted, qty_rejected, batch_number, mfg_date, expiry_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmtQC = $pdo->prepare("INSERT INTO qc_inspections (inspection_number, ref_type, ref_id, inspection_date, inspector_id, parameters, result, findings, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmtBatch = $pdo->prepare("INSERT INTO product_batches (batch_number, item_id, warehouse_id, supplier_id, production_order_id, goods_receipt_id, mfg_date, expiry_date, initial_qty, current_qty, status, qc_inspection_id, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmtStockMv = $pdo->prepare("INSERT INTO stock_movements (item_id, warehouse_id, movement_type, qty_in, qty_out, balance_after, ref_type, ref_id, ref_number, batch_number, notes, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmtInv = $pdo->prepare("INSERT INTO supplier_invoices (invoice_number, supplier_id, purchase_order_id, goods_receipt_id, supplier_invoice_number, invoice_date, due_date, subtotal, tax_amount, total_amount, paid_amount, balance, status, discrepancy_reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmtTWM = $pdo->prepare("INSERT INTO three_way_matches (supplier_invoice_id, purchase_order_id, goods_receipt_id, match_status, qty_diff, value_diff, notes, verified_by, verified_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmtAPV = $pdo->prepare("INSERT INTO ap_vouchers (voucher_number, supplier_invoice_id, supplier_id, voucher_date, amount, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    $stmtPRQ = $pdo->prepare("INSERT INTO payment_requests (request_number, ap_voucher_id, supplier_id, request_date, amount, reason, status, requested_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmtSPay = $pdo->prepare("INSERT INTO supplier_payments (payment_number, payment_request_id, supplier_id, payment_date, amount, payment_method, bank_name, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, 'transfer', 'Bank BCA', 'posted', ?, ?)");

    $stmtSPA = $pdo->prepare("INSERT INTO supplier_payment_allocations (supplier_payment_id, supplier_invoice_id, allocated_amount) VALUES (?, ?, ?)");

    $stmtMPS = $pdo->prepare("INSERT INTO production_schedules (schedule_number, product_item_id, period_year, period_month, planned_week, planned_qty, scheduled_start, scheduled_end, status, approved_by, approved_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed', 9, ?, 4, ?)");

    $stmtProdO = $pdo->prepare("INSERT INTO production_orders (po_number, product_item_id, bom_id, warehouse_material_id, warehouse_output_id, target_qty, actual_qty, scrap_qty, planned_start, planned_end, actual_start, actual_end, notes, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmtMatReq = $pdo->prepare("INSERT INTO material_requisitions (requisition_number, production_order_id, warehouse_id, request_date, status, requested_by, approved_by, approved_at, created_at) VALUES (?, ?, ?, ?, 'issued', 4, 9, ?, ?)");
    $stmtMatReqI = $pdo->prepare("INSERT INTO material_requisition_items (material_requisition_id, item_id, unit_id, qty_requested, qty_issued) VALUES (?, ?, ?, ?, ?)");

    $stmtMatIss = $pdo->prepare("INSERT INTO material_issues (issue_number, production_order_id, warehouse_id, issue_date, status, created_by, created_at) VALUES (?, ?, ?, ?, 'issued', 2, ?)");
    $stmtMatIssI = $pdo->prepare("INSERT INTO material_issue_items (material_issue_id, item_id, qty, lot_batch) VALUES (?, ?, ?, ?)");

    $stmtMoveTick = $pdo->prepare("INSERT INTO move_tickets (ticket_number, production_order_id, from_station, to_station, move_date, qty_moved, status, transferred_by, received_by, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, 'received', 4, 4, ?, ?)");

    $stmtJobTick = $pdo->prepare("INSERT INTO job_time_tickets (ticket_number, production_order_id, employee_id, work_date, start_time, end_time, hours_worked, hourly_rate, labor_cost, status, verified_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', 8, ?)");

    $stmtMsnLog = $pdo->prepare("INSERT INTO machine_logs (production_order_id, machine_id, machine_name, start_time, end_time, duration_minutes, operator, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 4, ?)");

    $stmtProdOut = $pdo->prepare("INSERT INTO production_outputs (production_order_id, batch_number, expiry_date, output_date, qty_good, qty_rework, qty_scrap, qc_inspection_id, warehouse_id, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'passed', 4, ?)");

    $stmtProdCost = $pdo->prepare("INSERT INTO production_costs (production_order_id, cost_type, description, amount, created_by, created_at) VALUES (?, ?, ?, ?, 7, ?)");

    $stmtSO = $pdo->prepare("INSERT INTO sales_orders (so_number, customer_id, so_date, due_date, total_amount, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtSOI = $pdo->prepare("INSERT INTO sales_order_items (sales_order_id, item_id, qty_ordered, qty_shipped, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?)");

    $stmtDO = $pdo->prepare("INSERT INTO delivery_orders (do_number, sales_order_id, warehouse_id, do_date, recipient_name, recipient_address, driver_name, vehicle_number, status, delivered_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'delivered', ?, 2, ?)");

    $stmtSI = $pdo->prepare("INSERT INTO sales_invoices (invoice_number, sales_order_id, delivery_order_id, customer_id, invoice_date, due_date, subtotal, tax_amount, total_amount, paid_amount, balance, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 0, 'paid', 6, ?)");

    $stmtCR = $pdo->prepare("INSERT INTO customer_receipts (receipt_number, customer_id, receipt_date, total_amount, payment_method, bank_name, status, verified_by, verified_at, created_by, created_at) VALUES (?, ?, ?, ?, 'transfer', 'Bank BCA', 'posted', 7, ?, 6, ?)");
    $stmtCRA = $pdo->prepare("INSERT INTO customer_receipt_allocations (customer_receipt_id, sales_invoice_id, allocated_amount) VALUES (?, ?, ?)");

    $stmtJE = $pdo->prepare("INSERT INTO journal_entries (entry_number, entry_date, ref_type, ref_id, ref_number, description, total_debit, total_credit, status, posted_by, posted_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'posted', 7, ?, ?)");
    $stmtJEL = $pdo->prepare("INSERT INTO journal_entry_lines (journal_entry_id, account_id, description, debit, credit) VALUES (?, ?, ?, ?, ?)");

    $stmtNotif = $pdo->prepare("INSERT INTO notifications (user_id, role_code, title, message, type, category, link, ref_type, ref_id, is_read, read_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    // Clean existing transactions for idempotent refresh
    echo "Cleaning existing historical transactional data for clean 6-month seed...\n";
    $tablesToTruncate = [
        'customer_receipt_allocations', 'customer_receipts', 'sales_invoices', 'delivery_order_items', 'delivery_orders', 'sales_order_items', 'sales_orders',
        'supplier_payment_allocations', 'supplier_payments', 'payment_requests', 'ap_vouchers', 'three_way_matches', 'supplier_invoices', 'supplier_return_items', 'supplier_returns', 'debit_memos',
        'goods_receipt_items', 'goods_receipts', 'purchase_order_items', 'purchase_orders', 'purchase_requisition_items', 'purchase_requisitions',
        'production_costs', 'scrap_records', 'production_outputs', 'machine_logs', 'job_time_tickets', 'move_tickets', 'material_issue_items', 'material_issues', 'material_return_items', 'material_returns', 'material_requisition_items', 'material_requisitions', 'production_materials', 'production_orders', 'production_schedules',
        'product_batches', 'qc_inspections', 'journal_entry_lines', 'journal_entries', 'stock_movements', 'notifications'
    ];
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($tablesToTruncate as $tb) {
        $pdo->exec("TRUNCATE TABLE `$tb`");
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // Begin transaction for transactional data
    $pdo->beginTransaction();

    // -------------------------------------------------------------------------
    // 3. GENERATE 180-DAY SIMULATION TIMELINE (Weeks 1 to 26)
    // -------------------------------------------------------------------------
    echo "3. Generating 180-Day Simulation Flow across 6 months...\n";

    $startDate = new DateTime('-180 days');
    $today = new DateTime();

    $prCount = 0;
    $poCount = 0;
    $grCount = 0;
    $qcCount = 0;
    $invCount = 0;
    $voucherCount = 0;
    $payCount = 0;

    $prodCount = 0;
    $soCount = 0;
    $journalCount = 0;
    $notifCount = 0;

    // Simulation loop by weeks
    $totalWeeks = 26;
    for ($w = 1; $w <= $totalWeeks; $w++) {
        $weekOffset = 180 - ($w * 7);
        $weekDate = (clone $startDate)->modify("+$w weeks");
        $dateStr = $weekDate->format('Y-m-d');
        $isRecentWeek = ($w >= 25); // Recent weeks contain pending/actionable data

        // =====================================================================
        // SIKLUS PENGADAAN (PROCUREMENT / EXPENDITURE)
        // 4 - 6 PR & PO per week
        // =====================================================================
        $procBatches = rand(4, 7);
        for ($pb = 1; $pb <= $procBatches; $pb++) {
            $prCount++;
            $prNum = sprintf('PR-%s-%04d', $weekDate->format('ym'), $prCount);
            $supp = $suppliers[array_rand($suppliers)];
            $rawItem = $rawItems[array_rand($rawItems)];
            $qty = rand(20, 150) * 10;
            $estPrice = $rawItem['cost_price'];
            $subtotal = $qty * $estPrice;

            // PR Status
            $prStatus = 'approved';
            if ($isRecentWeek && $pb === $procBatches) {
                $prStatus = ($pb % 2 === 0) ? 'pending_approval' : 'submitted';
            }

            $stmtPR->execute([$prNum, $userByRole['gudang'], $dateStr, 'Kebutuhan pemenuhan stok bahan baku Mayora', $subtotal, $prStatus, $dateStr . ' 08:30:00']);
            $prId = (int)$pdo->lastInsertId();

            $stmtPRI->execute([$prId, $rawItem['id'], $qty, $rawItem['unit_id'], $estPrice, $subtotal]);

            // Notification: PR Submitted
            $stmtNotif->execute([
                $userByRole['manager'], 'manager', "Pengajuan PR Baru: $prNum",
                "Gudang mengajukan permintaan pembelian $prNum senilai Rp " . number_format($subtotal, 0, ',', '.') . " untuk {$rawItem['name']}.",
                'info', 'procurement', "index.php?page=expenditure_procurement/pr_detail&id=$prId", 'purchase_requisitions', $prId,
                $isRecentWeek ? 0 : 1, $isRecentWeek ? null : $dateStr . ' 09:00:00', $dateStr . ' 08:31:00'
            ]);
            $notifCount++;

            // If PR approved, proceed to PO
            if ($prStatus === 'approved') {
                $poCount++;
                $poNum = sprintf('PO-%s-%04d', $weekDate->format('ym'), $poCount);
                $poDate = (clone $weekDate)->modify('+1 days')->format('Y-m-d');
                $expDelivery = (clone $weekDate)->modify('+4 days')->format('Y-m-d');

                $poStatus = 'received';
                if ($isRecentWeek) {
                    $poStatus = ($pb % 3 === 0) ? 'pending_approval' : (($pb % 2 === 0) ? 'approved' : 'partially_received');
                }

                $stmtPO->execute([$poNum, $prId, $supp['id'], $poDate, $expDelivery, $subtotal, $poStatus, $userByRole['purchasing'], $poDate . ' 10:00:00']);
                $poId = (int)$pdo->lastInsertId();

                $stmtPOI->execute([$poId, $rawItem['id'], $qty, ($poStatus === 'received' ? $qty : ($poStatus === 'partially_received' ? $qty * 0.5 : 0)), $estPrice, $subtotal]);

                // Notification: PO Created
                if ($poStatus === 'pending_approval') {
                    $stmtNotif->execute([
                        $userByRole['manager'], 'manager', "Persetujuan PO: $poNum",
                        "Purchasing telah menerbitkan PO $poNum kepada {$supp['name']} senilai Rp " . number_format($subtotal, 0, ',', '.') . ". Memerlukan otorisasi.",
                        'warning', 'procurement', "index.php?page=expenditure_procurement/po_detail&id=$poId", 'purchase_orders', $poId,
                        0, null, $poDate . ' 10:05:00'
                    ]);
                    $notifCount++;
                }

                // If PO approved or received, proceed to Goods Receipt
                if (in_array($poStatus, ['received', 'partially_received'])) {
                    $grCount++;
                    $grNum = sprintf('GR-%s-%04d', $weekDate->format('ym'), $grCount);
                    $grDate = (clone $weekDate)->modify('+3 days')->format('Y-m-d');
                    $qtyRecv = ($poStatus === 'partially_received') ? ($qty * 0.5) : $qty;

                    $grStatus = 'accepted';
                    if ($isRecentWeek && $pb === 1) {
                        $grStatus = 'inspecting'; // Pending Incoming QC
                    }

                    $stmtGR->execute([$grNum, $poId, $supp['id'], $whByCode['WH-RAW-01'], $grDate, 'SJ-' . rand(10000, 99999), 'Penerimaan bahan baku di gudang Tangerang', $grStatus, $userByRole['gudang'], $grDate . ' 11:00:00']);
                    $grId = (int)$pdo->lastInsertId();

                    // Batch info
                    $batchNum = sprintf('LOT-%s-%s-%03d', $rawItem['code'], date('ymd', strtotime($grDate)), $grCount);
                    $mfgDate = (clone $weekDate)->modify('-15 days')->format('Y-m-d');
                    $expDate = (clone $weekDate)->modify('+540 days')->format('Y-m-d'); // 1.5 year shelf life

                    $stmtGRI->execute([$grId, null, $rawItem['id'], $qtyRecv, ($grStatus === 'accepted' ? $qtyRecv : 0), 0, $batchNum, $mfgDate, $expDate, 'Kondisi kemasan rapi & segel utuh']);

                    // QC Inspection
                    $qcCount++;
                    $qcNum = sprintf('QC-IN-%s-%04d', $weekDate->format('ym'), $qcCount);
                    $qcResult = ($grStatus === 'accepted') ? 'pass' : 'pending';
                    $stmtQC->execute([$qcNum, 'goods_receipt', $grId, $grDate, $userByRole['qc'], 'Uji kadar air, organoleptik, dan kontaminasi bahan baku', $qcResult, 'Lolos uji spesifikasi bahan baku Mayora', $grDate . ' 13:00:00']);
                    $qcId = (int)$pdo->lastInsertId();

                    if ($grStatus === 'accepted') {
                        // Batch Tracking Table
                        $stmtBatch->execute([$batchNum, $rawItem['id'], $whByCode['WH-RAW-01'], $supp['id'], null, $grId, $mfgDate, $expDate, $qtyRecv, $qtyRecv, 'passed', $qcId, 'Bahan lolos Incoming QC & siap produksi', $grDate . ' 13:30:00']);

                        // Stock Movement (IN)
                        $currStock = (float)$pdo->query("SELECT qty_available FROM stock_balances WHERE item_id = {$rawItem['id']} AND warehouse_id = {$whByCode['WH-RAW-01']}")->fetchColumn();
                        $newStock = $currStock + $qtyRecv;
                        $pdo->query("UPDATE stock_balances SET qty_available = $newStock WHERE item_id = {$rawItem['id']} AND warehouse_id = {$whByCode['WH-RAW-01']}");

                        $stmtStockMv->execute([$rawItem['id'], $whByCode['WH-RAW-01'], 'in', $qtyRecv, 0, $newStock, 'goods_receipt', $grId, $grNum, $batchNum, "Penerimaan PO $poNum supplier {$supp['name']}", $userByRole['gudang'], $grDate . ' 13:35:00']);

                        // -----------------------------------------------------
                        // SIKLUS FAKTUR & THREE-WAY MATCHING
                        // -----------------------------------------------------
                        $invCount++;
                        $invNum = sprintf('INV-SUP-%s-%04d', $weekDate->format('ym'), $invCount);
                        $invDate = (clone $weekDate)->modify('+4 days')->format('Y-m-d');
                        $dueDate = (clone $weekDate)->modify('+' . $supp['payment_terms_days'] . ' days')->format('Y-m-d');
                        $invAmount = $qtyRecv * $estPrice;

                        $invStatus = 'matched';
                        $discReason = null;
                        if ($isRecentWeek && $pb === 2) {
                            $invStatus = 'discrepancy';
                            $discReason = 'Terdapat selisih harga tagihan supplier Rp 500/kg lebih tinggi dari PO disetujui.';
                        }

                        $stmtInv->execute([$invNum, $supp['id'], $poId, $grId, 'INV-VND-' . rand(10000, 99999), $invDate, $dueDate, $invAmount, 0, $invAmount, ($invStatus === 'matched' && !$isRecentWeek ? $invAmount : 0), ($invStatus === 'matched' && !$isRecentWeek ? 0 : $invAmount), $invStatus, $discReason, $invDate . ' 14:00:00']);
                        $invId = (int)$pdo->lastInsertId();

                        // Three Way Matching Record
                        $twmStatus = ($invStatus === 'matched') ? 'matched' : 'price_discrepancy';
                        $stmtTWM->execute([$invId, $poId, $grId, $twmStatus, 0, ($twmStatus === 'matched' ? 0 : 500 * $qtyRecv), $discReason ?: 'Three-Way Match 100% Sesuai (PO, GR, Invoice)', $userByRole['finance'], $invDate . ' 14:15:00', $invDate . ' 14:15:00']);

                        // Journal 1: Pembelian Bahan Baku (Debit Persediaan Bahan Baku, Kredit Utang Usaha)
                        if ($invStatus === 'matched') {
                            $journalCount++;
                            $jeNum = sprintf('JV-%s-%05d', $weekDate->format('ym'), $journalCount);
                            $stmtJE->execute([$jeNum, $invDate, 'supplier_invoice', $invId, $invNum, "Pengakuan utang pembelian bahan {$rawItem['name']} ($poNum)", $invAmount, $invAmount, $invDate . ' 14:30:00', $invDate . ' 14:30:00']);
                            $jeId = (int)$pdo->lastInsertId();

                            $stmtJEL->execute([$jeId, $accRawInv, "Persediaan Bahan Baku {$rawItem['name']}", $invAmount, 0]);
                            $stmtJEL->execute([$jeId, $accAP, "Utang Usaha {$supp['name']}", 0, $invAmount]);

                            // AP Voucher & Payment (if not recent week)
                            if (!$isRecentWeek) {
                                $voucherCount++;
                                $vNum = sprintf('VCH-%s-%04d', $weekDate->format('ym'), $voucherCount);
                                $stmtAPV->execute([$vNum, $invId, $supp['id'], $invDate, $invAmount, 'paid', $userByRole['finance'], $invDate . ' 15:00:00']);
                                $apvId = (int)$pdo->lastInsertId();

                                $payCount++;
                                $payNum = sprintf('PAY-%s-%04d', $weekDate->format('ym'), $payCount);
                                $payDate = (clone $weekDate)->modify('+10 days')->format('Y-m-d');
                                $stmtSPay->execute([$payNum, null, $supp['id'], $payDate, $invAmount, $userByRole['finance'], $payDate . ' 10:00:00']);
                                $payId = (int)$pdo->lastInsertId();

                                $stmtSPA->execute([$payId, $invId, $invAmount]);

                                // Journal 2: Pelunasan Utang (Debit Utang Usaha, Kredit Bank BCA)
                                $journalCount++;
                                $jePayNum = sprintf('JV-%s-%05d', $weekDate->format('ym'), $journalCount);
                                $stmtJE->execute([$jePayNum, $payDate, 'supplier_payment', $payId, $payNum, "Pelunasan utang pembelian ke {$supp['name']} ($invNum)", $invAmount, $invAmount, $payDate . ' 10:15:00', $payDate . ' 10:15:00']);
                                $jePayId = (int)$pdo->lastInsertId();

                                $stmtJEL->execute([$jePayId, $accAP, "Utang Usaha {$supp['name']}", $invAmount, 0]);
                                $stmtJEL->execute([$jePayId, $accCash, "Kas/Bank BCA Operasional", 0, $invAmount]);
                            } else {
                                // Recent: Voucher Pending Approval for Manager demo
                                $voucherCount++;
                                $vNum = sprintf('VCH-%s-%04d', $weekDate->format('ym'), $voucherCount);
                                $stmtAPV->execute([$vNum, $invId, $supp['id'], $invDate, $invAmount, 'verified', $userByRole['finance'], $invDate . ' 15:00:00']);
                                $apvId = (int)$pdo->lastInsertId();

                                $stmtNotif->execute([
                                    $userByRole['manager'], 'manager', "Approval Voucher Pembayaran: $vNum",
                                    "Finance mengajukan persetujuan pembayaran voucher $vNum ke {$supp['name']} senilai Rp " . number_format($invAmount, 0, ',', '.') . ".",
                                    'warning', 'finance', "index.php?page=expenditure_procurement/payments", 'ap_vouchers', $apvId,
                                    0, null, $invDate . ' 15:05:00'
                                ]);
                                $notifCount++;
                            }
                        }
                    }
                }
            }
        }

        // =====================================================================
        // SIKLUS PRODUKSI (MANUFACTURING / PRODUCTION OPERATIONS)
        // 5 - 8 SPK per week
        // =====================================================================
        $prodBatches = rand(5, 8);
        for ($prb = 1; $prb <= $prodBatches; $prb++) {
            $prodCount++;
            $spkNum = sprintf('SPK-%s-%04d', $weekDate->format('ym'), $prodCount);
            $fg = $fgItems[array_rand($fgItems)];
            $targetQty = rand(50, 300); // Dus
            $costPerUnit = $fg['target_cost'] ?: $fg['cost_price'];
            $totalCostEst = $targetQty * $costPerUnit;

            $spkStatus = 'completed';
            if ($isRecentWeek) {
                if ($prb === 1) $spkStatus = 'in_progress';
                elseif ($prb === 2) $spkStatus = 'waiting_qc';
                elseif ($prb === 3) $spkStatus = 'pending_approval';
                elseif ($prb === 4) $spkStatus = 'waiting_material';
            }

            $plannedStart = (clone $weekDate)->modify('+1 days')->format('Y-m-d');
            $plannedEnd   = (clone $weekDate)->modify('+2 days')->format('Y-m-d');
            $actualStart  = in_array($spkStatus, ['in_progress', 'waiting_qc', 'completed']) ? $plannedStart : null;
            $actualEnd    = ($spkStatus === 'completed') ? $plannedEnd : null;

            $scrapQty = ($spkStatus === 'completed') ? rand(1, 5) : 0;
            $actualQty = ($spkStatus === 'completed') ? ($targetQty - $scrapQty) : 0;

            // MPS Schedule entry
            $mpsNum = sprintf('MPS-%s-%04d', $weekDate->format('ym'), $prodCount);
            $stmtMPS->execute([$mpsNum, $fg['id'], (int)$weekDate->format('Y'), (int)$weekDate->format('n'), ceil($weekDate->format('j') / 7), $targetQty, $plannedStart, $plannedEnd, $plannedStart . ' 07:00:00', $plannedStart . ' 07:00:00']);

            // Insert Production Order
            $stmtProdO->execute([
                $spkNum, $fg['id'], $fg['bom_id'], $whByCode['WH-RAW-01'], $whByCode['WH-FG-01'],
                $targetQty, $actualQty, $scrapQty, $plannedStart, $plannedEnd, $actualStart, $actualEnd,
                "Rencana produksi batch Mayora {$fg['name']}", $spkStatus, $userByRole['ppic'], $plannedStart . ' 07:30:00'
            ]);
            $spkId = (int)$pdo->lastInsertId();

            // Notification for SPK
            if ($spkStatus === 'pending_approval') {
                $stmtNotif->execute([
                    $userByRole['manager'], 'manager', "Approval SPK Produksi: $spkNum",
                    "PPIC mengajukan Perintah Kerja $spkNum untuk memproduksi $targetQty Dus {$fg['name']}. Menunggu otorisasi.",
                    'info', 'production', "index.php?page=production/order_detail&id=$spkId", 'production_orders', $spkId,
                    0, null, $plannedStart . ' 07:35:00'
                ]);
                $notifCount++;
            }

            // If SPK is in progress or completed, execute Materials Requisition & Issue
            if (in_array($spkStatus, ['in_progress', 'waiting_qc', 'completed'])) {
                $mrNum = sprintf('MR-%s-%04d', $weekDate->format('ym'), $prodCount);
                $stmtMatReq->execute([$mrNum, $spkId, $whByCode['WH-RAW-01'], $actualStart, $actualStart . ' 08:00:00', $actualStart . ' 08:00:00']);
                $mrId = (int)$pdo->lastInsertId();

                $miNum = sprintf('MI-%s-%04d', $weekDate->format('ym'), $prodCount);
                $stmtMatIss->execute([$miNum, $spkId, $whByCode['WH-RAW-01'], $actualStart, $actualStart . ' 08:15:00']);
                $miId = (int)$pdo->lastInsertId();

                // Material Issue items & Journal (Debit WIP, Kredit Persediaan Bahan Baku)
                $matCost = $totalCostEst * 0.65; // ~65% Direct Material
                $laborCost = $totalCostEst * 0.20; // ~20% Direct Labor
                $overheadCost = $totalCostEst * 0.15; // ~15% Factory Overhead

                // Sample material issue items
                $stmtMatIssI->execute([$miId, $rawItems[0]['id'], $targetQty * 1.5, 'LOT-RAW-01']);
                $stmtMatIssI->execute([$miId, $rawItems[1]['id'], $targetQty * 0.5, 'LOT-RAW-02']);

                // Journal 3: Pemakaian Bahan Baku (Debit WIP, Kredit Persediaan Bahan Baku)
                $journalCount++;
                $jeMatNum = sprintf('JV-%s-%05d', $weekDate->format('ym'), $journalCount);
                $stmtJE->execute([$jeMatNum, $actualStart, 'material_issue', $miId, $miNum, "Pemakaian bahan baku langsung SPK $spkNum ({$fg['name']})", $matCost, $matCost, $actualStart . ' 08:30:00', $actualStart . ' 08:30:00']);
                $jeMatId = (int)$pdo->lastInsertId();

                $stmtJEL->execute([$jeMatId, $accWIP, "Persediaan BDP - Bahan Baku {$fg['name']}", $matCost, 0]);
                $stmtJEL->execute([$jeMatId, $accRawInv, "Persediaan Bahan Baku Terpakai", 0, $matCost]);

                // Move Tickets across stations
                $stmtMoveTick->execute([sprintf('MT-%s-%04d-1', $weekDate->format('ym'), $prodCount), $spkId, 'Stasiun Mixing', 'Stasiun Forming & Baking', $actualStart . ' 09:30:00', $targetQty, 'Adonan biskuit siap panggang', $actualStart . ' 09:30:00']);
                $stmtMoveTick->execute([sprintf('MT-%s-%04d-2', $weekDate->format('ym'), $prodCount), $spkId, 'Stasiun Baking', 'Stasiun Packaging', $actualStart . ' 11:30:00', $targetQty, 'Keping biskuit dingin siap kemas', $actualStart . ' 11:30:00']);

                // Job Time Tickets (Operator hours)
                $empOp = $employees[array_rand($employees)];
                $stmtJobTick->execute([sprintf('JTT-%s-%04d', $weekDate->format('ym'), $prodCount), $spkId, $empOp['id'], $actualStart, '08:00:00', '16:00:00', 8.0, 35000, 280000, $actualStart . ' 16:30:00']);

                // Machine Logs
                $msnOp = $machines[array_rand($machines)];
                $stmtMsnLog->execute([$spkId, $msnOp['id'], $msnOp['name'], $actualStart . ' 08:00:00', $actualStart . ' 14:00:00', 360, $empOp['full_name'], 'Operasi pencetakan stabil tanpa kendala teknis', $actualStart . ' 14:00:00']);

                // Production Costs (Material, Labor, Overhead)
                $stmtProdCost->execute([$spkId, 'material', 'Biaya Bahan Baku Langsung (Tepung, Gula, Lemak, Perisa)', $matCost, $actualStart . ' 16:00:00']);
                $stmtProdCost->execute([$spkId, 'labor', 'Biaya Tenaga Kerja Langsung Operator Produksi', $laborCost, $actualStart . ' 16:00:00']);
                $stmtProdCost->execute([$spkId, 'overhead', 'Alokasi Biaya Overhead Pabrik (Listrik, Gas Oven, Mesin)', $overheadCost, $actualStart . ' 16:00:00']);

                // Journal 4: Pembebanan BTKL & Overhead ke WIP
                $journalCount++;
                $jeLOHNum = sprintf('JV-%s-%05d', $weekDate->format('ym'), $journalCount);
                $stmtJE->execute([$jeLOHNum, $actualStart, 'production_order', $spkId, $spkNum, "Pembebanan Tenaga Kerja & Overhead SPK $spkNum ({$fg['name']})", $laborCost + $overheadCost, $laborCost + $overheadCost, $actualStart . ' 16:15:00', $actualStart . ' 16:15:00']);
                $jeLOHId = (int)$pdo->lastInsertId();

                $stmtJEL->execute([$jeLOHId, $accWIP, "Persediaan BDP - Tenaga Kerja & BOP", $laborCost + $overheadCost, 0]);
                $stmtJEL->execute([$jeLOHId, $accLabor, "Alokasi Beban Tenaga Kerja Langsung", 0, $laborCost]);
                $stmtJEL->execute([$jeLOHId, $accOverhead, "Overhead Pabrik Dibebankan (BOP)", 0, $overheadCost]);

                // If completed, finalize Final QC & Finished Goods Receipt
                if ($spkStatus === 'completed') {
                    $qcCount++;
                    $qcFinalNum = sprintf('QC-FN-%s-%04d', $weekDate->format('ym'), $qcCount);
                    $stmtQC->execute([$qcFinalNum, 'production', $spkId, $actualEnd, $userByRole['qc'], 'Uji net weight, moisture, metal detector, seal integrity', 'pass', 'Lolos uji mutu rilis barang jadi ke gudang', $actualEnd . ' 15:00:00']);
                    $qcFinalId = (int)$pdo->lastInsertId();

                    $fgBatchNum = sprintf('LOT-FG-%s-%s-%03d', $fg['code'], date('ymd', strtotime($actualEnd)), $prodCount);
                    $fgExpDate = (clone $weekDate)->modify('+365 days')->format('Y-m-d'); // 1 year shelf life

                    $stmtProdOut->execute([$spkId, $fgBatchNum, $fgExpDate, $actualEnd, $actualQty, 0, $scrapQty, $qcFinalId, $whByCode['WH-FG-01'], $actualEnd . ' 15:30:00']);

                    // Batch Tracking for Finished Goods
                    $stmtBatch->execute([$fgBatchNum, $fg['id'], $whByCode['WH-FG-01'], null, $spkId, null, $actualEnd, $fgExpDate, $actualQty, $actualQty, 'passed', $qcFinalId, 'Barang jadi lolos Final QC siap distribusi', $actualEnd . ' 15:35:00']);

                    // Stock Movement (IN FG)
                    $currFGStock = (float)$pdo->query("SELECT qty_available FROM stock_balances WHERE item_id = {$fg['id']} AND warehouse_id = {$whByCode['WH-FG-01']}")->fetchColumn();
                    $newFGStock = $currFGStock + $actualQty;
                    $pdo->query("UPDATE stock_balances SET qty_available = $newFGStock WHERE item_id = {$fg['id']} AND warehouse_id = {$whByCode['WH-FG-01']}");

                    $stmtStockMv->execute([$fg['id'], $whByCode['WH-FG-01'], 'in', $actualQty, 0, $newFGStock, 'production_order', $spkId, $spkNum, $fgBatchNum, "Penerimaan hasil produksi SPK $spkNum", $userByRole['gudang'], $actualEnd . ' 15:40:00']);

                    // Journal 5: Penyelesaian Produksi (Debit Persediaan Barang Jadi, Kredit WIP)
                    $finalProdVal = $actualQty * $costPerUnit;
                    $journalCount++;
                    $jeCompNum = sprintf('JV-%s-%05d', $weekDate->format('ym'), $journalCount);
                    $stmtJE->execute([$jeCompNum, $actualEnd, 'production_order', $spkId, $spkNum, "Penyelesaian produksi $actualQty Dus {$fg['name']} ($spkNum)", $finalProdVal, $finalProdVal, $actualEnd . ' 16:00:00', $actualEnd . ' 16:00:00']);
                    $jeCompId = (int)$pdo->lastInsertId();

                    $stmtJEL->execute([$jeCompId, $accFGInv, "Persediaan Barang Jadi {$fg['name']}", $finalProdVal, 0]);
                    $stmtJEL->execute([$jeCompId, $accWIP, "Persediaan BDP Ditransfer Selesai", 0, $finalProdVal]);
                }
            }
        }

        // =====================================================================
        // SIKLUS PENJUALAN (SALES & DISTRIBUTION / REVENUE)
        // 4 - 6 Sales Orders per week
        // =====================================================================
        $salesBatches = rand(4, 6);
        for ($sb = 1; $sb <= $salesBatches; $sb++) {
            $soCount++;
            $soNum = sprintf('SO-%s-%04d', $weekDate->format('ym'), $soCount);
            $cust = $customers[array_rand($customers)];
            $soFg = $fgItems[array_rand($fgItems)];
            $orderQty = rand(30, 200); // Dus
            $sellPrice = $soFg['selling_price'];
            $soAmount = $orderQty * $sellPrice;
            $soDate = (clone $weekDate)->modify('+2 days')->format('Y-m-d');
            $delivDate = (clone $weekDate)->modify('+4 days')->format('Y-m-d');

            $soStatus = 'completed';
            if ($isRecentWeek && $sb === $salesBatches) {
                $soStatus = 'pending_approval';
            }

            $stmtSO->execute([$soNum, $cust['id'], $soDate, $delivDate, $soAmount, $soStatus, $userByRole['penjualan'], $soDate . ' 09:00:00']);
            $soId = (int)$pdo->lastInsertId();

            $stmtSOI->execute([$soId, $soFg['id'], $orderQty, ($soStatus === 'completed' ? $orderQty : 0), $sellPrice, $soAmount]);

            if ($soStatus === 'completed') {
                $doNum = sprintf('DO-%s-%04d', $weekDate->format('ym'), $soCount);
                $stmtDO->execute([$doNum, $soId, $whByCode['WH-FG-01'], $delivDate, $cust['name'], 'Pusat Distribusi ' . $cust['name'], 'Supardi', 'B 9283 TYZ', $delivDate . ' 14:00:00', $delivDate . ' 10:00:00']);
                $doId = (int)$pdo->lastInsertId();

                // Stock Movement (OUT FG)
                $currFGStock = (float)$pdo->query("SELECT qty_available FROM stock_balances WHERE item_id = {$soFg['id']} AND warehouse_id = {$whByCode['WH-FG-01']}")->fetchColumn();
                $newFGStock = max(50, $currFGStock - $orderQty); // Guard against negative
                $pdo->query("UPDATE stock_balances SET qty_available = $newFGStock WHERE item_id = {$soFg['id']} AND warehouse_id = {$whByCode['WH-FG-01']}");

                $stmtStockMv->execute([$soFg['id'], $whByCode['WH-FG-01'], 'out', 0, $orderQty, $newFGStock, 'delivery_order', $doId, $doNum, null, "Pengiriman pesanan $soNum ke {$cust['name']}", $userByRole['gudang'], $delivDate . ' 10:30:00']);

                // Sales Invoice & Receipt
                $siNum = sprintf('INV-SLS-%s-%04d', $weekDate->format('ym'), $soCount);
                $stmtSI->execute([$siNum, $soId, $doId, $cust['id'], $delivDate, (clone $weekDate)->modify('+' . $cust['payment_terms_days'] . ' days')->format('Y-m-d'), $soAmount, $soAmount, $soAmount, $delivDate . ' 11:00:00']);
                $siId = (int)$pdo->lastInsertId();

                $rcptNum = sprintf('RCPT-%s-%04d', $weekDate->format('ym'), $soCount);
                $rcptDate = (clone $weekDate)->modify('+14 days')->format('Y-m-d');
                $stmtCR->execute([$rcptNum, $cust['id'], $rcptDate, $soAmount, $rcptDate . ' 11:00:00', $rcptDate . ' 11:00:00']);
                $crId = (int)$pdo->lastInsertId();

                $stmtCRA->execute([$crId, $siId, $soAmount]);

                // Journal 6: Penjualan & Piutang (Debit Piutang, Kredit Penjualan)
                $journalCount++;
                $jeSlsNum = sprintf('JV-%s-%05d', $weekDate->format('ym'), $journalCount);
                $stmtJE->execute([$jeSlsNum, $delivDate, 'sales_invoice', $siId, $siNum, "Faktur penjualan produk {$soFg['name']} ke {$cust['name']} ($soNum)", $soAmount, $soAmount, $delivDate . ' 11:15:00', $delivDate . ' 11:15:00']);
                $jeSlsId = (int)$pdo->lastInsertId();

                $stmtJEL->execute([$jeSlsId, $accAR, "Piutang Usaha {$cust['name']}", $soAmount, 0]);
                $stmtJEL->execute([$jeSlsId, $accSales, "Penjualan Barang Jadi", 0, $soAmount]);

                // Journal 7: Pengakuan HPP Penjualan (Debit HPP, Kredit Persediaan Barang Jadi)
                $cogsVal = $orderQty * $soFg['cost_price'];
                $journalCount++;
                $jeCOGSNum = sprintf('JV-%s-%05d', $weekDate->format('ym'), $journalCount);
                $stmtJE->execute([$jeCOGSNum, $delivDate, 'delivery_order', $doId, $doNum, "Beban Pokok Penjualan (HPP) $orderQty Dus {$soFg['name']} ($soNum)", $cogsVal, $cogsVal, $delivDate . ' 11:20:00', $delivDate . ' 11:20:00']);
                $jeCOGSId = (int)$pdo->lastInsertId();

                $stmtJEL->execute([$jeCOGSId, $accCOGS, "Harga Pokok Penjualan {$soFg['name']}", $cogsVal, 0]);
                $stmtJEL->execute([$jeCOGSId, $accFGInv, "Persediaan Barang Jadi Keluar", 0, $cogsVal]);

                // Journal 8: Pelunasan Piutang Kas/Bank (Debit Bank BCA, Kredit Piutang)
                if (!$isRecentWeek) {
                    $journalCount++;
                    $jeRcptNum = sprintf('JV-%s-%05d', $weekDate->format('ym'), $journalCount);
                    $stmtJE->execute([$jeRcptNum, $rcptDate, 'customer_receipt', $crId, $rcptNum, "Penerimaan kas piutang dari {$cust['name']} ($siNum)", $soAmount, $soAmount, $rcptDate . ' 11:30:00', $rcptDate . ' 11:30:00']);
                    $jeRcptId = (int)$pdo->lastInsertId();

                    $stmtJEL->execute([$jeRcptId, $accCash, "Kas/Bank BCA Penerimaan Penjualan", $soAmount, 0]);
                    $stmtJEL->execute([$jeRcptId, $accAR, "Piutang Usaha {$cust['name']}", 0, $soAmount]);
                }
            }
        }

        if ($w % 5 === 0 || $w === $totalWeeks) {
            echo " - Processed month " . ceil($w / 4.33) . "/6 (Week $w/$totalWeeks)... PRs: $prCount, POs: $poCount, GRs: $grCount, SPKs: $prodCount, SOs: $soCount, JVs: $journalCount\n";
        }
    }

    $pdo->commit();
    echo "\n========================================================\n";
    echo "SEEDING SUMMARY (6-MONTH HISTORICAL SIMULATION):\n";
    echo "========================================================\n";
    echo sprintf(" - Purchase Requisitions (PR)   : %d rows\n", $prCount);
    echo sprintf(" - Purchase Orders (PO)         : %d rows\n", $poCount);
    echo sprintf(" - Goods Receipts (GR)          : %d rows\n", $grCount);
    echo sprintf(" - QC Inspections (IN/FN)       : %d rows\n", $qcCount);
    echo sprintf(" - Supplier Invoices (Matched)  : %d rows\n", $invCount);
    echo sprintf(" - Payment Vouchers & Payments  : %d rows\n", $voucherCount);
    echo sprintf(" - Production Orders (SPK)      : %d rows\n", $prodCount);
    echo sprintf(" - Sales Orders (SO)            : %d rows\n", $soCount);
    echo sprintf(" - Accounting Journals (Balanced): %d entries\n", $journalCount);
    echo sprintf(" - Realtime Notifications       : %d alerts\n", $notifCount);
    echo "========================================================\n\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n[ERROR] Seeding failed at: " . $e->getFile() . ':' . $e->getLine() . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    exit(1);
}
