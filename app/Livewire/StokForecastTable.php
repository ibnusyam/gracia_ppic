<?php

namespace App\Livewire;

use App\Models\TollOutProduct;
use App\Reports\StokForecastReport;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tabel "Monitoring Stok & Forecast".
 *
 * Filter BULAN ($month, format 'YYYY-MM') hanya memengaruhi kolom bulanan (Forecast, Pengeluaran,
 * Outstanding); Stok/ED/DOI/Batch tetap acuan hari ini. Data langsung dimuat saat halaman dibuka
 * (query < 1 dtk & di-cache 5 menit per bulan). Pencarian kode/nama, sorting, & pagination
 * ditangani di sisi aplikasi terhadap baris yang sudah di-cache.
 */
class StokForecastTable extends Component
{
    use WithPagination;

    /** Prefix cache baris laporan. Naikkan versinya setiap kali susunan kolom baris berubah. */
    private const CACHE_VERSION = 'stok_forecast:v11';

    /** Bulan acuan 'YYYY-MM' untuk kolom Forecast/Pengeluaran/Outstanding. Default = bulan berjalan. */
    #[Url(history: true)]
    public string $month = '';

    #[Url(history: true)]
    public string $search = '';

    /** Kategori produk (all/pilot/alkes/komersil) — filter berdasarkan prefix kode. */
    #[Url(history: true)]
    public string $category = 'all';

    /** Status produk (aktif/nonaktif/semua) — berdasarkan master_barang.disable_date. */
    #[Url(history: true)]
    public string $status = StokForecastReport::STATUS_DEFAULT;

    /** Ambang ED (bulan) pemisah kolom Stok Saat Ini (ED ≤ / ED ≥ N Bln). */
    #[Url(history: true)]
    public int $edMonths = StokForecastReport::ED_MONTHS_DEFAULT;

    #[Url]
    public string $sortField = 'nama';

    #[Url]
    public string $sortDir = 'asc';

    /** Baris per halaman; 0 = tampilkan semua (tanpa pagination). */
    public int $perPage = 25;

    /** State modal drill-down detail batch (klik kolom Jumlah WIP / Belum Proses). */
    public bool $showBatchModal = false;

    /** @var array<int,array<string,mixed>> */
    public array $batchRows = [];

    public string $batchTitle = '';

    /**
     * Mode isi modal batch:
     *  - 'std' : kolom Unit (batch size standar), baris bertingkat (grup + sub-batch).
     *  - 'akt' : kolom Unit Aktual (batch_aktual), daftar DATAR semua batch tanpa dedupe.
     */
    public string $batchMode = 'std';

    /** Total kolom size pada modal 'akt' (jumlah batch_aktual seluruh baris). */
    public float $batchTotal = 0.0;

    /** State modal drill-down detail saldo (klik kolom ED ≤ 9 / ED ≥ 9 Bln di grup Stok Saat Ini). */
    public bool $showStokModal = false;

    /** @var array<int,array<string,mixed>> */
    public array $stokRows = [];

    public string $stokTitle = '';

    public float $stokTotal = 0.0;

    /** Jam terakhir data disegarkan manual (tampil sekilas sbg konfirmasi tombol Segarkan). */
    public string $refreshedAt = '';

    public function mount(): void
    {
        if ($this->month === '') {
            $this->month = now()->format('Y-m');
        }
    }

    /**
     * Buang cache baris untuk kombinasi filter yang sedang dilihat, lalu muat ulang data segar
     * dari DB. Dipakai tombol "Segarkan data" agar perubahan di DB langsung terlihat tanpa
     * menunggu cache 5 menit kedaluwarsa.
     */
    public function refreshData(): void
    {
        Cache::forget($this->cacheKey());
        $this->resetPage();
        $this->refreshedAt = now()->format('H:i:s');
    }

    /** Terapkan filter bulan / pencarian yang sedang diketik (form submit). */
    public function apply(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDir = 'asc';
        }
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'category', 'edMonths', 'status']);
        $this->month = now()->format('Y-m');
        $this->resetPage();
    }

    /** Buka modal berisi daftar batch yang menyusun angka WIP / Belum Proses satu produk. */
    public function showBatches(int $barangSeq, string $state, string $kode, string $nama): void
    {
        $rows = array_map(fn ($r) => (array) $r, (new StokForecastReport())->batchDetail($barangSeq, $state));

        // Susun bertingkat: satu baris per GRUP sub-batch (wakil = no_batch terkecil, biasanya 'A')
        // agar cocok dgn angka Unit yang dihitung per grup, dgn rincian sub-batch (A/B/C/…) di dalamnya.
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['grp']][] = $r;
        }

        $this->batchRows = array_values(array_map(function (array $subs) {
            usort($subs, fn ($a, $b) => strcmp((string) $a['no_batch'], (string) $b['no_batch']));
            $rep = $subs[0];
            $rep['subs'] = $subs;
            $rep['n_sub'] = count($subs);

            return $rep;
        }, $groups));

        $label = $state === 'wip' ? 'WIP' : 'Belum Proses';
        $nSub = count($rows);
        $nGrp = count($this->batchRows);
        $this->batchMode = 'std';
        $this->batchTotal = 0.0;
        $this->batchTitle = $label.' — '.$kode.' '.trim($nama).' ('.$nGrp.' batch'
            .($nSub > $nGrp ? ' / '.$nSub.' sub-batch' : '').')';
        $this->showBatchModal = true;
    }

    /**
     * Buka modal Unit Aktual: DAFTAR DATAR semua batch (tanpa dedupe grup) yang sedang WIP / Belum
     * Proses, menampilkan batch_aktual tiap batch. Konsisten dgn kolom "Unit Aktual" yang juga
     * menjumlahkan seluruh batch.
     */
    public function showBatchesAktual(int $barangSeq, string $state, string $kode, string $nama): void
    {
        $this->batchRows = array_map(fn ($r) => (array) $r, (new StokForecastReport())->batchDetail($barangSeq, $state));
        $this->batchTotal = array_sum(array_map(fn ($r) => (float) ($r['batch_aktual'] ?? 0), $this->batchRows));

        $label = $state === 'wip' ? 'WIP' : 'Belum Proses';
        $this->batchMode = 'akt';
        $this->batchTitle = 'Unit Aktual — '.$label.' — '.$kode.' '.trim($nama).' ('.count($this->batchRows).' batch)';
        $this->showBatchModal = true;
    }

    public function closeBatchModal(): void
    {
        $this->showBatchModal = false;
        $this->batchRows = [];
        $this->batchTitle = '';
        $this->batchMode = 'std';
        $this->batchTotal = 0.0;
    }

    /** Buka modal berisi daftar saldo (per batch) yang menyusun angka ED ≤ 9 / ED ≥ 9 Bln satu produk. */
    public function showStok(int $barangSeq, string $bucket, string $kode, string $nama): void
    {
        $rows = (new StokForecastReport())->stokDetail($barangSeq, $bucket, $this->edMonths);
        $this->stokRows = array_map(fn ($r) => (array) $r, $rows);
        $this->stokTotal = array_sum(array_map(fn ($r) => (float) $r['jumlah'], $this->stokRows));

        $m = StokForecastReport::sanitizeEdMonths($this->edMonths);
        $label = $bucket === 'lt9' ? 'ED ≤ '.$m.' Bln' : 'ED ≥ '.$m.' Bln';
        $this->stokTitle = $label.' — '.$kode.' '.trim($nama).' ('.count($this->stokRows).' batch)';
        $this->showStokModal = true;
    }

    /** Buka modal (memakai ulang modal stok) berisi rincian saldo karantina per batch satu produk. */
    public function showKarantina(int $barangSeq, string $kode, string $nama): void
    {
        $rows = (new StokForecastReport())->karantinaDetail($barangSeq);
        $this->stokRows = array_map(fn ($r) => (array) $r, $rows);
        $this->stokTotal = array_sum(array_map(fn ($r) => (float) $r['jumlah'], $this->stokRows));

        $this->stokTitle = 'FG Karantina — '.$kode.' '.trim($nama).' ('.count($this->stokRows).' batch)';
        $this->showStokModal = true;
    }

    public function closeStokModal(): void
    {
        $this->showStokModal = false;
        $this->stokRows = [];
        $this->stokTitle = '';
        $this->stokTotal = 0.0;
    }

    /**
     * Baris mentah dari DB legacy, di-cache per bulan (query gabungan ~0,7 dtk). Disimpan sebagai
     * array biasa (bukan stdClass) supaya aman di-serialize oleh cache store, lalu
     * di-rehidrasi kembali menjadi objek.
     */
    /**
     * Key cache baris untuk kombinasi filter (bulan/ED/status) yang sedang aktif. Diberi versi:
     * bila susunan kolom baris berubah, entri cache lama (bentuk berbeda) otomatis diabaikan
     * alih-alih memicu "Undefined property" di view.
     */
    protected function cacheKey(): string
    {
        $month = $this->month !== '' ? $this->month : now()->format('Y-m');
        $edMonths = StokForecastReport::sanitizeEdMonths($this->edMonths);
        $status = StokForecastReport::sanitizeStatus($this->status);

        return self::CACHE_VERSION.':'.$month.':ed'.$edMonths.':'.$status;
    }

    protected function baseRows(): array
    {
        $month = $this->month !== '' ? $this->month : now()->format('Y-m');
        $edMonths = StokForecastReport::sanitizeEdMonths($this->edMonths);
        $status = StokForecastReport::sanitizeStatus($this->status);

        $cached = Cache::remember($this->cacheKey(), now()->addMinutes(5), fn () =>
            array_map(fn ($r) => (array) $r, (new StokForecastReport())->rows($month, $edMonths, $status))
        );

        return array_map(fn ($r) => (object) $r, $cached);
    }

    public function render()
    {
        $arranged = StokForecastReport::arrange(
            $this->baseRows(), $this->search, $this->sortField, $this->sortDir, $this->category
        );
        $total = $arranged->count();

        // Total keseluruhan (semua hasil filter, lintas halaman) untuk kaki tabel:
        // total Batch Size WIP & Belum Proses.
        $totals = [
            'size_wip'             => $arranged->sum(fn ($r) => (float) $r->size_wip),
            'akt_wip'              => $arranged->sum(fn ($r) => (float) $r->akt_wip),
            'size_br_belum_proses' => $arranged->sum(fn ($r) => (float) $r->size_br_belum_proses),
            'akt_belum'            => $arranged->sum(fn ($r) => (float) $r->akt_belum),
        ];

        // perPage = 0 -> "Semua": satu halaman berisi seluruh baris.
        $perPage = $this->perPage > 0 ? $this->perPage : max($total, 1);

        $items = $arranged->forPage($this->getPage(), $perPage)->values();

        $paginator = new LengthAwarePaginator($items, $total, $perPage, $this->getPage(), [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);

        return view('livewire.stok-forecast-table', [
            'paginator' => $paginator,
            'groups' => StokForecastReport::groups($this->edMonths),
            'columns' => StokForecastReport::COLUMNS,
            'categories' => StokForecastReport::CATEGORIES,
            'statuses' => StokForecastReport::STATUSES,
            'total' => $total,
            'totals' => $totals,
            // Set kode Toll Out (dimuat segar tiap render, tak ikut cache baseRows per-bulan).
            'tollOut' => TollOutProduct::set(),
        ]);
    }
}
