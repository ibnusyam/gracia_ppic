<?php

namespace App\Livewire;

use App\Reports\WipBelumProsesReport;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tabel "WIP dan BR Belum Proses".
 *
 * Daftar produk yang punya batch BR Belum Proses / WIP pada rentang tanggal (tgl_input) yang
 * dipilih user. Data TIDAK dimuat otomatis — user menentukan rentang tanggal lalu menekan tombol
 * "Tampilkan". Setelah dimuat, hasil di-cache 5 menit per rentang tanggal.
 */
class WipBelumProsesTable extends Component
{
    #[Url(history: true)]
    public string $dateFrom = '';

    #[Url(history: true)]
    public string $dateTo = '';

    /** Urutan daftar produk: 'kode' atau 'nama'. */
    #[Url]
    public string $sortField = 'nama';

    #[Url]
    public string $sortDir = 'asc';

    /** Baru menampilkan data setelah user menekan "Tampilkan". */
    public bool $loaded = false;

    public function mount(): void
    {
        // Default rentang: 6 bulan terakhir s/d hari ini (belum dijalankan sampai tombol ditekan).
        if ($this->dateFrom === '') {
            $this->dateFrom = now()->subMonths(6)->format('Y-m-d');
        }
        if ($this->dateTo === '') {
            $this->dateTo = now()->format('Y-m-d');
        }

        // Bila kedua tanggal datang dari URL (mis. hasil share link), langsung tampilkan.
        $this->loaded = request()->hasAny(['dateFrom', 'dateTo']);
    }

    /** Jalankan laporan sesuai rentang tanggal terpilih. */
    public function generate(): void
    {
        $this->validate([
            'dateFrom' => 'required|date',
            'dateTo'   => 'required|date|after_or_equal:dateFrom',
        ], [
            'dateTo.after_or_equal' => 'Tanggal akhir harus sama atau setelah tanggal awal.',
        ]);

        $this->loaded = true;
    }

    /** Ubah kolom pengurutan daftar produk (toggle arah bila kolom sama). */
    public function sortBy(string $field): void
    {
        if (! in_array($field, ['kode', 'nama'], true)) {
            return;
        }
        if ($this->sortField === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDir = 'asc';
        }
    }

    public function render()
    {
        $products = [];
        // Rekap per status: banyak batch (n) + total batch size.
        $totals = [
            'belum' => ['n' => 0, 'size' => 0.0],
            'wip'   => ['n' => 0, 'size' => 0.0],
            'all'   => ['n' => 0, 'size' => 0.0],
        ];

        if ($this->loaded) {
            // Baris mentah di-cache per rentang tanggal; pengelompokan & sorting dilakukan di memori
            // (via helper bersama) supaya ganti urutan tak perlu query ulang. Disimpan sebagai array
            // biasa (bukan stdClass) agar aman di-serialize oleh cache store, lalu di-rehidrasi.
            $cached = Cache::remember(
                'wip_belum:v2:'.$this->dateFrom.':'.$this->dateTo,
                now()->addMinutes(5),
                fn () => array_map(fn ($r) => (array) $r, (new WipBelumProsesReport())->rows($this->dateFrom, $this->dateTo))
            );
            $rows = array_map(fn ($r) => (object) $r, $cached);

            ['products' => $products, 'totals' => $totals] =
                WipBelumProsesReport::group($rows, $this->sortField, $this->sortDir);
        }

        return view('livewire.wip-belum-proses-table', [
            'products' => $products,
            'totals'   => $totals,
            'count'    => count($products),
        ]);
    }
}
