<?php

namespace App\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Laporan "WIP dan BR Belum Proses" (DETAIL PER BATCH).
 *
 * Menampilkan SATU BARIS PER BATCH (tidak dikelompokkan per produk) untuk batch berstatus
 * BR Belum Proses atau WIP dalam RENTANG TANGGAL yang dipilih user (master_batch.tgl_input).
 * Jadi bila satu produk punya dua batch WIP, muncul dua baris.
 *
 * Definisi status batch (sama seperti StokForecastReport):
 *  - BR Belum Proses = penyerahan BELUM (tgl_penyerahan NULL) & karantina BELUM terisi.
 *  - WIP             = penyerahan SUDAH (tgl_penyerahan terisi) & karantina BELUM terisi.
 *  - Batch Size = master_formula_barang_jadi.jumlah_fb (formula TERBARU per produk); tanpa formula = 0.
 *
 * ATURAN (DB legacy gracia_mrp, PostgreSQL 8.4, READ-ONLY):
 *  - Rentang tanggal inklusif: tgl_input >= :from s/d <= :to (dipakai < :to + 1 hari).
 */
class WipBelumProsesReport
{
    private const SQL = <<<'SQL'
        SELECT d.kode,
               d.nama,
               d.no_batch,
               d.kode_batch,
               d.tgl_input,
               d.tgl_expired,
               d.batch_size,
               CASE WHEN d.tgl_penyerahan IS NOT NULL THEN 'wip' ELSE 'belum' END AS status
        FROM (
            SELECT m.seq,
                   m.no_batch,
                   m.kode_batch,
                   m.tgl_input,
                   m.tgl_expired,
                   b.kode,
                   TRIM(b.nama || ' ' || COALESCE(bs.nama, '')) AS nama,
                   pb.tanggal      AS tgl_penyerahan,
                   max(pm.tanggal) AS karantina,
                   fb.jumlah_fb::double precision AS batch_size
            FROM master_batch m
            JOIN master_barang b               ON b.seq  = m.barang_seq
            LEFT JOIN master_bentuk_sediaan bs ON bs.seq = b.bentuk_sediaan_seq
            LEFT JOIN (
                SELECT DISTINCT ON (barang_jadi_seq) barang_jadi_seq, jumlah_fb
                FROM master_formula_barang_jadi
                ORDER BY barang_jadi_seq, tanggal_formula DESC NULLS LAST, seq DESC
            ) fb ON (fb.barang_jadi_seq = m.barang_seq)
            LEFT JOIN penerimaan_barang_detail pd
                   ON (m.seq = pd.batch_seq AND pd.barang_seq = m.barang_seq)
            LEFT JOIN penerimaan_barang_master pm
                   ON (pm.seq = pd.master_seq)
            LEFT JOIN pengeluaran_barang_master pb
                   ON (pb.barang_jadi_seq = m.barang_seq AND pb.batch_seq = m.seq
                       AND pb.tanggal = (SELECT MAX(pb2.tanggal) FROM pengeluaran_barang_master pb2
                                         WHERE pb2.barang_jadi_seq = m.barang_seq
                                           AND pb2.batch_seq = m.seq))
            WHERE m.tgl_input >= :from::date
              AND m.tgl_input <  (:to::date + INTERVAL '1 day')
            GROUP BY m.seq, m.no_batch, m.kode_batch, m.tgl_input, m.tgl_expired, b.kode, b.nama, bs.nama, pb.tanggal, fb.jumlah_fb
        ) d
        WHERE d.karantina IS NULL
        ORDER BY d.kode, d.tgl_input DESC
        SQL;

    /**
     * Ambil daftar batch (detail) berstatus WIP / BR Belum Proses pada rentang [from, to] (inklusif),
     * berdasarkan tgl_input. Satu baris per batch.
     *
     * @param  string  $from  Tanggal awal 'YYYY-MM-DD'.
     * @param  string  $to    Tanggal akhir 'YYYY-MM-DD' (inklusif).
     * @return array<int,\stdClass>
     */
    public function rows(string $from, string $to): array
    {
        return DB::connection('pgsql')->select(self::SQL, [
            'from' => $from,
            'to'   => $to,
        ]);
    }

    /**
     * Kelompokkan baris per-batch menjadi per PRODUK (dua grup: belum & wip) + rekap total, lalu urutkan
     * daftar produk berdasarkan $sortField ('kode' / 'nama'). Dipakai bersama oleh komponen Livewire &
     * proses export supaya urutan/isinya konsisten.
     *
     * @param  array<int,\stdClass>  $rows
     * @return array{products: array<int,array<string,mixed>>, totals: array<string,array<string,float|int>>}
     */
    public static function group(array $rows, string $sortField = 'nama', string $sortDir = 'asc'): array
    {
        $totals = [
            'belum' => ['n' => 0, 'size' => 0.0],
            'wip'   => ['n' => 0, 'size' => 0.0],
            'all'   => ['n' => 0, 'size' => 0.0],
        ];

        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r->kode] ??= ['kode' => $r->kode, 'nama' => $r->nama, 'belum' => [], 'wip' => []];
            $k = $r->status === 'wip' ? 'wip' : 'belum';
            $grouped[$r->kode][$k][] = ['no_batch' => $r->no_batch, 'batch_size' => $r->batch_size];

            $totals[$k]['n']++;
            $totals[$k]['size']  += (float) $r->batch_size;
            $totals['all']['n']++;
            $totals['all']['size'] += (float) $r->batch_size;
        }

        $products = [];
        foreach ($grouped as $g) {
            $g['rowspan'] = max(count($g['belum']), count($g['wip']));
            $products[] = $g;
        }

        // Sorting daftar produk (kode/nama) di sisi aplikasi.
        if (! in_array($sortField, ['kode', 'nama'], true)) {
            $sortField = 'nama';
        }
        $dir = $sortDir === 'desc' ? -1 : 1;
        usort($products, fn ($a, $b) => strcmp((string) $a[$sortField], (string) $b[$sortField]) * $dir);

        return ['products' => $products, 'totals' => $totals];
    }
}
