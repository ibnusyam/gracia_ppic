<?php

namespace App\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Laporan "Monitoring Stok" (spec bagian 6).
 *
 * Agregasi per PRODUK (kode + nama) dari data batch di DB legacy gracia_mrp (PostgreSQL 8.4).
 * Definisi kolom (dikonfirmasi user) — state berdasarkan 2 kolom tanggal:
 *   penyerahan = pengeluaran_barang_master.tanggal, karantina = max(penerimaan_barang_master.tanggal).
 *  - Batch Belum Proses = penyerahan BELUM terisi DAN karantina BELUM terisi.
 *  - WIP                = penyerahan SUDAH terisi DAN karantina BELUM terisi.
 *  - Toll OUT           = penyerahan BELUM terisi DAN karantina SUDAH terisi.
 *  - Batch Selesai      = penyerahan SUDAH terisi DAN karantina SUDAH terisi (semua kolom terisi).
 *  - Total              = jumlah seluruh batch produk (COUNT(*)).
 *    (4 state di atas saling lepas & lengkap: Belum Proses + WIP + Toll OUT + Selesai = Total.)
 *  - Batch Size (kolom size_*) = TOTAL batch size dari batch pada state itu = jumlah batch pada
 *    state tsb x batch size per batch. master_batch tidak menyimpan batch size, jadi diambil dari
 *    master_formula_barang_jadi.jumlah_fb (formula TERBARU per produk). Produk tanpa formula = 0.
 *
 * ATURAN PENTING:
 *  - DB PostgreSQL 8.4 TIDAK mendukung agregat FILTER (…); gunakan SUM(CASE WHEN … END).
 *  - JANGAN ORDER BY tgl_input di SQL (baris NULL naik). Sorting ditangani di sisi aplikasi.
 *  - Filter rentang tanggal pakai bound params :start_date / :end_date (opsional; NULL = semua,
 *    batas akhir inklusif satu hari penuh via + INTERVAL '1 day'), difilter di tgl_input batch.
 */
class MonitoringStokReport
{
    /**
     * Definisi kolom (key hasil query => label). Flat — dipakai untuk validasi sort & export.
     * Tiap state punya sepasang kolom: <state> (jumlah batch) + size_<state> (total batch size).
     */
    public const COLUMNS = [
        'kode_produk'          => 'Kode Produk',
        'nama_produk'          => 'Nama Produk',
        'br_belum_proses'      => 'Batch Belum Proses',
        'size_br_belum_proses' => 'Batch Size',
        'wip'                  => 'WIP',
        'size_wip'             => 'Batch Size',
        'toll_out'             => 'Toll OUT',
        'size_toll_out'        => 'Batch Size',
        'batch_selesai'        => 'Batch Selesai',
        'size_batch_selesai'   => 'Batch Size',
        'total'                => 'Total',
        'size_total'           => 'Batch Size',
    ];

    /**
     * Grup tampilan header bertingkat: key grup (= key kolom "jumlah") => label grup.
     * Kolom "batch size" pasangannya selalu 'size_'.<key grup>.
     */
    public const GROUPS = [
        'br_belum_proses' => 'Batch Belum Proses',
        'wip'             => 'WIP',
        'toll_out'        => 'Toll OUT',
        'batch_selesai'   => 'Batch Selesai',
        'total'           => 'Total',
    ];

    private const SQL = <<<'SQL'
        SELECT sub.kode_produk,
               sub.nama_produk,
               SUM(CASE WHEN sub.tgl_penyerahan IS NULL     AND sub.karantina IS NULL     THEN 1 ELSE 0 END) AS br_belum_proses,
               SUM(CASE WHEN sub.tgl_penyerahan IS NULL     AND sub.karantina IS NULL     THEN COALESCE(sub.batch_size, 0) ELSE 0 END) AS size_br_belum_proses,
               SUM(CASE WHEN sub.tgl_penyerahan IS NOT NULL AND sub.karantina IS NULL     THEN 1 ELSE 0 END) AS wip,
               SUM(CASE WHEN sub.tgl_penyerahan IS NOT NULL AND sub.karantina IS NULL     THEN COALESCE(sub.batch_size, 0) ELSE 0 END) AS size_wip,
               SUM(CASE WHEN sub.tgl_penyerahan IS NULL     AND sub.karantina IS NOT NULL THEN 1 ELSE 0 END) AS toll_out,
               SUM(CASE WHEN sub.tgl_penyerahan IS NULL     AND sub.karantina IS NOT NULL THEN COALESCE(sub.batch_size, 0) ELSE 0 END) AS size_toll_out,
               SUM(CASE WHEN sub.tgl_penyerahan IS NOT NULL AND sub.karantina IS NOT NULL THEN 1 ELSE 0 END) AS batch_selesai,
               SUM(CASE WHEN sub.tgl_penyerahan IS NOT NULL AND sub.karantina IS NOT NULL THEN COALESCE(sub.batch_size, 0) ELSE 0 END) AS size_batch_selesai,
               COUNT(*)                                                                                      AS total,
               SUM(COALESCE(sub.batch_size, 0))                                                              AS size_total
        FROM (
            SELECT m.seq              AS seq,
                   b.kode             AS kode_produk,
                   b.nama             AS nama_produk,
                   pb.tanggal         AS tgl_penyerahan,
                   max(pm.tanggal)    AS karantina,
                   fb.jumlah_fb::double precision AS batch_size  -- cast: jumlah_fb 'real'/float4 kehilangan presisi saat di-SUM jutaan
            FROM master_batch m
            LEFT JOIN master_barang b
                   ON (b.seq = m.barang_seq)
            LEFT JOIN (
                    -- Batch size per produk = jumlah_fb dari formula TERBARU (tanggal_formula desc).
                    -- master_batch tidak menyimpan batch size, jadi diambil dari master formula produk jadi.
                    SELECT DISTINCT ON (barang_jadi_seq) barang_jadi_seq, jumlah_fb
                    FROM master_formula_barang_jadi
                    ORDER BY barang_jadi_seq, tanggal_formula DESC NULLS LAST, seq DESC
                 ) fb
                   ON (fb.barang_jadi_seq = m.barang_seq)
            LEFT JOIN penerimaan_barang_detail pd
                   ON (m.seq = pd.batch_seq AND pd.barang_seq = m.barang_seq)
            LEFT JOIN penerimaan_barang_master pm
                   ON (pm.seq = pd.master_seq)
            LEFT JOIN pengeluaran_barang_master pb
                   ON (pb.barang_jadi_seq = m.barang_seq AND pb.batch_seq = m.seq
                       AND pb.tanggal = (SELECT MAX(pb2.tanggal) FROM pengeluaran_barang_master pb2
                                         WHERE pb2.barang_jadi_seq = m.barang_seq
                                           AND pb2.batch_seq = m.seq))
            WHERE m.seq IS NOT NULL
              AND (:start_date IS NULL OR m.tgl_input >= :start_date)
              AND (:end_date   IS NULL OR m.tgl_input <  ((:end_date)::date + INTERVAL '1 day'))
            GROUP BY m.seq, b.kode, b.nama, pb.tanggal, fb.jumlah_fb
        ) sub
        GROUP BY sub.kode_produk, sub.nama_produk
        SQL;

    /**
     * Ambil baris laporan mentah dari DB legacy (read-only).
     *
     * @return array<int,\stdClass>
     */
    public function rows(?string $startDate = null, ?string $endDate = null): array
    {
        return DB::connection('pgsql')->select(self::SQL, [
            'start_date' => $startDate !== null && $startDate !== '' ? $startDate : null,
            'end_date'   => $endDate   !== null && $endDate   !== '' ? $endDate   : null,
        ]);
    }

    /**
     * Filter pencarian (kode/nama) + sorting di sisi aplikasi — dipakai ulang oleh
     * komponen Livewire dan proses export supaya hasilnya konsisten.
     * NULL selalu di urutan bawah (setara NULLS LAST) tanpa memandang arah sort.
     *
     * @param  array<int,\stdClass>  $rows
     * @return \Illuminate\Support\Collection<int,\stdClass>
     */
    public static function arrange(array $rows, string $search = '', string $sortField = 'kode_produk', string $sortDir = 'asc'): \Illuminate\Support\Collection
    {
        $out = collect($rows);

        if ($search !== '') {
            $q = mb_strtolower($search);
            $out = $out->filter(fn ($r) =>
                str_contains(mb_strtolower((string) $r->kode_produk), $q)
                || str_contains(mb_strtolower((string) $r->nama_produk), $q)
            );
        }

        if (! array_key_exists($sortField, self::COLUMNS)) {
            $sortField = 'kode_produk';
        }
        $dir = $sortDir === 'asc' ? 1 : -1;

        return $out->sort(function ($a, $b) use ($sortField, $dir) {
            $av = $a->$sortField ?? null;
            $bv = $b->$sortField ?? null;
            if ($av === null && $bv === null) return 0;
            if ($av === null) return 1;   // NULLS LAST
            if ($bv === null) return -1;
            if (is_numeric($av) && is_numeric($bv)) {
                return ($av <=> $bv) * $dir;
            }
            return strcmp((string) $av, (string) $bv) * $dir;
        })->values();
    }
}
