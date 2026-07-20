@php use App\Support\Fmt; @endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 9px; color: #111; }
        h1 { font-size: 14px; margin: 0 0 2px; }
        .meta { font-size: 9px; color: #555; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 0.5px solid #999; padding: 3px 4px; }
        th { background: #eee; text-align: left; }
        th.grp { text-align: center; background: #ddd; }
        th.num, td.num { text-align: right; }
        /* garis tebal pemisah antar grup (tiap grup = 2 kolom) */
        th.gstart, td.gstart { border-left: 1.4px solid #555; }
        tr { page-break-inside: avoid; }
    </style>
</head>
<body>
    <h1>Monitoring Stok</h1>
    <div class="meta">
        @if ($startDate || $endDate)
            Filter Tgl. Input: {{ Fmt::date($startDate) ?: '…' }} s/d {{ Fmt::date($endDate) ?: '…' }} &middot;
        @endif
        Total {{ number_format($rows->count(), 0, ',', '.') }} produk &middot; dicetak {{ now()->format('d/m/Y H:i') }}
    </div>

    <table>
        <thead>
            <tr>
                <th rowspan="2">No.</th>
                <th rowspan="2">Kode Produk</th>
                <th rowspan="2">Nama Produk</th>
                <th class="grp gstart" colspan="2">Batch Belum Proses</th>
                <th class="grp gstart" colspan="2">WIP</th>
                <th class="grp gstart" colspan="2">Toll OUT</th>
                <th class="grp gstart" colspan="2">Batch Selesai</th>
                <th class="grp gstart" colspan="2">Total</th>
            </tr>
            <tr>
                @for ($g = 0; $g < 5; $g++)
                    <th class="num gstart">Jumlah</th>
                    <th class="num">Batch Size</th>
                @endfor
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $i => $row)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $row->kode_produk }}</td>
                    <td>{{ $row->nama_produk }}</td>
                    <td class="num gstart">{{ Fmt::num($row->br_belum_proses, 0) }}</td>
                    <td class="num">{{ Fmt::num($row->size_br_belum_proses, 0) }}</td>
                    <td class="num gstart">{{ Fmt::num($row->wip, 0) }}</td>
                    <td class="num">{{ Fmt::num($row->size_wip, 0) }}</td>
                    <td class="num gstart">{{ Fmt::num($row->toll_out, 0) }}</td>
                    <td class="num">{{ Fmt::num($row->size_toll_out, 0) }}</td>
                    <td class="num gstart">{{ Fmt::num($row->batch_selesai, 0) }}</td>
                    <td class="num">{{ Fmt::num($row->size_batch_selesai, 0) }}</td>
                    <td class="num gstart">{{ Fmt::num($row->total, 0) }}</td>
                    <td class="num">{{ Fmt::num($row->size_total, 0) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
