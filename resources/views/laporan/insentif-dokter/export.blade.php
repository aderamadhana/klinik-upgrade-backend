@php
    $isSummary = $jenis === 'summary';
    $itemLabel = $kategori === 'treatment' ? 'TREATMENT' : 'PRODUK';
    $doctorLabel = strtoupper((string) ($filters['dokter_nama'] ?? '-'));

    if ((int) ($filters['is_dokter_spesialis'] ?? 0) === 1) {
        $doctorLabel .= ' (Spesialis)';
    }

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

        html,
        body {
            margin: 0;
            padding: 0;
            color: #111111;
            background: #ffffff;
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            font-size: 9px;
        }

        body {
            margin: 0;
            padding: 8mm 10mm;
        }

        .report-page {
            width: 100%;
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
            width: 18%;
            padding-right: 18px !important;
            vertical-align: middle !important;
        }

        .brand-logo {
            display: block;
            width: 190px;
            max-width: 100%;
            max-height: 62px;
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
            width: 56%;
            padding: 2px 18px 0 !important;
            border-left: 2px solid #dc8b19 !important;
        }

        .report-title {
            margin: 0;
            font-size: 17px;
            font-weight: 700;
            line-height: 1.15;
            text-transform: uppercase;
        }

        .clinic-name {
            margin-top: 3px;
            font-size: 12px;
            font-weight: 400;
            line-height: 1.2;
            text-transform: uppercase;
        }

        .meta-cell {
            width: 26%;
            padding-top: 1px !important;
            text-align: right;
            font-size: 10px;
            line-height: 1.45;
        }

        .meta-label {
            font-weight: 400;
        }

        .meta-value {
            font-weight: 700;
        }

        .page-number {
            margin-top: 1px;
            color: #555555;
            font-size: 8px;
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
            font-size: {{ $isSummary ? '8.5px' : '6.8px' }};
            font-weight: 700;
            line-height: 1.15;
            text-align: center;
            text-transform: uppercase;
        }

        .data-table td {
            background: #ffffff;
            font-size: {{ $isSummary ? '8px' : '6.7px' }};
            line-height: 1.25;
        }

        .text-left {
            text-align: left;
        }

        .text-center {
            text-align: center;
        }

        .text-right,
        .number-cell,
        .percentage-cell {
            text-align: right;
        }

        .nowrap,
        .number-cell,
        .percentage-cell {
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
            margin-top: 14px;
            font-size: 8px;
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

        .note-text strong {
            font-weight: 700;
        }

        .summary-name { width: 15%; }
        .summary-qty { width: 5.5%; }
        .summary-money { width: 11.5%; }
        .summary-percent { width: 10.5%; }

        .detail-date { width: 5.5%; }
        .detail-invoice { width: 8%; }
        .detail-branch { width: 6.5%; }
        .detail-rm { width: 5.5%; }
        .detail-patient { width: 8%; }
        .detail-transaction { width: 7%; }
        .detail-item { width: 10%; }
        .detail-qty { width: 3.5%; }
        .detail-money { width: 6.5%; }
        .detail-percent { width: 5%; }
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
                <div>
                    <span class="meta-label">Periode:</span>
                    <span class="meta-value">{{ $period }}</span>
                </div>
                <div>
                    <span class="meta-label">Dokter:</span>
                    <span class="meta-value">{{ $doctorLabel }}</span>
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
                <col class="summary-money">
                <col class="summary-percent">
                <col class="summary-money">
                <col class="summary-money">
            </colgroup>
            <thead>
            <tr>
                <th>NAMA {{ $itemLabel }}</th>
                <th>QTY</th>
                <th>HARGA AWAL</th>
                <th>SETELAH DISKON</th>
                <th>PPN {{ $ppnRate }}%</th>
                <th>DASAR FEE</th>
                <th>INSENTIF (%)</th>
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
                    <td class="number-cell">{{ $formatMoney($row['setelah_diskon'] ?? 0) }}</td>
                    <td class="number-cell">{{ $formatMoney($row['ppn_11'] ?? 0) }}</td>
                    <td class="number-cell">{{ $formatMoney($row['dasar_fee'] ?? 0) }}</td>
                    <td class="percentage-cell">{{ $formatNumber($row['insentif_persen'] ?? 0) }}</td>
                    <td class="number-cell">{{ $formatMoney($row['insentif_rupiah'] ?? 0) }}</td>
                    <td class="number-cell">{{ $formatMoney($row['total_insentif'] ?? 0) }}</td>
                </tr>
            @empty
                <tr class="empty-row">
                    <td colspan="9">Tidak ada data insentif pada periode dan filter yang dipilih.</td>
                </tr>
            @endforelse

            <tr>
                <td colspan="8" class="grand-total-label">GRAND TOTAL</td>
                <td class="grand-total-value">{{ $formatMoney($totalInsentif) }}</td>
            </tr>
            </tbody>
        </table>
    @else
        <table class="data-table">
            <colgroup>
                <col class="detail-date">
                <col class="detail-invoice">
                <col class="detail-branch">
                <col class="detail-rm">
                <col class="detail-patient">
                <col class="detail-transaction">
                <col class="detail-item">
                <col class="detail-qty">
                <col class="detail-money">
                <col class="detail-money">
                <col class="detail-money">
                <col class="detail-money">
                <col class="detail-percent">
                <col class="detail-money">
                <col class="detail-money">
            </colgroup>
            <thead>
            <tr>
                <th>TANGGAL</th>
                <th>NO INVOICE</th>
                <th>CABANG</th>
                <th>NO RM</th>
                <th>PASIEN</th>
                <th>JENIS TRANSAKSI</th>
                <th>NAMA {{ $itemLabel }}</th>
                <th>QTY</th>
                <th>HARGA AWAL</th>
                <th>SETELAH DISKON</th>
                <th>PPN {{ $ppnRate }}%</th>
                <th>DASAR FEE</th>
                <th>INSENTIF (%)</th>
                <th>INSENTIF (Rp)</th>
                <th>TOTAL INSENTIF</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="text-center nowrap">{{ $row['tanggal'] ?? '-' }}</td>
                    <td class="text-left nowrap">{{ $row['no_invoice'] ?? '-' }}</td>
                    <td class="text-left">{{ $row['toko_nama'] ?? '-' }}</td>
                    <td class="text-left nowrap">{{ $row['no_rm'] ?? '-' }}</td>
                    <td class="text-left">{{ $row['pasien_nama'] ?? '-' }}</td>
                    <td class="text-left">{{ $row['jenis_transaksi_label'] ?? 'Umum' }}</td>
                    <td class="text-left">{{ $row['nama_item'] ?? '-' }}</td>
                    <td class="number-cell">{{ $formatNumber($row['qty'] ?? 0) }}</td>
                    <td class="number-cell">{{ $formatMoney($row['harga_awal'] ?? 0) }}</td>
                    <td class="number-cell">{{ $formatMoney($row['setelah_diskon'] ?? 0) }}</td>
                    <td class="number-cell">{{ $formatMoney($row['ppn_11'] ?? 0) }}</td>
                    <td class="number-cell">{{ $formatMoney($row['dasar_fee'] ?? 0) }}</td>
                    <td class="percentage-cell">{{ $formatNumber($row['insentif_persen'] ?? 0) }}</td>
                    <td class="number-cell">{{ $formatMoney($row['insentif_rupiah'] ?? 0) }}</td>
                    <td class="number-cell">{{ $formatMoney($row['nilai_insentif'] ?? 0) }}</td>
                </tr>
            @empty
                <tr class="empty-row">
                    <td colspan="15">Tidak ada data insentif pada periode dan filter yang dipilih.</td>
                </tr>
            @endforelse

            <tr>
                <td colspan="14" class="grand-total-label">GRAND TOTAL</td>
                <td class="grand-total-value">{{ $formatMoney($totalInsentif) }}</td>
            </tr>
            </tbody>
        </table>
    @endif

    <div class="notes">
        <div class="notes-title">Catatan:</div>

        <div class="note-row">
            <div class="note-bullet">●</div>
            <div class="note-text">
                <strong>Dasar Fee</strong> dihitung dari <strong>Setelah Diskon – PPN {{ $ppnRate }}%</strong>
                (hanya berlaku untuk transaksi reguler dengan skema Persen).
            </div>
        </div>

        <div class="note-row">
            <div class="note-bullet">●</div>
            <div class="note-text">
                Insentif untuk transaksi <strong>endorse</strong> atau transaksi reguler dengan skema
                <strong>Flat</strong> dihitung berdasarkan <strong>Insentif (Rp)</strong> satuan dikali Qty.
            </div>
        </div>

        <div class="note-row">
            <div class="note-bullet">●</div>
            <div class="note-text">
                Jika kolom <strong>Insentif (%)</strong> berisi angka, maka perhitungan menggunakan persentase dari Dasar Fee.
            </div>
        </div>

        <div class="note-row">
            <div class="note-bullet">●</div>
            <div class="note-text">
                Jika kolom <strong>Insentif (Rp)</strong> berisi angka, maka perhitungan menggunakan nominal flat satuan.
            </div>
        </div>
    </div>
</div>
</body>
</html>
