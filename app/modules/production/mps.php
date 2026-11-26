<?php
/**
 * Master Production Schedule (MPS) / Jadwal Induk Produksi
 * PT Mayora Indah Tbk.
 * Mengelola perencanaan produksi mingguan/bulanan berdasarkan target penjualan dan kapasitas mesin.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireLogin();
enforcePageAccess('production/mps');

$db = getDbConnection();
$user = currentUser();

// Filter parameters
$currentYear = (int)date('Y');
$filterYear  = (int)($_GET['year'] ?? $currentYear);
$filterMonth = (int)($_GET['month'] ?? date('n'));
$filterCat   = trim($_GET['category'] ?? '');

// Handle Create Schedule Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_schedule') {
        $productId = (int)($_POST['product_item_id'] ?? 0);
        $periodYear = (int)($_POST['period_year'] ?? $currentYear);
        $periodMonth = (int)($_POST['period_month'] ?? date('n'));
        $plannedWeek = (int)($_POST['planned_week'] ?? 1);
        $plannedQty = parseDecimal($_POST['planned_qty'] ?? 0);
        $startDate = trim($_POST['scheduled_start'] ?? '');
        $endDate = trim($_POST['scheduled_end'] ?? '');

        if ($productId <= 0 || $plannedQty <= 0 || empty($startDate) || empty($endDate)) {
            setFlash('error', 'Produk, kuantitas rencana (>0), dan rentang tanggal wajib diisi.');
        } else {
            $schedNum = sprintf('MPS-%s-%04d', date('ym'), rand(100, 9999));
            $stmt = $db->prepare("INSERT INTO production_schedules 
                (schedule_number, product_item_id, period_year, period_month, planned_week, planned_qty, scheduled_start, scheduled_end, status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, NOW())");
            $stmt->execute([$schedNum, $productId, $periodYear, $periodMonth, $plannedWeek, $plannedQty, $startDate, $endDate, $user['id']]);

            setFlash('success', "Jadwal Induk Produksi $schedNum berhasil ditambahkan.");
            header("Location: index.php?page=production/mps&year=$periodYear&month=$periodMonth");
            exit;
        }
    }

    if ($action === 'approve_schedule') {
        $schedId = (int)($_POST['schedule_id'] ?? 0);
        if ($schedId > 0 && hasRole(['admin', 'manager', 'ppic'])) {
            $db->prepare("UPDATE production_schedules SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?")
               ->execute([$user['id'], $schedId]);
            setFlash('success', 'Jadwal MPS berhasil disetujui.');
        }
        header("Location: index.php?page=production/mps&year=$filterYear&month=$filterMonth");
        exit;
    }
}

// Fetch Active Finished Goods Products
$products = $db->query("SELECT i.id, i.code, i.name, c.name as category_name, u.code as unit_code 
    FROM items i 
    JOIN item_categories c ON i.category_id = c.id 
    JOIN units u ON i.unit_id = u.id 
    WHERE c.type = 'barang_jadi' AND i.is_active = 1 
    ORDER BY c.name ASC, i.name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Schedules
$where = ["ps.period_year = :year", "ps.period_month = :month"];
$params = [':year' => $filterYear, ':month' => $filterMonth];

if (!empty($filterCat)) {
    $where[] = "c.code = :cat";
    $params[':cat'] = $filterCat;
}
$whereSql = implode(' AND ', $where);

$stmtList = $db->prepare("SELECT ps.*, i.name as product_name, i.code as product_code, u.code as unit_code, c.name as category_name,
    COALESCE((SELECT SUM(actual_qty) FROM production_orders WHERE product_item_id = ps.product_item_id AND planned_start >= ps.scheduled_start AND planned_start <= ps.scheduled_end), 0) as realized_qty
    FROM production_schedules ps 
    JOIN items i ON ps.product_item_id = i.id 
    JOIN item_categories c ON i.category_id = c.id 
    JOIN units u ON i.unit_id = u.id 
    WHERE $whereSql 
    ORDER BY ps.planned_week ASC, i.name ASC");
$stmtList->execute($params);
$schedules = $stmtList->fetchAll(PDO::FETCH_ASSOC);

// Monthly summary aggregates
$totalPlanned = 0;
$totalRealized = 0;
foreach ($schedules as $s) {
    $totalPlanned += (float)$s['planned_qty'];
    $totalRealized += (float)$s['realized_qty'];
}
$achievementRate = $totalPlanned > 0 ? round(($totalRealized / $totalPlanned) * 100, 1) : 0;

$pageTitle = 'Master Production Schedule (MPS)';
$breadcrumbCategory = 'Produksi';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 class="page-title" style="margin: 0; font-size: 22px; font-weight: 700;">Jadwal Induk Produksi (Master Production Schedule - MPS)</h1>
        <div class="page-subtitle" style="color: #64748b; font-size: 13px; margin-top: 4px;">Perencanaan kapasitas manufaktur PT Mayora Indah Tbk &bull; Periode: Bulan <?= $filterMonth ?>, <?= $filterYear ?></div>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="index.php?page=production/mrp" class="btn btn-outline" style="border: 1px solid #cbd5e1; background: #fff; color: #1e293b; font-weight: 600; padding: 8px 14px; border-radius: 6px; text-decoration: none;">
            ⚙️ Buka MRP (Kebutuhan Material) &rarr;
        </a>
        <button type="button" class="btn btn-primary" onclick="openModal('addMpsModal')" style="background: #EE2726; color: #fff; font-weight: 600; padding: 8px 14px; border-radius: 6px; border: none; cursor: pointer;">
            + Tambah Jadwal Produksi
        </button>
    </div>
</div>

<!-- Summary KPI Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
        <span style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">Total Target Produksi</span>
        <div style="font-size: 24px; font-weight: 700; color: #0f172a; margin-top: 6px;"><?= number_format($totalPlanned, 0, ',', '.') ?> <span style="font-size: 13px; font-weight: 400; color: #64748b;">Dus</span></div>
        <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Target kuantitas manufaktur bulan ini</div>
    </div>
    <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
        <span style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">Realisasi Aktual Output</span>
        <div style="font-size: 24px; font-weight: 700; color: #15803d; margin-top: 6px;"><?= number_format($totalRealized, 0, ',', '.') ?> <span style="font-size: 13px; font-weight: 400; color: #64748b;">Dus</span></div>
        <div style="font-size: 11.5px; color: #15803d; margin-top: 4px;">Produk selesai lolos QC ke gudang</div>
    </div>
    <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
        <span style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">Tingkat Pencapaian (OEE/Target)</span>
        <div style="font-size: 24px; font-weight: 700; color: <?= $achievementRate >= 80 ? '#15803d' : ($achievementRate >= 50 ? '#b45309' : '#dc2626') ?>; margin-top: 6px;">
            <?= $achievementRate ?>%
        </div>
        <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Rasio penyelesaian rencana kerja</div>
    </div>
    <div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px;">
        <span style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">Jumlah Batch / SPK Terjadwal</span>
        <div style="font-size: 24px; font-weight: 700; color: #1d4ed8; margin-top: 6px;"><?= count($schedules) ?> <span style="font-size: 13px; font-weight: 400; color: #64748b;">Jadwal</span></div>
        <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">Tersebar di 4 minggu operasional</div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 20px;">
    <form method="GET" action="index.php" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin: 0;">
        <input type="hidden" name="page" value="production/mps">

        <div>
            <label style="display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 4px;">Tahun</label>
            <select name="year" onchange="this.form.submit()" style="padding: 6px 12px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                <?php for ($y = $currentYear - 1; $y <= $currentYear + 1; $y++): ?>
                    <option value="<?= $y ?>" <?= $filterYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <div>
            <label style="display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 4px;">Bulan</label>
            <select name="month" onchange="this.form.submit()" style="padding: 6px 12px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                <?php 
                $monthNames = [1=>'Januari', 2=>'Februari', 3=>'Maret', 4=>'April', 5=>'Mei', 6=>'Juni', 7=>'Juli', 8=>'Agustus', 9=>'September', 10=>'Oktober', 11=>'November', 12=>'Desember'];
                foreach ($monthNames as $mNum => $mLabel): ?>
                    <option value="<?= $mNum ?>" <?= $filterMonth === $mNum ? 'selected' : '' ?>><?= $mLabel ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label style="display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 4px;">Kategori Produk</label>
            <select name="category" onchange="this.form.submit()" style="padding: 6px 12px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                <option value="">Semua Lini Produk</option>
                <option value="CAT-BISKUIT" <?= $filterCat === 'CAT-BISKUIT' ? 'selected' : '' ?>>Biskuit (Roma)</option>
                <option value="CAT-WAFER" <?= $filterCat === 'CAT-WAFER' ? 'selected' : '' ?>>Wafer (Beng-Beng & Astor)</option>
                <option value="CAT-PERMEN" <?= $filterCat === 'CAT-PERMEN' ? 'selected' : '' ?>>Permen (Kopiko & Kis)</option>
                <option value="CAT-COKELAT" <?= $filterCat === 'CAT-COKELAT' ? 'selected' : '' ?>>Cokelat (Choki-Choki)</option>
                <option value="CAT-KOPI" <?= $filterCat === 'CAT-KOPI' ? 'selected' : '' ?>>Kopi (Torabika)</option>
                <option value="CAT-SEREAL" <?= $filterCat === 'CAT-SEREAL' ? 'selected' : '' ?>>Sereal (Energen)</option>
            </select>
        </div>

        <div style="margin-left: auto; font-size: 12px; color: #64748b; padding-top: 14px;">
            Menampilkan <?= count($schedules) ?> jadwal produksi
        </div>
    </form>
</div>

<!-- MPS Schedules Table -->
<div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
    <div class="table-responsive">
        <table class="table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0; text-align: left; font-size: 12px; color: #475569;">
                    <th style="padding: 12px 16px;">Minggu Ke</th>
                    <th style="padding: 12px 16px;">No. Jadwal (MPS)</th>
                    <th style="padding: 12px 16px;">Produk Mayora</th>
                    <th style="padding: 12px 16px;">Kategori</th>
                    <th style="padding: 12px 16px; text-align: right;">Target Rencana</th>
                    <th style="padding: 12px 16px; text-align: right;">Realisasi Aktual</th>
                    <th style="padding: 12px 16px; text-align: center;">Progress</th>
                    <th style="padding: 12px 16px; text-align: center;">Status</th>
                    <th style="padding: 12px 16px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($schedules)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 36px 16px; color: #94a3b8;">
                            Belum ada jadwal induk produksi pada periode ini. Klik "+ Tambah Jadwal Produksi" untuk menjadwalkan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($schedules as $s): 
                        $pct = (float)$s['planned_qty'] > 0 ? min(100, round(((float)$s['realized_qty'] / (float)$s['planned_qty']) * 100)) : 0;
                        $statusBadge = [
                            'draft'     => '<span class="badge" style="background:#f1f5f9; color:#475569; font-size:11px;">Draft</span>',
                            'approved'  => '<span class="badge" style="background:#dbeafe; color:#1d4ed8; font-size:11px;">Disetujui</span>',
                            'in_progress' => '<span class="badge" style="background:#fef3c7; color:#b45309; font-size:11px;">Berjalan</span>',
                            'completed' => '<span class="badge" style="background:#dcfce7; color:#15803d; font-size:11px;">Selesai</span>',
                        ];
                    ?>
                    <tr style="border-bottom: 1px solid #f1f5f9; font-size: 13px;">
                        <td style="padding: 12px 16px;">
                            <span style="display: inline-block; padding: 3px 8px; border-radius: 4px; background: #fee2e2; color: #EE2726; font-weight: 700; font-size: 11px;">
                                Minggu <?= (int)$s['planned_week'] ?>
                            </span>
                        </td>
                        <td style="padding: 12px 16px;">
                            <strong><?= e($s['schedule_number']) ?></strong><br>
                            <span style="font-size: 11px; color: #64748b;"><?= formatDate($s['scheduled_start']) ?> s/d <?= formatDate($s['scheduled_end']) ?></span>
                        </td>
                        <td style="padding: 12px 16px;">
                            <strong><?= e($s['product_name']) ?></strong><br>
                            <code style="font-size: 11px;"><?= e($s['product_code']) ?></code>
                        </td>
                        <td style="padding: 12px 16px; color: #64748b; font-size: 12px;">
                            <?= e($s['category_name']) ?>
                        </td>
                        <td style="padding: 12px 16px; text-align: right; font-weight: 600;">
                            <?= number_format((float)$s['planned_qty'], 0, ',', '.') ?> <?= e($s['unit_code']) ?>
                        </td>
                        <td style="padding: 12px 16px; text-align: right; font-weight: 600; color: #15803d;">
                            <?= number_format((float)$s['realized_qty'], 0, ',', '.') ?> <?= e($s['unit_code']) ?>
                        </td>
                        <td style="padding: 12px 16px; width: 140px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="flex: 1; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden;">
                                    <div style="width: <?= $pct ?>%; height: 100%; background: <?= $pct >= 100 ? '#15803d' : '#EE2726' ?>;"></div>
                                </div>
                                <span style="font-size: 11px; font-weight: 600; color: #475569; width: 32px; text-align: right;"><?= $pct ?>%</span>
                            </div>
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <?= $statusBadge[$s['status']] ?? $s['status'] ?>
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <div style="display: flex; gap: 6px; justify-content: center;">
                                <?php if ($s['status'] === 'draft'): ?>
                                <form method="POST" action="index.php?page=production/mps&year=<?= $filterYear ?>&month=<?= $filterMonth ?>" style="margin: 0;">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="approve_schedule">
                                    <input type="hidden" name="schedule_id" value="<?= (int)$s['id'] ?>">
                                    <button type="submit" class="btn btn-sm" style="background: #15803d; color: #fff; font-size: 11px; padding: 4px 8px; border-radius: 4px; border: none; cursor: pointer;">
                                        Setujui
                                    </button>
                                </form>
                                <?php endif; ?>
                                <a href="index.php?page=production/order_create&product_id=<?= (int)$s['product_item_id'] ?>&qty=<?= (int)$s['planned_qty'] ?>" class="btn btn-sm btn-secondary" style="font-size: 11px; padding: 4px 8px; text-decoration: none;">
                                    + SPK
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Tambah MPS -->
<div id="addMpsModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: #fff; border-radius: 10px; max-width: 520px; width: 90%; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="margin: 0; font-size: 18px; font-weight: 700; color: #1e293b;">Tambah Jadwal Induk Produksi (MPS)</h3>
            <button type="button" onclick="closeModal('addMpsModal')" style="background: none; border: none; font-size: 18px; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>

        <form method="POST" action="index.php?page=production/mps">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_schedule">

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Pilih Produk Mayora *</label>
                <select name="product_item_id" required style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
                    <option value="">-- Pilih Produk Jadi --</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['code']) ?> - <?= e($p['category_name']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 14px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Tahun</label>
                    <input type="number" name="period_year" value="<?= $filterYear ?>" required style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Bulan</label>
                    <select name="period_month" required style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
                        <?php foreach ($monthNames as $mNum => $mLabel): ?>
                            <option value="<?= $mNum ?>" <?= $filterMonth === $mNum ? 'selected' : '' ?>><?= $mLabel ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Minggu Ke</label>
                    <select name="planned_week" required style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
                        <option value="1">Minggu 1</option>
                        <option value="2">Minggu 2</option>
                        <option value="3">Minggu 3</option>
                        <option value="4">Minggu 4</option>
                    </select>
                </div>
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Target Rencana Produksi (Dus) *</label>
                <input type="number" name="planned_qty" min="1" step="1" required placeholder="Contoh: 500" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Tanggal Mulai</label>
                    <input type="date" name="scheduled_start" required value="<?= date('Y-m-01') ?>" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 4px;">Tanggal Selesai</label>
                    <input type="date" name="scheduled_end" required value="<?= date('Y-m-07') ?>" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeModal('addMpsModal')" class="btn btn-secondary" style="padding: 8px 16px; border-radius: 6px;">Batal</button>
                <button type="submit" class="btn btn-primary" style="background: #EE2726; color: #fff; font-weight: 600; padding: 8px 16px; border-radius: 6px; border: none; cursor: pointer;">Simpan Rencana MPS</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'flex';
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
