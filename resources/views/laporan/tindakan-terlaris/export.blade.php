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
        $numeric = (float) $value;

        return floor($numeric) === $numeric
            ? number_format($numeric, 0, ',', '.')
            : rtrim(rtrim(number_format($numeric, 4, ',', '.'), '0'), ',');
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
            margin: 10mm;
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
            height: 78px;
            text-align: center;
        }

        .logo-cell img {
            display: inline-block;
            width: 205px;
            max-height: 72px;
        }

        .company-cell {
            width: 69%;
            text-align: center;
        }

        .company-name {
            margin: 0;
            font-size: 23px;
            font-weight: bold;
            line-height: 1.1;
        }

        .company-contact {
            margin-top: 4px;
            font-size: 12px;
        }

        .branch-name {
            margin-top: 3px;
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
        }

        .filter-note {
            margin-bottom: 5px;
            color: #444444;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8px;
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
            font-weight: normal;
            text-align: left;
        }

        .report-table td {
            font-size: 11px;
            line-height: 1.15;
        }

        .treatment-cell {
            word-break: break-word;
        }

        .number-cell,
        .money-cell {
            white-space: nowrap;
        }

        .total-row td {
            background: #eff6e8;
            font-weight: bold;
        }

        .total-label {
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

    @if (($report['jenis_transaksi_label'] ?? '') !== 'Semua jenis transaksi')
        <div class="filter-note">Jenis transaksi: {{ $report['jenis_transaksi_label'] }}</div>
    @endif

    <table class="report-table">
        <colgroup>
            <col style="width: 5%">
            <col style="width: 55%">
            <col style="width: 15%">
            <col style="width: 25%">
        </colgroup>
        <thead>
            <tr>
                <th>No.</th>
                <th>Tindakan</th>
                <th>Jumlah</th>
                <th>Total Harga</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $item)
                <tr>
                    <td>{{ $item['no'] }}</td>
                    <td class="treatment-cell">{{ $item['tindakan'] }}</td>
                    <td class="number-cell">{{ $quantity($item['jumlah']) }}</td>
                    <td class="money-cell">{{ $money($item['total_harga']) }}</td>
                </tr>
            @empty
                <tr class="empty-row">
                    <td colspan="4">Tidak ada data tindakan pada periode dan filter yang dipilih.</td>
                </tr>
            @endforelse

            <tr class="total-row">
                <td colspan="2" class="total-label">TOTAL</td>
                <td class="number-cell">{{ $quantity($report['total_jumlah']) }}</td>
                <td class="money-cell">{{ $money($report['total_harga']) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
