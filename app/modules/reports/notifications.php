<?php
/**
 * Halaman Pusat Notifikasi Terpadu (Notifications Center)
 * PT Mayora Indah Tbk
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../services/notification_service.php';

requireLogin();
$user = currentUser();
$userId = (int)$user['id'];
$roleCode = $user['role_code'] ?? '';

// Handle Actions (Mark As Read, Mark All As Read)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_all_read') {
        NotificationService::markAllAsRead($user);
        setFlash('success', 'Semua notifikasi berhasil ditandai sebagai sudah dibaca.');
        header('Location: index.php?page=reports/notifications');
        exit;
    }

    if ($action === 'mark_read') {
        $notifId = (int)($_POST['notification_id'] ?? 0);
        if ($notifId > 0) {
            NotificationService::markAsRead($notifId, $user);
            setFlash('success', 'Notifikasi berhasil ditandai sebagai sudah dibaca.');
        }
        $redirectUrl = $_POST['redirect_to'] ?? 'index.php?page=reports/notifications';
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// Filters
$filterRead = $_GET['status'] ?? 'all'; // all, unread, read
$filterCategory = $_GET['category'] ?? 'all';
$pageNumber = max(1, (int)($_GET['p'] ?? 1));
$limit = 15;
$offset = ($pageNumber - 1) * $limit;

$roleCodes = [$roleCode];
if ($roleCode === 'pembelian') $roleCodes[] = 'purchasing';
if ($roleCode === 'purchasing') $roleCodes[] = 'pembelian';
if ($roleCode === 'produksi') $roleCodes[] = 'ppic';
if ($roleCode === 'ppic') $roleCodes[] = 'produksi';
if ($roleCode === 'manajer') $roleCodes[] = 'manager';
if ($roleCode === 'manager') $roleCodes[] = 'manajer';

$inRoles = implode("','", array_map('addslashes', $roleCodes));

$where = ["(user_id = :user_id OR (user_id IS NULL AND role_code IN ('$inRoles')))"];
$params = [':user_id' => $userId];

if ($filterRead === 'unread') {
    $where[] = "is_read = 0";
} elseif ($filterRead === 'read') {
    $where[] = "is_read = 1";
}

if ($filterCategory !== 'all' && !empty($filterCategory)) {
    $where[] = "category = :category";
    $params[':category'] = $filterCategory;
}

$whereSql = implode(' AND ', $where);

$pdo = getDbConnection();
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE $whereSql");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $limit));

$listStmt = $pdo->prepare("SELECT * FROM notifications WHERE $whereSql ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
$listStmt->execute($params);
$notifications = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$unreadTotal = NotificationService::getUnreadCount($user);

$pageTitle = 'Pusat Notifikasi & Pemberitahuan';
include __DIR__ . '/../../includes/header.php';
?>

<div class="content-header" style="margin-bottom: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
            <h1 style="margin: 0; font-size: 22px; font-weight: 700; color: #1e293b;">Pusat Notifikasi Sistem</h1>
            <p style="margin: 4px 0 0 0; color: #64748b; font-size: 13px;">Daftar seluruh pemberitahuan, peringatan operasional, dan status approval untuk peran <strong><?= e($user['role_name'] ?? $roleCode) ?></strong>.</p>
        </div>
        <div style="display: flex; gap: 8px;">
            <?php if ($unreadTotal > 0): ?>
            <form method="POST" action="index.php?page=reports/notifications" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn btn-outline" style="background:#fff; border:1px solid #cbd5e1; color:#0f172a; font-weight:600; font-size:12px; padding:8px 14px; border-radius:6px; cursor:pointer;">
                    ✓ Tandai Semua Sudah Dibaca (<?= $unreadTotal ?>)
                </button>
            </form>
            <?php endif; ?>
            <a href="index.php?page=dashboard" class="btn btn-primary" style="background:#EE2726; color:#fff; font-weight:600; font-size:12px; padding:8px 14px; border-radius:6px; text-decoration:none;">
                Kembali ke Dashboard
            </a>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 20px;">
    <form method="GET" action="index.php" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin: 0;">
        <input type="hidden" name="page" value="reports/notifications">
        
        <div>
            <label style="display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 4px;">Status Baca</label>
            <select name="status" onchange="this.form.submit()" style="padding: 6px 12px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                <option value="all" <?= $filterRead === 'all' ? 'selected' : '' ?>>Semua Status</option>
                <option value="unread" <?= $filterRead === 'unread' ? 'selected' : '' ?>>Belum Dibaca (<?= $unreadTotal ?>)</option>
                <option value="read" <?= $filterRead === 'read' ? 'selected' : '' ?>>Sudah Dibaca</option>
            </select>
        </div>

        <div>
            <label style="display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 4px;">Kategori</label>
            <select name="category" onchange="this.form.submit()" style="padding: 6px 12px; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px;">
                <option value="all" <?= $filterCategory === 'all' ? 'selected' : '' ?>>Semua Kategori</option>
                <option value="procurement" <?= $filterCategory === 'procurement' ? 'selected' : '' ?>>Pengadaan & Pembelian</option>
                <option value="production" <?= $filterCategory === 'production' ? 'selected' : '' ?>>Produksi & PPIC</option>
                <option value="qc" <?= $filterCategory === 'qc' ? 'selected' : '' ?>>Quality Control</option>
                <option value="inventory" <?= $filterCategory === 'inventory' ? 'selected' : '' ?>>Gudang & Stok</option>
                <option value="finance" <?= $filterCategory === 'finance' ? 'selected' : '' ?>>Keuangan & Akuntansi</option>
                <option value="hr" <?= $filterCategory === 'hr' ? 'selected' : '' ?>>SDM & Payroll</option>
                <option value="system" <?= $filterCategory === 'system' ? 'selected' : '' ?>>Sistem Umum</option>
            </select>
        </div>

        <div style="margin-left: auto; font-size: 12px; color: #64748b; padding-top: 14px;">
            Menampilkan <?= count($notifications) ?> dari <?= $totalRows ?> notifikasi
        </div>
    </form>
</div>

<!-- List Notifikasi -->
<div class="card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
    <?php if (empty($notifications)): ?>
        <div style="text-align: center; padding: 48px 20px; color: #94a3b8;">
            <svg style="width: 48px; height: 48px; margin: 0 auto 12px auto; color: #cbd5e1; display: block;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            <h4 style="margin: 0 0 6px 0; font-size: 15px; color: #475569; font-weight: 600;">Tidak ada notifikasi</h4>
            <p style="margin: 0; font-size: 13px;">Belum ada notifikasi pada kategori atau filter yang dipilih.</p>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column;">
            <?php foreach ($notifications as $n): 
                $typeColors = [
                    'info'    => ['bg' => '#eff6ff', 'border' => '#bfdbfe', 'text' => '#1d4ed8', 'badge' => '#dbeafe'],
                    'warning' => ['bg' => '#fffbeb', 'border' => '#fde68a', 'text' => '#b45309', 'badge' => '#fef3c7'],
                    'danger'  => ['bg' => '#fef2f2', 'border' => '#fecaca', 'text' => '#b91c1c', 'badge' => '#fee2e2'],
                    'success' => ['bg' => '#f0fdf4', 'border' => '#bbf7d0', 'text' => '#15803d', 'badge' => '#dcfce7']
                ];
                $c = $typeColors[$n['type']] ?? $typeColors['info'];
                $isUnread = (int)$n['is_read'] === 0;
            ?>
            <div style="display: flex; align-items: flex-start; gap: 14px; padding: 16px 20px; border-bottom: 1px solid #f1f5f9; background: <?= $isUnread ? '#fff9f9' : '#ffffff' ?>; transition: background 0.15s ease;">
                <!-- Icon Dot -->
                <div style="width: 10px; height: 10px; border-radius: 50%; margin-top: 5px; flex-shrink: 0; background: <?= $isUnread ? '#EE2726' : '#cbd5e1' ?>;"></div>
                
                <!-- Content -->
                <div style="flex: 1; min-width: 0;">
                    <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 10px; margin-bottom: 4px;">
                        <h4 style="margin: 0; font-size: 14px; font-weight: <?= $isUnread ? '700' : '600' ?>; color: #0f172a;">
                            <?= e($n['title']) ?>
                        </h4>
                        <span style="font-size: 11px; color: #94a3b8; white-space: nowrap;">
                            <?= date('d M Y, H:i', strtotime($n['created_at'])) ?>
                        </span>
                    </div>

                    <p style="margin: 0 0 8px 0; font-size: 13px; color: #475569; line-height: 1.5;">
                        <?= nl2br(e($n['message'])) ?>
                    </p>

                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <span style="font-size: 10.5px; font-weight: 600; text-transform: uppercase; background: <?= $c['badge'] ?>; color: <?= $c['text'] ?>; padding: 2px 8px; border-radius: 4px;">
                            <?= e($n['category']) ?>
                        </span>

                        <?php if (!empty($n['link'])): ?>
                            <a href="<?= e($n['link']) ?>" style="font-size: 12px; font-weight: 600; color: #EE2726; text-decoration: none;">
                                Buka Transaksi Terkait &rarr;
                            </a>
                        <?php endif; ?>

                        <?php if ($isUnread): ?>
                            <form method="POST" action="index.php?page=reports/notifications" style="margin:0; display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>">
                                <button type="submit" style="background: none; border: none; padding: 0; color: #64748b; font-size: 11.5px; text-decoration: underline; cursor: pointer;">
                                    Tandai telah dibaca
                                </button>
                            </form>
                        <?php else: ?>
                            <span style="font-size: 11px; color: #94a3b8;">✓ Sudah dibaca</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div style="margin-top: 20px; display: flex; justify-content: center; gap: 6px;">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <a href="index.php?page=reports/notifications&status=<?= e($filterRead) ?>&category=<?= e($filterCategory) ?>&p=<?= $p ?>" 
           style="padding: 6px 12px; font-size: 12px; font-weight: 600; border-radius: 4px; text-decoration: none; border: 1px solid #cbd5e1; <?= $p === $pageNumber ? 'background:#EE2726; color:#fff; border-color:#EE2726;' : 'background:#fff; color:#334155;' ?>">
            <?= $p ?>
        </a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
