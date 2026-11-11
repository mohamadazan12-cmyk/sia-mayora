<?php
/**
 * Modul Pengeluaran: Permintaan Pembayaran Utang Pemasok
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

$pageTitle = 'Permintaan Pembayaran Utang';
$breadcrumbCategory = 'Pengeluaran';

$db = getDbConnection();
$user = currentUser();

// Handle Approval / Rejection / Cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCsrf();
    $prqId = (int)($_POST['prq_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $reason = trim($_POST['approval_reason'] ?? '');

    $canApprove = hasRole(['Supervisor / Manajer', 'Administrator', 'Finance/Treasury', 'manager', 'admin', 'finance']);

    if (!$canApprove && in_array($action, ['approve', 'reject'])) {
        setFlash('error', 'Anda tidak memiliki hak otorisasi untuk menyetujui permintaan pembayaran.');
        header('Location: index.php?page=expenditure_procurement/payment_requests');
        exit;
    }

    try {
        if ($action === 'approve') {
            $upd = $db->prepare("UPDATE payment_requests SET status = 'approved', updated_at = NOW(), updated_by = :uid WHERE id = :id");
            $upd->execute([':uid' => $user['id'], ':id' => $prqId]);
            ApprovalService::approve('payment_request', $prqId, "PRQ-{$prqId}", $user['id'], $reason, $db);
            setFlash('success', 'Permintaan pembayaran berhasil disetujui untuk diproses pelunasan oleh Treasury.');
        } elseif ($action === 'reject') {
            if (empty($reason)) {
                throw new Exception('Alasan penolakan wajib diisi.');
            }
            $upd = $db->prepare("UPDATE payment_requests SET status = 'rejected', updated_at = NOW(), updated_by = :uid WHERE id = :id");
            $upd->execute([':uid' => $user['id'], ':id' => $prqId]);
            ApprovalService::reject('payment_request', $prqId, "PRQ-{$prqId}", $user['id'], $reason, $db);
            setFlash('warning', 'Permintaan pembayaran telah ditolak.');
        } elseif ($action === 'cancel') {
            if (empty($reason)) {
                throw new Exception('Alasan pembatalan permintaan pembayaran wajib diisi.');
            }
            $upd = $db->prepare("UPDATE payment_requests SET status = 'cancelled', reason = CONCAT_WS(' | Batal: ', reason, :reason), updated_at = NOW(), updated_by = :uid WHERE id = :id");
            $upd->execute([':reason' => $reason, ':uid' => $user['id'], ':id' => $prqId]);
            logAudit($user['id'], 'cancel', 'payment_requests', $prqId, null, ['status' => 'cancelled', 'reason' => $reason]);
            setFlash('success', 'Permintaan pembayaran berhasil dibatalkan.');
        }
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
    }

    header('Location: index.php?page=expenditure_procurement/payment_requests');
    exit;
}

// Fetch Payment Requests
$requests = $db->query("
    SELECT prq.*, s.name AS supplier_name, s.code AS supplier_code, apv.voucher_number, u.full_name AS requester_name
    FROM payment_requests prq
    JOIN suppliers s ON prq.supplier_id = s.id
    LEFT JOIN ap_vouchers apv ON prq.ap_voucher_id = apv.id
    LEFT JOIN users u ON prq.requested_by = u.id
    ORDER BY prq.id DESC
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Permintaan Pembayaran Pemasok (Payment Request)</h1>
        <div class="page-subtitle">Siklus Pengeluaran &bull; Alur Persetujuan Pembayaran sebelum Pelunasan Kas/Bank</div>
    </div>
    <div class="page-actions">
        <a href="index.php?page=expenditure_procurement/payments" class="btn btn-secondary">
            Lihat Riwayat Pelunasan &raquo;
        </a>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>No. Permintaan</th>
                    <th>Tanggal</th>
                    <th>Pemasok / Vendor</th>
                    <th>Ref. Voucher AP</th>
                    <th>Alasan / Keterangan</th>
                    <th class="text-right">Jumlah Diajukan</th>
                    <th>Pemohon</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Aksi / Keputusan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="9" class="text-center" style="padding: 24px; color: var(--text-muted);">
                            Belum ada permohonan pembayaran yang diajukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($requests as $r): ?>
                        <tr>
                            <td><strong><?= e($r['request_number']) ?></strong></td>
                            <td><?= formatDate($r['request_date']) ?></td>
                            <td><?= e($r['supplier_name']) ?></td>
                            <td><code><?= e($r['voucher_number'] ?: '-') ?></code></td>
                            <td><?= e($r['reason'] ?: '-') ?></td>
                            <td class="text-right font-weight-bold"><?= formatRupiah($r['amount']) ?></td>
                            <td><?= e($r['requester_name']) ?></td>
                            <td class="text-center"><?= getStatusBadge($r['status']) ?></td>
                            <td class="text-center" style="white-space: nowrap;">
                                <button type="button" class="btn btn-sm btn-secondary" 
                                        onclick="showRequestDetail(<?= htmlspecialchars(json_encode([
                                            'number' => $r['request_number'],
                                            'date' => formatDate($r['request_date']),
                                            'supplier' => $r['supplier_name'] . ' (' . $r['supplier_code'] . ')',
                                            'voucher' => $r['voucher_number'] ?: '-',
                                            'amount' => formatRupiah($r['amount']),
                                            'reason' => $r['reason'] ?: '-',
                                            'requester' => $r['requester_name'] ?: '-',
                                            'status' => $r['status']
                                        ]), ENT_QUOTES, 'UTF-8') ?>)">
                                    Detail
                                </button>

                                <?php if ($r['status'] === 'pending_approval' && hasRole(['Supervisor / Manajer', 'Administrator', 'Finance/Treasury', 'manager', 'admin', 'finance'])): ?>
                                    <form action="index.php?page=expenditure_procurement/payment_requests" method="POST" style="display: inline-flex; gap: 4px;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="prq_id" value="<?= $r['id'] ?>">
                                        <button type="submit" name="action" value="approve" class="btn btn-sm btn-success" title="Setujui">✓ Setuju</button>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="openRejectPrqModal(<?= $r['id'] ?>)" title="Tolak">✕ Tolak</button>
                                        <button type="button" class="btn btn-sm btn-secondary" onclick="openCancelPrqModal(<?= $r['id'] ?>)" title="Batalkan">Batalkan</button>
                                    </form>
                                <?php elseif ($r['status'] === 'approved'): ?>
                                    <a href="index.php?page=expenditure_procurement/payment_create" class="btn btn-sm btn-primary">
                                        Proses Bayar
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Detail Permintaan Pembayaran -->
<div id="prq-detail-modal" class="modal-overlay">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title" id="prqd-title">Detail Permintaan Pembayaran</h3>
            <button type="button" class="modal-close" onclick="closeModal('prq-detail-modal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="detail-grid" style="grid-template-columns: 1fr 1fr; margin-bottom: 16px;">
                <div class="detail-item">
                    <div class="detail-label">Nomor Dokumen</div>
                    <div class="detail-value"><code id="prqd-number"></code></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Tanggal Pengajuan</div>
                    <div class="detail-value" id="prqd-date"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Pemasok / Vendor</div>
                    <div class="detail-value" id="prqd-supplier"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Voucher AP</div>
                    <div class="detail-value"><code id="prqd-voucher"></code></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Jumlah Pembayaran</div>
                    <div class="detail-value" id="prqd-amount" style="color: var(--primary); font-weight: 700;"></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Diajukan Oleh</div>
                    <div class="detail-value" id="prqd-requester"></div>
                </div>
                <div class="detail-item" style="grid-column: span 2;">
                    <div class="detail-label">Status</div>
                    <div class="detail-value" id="prqd-status"></div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Alasan / Keperluan Pembayaran</label>
                <div id="prqd-reason" style="padding: 8px 12px; background: #f8fafc; border-radius: 4px; font-size: 13px;"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('prq-detail-modal')">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal Tolak Permintaan -->
<div id="reject-prq-modal" class="modal-overlay">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Tolak Permintaan Pembayaran</h3>
            <button type="button" class="modal-close" onclick="closeModal('reject-prq-modal')">&times;</button>
        </div>
        <form action="index.php?page=expenditure_procurement/payment_requests" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="prq_id" id="reject-prq-id" value="">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label required">Alasan Penolakan</label>
                    <textarea name="approval_reason" class="form-control" rows="3" required placeholder="Jelaskan alasan penolakan permintaan pembayaran..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('reject-prq-modal')">Batal</button>
                <button type="submit" class="btn btn-danger">Tolak Permintaan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Batal Permintaan -->
<div id="cancel-prq-modal" class="modal-overlay">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">Batalkan Permintaan Pembayaran</h3>
            <button type="button" class="modal-close" onclick="closeModal('cancel-prq-modal')">&times;</button>
        </div>
        <form action="index.php?page=expenditure_procurement/payment_requests" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="prq_id" id="cancel-prq-id" value="">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label required">Alasan Pembatalan</label>
                    <textarea name="approval_reason" class="form-control" rows="3" required placeholder="Jelaskan alasan pembatalan permintaan pembayaran..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('cancel-prq-modal')">Tutup</button>
                <button type="submit" class="btn btn-danger">Batalkan Permintaan</button>
            </div>
        </form>
    </div>
</div>

<script>
function showRequestDetail(data) {
    document.getElementById('prqd-title').textContent = 'Detail Permintaan: ' + data.number;
    document.getElementById('prqd-number').textContent = data.number;
    document.getElementById('prqd-date').textContent = data.date;
    document.getElementById('prqd-supplier').textContent = data.supplier;
    document.getElementById('prqd-voucher').textContent = data.voucher;
    document.getElementById('prqd-amount').textContent = data.amount;
    document.getElementById('prqd-requester').textContent = data.requester;
    document.getElementById('prqd-status').innerHTML = '<span class="badge badge-info">' + data.status + '</span>';
    document.getElementById('prqd-reason').textContent = data.reason;
    openModal('prq-detail-modal');
}

function openRejectPrqModal(id) {
    document.getElementById('reject-prq-id').value = id;
    openModal('reject-prq-modal');
}

function openCancelPrqModal(id) {
    document.getElementById('cancel-prq-id').value = id;
    openModal('cancel-prq-modal');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
