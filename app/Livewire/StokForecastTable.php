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
    private const CACHE_VERSION = 'stok_forecast:v2';

    /** Bulan acuan 'YYYY-MM' untuk kolom Forecast/Pengeluaran/Outstanding. Default = bulan berjalan. */
    #[Url(history: true)]
    public string $month = '';

    #[Url(history: true)]
    public string $search = '';

    /** Kategori produk (all/pilot/alkes/komersil) — filter berdasarkan prefix kode. */
    #[Url(history: true)]
    public string $category = 'all';

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

    /** State modal drill-down detail saldo (klik kolom ED ≤ 9 / ED ≥ 9 Bln di grup Stok Saat Ini). */
    public bool $showStokModal = false;

    /** @var array<int,array<string,mixed>> */
    public array $stokRows = [];

    public string $stokTitle = '';

    public float $stokTotal = 0.0;

    public function mount(): void
    {
        if ($this->month === '') {
            $this->month = now()->format('Y-m');
        }
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
        $this->reset(['search', 'category', 'edMonths']);
        $this->month = now()->format('Y-m');
        $this->resetPage();
    }

    /** Buka modal berisi daftar batch yang menyusun angka WIP / Belum Proses satu produk. */
    public function showBatches(int $barangSeq, string $state, string $kode, string $nama): void
    {
        $rows = (new StokForecastReport())->batchDetail($barangSeq, $state);
        $this->batchRows = array_map(fn ($r) => (array) $r, $rows);

        $label = $state === 'wip' ? 'WIP' : 'Belum Proses';
        $this->batchTitle = $label.' — '.$kode.' '.trim($nama).' ('.count($this->batchRows).' batch)';
        $this->showBatchModal = true;
    }

    public function closeBatchModal(): void
    {
        $this->showBatchModal = false;
        $this->batchRows = [];
        $this->batchTitle = '';
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
    protected function baseRows(): array
    {
        $month = $this->month !== '' ? $this->month : now()->format('Y-m');
        $edMonths = StokForecastReport::sanitizeEdMonths($this->edMonths);

        // Key diberi versi: bila susunan kolom baris berubah, entri cache lama (bentuk berbeda)
        // otomatis diabaikan alih-alih memicu "Undefined property" di view.
        $cached = Cache::remember(self::CACHE_VERSION.':'.$month.':ed'.$edMonths, now()->addMinutes(5), fn () =>
            array_map(fn ($r) => (array) $r, (new StokForecastReport())->rows($month, $edMonths))
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
            'size_br_belum_proses' => $arranged->sum(fn ($r) => (float) $r->size_br_belum_proses),
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
            'total' => $total,
            'totals' => $totals,
            // Set kode Toll Out (dimuat segar tiap render, tak ikut cache baseRows per-bulan).
            'tollOut' => TollOutProduct::set(),
        ]);
    }
}
