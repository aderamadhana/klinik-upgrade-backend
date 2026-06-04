@php
    date_default_timezone_set('Asia/Jakarta');

    $money = function ($value) {
        return number_format((float) ($value ?? 0), 0, '.', ',');
    };

    $qty = function ($value) {
        $number = (float) ($value ?? 0);
        $text = rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
        return $text === '' ? '0' : $text;
    };

    $dateOnly = function ($value) {
        if (!$value) {
            return '-';
        }

        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return '-';
        }
    };

    $dateTime = function ($value) {
        if (!$value) {
            return '-';
        }

        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return '-';
        }
    };

    $itemTypeLabel = function ($itemType) {
        return match ((int) $itemType) {
            1 => 'Konsultasi',
            2 => 'Treatment',
            3 => 'Produk',
            4 => 'Deposit Treatment',
            5 => 'Accurate',
            default => 'Item',
        };
    };

    $itemNetTotal = function ($item) {
        $afterSubtotalDiscount = (float) ($item->subtotal_after_diskon_subtotal ?? 0);

        if ($afterSubtotalDiscount > 0) {
            return $afterSubtotalDiscount;
        }

        $subtotal = (float) ($item->subtotal ?? 0);

        if ($subtotal > 0) {
            return $subtotal;
        }

        return max(
            ((float) ($item->harga ?? 0) * (float) ($item->qty ?? 0))
            - (float) ($item->diskon_amount ?? 0)
            - (float) ($item->diskon_subtotal_amount ?? 0)
            - (float) ($item->diskon_referral ?? 0),
            0
        );
    };

    $tokoNama = $toko->nama_toko ?? $toko->nama ?? $invoice->toko_nama ?? '-';
    $tokoAlamat = $toko->alamat ?? $invoice->toko_alamat ?? '-';
    $tokoPhone = $toko->no_telepon ?? $toko->no_telpon ?? $invoice->toko_no_telepon ?? '-';

    $noInvoice = $invoice->no_invoice ?? $invoice->faktur ?? '-';
    $tanggalInvoice = $invoice->tanggal_invoice ?? $invoice->created_at ?? null;
    $tanggalLunas = $invoice->tanggal_lunas ?? $invoice->updated_at ?? $tanggalInvoice;

    $pasienNama = $pasien->nama ?? $pasien->nama_pasien ?? $invoice->pasien_nama ?? $invoice->nama_pembeli ?? '-';
    $noRm = $pasien->no_rm ?? $invoice->no_rm ?? null;
    $pelanggan = $noRm ? $noRm . ' - ' . $pasienNama : $pasienNama;

    $dokterNama = $registrasi->dokterAwal->nama ?? $invoice->dokter_nama ?? '-';
    $perawatNama = $registrasi->perawatAwal->nama ?? $invoice->perawat_nama ?? '-';
    $kasirNama = $invoice->updated_by ?? $invoice->created_by ?? '-';

    $subtotal = (float) ($invoice->subtotal ?? 0);
    $totalDiskonItem = (float) ($invoice->total_diskon_item ?? 0);
    $diskonSubtotal = (float) ($invoice->diskon_subtotal_amount ?? 0);
    $totalPromo = (float) ($invoice->total_promo ?? 0);
    $totalDiskonReferral = (float) ($invoice->total_diskon_referral ?? 0);
    $pointRedeemValue = (float) ($invoice->point_redeem_value ?? 0);
    $totalDiskon = $totalDiskonItem + $diskonSubtotal + $totalPromo + $totalDiskonReferral + $pointRedeemValue;

    $grandTotal = (float) ($invoice->grand_total ?? 0);
    $totalBayar = (float) ($invoice->total_bayar ?? 0);
    $kembalian = (float) ($invoice->total_kembalian ?? 0);
@endphp

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $noInvoice }}</title>

    <style>
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
            font-size: 11px;
            line-height: 1.25;
        }

        .screen-wrap {
            width: 384px;
            box-sizing: border-box;
            padding: 14px 28px 18px;
        }

        .print-button {
            width: 100%;
            height: 30px;
            margin: 0 0 18px;
            border: 0;
            border-radius: 9px;
            background: #ffc0cb;
            color: #000000;
            font-weight: 700;
            cursor: pointer;
        }

        .receipt {
            width: 100%;
            box-sizing: border-box;
        }

        .center {
            text-align: center;
        }

        .strong {
            font-weight: 700;
        }

        .separator {
            margin: 8px 0;
            border-top: 1px dashed #000000;
        }

        .double-separator {
            margin: 8px 0;
            border-top: 2px solid #000000;
        }

        .header-title {
            font-size: 11px;
            font-weight: 700;
            text-align: center;
            text-transform: uppercase;
        }

        .header-subtitle {
            margin-top: 2px;
            text-align: center;
            font-size: 10.5px;
        }

        .receipt-title {
            margin: 8px 0;
            text-align: center;
            font-weight: 700;
        }

        .info-row,
        .summary-row,
        .item-meta,
        .item-breakdown-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px;
        }

        .info-row,
        .summary-row {
            margin-bottom: 3px;
        }

        .info-row span:first-child,
        .summary-row span:first-child {
            flex: 1;
        }

        .info-row strong,
        .summary-row strong {
            text-align: right;
            white-space: nowrap;
        }

        .item-header {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 5px;
            font-weight: 700;
        }

        .item-row {
            padding: 7px 0;
            border-bottom: 1px dashed #000000;
        }

        .item-name {
            font-weight: 700;
            text-transform: uppercase;
            word-break: break-word;
        }

        .item-meta {
            margin-top: 3px;
            color: #222222;
        }

        .item-breakdown {
            margin-top: 4px;
        }

        .item-breakdown-row {
            margin-top: 2px;
        }

        .item-total {
            font-weight: 700;
        }

        .grand-total {
            margin-top: 7px;
            padding-top: 6px;
            border-top: 1px solid #000000;
            font-size: 12px;
            font-weight: 700;
        }

        .footer-note {
            margin-top: 8px;
            text-align: center;
            font-size: 10.5px;
        }

        .promo-code {
            text-align: center;
            font-weight: 700;
            margin-bottom: 3px;
        }

        .empty-row {
            padding: 10px 0;
            text-align: center;
            color: #333333;
            border-bottom: 1px dashed #000000;
        }

        @media print {
            .screen-wrap {
                width: 80mm;
                padding: 0 4mm 0;
            }

            .print-button {
                display: none;
            }

            .receipt {
                width: 100%;
            }
        }
        .qr-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 10px;
        }

        .qr-image {
            width: 92px;
            height: 92px;
            flex: 0 0 auto;
        }

        .qr-text {
            flex: 1;
            font-size: 10.5px;
            line-height: 1.25;
            text-align: left;
        }
    </style>
</head>

<body>
    <div class="screen-wrap">
        <button class="print-button" onclick="window.print()">CETAK INVOICE</button>

        <div class="receipt">
            <div class="header-title">
                MS GLOW AESTHETIC {{ $tokoNama }}
            </div>

            <div class="header-subtitle">
                {{ $tokoAlamat }}
            </div>

            <div class="header-subtitle">
                Phone: {{ $tokoPhone }}
            </div>

            <div class="double-separator"></div>
            <div class="receipt-title">
                ***KWITANSI PEMBAYARAN{{ (int) ($invoice->jenis_transaksi ?? 0) === 4 ? ' DEPOSIT' : '' }}***
            </div>
            <div class="double-separator"></div>

            <div class="info-row">
                <span>Tgl</span>
                <strong>{{ $dateOnly($tanggalInvoice) }}</strong>
            </div>

            <div class="info-row">
                <span>No. Invoice</span>
                <strong>{{ $noInvoice }}</strong>
            </div>

            <div class="info-row">
                <span>Waktu Transaksi</span>
                <strong>{{ $dateTime($tanggalLunas) }}</strong>
            </div>

            <div class="info-row">
                <span>Pelanggan</span>
                <strong>{{ $pelanggan }}</strong>
            </div>

            <div class="info-row">
                <span>Dokter</span>
                <strong>{{ $dokterNama }}</strong>
            </div>

            <div class="info-row">
                <span>Perawat</span>
                <strong>{{ $perawatNama }}</strong>
            </div>

            <div class="info-row">
                <span>Kasir</span>
                <strong>{{ $kasirNama }}</strong>
            </div>

            <div class="info-row">
                <span>Print By</span>
                <strong>{{ $printedBy ?? '-' }}</strong>
            </div>

            <div class="separator"></div>

            <div class="item-header">
                <span>Detail Item</span>
                <span>Total</span>
            </div>

            @forelse ($items as $item)
                @php
                    $gross = (float) ($item->subtotal_before_diskon_subtotal ?? 0);

                    if ($gross <= 0) {
                        $gross = (float) ($item->harga ?? 0) * (float) ($item->qty ?? 0);
                    }

                    $diskonItem = (float) ($item->diskon_amount ?? 0);
                    $diskonSubtotalItem = (float) ($item->diskon_subtotal_amount ?? 0);
                    $diskonReferral = (float) ($item->diskon_referral ?? 0);
                    $netTotal = $itemNetTotal($item);
                @endphp

                <div class="item-row">
                    <div class="item-name">
                        {{ $item->nama_item ?? 'Item' }}
                    </div>

                    <div class="item-meta">
                        <span>{{ $itemTypeLabel($item->item_type ?? null) }}</span>
                        <span>{{ $money($item->harga ?? 0) }} x {{ $qty($item->qty ?? 0) }}</span>
                    </div>

                    <div class="item-breakdown">
                        <div class="item-breakdown-row">
                            <span>Subtotal</span>
                            <strong>{{ $money($gross) }}</strong>
                        </div>

                        @if ($diskonItem > 0)
                            <div class="item-breakdown-row">
                                <span>Diskon item</span>
                                <strong>-{{ $money($diskonItem) }}</strong>
                            </div>
                        @endif

                        @if ($diskonSubtotalItem > 0)
                            <div class="item-breakdown-row">
                                <span>Diskon subtotal</span>
                                <strong>-{{ $money($diskonSubtotalItem) }}</strong>
                            </div>
                        @endif

                        @if ($diskonReferral > 0)
                            <div class="item-breakdown-row">
                                <span>Diskon referral</span>
                                <strong>-{{ $money($diskonReferral) }}</strong>
                            </div>
                        @endif

                        <div class="item-breakdown-row item-total">
                            <span>Total Item</span>
                            <strong>{{ $money($netTotal) }}</strong>
                        </div>
                    </div>
                </div>
            @empty
                <div class="empty-row">
                    Tidak ada item pembayaran.
                </div>
            @endforelse

            <div class="separator"></div>

            <div class="summary-row">
                <span>Subtotal Item</span>
                <strong>{{ $money($subtotal) }}</strong>
            </div>

            <div class="summary-row">
                <span>Diskon Item</span>
                <strong>{{ $money($totalDiskonItem) }}</strong>
            </div>

            <div class="summary-row">
                <span>Diskon Subtotal</span>
                <strong>{{ $money($diskonSubtotal) }}</strong>
            </div>

            <div class="summary-row">
                <span>Promo / Voucher</span>
                <strong>{{ $money($totalPromo) }}</strong>
            </div>

            <div class="summary-row">
                <span>Diskon Referral</span>
                <strong>{{ $money($totalDiskonReferral) }}</strong>
            </div>

            <div class="summary-row">
                <span>Redeem Poin</span>
                <strong>{{ $money($pointRedeemValue) }}</strong>
            </div>

            <div class="summary-row">
                <span>Total Diskon</span>
                <strong>{{ $money($totalDiskon) }}</strong>
            </div>

            <div class="summary-row grand-total">
                <span>Total Harga</span>
                <strong>{{ $money($grandTotal) }}</strong>
            </div>

            @foreach ($metode as $method)
                <div class="summary-row">
                    <span>Pembayaran › {{ $method->metode_bayar_nama ?? $method->nama ?? 'Metode' }}</span>
                    <strong>{{ $money($method->nominal_dialokasikan ?? $method->nominal_diterima ?? 0) }}</strong>
                </div>
            @endforeach

            <div class="summary-row">
                <span>Total Bayar</span>
                <strong>{{ $money($totalBayar) }}</strong>
            </div>

            <div class="summary-row">
                <span>Kembalian</span>
                <strong>{{ $money($kembalian) }}</strong>
            </div>

            @if ($promos->count() > 0)
                <div class="separator"></div>
                <div class="center strong" style="margin-bottom: 4px;">
                    Promo / Voucher
                </div>

                @foreach ($promos as $promo)
                    <div class="promo-code">
                        {{ $promo->nama_voucher ?? $promo->kode_voucher ?? 'Promo' }}
                    </div>
                @endforeach
            @endif

            <div class="double-separator"></div>

            @if ((float) ($invoice->point_earned ?? 0) > 0 || (float) ($invoice->poin ?? 0) > 0)
                <div class="center">
                    Pendapatan Poin: {{ $money($invoice->point_earned ?? 0) }}
                    @if ((float) ($invoice->poin ?? 0) > 0)
                        | Sisa Poin: {{ $money($invoice->poin ?? 0) }}
                    @endif
                </div>
            @endif

            <div class="footer-note">
                Terima Kasih atas Kunjungan Anda
            </div>

            <div class="footer-note">
                "Produk yang sudah dibeli tidak dapat ditukarkan atau dikembalikan"
            </div>

            <div class="footer-note">
                "Pastikan Anda mendapatkan resep tercetak setelah membeli produk dari konsultasi dokter"
            </div>

            <div class="footer-note strong">
                "Pengambilan untuk pembelian paket bundling treatment mengikuti ketentuan masing-masing cabang"
            </div>
            @if (!empty($qrDataUri))
            <div class="qr-wrap">
                <img
                    src="{{ $qrDataUri }}"
                    class="qr-image"
                    alt="QR Penilaian Pelayanan"
                >

                <div class="qr-text">
                    [Scan QR Code di samping untuk memberikan penilaian terhadap pelayanan kami]
                </div>
            </div>
        @endif
        </div>
    </div>

    <script>
        window.onload = function () {
            setTimeout(function () {
                window.print();
            }, 300);
        };
    </script>
</body>
</html>