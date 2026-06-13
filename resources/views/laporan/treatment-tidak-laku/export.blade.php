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
            margin: 11mm 12mm;
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
        }

        .company-cell {
            width: 69%;
            text-align: center;
        }

        .company-name {
            margin: 0;
            font-size: 20px;
            font-weight: bold;
            line-height: 1.1;
        }

        .company-contact {
            margin-top: 4px;
            font-size: 10px;
        }

        .branch-name {
            margin-top: 4px;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8px;
            font-weight: bold;
        }

        .separator {
            height: 1px;
            margin: 9px 0 8px;
            border-top: 1px solid #8b8b8b;
        }

        .period {
            margin-bottom: 4px;
            font-size: 11px;
            font-weight: bold;
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
            border: 0.8px solid #333333;
            padding: 3px 4px;
            vertical-align: middle;
        }

        .report-table th {
            background: #f3f3f3;
            font-size: 11px;
            font-weight: bold;
            text-align: left;
        }

        .report-table td {
            font-size: 11px;
            line-height: 1.15;
        }

        .number-column {
            width: 35px;
            text-align: center;
        }

        .empty-row td {
            height: 30px;
            color: #666666;
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
                @endif
            </td>
            <td class="company-cell">
                <div class="company-name">{{ $report['company_name'] }}</div>
                <div class="company-contact">{{ $report['company_contact'] }}</div>
                <div class="branch-name">{{ $report['branch_label'] }}</div>
            </td>
        </tr>
    </table>

    <div class="separator"></div>

    <div class="period">
        TANGGAL : {{ $report['tanggal_awal'] }} s/d {{ $report['tanggal_akhir'] }}
    </div>

    <table class="report-table">
        <thead>
            <tr>
                <th class="number-column">No.</th>
                <th>Nama Treatment</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $item)
                <tr>
                    <td class="number-column">{{ $item['no'] }}</td>
                    <td>{{ $item['nama'] }}</td>
                </tr>
            @empty
                <tr class="empty-row">
                    <td colspan="2">Tidak ada treatment tidak laku pada periode terpilih.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
