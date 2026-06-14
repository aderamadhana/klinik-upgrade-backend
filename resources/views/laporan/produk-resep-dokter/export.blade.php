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

        .meta {
            margin-bottom: 3px;
            font-size: 11px;
        }

        table.report-table {
            width: 100%;
            margin-top: 5px;
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

        .number {
            text-align: center;
        }

        .currency {
            text-align: right;
            white-space: nowrap;
        }

        .grand-total td {
            font-weight: bold;
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

    <div class="meta">
        TANGGAL : {{ $report['period_label'] }} - Nama : {{ $report['doctor_name'] }}
        @if (($report['branch_label'] ?? '') !== 'SEMUA CABANG')
            ({{ $report['branch_label'] }})
        @endif
    </div>

    <table class="report-table">
        <thead>
            <tr>
                <th style="width: 15%;">Tanggal</th>
                <th style="width: 27%;">Nama Pasien</th>
                <th style="width: 34%;">Nama Produk</th>
                <th class="number" style="width: 9%;">Jumlah</th>
                <th class="currency" style="width: 15%;">Harga Produk</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    <td>{{ $row['tanggal'] }}</td>
                    <td>{{ $row['nama_pasien'] }}</td>
                    <td>{{ $row['nama_produk'] }}</td>
                    <td class="number">{{ number_format((float) $row['jumlah'], 0, ',', '.') }}</td>
                    <td class="currency">Rp {{ number_format((float) $row['total_harga'], 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="empty-row">
                        Tidak ada produk resep dokter pada periode yang dipilih.
                    </td>
                </tr>
            @endforelse
            <tr class="grand-total">
                <td colspan="4">GRAND TOTAL</td>
                <td class="currency">Rp {{ number_format((float) $report['totals']['grand_total'], 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
