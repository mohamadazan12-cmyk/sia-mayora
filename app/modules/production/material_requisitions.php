<?php
/**
 * Modul Permintaan Bahan Baku (Material Requisitions - MR)
 * PT Mayora Indah Tbk.
 * Alur pengeluaran bahan dari Gudang ke Lantai Produksi berdasarkan SPK.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../services/inventory_service.php';
require_once __DIR__ . '/../../services/journal_service.php';

requireLogin();
enforcePageAccess('production/material_requisitions');

$db = getDbConnection();
$user = currentUser();

$search = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;

// Handle Actions (Approve, Issue)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $mrId   = (int)($_POST['mr_id'] ?? 0);

    if ($action === 'issue_materials' && $mrId > 0) {
        $db->beginTransaction();
        try {
            $mr = $db->query("SELECT mr.*, pdo.po_number, pdo.product_item_id, pdo.warehouse_material_id 
                FROM material_requisitions mr 
                JOIN production_orders pdo ON mr.production_order_id = pdo.id 
                WHERE mr.id = $mrId")->fetch(PDO::FETCH_ASSOC);

            if (!$mr) throw new Exception('Data Permintaan Bahan (MR) tidak ditemukan.');
            if ($mr['status'] === 'issued') throw new Exception('Material Requisition sudah dikeluarkan sebelumnya.');

            $mrItems = $db->query("SELECT mri.*, i.name as item_name, i.cost_price 
                FROM material_requisition_items mri 
                JOIN items i ON mri.item_id = i.id 
                WHERE mri.material_requisition_id = $mrId")->fetchAll(PDO::FETCH_ASSOC);

            $whId = $mr['warehouse_material_id'] ?: 1;
            $miNum = sprintf('MI-%s-%04d', date('ym'), rand(100, 9999));

            // Create Material Issue
            $stmtMi = $db->prepare("INSERT INTO material_issues (issue_number, production_order_id, warehouse_id, issue_date, status, created_by, created_at)
                VALUES (?, ?, ?, CURDATE(), 'issued', ?, NOW())");
            $stmtMi->execute([$miNum, $mr['production_order_id'], $whId, $user['id']]);
            $miId = (int)$db->lastInsertId();

            $totalMatCost = 0;
            $stmtMii = $db->prepare("INSERT INTO material_issue_items (material_issue_id, item_id, qty, lot_batch) VALUES (?, ?, ?, ?)");
            $stmtUpdMri = $db->prepare("UPDATE material_requisition_items SET qty_issued = qty_requested WHERE id = ?");

            foreach ($mrItems as $mi) {
                $qty = (float)$mi['qty_requested'];
                $cost = (float)$mi['cost_price'];
                $totalMatCost += ($qty * $cost);

                $stmtMii->execute([$miId, $mi['item_id'], $qty, 'LOT-FIFO-' . date('ymd')]);
                $stmtUpdMri->execute([$mi['id']]);

                // Reduce inventory stock
                InventoryService::reduceStock(
                    $mi['item_id'],
                    $whId,
                    $qty,
                    'material_issue',
                    $miId,
                    $miNum,
                    "Pengeluaran bahan untuk SPK {$mr['po_number']}",
                    $user['id'],
                    $db
                );
            }

            // Accounting Journal: Debit WIP (1330), Kredit Raw Material (1310)
            $accWIP = JournalService::getAccountIdByCode('1330', $db) ?: 9;
            $accRaw = JournalService::getAccountIdByCode('1310', $db) ?: 7;

            $jLines = [
                ['account_id' => $accWIP, 'description' => "Persediaan BDP - Bahan SPK {$mr['po_number']}", 'debit' => $totalMatCost, 'credit' => 0],
                ['account_id' => $accRaw, 'description' => "Pengeluaran Bahan Baku MR {$mr['requisition_number']}", 'debit' => 0, 'credit' => $totalMatCost]
            ];

            JournalService::createEntry(
                date('Y-m-d'),
                'material_issue',
                $miId,
                $miNum,
                "Pemakaian Bahan Baku SPK {$mr['po_number']} ($miNum)",
                $jLines,
                $user['id'],
                true,
                $db
            );

            // Update MR status
            $db->prepare("UPDATE material_requisitions SET status = 'issued', updated_at = NOW() WHERE id = ?")->execute([$mrId]);

            // Update SPK status to in_progress if waiting
            $db->prepare("UPDATE production_orders SET status = 'in_progress', actual_start = COALESCE(actual_start, CURDATE()) WHERE id = ? AND status IN ('approved', 'waiting_material')")
               ->execute([$mr['production_order_id']]);

            $db->commit();
            setFlash('success', "Bahan Baku untuk MR {$mr['requisition_number']} berhasil dikeluarkan ($miNum) dan dibukukan ke jurnal!");
            header("Location: index.php?page=production/material_requisitions");
            exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            setFlash('error', 'Gagal memproses pengeluaran bahan: ' . $e->getMessage());
            header("Location: index.php?page=production/material_requisitions");
            exit;
        }
    }
}

// Fetch MR List
$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(mr.requisition_number LIKE :q OR pdo.po_number LIKE :q OR i.name LIKE :q)';
    $params[':q'] = "%$search%";
}
if ($status !== '') {
    $where[] = 'mr.status = :status';
    $params[':status'] = $status;
}
$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM material_requisitions mr 
    JOIN production_orders pdo ON mr.production_order_id = pdo.id 
    JOIN items i ON pdo.product_item_id = i.id 
    WHERE $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$pagination = paginate($totalRows, $pageNo, $perPage);

$stmt = $db->prepare("SELECT mr.*, pdo.po_number, pdo.target_qty, i.name as product_name, i.code as product_code, u.code as unit_code,
    w.name as warehouse_name, u_req.full_name as requester_name
    FROM material_requisitions mr 
    JOIN production_orders pdo ON mr.production_order_id = pdo.id 
    JOIN items i ON pdo.product_item_id = i.id 
    JOIN units u ON i.unit_id = u.id 
    LEFT JOIN warehouses w ON mr.warehouse_id = w.id 
    LEFT JOIN users u_req ON mr.requested_by = u_req.id 
    WHERE $whereSql 
    ORDER BY mr.id DESC 
    LIMIT :offset, :per_page");

foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmt->bindValue(':per_page', $pagination['per_page'], PDO::PARAM_INT);
$stmt->execute();
$requisitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Permintaan Bahan Baku (Material Requisition)';
$breadcrumbCategory = 'Produksi';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 class="page-title" style="margin: 0; font-size: 22px; font-weight: 700;">Permintaan Bahan Baku (Material Requisition - MR)</h1>
        <div class="page-subtitle" style="color: #64748b; font-size: 13px; margin-top: 4px;">Siklus Produksi &bull; Dokumen otorisasi pemindahan bahan baku dari Gudang ke Lantai Pabrik Mayora</div>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="index.php?page=production/orders" class="btn btn-primary" style="background: #EE2726; color: #fff; font-weight: 600; padding: 8px 14px; border-radius: 6px; text-decoration: none;">
            Daftar SPK Produksi &rarr;
        </a>
    </div>
</div>

<!-- Filter Bar -->
<form action="index.php" method="GET" class="filter-bar" style="display: flex; gap: 10px; align-items: center; margin-bottom: 20px; flex-wrap: wrap;">
    <input type="hidden" name="page" value="production/material_requisitions">
    <div class="filter-group">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari No. MR, SPK, produk..." style="padding: 7px 12px; border: 1px solid #cbd5e1; border-radius: 6px; min-width: 240px; font-size: 13px;">
    </div>
    <div class="filter-group">
        <select name="status" style="padding: 7px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
            <option value="">-- Semua Status MR --</option>
            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option>
            <option value="submitted" <?= $status === 'submitted' ? 'selected' : '' ?>>Diajukan (Submitted)</option>
            <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Disetujui PPIC</option>
            <option value="issued" <?= $status === 'issued' ? 'selected' : '' ?>>Dikeluarkan (Issued)</option>
            <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Dibatalkan</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm" style="padding: 7px 14px; border-radius: 6px;">Filter</button>
    <?php if ($search !== '' || $status !== ''): ?>
        <a href="index.php?page=production/material_requisitions" class="btn btn-sm btn-secondary" style="padding: 7px 14px; border-radius: 6px; text-decoration: none;">Reset</a>
    <?php endif; ?>
</form>

<!-- Table MR -->
<div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
    <div class="table-responsive">
        <table class="table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left; font-size: 12px; color: #475569;">
                    <th style="padding: 12px 16px;">No. Requisition (MR)</th>
                    <th style="padding: 12px 16px;">Ref. Perintah Kerja (SPK)</th>
                    <th style="padding: 12px 16px;">Target Produk Jadi</th>
                    <th style="padding: 12px 16px;">Tanggal Pengajuan</th>
                    <th style="padding: 12px 16px;">Gudang Sumber</th>
                    <th style="padding: 12px 16px; text-align: center;">Status MR</th>
                    <th style="padding: 12px 16px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requisitions)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 36px 16px; color: #94a3b8;">
                            Belum ada dokumen Material Requisition (MR).
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($requisitions as $m): 
                        $statusBadge = [
                            'draft'     => '<span class="badge" style="background:#f1f5f9; color:#475569; font-size:11px;">Draft</span>',
                            'submitted' => '<span class="badge" style="background:#e0f2fe; color:#0369a1; font-size:11px;">Diajukan</span>',
                            'approved'  => '<span class="badge" style="background:#fef3c7; color:#b45309; font-size:11px;">Disetujui</span>',
                            'issued'    => '<span class="badge" style="background:#dcfce7; color:#15803d; font-size:11px;">✓ Dikeluarkan</span>',
                            'cancelled' => '<span class="badge" style="background:#fee2e2; color:#dc2626; font-size:11px;">Batal</span>',
                        ];
                    ?>
                    <tr style="border-bottom: 1px solid #f1f5f9; font-size: 13px;">
                        <td style="padding: 12px 16px;">
                            <strong><?= e($m['requisition_number']) ?></strong><br>
                            <span style="font-size: 11px; color: #64748b;">Pemohon: <?= e($m['requester_name'] ?: 'PPIC') ?></span>
                        </td>
                        <td style="padding: 12px 16px;">
                            <a href="index.php?page=production/order_detail&id=<?= (int)$m['production_order_id'] ?>" style="color: #EE2726; font-weight: 600; text-decoration: none;">
                                <?= e($m['po_number']) ?> &rarr;
                            </a>
                        </td>
                        <td style="padding: 12px 16px;">
                            <strong><?= e($m['product_name']) ?></strong><br>
                            <span style="font-size: 11.5px; color: #64748b;">Target: <?= number_format((float)$m['target_qty'], 0, ',', '.') ?> <?= e($m['unit_code']) ?></span>
                        </td>
                        <td style="padding: 12px 16px; font-size: 12.5px;">
                            <?= formatDate($m['request_date']) ?>
                        </td>
                        <td style="padding: 12px 16px; font-size: 12.5px; color: #475569;">
                            <?= e($m['warehouse_name'] ?: 'Gudang Bahan Baku') ?>
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <?= $statusBadge[$m['status']] ?? $m['status'] ?>
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <?php if (in_array($m['status'], ['submitted', 'approved']) && hasRole(['admin', 'gudang', 'manager'])): ?>
                            <form method="POST" action="index.php?page=production/material_requisitions" style="margin: 0; display: inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="issue_materials">
                                <input type="hidden" name="mr_id" value="<?= (int)$m['id'] ?>">
                                <button type="submit" onclick="return confirm('Keluarkan bahan baku dari stok gudang dan bukukan ke jurnal WIP?');" class="btn btn-sm btn-primary" style="background: #15803d; color: #fff; font-size: 11px; padding: 5px 10px; border-radius: 4px; border: none; cursor: pointer;">
                                    📦 Keluarkan Bahan
                                </button>
                            </form>
                            <?php else: ?>
                            <a href="index.php?page=production/order_detail&id=<?= (int)$m['production_order_id'] ?>" class="btn btn-sm btn-secondary" style="font-size: 11px; padding: 4px 8px; text-decoration: none;">
                                Lihat SPK
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer" style="padding: 12px 16px; border-top: 1px solid #e2e8f0; background: #f8fafc;">
        <?= renderPagination($pagination, 'index.php?page=production/material_requisitions&q=' . urlencode($search) . '&status=' . urlencode($status)) ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
