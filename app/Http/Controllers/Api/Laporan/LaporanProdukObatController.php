<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LaporanProdukObatController extends Controller
{
    private const ALLOWED_JENIS_TRANSAKSI = [0, 1, 2, 3, 4];

    public function summary(Request $request)
    {
        $filters = $this->normalizeFilters($request);

        if ($filters['validator']->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Filter laporan tidak valid.',
                'errors' => $filters['validator']->errors(),
            ], 422);
        }

        $filters = $filters['data'];
        $rows = $this->getRows($filters);
        $jenisOptions = $this->getJenisTransaksiOptions();
        $jenisLabels = $jenisOptions->pluck('nama_jenis_transaksi', 'id');

        return response()->json([
            'status' => true,
            'message' => 'Ringkasan laporan obat/produk berhasil diambil.',
            'data' => [
                'filters' => $this->publicFilters($filters),
                'jenis_transaksi_options' => $jenisOptions->values(),
                'total_item' => $rows->count(),
                'total_invoice' => $rows->pluck('pembayaran_id')->unique()->count(),
                'total_pasien' => $rows->pluck('pasien_id')->filter()->unique()->count(),
                'total_qty' => (float) $rows->sum('qty'),
                'total_gross' => (float) $rows->sum('gross_amount'),
                'total_diskon' => (float) $rows->sum('total_diskon'),
                'total_net' => (float) $rows->sum('subtotal'),
                'total_hpp' => (float) $rows->sum('hpp_amount'),
                'estimasi_margin' => (float) $rows->sum('estimasi_margin'),
                'by_jenis_transaksi' => collect(self::ALLOWED_JENIS_TRANSAKSI)
                    ->map(function ($id) use ($rows, $jenisLabels) {
                        $items = $rows->where('jenis_transaksi_id', $id);

                        return [
                            'id' => $id,
                            'nama' => $jenisLabels[$id] ?? $this->defaultJenisTransaksiLabel($id),
                            'total_item' => $items->count(),
                            'total_invoice' => $items->pluck('pembayaran_id')->unique()->count(),
                            'total_pasien' => $items->pluck('pasien_id')->filter()->unique()->count(),
                            'total_qty' => (float) $items->sum('qty'),
                            'total_gross' => (float) $items->sum('gross_amount'),
                            'total_diskon' => (float) $items->sum('total_diskon'),
                            'total_net' => (float) $items->sum('subtotal'),
                            'total_hpp' => (float) $items->sum('hpp_amount'),
                            'estimasi_margin' => (float) $items->sum('estimasi_margin'),
                        ];
                    })
                    ->values(),
                'by_kategori_produk' => $rows
                    ->groupBy('kategori_produk')
                    ->map(function ($items, $kategori) {
                        return [
                            'kategori_produk' => $kategori ?: '-',
                            'total_item' => $items->count(),
                            'total_qty' => (float) $items->sum('qty'),
                            'total_net' => (float) $items->sum('subtotal'),
                            'estimasi_margin' => (float) $items->sum('estimasi_margin'),
                        ];
                    })
                    ->sortByDesc('total_net')
                    ->values(),
                'top_produk' => $rows
                    ->groupBy('produk_key')
                    ->map(function ($items) {
                        $first = $items->first();

                        return [
                            'produk_id' => $first['produk_id'],
                            'nama_produk' => $first['nama_produk'],
                            'kategori_produk' => $first['kategori_produk'],
                            'total_item' => $items->count(),
                            'total_qty' => (float) $items->sum('qty'),
                            'total_net' => (float) $items->sum('subtotal'),
                        ];
                    })
                    ->sortByDesc('total_net')
                    ->take(5)
                    ->values(),
            ],
        ]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);

        if (! in_array($format, ['pdf', 'excel'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Format laporan harus pdf atau excel.',
            ], 422);
        }

        $filters = $this->normalizeFilters($request);

        if ($filters['validator']->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Filter laporan tidak valid.',
                'errors' => $filters['validator']->errors(),
            ], 422);
        }

        $filters = $filters['data'];
        $rows = $this->getRows($filters);
        $columns = $this->columns();
        $title = 'DATA LAPORAN OBAT / PRODUK';
        $filename = $this->filename($format, $filters);
        $html = $this->buildHtml($title, $columns, $rows, $filters, $format === 'pdf');

        if ($format === 'excel') {
            return response($html, 200, [
                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
            ]);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    private function normalizeFilters(Request $request): array
    {
        $today = now()->toDateString();
        $tokoId = $request->query('toko_id', $request->header('X-Toko-Id'));
        $tokoId = is_numeric($tokoId) ? (int) $tokoId : null;
        $jenisTransaksi = $request->query('jenis_transaksi');

        if ($jenisTransaksi === null || $jenisTransaksi === '' || $jenisTransaksi === 'all') {
            $jenisTransaksi = null;
        } elseif (is_numeric($jenisTransaksi)) {
            $jenisTransaksi = (int) $jenisTransaksi;
        }

        $data = [
            'tanggal_awal' => $request->query('tanggal_awal', $today),
            'tanggal_akhir' => $request->query('tanggal_akhir', $request->query('tanggal_awal', $today)),
            'toko_id' => $tokoId,
            'jenis_transaksi' => $jenisTransaksi,
        ];

        $validator = Validator::make($data, [
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            'toko_id' => ['nullable', 'integer', 'exists:master_toko,id'],
            'jenis_transaksi' => ['nullable', 'integer', 'in:0,1,2,3,4'],
        ], [
            'tanggal_akhir.after_or_equal' => 'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
            'jenis_transaksi.in' => 'Jenis transaksi harus 0, 1, 2, 3, atau 4.',
        ]);

        return [
            'validator' => $validator,
            'data' => $data,
        ];
    }

    private function publicFilters(array $filters): array
    {
        $toko = null;

        if (! empty($filters['toko_id'])) {
            $toko = DB::table('master_toko')->where('id', $filters['toko_id'])->first(['id', 'nama_toko']);
        }

        return [
            'tanggal_awal' => $filters['tanggal_awal'],
            'tanggal_akhir' => $filters['tanggal_akhir'],
            'toko_id' => $filters['toko_id'],
            'toko_nama' => $toko->nama_toko ?? null,
            'tanggal_berdasarkan' => 'Tanggal lunas invoice',
            'jenis_transaksi' => $filters['jenis_transaksi'],
            'jenis_transaksi_label' => $this->jenisTransaksiLabel($filters['jenis_transaksi']),
        ];
    }

    private function getRows(array $filters)
    {
        $tanggalSql = 'COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)';

        $metodeAgg = DB::table('pembayaran_invoice_metode')
            ->selectRaw("\n                pembayaran_id,\n                GROUP_CONCAT(\n                    CONCAT(COALESCE(metode_bayar_nama, '-'), '::', COALESCE(nominal_dialokasikan, 0))\n                    ORDER BY sort_order, id\n                    SEPARATOR '||'\n                ) as metode_bayar_raw,\n                SUM(COALESCE(nominal_dialokasikan, 0)) as total_metode\n            ")
            ->where('status', 1)
            ->where('is_delete', 0)
            ->groupBy('pembayaran_id');

        $query = DB::table('pembayaran_invoice_item as pii')
            ->join('pembayaran_invoice as pi', 'pi.id', '=', 'pii.pembayaran_id')
            ->leftJoin('registrasi_kunjungan as rk', 'rk.id', '=', 'pi.registrasi_id')
            ->leftJoin('master_toko as toko', 'toko.id', '=', 'pi.toko_id')
            ->leftJoin('pasien as pasien', 'pasien.id', '=', 'pi.pasien_id')
            ->leftJoin('master_produk as produk', 'produk.id', '=', 'pii.produk_id')
            ->leftJoin('master_produk_toko as produk_toko', 'produk_toko.id', '=', 'pii.produk_toko_id')
            ->leftJoin('master_kategori_produk as kategori', 'kategori.id', '=', 'produk.kategori_produk_id')
            ->leftJoin('master_golongan_produk as golongan', 'golongan.id', '=', 'produk.golongan_produk_id')
            ->leftJoin('master_satuan as satuan', 'satuan.id', '=', 'produk.satuan_id')
            ->leftJoin('master_tempat_produk as tempat', function ($join) {
                $join->on('tempat.id', '=', DB::raw('COALESCE(pii.tempat_produk_id, produk.tempat_produk_id)'));
            })
            ->leftJoin('master_supplier as supplier', 'supplier.id', '=', 'produk_toko.supplier_id')
            ->leftJoin('master_jenis_transaksi as jt', 'jt.id', '=', 'pii.jenis_transaksi')
            ->leftJoin('master_karyawan as dokter', function ($join) {
                $join->on('dokter.id', '=', DB::raw('COALESCE(pii.dokter_id, pi.dokter_id, pi.referensi_dokter_id)'));
            })
            ->leftJoin('farmasi_antrian_resep as resep', 'resep.pembayaran_id', '=', 'pi.id')
            ->leftJoin('master_karyawan as apoteker', 'apoteker.id', '=', 'resep.petugas_karyawan_id')
            ->leftJoin('master_jabatan as jabatan_apoteker', 'jabatan_apoteker.id', '=', 'apoteker.jabatan_id')
            ->leftJoinSub($metodeAgg, 'metode', function ($join) {
                $join->on('metode.pembayaran_id', '=', 'pi.id');
            })
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->where('pii.item_type', 3)
            ->where('pii.status', 1)
            ->where('pii.is_delete', 0)
            ->whereIn('pii.jenis_transaksi', self::ALLOWED_JENIS_TRANSAKSI)
            ->whereRaw("DATE({$tanggalSql}) BETWEEN ? AND ?", [
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
            ]);

        if (! empty($filters['toko_id'])) {
            $query->where('pi.toko_id', (int) $filters['toko_id']);
        }

        if ($filters['jenis_transaksi'] !== null) {
            $query->where('pii.jenis_transaksi', (int) $filters['jenis_transaksi']);
        }

        return $query
            ->orderByRaw("DATE({$tanggalSql}) asc")
            ->orderBy('pi.no_invoice')
            ->orderBy('pii.id')
            ->get([
                'pii.id',
                'pii.pembayaran_id',
                'pii.registrasi_id',
                'pii.source_type',
                'pii.source_detail_id',
                'pii.jenis_transaksi',
                'pii.produk_id',
                'pii.produk_toko_id',
                'pii.tempat_produk_id',
                'pii.stock_reservasi_id',
                'pii.nama_item',
                'pii.satuan',
                'pii.qty',
                'pii.harga',
                'pii.diskon_tipe',
                'pii.diskon_nilai',
                'pii.diskon_amount',
                'pii.diskon_referral',
                'pii.diskon_subtotal_amount',
                'pii.subtotal_before_diskon_subtotal',
                'pii.subtotal_after_diskon_subtotal',
                'pii.subtotal',
                'pii.dokter_id',
                'pii.is_saran_dokter',
                'pii.frekuensi',
                'pii.waktu_pakai',
                'pii.instruksi_pemakaian',
                'pii.kode_accurate_snapshot',
                'pii.nama_accurate_snapshot',
                'pii.accurate_source_type',
                'pii.accurate_source_code',
                'pii.is_send_to_accurate',
                'pii.send_when_zero',
                'pi.no_invoice',
                'pi.kode_registrasi',
                'pi.invoice_suffix',
                'pi.toko_id',
                'toko.nama_toko',
                'pi.pasien_id',
                'pasien.no_rm',
                'pasien.nama as pasien_nama',
                DB::raw("DATE({$tanggalSql}) as tanggal_lunas"),
                'pi.tanggal_invoice',
                'pi.tanggal_lunas as tanggal_lunas_raw',
                'pi.sumber_kedatangan',
                'pi.member_no',
                'pi.member_tier_nama',
                'pi.catatan',
                'produk.kode_accurate as kode_accurate_master',
                'produk.nama as produk_nama_master',
                'produk.is_obat_resep',
                'produk.is_obat_bebas',
                'produk_toko.harga_jual as harga_jual_master',
                'produk_toko.harga_beli as harga_beli_master',
                'produk_toko.stok_minimum',
                'produk_toko.fee_dokter',
                'produk_toko.fee_beautician',
                'kategori.nama_kategori_produk',
                'golongan.kode_golongan_produk',
                'golongan.nama_golongan_produk',
                'satuan.nama_satuan',
                'tempat.nama_tempat_produk',
                'supplier.kode as supplier_kode',
                'supplier.nama as supplier_nama',
                'jt.kode_jenis_transaksi',
                'jt.nama_jenis_transaksi',
                'dokter.nama as dokter_nama',
                'resep.status as resep_status',
                'resep.started_at as resep_started_at',
                'resep.finished_at as resep_finished_at',
                'resep.petugas_karyawan_id as apoteker_id',
                DB::raw('COALESCE(apoteker.nama, resep.petugas_nama_snapshot) as apoteker_nama'),
                DB::raw('COALESCE(jabatan_apoteker.nama_jabatan, resep.petugas_jabatan_snapshot) as apoteker_jabatan'),
                'metode.metode_bayar_raw',
                'rk.channel_konsultasi',
                'rk.is_pembelian_online',
            ])
            ->map(function ($row, $index) {
                $qty = (float) $row->qty;
                $harga = (float) $row->harga;
                $hargaBeli = (float) $row->harga_beli_master;
                $gross = $qty * $harga;
                $hpp = $qty * $hargaBeli;
                $totalDiskon = (float) $row->diskon_amount
                    + (float) $row->diskon_referral
                    + (float) $row->diskon_subtotal_amount;
                $jenisTransaksiId = (int) $row->jenis_transaksi;
                $namaProduk = $row->produk_nama_master ?: $row->nama_item;
                $kategoriProduk = $row->nama_kategori_produk ?: '-';

                return [
                    'no' => $index + 1,
                    'id' => (int) $row->id,
                    'pembayaran_id' => (int) $row->pembayaran_id,
                    'registrasi_id' => (int) $row->registrasi_id,
                    'tanggal_lunas' => $row->tanggal_lunas ? Carbon::parse($row->tanggal_lunas)->format('d/m/Y') : '-',
                    'tanggal_lunas_raw' => $row->tanggal_lunas_raw,
                    'tanggal_invoice' => $row->tanggal_invoice ? Carbon::parse($row->tanggal_invoice)->format('d/m/Y H:i') : '-',
                    'no_invoice' => $row->no_invoice ?: '-',
                    'kode_registrasi' => $row->kode_registrasi ?: '-',
                    'invoice_suffix' => $row->invoice_suffix ?: '-',
                    'toko_id' => $row->toko_id ? (int) $row->toko_id : null,
                    'toko_nama' => $row->nama_toko ?: '-',
                    'pasien_id' => $row->pasien_id ? (int) $row->pasien_id : null,
                    'no_rm' => $row->no_rm ?: '-',
                    'pasien_nama' => $row->pasien_nama ?: '-',
                    'member_no' => $row->member_no ?: '-',
                    'member_tier' => $row->member_tier_nama ?: '-',
                    'produk_id' => $row->produk_id ? (int) $row->produk_id : null,
                    'produk_toko_id' => $row->produk_toko_id ? (int) $row->produk_toko_id : null,
                    'produk_key' => ($row->produk_id ?: 'manual') . '|' . $namaProduk,
                    'kode_accurate' => $row->kode_accurate_snapshot ?: ($row->kode_accurate_master ?: '-'),
                    'nama_produk' => $namaProduk ?: '-',
                    'nama_accurate' => $row->nama_accurate_snapshot ?: '-',
                    'kategori_produk' => $kategoriProduk,
                    'golongan_produk' => $row->nama_golongan_produk ?: ($row->kode_golongan_produk ?: '-'),
                    'jenis_obat' => $this->jenisObatLabel($row->is_obat_resep, $row->is_obat_bebas),
                    'tempat_produk' => $row->nama_tempat_produk ?: '-',
                    'supplier' => trim(($row->supplier_kode ? $row->supplier_kode . ' - ' : '') . ($row->supplier_nama ?: '')) ?: '-',
                    'satuan' => $row->satuan ?: ($row->nama_satuan ?: 'pcs'),
                    'qty' => $qty,
                    'harga' => $harga,
                    'gross_amount' => $gross,
                    'diskon_tipe' => $this->discountTypeLabel($row->diskon_tipe),
                    'diskon_nilai' => (float) $row->diskon_nilai,
                    'diskon_amount' => (float) $row->diskon_amount,
                    'diskon_referral' => (float) $row->diskon_referral,
                    'diskon_subtotal_amount' => (float) $row->diskon_subtotal_amount,
                    'total_diskon' => $totalDiskon,
                    'subtotal_before_diskon_subtotal' => (float) $row->subtotal_before_diskon_subtotal,
                    'subtotal_after_diskon_subtotal' => (float) $row->subtotal_after_diskon_subtotal,
                    'subtotal' => (float) $row->subtotal,
                    'harga_jual_master' => (float) $row->harga_jual_master,
                    'harga_beli_master' => $hargaBeli,
                    'hpp_amount' => $hpp,
                    'estimasi_margin' => (float) $row->subtotal - $hpp,
                    'stok_minimum' => (float) $row->stok_minimum,
                    'fee_dokter' => (float) $row->fee_dokter,
                    'fee_beautician' => (float) $row->fee_beautician,
                    'dokter' => $row->dokter_nama ?: '-',
                    'is_saran_dokter' => ((int) $row->is_saran_dokter === 1) ? 'Ya' : 'Tidak',
                    'apoteker' => $row->apoteker_nama ?: '-',
                    'apoteker_jabatan' => $row->apoteker_jabatan ?: '-',
                    'status_resep' => $this->resepStatusLabel($row->resep_status),
                    'resep_started_at' => $row->resep_started_at ? Carbon::parse($row->resep_started_at)->format('d/m/Y H:i') : '-',
                    'resep_finished_at' => $row->resep_finished_at ? Carbon::parse($row->resep_finished_at)->format('d/m/Y H:i') : '-',
                    'frekuensi' => $row->frekuensi ?: '-',
                    'waktu_pakai' => $row->waktu_pakai ?: '-',
                    'instruksi_pemakaian' => $row->instruksi_pemakaian ?: '-',
                    'jenis_transaksi_id' => $jenisTransaksiId,
                    'jenis_transaksi_kode' => $row->kode_jenis_transaksi ?: $this->defaultJenisTransaksiKode($jenisTransaksiId),
                    'jenis_transaksi' => $row->nama_jenis_transaksi ?: $this->defaultJenisTransaksiLabel($jenisTransaksiId),
                    'source_type' => $this->sourceTypeLabel($row->source_type),
                    'source_detail_id' => $row->source_detail_id ?: '-',
                    'channel' => $this->channelLabel($row->channel_konsultasi, $row->is_pembelian_online),
                    'sumber_kedatangan' => $row->sumber_kedatangan ?: '-',
                    'metode_bayar' => $this->paymentMethods($row->metode_bayar_raw),
                    'stock_reservasi_id' => $row->stock_reservasi_id ?: '-',
                    'accurate_source' => trim(($row->accurate_source_type ?: '') . ' ' . ($row->accurate_source_code ?: '')) ?: '-',
                    'send_to_accurate' => ((int) $row->is_send_to_accurate === 1) ? 'Ya' : 'Tidak',
                    'send_when_zero' => ((int) $row->send_when_zero === 1) ? 'Ya' : 'Tidak',
                    'catatan' => $row->catatan ?: '-',
                ];
            })
            ->values();
    }

    private function columns(): array
    {
        return [
            ['key' => 'no', 'label' => 'No', 'type' => 'number'],
            ['key' => 'tanggal_lunas', 'label' => 'Tanggal'],
            ['key' => 'no_invoice', 'label' => 'No Invoice'],
            ['key' => 'kode_registrasi', 'label' => 'No Registrasi'],
            ['key' => 'toko_nama', 'label' => 'Cabang'],
            ['key' => 'jenis_transaksi', 'label' => 'Jenis Transaksi'],
            ['key' => 'source_type', 'label' => 'Sumber Item'],
            ['key' => 'channel', 'label' => 'Channel'],
            ['key' => 'no_rm', 'label' => 'No RM'],
            ['key' => 'pasien_nama', 'label' => 'Pasien'],
            ['key' => 'member_no', 'label' => 'No Member'],
            ['key' => 'member_tier', 'label' => 'Tier Member'],
            ['key' => 'kode_accurate', 'label' => 'Kode Accurate'],
            ['key' => 'nama_produk', 'label' => 'Obat / Produk'],
            ['key' => 'nama_accurate', 'label' => 'Nama Accurate'],
            ['key' => 'kategori_produk', 'label' => 'Kategori Produk'],
            ['key' => 'golongan_produk', 'label' => 'Golongan Produk'],
            ['key' => 'jenis_obat', 'label' => 'Jenis Obat'],
            ['key' => 'tempat_produk', 'label' => 'Tempat Produk'],
            ['key' => 'supplier', 'label' => 'Supplier'],
            ['key' => 'dokter', 'label' => 'Dokter'],
            ['key' => 'is_saran_dokter', 'label' => 'Saran Dokter'],
            ['key' => 'apoteker', 'label' => 'Apoteker'],
            ['key' => 'apoteker_jabatan', 'label' => 'Jabatan Apoteker'],
            ['key' => 'status_resep', 'label' => 'Status Resep'],
            ['key' => 'resep_finished_at', 'label' => 'Resep Selesai'],
            ['key' => 'qty', 'label' => 'Qty', 'type' => 'number'],
            ['key' => 'satuan', 'label' => 'Satuan'],
            ['key' => 'harga', 'label' => 'Harga', 'type' => 'currency'],
            ['key' => 'gross_amount', 'label' => 'Gross', 'type' => 'currency'],
            ['key' => 'diskon_tipe', 'label' => 'Tipe Diskon'],
            ['key' => 'diskon_nilai', 'label' => 'Nilai Diskon', 'type' => 'number'],
            ['key' => 'diskon_amount', 'label' => 'Diskon Item', 'type' => 'currency'],
            ['key' => 'diskon_referral', 'label' => 'Diskon Referral', 'type' => 'currency'],
            ['key' => 'diskon_subtotal_amount', 'label' => 'Diskon Subtotal', 'type' => 'currency'],
            ['key' => 'total_diskon', 'label' => 'Total Diskon', 'type' => 'currency'],
            ['key' => 'subtotal', 'label' => 'Subtotal Net', 'type' => 'currency'],
            ['key' => 'harga_beli_master', 'label' => 'Harga Beli Master', 'type' => 'currency'],
            ['key' => 'hpp_amount', 'label' => 'Estimasi HPP', 'type' => 'currency'],
            ['key' => 'estimasi_margin', 'label' => 'Estimasi Margin', 'type' => 'currency'],
            ['key' => 'fee_dokter', 'label' => 'Fee Dokter', 'type' => 'currency'],
            ['key' => 'fee_beautician', 'label' => 'Fee Beautician', 'type' => 'currency'],
            ['key' => 'frekuensi', 'label' => 'Frekuensi'],
            ['key' => 'waktu_pakai', 'label' => 'Waktu Pakai'],
            ['key' => 'instruksi_pemakaian', 'label' => 'Instruksi Pemakaian'],
            ['key' => 'sumber_kedatangan', 'label' => 'Sumber Kedatangan'],
            ['key' => 'metode_bayar', 'label' => 'Metode Bayar'],
            ['key' => 'stock_reservasi_id', 'label' => 'ID Reservasi Stok'],
            ['key' => 'send_to_accurate', 'label' => 'Kirim Accurate'],
            ['key' => 'send_when_zero', 'label' => 'Kirim Jika Nol'],
            ['key' => 'catatan', 'label' => 'Catatan Invoice'],
        ];
    }

    private function buildHtml(string $title, array $columns, $rows, array $filters, bool $printable): string
    {
        $publicFilters = $this->publicFilters($filters);
        $period = Carbon::parse($filters['tanggal_awal'])->format('d/m/Y')
            . ' - '
            . Carbon::parse($filters['tanggal_akhir'])->format('d/m/Y');
        $autoPrint = $printable ? '<script>window.addEventListener("load", function () { window.print(); });</script>' : '';

        $thead = collect($columns)->map(function ($column) {
            return '<th>' . e($column['label']) . '</th>';
        })->implode('');

        $tbody = $rows->map(function ($row) use ($columns) {
            $cells = collect($columns)->map(function ($column) use ($row) {
                $type = $column['type'] ?? 'text';
                $value = $row[$column['key']] ?? null;
                $class = in_array($type, ['number', 'currency'], true) ? ' class="num"' : '';

                return '<td' . $class . '>' . e($this->formatValue($value, $type)) . '</td>';
            })->implode('');

            return '<tr>' . $cells . '</tr>';
        })->implode('');

        if ($tbody === '') {
            $tbody = '<tr><td colspan="' . count($columns) . '" class="empty">Tidak ada data obat/produk pada filter ini.</td></tr>';
        }

        return '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>' . e($title) . '</title>
<style>
    body { font-family: Arial, Helvetica, sans-serif; color: #111827; font-size: 11px; margin: 18px; }
    h1 { font-size: 18px; margin: 0 0 10px; }
    .meta { margin-bottom: 14px; color: #374151; line-height: 1.7; }
    .summary { display: flex; gap: 18px; margin: 12px 0 16px; font-size: 12px; font-weight: 700; flex-wrap: wrap; }
    .table-wrap { width: 100%; overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f3f4f6; border: 1px solid #d1d5db; padding: 6px; text-align: left; white-space: nowrap; }
    td { border: 1px solid #d1d5db; padding: 6px; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .empty { text-align: center; color: #6b7280; padding: 20px; }
    @media print { body { margin: 10mm; } @page { size: landscape; } }
</style>
</head>
<body>
<h1>' . e($title) . '</h1>
<div class="meta">
    Periode: <strong>' . e($period) . '</strong><br>
    Berdasarkan: <strong>' . e($publicFilters['tanggal_berdasarkan']) . '</strong><br>
    Jenis transaksi: <strong>' . e($publicFilters['jenis_transaksi_label']) . '</strong><br>
    Cabang: <strong>' . e($publicFilters['toko_nama'] ?: 'Semua cabang / sesuai akses') . '</strong>
</div>
<div class="summary">
    <span>Total Item: ' . e($this->number((float) $rows->count())) . '</span>
    <span>Total Qty: ' . e($this->number((float) $rows->sum('qty'))) . '</span>
    <span>Total Gross: Rp ' . e($this->money((float) $rows->sum('gross_amount'))) . '</span>
    <span>Total Diskon: Rp ' . e($this->money((float) $rows->sum('total_diskon'))) . '</span>
    <span>Total Net: Rp ' . e($this->money((float) $rows->sum('subtotal'))) . '</span>
    <span>Estimasi HPP: Rp ' . e($this->money((float) $rows->sum('hpp_amount'))) . '</span>
    <span>Estimasi Margin: Rp ' . e($this->money((float) $rows->sum('estimasi_margin'))) . '</span>
</div>
<div class="table-wrap">
<table>
<thead><tr>' . $thead . '</tr></thead>
<tbody>' . $tbody . '</tbody>
</table>
</div>
' . $autoPrint . '
</body>
</html>';
    }

    private function filename(string $format, array $filters): string
    {
        $extension = $format === 'excel' ? 'xls' : 'html';
        $jenisTransaksi = $filters['jenis_transaksi'] === null
            ? 'semua-jenis-transaksi'
            : $this->slug($this->jenisTransaksiLabel($filters['jenis_transaksi']));

        return implode('-', [
            'data',
            'laporan',
            'obat-produk',
            $jenisTransaksi,
            Carbon::parse($filters['tanggal_awal'])->format('Ymd'),
            Carbon::parse($filters['tanggal_akhir'])->format('Ymd'),
        ]) . '.' . $extension;
    }

    private function getJenisTransaksiOptions()
    {
        $rows = DB::table('master_jenis_transaksi')
            ->whereIn('id', self::ALLOWED_JENIS_TRANSAKSI)
            ->where('is_active', 1)
            ->where('is_delete', 0)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'kode_jenis_transaksi', 'nama_jenis_transaksi', 'deskripsi']);

        if ($rows->count() === count(self::ALLOWED_JENIS_TRANSAKSI)) {
            return $rows;
        }

        $existingIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $missing = collect(self::ALLOWED_JENIS_TRANSAKSI)
            ->reject(fn ($id) => in_array($id, $existingIds, true))
            ->map(fn ($id) => (object) [
                'id' => $id,
                'kode_jenis_transaksi' => $this->defaultJenisTransaksiKode($id),
                'nama_jenis_transaksi' => $this->defaultJenisTransaksiLabel($id),
                'deskripsi' => null,
            ]);

        return $rows->concat($missing)->sortBy('id')->values();
    }

    private function jenisTransaksiLabel($jenisTransaksi): string
    {
        if ($jenisTransaksi === null || $jenisTransaksi === '') {
            return 'Semua jenis transaksi';
        }

        $row = DB::table('master_jenis_transaksi')
            ->where('id', (int) $jenisTransaksi)
            ->first(['nama_jenis_transaksi']);

        return $row->nama_jenis_transaksi ?? $this->defaultJenisTransaksiLabel((int) $jenisTransaksi);
    }

    private function defaultJenisTransaksiLabel(int $id): string
    {
        return match ($id) {
            0 => 'Umum',
            1 => 'Endorse / Fasilitas Karyawan',
            2 => 'EliteGlowbal',
            3 => 'Owner',
            4 => 'Deposit',
            default => 'Tidak diketahui',
        };
    }

    private function defaultJenisTransaksiKode(int $id): string
    {
        return match ($id) {
            0 => 'UMUM',
            1 => 'ENDORSE_FASKAR',
            2 => 'ELITEGLOWBAL',
            3 => 'OWNER',
            4 => 'DEPOSIT',
            default => 'UNKNOWN',
        };
    }

    private function sourceTypeLabel($sourceType): string
    {
        return match ((int) $sourceType) {
            1 => 'Registrasi Treatment',
            2 => 'Registrasi Produk',
            3 => 'Resep Dokter',
            4 => 'Konsultasi',
            0 => 'Manual Kasir',
            default => 'Lainnya',
        };
    }

    private function discountTypeLabel($value): string
    {
        return match ((int) $value) {
            1 => 'Persen',
            2 => 'Rupiah',
            default => 'Tidak ada',
        };
    }

    private function jenisObatLabel($isResep, $isBebas): string
    {
        $labels = [];

        if ((int) $isResep === 1) {
            $labels[] = 'Obat Resep';
        }

        if ((int) $isBebas === 1) {
            $labels[] = 'Obat Bebas';
        }

        return $labels ? implode(' / ', $labels) : 'Produk';
    }

    private function resepStatusLabel($status): string
    {
        if ($status === null || $status === '') {
            return '-';
        }

        return match ((int) $status) {
            0 => 'Menunggu',
            1 => 'Diproses',
            2 => 'Selesai',
            9 => 'Dibatalkan',
            default => 'Tidak diketahui',
        };
    }

    private function paymentMethods(?string $raw): string
    {
        if (! $raw) {
            return '-';
        }

        return collect(explode('||', $raw))
            ->map(function ($item) {
                $parts = explode('::', $item, 2);
                $name = trim((string) ($parts[0] ?? '-'));
                $amount = (float) ($parts[1] ?? 0);

                return $name . ' Rp ' . $this->money($amount);
            })
            ->implode(', ');
    }

    private function channelLabel($channelKonsultasi, $isPembelianOnline): string
    {
        if ((int) $isPembelianOnline === 1) {
            return 'Pembelian Online';
        }

        return match ((int) $channelKonsultasi) {
            1 => 'Konsultasi Offline',
            2 => 'Konsultasi Online',
            default => 'Umum',
        };
    }

    private function formatValue($value, string $type): string
    {
        if ($type === 'currency') {
            return 'Rp ' . $this->money((float) $value);
        }

        if ($type === 'number') {
            return $this->number((float) $value);
        }

        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : '-';
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $value), '-'));

        return $slug !== '' ? $slug : 'jenis-transaksi';
    }

    private function money(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private function number(float $value): string
    {
        $decimals = floor($value) == $value ? 0 : 2;

        return number_format($value, $decimals, ',', '.');
    }
}
