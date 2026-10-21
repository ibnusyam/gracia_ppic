<div>
    <x-report-shell
        title="Produk Toll Out (Makloon)"
        subtitle="Daftar kode produk yang diproduksi di luar/makloon. Dipakai untuk menandai baris di Monitoring Stok (sel MOI Belum Proses ≤ 2,7 bulan → kuning).">

        <x-slot name="actions">
            <a href="{{ route('reports.stok-forecast') }}"
               class="inline-flex items-center px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-md">
                ← Monitoring Stok
            </a>
        </x-slot>

        <x-slot name="filters">
            <form wire:submit="add" class="flex flex-col sm:flex-row gap-2 sm:items-start">
                <div class="flex-1">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Kode Produk</label>
                    <input type="text" wire:model="kode" placeholder="mis. OJ111P"
                           class="w-full sm:max-w-xs rounded-md border-gray-300 text-sm shadow-sm uppercase">
                    @error('kode') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:pt-6">
                    <button type="submit"
                            class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-md">
                        <span wire:loading.remove wire:target="add">Tambah</span>
                        <span wire:loading wire:target="add">Menyimpan…</span>
                    </button>
                </div>
            </form>

            @if ($notice)
                <p class="mt-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1">{{ $notice }}</p>
            @endif
        </x-slot>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="w-12 px-3 py-2 text-left font-semibold text-gray-600">No.</th>
                        <th wire:click="sortBy('kode')"
                            class="w-40 px-3 py-2 text-left font-semibold text-gray-600 cursor-pointer select-none hover:text-gray-800">
                            <span class="inline-flex items-center gap-1">Kode
                                @if ($sortField === 'kode')<span class="text-gray-400">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>@endif
                            </span>
                        </th>
                        <th wire:click="sortBy('nama')"
                            class="px-3 py-2 text-left font-semibold text-gray-600 cursor-pointer select-none hover:text-gray-800">
                            <span class="inline-flex items-center gap-1">Nama Produk
                                @if ($sortField === 'nama')<span class="text-gray-400">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>@endif
                            </span>
                        </th>
                        <th class="w-24 px-3 py-2 text-right font-semibold text-gray-600">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($items as $i => $item)
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2 text-gray-500">{{ $i + 1 }}</td>
                            <td class="px-3 py-2 font-medium text-gray-800 whitespace-nowrap">{{ $item->kode }}</td>
                            <td class="px-3 py-2 {{ $item->nama ? 'text-gray-700' : 'text-gray-400 italic' }}">
                                {{ $item->nama ?: 'tidak ditemukan di master' }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <button type="button"
                                        wire:click="delete({{ $item->id }})"
                                        wire:confirm="Hapus kode {{ $item->kode }} dari daftar Toll Out?"
                                        class="text-red-600 hover:text-red-800 text-sm font-medium">
                                    Hapus
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-3 py-8 text-center text-gray-400">
                                Belum ada produk Toll Out. Tambahkan kode di atas.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-report-shell>
</div>
