# Sistem Informasi Akuntansi & Operasional Manufaktur dan Distribusi (SIA)

Sistem Informasi Akuntansi & Operasional Terintegrasi untuk perusahaan manufaktur dan distribusi berbasis PHP Native dan MariaDB/MySQL. Sistem ini mengintegrasikan seluruh siklus operasional bisnis: mulai dari pengadaan barang (*Procurement / P2P*), pengelolaan persediaan (*Inventory*), perencanaan & eksekusi produksi (*Manufacturing / PPIC & QC*), penjualan & distribusi (*Sales & Distribution / O2C*), pengelolaan SDM & penggajian (*HR & Payroll*), hingga pembukuan otomatis dan jurnal umum (*General Ledger & Financial Accounting*).

---

## Daftar Isi
1. [Fitur & Keunggulan Sistem](#fitur--keunggulan-sistem)
2. [Prasyarat Sistem (Requirements)](#prasyarat-sistem-requirements)
3. [Panduan Instalasi & Setup Database](#panduan-instalasi--setup-database)
4. [Kredensial Akun Pengguna Demo](#kredensial-akun-pengguna-demo)
5. [Arsitektur Database & Konvensi Teknis](#arsitektur-database--konvensi-teknis)
6. [Katalog 72 Tabel Database (Data Dictionary)](#katalog-72-tabel-database-data-dictionary)
7. [Alur Siklus Bisnis Utama](#alur-siklus-bisnis-utama)
8. [Struktur Direktori Proyek](#struktur-direktori-proyek)

---

## Fitur & Keunggulan Sistem

- **Multi-Role Access Control (RBAC):** Hak akses spesifik berdasarkan fungsi jabatan (Admin, Gudang, Purchasing, PPIC, QC, Sales, Finance, HR, Manajer).
- **Integritas Relasional Penuh:** Didukung 72 tabel berbasis **InnoDB** dengan **203 Foreign Key Constraints** dan indeks teroptimasi untuk menjaga keutuhan data (*referential integrity*).
- **Siklus Pengadaan Lengkap (P2P):** Dari *Purchase Requisition* (PR), *Purchase Order* (PO), penerimaan barang gudang (*Goods Receipt*), verifikasi *3-Way Matching* (PO vs GR vs Invoice), hingga *AP Voucher* dan pembayaran supplier (*Payment Request & Disbursement*).
- **Manufaktur & Pengendalian Mutu (PPIC & QC):** Mengelola *Bill of Materials* (BOM), *Production Orders*, mutasi pengeluaran bahan baku (*Material Issue*), pencatatan *Machine Log*, *Scrap*, inspeksi mutu (*QC Inspections*), hingga kalkulasi biaya produksi (*Production Costing*).
- **Penjualan & Pengendalian Piutang (O2C & AR):** Pencatatan pesanan (*Customer Order* / *Sales Order*), kontrol limit kredit (*Credit Override Approval*), *Delivery Order* (DO) & bukti serah terima (*Delivery Proof*), Faktur Penjualan, nota kredit (*Credit Note*), dan alokasi penerimaan kas piutang (*AR Receipt Allocations*).
- **Penggajian Terintegrasi (HR & Payroll):** Data absensi, lembur, cuti, perhitungan komponen gaji (pokok, tunjangan, lembur, BPJS, PPh21), persetujuan berjenjang, dan pencetakan slip gaji.
- **Akuntansi Otomatis (General Ledger):** Bagan akun (*Chart of Accounts*), pemetaan transaksi (*Account Mappings*), dan pembuatan entri jurnal otomatis dengan penomoran standar debit-kredit seimbang (*balanced entry*).
- **Keamanan Berlapis:** Dilengkapi CSRF Token generator/validator, hashing password menggunakan `bcrypt`, parameterized query anti SQL Injection via PDO, proteksi *Session Fixation*, dan pencatatan riwayat audit (*Audit Logs*).

---

## Prasyarat Sistem (Requirements)

- **PHP:** Versi 8.1 atau 8.2+ (Ekstensi aktif: `pdo_mysql`, `mbstring`, `openssl`, `session`)
- **Database Server:** MariaDB 10.4+ atau MySQL 8.0+
- **Web Server:** Apache (misal via XAMPP/Laragon) atau PHP Built-in Web Server
- **Browser Modern:** Google Chrome, Mozilla Firefox, Microsoft Edge, atau Safari

---

## Panduan Instalasi & Setup Database

### 1. Clone atau Ekstrak Proyek
Pastikan direktori proyek berada di direktori web server Anda, misalnya:
`C:\xampp\htdocs\projek_sia` atau folder kerja Anda:
```bash
cd "C:\Users\Hype AMD\projek SIA"
```

### 2. Buat Database MySQL/MariaDB
Buka terminal / command prompt atau aplikasi client MySQL (HeidiSQL / phpMyAdmin), lalu buat database:
```sql
CREATE DATABASE IF NOT EXISTS projek_sia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 3. Impor Skema & Data Seed Demo

Gunakan MySQL Command Line Client:
```bash
# Impor struktur 72 tabel
mysql -u root -p projek_sia < "database/schema.sql"

# Impor data awal dan pengguna demo
mysql -u root -p projek_sia < "database/seed_demo.sql"
```

*(Jika menggunakan user root tanpa password default XAMPP, hapus opsi `-p`)*:
```powershell
& "C:\xampp\mysql\bin\mysql.exe" -u root projek_sia -e "source database/schema.sql"
& "C:\xampp\mysql\bin\mysql.exe" -u root projek_sia -e "source database/seed_demo.sql"
```

### 4. Konfigurasi Koneksi Database
Periksa berkas konfigurasi di `app/config/database.php`:
```php
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'projek_sia');
define('DB_USER', 'root');
define('DB_PASS', '');
```
Sesuaikan `DB_USER` dan `DB_PASS` jika database Anda menggunakan kata sandi khusus.

### 5. Jalankan Aplikasi
Anda dapat menjalankannya langsung menggunakan PHP Built-in Server:
```bash
php -S localhost:8000 -t public
```
Kemudian buka browser di: `http://localhost:8000`

Jika menggunakan XAMPP Apache, pastikan DocumentRoot atau Alias mengarah ke subfolder `/public`.

---

## Kredensial Akun Pengguna Demo

Semua akun demo telah disediakan dengan kata sandi bawaan:
> **Kata Sandi Default:** `password` *(atau alternatif: `admin123`)*

Tersedia akun dengan nama peran teknis maupun alias bahasa Indonesia:

| No | Username | Alias Username | Peran (Role) | Ruang Lingkup Wewenang |
|:---|:---|:---|:---|:---|
| 1 | `admin` | - | Administrator Sistem | Konfigurasi sistem penuh, manajemen pengguna, audit log |
| 2 | `gudang` | - | Gudang & Logistik | Penerimaan bahan, stok opname, mutasi gudang, surat jalan |
| 3 | `purchasing` | `pembelian` | Pengadaan (Purchasing) | Purchase Requisition (PR), Purchase Order (PO), data supplier |
| 4 | `ppic` | `produksi` | Produksi & PPIC | Rencana produksi, BOM, SPK / Production Order, biaya produksi |
| 5 | `qc` | - | Quality Control (QC) | Inspeksi penerimaan barang, uji kualitas produksi, scrap record |
| 6 | `penjualan` | - | Penjualan & Piutang (AR) | Customer Order, Sales Order, Faktur Penjualan, nota kredit |
| 7 | `finance` | - | Keuangan & Akuntansi | 3-Way Match, faktur pembelian, AP Voucher, pembayaran, jurnal GL |
| 8 | `hr` | - | HR & Payroll | Karyawan, absensi, cuti & lembur, proses gaji & slip gaji |
| 9 | `manager` | `manajer` | Supervisor / Manajer | Verifikasi & approval lintas modul (PR, PO, Credit Limit, Gaji) |

---

## Arsitektur Database & Konvensi Teknis

1. **Storage Engine & Charset:** Seluruh 72 tabel menggunakan engine `InnoDB` dengan charset `utf8mb4` dan collation `utf8mb4_unicode_ci` untuk menjamin transaksi ACID dan kompatibilitas karakter internasional.
2. **Presisi Finansial & Kuantitas:**
   - Nilai uang, subtotal, pajak, dan kuantitas unit diskrit menggunakan `DECIMAL(15,2)`.
   - Rasio bahan baku pada Bill of Materials (BOM) dan formulasi kimia/material menggunakan `DECIMAL(15,4)` untuk akurasi tinggi.
3. **Standarisasi Tanggal:**
   - Tanggal kalender bisnis (faktur, jatuh tempo, periode) menggunakan tipe `DATE`.
   - Jejak audit dan pencatatan transaksi waktu nyata menggunakan `DATETIME` (`CURRENT_TIMESTAMP`).
4. **Audit Trail Otomatis:**
   - Tabel operasional utama memiliki kolom `created_at`, `created_by`, `updated_at`, dan `updated_by` yang terhubung ke tabel `users`.
5. **Format Status (ENUM):**
   - Nilai ENUM berstandar huruf kecil dengan pemisah garis bawah (snake_case), seperti `'draft'`, `'submitted'`, `'approved'`, `'rejected'`, `'completed'`, `'cancelled'`.
6. **Integritas Referensial (Foreign Keys):**
   - 203 foreign key constraints aktif dengan relasi `ON DELETE RESTRICT` pada data master dan transaksi penting untuk mencegah penghapusan tanpa sengaja, serta `ON DELETE CASCADE` pada baris rincian item (*detail lines*).

---

## Katalog 72 Tabel Database (Data Dictionary)

Skema database terbagi secara modular ke dalam 9 kelompok fungsional:

### Kelompok 1: Pengguna, Otorisasi, Persetujuan & Struktur Organisasi (6 Tabel)
1. `roles`: Master peran pengguna sistem dan wewenang operasional.
2. `users`: Akun pengguna sistem lengkap dengan role, email, status aktif, dan hash password bcrypt.
3. `audit_logs`: Rekam jejak seluruh mutasi data, login, dan aksi pengguna dalam sistem.
4. `approvals`: Riwayat persetujuan berjenjang multi-dokumen (PR, PO, SO, Credit Override, Payroll).
5. `departments`: Master departemen internal perusahaan (Produksi, Gudang, QC, Keuangan, dll.).
6. `positions`: Master formasi jabatan struktural dan fungsional di setiap departemen.

### Kelompok 2: Master Barang, Pergudangan & Mitra Bisnis (10 Tabel)
7. `item_categories`: Kategori klasifikasi barang (Bahan Baku, Penolong, WIP, Barang Jadi).
8. `units`: Satuan ukuran stok barang (PCS, KG, LTR, MTR, BOX, SAK).
9. `items`: Master data produk, bahan mentah, spesifikasi, harga beli/jual standar, dan batas minimum stok.
10. `warehouses`: Master lokasi gudang fisik dan fungsional penyimpanan stok.
11. `stock_balances`: Posisi saldo persediaan terkini per barang per gudang penyimpanan.
12. `stock_movements`: Kartu stok historis untuk setiap perpindahan masuk, keluar, dan penyesuaian.
13. `customers`: Master data pelanggan distributor/toko, syarat pembayaran (*payment terms*), dan batas plafon kredit.
14. `suppliers`: Master data vendor/pemasok bahan baku dan jasa operasional.
15. `boms`: Master formula produksi (*Bill of Materials*) produk jadi dan produk antara.
16. `bom_items`: Rincian formula bahan baku, kuantitas kebutuhan per unit, dan toleransi susut (*scrap rate*).

### Kelompok 3: Anggaran & Data Pegawai (3 Tabel)
17. `employees`: Profil lengkap pegawai, NIK, jabatan, status kepegawaian, dan informasi rekening bank.
18. `budgets`: Header master rencana anggaran operasional dan belanja modal tahunan/departemen.
19. `budget_lines`: Rincian pos alokasi anggaran per akun GL beserta realisasi pemakaian biaya.

### Kelompok 4: Penjualan, Distribusi & Piutang Dagang (16 Tabel)
20. `customer_orders`: Pesanan awal dari pelanggan sebelum validasi kredit atau komitmen jadwal.
21. `customer_order_items`: Rincian item barang dan harga pesanan pelanggan.
22. `credit_overrides`: Pengajuan dan persetujuan khusus manajer saat pesanan melampaui limit kredit pelanggan.
23. `sales_orders`: Surat Pesanan Penjualan resmi (*Sales Order* / SO) yang telah disetujui.
24. `sales_order_items`: Rincian item barang dan diskon pada Sales Order.
25. `delivery_orders`: Surat Jalan / *Delivery Order* (DO) untuk otorisasi pengiriman armada gudang.
26. `delivery_order_items`: Rincian barang fisik yang dikeluarkan dan dikirim ke pelanggan.
27. `delivery_proofs`: Dokumentasi bukti tanda terima barang di lokasi pelanggan (resi / foto / tanda tangan).
28. `sales_invoices`: Faktur tagihan penjualan dagang (*AR Invoice*) dengan perhitungan PPN dan jatuh tempo.
29. `sales_invoice_items`: Rincian item barang pada faktur tagihan penjualan.
30. `collection_reminders`: Rekam pengingat penagihan piutang dan eskalasi jatuh tempo faktur.
31. `customer_receipts`: Bukti penerimaan pembayaran kas/bank dari pelanggan atas piutang usaha.
32. `customer_receipt_allocations`: Alokasi nominal penerimaan kas terhadap satu atau beberapa nomor faktur penjualan.
33. `customer_returns`: Dokumen retur barang rusak/salah dari pelanggan (*Sales Return*).
34. `customer_return_items`: Rincian item dan kuantitas barang retur pelanggan.
35. `credit_notes`: Nota kredit untuk pengurangan saldo piutang pelanggan atas retur atau koreksi harga.

### Kelompok 5: Manufaktur, Produksi & Pengendalian Kualitas (9 Tabel)
36. `production_orders`: Perintah Kerja Produksi (*Work Order* / SPK) dengan target jadwal dan kuantitas.
37. `production_materials`: Kebutuhan alokasi bahan baku untuk setiap perintah kerja produksi.
38. `material_issues`: Bukti pengeluaran bahan baku dari gudang ke lantai produksi (*Job Issue*).
39. `material_issue_items`: Rincian item dan volume bahan mentah yang diserahkan ke jalur perakitan.
40. `machine_logs`: Catatan jam kerja mesin (*machine running hours*), pemeliharaan, dan waktu henti (*downtime*).
41. `qc_inspections`: Hasil pemeriksaan laboratorium QC mutu (bahan datang, *in-process*, dan barang jadi).
42. `production_outputs`: Laporan penyelesaian produksi barang jadi yang siap dipindahkan ke gudang.
43. `scrap_records`: Pencatatan sisa bahan terbuang atau barang gagal lolos mutu (*reject*).
44. `production_costs`: Rekapitulasi biaya pokok produksi (Bahan Langsung, Tenaga Kerja Langsung, Overhead Pabrik).

### Kelompok 6: Pengadaan, Penerimaan Barang, Hutang & Pembayaran (14 Tabel)
45. `purchase_requisitions`: Surat Permintaan Pembelian barang/jasa dari departemen pemohon (*PR*).
46. `purchase_requisition_items`: Rincian item barang dan taksiran tanggal kebutuhan pada PR.
47. `purchase_orders`: Dokumen Kontrak / Pesanan Pembelian resmi ke pemasok (*PO*).
48. `purchase_order_items`: Rincian kuantitas, harga satuan neto, dan pajak pada PO.
49. `goods_receipts`: Laporan Penerimaan Barang di gudang dari vendor (*Goods Receipt Note* / GRN).
50. `goods_receipt_items`: Rincian fisik barang yang diterima, nomor batch produksi, dan gudang tujuan.
51. `supplier_returns`: Dokumen pengembalian barang cacat/tidak sesuai spesifikasi ke pemasok.
52. `supplier_return_items`: Rincian item barang yang dikembalikan ke vendor.
53. `supplier_invoices`: Faktur tagihan hutang dagang dari pemasok (*AP Invoice*).
54. `three_way_matches`: Rekonsiliasi pencocokan 3 arah antara PO, GRN gudang, dan Faktur Supplier.
55. `ap_vouchers`: Voucher pengakuan hutang dagang (*Accounts Payable Voucher*) setelah verifikasi cocok.
56. `payment_requests`: Pengajuan pencairan kas/bank untuk pembayaran faktur vendor yang disetujui.
57. `supplier_payments`: Bukti pengeluaran kas/bank untuk pelunasan hutang ke pemasok.
58. `supplier_payment_allocations`: Rincian alokasi pembayaran terhadap nomor faktur / AP Voucher pemasok.

### Kelompok 7: Sumber Daya Manusia & Penggajian Pegawai (9 Tabel)
59. `attendance_records`: Data rekapitulasi kehadiran, jam masuk, jam pulang, dan status presensi pegawai.
60. `leave_overtime_requests`: Pengajuan izin, cuti tahunan/sakit, dan surat tugas lembur (*SPKL*).
61. `employee_change_requests`: Pengajuan mutasi, promosi jabatan, penyesuaian gaji pokok, atau perubahan departemen.
62. `payroll_components`: Master komponen penggajian (Gaji Pokok, Tunjangan Jabatan, Uang Makan, Lembur, BPJS, PPh21).
63. `payroll_periods`: Master penetapan periode siklus penggajian bulanan.
64. `payroll_records`: Rekapitulasi nilai gaji bruto, total potongan, dan gaji bersih (*take-home pay*) per karyawan.
65. `payroll_record_components`: Rincian rincian komponen pendapatan dan potongan untuk setiap lembar penggajian karyawan.
66. `payroll_approvals`: Jejak persetujuan berjenjang rekapitulasi penggajian sebelum dana ditransfer.
67. `payslips`: Dokumen cetak slip gaji karyawan beserta nomor referensi unik slip.

### Kelompok 8: Buku Besar, Bagan Akun & Jurnal Finansial (4 Tabel)
68. `accounts`: Master Bagan Akun Standar / *Chart of Accounts* (Aset, Kewajiban, Ekuitas, Pendapatan, Beban).
69. `account_mappings`: Konfigurasi pemetaan otomatis akun debit/kredit untuk setiap jenis modul transaksi bisnis.
70. `journal_entries`: Header transaksi entri jurnal umum dan jurnal penyesuaian (*General Journal*).
71. `journal_entry_lines`: Baris rincian posting jurnal akuntansi dengan validasi saldo debit dan kredit berimbang.

### Kelompok 9: Keamanan & Web Token (1 Tabel)
72. `csrf_tokens`: Penyimpanan token anti *Cross-Site Request Forgery* (CSRF) per sesi pengguna web.

---

## Alur Siklus Bisnis Utama

### 1. Siklus Pengadaan (Procure-to-Pay / P2P)
1. **Kebutuhan Pengadaan:** Staf gudang/departemen membuat *Purchase Requisition* (PR).
2. **Persetujuan & Pembuatan PO:** Manajer menyetujui PR; bagian Purchasing menerbitkan *Purchase Order* (PO) kepada supplier.
3. **Penerimaan Fisik:** Supplier mengirimkan barang; staf gudang memeriksa fisik dan mencatat *Goods Receipt* (GR).
4. **Verifikasi Mutu:** Bagian QC melakukan inspeksi barang masuk pada tabel `qc_inspections`.
5. **Verifikasi 3 Arah (3-Way Match):** Bagian Finance membandingkan kuantitas dan harga antara PO, GR, dan Invoice Supplier.
6. **Voucher & Pembayaran:** Finance menerbitkan *AP Voucher*, mengajukan *Payment Request*, dan mengeksekusi *Supplier Payment* dengan posting jurnal otomatis.

### 2. Siklus Manufaktur (Production Cycle)
1. **Formula Produk (BOM):** Bagian teknik/PPIC menyusun daftar kebutuhan bahan pada tabel `boms` dan `bom_items`.
2. **Perintah Kerja (SPK):** Diterbitkan *Production Order* berdasarkan target produksi atau pesanan pelanggan.
3. **Pengeluaran Bahan Baku:** Staf gudang menyerahkan bahan baku melalui *Material Issue* yang secara otomatis mengurangi saldo stok bahan di tabel `stock_balances`.
4. **Eksekusi & Mutu:** Operator mencatat *Machine Log*, dan QC melakukan inspeksi berkala selama proses manufaktur.
5. **Barang Jadi & Biaya:** Produk jadi dicatat di `production_outputs` dan masuk ke stok gudang barang jadi. Sistem menghitung biaya produksi total di `production_costs`.

### 3. Siklus Penjualan (Order-to-Cash / O2C)
1. **Pesanan Penjualan:** Sales menginput *Customer Order* / *Sales Order* (SO).
2. **Kontrol Plafon Kredit:** Jika pesanan melampaui limit kredit pelanggan atau terdapat faktur jatuh tempo, sistem meminta otorisasi manajer melalui *Credit Override*.
3. **Pengiriman Barang:** Gudang menerbitkan *Delivery Order* (DO) dan mengurangi stok barang jadi.
4. **Faktur & Penagihan:** Finance mencetak *Sales Invoice* (Faktur Penjualan) dengan batas tanggal jatuh tempo.
5. **Penerimaan Piutang:** Kasir mencatat bukti pembayaran pelanggan (*Customer Receipt*) dan mengalokasikannya ke faktur yang bersangkutan.

---

## Struktur Direktori Proyek

```text
projek SIA/
├── app/
│   ├── config/
│   │   └── database.php              # Konfigurasi koneksi database PDO
│   ├── includes/
│   │   ├── auth.php                  # Otentikasi, otorisasi RBAC, logAudit
│   │   ├── csrf.php                  # Proteksi token CSRF
│   │   ├── footer.php                # Template footer UI
│   │   ├── header.php                # Template navbar dan navigasi atas
│   │   ├── helpers.php               # Helper formatting (rupiah, status badge, pagination)
│   │   └── sidebar.php               # Menu samping navigasi 10 kategori PRD
│   ├── modules/
│   │   ├── auth/                     # Layar masuk (login) dan keluar (logout)
│   │   ├── dashboard/                # Ringkasan KPI dan matriks operasional
│   │   ├── receiving_sales/          # 1. Penerimaan & Penjualan (SO, DO, Invoices, AR, Receipts, Returns)
│   │   ├── production/               # 2. Produksi (SPK, Material Issues, Machine Logs, QC, Outputs, HPP)
│   │   ├── expenditure_procurement/  # 3. Pengeluaran (PR, PO, Goods Receipt, AP Invoices, Payments)
│   │   ├── hr_payroll/               # 4. SDM & Penggajian (Employees, Attendance, Cuti/Lembur, Payroll, Slip)
│   │   ├── master_data/              # Data Master (Items, Customers, Suppliers, Warehouses, BOM, COA)
│   │   ├── inventory/                # Persediaan (Saldo Stok, Kartu Mutasi, Stock Opname / Penyesuaian)
│   │   ├── reports/                  # Laporan & Audit (Laporan 4 Siklus, Jurnal Umum GL, Audit Trail)
│   │   └── users/                    # Administrasi (User Management & Hak Akses RBAC)
│   └── services/
│       ├── approval_service.php      # Engine persetujuan berjenjang
│       ├── inventory_service.php     # Mutasi dan validasi stok realtime (row locking, anti negative stock)
│       └── journal_service.php       # Generator otomatis entri jurnal GL berimbang (double-entry)
├── database/
│   ├── schema.sql                    # DDL 72 Tabel, 203 relasi FK, dan indeks InnoDB
│   └── seed_demo.sql                 # DML Data awal, 12 akun pengguna, master data & transaksi demo
├── public/
│   ├── css/
│   │   └── style.css                 # Lembar gaya CSS responsif murni (Pure CSS, tanpa JavaScript)
│   └── index.php                     # Front Controller & Router aplikasi
├── tests/
│   └── smoke_test.php                # Pengujian integrasi otomatis & linting 70 berkas PHP
└── README.md                         # Dokumentasi lengkap sistem
```

---

## Pengujian Sistem Otomatis (Integration Smoke Test)
Untuk memverifikasi keutuhan skema database, validasi atomic row-locking pada mutasi stok, penegakan prinsip jurnal akuntansi berimbang (Debit = Kredit), serta memastikan seluruh 58 rute modul sistem tersedia bebas dari kesalahan sintaksis, jalankan skrip pengujian:

```bash
C:\xampp\php\php.exe -f "tests/smoke_test.php"
```

Output pengujian memvalidasi:
- Koneksi database MariaDB / MySQL.
- Keberadaan 28 tabel transaksi dan master data.
- Atomic stock adjustment dan pencegahan stok minus pada `InventoryService`.
- Penolakan jurnal tidak seimbang pada `JournalService`.
- Pencatatan log aktivitas pada tabel `audit_logs`.
- Keberadaan dan sintaksis dari 70 berkas PHP dalam sistem (0 error).

---

## Lisensi & Hak Penggunaan
Sistem Informasi Akuntansi & Operasional Manufaktur dan Distribusi ini dikembangkan sebagai solusi perangkat lunak terintegrasi untuk mendukung otomasi tata kelola rantai pasok dan pelaporan keuangan perusahaan.



tes Konek github ges- Dimas