<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Halaman "Kelola User" — khusus admin. Menambah, mengubah, menghapus user,
 * serta reset password. Data user disimpan di SQLite (koneksi default), terpisah
 * dari DB legacy PostgreSQL yang read-only.
 */
class UserManager extends Component
{
    /** Id user yang sedang diedit; null = mode tambah baru. */
    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public bool $is_admin = false;

    /** Pesan sukses/info yang tampil sekali setelah aksi. */
    public string $notice = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }

    protected function rules(): array
    {
        return [
            'name'  => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            // Saat tambah: wajib. Saat edit: opsional (kosong = password tidak diubah).
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', 'min:8'],
            'is_admin' => 'boolean',
        ];
    }

    protected function messages(): array
    {
        return [
            'email.unique' => 'Email sudah dipakai user lain.',
            'password.min' => 'Password minimal 8 karakter.',
        ];
    }

    public function edit(int $id): void
    {
        $user = User::findOrFail($id);
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->is_admin = (bool) $user->is_admin;
        $this->password = '';
        $this->notice = '';
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $data = $this->validate();

        if ($this->editingId) {
            $user = User::findOrFail($this->editingId);

            // Jangan sampai admin terakhir kehilangan status admin (aplikasi terkunci).
            if ($user->is_admin && ! $this->is_admin && $this->adminCount() <= 1) {
                $this->addError('is_admin', 'Tidak bisa menonaktifkan admin terakhir.');

                return;
            }

            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->is_admin = $this->is_admin;
            if (! empty($data['password'])) {
                $user->password = $data['password']; // cast 'hashed' -> otomatis di-hash
            }
            $user->save();

            $this->notice = "User {$user->name} diperbarui.";
        } else {
            User::create([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'password' => $data['password'],
                'is_admin' => $this->is_admin,
            ]);

            $this->notice = "User {$data['name']} ditambahkan.";
        }

        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            $this->notice = 'Tidak bisa menghapus akun Anda sendiri.';

            return;
        }

        if ($user->is_admin && $this->adminCount() <= 1) {
            $this->notice = 'Tidak bisa menghapus admin terakhir.';

            return;
        }

        $name = $user->name;
        $user->delete();

        if ($this->editingId === $id) {
            $this->resetForm();
        }

        $this->notice = "User {$name} dihapus.";
    }

    private function adminCount(): int
    {
        return User::where('is_admin', true)->count();
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'email', 'password', 'is_admin']);
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.user-manager', [
            'users' => User::orderBy('name')->get(),
        ]);
    }
}
