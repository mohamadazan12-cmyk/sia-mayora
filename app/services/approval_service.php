<?php
/**
 * Approval Service
 * Handles multi-level approvals, rejections with mandatory reasons, and audit trail.
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

class ApprovalService {

    /**
     * Submit an entity for approval
     */
    public static function submit($entityType, $entityId, $refNumber, $notes = '', $pdo = null) {
        if ($pdo === null) {
            $pdo = getDbConnection();
        }
        $stmt = $pdo->prepare('
            INSERT INTO approvals (entity_type, entity_id, ref_number, decision, reason, created_at)
            VALUES (:entity_type, :entity_id, :ref_number, "pending", :reason, NOW())
        ');
        $stmt->execute([
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':ref_number'  => $refNumber,
            ':reason'      => $notes,
        ]);
        return $pdo->lastInsertId();
    }

    /**
     * Approve an entity
     */
    public static function approve($entityType, $entityId, $refNumber, $userId, $notes = '', $pdo = null) {
        if ($pdo === null) {
            $pdo = getDbConnection();
        }

        $stmt = $pdo->prepare('
            INSERT INTO approvals (entity_type, entity_id, ref_number, decision, reason, approved_by, decided_at, created_at)
            VALUES (:entity_type, :entity_id, :ref_number, "approved", :reason, :approved_by, NOW(), NOW())
        ');
        $stmt->execute([
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':ref_number'  => $refNumber,
            ':reason'      => $notes ?: 'Disetujui',
            ':approved_by' => $userId,
        ]);

        logAudit($userId, 'approval_approved', $entityType, $entityId, null, [
            'decision' => 'approved',
            'notes'    => $notes,
        ]);

        return true;
    }

    /**
     * Reject an entity (Mandatory reason required)
     */
    public static function reject($entityType, $entityId, $refNumber, $userId, $reason, $pdo = null) {
        $reason = trim($reason);
        if (empty($reason)) {
            throw new InvalidArgumentException('Alasan penolakan wajib diisi.');
        }

        if ($pdo === null) {
            $pdo = getDbConnection();
        }

        $stmt = $pdo->prepare('
            INSERT INTO approvals (entity_type, entity_id, ref_number, decision, reason, approved_by, decided_at, created_at)
            VALUES (:entity_type, :entity_id, :ref_number, "rejected", :reason, :approved_by, NOW(), NOW())
        ');
        $stmt->execute([
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':ref_number'  => $refNumber,
            ':reason'      => $reason,
            ':approved_by' => $userId,
        ]);

        logAudit($userId, 'approval_rejected', $entityType, $entityId, null, [
            'decision' => 'rejected',
            'reason'   => $reason,
        ]);

        return true;
    }

    /**
     * Request revision (Mandatory reason required)
     */
    public static function requestRevision($entityType, $entityId, $refNumber, $userId, $reason, $pdo = null) {
        $reason = trim($reason);
        if (empty($reason)) {
            throw new InvalidArgumentException('Alasan permintaan revisi wajib diisi.');
        }

        if ($pdo === null) {
            $pdo = getDbConnection();
        }

        $stmt = $pdo->prepare('
            INSERT INTO approvals (entity_type, entity_id, ref_number, decision, reason, approved_by, decided_at, created_at)
            VALUES (:entity_type, :entity_id, :ref_number, "revision", :reason, :approved_by, NOW(), NOW())
        ');
        $stmt->execute([
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':ref_number'  => $refNumber,
            ':reason'      => $reason,
            ':approved_by' => $userId,
        ]);

        logAudit($userId, 'approval_revision', $entityType, $entityId, null, [
            'decision' => 'revision',
            'reason'   => $reason,
        ]);

        return true;
    }

    /**
     * Get approval history for an entity
     */
    public static function getHistory($entityType, $entityId) {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare('
            SELECT a.*, u.full_name AS approver_name, r.role_name AS approver_role
            FROM approvals a
            LEFT JOIN users u ON a.approved_by = u.id
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE a.entity_type = :entity_type AND a.entity_id = :entity_id
            ORDER BY a.id ASC
        ');
        $stmt->execute([
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
        ]);
        return $stmt->fetchAll();
    }
}
