-- =============================================================================
--  DIAGNOSA: kenapa "Stok Produk Jadi" di aplikasi kita BEDA dgn laporan legacy
--  "Rekap Stock Produk Jadi".
--
--  KESIMPULAN (sudah divalidasi ke screenshot):
--   * Aplikasi kita  -> membaca SNAPSHOT PERIODIK `saldo_sl_barang` (tgl_saldo =
--                       awal bulan). Snapshot ini hanya di-update saat "Sinkronisasi"
--                       dijalankan, jadi utk banyak produk snapshot-nya BASI
--                       (mis. batch aktif OJ011O snapshot terakhir 2026-05-01 = 699).
--   * Layar legacy   -> menghitung LIVE sampai tanggal "Sampai": saldo di-roll-forward
--                       dgn pergerakan aktual (penerimaan masuk - pengeluaran keluar
--                       +/- penyesuaian/retur). Itu sebabnya OJ011O tampil 879, bukan 699.
--
--  Skrip ini READ-ONLY. Ubah tanggal :sampai di bawah sesuai filter "Sampai" di layar.
--  Kolom hasil:
--    app_total   = angka yg SEKARANG dipakai aplikasi kita (snapshot terbaru per batch)
--    snap_date   = tanggal snapshot terbaru yg terpakai  -> kalau lama = data basi
--    masuk/keluar= pergerakan LIVE s/d :sampai (penerimaan_barang - pengeluaran_produk)
--    live_net    = masuk - keluar  (pendekatan angka layar legacy)
--  Bandingkan app_total vs live_net: kalau jauh & snap_date lama -> itu penyebab bedanya.
-- =============================================================================

-- ── Ganti tanggal ini sesuai filter "Sampai" di layar ───────────────────────
--    (PG 8.4: pakai literal langsung, ganti ke-4 kemunculan '2026-09-01' di bawah
--     bila ingin tanggal lain — atau bungkus dgn view/psql \set sesuai selera)

WITH app_snap AS (
    -- Snapshot TERBARU per baris saldo (per batch), TANPA batas tanggal.
    -- Ini persis yang dilakukan CTE `sa` di StokForecastReport.php aplikasi kita.
    SELECT DISTINCT ON (s.seq)
           s.seq, s.barang_seq, s.tgl_expired, s.jumlah, s.jumlah_karantina, s.tgl_saldo
    FROM saldo_sl_barang s
    ORDER BY s.seq, s.tgl_saldo DESC
)
SELECT b.kode,
       trim(b.nama)                                                       AS nama,

       -- (1) Angka versi APLIKASI KITA (snapshot basi)
       (SELECT COALESCE(SUM(a.jumlah), 0)
          FROM app_snap a WHERE a.barang_seq = b.seq)                     AS app_total,
       (SELECT COALESCE(SUM(a.jumlah_karantina), 0)
          FROM app_snap a WHERE a.barang_seq = b.seq)                     AS app_karantina,
       (SELECT MAX(a.tgl_saldo)
          FROM app_snap a WHERE a.barang_seq = b.seq AND a.jumlah > 0)    AS snap_date,

       -- (2) Pergerakan LIVE s/d tanggal "Sampai"
       (SELECT COALESCE(SUM(d.jumlah), 0)
          FROM penerimaan_barang_detail d
          JOIN penerimaan_barang_master m ON m.seq = d.master_seq
         WHERE d.barang_seq = b.seq
           AND m.tanggal <= DATE '2026-09-01')                           AS masuk,
       (SELECT COALESCE(SUM(d.jumlah), 0)
          FROM pengeluaran_produk_detail d
          JOIN pengeluaran_produk_master m ON m.seq = d.master_seq
         WHERE d.barang_seq = b.seq
           AND m.tanggal <= DATE '2026-09-01')                           AS keluar,

       -- (3) Pendekatan angka LAYAR legacy = masuk - keluar
       (SELECT COALESCE(SUM(d.jumlah), 0)
          FROM penerimaan_barang_detail d
          JOIN penerimaan_barang_master m ON m.seq = d.master_seq
         WHERE d.barang_seq = b.seq AND m.tanggal <= DATE '2026-09-01')
     - (SELECT COALESCE(SUM(d.jumlah), 0)
          FROM pengeluaran_produk_detail d
          JOIN pengeluaran_produk_master m ON m.seq = d.master_seq
         WHERE d.barang_seq = b.seq AND m.tanggal <= DATE '2026-09-01')  AS live_net

FROM master_barang b
WHERE b.disable_date IS NULL                       -- Status "Aktif"; hapus baris ini utk SEMUA
-- Batasi ke produk tertentu utk uji cepat (hapus WHERE ini utk semua produk):
--   AND b.kode IN ('OJ011O','OJ374O','OJ070-P','OJ425O','OJ431P')
ORDER BY b.kode;
