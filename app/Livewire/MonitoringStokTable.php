<?php

namespace App\Livewire;

use App\Reports\MonitoringStokReport;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tabel "Monitoring Stok" (spec bagian 6). Ditanam di halaman Dashboard.
 *
 * Input filter memakai binding DEFERRED (bukan .live): perubahan tanggal/pencarian TIDAK
 * langsung query DB. Data baru dimuat saat user menekan tombol "Tampilkan" (method apply()),
 * supaya query berat (~3 detik) tidak berjalan terus-menerus tiap ketikan.
 */
class MonitoringStokTable extends Component
{
    use WithPagination;

    #[Url(history: true)]
    public ?string $startDate = null;

    #[Url(history: true)]
    public ?string $endDate = null;

    #[Url(history: true)]
    public string $search = '';

    #[Url]
    public string $sortField = 'kode_produk';

    #[Url]
    public string $sortDir = 'asc';

    public int $perPage = 25;

    /** Data hanya dimuat setelah user menekan "Tampilkan" (hindari query DB saat load/tiap ketik). */
    public bool $loaded = false;

    public function mount(): void
    {
        // Bila dibuka lewat link ber-filter (bookmark/URL berisi tanggal/pencarian), langsung muat.
        if ($this->startDate || $this->endDate || $this->search !== '') {
            $this->loaded = true;
        }
    }

    /** Tombol "Tampilkan" / submit form: terapkan filter yang sedang diketik & jalankan query. */
    public function apply(): void
    {
        $this->loaded = true;
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
        $this->reset(['startDate', 'endDate', 'search']);
        $this->resetPage();
    }

    /**
     * Raw rows dari DB legacy, di-cache per rentang tanggal (query ~3 detik).
     * Disimpan sebagai array biasa (bukan stdClass) supaya aman di-serialize/unserialize
     * oleh cache store, lalu di-rehidrasi kembali menjadi objek.
     */
    protected function baseRows(): array
    {
        $key = 'monstok:'.($this->startDate ?: '-').':'.($this->endDate ?: '-');

        $cached = Cache::remember($key, now()->addMinutes(5), fn () =>
            array_map(fn ($r) => (array) $r, (new MonitoringStokReport())->rows($this->startDate, $this->endDate))
        );

        return array_map(fn ($r) => (object) $r, $cached);
    }

    public function render()
    {
        $total = 0;
        $items = collect();

        // Query hanya dijalankan setelah user menekan "Tampilkan".
        if ($this->loaded) {
            $arranged = MonitoringStokReport::arrange(
                $this->baseRows(), $this->search, $this->sortField, $this->sortDir
            );
            $total = $arranged->count();
            $items = $arranged->forPage($this->getPage(), $this->perPage)->values();
        }

        $paginator = new LengthAwarePaginator($items, $total, $this->perPage, $this->getPage(), [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);

        return view('livewire.monitoring-stok-table', [
            'paginator' => $paginator,
            'columns' => MonitoringStokReport::COLUMNS,
            'total' => $total,
            'loaded' => $this->loaded,
        ]);
    }
}
