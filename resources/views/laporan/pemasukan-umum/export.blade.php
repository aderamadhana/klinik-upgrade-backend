@php
    $logoData = null;
    $logoPath = public_path('logo.png');

    if (is_file($logoPath)) {
        $extension = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mime = $extension === 'jpg' || $extension === 'jpeg' ? 'image/jpeg' : 'image/png';
        $logoData = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoPath));
    }

    $money = static fn ($value) => 'Rp ' . number_format((float) $value, 0, ',', '.');

    $salesRows = static function (array $data, bool $premier = false): array {
        $suffix = $premier ? ' PREMIER LOUNGE' : '';

        return [
            ['label' => 'PENJUALAN PRODUK' . $suffix, 'value' => $data['penjualan_produk'], 'bold' => false],
            ['label' => 'DISC PRODUK' . $suffix, 'value' => $data['diskon_produk'], 'bold' => false],
            ['label' => 'TOTAL PENJUALAN PRODUK' . $suffix, 'value' => $data['total_penjualan_produk'], 'bold' => true],
            ['label' => 'PENJUALAN TREATMENT' . $suffix, 'value' => $data['penjualan_treatment'], 'bold' => false],
            ['label' => 'DISC TREATMENT' . $suffix, 'value' => $data['diskon_treatment'], 'bold' => false],
            ['label' => 'TOTAL PENJUALAN TREATMENT' . $suffix, 'value' => $data['total_penjualan_treatment'], 'bold' => true],
            ['label' => 'TOTAL PENJUALAN' . $suffix, 'value' => $data['total_penjualan'], 'bold' => true],
        ];
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
            margin: 11mm 13mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #111111;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9px;
            line-height: 1.25;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-table td {
            border: 0;
            vertical-align: middle;
        }

        .logo-cell {
            width: 29%;
            text-align: left;
        }

        .logo {
            width: 155px;
            max-height: 62px;
            object-fit: contain;
        }

        .company-cell {
            width: 71%;
            text-align: right;
        }

        .company-name {
            margin: 0;
            font-family: "Times New Roman", serif;
            font-size: 19px;
            font-weight: bold;
            line-height: 1.15;
        }

        .company-contact {
            margin-top: 2px;
            font-family: "Times New Roman", serif;
            font-size: 9px;
        }

        .divider {
            margin: 16px 0 8px;
            border-top: 1px solid #8f8f8f;
        }

        .report-title {
            margin: 0 0 1px;
            font-size: 11px;
            font-weight: bold;
        }

        .report-meta {
            margin: 0 0 6px;
            font-size: 8.5px;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .report-table td {
            padding: 2px 4px;
            border: 1px solid #333333;
            vertical-align: middle;
        }

        .report-table .label {
            width: 72%;
        }

        .report-table .value {
            width: 28%;
            text-align: right;
            white-space: nowrap;
        }

        .report-table .total-row td {
            font-weight: bold;
        }

        .spacer-row td {
            height: 8px;
            padding: 0;
            border: 0;
        }

        .section-title td {
            padding-top: 3px;
            padding-bottom: 3px;
            background: #e9edf3;
            font-weight: bold;
        }

        .footer-note {
            margin-top: 8px;
            color: #555555;
            font-size: 7.5px;
        }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td class="logo-cell">
                @if ($logoData)
                    <img src="{{ $logoData }}" class="logo" alt="MS Glow Aesthetic">
                @endif
            </td>
            <td class="company-cell">
                <div class="company-name">PT. KOSMETIKA KLINIK INDONESIA</div>
                <div class="company-contact">
                    Email : admin@msglowclinic.id | Website : www.msglowclinic.id
                </div>
            </td>
        </tr>
    </table>

    <div class="divider"></div>

    <div class="report-title">{{ $report['title'] }}</div>
    <div class="report-meta">
        TANGGAL : {{ $report['period_label'] }}
        | {{ $report['jenis_pemasukan_label'] }}
        | {{ $report['branch_label'] }}
    </div>

    <table class="report-table">
        <tbody>
            @foreach ($salesRows($report['regular']) as $row)
                <tr class="{{ $row['bold'] ? 'total-row' : '' }}">
                    <td class="label">{{ $row['label'] }}</td>
                    <td class="value">{{ $money($row['value']) }}</td>
                </tr>
            @endforeach

            <tr class="spacer-row"><td colspan="2"></td></tr>

            @foreach ($salesRows($report['premier'], true) as $row)
                <tr class="{{ $row['bold'] ? 'total-row' : '' }}">
                    <td class="label">{{ $row['label'] }}</td>
                    <td class="value">{{ $money($row['value']) }}</td>
                </tr>
            @endforeach

            <tr class="spacer-row"><td colspan="2"></td></tr>

            <tr class="total-row">
                <td class="label">TOTAL DISKON SUBTOTAL</td>
                <td class="value">{{ $money($report['total_diskon_subtotal']) }}</td>
            </tr>
            <tr class="total-row">
                <td class="label">TOTAL PENDAPATAN ALL</td>
                <td class="value">{{ $money($report['total_pendapatan_all']) }}</td>
            </tr>

            <tr class="spacer-row"><td colspan="2"></td></tr>

            <tr class="section-title">
                <td colspan="2">RINCIAN PEMBAYARAN</td>
            </tr>

            @forelse ($report['payment_methods'] as $method)
                <tr>
                    <td class="label">{{ $method['nama'] }}</td>
                    <td class="value">{{ $money($method['nominal']) }}</td>
                </tr>
            @empty
                <tr>
                    <td class="label">BELUM ADA METODE PEMBAYARAN</td>
                    <td class="value">{{ $money(0) }}</td>
                </tr>
            @endforelse

            <tr class="total-row">
                <td class="label">TOTAL CASH</td>
                <td class="value">{{ $money($report['total_cash']) }}</td>
            </tr>
            <tr class="total-row">
                <td class="label">TOTAL NON CASH</td>
                <td class="value">{{ $money($report['total_non_cash']) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="footer-note">
        Data berdasarkan invoice berstatus lunas. Treatment mencakup konsultasi,
        treatment reguler, dan treatment deposit. Nominal metode pembayaran memakai
        nominal yang dialokasikan ke invoice.
    </div>
</body>
</html>
