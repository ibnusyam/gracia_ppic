<?php

namespace App\Support;

use Carbon\Carbon;
use Throwable;

/**
 * Format tampilan gaya Indonesia untuk laporan:
 *  - angka ribuan: 8.807,00
 *  - tanggal: dd/mm/yyyy
 */
class Fmt
{
    /** Angka dengan pemisah ribuan "." dan desimal "," (default 2 desimal). */
    public static function num(int|float|string|null $value, int $decimals = 2): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, $decimals, ',', '.');
    }

    /**
     * Tampilan nilai rasio hasil perkalian/pembagian forecast (DOI, % Realisasi, dst).
     *  - null  -> "–"  (Forecast = 0 / tak terdefinisi)
     *  - 0     -> "#"  (hasil perkalian nol; dibedakan dari kosong agar tidak rancu)
     *  - lain  -> angka terformat + $suffix.
     */
    public static function ratio(int|float|string|null $value, int $decimals = 1, string $suffix = ''): string
    {
        if ($value === null || $value === '') {
            return '–';
        }
        if ((float) $value == 0.0) {
            return '#';
        }

        return self::num($value, $decimals).$suffix;
    }

    /** Tanggal dd/mm/yyyy. Mengembalikan '' bila kosong/invalid. */
    public static function date(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (Throwable) {
            return (string) $value;
        }
    }
}
