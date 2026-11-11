<?php
/**
 * Modul Produksi: Detail Perintah Kerja Produksi (SPK) & Kontrol Alur
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Detail Perintah Produksi';
$breadcrumbCategory = 'Produksi';

$db = getDbConnection();
$user = currentUser();

$pdoId = (int)($_GET['id'] ?? 0);
if ($pdoId <= 0) {
    setFlash('error', 'ID Perintah Produksi tidak valid.');
    header('Location: index.php?page=production/orders');
    exit;
}

// Fetch Header
$stmt = $db->prepare("
    SELECT pdo.*, i.name AS product_name, i.code AS product_code, i.cost_price, u.name AS unit_name,
           b.code AS bom_code, b.name AS bom_name,
           wm.name AS wh_material_name, wo.name AS wh_output_name,
           usr.full_name AS creator_name
    FROM production_orders pdo
    JOIN items i ON pdo.product_item_id = i.id
    LEFT JOIN units u ON i.unit_id = u.id
    LEFT JOIN boms b ON pdo.bom_id = b.id
    JOIN warehouses wm ON pdo.warehouse_material_id = wm.id
    JOIN warehouses wo ON pdo.warehouse_output_id = wo.id
    LEFT JOIN users usr ON pdo.created_by = usr.id
    WHERE pdo.id = :id
");
$stmt->execute([':id' => $pdoId]);
$pdo = $stmt->fetch();

if (!$pdo) {
    setFlash('error', 'Data Perintah Produksi tidak ditemukan.');
    header('Location: index.php?page=production/orders');
    exit;
}

// Handle Status Transitions & Action Forms
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'submit_approval' && $pdo['status'] === 'draft') {
            $db->prepare("UPDATE production_orders SET status = 'pending_approval', updated_at = NOW(), updated_by = :uid WHERE id = :id")->execute([':uid' => $user['id'], ':id' => $pdoId]);
            ApprovalService::submit('production_order', $pdoId, $pdo['po_number'], 'Diajukan untuk persetujuan produksi', $db);
            logAudit($user['id'], 'submit_approval', 'production_orders', $pdoId, ['status' => 'draft'], ['status' => 'pending_approval']);
            setFlash('success', "SPK {$pdo['po_number']} berhasil diajukan untuk persetujuan manajer.");
            header("Location: index.php?page=production/order_detail&id={$pdoId}");
            exit;
        } elseif ($action === 'approve_spk' && in_array($pdo['status'], ['draft', 'pending_approval'])) {
            if (!hasRole(['Supervisor / Manajer', 'Administrator', 'manager', 'admin', 'ppic'])) {
                throw new Exception("Anda tidak berwenang menyetujui perintah produksi ini.");
            }
            $oldStatus = $pdo['status'];
            $db->prepare("UPDATE production_orders SET status = 'approved', updated_at = NOW(), updated_by = :uid WHERE id = :id")->execute([':uid' => $user['id'], ':id' => $pdoId]);
            ApprovalService::approve('production_order', $pdoId, $pdo['po_number'], $user['id'], 'SPK disetujui untuk pelaksanaan produksi', $db);
            logAudit($user['id'], 'approve', 'production_orders', $pdoId, ['status' => $oldStatus], ['status' => 'approved']);
            setFlash('success', "Perintah Kerja Produksi (SPK) {$pdo['po_number']} telah disetujui.");
            header("Location: index.php?page=production/order_detail&id={$pdoId}");
            exit;
        } elseif ($action === 'reject_spk' && $pdo['status'] === 'pending_approval') {
            if (!hasRole(['Supervisor / Manajer', 'Administrator', 'manager', 'admin'])) {
                throw new Exception("Anda tidak berwenang menolak perintah produksi.");
            }
            $rejectReason = trim($_POST['reject_reason'] ?? 'Ditolak manajer');
            $db->prepare("UPDATE production_orders SET status = 'cancelled', notes = CONCAT_WS(' | Ditolak: ', notes, :reason), updated_at = NOW(), updated_by = :uid WHERE id = :id")->execute([':reason' => $rejectReason, ':uid' => $user['id'], ':id' => $pdoId]);
            ApprovalService::reject('production_order', $pdoId, $pdo['po_number'], $user['id'], $rejectReason, $db);
            logAudit($user['id'], 'reject', 'production_orders', $pdoId, ['status' => 'pending_approval'], ['status' => 'cancelled', 'reason' => $rejectReason]);
            setFlash('warning', "Perintah Kerja Produksi (SPK) {$pdo['po_number']} telah ditolak.");
            header("Location: index.php?page=production/order_detail&id={$pdoId}");
            exit;
        } elseif ($action === 'start_production' && in_array($pdo['status'], ['approved', 'waiting_material'])) {
            $upd = $db->prepare("UPDATE production_orders SET status = 'in_progress', actual_start = CURDATE(), updated_at = NOW(), updated_by = :uid WHERE id = :id");
            $upd->execute([':uid' => $user['id'], ':id' => $pdoId]);
            logAudit($user['id'], 'start', 'production_orders', $pdoId, null, 'Memulai proses produksi di lantai pabrik');
            setFlash('success', 'Status produksi diperbarui menjadi: Sedang Berjalan (In Progress).');
        } elseif ($action === 'add_machine_log') {
            $machineName = trim($_POST['machine_name'] ?? '');
            $startTime   = trim($_POST['start_time'] ?? date('Y-m-d H:i:s'));
            $endTime     = trim($_POST['end_time'] ?? date('Y-m-d H:i:s'));
            $duration    = (int)($_POST['duration_minutes'] ?? 0);
            $operator    = trim($_POST['operator'] ?? $user['full_name']);
            $notes       = trim($_POST['notes'] ?? '');

            if (empty($machineName)) {
                throw new Exception('Nama mesin wajib diisi.');
            }

            $stmtMl = $db->prepare("
                INSERT INTO machine_logs (production_order_id, machine_name, start_time, end_time, duration_minutes, operator, notes, created_by, created_at)
                VALUES (:pdo_id, :mname, :start_t, :end_t, :dur, :op, :notes, :uid, NOW())
            ");
            $stmtMl->execute([
                ':pdo_id'  => $pdoId,
                ':mname'   => $machineName,
                ':start_t' => $startTime,
                ':end_t'   => $endTime,
                ':dur'     => $duration,
                ':op'      => $operator,
                ':notes'   => $notes,
                ':uid'     => $user['id'],
            ]);
            setFlash('success', "Aktivitas mesin {$machineName} berhasil dicatat.");
        } elseif ($action === 'record_qc') {
            $params   = trim($_POST['parameters'] ?? '');
            $result   = trim($_POST['result'] ?? 'pass');
            $findings = trim($_POST['findings'] ?? '');
            $notes    = trim($_POST['notes'] ?? '');

            $inspNum = generateDocNumber('QC', 'qc_inspections', 'inspection_number');

            $stmtQc = $db->prepare("
                INSERT INTO qc_inspections (inspection_number, ref_type, ref_id, inspection_date, inspector_id, parameters, result, findings, notes, created_by, created_at)
                VALUES (:inum, 'production', :pdo_id, CURDATE(), :ins_id, :params, :res, :find, :notes, :uid, NOW())
            ");
            $stmtQc->execute([
                ':inum'    => $inspNum,
                ':pdo_id'  => $pdoId,
                ':ins_id'  => $user['id'],
                ':params'  => $params,
                ':res'     => $result,
                ':find'    => $findings,
                ':notes'   => $notes,
                ':uid'     => $user['id'],
            ]);
            $qcId = $db->lastInsertId();

            // Update PO status to waiting_qc or in_progress based on result
            $newStatus = ($result === 'pass') ? 'waiting_qc' : 'rework';
            $db->prepare("UPDATE production_orders SET status = :status, updated_at = NOW() WHERE id = :id")->execute([':status' => $newStatus, ':id' => $pdoId]);

            setFlash('success', "Pemeriksaan QC {$inspNum} berhasil dicatat dengan hasil: " . strtoupper($result) . ".");
        } elseif ($action === 'record_output') {
            // Finished Goods Output & Scrap
            $qtyGood   = parseDecimal($_POST['qty_good'] ?? 0);
            $qtyScrap  = parseDecimal($_POST['qty_scrap'] ?? 0);
            $scrapNote = trim($_POST['scrap_reason'] ?? 'Produk gagal spesifikasi / cacat mesin');
            $qcId      = !empty($_POST['qc_inspection_id']) ? (int)$_POST['qc_inspection_id'] : null;

            if ($qtyGood <= 0 && $qtyScrap <= 0) {
                throw new Exception('Kuantitas hasil produksi atau scrap harus lebih besar dari 0.');
            }

            $db->beginTransaction();
            try {
                // Record Output
                $stmtOut = $db->prepare("
                    INSERT INTO production_outputs (production_order_id, output_date, qty_good, qty_scrap, qc_inspection_id, warehouse_id, notes, status, created_by, created_at)
                    VALUES (:pdo_id, CURDATE(), :good, :scrap, :qcid, :wh_id, 'Hasil produksi batch', 'passed', :uid, NOW())
                ");
                $stmtOut->execute([
                    ':pdo_id' => $pdoId,
                    ':good'   => $qtyGood,
                    ':scrap'  => $qtyScrap,
                    ':qcid'   => $qcId,
                    ':wh_id'  => $pdo['warehouse_output_id'],
                    ':uid'    => $user['id'],
                ]);
                $outputId = $db->lastInsertId();

                // If scrap exists, record scrap
                if ($qtyScrap > 0) {
                    $stmtScrap = $db->prepare("
                        INSERT INTO scrap_records (production_order_id, production_output_id, item_id, qty, reason, scrap_date, created_by, created_at)
                        VALUES (:pdo_id, :out_id, :item_id, :qty, :reason, CURDATE(), :uid, NOW())
                    ");
                    $stmtScrap->execute([
                        ':pdo_id'  => $pdoId,
                        ':out_id'  => $outputId,
                        ':item_id' => $pdo['product_item_id'],
                        ':qty'     => $qtyScrap,
                        ':reason'  => $scrapNote,
                        ':uid'     => $user['id'],
                    ]);
                }

                // ATOMIC INVENTORY ADDITION ONLY FOR GOOD FINISHED GOODS (PRD 8.2, 8.4, 20)
                if ($qtyGood > 0) {
                    InventoryService::addStock(
                        $pdo['product_item_id'],
                        $pdo['warehouse_output_id'],
                        $qtyGood,
                        'production_output',
                        $outputId,
                        $pdo['po_number'],
                        "Penyelesaian produksi dari {$pdo['po_number']}",
                        $user['id'],
                        $db
                    );
                }

                // Update PO actuals and mark completed
                $stmtUpdPo = $db->prepare("
                    UPDATE production_orders 
                    SET actual_qty = actual_qty + :good, scrap_qty = scrap_qty + :scrap, 
                        status = 'completed', actual_end = CURDATE(), updated_at = NOW(), updated_by = :uid 
                    WHERE id = :id
                ");
                $stmtUpdPo->execute([
                    ':good'  => $qtyGood,
                    ':scrap' => $qtyScrap,
                    ':uid'   => $user['id'],
                    ':id'    => $pdoId,
                ]);

                // Calculate standard production cost record
                $db->prepare("
                    INSERT INTO production_costs (production_order_id, cost_type, description, amount, created_by, created_at)
                    VALUES 
                    (:pdo_id, 'material', 'Biaya Bahan Baku Terpakai', :mat_cost, :uid, NOW()),
                    (:pdo_id, 'labor', 'Alokasi Upah Tenaga Kerja Produksi', :lab_cost, :uid, NOW()),
                    (:pdo_id, 'overhead', 'Alokasi Beban Listrik & Mesin (BOP)', :bop_cost, :uid, NOW())
                ")->execute([
                    ':pdo_id'   => $pdoId,
                    ':mat_cost' => $qtyGood * 52500, // standard calculation
                    ':lab_cost' => $qtyGood * 12000,
                    ':bop_cost' => $qtyGood * 10500,
                    ':uid'      => $user['id'],
                ]);

                // Accounting Journal for Finished Goods Production (PRD 13)
                // Debit: Persediaan Barang Jadi (Akun 1340 / ID 10)
                // Credit: Biaya Bahan Baku Terpakai (Akun 5200 / ID 20)
                $totalProdValue = $qtyGood * 75000;
                $accBarangJadi = JournalService::getAccountIdByCode('1340', $db) ?: 10;
                $accBiayaBahan = JournalService::getAccountIdByCode('5200', $db) ?: 20;

                $journalLines = [
                    [
                        'account_id'  => $accBarangJadi,
                        'description' => "Penerimaan hasil barang jadi produksi {$pdo['product_name']} ({$qtyGood} {$pdo['unit_name']})",
                        'debit'       => $totalProdValue,
                        'credit'      => 0.00,
                    ],
                    [
                        'account_id'  => $accBiayaBahan,
                        'description' => "Pengakuan biaya produksi SPK {$pdo['po_number']}",
                        'debit'       => 0.00,
                        'credit'      => $totalProdValue,
                    ],
                ];

                JournalService::createEntry(
                    date('Y-m-d'),
                    'production_order',
                    $pdoId,
                    $pdo['po_number'],
                    "Penyelesaian Hasil Produksi {$pdo['po_number']} - {$pdo['product_name']}",
                    $journalLines,
                    $user['id'],
                    true,
                    $db
                );

                $db->commit();
                setFlash('success', "Hasil produksi {$qtyGood} {$pdo['unit_name']} berhasil dimasukkan ke Gudang Barang Jadi, dan {$qtyScrap} tercatat sebagai scrap.");
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        } elseif ($action === 'cancel_order') {
            $reason = trim($_POST['cancel_reason'] ?? '');
            if (empty($reason)) {
                throw new Exception('Alasan pembatalan perintah produksi wajib diisi.');
            }
            if (in_array($pdo['status'], ['completed', 'cancelled'])) {
                throw new Exception("Perintah produksi tidak dapat dibatalkan karena berstatus {$pdo['status']}.");
            }

            // Check non-failed outputs
            $cntOut = (int)$db->query("SELECT COUNT(*) FROM production_outputs WHERE production_order_id = {$pdoId} AND status != 'failed'")->fetchColumn();
            if ($cntOut > 0) {
                throw new Exception('Tidak dapat membatalkan SPK karena sudah ada barang jadi yang dicatat. Batalkan/void hasil produksi terlebih dahulu.');
            }

            // Check active material issues
            $cntMi = (int)$db->query("SELECT COUNT(*) FROM material_issues WHERE production_order_id = {$pdoId} AND status = 'issued'")->fetchColumn();
            if ($cntMi > 0) {
                throw new Exception('Tidak dapat membatalkan SPK karena ada bahan baku yang sudah dikeluarkan dari gudang. Batalkan Bukti Pengeluaran Bahan (Material Issue) terlebih dahulu agar stok bahan kembali ke gudang.');
            }

            $db->prepare("
                UPDATE production_orders 
                SET status = 'cancelled', notes = CONCAT_WS(' | Pembatalan: ', notes, :reason), updated_at = NOW(), updated_by = :uid 
                WHERE id = :id
            ")->execute([
                ':reason' => $reason,
                ':uid'    => $user['id'],
                ':id'     => $pdoId,
            ]);

            logAudit($user['id'], 'cancel', 'production_orders', $pdoId, ['status' => $pdo['status']], ['status' => 'cancelled', 'reason' => $reason]);
            setFlash('success', "Perintah Kerja Produksi (SPK) {$pdo['po_number']} berhasil dibatalkan.");
            header("Location: index.php?page=production/order_detail&id={$pdoId}");
            exit;
        } elseif ($action === 'delete_order') {
            if ($pdo['status'] !== 'draft') {
                throw new Exception('Hanya SPK berstatus Draf yang dapat dihapus dari sistem.');
            }

            $blockers = checkRecordReferences('production_orders', $pdoId, ['production_materials']);
            if (!empty($blockers)) {
                throw new Exception('Tidak dapat menghapus SPK karena terdapat referensi: ' . implode(', ', $blockers));
            }

            $db->beginTransaction();
            try {
                $db->prepare("DELETE FROM production_materials WHERE production_order_id = :id")->execute([':id' => $pdoId]);
                $db->prepare("DELETE FROM production_orders WHERE id = :id")->execute([':id' => $pdoId]);
                logAudit($user['id'], 'delete', 'production_orders', $pdoId, $pdo, null);
                $db->commit();

                setFlash('success', "Draf Perintah Kerja Produksi {$pdo['po_number']} berhasil dihapus.");
                header('Location: index.php?page=production/orders');
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        }

        header("Location: index.php?page=production/order_detail&id={$pdoId}");
        exit;
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        header("Location: index.php?page=production/order_detail&id={$pdoId}");
        exit;
    }
}

// Fetch Materials
$materials = $db->prepare("
    SELECT pm.*, i.code AS item_code, i.name AS item_name, u.name AS unit_name
    FROM production_materials pm
    JOIN items i ON pm.item_id = i.id
    LEFT JOIN units u ON pm.unit_id = u.id
    WHERE pm.production_order_id = :pdo_id
");
$materials->execute([':pdo_id' => $pdoId]);
$materialList = $materials->fetchAll();

// Fetch Machine Logs
$machineLogs = $db->prepare("SELECT * FROM machine_logs WHERE production_order_id = :id ORDER BY id ASC");
$machineLogs->execute([':id' => $pdoId]);
$machineLogList = $machineLogs->fetchAll();

// Fetch QC Inspections
$qcInspections = $db->prepare("
    SELECT qc.*, u.full_name AS inspector_name 
    FROM qc_inspections qc 
    LEFT JOIN users u ON qc.inspector_id = u.id 
    WHERE qc.ref_type = 'production' AND qc.ref_id = :id
    ORDER BY qc.id DESC
");
$qcInspections->execute([':id' => $pdoId]);
$qcList = $qcInspections->fetchAll();

// Fetch Outputs
$outputs = $db->prepare("SELECT * FROM production_outputs WHERE production_order_id = :id ORDER BY id DESC");
$outputs->execute([':id' => $pdoId]);
$outputList = $outputs->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Perintah Produksi: <?= e($pdo['po_number']) ?></h1>
        <div class="page-subtitle">Status: <?= getStatusBadge($pdo['status']) ?> &bull; Produk: <strong><?= e($pdo['product_name']) ?></strong></div>
    </div>
    <div class="page-actions" style="display:flex; gap:8px; flex-wrap:wrap;">
        <a href="index.php?page=production/orders" class="btn btn-secondary">&laquo; Kembali</a>
        <a href="index.php?page=production/hpp_summary&id=<?= $pdo['id'] ?>" class="btn btn-secondary">
            📊 Laporan HPP
        </a>
        <?php if (in_array($pdo['status'], ['draft', 'pending_approval'])): ?>
            <a href="index.php?page=production/order_create&id=<?= $pdo['id'] ?>" class="btn btn-primary">
                ✏️ Edit SPK
            </a>
        <?php endif; ?>
        <?php if (!in_array($pdo['status'], ['completed', 'cancelled'])): ?>
            <button type="button" class="btn btn-danger" onclick="document.getElementById('modalCancelPdo').style.display='flex'">
                🚫 Batalkan SPK
            </button>
        <?php endif; ?>
        <?php if ($pdo['status'] === 'draft'): ?>
            <button type="button" class="btn btn-outline-danger" onclick="document.getElementById('modalDeletePdo').style.display='flex'">
                🗑️ Hapus Draf
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="detail-grid">
    <div class="detail-item">
        <div class="detail-label">Produk & Resep (BOM)</div>
        <div class="detail-value">
            <?= e($pdo['product_name']) ?> (<code><?= e($pdo['product_code']) ?></code>)<br>
            <span style="font-size: 11.5px; color: var(--text-muted);"><?= e($pdo['bom_name']) ?></span>
        </div>
    </div>
    <div class="detail-item">
        <div class="detail-label">Target vs Realisasi Hasil</div>
        <div class="detail-value">
            Target: <strong><?= formatNumber($pdo['target_qty']) ?> <?= e($pdo['unit_name']) ?></strong><br>
            Hasil Lolos: <span style="color: var(--success); font-weight: 700;"><?= formatNumber($pdo['actual_qty']) ?></span> &bull; Scrap: <span style="color: var(--danger); font-weight: 700;"><?= formatNumber($pdo['scrap_qty']) ?></span>
        </div>
    </div>
    <div class="detail-item">
        <div class="detail-label">Jadwal Perintah Kerja</div>
        <div class="detail-value">
            Rencana: <?= formatDate($pdo['planned_start']) ?> s/d <?= formatDate($pdo['planned_end']) ?><br>
            Aktual: <?= formatDate($pdo['actual_start']) ?> s/d <?= formatDate($pdo['actual_end']) ?>
        </div>
    </div>
    <div class="detail-item">
        <div class="detail-label">Alur Gudang Persediaan</div>
        <div class="detail-value">
            Bahan: <?= e($pdo['wh_material_name']) ?><br>
            &rarr; Barang Jadi: <?= e($pdo['wh_output_name']) ?>
        </div>
    </div>
</div>

<!-- Quick Action Workflow Bar -->
<div class="card" style="background-color: #f8fafc;">
    <div class="card-body" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: space-between;">
        <div style="font-weight: 600; color: var(--primary);">
            Tahapan Operasional Produksi:
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <?php if ($pdo['status'] === 'draft'): ?>
                <form action="index.php?page=production/order_detail&id=<?= $pdoId ?>" method="POST" style="display: inline;">
                    <?= csrfField() ?>
                    <button type="submit" name="action" value="submit_approval" class="btn btn-warning">
                        📤 Ajukan Otorisasi SPK
                    </button>
                </form>
                <?php if (hasRole(['Supervisor / Manajer', 'Administrator', 'manager', 'admin', 'ppic'])): ?>
                    <form action="index.php?page=production/order_detail&id=<?= $pdoId ?>" method="POST" style="display: inline;" onsubmit="return confirm('Setujui dan terbitkan SPK ini secara langsung?');">
                        <?= csrfField() ?>
                        <button type="submit" name="action" value="approve_spk" class="btn btn-primary">
                            ✓ Terbitkan &amp; Setujui Langsung
                        </button>
                    </form>
                <?php endif; ?>
            <?php elseif ($pdo['status'] === 'pending_approval'): ?>
                <?php if (hasRole(['Supervisor / Manajer', 'Administrator', 'manager', 'admin', 'ppic'])): ?>
                    <form action="index.php?page=production/order_detail&id=<?= $pdoId ?>" method="POST" style="display: inline;" onsubmit="return confirm('Apakah Anda menyetujui Perintah Kerja Produksi (SPK) ini?');">
                        <?= csrfField() ?>
                        <button type="submit" name="action" value="approve_spk" class="btn btn-success">
                            ✓ Setujui SPK (Approve)
                        </button>
                    </form>
                    <form action="index.php?page=production/order_detail&id=<?= $pdoId ?>" method="POST" style="display: inline;" onsubmit="return confirm('Tolak Perintah Kerja Produksi (SPK) ini?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="reject_reason" value="Ditolak pada peninjauan manajer">
                        <button type="submit" name="action" value="reject_spk" class="btn btn-danger">
                            ✕ Tolak SPK
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (in_array($pdo['status'], ['approved', 'waiting_material'])): ?>
                <form action="index.php?page=production/order_detail&id=<?= $pdoId ?>" method="POST" style="display: inline;">
                    <?= csrfField() ?>
                    <button type="submit" name="action" value="start_production" class="btn btn-primary">
                        ▶ Mulai Produksi di Pabrik
                    </button>
                </form>
            <?php endif; ?>

            <?php if (in_array($pdo['status'], ['approved', 'waiting_material', 'in_progress'])): ?>
                <a href="index.php?page=production/material_issues&pdo_id=<?= $pdoId ?>" class="btn btn-warning">
                    📦 Pengeluaran Bahan Baku
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Panel 1: Bahan Baku -->
<div class="card">
    <div class="card-header">
        <span class="card-title">1. Pemakaian Bahan Baku (Rencana Formula vs Aktual Terpakai)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Kode</th>
                    <th>Nama Bahan Baku</th>
                    <th class="text-right">Kebutuhan Rencana</th>
                    <th class="text-right">Pemakaian Aktual</th>
                    <th>Satuan</th>
                    <th>Keterangan</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($materialList as $idx => $m): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><code><?= e($m['item_code']) ?></code></td>
                        <td><strong><?= e($m['item_name']) ?></strong></td>
                        <td class="text-right"><?= formatNumber($m['qty_planned'], 4) ?></td>
                        <td class="text-right" style="font-weight: 600; color: var(--primary);">
                            <?= formatNumber($m['qty_actual'] > 0 ? $m['qty_actual'] : $m['qty_planned'], 4) ?>
                        </td>
                        <td><?= e($m['unit_name']) ?></td>
                        <td><?= e($m['notes'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Panel 2: Aktivitas & Waktu Kerja Mesin -->
<div class="card">
    <div class="card-header">
        <span class="card-title">2. Log Waktu Kerja & Operasional Mesin</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Nama Mesin Produksi</th>
                    <th>Waktu Mulai</th>
                    <th>Waktu Selesai</th>
                    <th class="text-right">Durasi (Menit)</th>
                    <th>Operator</th>
                    <th>Catatan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($machineLogList)): ?>
                    <tr><td colspan="6" class="text-center" style="padding: 14px; color: var(--text-muted);">Belum ada log mesin dicatat.</td></tr>
                <?php else: ?>
                    <?php foreach ($machineLogList as $ml): ?>
                        <tr>
                            <td><strong><?= e($ml['machine_name']) ?></strong></td>
                            <td><?= formatDateTime($ml['start_time']) ?></td>
                            <td><?= formatDateTime($ml['end_time']) ?></td>
                            <td class="text-right font-weight-bold"><?= $ml['duration_minutes'] ?> menit</td>
                            <td><?= e($ml['operator']) ?></td>
                            <td><?= e($ml['notes'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pdo['status'] === 'in_progress'): ?>
        <div class="card-footer">
            <details>
                <summary style="cursor: pointer; font-weight: 600; color: var(--primary);">+ Tambah Catatan Operasional Mesin</summary>
                <form action="index.php?page=production/order_detail&id=<?= $pdoId ?>" method="POST" style="margin-top: 14px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_machine_log">
                    <div class="form-row">
                        <div class="col-md-4">
                            <input type="text" name="machine_name" class="form-control" placeholder="Nama mesin (cth: Mixer Adonan #1)" required>
                        </div>
                        <div class="col-md-3">
                            <input type="number" name="duration_minutes" class="form-control" placeholder="Durasi kerja (menit)" value="120" required>
                        </div>
                        <div class="col-md-3">
                            <input type="text" name="operator" class="form-control" placeholder="Nama operator mesin" value="<?= e($user['full_name']) ?>" required>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary" style="width: 100%;">Simpan Log</button>
                        </div>
                    </div>
                </form>
            </details>
        </div>
    <?php endif; ?>
</div>

<!-- Panel 3: Pemeriksaan QC -->
<div class="card">
    <div class="card-header">
        <span class="card-title">3. Pemeriksaan Mutu (Quality Control / CCP)</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>No. Inspeksi QC</th>
                    <th>Tanggal</th>
                    <th>Inspektur</th>
                    <th>Parameter Diuji</th>
                    <th class="text-center">Hasil QC</th>
                    <th>Temuan & Catatan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($qcList)): ?>
                    <tr><td colspan="6" class="text-center" style="padding: 14px; color: var(--text-muted);">Belum ada inspeksi QC tercatat.</td></tr>
                <?php else: ?>
                    <?php foreach ($qcList as $qc): ?>
                        <tr>
                            <td><strong><?= e($qc['inspection_number']) ?></strong></td>
                            <td><?= formatDate($qc['inspection_date']) ?></td>
                            <td><?= e($qc['inspector_name']) ?></td>
                            <td><?= e($qc['parameters']) ?></td>
                            <td class="text-center"><?= getStatusBadge($qc['result']) ?></td>
                            <td>
                                <strong><?= e($qc['findings']) ?></strong><br>
                                <span style="font-size: 11px; color: var(--text-muted);"><?= e($qc['notes'] ?: '-') ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pdo['status'] === 'in_progress' || $pdo['status'] === 'waiting_qc'): ?>
        <div class="card-footer">
            <details>
                <summary style="cursor: pointer; font-weight: 600; color: var(--primary);">+ Input Hasil Pemeriksaan Mutu (QC)</summary>
                <form action="index.php?page=production/order_detail&id=<?= $pdoId ?>" method="POST" style="margin-top: 14px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="record_qc">
                    <div class="form-row">
                        <div class="col-md-4">
                            <label class="form-label required">Parameter Pemeriksaan</label>
                            <input type="text" name="parameters" class="form-control" value="Kadar air, rasa, kerenyahan, berat netto per pak" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required">Keputusan Hasil Mutu</label>
                            <select name="result" class="form-control" required>
                                <option value="pass">Lulus QC (Pass)</option>
                                <option value="fail">Tidak Lulus / Gagal (Fail)</option>
                                <option value="conditional">Lulus Bersyarat (Rework)</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label required">Temuan Pemeriksaan</label>
                            <input type="text" name="findings" class="form-control" placeholder="Contoh: Kerenyahan dan warna panggangan merata" required>
                        </div>
                    </div>
                    <div style="margin-top: 10px; display: flex; justify-content: flex-end;">
                        <button type="submit" class="btn btn-success">Simpan Hasil Pemeriksaan QC</button>
                    </div>
                </form>
            </details>
        </div>
    <?php endif; ?>
</div>

<!-- Panel 4: Penyelesaian Hasil Produksi (Output & Scrap) -->
<?php if (in_array($pdo['status'], ['waiting_qc', 'in_progress'])): ?>
    <div class="card" style="border-left: 4px solid var(--success);">
        <div class="card-header">
            <span class="card-title">4. Penyelesaian Batch Produksi: Simpan Barang Jadi & Scrap</span>
        </div>
        <div class="card-body">
            <form action="index.php?page=production/order_detail&id=<?= $pdoId ?>" method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="record_output">
                <?php if (!empty($qcList)): ?>
                    <input type="hidden" name="qc_inspection_id" value="<?= $qcList[0]['id'] ?>">
                <?php endif; ?>
                
                <div class="form-row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label required" style="color: var(--success);">Kuantitas Lulus QC (Barang Jadi Masuk Stok)</label>
                            <input type="text" name="qty_good" class="form-control text-right" style="font-weight: 700; border-color: var(--success);" value="<?= formatNumber($pdo['target_qty']) ?>" required>
                            <div class="form-text">Satuan: <?= e($pdo['unit_name']) ?> &bull; Akan langsung menambah persediaan gudang</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label class="form-label" style="color: var(--danger);">Kuantitas Scrap / Gagal</label>
                            <input type="text" name="qty_scrap" class="form-control text-right" style="border-color: var(--danger-border);" value="0">
                            <div class="form-text">Tidak masuk ke stok siap jual</div>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="form-group">
                            <label class="form-label">Alasan Scrap / Kerusakan (Bila ada)</label>
                            <input type="text" name="scrap_reason" class="form-control" placeholder="Contoh: Remuk conveyor potong atau gosong pemanggang">
                        </div>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; margin-top: 10px;">
                    <button type="submit" class="btn btn-success" style="font-weight: 600;">
                        ✓ Selesaikan Produksi, Tambah Stok Barang Jadi & Buat Jurnal Akuntansi
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- Modal Pembatalan SPK -->
<div id="modalCancelPdo" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; border-radius:8px; max-width:500px; width:90%; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:var(--danger);">Konfirmasi Pembatalan Perintah Produksi</h3>
        <p style="color:var(--text-muted); font-size:14px;">
            Anda akan membatalkan SPK <strong><?= e($pdo['po_number']) ?></strong>. Pembatalan memerlukan alasan wajib dan akan dicatat ke dalam audit trail. Pastikan tidak ada pengeluaran bahan aktif atau barang jadi yang sudah dicatat.
        </p>
        <form action="index.php?page=production/order_detail&id=<?= $pdoId ?>" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel_order">
            <div class="form-group" style="margin-bottom:16px;">
                <label class="form-label required">Alasan Pembatalan</label>
                <textarea name="cancel_reason" class="form-control" rows="3" placeholder="Masukkan alasan rinci pembatalan SPK..." required></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalCancelPdo').style.display='none'">Tutup</button>
                <button type="submit" class="btn btn-danger">Konfirmasi Pembatalan SPK</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Hapus Draf SPK -->
<div id="modalDeletePdo" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; border-radius:8px; max-width:480px; width:90%; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0; color:var(--danger);">Hapus Draf Perintah Produksi</h3>
        <p style="color:var(--text-muted); font-size:14px;">
            Apakah Anda yakin ingin menghapus draf SPK <strong><?= e($pdo['po_number']) ?></strong>? Tindakan ini permanen dan hanya dapat dilakukan selama belum ada transaksi operasional terkait.
        </p>
        <form action="index.php?page=production/order_detail&id=<?= $pdoId ?>" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_order">
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('modalDeletePdo').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-danger">Ya, Hapus Draf</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
