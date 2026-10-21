# Sumber Data — Tabel Master Produk (data lineage)

Dokumen ini memetakan **tiap kolom** pada tabel referensi produk (contoh baris:
ZOLASTIN / OJ016A-P) ke **asal datanya di DB legacy PostgreSQL** (`gracia_mrp`, READ-ONLY).
Beberapa kolom **tidak tersimpan di DB** — itu sudah dipastikan dan ditandai jelas di bawah.

Contoh baris acuan:

| Kolom | Nilai |
|---|---|
| Kode_Produk | OJ016A-P |
| Nama_Produk_(MRP) | ZOLASTIN |
| Nama_Produk_(Label) | ZOLASTIN TABLET |
| NIE | DPL2035310310A1 |
| Umur_Produk_(Bulan) | 24 |
| Besar_Batch (FB) | 6.000 |
| Desc_Kemasan | DUS, 5 STRIP @ 10 TABLET |
| HET (per Folding_Box) | Rp348.096 |
| Jml_FB_dalam_MB | 27 |
| Jml_MB_dalam_1_Palet (max) | 248 |
| Range_Penimbangan_MB | 772,02–785,22 gram |
| Klaim_Penyimpanan | SIMPAN PADA SUHU DI BAWAH 30°C |

---

## 1. Peta sumber per kolom

Keterangan status: ✅ = ada di DB (bisa ditarik langsung) · ❌ = **tidak ada di DB** (sumber eksternal/manual).

| Kolom diminta | Status | Sumber (tabel.kolom) | Catatan |
|---|:--:|---|---|
| **Kode_Produk** | ✅ | `master_barang.kode` | Kunci produk (`seq` = PK internal). |
| **Nama_Produk_(MRP)** | ✅ | `master_barang.nama` | Nama internal/MRP (mis. "ZOLASTIN"). |
| **Nama_Produk_(Label)** | ✅* | `master_barang.nama` + `master_bentuk_sediaan.nama` | *Bukan satu kolom — **tersusun**: nama + bentuk sediaan (via `bentuk_sediaan_seq`) → "ZOLASTIN" + "TABLET". |
| **NIE** (no. registrasi) | ✅ | `master_barang.no_reg` | = `DPL2035310310A1`. Bonus: `tgl_terbit` (terbit) & `tgl_berlaku` (kedaluwarsa izin). |
| **Umur_Produk_(Bulan)** | ✅ | `master_barang.shelf_life` | = 24. Nilai sama juga ada di `master_formula_barang_jadi.umur_obat`. |
| **Besar_Batch (FB)** | ✅ | `master_formula_barang_jadi.jumlah_fb` | Ambil **formula terbaru** (`ORDER BY tanggal_formula DESC`). = 6000. |
| **Desc_Kemasan** | ✅ | `master_barang.kemasan` | = "DUS,5 STRIP @ 10 TABLET". |
| **HET (per Folding_Box)** | ❌ | — | **Tidak ada di DB.** Harga di `master_barang` berbeda (`harga`=300.000, `harga_telkom`=110.000) — bukan HET. HET (Harga Eceran Tertinggi) ditetapkan pemerintah/di-maintain terpisah (file/manual). |
| **Jml_FB_dalam_MB** | ✅ | `master_barang.isi` | = 27 (jumlah Folding Box per Master Box). |
| **Jml_MB_dalam_1_Palet (max)** | ❌ | — | **Tidak ada di DB.** Dihitung manual dari dimensi karton & ukuran palet (lihat kolom bantu `panjang/lebar/tinggi`). |
| **Range_Penimbangan_MB** | ❌ | — | **Tidak ada di DB.** Rentang QC saat menimbang Master Box; terkait `berat_isi` (0.8) × `isi` (27) ± toleransi, tapi nilai jadinya tidak disimpan. |
| **Klaim_Penyimpanan** | ❌ | — | **Tidak ada di DB.** Berasal dari dossier/label registrasi (mis. "SIMPAN PADA SUHU DI BAWAH 30°C") — manual/eksternal. |

> Dicek via pencarian nama kolom di seluruh skema: **tidak ada** kolom `palet/pallet`, `klaim`,
> `penyimpanan/simpan`, `timbang/penimbangan`, `brutto/netto`, maupun `het` (selain satu kolom
> `het` di modul pembelian MM yang tidak relevan). Jadi 4 kolom di atas memang harus dari sumber lain.

---

## 2. SQL siap pakai — ambil semua field yang ADA di DB

PostgreSQL 8.4 (READ-ONLY). Ganti `:kode` dengan kode produk (atau hapus WHERE untuk semua produk).

```sql
SELECT
  b.kode                                   AS "Kode_Produk",
  b.nama                                   AS "Nama_Produk_(MRP)",
  TRIM(b.nama || ' ' || COALESCE(bs.nama,'')) AS "Nama_Produk_(Label)",
  b.no_reg                                 AS "NIE",
  b.tgl_terbit                             AS "NIE_tgl_terbit",
  b.tgl_berlaku                            AS "NIE_tgl_berlaku",
  b.shelf_life                             AS "Umur_Produk_(Bulan)",
  (SELECT f.jumlah_fb
     FROM master_formula_barang_jadi f
    WHERE f.barang_jadi_seq = b.seq
    ORDER BY f.tanggal_formula DESC NULLS LAST, f.seq DESC
    LIMIT 1)                               AS "Besar_Batch_(FB)",
  b.kemasan                                AS "Desc_Kemasan",
  b.isi                                    AS "Jml_FB_dalam_MB",
  -- berikut TIDAK ADA di DB (diisi manual/eksternal):
  NULL::text  AS "HET_(per_Folding_Box)",
  NULL::int   AS "Jml_MB_dalam_1_Palet_max",
  NULL::text  AS "Range_Penimbangan_MB",
  NULL::text  AS "Klaim_Penyimpanan"
FROM master_barang b
LEFT JOIN master_bentuk_sediaan bs ON bs.seq = b.bentuk_sediaan_seq
WHERE b.kode = 'OJ016A-P'          -- hapus baris ini untuk SEMUA produk
ORDER BY b.nama;
```

Catatan PG 8.4: jangan pakai `LATERAL`/`left()`; subquery skalar `LIMIT 1` di atas aman.

---

## 3. Kolom bantu di `master_barang` (berguna untuk hitung yang "tidak ada")

Walau HET/palet/range penimbangan tak tersimpan jadi, ada bahan mentah untuk menghitungnya:

| Kolom | Nilai (ZOLASTIN) | Guna |
|---|---|---|
| `berat_isi` | 0.8 | Berat per Folding Box (kg) → bahan Range_Penimbangan_MB |
| `isi` | 27 | FB per MB |
| `panjang` × `lebar` × `tinggi` | 21.5 × 16.2 × 14.6 (cm) | Dimensi karton (MB) → bahan hitung muat/palet |
| `jumlah_unit` | 30 | Jumlah unit |
| `satuan_terkecil` | 50 | Satuan terkecil (5 strip × 10 tablet) |
| `kekuatan_n_variasi` | "TIAP TABLET MENGANDUNG: ALPRAZOLAM 1 MG" | Kekuatan/komposisi |
| `harga`, `harga_telkom`, `harga_terkecil`, `harga_katalog` | 300.000 / 110.000 / 4.500 / 0 | Harga internal (BUKAN HET) |
| `produsen_seq`, `golongan_seq`, `jenis_seq` | 1 / 2 / 5 | Produsen / golongan / jenis (OJ) |

---

## 4. Ringkas

- **8 dari 12 kolom tersedia di DB** (Kode, Nama MRP, Nama Label*, NIE, Umur, Besar Batch, Desc Kemasan, Jml FB/MB).
- **4 kolom tidak ada di DB** dan harus dari sumber lain/manual:
  **HET**, **Jml MB per Palet**, **Range Penimbangan MB**, **Klaim Penyimpanan**.
- Tabel `master_produk_jadi_bpom` ada tapi **minim** (hanya kode, nama, nie, kemasan, sediaan,
  kekuatan) dan **tidak memuat baris untuk ZOLASTIN** — jadi tidak dipakai di peta ini.
