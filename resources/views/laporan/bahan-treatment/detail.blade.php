@php
    $logoBase64 = null;
    $logoPath = public_path('logo.png');

    if (is_file($logoPath)) {
        $extension = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mime = in_array($extension, ['jpg', 'jpeg'], true) ? 'image/jpeg' : 'image/png';
        $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoPath));
    }

    $formatQty = static function ($value): string {
        $formatted = number_format((float) $value, 4, ',', '.');
        return rtrim(rtrim($formatted, '0'), ',');
    };
@endphp

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $report['title'] }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm 10mm 13mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #111827;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8.5px;
        }

        .report-header {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .report-header td {
            border: 0;
            vertical-align: middle;
        }

        .logo-cell {
            width: 19%;
            height: 55px;
        }

        .logo-cell img {
            width: 125px;
            max-height: 49px;
            object-fit: contain;
        }

        .logo-fallback {
            font-size: 15px;
            font-weight: bold;
        }

        .title-cell {
            width: 48%;
            padding-left: 5px;
        }

        .report-title {
            color: #17365d;
            font-size: 13px;
            font-weight: bold;
            line-height: 1.2;
        }

        .report-subtitle {
            margin-top: 2px;
            color: #334155;
            font-size: 7.5px;
            font-weight: bold;
        }

        .meta-cell {
            width: 33%;
            text-align: right;
            font-size: 7.5px;
            line-height: 1.35;
        }

        .divider {
            margin: 2px 0 10px;
            border-top: 2px solid #334155;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .report-table thead {
            display: table-header-group;
        }

        .report-table th,
        .report-table td {
            border: 1px solid #7b8794;
            padding: 3px 5px;
            vertical-align: middle;
            line-height: 1.2;
            word-break: break-word;
        }

        .report-table th {
            background: #d9e2f3;
            color: #111827;
            font-size: 7.8px;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
        }

        .registration-row td {
            background: #1f4e78;
            color: #ffffff;
            font-weight: bold;
            padding: 4px 6px;
        }

        .treatment-row td {
            background: #dce6f1;
            color: #334155;
            font-size: 7.8px;
            font-style: italic;
            font-weight: bold;
            padding: 4px 16px;
        }

        .center {
            text-align: center;
        }

        .right {
            text-align: right;
        }

        .empty-row td {
            height: 35px;
            color: #64748b;
            font-style: italic;
            text-align: center;
        }

        .footer {
            position: fixed;
            right: 0;
            bottom: -8mm;
            color: #475569;
            font-size: 7px;
        }

        .page-number::after {
            content: counter(page) "/" counter(pages);
        }

        .keep-row {
            page-break-inside: avoid;
        }
    </style>
</head>
<body>
    <table class="report-header">
        <tr>
            <td class="logo-cell">
                @if ($logoBase64)
                    <img src="{{ $logoBase64 }}" alt="MS Glow Aesthetic">
                @else
                    <div class="logo-fallback">MS GLOW<br>AESTHETIC</div>
                @endif
            </td>
            <td class="title-cell">
                <div class="report-title">{{ $report['title'] }}</div>
                <div class="report-subtitle">MS GLOW AESTHETIC {{ $report['branch_label'] }}</div>
            </td>
            <td class="meta-cell">
                <div><strong>Cabang:</strong> {{ $report['branch_label'] }}</div>
                <div><strong>Periode:</strong> {{ $report['period_label'] }}</div>
                <div><strong>Dicetak:</strong> {{ $report['generated_at'] }}</div>
            </td>
        </tr>
    </table>

    <div class="divider"></div>

    <table class="report-table">
        <thead>
            <tr>
                <th style="width: 8%;">No.</th>
                <th style="width: 18%;">Kode Bahan</th>
                <th style="width: 53%;">Nama Bahan</th>
                <th style="width: 11%;">Satuan</th>
                <th style="width: 10%;">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['groups'] as $registration)
                <tr class="registration-row keep-row">
                    <td colspan="5">
                        {{ $registration['no_invoice'] }}
                        &nbsp;—&nbsp; {{ date('d/m/Y', strtotime($registration['tanggal'])) }}
                        &nbsp;—&nbsp; {{ $registration['nama_pasien'] }}
                        @if ($registration['no_rm'] !== '-')
                            ({{ $registration['no_rm'] }})
                        @endif
                    </td>
                </tr>

                @foreach ($registration['treatments'] as $treatment)
                    <tr class="treatment-row keep-row">
                        <td colspan="5">
                            [{{ $treatment['kode_treatment'] }}] {{ $treatment['nama_treatment'] }}
                        </td>
                    </tr>

                    @foreach ($treatment['items'] as $item)
                        <tr class="keep-row">
                            <td class="center">{{ $item['no'] }}</td>
                            <td class="center">{{ $item['kode_bahan'] }}</td>
                            <td>{{ $item['nama_bahan'] }}</td>
                            <td class="center">{{ $item['satuan'] }}</td>
                            <td class="right">{{ $formatQty($item['jumlah']) }}</td>
                        </tr>
                    @endforeach
                @endforeach
            @empty
                <tr class="empty-row">
                    <td colspan="5">Tidak ada penggunaan bahan treatment pada periode ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Hal. <span class="page-number"></span>
    </div>
</body>
</html>
