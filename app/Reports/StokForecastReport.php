<?php

namespace App\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Laporan "Monitoring Stok & Forecast".
 *
 * Agregasi per PRODUK (kode + nama) dari DB legacy gracia_mrp (PostgreSQL 8.4), READ-ONLY.
 * Menggabungkan tiga sumber:
 *   1. Saldo SL terbaru (saldo_sl_barang, baris tgl_saldo terakhir per seq) -> stok per Expired Date.
 *   2. Forecast & pengeluaran bulan berjalan (setting_forecast, surat_jalan_*).
 *   3. Status batch 6 BULAN TERAKHIR (master_batch) -> WIP & Belum Proses,
 *      masing-masing berpasangan Jumlah + Batch Size (+ Free Stock & DOI kumulatif).
 *
 * Definisi kolom (dikonfirmasi user):
 *  - Forecast     = SUM setting_forecast.jumlah bulan berjalan (disable_date NULL).
 *  - Pengeluaran  = SUM surat_jalan_detail.jumlah bulan berjalan.
 *  - % Realisasi  = Pengeluaran / Forecast * 100 (NULL bila Forecast = 0 -> ditampilkan "-").
 *  - ED < 9 Bln   = SUM saldo yang expired < hari ini + 9 bulan.
 *  - ED >= 9 Bln  = SUM saldo yang expired >= hari ini + 9 bulan.
 *  - Total Stok   = SUM seluruh saldo (jumlah). ED<9 + ED>=9 = Total Stok.
 *  - DOI          = (Total Stok / Forecast) * 30 = perkiraan hari stok bertahan (days of inventory).
 *                   NULL bila Forecast = 0 -> ditampilkan "-".
 *  - Outstanding  = SUM sales_order_detail.sisa untuk SO bulan berjalan (status <> 2 = buang batal/tolak).
 *  - Freestock    = Total Stok - Outstanding (bisa negatif bila Outstanding > Total Stok). Menjadi
 *                   dasar seluruh Free Stock kumulatif di bawah (Karantina/WIP/Belum Proses).
 *  - DOI (Freestock) = (Freestock / Forecast) * 30. NULL bila Forecast = 0 -> "-".
 *  - FG Karantina Unit = SUM saldo_sl_barang.jumlah_karantina (stok masih di karantina).
 *  - Free Stock (Karantina) = Freestock + Unit FG Karantina.
 *  - DOI (Karantina) = (Free Stock Karantina / Forecast) * 30. NULL bila Forecast = 0 -> "-".
 *  Urutan batch di tabel: WIP dulu, baru Belum Proses (WIP lebih dekat jadi FG). Free Stock kumulatif
 *  mengikuti urutan itu:
 *  - Free Stock (WIP) = Free Stock Karantina + Batch Size WIP (kumulatif).
 *  - DOI (WIP) = (Free Stock WIP / Forecast) * 30.
 *  - Free Stock (Belum Proses) = Free Stock WIP + Batch Size Belum Proses (kumulatif).
 *  - DOI (Belum Proses) = (Free Stock Belum Proses / Forecast) * 30.
 *  - WIP          = jumlah batch: penyerahan SUDAH & karantina BELUM terisi.
 *  - Belum Proses = jumlah batch: penyerahan BELUM & karantina BELUM terisi.
 *  - Batch Size (size_*) = total batch size batch pada state itu = (jumlah batch) x batch size/batch.
 *    Batch size per produk diambil dari master_formula_barang_jadi.jumlah_fb (formula TERBARU per
 *    produk); produk tanpa formula = 0. jumlah_fb (real/float4) di-cast ke double precision.
 *    Batch dibatasi tgl_input >= CURRENT_DATE - 6 bulan.
 *
 * UNIVERSE PRODUK (yang tampil): SELURUH master_barang — semua barang ditampilkan, termasuk yang tanpa
 *  saldo & tanpa batch. Untuk produk tanpa data, kolom stok/forecast/batch = 0 (LEFT JOIN + COALESCE).
 *
 * ATURAN PENTING (sama seperti MonitoringStokReport):
 *  - PostgreSQL 8.4 TIDAK mendukung agregat FILTER (...); gunakan SUM(CASE WHEN ... END).
 *  - PostgreSQL 8.4 TIDAK punya round(double, int); persen di-cast ke ::numeric dulu.
 *  - Filter BULAN (param :ref) hanya memengaruhi kolom bulanan: Forecast, Pengeluaran, Outstanding.
 *    Stok, ED, DOI, dan Batch tetap acuan hari ini (CURRENT_DATE). Default :ref = bulan berjalan.
 *  - Sorting/pencarian ditangani di sisi aplikasi (arrange), bukan di SQL.
 */
class StokForecastReport
{
    /** Definisi kolom (key hasil query => label). Flat — dipakai untuk validasi sort & export. */
    public const COLUMNS = [
        'kode'                 => 'Kode',
        'nama'                 => 'Nama Produk',
        'batch_size'           => 'Batch Size',
        'forecast'             => 'FC',
        'pengeluaran'          => 'Pengeluaran - Keluar',
        'persen'               => 'Pengeluaran - Realisasi',
        'ed_lt9'               => '< 9 Bln',
        'ed_gte9'              => '≥ 9 Bln',
        'total_stok'           => 'Total',
        'doi'                  => 'DOI',
        'outstanding'          => 'Outstanding PO - Unit',
        'freestock'            => 'Outstanding PO - Freestock',
        'doi_free'             => 'Outstanding PO - DOI',
        'fg_karantina'         => 'FG Karantina - Unit',
        'freestock_kar'        => 'FG Karantina - Freestock',
        'doi_kar'              => 'FG Karantina - DOI',
        'size_wip'             => 'WIP - Unit',
        'freestock_wip'        => 'WIP - Freestock',
        'doi_wip'              => 'WIP - DOI',
        'size_br_belum_proses' => 'Belum Proses - Unit',
        'freestock_bp'         => 'Belum Proses - Freestock',
        'doi_bp'               => 'Belum Proses - DOI',
    ];

    /**
     * Kategori produk (berdasarkan prefix kode). 'all' = semua produk.
     *  - pilot    : kode diawali PLT.
     *  - alkes    : kode diawali AK atau PSAK.
     *  - komersil : sisanya.
     */
    public const CATEGORIES = [
        'all'      => 'Semua',
        'pilot'    => 'Pilot (PLT)',
        'alkes'    => 'Alkes (AK / PSAK)',
        'komersil' => 'Komersil',
    ];

    /** Ambang ED (bulan) bawaan untuk memilah kolom "Stok Saat Ini" (ED ≤ / ED ≥ N Bln). */
    public const ED_MONTHS_DEFAULT = 9;

    /**
     * Header bertingkat: label grup => [key kolom => sub-label].
     * Grup ber-subkolom TUNGGAL (sub-label '') dirender sbg satu kolom merged (rowspan) — mis. FORECAST.
     * Label ED memakai ambang bawaan; untuk ambang dinamis pakai {@see groups()}.
     */
    public const GROUPS = [
        'FC' => [
            'forecast' => '',
        ],
        'PENGELUARAN' => [
            'pengeluaran' => 'Keluar',
            'persen'      => '%',
        ],
        'STOK SAAT INI' => [
            'ed_lt9'     => '< 9 Bln',
            'ed_gte9'    => '≥ 9 Bln',
            'total_stok' => 'Total',
            'doi'        => 'DOI',
        ],
        'OUTSTANDING PO' => [
            'outstanding' => 'Unit',
            'freestock'   => 'Free',
            'doi_free'    => 'DOI',
        ],
        'FG KARANTINA' => [
            'fg_karantina'  => 'Unit',
            'freestock_kar' => 'Free',
            'doi_kar'       => 'DOI',
        ],
        'WIP' => [
            'size_wip'      => 'Unit',
            'freestock_wip' => 'Free',
            'doi_wip'       => 'DOI',
        ],
        'BELUM PROSES' => [
            'size_br_belum_proses' => 'Unit',
            'freestock_bp'         => 'Free',
            'doi_bp'               => 'DOI',
        ],
    ];

    private const SQL = <<<'SQL'
        WITH fb AS (
            -- Batch size STANDAR per produk = jumlah_fb dari formula TERBARU (tanggal_formula desc).
            -- Dipakai dua kali: kolom "Batch Size" per produk & perhitungan total size batch di CTE bt.
            SELECT DISTINCT ON (barang_jadi_seq)
                   barang_jadi_seq, jumlah_fb::double precision AS batch_size
            FROM master_formula_barang_jadi
            ORDER BY barang_jadi_seq, tanggal_formula DESC NULLS LAST, seq DESC
        ),
        sa AS (
            -- Agregat saldo SL TERBARU per PRODUK: ambil baris tgl_saldo paling akhir per seq.
            -- CATATAN: TANPA batas atas tanggal. Saldo SL di sistem legacy di-posting per batas
            -- periode (awal bulan) & sebagian stok/karantina terbaru bertanggal periode BERIKUTNYA
            -- (mis. tgl_saldo awal bulan depan). Membatasi `<= CURRENT_DATE` akan menyembunyikan
            -- stok/karantina yang sebenarnya sudah ada sekarang. DISTINCT ON tetap 1 baris/seq,
            -- jadi tak ada penggandaan — cukup ambil snapshot terbaru per seq.
            -- net = jumlah + karantina - holding; dipakai untuk menentukan produk "punya stok".
            SELECT sl.barang_seq,
                   SUM(CASE WHEN sl.tgl_expired <  CURRENT_DATE + (:months || ' months')::interval THEN sl.jumlah ELSE 0 END) AS ed_lt9,
                   SUM(CASE WHEN sl.tgl_expired >= CURRENT_DATE + (:months || ' months')::interval THEN sl.jumlah ELSE 0 END) AS ed_gte9,
                   SUM(sl.jumlah) AS total_stok,
                   SUM(sl.jumlah_karantina) AS karantina_unit,
                   SUM(sl.jumlah + sl.jumlah_karantina - sl.jumlah_holding) AS net
            FROM (
                SELECT DISTINCT ON (s.seq)
                       s.seq, s.barang_seq, s.tgl_expired, s.jumlah, s.jumlah_karantina, s.jumlah_holding
                FROM saldo_sl_barang s
                ORDER BY s.seq, s.tgl_saldo DESC
            ) sl
            GROUP BY sl.barang_seq
        ),
        bt AS (
            -- Status batch per PRODUK, dibatasi 6 bulan terakhir (tgl_input), dengan total batch size.
            SELECT sub.barang_seq,
                   SUM(CASE WHEN sub.tgl_penyerahan IS NULL     AND sub.karantina IS NULL     THEN 1 ELSE 0 END) AS br_belum_proses,
                   SUM(CASE WHEN sub.tgl_penyerahan IS NULL     AND sub.karantina IS NULL     THEN COALESCE(sub.batch_size, 0) ELSE 0 END) AS size_br_belum_proses,
                   SUM(CASE WHEN sub.tgl_penyerahan IS NOT NULL AND sub.karantina IS NULL     THEN 1 ELSE 0 END) AS wip,
                   SUM(CASE WHEN sub.tgl_penyerahan IS NOT NULL AND sub.karantina IS NULL     THEN COALESCE(sub.batch_size, 0) ELSE 0 END) AS size_wip
            FROM (
                SELECT m.seq         AS seq,
                       m.barang_seq  AS barang_seq,
                       pb.tanggal    AS tgl_penyerahan,
                       max(pm.tanggal) AS karantina,
                       fb.batch_size AS batch_size
                FROM master_batch m
                LEFT JOIN fb ON (fb.barang_jadi_seq = m.barang_seq)
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
                  AND m.tgl_input >= (CURRENT_DATE - INTERVAL '6 months')
                GROUP BY m.seq, m.barang_seq, pb.tanggal, fb.batch_size
            ) sub
            GROUP BY sub.barang_seq
        )
        SELECT main.barang_seq,
               main.kode,
               main.nama,
               main.batch_size,
               main.forecast,
               main.pengeluaran,
               CASE WHEN main.forecast > 0
                    THEN round((main.pengeluaran::numeric / main.forecast::numeric) * 100, 2)
                    ELSE NULL END AS persen,
               main.ed_lt9,
               main.ed_gte9,
               main.total_stok,
               CASE WHEN main.forecast > 0
                    THEN round((main.total_stok::numeric / main.forecast::numeric) * 30, 1)
                    ELSE NULL END AS doi,
               main.outstanding,
               (main.total_stok - main.outstanding) AS freestock,
               CASE WHEN main.forecast > 0
                    THEN round(((main.total_stok - main.outstanding)::numeric / main.forecast::numeric) * 30, 1)
                    ELSE NULL END AS doi_free,
               main.karantina_unit AS fg_karantina,
               ((main.total_stok - main.outstanding) + main.karantina_unit) AS freestock_kar,
               CASE WHEN main.forecast > 0
                    THEN round((((main.total_stok - main.outstanding) + main.karantina_unit)::numeric / main.forecast::numeric) * 30, 1)
                    ELSE NULL END AS doi_kar,
               COALESCE(bt.wip, 0)                  AS wip,
               COALESCE(bt.size_wip, 0)             AS size_wip,
               (((main.total_stok - main.outstanding) + main.karantina_unit) + COALESCE(bt.size_wip, 0)) AS freestock_wip,
               CASE WHEN main.forecast > 0
                    THEN round(((((main.total_stok - main.outstanding) + main.karantina_unit) + COALESCE(bt.size_wip, 0))::numeric / main.forecast::numeric) * 30, 1)
                    ELSE NULL END AS doi_wip,
               COALESCE(bt.br_belum_proses, 0)      AS br_belum_proses,
               COALESCE(bt.size_br_belum_proses, 0) AS size_br_belum_proses,
               (((main.total_stok - main.outstanding) + main.karantina_unit) + COALESCE(bt.size_wip, 0) + COALESCE(bt.size_br_belum_proses, 0)) AS freestock_bp,
               CASE WHEN main.forecast > 0
                    THEN round(((((main.total_stok - main.outstanding) + main.karantina_unit) + COALESCE(bt.size_wip, 0) + COALESCE(bt.size_br_belum_proses, 0))::numeric / main.forecast::numeric) * 30, 1)
                    ELSE NULL END AS doi_bp
        FROM (
            SELECT prod.barang_seq,
                   b.kode,
                   TRIM(b.nama || ' ' || COALESCE(bs.nama, '')) AS nama,
                   COALESCE(fbs.batch_size, 0)        AS batch_size,
                   COALESCE(fc.forecast_bulan_ini, 0) AS forecast,
                   COALESCE(sj.terjual_bulan_ini, 0)  AS pengeluaran,
                   COALESCE(sa.ed_lt9, 0)         AS ed_lt9,
                   COALESCE(sa.ed_gte9, 0)        AS ed_gte9,
                   COALESCE(sa.total_stok, 0)     AS total_stok,
                   COALESCE(sa.karantina_unit, 0) AS karantina_unit,
                   COALESCE(po.outstanding, 0)    AS outstanding
            FROM (
                -- Universe produk = SELURUH master_barang (tampilkan semua barang, termasuk yang
                -- tanpa saldo & tanpa batch). Kolom stok/forecast/batch untuk produk tanpa data
                -- menjadi 0 lewat LEFT JOIN + COALESCE di bawah.
                SELECT seq AS barang_seq FROM master_barang
            ) prod
            JOIN master_barang b               ON b.seq  = prod.barang_seq
            LEFT JOIN master_bentuk_sediaan bs ON bs.seq = b.bentuk_sediaan_seq
            LEFT JOIN fb fbs ON fbs.barang_jadi_seq = prod.barang_seq
            LEFT JOIN sa ON sa.barang_seq = prod.barang_seq
            LEFT JOIN (
                SELECT barang_seq, SUM(jumlah) AS forecast_bulan_ini
                FROM setting_forecast
                WHERE disable_date IS NULL
                  AND date_trunc('month', bulan) = date_trunc('month', :ref::date)
                GROUP BY barang_seq
            ) fc ON fc.barang_seq = prod.barang_seq
            LEFT JOIN (
                SELECT d.barang_seq, SUM(d.jumlah) AS terjual_bulan_ini
                FROM surat_jalan_master m
                JOIN surat_jalan_detail d ON d.master_seq = m.seq
                WHERE m.tanggal >= date_trunc('month', :ref::date)
                  AND m.tanggal <  date_trunc('month', :ref::date) + INTERVAL '1 month'
                GROUP BY d.barang_seq
            ) sj ON sj.barang_seq = prod.barang_seq
            LEFT JOIN (
                -- Outstanding PO = sisa Sales Order bulan berjalan (status <> 2 = buang SO batal/tolak).
                SELECT d.barang_seq, SUM(d.sisa) AS outstanding
                FROM sales_order_detail d
                JOIN sales_order_master m ON m.seq = d.master_seq
                WHERE d.sisa > 0
                  AND m.status <> 2
                  AND m.tanggal >= date_trunc('month', :ref::date)::date
                  AND m.tanggal <  (date_trunc('month', :ref::date) + INTERVAL '1 month')::date
                GROUP BY d.barang_seq
            ) po ON po.barang_seq = prod.barang_seq
        ) main
        LEFT JOIN bt ON bt.barang_seq = main.barang_seq
        SQL;

    /**
     * Ambil baris laporan mentah dari DB legacy (read-only).
     *
     * @param  string|null  $month  Bulan acuan format 'YYYY-MM' (atau 'YYYY-MM-DD'); null = bulan berjalan.
     *   Hanya memengaruhi kolom bulanan: Forecast, Pengeluaran, Outstanding. Stok/ED/DOI/Batch tetap
     *   acuan hari ini (CURRENT_DATE). Param :ref dipakai berulang (koneksi pgsql EMULATE_PREPARES).
     * @return array<int,\stdClass>
     */
    public function rows(?string $month = null, int $edMonths = self::ED_MONTHS_DEFAULT): array
    {
        $ref = ($month !== null && $month !== '')
            ? (strlen($month) === 7 ? $month.'-01' : $month)
            : now()->format('Y-m-01');

        return DB::connection('pgsql')->select(self::SQL, [
            'ref'    => $ref,
            'months' => self::sanitizeEdMonths($edMonths),
        ]);
    }

    /** Ambang ED (bulan) selalu bilangan bulat >= 1; nilai tak valid dikembalikan ke bawaan. */
    public static function sanitizeEdMonths(int $edMonths): int
    {
        return $edMonths >= 1 ? $edMonths : self::ED_MONTHS_DEFAULT;
    }

    /**
     * Salinan {@see GROUPS} dengan label ED memakai ambang $edMonths (mis. "ED ≤ 6 Bln").
     * Dipakai oleh tabel Livewire, export Excel, dan PDF supaya header mengikuti filter.
     */
    public static function groups(int $edMonths = self::ED_MONTHS_DEFAULT): array
    {
        $m = self::sanitizeEdMonths($edMonths);
        $g = self::GROUPS;
        $g['STOK SAAT INI']['ed_lt9']  = '< '.$m.' Bln';
        $g['STOK SAAT INI']['ed_gte9'] = '≥ '.$m.' Bln';

        return $g;
    }

    /** Apakah $kode termasuk kategori $category (lihat {@see CATEGORIES}). */
    public static function matchesCategory(string $kode, string $category): bool
    {
        $k = strtoupper(ltrim($kode));

        return match ($category) {
            'pilot'    => str_starts_with($k, 'PLT'),
            'alkes'    => str_starts_with($k, 'AK') || str_starts_with($k, 'PSAK'),
            'komersil' => ! (str_starts_with($k, 'PLT') || str_starts_with($k, 'AK') || str_starts_with($k, 'PSAK')),
            default    => true, // 'all' / nilai tak dikenal
        };
    }

    /** SQL detail batch per PRODUK + STATE (window & logika state SAMA dengan agregat bt di atas). */
    private const BATCH_DETAIL_SQL = <<<'SQL'
        SELECT d.no_batch, d.kode_batch, d.tgl_input, d.tgl_expired, d.tgl_penyerahan, d.karantina, d.batch_size
        FROM (
            SELECT m.seq, m.no_batch, m.kode_batch, m.tgl_input, m.tgl_expired,
                   pb.tanggal AS tgl_penyerahan, max(pm.tanggal) AS karantina,
                   fb.jumlah_fb::double precision AS batch_size
            FROM master_batch m
            LEFT JOIN (
                SELECT DISTINCT ON (barang_jadi_seq) barang_jadi_seq, jumlah_fb
                FROM master_formula_barang_jadi
                ORDER BY barang_jadi_seq, tanggal_formula DESC NULLS LAST, seq DESC
            ) fb ON (fb.barang_jadi_seq = m.barang_seq)
            LEFT JOIN penerimaan_barang_detail pd ON (m.seq = pd.batch_seq AND pd.barang_seq = m.barang_seq)
            LEFT JOIN penerimaan_barang_master pm ON (pm.seq = pd.master_seq)
            LEFT JOIN pengeluaran_barang_master pb ON (pb.barang_jadi_seq = m.barang_seq AND pb.batch_seq = m.seq
                AND pb.tanggal = (SELECT MAX(pb2.tanggal) FROM pengeluaran_barang_master pb2
                                  WHERE pb2.barang_jadi_seq = m.barang_seq AND pb2.batch_seq = m.seq))
            WHERE m.barang_seq = :barang
              AND m.tgl_input >= (CURRENT_DATE - INTERVAL '6 months')
            GROUP BY m.seq, m.no_batch, m.kode_batch, m.tgl_input, m.tgl_expired, pb.tanggal, fb.jumlah_fb
        ) d
        WHERE (:state = 'wip'   AND d.tgl_penyerahan IS NOT NULL AND d.karantina IS NULL)
           OR (:state = 'belum' AND d.tgl_penyerahan IS NULL     AND d.karantina IS NULL)
        ORDER BY d.tgl_input DESC
        SQL;

    /**
     * Detail batch yang menyusun angka WIP / Belum Proses pada satu produk (untuk drill-down klik).
     *
     * @param  string  $state  'wip' atau 'belum'.
     * @return array<int,\stdClass>
     */
    public function batchDetail(int $barangSeq, string $state): array
    {
        if (! in_array($state, ['wip', 'belum'], true)) {
            return [];
        }

        return DB::connection('pgsql')->select(self::BATCH_DETAIL_SQL, [
            'barang' => $barangSeq,
            'state'  => $state,
        ]);
    }

    /**
     * SQL detail saldo yang menyusun kolom "Stok Saat Ini" (ED ≤ 9 / ED ≥ 9 Bln) per PRODUK.
     * Sumber & logika bucket SAMA dengan CTE `sa` di atas: baris saldo terbaru per seq (tgl_saldo
     * terakhir), lalu dipilah berdasarkan tgl_expired terhadap CURRENT_DATE + 9 bulan. Dikelompokkan
     * per batch (kode_batch + no_batch + ED); baris tgl_expired NULL tidak masuk bucket manapun.
     */
    private const STOK_DETAIL_SQL = <<<'SQL'
        SELECT mb.no_batch, d.kode_batch, d.tgl_expired,
               SUM(d.jumlah) AS jumlah,
               SUM(d.jumlah_karantina) AS jumlah_karantina
        FROM (
            SELECT DISTINCT ON (s.seq)
                   s.seq, s.batch_seq, s.kode_batch, s.tgl_expired, s.jumlah, s.jumlah_karantina
            FROM saldo_sl_barang s
            WHERE s.barang_seq = :barang
            ORDER BY s.seq, s.tgl_saldo DESC
        ) d
        LEFT JOIN master_batch mb ON mb.seq = d.batch_seq
        WHERE (:bucket = 'lt9'  AND d.tgl_expired <  CURRENT_DATE + (:months || ' months')::interval)
           OR (:bucket = 'gte9' AND d.tgl_expired >= CURRENT_DATE + (:months || ' months')::interval)
        GROUP BY mb.no_batch, d.kode_batch, d.tgl_expired
        HAVING SUM(d.jumlah) <> 0
        ORDER BY d.tgl_expired
        SQL;

    /**
     * Detail saldo (per batch) yang menyusun angka ED ≤ 9 Bln / ED ≥ 9 Bln pada satu produk.
     *
     * @param  string  $bucket  'lt9' (ED < 9 bln) atau 'gte9' (ED >= 9 bln).
     * @return array<int,\stdClass>
     */
    public function stokDetail(int $barangSeq, string $bucket, int $edMonths = self::ED_MONTHS_DEFAULT): array
    {
        if (! in_array($bucket, ['lt9', 'gte9'], true)) {
            return [];
        }

        return DB::connection('pgsql')->select(self::STOK_DETAIL_SQL, [
            'barang' => $barangSeq,
            'bucket' => $bucket,
            'months' => self::sanitizeEdMonths($edMonths),
        ]);
    }

    /**
     * SQL detail saldo yang menyusun angka "FG Karantina - Unit" (= SUM jumlah_karantina) per PRODUK.
     * Sumber sama dengan CTE `sa`: baris saldo terbaru per seq. Tanpa filter ED — semua batch yang
     * punya stok karantina. Kolom `jumlah` di-alias dari jumlah_karantina agar cocok dengan modal
     * drill-down stok yang sama.
     */
    private const KARANTINA_DETAIL_SQL = <<<'SQL'
        SELECT mb.no_batch, d.kode_batch, d.tgl_expired,
               SUM(d.jumlah_karantina) AS jumlah
        FROM (
            SELECT DISTINCT ON (s.seq)
                   s.seq, s.batch_seq, s.kode_batch, s.tgl_expired, s.jumlah_karantina
            FROM saldo_sl_barang s
            WHERE s.barang_seq = :barang
            ORDER BY s.seq, s.tgl_saldo DESC
        ) d
        LEFT JOIN master_batch mb ON mb.seq = d.batch_seq
        GROUP BY mb.no_batch, d.kode_batch, d.tgl_expired
        HAVING SUM(d.jumlah_karantina) <> 0
        ORDER BY d.tgl_expired
        SQL;

    /**
     * Detail saldo (per batch) yang menyusun angka FG Karantina - Unit pada satu produk.
     * Bentuk baris sama dgn {@see stokDetail()} (no_batch, kode_batch, tgl_expired, jumlah).
     *
     * @return array<int,\stdClass>
     */
    public function karantinaDetail(int $barangSeq): array
    {
        return DB::connection('pgsql')->select(self::KARANTINA_DETAIL_SQL, [
            'barang' => $barangSeq,
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
    public static function arrange(array $rows, string $search = '', string $sortField = 'nama', string $sortDir = 'asc', string $category = 'all'): \Illuminate\Support\Collection
    {
        $out = collect($rows);

        if ($category !== '' && $category !== 'all') {
            $out = $out->filter(fn ($r) => self::matchesCategory((string) $r->kode, $category));
        }

        if ($search !== '') {
            $q = mb_strtolower($search);
            $out = $out->filter(fn ($r) =>
                str_contains(mb_strtolower((string) $r->kode), $q)
                || str_contains(mb_strtolower((string) $r->nama), $q)
            );
        }

        if (! array_key_exists($sortField, self::COLUMNS)) {
            $sortField = 'nama';
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
