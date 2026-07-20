<?php

namespace App\Http\Controllers;

use App\Exports\MonitoringStokExport;
use App\Reports\MonitoringStokReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class MonitoringStokExportController extends Controller
{
    private function params(Request $request): array
    {
        return [
            'start' => $request->query('start_date') ?: null,
            'end'   => $request->query('end_date') ?: null,
            'search'   => (string) $request->query('search', ''),
            'sortField' => (string) $request->query('sort_field', 'kode_produk'),
            'sortDir'   => (string) $request->query('sort_dir', 'asc'),
        ];
    }

    public function excel(Request $request)
    {
        $p = $this->params($request);
        $filename = 'monitoring-stok-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(
            new MonitoringStokExport($p['start'], $p['end'], $p['search'], $p['sortField'], $p['sortDir']),
            $filename
        );
    }

    public function pdf(Request $request)
    {
        $p = $this->params($request);
        $rows = MonitoringStokReport::arrange(
            (new MonitoringStokReport())->rows($p['start'], $p['end']),
            $p['search'], $p['sortField'], $p['sortDir']
        );

        $pdf = Pdf::loadView('reports.monitoring-stok-pdf', [
            'rows' => $rows,
            'columns' => MonitoringStokReport::COLUMNS,
            'startDate' => $p['start'],
            'endDate' => $p['end'],
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('monitoring-stok-'.now()->format('Ymd-His').'.pdf');
    }
}
