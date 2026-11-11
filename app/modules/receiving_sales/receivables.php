<?php
/**
 * Modul Penerimaan: Piutang Usaha & Penagihan (Accounts Receivable & Aging)
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Piutang & Penagihan (Accounts Receivable)';
$breadcrumbCategory = 'Penerimaan';

$db = getDbConnection();
$user = currentUser();

// Handle adding a Collection Reminder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_reminder'])) {
    requireCsrf();

    $invId = (int)($_POST['invoice_id'] ?? 0);
    $remDate = trim($_POST['reminder_date'] ?? date('Y-m-d'));
    $remType = trim($_POST['reminder_type'] ?? 'Telepon');
    $remNotes = trim($_POST['notes'] ?? '');

    if ($invId > 0 && !empty($remDate) && !empty($remNotes)) {
        $db->prepare("
            INSERT INTO collection_reminders (sales_invoice_id, reminder_date, reminder_type, notes, sent_by, created_at)
            VALUES (:inv_id, :rem_date, :rem_type, :notes, :uid, NOW())
        ")->execute([
            ':inv_id'   => $invId,
            ':rem_date' => $remDate,
            ':rem_type' => $remType,
            ':notes'    => $remNotes,
            ':uid'      => $user['id'],
        ]);

        logAudit($user['id'], 'create_collection_reminder', 'collection_reminders', $db->lastInsertId(), null, [
            'invoice_id' => $invId,
            'type'       => $remType,
        ]);

        setFlash('success', 'Catatan penagihan berhasil disimpan.');
        header('Location: index.php?page=receiving_sales/receivables');
        exit;
    } else {
        setFlash('error', 'Semua kolom reminder wajib diisi.');
        header('Location: index.php?page=receiving_sales/receivables');
        exit;
    }
}

// Fetch all unpaid / outstanding invoices with aging calculation
$agingQuery = $db->query("
    SELECT inv.*, c.name AS customer_name, c.code AS customer_code, c.phone AS customer_phone,
           DATEDIFF(CURRENT_DATE, inv.due_date) AS days_overdue,
           CASE
               WHEN inv.due_date >= CURRENT_DATE THEN 'current'
               WHEN DATEDIFF(CURRENT_DATE, inv.due_date) BETWEEN 1 AND 30 THEN '1_30'
               WHEN DATEDIFF(CURRENT_DATE, inv.due_date) BETWEEN 31 AND 60 THEN '31_60'
               WHEN DATEDIFF(CURRENT_DATE, inv.due_date) BETWEEN 61 AND 90 THEN '61_90'
               ELSE 'over_90'
           END AS aging_bucket,
           (SELECT COUNT(*) FROM collection_reminders cr WHERE cr.sales_invoice_id = inv.id) AS reminder_count
    FROM sales_invoices inv
    JOIN customers c ON inv.customer_id = c.id
    WHERE inv.status IN ('issued', 'partially_paid', 'overdue') AND inv.balance > 0
    ORDER BY days_overdue DESC, inv.due_date ASC
");
$unpaidInvoices = $agingQuery->fetchAll();

// Calculate Aging Totals
$totals = [
    'current' => 0.0,
    '1_30'    => 0.0,
    '31_60'   => 0.0,
    '61_90'   => 0.0,
    'over_90' => 0.0,
    'all'     => 0.0,
];

foreach ($unpaidInvoices as $row) {
    $bal = (float)$row['balance'];
    $totals[$row['aging_bucket']] += $bal;
    $totals['all'] += $bal;
}

// Fetch recent 10 reminders
$reminders = $db->query("
    SELECT cr.*, inv.invoice_number, c.name AS customer_name, u.full_name AS sender_name
    FROM collection_reminders cr
    JOIN sales_invoices inv ON cr.sales_invoice_id = inv.id
    JOIN customers c ON inv.customer_id = c.id
    LEFT JOIN users u ON cr.sent_by = u.id
    ORDER BY cr.id DESC
    LIMIT 10
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Piutang &amp; Penagihan (Accounts Receivable)</h1>
        <div class="page-subtitle">Siklus Penerimaan &bull; Analisis Umur Piutang (Aging Schedule), Pengawasan Jatuh Tempo &amp; Reminder Kolektibilitas</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=receiving_sales/receipts" class="btn btn-secondary">
            Kwitansi Kas Masuk &rarr;
        </a>
    </div>
</div>

<!-- Aging Schedule Metrics -->
<div class="metrics-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
    <div class="metric-card" style="border-top: 3px solid #16a34a;">
        <div class="metric-value" style="color: #16a34a; font-size: 18px;"><?= formatRupiah($totals['current']) ?></div>
        <div class="metric-label">Lancar (&le; Jatuh Tempo)</div>
    </div>
    <div class="metric-card" style="border-top: 3px solid #eab308;">
        <div class="metric-value" style="color: #ca8a04; font-size: 18px;"><?= formatRupiah($totals['1_30']) ?></div>
        <div class="metric-label">Menunggak 1 - 30 Hari</div>
    </div>
    <div class="metric-card" style="border-top: 3px solid #f97316;">
        <div class="metric-value" style="color: #ea580c; font-size: 18px;"><?= formatRupiah($totals['31_60']) ?></div>
        <div class="metric-label">Menunggak 31 - 60 Hari</div>
    </div>
    <div class="metric-card" style="border-top: 3px solid #ef4444;">
        <div class="metric-value" style="color: #dc2626; font-size: 18px;"><?= formatRupiah($totals['61_90']) ?></div>
        <div class="metric-label">Menunggak 61 - 90 Hari</div>
    </div>
    <div class="metric-card" style="border-top: 3px solid #991b1b;">
        <div class="metric-value" style="color: #991b1b; font-size: 18px;"><?= formatRupiah($totals['over_90']) ?></div>
        <div class="metric-label">Macet (&gt; 90 Hari)</div>
    </div>
    <div class="metric-card" style="border-top: 3px solid #0f172a; background:#f8fafc;">
        <div class="metric-value" style="font-size: 18px;"><?= formatRupiah($totals['all']) ?></div>
        <div class="metric-label font-bold">Total Piutang Beredar</div>
    </div>
</div>

<!-- Outstanding Invoices Table -->
<div class="card mb-4">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h2 class="card-title">Daftar Faktur Belum Lunas (Outstanding Invoices)</h2>
        <span class="badge badge-info"><?= count($unpaidInvoices) ?> Faktur Menunggu Pembayaran</span>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>No. Faktur</th>
                        <th>Pelanggan</th>
                        <th>Tgl Faktur</th>
                        <th>Jatuh Tempo</th>
                        <th class="text-right">Total Faktur</th>
                        <th class="text-right">Sisa Piutang (Balance)</th>
                        <th>Umur / Hari Lewat</th>
                        <th>Kategori Aging</th>
                        <th class="text-center">Tindakan Penagihan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($unpaidInvoices)): ?>
                        <tr>
                            <td colspan="9" class="text-center" style="padding:30px; color:#16a34a; font-weight:600;">
                                ✓ Luar biasa! Seluruh faktur penjualan telah lunas. Tidak ada piutang tertunggak saat ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($unpaidInvoices as $inv): ?>
                            <?php 
                                $days = (int)$inv['days_overdue'];
                                $isDue = $days > 0;
                            ?>
                            <tr>
                                <td>
                                    <strong><a href="index.php?page=receiving_sales/invoice_detail&id=<?= $inv['id'] ?>"><?= e($inv['invoice_number']) ?></a></strong>
                                </td>
                                <td>
                                    <strong><?= e($inv['customer_name']) ?></strong>
                                    <div class="text-muted" style="font-size:11px;"><?= e($inv['customer_code']) ?> &bull; <?= e($inv['customer_phone'] ?: '-') ?></div>
                                </td>
                                <td><?= formatDate($inv['invoice_date']) ?></td>
                                <td>
                                    <span style="<?= $isDue ? 'color:#dc2626; font-weight:600;' : '' ?>">
                                        <?= formatDate($inv['due_date']) ?>
                                    </span>
                                </td>
                                <td class="text-right font-medium"><?= formatRupiah((float)$inv['total_amount']) ?></td>
                                <td class="text-right font-bold" style="color:#ea580c;"><?= formatRupiah((float)$inv['balance']) ?></td>
                                <td>
                                    <?php if ($days > 0): ?>
                                        <span class="badge badge-danger"><?= $days ?> hari lewat</span>
                                    <?php else: ?>
                                        <span class="badge badge-success"><?= abs($days) ?> hari lagi</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($inv['aging_bucket'] === 'current'): ?>
                                        <span style="color:#16a34a; font-weight:600;">Lancar</span>
                                    <?php elseif ($inv['aging_bucket'] === '1_30'): ?>
                                        <span style="color:#ca8a04; font-weight:600;">1 - 30 Hari</span>
                                    <?php elseif ($inv['aging_bucket'] === '31_60'): ?>
                                        <span style="color:#ea580c; font-weight:600;">31 - 60 Hari</span>
                                    <?php elseif ($inv['aging_bucket'] === '61_90'): ?>
                                        <span style="color:#dc2626; font-weight:600;">61 - 90 Hari</span>
                                    <?php else: ?>
                                        <span style="color:#991b1b; font-weight:bold;">Macet (&gt;90 Hari)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center" style="white-space:nowrap;">
                                    <a href="index.php?page=receiving_sales/receipt_create&customer_id=<?= $inv['customer_id'] ?>&invoice_id=<?= $inv['id'] ?>" class="btn btn-sm btn-primary">
                                        💰 Bayar
                                    </a>

                                    <button type="button" class="btn btn-sm btn-secondary" onclick="openModal('modal-rem-<?= $inv['id'] ?>')" title="Catat Penagihan">
                                        🔔 Reminder (<?= (int)$inv['reminder_count'] ?>)
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Recent Reminders Activity Log -->
<div class="card mb-4">
    <div class="card-header">
        <h2 class="card-title">Riwayat Aktivitas Penagihan Terbaru (Collection Log)</h2>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Tgl Penagihan</th>
                        <th>Faktur</th>
                        <th>Pelanggan</th>
                        <th>Metode</th>
                        <th>Catatan Penagihan</th>
                        <th>Petugas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reminders)): ?>
                        <tr>
                            <td colspan="6" class="text-center" style="padding:20px; color:#64748b;">
                                Belum ada riwayat aktivitas reminder penagihan yang tercatat.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reminders as $rem): ?>
                            <tr>
                                <td><?= formatDate($rem['reminder_date']) ?></td>
                                <td><strong><?= e($rem['invoice_number']) ?></strong></td>
                                <td><?= e($rem['customer_name']) ?></td>
                                <td><span class="badge badge-info"><?= e($rem['reminder_type']) ?></span></td>
                                <td><?= e($rem['notes']) ?></td>
                                <td><?= e($rem['sender_name'] ?: 'Staff') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modals for Reminders outside table -->
<?php if (!empty($receivables)): ?>
    <?php foreach ($receivables as $inv): ?>
        <div id="modal-rem-<?= $inv['id'] ?>" class="modal-backdrop">
            <div class="modal-dialog modal-sm">
                <div class="modal-header">
                    <h3 class="modal-title">🔔 Catat Aktivitas Penagihan</h3>
                    <button type="button" class="modal-close" data-modal-close>&times;</button>
                </div>
                <form action="index.php?page=receiving_sales/receivables" method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action_add_reminder" value="1">
                    <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                    <div class="modal-body">
                        <div style="background:#f8fafc; padding:10px; border-radius:6px; margin-bottom:12px; font-size:12.5px; border:1px solid #e2e8f0;">
                            No. Faktur: <strong><?= e($inv['invoice_number']) ?></strong><br>
                            Pelanggan: <strong><?= e($inv['customer_name']) ?></strong><br>
                            Sisa Piutang: <strong style="color:#dc2626;"><?= formatRupiah((float)$inv['balance']) ?></strong>
                        </div>
                        <div class="form-group">
                            <label>Tanggal Reminder / Kontak *</label>
                            <input type="date" name="reminder_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Saluran / Metode Kontak *</label>
                            <select name="reminder_type">
                                <option value="Telepon">Panggilan Telepon (WA/Call)</option>
                                <option value="Email">Email Surat Tagihan</option>
                                <option value="Surat Tagihan">Surat Somasi / Peringatan Fisik</option>
                                <option value="Kunjungan">Kunjungan Langsung Sales / Kolektor</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Hasil &amp; Catatan Tindak Lanjut *</label>
                            <textarea name="notes" rows="3" placeholder="Respon pelanggan, janji tanggal pembayaran..." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-modal-close>Batal</button>
                        <button type="submit" class="btn btn-primary btn-sm">Simpan Catatan</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
