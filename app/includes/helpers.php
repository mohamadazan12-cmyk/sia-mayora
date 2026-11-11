<?php
/**
 * General Helper Functions
 * Sistem Informasi Operasional Manufaktur dan Distribusi
 */

/**
 * Escape string for HTML output
 */
function e($value) {
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Format date for display: DD-MM-YYYY
 */
function formatDate($date) {
    if (empty($date) || $date === '0000-00-00') {
        return '-';
    }
    $timestamp = strtotime($date);
    if (!$timestamp) {
        return e($date);
    }
    return date('d-m-Y', $timestamp);
}

/**
 * Format date and time: DD-MM-YYYY HH:ii
 */
function formatDateTime($datetime) {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }
    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return e($datetime);
    }
    return date('d-m-Y H:i', $timestamp);
}

/**
 * Format amount as Indonesian Rupiah (Rp)
 */
function formatRupiah($amount, $withDecimals = false) {
    $val = (float)$amount;
    if ($withDecimals) {
        return 'Rp ' . number_format($val, 2, ',', '.');
    }
    return 'Rp ' . number_format($val, 0, ',', '.');
}

/**
 * Format general number / quantity
 */
function formatNumber($num, $decimals = 2) {
    $val = (float)$num;
    // Trim trailing zeros after decimal if needed
    $formatted = number_format($val, $decimals, ',', '.');
    if ($decimals > 0) {
        $formatted = rtrim(rtrim($formatted, '0'), ',');
    }
    return $formatted;
}

/**
 * Set flash message in session
 */
function setFlash($type, $message) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash_' . $type] = $message;
}

/**
 * Get and clear flash message
 */
function getFlash($type) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $key = 'flash_' . $type;
    if (isset($_SESSION[$key])) {
        $msg = $_SESSION[$key];
        unset($_SESSION[$key]);
        return $msg;
    }
    return null;
}

/**
 * Render all pending flash messages
 */
function renderFlashMessages() {
    $types = [
        'success' => 'alert-success',
        'error'   => 'alert-danger',
        'warning' => 'alert-warning',
        'info'    => 'alert-info',
    ];

    $html = '';
    foreach ($types as $type => $cssClass) {
        $msg = getFlash($type);
        if ($msg) {
            $html .= '<div class="alert ' . $cssClass . '">' . e($msg) . '</div>';
        }
    }
    return $html;
}

/**
 * Status translations and CSS badge mappings
 */
function getStatusBadge($status) {
    $statusMap = [
        // Umum
        'draft'               => ['label' => 'Draf', 'class' => 'badge-secondary'],
        'pending'             => ['label' => 'Menunggu', 'class' => 'badge-warning'],
        'pending_approval'    => ['label' => 'Menunggu Persetujuan', 'class' => 'badge-warning'],
        'approved'            => ['label' => 'Disetujui', 'class' => 'badge-success'],
        'rejected'            => ['label' => 'Ditolak', 'class' => 'badge-danger'],
        'revision'            => ['label' => 'Revisi', 'class' => 'badge-warning'],
        'completed'           => ['label' => 'Selesai', 'class' => 'badge-success'],
        'cancelled'           => ['label' => 'Dibatalkan', 'class' => 'badge-danger'],
        'active'              => ['label' => 'Aktif', 'class' => 'badge-success'],
        'inactive'            => ['label' => 'Nonaktif', 'class' => 'badge-secondary'],

        // Penjualan & Penerimaan
        'confirmed'           => ['label' => 'Dikonfirmasi', 'class' => 'badge-info'],
        'picking'             => ['label' => 'Picking', 'class' => 'badge-info'],
        'packed'              => ['label' => 'Packed', 'class' => 'badge-info'],
        'shipped'             => ['label' => 'Dikirim', 'class' => 'badge-primary'],
        'delivered'           => ['label' => 'Terkirim', 'class' => 'badge-success'],
        'issued'              => ['label' => 'Diterbitkan', 'class' => 'badge-info'],
        'partially_paid'      => ['label' => 'Lunas Sebagian', 'class' => 'badge-warning'],
        'paid'                => ['label' => 'Lunas', 'class' => 'badge-success'],
        'overdue'             => ['label' => 'Jatuh Tempo', 'class' => 'badge-danger'],
        'verified'            => ['label' => 'Terverifikasi', 'class' => 'badge-info'],
        'posted'              => ['label' => 'Diposting', 'class' => 'badge-success'],
        'processed'           => ['label' => 'Diproses', 'class' => 'badge-info'],
        'applied'             => ['label' => 'Diterapkan', 'class' => 'badge-success'],

        // Produksi
        'waiting_material'    => ['label' => 'Menunggu Bahan', 'class' => 'badge-warning'],
        'in_progress'         => ['label' => 'Berjalan', 'class' => 'badge-primary'],
        'waiting_qc'          => ['label' => 'Menunggu QC', 'class' => 'badge-warning'],
        'rework'              => ['label' => 'Rework', 'class' => 'badge-warning'],
        'pass'                => ['label' => 'Lulus QC', 'class' => 'badge-success'],
        'fail'                => ['label' => 'Tidak Lulus', 'class' => 'badge-danger'],
        'conditional'         => ['label' => 'Lulus Bersyarat', 'class' => 'badge-warning'],

        // Pengadaan / Pengeluaran
        'ordered'             => ['label' => 'Dipesan', 'class' => 'badge-info'],
        'partially_received'  => ['label' => 'Diterima Sebagian', 'class' => 'badge-warning'],
        'received'            => ['label' => 'Diterima', 'class' => 'badge-success'],
        'inspecting'          => ['label' => 'Inspeksi QC', 'class' => 'badge-warning'],
        'accepted'            => ['label' => 'Diterima Penuh', 'class' => 'badge-success'],
        'partially_accepted'  => ['label' => 'Diterima Sebagian', 'class' => 'badge-warning'],
        'matched'             => ['label' => 'Cocok (Matched)', 'class' => 'badge-success'],
        'qty_discrepancy'     => ['label' => 'Selisih Kuantitas', 'class' => 'badge-danger'],
        'price_discrepancy'   => ['label' => 'Selisih Harga', 'class' => 'badge-danger'],
        'both_discrepancy'    => ['label' => 'Selisih Qty & Harga', 'class' => 'badge-danger'],
        'disputed'            => ['label' => 'Sengketa', 'class' => 'badge-danger'],

        // SDM & Payroll
        'hadir'               => ['label' => 'Hadir', 'class' => 'badge-success'],
        'terlambat'           => ['label' => 'Terlambat', 'class' => 'badge-warning'],
        'izin'                => ['label' => 'Izin', 'class' => 'badge-info'],
        'sakit'               => ['label' => 'Sakit', 'class' => 'badge-info'],
        'alpha'               => ['label' => 'Alpha', 'class' => 'badge-danger'],
        'cuti'                => ['label' => 'Cuti', 'class' => 'badge-primary'],
        'libur'               => ['label' => 'Libur', 'class' => 'badge-secondary'],
        'belum_absen'         => ['label' => 'Belum Presensi', 'class' => 'badge-secondary'],
        'calculating'         => ['label' => 'Kalkulasi', 'class' => 'badge-info'],
        'review'              => ['label' => 'Review HR', 'class' => 'badge-warning'],
        'pending_finance'     => ['label' => 'Menunggu Finance', 'class' => 'badge-warning'],
        'transferred'         => ['label' => 'Ditransfer', 'class' => 'badge-success'],
        'closed'              => ['label' => 'Ditutup', 'class' => 'badge-secondary'],

        // Jurnal Akuntansi & Kredit
        'reversed'            => ['label' => 'Dibalik (Reversed)', 'class' => 'badge-danger'],
        'normal'              => ['label' => 'Normal', 'class' => 'badge-success'],
        'blocked'             => ['label' => 'Diblokir', 'class' => 'badge-danger'],
        'resigned'            => ['label' => 'Resign', 'class' => 'badge-secondary'],
    ];

    $info = $statusMap[$status] ?? ['label' => ucfirst(str_replace('_', ' ', $status)), 'class' => 'badge-secondary'];
    return '<span class="badge ' . $info['class'] . '">' . e($info['label']) . '</span>';
}

/**
 * Alias for getStatusBadge to ensure compatibility across modules
 */
function statusBadge($status) {
    return getStatusBadge($status);
}

/**
 * Generate unique document sequence number
 * E.g. PR-202610-0001
 */
function generateDocNumber($prefix, $tableName, $columnName) {
    $db = getDbConnection();
    $yearMonth = date('Ym');
    $pattern = $prefix . '-' . $yearMonth . '-%';

    $stmt = $db->prepare("SELECT {$columnName} FROM {$tableName} WHERE {$columnName} LIKE :pattern ORDER BY id DESC LIMIT 1");
    $stmt->execute([':pattern' => $pattern]);
    $lastNum = $stmt->fetchColumn();

    if ($lastNum) {
        $parts = explode('-', $lastNum);
        $seq = (int)end($parts) + 1;
    } else {
        $seq = 1;
    }

    return sprintf('%s-%s-%04d', $prefix, $yearMonth, $seq);
}

/**
 * Calculate simple pagination parameters
 */
function paginate($totalRows, $currentPage = 1, $perPage = 15) {
    $totalPages = (int)ceil($totalRows / $perPage);
    $currentPage = max(1, min($currentPage, max(1, $totalPages)));
    $offset = ($currentPage - 1) * $perPage;

    return [
        'total_rows'   => $totalRows,
        'per_page'     => $perPage,
        'current_page' => $currentPage,
        'total_pages'  => $totalPages,
        'offset'       => $offset,
    ];
}

/**
 * Render pagination controls HTML (Pure HTML, no JS)
 */
function renderPagination($pagination, $baseUrl) {
    if ($pagination['total_pages'] <= 1) {
        return '';
    }

    $current = $pagination['current_page'];
    $total = $pagination['total_pages'];

    $html = '<div class="pagination">';
    
    // First & Previous
    if ($current > 1) {
        $html .= '<a href="' . $baseUrl . '&p=1" class="page-link">&laquo; Pertama</a>';
        $html .= '<a href="' . $baseUrl . '&p=' . ($current - 1) . '" class="page-link">&lsaquo; Sebelumnya</a>';
    } else {
        $html .= '<span class="page-link disabled">&laquo; Pertama</span>';
        $html .= '<span class="page-link disabled">&lsaquo; Sebelumnya</span>';
    }

    // Number links (window of 5)
    $start = max(1, $current - 2);
    $end = min($total, $current + 2);

    for ($i = $start; $i <= $end; $i++) {
        if ($i === $current) {
            $html .= '<span class="page-link active">' . $i . '</span>';
        } else {
            $html .= '<a href="' . $baseUrl . '&p=' . $i . '" class="page-link">' . $i . '</a>';
        }
    }

    // Next & Last
    if ($current < $total) {
        $html .= '<a href="' . $baseUrl . '&p=' . ($current + 1) . '" class="page-link">Berikutnya &rsaquo;</a>';
        $html .= '<a href="' . $baseUrl . '&p=' . $total . '" class="page-link">Terakhir &raquo;</a>';
    } else {
        $html .= '<span class="page-link disabled">Berikutnya &rsaquo;</span>';
        $html .= '<span class="page-link disabled">Terakhir &raquo;</span>';
    }

    $html .= '<span class="page-info">Halaman ' . $current . ' dari ' . $total . ' (Total ' . $pagination['total_rows'] . ' data)</span>';
    $html .= '</div>';

    return $html;
}

/**
 * Clean and validate decimal/float input
 */
function parseDecimal($value, $default = 0.0) {
    if ($value === null || $value === '') {
        return $default;
    }
    // Remove thousand separators and replace comma with dot
    $clean = str_replace('.', '', (string)$value);
    $clean = str_replace(',', '.', $clean);
    return is_numeric($clean) ? (float)$clean : $default;
}

/**
 * Check if a database record is referenced by any dependent foreign keys.
 * Returns an array of human-readable references, or empty array if safe.
 */
function checkRecordReferences($tableName, $id, $pdo = null) {
    if ($pdo === null) {
        $pdo = getDbConnection();
    }
    $references = [];
    $id = (int)$id;

    switch ($tableName) {
        case 'suppliers':
            $checks = [
                ['table' => 'purchase_orders', 'col' => 'supplier_id', 'label' => 'Pesanan Pembelian (PO)'],
                ['table' => 'goods_receipts', 'col' => 'supplier_id', 'label' => 'Penerimaan Barang (GR)'],
                ['table' => 'supplier_invoices', 'col' => 'supplier_id', 'label' => 'Faktur Pemasok'],
                ['table' => 'payment_requests', 'col' => 'supplier_id', 'label' => 'Permintaan Pembayaran'],
                ['table' => 'supplier_payments', 'col' => 'supplier_id', 'label' => 'Pembayaran Pemasok'],
                ['table' => 'supplier_returns', 'col' => 'supplier_id', 'label' => 'Retur Pembelian'],
                ['table' => 'ap_vouchers', 'col' => 'supplier_id', 'label' => 'Voucher AP'],
            ];
            break;

        case 'customers':
            $checks = [
                ['table' => 'sales_orders', 'col' => 'customer_id', 'label' => 'Sales Order (SO)'],
                ['table' => 'sales_invoices', 'col' => 'customer_id', 'label' => 'Faktur Penjualan'],
                ['table' => 'customer_receipts', 'col' => 'customer_id', 'label' => 'Penerimaan Kas Pelanggan'],
                ['table' => 'customer_returns', 'col' => 'customer_id', 'label' => 'Retur Penjualan'],
                ['table' => 'credit_notes', 'col' => 'customer_id', 'label' => 'Nota Kredit'],
                ['table' => 'customer_orders', 'col' => 'customer_id', 'label' => 'Pesanan Pelanggan'],
            ];
            break;

        case 'items':
            $checks = [
                ['table' => 'stock_movements', 'col' => 'item_id', 'label' => 'Riwayat Mutasi Stok'],
                ['table' => 'purchase_requisition_items', 'col' => 'item_id', 'label' => 'Item Permintaan Pembelian (PR)'],
                ['table' => 'purchase_order_items', 'col' => 'item_id', 'label' => 'Item Pesanan Pembelian (PO)'],
                ['table' => 'goods_receipt_items', 'col' => 'item_id', 'label' => 'Item Penerimaan Barang (GR)'],
                ['table' => 'sales_order_items', 'col' => 'item_id', 'label' => 'Item Sales Order (SO)'],
                ['table' => 'delivery_order_items', 'col' => 'item_id', 'label' => 'Item Surat Jalan (DO)'],
                ['table' => 'sales_invoice_items', 'col' => 'item_id', 'label' => 'Item Faktur Penjualan'],
                ['table' => 'boms', 'col' => 'product_item_id', 'label' => 'Formula BOM Produk Jadi'],
                ['table' => 'bom_items', 'col' => 'item_id', 'label' => 'Komponen Bahan BOM'],
                ['table' => 'production_orders', 'col' => 'product_item_id', 'label' => 'SPK Perintah Produksi'],
                ['table' => 'material_issue_items', 'col' => 'item_id', 'label' => 'Pengeluaran Bahan Baku'],
                ['table' => 'scrap_records', 'col' => 'item_id', 'label' => 'Catatan Produk Rusak / Scrap'],
            ];
            break;

        case 'warehouses':
            $checks = [
                ['table' => 'stock_balances', 'col' => 'warehouse_id', 'cond' => 'qty_available > 0 OR qty_reserved > 0', 'label' => 'Saldo Fisik Persediaan Aktif'],
                ['table' => 'stock_movements', 'col' => 'warehouse_id', 'label' => 'Riwayat Mutasi Stok'],
                ['table' => 'goods_receipts', 'col' => 'warehouse_id', 'label' => 'Penerimaan Barang (GR)'],
                ['table' => 'delivery_orders', 'col' => 'warehouse_id', 'label' => 'Pengiriman Surat Jalan (DO)'],
                ['table' => 'production_orders', 'col' => 'warehouse_output_id', 'label' => 'SPK Gudang Output'],
                ['table' => 'material_issues', 'col' => 'warehouse_id', 'label' => 'Pengeluaran Bahan Baku'],
            ];
            break;

        case 'boms':
            $checks = [
                ['table' => 'production_orders', 'col' => 'bom_id', 'label' => 'Perintah Kerja Produksi (SPK)'],
            ];
            break;

        case 'accounts':
            $checks = [
                ['table' => 'journal_entry_lines', 'col' => 'account_id', 'label' => 'Baris Jurnal Buku Besar'],
                ['table' => 'accounts', 'col' => 'parent_id', 'label' => 'Sub-Akun Buku Besar Terkait'],
                ['table' => 'account_mappings', 'col' => 'debit_account_id', 'label' => 'Pemetaan Akun Debit Otomatis'],
                ['table' => 'account_mappings', 'col' => 'credit_account_id', 'label' => 'Pemetaan Akun Kredit Otomatis'],
            ];
            break;

        case 'employees':
            $checks = [
                ['table' => 'payroll_records', 'col' => 'employee_id', 'label' => 'Riwayat Penggajian (Payroll)'],
                ['table' => 'attendance_records', 'col' => 'employee_id', 'label' => 'Catatan Presensi Harian'],
                ['table' => 'leave_overtime_requests', 'col' => 'employee_id', 'label' => 'Pengajuan Cuti / Lembur'],
                ['table' => 'payslips', 'col' => 'employee_id', 'label' => 'Slip Gaji Diterbitkan'],
                ['table' => 'employee_mutations', 'col' => 'employee_id', 'label' => 'Catatan Mutasi / Promosi'],
                ['table' => 'employee_trainings', 'col' => 'employee_id', 'label' => 'Program Pelatihan Karyawan'],
                ['table' => 'employee_terminations', 'col' => 'employee_id', 'label' => 'Berkas Hak Akhir / Terminasi'],
            ];
            break;

        case 'departments':
            $checks = [
                ['table' => 'employees', 'col' => 'department_id', 'label' => 'Data Karyawan Terkait'],
                ['table' => 'positions', 'col' => 'department_id', 'label' => 'Jabatan di Bawah Departemen'],
                ['table' => 'purchase_requisitions', 'col' => 'department_id', 'label' => 'Pengajuan PR Departemen'],
            ];
            break;

        case 'positions':
            $checks = [
                ['table' => 'employees', 'col' => 'position_id', 'label' => 'Data Karyawan Aktif Memegang Jabatan'],
            ];
            break;

        case 'units':
            $checks = [
                ['table' => 'items', 'col' => 'unit_id', 'label' => 'Master Barang / Material Menggunakan Satuan Ini'],
            ];
            break;

        case 'purchase_requisitions':
            $checks = [
                ['table' => 'purchase_orders', 'col' => 'purchase_requisition_id', 'label' => 'Purchase Order (PO) Terkait'],
            ];
            break;

        case 'purchase_orders':
            $checks = [
                ['table' => 'goods_receipts', 'col' => 'purchase_order_id', 'label' => 'Penerimaan Barang (GR) Terkait'],
                ['table' => 'supplier_invoices', 'col' => 'purchase_order_id', 'label' => 'Faktur Pemasok Terkait'],
            ];
            break;

        case 'sales_orders':
            $checks = [
                ['table' => 'delivery_orders', 'col' => 'sales_order_id', 'label' => 'Surat Jalan / DO Terkait'],
                ['table' => 'sales_invoices', 'col' => 'sales_order_id', 'label' => 'Faktur Penjualan Terkait'],
            ];
            break;

        case 'production_orders':
            $checks = [
                ['table' => 'material_issues', 'col' => 'production_order_id', 'cond' => "status != 'cancelled'", 'label' => 'Bukti Pengeluaran Bahan (MI) Aktif'],
                ['table' => 'production_outputs', 'col' => 'production_order_id', 'label' => 'Hasil Output Produksi'],
                ['table' => 'qc_inspections', 'col' => 'ref_id', 'cond' => "ref_type = 'production'", 'label' => 'Inspeksi QC Produksi'],
                ['table' => 'scrap_records', 'col' => 'production_order_id', 'label' => 'Catatan Scrap Produksi'],
            ];
            break;

        case 'goods_receipts':
            $checks = [
                ['table' => 'supplier_invoices', 'col' => 'goods_receipt_id', 'cond' => "status != 'cancelled'", 'label' => 'Faktur Tagihan Pemasok Aktif'],
                ['table' => 'supplier_returns', 'col' => 'goods_receipt_id', 'cond' => "status != 'cancelled'", 'label' => 'Retur Pembelian Terkait'],
            ];
            break;

        case 'supplier_invoices':
            $checks = [
                ['table' => 'supplier_payment_allocations', 'col' => 'supplier_invoice_id', 'label' => 'Alokasi Pembayaran Kas/Bank'],
                ['table' => 'credit_notes', 'col' => 'sales_invoice_id', 'label' => 'Nota Kredit Terkait'],
            ];
            break;

        case 'delivery_orders':
            $checks = [
                ['table' => 'sales_invoices', 'col' => 'delivery_order_id', 'cond' => "status != 'cancelled'", 'label' => 'Faktur Penjualan Terkait'],
            ];
            break;

        case 'sales_invoices':
            $checks = [
                ['table' => 'customer_receipt_allocations', 'col' => 'sales_invoice_id', 'label' => 'Alokasi Penerimaan Kas Pelanggan'],
                ['table' => 'customer_returns', 'col' => 'sales_invoice_id', 'cond' => "status != 'cancelled'", 'label' => 'Retur Penjualan Aktif'],
                ['table' => 'credit_notes', 'col' => 'sales_invoice_id', 'label' => 'Nota Kredit Terkait'],
            ];
            break;

        case 'qc_inspections':
            $checks = [
                ['table' => 'production_outputs', 'col' => 'qc_inspection_id', 'label' => 'Hasil Output Produksi'],
                ['table' => 'goods_receipt_items', 'col' => 'qc_inspection_id', 'label' => 'Item Penerimaan Barang (GR)'],
            ];
            break;

        case 'payroll_periods':
            $checks = [
                ['table' => 'payroll_records', 'col' => 'payroll_period_id', 'cond' => "status IN ('verified', 'approved', 'transferred')", 'label' => 'Catatan Penggajian Telah Disetujui / Ditransfer'],
            ];
            break;

        default:
            $checks = [];
            break;
    }

    foreach ($checks as $chk) {
        $sql = "SELECT COUNT(*) FROM `{$chk['table']}` WHERE `{$chk['col']}` = :id";
        if (!empty($chk['cond'])) {
            $sql .= " AND ({$chk['cond']})";
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $id]);
            $cnt = (int)$stmt->fetchColumn();
            if ($cnt > 0) {
                $references[] = "{$cnt} {$chk['label']}";
            }
        } catch (Exception $ex) {
            // Table might not exist or other issue, ignore
        }
    }

    return $references;
}

/**
 * Mendapatkan inisial 2 huruf dari nama pengguna untuk avatar bulat
 */
function getUserInitials($name) {
    if (empty($name)) {
        return 'MY';
    }
    $words = preg_split('/\s+/', trim((string)$name));
    if (count($words) >= 2) {
        return strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
    }
    return strtoupper(mb_substr((string)$name, 0, 2));
}

/**
 * Format tanggal ringkas (alias formatDate)
 */
function tgl($date) {
    return formatDate($date);
}

/**
 * Menghitung jumlah notifikasi pending / butuh otorisasi sesuai peran pengguna saat ini
 */
function getSystemNotificationCount($user = null) {
    if (!$user) {
        $user = currentUser();
    }
    if (!$user) {
        return 0;
    }

    $db = getDbConnection();
    $role = strtolower($user['role_code'] ?? $user['role_name'] ?? '');
    $count = 0;

    try {
        if (in_array($role, ['admin', 'administrator', 'manager', 'manajer', 'supervisor / manajer'])) {
            $mut = (int)$db->query("SELECT COUNT(*) FROM employee_mutations WHERE status = 'Menunggu'")->fetchColumn();
            $trn = (int)$db->query("SELECT COUNT(*) FROM employee_trainings WHERE status = 'Menunggu'")->fetchColumn();
            $trm = (int)$db->query("SELECT COUNT(*) FROM employee_terminations WHERE status = 'Menunggu Persetujuan'")->fetchColumn();
            $pay = (int)$db->query("SELECT COUNT(*) FROM payroll_records WHERE status IN ('draft', 'calculated')")->fetchColumn();
            $pr  = (int)$db->query("SELECT COUNT(*) FROM purchase_requisitions WHERE status = 'submitted'")->fetchColumn();
            $count = $mut + $trn + $trm + $pay + $pr;
        } elseif ($role === 'hr') {
            $lv  = (int)$db->query("SELECT COUNT(*) FROM leave_overtime_requests WHERE status = 'pending'")->fetchColumn();
            $pay = (int)$db->query("SELECT COUNT(*) FROM payroll_records WHERE status IN ('draft', 'calculated', 'rejected')")->fetchColumn();
            $count = $lv + $pay;
        } elseif ($role === 'finance') {
            $pay = (int)$db->query("SELECT COUNT(*) FROM payroll_records WHERE status = 'verified'")->fetchColumn();
            $inv = (int)$db->query("SELECT COUNT(*) FROM supplier_invoices WHERE status = 'draft'")->fetchColumn();
            $count = $pay + $inv;
        } elseif ($role === 'purchasing') {
            $pr = (int)$db->query("SELECT COUNT(*) FROM purchase_requisitions WHERE status = 'approved'")->fetchColumn();
            $count = $pr;
        } elseif ($role === 'qc') {
            $qc = (int)$db->query("SELECT COUNT(*) FROM qc_inspections WHERE status = 'inspecting'")->fetchColumn();
            $count = $qc;
        } elseif ($role === 'gudang') {
            $gr = (int)$db->query("SELECT COUNT(*) FROM goods_receipts WHERE status = 'draft'")->fetchColumn();
            $count = $gr;
        }
    } catch (Exception $e) {
        // Abaikan jika query gagal agar tidak mengganggu rendering UI
        $count = 0;
    }

    return $count;
}

