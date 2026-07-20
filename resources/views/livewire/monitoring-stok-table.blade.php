@php use App\Support\Fmt; @endphp

@php
    $exportParams = array_filter([
        'start_date' => $startDate,
        'end_date'   => $endDate,
        'search'     => $search,
        'sort_field' => $sortField,
        'sort_dir'   => $sortDir,
    ], fn ($v) => $v !== null && $v !== '');

    $groups = App\Reports\MonitoringStokReport::GROUPS;
@endphp

<div>
    <x-report-shell
        title="Monitoring Stok"
        :subtitle="$loaded
            ? 'Menampilkan '.number_format($total, 0, ',', '.').' produk'.(($startDate || $endDate) ? ' — filter Tgl. Input: '.(Fmt::date($startDate) ?: '…').' s/d '.(Fmt::date($endDate) ?: '…') : '')
            : 'Atur filter lalu klik Tampilkan untuk memuat data.'">

        <x-slot name="actions">
            <a href="{{ route('reports.monitoring-stok.excel', $exportParams) }}"
               class="inline-flex items-center px-3 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-md">
                Export Excel
            </a>
            <a href="{{ route('reports.monitoring-stok.pdf', $exportParams) }}" target="_blank"
               class="inline-flex items-center px-3 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md">
                Cetak PDF
            </a>
        </x-slot>

        <x-slot name="filters">
            {{-- Binding deferred (wire:model tanpa .live): data baru dimuat hanya saat "Tampilkan" ditekan. --}}
            <form wire:submit="apply" class="space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Tanggal Mulai</label>
                        <input type="date" wire:model="startDate"
                               class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Tanggal Akhir</label>
                        <input type="date" wire:model="endDate"
                               class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Cari (Kode / Nama Produk)</label>
                        <input type="text" wire:model="search" placeholder="ketik lalu klik Tampilkan…"
                               class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Baris / halaman</label>
                        <select wire:model="perPage" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                            <option value="25">25 / hal</option>
                            <option value="50">50 / hal</option>
                            <option value="100">100 / hal</option>
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
        <div wire:loading.delay wire:target="apply,sortBy,gotoPage,nextPage,previousPage"
             class="px-4 py-2 text-xs text-gray-500 bg-gray-50 border-b">Memuat data…</div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    {{-- Baris 1: nama grup — tiap grup membawahi 2 kolom (Jumlah + Batch Size) --}}
                    <tr class="border-b border-gray-200">
                        <th rowspan="2" class="px-3 py-2 text-left font-semibold text-gray-600 w-12 align-bottom">No.</th>
                        <th rowspan="2" wire:click="sortBy('kode_produk')"
                            class="px-3 py-2 text-left font-semibold text-gray-600 whitespace-nowrap cursor-pointer select-none align-bottom">
                            <span class="inline-flex items-center gap-1">Kode Produk
                                @if ($sortField === 'kode_produk')<span class="text-gray-400">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>@endif
                            </span>
                        </th>
                        <th rowspan="2" wire:click="sortBy('nama_produk')"
                            class="px-3 py-2 text-left font-semibold text-gray-600 whitespace-nowrap cursor-pointer select-none align-bottom">
                            <span class="inline-flex items-center gap-1">Nama Produk
                                @if ($sortField === 'nama_produk')<span class="text-gray-400">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>@endif
                            </span>
                        </th>
                        @foreach ($groups as $gkey => $glabel)
                            <th colspan="2"
                                class="px-3 py-2 text-center font-semibold text-gray-700 whitespace-nowrap border-l-2 border-gray-300">
                                {{ $glabel }}
                            </th>
                        @endforeach
                    </tr>
                    {{-- Baris 2: sub-kolom Jumlah / Batch Size tiap grup --}}
                    <tr>
                        @foreach ($groups as $gkey => $glabel)
                            <th wire:click="sortBy('{{ $gkey }}')"
                                class="px-3 py-1.5 text-right text-xs font-medium text-gray-500 whitespace-nowrap cursor-pointer select-none border-l-2 border-gray-300">
                                <span class="inline-flex items-center gap-1 justify-end">Jumlah
                                    @if ($sortField === $gkey)<span class="text-gray-400">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>@endif
                                </span>
                            </th>
                            <th wire:click="sortBy('size_{{ $gkey }}')"
                                class="px-3 py-1.5 text-right text-xs font-medium text-gray-500 whitespace-nowrap cursor-pointer select-none">
                                <span class="inline-flex items-center gap-1 justify-end">Batch Size
                                    @if ($sortField === 'size_'.$gkey)<span class="text-gray-400">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>@endif
                                </span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @if (! $loaded)
                        <tr>
                            <td colspan="{{ count($columns) + 1 }}" class="px-3 py-10 text-center text-gray-400">
                                Atur filter tanggal / pencarian lalu klik
                                <span class="font-medium text-indigo-600">Tampilkan</span> untuk memuat data.
                            </td>
                        </tr>
                    @else
                        @forelse ($paginator as $i => $row)
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-2 text-gray-500">{{ $paginator->firstItem() + $i }}</td>
                                <td class="px-3 py-2 whitespace-nowrap font-medium text-gray-800">{{ $row->kode_produk }}</td>
                                <td class="px-3 py-2">{{ $row->nama_produk }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-right border-l-2 border-gray-200 {{ (int) $row->br_belum_proses > 0 ? 'text-amber-700 font-semibold' : 'text-gray-400' }}">{{ Fmt::num($row->br_belum_proses, 0) }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-right {{ (float) $row->size_br_belum_proses > 0 ? 'text-amber-600' : 'text-gray-300' }}">{{ Fmt::num($row->size_br_belum_proses, 0) }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-right border-l-2 border-gray-200 {{ (int) $row->wip > 0 ? 'text-blue-700 font-semibold' : 'text-gray-400' }}">{{ Fmt::num($row->wip, 0) }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-right {{ (float) $row->size_wip > 0 ? 'text-blue-600' : 'text-gray-300' }}">{{ Fmt::num($row->size_wip, 0) }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-right border-l-2 border-gray-200 {{ (int) $row->toll_out > 0 ? 'text-purple-700 font-semibold' : 'text-gray-400' }}">{{ Fmt::num($row->toll_out, 0) }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-right {{ (float) $row->size_toll_out > 0 ? 'text-purple-600' : 'text-gray-300' }}">{{ Fmt::num($row->size_toll_out, 0) }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-right border-l-2 border-gray-200 {{ (int) $row->batch_selesai > 0 ? 'text-green-700 font-semibold' : 'text-gray-400' }}">{{ Fmt::num($row->batch_selesai, 0) }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-right {{ (float) $row->size_batch_selesai > 0 ? 'text-green-600' : 'text-gray-300' }}">{{ Fmt::num($row->size_batch_selesai, 0) }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-right border-l-2 border-gray-200 font-semibold text-gray-700">{{ Fmt::num($row->total, 0) }}</td>
                                <td class="px-3 py-2 whitespace-nowrap text-right font-medium text-gray-500">{{ Fmt::num($row->size_total, 0) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($columns) + 1 }}" class="px-3 py-8 text-center text-gray-400">
                                    Tidak ada data untuk filter ini.
                                </td>
                            </tr>
                        @endforelse
                    @endif
                </tbody>
            </table>
        </div>

        @if ($loaded)
            <div class="px-4 py-3 border-t">
                {{ $paginator->links() }}
            </div>
        @endif
    </x-report-shell>
</div>
