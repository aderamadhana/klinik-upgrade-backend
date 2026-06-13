@php
    $logoBase64 = null;
    $logoPath = public_path('logo.png');

    if (is_file($logoPath)) {
        $extension = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mime = $extension === 'jpg' || $extension === 'jpeg' ? 'image/jpeg' : 'image/png';
        $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoPath));
    }

    $number = static function ($value): string {
        $numeric = (float) $value;
        $decimals = floor($numeric) === $numeric ? 0 : 2;

        return number_format($numeric, $decimals, ',', '.');
    };

    $money = static fn ($value): string => 'Rp ' . number_format((float) $value, 0, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $report['title'] }}</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 7mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #111111;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8px;
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
            width: 28%;
            height: 66px;
            text-align: center;
        }

        .logo-cell img {
            display: inline-block;
            width: 190px;
            max-height: 62px;
            object-fit: contain;
        }

        .company-cell {
            width: 72%;
            text-align: center;
        }

        .company-name {
            margin: 0;
            font-family: "Times New Roman", Times, serif;
            font-size: 23px;
            font-weight: bold;
            line-height: 1.1;
        }

        .company-contact {
            margin-top: 4px;
            font-family: "Times New Roman", Times, serif;
            font-size: 12px;
        }

        .branch-name {
            margin-top: 3px;
            font-size: 8px;
            font-weight: bold;
        }

        .separator {
            height: 1px;
            margin: 7px 0;
            border-top: 1px solid #8b8b8b;
        }

        .period {
            margin-bottom: 4px;
            font-family: "Times New Roman", Times, serif;
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

        .report-table th,
        .report-table td {
            border: 0.7px solid #3f3f3f;
            padding: 3px;
            vertical-align: middle;
        }

        .report-table th {
            background: #d9dde3;
            text-align: center;
            font-size: 6.8px;
            font-weight: bold;
            line-height: 1.1;
        }

        .report-table td {
            font-size: 7.2px;
            line-height: 1.15;
        }

        .center {
            text-align: center;
        }

        .right {
            text-align: right;
            white-space: nowrap;
        }

        .name-cell {
            word-break: break-word;
        }

        .total-row td {
            background: #eef4e6;
            font-weight: bold;
        }

        .empty-row td {
            height: 28px;
            color: #666666;
            text-align: center;
        }

        .footer-note {
            margin-top: 5px;
            color: #555555;
            font-size: 6.8px;
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
    </div>

    <table class="report-table">
        <colgroup>
            <col style="width: 4%">
            <col style="width: 12%">
            <col style="width: 23%">
            <col style="width: 11%">
            <col style="width: 10%">
            <col style="width: 7%">
            <col style="width: 7%">
            <col style="width: 8%">
            <col style="width: 7%">
            <col style="width: 9%">
            <col style="width: 10%">
        </colgroup>
        <thead>
            <tr>
                <th>No.</th>
                <th>Kategori</th>
                <th>Nama Produk</th>
                <th>Kode Accurate</th>
                <th>Harga</th>
                <th>Stok<br>Masuk</th>
                <th>Stok Keluar<br>Biasa</th>
                <th>Stok Keluar<br>Premiere</th>
                <th>Stok Keluar<br>Total</th>
                <th>Akumulasi<br>Diskon</th>
                <th>Harga Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $item)
                <tr>
                    <td class="center">{{ $item['no'] }}</td>
                    <td>{{ $item['kategori_produk'] }}</td>
                    <td class="name-cell">{{ $item['nama_produk'] }}</td>
                    <td class="center">{{ $item['kode_accurate'] }}</td>
                    <td class="right">{{ $money($item['harga']) }}</td>
                    <td class="center">{{ $number($item['stok_masuk']) }}</td>
                    <td class="center">{{ $number($item['stok_keluar_biasa']) }}</td>
                    <td class="center">{{ $number($item['stok_keluar_premiere']) }}</td>
                    <td class="center">{{ $number($item['stok_keluar_total']) }}</td>
                    <td class="right">{{ $money($item['akumulasi_diskon']) }}</td>
                    <td class="right">{{ $money($item['harga_total']) }}</td>
                </tr>
            @empty
                <tr class="empty-row">
                    <td colspan="11">Tidak ada data obat/produk pada periode dan filter yang dipilih.</td>
                </tr>
            @endforelse

            <tr class="total-row">
                <td colspan="5" class="right">TOTAL</td>
                <td class="center">{{ $number($report['totals']['stok_masuk']) }}</td>
                <td class="center">{{ $number($report['totals']['stok_keluar_biasa']) }}</td>
                <td class="center">{{ $number($report['totals']['stok_keluar_premiere']) }}</td>
                <td class="center">{{ $number($report['totals']['stok_keluar_total']) }}</td>
                <td class="right">{{ $money($report['totals']['akumulasi_diskon']) }}</td>
                <td class="right">{{ $money($report['totals']['harga_total']) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="footer-note">
        Stok masuk dihitung dari mutasi stok masuk selain stok awal. Stok keluar dan nilai penjualan dihitung dari item invoice produk yang telah lunas.
    </div>
</body>
</html>
