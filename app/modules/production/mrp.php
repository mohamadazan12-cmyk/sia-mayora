<?php
/**
 * Material Requirements Planning (MRP) & Shortage Calculator
 * PT Mayora Indah Tbk.
 * Menghitung kebutuhan bahan baku/kemasan dari BOM, stok on-hand, dan PO on-order,
 * serta menyediakan aksi langsung Auto-Generate Purchase Requisition (PR).
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../services/notification_service.php';

requireLogin();
enforcePageAccess('production/mrp');

$db = getDbConnection();
$user = currentUser();

// Handle Auto-Generate PR Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'generate_pr_shortage') {
        $shortageItemIds = $_POST['shortage_item_ids'] ?? [];
        $shortageQtys    = $_POST['shortage_qtys'] ?? [];

        if (empty($shortageItemIds)) {
            setFlash('error', 'Tidak ada item kekurangan bahan yang dipilih untuk dibuatkan PR.');
            header('Location: index.php?page=production/mrp');
            exit;
        }

        $db->beginTransaction();
        try {
            $prNum = generateDocNumber('PR', 'purchase_requisitions', 'pr_number');
            $deptId = 1; // Dept Produksi / PPIC
            $totalEst = 0;

            // Prepare items data
            $prItems = [];
            foreach ($shortageItemIds as $idx => $itemId) {
                $itemId = (int)$itemId;
                $reqQty = parseDecimal($shortageQtys[$idx] ?? 0);
                if ($itemId > 0 && $reqQty > 0) {
                    $itemData = $db->query("SELECT id, name, unit_id, cost_price FROM items WHERE id = $itemId")->fetch(PDO::FETCH_ASSOC);
                    if ($itemData) {
                        $cost = (float)$itemData['cost_price'];
                        $subtotal = $reqQty * $cost;
                        $totalEst += $subtotal;
                        $prItems[] = [
                            'item_id' => $itemId,
                            'unit_id' => $itemData['unit_id'],
                            'qty'     => $reqQty,
                            'price'   => $cost,
                            'subtotal'=> $subtotal,
                            'name'    => $itemData['name']
                        ];
                    }
                }
            }

            if (empty($prItems)) {
                throw new Exception('Data kuantitas pemesanan kekurangan material tidak valid.');
            }

            // Insert PR Header
            $stmtPr = $db->prepare("INSERT INTO purchase_requisitions 
                (pr_number, department_id, requested_by, request_date, reason, total_estimated, status, created_at)
                VALUES (?, ?, ?, CURDATE(), 'Otomatis dibuat dari Kalkulasi Kebutuhan Material (MRP) Mayora', ?, 'submitted', NOW())");
            $stmtPr->execute([$prNum, $deptId, $user['id'], $totalEst]);
            $prId = (int)$db->lastInsertId();

            // Insert PR Items
            $stmtPri = $db->prepare("INSERT INTO purchase_requisition_items 
                (purchase_requisition_id, item_id, qty, unit_id, estimated_price, subtotal)
                VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($prItems as $pi) {
                $stmtPri->execute([$prId, $pi['item_id'], $pi['qty'], $pi['unit_id'], $pi['price'], $pi['subtotal']]);
            }

            // Notification to Purchasing & Manager
            NotificationService::create([
                'role_code' => 'purchasing',
                'title'     => "PR Baru dari MRP: $prNum",
                'message'   => "PPIC mengenerate PR $prNum senilai Rp " . number_format($totalEst, 0, ',', '.') . " untuk mengatasi kekurangan bahan baku " . count($prItems) . " item.",
                'type'      => 'warning',
                'category'  => 'procurement',
                'link'      => "index.php?page=expenditure_procurement/pr_detail&id=$prId",
                'ref_type'  => 'purchase_requisitions',
                'ref_id'    => $prId
            ]);

            NotificationService::create([
                'role_code' => 'manager',
                'title'     => "Kebutuhan Material Produksi: $prNum",
                'message'   => "Permintaan Pembelian darurat $prNum diajukan otomatis melalui kalkulasi MRP manufaktur.",
                'type'      => 'info',
                'category'  => 'procurement',
                'link'      => "index.php?page=expenditure_procurement/pr_detail&id=$prId",
                'ref_type'  => 'purchase_requisitions',
                'ref_id'    => $prId
            ]);

            logAudit($user['id'], 'create', 'purchase_requisitions', $prId, null, [
                'pr_number' => $prNum,
                'source'    => 'MRP Calculation Auto-Generate',
                'items'     => count($prItems),
                'total'     => $totalEst
            ]);

            $db->commit();
            setFlash('success', "Permintaan Pembelian (PR) {$prNum} berhasil di-generate secara otomatis untuk " . count($prItems) . " item material!");
            header("Location: index.php?page=expenditure_procurement/pr_detail&id={$prId}");
            exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            setFlash('error', 'Gagal membuat PR otomatis: ' . $e->getMessage());
            header('Location: index.php?page=production/mrp');
            exit;
        }
    }
}

// -----------------------------------------------------------------------------
// MRP Calculation Engine
// -----------------------------------------------------------------------------
// 1. Fetch Gross Requirements from approved/draft MPS or in-progress SPK
$grossRequirements = [];

// From uncompleted Production Orders (SPK)
$stmtSPKReq = $db->query("
    SELECT bi.item_id, SUM(bi.qty_per_unit * (pdo.target_qty - pdo.actual_qty) * (1 + (bi.shrinkage_percent / 100))) as gross_qty
    FROM production_orders pdo
    JOIN boms b ON pdo.bom_id = b.id
    JOIN bom_items bi ON b.id = bi.bom_id
    WHERE pdo.status IN ('draft', 'pending_approval', 'approved', 'waiting_material', 'in_progress')
    GROUP BY bi.item_id
");
while ($r = $stmtSPKReq->fetch(PDO::FETCH_ASSOC)) {
    $grossRequirements[(int)$r['item_id']] = (float)$r['gross_qty'];
}

// From active Production Schedules (MPS) of current month
$stmtMpsReq = $db->query("
    SELECT bi.item_id, SUM(bi.qty_per_unit * ps.planned_qty * (1 + (bi.shrinkage_percent / 100))) as gross_qty
    FROM production_schedules ps
    JOIN boms b ON b.product_item_id = ps.product_item_id
    JOIN bom_items bi ON b.id = bi.bom_id
    WHERE ps.status IN ('draft', 'approved') AND ps.period_month = MONTH(CURDATE()) AND ps.period_year = YEAR(CURDATE())
    GROUP BY bi.item_id
");
while ($r = $stmtMpsReq->fetch(PDO::FETCH_ASSOC)) {
    $iId = (int)$r['item_id'];
    $grossRequirements[$iId] = ($grossRequirements[$iId] ?? 0) + ((float)$r['gross_qty'] * 0.4); // scheduled pipeline
}

// 2. Fetch Raw Materials & Packaging items with Stock on Hand & PO On-Order
$rawMaterials = $db->query("
    SELECT i.id, i.code, i.name, i.min_stock, i.cost_price, u.code as unit_code, c.name as category_name,
        COALESCE((SELECT SUM(qty_available) FROM stock_balances WHERE item_id = i.id), 0) as stock_on_hand,
        COALESCE((SELECT SUM(poi.qty_ordered - poi.qty_received) 
                  FROM purchase_order_items poi 
                  JOIN purchase_orders po ON poi.purchase_order_id = po.id 
                  WHERE poi.item_id = i.id AND po.status IN ('approved', 'partially_received')), 0) as on_order_po
    FROM items i
    JOIN item_categories c ON i.category_id = c.id
    JOIN units u ON i.unit_id = u.id
    WHERE c.type IN ('bahan_baku', 'packaging') AND i.is_active = 1
    ORDER BY c.name ASC, i.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 3. Compute Net Requirements and Status
$mrpRows = [];
$totalShortageItems = 0;
$totalEstimatedShortageCost = 0;

foreach ($rawMaterials as $rm) {
    $itemId = (int)$rm['id'];
    $gross = $grossRequirements[$itemId] ?? 0;
    $onHand = (float)$rm['stock_on_hand'];
    $onOrder = (float)$rm['on_order_po'];
    $minStock = (float)$rm['min_stock'];

    // Net Requirement = Gross Req + Safety/Min Stock - (On Hand + On Order)
    $projectedBalance = ($onHand + $onOrder) - $gross;
    $netReq = 0;

    if ($projectedBalance < $minStock) {
        $netReq = max(0, ($minStock - $projectedBalance));
        // Round to nearest friendly batch quantity (e.g. 50 or 100)
        $netReq = ceil($netReq / 50) * 50;
    }

    $status = 'ok';
    if ($netReq > 0) {
        $status = 'shortage';
        $totalShortageItems++;
        $totalEstimatedShortageCost += ($netReq * (float)$rm['cost_price']);
    } elseif ($onHand < $minStock) {
        $status = 'warning';
    }

    $mrpRows[] = [
        'id'            => $itemId,
        'code'          => $rm['code'],
        'name'          => $rm['name'],
        'category'      => $rm['category_name'],
        'unit'          => $rm['unit_code'],
        'cost_price'    => (float)$rm['cost_price'],
        'min_stock'     => $minStock,
        'gross_req'     => $gross,
        'stock_on_hand' => $onHand,
        'on_order_po'   => $onOrder,
        'projected'     => $projectedBalance,
        'net_req'       => $netReq,
        'status'        => $status
    ];
}

$pageTitle = 'Perencanaan Kebutuhan Material (MRP)';
$breadcrumbCategory = 'Produksi';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 class="page-title" style="margin: 0; font-size: 22px; font-weight: 700;">Material Requirements Planning (MRP)</h1>
        <div class="page-subtitle" style="color: #64748b; font-size: 13px; margin-top: 4px;">Kalkulasi ledakan BOM, ketersediaan bahan baku, jadwal pesanan terbuka, dan deteksi defisit material.</div>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="index.php?page=production/mps" class="btn btn-outline" style="border: 1px solid #cbd5e1; background: #fff; color: #1e293b; font-weight: 600; padding: 8px 14px; border-radius: 6px; text-decoration: none;">
            &laquo; Kembali ke Jadwal MPS
        </a>
        <?php if ($totalShortageItems > 0): ?>
            <button type="button" onclick="document.getElementById('mrpForm').submit();" class="btn btn-primary" style="background: #EE2726; color: #fff; font-weight: 600; padding: 8px 14px; border-radius: 6px; border: none; cursor: pointer; box-shadow: 0 4px 6px -1px rgba(238, 39, 38, 0.2);">
                ⚡ Auto-Generate PR (<?= $totalShortageItems ?> Item Shortage)
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- MRP Metrics Summary -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
        <span style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">Total Bahan Dipantau</span>
        <div style="font-size: 24px; font-weight: 700; color: #0f172a; margin-top: 6px;"><?= count($mrpRows) ?> <span style="font-size: 13px; font-weight: 400; color: #64748b;">SKU</span></div>
        <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Bahan baku pangan & material kemasan</div>
    </div>
    <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; border-left: 4px solid #dc2626;">
        <span style="font-size: 12px; font-weight: 600; color: #dc2626; text-transform: uppercase;">Defisit / Shortage Material</span>
        <div style="font-size: 24px; font-weight: 700; color: #dc2626; margin-top: 6px;"><?= $totalShortageItems ?> <span style="font-size: 13px; font-weight: 400; color: #dc2626;">Item Defisit</span></div>
        <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Perlu segera diterbitkan PR Pembelian</div>
    </div>
    <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
        <span style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">Estimasi Nilai Pengadaan PR</span>
        <div style="font-size: 24px; font-weight: 700; color: #0f172a; margin-top: 6px;">Rp <?= number_format($totalEstimatedShortageCost, 0, ',', '.') ?></div>
        <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Total biaya pemenuhan kebutuhan bersih</div>
    </div>
    <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
        <span style="font-size: 12px; font-weight: 600; color: #15803d; text-transform: uppercase;">Kesiapan Suplai Produksi</span>
        <div style="font-size: 24px; font-weight: 700; color: #15803d; margin-top: 6px;">
            <?= count($mrpRows) > 0 ? round(((count($mrpRows) - $totalShortageItems) / count($mrpRows)) * 100, 1) : 100 ?>%
        </div>
        <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Stok mencukupi untuk batch berjalan</div>
    </div>
</div>

<!-- MRP Matrix Table -->
<div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
    <div style="padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
        <div>
            <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: #0f172a;">Matriks Perhitungan Kebutuhan Bersih Material (Net Requirements Matrix)</h3>
            <span style="font-size: 12px; color: #64748b;">Formula: Kebutuhan Bersih = Kebutuhan Kotor (BOM) + Buffer Safety Stock - (Stok Fisik + PO Terbuka)</span>
        </div>
        <?php if ($totalShortageItems > 0): ?>
            <span style="background: #fee2e2; color: #dc2626; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 6px;">
                ⚠️ Terdeteksi <?= $totalShortageItems ?> Bahan Baku di bawah Buffer Minimal
            </span>
        <?php endif; ?>
    </div>

    <form id="mrpForm" method="POST" action="index.php?page=production/mrp" style="margin: 0;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="generate_pr_shortage">

        <div class="table-responsive">
            <table class="table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f1f5f9; border-bottom: 2px solid #cbd5e1; text-align: left; font-size: 12px; color: #334155;">
                        <th style="padding: 12px 14px; text-align: center; width: 40px;">Pilih</th>
                        <th style="padding: 12px 14px;">Kode & Material</th>
                        <th style="padding: 12px 14px;">Kategori</th>
                        <th style="padding: 12px 14px; text-align: right;">Kebutuhan Kotor (Gross)</th>
                        <th style="padding: 12px 14px; text-align: right;">Stok Gudang (On-Hand)</th>
                        <th style="padding: 12px 14px; text-align: right;">Pesanan Terbuka (PO)</th>
                        <th style="padding: 12px 14px; text-align: right;">Safety Stock Min.</th>
                        <th style="padding: 12px 14px; text-align: right; background: #fff1f2;">Defisit / Net Req.</th>
                        <th style="padding: 12px 14px; text-align: center;">Status Kesiapan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mrpRows as $r): 
                        $isShortage = $r['status'] === 'shortage';
                        $isWarning = $r['status'] === 'warning';
                    ?>
                    <tr style="border-bottom: 1px solid #f1f5f9; font-size: 13px; background: <?= $isShortage ? '#fff5f5' : ($isWarning ? '#fffbeb' : '#ffffff') ?>;">
                        <td style="padding: 12px 14px; text-align: center;">
                            <?php if ($isShortage): ?>
                                <input type="checkbox" name="shortage_item_ids[]" value="<?= $r['id'] ?>" checked style="cursor: pointer;">
                                <input type="hidden" name="shortage_qtys[]" value="<?= $r['net_req'] ?>">
                            <?php else: ?>
                                <span style="color: #cbd5e1;">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px 14px;">
                            <strong><?= e($r['name']) ?></strong><br>
                            <code style="font-size: 11px;"><?= e($r['code']) ?></code>
                        </td>
                        <td style="padding: 12px 14px; color: #64748b; font-size: 12px;">
                            <?= e($r['category']) ?>
                        </td>
                        <td style="padding: 12px 14px; text-align: right; font-weight: 600;">
                            <?= number_format($r['gross_req'], 1, ',', '.') ?> <?= e($r['unit']) ?>
                        </td>
                        <td style="padding: 12px 14px; text-align: right; font-weight: 600; color: <?= $r['stock_on_hand'] < $r['min_stock'] ? '#dc2626' : '#15803d' ?>;">
                            <?= number_format($r['stock_on_hand'], 1, ',', '.') ?> <?= e($r['unit']) ?>
                        </td>
                        <td style="padding: 12px 14px; text-align: right; color: #1d4ed8;">
                            <?= number_format($r['on_order_po'], 1, ',', '.') ?> <?= e($r['unit']) ?>
                        </td>
                        <td style="padding: 12px 14px; text-align: right; color: #64748b;">
                            <?= number_format($r['min_stock'], 1, ',', '.') ?> <?= e($r['unit']) ?>
                        </td>
                        <td style="padding: 12px 14px; text-align: right; font-weight: 700; background: <?= $isShortage ? '#fee2e2' : 'transparent' ?>; color: <?= $isShortage ? '#dc2626' : '#64748b' ?>;">
                            <?= $r['net_req'] > 0 ? number_format($r['net_req'], 0, ',', '.') . ' ' . e($r['unit']) : '-' ?>
                        </td>
                        <td style="padding: 12px 14px; text-align: center;">
                            <?php if ($isShortage): ?>
                                <span class="badge" style="background: #dc2626; color: #fff; font-size: 10.5px; font-weight: 700; padding: 3px 8px; border-radius: 4px;">
                                    Defisit Material
                                </span>
                            <?php elseif ($isWarning): ?>
                                <span class="badge" style="background: #f59e0b; color: #fff; font-size: 10.5px; font-weight: 600; padding: 3px 8px; border-radius: 4px;">
                                    Mendekati Min
                                </span>
                            <?php else: ?>
                                <span class="badge" style="background: #dcfce7; color: #15803d; font-size: 10.5px; font-weight: 600; padding: 3px 8px; border-radius: 4px;">
                                    ✓ Cukup Aman
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
