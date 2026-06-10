@php
    date_default_timezone_set('Asia/Jakarta');

    $dateOnly = static function ($value): string {
        if (!$value) {
            return '-';
        }

        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $exception) {
            return '-';
        }
    };

    $qty = static function ($value): string {
        $number = (float) ($value ?? 0);
        $formatted = rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    };

    $pasienNama = data_get($resep, 'pasien.nama') ?: 'Pasien tidak ditemukan';
    $pasienNoRm = data_get($resep, 'pasien.no_rm') ?: '-';
    $pelanggan = trim($pasienNoRm . ' - ' . $pasienNama, ' -');
    $tokoNama = data_get($resep, 'toko.nama') ?: '-';
    $tokoAlamat = data_get($resep, 'toko.alamat');
    $tokoTelepon = data_get($resep, 'toko.no_telepon');
    $apoteker = data_get($resep, 'petugas.nama') ?: '-';
    $apotekerJabatan = data_get($resep, 'petugas.jabatan');
    $plan = trim((string) data_get($soap, 'plan', ''));
    $nextConsultationDate = data_get($soap, 'next_konsultasi_date');
    $treatments = collect(data_get($resep, 'treatment', []));
    $products = collect(data_get($resep, 'produk', []));
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resep {{ data_get($resep, 'no_invoice', '-') }}</title>
    <style>
        * {
            box-sizing: border-box;
        }

        @page {
            size: 80mm auto;
            margin: 0;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            background: #ffffff;
            color: #000000;
            font-family: Arial, Helvetica, sans-serif;
        }

        body {
            width: 80mm;
            max-width: 100%;
        }

        .screen-actions {
            width: 100%;
            padding: 6px 6px 10px;
            text-align: center;
        }

        .print-button {
            width: 92%;
            min-height: 30px;
            border: 0;
            border-radius: 10px;
            background: #ffb6c1;
            color: #000000;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .receipt {
            width: 80mm;
            max-width: 100%;
            padding: 0 2mm 7mm;
            font-size: 8pt;
            line-height: 1.35;
        }

        .receipt-title {
            margin-bottom: 8px;
            text-align: center;
            font-size: 11pt;
            font-weight: 700;
        }

        .branch {
            margin-bottom: 7px;
            text-align: center;
            font-size: 7.5pt;
            line-height: 1.3;
        }

        .branch strong {
            display: block;
            font-size: 8.5pt;
        }

        .meta-table,
        .item-table {
            width: 100%;
            border-collapse: collapse;
        }

        .meta-table td {
            padding: 1px 0;
            vertical-align: top;
        }

        .meta-label {
            width: 31mm;
            white-space: nowrap;
        }

        .meta-separator {
            width: 4mm;
            text-align: center;
        }

        .meta-value {
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .divider {
            margin: 5px 0;
            border-top: 1px dashed #000000;
        }

        .section-title {
            margin: 4px 0;
            font-weight: 700;
            text-transform: uppercase;
        }

        .item-table th {
            padding: 3px 0;
            border-top: 1px dashed #000000;
            border-bottom: 1px dashed #000000;
            text-align: left;
            font-size: 8pt;
        }

        .item-table th:last-child,
        .item-table td:last-child {
            width: 18mm;
            text-align: right;
        }

        .item-table td {
            padding: 4px 0 1px;
            vertical-align: top;
        }

        .item-name {
            font-weight: 700;
            text-transform: uppercase;
        }

        .usage {
            padding: 0 0 4px;
            font-size: 7.5pt;
            overflow-wrap: anywhere;
        }

        .qr-wrap {
            margin-top: 8px;
            text-align: center;
        }

        .qr-wrap img {
            width: 30mm;
            height: 30mm;
            object-fit: contain;
        }

        .receipt-code {
            margin-top: 2px;
            font-size: 6.5pt;
            overflow-wrap: anywhere;
        }

        .footer {
            margin-top: 8px;
            text-align: center;
            font-size: 7.5pt;
            line-height: 1.45;
        }

        .footer-note {
            margin-top: 2px;
        }

        @media print {
            .screen-actions {
                display: none !important;
            }

            body,
            .receipt {
                width: 80mm;
            }
        }
    </style>
</head>
<body>
    <div class="screen-actions">
        <button type="button" class="print-button" onclick="window.print()">
            CETAK RESEP DOKTER
        </button>
    </div>

    <main class="receipt">
        <div class="receipt-title">CETAK RESEP DOKTER</div>

        <div class="branch">
            <strong>MS GLOW AESTHETIC {{ strtoupper($tokoNama) }}</strong>
            @if ($tokoAlamat)
                <div>{{ $tokoAlamat }}</div>
            @endif
            @if ($tokoTelepon)
                <div>Phone: {{ $tokoTelepon }}</div>
            @endif
        </div>

        <table class="meta-table">
            <tr>
                <td class="meta-label">Tgl</td>
                <td class="meta-separator">:</td>
                <td class="meta-value">{{ $dateOnly(data_get($resep, 'tanggal_lunas')) }}</td>
            </tr>
            <tr>
                <td class="meta-label">No. Invoice</td>
                <td class="meta-separator">:</td>
                <td class="meta-value">{{ data_get($resep, 'no_invoice', '-') }}</td>
            </tr>
            <tr>
                <td class="meta-label">Pelanggan</td>
                <td class="meta-separator">:</td>
                <td class="meta-value">{{ $pelanggan }}</td>
            </tr>
            <tr>
                <td class="meta-label">Dokter</td>
                <td class="meta-separator">:</td>
                <td class="meta-value">{{ $dokterNama }}</td>
            </tr>
            <tr>
                <td class="meta-label">Diproses Oleh</td>
                <td class="meta-separator">:</td>
                <td class="meta-value">
                    {{ $apoteker }}
                    @if ($apotekerJabatan)
                        ({{ $apotekerJabatan }})
                    @endif
                </td>
            </tr>
            <tr>
                <td class="meta-label">Plan</td>
                <td class="meta-separator">:</td>
                <td class="meta-value">{{ $plan !== '' ? $plan : '-' }}</td>
            </tr>
            <tr>
                <td class="meta-label">Saran Konsultasi Selanjutnya</td>
                <td class="meta-separator">:</td>
                <td class="meta-value">{{ $dateOnly($nextConsultationDate) }}</td>
            </tr>
        </table>

        @if (data_get($resep, 'has_konsultasi'))
            <div class="divider"></div>
            <div class="section-title">
                1. {{ data_get($resep, 'konsultasi.jenis', 'Konsul Dokter') }}
            </div>
        @endif

        @if ($treatments->isNotEmpty())
            <table class="item-table">
                <thead>
                    <tr>
                        <th>Nama Treatment</th>
                        <th>Qty</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($treatments as $treatment)
                        <tr>
                            <td class="item-name">
                                {{ $loop->iteration }}. {{ data_get($treatment, 'nama', 'Treatment') }}
                            </td>
                            <td>
                                {{ $qty(data_get($treatment, 'qty')) }}
                                {{ data_get($treatment, 'satuan') ?: '' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($products->isNotEmpty())
            <table class="item-table">
                <thead>
                    <tr>
                        <th>Nama Produk</th>
                        <th>Qty</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($products as $product)
                        <tr>
                            <td class="item-name">
                                {{ $loop->iteration }}. {{ data_get($product, 'nama', 'Produk') }}
                            </td>
                            <td>
                                {{ $qty(data_get($product, 'qty')) }}
                                {{ data_get($product, 'satuan') ?: '' }}
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2" class="usage">
                                <div>
                                    <strong>Cara pakai:</strong>
                                    {{ data_get($product, 'aturan_pakai', 'Aturan pakai belum diisi') }}
                                </div>
                                <div>
                                    <strong>Cara penggunaan:</strong>
                                    {{ data_get($product, 'cara_penggunaan') ?: '-' }}
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if (!empty($qrDataUri))
            <div class="qr-wrap">
                <img src="{{ $qrDataUri }}" alt="QR Resep">
                <div class="receipt-code">
                    {{ data_get($resep, 'no_invoice', '-') }}
                </div>
            </div>
        @endif

        <div class="footer">
            <div>Terima Kasih atas Kunjungan Anda</div>
            <div class="footer-note">
                &quot;Produk yang sudah dibeli tidak dapat ditukarkan atau dikembalikan&quot;
            </div>
        </div>
    </main>

    @if ($autoPrint)
        <script>
            window.addEventListener("load", function () {
                window.setTimeout(function () {
                    window.print();
                }, 250);
            });
        </script>
    @endif
</body>
</html>
