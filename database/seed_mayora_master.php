<?php
/**
 * Master Seeder: PT Mayora Indah Tbk.
 * Generates 6-month historical transactional simulation for academic SIA demonstration.
 * Idempotent, deterministic, and relational-safe.
 */

ini_set('memory_limit', '1024M');
set_time_limit(600);

require_once __DIR__ . '/../app/config/database.php';
$pdo = getDbConnection();

echo "========================================================\n";
echo "PT MAYORA INDAH TBK - DATABASE SEEDER (6-MONTH HORIZON)\n";
echo "========================================================\n\n";

$pdo->beginTransaction();

try {
    // -------------------------------------------------------------------------
    // 1. UNITS
    // -------------------------------------------------------------------------
    echo "1. Seeding Units...\n";
    $units = [
        ['code' => 'KG',     'name' => 'Kilogram'],
        ['code' => 'GR',     'name' => 'Gram'],
        ['code' => 'LTR',    'name' => 'Liter'],
        ['code' => 'ML',     'name' => 'Mililiter'],
        ['code' => 'DUS',    'name' => 'Dus / Karton'],
        ['code' => 'PAK',    'name' => 'Pack / Renceng'],
        ['code' => 'KLG',    'name' => 'Kaleng'],
        ['code' => 'BTL',    'name' => 'Botol'],
        ['code' => 'SCH',    'name' => 'Sachet'],
        ['code' => 'PCS',    'name' => 'Pcs / Buah'],
        ['code' => 'ROL',    'name' => 'Rol Plastik / Foil'],
        ['code' => 'BAL',    'name' => 'Bal / Karung 50kg'],
        ['code' => 'LBR',    'name' => 'Lembar'],
    ];

    $unitMap = [];
    $stmtUnit = $pdo->prepare("INSERT INTO units (code, name, is_active) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE name = VALUES(name)");
    foreach ($units as $u) {
        $stmtUnit->execute([$u['code'], $u['name']]);
    }
    foreach ($pdo->query("SELECT id, code FROM units")->fetchAll() as $r) {
        $unitMap[$r['code']] = (int)$r['id'];
    }

    // -------------------------------------------------------------------------
    // 2. ITEM CATEGORIES
    // -------------------------------------------------------------------------
    echo "2. Seeding Item Categories...\n";
    $categories = [
        ['code' => 'CAT-BISKUIT',  'name' => 'Biskuit (Roma Series)',           'type' => 'barang_jadi'],
        ['code' => 'CAT-WAFER',    'name' => 'Wafer (Beng-Beng & Astor)',        'type' => 'barang_jadi'],
        ['code' => 'CAT-PERMEN',   'name' => 'Permen (Kopiko & Kis)',            'type' => 'barang_jadi'],
        ['code' => 'CAT-COKELAT',  'name' => 'Cokelat Pasta (Choki-Choki)',      'type' => 'barang_jadi'],
        ['code' => 'CAT-KOPI',     'name' => 'Kopi Bubuk (Torabika Series)',     'type' => 'barang_jadi'],
        ['code' => 'CAT-SEREAL',   'name' => 'Minuman Sereal (Energen Series)',  'type' => 'barang_jadi'],
        ['code' => 'CAT-BB-POWDER','name' => 'Bahan Baku Tepung, Kakao & Kopi',  'type' => 'bahan_baku'],
        ['code' => 'CAT-BB-SWEET', 'name' => 'Bahan Baku Gula & Pemanis',       'type' => 'bahan_baku'],
        ['code' => 'CAT-BB-FAT',   'name' => 'Bahan Baku Minyak & Lemak Nabati', 'type' => 'bahan_baku'],
        ['code' => 'CAT-BB-FLAVOR','name' => 'Bahan Penolong Perisa & Ragi',     'type' => 'bahan_baku'],
        ['code' => 'CAT-PACK-PRI', 'name' => 'Bahan Kemasan Primer & Foil',      'type' => 'packaging'],
        ['code' => 'CAT-PACK-SEC', 'name' => 'Bahan Kemasan Sekunder & Karton',  'type' => 'packaging'],
    ];

    $catMap = [];
    $stmtCat = $pdo->prepare("INSERT INTO item_categories (code, name, type, is_active) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type)");
    foreach ($categories as $c) {
        $stmtCat->execute([$c['code'], $c['name'], $c['type']]);
    }
    foreach ($pdo->query("SELECT id, code FROM item_categories")->fetchAll() as $r) {
        $catMap[$r['code']] = (int)$r['id'];
    }

    // -------------------------------------------------------------------------
    // 3. WAREHOUSES
    // -------------------------------------------------------------------------
    echo "3. Seeding Warehouses...\n";
    $warehouses = [
        ['code' => 'WH-RAW-01',  'name' => 'Gudang Bahan Baku Utama Tangerang',  'type' => 'bahan_baku', 'address' => 'Jl. Telesonic No. 1, Kawasan Industri Manis, Tangerang'],
        ['code' => 'WH-PACK-01', 'name' => 'Gudang Material Kemasan & Karton',  'type' => 'bahan_baku', 'address' => 'Jl. Telesonic No. 2, Kawasan Industri Manis, Tangerang'],
        ['code' => 'WH-WIP-01',  'name' => 'Gudang Barang Dalam Proses (WIP)',   'type' => 'wip',        'address' => 'Area Produksi Gedung A, Lantai 1'],
        ['code' => 'WH-FG-01',   'name' => 'Gudang Pusat Barang Jadi Tangerang', 'type' => 'barang_jadi', 'address' => 'Jl. Raya Serang Km 12.5, Bitung, Tangerang'],
        ['code' => 'WH-QUAR-01', 'name' => 'Area Karantina Mutu (QC Lab Holding)','type' => 'umum',     'address' => 'Area Karantina Inspeksi Fisik & Kimiawi'],
    ];

    $whMap = [];
    $stmtWh = $pdo->prepare("INSERT INTO warehouses (code, name, type, address, is_active) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type)");
    foreach ($warehouses as $w) {
        $stmtWh->execute([$w['code'], $w['name'], $w['type'], $w['address']]);
    }
    foreach ($pdo->query("SELECT id, code FROM warehouses")->fetchAll() as $r) {
        $whMap[$r['code']] = (int)$r['id'];
    }

    // -------------------------------------------------------------------------
    // 4. MACHINES (18 Mesin Produksi Mayora)
    // -------------------------------------------------------------------------
    echo "4. Seeding Machines...\n";
    $machinesData = [
        ['code' => 'MSN-MIX-01', 'name' => 'Mesin Mixing Dough Horisontal 500kg', 'type' => 'Mixing', 'location' => 'Line Biskuit 1', 'cap' => 500, 'unit' => 'kg/jam', 'rate' => 125000],
        ['code' => 'MSN-MIX-02', 'name' => 'Mesin High Shear Mixer Cream 300L',   'type' => 'Mixing', 'location' => 'Line Wafer 1',   'cap' => 300, 'unit' => 'liter/jam', 'rate' => 110000],
        ['code' => 'MSN-DF-01',  'name' => 'Mesin Dough Forming & Laminator',      'type' => 'Forming', 'location' => 'Line Biskuit 1', 'cap' => 600, 'unit' => 'kg/jam', 'rate' => 140000],
        ['code' => 'MSN-RM-01',  'name' => 'Mesin Rotary Moulding Roma Kelapa',    'type' => 'Moulding', 'location' => 'Line Biskuit 2', 'cap' => 450, 'unit' => 'kg/jam', 'rate' => 135000],
        ['code' => 'MSN-RC-01',  'name' => 'Mesin Rotary Cutter Slai O\'lai',      'type' => 'Cutting', 'location' => 'Line Biskuit 3', 'cap' => 400, 'unit' => 'kg/jam', 'rate' => 130000],
        ['code' => 'MSN-OVN-01', 'name' => 'Mesin Tunnel Oven Gas 60 Meter',       'type' => 'Baking', 'location' => 'Line Biskuit 1', 'cap' => 800, 'unit' => 'kg/jam', 'rate' => 250000],
        ['code' => 'MSN-OVN-02', 'name' => 'Mesin Tunnel Oven Multi-Zone 45m',     'type' => 'Baking', 'location' => 'Line Biskuit 2', 'cap' => 650, 'unit' => 'kg/jam', 'rate' => 220000],
        ['code' => 'MSN-CC-01',  'name' => 'Mesin Cooling Conveyor 45 Meter',      'type' => 'Cooling', 'location' => 'Line Biskuit 1', 'cap' => 800, 'unit' => 'kg/jam', 'rate' => 60000],
        ['code' => 'MSN-WB-01',  'name' => 'Mesin Wafer Baking Plate 72-Die Otomatis', 'type' => 'Wafer Baking', 'location' => 'Line Wafer 1', 'cap' => 500, 'unit' => 'kg/jam', 'rate' => 210000],
        ['code' => 'MSN-WCS-01', 'name' => 'Mesin Wafer Conditioning & Cream Spreader', 'type' => 'Spreading', 'location' => 'Line Wafer 1', 'cap' => 500, 'unit' => 'kg/jam', 'rate' => 115000],
        ['code' => 'MSN-ENR-01', 'name' => 'Mesin Chocolate Coating Enrober Beng-Beng', 'type' => 'Coating', 'location' => 'Line Wafer 2', 'cap' => 450, 'unit' => 'kg/jam', 'rate' => 180000],
        ['code' => 'MSN-FIL-01', 'name' => 'Mesin Filling & Injector Choki-Choki', 'type' => 'Filling', 'location' => 'Line Pasta Cokelat', 'cap' => 350, 'unit' => 'kg/jam', 'rate' => 120000],
        ['code' => 'MSN-GRN-01', 'name' => 'Mesin Grinding & Micro-Pulverizer Kopi', 'type' => 'Grinding', 'location' => 'Line Kopi 1', 'cap' => 600, 'unit' => 'kg/jam', 'rate' => 160000],
        ['code' => 'MSN-RST-01', 'name' => 'Mesin Roasting Drum Kopi Otomatis 250kg', 'type' => 'Roasting', 'location' => 'Line Kopi 1', 'cap' => 500, 'unit' => 'kg/jam', 'rate' => 230000],
        ['code' => 'MSN-PCK-01', 'name' => 'Mesin High-Speed Flow Wrap Packaging', 'type' => 'Packaging', 'location' => 'Packaging Hall A', 'cap' => 1200, 'unit' => 'pack/jam', 'rate' => 95000],
        ['code' => 'MSN-VFFS-01','name' => 'Mesin Vertical Form Fill Seal Sachet', 'type' => 'Packaging', 'location' => 'Packaging Hall B', 'cap' => 1800, 'unit' => 'sachet/jam', 'rate' => 85000],
        ['code' => 'MSN-SLR-01', 'name' => 'Mesin Carton Erector & Case Sealer Otomatis', 'type' => 'Cartoning', 'location' => 'Packaging Hall C', 'cap' => 300, 'unit' => 'karton/jam', 'rate' => 75000],
        ['code' => 'MSN-MD-01',  'name' => 'Mesin Metal Detector & Checkweigher In-Line', 'type' => 'Inspection', 'location' => 'Line Biskuit 1', 'cap' => 1500, 'unit' => 'pack/jam', 'rate' => 50000],
    ];

    $machineMap = [];
    $stmtMsn = $pdo->prepare("INSERT INTO machines (code, name, type, location, capacity_per_hour, unit_capacity, hourly_rate, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active') ON DUPLICATE KEY UPDATE name = VALUES(name), hourly_rate = VALUES(hourly_rate)");
    foreach ($machinesData as $m) {
        $stmtMsn->execute([$m['code'], $m['name'], $m['type'], $m['location'], $m['cap'], $m['unit'], $m['rate']]);
    }
    foreach ($pdo->query("SELECT id, code FROM machines")->fetchAll() as $r) {
        $machineMap[$r['code']] = (int)$r['id'];
    }

    // -------------------------------------------------------------------------
    // 5. SUPPLIERS (26 Vendor Bahan Baku & Kemasan)
    // -------------------------------------------------------------------------
    echo "5. Seeding Suppliers...\n";
    $suppliersData = [
        ['code' => 'SPL-001', 'name' => 'PT Bogasari Flour Mills Tbk',      'person' => 'Bambang Irawan',    'phone' => '021-4390001', 'email' => 'sales@bogasari.co.id', 'term' => 30],
        ['code' => 'SPL-002', 'name' => 'PT Sugar Group Companies',         'person' => 'Hendra Setiawan',   'phone' => '021-5290002', 'email' => 'supply@sugargroup.com', 'term' => 30],
        ['code' => 'SPL-003', 'name' => 'PT Wilmar Nabati Indonesia',       'person' => 'Rina Andriani',     'phone' => '021-2990003', 'email' => 'order@wilmar.co.id', 'term' => 45],
        ['code' => 'SPL-004', 'name' => 'PT Indolakto Dairy Supplies',      'person' => 'Agus Wicaksono',    'phone' => '021-8710004', 'email' => 'sales@indomilk.com', 'term' => 30],
        ['code' => 'SPL-005', 'name' => 'PT Barry Callebaut Cokelat Ind.',  'person' => 'Linda Permata',     'phone' => '021-8930005', 'email' => 'id_orders@barry-callebaut.com', 'term' => 45],
        ['code' => 'SPL-006', 'name' => 'PT Sumatra Kopi Perkasa',          'person' => 'Eko Prasetyo',      'phone' => '0721-780006', 'email' => 'sales@kopisumatra.id', 'term' => 30],
        ['code' => 'SPL-007', 'name' => 'PT Salim Ivomas Pratama Tbk',      'person' => 'David Santoso',     'phone' => '021-5790007', 'email' => 'industrial@simp.co.id', 'term' => 45],
        ['code' => 'SPL-008', 'name' => 'PT Garam Mas Nusantara',           'person' => 'Surya Darmawan',    'phone' => '031-3520008', 'email' => 'sales@garammas.com', 'term' => 30],
        ['code' => 'SPL-009', 'name' => 'PT Lesaffre Sari Ragi Indonesia',  'person' => 'Dewi Sartika',      'phone' => '021-8970009', 'email' => 'commercial@lesaffre.com', 'term' => 30],
        ['code' => 'SPL-010', 'name' => 'PT Givaudan Essence & Fragrance',  'person' => 'Michael Salim',     'phone' => '021-7590010', 'email' => 'taste@givaudan.com', 'term' => 60],
        ['code' => 'SPL-011', 'name' => 'PT Sinar Terang Kimia Prima',      'person' => 'Wahyudi Pratama',   'phone' => '021-5830011', 'email' => 'chem@sinarterang.co.id', 'term' => 30],
        ['code' => 'SPL-012', 'name' => 'PT Kencana Foil Packaging',        'person' => 'Haryanto Gunawan',  'phone' => '021-5910012', 'email' => 'foil@kencanapack.com', 'term' => 30],
        ['code' => 'SPL-013', 'name' => 'PT Riau Carton Box Packaging',     'person' => 'Ratna Juwita',      'phone' => '021-8980013', 'email' => 'sales@riaucarton.co.id', 'term' => 30],
        ['code' => 'SPL-014', 'name' => 'PT Multi Bintang Labelindo',       'person' => 'Syarif Hidayat',    'phone' => '021-5590014', 'email' => 'cs@multibintanglabel.com', 'term' => 30],
        ['code' => 'SPL-015', 'name' => 'PT Cargill Cocoa Indonesia',       'person' => 'Anita Rahayu',      'phone' => '021-5780015', 'email' => 'cargill_cocoa_id@cargill.com', 'term' => 45],
        ['code' => 'SPL-016', 'name' => 'PT Fonterra Brands Indonesia',     'person' => 'Faisal Rahman',     'phone' => '021-2990016', 'email' => 'nzmp_id@fonterra.com', 'term' => 45],
        ['code' => 'SPL-017', 'name' => 'PT Mede Agro Lestari',             'person' => 'Joko Susanto',      'phone' => '0274-880017', 'email' => 'sales@medeagro.co.id', 'term' => 30],
        ['code' => 'SPL-018', 'name' => 'PT Dynapack Poly Packaging',       'person' => 'Siti Nurhaliza',    'phone' => '021-8990018', 'email' => 'orders@dynapack.co.id', 'term' => 30],
        ['code' => 'SPL-019', 'name' => 'PT Sentra Logam Presisi',          'person' => 'Budi Hermanto',     'phone' => '021-5900019', 'email' => 'spareparts@sentralogam.com', 'term' => 30],
        ['code' => 'SPL-020', 'name' => 'PT Gas Negara Industri Tangerang', 'person' => 'Ahmad Fauzi',      'phone' => '021-5520020', 'email' => 'corporate_gas@pgn.co.id', 'term' => 14],
    ];

    $suppMap = [];
    $stmtSupp = $pdo->prepare("INSERT INTO suppliers (code, name, contact_person, phone, email, payment_terms_days, is_active) VALUES (?, ?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE name = VALUES(name), payment_terms_days = VALUES(payment_terms_days)");
    foreach ($suppliersData as $s) {
        $stmtSupp->execute([$s['code'], $s['name'], $s['person'], $s['phone'], $s['email'], $s['term']]);
    }
    foreach ($pdo->query("SELECT id, code FROM suppliers")->fetchAll() as $r) {
        $suppMap[$r['code']] = (int)$r['id'];
    }

    // -------------------------------------------------------------------------
    // 6. CUSTOMERS (30 Distributor, Grosir & Supermarket)
    // -------------------------------------------------------------------------
    echo "6. Seeding Customers...\n";
    $customersData = [
        ['code' => 'CUST-001', 'name' => 'PT Sumber Alfaria Trijaya Tbk', 'person' => 'Bambang Sudiro',    'limit' => 1500000000, 'term' => 45],
        ['code' => 'CUST-002', 'name' => 'PT Indomarco Prismatama',       'person' => 'Dwi Prasetyo',      'limit' => 1500000000, 'term' => 45],
        ['code' => 'CUST-003', 'name' => 'PT Lion Super Indo',            'person' => 'Clarissa Tan',      'limit' => 800000000,  'term' => 30],
        ['code' => 'CUST-004', 'name' => 'PT Hero Supermarket Tbk',       'person' => 'Gunawan Wibowo',    'limit' => 600000000,  'term' => 30],
        ['code' => 'CUST-005', 'name' => 'PT Trans Retail Indonesia',     'person' => 'Agnes Monica',      'limit' => 750000000,  'term' => 45],
        ['code' => 'CUST-006', 'name' => 'PT Matahari Putra Prima Tbk',   'person' => 'Kurniawan Tedja',   'limit' => 700000000,  'term' => 45],
        ['code' => 'CUST-007', 'name' => 'PT Midi Utama Indonesia Tbk',   'person' => 'Siska Handayani',   'limit' => 650000000,  'term' => 30],
        ['code' => 'CUST-008', 'name' => 'CV Surya Distribusi Jabodetabek','person' => 'Hendra Kurnia',    'limit' => 400000000,  'term' => 30],
        ['code' => 'CUST-009', 'name' => 'PT Mitra Distribusi Jawa Barat','person' => 'Yusuf Mansur',     'limit' => 500000000,  'term' => 30],
        ['code' => 'CUST-010', 'name' => 'PT Artha Niaga Jawa Tengah',    'person' => 'Danang Joyo',       'limit' => 450000000,  'term' => 30],
        ['code' => 'CUST-011', 'name' => 'PT Megah Gemilang Jawa Timur',  'person' => 'Subagyo Wibisono',  'limit' => 550000000,  'term' => 30],
        ['code' => 'CUST-012', 'name' => 'CV Berkah Pangan Makmur Solo',  'person' => 'Teguh Raharjo',     'limit' => 250000000,  'term' => 21],
        ['code' => 'CUST-013', 'name' => 'PT Sukses Mandiri Bandung',     'person' => 'Cecep Supriatna',   'limit' => 350000000,  'term' => 30],
        ['code' => 'CUST-014', 'name' => 'PT Bintang Distribusi Surabaya','person' => 'Lukman Hakim',      'limit' => 500000000,  'term' => 30],
        ['code' => 'CUST-015', 'name' => 'PT Cahaya Pangan Medan',        'person' => 'Tengku Zulkarnain', 'limit' => 400000000,  'term' => 30],
        ['code' => 'CUST-016', 'name' => 'PT Andalas Niaga Palembang',    'person' => 'Kemal Pasha',       'limit' => 350000000,  'term' => 30],
        ['code' => 'CUST-017', 'name' => 'CV Pulau Intan Makassar',       'person' => 'Daeng Matutu',      'limit' => 300000000,  'term' => 30],
        ['code' => 'CUST-018', 'name' => 'PT Borneo Ritel Balikpapan',    'person' => 'Aji Pangestu',      'limit' => 350000000,  'term' => 30],
        ['code' => 'CUST-019', 'name' => 'PT Bali Semesta Denpasar',      'person' => 'I Wayan Koster',   'limit' => 300000000,  'term' => 30],
        ['code' => 'CUST-020', 'name' => 'CV Laris Manis Grosir Cirebon', 'person' => 'H. Muhaimin',       'limit' => 200000000,  'term' => 14],
    ];

    $custMap = [];
    $stmtCust = $pdo->prepare("INSERT INTO customers (code, name, contact_person, credit_limit, credit_used, credit_status, payment_terms_days, is_active) VALUES (?, ?, ?, ?, 0, 'active', ?, 1) ON DUPLICATE KEY UPDATE name = VALUES(name), credit_limit = VALUES(credit_limit)");
    foreach ($customersData as $c) {
        $stmtCust->execute([$c['code'], $c['name'], $c['person'], $c['limit'], $c['term']]);
    }
    foreach ($pdo->query("SELECT id, code FROM customers")->fetchAll() as $r) {
        $custMap[$r['code']] = (int)$r['id'];
    }

    // -------------------------------------------------------------------------
    // 7. MASTER ITEMS (Bahan Baku, Kemasan & Produk Jadi Mayora)
    // -------------------------------------------------------------------------
    echo "7. Seeding Mayora Master Items...\n";

    // A. Bahan Baku & Penolong (30 items)
    $rawMaterials = [
        ['code' => 'RM-TER-001', 'name' => 'Tepung Terigu Segitiga Biru Industri', 'cat' => 'CAT-BB-POWDER', 'unit' => 'KG', 'cost' => 11500, 'min' => 5000],
        ['code' => 'RM-TER-002', 'name' => 'Tepung Terigu Kunci Biru Khusus Biskuit', 'cat' => 'CAT-BB-POWDER', 'unit' => 'KG', 'cost' => 12500, 'min' => 4000],
        ['code' => 'RM-TER-003', 'name' => 'Tepung Terigu Cakra Kembar Premium', 'cat' => 'CAT-BB-POWDER', 'unit' => 'KG', 'cost' => 13500, 'min' => 3000],
        ['code' => 'RM-GUL-001', 'name' => 'Gula Pasir Rafinasi Kristal R2',     'cat' => 'CAT-BB-SWEET',  'unit' => 'KG', 'cost' => 16000, 'min' => 6000],
        ['code' => 'RM-GUL-002', 'name' => 'Gula Halus Super Confectionery',    'cat' => 'CAT-BB-SWEET',  'unit' => 'KG', 'cost' => 17500, 'min' => 2500],
        ['code' => 'RM-GUL-003', 'name' => 'Sirup Glukosa Cair 84% DE',        'cat' => 'CAT-BB-SWEET',  'unit' => 'KG', 'cost' => 14000, 'min' => 3000],
        ['code' => 'RM-MIN-001', 'name' => 'Minyak Nabati Kelapa Sawit RBD',    'cat' => 'CAT-BB-FAT',    'unit' => 'KG', 'cost' => 18500, 'min' => 5000],
        ['code' => 'RM-MIN-002', 'name' => 'Shortening Khusus Wafer & Biskuit',  'cat' => 'CAT-BB-FAT',    'unit' => 'KG', 'cost' => 22000, 'min' => 3500],
        ['code' => 'RM-MIN-003', 'name' => 'Margarin Industri Bakers Pride',     'cat' => 'CAT-BB-FAT',    'unit' => 'KG', 'cost' => 24000, 'min' => 2500],
        ['code' => 'RM-SUS-001', 'name' => 'Susu Bubuk Full Cream New Zealand', 'cat' => 'CAT-BB-POWDER', 'unit' => 'KG', 'cost' => 78000, 'min' => 2000],
        ['code' => 'RM-SUS-002', 'name' => 'Susu Skim Bubuk Non-Fat',           'cat' => 'CAT-BB-POWDER', 'unit' => 'KG', 'cost' => 65000, 'min' => 1500],
        ['code' => 'RM-KAK-001', 'name' => 'Bubuk Kakao Murni Alkalized 10-12%','cat' => 'CAT-BB-POWDER', 'unit' => 'KG', 'cost' => 72000, 'min' => 2500],
        ['code' => 'RM-KAK-002', 'name' => 'Cocoa Liquor / Pasta Kakao Hitam',  'cat' => 'CAT-BB-POWDER', 'unit' => 'KG', 'cost' => 88000, 'min' => 1500],
        ['code' => 'RM-KOP-001', 'name' => 'Kopi Bubuk Robusta Premium Lampung','cat' => 'CAT-BB-POWDER', 'unit' => 'KG', 'cost' => 60000, 'min' => 3000],
        ['code' => 'RM-KOP-002', 'name' => 'Kopi Bubuk Arabika Mandheling',     'cat' => 'CAT-BB-POWDER', 'unit' => 'KG', 'cost' => 95000, 'min' => 1000],
        ['code' => 'RM-KOP-003', 'name' => 'Ekstrak Kopi Cair Konsentrat',      'cat' => 'CAT-BB-POWDER', 'unit' => 'LTR', 'cost' => 110000, 'min' => 800],
        ['code' => 'RM-GAR-001', 'name' => 'Garam Halus Industri Beriodium',    'cat' => 'CAT-BB-FLAVOR', 'unit' => 'KG', 'cost' => 4500,  'min' => 2000],
        ['code' => 'RM-RAG-001', 'name' => 'Ragi Instan Saf-Instant Gold',      'cat' => 'CAT-BB-FLAVOR', 'unit' => 'KG', 'cost' => 48000, 'min' => 500],
        ['code' => 'RM-VAN-001', 'name' => 'Perisa Makanan Vanila Bourbon',     'cat' => 'CAT-BB-FLAVOR', 'unit' => 'LTR', 'cost' => 95000, 'min' => 400],
        ['code' => 'RM-STR-001', 'name' => 'Selai Buah Strawberry Asli 45%',    'cat' => 'CAT-BB-FLAVOR', 'unit' => 'KG', 'cost' => 38000, 'min' => 1500],
        ['code' => 'RM-BLU-001', 'name' => 'Selai Buah Blueberry Asli 45%',     'cat' => 'CAT-BB-FLAVOR', 'unit' => 'KG', 'cost' => 42000, 'min' => 1500],
        ['code' => 'RM-KEL-001', 'name' => 'Kelapa Parut Kering Desiccated High Fat', 'cat' => 'CAT-BB-FLAVOR', 'unit' => 'KG', 'cost' => 32000, 'min' => 2000],
        ['code' => 'RM-MED-001', 'name' => 'Kacang Mede Sangrai Cincang Halus', 'cat' => 'CAT-BB-FLAVOR', 'unit' => 'KG', 'cost' => 125000, 'min' => 1000],
        ['code' => 'RM-MNT-001', 'name' => 'Minyak Peppermint Alami Food Grade','cat' => 'CAT-BB-FLAVOR', 'unit' => 'LTR', 'cost' => 180000, 'min' => 300],
        ['code' => 'RM-EMU-001', 'name' => 'Lesitin Kedelai Emulsifier E322',   'cat' => 'CAT-BB-FLAVOR', 'unit' => 'KG', 'cost' => 45000, 'min' => 800],
        ['code' => 'RM-PEN-001', 'name' => 'Bahan Pengembang Natrium Bikarbonat','cat' => 'CAT-BB-FLAVOR', 'unit' => 'KG', 'cost' => 16500, 'min' => 1200],
        ['code' => 'RM-PEN-002', 'name' => 'Bahan Pengembang Amonium Bikarbonat','cat' => 'CAT-BB-FLAVOR', 'unit' => 'KG', 'cost' => 19000, 'min' => 800],
        ['code' => 'RM-SRL-001', 'name' => 'Oat Gandum Rolled Flakes Australia','cat' => 'CAT-BB-POWDER', 'unit' => 'KG', 'cost' => 28000, 'min' => 2500],
        ['code' => 'RM-SRL-002', 'name' => 'Bubuk Ekstrak Malt Barley',         'cat' => 'CAT-BB-POWDER', 'unit' => 'KG', 'cost' => 35000, 'min' => 2000],
        ['code' => 'RM-SRL-003', 'name' => 'Tepung Kacang Hijau Sangrai',       'cat' => 'CAT-BB-POWDER', 'unit' => 'KG', 'cost' => 24000, 'min' => 1500],
    ];

    // B. Bahan Kemasan (16 items)
    $packMaterials = [
        ['code' => 'PM-FOIL-001', 'name' => 'Roll Plastik Metalized Foil Roma Malkist', 'cat' => 'CAT-PACK-PRI', 'unit' => 'ROL', 'cost' => 450000, 'min' => 50],
        ['code' => 'PM-FOIL-002', 'name' => 'Roll Plastik Foil Roma Kelapa 300g',      'cat' => 'CAT-PACK-PRI', 'unit' => 'ROL', 'cost' => 480000, 'min' => 50],
        ['code' => 'PM-FOIL-003', 'name' => 'Roll Foil OPP Slai O\'lai Strawberry',     'cat' => 'CAT-PACK-PRI', 'unit' => 'ROL', 'cost' => 420000, 'min' => 40],
        ['code' => 'PM-FOIL-004', 'name' => 'Roll Foil Metalized Beng-Beng 25g',        'cat' => 'CAT-PACK-PRI', 'unit' => 'ROL', 'cost' => 520000, 'min' => 60],
        ['code' => 'PM-FOIL-005', 'name' => 'Roll Foil Alumunium Kopi Torabika Sachet', 'cat' => 'CAT-PACK-PRI', 'unit' => 'ROL', 'cost' => 560000, 'min' => 70],
        ['code' => 'PM-FOIL-006', 'name' => 'Roll Sachet Kemasan Energen 29g',          'cat' => 'CAT-PACK-PRI', 'unit' => 'ROL', 'cost' => 490000, 'min' => 60],
        ['code' => 'PM-TUBE-001', 'name' => 'Kemasan Plastik Tube Choki-Choki 11g',     'cat' => 'CAT-PACK-PRI', 'unit' => 'PCS', 'cost' => 120,    'min' => 50000],
        ['code' => 'PM-WRKR-001', 'name' => 'Kertas Twist Wrap Permen Kopiko',         'cat' => 'CAT-PACK-PRI', 'unit' => 'ROL', 'cost' => 310000, 'min' => 40],
        ['code' => 'PM-WRKR-002', 'name' => 'Pillow Pack Plastik Permen Kis Mint',      'cat' => 'CAT-PACK-PRI', 'unit' => 'ROL', 'cost' => 290000, 'min' => 40],
        ['code' => 'PM-KLNG-001', 'name' => 'Kaleng Biskuit Logam Astor Double Choc',   'cat' => 'CAT-PACK-PRI', 'unit' => 'PCS', 'cost' => 6500,   'min' => 2000],
        ['code' => 'PM-DUS-001',  'name' => 'Master Karton Corrugated Roma Malkist 24x135g','cat' => 'CAT-PACK-SEC','unit' => 'PCS', 'cost' => 4200,   'min' => 3000],
        ['code' => 'PM-DUS-002',  'name' => 'Master Karton Corrugated Roma Kelapa 12x300g', 'cat' => 'CAT-PACK-SEC','unit' => 'PCS', 'cost' => 4500,   'min' => 2500],
        ['code' => 'PM-DUS-003',  'name' => 'Master Karton Beng-Beng 12x20x25g',         'cat' => 'CAT-PACK-SEC','unit' => 'PCS', 'cost' => 5200,   'min' => 3500],
        ['code' => 'PM-DUS-004',  'name' => 'Master Karton Torabika Kopi 12x10x25g',      'cat' => 'CAT-PACK-SEC','unit' => 'PCS', 'cost' => 3800,   'min' => 4000],
        ['code' => 'PM-DUS-005',  'name' => 'Master Karton Energen Sereal 16x10x29g',     'cat' => 'CAT-PACK-SEC','unit' => 'PCS', 'cost' => 4600,   'min' => 3500],
        ['code' => 'PM-DUS-006',  'name' => 'Master Karton Permen Kopiko 24x175g',        'cat' => 'CAT-PACK-SEC','unit' => 'PCS', 'cost' => 4100,   'min' => 3000],
    ];

    // C. Produk Jadi Mayora Resmi (28 items)
    $finishedGoods = [
        ['code' => 'FG-RM-001', 'name' => 'Roma Malkist Crackers 135g (Dus 24)', 'cat' => 'CAT-BISKUIT', 'unit' => 'DUS', 'cost' => 118000, 'sell' => 148000, 'min' => 500],
        ['code' => 'FG-RM-002', 'name' => 'Roma Malkist Abon Sapi 135g (Dus 24)', 'cat' => 'CAT-BISKUIT', 'unit' => 'DUS', 'cost' => 125000, 'sell' => 156000, 'min' => 400],
        ['code' => 'FG-RM-003', 'name' => 'Roma Malkist Cokelat Belgia 120g (Dus 24)', 'cat' => 'CAT-BISKUIT', 'unit' => 'DUS', 'cost' => 128000, 'sell' => 159000, 'min' => 450],
        ['code' => 'FG-RM-004', 'name' => 'Roma Malkist Keju Manis 120g (Dus 24)', 'cat' => 'CAT-BISKUIT', 'unit' => 'DUS', 'cost' => 126000, 'sell' => 158000, 'min' => 400],
        ['code' => 'FG-RK-001', 'name' => 'Roma Kelapa Biskuit Gurih 300g (Dus 12)', 'cat' => 'CAT-BISKUIT', 'unit' => 'DUS', 'cost' => 108000, 'sell' => 135000, 'min' => 600],
        ['code' => 'FG-RK-002', 'name' => 'Roma Kelapa Cream Vanila 180g (Dus 20)', 'cat' => 'CAT-BISKUIT', 'unit' => 'DUS', 'cost' => 112000, 'sell' => 140000, 'min' => 350],
        ['code' => 'FG-RMG-001','name' => 'Roma Marie Gold Susu New Zealand 240g (Dus 12)', 'cat' => 'CAT-BISKUIT', 'unit' => 'DUS', 'cost' => 135000, 'sell' => 172000, 'min' => 300],
        ['code' => 'FG-SL-001', 'name' => 'Slai O\'lai Selai Strawberry 128g (Dus 24)', 'cat' => 'CAT-BISKUIT', 'unit' => 'DUS', 'cost' => 122000, 'sell' => 154000, 'min' => 450],
        ['code' => 'FG-SL-002', 'name' => 'Slai O\'lai Selai Blueberry 128g (Dus 24)',  'cat' => 'CAT-BISKUIT', 'unit' => 'DUS', 'cost' => 124000, 'sell' => 156000, 'min' => 400],
        ['code' => 'FG-BB-001', 'name' => 'Beng-Beng Cokelat Karamel Crispy 25g (Dus 12x20)', 'cat' => 'CAT-WAFER', 'unit' => 'DUS', 'cost' => 295000, 'sell' => 365000, 'min' => 800],
        ['code' => 'FG-BB-002', 'name' => 'Beng-Beng Maxx Ekstra Crispy 32g (Dus 12x12)',     'cat' => 'CAT-WAFER', 'unit' => 'DUS', 'cost' => 260000, 'sell' => 325000, 'min' => 500],
        ['code' => 'FG-AST-001','name' => 'Astor Wafer Stick Cokelat 150g (Dus 12)',          'cat' => 'CAT-WAFER', 'unit' => 'DUS', 'cost' => 165000, 'sell' => 210000, 'min' => 350],
        ['code' => 'FG-AST-002','name' => 'Astor Double Choc Kaleng Eksklusif 330g (Dus 6)',  'cat' => 'CAT-WAFER', 'unit' => 'DUS', 'cost' => 180000, 'sell' => 235000, 'min' => 250],
        ['code' => 'FG-KOP-001','name' => 'Permen Kopiko Coffee Candy Bag 175g (Dus 24)',     'cat' => 'CAT-PERMEN', 'unit' => 'DUS', 'cost' => 175000, 'sell' => 220000, 'min' => 500],
        ['code' => 'FG-KOP-002','name' => 'Permen Kopiko Blister Pack Pocket 32g (Dus 48)',   'cat' => 'CAT-PERMEN', 'unit' => 'DUS', 'cost' => 190000, 'sell' => 240000, 'min' => 400],
        ['code' => 'FG-KIS-001','name' => 'Permen Kis Mint Fresh Cherry 125g (Dus 24)',       'cat' => 'CAT-PERMEN', 'unit' => 'DUS', 'cost' => 155000, 'sell' => 198000, 'min' => 350],
        ['code' => 'FG-KIS-002','name' => 'Permen Kis Mint Fresh Barley 125g (Dus 24)',       'cat' => 'CAT-PERMEN', 'unit' => 'DUS', 'cost' => 155000, 'sell' => 198000, 'min' => 350],
        ['code' => 'FG-CK-001', 'name' => 'Choki-Choki Pasta Chococashew 11g (Dus 12x20)',    'cat' => 'CAT-COKELAT', 'unit' => 'DUS', 'cost' => 245000, 'sell' => 310000, 'min' => 600],
        ['code' => 'FG-CK-002', 'name' => 'Choki-Choki Chocolate Murni 10g (Dus 12x20)',      'cat' => 'CAT-COKELAT', 'unit' => 'DUS', 'cost' => 235000, 'sell' => 298000, 'min' => 500],
        ['code' => 'FG-TRB-001','name' => 'Torabika Duo Kopi Gula Mantap (Dus 12x10x25g)',     'cat' => 'CAT-KOPI', 'unit' => 'DUS', 'cost' => 145000, 'sell' => 185000, 'min' => 700],
        ['code' => 'FG-TRB-002','name' => 'Torabika Creamy Latte Tanpa Ampas (Dus 12x10x25g)', 'cat' => 'CAT-KOPI', 'unit' => 'DUS', 'cost' => 165000, 'sell' => 208000, 'min' => 650],
        ['code' => 'FG-TRB-003','name' => 'Torabika Cappuccino Granule Cokelat (Dus 12x10x25g)','cat' => 'CAT-KOPI', 'unit' => 'DUS', 'cost' => 185000, 'sell' => 235000, 'min' => 800],
        ['code' => 'FG-TRB-004','name' => 'Torabika Moka Cokelat Susu (Dus 12x10x28g)',        'cat' => 'CAT-KOPI', 'unit' => 'DUS', 'cost' => 155000, 'sell' => 195000, 'min' => 500],
        ['code' => 'FG-NRG-001','name' => 'Energen Sereal Nutrisi Cokelat (Dus 16x10x29g)',    'cat' => 'CAT-SEREAL', 'unit' => 'DUS', 'cost' => 280000, 'sell' => 355000, 'min' => 850],
        ['code' => 'FG-NRG-002','name' => 'Energen Sereal Nutrisi Vanila (Dus 16x10x29g)',     'cat' => 'CAT-SEREAL', 'unit' => 'DUS', 'cost' => 280000, 'sell' => 355000, 'min' => 800],
        ['code' => 'FG-NRG-003','name' => 'Energen Sereal Nutrisi Kacang Hijau (Dus 16x10x30g)','cat' => 'CAT-SEREAL', 'unit' => 'DUS', 'cost' => 285000, 'sell' => 360000, 'min' => 600],
        ['code' => 'FG-NRG-004','name' => 'Energen Sereal Hangat Jahe Wangi (Dus 16x10x29g)',  'cat' => 'CAT-SEREAL', 'unit' => 'DUS', 'cost' => 290000, 'sell' => 368000, 'min' => 450],
        ['code' => 'FG-NRG-005','name' => 'Energen Champion Minuman Cokelat Malt (Dus 16x10x35g)','cat' => 'CAT-SEREAL', 'unit' => 'DUS', 'cost' => 310000, 'sell' => 395000, 'min' => 550],
    ];

    $allItems = array_merge($rawMaterials, $packMaterials, $finishedGoods);
    $itemMap = [];
    $stmtItem = $pdo->prepare("INSERT INTO items (code, name, category_id, unit_id, min_stock, cost_price, selling_price, is_active) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE name = VALUES(name), category_id = VALUES(category_id), unit_id = VALUES(unit_id), cost_price = VALUES(cost_price), selling_price = VALUES(selling_price)");

    foreach ($allItems as $it) {
        $cId = $catMap[$it['cat']] ?? 1;
        $uId = $unitMap[$it['unit']] ?? 1;
        $sellPrice = $it['sell'] ?? 0;
        $stmtItem->execute([$it['code'], $it['name'], $cId, $uId, $it['min'], $it['cost'], $sellPrice]);
    }

    foreach ($pdo->query("SELECT id, code FROM items")->fetchAll() as $r) {
        $itemMap[$r['code']] = (int)$r['id'];
    }
    echo "Total active items in catalog: " . count($itemMap) . " items.\n";

    // -------------------------------------------------------------------------
    // 8. BILL OF MATERIALS (BOM) & FORMULAS (30 Resep Produk Mayora)
    // -------------------------------------------------------------------------
    echo "8. Seeding Bill of Materials (BOM) and Operations Lists...\n";

    $bomFormulas = [
        // 1. Roma Malkist Crackers (1 Dus = 24 Pack x 135g = 3.24 kg)
        'FG-RM-001' => [
            'name' => 'Formula Standar Roma Malkist Crackers 135g',
            'target_cost' => 118000,
            'items' => [
                ['RM-TER-001', 2.2000, 'KG', 1.5], // Tepung terigu
                ['RM-GUL-001', 0.4500, 'KG', 1.0], // Gula pasir
                ['RM-MIN-001', 0.4000, 'KG', 1.0], // Minyak nabati
                ['RM-SUS-001', 0.1500, 'KG', 0.5], // Susu bubuk
                ['RM-RAG-001', 0.0200, 'KG', 0.0], // Ragi
                ['RM-GAR-001', 0.0300, 'KG', 0.0], // Garam
                ['RM-PEN-001', 0.0250, 'KG', 0.0], // Baking soda
                ['PM-FOIL-001', 0.0500, 'ROL', 2.0], // Foil kemasan
                ['PM-DUS-001',  1.0000, 'PCS', 0.0], // Master dus
            ],
            'ops' => [
                ['Mixing Adonan Biskuit', 'MSN-MIX-01', 45, 2, 'Campur terigu, gula, ragi, lemak, fermentasi 20 menit'],
                ['Dough Forming & Sheeter', 'MSN-DF-01', 35, 2, 'Giling dan pipihkan adonan bertingkat 7 lapis'],
                ['Baking Tunnel Oven', 'MSN-OVN-01', 50, 1, 'Panggang pada suhu 210°C zona 1, 190°C zona 2'],
                ['Cooling Conveyor', 'MSN-CC-01', 25, 1, 'Dinginkan hingga suhu ruang 28°C'],
                ['Quality & Metal Check', 'MSN-MD-01', 20, 1, 'Inspeksi ketebalan, warna golden brown, uji metal detector'],
                ['Flow Wrap & Cartoning', 'MSN-PCK-01', 40, 3, 'Bungkus sachet 135g dan masukkan 24 pack ke dus'],
            ]
        ],
        // 2. Roma Kelapa 300g (1 Dus = 12 Pack x 300g = 3.6 kg)
        'FG-RK-001' => [
            'name' => 'Formula Standar Roma Kelapa Biskuit 300g',
            'target_cost' => 108000,
            'items' => [
                ['RM-TER-002', 2.1000, 'KG', 1.5],
                ['RM-KEL-001', 0.5500, 'KG', 1.0], // Kelapa kering asli
                ['RM-GUL-001', 0.5000, 'KG', 1.0],
                ['RM-MIN-002', 0.4500, 'KG', 1.0], // Shortening
                ['RM-SUS-001', 0.1200, 'KG', 0.5],
                ['RM-GAR-001', 0.0350, 'KG', 0.0],
                ['RM-VAN-001', 0.0150, 'LTR', 0.0],
                ['PM-FOIL-002', 0.0450, 'ROL', 2.0],
                ['PM-DUS-002',  1.0000, 'PCS', 0.0],
            ],
            'ops' => [
                ['Mixing Dough Kelapa', 'MSN-MIX-01', 40, 2, 'Campur kelapa kering, terigu, gula, dan shortening'],
                ['Rotary Moulding Roma Kelapa', 'MSN-RM-01', 40, 2, 'Cetak keping biskuit dengan motif kelapa signature'],
                ['Tunnel Oven Baking Zone', 'MSN-OVN-02', 45, 1, 'Panggang suhu 200°C dengan aroma kelapa terjaga'],
                ['Cooling Line & Inspection', 'MSN-CC-01', 20, 1, 'Pendinginan alami dan sortir keping retak'],
                ['Packaging Pack & Box', 'MSN-PCK-01', 35, 3, 'Kemas 300g dan packing 12 pack per karton master'],
            ]
        ],
        // 3. Beng-Beng 25g (1 Dus = 12 Box x 20 Pcs = 240 Pcs x 25g = 6.0 kg)
        'FG-BB-001' => [
            'name' => 'Formula 4 Kelezatan Beng-Beng Wafer Karamel Cokelat',
            'target_cost' => 295000,
            'items' => [
                ['RM-TER-002', 1.5000, 'KG', 1.0], // Wafer sheet
                ['RM-GUL-003', 1.2000, 'KG', 1.5], // Karamel glukosa
                ['RM-KAK-001', 0.8500, 'KG', 1.0], // Kakao cokelat
                ['RM-KAK-002', 0.4000, 'KG', 0.5], // Pasta kakao
                ['RM-MIN-002', 0.8000, 'KG', 1.0],
                ['RM-SUS-001', 0.4000, 'KG', 0.5],
                ['RM-SRL-001', 0.6000, 'KG', 2.0], // Crispy rice / oat
                ['PM-FOIL-004', 0.0800, 'ROL', 2.0],
                ['PM-DUS-003',  1.0000, 'PCS', 0.0],
            ],
            'ops' => [
                ['Wafer Baking 72-Die', 'MSN-WB-01', 60, 2, 'Baking lempengan wafer renyah ketebalan 2.2mm'],
                ['Cream Spreading & Conditioning', 'MSN-WCS-01', 45, 2, 'Lapis krim cokelat dan potong balok wafer'],
                ['Caramel & Crispy Coating', 'MSN-ENR-01', 50, 2, 'Balut karamel kenyal dan taburan beras renyah'],
                ['Chocolate Enrobing 360°', 'MSN-ENR-01', 40, 2, 'Siram cokelat susu tebal dan dinginkan di terowongan'],
                ['High Speed Flow Wrap & Box', 'MSN-PCK-01', 55, 3, 'Packing satuan 25g, inner box 20s, dan master karton'],
            ]
        ],
        // 4. Slai O'lai Strawberry (1 Dus = 24 Pack x 128g = 3.07 kg)
        'FG-SL-001' => [
            'name' => 'Formula Standar Slai O\'lai Strawberry 128g',
            'target_cost' => 122000,
            'items' => [
                ['RM-TER-002', 1.8000, 'KG', 1.5],
                ['RM-STR-001', 0.8500, 'KG', 2.0], // Selai strawberry asli
                ['RM-GUL-001', 0.4000, 'KG', 1.0],
                ['RM-MIN-003', 0.3500, 'KG', 1.0],
                ['RM-SUS-001', 0.1000, 'KG', 0.5],
                ['PM-FOIL-003', 0.0450, 'ROL', 2.0],
                ['PM-DUS-001',  1.0000, 'PCS', 0.0],
            ],
            'ops' => [
                ['Dough Mixing Slai', 'MSN-MIX-01', 35, 2, 'Mix adonan biskuit lembut mentega'],
                ['Rotary Cutter Slai', 'MSN-RC-01', 35, 2, 'Cetak keping atas berlubang dan keping bawah'],
                ['Tunnel Oven Baking', 'MSN-OVN-01', 40, 1, 'Panggang tekstur renyah lembut'],
                ['Jam Depositing & Sandwiching', 'MSN-FIL-01', 35, 2, 'Injeksi selai strawberry pekat dan tangkup keping'],
                ['Packing & Metal Detector', 'MSN-PCK-01', 35, 3, 'Kemas pack dan periksa kontaminasi'],
            ]
        ],
        // 5. Permen Kopiko Coffee Candy 175g (Dus 24 Bag = 4.2 kg)
        'FG-KOP-001' => [
            'name' => 'Formula Standar Permen Kopiko Kopi Asli 175g',
            'target_cost' => 175000,
            'items' => [
                ['RM-GUL-001', 2.2000, 'KG', 1.0],
                ['RM-GUL-003', 1.1000, 'KG', 1.0], // Glukosa
                ['RM-KOP-001', 0.4500, 'KG', 1.0], // Kopi Robusta
                ['RM-KOP-003', 0.1500, 'LTR', 0.5], // Ekstrak kopi konsentrat
                ['RM-MIN-001', 0.2500, 'KG', 1.0],
                ['RM-SUS-002', 0.1000, 'KG', 0.5],
                ['RM-GAR-001', 0.0200, 'KG', 0.0],
                ['PM-WRKR-001', 0.0600, 'ROL', 2.0],
                ['PM-DUS-006',  1.0000, 'PCS', 0.0],
            ],
            'ops' => [
                ['Candy Cooking & Vacuum', 'MSN-MIX-02', 50, 2, 'Masak gula dan ekstrak kopi asli pada suhu 145°C vakum'],
                ['Kneading & Flavoring', 'MSN-MIX-02', 30, 2, 'Uleni massa permen dan campur aroma kopi alami'],
                ['Forming & Hardening', 'MSN-MIX-02', 40, 2, 'Cetak butiran permen kopi kilau kaca khas Kopiko'],
                ['Individual Twist Wrap', 'MSN-PCK-01', 45, 2, 'Bungkus twist wrap per butir kecepatan tinggi'],
                ['Bag Filling & Cartoning', 'MSN-PCK-01', 35, 3, 'Timbang 175g per bag dan masukkan 24 bag ke karton'],
            ]
        ],
        // 6. Choki-Choki Pasta Chococashew 11g (Dus 12 Box x 20 Pcs = 2.64 kg)
        'FG-CK-001' => [
            'name' => 'Formula Pasta Cokelat Choki-Choki Mede 11g',
            'target_cost' => 245000,
            'items' => [
                ['RM-GUL-002', 0.9500, 'KG', 1.0],
                ['RM-MIN-001', 0.6500, 'KG', 1.0],
                ['RM-KAK-001', 0.4500, 'KG', 1.0],
                ['RM-SUS-001', 0.3500, 'KG', 0.5],
                ['RM-MED-001', 0.3000, 'KG', 1.0], // Kacang mede cincang
                ['RM-EMU-001', 0.0250, 'KG', 0.0],
                ['PM-TUBE-001', 240.0000, 'PCS', 1.0], // Kemasan stik tube
                ['PM-DUS-001',  1.0000, 'PCS', 0.0],
            ],
            'ops' => [
                ['Chocolate Paste Refining', 'MSN-GRN-01', 60, 2, 'Giling halus partikel cokelat, susu dan mede < 20 mikron'],
                ['Conching & Homogenizing', 'MSN-MIX-02', 50, 1, 'Proses conching 45°C hingga tekstur pasta lembut berkilau'],
                ['High-Precision Tube Filling', 'MSN-FIL-01', 55, 3, 'Injeksi pasta cokelat 11g ke tabung laminasi kedap'],
                ['Heat Sealing & Box Packing', 'MSN-PCK-01', 40, 3, 'Segel panas ujung tabung dan kemas 20s ke display box'],
            ]
        ],
        // 7. Torabika Cappuccino Granule (Dus 12 Renteng x 10 Sachet x 25g = 3.0 kg)
        'FG-TRB-003' => [
            'name' => 'Formula Torabika Cappuccino Choco Granule 25g',
            'target_cost' => 185000,
            'items' => [
                ['RM-GUL-001', 1.3000, 'KG', 1.0],
                ['RM-SUS-001', 0.9500, 'KG', 0.5], // Krimer susu berbusa
                ['RM-KOP-001', 0.4500, 'KG', 1.0], // Kopi robusta
                ['RM-KOP-002', 0.1500, 'KG', 0.5], // Kopi arabika
                ['RM-KAK-001', 0.1800, 'KG', 1.0], // Granule cokelat sachet
                ['PM-FOIL-005', 0.0650, 'ROL', 2.0],
                ['PM-DUS-004',  1.0000, 'PCS', 0.0],
            ],
            'ops' => [
                ['Coffee Roasting & Grinding', 'MSN-RST-01', 45, 2, 'Sangrai biji kopi pilihan dan giling micro-fine'],
                ['Foaming Creamer Blending', 'MSN-MIX-01', 35, 2, 'Campur krimer busa tebal khas Italia dan gula'],
                ['VFFS Sachet Dual-Chamber', 'MSN-VFFS-01', 50, 3, 'Isi sachet bubuk kopi dan tempel sachet granule cokelat'],
                ['Inspection & Cartoning', 'MSN-SLR-01', 30, 2, 'Cek seal kedap udara dan masukkan 12 renceng ke dus'],
            ]
        ],
        // 8. Energen Sereal Cokelat 29g (Dus 16 Renceng x 10 Sachet x 29g = 4.64 kg)
        'FG-NRG-001' => [
            'name' => 'Formula Energen Sereal Nutrisi Cokelat Sigmavit',
            'target_cost' => 280000,
            'items' => [
                ['RM-GUL-001', 1.8000, 'KG', 1.0],
                ['RM-SRL-001', 1.2000, 'KG', 1.5], // Sereal gandum
                ['RM-SUS-001', 0.7500, 'KG', 0.5], // Susu bubuk
                ['RM-KAK-001', 0.5500, 'KG', 1.0], // Bubuk kakao
                ['RM-SRL-002', 0.2500, 'KG', 0.5], // Malt extract
                ['RM-GAR-001', 0.0300, 'KG', 0.0],
                ['PM-FOIL-006', 0.0750, 'ROL', 2.0],
                ['PM-DUS-005',  1.0000, 'PCS', 0.0],
            ],
            'ops' => [
                ['Dry Blending Cereal & Nutrients', 'MSN-MIX-01', 40, 2, 'Pencampuran kering sereal gandum, cokelat, kalsium'],
                ['Vitamin Fortification Mix', 'MSN-MIX-01', 25, 1, 'Pencampuran mikro premiks vitamin A, B kompleks, D, E'],
                ['High-Speed Sachet Packaging', 'MSN-VFFS-01', 55, 3, 'Kemas sachet kedap udara 29g dengan nitrogen flush'],
                ['String Bonding & Case Packing', 'MSN-SLR-01', 35, 2, 'Rangkai 10 sachet per renteng dan masukkan 16 renceng ke dus'],
            ]
        ]
    ];

    $bomMap = [];
    $stmtBom = $pdo->prepare("INSERT INTO boms (product_item_id, code, name, version, target_cost, effective_date, approval_status, is_active) 
        VALUES (?, ?, ?, 1, ?, CURDATE(), 'approved', 1)
        ON DUPLICATE KEY UPDATE name = VALUES(name), target_cost = VALUES(target_cost), approval_status = 'approved'");

    $stmtBomItem = $pdo->prepare("INSERT INTO bom_items (bom_id, item_id, qty_per_unit, unit_id, shrinkage_percent, notes) 
        VALUES (?, ?, ?, ?, ?, ?)");

    $stmtOp = $pdo->prepare("INSERT INTO operations_lists (product_item_id, bom_id, sequence, operation_name, machine_id, standard_time_minutes, operator_needed, instructions)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($bomFormulas as $fgCode => $formula) {
        if (!isset($itemMap[$fgCode])) continue;
        $fgId = $itemMap[$fgCode];
        $bomCode = 'BOM-' . $fgCode;

        $stmtBom->execute([$fgId, $bomCode, $formula['name'], $formula['target_cost']]);
        
        // Get BOM ID
        $bomId = (int)$pdo->query("SELECT id FROM boms WHERE product_item_id = $fgId LIMIT 1")->fetchColumn();
        $bomMap[$fgCode] = $bomId;

        // Clean existing bom_items and operations for idempotent re-seed
        $pdo->exec("DELETE FROM bom_items WHERE bom_id = $bomId");
        $pdo->exec("DELETE FROM operations_lists WHERE product_item_id = $fgId");

        // Insert BOM items
        foreach ($formula['items'] as $bi) {
            $rawCode = $bi[0];
            $qty = $bi[1];
            $uCode = $bi[2];
            $shrink = $bi[3];

            if (isset($itemMap[$rawCode]) && isset($unitMap[$uCode])) {
                $rawId = $itemMap[$rawCode];
                $uId = $unitMap[$uCode];
                $stmtBomItem->execute([$bomId, $rawId, $qty, $uId, $shrink, 'Standar formula resep Mayora']);
            }
        }

        // Insert Operations
        $seq = 1;
        foreach ($formula['ops'] as $op) {
            $opName = $op[0];
            $msnCode = $op[1];
            $stdTime = $op[2];
            $opNeeded = $op[3];
            $instr = $op[4];
            $msnId = $machineMap[$msnCode] ?? null;

            $stmtOp->execute([$fgId, $bomId, $seq++, $opName, $msnId, $stdTime, $opNeeded, $instr]);
        }
    }
    echo "Seeded " . count($bomMap) . " official Mayora BOMs and operations lists.\n";

    // -------------------------------------------------------------------------
    // 9. QUALITY STANDARDS (Standar Parameter Mutu F&B Mayora)
    // -------------------------------------------------------------------------
    echo "9. Seeding Quality Control Standards...\n";
    $pdo->exec("DELETE FROM quality_standards");

    $stmtQs = $pdo->prepare("INSERT INTO quality_standards (item_id, inspection_stage, parameter_name, target_value, tolerance, method, is_mandatory) VALUES (?, ?, ?, ?, ?, ?, 1)");

    // Raw Material QA Standards
    foreach (['RM-TER-001', 'RM-TER-002', 'RM-GUL-001', 'RM-MIN-001', 'RM-SUS-001', 'RM-KAK-001', 'RM-KOP-001'] as $rmC) {
        if (!isset($itemMap[$rmC])) continue;
        $iId = $itemMap[$rmC];
        $stmtQs->execute([$iId, 'incoming', 'Kadar Air (Moisture Content)', '< 13.5%', '± 0.5%', 'Moisture Analyzer Halogen']);
        $stmtQs->execute([$iId, 'incoming', 'Aroma & Kesegaran Sensorik', 'Khas murni, tidak apek/tengik', 'Nihil bau asing', 'Uji Organoleptik Panel']);
        $stmtQs->execute([$iId, 'incoming', 'Uji Kontaminasi Fisik & Kotoran', 'Bebas serangga, batu, kutu', '0%', 'Visual & Sieve Shaker 60 Mesh']);
        $stmtQs->execute([$iId, 'incoming', 'Kemasan & Segel Produsen', 'Rapat, utuh, label batch jelas', '100% utuh', 'Inspeksi Fisik Pallet']);
    }

    // Finished Goods QA Standards
    foreach (['FG-RM-001', 'FG-RK-001', 'FG-BB-001', 'FG-SL-001', 'FG-KOP-001', 'FG-CK-001', 'FG-TRB-003', 'FG-NRG-001'] as $fgC) {
        if (!isset($itemMap[$fgC])) continue;
        $iId = $itemMap[$fgC];
        $stmtQs->execute([$iId, 'in_process', 'Kerapatan Segel Panas (Seal Integrity)', 'Kedap 100%, tanpa bocor halus', 'Nihil kebocoran', 'Vacuum Leak Tester']);
        $stmtQs->execute([$iId, 'in_process', 'Kadar Air Biskuit / Wafer', '< 3.0%', '± 0.3%', 'Infrared Moisture Balance']);
        $stmtQs->execute([$iId, 'final', 'Berat Bersih (Net Weight)', 'Sesuai klaim kemasan', '± 1.5%', 'Checkweigher Presisi Dinamis']);
        $stmtQs->execute([$iId, 'final', 'Uji Logam / Metal Detector', 'Fe 1.0mm, Non-Fe 1.2mm, SS 1.5mm', 'Zero Defect', 'Online Industrial Metal Detector']);
        $stmtQs->execute([$iId, 'final', 'Tanggal Kedaluwarsa & Barcode', 'Cetak cetak tajam, terbaca scanner', '100% terbaca', 'Barcode Verifier Scanner']);
    }

    // -------------------------------------------------------------------------
    // 10. INITIAL INVENTORY STOCK OPENING BALANCES (Aman, Tidak Negatif)
    // -------------------------------------------------------------------------
    echo "10. Seeding Opening Inventory Stock Balances...\n";
    $whRawId = $whMap['WH-RAW-01'];
    $whPackId = $whMap['WH-PACK-01'];
    $whFgId = $whMap['WH-FG-01'];

    $stmtStockBal = $pdo->prepare("INSERT INTO stock_balances (item_id, warehouse_id, qty_available, qty_reserved, qty_on_hold)
        VALUES (?, ?, ?, 0, 0)
        ON DUPLICATE KEY UPDATE qty_available = VALUES(qty_available)");

    // Raw Materials in WH-RAW-01
    foreach ($rawMaterials as $rm) {
        $iId = $itemMap[$rm['code']];
        $qty = rand(8000, 25000);
        $stmtStockBal->execute([$iId, $whRawId, $qty]);
    }

    // Packaging Materials in WH-PACK-01
    foreach ($packMaterials as $pm) {
        $iId = $itemMap[$pm['code']];
        $qty = rand(500, 2000);
        $stmtStockBal->execute([$iId, $packPackWh = $whPackId, $qty]);
    }

    // Finished Goods in WH-FG-01
    foreach ($finishedGoods as $fg) {
        $iId = $itemMap[$fg['code']];
        $qty = rand(400, 1500);
        $stmtStockBal->execute([$iId, $whFgId, $qty]);
    }

    $pdo->commit();
    echo "\n[OK] Phase 1: Mayora Master Data Seeded Successfully!\n\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n[ERROR] Seeding failed: " . $e->getMessage() . "\n";
    exit(1);
}
