<?php
/**
 * Internal Accounting and Journal Service
 * Handles balanced double-entry journal entries, automatic postings, and reversals.
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

class JournalService {

    /**
     * Create and validate a balanced double-entry journal entry
     * $lines is an array of ['account_id' => int, 'description' => string, 'debit' => float, 'credit' => float]
     */
    public static function createEntry($entryDate, $refType, $refId, $refNumber, $description, array $lines, $userId, $autoPost = true, $pdo = null) {
        if (empty($lines)) {
            throw new InvalidArgumentException('Baris jurnal tidak boleh kosong.');
        }

        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($lines as $line) {
            $totalDebit += (float)($line['debit'] ?? 0);
            $totalCredit += (float)($line['credit'] ?? 0);
        }

        // Check if balanced (allow small roundoff difference <= 0.01)
        if (abs($totalDebit - $totalCredit) > 0.01) {
            throw new Exception("Jurnal tidak seimbang! Total Debit (" . number_format($totalDebit, 2) . ") harus sama dengan Total Kredit (" . number_format($totalCredit, 2) . ").");
        }

        $localTransaction = false;
        if ($pdo === null) {
            $pdo = getDbConnection();
            $pdo->beginTransaction();
            $localTransaction = true;
        }

        try {
            // Generate entry number
            $yearMonth = date('Ym', strtotime($entryDate));
            $prefix = 'JV-' . $yearMonth;
            $stmtLast = $pdo->prepare('SELECT entry_number FROM journal_entries WHERE entry_number LIKE :pfx ORDER BY id DESC LIMIT 1');
            $stmtLast->execute([':pfx' => $prefix . '-%']);
            $lastNum = $stmtLast->fetchColumn();
            $seq = 1;
            if ($lastNum) {
                $parts = explode('-', $lastNum);
                $seq = (int)end($parts) + 1;
            }
            $entryNumber = sprintf('%s-%04d', $prefix, $seq);

            $status = $autoPost ? 'posted' : 'draft';
            $postedAt = $autoPost ? date('Y-m-d H:i:s') : null;
            $postedBy = $autoPost ? $userId : null;

            // Insert Header
            $stmtHeader = $pdo->prepare('
                INSERT INTO journal_entries 
                (entry_number, entry_date, ref_type, ref_id, ref_number, description, total_debit, total_credit, status, posted_by, posted_at, created_at, created_by)
                VALUES 
                (:entry_number, :entry_date, :ref_type, :ref_id, :ref_number, :description, :total_debit, :total_credit, :status, :posted_by, :posted_at, NOW(), :created_by)
            ');
            $stmtHeader->execute([
                ':entry_number' => $entryNumber,
                ':entry_date'   => $entryDate,
                ':ref_type'     => $refType,
                ':ref_id'       => $refId,
                ':ref_number'   => $refNumber,
                ':description'  => $description,
                ':total_debit'  => $totalDebit,
                ':total_credit' => $totalCredit,
                ':status'       => $status,
                ':posted_by'    => $postedBy,
                ':posted_at'    => $postedAt,
                ':created_by'   => $userId,
            ]);
            $journalId = $pdo->lastInsertId();

            // Insert Lines
            $stmtLine = $pdo->prepare('
                INSERT INTO journal_entry_lines 
                (journal_entry_id, account_id, description, debit, credit)
                VALUES 
                (:journal_entry_id, :account_id, :description, :debit, :credit)
            ');

            foreach ($lines as $line) {
                $stmtLine->execute([
                    ':journal_entry_id' => $journalId,
                    ':account_id'       => $line['account_id'],
                    ':description'      => $line['description'] ?? $description,
                    ':debit'            => (float)($line['debit'] ?? 0),
                    ':credit'           => (float)($line['credit'] ?? 0),
                ]);
            }

            logAudit($userId, 'create_journal', 'journal_entries', $journalId, null, [
                'entry_number' => $entryNumber,
                'total'        => $totalDebit,
                'status'       => $status,
            ]);

            if ($localTransaction) {
                $pdo->commit();
            }

            return $journalId;
        } catch (Exception $e) {
            if ($localTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reverse a posted journal entry (reversal voucher)
     */
    public static function reverseEntry($journalEntryId, $reason, $userId, $pdo = null) {
        $localTransaction = false;
        if ($pdo === null) {
            $pdo = getDbConnection();
            $pdo->beginTransaction();
            $localTransaction = true;
        }

        try {
            $stmt = $pdo->prepare('SELECT * FROM journal_entries WHERE id = :id');
            $stmt->execute([':id' => $journalEntryId]);
            $entry = $stmt->fetch();

            if (!$entry) {
                throw new Exception('Jurnal tidak ditemukan.');
            }
            if ($entry['status'] === 'reversed') {
                throw new Exception('Jurnal sudah pernah dibalik (reversed).');
            }

            // Get lines
            $stmtLines = $pdo->prepare('SELECT * FROM journal_entry_lines WHERE journal_entry_id = :id');
            $stmtLines->execute([':id' => $journalEntryId]);
            $lines = $stmtLines->fetchAll();

            // Create reversal lines (swap debit and credit)
            $reversalLines = [];
            foreach ($lines as $line) {
                $reversalLines[] = [
                    'account_id'  => $line['account_id'],
                    'description' => 'Pembalik ' . $entry['entry_number'] . ': ' . $line['description'],
                    'debit'       => $line['credit'],
                    'credit'      => $line['debit'],
                ];
            }

            // Create reversal journal entry
            $desc = 'Pembalikan jurnal ' . $entry['entry_number'] . ' - Alasan: ' . $reason;
            $revId = self::createEntry(
                date('Y-m-d'),
                'reversal',
                $journalEntryId,
                $entry['entry_number'],
                $desc,
                $reversalLines,
                $userId,
                true,
                $pdo
            );

            // Mark original as reversed
            $stmtUpd = $pdo->prepare('UPDATE journal_entries SET status = "reversed", updated_at = NOW(), updated_by = :user WHERE id = :id');
            $stmtUpd->execute([
                ':user' => $userId,
                ':id'   => $journalEntryId,
            ]);

            if ($localTransaction) {
                $pdo->commit();
            }

            return $revId;
        } catch (Exception $e) {
            if ($localTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Find account ID by code
     */
    public static function getAccountIdByCode($code, $pdo = null) {
        if ($pdo === null) {
            $pdo = getDbConnection();
        }
        $stmt = $pdo->prepare('SELECT id FROM accounts WHERE code = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        return $stmt->fetchColumn();
    }

    /**
     * Reverse a posted journal entry by reference type and ID
     */
    public static function reverseEntryByRef($refType, $refId, $reason, $userId, $pdo = null) {
        if ($pdo === null) {
            $pdo = getDbConnection();
        }
        $stmt = $pdo->prepare('SELECT id FROM journal_entries WHERE ref_type = :t AND ref_id = :id AND status = "posted" ORDER BY id DESC LIMIT 1');
        $stmt->execute([':t' => $refType, ':id' => $refId]);
        $jeId = $stmt->fetchColumn();
        if ($jeId) {
            return self::reverseEntry($jeId, $reason, $userId, $pdo);
        }
        return null;
    }
}
