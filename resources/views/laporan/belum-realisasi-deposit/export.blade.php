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
            margin: 11mm 11mm 12mm;
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
            width: 31%;
            height: 74px;
            text-align: center;
        }

        .logo-cell img {
            display: inline-block;
            width: 170px;
            max-height: 66px;
            object-fit: contain;
        }

        .company-cell {
            width: 69%;
            text-align: center;
        }

        .company-name {
            margin: 0;
            font-size: 21px;
            font-weight: bold;
            line-height: 1.2;
        }

        .company-contact {
            margin-top: 3px;
            font-size: 9.5px;
        }

        .divider {
            margin: 9px 0 7px;
            border-top: 1px solid #777777;
        }

        .report-title {
            margin: 0 0 3px;
            font-size: 20px;
            font-weight: bold;
            text-align: center;
        }

        .report-meta {
            margin-bottom: 7px;
            text-align: center;
            font-size: 10px;
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
            border: 1px solid #222222;
            padding: 3px 4px;
            line-height: 1.15;
            vertical-align: top;
            word-break: break-word;
        }

        .report-table th {
            font-weight: bold;
            text-align: left;
            vertical-align: middle;
        }

        .currency {
            text-align: right;
            white-space: nowrap;
        }

        .subtext {
            margin-top: 2px;
            color: #444444;
            font-size: 8.5px;
            line-height: 1.15;
        }

        .expired {
            font-weight: bold;
        }

        .empty-row {
            height: 34px;
            text-align: center;
            vertical-align: middle !important;
        }

        .total-row td {
            font-weight: bold;
            vertical-align: middle;
        }

        .footer-note {
            margin-top: 6px;
            color: #444444;
            font-size: 8.5px;
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

    <div class="report-title">{{ $report['title'] }}</div>
    <div class="report-meta">
        CABANG : {{ $report['branch_label'] }}
        &nbsp;|&nbsp;
        DICETAK : {{ $report['generated_at'] }}
    </div>

    <table class="report-table">
        <thead>
            <tr>
                <th style="width: 14%;">No. Faktur</th>
                <th style="width: 20%;">Nama Pasien</th>
                <th style="width: 21%;">Nama Treatment</th>
                <th style="width: 20%;">Catatan</th>
                <th style="width: 11%;">Tanggal Exp</th>
                <th class="currency" style="width: 14%;">Harga Treatment</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    <td>{{ $row['no_invoice'] }}</td>
                    <td>
                        {{ $row['nama_pasien'] }}
                        <div class="subtext">{{ $row['no_rm'] }}</div>
                    </td>
                    <td>
                        {{ $row['nama_treatment'] }}
                        <div class="subtext">
                            Sisa {{ number_format((float) $row['qty_sisa'], 2, ',', '.') }} sesi
                            × Rp {{ number_format((float) $row['harga_satuan'], 0, ',', '.') }}
                        </div>
                    </td>
                    <td>{{ $row['catatan'] }}</td>
                    <td class="{{ $row['is_expired'] ? 'expired' : '' }}">
                        {{ $row['expired_at'] ?: '-' }}
                        <div class="subtext">{{ $row['status_expired'] }}</div>
                    </td>
                    <td class="currency">
                        Rp {{ number_format((float) $row['nilai_sisa'], 0, ',', '.') }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="empty-row">
                        Tidak ada deposit yang belum direalisasi pada cabang ini.
                    </td>
                </tr>
            @endforelse

            <tr class="total-row">
                <td colspan="4">
                    TOTAL BELUM REALISASI
                    ({{ number_format((float) $report['totals']['total_qty_sisa'], 2, ',', '.') }} sesi)
                </td>
                <td>{{ number_format((int) $report['totals']['total_deposit'], 0, ',', '.') }} deposit</td>
                <td class="currency">
                    Rp {{ number_format((float) $report['totals']['total_nilai_sisa'], 0, ',', '.') }}
                </td>
            </tr>
        </tbody>
    </table>

    <div class="footer-note">
        Deposit kedaluwarsa tetap ditampilkan selama masih mempunyai sisa klaim.
        Nilai pada kolom Harga Treatment adalah nilai deposit yang masih belum direalisasi.
    </div>
</body>
</html>
