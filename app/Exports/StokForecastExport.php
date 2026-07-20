<?php

namespace App\Exports;

use App\Reports\StokForecastReport;
use App\Support\Fmt;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class StokForecastExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * Hanya % Realisasi yang ditulis sebagai TEKS ('–', '#', '85,50%') karena mengandung tanda %.
     * Kolom lain (termasuk DOI) ditulis sebagai ANGKA asli — bukan string berformat Indonesia —
     * supaya Excel tidak salah membaca "1.210" sebagai 1,21. Format ribuan diserahkan ke Excel
     * via kode '#,##0'.
     */
    private const RATIO_KEYS = ['persen'];

    /** Kolom DOI: angka murni; null (forecast = 0) ditampilkan sebagai 0, tanpa tanda '#'/'–'. */
    private const DOI_KEYS = ['doi', 'doi_free', 'doi_kar', 'doi_wip', 'doi_bp'];

    private const NUMBER_FORMAT = '#,##0';

    private int $n = 0;

    public function __construct(
        private string $month = '',
        private string $search = '',
        private string $sortField = 'nama',
        private string $sortDir = 'asc',
        private string $category = 'all',
        private int $edMonths = StokForecastReport::ED_MONTHS_DEFAULT,
    ) {}

    public function collection()
    {
        $rows = (new StokForecastReport())->rows($this->month, $this->edMonths);

        return StokForecastReport::arrange($rows, $this->search, $this->sortField, $this->sortDir, $this->category);
    }

    public function headings(): array
    {
        // Excel tak punya header bertingkat, jadi label diberi prefiks grup agar jelas.
        $cols = ['No.', 'Kode', 'Nama Produk', 'Batch Size'];
        foreach (StokForecastReport::groups($this->edMonths) as $glabel => $subs) {
            foreach ($subs as $sublabel) {
                // Grup 1 kolom (sub-label '') -> pakai label grup saja (mis. "FORECAST").
                $cols[] = $sublabel === '' ? $glabel : $glabel.' - '.$sublabel;
            }
        }

        return $cols;
    }

    /**
     * Kolom angka -> format ribuan '#,##0' (0 = tetap tampil "0"); kolom rasio -> TEXT.
     * Kolom D = Batch Size, lalu kolom E dst mengikuti urutan GROUPS.
     */
    public function columnFormats(): array
    {
        $formats = ['D' => self::NUMBER_FORMAT]; // Batch Size
        $i = 5; // kolom E (setelah No/Kode/Nama/Batch Size)
        foreach (StokForecastReport::GROUPS as $subs) {
            foreach ($subs as $key => $label) {
                $formats[Coordinate::stringFromColumnIndex($i)] = in_array($key, self::RATIO_KEYS, true)
                    ? NumberFormat::FORMAT_TEXT
                    : self::NUMBER_FORMAT;
                $i++;
            }
        }

        return $formats;
    }

    public function map($row): array
    {
        $this->n++;

        $out = [(string) $this->n, (string) $row->kode, (string) $row->nama, (float) $row->batch_size];

        foreach (StokForecastReport::GROUPS as $subs) {
            foreach ($subs as $key => $label) {
                if (in_array($key, self::RATIO_KEYS, true)) {
                    // Realisasi: tanpa simbol %, 1 angka di belakang koma.
                    $out[] = Fmt::ratio($row->$key, 1);
                } elseif (in_array($key, self::DOI_KEYS, true)) {
                    // DOI: angka murni; null -> 0.
                    $out[] = (float) ($row->$key ?? 0);
                } else {
                    // Angka asli — biar Excel yang memformat ribuan (tak ada 0 di belakang yang hilang).
                    $out[] = $row->$key === null ? null : (float) $row->$key;
                }
            }
        }

        return $out;
    }
}
