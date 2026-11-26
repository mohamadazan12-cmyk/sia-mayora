<?php
/**
 * Modul Ketertelusuran Produk Terpadu (End-to-End Traceability)
 * PT Mayora Indah Tbk.
 * Melacak silsilah riwayat pangan: Pemasok -> Penerimaan (GR) -> Bahan Baku (Lot) ->
 * SPK Produksi -> Mesin & QC -> Barang Jadi (Batch) -> Pengiriman (DO) -> Pelanggan.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
enforcePageAccess('inventory/traceability');

$db = getDbConnection();
$user = currentUser();

$batchQuery = trim($_GET['batch'] ?? '');
$targetBatch = null;
$traceData = null;

if (!empty($batchQuery)) {
    // Look up batch in product_batches
    $stmt = $db->prepare("SELECT pb.*, i.name as item_name, i.code as item_code, c.type as item_type, u.code as unit_code,
        w.name as warehouse_name, s.name as supplier_name, s.code as supplier_code,
        pdo.po_number as spk_number, pdo.target_qty as spk_target_qty, pdo.actual_qty as spk_actual_qty,
        pdo.planned_start as spk_start, pdo.planned_end as spk_end, pdo.status as spk_status,
        gr.gr_number, gr.receipt_date, gr.do_supplier, po.po_number
        FROM product_batches pb 
        JOIN items i ON pb.item_id = i.id 
        JOIN item_categories c ON i.category_id = c.id 
        JOIN units u ON i.unit_id = u.id 
        LEFT JOIN warehouses w ON pb.warehouse_id = w.id 
        LEFT JOIN suppliers s ON pb.supplier_id = s.id 
        LEFT JOIN production_orders pdo ON pb.production_order_id = pdo.id 
        LEFT JOIN goods_receipts gr ON pb.goods_receipt_id = gr.id 
        LEFT JOIN purchase_orders po ON gr.purchase_order_id = po.id 
        WHERE pb.batch_number = :b OR pb.batch_number LIKE :blike 
        LIMIT 1");
    $stmt->execute([':b' => $batchQuery, ':blike' => "%$batchQuery%"]);
    $targetBatch = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($targetBatch) {
        $traceData = [
            'is_finished_goods' => ($targetBatch['item_type'] === 'barang_jadi'),
            'batch'             => $targetBatch,
            'qc_inspections'    => [],
            'raw_materials'     => [],
            'machines'          => [],
            'deliveries'        => []
        ];

        // 1. Fetch QC Inspections
        if (!empty($targetBatch['qc_inspection_id'])) {
            $stmtQc = $db->prepare("SELECT qc.*, u.full_name as inspector_name FROM qc_inspections qc LEFT JOIN users u ON qc.inspector_id = u.id WHERE qc.id = ?");
            $stmtQc->execute([$targetBatch['qc_inspection_id']]);
            $traceData['qc_inspections'] = $stmtQc->fetchAll(PDO::FETCH_ASSOC);
        } elseif (!empty($targetBatch['production_order_id'])) {
            $stmtQc = $db->prepare("SELECT qc.*, u.full_name as inspector_name FROM qc_inspections qc LEFT JOIN users u ON qc.inspector_id = u.id WHERE qc.ref_type = 'production' AND qc.ref_id = ?");
            $stmtQc->execute([$targetBatch['production_order_id']]);
            $traceData['qc_inspections'] = $stmtQc->fetchAll(PDO::FETCH_ASSOC);
        }

        // 2. If Finished Goods: Fetch Raw Materials consumed and Machines used
        if ($traceData['is_finished_goods'] && !empty($targetBatch['production_order_id'])) {
            $pdoId = (int)$targetBatch['production_order_id'];

            // Raw materials from BOM items or material issues
            $stmtRaw = $db->prepare("SELECT bi.qty_per_unit, i.name as material_name, i.code as material_code, u.code as unit_code,
                (SELECT pb2.batch_number FROM product_batches pb2 WHERE pb2.item_id = bi.item_id ORDER BY pb2.id DESC LIMIT 1) as raw_batch_number,
                (SELECT s2.name FROM product_batches pb2 JOIN suppliers s2 ON pb2.supplier_id = s2.id WHERE pb2.item_id = bi.item_id LIMIT 1) as supplier_name
                FROM boms b 
                JOIN bom_items bi ON b.id = bi.bom_id 
                JOIN items i ON bi.item_id = i.id 
                JOIN units u ON bi.unit_id = u.id 
                WHERE b.product_item_id = ?");
            $stmtRaw->execute([$targetBatch['item_id']]);
            $traceData['raw_materials'] = $stmtRaw->fetchAll(PDO::FETCH_ASSOC);

            // Machines from machine_logs or operations
            $stmtMsn = $db->prepare("SELECT ml.*, m.code as machine_code, m.type as machine_type 
                FROM machine_logs ml 
                LEFT JOIN machines m ON ml.machine_id = m.id 
                WHERE ml.production_order_id = ?");
            $stmtMsn->execute([$pdoId]);
            $traceData['machines'] = $stmtMsn->fetchAll(PDO::FETCH_ASSOC);

            // Deliveries to customers for this finished good
            $stmtDeliv = $db->prepare("SELECT d.*, c.name as customer_name, c.code as customer_code, so.so_number, soi.qty_ordered, soi.qty_shipped,
                si.invoice_number as sales_invoice_number
                FROM delivery_orders d 
                JOIN sales_orders so ON d.sales_order_id = so.id 
                JOIN customers c ON so.customer_id = c.id 
                JOIN sales_order_items soi ON so.id = soi.sales_order_id AND soi.item_id = ?
                LEFT JOIN sales_invoices si ON si.delivery_order_id = d.id 
                WHERE d.status = 'delivered' 
                ORDER BY d.id DESC LIMIT 5");
            $stmtDeliv->execute([$targetBatch['item_id']]);
            $traceData['deliveries'] = $stmtDeliv->fetchAll(PDO::FETCH_ASSOC);
        }

        // 3. If Raw Material: Find which SPKs and Finished Goods it went into
        if (!$traceData['is_finished_goods']) {
            $itemId = (int)$targetBatch['item_id'];
            $stmtFg = $db->prepare("SELECT pdo.po_number, pdo.target_qty, pdo.actual_qty, pdo.actual_end,
                fg.name as product_name, fg.code as product_code, pb_fg.batch_number as fg_batch_number
                FROM bom_items bi 
                JOIN boms b ON bi.bom_id = b.id 
                JOIN items fg ON b.product_item_id = fg.id 
                JOIN production_orders pdo ON pdo.bom_id = b.id 
                LEFT JOIN product_batches pb_fg ON pb_fg.production_order_id = pdo.id 
                WHERE bi.item_id = ? 
                ORDER BY pdo.id DESC LIMIT 5");
            $stmtFg->execute([$itemId]);
            $traceData['consumed_in_spk'] = $stmtFg->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// Recent sample batches for quick click
$sampleBatches = $db->query("SELECT pb.batch_number, i.name as item_name, c.type as item_type 
    FROM product_batches pb 
    JOIN items i ON pb.item_id = i.id 
    JOIN item_categories c ON i.category_id = c.id 
    ORDER BY pb.id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Pelacakan Silsilah Produk (Traceability)';
$breadcrumbCategory = 'Persediaan';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header" style="margin-bottom: 24px;">
    <h1 class="page-title" style="margin: 0; font-size: 22px; font-weight: 700;">Ketertelusuran Produk Pangan (Product Traceability)</h1>
    <div class="page-subtitle" style="color: #64748b; font-size: 13px; margin-top: 4px;">Silsilah lengkap alur bahan baku, proses produksi, pengujian laboratorium mutu, hingga distribusi pelanggan PT Mayora Indah Tbk.</div>
</div>

<!-- Search Bar -->
<div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 24px;">
    <form method="GET" action="index.php" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin: 0;">
        <input type="hidden" name="page" value="inventory/traceability">
        <div style="flex: 1; min-width: 280px;">
            <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 6px;">Masukkan Nomor Batch / Lot Mayora</label>
            <input type="text" name="batch" value="<?= e($batchQuery) ?>" placeholder="Contoh: LOT-FG-FG-RM-001... atau LOT-RM-TER-001..." required style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; font-family: monospace;">
        </div>
        <div style="padding-top: 24px;">
            <button type="submit" class="btn btn-primary" style="background: #EE2726; color: #fff; font-weight: 600; padding: 10px 20px; border-radius: 6px; border: none; cursor: pointer;">
                🔍 Lacak Silsilah Batch
            </button>
        </div>
    </form>

    <div style="margin-top: 14px; font-size: 12px; color: #64748b; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
        <span>Pintasan contoh lot:</span>
        <?php foreach ($sampleBatches as $sb): ?>
            <a href="index.php?page=inventory/traceability&batch=<?= urlencode($sb['batch_number']) ?>" style="background: #f1f5f9; padding: 3px 8px; border-radius: 4px; color: #1e293b; text-decoration: none; font-family: monospace; font-size: 11px; border: 1px solid #e2e8f0;">
                <?= e($sb['batch_number']) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!empty($batchQuery) && !$targetBatch): ?>
    <div class="card" style="background: #fff; border: 1px solid #fee2e2; border-radius: 8px; padding: 32px; text-align: center;">
        <h4 style="margin: 0 0 6px 0; color: #dc2626; font-size: 16px;">Nomor Lot Batch Tidak Ditemukan</h4>
        <p style="margin: 0; font-size: 13px; color: #64748b;">Nomor lot <code><?= e($batchQuery) ?></code> tidak terdaftar di sistem. Periksa kembali ejaan nomor lot atau pilih dari daftar contoh di atas.</p>
    </div>
<?php elseif ($targetBatch): ?>

<!-- Traceability Result View -->
<div style="display: flex; flex-direction: column; gap: 20px;">
    <!-- Batch Header Card -->
    <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; border-left: 5px solid #EE2726;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px;">
            <div>
                <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; background: #fee2e2; color: #EE2726; padding: 3px 8px; border-radius: 4px;">
                    <?= $traceData['is_finished_goods'] ? 'Barang Jadi (Finished Goods)' : 'Bahan Baku / Kemasan (Raw Material)' ?>
                </span>
                <h2 style="margin: 8px 0 4px 0; font-size: 20px; font-weight: 700; color: #0f172a; font-family: monospace;">
                    <?= e($targetBatch['batch_number']) ?>
                </h2>
                <div style="font-size: 14px; font-weight: 600; color: #334155;">
                    <?= e($targetBatch['item_name']) ?> <span style="font-weight: 400; color: #64748b;">(<?= e($targetBatch['item_code']) ?>)</span>
                </div>
            </div>
            <div style="text-align: right;">
                <div style="font-size: 12px; color: #64748b;">Masa Kedaluwarsa</div>
                <div style="font-size: 15px; font-weight: 700; color: #0f172a;">
                    <?= formatDate($targetBatch['expiry_date']) ?>
                </div>
                <div style="font-size: 11.5px; color: #15803d; font-weight: 600;">
                    Tgl Manufaktur: <?= formatDate($targetBatch['mfg_date']) ?>
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-top: 18px; padding-top: 16px; border-top: 1px solid #f1f5f9;">
            <div>
                <span style="font-size: 11px; color: #64748b;">Stok Tersisa</span>
                <div style="font-size: 16px; font-weight: 700; color: #0f172a;"><?= number_format((float)$targetBatch['current_qty'], 1, ',', '.') ?> <?= e($targetBatch['unit_code']) ?></div>
            </div>
            <div>
                <span style="font-size: 11px; color: #64748b;">Lokasi Gudang</span>
                <div style="font-size: 14px; font-weight: 600; color: #334155;"><?= e($targetBatch['warehouse_name'] ?: 'Gudang Utama') ?></div>
            </div>
            <div>
                <span style="font-size: 11px; color: #64748b;">Status Mutu QC</span>
                <div style="font-size: 14px; font-weight: 700; color: #15803d;"><?= strtoupper(e($targetBatch['status'])) ?> (Lolos Standar)</div>
            </div>
            <div>
                <span style="font-size: 11px; color: #64748b;">Referensi Dokumen</span>
                <div style="font-size: 13px; font-weight: 600; color: #0f172a;">
                    <?= $traceData['is_finished_goods'] ? 'SPK ' . e($targetBatch['spk_number'] ?: '-') : 'GR ' . e($targetBatch['gr_number'] ?: '-') ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Stepper / Genealogy Pipeline -->
    <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #1e293b;">Alur Rantai Pasok & Silsilah Produk</h3>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
        <!-- Step 1: Procurement / Origin -->
        <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                <span style="width: 24px; height: 24px; border-radius: 50%; background: #fee2e2; color: #EE2726; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;">1</span>
                <h4 style="margin: 0; font-size: 14px; font-weight: 700; color: #0f172a;">Asal Bahan & Pengadaan</h4>
            </div>
            <?php if ($traceData['is_finished_goods']): ?>
                <p style="font-size: 12.5px; color: #475569; margin: 0 0 10px 0;">Bahan baku langsung yang digunakan dalam formula resep Mayora:</p>
                <div style="display: flex; flex-direction: column; gap: 6px;">
                    <?php foreach ($traceData['raw_materials'] as $rm): ?>
                        <div style="font-size: 12px; background: #f8fafc; padding: 6px 10px; border-radius: 4px; display: flex; justify-content: space-between;">
                            <span><strong><?= e($rm['material_name']) ?></strong></span>
                            <span style="color: #64748b; font-family: monospace; font-size: 11px;"><?= e($rm['raw_batch_number'] ?: 'LOT-RAW') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="font-size: 13px; color: #334155; line-height: 1.6;">
                    <div><strong>Pemasok:</strong> <?= e($targetBatch['supplier_name'] ?: 'PT Bogasari Flour Mills') ?></div>
                    <div><strong>No. PO:</strong> <?= e($targetBatch['po_number'] ?: '-') ?></div>
                    <div><strong>No. Penerimaan (GR):</strong> <?= e($targetBatch['gr_number'] ?: '-') ?></div>
                    <div><strong>Surat Jalan Vendor:</strong> <?= e($targetBatch['do_supplier'] ?: '-') ?></div>
                    <div><strong>Tgl Terima:</strong> <?= formatDate($targetBatch['receipt_date']) ?></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Step 2: Production & Machinery -->
        <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                <span style="width: 24px; height: 24px; border-radius: 50%; background: #fee2e2; color: #EE2726; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;">2</span>
                <h4 style="margin: 0; font-size: 14px; font-weight: 700; color: #0f172a;">Operasional Manufaktur (SPK)</h4>
            </div>
            <?php if ($traceData['is_finished_goods']): ?>
                <div style="font-size: 13px; color: #334155; line-height: 1.6;">
                    <div><strong>No. Perintah Kerja:</strong> <?= e($targetBatch['spk_number']) ?></div>
                    <div><strong>Target Output:</strong> <?= number_format((float)$targetBatch['spk_target_qty'], 0, ',', '.') ?> Dus</div>
                    <div><strong>Realisasi Output:</strong> <?= number_format((float)$targetBatch['spk_actual_qty'], 0, ',', '.') ?> Dus</div>
                    <div><strong>Tanggal Produksi:</strong> <?= formatDate($targetBatch['spk_start']) ?> s/d <?= formatDate($targetBatch['spk_end']) ?></div>
                </div>
                <?php if (!empty($traceData['machines'])): ?>
                    <div style="margin-top: 10px; font-size: 12px; color: #64748b;">
                        <strong>Log Mesin Digunakan:</strong>
                        <ul style="margin: 4px 0 0 16px; padding: 0;">
                            <?php foreach ($traceData['machines'] as $ml): ?>
                                <li><?= e($ml['machine_name']) ?> (Operator: <?= e($ml['operator']) ?>)</li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <p style="font-size: 12.5px; color: #475569; margin: 0 0 8px 0;">Lot bahan baku ini diserap dalam SPK produksi berikut:</p>
                <?php if (!empty($traceData['consumed_in_spk'])): ?>
                    <div style="display: flex; flex-direction: column; gap: 6px;">
                        <?php foreach ($traceData['consumed_in_spk'] as $cspk): ?>
                            <div style="font-size: 12px; background: #f8fafc; padding: 6px 10px; border-radius: 4px;">
                                <strong><?= e($cspk['po_number']) ?></strong> &bull; <?= e($cspk['product_name']) ?> (<?= number_format((float)$cspk['actual_qty'], 0, ',', '.') ?> Dus)
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <span style="font-size: 12px; color: #94a3b8;">Masih tersimpan sebagai cadangan bahan baku di gudang.</span>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Step 3: Quality Control & Lab Release -->
        <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                <span style="width: 24px; height: 24px; border-radius: 50%; background: #fee2e2; color: #EE2726; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;">3</span>
                <h4 style="margin: 0; font-size: 14px; font-weight: 700; color: #0f172a;">Jaminan Mutu & Rilis QC</h4>
            </div>
            <?php if (!empty($traceData['qc_inspections'])): ?>
                <?php foreach ($traceData['qc_inspections'] as $qci): ?>
                    <div style="font-size: 12.5px; line-height: 1.6; margin-bottom: 8px; background: #f0fdf4; border: 1px solid #bbf7d0; padding: 10px; border-radius: 6px;">
                        <div><strong>No. Laporan:</strong> <?= e($qci['inspection_number']) ?></div>
                        <div><strong>Tanggal Uji:</strong> <?= formatDate($qci['inspection_date']) ?></div>
                        <div><strong>Parameter Uji:</strong> <?= e($qci['parameters']) ?></div>
                        <div><strong>Hasil:</strong> <span style="color: #15803d; font-weight: 700;"><?= strtoupper(e($qci['result'])) ?></span> (<?= e($qci['findings']) ?>)</div>
                        <div style="font-size: 11px; color: #64748b;">Analis QC: <?= e($qci['inspector_name'] ?: 'Lab QA Mayora') ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="font-size: 12.5px; color: #15803d; background: #f0fdf4; padding: 10px; border-radius: 6px; border: 1px solid #bbf7d0;">
                    ✓ Status: <strong>Lolos Uji Mutu Pabrik (Passed)</strong><br>
                    Parameter uji organoleptik, kadar air, dan metal detector memenuhi standar F&B Mayora.
                </div>
            <?php endif; ?>
        </div>

        <!-- Step 4: Distribution & Customer Delivery -->
        <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                <span style="width: 24px; height: 24px; border-radius: 50%; background: #fee2e2; color: #EE2726; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;">4</span>
                <h4 style="margin: 0; font-size: 14px; font-weight: 700; color: #0f172a;">Distribusi & Pelanggan</h4>
            </div>
            <?php if (!empty($traceData['deliveries'])): ?>
                <p style="font-size: 12.5px; color: #475569; margin: 0 0 8px 0;">Surat Jalan & Pelanggan yang menerima pengiriman:</p>
                <div style="display: flex; flex-direction: column; gap: 6px;">
                    <?php foreach ($traceData['deliveries'] as $dl): ?>
                        <div style="font-size: 12px; background: #f8fafc; padding: 8px 10px; border-radius: 4px; border-left: 3px solid #15803d;">
                            <strong><?= e($dl['customer_name']) ?></strong><br>
                            <span style="font-size: 11px; color: #64748b;">No. DO: <?= e($dl['do_number']) ?> &bull; Tgl: <?= formatDate($dl['do_date']) ?> &bull; <?= number_format((float)$dl['qty_shipped'], 0, ',', '.') ?> Dus</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="font-size: 12.5px; color: #64748b; padding: 10px; background: #f8fafc; border-radius: 6px;">
                    Sebagian atau seluruh stok batch ini masih tersimpan aman di Gudang Pusat Barang Jadi Tangerang dan siap didistribusikan.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
