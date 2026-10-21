<div>
    <x-report-shell
        title="Log Login"
        subtitle="Riwayat aktivitas masuk, keluar, dan percobaan login gagal.">

        <x-slot name="filters">
            <div class="flex flex-col sm:flex-row sm:items-end gap-3">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Cari</label>
                    <input type="text" wire:model.live.debounce.400ms="search"
                           placeholder="Nama, email, atau IP…"
                           class="w-full border-gray-300 rounded-md shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Jenis</label>
                    <select wire:model.live="event"
                            class="border-gray-300 rounded-md shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua</option>
                        <option value="login">Login</option>
                        <option value="logout">Logout</option>
                        <option value="failed">Gagal</option>
                    </select>
                </div>
            </div>
        </x-slot>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Waktu</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Jenis</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Nama</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Email</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">IP</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Perangkat</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($logs as $log)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 whitespace-nowrap text-gray-700">
                                {{ $log->created_at?->format('d/m/Y H:i:s') }}
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                @php
                                    $badge = match ($log->event) {
                                        'login'  => 'bg-green-100 text-green-800',
                                        'logout' => 'bg-gray-100 text-gray-700',
                                        'failed' => 'bg-red-100 text-red-800',
                                        default  => 'bg-gray-100 text-gray-700',
                                    };
                                    $label = match ($log->event) {
                                        'login'  => 'Login',
                                        'logout' => 'Logout',
                                        'failed' => 'Gagal',
                                        default  => $log->event,
                                    };
                                @endphp
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $badge }}">
                                    {{ $label }}
                                </span>
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap text-gray-700">{{ $log->name ?? '—' }}</td>
                            <td class="px-4 py-2 whitespace-nowrap text-gray-700">{{ $log->email ?? '—' }}</td>
                            <td class="px-4 py-2 whitespace-nowrap text-gray-500">{{ $log->ip_address ?? '—' }}</td>
                            <td class="px-4 py-2 max-w-xs truncate text-gray-400" title="{{ $log->user_agent }}">
                                {{ $log->user_agent ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-gray-400">
                                Belum ada catatan aktivitas.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($logs->hasPages())
            <div class="px-4 py-3 border-t border-gray-100">
                {{ $logs->links() }}
            </div>
        @endif
    </x-report-shell>
</div>
