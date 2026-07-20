@php use App\Support\Fmt; @endphp

<div>
    <x-report-shell
        title="WIP dan BR Belum Proses"
        :subtitle="$loaded
            ? 'Menampilkan '.number_format($count, 0, ',', '.').' produk ('.number_format($totals['all']['n'], 0, ',', '.').' batch) — tgl input '.\Illuminate\Support\Carbon::parse($dateFrom)->format('d/m/Y').' s/d '.\Illuminate\Support\Carbon::parse($dateTo)->format('d/m/Y')
            : 'Pilih rentang tanggal lalu klik Tampilkan.'">

        @if ($loaded && $count > 0)
            <x-slot name="actions">
                <a href="{{ route('reports.wip-belum-proses.excel', ['from' => $dateFrom, 'to' => $dateTo, 'sort_field' => $sortField, 'sort_dir' => $sortDir]) }}"
                   class="inline-flex items-center px-3 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-md">
                    Export Excel
                </a>
            </x-slot>
        @endif

        <x-slot name="filters">
            <form wire:submit="generate" class="space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Tanggal Awal <span class="text-gray-400">(tgl input batch)</span></label>
                        <input type="date" wire:model="dateFrom" max="{{ $dateTo }}"
                               class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                        @error('dateFrom') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Tanggal Akhir</label>
                        <input type="date" wire:model="dateTo"
                               class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                        @error('dateTo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex gap-2">
                        <button type="submit"
                                class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-md">
                            <span wire:loading.remove wire:target="generate">Tampilkan</span>
                            <span wire:loading wire:target="generate">Memuat…</span>
                        </button>
                    </div>
                </div>
            </form>
        </x-slot>

        @if (! $loaded)
            <div class="px-4 py-16 text-center text-gray-400 text-sm">
                Tentukan <span class="font-medium text-gray-500">Tanggal Awal</span> &amp;
                <span class="font-medium text-gray-500">Tanggal Akhir</span>, lalu klik
                <span class="font-medium text-gray-500">Tampilkan</span>.
            </div>
        @else
            <div wire:loading.delay wire:target="generate"
                 class="px-4 py-2 text-xs text-gray-500 bg-gray-50 border-b">Memuat data…</div>

            {{-- Scroll container: header di-freeze (sticky) saat tabel di-scroll. Padding &
                 font dipadatkan agar kolom rapat, tidak memakan banyak ruang. --}}
            <div class="overflow-auto max-h-[70vh]">
                <table class="w-auto text-[11px]">
                    <thead class="bg-gray-50">
                        {{-- Baris 1: nama grup --}}
                        <tr class="border-b border-gray-200">
                            <th rowspan="2" class="sticky top-0 z-20 bg-gray-50 w-10 px-1 py-1 text-left font-semibold text-gray-600 align-bottom">No.</th>
                            <th rowspan="2" wire:click="sortBy('kode')"
                                class="sticky top-0 z-20 bg-gray-50 w-24 px-1 py-1 text-left font-semibold text-gray-600 whitespace-nowrap align-bottom cursor-pointer select-none">
                                <span class="inline-flex items-center gap-1">Kode
                                    @if ($sortField === 'kode')<span class="text-gray-400">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>@endif
                                </span>
                            </th>
                            <th rowspan="2" wire:click="sortBy('nama')"
                                class="sticky top-0 z-20 bg-gray-50 px-1 py-1 text-left font-semibold text-gray-600 whitespace-nowrap align-bottom border-r-2 border-gray-300 cursor-pointer select-none">
                                <span class="inline-flex items-center gap-1">Nama Produk
                                    @if ($sortField === 'nama')<span class="text-gray-400">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>@endif
                                </span>
                            </th>
                            <th colspan="2" class="sticky top-0 z-20 bg-gray-50 h-6 px-1 py-1 text-center font-semibold text-amber-700 whitespace-nowrap border-l-2 border-gray-300">BR Belum Proses</th>
                            <th colspan="2" class="sticky top-0 z-20 bg-gray-50 h-6 px-1 py-1 text-center font-semibold text-blue-700 whitespace-nowrap border-l-2 border-gray-300">WIP</th>
                        </tr>
                        {{-- Baris 2: sub-kolom tiap grup --}}
                        <tr>
                            <th class="sticky top-6 z-20 bg-gray-50 h-6 px-1 py-1 text-left font-medium text-gray-500 whitespace-nowrap border-l-2 border-gray-300">Nomor Batch</th>
                            <th class="sticky top-6 z-20 bg-gray-50 h-6 px-1 py-1 text-right font-medium text-gray-500 whitespace-nowrap">Batch Size</th>
                            <th class="sticky top-6 z-20 bg-gray-50 h-6 px-1 py-1 text-left font-medium text-gray-500 whitespace-nowrap border-l-2 border-gray-300">Nomor Batch</th>
                            <th class="sticky top-6 z-20 bg-gray-50 h-6 px-1 py-1 text-right font-medium text-gray-500 whitespace-nowrap">Batch Size</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $no = 0; @endphp
                        @forelse ($products as $p)
                            @php $no++; $rs = max(count($p['belum']), count($p['wip'])); @endphp
                            @for ($r = 0; $r < $rs; $r++)
                                @php $sep = $r === 0 ? 'border-t-2 border-gray-200' : 'border-t border-gray-100'; @endphp
                                <tr class="hover:bg-gray-50">
                                    @if ($r === 0)
                                        <td rowspan="{{ $rs }}" class="w-10 px-1 py-1 text-gray-500 align-top {{ $sep }}">{{ $no }}</td>
                                        <td rowspan="{{ $rs }}" class="w-24 px-1 py-1 whitespace-nowrap font-medium text-gray-800 align-top {{ $sep }}">{{ $p['kode'] }}</td>
                                        <td rowspan="{{ $rs }}" class="px-1 py-1 whitespace-nowrap align-top border-r-2 border-gray-200 {{ $sep }}">{{ $p['nama'] }}</td>
                                    @endif

                                    {{-- BR Belum Proses --}}
                                    <td class="px-1 py-1 whitespace-nowrap border-l-2 border-gray-200 {{ $sep }} {{ isset($p['belum'][$r]) ? 'font-medium text-gray-800' : 'text-gray-300' }}">{{ $p['belum'][$r]['no_batch'] ?? '–' }}</td>
                                    <td class="px-1 py-1 whitespace-nowrap text-right {{ $sep }} {{ isset($p['belum'][$r]) ? 'text-amber-700' : 'text-gray-300' }}">{{ isset($p['belum'][$r]) ? Fmt::num($p['belum'][$r]['batch_size'], 0) : '' }}</td>

                                    {{-- WIP --}}
                                    <td class="px-1 py-1 whitespace-nowrap border-l-2 border-gray-200 {{ $sep }} {{ isset($p['wip'][$r]) ? 'font-medium text-gray-800' : 'text-gray-300' }}">{{ $p['wip'][$r]['no_batch'] ?? '–' }}</td>
                                    <td class="px-1 py-1 whitespace-nowrap text-right {{ $sep }} {{ isset($p['wip'][$r]) ? 'text-blue-700' : 'text-gray-300' }}">{{ isset($p['wip'][$r]) ? Fmt::num($p['wip'][$r]['batch_size'], 0) : '' }}</td>
                                </tr>
                            @endfor
                        @empty
                            <tr>
                                <td colspan="7" class="px-3 py-8 text-center text-gray-400">
                                    Tidak ada batch WIP / BR Belum Proses pada rentang tanggal ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($count > 0)
                        {{-- Rekap: banyak batch & total batch size tiap grup, lalu grand total --}}
                        <tfoot class="bg-gray-100 font-semibold text-gray-800 border-t-2 border-gray-300">
                            <tr>
                                <td colspan="3" class="px-1 py-1.5 text-right whitespace-nowrap border-r-2 border-gray-200">TOTAL</td>
                                <td class="px-1 py-1.5 text-right whitespace-nowrap border-l-2 border-gray-200 text-amber-700">{{ Fmt::num($totals['belum']['n'], 0) }} batch</td>
                                <td class="px-1 py-1.5 text-right text-amber-700">{{ Fmt::num($totals['belum']['size'], 0) }}</td>
                                <td class="px-1 py-1.5 text-right whitespace-nowrap border-l-2 border-gray-200 text-blue-700">{{ Fmt::num($totals['wip']['n'], 0) }} batch</td>
                                <td class="px-1 py-1.5 text-right text-blue-700">{{ Fmt::num($totals['wip']['size'], 0) }}</td>
                            </tr>
                            <tr class="text-gray-600 font-medium border-t border-gray-300">
                                <td colspan="3" class="px-1 py-1.5 text-right whitespace-nowrap border-r-2 border-gray-200">TOTAL SEMUA BATCH</td>
                                <td colspan="4" class="px-1 py-1.5 text-right whitespace-nowrap border-l-2 border-gray-200">{{ Fmt::num($totals['all']['n'], 0) }} batch &middot; total size {{ Fmt::num($totals['all']['size'], 0) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        @endif
    </x-report-shell>
</div>
