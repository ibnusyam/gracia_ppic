<?php

namespace App\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Laporan "Monitoring Stok & Forecast".
 *
 * Agregasi per PRODUK (kode + nama) dari DB legacy gracia_mrp (PostgreSQL 8.4), READ-ONLY.
 * Menggabungkan tiga sumber:
 *   1. Saldo SL terbaru (saldo_sl_barang, baris tgl_saldo terakhir per seq) -> stok per Expired Date.
 *   2. Forecast & pengeluaran bulan berjalan (setting_forecast, pengeluaran_produk_*).
 *   3. Status batch 6 BULAN TERAKHIR (master_batch) -> WIP & Belum Proses,
 *      masing-masing berpasangan Jumlah + Batch Size (+ Free Stock & DOI kumulatif).
 *
 * Definisi kolom (dikonfirmasi user):
 *  - Forecast     = SUM setting_forecast.jumlah bulan berjalan (disable_date NULL).
 *  - Pengeluaran  = SUM pemakaian_barang.jumlah (mutasi keluar via surat jalan) satu bulan penuh,
 *                   KOMERSIL saja (SO komersil, buang stok repack). Lihat CTE sj.
 *  - % Realisasi  = Pengeluaran / Forecast * 100 (NULL bila Forecast = 0 -> ditampilkan "-").
 *  - ED < 9 Bln   = SUM saldo yang expired < hari ini + 9 bulan.
 *  - ED >= 9 Bln  = SUM saldo yang expired >= hari ini + 9 bulan.
 *  - Total Stok   = SUM seluruh saldo (jumlah). ED<9 + ED>=9 = Total Stok.
 *  - MOI          = Total Stok / Forecast = perkiraan bulan stok bertahan (months of inventory).
 *                   NULL bila Forecast = 0 -> ditampilkan "-".
 *  - Outstanding  = SUM sales_order_detail.sisa untuk SO bulan berjalan (status <> 2 = buang batal/tolak).
 *  - Freestock    = Total Stok - Outstanding (bisa negatif bila Outstanding > Total Stok). Menjadi
 *                   dasar seluruh Free Stock kumulatif di bawah (Karantina/WIP/Belum Proses).
 *  - MOI (Freestock) = Freestock / Forecast. NULL bila Forecast = 0 -> "-".
 *  - FG Karantina Unit = SUM saldo_sl_barang.jumlah_karantina (stok masih di karantina).
 *  - Free Stock (Karantina) = Freestock + Unit FG Karantina.
 *  - MOI (Karantina) = Free Stock Karantina / Forecast. NULL bila Forecast = 0 -> "-".
 *  Urutan batch di tabel: WIP dulu, baru Belum Proses (WIP lebih dekat jadi FG). Free Stock kumulatif
 *  mengikuti urutan itu:
 *  - Free Stock (WIP) = Free Stock Karantina + AKTUAL WIP (kumulatif).
 *  - MOI (WIP) = Free Stock WIP / Forecast.
 *  - Free Stock (Belum Proses) = Free Stock WIP + AKTUAL Belum Proses (kumulatif).
 *  - MOI (Belum Proses) = Free Stock Belum Proses / Forecast.
 *  Free & MOI WIP/Belum Proses memakai kolom AKTUAL (batch_aktual), BUKAN Unit (batch size standar).
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
        'sl'                   => 'SL',
        'batch_size'           => 'Batch Size',
        'forecast'             => 'FC',
        'pengeluaran'          => 'Sales - Keluar',
        'persen'               => 'Sales - Realisasi',
        'ed_lt9'               => '< 9 Bln',
        'ed_gte9'              => '≥ 9 Bln',
        'total_stok'           => 'Total',
        'doi'                  => 'MOI',
        'outstanding'          => 'Outstanding PO - Unit',
        'freestock'            => 'Outstanding PO - Freestock',
        'doi_free'             => 'Outstanding PO - MOI',
        'fg_karantina'         => 'FG Karantina - Unit',
        'freestock_kar'        => 'FG Karantina - Freestock',
        'doi_kar'              => 'FG Karantina - MOI',
        'size_wip'             => 'WIP - Unit',
        'akt_wip'              => 'WIP - Aktual',
        'freestock_wip'        => 'WIP - Freestock',
        'doi_wip'              => 'WIP - MOI',
        'size_br_belum_proses' => 'Belum Proses - Unit',
        'akt_belum'            => 'Belum Proses - Aktual',
        'freestock_bp'         => 'Belum Proses - Freestock',
        'doi_bp'               => 'Belum Proses - MOI',
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

    /**
     * Status produk berdasarkan master_barang.disable_date:
     * NULL = masih aktif, terisi = sudah dinonaktifkan.
     */
    public const STATUSES = [
        'aktif'    => 'Aktif',
        'nonaktif' => 'Nonaktif',
        'semua'    => 'Semua',
    ];

    /** Status default saat halaman dibuka. */
    public const STATUS_DEFAULT = 'aktif';

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
        'SALES' => [
            'pengeluaran' => 'Keluar',
            'persen'      => '%',
        ],
        'STOK SAAT INI' => [
            'ed_lt9'     => '< 9 Bln',
            'ed_gte9'    => '≥ 9 Bln',
            'total_stok' => 'Total',
            'doi'        => 'MOI',
        ],
        'OUTSTANDING PO' => [
            'outstanding' => 'Unit',
            'freestock'   => 'Free',
            'doi_free'    => 'MOI',
        ],
        'FG KARANTINA' => [
            'fg_karantina'  => 'Unit',
            'freestock_kar' => 'Free',
            'doi_kar'       => 'MOI',
        ],
        'WIP' => [
            'size_wip'      => 'Unit',
            'akt_wip'       => 'Aktual',
            'freestock_wip' => 'Free',
            'doi_wip'       => 'MOI',
        ],
        'BELUM PROSES' => [
            'size_br_belum_proses' => 'Unit',
            'akt_belum'            => 'Aktual',
            'freestock_bp'         => 'Free',
            'doi_bp'               => 'MOI',
        ],
    ];

    private const SQL = <<<'SQL'
        WITH fb AS (
            -- Batch size STANDAR per produk = jumlah_fb dari formula TERBARU (tanggal_formula desc).
            -- Dipakai dua kali: kolom "Batch Size" per produk & perhitungan total size batch di CTE bt.
            SELECT DISTINCT ON (barang_jadi_seq)
                   barang_jadi_seq,
                   jumlah_fb::double precision AS batch_size,
                   umur_obat::double precision AS umur_obat
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
        bt_src AS (
            -- Satu baris per BATCH FISIK (master_batch.seq) berstatus WIP/Belum dalam 6 bln terakhir,
            -- membawa: status, grup sub-batch, batch size STANDAR (formula), & batch size AKTUAL.
            SELECT sub.barang_seq,
                   sub.grp,
                   CASE WHEN sub.tgl_penyerahan IS NOT NULL THEN 'wip' ELSE 'belum' END AS state,
                   COALESCE(sub.batch_size, 0)   AS batch_size,
                   COALESCE(sub.batch_aktual, 0) AS batch_aktual
            FROM (
                SELECT m.seq         AS seq,
                       m.barang_seq  AS barang_seq,
                       -- Penyerahan = tgl pengeluaran barang jadi. Bisa lewat jalur NORMAL
                       -- (barang_jadi_seq/batch_seq) ATAU jalur MAKLON (dikirim ke pihak maklon,
                       -- barang_jadi_seq=batch_seq=0, dicatat di makloon_bj_seq/makloon_batch_seq).
                       (SELECT MAX(pb.tanggal) FROM pengeluaran_barang_master pb
                         WHERE (pb.barang_jadi_seq = m.barang_seq AND pb.batch_seq = m.seq)
                            OR (pb.makloon_bj_seq = m.barang_seq AND pb.makloon_batch_seq = m.seq)) AS tgl_penyerahan,
                       max(pm.tanggal) AS karantina,
                       fb.batch_size AS batch_size,
                       m.batch_aktual AS batch_aktual,
                       -- Grup sub-batch: 5 karakter depan, HANYA bila sisanya <= 2 karakter & tanpa
                       -- angka (mis. 'A', 'AX', '.'). Nomor batch berskema panjang seperti
                       -- '02635102037' atau '26HS04.004B' TIDAK digabung walau 5 digit depannya sama.
                       CASE WHEN m.no_batch IS NULL OR m.no_batch = '' THEN 'SEQ:' || m.seq
                            WHEN length(m.no_batch) BETWEEN 5 AND 7
                                 AND substr(m.no_batch, 6) !~ '[0-9]' THEN substr(m.no_batch, 1, 5)
                            ELSE m.no_batch END AS grp
                FROM master_batch m
                LEFT JOIN fb ON (fb.barang_jadi_seq = m.barang_seq)
                LEFT JOIN penerimaan_barang_detail pd
                       ON (m.seq = pd.batch_seq AND pd.barang_seq = m.barang_seq)
                LEFT JOIN penerimaan_barang_master pm
                       ON (pm.seq = pd.master_seq)
                WHERE m.seq IS NOT NULL
                  AND m.tgl_input >= (CURRENT_DATE - INTERVAL '6 months')
                GROUP BY m.seq, m.barang_seq, m.no_batch, m.batch_aktual, fb.batch_size
            ) sub
            WHERE sub.karantina IS NULL
        ),
        bt AS (
            -- Kolom "Unit" (batch size STANDAR): sub-batch (mis. IE341A/B/C/D) = SATU batch fisik yg
            -- dipecah, tiap barisnya membawa batch size penuh. Karena itu di-DEDUPE per (produk, status,
            -- grup) supaya tidak berlipat. Dedupe PER STATUS: bila sebagian sub-batch sudah WIP dan
            -- sisanya Belum Proses, masing-masing kolom menghitungnya satu kali.
            SELECT g.barang_seq,
                   SUM(CASE WHEN g.state = 'belum' THEN 1 ELSE 0 END)            AS br_belum_proses,
                   SUM(CASE WHEN g.state = 'belum' THEN g.batch_size ELSE 0 END) AS size_br_belum_proses,
                   SUM(CASE WHEN g.state = 'wip'   THEN 1 ELSE 0 END)            AS wip,
                   SUM(CASE WHEN g.state = 'wip'   THEN g.batch_size ELSE 0 END) AS size_wip
            FROM (
                SELECT s.barang_seq, s.grp, s.state, MAX(s.batch_size) AS batch_size
                FROM bt_src s
                GROUP BY s.barang_seq, s.grp, s.state
            ) g
            GROUP BY g.barang_seq
        ),
        bta AS (
            -- Kolom "Unit Aktual" (batch size AKTUAL): SUM SELURUH batch tanpa dedupe grup —
            -- semua sub-batch (A/B/C/…) dijumlahkan apa adanya sesuai permintaan.
            SELECT s.barang_seq,
                   SUM(CASE WHEN s.state = 'wip'   THEN s.batch_aktual ELSE 0 END) AS akt_wip,
                   SUM(CASE WHEN s.state = 'belum' THEN s.batch_aktual ELSE 0 END) AS akt_belum
            FROM bt_src s
            GROUP BY s.barang_seq
        )
        SELECT main.barang_seq,
               main.kode,
               main.nama,
               main.sl,
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
                    THEN round((main.total_stok::numeric / main.forecast::numeric), 1)
                    ELSE NULL END AS doi,
               main.outstanding,
               (main.total_stok - main.outstanding) AS freestock,
               CASE WHEN main.forecast > 0
                    THEN round(((main.total_stok - main.outstanding)::numeric / main.forecast::numeric), 1)
                    ELSE NULL END AS doi_free,
               main.karantina_unit AS fg_karantina,
               ((main.total_stok - main.outstanding) + main.karantina_unit) AS freestock_kar,
               CASE WHEN main.forecast > 0
                    THEN round((((main.total_stok - main.outstanding) + main.karantina_unit)::numeric / main.forecast::numeric), 1)
                    ELSE NULL END AS doi_kar,
               COALESCE(bt.wip, 0)                  AS wip,
               COALESCE(bt.size_wip, 0)             AS size_wip,
               COALESCE(bta.akt_wip, 0)             AS akt_wip,
               -- Free WIP = Free FG Karantina + AKTUAL WIP.
               (((main.total_stok - main.outstanding) + main.karantina_unit) + COALESCE(bta.akt_wip, 0)) AS freestock_wip,
               CASE WHEN main.forecast > 0
                    THEN round(((((main.total_stok - main.outstanding) + main.karantina_unit) + COALESCE(bta.akt_wip, 0))::numeric / main.forecast::numeric), 1)
                    ELSE NULL END AS doi_wip,
               COALESCE(bt.br_belum_proses, 0)      AS br_belum_proses,
               COALESCE(bt.size_br_belum_proses, 0) AS size_br_belum_proses,
               COALESCE(bta.akt_belum, 0)           AS akt_belum,
               -- Free Belum Proses = Free WIP + AKTUAL Belum Proses.
               (((main.total_stok - main.outstanding) + main.karantina_unit) + COALESCE(bta.akt_wip, 0) + COALESCE(bta.akt_belum, 0)) AS freestock_bp,
               CASE WHEN main.forecast > 0
                    THEN round(((((main.total_stok - main.outstanding) + main.karantina_unit) + COALESCE(bta.akt_wip, 0) + COALESCE(bta.akt_belum, 0))::numeric / main.forecast::numeric), 1)
                    ELSE NULL END AS doi_bp
        FROM (
            SELECT prod.barang_seq,
                   b.kode,
                   TRIM(b.nama || ' ' || COALESCE(bs.nama, '')) AS nama,
                   fbs.umur_obat                      AS sl,
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
                SELECT seq AS barang_seq FROM master_barang WHERE 1=1 /*__STATUS__*/
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
                -- Pengeluaran KOMERSIL = mereproduksi PERSIS laporan legacy "Pengeluaran Produk VS
                -- Forecast" (tombol "Load Keluar vs Forecast"), divalidasi 100%. Sumbernya MUTASI STOK (pemakaian_barang), bukan
                -- pengeluaran_produk_detail, ditaut lewat surat jalan + sales order:
                --  * p.tipe_trans = 10           -> hanya mutasi keluar normal (5/11 = retur/koreksi).
                --  * so.tipe % 10 NOT IN (1,2,5,6) -> hanya SO komersil (buang tender/khusus, mis.
                --                                    21,22,26,31,36 & 35 = pemusnahan).
                --  * stok line jenis_barang = 1  -> HANYA stok KOMERSIL (= dropdown "Jenis Barang
                --                                    [komersil]" di legacy). Dibuang: 2 = repack (mis.
                --                                    OJ043P IF126, OJ437P IA032), 3 (mis. PLT335P), 4
                --                                    (mis. OJ254-P IH109). Dinilai per stok line.
                -- Divalidasi 100%: Sept full 186/186 & Okt 01-05 92/92 (nilai & daftar produk).
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
        LEFT JOIN bta ON bta.barang_seq = main.barang_seq
        SQL;

    /**
     * Ambil baris laporan mentah dari DB legacy (read-only).
     *
     * @param  string|null  $month  Bulan acuan format 'YYYY-MM' (atau 'YYYY-MM-DD'); null = bulan berjalan.
     *   Hanya memengaruhi kolom bulanan: Forecast, Pengeluaran, Outstanding. Stok/ED/DOI/Batch tetap
     *   acuan hari ini (CURRENT_DATE). Param :ref dipakai berulang (koneksi pgsql EMULATE_PREPARES).
     * @param  string  $status  Filter status produk: 'aktif' (default), 'nonaktif', atau 'semua'.
     * @return array<int,\stdClass>
     */
    public function rows(?string $month = null, int $edMonths = self::ED_MONTHS_DEFAULT, string $status = self::STATUS_DEFAULT): array
    {
        $ref = ($month !== null && $month !== '')
            ? (strlen($month) === 7 ? $month.'-01' : $month)
            : now()->format('Y-m-01');

        // Fragmen SQL dipilih dari whitelist (bukan nilai user langsung) → aman dari injeksi.
        $statusFilter = match ($status) {
            'nonaktif' => 'AND disable_date IS NOT NULL',
            'semua'    => '',
            default    => 'AND disable_date IS NULL', // 'aktif'
        };

        $sql = str_replace('/*__STATUS__*/', $statusFilter, self::SQL);

        return DB::connection('pgsql')->select($sql, [
            'ref'    => $ref,
            'months' => self::sanitizeEdMonths($edMonths),
        ]);
    }

    /** Status produk selalu salah satu key {@see STATUSES}; nilai tak valid dikembalikan ke bawaan. */
    public static function sanitizeStatus(string $status): string
    {
        return array_key_exists($status, self::STATUSES) ? $status : self::STATUS_DEFAULT;
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
        -- SEMUA sub-batch dikembalikan (A, B, C, ...) lengkap dengan tanggalnya, disertai kolom `grp`
        -- supaya pemanggil bisa menyusunnya bertingkat: satu baris per grup + rincian sub-batch.
        SELECT d.grp, d.no_batch, d.kode_batch, d.tgl_input, d.tgl_expired, d.tgl_penyerahan, d.karantina, d.batch_size, d.batch_aktual
        FROM (
            SELECT m.seq, m.no_batch, m.kode_batch, m.tgl_input, m.tgl_expired,
                   (SELECT MAX(pb.tanggal) FROM pengeluaran_barang_master pb
                     WHERE (pb.barang_jadi_seq = m.barang_seq AND pb.batch_seq = m.seq)
                        OR (pb.makloon_bj_seq = m.barang_seq AND pb.makloon_batch_seq = m.seq)) AS tgl_penyerahan,
                   max(pm.tanggal) AS karantina,
                   fb.jumlah_fb::double precision AS batch_size,
                   m.batch_aktual AS batch_aktual,
                   CASE WHEN m.no_batch IS NULL OR m.no_batch = '' THEN 'SEQ:' || m.seq
                        WHEN length(m.no_batch) BETWEEN 5 AND 7
                             AND substr(m.no_batch, 6) !~ '[0-9]' THEN substr(m.no_batch, 1, 5)
                        ELSE m.no_batch END AS grp
            FROM master_batch m
            LEFT JOIN (
                SELECT DISTINCT ON (barang_jadi_seq) barang_jadi_seq, jumlah_fb
                FROM master_formula_barang_jadi
                ORDER BY barang_jadi_seq, tanggal_formula DESC NULLS LAST, seq DESC
            ) fb ON (fb.barang_jadi_seq = m.barang_seq)
            LEFT JOIN penerimaan_barang_detail pd ON (m.seq = pd.batch_seq AND pd.barang_seq = m.barang_seq)
            LEFT JOIN penerimaan_barang_master pm ON (pm.seq = pd.master_seq)
            WHERE m.barang_seq = :barang
              AND m.tgl_input >= (CURRENT_DATE - INTERVAL '6 months')
            GROUP BY m.seq, m.barang_seq, m.no_batch, m.kode_batch, m.tgl_input, m.tgl_expired, m.batch_aktual, fb.jumlah_fb
        ) d
        WHERE (:state = 'wip'   AND d.tgl_penyerahan IS NOT NULL AND d.karantina IS NULL)
           OR (:state = 'belum' AND d.tgl_penyerahan IS NULL     AND d.karantina IS NULL)
        ORDER BY d.tgl_input DESC, d.grp, d.no_batch
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
