<?php

namespace App\Listeners;

use App\Models\LoginLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;

/**
 * Mencatat aktivitas autentikasi ke tabel login_logs.
 *
 * Didaftarkan di AppServiceProvider untuk tiga event bawaan Laravel:
 * Login (berhasil), Logout, dan Failed (kredensial salah).
 */
class RecordAuthActivity
{
    public function __construct(private Request $request) {}

    // Nama method sengaja TIDAK diawali "handle" agar tidak ikut ter-auto-discover
    // oleh Laravel (yang akan menyebabkan pencatatan dobel); pendaftaran dilakukan
    // secara eksplisit di AppServiceProvider.
    public function onLogin(Login $event): void
    {
        $this->record('login', $event->user->getAttribute('name'), $event->user->getAttribute('email'), $event->user->getAuthIdentifier());
    }

    public function onLogout(Logout $event): void
    {
        $user = $event->user;

        $this->record(
            'logout',
            $user?->getAttribute('name'),
            $user?->getAttribute('email'),
            $user?->getAuthIdentifier(),
        );
    }

    public function onFailed(Failed $event): void
    {
        // Login gagal: user tidak dikenal, tapi email yang dicoba tetap dicatat.
        $this->record('failed', null, $event->credentials['email'] ?? null, null);
    }

    private function record(string $event, ?string $name, ?string $email, int|string|null $userId): void
    {
        LoginLog::create([
            'user_id'    => $userId,
            'name'       => $name,
            'email'      => $email,
            'event'      => $event,
            'ip_address' => $this->request->ip(),
            'user_agent' => substr((string) $this->request->userAgent(), 0, 1000),
            'created_at' => now(),
        ]);
    }
}
