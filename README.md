# Reporting MRP Gracia

Aplikasi web **Laravel monolith** yang menyajikan laporan & dashboard dari database
**PostgreSQL legacy** milik sistem MRP/ERP manufaktur farmasi (dibuat dengan Delphi).

> **DB legacy diperlakukan READ-ONLY.** Aplikasi tidak pernah membuat/menjalankan
> migration, mengubah struktur, atau menulis data ke DB `gracia_mrp`. Tabel auth Laravel
> (users, sessions, cache, jobs) disimpan terpisah di **SQLite**, bukan di DB legacy.

## Tech stack
- Laravel 13, PHP 8.3, Blade + Tailwind CSS, **Livewire 4** (tabel/filter interaktif)
- Auth: **Laravel Breeze** (Blade stack, satu guard) — semua laporan di belakang login
- Export: **maatwebsite/excel** (.xlsx) & **barryvdh/laravel-dompdf** (PDF)
- Dua koneksi DB:
  - `sqlite` (default) → tabel milik Laravel saja (auth/session/cache/jobs)
  - `pgsql` → DB legacy `gracia_mrp` (READ-ONLY), env dengan prefix `PGSQL_*`

## Setup

### 1. Dependencies
```bash
composer install
npm install && npm run build
```

### 2. `.env`
Salin `.env.example` → `.env`, lalu `php artisan key:generate`.
Konfigurasi koneksi:
```env
# Tabel Laravel sendiri (auth dll) — SQLite
DB_CONNECTION=sqlite

# DB legacy MRP (PostgreSQL) — READ-ONLY, jangan commit kredensial
PGSQL_HOST=192.168.0.185
PGSQL_PORT=5432
PGSQL_DATABASE=gracia_mrp
PGSQL_USERNAME=mrp_web      # gunakan role read-only, bukan superuser postgres
PGSQL_PASSWORD=****
```
> `.env` sudah ada di `.gitignore` — jangan pernah commit kredensial.

### 3. Migrasi tabel Laravel (SQLite saja — TIDAK menyentuh DB legacy)
```bash
touch database/database.sqlite
php artisan migrate
```

### 4. Buat user login
```bash
php artisan tinker --execute="App\Models\User::firstOrCreate(['email'=>'admin@gracia.local'],['name'=>'Admin','password'=>Hash::make('rahasia')]);"
```

## Menjalankan & akses dari PC lain
Server dev di-bind ke semua interface agar bisa diakses dari komputer lain di jaringan:
```bash
php artisan serve --host=0.0.0.0 --port=8000
```
Akses: **http://192.168.0.58:8000** (IP LAN server ini).
Pastikan `APP_URL=http://192.168.0.58:8000` di `.env`, dan jika firewall aktif:
`sudo ufw allow 8000/tcp`.

Di server ini prosesnya dijaga oleh **PM2**:
`pm2 start "php artisan serve --host=0.0.0.0 --port=8000" --name mrp-app`.
Untuk produksi permanen, disarankan pindah ke **nginx + php-fpm** (lihat pola app lain di server).

## Keamanan DB — WAJIB dibaca
DB legacy saat ini diakses dengan superuser `postgres` **tanpa password** — berisiko.
Buat role **read-only** khusus aplikasi, lalu arahkan `.env` ke role tersebut:
```bash
psql -h 192.168.0.185 -U postgres -d gracia_mrp -f database/sql/create_readonly_role.sql
```
Skrip: `database/sql/create_readonly_role.sql` (ganti `'ganti_ini'` dengan password kuat
lebih dulu). Setelah itu set `PGSQL_USERNAME=mrp_web` & `PGSQL_PASSWORD=...` di `.env`.

## Daftar laporan
| Laporan | Route | Status |
|---|---|---|
| **Batch / Karantina** | `/reports/karantina` | ✅ Selesai — filter rentang Tgl. Input, pencarian (no batch/kode/nama), sortable, paginated, export Excel & PDF |
| **Monitoring Stok** | `/dashboard` (tabel utama di dashboard) | ✅ Selesai — agregasi per produk: Jumlah BR Belum Proses & Jumlah WIP; filter tanggal, pencarian (kode/nama), sortable, paginated, export Excel & PDF |
| **Monitoring Stok & Forecast** | `/reports/stok-forecast` | ✅ Selesai — agregasi per produk gabungan saldo + forecast + status batch 6 bln; pencarian (kode/nama), sortable, paginated, export Excel & PDF. **Tanpa filter tanggal** (acuan dinamis `CURRENT_DATE`) |
| **WIP dan BR Belum Proses** | `/reports/wip-belum-proses` | ✅ Selesai — detail per batch (WIP & BR Belum Proses) pd rentang tgl input; dikelompokkan per produk (Kode/Nama **bisa di-sort**), rekap total, **export Excel** (Batch Size sbg angka asli `#,##0` — 0/ribuan tak hilang) |

### Definisi kolom Monitoring Stok (dikonfirmasi user)
Agregasi **per produk** (kode + nama). Header **bertingkat & bergrup** (border pemisah): tiap
state = sepasang kolom **Jumlah + Batch Size**. Grup: Batch Belum Proses · WIP · Toll OUT ·
Batch Selesai · Total (masing-masing punya Jumlah & Batch Size).
Dihitung dari data batch (`master_batch`) — 3 state berdasarkan 2 kolom tanggal
(penyerahan = `pengeluaran_barang_master.tanggal`, karantina = `max(penerimaan_barang_master.tanggal)`):
- **Batch Belum Proses** = penyerahan **belum** terisi **dan** karantina **belum** terisi.
- **WIP** = penyerahan **sudah** terisi **dan** karantina **belum** terisi.
- **Toll OUT** = penyerahan **belum** terisi **dan** karantina **sudah** terisi.
- **Batch Selesai** = penyerahan **sudah** terisi **dan** karantina **sudah** terisi (semua kolom terisi).
- **Total** = jumlah seluruh batch produk (`COUNT(*)`).
  4 state saling lepas & lengkap: **Belum Proses + WIP + Toll OUT + Selesai = Total**.
- **Batch Size** (kolom pasangan tiap state, termasuk Batch Selesai & Total) = **total batch size**
  dari batch pada state itu = (jumlah batch pada state) × batch size per batch. `master_batch` tidak
  menyimpan batch size, jadi diambil dari `master_formula_barang_jadi.jumlah_fb` — dari **formula
  terbaru** per produk (`tanggal_formula` desc). Produk **tanpa formula** → batch size **0**.
  `jumlah_fb` bertipe `real` (float4) → di-cast ke `double precision` sebelum di-SUM agar presisi
  tidak hilang pada angka jutaan. Batch Size(Belum)+WIP+Toll+Selesai = Batch Size(Total).

> Data tidak dimuat otomatis. Atur filter tanggal/pencarian lalu klik **Tampilkan** (binding
> `wire:model` deferred) supaya query berat (~3 dtk) tidak jalan tiap ketikan.

> Catatan: DB PostgreSQL 8.4 **tidak** mendukung agregat `FILTER (…)`; hitungan kondisional
> memakai `SUM(CASE WHEN … THEN 1 ELSE 0 END)`. SQL ada di `app/Reports/MonitoringStokReport.php`.

### Definisi kolom Monitoring Stok & Forecast
Agregasi **per produk** (`master_barang`). Label header diperbarui (SQL di `app/Reports/StokForecastReport.php`;
grup 1-kolom seperti **FORECAST** dirender sbg satu sel merged/rowspan):
- **FORECAST** — `SUM(setting_forecast.jumlah)` bulan berjalan (`disable_date` NULL).
- **PENGELUARAN** — *Keluar* (`SUM(surat_jalan_detail.jumlah)` bulan berjalan) · *Realisasi* =
  Keluar/Forecast×100 (Forecast 0 → `NULL`; PostgreSQL 8.4 tak punya `round(double,int)` → di-cast `::numeric`).
- **STOK SAAT INI** — dari saldo SL terbaru (`saldo_sl_barang`, baris `tgl_saldo` terakhir per seq,
  **tanpa batas atas tanggal**): *ED ≤ 9 Bln* ·
  *ED ≥ 9 Bln* (batas `CURRENT_DATE + 9 months`; teknis: <9 vs ≥9) · *Total* (`SUM(jumlah)`) ·
  <br>⚠️ Saldo SL di-posting per batas periode (awal bulan) & sebagian stok/karantina terbaru bertanggal
  periode **berikutnya** (mis. `tgl_saldo` awal bulan depan). Karena itu saldo diambil per snapshot
  **terbaru per seq tanpa filter `<= CURRENT_DATE`** (DISTINCT ON tetap 1 baris/seq → tak ada penggandaan);
  membatasi ke hari ini akan menyembunyikan stok/karantina yang sebenarnya sudah ada sekarang.
  *DOI* = (Total / Forecast) × 30 = perkiraan hari stok bertahan (days of inventory); Forecast 0 → 0.
  **Universe produk** = punya saldo (`net = jumlah + karantina − holding <> 0`) **ATAU** punya batch *WIP/Belum
  Proses* dalam 6 bulan terakhir — jadi produk dengan batch berjalan tetap tampil walau stok = 0 (kolom Stok/ED
  akan 0). Batch yang semuanya sudah Selesai tanpa stok tidak menambah baris kosong.
- **OUTSTANDING PO** — dari Sales Order bulan berjalan (`sales_order_detail`/`_master`, `sisa > 0`,
  `status <> 2` = buang SO batal/tolak): *Unit* (`SUM(sisa)`) · *Freestock* = ED≥9 Bln − Unit
  (bisa **negatif** → ditandai merah) · *DOI* = (Freestock / Forecast) × 30; Forecast 0 → 0.
- **FG KARANTINA** — *Unit* = `SUM(saldo_sl_barang.jumlah_karantina)` (stok masih di karantina) ·
  *Freestock* = Freestock (Outstanding PO) + Unit FG Karantina · *DOI* = (Freestock Karantina / Forecast) × 30;
  Forecast 0 → 0.
- **WIP** & **BELUM PROSES** — batch dari `master_batch` dengan `tgl_input ≥ CURRENT_DATE − 6 months`.
  Dua grup, urut **WIP** dulu lalu **BELUM PROSES** (WIP lebih dekat menjadi FG): *WIP* (penyerahan sudah,
  karantina belum) · *Belum Proses* (penyerahan & karantina belum). Tiap state = *Unit* (Σ batch size) +
  *Freestock* & *DOI* (kumulatif, mengikuti urutan kolom): Freestock WIP = Freestock Karantina + Unit
  WIP; Freestock Belum Proses = Freestock WIP + Unit Belum Proses; DOI = (Freestock ÷ Forecast)
  × 30. *Unit* per state = Σ batch size batch pada state itu (batch size per produk dari
  `master_formula_barang_jadi.jumlah_fb` formula terbaru; produk tanpa formula = 0; `jumlah_fb` di-cast
  `double precision`).

> **Tampilan nilai:** *DOI* ditampilkan sebagai **angka biasa** (bilangan bulat, 0 desimal) — 0 tetap “0”,
> tanpa tanda “–”/“#”; Forecast = 0 (DOI `NULL`) juga ditampilkan **0**. Hanya *Realisasi* yang lewat
> `Fmt::ratio()` (**1 desimal, tanpa simbol %**): Forecast = 0 → **“–”**, hasil tepat 0 → **“#”**, selain itu angka biasa.

> **Drill-down batch:** angka pada kolom *Unit* **WIP** & **BELUM PROSES** bisa **diklik** → modal berisi
> daftar batch penyusunnya (No. Batch, Kode Batch, Tgl Input, Tgl Penyerahan, ED, Batch Size). Query di
> `StokForecastReport::batchDetail()` memakai window & logika state yang sama dengan agregatnya.

> **Drill-down stok & karantina:** angka pada **STOK SAAT INI → ED ≤ 9 Bln / ED ≥ 9 Bln** dan
> **FG KARANTINA → Unit** bisa **diklik** → modal (dipakai bersama) berisi rincian saldo per batch
> (No. Batch, Kode Batch, ED, Jumlah + baris TOTAL). Query di `StokForecastReport::stokDetail()`
> (ED, dipilah `tgl_expired` terhadap `CURRENT_DATE + 9 months`) & `karantinaDetail()`
> (Σ `jumlah_karantina`, tanpa filter ED) memakai sumber yang sama dengan CTE `sa` (baris saldo
> terbaru per seq), jadi total rincian = angka pada sel.

> Data dimuat otomatis saat halaman dibuka (query gabungan ~0,7 dtk, di-cache 5 menit **per bulan**).
> **Filter Bulan** (`<input type="month">`, default bulan berjalan) hanya memengaruhi kolom bulanan
> *Forecast*, *Pengeluaran*, *Outstanding* (via param SQL `:ref`); *Stok/ED/DOI/Batch* tetap acuan
> `CURRENT_DATE`. Tabel dipadatkan (`text-[11px]`, padding kecil) & nama produk dipotong (ellipsis, hover
> = nama penuh) agar seluruh kolom muat 1 layar; **header di-freeze** (sticky) saat di-scroll. Warna sel
> data dihilangkan — **hanya nilai negatif yang merah**; kolom *Unit* WIP/Belum tetap bergaris bawah sbg
> penanda klik. Baris/halaman: 25 / 50 / 100 / **Semua** (`perPage = 0`). Pencarian & sorting sisi-aplikasi.

> Export Excel: kolom angka ditulis sebagai **angka asli** (bukan string berformat Indonesia) dengan
> format sel `#,##0` — jadi ribuan dirender Excel sesuai locale & `1210` tak lagi salah dibaca `1,21`.
> *DOI* juga angka murni (`#,##0`, `NULL` → 0); hanya *Realisasi* yang TEKS (`–` / `#` / `85,5`, 1 desimal tanpa %). `WithStrictNullComparison` menjaga
> `0` tak hilang; **`ShouldAutoSize`** meng-autofit lebar kolom. Lihat `app/Exports/StokForecastExport.php`.

> **Produk Toll Out (makloon).** Tombol **Toll Out** (di samping Export Excel) membuka halaman
> `/reports/toll-out` (`App\Livewire\TollOutManager`) untuk mengelola daftar **kode** produk yang
> diproduksi di luar. Daftar disimpan di **SQLite** (tabel `toll_out_products`, koneksi default —
> **bukan** DB legacy yang read-only); nama produk di-lookup dari `master_barang` saat ditambahkan.
> Di Monitoring Stok, baris produk Toll Out yang **DOI kolom BELUM PROSES ≤ 80 hari** (dan Forecast > 0,
> jadi DOI benar-benar terhitung) diberi **latar kuning** (`bg-yellow-200`) sebagai peringatan dini
> reorder — hanya di layar (Export Excel/PDF tak berubah). Set kode dimuat segar tiap render (di luar
> cache baris per-bulan) via `TollOutProduct::set()`, jadi perubahan daftar langsung tampil.

## Arsitektur laporan (pola reusable)
- **Service per laporan** di `app/Reports/` (mis. `KarantinaReport`) — berisi SQL tervalidasi
  (bound params) + logika filter/sort sisi-aplikasi. Controller tipis, tanpa Eloquent untuk
  agregat.
- **Komponen Livewire** di `app/Livewire/` — filter, sorting sisi-aplikasi, pagination.
- **Shell Blade reusable** `resources/views/components/report-shell.blade.php` — judul, panel
  filter, tombol export, tabel.
- **Export** di `app/Exports/` + `app/Http/Controllers/*ExportController` (Excel & PDF).
- **Format Indonesia** via `App\Support\Fmt` — angka `8.807,00`, tanggal `dd/mm/yyyy`.

### Catatan penting query Batch/Karantina
- SQL sudah tervalidasi di DB ini. **Jangan** tambahkan `ORDER BY m.tgl_input` di SQL
  (baris `tgl_input` NULL akan naik & hasil terlihat kosong). Sorting ditangani di aplikasi
  (default `tgl_input` menurun, NULL selalu di bawah).
- Koneksi `pgsql` memakai `PDO::ATTR_EMULATE_PREPARES = true` agar named param
  (`:start_date`/`:end_date`) bisa dipakai berulang dalam satu statement (khusus read-only).
