# PANDUAN LENGKAP PENGOPERASIAN SELURUH SIKLUS BISNIS
## Sistem Informasi Akuntansi & Operasional PT Mayora Indah Tbk

Panduan praktis langkah demi langkah untuk menjalankan dan mensimulasikan seluruh siklus operasional bisnis pada Sistem Informasi Akuntansi (SIA) PT Mayora Indah Tbk.

---

### DAFTAR ISI
1. [Prasyarat & Kredensial Akun Pengguna](#1-prasyarat--kredensial-akun-pengguna)
2. [Siklus 1: Siklus Pengeluaran & Pengadaan (Procure-to-Pay / P2P)](#2-siklus-1-siklus-pengeluaran--pengadaan-procure-to-pay--p2p)
3. [Siklus 2: Siklus Produksi & Pabrikasi (Manufacturing / Production Cycle)](#3-siklus-2-siklus-produksi--pabrikasi-manufacturing--production-cycle)
4. [Siklus 3: Siklus Penerimaan & Penjualan (Order-to-Cash / O2C)](#4-siklus-3-siklus-penerimaan--penjualan-order-to-cash--o2c)
5. [Siklus 4: Siklus SDM & Penggajian (HR & Payroll Cycle)](#5-siklus-4-siklus-sdm--penggajian-hr--payroll-cycle)
6. [Fitur Pengawasan Mutu & Ketertelusuran Pangan (Traceability & FEFO)](#6-fitur-pengawasan-mutu--ketertelusuran-pangan-traceability--fefo)
7. [Pemeriksaan Keseimbangan Akuntansi & Buku Jurnal Umum (GL)](#7-pemeriksaan-keseimbangan-akuntansi--buku-jurnal-umum-gl)

---

### 1. Prasyarat & Kredensial Akun Pengguna

#### Menjalankan Web Server & Database
Pastikan database MySQL dan PHP aktif (misal melalui XAMPP):
```powershell
# Jalankan PHP server di folder projek
php -S localhost:8000 -t public
```
Akses sistem melalui browser: **`http://localhost:8000`**

#### Kredensial Login 9 Role Pengguna
Semua akun demo memiliki kata sandi bawaan: **`password`** (atau alternatif: **`admin123`**).

| No | Username | Peran (Role) | Wewenang Utama | Rekomendasi Alur |
|:---|:---|:---|:---|:---|
| 1 | **`admin`** | **Administrator** | **Akses Penuh Lintas Siklus** (bisa menjalankan semua modul tanpa ganti login) | *Paling direkomendasikan untuk demo cepat* |
| 2 | `purchasing` | Staff Pembelian | PR, PO, Master Supplier | Siklus Pengeluaran |
| 3 | `ppic` | Produksi & PPIC | MPS, MRP, SPK, Move Tickets, Job Tickets, Catat Output | Siklus Produksi |
| 4 | `gudang` | Petugas Gudang | Penerimaan GR, Pengeluaran Bahan (MR), Saldo Stok, FEFO | Pengeluaran, Produksi, Penjualan |
| 5 | `qc` | Quality Control | QC Incoming, QC Proses, QC Akhir, Analisis Scrap | Pengeluaran & Produksi |
| 6 | `penjualan` | Staff Penjualan | Sales Order (SO), Surat Jalan (DO), Faktur Penjualan | Siklus Penerimaan |
| 7 | `finance` | Keuangan / Kasir | 3-Way Matching, Faktur Beli/Jual, Kas Keluar/Masuk, Jurnal GL | Pengeluaran & Penerimaan |
| 8 | `hr` | HR & Payroll | Karyawan, Presensi, Cuti, Lembur, Hitung Payroll, Slip Gaji | Siklus SDM & Penggajian |
| 9 | `manager` | Manajer Operasional | Approval PR, PO, SPK, Credit Limit, Mutasi & Penggajian | Otorisasi Semua Siklus |

---

### 2. Siklus 1: Siklus Pengeluaran & Pengadaan (Procure-to-Pay / P2P)

Alur lengkap: **Perencanaan Kebutuhan $\rightarrow$ PR $\rightarrow$ Otorisasi $\rightarrow$ PO $\rightarrow$ Penerimaan Barang (GR) $\rightarrow$ QC Incoming $\rightarrow$ Retur & Nota Debit (jika cacat) $\rightarrow$ 3-Way Matching Faktur $\rightarrow$ Pembayaran Kas Pemasok**.

```
[1. Buat PR] ──> [2. Setujui PR] ──> [3. Buat PO Vendor] ──> [4. Penerimaan GR (Gudang)]
                                                                           │
[8. Jurnal & Kas] <── [7. Pelunasan Kas] <── [6. 3-Way Matching Faktur] <── [5. QC Incoming]
                                                        │                         │
                                                        └──── [Nota Debit / Retur] ┘
```

#### Langkah 2.1: Buat Permintaan Pembelian (Purchase Requisition - PR)
1. Login sebagai `purchasing` (atau `admin`).
2. Masuk ke menu **Siklus Pengeluaran** $\rightarrow$ **Permintaan Pembelian (PR)** (`index.php?page=expenditure_procurement/pr`).
3. Klik tombol merah **+ Buat Permintaan Baru**.
4. Pilih Departemen Pemohon (contoh: *Departemen Produksi*).
5. Tambahkan baris bahan baku (misal: *Tepung Terigu Protein Sedang Bogasari*, Kuantitas: `1000` Kg).
6. Masukkan estimasi tanggal kebutuhan dan alasan keperluan, lalu klik **Simpan Permintaan**.
7. Di halaman detail PR, klik **Ajukan Otorisasi (Submit for Approval)**. Status berubah menjadi `pending_approval`.

#### Langkah 2.2: Otorisasi PR oleh Manajer
1. Login sebagai `manager` (atau `admin`).
2. Buka menu **Antrean Dokumen Approval** di dashboard atau buka detail PR yang bersangkutan.
3. Klik tombol hijau **Setujui PR (Approve)**. Status berubah menjadi `approved`.

#### Langkah 2.3: Pembuatan Pesanan Pembelian ke Vendor (Purchase Order - PO)
1. Login kembali sebagai `purchasing` (atau `admin`).
2. Masuk ke menu **Pesanan Pembelian (PO)** (`index.php?page=expenditure_procurement/po`).
3. Klik **+ Buat PO Baru**.
4. Pilih nomor PR yang telah disetujui, pilih pemasok (contoh: *PT Bogasari Flour Mills*).
5. Masukkan termin pembayaran (contoh: *30 hari*), periksa harga satuan, lalu klik **Simpan Pesanan**.
6. Klik tombol **Setujui & Terbitkan PO**. Status PO menjadi `approved` (terbuka).

#### Langkah 2.4: Penerimaan Fisik Barang di Gudang (Goods Receipt - GR)
1. Login sebagai `gudang` (atau `admin`).
2. Buka menu **Penerimaan Barang (GR)** (`index.php?page=expenditure_procurement/gr`).
3. Klik **+ Catat Penerimaan Barang**.
4. Pilih nomor PO yang bersangkutan.
5. Masukkan nomor Surat Jalan Pemasok (*DO Vendor*), gudang tujuan (*Gudang Bahan Baku Utama Tangerang*), nomor lot/batch vendor, dan tanggal kedaluwarsa bahan.
6. Masukkan jumlah fisik yang diterima, lalu klik **Simpan Penerimaan**. Status GR menjadi `received`.

#### Langkah 2.5: Inspeksi Mutu QC Masuk & Retur Pemasok (Bila Ditemukan Cacat)
1. Login sebagai `qc` (atau `admin`).
2. Masuk ke detail GR atau menu **Pengendalian Mutu** $\rightarrow$ **Inspeksi QC**.
3. Jika barang lolos uji: Isi jumlah diterima penuh (`qty_accepted`).
4. **Jika barang cacat (simulasi retur & nota debit):**
   - Catat kuantitas ditolak pada `qty_rejected` (misal: 50 Kg karena kemasan sobek / kadar air tinggi).
   - Masuk ke menu **Siklus Pengeluaran** $\rightarrow$ **Retur Pemasok & Nota Debit** (`index.php?page=expenditure_procurement/returns`).
   - Buat dokumen retur dengan memilih nomor GR terkait.
   - Klik **Terbitkan Nota Debit (Debit Memo)**.
   - **Otomasi Sistem:** Nilai utang ke vendor dipotong otomatis, dan sistem menjurnal pembalik akun Utang Usaha (2100) ke Persediaan Bahan Baku (1310).

#### Langkah 2.6: Verifikasi Faktur & Three-Way Matching
1. Login sebagai `finance` (atau `admin`).
2. Masuk ke menu **Faktur Pembelian** (`index.php?page=expenditure_procurement/invoices`).
3. Klik **+ Catat Faktur Pemasok**, masukkan nomor invoice dari supplier, pilih nomor PO dan nomor GR terkait.
4. Buka halaman detail faktur (`expenditure_procurement/invoice_detail`).
5. Sistem menyajikan tabel **Three-Way Matching (Pencocokan 3 Dokumen: PO vs GR vs Invoice)**.
6. Klik tombol **Verifikasi & Sahkan Faktur**. Utang Usaha (AP) resmi tercatat di pembukuan.

#### Langkah 2.7: Pembayaran Kas & Pelunasan Utang Vendor
1. Di halaman **Pembayaran Pemasok** (`index.php?page=expenditure_procurement/payments`), klik **+ Pembayaran Baru**.
2. Pilih vendor dan faktur yang ingin dilunasi.
3. Pilih metode pembayaran (Kas Bank BCA Operasional / Transfer).
4. Klik **Proses Pembayaran**.
5. **Verifikasi:** Saldo utang faktur menjadi Rp 0 (Lunas), dan voucher jurnal otomatis mencatat Kas/Bank (1110) di Kredit dan Utang Usaha (2100) di Debit.

---

### 3. Siklus 2: Siklus Produksi & Pabrikasi (Manufacturing / Production Cycle)

Alur lengkap: **Rencana MPS $\rightarrow$ Kalkulator MRP $\rightarrow$ SPK Baru $\rightarrow$ Approval $\rightarrow$ Pengeluaran Bahan (MR) $\rightarrow$ Lantai Produksi (Tiket & Jam Kerja) $\rightarrow$ Output & Scrap $\rightarrow$ QC Akhir $\rightarrow$ Otomasi Batch & FEFO**.

```
[1. MPS / Target] ──> [2. Hitung MRP] ──> [3. Buat SPK] ──> [4. Otorisasi SPK]
                                                                   │
[8. Batch & Stok Jadi] <── [7. QC Akhir] <── [6. Catat Output] <── [5. Keluar Bahan (MR)]
```

#### Langkah 3.1: Perencanaan Jadwal Induk Produksi (MPS)
1. Login sebagai `ppic` (atau `admin`).
2. Masuk ke menu **Siklus Produksi** $\rightarrow$ **Jadwal Induk (MPS)** (`index.php?page=production/mps`).
3. Tinjau alokasi target produksi produk F&B Mayora bulanan (misal: *Roma Biskuit Kelapa 300g*, *Beng-Beng Regular*, *Torabika Duo*).

#### Langkah 3.2: Analisis Kebutuhan Material (Kalkulator MRP & Auto-PR)
1. Buka menu **Kalkulator MRP** (`index.php?page=production/mrp`).
2. Pilih produk jadi (contoh: *Roma Kelapa 300g*) dan masukkan kuantitas rencana (contoh: `500` Dus).
3. Klik **Hitung Kebutuhan (Run MRP)**.
4. Sistem memecah formula Bill of Materials (BOM) dan membandingkan stok fisik di gudang vs kebutuhan produksi.
5. Jika ada bahan yang kurang (*net deficit*), klik tombol **Buat PR Pengadaan Otomatis** untuk langsung mengalirkan kebutuhan ke siklus pengadaan.

#### Langkah 3.3: Pembuatan Perintah Produksi / SPK (Production Order)
1. Masuk ke menu **Perintah Produksi (SPK)** (`index.php?page=production/orders`).
2. Klik tombol merah **+ Buat SPK Baru**.
3. Isi data formulir:
   - **Produk Jadi**: Pilih produk Mayora (misal: *Roma Biskuit Kelapa 300g*).
   - **BOM Resep**: Pilih resep standar aktif produk tersebut.
   - **Jalur Operasi (Routing)**: Pilih lini mesin produksi (misal: *Lini Biskuit Roma #1 Tangerang*).
   - **Target Jumlah**: Masukkan jumlah target (contoh: `200` Dus).
   - **Jadwal**: Tentukan tanggal mulai dan target selesai.
4. Klik **Simpan SPK**. Status SPK tersimpan sebagai `draft`.
5. Di halaman detail SPK, klik tombol **Ajukan Persetujuan**. Status menjadi `pending_approval`.

#### Langkah 3.4: Otorisasi SPK oleh Manajer
1. Login sebagai `manager` (atau `admin`).
2. Buka detail SPK tersebut (bisa melalui widget notifikasi topbar atau antrean approval di dashboard).
3. Klik tombol hijau **Setujui SPK (Approve)**. Status berubah menjadi `approved`.

#### Langkah 3.5: Otorisasi & Pengeluaran Bahan Baku dari Gudang (Material Requisition - MR)
1. Login sebagai `gudang` (atau `admin`).
2. Di halaman detail SPK, klik tab **Bahan Baku** lalu klik **Buat Permintaan Pengeluaran Bahan (MR)**.
3. Masuk ke modul **Permintaan Bahan (MR)** (`index.php?page=production/material_requisitions`).
4. Klik nomor MR yang bersangkutan, lalu klik tombol **Keluarkan Bahan dari Gudang (Issue Materials)**.
5. **Otomasi Sistem:**
   - Saldo fisik stok bahan baku (tepung, gula rafinasi, minyak nabati, karton packaging) otomatis berkurang di gudang.
   - Kartu stok (*stock movements*) mencatat mutasi pengeluaran bahan untuk SPK tersebut.

#### Langkah 3.6: Eksekusi Lantai Pabrik (Mulai SPK, Move Tickets & Job-Time Tickets)
1. Login sebagai `ppic` (atau `admin`).
2. Buka halaman detail SPK (`index.php?page=production/order_detail&id=...`).
3. Klik tombol **Mulai Produksi (Start Production)**. Status SPK berubah menjadi `in_progress`.
4. Di bagian bawah detail SPK, Anda dapat mencatat riwayat operasional nyata:
   - **Move Tickets (Perpindahan Antar Stasiun)**: Klik *+ Catat Perpindahan Stasiun*, masukkan lot yang berpindah dari Stasiun Pengadukan (*Mixing*) ke Pemanggangan (*Baking*) atau Pembungkusan (*Packing*).
   - **Job-Time Tickets (Kartu Jam Kerja)**: Masukkan nama operator pelaksana, stasiun, dan jumlah jam kerja (sebagai dasar pembebanan Biaya Tenaga Kerja Langsung / BTKL).
   - **Machine Logs (Catatan Mesin)**: Catat jam operasional mesin pencetak dan oven.

#### Langkah 3.7: Pencatatan Hasil Produksi (Output & Scrap)
1. Pada detail SPK yang berstatus *in_progress*, gulir ke form **Catat Hasil Produksi (Record Output)**:
   - **Jumlah Lulus (Good Qty)**: Masukkan jumlah dus produk jadi yang sempurna (contoh: `196`).
   - **Jumlah Scrap/Afkir (Scrap Qty)**: Masukkan dus cacat/gagal kemas (contoh: `4`).
   - **Pilih Mesin**: Pilih mesin pengemasan akhir.
2. Klik tombol merah **Simpan Hasil Produksi**.
3. **Otomasi Sistem:**
   - Sistem secara otomatis menerbitkan nomor Batch/Lot baru berformat `LOT-FG-YYMM-XXXX`.
   - Menghitung tanggal kedaluwarsa 12–24 bulan ke depan secara presisi.
   - Status SPK beralih menjadi `waiting_qc` (menunggu pengujian lab mutu).

#### Langkah 3.8: Uji Kualitas Akhir (QC Inspections) & SPK Selesai
1. Login sebagai `qc` (atau `admin`).
2. Masuk ke menu **Pengendalian Mutu** $\rightarrow$ **Inspeksi QC** (`index.php?page=production/qc_inspections`).
3. Buka antrean inspeksi untuk SPK tersebut.
4. Masukkan hasil uji organoleptik/laboratorium, pilih hasil **Lulus (Pass)**, lalu klik **Simpan Inspeksi**.
5. **Otomasi Sistem:**
   - Status SPK berubah menjadi **Completed** (Selesai).
   - Saldo stok barang jadi di gudang bertambah otomatis sebesar `Good Qty`.
   - Batch terdaftar di modul **Monitoring Batch & FEFO** dengan status *Passed/Released*.

---

### 4. Siklus 3: Siklus Penerimaan & Penjualan (Order-to-Cash / O2C)

Alur lengkap: **Pesanan Penjualan (SO) $\rightarrow$ Validasi Plafon Kredit $\rightarrow$ Surat Jalan (DO) $\rightarrow$ Pengiriman Gudang $\rightarrow$ Faktur Penjualan $\rightarrow$ Penerimaan Kas / Piutang (AR)**.

```
[1. Buat Pesanan SO] ──> [2. Validasi Kredit] ──> [3. Terbitkan DO] ──> [4. Kirim Barang]
                                                                                │
[7. Jurnal Penjualan] <── [6. Pelunasan Piutang] <── [5. Faktur Penjualan] <────┘
```

#### Langkah 4.1: Pembuatan Pesanan Penjualan (Sales Order - SO)
1. Login sebagai `penjualan` (atau `admin`).
2. Masuk ke menu **Siklus Penerimaan** $\rightarrow$ **Pesanan Penjualan (SO)** (`index.php?page=receiving_sales/sales_orders`).
3. Klik tombol merah **+ Buat Pesanan Baru**.
4. Pilih pelanggan distributor (misal: *PT Indomarco Prismatama* atau *PT Sumber Alfaria Trijaya Tbk*).
5. Tambahkan produk barang jadi (misal: *Roma Biskuit Kelapa 300g*, Kuantitas: `100` Dus).
6. Tentukan syarat pembayaran (*Payment Terms: 30 Hari*).
7. Klik **Simpan Pesanan**. Status SO menjadi `draft`.
8. Klik tombol **Ajukan Persetujuan**.

#### Langkah 4.2: Otorisasi SO & Pemeriksaan Limit Kredit
1. Login sebagai `manager` (atau `admin`).
2. Buka detail SO tersebut. Sistem mengecek batas plafon kredit pelanggan (*credit limit*):
   - Jika sisa plafon cukup: Klik **Setujui Pesanan (Approve)**.
   - Jika melebihi plafon: Tinjau form *Credit Override*, masukkan catatan dispensasi manajer, lalu setujui.
3. Status SO berubah menjadi `approved` (siap dikirim).

#### Langkah 4.3: Penerbitan Surat Jalan & Pengiriman (Delivery Order - DO)
1. Login sebagai `gudang` (atau `penjualan` / `admin`).
2. Buka menu **Pengiriman Barang (DO)** (`index.php?page=receiving_sales/delivery_orders`).
3. Klik **+ Buat Surat Jalan Baru**, pilih nomor SO yang telah disetujui.
4. Masukkan nomor polisi armada truk, nama pengemudi (*driver*), dan tanggal pengiriman.
5. Periksa kuantitas barang yang dimuat ke truk.
6. Klik **Konfirmasi Pengiriman (Confirm Dispatch)**.
7. **Otomasi Sistem:**
   - Saldo fisik barang jadi di gudang berkurang seketika.
   - Kartu stok persediaan mencatat mutasi keluar untuk pengiriman pelanggan.

#### Langkah 4.4: Penerbitan Faktur Penjualan (Sales Invoice)
1. Login sebagai `finance` (atau `penjualan` / `admin`).
2. Masuk ke menu **Faktur Penjualan** (`index.php?page=receiving_sales/invoices`).
3. Klik **+ Terbitkan Faktur Baru**, pilih nomor DO yang telah dikirim.
4. Sistem otomatis menghitung subtotal, PPN 11%, dan tanggal jatuh tempo pembayaran.
5. Klik **Simpan & Terbitkan Faktur**.
6. **Otomasi Sistem:**
   - Piutang Usaha (*Accounts Receivable*) pelanggan tercatat di buku besar.
   - Sistem membukukan jurnal otomatis: Debit Piutang Usaha (1130), Kredit Penjualan Produk Jadi (4100), dan Kredit Utang PPN Keluaran (2120).

#### Langkah 4.5: Penerimaan Kas Pelanggan & Pelunasan Piutang
1. Login sebagai `finance` (atau `admin`).
2. Masuk ke menu **Penerimaan Kas (Receipts)** (`index.php?page=receiving_sales/receipts`).
3. Klik **+ Catat Penerimaan Kas**.
4. Pilih pelanggan dan nomor faktur yang hendak dilunasi.
5. Masukkan rekening bank penerima (misal: *Bank Mandiri Operasional*), nominal yang dibayar, dan nomor referensi transfer.
6. Klik **Proses Penerimaan**.
7. **Verifikasi:** Status faktur berubah menjadi `paid` (Lunas), saldo piutang pelanggan berkurang, dan jurnal kas masuk (1110) tercatat di debit secara otomatis.

---

### 5. Siklus 4: Siklus SDM & Penggajian (HR & Payroll Cycle)

Alur lengkap: **Master Data Karyawan $\rightarrow$ Presensi Harian $\rightarrow$ Pengajuan Cuti & Lembur $\rightarrow$ Mutasi/Pelatihan $\rightarrow$ Hitung Payroll Otomatis $\rightarrow$ Verifikasi Slip Gaji $\rightarrow$ Approval Manajer $\rightarrow$ Pembayaran & Jurnal Akuntansi**.

```
[1. Presensi Kerja] ──> [2. Cuti & Lembur] ──> [3. Hitung Payroll]
                                                      │
[6. Jurnal Beban Gaji] <── [5. Otorisasi Manajer] <── [4. Verifikasi Slip Gaji]
```

#### Langkah 5.1: Pengelolaan Karyawan & Presensi Kerja
1. Login sebagai `hr` (atau `admin`).
2. Masuk ke menu **SDM & Penggajian** $\rightarrow$ **Data Karyawan** (`index.php?page=hr_payroll/employees`) untuk melihat daftar tenaga kerja aktif, divisi manufaktur, dan gaji pokok.
3. Buka menu **Presensi Kerja** (`index.php?page=hr_payroll/attendance`) untuk meninjau rekaman absensi harian (hadir, sakit, izin, alpha).

#### Langkah 5.2: Pengajuan & Otorisasi Cuti atau Lembur
1. Masuk ke menu **Cuti & Lembur** (`index.php?page=hr_payroll/leave_overtime`).
2. Klik **+ Ajukan Cuti/Lembur**.
3. Pilih karyawan operator pabrik, pilih jenis permohonan (misal: *Lembur Produksi - 4 Jam*), dan masukkan alasan.
4. Klik **Simpan Permohonan**.
5. Manajer/Supervisor menyetujui permohonan tersebut. Jam lembur yang disetujui akan otomatis ditarik sebagai penambah uang lembur pada saat hitung gaji.

#### Langkah 5.3: Pemrosesan Gaji Karyawan (Payroll Process)
1. Buka menu **Proses Penggajian** (`index.php?page=hr_payroll/payroll_process`).
2. Klik **+ Buat Periode Gaji Baru** (misal: Periode Bulan Berjalan).
3. Klik tombol biru **Hitung Payroll Otomatis**.
4. **Otomasi Sistem:**
   - Sistem menghitung untuk setiap karyawan:
     $$\text{Gaji Bruto} = \text{Gaji Pokok} + \text{Tunjangan Jabatan} + \text{Uang Makan} + \text{Upah Lembur}$$
     $$\text{Potongan} = \text{Iuran BPJS Kesehatan} + \text{BPJS Ketenagakerjaan} + \text{PPh 21}$$
     $$\text{Take-Home Pay (Net)} = \text{Gaji Bruto} - \text{Total Potongan}$$
5. Status periode menjadi `calculated`.

#### Langkah 5.4: Verifikasi Slip Gaji & Persetujuan Manajer
1. Buka menu **Verifikasi Gaji** (`index.php?page=hr_payroll/salary_verification`).
2. Periksa rincian lembar gaji per karyawan. Jika akurat, klik **Validasi Lembar Gaji**.
3. Login sebagai `manager` (atau `admin`), buka antrean approval payroll, lalu klik **Setujui Periode Penggajian (Approve)**. Status menjadi `approved`.

#### Langkah 5.5: Pencairan Gaji, Cetak Slip & Jurnal Akuntansi
1. Login sebagai `finance` (atau `hr` / `admin`).
2. Di halaman penggajian, klik tombol **Bayarkan Gaji (Disburse / Mark as Paid)**.
3. Klik tombol **Cetak Slip Gaji** untuk melihat slip gaji siap cetak per karyawan.
4. **Otomasi Sistem:** Sistem menerbitkan entri jurnal umum otomatis: Debit Beban Gaji & Upah (5100/5200) dan Kredit Kas/Bank (1110) secara seimbang.

---

### 6. Fitur Pengawasan Mutu & Ketertelusuran Pangan (Traceability & FEFO)

Sistem telah dilengkapi modul khusus standar industri makanan & minuman (F&B Mayora):

#### 6.1 Monitoring Batch & FEFO (First Expired, First Out)
- Akses menu: **Gudang & Persediaan** $\rightarrow$ **Monitoring Batch & FEFO** (`index.php?page=inventory/batches`).
- **Fungsi:**
  - Memantau masa kedaluwarsa seluruh lot bahan baku dan produk jadi.
  - Menandai batch berstatus *Aman* (hijau), *Mendekati ED < 90 hari* (kuning), *Kritis < 30 hari* (oranye), atau *Expired* (merah).
  - Memastikan staf gudang mengeluarkan batch dengan tanggal kedaluwarsa paling awal terlebih dahulu (*FEFO compliance*).

#### 6.2 Ketertelusuran Silsilah Produk Pangan (Traceability)
- Akses menu: **Gudang & Persediaan** $\rightarrow$ **Ketertelusuran Produk** (`index.php?page=inventory/traceability`).
- **Fungsi:**
  - Masukkan nomor SPK (contoh: `SPK-2606-0001`) atau nomor Batch (contoh: `LOT-FG-2606-0001`).
  - Sistem menampilkan **Pohon Silsilah Lengkap**:
    $$\text{Vendor Bahan Baku} \longrightarrow \text{Lot Bahan Datang (GR)} \longrightarrow \text{Eksekusi SPK & Mesin Pabrik}$$
    $$\longrightarrow \text{Hasil Uji Lab QC} \longrightarrow \text{Batch Produk Jadi} \longrightarrow \text{Surat Jalan (DO) & Pelanggan}$$

---

### 7. Pemeriksaan Keseimbangan Akuntansi & Buku Jurnal Umum (GL)

Setiap transaksi dari ke-4 siklus operasional di atas secara otomatis menghasilkan pencatatan jurnal akuntansi berpasangan (*double-entry balancing*):

1. Masuk ke menu **Laporan & Akuntansi** $\rightarrow$ **Jurnal Umum** (`index.php?page=reports/journals`).
2. Periksa status neraca:
   - Pastikan indikator **Neraca Saldo Jurnal Seimbang (Balanced)** berwarna hijau.
   - Total Debit harus sama persis dengan Total Kredit ($\text{Debit} - \text{Kredit} = 0$).
3. Anda dapat memfilter jurnal berdasarkan nomor dokumen (PR, PO, GR, SPK, DO, Invoice, Payment) untuk mengaudit rekam jejak keuangan transaksi tersebut.

---

### 8. Pengujian Otomatis Sistem (Test Suites)

Untuk memvalidasi keutuhan sistem kapan saja, jalankan script pengujian melalui terminal:

```powershell
# 1. Smoke test integritas modul & koneksi database
& "C:\xampp\php\php.exe" "tests\smoke_test.php"

# 2. Audit keseimbangan debit-kredit jurnal dan stok fisik
& "C:\xampp\php\php.exe" "tests\test_accounting_stock_integrity.php"

# 3. Uji hak akses otorisasi 9 role pengguna (RBAC)
& "C:\xampp\php\php.exe" "tests\test_rbac_all_roles.php"

# 4. Uji tampilan visual dan elemen dashboard 9 role
& "C:\xampp\php\php.exe" "tests\test_multi_dashboard.php"
```
Seluruh pengujian di atas wajib menghasilkan status: **100% LULUS (PASSED)**.
