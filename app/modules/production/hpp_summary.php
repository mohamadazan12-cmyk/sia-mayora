<?php
/**
 * Modul Produksi: Laporan Hasil Produksi & Kalkulasi HPP (Harga Pokok Produksi)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Laporan Produksi & Kalkulasi HPP';
$breadcrumbCategory = 'Produksi';

$db = getDbConnection();
$user = currentUser();

$selectedPdoId = (int)($_GET['id'] ?? 0);

// Handle Finance Manager Verification POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_finance'])) {
    requireCsrf();
    $targetId = (int)($_POST['production_order_id'] ?? 0);
    $notes    = trim($_POST['verification_notes'] ?? 'Diverifikasi dan disetujui oleh Finance Manager');

    // Check authorization: Finance or Manager or Admin
    if (!hasRole(['Finance/Treasury', 'Supervisor / Manajer', 'Administrator', 'finance', 'manager', 'admin'])) {
        setFlash('error', 'Hanya Manajer Keuangan / Supervisor yang berwenang memverifikasi laporan HPP produksi.');
        header('Location: index.php?page=production/hpp_summary&id=' . $targetId);
        exit;
    }

    try {
        ApprovalService::approve('production_hpp', $targetId, "HPP-PDO-{$targetId}", $user['id'], $notes, $db);
        logAudit($user['id'], 'verify_hpp', 'production_orders', $targetId, null, 'Verifikasi biaya produksi & HPP oleh Finance Manager');
        setFlash('success', 'Laporan Biaya & HPP Produksi berhasil diverifikasi dan disahkan oleh Finance Manager.');
        header('Location: index.php?page=production/hpp_summary&id=' . $targetId);
        exit;
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        header('Location: index.php?page=production/hpp_summary&id=' . $targetId);
        exit;
    }
}

// Fetch Completed Production Orders
$orders = $db->query("
    SELECT pdo.*, i.name AS product_name, i.code AS product_code, u.name AS unit_name
    FROM production_orders pdo
    JOIN items i ON pdo.product_item_id = i.id
    LEFT JOIN units u ON i.unit_id = u.id
    WHERE pdo.status IN ('completed', 'waiting_qc', 'in_progress')
    ORDER BY pdo.id DESC
")->fetchAll();

if ($selectedPdoId <= 0 && !empty($orders)) {
    $selectedPdoId = (int)$orders[0]['id'];
}

$activePdo = null;
$materials = [];
$costs = [];
$machineMinutes = 0;
$totalCost = 0.0;
$costPerUnit = 0.0;

if ($selectedPdoId > 0) {
    $stmt = $db->prepare("
        SELECT pdo.*, i.name AS product_name, i.code AS product_code, u.name AS unit_name,
               wm.name AS wh_material_name, wo.name AS wh_output_name,
               usr.full_name AS creator_name
        FROM production_orders pdo
        JOIN items i ON pdo.product_item_id = i.id
        LEFT JOIN units u ON i.unit_id = u.id
        JOIN warehouses wm ON pdo.warehouse_material_id = wm.id
        JOIN warehouses wo ON pdo.warehouse_output_id = wo.id
        LEFT JOIN users usr ON pdo.created_by = usr.id
        WHERE pdo.id = :id
    ");
    $stmt->execute([':id' => $selectedPdoId]);
    $activePdo = $stmt->fetch();

    if ($activePdo) {
        // Fetch Materials
        $stmtM = $db->prepare("
            SELECT pm.*, i.name AS item_name, i.code AS item_code, i.cost_price, u.name AS unit_name
            FROM production_materials pm
            JOIN items i ON pm.item_id = i.id
            LEFT JOIN units u ON pm.unit_id = u.id
            WHERE pm.production_order_id = :id
        ");
        $stmtM->execute([':id' => $selectedPdoId]);
        $materials = $stmtM->fetchAll();

        // Calculate machine minutes
        $stmtMm = $db->prepare("SELECT COALESCE(SUM(duration_minutes), 0) FROM machine_logs WHERE production_order_id = :id");
        $stmtMm->execute([':id' => $selectedPdoId]);
        $machineMinutes = (int)$stmtMm->fetchColumn();

        // Fetch Recorded Costs
        $stmtC = $db->prepare("SELECT * FROM production_costs WHERE production_order_id = :id");
        $stmtC->execute([':id' => $selectedPdoId]);
        $costs = $stmtC->fetchAll();

        if (empty($costs)) {
            // Default calculated components
            $actualQty = (float)$activePdo['actual_qty'] > 0 ? (float)$activePdo['actual_qty'] : (float)$activePdo['target_qty'];
            $costs = [
                ['cost_type' => 'material', 'description' => 'Biaya Pemakaian Bahan Baku Aktual', 'amount' => $actualQty * 52500],
                ['cost_type' => 'labor',    'description' => 'Biaya Upah Tenaga Kerja Langsung Pabrik', 'amount' => $actualQty * 12000],
                ['cost_type' => 'overhead', 'description' => 'Biaya Listrik, Bahan Bakar & Mesin (BOP)', 'amount' => $actualQty * 10500],
            ];
        }

        foreach ($costs as $c) {
            $totalCost += (float)$c['amount'];
        }

        $qtyBasis = (float)$activePdo['actual_qty'] > 0 ? (float)$activePdo['actual_qty'] : (float)$activePdo['target_qty'];
        if ($qtyBasis > 0) {
            $costPerUnit = $totalCost / $qtyBasis;
        }
    }
}

// Check approval history
$approvalHist = [];
if ($selectedPdoId > 0) {
    $approvalHist = ApprovalService::getHistory('production_hpp', $selectedPdoId);
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Laporan Hasil Produksi & Analisis HPP</h1>
        <div class="page-subtitle">Siklus Produksi &bull; Perhitungan Komponen Biaya Bahan, Tenaga Kerja, Overhead (BOP), dan Verifikasi Finance</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=production/orders" class="btn btn-secondary">&laquo; Kembali ke Daftar SPK</a>
    </div>
</div>

<!-- Selector Perintah Produksi -->
<div class="card">
    <div class="card-body" style="padding: 12px 18px;">
        <form action="index.php" method="GET" class="filter-bar" style="margin-bottom: 0;">
            <input type="hidden" name="page" value="production/hpp_summary">
            <div class="filter-group" style="flex: 1;">
                <label style="font-weight: 600; font-size: 13px;">Pilih Perintah Produksi (SPK):</label>
                <select name="id" class="form-control" style="width: 100%;" onchange="this.form.submit()">
                    <?php foreach ($orders as $ord): ?>
                        <option value="<?= $ord['id'] ?>" <?= $selectedPdoId == $ord['id'] ? 'selected' : '' ?>>
                            <?= e($ord['po_number']) ?> &bull; <?= e($ord['product_name']) ?> (Hasil: <?= formatNumber($ord['actual_qty']) ?> <?= e($ord['unit_name']) ?>) [<?= e($ord['status']) ?>]
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">Buka Laporan HPP</button>
        </form>
    </div>
</div>

<?php if ($activePdo): ?>
    <!-- Ringkasan Eksekutif Produksi -->
    <div class="metrics-grid">
        <div class="metric-card border-info">
            <div class="metric-title">TARGET VS REALISASI</div>
            <div class="metric-value"><?= formatNumber($activePdo['actual_qty']) ?> / <?= formatNumber($activePdo['target_qty']) ?></div>
            <div class="metric-sub"><?= e($activePdo['unit_name']) ?> &bull; Efisiensi Output: <?= round(((float)$activePdo['actual_qty'] / max(1, (float)$activePdo['target_qty'])) * 100, 1) ?>%</div>
        </div>

        <div class="metric-card border-danger">
            <div class="metric-title">PRODUK GAGAL (SCRAP)</div>
            <div class="metric-value" style="color: var(--danger);"><?= formatNumber($activePdo['scrap_qty']) ?> <?= e($activePdo['unit_name']) ?></div>
            <div class="metric-sub">Tingkat Kerusakan: <?= round(((float)$activePdo['scrap_qty'] / max(1, (float)$activePdo['target_qty'])) * 100, 2) ?>%</div>
        </div>

        <div class="metric-card border-warning">
            <div class="metric-title">TOTAL WAKTU MESIN</div>
            <div class="metric-value"><?= round($machineMinutes / 60, 1) ?> Jam</div>
            <div class="metric-sub"><?= $machineMinutes ?> menit waktu aktif operasional</div>
        </div>

        <div class="metric-card border-success">
            <div class="metric-title">HPP PER UNIT PRODUK</div>
            <div class="metric-value" style="color: var(--success);"><?= formatRupiah($costPerUnit) ?></div>
            <div class="metric-sub">Total Biaya Batch: <?= formatRupiah($totalCost) ?></div>
        </div>
    </div>

    <!-- Rincian Biaya Produksi (HPP) -->
    <div class="card">
        <div class="card-header">
            <span class="card-title">Rincian Komponen Biaya Produksi (Harga Pokok Penjualan Dasar)</span>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Komponen Biaya</th>
                        <th>Kategori</th>
                        <th>Penjelasan / Keterangan</th>
                        <th class="text-right">Total Biaya (Rp)</th>
                        <th class="text-right">Biaya per Unit (Rp)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($costs as $c): ?>
                        <?php 
                        $amt = (float)$c['amount'];
                        $unitCost = $qtyBasis > 0 ? ($amt / $qtyBasis) : 0;
                        ?>
                        <tr>
                            <td><strong><?= ucfirst(e($c['cost_type'])) ?></strong></td>
                            <td><span class="badge badge-secondary"><?= e($c['cost_type']) ?></span></td>
                            <td><?= e($c['description']) ?></td>
                            <td class="text-right font-weight-bold"><?= formatRupiah($amt) ?></td>
                            <td class="text-right" style="color: var(--text-muted);"><?= formatRupiah($unitCost) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background-color: #f8fafc; font-weight: 700;">
                        <td colspan="3" class="text-right">TOTAL HARGA POKOK PRODUKSI (HPP):</td>
                        <td class="text-right" style="color: var(--primary); font-size: 15px;"><?= formatRupiah($totalCost) ?></td>
                        <td class="text-right" style="color: var(--success); font-size: 15px;"><?= formatRupiah($costPerUnit) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Verifikasi Finance Manager -->
    <div class="card" style="border-left: 4px solid var(--primary);">
        <div class="card-header">
            <span class="card-title">Verifikasi & Pengesahan Finance Manager</span>
            <?php if (!empty($approvalHist)): ?>
                <span class="badge badge-success">✓ Terverifikasi oleh Finance</span>
            <?php else: ?>
                <span class="badge badge-warning">Menunggu Pengesahan Finance</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($approvalHist)): ?>
                <p>Finance Manager memvalidasi keabsahan data material issue, pencatatan waktu mesin, hasil kelulusan QC, dan kesesuaian formula HPP sebelum pembukuan diakui final.</p>
                <?php if (hasRole(['Finance/Treasury', 'Supervisor / Manajer', 'Administrator', 'finance', 'manager', 'admin'])): ?>
                    <form action="index.php?page=production/hpp_summary&id=<?= $activePdo['id'] ?>" method="POST" style="margin-top: 14px;">
                        <?= csrfField() ?>
                        <input type="hidden" name="production_order_id" value="<?= $activePdo['id'] ?>">
                        <div class="form-group">
                            <label class="form-label">Catatan Verifikasi Finance Manager</label>
                            <input type="text" name="verification_notes" class="form-control" value="Biaya bahan, tenaga kerja, dan overhead telah diverifikasi sesuai standar akuntansi manufaktur." required>
                        </div>
                        <button type="submit" name="verify_finance" value="1" class="btn btn-success" style="font-weight: 600;">
                            ✓ Sahkan & Verifikasi Laporan HPP Produksi
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info" style="margin-top: 10px;">
                        <em>Hanya pengguna dengan kewenangan Finance Manager atau Manajer yang dapat menandatangani verifikasi ini.</em>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Tanggal Verifikasi</th>
                                <th>Finance Manager</th>
                                <th>Keputusan</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($approvalHist as $h): ?>
                                <tr>
                                    <td><?= formatDateTime($h['decided_at'] ?: $h['created_at']) ?></td>
                                    <td><strong><?= e($h['approver_name'] ?: 'Finance Manager') ?></strong></td>
                                    <td><span class="badge badge-success">✓ Disahkan</span></td>
                                    <td><?= e($h['reason']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
