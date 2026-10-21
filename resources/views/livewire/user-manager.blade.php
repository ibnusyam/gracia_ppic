<div>
    <x-report-shell
        title="Kelola User"
        subtitle="Tambah, ubah, atau hapus akun yang bisa masuk ke aplikasi. Hanya admin yang dapat mengakses halaman ini.">

        <x-slot name="filters">
            <form wire:submit="save" class="space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nama</label>
                        <input type="text" wire:model="name"
                               class="w-full border-gray-300 rounded-md shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" wire:model="email"
                               class="w-full border-gray-300 rounded-md shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Password @if ($editingId)<span class="text-gray-400 font-normal">(kosongkan bila tak diubah)</span>@endif
                        </label>
                        <input type="password" wire:model="password" autocomplete="new-password"
                               class="w-full border-gray-300 rounded-md shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-end">
                        <label class="inline-flex items-center gap-2 pb-2">
                            <input type="checkbox" wire:model="is_admin"
                                   class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                            <span class="text-sm text-gray-700">Admin</span>
                        </label>
                        @error('is_admin') <p class="ml-3 mb-2 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button type="submit"
                            class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-md">
                        <span wire:loading.remove wire:target="save">{{ $editingId ? 'Simpan Perubahan' : 'Tambah User' }}</span>
                        <span wire:loading wire:target="save">Menyimpan…</span>
                    </button>
                    @if ($editingId)
                        <button type="button" wire:click="cancel"
                                class="inline-flex items-center px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-md">
                            Batal
                        </button>
                    @endif
                </div>
            </form>

            @if ($notice)
                <p class="mt-3 text-xs text-emerald-700 bg-emerald-50 border border-emerald-200 rounded px-2 py-1">{{ $notice }}</p>
            @endif
        </x-slot>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="w-12 px-4 py-3 text-left font-medium text-gray-500">No.</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Nama</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Email</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500">Peran</th>
                        <th class="w-40 px-4 py-3 text-right font-medium text-gray-500">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($users as $i => $user)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2 text-gray-500">{{ $i + 1 }}</td>
                            <td class="px-4 py-2 font-medium text-gray-800 whitespace-nowrap">
                                {{ $user->name }}
                                @if ($user->id === auth()->id())
                                    <span class="ml-1 text-xs text-gray-400">(Anda)</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-gray-700 whitespace-nowrap">{{ $user->email }}</td>
                            <td class="px-4 py-2 whitespace-nowrap">
                                @if ($user->is_admin)
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">Admin</span>
                                @else
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">User</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <button type="button" wire:click="edit({{ $user->id }})"
                                        class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">
                                    Edit
                                </button>
                                <button type="button"
                                        wire:click="delete({{ $user->id }})"
                                        wire:confirm="Hapus user {{ $user->name }}?"
                                        class="ml-3 text-red-600 hover:text-red-800 text-sm font-medium">
                                    Hapus
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-gray-400">Belum ada user.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-report-shell>
</div>
