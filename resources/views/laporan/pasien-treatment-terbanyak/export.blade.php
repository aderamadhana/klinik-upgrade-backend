@php
    $logoBase64 = null;
    $logoPath = public_path('logo.png');

    if (is_file($logoPath)) {
        $extension = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mime = in_array($extension, ['jpg', 'jpeg'], true) ? 'image/jpeg' : 'image/png';
        $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoPath));
    }

    $money = static fn ($value): string => 'Rp ' . number_format((float) $value, 0, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $report['title'] }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm 10mm 10mm 10mm;
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
            width: 30%;
            height: 80px;
            text-align: center;
        }

        .logo-cell img {
            display: inline-block;
            width: 205px;
            max-height: 75px;
            object-fit: contain;
        }

        .company-cell {
            width: 70%;
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
            font-weight: normal;
            text-align: left;
            font-size: 11px;
        }

        .report-table td {
            font-size: 11px;
            line-height: 1.15;
        }

        .center {
            text-align: center;
        }

        .right {
            text-align: right;
            white-space: nowrap;
        }

        .patient-cell {
            word-break: break-word;
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
        TANGGAL : {{ $report['period_label'] }}
        @if (($report['jenis_transaksi_label'] ?? '') !== 'Semua jenis transaksi')
            | {{ $report['jenis_transaksi_label'] }}
        @endif
        | {{ $report['peringkat_label'] }}
    </div>

    <table class="report-table">
        <colgroup>
            <col style="width: 5%">
            <col style="width: 44%">
            <col style="width: 20%">
            <col style="width: 31%">
        </colgroup>
        <thead>
            <tr>
                <th>No.</th>
                <th>Pasien</th>
                <th>Jumlah Transaksi</th>
                <th>Total Nominal</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $item)
                <tr>
                    <td>{{ $item['no'] }}</td>
                    <td class="patient-cell">{{ $item['nama_pasien'] }}</td>
                    <td>{{ number_format((int) $item['jumlah_transaksi'], 0, ',', '.') }}</td>
                    <td>{{ $money($item['total_nominal']) }}</td>
                </tr>
            @empty
                <tr class="empty-row">
                    <td colspan="4">Tidak ada data pasien treatment pada periode dan filter yang dipilih.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
