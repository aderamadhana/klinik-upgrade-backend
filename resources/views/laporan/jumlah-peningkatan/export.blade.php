@php
    $logoBase64 = null;
    $logoPath = public_path('logo.png');

    if (is_file($logoPath)) {
        $extension = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mime = in_array($extension, ['jpg', 'jpeg'], true) ? 'image/jpeg' : 'image/png';
        $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoPath));
    }
@endphp

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $report['title'] }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm 14mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #111111;
            font-family: "Times New Roman", Times, serif;
            font-size: 11px;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .header-table td {
            border: 0;
            vertical-align: middle;
        }

        .logo-cell {
            width: 31%;
            height: 76px;
            text-align: center;
        }

        .logo-cell img {
            display: inline-block;
            width: 175px;
            max-height: 68px;
            object-fit: contain;
        }

        .company-cell {
            width: 69%;
            text-align: center;
        }

        .company-name {
            margin: 0;
            font-size: 22px;
            font-weight: bold;
            line-height: 1.2;
        }

        .company-contact {
            margin-top: 3px;
            font-size: 10px;
        }

        .divider {
            margin: 10px 0 10px;
            border-top: 1px solid #777777;
        }

        .period {
            margin-bottom: 5px;
            font-size: 11px;
        }

        table.report-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .report-table th,
        .report-table td {
            border: 1px solid #222222;
            padding: 3px 4px;
            line-height: 1.2;
            vertical-align: middle;
        }

        .report-table th {
            font-weight: bold;
            text-align: left;
        }

        .report-table td.number,
        .report-table th.number {
            text-align: center;
        }

        .empty-row {
            height: 32px;
            text-align: center;
        }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td class="logo-cell">
                @if ($logoBase64)
                    <img src="{{ $logoBase64 }}" alt="MS Glow Aesthetic">
                @else
                    <strong style="font-size: 20px;">MS GLOW AESTHETIC</strong>
                @endif
            </td>
            <td class="company-cell">
                <div class="company-name">{{ $report['company_name'] }}</div>
                <div class="company-contact">{{ $report['company_contact'] }}</div>
            </td>
        </tr>
    </table>

    <div class="divider"></div>

    <div class="period">
        TANGGAL : {{ $report['period_label'] }}
        @if (($report['branch_label'] ?? '') !== 'SEMUA CABANG')
            ({{ $report['branch_label'] }})
        @endif
    </div>

    <table class="report-table">
        <thead>
            <tr>
                <th class="number" style="width: 7%;">No.</th>
                <th style="width: 25%;">TOTAL PEMBELIAN</th>
                <th style="width: 26%;">TOTAL PERAWATAN</th>
                <th style="width: 27%;">TOTAL PASIEN BARU</th>
                <th style="width: 15%;">TOKO ID</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    <td class="number">{{ $row['no'] }}</td>
                    <td>{{ number_format((int) $row['total_pembelian'], 0, ',', '.') }}</td>
                    <td>{{ number_format((int) $row['total_perawatan'], 0, ',', '.') }}</td>
                    <td>{{ number_format((int) $row['total_pasien_baru'], 0, ',', '.') }}</td>
                    <td>{{ $row['toko_nama'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="empty-row">
                        Tidak ada cabang yang sesuai dengan filter laporan.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
