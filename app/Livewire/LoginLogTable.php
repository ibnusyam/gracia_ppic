<?php

namespace App\Livewire;

use App\Models\LoginLog;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Tabel "Log Login" — riwayat siapa saja yang masuk / keluar aplikasi,
 * beserta percobaan login yang gagal. Sumber data: tabel login_logs.
 */
class LoginLogTable extends Component
{
    use WithPagination;

    /** Filter jenis aktivitas: '' (semua) | login | logout | failed. */
    #[Url]
    public string $event = '';

    /** Pencarian nama / email / IP. */
    #[Url]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }

    public function updating(): void
    {
        // Setiap ganti filter, kembali ke halaman 1.
        $this->resetPage();
    }

    public function render()
    {
        $logs = LoginLog::query()
            ->when($this->event !== '', fn ($q) => $q->where('event', $this->event))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('ip_address', 'like', $term);
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.login-log-table', [
            'logs' => $logs,
        ]);
    }
}
