<?php
/**
 * Notification Service
 * Sistem Informasi Akuntansi PT Mayora Indah Tbk.
 * Manages notifications for all 9 user roles with persistence,
 * unread badges, instant mark-as-read, and deep links to transactions.
 */

require_once __DIR__ . '/../config/database.php';

class NotificationService {

    /**
     * Send notification to a specific user or role
     *
     * @param array $data [
     *   'user_id'    => ?int,
     *   'role_code'  => ?string, (admin, purchasing, ppic, gudang, qc, finance, hr, manager, penjualan)
     *   'title'      => string,
     *   'message'    => string,
     *   'type'       => string ('info'|'warning'|'success'|'danger'),
     *   'category'   => string ('procurement'|'production'|'qc'|'inventory'|'finance'|'hr'|'system'),
     *   'link'       => ?string,
     *   'ref_type'   => ?string,
     *   'ref_id'     => ?int
     * ]
     * @return int|false Notification ID
     */
    public static function create($data) {
        $pdo = getDbConnection();

        $userId = !empty($data['user_id']) ? (int)$data['user_id'] : null;
        $roleCode = !empty($data['role_code']) ? trim($data['role_code']) : null;
        $title = trim($data['title'] ?? 'Pemberitahuan Sistem');
        $message = trim($data['message'] ?? '');
        $type = in_array($data['type'] ?? '', ['info', 'warning', 'success', 'danger']) ? $data['type'] : 'info';
        $category = trim($data['category'] ?? 'system');
        $link = !empty($data['link']) ? trim($data['link']) : null;
        $refType = !empty($data['ref_type']) ? trim($data['ref_type']) : null;
        $refId = !empty($data['ref_id']) ? (int)$data['ref_id'] : null;

        // Prevent duplicate spam: check if identical notification exists within the last 5 minutes
        if ($refType && $refId) {
            $checkStmt = $pdo->prepare("SELECT id FROM notifications 
                WHERE ((user_id = ? AND user_id IS NOT NULL) OR (role_code = ? AND role_code IS NOT NULL))
                AND ref_type = ? AND ref_id = ? AND is_read = 0
                AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                LIMIT 1");
            $checkStmt->execute([$userId, $roleCode, $refType, $refId]);
            if ($checkStmt->fetch()) {
                return false; // Skip duplicate notification
            }
        }

        $stmt = $pdo->prepare("INSERT INTO notifications 
            (user_id, role_code, title, message, type, category, link, ref_type, ref_id, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())");
        
        $stmt->execute([
            $userId,
            $roleCode,
            $title,
            $message,
            $type,
            $category,
            $link,
            $refType,
            $refId
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Get unread notification count for a user and their role
     */
    public static function getUnreadCount($user) {
        if (empty($user)) return 0;
        $pdo = getDbConnection();

        $userId = (int)($user['id'] ?? 0);
        $roleCode = $user['role_code'] ?? '';

        // Role aliases for compatibility
        $roleCodes = [$roleCode];
        if ($roleCode === 'pembelian') $roleCodes[] = 'purchasing';
        if ($roleCode === 'purchasing') $roleCodes[] = 'pembelian';
        if ($roleCode === 'produksi') $roleCodes[] = 'ppic';
        if ($roleCode === 'ppic') $roleCodes[] = 'produksi';
        if ($roleCode === 'manajer') $roleCodes[] = 'manager';
        if ($roleCode === 'manager') $roleCodes[] = 'manajer';

        $inClause = implode("','", array_map('addslashes', $roleCodes));

        $sql = "SELECT COUNT(*) FROM notifications 
                WHERE is_read = 0 
                AND (user_id = ? OR (user_id IS NULL AND role_code IN ('$inClause')))";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get recent notifications for topbar dropdown
     */
    public static function getRecent($user, $limit = 5) {
        if (empty($user)) return [];
        $pdo = getDbConnection();

        $userId = (int)($user['id'] ?? 0);
        $roleCode = $user['role_code'] ?? '';

        $roleCodes = [$roleCode];
        if ($roleCode === 'pembelian') $roleCodes[] = 'purchasing';
        if ($roleCode === 'purchasing') $roleCodes[] = 'pembelian';
        if ($roleCode === 'produksi') $roleCodes[] = 'ppic';
        if ($roleCode === 'ppic') $roleCodes[] = 'produksi';
        if ($roleCode === 'manajer') $roleCodes[] = 'manager';
        if ($roleCode === 'manager') $roleCodes[] = 'manajer';

        $inClause = implode("','", array_map('addslashes', $roleCodes));

        $sql = "SELECT * FROM notifications 
                WHERE (user_id = ? OR (user_id IS NULL AND role_code IN ('$inClause')))
                ORDER BY is_read ASC, created_at DESC 
                LIMIT " . (int)$limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark a single notification as read
     */
    public static function markAsRead($notificationId, $user) {
        $pdo = getDbConnection();
        $userId = (int)($user['id'] ?? 0);
        $roleCode = $user['role_code'] ?? '';

        $roleCodes = [$roleCode];
        if ($roleCode === 'pembelian') $roleCodes[] = 'purchasing';
        if ($roleCode === 'purchasing') $roleCodes[] = 'pembelian';
        if ($roleCode === 'produksi') $roleCodes[] = 'ppic';
        if ($roleCode === 'ppic') $roleCodes[] = 'produksi';
        if ($roleCode === 'manajer') $roleCodes[] = 'manager';
        if ($roleCode === 'manager') $roleCodes[] = 'manajer';

        $inClause = implode("','", array_map('addslashes', $roleCodes));

        $stmt = $pdo->prepare("UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE id = ? AND (user_id = ? OR (user_id IS NULL AND role_code IN ('$inClause')))");
        return $stmt->execute([(int)$notificationId, $userId]);
    }

    /**
     * Mark all notifications as read for current user/role
     */
    public static function markAllAsRead($user) {
        $pdo = getDbConnection();
        $userId = (int)($user['id'] ?? 0);
        $roleCode = $user['role_code'] ?? '';

        $roleCodes = [$roleCode];
        if ($roleCode === 'pembelian') $roleCodes[] = 'purchasing';
        if ($roleCode === 'purchasing') $roleCodes[] = 'pembelian';
        if ($roleCode === 'produksi') $roleCodes[] = 'ppic';
        if ($roleCode === 'ppic') $roleCodes[] = 'produksi';
        if ($roleCode === 'manajer') $roleCodes[] = 'manager';
        if ($roleCode === 'manager') $roleCodes[] = 'manajer';

        $inClause = implode("','", array_map('addslashes', $roleCodes));

        $stmt = $pdo->prepare("UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE is_read = 0 AND (user_id = ? OR (user_id IS NULL AND role_code IN ('$inClause')))");
        return $stmt->execute([$userId]);
    }
}
