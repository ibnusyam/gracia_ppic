<?php

namespace App\Exports;

use App\Reports\MonitoringStokReport;
use App\Support\Fmt;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class MonitoringStokExport implements FromCollection, WithHeadings, WithMapping
{
    private int $n = 0;

    public function __construct(
        private ?string $startDate = null,
        private ?string $endDate = null,
        private string $search = '',
        private string $sortField = 'kode_produk',
        private string $sortDir = 'asc',
    ) {}

    public function collection()
    {
        $rows = (new MonitoringStokReport())->rows($this->startDate, $this->endDate);

        return MonitoringStokReport::arrange($rows, $this->search, $this->sortField, $this->sortDir);
    }

    public function headings(): array
    {
        // Header eksplisit — Excel tak punya header bertingkat, jadi "<Grup> - Batch Size".
        $cols = ['No.', 'Kode Produk', 'Nama Produk'];
        foreach (MonitoringStokReport::GROUPS as $label) {
            $cols[] = $label;
            $cols[] = $label.' - Batch Size';
        }

        return $cols;
    }

    public function map($row): array
    {
        $this->n++;

        return [
            $this->n,
            $row->kode_produk,
            $row->nama_produk,
            Fmt::num($row->br_belum_proses, 0),
            Fmt::num($row->size_br_belum_proses, 0),
            Fmt::num($row->wip, 0),
            Fmt::num($row->size_wip, 0),
            Fmt::num($row->toll_out, 0),
            Fmt::num($row->size_toll_out, 0),
            Fmt::num($row->batch_selesai, 0),
            Fmt::num($row->size_batch_selesai, 0),
            Fmt::num($row->total, 0),
            Fmt::num($row->size_total, 0),
        ];
    }
}
