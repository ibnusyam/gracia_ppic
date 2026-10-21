<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris riwayat aktivitas autentikasi.
 *
 * @property string|null $event  login | logout | failed
 */
class LoginLog extends Model
{
    /** Hanya punya created_at (diisi manual saat event), tanpa updated_at. */
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'event',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
