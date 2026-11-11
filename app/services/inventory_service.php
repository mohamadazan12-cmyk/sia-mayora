<?php
/**
 * Inventory Service
 * Manages stock balances, movements, atomic operations, and prevents negative stock.
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

require_once __DIR__ . '/../config/database.php';

class InventoryService {

    /**
     * Get or initialize stock balance record with row lock
     */
    public static function getBalanceForUpdate($itemId, $warehouseId, $pdo) {
        $stmt = $pdo->prepare('
            SELECT * FROM stock_balances 
            WHERE item_id = :item_id AND warehouse_id = :warehouse_id 
            FOR UPDATE
        ');
        $stmt->execute([
            ':item_id'      => $itemId,
            ':warehouse_id' => $warehouseId,
        ]);
        $balance = $stmt->fetch();

        if (!$balance) {
            // Insert initial record
            $insert = $pdo->prepare('
                INSERT INTO stock_balances (item_id, warehouse_id, qty_available, qty_reserved, qty_on_hold, updated_at)
                VALUES (:item_id, :warehouse_id, 0, 0, 0, NOW())
            ');
            $insert->execute([
                ':item_id'      => $itemId,
                ':warehouse_id' => $warehouseId,
            ]);

            $stmt->execute([
                ':item_id'      => $itemId,
                ':warehouse_id' => $warehouseId,
            ]);
            $balance = $stmt->fetch();
        }

        return $balance;
    }

    /**
     * Get stock balance without lock (read only)
     */
    public static function getBalance($itemId, $warehouseId, $pdo = null) {
        if ($pdo === null) {
            $pdo = getDbConnection();
        }
        $stmt = $pdo->prepare('
            SELECT sb.*, i.name AS item_name, i.code AS item_code, u.name AS unit_name, w.name AS warehouse_name
            FROM stock_balances sb
            JOIN items i ON sb.item_id = i.id
            LEFT JOIN units u ON i.unit_id = u.id
            JOIN warehouses w ON sb.warehouse_id = w.id
            WHERE sb.item_id = :item_id AND sb.warehouse_id = :warehouse_id
        ');
        $stmt->execute([
            ':item_id'      => $itemId,
            ':warehouse_id' => $warehouseId,
        ]);
        return $stmt->fetch() ?: [
            'qty_available' => 0,
            'qty_reserved'  => 0,
            'qty_on_hold'   => 0,
        ];
    }

    /**
     * Add stock (Penerimaan barang dari supplier, hasil produksi lolos QC, retur barang)
     */
    public static function addStock($itemId, $warehouseId, $qty, $refType, $refId, $refNumber, $notes, $userId, $pdo = null) {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Kuantitas penambahan stok harus lebih besar dari 0.');
        }

        $localTransaction = false;
        if ($pdo === null) {
            $pdo = getDbConnection();
            $pdo->beginTransaction();
            $localTransaction = true;
        }

        try {
            $balance = self::getBalanceForUpdate($itemId, $warehouseId, $pdo);
            $newAvailable = (float)$balance['qty_available'] + (float)$qty;

            // Update balance
            $upd = $pdo->prepare('
                UPDATE stock_balances 
                SET qty_available = :qty_available, updated_at = NOW() 
                WHERE id = :id
            ');
            $upd->execute([
                ':qty_available' => $newAvailable,
                ':id'            => $balance['id'],
            ]);

            // Record movement
            $mov = $pdo->prepare('
                INSERT INTO stock_movements 
                (item_id, warehouse_id, movement_type, qty_in, qty_out, balance_after, ref_type, ref_id, ref_number, notes, user_id, created_at)
                VALUES 
                (:item_id, :warehouse_id, "in", :qty_in, 0, :balance_after, :ref_type, :ref_id, :ref_number, :notes, :user_id, NOW())
            ');
            $mov->execute([
                ':item_id'       => $itemId,
                ':warehouse_id'  => $warehouseId,
                ':qty_in'        => $qty,
                ':balance_after' => $newAvailable,
                ':ref_type'      => $refType,
                ':ref_id'        => $refId,
                ':ref_number'    => $refNumber,
                ':notes'         => $notes,
                ':user_id'       => $userId,
            ]);

            if ($localTransaction) {
                $pdo->commit();
            }

            return $newAvailable;
        } catch (Exception $e) {
            if ($localTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reduce stock (Pengeluaran bahan produksi, pengiriman penjualan DO)
     * Throws exception if stock is insufficient to prevent negative balance.
     */
    public static function reduceStock($itemId, $warehouseId, $qty, $refType, $refId, $refNumber, $notes, $userId, $pdo = null) {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Kuantitas pengurangan stok harus lebih besar dari 0.');
        }

        $localTransaction = false;
        if ($pdo === null) {
            $pdo = getDbConnection();
            $pdo->beginTransaction();
            $localTransaction = true;
        }

        try {
            $balance = self::getBalanceForUpdate($itemId, $warehouseId, $pdo);
            $currentAvailable = (float)$balance['qty_available'];

            if ($currentAvailable < (float)$qty) {
                // Get item name for clear error message
                $stmtItem = $pdo->prepare('SELECT name, code FROM items WHERE id = :id');
                $stmtItem->execute([':id' => $itemId]);
                $item = $stmtItem->fetch();
                $itemName = $item ? "{$item['name']} ({$item['code']})" : "ID {$itemId}";

                throw new Exception("Stok tidak mencukupi untuk item {$itemName}. Tersedia: {$currentAvailable}, dibutuhkan: {$qty}.");
            }

            $newAvailable = $currentAvailable - (float)$qty;

            // Update balance
            $upd = $pdo->prepare('
                UPDATE stock_balances 
                SET qty_available = :qty_available, updated_at = NOW() 
                WHERE id = :id
            ');
            $upd->execute([
                ':qty_available' => $newAvailable,
                ':id'            => $balance['id'],
            ]);

            // Record movement
            $mov = $pdo->prepare('
                INSERT INTO stock_movements 
                (item_id, warehouse_id, movement_type, qty_in, qty_out, balance_after, ref_type, ref_id, ref_number, notes, user_id, created_at)
                VALUES 
                (:item_id, :warehouse_id, "out", 0, :qty_out, :balance_after, :ref_type, :ref_id, :ref_number, :notes, :user_id, NOW())
            ');
            $mov->execute([
                ':item_id'       => $itemId,
                ':warehouse_id'  => $warehouseId,
                ':qty_out'       => $qty,
                ':balance_after' => $newAvailable,
                ':ref_type'      => $refType,
                ':ref_id'        => $refId,
                ':ref_number'    => $refNumber,
                ':notes'         => $notes,
                ':user_id'       => $userId,
            ]);

            if ($localTransaction) {
                $pdo->commit();
            }

            return $newAvailable;
        } catch (Exception $e) {
            if ($localTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Stock adjustment (Penyesuaian stok opname / koreksi)
     */
    public static function adjustStock($itemId, $warehouseId, $newQty, $reason, $refNumber, $userId, $pdo = null) {
        if ($newQty < 0) {
            throw new InvalidArgumentException('Kuantitas penyesuaian stok tidak boleh negatif.');
        }

        $localTransaction = false;
        if ($pdo === null) {
            $pdo = getDbConnection();
            $pdo->beginTransaction();
            $localTransaction = true;
        }

        try {
            $balance = self::getBalanceForUpdate($itemId, $warehouseId, $pdo);
            $currentQty = (float)$balance['qty_available'];
            $diff = $newQty - $currentQty;

            $qtyIn = $diff > 0 ? $diff : 0;
            $qtyOut = $diff < 0 ? abs($diff) : 0;

            // Update balance
            $upd = $pdo->prepare('
                UPDATE stock_balances 
                SET qty_available = :qty_available, updated_at = NOW() 
                WHERE id = :id
            ');
            $upd->execute([
                ':qty_available' => $newQty,
                ':id'            => $balance['id'],
            ]);

            // Record movement
            $mov = $pdo->prepare('
                INSERT INTO stock_movements 
                (item_id, warehouse_id, movement_type, qty_in, qty_out, balance_after, ref_type, ref_id, ref_number, notes, user_id, created_at)
                VALUES 
                (:item_id, :warehouse_id, "adjustment", :qty_in, :qty_out, :balance_after, "stock_adjustment", 0, :ref_number, :notes, :user_id, NOW())
            ');
            $mov->execute([
                ':item_id'       => $itemId,
                ':warehouse_id'  => $warehouseId,
                ':qty_in'        => $qtyIn,
                ':qty_out'       => $qtyOut,
                ':balance_after' => $newQty,
                ':ref_number'    => $refNumber,
                ':notes'         => 'Penyesuaian stok: ' . $reason,
                ':user_id'       => $userId,
            ]);

            if ($localTransaction) {
                $pdo->commit();
            }

            return $newQty;
        } catch (Exception $e) {
            if ($localTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
