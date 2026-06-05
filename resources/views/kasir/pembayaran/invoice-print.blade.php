@php
    use Carbon\Carbon;

    $money = function ($value) {
        return number_format(round((float) ($value ?? 0)), 0, ',', '.');
    };

    $dateFormat = function ($value, $format = 'Y-m-d') {
        if (empty($value)) {
            return '-';
        }

        try {
            return Carbon::parse($value)->format($format);
        } catch (Throwable $e) {
            return (string) $value;
        }
    };

    $activeItems = collect($items ?? ($invoice->items ?? []))
        ->filter(function ($item) {
            return (int) ($item->is_delete ?? 0) === 0;
        })
        ->values();

    $activeMetode = collect($metode ?? ($invoice->metode ?? []))
        ->filter(function ($row) {
            return (int) ($row->is_delete ?? 0) === 0;
        })
        ->values();

    $activePromos = collect($promos ?? ($invoice->promos ?? []))
        ->filter(function ($promo) {
            return (int) ($promo->is_delete ?? 0) === 0 && (float) ($promo->diskon_amount ?? 0) > 0;
        })
        ->values();

    $itemPromosByItem = $activePromos
        ->filter(function ($promo) {
            return !empty($promo->pembayaran_item_id);
        })
        ->groupBy(function ($promo) {
            return (int) $promo->pembayaran_item_id;
        });

    $invoicePromos = $activePromos
        ->filter(function ($promo) {
            return empty($promo->pembayaran_item_id);
        })
        ->values();

    $invoiceSuffix = strtoupper((string) ($invoice->invoice_suffix ?? substr((string) $invoice->no_invoice, -1)));
    $jenisTransaksi = (int) ($invoice->jenis_transaksi ?? 0);
    $isDepositInvoice = $invoiceSuffix === 'D' || $jenisTransaksi === 4;

    $title = $isDepositInvoice
        ? '***KWITANSI PEMBAYARAN DEPOSIT***'
        : '***KWITANSI PEMBAYARAN***';

    $tokoNama = $toko->nama_toko
        ?? $toko->nama
        ?? $invoice->toko_nama
        ?? 'MS GLOW AESTHETIC';

    $tokoAlamat = $toko->alamat
        ?? $toko->alamat_toko
        ?? 'Jl. Guntur No.8, Oro-oro Dowo, Klojen, Kota Malang';

    $tokoTelp = $toko->phone
        ?? $toko->no_telp
        ?? $toko->telepon
        ?? '0341-3015563';

    $pasienNama = $pasien->nama
        ?? $invoice->nama_pasien
        ?? '-';

    $pasienKode = $pasien->no_rm
        ?? $pasien->pasien_no_rm
        ?? $pasien->kode_pasien
        ?? $invoice->member_no
        ?? null;

    $pelangganLabel = trim(($pasienKode ? $pasienKode . ' - ' : '') . $pasienNama);

    $dokterNama = $invoice->registrasi?->dokterAwal?->nama
        ?? $invoice->dokter_nama
        ?? 'Dokter';

    $perawatNama = $invoice->registrasi?->perawatAwal?->nama
        ?? $invoice->perawat_nama
        ?? 'Beautician';

    $kasirNama = $invoice->updated_by
        ?? $invoice->created_by
        ?? ($printedBy ?? 'system');

    $subtotalItem = (float) ($invoice->subtotal ?? 0);
    if ($subtotalItem <= 0) {
        $subtotalItem = (float) $activeItems->sum(function ($item) {
            return (float) ($item->subtotal ?? $item->total ?? 0);
        });
    }

    $diskonItem = (float) ($invoice->total_diskon_item ?? $invoice->diskon_item ?? 0);
    $diskonSubtotal = (float) ($invoice->diskon_subtotal_amount ?? $invoice->diskon_subtotal ?? 0);
    $diskonPromo = (float) ($invoice->total_promo ?? $invoice->diskon_promo ?? 0);
    if ($diskonPromo <= 0 && $activePromos->isNotEmpty()) {
        $diskonPromo = (float) $activePromos->sum(function ($promo) {
            return (float) ($promo->diskon_amount ?? 0);
        });
    }

    $diskonReferral = (float) ($invoice->total_diskon_referral ?? $invoice->diskon_referral ?? 0);
    $diskonMember = (float) ($invoice->diskon_member_amount ?? 0);
    $redeemPoin = (float) ($invoice->point_redeem_value ?? 0);
    $totalDiskon = $diskonItem + $diskonSubtotal + $diskonPromo + $diskonReferral + $diskonMember + $redeemPoin;

    $grandTotal = (float) ($invoice->grand_total ?? max($subtotalItem - $totalDiskon, 0));
    $totalBayar = (float) ($invoice->total_bayar ?? $activeMetode->sum(function ($row) {
        return (float) ($row->nominal_dialokasikan ?? 0);
    }));
    $totalKembalian = (float) ($invoice->total_kembalian ?? max($totalBayar - $grandTotal, 0));

    $labelJenisItem = function ($item) {
        $type = $item->item_type ?? $item->jenis_item ?? null;

        if (is_numeric($type)) {
            return match ((int) $type) {
                1 => 'Konsultasi',
                2 => 'Treatment',
                3 => 'Produk',
                default => 'Item',
            };
        }

        $type = strtolower((string) $type);

        if (in_array($type, ['produk', 'obat', 'penjualan'], true)) {
            return 'Produk';
        }

        if (in_array($type, ['treatment', 'perawatan'], true)) {
            return 'Treatment';
        }

        if ($type === 'konsultasi') {
            return 'Konsultasi';
        }

        return 'Item';
    };

    $qtyFormat = function ($value) {
        $formatted = rtrim(rtrim(number_format((float) ($value ?? 0), 2, ',', '.'), '0'), ',');

        return $formatted !== '' ? $formatted : '0';
    };

    $itemSections = ['Produk', 'Treatment', 'Konsultasi', 'Item'];
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice->no_invoice ?? 'Invoice' }}</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #ffffff;
            color: #000000;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            line-height: 1.3;
        }

        .print-action {
            width: 360px;
            max-width: 100%;
            margin: 10px auto 8px;
            padding: 0 10px;
        }

        .print-action button {
            width: 100%;
            height: 34px;
            border: 0;
            border-radius: 8px;
            background: #f8b7c5;
            color: #000;
            font-weight: 700;
            cursor: pointer;
        }

        .receipt {
            width: 360px;
            max-width: 100%;
            margin: 0 auto;
            padding: 8px 10px 18px;
        }

        .center {
            text-align: center;
        }

        .store-name {
            font-size: 13px;
            font-weight: 800;
            margin-bottom: 2px;
        }

        .store-meta {
            font-size: 11px;
        }

        .line {
            border-top: 2px solid #000;
            margin: 10px 0 8px;
        }

        .dash {
            border-top: 1px dashed #000;
            margin: 8px 0;
        }

        .title {
            font-weight: 800;
            text-align: center;
            margin: 8px 0;
        }

        .row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px;
            margin: 2px 0;
        }

        .row .label {
            min-width: 110px;
        }

        .row .value {
            flex: 1;
            text-align: right;
            font-weight: 700;
        }

        .info-row {
            margin: 1px 0;
        }

        .info-row .label {
            min-width: 86px;
        }

        .info-row .value {
            line-height: 1.15;
        }

        .item-head {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin-top: 10px;
            padding-bottom: 3px;
            border-bottom: 1px solid #000;
            font-weight: 800;
        }

        .item-section-title {
            margin-top: 7px;
            padding: 2px 0;
            border-bottom: 1px dashed #999;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .item-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 86px;
            gap: 8px;
            padding: 5px 0;
            border-bottom: 1px dotted #c7c7c7;
        }

        .item-row:last-child {
            border-bottom: 0;
        }

        .item-left,
        .item-right {
            min-width: 0;
        }

        .item-name-line {
            font-weight: 800;
            text-transform: uppercase;
            word-break: break-word;
        }

        .item-subline {
            margin-top: 2px;
            font-size: 11px;
        }

        .item-right {
            text-align: right;
            font-weight: 700;
        }

        .item-net-line {
            margin-top: 2px;
            font-size: 11px;
            font-weight: 800;
        }

        .promo-item-line {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin-top: 2px;
            font-size: 11px;
        }

        .promo-item-line span:first-child {
            flex: 1;
            word-break: break-word;
        }

        .promo-item-line span:last-child {
            min-width: 64px;
            text-align: right;
            font-weight: 700;
        }

        .summary {
            margin-top: 8px;
        }

        .summary .row .label {
            min-width: 150px;
        }

        .summary .row .value {
            font-weight: 700;
        }

        .grand {
            padding-top: 6px;
            border-top: 1px solid #000;
            font-size: 13px;
            font-weight: 800;
        }

        .promo-list {
            margin-top: 8px;
            text-align: center;
        }

        .promo-list-title {
            font-weight: 800;
            margin-bottom: 4px;
        }

        .promo-list-row {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin: 2px 0;
            font-size: 11px;
            text-align: left;
        }

        .promo-list-row span:first-child {
            font-weight: 700;
        }

        .note {
            margin-top: 8px;
            text-align: center;
            font-size: 11px;
        }

        .qr {
            margin-top: 10px;
            text-align: left;
        }

        .qr img {
            width: 72px;
            height: 72px;
        }

        @media print {
            .print-action {
                display: none;
            }

            body {
                margin: 0;
            }

            .receipt {
                width: 72mm;
                padding: 0 3mm;
            }
        }
    </style>
</head>
<body>
    <div class="print-action">
        <button type="button" onclick="window.print()">CETAK INVOICE</button>
    </div>

    <div class="receipt">
        <div class="center">
            <div class="store-name">{{ strtoupper($tokoNama) }}</div>
            <div class="store-meta">{{ $tokoAlamat }}</div>
            <div class="store-meta">Phone: {{ $tokoTelp }}</div>
        </div>

        <div class="line"></div>
        <div class="title">{{ $title }}</div>
        <div class="line"></div>

        <div class="row info-row">
            <div class="label">Tgl</div>
            <div class="value">{{ $dateFormat($invoice->tanggal_invoice ?? $invoice->created_at) }}</div>
        </div>
        <div class="row info-row">
            <div class="label">No. Invoice</div>
            <div class="value">{{ $invoice->no_invoice ?? '-' }}</div>
        </div>
        <div class="row info-row">
            <div class="label">Waktu Transaksi</div>
            <div class="value">{{ $dateFormat($invoice->tanggal_lunas ?? $invoice->updated_at ?? $invoice->created_at, 'Y-m-d H:i:s') }}</div>
        </div>
        <div class="row info-row">
            <div class="label">Pelanggan</div>
            <div class="value">{{ $pelangganLabel }}</div>
        </div>
        <div class="row info-row">
            <div class="label">Dokter</div>
            <div class="value">{{ $dokterNama }}</div>
        </div>
        <div class="row info-row">
            <div class="label">Perawat</div>
            <div class="value">{{ $perawatNama }}</div>
        </div>
        <div class="row info-row">
            <div class="label">Kasir</div>
            <div class="value">{{ $kasirNama }}</div>
        </div>
        <div class="row info-row">
            <div class="label">Print By</div>
            <div class="value">{{ $printedBy ?? $kasirNama }}</div>
        </div>

        <div class="item-head">
            <span>Detail Item</span>
            <span>Total</span>
        </div>

        @foreach ($itemSections as $sectionName)
            @php
                $sectionItems = $activeItems
                    ->filter(function ($item) use ($labelJenisItem, $sectionName) {
                        return $labelJenisItem($item) === $sectionName;
                    })
                    ->values();
            @endphp

            @if ($sectionItems->isNotEmpty())
                <div class="item-section-title">{{ $sectionName }}</div>

                @foreach ($sectionItems as $itemIndex => $item)
                    @php
                        $qty = (float) ($item->qty ?? $item->jumlah ?? 1);
                        if ($qty <= 0) {
                            $qty = 1;
                        }

                        $harga = (float) ($item->harga ?? $item->harga_satuan ?? 0);
                        $subtotal = (float) ($item->subtotal ?? $item->total ?? ($harga * $qty));
                        $itemPromos = collect($itemPromosByItem->get((int) ($item->id ?? 0), collect()))->values();
                        $itemPromoAmount = (float) $itemPromos->sum(function ($promo) {
                            return (float) ($promo->diskon_amount ?? 0);
                        });
                        $itemFinal = max($subtotal - $itemPromoAmount, 0);
                    @endphp

                    <div class="item-row">
                        <div class="item-left">
                            <div class="item-name-line">{{ $itemIndex + 1 }}. {{ $item->nama_item ?? $item->nama ?? '-' }}</div>
                            <div class="item-subline">{{ $money($harga) }} x {{ $qtyFormat($qty) }}</div>

                            @foreach ($itemPromos as $promo)
                                <div class="promo-item-line">
                                    <span>Voucher: {{ $promo->nama_voucher ?? $promo->kode_voucher ?? 'Promo' }}</span>
                                    <span>-{{ $money($promo->diskon_amount ?? 0) }}</span>
                                </div>
                            @endforeach
                        </div>

                        <div class="item-right">
                            <div>{{ $money($subtotal) }}</div>
                            @if ($itemPromoAmount > 0)
                                <div class="item-net-line">Net {{ $money($itemFinal) }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            @endif
        @endforeach

        <div class="dash"></div>

        <div class="summary">
            <div class="row">
                <div class="label">Subtotal Item</div>
                <div class="value">{{ $money($subtotalItem) }}</div>
            </div>

            @if ($diskonItem > 0)
                <div class="row">
                    <div class="label">Diskon Item</div>
                    <div class="value">{{ $money($diskonItem) }}</div>
                </div>
            @endif

            @if ($diskonSubtotal > 0)
                <div class="row">
                    <div class="label">Diskon Subtotal</div>
                    <div class="value">{{ $money($diskonSubtotal) }}</div>
                </div>
            @endif

            @if ($diskonPromo > 0)
                <div class="row">
                    <div class="label">Total Promo / Voucher</div>
                    <div class="value">{{ $money($diskonPromo) }}</div>
                </div>
            @endif

            @if ($diskonMember > 0)
                <div class="row">
                    <div class="label">Diskon Member</div>
                    <div class="value">{{ $money($diskonMember) }}</div>
                </div>
            @endif

            @if ($diskonReferral > 0)
                <div class="row">
                    <div class="label">Diskon Referral</div>
                    <div class="value">{{ $money($diskonReferral) }}</div>
                </div>
            @endif

            @if ($redeemPoin > 0)
                <div class="row">
                    <div class="label">Redeem Poin</div>
                    <div class="value">{{ $money($redeemPoin) }}</div>
                </div>
            @endif

            @if ($totalDiskon > 0)
                <div class="row">
                    <div class="label">Total Diskon</div>
                    <div class="value">{{ $money($totalDiskon) }}</div>
                </div>
            @endif

            <div class="row grand">
                <div class="label">Total Harga</div>
                <div class="value">{{ $money($grandTotal) }}</div>
            </div>

            @foreach ($activeMetode as $row)
                <div class="row">
                    <div class="label">Pembayaran › {{ $row->metode_bayar_nama ?? $row->nama_metode_bayar ?? 'Metode' }}</div>
                    <div class="value">{{ $money($row->nominal_dialokasikan ?? 0) }}</div>
                </div>
            @endforeach

            <div class="row">
                <div class="label">Total Bayar</div>
                <div class="value">{{ $money($totalBayar) }}</div>
            </div>
            <div class="row">
                <div class="label">Kembalian</div>
                <div class="value">{{ $money($totalKembalian) }}</div>
            </div>
        </div>

        @if ($invoicePromos->isNotEmpty())
            <div class="dash"></div>
            <div class="promo-list">
                <div class="promo-list-title">Voucher Invoice</div>
                @foreach ($invoicePromos as $promo)
                    <div class="promo-list-row">
                        <span>{{ $promo->nama_voucher ?? $promo->kode_voucher ?? 'Promo' }}</span>
                        <span>-{{ $money($promo->diskon_amount ?? 0) }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="line"></div>

        <div class="note">
            <div>Terima Kasih atas Kunjungan Anda</div>
            <br>
            <div>"Produk yang sudah dibeli tidak dapat ditukarkan atau dikembalikan"</div>
            <br>
            <div>"Pastikan Anda mendapatkan resep tercetak setelah membeli produk dari konsultasi dokter"</div>
            <br>
            <div><strong>"Pengambilan untuk pembelian paket bundling treatment mengikuti ketentuan masing-masing cabang"</strong></div>
        </div>

        @if (!empty($qrDataUri))
            <div class="qr">
                <img src="{{ $qrDataUri }}" alt="QR Code">
            </div>
        @endif
    </div>

    <script>
        window.addEventListener('load', function () {
            if (new URLSearchParams(window.location.search).get('auto_print') === '1') {
                window.print();
            }
        });
    </script>
</body>
</html>
