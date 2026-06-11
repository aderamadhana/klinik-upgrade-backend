<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LaporanTreatmentController extends Controller
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
            'message' => 'Ringkasan laporan treatment berhasil diambil.',
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
                        ];
                    })
                    ->values(),
                'top_treatment' => $rows
                    ->groupBy('treatment_key')
                    ->map(function ($items) {
                        $first = $items->first();

                        return [
                            'treatment_id' => $first['treatment_id'],
                            'nama_treatment' => $first['nama_treatment'],
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
        $title = 'DATA LAPORAN TREATMENT';
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
            ->leftJoin('master_treatment as treatment', 'treatment.id', '=', 'pii.treatment_id')
            ->leftJoin('master_treatment_toko as treatment_toko', 'treatment_toko.id', '=', 'pii.treatment_toko_id')
            ->leftJoin('master_jenis_transaksi as jt', 'jt.id', '=', 'pii.jenis_transaksi')
            ->leftJoin('master_karyawan as dokter', function ($join) {
                $join->on('dokter.id', '=', DB::raw('COALESCE(pii.dokter_id, pi.dokter_id, pi.referensi_dokter_id)'));
            })
            ->leftJoin('master_karyawan as perawat', 'perawat.id', '=', 'pii.perawat_id')
            ->leftJoinSub($metodeAgg, 'metode', function ($join) {
                $join->on('metode.pembayaran_id', '=', 'pi.id');
            })
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->where('pii.item_type', 2)
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
                'pii.deposit_treatment_id',
                'pii.deposit_claim_id',
                'pii.expired_at',
                'pii.treatment_id',
                'pii.treatment_toko_id',
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
                'pii.is_saran_dokter',
                'pii.created_at as item_created_at',
                'pi.no_invoice',
                'pi.kode_registrasi',
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
                'treatment.kode_accurate',
                'treatment.nama as treatment_nama_master',
                'treatment.kategori_sales',
                'treatment.waktu as durasi_treatment',
                'treatment_toko.tarif as tarif_master',
                'treatment_toko.harga_terendah',
                'treatment_toko.biaya_modal',
                'treatment_toko.tarif_dokter',
                'treatment_toko.tarif_beautician',
                'treatment_toko.insentif_use',
                'jt.kode_jenis_transaksi',
                'jt.nama_jenis_transaksi',
                'dokter.nama as dokter_nama',
                'perawat.nama as perawat_nama',
                'metode.metode_bayar_raw',
                'rk.channel_konsultasi',
                'rk.is_pembelian_online',
            ])
            ->map(function ($row, $index) {
                $qty = (float) $row->qty;
                $harga = (float) $row->harga;
                $gross = $qty * $harga;
                $totalDiskon = (float) $row->diskon_amount
                    + (float) $row->diskon_referral
                    + (float) $row->diskon_subtotal_amount;
                $jenisTransaksiId = (int) $row->jenis_transaksi;
                $namaTreatment = $row->treatment_nama_master ?: $row->nama_item;

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
                    'toko_id' => $row->toko_id ? (int) $row->toko_id : null,
                    'toko_nama' => $row->nama_toko ?: '-',
                    'pasien_id' => $row->pasien_id ? (int) $row->pasien_id : null,
                    'no_rm' => $row->no_rm ?: '-',
                    'pasien_nama' => $row->pasien_nama ?: '-',
                    'member_no' => $row->member_no ?: '-',
                    'member_tier' => $row->member_tier_nama ?: '-',
                    'treatment_id' => $row->treatment_id ? (int) $row->treatment_id : null,
                    'treatment_key' => ($row->treatment_id ?: 'manual') . '|' . $namaTreatment,
                    'treatment_toko_id' => $row->treatment_toko_id ? (int) $row->treatment_toko_id : null,
                    'kode_accurate' => $row->kode_accurate ?: '-',
                    'nama_treatment' => $namaTreatment ?: '-',
                    'kategori_sales' => $row->kategori_sales ?: '-',
                    'durasi_treatment' => $row->durasi_treatment ? ((int) $row->durasi_treatment . ' menit') : '-',
                    'satuan' => $row->satuan ?: 'Treatment',
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
                    'tarif_master' => (float) $row->tarif_master,
                    'harga_terendah' => (float) $row->harga_terendah,
                    'biaya_modal' => (float) $row->biaya_modal,
                    'tarif_dokter' => (float) $row->tarif_dokter,
                    'tarif_beautician' => (float) $row->tarif_beautician,
                    'insentif_use' => $row->insentif_use ?: '-',
                    'dokter' => $row->dokter_nama ?: '-',
                    'perawat' => $row->perawat_nama ?: '-',
                    'is_saran_dokter' => ((int) $row->is_saran_dokter === 1) ? 'Ya' : 'Tidak',
                    'jenis_transaksi_id' => $jenisTransaksiId,
                    'jenis_transaksi_kode' => $row->kode_jenis_transaksi ?: $this->defaultJenisTransaksiKode($jenisTransaksiId),
                    'jenis_transaksi' => $row->nama_jenis_transaksi ?: $this->defaultJenisTransaksiLabel($jenisTransaksiId),
                    'tipe_treatment' => $this->treatmentTransactionType($jenisTransaksiId, $row->deposit_treatment_id, $row->deposit_claim_id),
                    'expired_deposit' => $row->expired_at ? Carbon::parse($row->expired_at)->format('d/m/Y') : '-',
                    'source_type' => $this->sourceTypeLabel($row->source_type),
                    'source_detail_id' => $row->source_detail_id ?: '-',
                    'channel' => $this->channelLabel($row->channel_konsultasi, $row->is_pembelian_online),
                    'sumber_kedatangan' => $row->sumber_kedatangan ?: '-',
                    'metode_bayar' => $this->paymentMethods($row->metode_bayar_raw),
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
            ['key' => 'tipe_treatment', 'label' => 'Tipe Treatment'],
            ['key' => 'no_rm', 'label' => 'No RM'],
            ['key' => 'pasien_nama', 'label' => 'Pasien'],
            ['key' => 'member_no', 'label' => 'No Member'],
            ['key' => 'member_tier', 'label' => 'Tier Member'],
            ['key' => 'kode_accurate', 'label' => 'Kode Accurate'],
            ['key' => 'nama_treatment', 'label' => 'Treatment'],
            ['key' => 'kategori_sales', 'label' => 'Kategori Sales'],
            ['key' => 'durasi_treatment', 'label' => 'Durasi'],
            ['key' => 'source_type', 'label' => 'Sumber Item'],
            ['key' => 'channel', 'label' => 'Channel'],
            ['key' => 'dokter', 'label' => 'Dokter'],
            ['key' => 'perawat', 'label' => 'Nurse/Beautician'],
            ['key' => 'is_saran_dokter', 'label' => 'Saran Dokter'],
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
            ['key' => 'tarif_master', 'label' => 'Tarif Master', 'type' => 'currency'],
            ['key' => 'harga_terendah', 'label' => 'Harga Terendah', 'type' => 'currency'],
            ['key' => 'biaya_modal', 'label' => 'Biaya Modal', 'type' => 'currency'],
            ['key' => 'tarif_dokter', 'label' => 'Tarif Dokter', 'type' => 'currency'],
            ['key' => 'tarif_beautician', 'label' => 'Tarif Beautician', 'type' => 'currency'],
            ['key' => 'insentif_use', 'label' => 'Skema Insentif'],
            ['key' => 'expired_deposit', 'label' => 'Expired Deposit'],
            ['key' => 'sumber_kedatangan', 'label' => 'Sumber Kedatangan'],
            ['key' => 'metode_bayar', 'label' => 'Metode Bayar'],
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
            $tbody = '<tr><td colspan="' . count($columns) . '" class="empty">Tidak ada data treatment pada filter ini.</td></tr>';
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
            'treatment',
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

    private function treatmentTransactionType(int $jenisTransaksi, $depositTreatmentId, $depositClaimId): string
    {
        if (! empty($depositClaimId)) {
            return 'Claim Deposit';
        }

        if ($jenisTransaksi === 4 || ! empty($depositTreatmentId)) {
            return 'Pembelian Deposit Treatment';
        }

        return 'Treatment Umum';
    }

    private function sourceTypeLabel($sourceType): string
    {
        return match ((int) $sourceType) {
            1 => 'Registrasi Treatment',
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
