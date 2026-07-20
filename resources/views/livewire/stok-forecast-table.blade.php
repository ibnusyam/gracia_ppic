@php use App\Support\Fmt; @endphp

@php
    $exportParams = array_filter([
        'month'      => $month,
        'search'     => $search,
        'category'   => $category,
        'ed_months'  => $edMonths,
        'sort_field' => $sortField,
        'sort_dir'   => $sortDir,
    ], fn ($v) => $v !== null && $v !== '' && $v !== 'all');

    $monthLabel = \Illuminate\Support\Carbon::parse(($month ?: now()->format('Y-m')).'-01')->format('m/Y');
@endphp

<div>
    <x-report-shell
        title="Monitoring Stok & Forecast"
        :subtitle="'Menampilkan '.number_format($total, 0, ',', '.').' produk — Forecast/Pengeluaran/Outstanding bulan '.$monthLabel.'; stok & batch per '.now()->format('d/m/Y')">

        <x-slot name="actions">
            <a href="{{ route('reports.toll-out') }}"
               class="inline-flex items-center px-3 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium rounded-md">
                Toll Out
            </a>
            <a href="{{ route('reports.stok-forecast.excel', $exportParams) }}"
               class="inline-flex items-center px-3 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-md">
                Export Excel
            </a>
            <a href="{{ route('reports.stok-forecast.pdf', $exportParams) }}" target="_blank"
               class="inline-flex items-center px-3 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md">
                Cetak PDF
            </a>
        </x-slot>

        <x-slot name="filters">
            <form wire:submit="apply" class="space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Bulan <span class="text-gray-400">(Forecast/Pengeluaran/Outstanding)</span></label>
                        <input type="month" wire:model="month"
                               class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Kategori</label>
                        <select wire:model="category" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            @foreach ($categories as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">ED <span class="text-gray-400">(bulan)</span></label>
                        <input type="number" min="1" max="60" step="1" wire:model="edMonths"
                               class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                    </div>
                    <div class="lg:col-span-2">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Cari (Kode / Nama Produk)</label>
                        <input type="text" wire:model="search" placeholder="ketik lalu Enter / klik Tampilkan…"
                               class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Baris / halaman</label>
                        <select wire:model.live="perPage" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            <option value="25">25 / hal</option>
                            <option value="50">50 / hal</option>
                            <option value="100">100 / hal</option>
                            <option value="0">Semua</option>
                        </select>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="submit"
                            class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-md">
                        <span wire:loading.remove wire:target="apply">Tampilkan</span>
                        <span wire:loading wire:target="apply">Memuat…</span>
                    </button>
                    <button type="button" wire:click="resetFilters"
                            class="px-3 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md">
                        Reset
                    </button>
                </div>
            </form>
        </x-slot>

        {{-- indikator loading --}}
        <div wire:loading.delay wire:target="apply,sortBy,resetFilters,gotoPage,nextPage,previousPage"
             class="px-4 py-2 text-xs text-gray-500 bg-gray-50 border-b">Memuat data…</div>

        {{-- Scroll container: header di-freeze (sticky) saat tabel di-scroll. Padding & font
             dipadatkan agar seluruh kolom muat dalam satu layar tanpa geser ke kanan;
             nama produk dipotong (ellipsis) — arahkan kursor utk lihat nama penuh. --}}
        <div class="overflow-auto max-h-[70vh]">
            <table class="min-w-full text-[10px] divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    {{-- Baris 1: nama grup --}}
                    <tr class="border-b border-gray-200">
                        <th rowspan="2" class="sticky top-0 z-20 bg-gray-50 w-8 px-1 py-1 text-center text-[11px] font-bold text-gray-700 align-bottom">No.</th>
                        <th rowspan="2" wire:click="sortBy('kode')"
                            class="sticky top-0 z-20 bg-gray-50 w-20 px-1 py-1 text-center text-[11px] font-bold text-gray-700 whitespace-nowrap cursor-pointer select-none align-bottom">
                            <span class="inline-flex items-center gap-1 justify-center">Kode
                                @if ($sortField === 'kode')<span class="text-gray-400">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>@endif
                            </span>
                        </th>
                        <th rowspan="2" wire:click="sortBy('nama')"
                            class="sticky top-0 z-20 bg-gray-50 px-1 py-1 text-center text-[11px] font-bold text-gray-700 cursor-pointer select-none align-bottom">
                            <span class="inline-flex items-center gap-1 justify-center">Nama Produk
                                @if ($sortField === 'nama')<span class="text-gray-400">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>@endif
                            </span>
                        </th>
                        <th rowspan="2" wire:click="sortBy('batch_size')"
                            class="sticky top-0 z-20 bg-gray-50 px-1 py-1 text-center text-[11px] font-bold text-gray-700 whitespace-nowrap cursor-pointer select-none align-bottom border-r-2 border-gray-300">
                            <span class="inline-flex items-center gap-1 justify-center" title="Batch Size standar produk">BS
                                @if ($sortField === 'batch_size')<span class="text-gray-400">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>@endif
                            </span>
                        </th>
                        @foreach ($groups as $glabel => $subs)
                            @if (count($subs) === 1)
                                {{-- Grup 1 kolom (mis. FORECAST): satu sel merged, membentang 2 baris --}}
                                @php $skey = array_key_first($subs); @endphp
                                <th rowspan="2" wire:click="sortBy('{{ $skey }}')"
                                    class="sticky top-0 z-20 bg-gray-50 px-1 py-1 text-center text-[11px] font-bold text-gray-700 whitespace-nowrap cursor-pointer select-none align-bottom border-l-2 border-gray-300">
                                    <span class="inline-flex items-center gap-1 justify-center">{{ $glabel }}
                                        @if ($sortField === $skey)<span class="text-gray-400">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>@endif
                                    </span>
                                </th>
                            @else
                                <th colspan="{{ count($subs) }}"
                                    class="sticky top-0 z-20 bg-gray-50 h-7 px-1 py-1 text-center text-[11px] font-bold text-gray-700 whitespace-nowrap border-l-2 border-gray-300">
                                    {{ $glabel }}
                                </th>
                            @endif
                        @endforeach
                    </tr>
                    {{-- Baris 2: sub-kolom tiap grup (grup 1-kolom dilewati; sudah rowspan di baris 1) --}}
                    <tr>
                        @foreach ($groups as $subs)
                            @if (count($subs) > 1)
                                @php $first = true; @endphp
                                @foreach ($subs as $key => $sublabel)
                                    <th wire:click="sortBy('{{ $key }}')"
                                        class="sticky top-7 z-20 bg-gray-50 h-7 px-1 py-1 text-center text-[11px] font-bold text-gray-600 whitespace-nowrap cursor-pointer select-none {{ $first ? 'border-l-2 border-gray-300' : '' }}">
                                        <span class="inline-flex items-center gap-1 justify-center">{{ $sublabel }}
                                            @if ($sortField === $key)<span class="text-gray-400">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>@endif
                                        </span>
                                    </th>
                                    @php $first = false; @endphp
                                @endforeach
                            @endif
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($paginator as $i => $row)
                        <tr class="group hover:bg-gray-50">
                            <td class="w-8 px-1 py-1 text-gray-500">{{ $paginator->firstItem() + $i }}</td>
                            <td class="w-20 px-1 py-1 whitespace-nowrap font-medium text-gray-800">{{ $row->kode }}</td>
                            <td class="px-1 py-1">
                                {{-- Lebar dipatok & dipotong (ellipsis) supaya nama panjang tidak melebarkan tabel;
                                     nama penuh tetap bisa dilihat lewat tooltip (hover). --}}
                                <div class="w-[120px] truncate" title="{{ $row->nama }}">{{ $row->nama }}</div>
                            </td>
                            <td class="px-1 py-1 whitespace-nowrap text-right text-gray-600 border-r-2 border-gray-200">{{ Fmt::num($row->batch_size, 0) }}</td>

                            {{-- FORECAST + PENGELUARAN (Keluar/Realisasi) — tanpa warna dekoratif; hanya minus merah. --}}
                            <td class="px-1 py-1 whitespace-nowrap text-right border-l-2 border-gray-200">{{ Fmt::num($row->forecast, 0) }}</td>
                            <td class="px-1 py-1 whitespace-nowrap text-right border-l-2 border-gray-200">{{ Fmt::num($row->pengeluaran, 0) }}</td>
                            <td class="px-1 py-1 whitespace-nowrap text-right">{{ Fmt::ratio($row->persen, 1) }}%</td>

                            {{-- Stok Saat Ini — ED bisa diklik utk lihat rincian saldo per batch --}}
                            <td class="px-1 py-1 whitespace-nowrap text-right border-l-2 border-gray-200">
                                @if ((float) $row->ed_lt9 != 0)
                                    <button type="button" wire:click="showStok({{ (int) $row->barang_seq }}, 'lt9', @js($row->kode), @js($row->nama))"
                                            class="underline decoration-dotted underline-offset-2 hover:text-black">{{ Fmt::num($row->ed_lt9, 0) }}</button>
                                @else
                                    {{ Fmt::num($row->ed_lt9, 0) }}
                                @endif
                            </td>
                            <td class="px-1 py-1 whitespace-nowrap text-right">
                                @if ((float) $row->ed_gte9 != 0)
                                    <button type="button" wire:click="showStok({{ (int) $row->barang_seq }}, 'gte9', @js($row->kode), @js($row->nama))"
                                            class="underline decoration-dotted underline-offset-2 hover:text-black">{{ Fmt::num($row->ed_gte9, 0) }}</button>
                                @else
                                    {{ Fmt::num($row->ed_gte9, 0) }}
                                @endif
                            </td>
                            <td class="px-1 py-1 whitespace-nowrap text-right font-semibold">{{ Fmt::num($row->total_stok, 0) }}</td>
                            <td class="px-1 py-1 whitespace-nowrap text-right {{ (float) ($row->doi ?? 0) < 0 ? 'text-red-600' : '' }}">{{ Fmt::num($row->doi ?? 0, 0) }}</td>

                            {{-- Outstanding PO --}}
                            <td class="px-1 py-1 whitespace-nowrap text-right border-l-2 border-gray-200">{{ Fmt::num($row->outstanding, 0) }}</td>
                            <td class="px-1 py-1 whitespace-nowrap text-right {{ (float) $row->freestock < 0 ? 'text-red-600' : '' }}">{{ Fmt::num($row->freestock, 0) }}</td>
                            <td class="px-1 py-1 whitespace-nowrap text-right {{ (float) ($row->doi_free ?? 0) < 0 ? 'text-red-600' : '' }}">{{ Fmt::num($row->doi_free ?? 0, 0) }}</td>

                            {{-- FG Karantina — Unit bisa diklik utk lihat rincian saldo karantina per batch --}}
                            <td class="px-1 py-1 whitespace-nowrap text-right border-l-2 border-gray-200">
                                @if ((float) $row->fg_karantina != 0)
                                    <button type="button" wire:click="showKarantina({{ (int) $row->barang_seq }}, @js($row->kode), @js($row->nama))"
                                            class="underline decoration-dotted underline-offset-2 hover:text-black">{{ Fmt::num($row->fg_karantina, 0) }}</button>
                                @else
                                    {{ Fmt::num($row->fg_karantina, 0) }}
                                @endif
                            </td>
                            <td class="px-1 py-1 whitespace-nowrap text-right {{ (float) $row->freestock_kar < 0 ? 'text-red-600' : '' }}">{{ Fmt::num($row->freestock_kar, 0) }}</td>
                            <td class="px-1 py-1 whitespace-nowrap text-right {{ (float) ($row->doi_kar ?? 0) < 0 ? 'text-red-600' : '' }}">{{ Fmt::num($row->doi_kar ?? 0, 0) }}</td>

                            {{-- Batch 6 bulan terakhir: WIP dulu lalu Belum Proses; kolom Batch Size (total)
                                 bisa diklik utk lihat detail batch penyusunnya (garis bawah = penanda klik) --}}
                            <td class="px-1 py-1 whitespace-nowrap text-right border-l-2 border-gray-200">
                                @if ((int) $row->wip > 0)
                                    <button type="button" wire:click="showBatches({{ (int) $row->barang_seq }}, 'wip', @js($row->kode), @js($row->nama))"
                                            class="font-semibold underline decoration-dotted underline-offset-2 hover:text-black">{{ Fmt::num($row->size_wip, 0) }}</button>
                                @else
                                    {{ Fmt::num($row->size_wip, 0) }}
                                @endif
                            </td>
                            <td class="px-1 py-1 whitespace-nowrap text-right {{ (float) $row->freestock_wip < 0 ? 'text-red-600' : '' }}">{{ Fmt::num($row->freestock_wip, 0) }}</td>
                            <td class="px-1 py-1 whitespace-nowrap text-right {{ (float) ($row->doi_wip ?? 0) < 0 ? 'text-red-600' : '' }}">{{ Fmt::num($row->doi_wip ?? 0, 0) }}</td>
                            <td class="px-1 py-1 whitespace-nowrap text-right border-l-2 border-gray-200">
                                @if ((int) $row->br_belum_proses > 0)
                                    <button type="button" wire:click="showBatches({{ (int) $row->barang_seq }}, 'belum', @js($row->kode), @js($row->nama))"
                                            class="font-semibold underline decoration-dotted underline-offset-2 hover:text-black">{{ Fmt::num($row->size_br_belum_proses, 0) }}</button>
                                @else
                                    {{ Fmt::num($row->size_br_belum_proses, 0) }}
                                @endif
                            </td>
                            <td class="px-1 py-1 whitespace-nowrap text-right {{ (float) $row->freestock_bp < 0 ? 'text-red-600' : '' }}">{{ Fmt::num($row->freestock_bp, 0) }}</td>
                            @php
                                // Produk Toll Out (makloon) + DOI Belum Proses terhitung (Forecast > 0) & <= 80 hari -> peringatan kuning.
                                $tollYellow = isset($tollOut[strtoupper(trim((string) $row->kode))])
                                    && $row->doi_bp !== null && (float) $row->doi_bp <= 80;
                            @endphp
                            <td class="px-1 py-1 whitespace-nowrap text-right {{ $tollYellow ? 'bg-yellow-200 font-semibold ' : '' }}{{ (float) ($row->doi_bp ?? 0) < 0 ? 'text-red-600' : '' }}"
                                @if ($tollYellow) title="Toll Out — DOI Belum Proses ≤ 80 hari" @endif>{{ Fmt::num($row->doi_bp ?? 0, 0) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) + 1 }}" class="px-3 py-8 text-center text-gray-400">
                                Tidak ada data untuk pencarian ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($total > 0)
                    {{-- Baris TOTAL: jumlah WIP & Belum Proses seluruh hasil filter (lintas halaman) --}}
                    <tfoot class="bg-gray-100 font-semibold text-gray-800 border-t-2 border-gray-300">
                        <tr>
                            <td colspan="4" class="px-1 py-1.5 text-right whitespace-nowrap border-r-2 border-gray-200">TOTAL (semua hasil filter)</td>
                            <td colspan="13"></td>
                            <td class="px-1 py-1.5 text-right border-l-2 border-gray-200">{{ Fmt::num($totals['size_wip'], 0) }}</td>
                            <td colspan="2"></td>
                            <td class="px-1 py-1.5 text-right border-l-2 border-gray-200">{{ Fmt::num($totals['size_br_belum_proses'], 0) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <div class="px-4 py-3 border-t">
            {{ $paginator->links() }}
        </div>
    </x-report-shell>

    {{-- Modal drill-down: daftar batch penyusun angka WIP / Belum Proses --}}
    @if ($showBatchModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="fixed inset-0 bg-black bg-opacity-40" wire:click="closeBatchModal"></div>
            <div class="relative bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[80vh] flex flex-col">
                <div class="flex items-center justify-between px-4 py-3 border-b">
                    <h3 class="text-sm font-semibold text-gray-800">{{ $batchTitle }}</h3>
                    <button type="button" wire:click="closeBatchModal"
                            class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
                </div>
                <div class="overflow-auto p-4">
                    <table class="min-w-full text-xs">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-2 py-1 text-left font-medium">No. Batch</th>
                                <th class="px-2 py-1 text-left font-medium">Kode Batch</th>
                                <th class="px-2 py-1 text-left font-medium">Tgl Input</th>
                                <th class="px-2 py-1 text-left font-medium">Tgl Penyerahan</th>
                                <th class="px-2 py-1 text-left font-medium">ED</th>
                                <th class="px-2 py-1 text-right font-medium">Batch Size</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($batchRows as $b)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-2 py-1 whitespace-nowrap font-medium text-gray-800">{{ $b['no_batch'] }}</td>
                                    <td class="px-2 py-1 whitespace-nowrap text-gray-600">{{ $b['kode_batch'] }}</td>
                                    <td class="px-2 py-1 whitespace-nowrap text-gray-600">{{ Fmt::date($b['tgl_input']) }}</td>
                                    <td class="px-2 py-1 whitespace-nowrap {{ $b['tgl_penyerahan'] ? 'text-gray-600' : 'text-gray-300' }}">{{ $b['tgl_penyerahan'] ? Fmt::date($b['tgl_penyerahan']) : '–' }}</td>
                                    <td class="px-2 py-1 whitespace-nowrap text-gray-600">{{ Fmt::date($b['tgl_expired']) }}</td>
                                    <td class="px-2 py-1 whitespace-nowrap text-right text-gray-700">{{ Fmt::num($b['batch_size'], 0) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-2 py-6 text-center text-gray-400">Tidak ada batch.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal drill-down: rincian saldo (per batch) penyusun angka ED ≤ 9 / ED ≥ 9 Bln --}}
    @if ($showStokModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="fixed inset-0 bg-black bg-opacity-40" wire:click="closeStokModal"></div>
            <div class="relative bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[80vh] flex flex-col">
                <div class="flex items-center justify-between px-4 py-3 border-b">
                    <h3 class="text-sm font-semibold text-gray-800">{{ $stokTitle }}</h3>
                    <button type="button" wire:click="closeStokModal"
                            class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
                </div>
                <div class="overflow-auto p-4">
                    <table class="min-w-full text-xs">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-2 py-1 text-left font-medium">No. Batch</th>
                                <th class="px-2 py-1 text-left font-medium">Kode Batch</th>
                                <th class="px-2 py-1 text-left font-medium">ED</th>
                                <th class="px-2 py-1 text-right font-medium">Jumlah</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($stokRows as $s)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-2 py-1 whitespace-nowrap font-medium text-gray-800">{{ $s['no_batch'] ?: '–' }}</td>
                                    <td class="px-2 py-1 whitespace-nowrap text-gray-600">{{ $s['kode_batch'] ?: '–' }}</td>
                                    <td class="px-2 py-1 whitespace-nowrap text-gray-600">{{ Fmt::date($s['tgl_expired']) }}</td>
                                    <td class="px-2 py-1 whitespace-nowrap text-right {{ (float) $s['jumlah'] < 0 ? 'text-red-600' : 'text-gray-700' }}">{{ Fmt::num($s['jumlah'], 0) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-2 py-6 text-center text-gray-400">Tidak ada rincian saldo.</td></tr>
                            @endforelse
                        </tbody>
                        @if (count($stokRows) > 0)
                            <tfoot class="bg-gray-100 font-semibold text-gray-800 border-t-2 border-gray-300">
                                <tr>
                                    <td colspan="3" class="px-2 py-1.5 text-right">TOTAL</td>
                                    <td class="px-2 py-1.5 text-right">{{ Fmt::num($stokTotal, 0) }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>
    @endif
</div>
