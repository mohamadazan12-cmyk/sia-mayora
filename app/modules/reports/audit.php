<?php
/**
 * Modul Laporan & Audit: Jejak Audit & Riwayat Aktivitas Sistem (Audit Trail Viewer)
 * Menampilkan pencatatan audit perubahan data, identitas operator, stempel waktu, dan perbandingan nilai.
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Jejak Audit & Riwayat Sistem';
$breadcrumbCategory = 'Laporan & Audit';

$db = getDbConnection();
$user = currentUser();

// RBAC: Administrator, Auditor, Manajer
requireRole(['Administrator', 'admin', 'Supervisor / Manajer', 'Supervisor/Manajer', 'manager', 'manajer', 'Finance/Treasury', 'finance']);

// Filter Parameters
$startDate = trim($_GET['start_date'] ?? date('Y-m-01'));
$endDate = trim($_GET['end_date'] ?? date('Y-m-t'));
$actionFilter = trim($_GET['action_name'] ?? '');
$entityFilter = trim($_GET['entity_type'] ?? '');
$userFilter = (int)($_GET['user_id'] ?? 0);
$search = trim($_GET['q'] ?? '');

$where = ["1=1"];
$params = [];

if ($startDate !== '') {
    $where[] = "DATE(al.created_at) >= :start_date";
    $params[':start_date'] = $startDate;
}
if ($endDate !== '') {
    $where[] = "DATE(al.created_at) <= :end_date";
    $params[':end_date'] = $endDate;
}
if ($actionFilter !== '') {
    $where[] = "al.action = :action_name";
    $params[':action_name'] = $actionFilter;
}
if ($entityFilter !== '') {
    $where[] = "al.entity_type = :entity_type";
    $params[':entity_type'] = $entityFilter;
}
if ($userFilter > 0) {
    $where[] = "al.user_id = :user_id";
    $params[':user_id'] = $userFilter;
}
if ($search !== '') {
    $where[] = "(al.action LIKE :q OR al.entity_type LIKE :q OR al.ip_address LIKE :q OR u.full_name LIKE :q OR al.new_values LIKE :q)";
    $params[':q'] = "%{$search}%";
}

$whereSql = implode(' AND ', $where);

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmtExport = $db->prepare("
        SELECT al.created_at, u.full_name AS operator_name, u.username, r.role_name,
               al.action, al.entity_type, al.entity_id, al.ip_address,
               al.old_values, al.new_values
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE {$whereSql}
        ORDER BY al.id DESC
    ");
    $stmtExport->execute($params);
    $rows = $stmtExport->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=audit_trail_' . date('Ymd_His') . '.csv');

    $fp = fopen('php://output', 'w');
    fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($fp, ['Waktu & Tanggal', 'Nama Petugas', 'Username', 'Peran / Role', 'Tindakan (Action)', 'Entitas', 'ID Entitas', 'Alamat IP', 'Nilai Sebelum', 'Nilai Sesudah']);

    foreach ($rows as $r) {
        fputcsv($fp, [
            formatDateTime($r['created_at']),
            $r['operator_name'] ?: 'Sistem Otomatis',
            $r['username'] ?: '-',
            $r['role_name'] ?: '-',
            $r['action'],
            $r['entity_type'],
            $r['entity_id'] ?: '-',
            $r['ip_address'] ?: '-',
            $r['old_values'] ?: '-',
            $r['new_values'] ?: '-'
        ]);
    }
    fclose($fp);
    exit;
}

// Summary Metrics
$stmtMetrics = $db->prepare("
    SELECT 
        COUNT(al.id) AS total_logs,
        COUNT(DISTINCT al.user_id) AS active_users,
        SUM(CASE WHEN al.action LIKE '%approve%' OR al.action LIKE '%reject%' THEN 1 ELSE 0 END) AS total_approvals,
        SUM(CASE WHEN DATE(al.created_at) = CURDATE() THEN 1 ELSE 0 END) AS logs_today
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE {$whereSql}
");
$stmtMetrics->execute($params);
$metrics = $stmtMetrics->fetch();

// Total count for pagination
$stmtCount = $db->prepare("
    SELECT COUNT(al.id)
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE {$whereSql}
");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();

$currentPage = (int)($_GET['p'] ?? 1);
$perPage = 20;
$pagination = paginate($totalRows, $currentPage, $perPage);

// Fetch logs
$stmtLogs = $db->prepare("
    SELECT al.*, u.full_name AS operator_name, u.username, r.role_name
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE {$whereSql}
    ORDER BY al.id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) {
    $stmtLogs->bindValue($k, $v);
}
$stmtLogs->bindValue(':limit', $pagination['per_page'], PDO::PARAM_INT);
$stmtLogs->bindValue(':offset', $pagination['offset'], PDO::PARAM_INT);
$stmtLogs->execute();
$logs = $stmtLogs->fetchAll();

// Users list for dropdown
$userList = $db->query("SELECT id, username, full_name FROM users ORDER BY full_name ASC")->fetchAll();

// Distinct actions & entities
$distinctActions = $db->query("SELECT DISTINCT action FROM audit_logs WHERE action IS NOT NULL AND action != '' ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
$distinctEntities = $db->query("SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL AND entity_type != '' ORDER BY entity_type ASC")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Jejak Audit &amp; Riwayat Sistem</h1>
        <div class="page-subtitle">Transparansi Operasional &bull; Log Jejak Audit Permanen, Pelacakan Modifikasi Dokumen, dan Pengawasan Hak Akses</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=reports/index" class="btn btn-secondary">
            📑 Laporan Operasional
        </a>
        <a href="index.php?page=reports/journals" class="btn btn-secondary">
            📑 Jurnal Umum
        </a>
        <a href="index.php?page=reports/audit&export=csv<?= $startDate ? '&start_date='.$startDate : '' ?><?= $endDate ? '&end_date='.$endDate : '' ?><?= $actionFilter ? '&action_name='.$actionFilter : '' ?><?= $entityFilter ? '&entity_type='.$entityFilter : '' ?><?= $userFilter ? '&user_id='.$userFilter : '' ?><?= $search ? '&q='.urlencode($search) : '' ?>" class="btn btn-secondary">
            📥 Unduh Log (CSV)
        </a>
    </div>
</div>

<?= renderFlashMessages() ?>

<!-- Summary Cards -->
<div class="metrics-grid mb-4" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
    <div class="metric-card">
        <div class="metric-value"><?= formatNumber($metrics['total_logs'] ?? 0, 0) ?> Aktivitas</div>
        <div class="metric-label">Total Log Tersaring</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#0284c7;"><?= formatNumber($metrics['logs_today'] ?? 0, 0) ?> Aktivitas</div>
        <div class="metric-label">Aktivitas Hari Ini</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#16a34a;"><?= formatNumber($metrics['active_users'] ?? 0, 0) ?> Operator</div>
        <div class="metric-label">Petugas / Operator Terlibat</div>
    </div>
    <div class="metric-card">
        <div class="metric-value" style="color:#d97706;"><?= formatNumber($metrics['total_approvals'] ?? 0, 0) ?> Dokumen</div>
        <div class="metric-label">Keputusan Persetujuan / Approval</div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card mb-4">
    <div style="padding: 15px 20px;">
        <form action="index.php" method="GET" class="filter-bar" style="margin-bottom:0; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
            <input type="hidden" name="page" value="reports/audit">

            <div class="filter-group">
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Cari keyword / IP / Nilai..." style="min-width:200px;">
            </div>

            <div class="filter-group">
                <label style="font-size:12px; margin-right:4px; color:#64748b;">Dari:</label>
                <input type="date" name="start_date" value="<?= e($startDate) ?>">
            </div>

            <div class="filter-group">
                <label style="font-size:12px; margin-right:4px; color:#64748b;">Sampai:</label>
                <input type="date" name="end_date" value="<?= e($endDate) ?>">
            </div>

            <div class="filter-group">
                <select name="action_name">
                    <option value="">-- Semua Aksi --</option>
                    <?php foreach ($distinctActions as $act): ?>
                        <option value="<?= e($act) ?>" <?= $actionFilter === $act ? 'selected' : '' ?>>
                            <?= e(strtoupper(str_replace('_', ' ', $act))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <select name="entity_type">
                    <option value="">-- Semua Entitas / Modul --</option>
                    <?php foreach ($distinctEntities as $ent): ?>
                        <option value="<?= e($ent) ?>" <?= $entityFilter === $ent ? 'selected' : '' ?>>
                            <?= e(strtoupper(str_replace('_', ' ', $ent))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <select name="user_id">
                    <option value="">-- Semua Pengguna --</option>
                    <?php foreach ($userList as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $userFilter === (int)$u['id'] ? 'selected' : '' ?>>
                            <?= e($u['full_name']) ?> (<?= e($u['username']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary btn-sm">Saring Data</button>

            <?php if ($search !== '' || $actionFilter !== '' || $entityFilter !== '' || $userFilter > 0 || $startDate !== date('Y-m-01') || $endDate !== date('Y-m-t')): ?>
                <a href="index.php?page=reports/audit" class="btn btn-secondary btn-sm">Reset</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Audit Trail Table -->
<div class="card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <h2 class="card-title">Daftar Rekam Jejak Audit Aktivitas</h2>
        <span class="badge badge-info"><?= $totalRows ?> Total Log</span>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:140px;">Waktu &amp; Tanggal</th>
                        <th style="width:160px;">Operator / Pengguna</th>
                        <th style="width:140px;">Tindakan (Action)</th>
                        <th style="width:140px;">Entitas Data</th>
                        <th style="width:70px;">ID Ref</th>
                        <th style="width:110px;">Alamat IP</th>
                        <th>Perubahan Data (Snapshot / Payload)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:30px; color:#94a3b8;">
                                Belum ada rekaman audit log yang cocok dengan filter yang dipilih.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td style="white-space:nowrap; font-size:12px; font-family:monospace; color:#475569;">
                                    <?= formatDateTime($l['created_at']) ?>
                                </td>
                                <td>
                                    <strong><?= e($l['operator_name'] ?: 'Sistem Otomatis') ?></strong><br>
                                    <small style="color:#64748b;"><?= e($l['role_name'] ?: '-') ?></small>
                                </td>
                                <td>
                                    <?php
                                    $actBadge = 'badge-secondary';
                                    if (strpos($l['action'], 'create') !== false || strpos($l['action'], 'add') !== false) {
                                        $actBadge = 'badge-success';
                                    } elseif (strpos($l['action'], 'update') !== false || strpos($l['action'], 'adjust') !== false) {
                                        $actBadge = 'badge-info';
                                    } elseif (strpos($l['action'], 'delete') !== false || strpos($l['action'], 'reverse') !== false || strpos($l['action'], 'reject') !== false) {
                                        $actBadge = 'badge-danger';
                                    } elseif (strpos($l['action'], 'approve') !== false) {
                                        $actBadge = 'badge-success';
                                    }
                                    ?>
                                    <span class="badge <?= $actBadge ?>"><?= e(strtoupper(str_replace('_', ' ', $l['action']))) ?></span>
                                </td>
                                <td>
                                    <span style="font-family:monospace; font-size:12px; font-weight:600; color:#1e293b;"><?= e($l['entity_type']) ?></span>
                                </td>
                                <td>
                                    <span style="font-family:monospace;"><?= $l['entity_id'] ?: '-' ?></span>
                                </td>
                                <td style="font-family:monospace; font-size:12px; color:#64748b;">
                                    <?= e($l['ip_address'] ?: '127.0.0.1') ?>
                                </td>
                                <td>
                                    <details style="cursor:pointer; font-size:12.5px;">
                                        <summary style="color:#0284c7; outline:none; font-weight:500;">
                                            Lihat Rincian Perubahan Data
                                        </summary>
                                        <div style="margin-top:8px; padding:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px; font-family:monospace; font-size:11.5px; white-space:pre-wrap; max-height:200px; overflow-y:auto;">
<?php if ($l['old_values']): ?>
<strong style="color:#dc2626;">[NILAI SEBELUM]:</strong>
<?= e(is_string($l['old_values']) && (strpos($l['old_values'], '{') === 0 || strpos($l['old_values'], '[') === 0) ? json_encode(json_decode($l['old_values']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $l['old_values']) ?>

<?php endif; ?>
<?php if ($l['new_values']): ?>
<strong style="color:#16a34a;">[NILAI SESUDAH]:</strong>
<?= e(is_string($l['new_values']) && (strpos($l['new_values'], '{') === 0 || strpos($l['new_values'], '[') === 0) ? json_encode(json_decode($l['new_values']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $l['new_values']) ?>
<?php endif; ?>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($pagination['total_pages'] > 1): ?>
        <div class="card-footer" style="padding:15px 20px;">
            <?php
            $baseUrl = 'index.php?page=reports/audit' .
                ($startDate ? '&start_date='.$startDate : '') .
                ($endDate ? '&end_date='.$endDate : '') .
                ($actionFilter ? '&action_name='.$actionFilter : '') .
                ($entityFilter ? '&entity_type='.$entityFilter : '') .
                ($userFilter ? '&user_id='.$userFilter : '') .
                ($search ? '&q='.urlencode($search) : '');
            echo renderPagination($pagination, $baseUrl);
            ?>
        </div>
    <?php endif; ?>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>
