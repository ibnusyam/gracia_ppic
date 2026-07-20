<?php

namespace App\Exports;

use App\Reports\WipBelumProsesReport;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Export Excel "WIP dan BR Belum Proses" — SATU BARIS PER BATCH (flat), dikelompokkan & diurutkan
 * per produk sama seperti tampilan. Kolom Batch Size ditulis sebagai ANGKA asli (bukan string
 * berformat Indonesia) + format sel '#,##0' supaya Excel tak salah membaca & angka 0 tidak hilang.
 * Nomor Batch dipaksa TEKS agar kode batch yang mirip angka tak berubah.
 */
class WipBelumProsesExport implements FromCollection, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStrictNullComparison
{
    public function __construct(
        private string $from,
        private string $to,
        private string $sortField = 'nama',
        private string $sortDir = 'asc',
    ) {}

    public function collection()
    {
        $rows = (new WipBelumProsesReport())->rows($this->from, $this->to);
        ['products' => $products, 'totals' => $totals] =
            WipBelumProsesReport::group($rows, $this->sortField, $this->sortDir);

        $out = collect();
        $no = 0;
        foreach ($products as $p) {
            // Urutan kolom mengikuti tampilan: BR Belum Proses dulu, lalu WIP.
            foreach (['belum' => 'BR Belum Proses', 'wip' => 'WIP'] as $key => $label) {
                foreach ($p[$key] as $b) {
                    $no++;
                    $out->push([
                        $no,
                        $p['kode'],
                        $p['nama'],
                        $label,
                        (string) $b['no_batch'],
                        (float) $b['batch_size'], // ANGKA asli
                    ]);
                }
            }
        }

        // Baris TOTAL (Batch Size tetap numerik).
        $out->push(['', '', '', 'TOTAL', $totals['all']['n'].' batch', (float) $totals['all']['size']]);

        return $out;
    }

    public function headings(): array
    {
        return ['No.', 'Kode', 'Nama Produk', 'Kondisi', 'Nomor Batch', 'Batch Size'];
    }

    public function columnFormats(): array
    {
        return [
            'E' => NumberFormat::FORMAT_TEXT, // Nomor Batch = teks
            'F' => '#,##0',                   // Batch Size = angka ribuan
        ];
    }
}
