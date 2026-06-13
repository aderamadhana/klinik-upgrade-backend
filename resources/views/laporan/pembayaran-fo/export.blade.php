@php
    $logoBase64 = null;
    $logoPath = public_path('logo.png');

    if (is_file($logoPath)) {
        $extension = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mime = in_array($extension, ['jpg', 'jpeg'], true) ? 'image/jpeg' : 'image/png';
        $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoPath));
    }

    $rupiah = static fn ($value) => 'Rp ' . number_format((float) $value, 0, ',', '.');
@endphp

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $report['title'] }}</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 9mm 10mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #111111;
            font-family: "Times New Roman", Times, serif;
            font-size: 8.3px;
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
            height: 70px;
            text-align: center;
        }

        .logo-cell img {
            display: inline-block;
            width: 180px;
            max-height: 64px;
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

        .meta {
            margin-bottom: 2px;
            font-size: 9.5px;
            font-weight: bold;
        }

        .report-table,
        .payment-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .report-table thead,
        .payment-table thead {
            display: table-header-group;
        }

        .report-table tr,
        .payment-table tr {
            page-break-inside: avoid;
        }

        .report-table th,
        .report-table td,
        .payment-table th,
        .payment-table td {
            border: 0.8px solid #333333;
            padding: 3px 3px;
            vertical-align: top;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        .report-table th,
        .payment-table th {
            background: #f3f3f3;
            font-weight: bold;
            text-align: left;
            vertical-align: middle;
        }

        .report-table td,
        .payment-table td {
            line-height: 1.15;
        }

        .number {
            width: 3.5%;
            text-align: center;
        }

        .invoice {
            width: 9%;
        }

        .patient {
            width: 13%;
        }

        .money-treatment,
        .money-product {
            width: 9%;
        }

        .money-total,
        .money-discount,
        .money-paid,
        .money-change {
            width: 10%;
        }

        .transaction-type {
            width: 9.5%;
        }

        .status {
            width: 6%;
            text-align: center;
        }

        .money {
            text-align: right;
            white-space: nowrap;
        }

        .total-row td {
            background: #f4f8ef;
            font-weight: bold;
        }

        .empty-row td {
            height: 28px;
            color: #666666;
            text-align: center;
        }

        .payment-section {
            width: 48%;
            margin-top: 24px;
            page-break-inside: avoid;
        }

        .payment-table th:first-child,
        .payment-table td:first-child {
            width: 65%;
        }

        .payment-table th:last-child,
        .payment-table td:last-child {
            width: 35%;
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

    <div class="meta">KASIR : {{ $report['cashier_name'] }}</div>
    <div class="meta">TANGGAL : {{ $report['date_label'] }}</div>

    <table class="report-table">
        <thead>
            <tr>
                <th class="number">No.</th>
                <th class="invoice">Faktur</th>
                <th class="patient">Pasien</th>
                <th class="money-treatment">Treatment</th>
                <th class="money-product">Produk</th>
                <th class="money-total">Total Pembelian</th>
                <th class="money-discount">Diskon Subtotal</th>
                <th class="money-paid">Bayar</th>
                <th class="money-change">Kembalian</th>
                <th class="transaction-type">Jenis Transaksi</th>
                <th class="status">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $item)
                <tr>
                    <td class="number">{{ $item['no'] }}</td>
                    <td class="invoice">{{ $item['faktur'] }}</td>
                    <td class="patient">{{ $item['pasien'] }}</td>
                    <td class="money money-treatment">{{ $rupiah($item['treatment']) }}</td>
                    <td class="money money-product">{{ $rupiah($item['produk']) }}</td>
                    <td class="money money-total">{{ $rupiah($item['total_pembelian']) }}</td>
                    <td class="money money-discount">{{ $rupiah($item['diskon_subtotal']) }}</td>
                    <td class="money money-paid">{{ $rupiah($item['bayar']) }}</td>
                    <td class="money money-change">{{ $rupiah($item['kembalian']) }}</td>
                    <td class="transaction-type">{{ $item['jenis_transaksi'] }}</td>
                    <td class="status">{{ $item['status_label'] }}</td>
                </tr>
            @empty
                <tr class="empty-row">
                    <td colspan="11">Tidak ada data pembayaran untuk kasir dan tanggal yang dipilih.</td>
                </tr>
            @endforelse

            @if ($report['rows'] !== [])
                <tr class="total-row">
                    <td colspan="3">TOTAL</td>
                    <td class="money">{{ $rupiah($report['totals']['total_treatment']) }}</td>
                    <td class="money">{{ $rupiah($report['totals']['total_produk']) }}</td>
                    <td class="money">{{ $rupiah($report['totals']['total_pembelian']) }}</td>
                    <td class="money">{{ $rupiah($report['totals']['total_diskon_subtotal']) }}</td>
                    <td class="money">{{ $rupiah($report['totals']['total_bayar']) }}</td>
                    <td class="money">{{ $rupiah($report['totals']['total_kembalian']) }}</td>
                    <td colspan="2">
                        Lunas: {{ $report['totals']['total_lunas'] }} |
                        Belum lunas: {{ $report['totals']['total_belum_lunas'] }}
                    </td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="payment-section">
        <table class="payment-table">
            <thead>
                <tr>
                    <th>Tipe Pembayaran</th>
                    <th>Jumlah</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($report['payment_types'] as $item)
                    <tr>
                        <td>{{ $item['nama'] }}</td>
                        <td class="money">{{ $rupiah($item['jumlah']) }}</td>
                    </tr>
                @empty
                    <tr class="empty-row">
                        <td colspan="2">Tidak ada tipe pembayaran.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>
