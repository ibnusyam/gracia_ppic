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
        /* garis tebal pemisah antar grup */
        th.gstart, td.gstart { border-left: 1.4px solid #555; }
        tr { page-break-inside: avoid; }
    </style>
</head>
<body>
    <h1>Monitoring Stok &amp; Forecast</h1>
    <div class="meta">
        Forecast/Pengeluaran/Outstanding bulan {{ $monthLabel ?? now()->format('m/Y') }} &middot;
        stok &amp; batch per {{ now()->format('d/m/Y') }} &middot;
        Total {{ number_format($rows->count(), 0, ',', '.') }} produk &middot;
        dicetak {{ now()->format('d/m/Y H:i') }}
    </div>

    <table>
        <thead>
            <tr>
                <th rowspan="2">No.</th>
                <th rowspan="2">Kode</th>
                <th rowspan="2">Nama Produk</th>
                <th rowspan="2" class="num">Batch Size</th>
                @foreach ($groups as $glabel => $subs)
                    @if (count($subs) === 1)
                        <th class="grp gstart num" rowspan="2">{{ $glabel }}</th>
                    @else
                        <th class="grp gstart" colspan="{{ count($subs) }}">{{ $glabel }}</th>
                    @endif
                @endforeach
            </tr>
            <tr>
                @foreach ($groups as $subs)
                    @if (count($subs) > 1)
                        @php $first = true; @endphp
                        @foreach ($subs as $sublabel)
                            <th class="num {{ $first ? 'gstart' : '' }}">{{ $sublabel }}</th>
                            @php $first = false; @endphp
                        @endforeach
                    @endif
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $i => $row)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $row->kode }}</td>
                    <td>{{ $row->nama }}</td>
                    <td class="num">{{ Fmt::num($row->batch_size, 0) }}</td>
                    <td class="num gstart">{{ Fmt::num($row->forecast, 0) }}</td>
                    <td class="num gstart">{{ Fmt::num($row->pengeluaran, 0) }}</td>
                    <td class="num">{{ Fmt::ratio($row->persen, 1) }}</td>
                    <td class="num gstart">{{ Fmt::num($row->ed_lt9, 0) }}</td>
                    <td class="num">{{ Fmt::num($row->ed_gte9, 0) }}</td>
                    <td class="num">{{ Fmt::num($row->total_stok, 0) }}</td>
                    <td class="num">{{ Fmt::num($row->doi ?? 0, 0) }}</td>
                    <td class="num gstart">{{ Fmt::num($row->outstanding, 0) }}</td>
                    <td class="num">{{ Fmt::num($row->freestock, 0) }}</td>
                    <td class="num">{{ Fmt::num($row->doi_free ?? 0, 0) }}</td>
                    <td class="num gstart">{{ Fmt::num($row->fg_karantina, 0) }}</td>
                    <td class="num">{{ Fmt::num($row->freestock_kar, 0) }}</td>
                    <td class="num">{{ Fmt::num($row->doi_kar ?? 0, 0) }}</td>
                    <td class="num gstart">{{ Fmt::num($row->size_wip, 0) }}</td>
                    <td class="num">{{ Fmt::num($row->freestock_wip, 0) }}</td>
                    <td class="num">{{ Fmt::num($row->doi_wip ?? 0, 0) }}</td>
                    <td class="num gstart">{{ Fmt::num($row->size_br_belum_proses, 0) }}</td>
                    <td class="num">{{ Fmt::num($row->freestock_bp, 0) }}</td>
                    <td class="num">{{ Fmt::num($row->doi_bp ?? 0, 0) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
