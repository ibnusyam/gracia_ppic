# Kolom "Pengeluaran" (Sales) — rumus komersil

Kolom **Pengeluaran** pada laporan *Monitoring Stok & Forecast* mereproduksi **persis** laporan
legacy **"Pengeluaran Produk VS Forecast"** (tombol *Load Keluar vs Forecast*, Jenis Barang = komersil).

Kode: [`app/Reports/StokForecastReport.php`](../app/Reports/StokForecastReport.php) (CTE `sj`).

---

## 1. Rumus

```
Pengeluaran(produk, bulan)
  = Σ pemakaian_barang.jumlah
    untuk mutasi stok keluar milik SURAT JALAN bertanggal di bulan acuan (tgl 1 s/d akhir bulan)
    yang:  tipe_trans = 10                          (mutasi keluar normal)
      dan  sales_order.tipe % 10 NOT IN (1,2,5,6)   (SO komersil)
      dan  stok line-nya jenis_barang = 1           (stok KOMERSIL saja)
```

```sql
SELECT d.barang_seq, SUM(p.jumlah) AS terjual_bulan_ini
FROM surat_jalan_master m
JOIN surat_jalan_detail d  ON d.master_seq = m.seq
JOIN sales_order_master so ON so.seq = m.so_seq
JOIN pemakaian_barang p    ON p.trans_seq  = m.seq
                          AND p.barang_seq = d.barang_seq
                          AND p.batch_seq  = d.batch_seq
WHERE m.tanggal >= date_trunc('month', :ref::date)
  AND m.tanggal <  date_trunc('month', :ref::date) + INTERVAL '1 month'
  AND p.tipe_trans = 10
  AND (so.tipe % 10) NOT IN (1, 2, 5, 6)
  AND EXISTS (
      SELECT 1 FROM saldo_sl_barang s
      WHERE s.seq = p.stok_line_seq AND s.jenis_barang = 1
  )
GROUP BY d.barang_seq
```

Forecast tetap: `Σ setting_forecast.jumlah` bulan acuan, `disable_date IS NULL`.

---

## 2. Arti tiap filter

| Filter | Kenapa |
|---|---|
| Sumber `pemakaian_barang` (bukan `pengeluaran_produk_detail`) | Legacy memakai mutasi stok yang benar-benar mengurangi saldo. `pengeluaran_produk_detail` kadang beda (terbukti: rumus berbasis itu hanya ~162/183). |
| `tipe_trans = 10` | Nilai lain (5, 11) = retur/koreksi, tidak dihitung sebagai keluar. |
| `so.tipe % 10 NOT IN (1,2,5,6)` | Hanya SO komersil. Dibuang: tipe tender/khusus (21, 22, 26, 31, 36, …) dan **35 = pemusnahan** (barang ED dimusnahkan). Disimpan: 10, 60, 83, 37, dll. |
| Stok line `jenis_barang = 1` saja | = dropdown **"Jenis Barang [komersil]"** di legacy. Dinilai per **stok line** (`pemakaian_barang.stok_line_seq` → `saldo_sl_barang.seq`). Yang dibuang: **2 = repack** (mis. OJ043P IF126 "Repack Propepsa", OJ437P IA032), **3** (mis. PLT335P), **4** (mis. OJ254-P IH109, 1.000 unit). |

### Yang BUKAN penanda (koreksi analisis sebelumnya)
- **Bukan** prefix nomor dokumen / distributor (PV, SJ, SL, RU, GP, GF…).
- **Bukan** `asal_penerimaan = 4` — nilai itu juga dipakai untuk *Stock Opname* & *Closingan*
  (mis. OJ277-P), yang justru **dihitung**. Pembeda yang benar adalah `jenis_barang` stok line (= 1 untuk komersil).
- Retur pengganti (dokumen RU) **tetap dihitung** selama SO-nya komersil.

---

## 3. Validasi

| Export legacy | Periode | Hasil |
|---|---|---|
| `data terbaru september.xls` | 01–30/09/2026 (bulan tutup) | **186/186 nilai, 0 baris ekstra** · forecast 186/186 |
| `data terbaru.xls` | 01–05/10/2026 | **92/92 nilai, 0 baris ekstra** (dipotong di waktu export, `surat_jalan_master.tgl_input < 05/10 08:49`) · forecast 92/92 |

> Untuk bulan berjalan, angka app akan terus bertambah dibanding file export karena surat jalan
> baru masuk setelah export. Validasi paling bersih = bulan yang sudah tutup (seperti September).

Cara cek cepat satu produk vs layar legacy: jalankan SQL di atas dengan rentang tanggal yang
sama dengan filter *Dari–Sampai* di layar, tambah `AND d.barang_seq = <seq>`.

---

## 4. Cache & deploy

- `CACHE_VERSION` di [`app/Livewire/StokForecastTable.php`](../app/Livewire/StokForecastTable.php)
  dinaikkan ke `stok_forecast:v9` agar angka lama tidak tertahan cache 5 menit.
- Perubahan murni SQL/PHP → tidak perlu `npm run build`. Di server: `pm2 restart mrp-app`.
- Waktu query laporan penuh ±0,9 dtk (435 produk).
