<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catatan aktivitas autentikasi (login berhasil, logout, dan login gagal).
     * Disimpan di DB aplikasi (sqlite), TERPISAH dari DB legacy pgsql yang read-only.
     */
    public function up(): void
    {
        Schema::create('login_logs', function (Blueprint $table) {
            $table->id();
            // user_id null bila login gagal (kredensial tak dikenal).
            $table->foreignId('user_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->index();
            // 'login' | 'logout' | 'failed'
            $table->string('event', 20)->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_logs');
    }
};
