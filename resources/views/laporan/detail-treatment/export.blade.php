@php
    $logoBase64 = null;
    $logoPath = public_path('logo.png');

    if (is_file($logoPath)) {
        $extension = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mime = in_array($extension, ['jpg', 'jpeg'], true) ? 'image/jpeg' : 'image/png';
        $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoPath));
    }

    $money = static fn ($value): string => 'Rp ' . number_format((float) $value, 0, ',', '.');
    $quantity = static function ($value): string {
        $number = (float) $value;

        if (floor($number) === $number) {
            return number_format($number, 0, ',', '.');
        }

        return rtrim(rtrim(number_format($number, 4, ',', '.'), '0'), ',');
    };
@endphp

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $report['title'] }}</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 10mm 11mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #111111;
            font-family: "Times New Roman", Times, serif;
            font-size: 10px;
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
            width: 30%;
            height: 74px;
            text-align: center;
        }

        .logo-cell img {
            display: inline-block;
            width: 175px;
            max-height: 66px;
        }

        .company-cell {
            width: 70%;
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
            margin: 8px 0 7px;
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

        .report-table tr {
            page-break-inside: avoid;
        }

        .report-table th,
        .report-table td {
            border: 0.8px solid #333333;
            padding: 3px 4px;
            vertical-align: middle;
        }

        .report-table th {
            background: #f3f3f3;
            font-size: 10px;
            font-weight: bold;
            text-align: left;
        }

        .report-table td {
            font-size: 10px;
            line-height: 1.15;
        }

        .date-column {
            width: 10%;
            text-align: center;
        }

        .invoice-column {
            width: 14%;
        }

        .patient-column {
            width: 27%;
        }

        .treatment-column {
            width: 30%;
        }

        .qty-column {
            width: 7%;
            text-align: right;
        }

        .amount-column {
            width: 12%;
            text-align: right;
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

    <div class="period">TANGGAL : {{ $report['period_label'] }}</div>

    <table class="report-table">
        <thead>
            <tr>
                <th class="date-column">Tanggal</th>
                <th class="invoice-column">No Faktur</th>
                <th class="patient-column">Nama Pasien</th>
                <th class="treatment-column">Nama Treatment</th>
                <th class="qty-column">Jumlah</th>
                <th class="amount-column">Total Harga Treatment</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $item)
                <tr>
                    <td class="date-column">{{ $item['tanggal'] }}</td>
                    <td class="invoice-column">{{ $item['no_invoice'] }}</td>
                    <td class="patient-column">
                        {{ $item['no_rm'] }} - {{ $item['nama_pasien'] }}
                    </td>
                    <td class="treatment-column">{{ $item['nama_treatment'] }}</td>
                    <td class="qty-column">{{ $quantity($item['qty']) }}</td>
                    <td class="amount-column">{{ $money($item['total_harga']) }}</td>
                </tr>
            @empty
                <tr class="empty-row">
                    <td colspan="6">Tidak ada detail treatment pada periode dan cabang yang dipilih.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
