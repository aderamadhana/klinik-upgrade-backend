<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Insentif Apoteker</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 15mm 14mm 13mm 14mm;
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

        .report-group {
            width: 100%;
        }

        .report-group + .report-group {
            page-break-before: always;
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
            text-align: center;
        }

        .company-cell {
            width: 69%;
            text-align: center;
        }

        .logo {
            width: 170px;
            max-height: 72px;
            object-fit: contain;
        }

        .logo-fallback {
            font-size: 23px;
            font-weight: bold;
        }

        .company-name {
            margin: 0;
            font-size: 20px;
            font-weight: bold;
            line-height: 1.15;
        }

        .company-contact {
            margin-top: 4px;
            font-size: 10.5px;
        }

        .header-line {
            margin: 18px 0 10px;
            border-top: 1px solid #777777;
        }

        .report-meta {
            margin-bottom: 5px;
            font-size: 11px;
            line-height: 1.4;
        }

        .report-meta strong {
            font-weight: bold;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .data-table th,
        .data-table td {
            border: 1px solid #333333;
            padding: 4px 5px;
            vertical-align: middle;
        }

        .data-table th {
            background: #e7e7e7;
            text-align: left;
            font-weight: normal;
        }

        .invoice-column {
            width: 58%;
        }

        .fee-column {
            width: 42%;
        }

        .fee-cell {
            white-space: nowrap;
        }

        .empty-cell {
            padding: 12px 5px !important;
            color: #666666;
            text-align: center;
        }

        .total-row td {
            background: #eef5e8;
            font-weight: bold;
        }

        .total-label {
            text-align: right;
        }

        .notes {
            margin-top: 12px;
            font-size: 9.5px;
            line-height: 1.45;
        }

        .notes-title {
            margin-bottom: 4px;
            font-style: italic;
        }

        .notes ul {
            margin: 0;
            padding-left: 16px;
        }
    </style>
</head>
<body>
@foreach ($groups as $group)
    <section class="report-group">
        <table class="header-table">
            <tr>
                <td class="logo-cell">
                    @if ($logoDataUri)
                        <img class="logo" src="{{ $logoDataUri }}" alt="MS Glow Aesthetics">
                    @else
                        <div class="logo-fallback">MS GLOW AESTHETICS</div>
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

        <div class="header-line"></div>

        <div class="report-meta">
            <strong>TANGGAL :</strong> {{ $periodeLabel }}
            - <strong>Nama :</strong> {{ mb_strtoupper($group['apoteker_nama'] ?? '-') }}
            @if (!empty($group['apoteker_jabatan']))
                ({{ $group['apoteker_jabatan'] }})
            @endif
            @if (!empty($filters['toko_nama']))
                - <strong>Cabang :</strong> {{ $filters['toko_nama'] }}
            @endif
        </div>

        <table class="data-table">
            <thead>
            <tr>
                <th class="invoice-column">No. Faktur</th>
                <th class="fee-column">Fee</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($group['rows'] as $row)
                <tr>
                    <td>{{ $row['no_invoice'] ?? '-' }}</td>
                    <td class="fee-cell">
                        Rp {{ number_format((float) ($row['nilai_insentif'] ?? 0), 0, ',', '.') }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="2" class="empty-cell">
                        Tidak ada data insentif pada periode dan filter yang dipilih.
                    </td>
                </tr>
            @endforelse
            <tr class="total-row">
                <td class="total-label">TOTAL INSENTIF</td>
                <td class="fee-cell">
                    Rp {{ number_format((float) ($group['total_insentif'] ?? 0), 0, ',', '.') }}
                </td>
            </tr>
            </tbody>
        </table>

        <div class="notes">
            <div class="notes-title">Catatan:</div>
            <ul>
                <li>Insentif dihitung satu kali untuk setiap resep/faktur yang selesai diproses oleh petugas.</li>
                <li>
                    Fee per resep adalah
                    Rp {{ number_format((float) ($filters['fee_per_resep'] ?? 0), 0, ',', '.') }}.
                </li>
                <li>Faktur yang memiliki beberapa produk/obat tetap dihitung satu kali.</li>
            </ul>
        </div>
    </section>
@endforeach
</body>
</html>
