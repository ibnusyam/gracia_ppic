<?php

namespace App\Http\Controllers;

use App\Exports\StokForecastExport;
use App\Reports\StokForecastReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class StokForecastExportController extends Controller
{
    private function params(Request $request): array
    {
        return [
            'month'     => (string) $request->query('month', ''),
            'search'    => (string) $request->query('search', ''),
            'category'  => (string) $request->query('category', 'all'),
            'status'    => (string) $request->query('status', StokForecastReport::STATUS_DEFAULT),
            'edMonths'  => (int) $request->query('ed_months', StokForecastReport::ED_MONTHS_DEFAULT),
            'sortField' => (string) $request->query('sort_field', 'nama'),
            'sortDir'   => (string) $request->query('sort_dir', 'asc'),
        ];
    }

    public function excel(Request $request)
    {
        $p = $this->params($request);
        $filename = 'monitoring-stok-forecast-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(
            new StokForecastExport($p['month'], $p['search'], $p['sortField'], $p['sortDir'], $p['category'], $p['edMonths'], $p['status']),
            $filename
        );
    }

    public function pdf(Request $request)
    {
        $p = $this->params($request);
        $rows = StokForecastReport::arrange(
            (new StokForecastReport())->rows($p['month'], $p['edMonths'], $p['status']),
            $p['search'], $p['sortField'], $p['sortDir'], $p['category']
        );

        $monthLabel = \Illuminate\Support\Carbon::parse(($p['month'] ?: now()->format('Y-m')).'-01')->format('m/Y');

        $pdf = Pdf::loadView('reports.stok-forecast-pdf', [
            'rows' => $rows,
            'groups' => StokForecastReport::groups($p['edMonths']),
            'monthLabel' => $monthLabel,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('monitoring-stok-forecast-'.now()->format('Ymd-His').'.pdf');
    }
}
