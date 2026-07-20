<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Kode produk Toll Out (makloon). Tersimpan di koneksi default (SQLite), writable.
 * Kode disimpan ternormalisasi (huruf besar, tanpa spasi tepi) supaya cocok dgn kode di master_barang.
 */
class TollOutProduct extends Model
{
    protected $fillable = ['kode', 'nama'];

    /**
     * Set kode Toll Out ternormalisasi untuk pencocokan cepat: [KODE => true, ...].
     * Dipakai tabel Monitoring Stok untuk menandai baris tanpa query per-baris.
     *
     * @return array<string,int>
     */
    public static function set(): array
    {
        return array_flip(
            self::query()->pluck('kode')
                ->map(fn ($k) => strtoupper(trim((string) $k)))
                ->all()
        );
    }
}
