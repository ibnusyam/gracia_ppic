<?php

namespace App\Http\Controllers;

use App\Exports\WipBelumProsesExport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class WipBelumProsesExportController extends Controller
{
    public function excel(Request $request)
    {
        $from = (string) $request->query('from', now()->subMonths(6)->format('Y-m-d'));
        $to   = (string) $request->query('to', now()->format('Y-m-d'));
        $sortField = (string) $request->query('sort_field', 'nama');
        $sortDir   = (string) $request->query('sort_dir', 'asc');

        $filename = 'wip-belum-proses-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(
            new WipBelumProsesExport($from, $to, $sortField, $sortDir),
            $filename
        );
    }
}
