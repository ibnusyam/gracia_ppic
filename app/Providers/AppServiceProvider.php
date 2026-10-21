<?php

namespace App\Providers;

use App\Listeners\RecordAuthActivity;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Catat aktivitas autentikasi ke tabel login_logs.
        Event::listen(Login::class, [RecordAuthActivity::class, 'onLogin']);
        Event::listen(Logout::class, [RecordAuthActivity::class, 'onLogout']);
        Event::listen(Failed::class, [RecordAuthActivity::class, 'onFailed']);

        // Hanya admin yang boleh mengelola user & melihat log login.
        Gate::define('admin', fn (User $user) => (bool) $user->is_admin);
    }
}
