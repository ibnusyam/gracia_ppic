<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daftar produk "Toll Out" (makloon / diproduksi di luar) — disimpan di DB Laravel sendiri (SQLite),
 * BUKAN di DB legacy gracia_mrp yang READ-ONLY. Dipakai untuk menandai baris di Monitoring Stok
 * (highlight kuning DOI Belum Proses <= 80 hari).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toll_out_products', function (Blueprint $table) {
            $table->id();
            $table->string('kode')->unique();
            $table->string('nama')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('toll_out_products');
    }
};
