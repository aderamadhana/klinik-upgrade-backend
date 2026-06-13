<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Detail Pasien</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm 10mm 12mm 10mm;
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
            padding: 0 14px 0 0;
            text-align: center;
        }

        .logo {
            width: 165px;
            max-height: 72px;
            object-fit: contain;
        }

        .company-cell {
            width: 69%;
            padding: 2px 0 0 8px;
            text-align: center;
        }

        .company-name {
            margin: 0;
            font-size: 21px;
            font-weight: bold;
            line-height: 1.15;
        }

        .company-contact {
            margin-top: 3px;
            font-size: 10px;
        }

        .header-line {
            margin: 16px 0 12px;
            border-top: 1px solid #777777;
        }

        .period {
            margin-bottom: 5px;
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
            border: 1px solid #333333;
            padding: 2px 4px;
            line-height: 1.2;
        }

        .report-table th {
            background: #eeeeee;
            font-weight: normal;
            text-align: left;
        }

        .col-no {
            width: 5%;
            text-align: center;
        }

        .col-invoice {
            width: 20%;
        }

        .col-patient {
            width: 41%;
        }

        .col-money {
            width: 17%;
            text-align: right;
            white-space: nowrap;
        }

        .empty-row td {
            padding: 12px 6px;
            color: #666666;
            text-align: center;
        }

        .total-row td {
            background: #eef5e6;
            font-weight: bold;
        }

        .total-label {
            text-align: right;
        }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td class="logo-cell">
                @if ($logoDataUri)
                    <img
                        src="{{ $logoDataUri }}"
                        class="logo"
                        alt="MS Glow Aesthetics"
                    >
                @endif
            </td>
            <td class="company-cell">
                <div class="company-name">{{ $companyName }}</div>
                <div class="company-contact">{{ $companyContact }}</div>
            </td>
        </tr>
    </table>

    <div class="header-line"></div>

    <div class="period">
        TANGGAL : {{ $periodLabel }}
        @if (!empty($filters['toko_nama']))
            - Cabang : {{ strtoupper($filters['toko_nama']) }}
        @endif
    </div>

    <table class="report-table">
        <thead>
            <tr>
                <th class="col-no">No.</th>
                <th class="col-invoice">Faktur</th>
                <th class="col-patient">Pasien</th>
                <th class="col-money">Treatment</th>
                <th class="col-money">Produk</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="col-no">{{ $row['no'] }}</td>
                    <td class="col-invoice">{{ $row['no_invoice'] }}</td>
                    <td class="col-patient">{{ $row['nama_pasien'] }}</td>
                    <td class="col-money">
                        Rp {{ number_format($row['total_treatment'], 0, ',', '.') }}
                    </td>
                    <td class="col-money">
                        Rp {{ number_format($row['total_produk'], 0, ',', '.') }}
                    </td>
                </tr>
            @empty
                <tr class="empty-row">
                    <td colspan="5">
                        Tidak ada data transaksi pada periode yang dipilih.
                    </td>
                </tr>
            @endforelse

            <tr class="total-row">
                <td colspan="3" class="total-label">GRAND TOTAL</td>
                <td class="col-money">
                    Rp {{ number_format($totalTreatment, 0, ',', '.') }}
                </td>
                <td class="col-money">
                    Rp {{ number_format($totalProduk, 0, ',', '.') }}
                </td>
            </tr>
        </tbody>
    </table>
</body>
</html>
