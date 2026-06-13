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
            margin: 10mm 11mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #111111;
            font-family: "Times New Roman", Times, serif;
            font-size: 9.5px;
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
            height: 74px;
            text-align: center;
        }

        .logo-cell img {
            display: inline-block;
            width: 170px;
            max-height: 66px;
        }

        .company-cell {
            width: 69%;
            text-align: center;
        }

        .company-name {
            margin: 0;
            font-size: 19px;
            font-weight: bold;
            line-height: 1.1;
        }

        .company-contact {
            margin-top: 4px;
            font-size: 9.5px;
        }

        .separator {
            height: 1px;
            margin: 8px 0 7px;
            border-top: 1px solid #8b8b8b;
        }

        .period {
            margin-bottom: 4px;
            font-size: 10.5px;
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

        .report-table tr {
            page-break-inside: avoid;
        }

        .report-table th,
        .report-table td {
            border: 0.8px solid #333333;
            padding: 3px 4px;
            vertical-align: top;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        .report-table th {
            background: #f3f3f3;
            font-size: 9.5px;
            font-weight: bold;
            text-align: left;
            vertical-align: middle;
        }

        .report-table td {
            font-size: 9.5px;
            line-height: 1.18;
        }

        .number-column {
            width: 5%;
            text-align: center;
        }

        .name-column {
            width: 21%;
        }

        .rm-column {
            width: 16%;
        }

        .treatment-column {
            width: 29%;
        }

        .date-column {
            width: 13%;
            text-align: center;
        }

        .invoice-column {
            width: 16%;
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
            </td>
        </tr>
    </table>

    <div class="separator"></div>

    <div class="period">TANGGAL : {{ $report['period_label'] }}</div>

    <table class="report-table">
        <thead>
            <tr>
                <th class="number-column">No.</th>
                <th class="name-column">Nama</th>
                <th class="rm-column">No RM</th>
                <th class="treatment-column">Treatment Terakhir</th>
                <th class="date-column">Tanggal Terakhir</th>
                <th class="invoice-column">Faktur</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $item)
                <tr>
                    <td class="number-column">{{ $item['no'] }}</td>
                    <td class="name-column">{{ $item['nama_pasien'] }}</td>
                    <td class="rm-column">{{ $item['no_rm'] }}</td>
                    <td class="treatment-column">{{ $item['treatment_terakhir'] }}</td>
                    <td class="date-column">{{ $item['tanggal_terakhir'] }}</td>
                    <td class="invoice-column">{{ $item['faktur'] }}</td>
                </tr>
            @empty
                <tr class="empty-row">
                    <td colspan="6">
                        Tidak ada pasien dengan transaksi treatment terakhir pada periode dan cabang yang dipilih.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
