@props([
    'title',
    'subtitle' => null,
])

{{-- Pola halaman laporan yang dapat dipakai ulang: judul, panel filter, aksi export, tabel. --}}
<div class="py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold text-gray-800">{{ $title }}</h1>
                @if ($subtitle)
                    <p class="text-sm text-gray-500">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex flex-wrap gap-2">
                    {{ $actions }}
                </div>
            @endisset
        </div>

        @isset($filters)
            <div class="bg-white shadow-sm rounded-lg p-4">
                {{ $filters }}
            </div>
        @endisset

        <div class="bg-white shadow-sm rounded-lg overflow-hidden">
            {{ $slot }}
        </div>
    </div>
</div>
