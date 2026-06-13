@php
    $isSummary = $jenis === 'summary';
    $staffLabel = strtoupper((string) ($filters['staff_nama'] ?? 'SEMUA NURSE/BEAUTICIAN'));
    $formatMoney = static fn ($value) => number_format((float) ($value ?? 0), 0, ',', '.');
    $formatNumber = static function ($value) {
        $number = (float) ($value ?? 0);
        $decimals = floor($number) == $number ? 0 : 2;

        return number_format($number, $decimals, ',', '.');
    };
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title }}</title>

    <style>
        @page {
            size: A4 landscape;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        html {
            margin: 0;
            padding: 0;
            background: #ffffff;
        }

        body {
            margin: 0;
            padding: 8mm 10mm;
            color: #111111;
            background: #ffffff;
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 9px;
        }

        .report-page {
            width: auto;
            margin: 0;
            padding: 0;
        }

        .report-header {
            width: 100%;
            margin: 0 0 24px;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .report-header td {
            padding: 0;
            border: 0;
            vertical-align: top;
        }

        .brand-cell {
            width: 19%;
            padding-right: 14px !important;
            vertical-align: middle !important;
        }

        .brand-logo {
            display: block;
            width: 165px;
            max-width: 100%;
            max-height: 58px;
        }

        .brand-fallback {
            color: #222222;
            font-family: DejaVu Serif, Georgia, serif;
            font-size: 20px;
            font-weight: 700;
            line-height: 1.1;
            white-space: nowrap;
        }

        .title-cell {
            width: 53%;
            padding: 2px 14px 0 !important;
        }

        .report-title {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.15;
            text-transform: uppercase;
        }

        .clinic-name {
            margin-top: 3px;
            font-size: 11px;
            font-weight: 400;
            line-height: 1.2;
            text-transform: uppercase;
        }

        .meta-cell {
            width: 28%;
            padding: 1px 0 0 8px !important;
            text-align: right;
            font-size: 8.5px;
            line-height: 1.45;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        .meta-label {
            font-weight: 400;
        }

        .meta-value {
            font-weight: 700;
            white-space: normal;
        }

        .meta-period {
            white-space: nowrap;
        }

        .page-number {
            margin-top: 1px;
            color: #555555;
            font-size: 7.5px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .data-table thead {
            display: table-header-group;
        }

        .data-table tr {
            page-break-inside: avoid;
        }

        .data-table th,
        .data-table td {
            padding: 5px 5px;
            border: 1px solid #666666;
            vertical-align: middle;
        }

        .data-table th {
            color: #080808;
            background: #d8dae0;
            font-size: {{ $isSummary ? '8.3px' : '7px' }};
            font-weight: 700;
            line-height: 1.15;
            text-align: center;
            text-transform: uppercase;
        }

        .data-table td {
            background: #ffffff;
            font-size: {{ $isSummary ? '8px' : '6.8px' }};
            line-height: 1.25;
        }

        .text-left {
            text-align: left;
        }

        .text-center {
            text-align: center;
        }

        .text-right,
        .number-cell {
            text-align: right;
        }

        .nowrap,
        .number-cell {
            white-space: nowrap;
        }

        .empty-row td {
            height: 28px;
            color: #666666;
            text-align: center;
        }

        .grand-total-label,
        .grand-total-value {
            background: #edf4e5 !important;
            font-weight: 700;
        }

        .grand-total-label {
            text-align: right;
            text-transform: uppercase;
        }

        .grand-total-value {
            text-align: right;
            white-space: nowrap;
        }

        .notes {
            margin-top: 13px;
            font-size: 7.8px;
            line-height: 1.45;
        }

        .notes-title {
            margin-bottom: 4px;
            font-style: italic;
        }

        .note-row {
            display: table;
            width: 100%;
            margin-bottom: 2px;
        }

        .note-bullet,
        .note-text {
            display: table-cell;
            vertical-align: top;
        }

        .note-bullet {
            width: 13px;
            font-size: 11px;
            line-height: 10px;
        }

        .summary-name { width: 26%; }
        .summary-qty { width: 10%; }
        .summary-money { width: 21.33%; }

        .detail-date { width: 8%; }
        .detail-invoice { width: 10%; }
        .detail-patient { width: 13%; }
        .detail-treatment { width: 18%; }
        .detail-transaction { width: 14%; }
        .detail-qty { width: 5%; }
        .detail-money { width: 8%; }
    </style>
</head>
<body>
<div class="report-page">
    <table class="report-header">
        <tr>
            <td class="brand-cell">
                @if ($logoDataUri)
                    <img
                        src="{{ $logoDataUri }}"
                        alt="MS Glow Aesthetic"
                        class="brand-logo"
                    >
                @else
                    <div class="brand-fallback">MS GLOW AESTHETICS</div>
                @endif
            </td>

            <td class="title-cell">
                <div class="report-title">{{ $title }}</div>
                <div class="clinic-name">{{ $clinicName }}</div>
            </td>

            <td class="meta-cell">
                <div class="meta-period">
                    <span class="meta-label">Periode:</span>
                    <span class="meta-value">{{ $period }}</span>
                </div>
                <div>
                    <span class="meta-label">Perawat:</span>
                    <span class="meta-value">{{ $staffLabel }}</span>
                </div>
                <div class="page-number">Halaman 1 / 1</div>
            </td>
        </tr>
    </table>

    @if ($isSummary)
        <table class="data-table">
            <colgroup>
                <col class="summary-name">
                <col class="summary-qty">
                <col class="summary-money">
                <col class="summary-money">
                <col class="summary-money">
            </colgroup>
            <thead>
            <tr>
                <th>NAMA TREATMENT</th>
                <th>QTY</th>
                <th>HARGA AWAL</th>
                <th>INSENTIF (Rp)</th>
                <th>TOTAL INSENTIF</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="text-left">{{ $row['nama_item'] ?? '-' }}</td>
                    <td class="number-cell">{{ $formatNumber($row['total_qty'] ?? 0) }}</td>
                    <td class="number-cell">{{ $formatMoney($row['harga_awal'] ?? 0) }}</td>
                    <td class="number-cell">{{ $formatMoney($row['insentif_rupiah'] ?? 0) }}</td>
                    <td class="number-cell">{{ $formatMoney($row['total_insentif'] ?? 0) }}</td>
                </tr>
            @empty
                <tr class="empty-row">
                    <td colspan="5">
                        Tidak ada data insentif pada periode dan filter yang dipilih.
                    </td>
                </tr>
            @endforelse
            <tr>
                <td colspan="4" class="grand-total-label">GRAND TOTAL</td>
                <td class="grand-total-value">{{ $formatMoney($totalInsentif) }}</td>
            </tr>
            </tbody>
        </table>

        <div class="notes">
            <div class="notes-title">Catatan:</div>
            <div class="note-row">
                <div class="note-bullet">●</div>
                <div class="note-text">
                    Total Insentif = QTY x Insentif (Rp).
                </div>
            </div>
            <div class="note-row">
                <div class="note-bullet">●</div>
                <div class="note-text">
                    Data diambil dari transaksi reguler (pembayaran) dan realisasi deposit.
                </div>
            </div>
            <div class="note-row">
                <div class="note-bullet">●</div>
                <div class="note-text">
                    Insentif (Rp) menggunakan rate "Tarif Nurse" dari master treatment.
                </div>
            </div>
        </div>
    @else
        <table class="data-table">
            <colgroup>
                <col class="detail-date">
                <col class="detail-invoice">
                <col class="detail-patient">
                <col class="detail-treatment">
                <col class="detail-transaction">
                <col class="detail-qty">
                <col class="detail-money">
                <col class="detail-money">
                <col class="detail-money">
                <col class="detail-money">
            </colgroup>
            <thead>
            <tr>
                <th>TANGGAL</th>
                <th>FAKTUR</th>
                <th>NAMA PASIEN</th>
                <th>NAMA TREATMENT</th>
                <th>JENIS TRANSAKSI</th>
                <th>QTY</th>
                <th>HARGA AWAL</th>
                <th>SETELAH DISKON</th>
                <th>INSENTIF (Rp)</th>
                <th>TOTAL INSENTIF</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="text-center nowrap">{{ $row['tanggal'] ?? '-' }}</td>
                    <td class="text-center">{{ $row['no_invoice'] ?? '-' }}</td>
                    <td class="text-left">{{ $row['pasien_nama'] ?? '-' }}</td>
                    <td class="text-left">{{ $row['nama_item'] ?? '-' }}</td>
                    <td class="text-left">{{ $row['jenis_transaksi_label'] ?? '-' }}</td>
                    <td class="number-cell">{{ $formatNumber($row['qty'] ?? 0) }}</td>
                    <td class="number-cell">{{ $formatMoney($row['harga_awal'] ?? 0) }}</td>
                    <td class="number-cell">{{ $formatMoney($row['setelah_diskon'] ?? 0) }}</td>
                    <td class="number-cell">{{ $formatMoney($row['insentif_rupiah'] ?? 0) }}</td>
                    <td class="number-cell">{{ $formatMoney($row['nilai_insentif'] ?? 0) }}</td>
                </tr>
            @empty
                <tr class="empty-row">
                    <td colspan="10">
                        Tidak ada data insentif pada periode dan filter yang dipilih.
                    </td>
                </tr>
            @endforelse
            <tr>
                <td colspan="9" class="grand-total-label">GRAND TOTAL</td>
                <td class="grand-total-value">{{ $formatMoney($totalInsentif) }}</td>
            </tr>
            </tbody>
        </table>

        <div class="notes">
            <div class="notes-title">
                Catatan: Total Insentif = QTY x Insentif (Rp). Insentif (Rp) menggunakan rate "Tarif Nurse".
            </div>
        </div>
    @endif
</div>
</body>
</html>
