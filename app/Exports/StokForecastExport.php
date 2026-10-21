<?php

namespace App\Exports;

use App\Reports\StokForecastReport;
use App\Support\Fmt;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class StokForecastExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithEvents, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * Hanya % Realisasi yang ditulis sebagai TEKS ('–', '#', '85,50%') karena mengandung tanda %.
     * Kolom lain (termasuk DOI) ditulis sebagai ANGKA asli — bukan string berformat Indonesia —
     * supaya Excel tidak salah membaca "1.210" sebagai 1,21. Format ribuan diserahkan ke Excel
     * via kode '#,##0'.
     */
    private const RATIO_KEYS = ['persen'];

    /** Kolom MOI (bulan): angka murni; null (forecast = 0) ditampilkan sebagai 0, tanpa tanda '#'/'–'. */
    private const DOI_KEYS = ['doi', 'doi_free', 'doi_kar', 'doi_wip', 'doi_bp'];

    private const NUMBER_FORMAT = '#,##0';

    /** MOI dalam bulan -> 1 angka di belakang koma (mis. 1,2 / 2,7). */
    private const MOI_NUMBER_FORMAT = '#,##0.0';

    /** Warna latar hijau untuk % Sales > 100% (sama dgn tabel layar & PDF: #86EFAC). */
    private const GREEN_FILL = '86EFAC';

    private int $n = 0;

    /** Nomor baris Excel (header = baris 1) yang % Sales-nya > 100%, diwarnai di AfterSheet. */
    private array $greenRows = [];

    public function __construct(
        private string $month = '',
        private string $search = '',
        private string $sortField = 'nama',
        private string $sortDir = 'asc',
        private string $category = 'all',
        private int $edMonths = StokForecastReport::ED_MONTHS_DEFAULT,
        private string $status = StokForecastReport::STATUS_DEFAULT,
    ) {}

    public function collection()
    {
        $rows = (new StokForecastReport())->rows($this->month, $this->edMonths, $this->status);

        return StokForecastReport::arrange($rows, $this->search, $this->sortField, $this->sortDir, $this->category);
    }

    public function headings(): array
    {
        // Excel tak punya header bertingkat, jadi label diberi prefiks grup agar jelas.
        $cols = ['No.', 'Kode', 'Nama Produk', 'SL', 'Batch Size'];
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
     * Kolom D = SL, E = Batch Size, lalu kolom F dst mengikuti urutan GROUPS.
     */
    public function columnFormats(): array
    {
        $formats = ['D' => self::NUMBER_FORMAT, 'E' => self::NUMBER_FORMAT]; // SL, Batch Size
        $i = 6; // kolom F (setelah No/Kode/Nama/SL/Batch Size)
        foreach (StokForecastReport::GROUPS as $subs) {
            foreach ($subs as $key => $label) {
                if (in_array($key, self::RATIO_KEYS, true)) {
                    $fmt = NumberFormat::FORMAT_TEXT;
                } elseif (in_array($key, self::DOI_KEYS, true)) {
                    $fmt = self::MOI_NUMBER_FORMAT;
                } else {
                    $fmt = self::NUMBER_FORMAT;
                }
                $formats[Coordinate::stringFromColumnIndex($i)] = $fmt;
                $i++;
            }
        }

        return $formats;
    }

    public function map($row): array
    {
        $this->n++;

        if ($row->persen !== null && (float) $row->persen > 100) {
            $this->greenRows[] = $this->n + 1; // +1 karena baris 1 = heading
        }

        $out = [(string) $this->n, (string) $row->kode, (string) $row->nama, $row->sl === null ? null : (float) $row->sl, (float) $row->batch_size];

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

    /** Huruf kolom Excel untuk sebuah key laporan (kolom F dst mengikuti urutan GROUPS). */
    private static function columnOf(string $key): ?string
    {
        $i = 6;
        foreach (StokForecastReport::GROUPS as $subs) {
            foreach ($subs as $k => $label) {
                if ($k === $key) {
                    return Coordinate::stringFromColumnIndex($i);
                }
                $i++;
            }
        }

        return null;
    }

    /** Warnai sel % Sales > 100% dengan hijau setelah semua baris tertulis. */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $col = self::columnOf('persen');
                if ($col === null) {
                    return;
                }
                $sheet = $event->sheet->getDelegate();
                foreach ($this->greenRows as $r) {
                    $sheet->getStyle($col.$r)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB(self::GREEN_FILL);
                }
            },
        ];
    }
}
