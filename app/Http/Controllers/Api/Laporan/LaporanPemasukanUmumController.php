<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LaporanPemasukanUmumController extends Controller
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
        $rows = $this->getRows($filters, null);
        $langsung = $rows->where('jenis_pemasukan_key', 'langsung');
        $booking = $rows->where('jenis_pemasukan_key', 'booking');
        $jenisOptions = $this->getJenisTransaksiOptions();
        $jenisLabels = $jenisOptions->pluck('nama_jenis_transaksi', 'id');

        return response()->json([
            'status' => true,
            'message' => 'Ringkasan laporan pemasukan berhasil diambil.',
            'data' => [
                'filters' => $this->publicFilters($filters),
                'jenis_transaksi_options' => $jenisOptions->values(),
                'total_invoice' => $rows->count(),
                'total_pasien' => $rows->pluck('pasien_id')->filter()->unique()->count(),
                'grand_total' => (float) $rows->sum('grand_total'),
                'total_bayar' => (float) $rows->sum('total_bayar'),
                'total_diskon' => (float) $rows->sum('total_diskon'),
                'langsung' => $this->makeAggregate($langsung),
                'booking' => $this->makeAggregate($booking),
                'by_jenis_transaksi' => collect(self::ALLOWED_JENIS_TRANSAKSI)
                    ->map(function ($id) use ($rows, $jenisLabels) {
                        $items = $rows->where('jenis_transaksi_id', $id);

                        return [
                            'id' => $id,
                            'nama' => $jenisLabels[$id] ?? $this->defaultJenisTransaksiLabel($id),
                            ...$this->makeAggregate($items),
                        ];
                    })
                    ->values(),
            ],
        ]);
    }

    public function export(Request $request, string $jenis)
    {
        $jenis = strtolower($jenis);

        if (! in_array($jenis, ['semua', 'langsung', 'booking'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Jenis laporan harus semua, langsung, atau booking.',
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
        $rows = $this->getRows($filters, $jenis);
        $columns = $this->columns();
        $title = 'DATA LAPORAN PEMASUKAN - ' . strtoupper($this->jenisPemasukanLabel($jenis));
        $filename = $this->filename($jenis, $filters);
        $html = $this->buildHtml($title, $columns, $rows, $filters, $jenis);

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

    private function makeAggregate($rows): array
    {
        return [
            'total_invoice' => $rows->count(),
            'total_pasien' => $rows->pluck('pasien_id')->filter()->unique()->count(),
            'subtotal_produk' => (float) $rows->sum('subtotal_produk'),
            'subtotal_treatment' => (float) $rows->sum('subtotal_treatment'),
            'subtotal_konsultasi' => (float) $rows->sum('subtotal_konsultasi'),
            'total_diskon' => (float) $rows->sum('total_diskon'),
            'grand_total' => (float) $rows->sum('grand_total'),
            'total_bayar' => (float) $rows->sum('total_bayar'),
        ];
    }

    private function getRows(array $filters, ?string $jenisPemasukan)
    {
        $tanggalSql = 'COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)';

        $metodeAgg = DB::table('pembayaran_invoice_metode')
            ->selectRaw("\n                pembayaran_id,\n                GROUP_CONCAT(\n                    CONCAT(COALESCE(metode_bayar_nama, '-'), '::', COALESCE(nominal_dialokasikan, 0))\n                    ORDER BY sort_order, id\n                    SEPARATOR '||'\n                ) as metode_bayar_raw,\n                SUM(COALESCE(nominal_dialokasikan, 0)) as total_metode\n            ")
            ->where('status', 1)
            ->where('is_delete', 0)
            ->groupBy('pembayaran_id');

        $bookingSub = DB::table('antrian as a')
            ->leftJoin('booking_layanan as bl', function ($join) {
                $join->on('bl.id', '=', 'a.source_id')
                    ->where('a.source_type', '=', 'booking');
            })
            ->selectRaw("\n                a.registrasi_id,\n                MAX(a.kode_nomor) as kode_antrian,\n                MAX(bl.booking_code) as booking_code,\n                MAX(bl.appointment_at) as appointment_at,\n                MAX(bl.source) as booking_source\n            ")
            ->where('a.source_type', 'booking')
            ->whereNotNull('a.registrasi_id')
            ->where(function ($q) {
                $q->where('a.is_delete', 0)
                    ->orWhereNull('a.is_delete');
            })
            ->groupBy('a.registrasi_id');

        $query = DB::table('pembayaran_invoice as pi')
            ->leftJoin('registrasi_kunjungan as rk', 'rk.id', '=', 'pi.registrasi_id')
            ->leftJoin('master_toko as mt', 'mt.id', '=', 'pi.toko_id')
            ->leftJoin('pasien as ps', 'ps.id', '=', 'pi.pasien_id')
            ->leftJoin('master_jenis_transaksi as jt', 'jt.id', '=', 'pi.jenis_transaksi')
            ->leftJoinSub($metodeAgg, 'metode', function ($join) {
                $join->on('metode.pembayaran_id', '=', 'pi.id');
            })
            ->leftJoinSub($bookingSub, 'booking', function ($join) {
                $join->on('booking.registrasi_id', '=', 'pi.registrasi_id');
            })
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->whereIn('pi.jenis_transaksi', self::ALLOWED_JENIS_TRANSAKSI)
            ->whereRaw("DATE({$tanggalSql}) BETWEEN ? AND ?", [
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
            ]);

        if ($jenisPemasukan === 'booking') {
            $query->whereNotNull('booking.registrasi_id');
        } elseif ($jenisPemasukan === 'langsung') {
            $query->whereNull('booking.registrasi_id');
        }

        if (! empty($filters['toko_id'])) {
            $query->where('pi.toko_id', (int) $filters['toko_id']);
        }

        if ($filters['jenis_transaksi'] !== null) {
            $query->where('pi.jenis_transaksi', (int) $filters['jenis_transaksi']);
        }

        return $query
            ->orderByRaw("DATE({$tanggalSql}) asc")
            ->orderBy('pi.jenis_transaksi')
            ->orderBy('pi.no_invoice')
            ->get([
                'pi.id',
                'pi.registrasi_id',
                'pi.kode_registrasi',
                'pi.no_invoice',
                'pi.toko_id',
                'mt.nama_toko',
                'pi.pasien_id',
                'ps.no_rm',
                'ps.nama as pasien_nama',
                DB::raw("DATE({$tanggalSql}) as tanggal_lunas"),
                'pi.tanggal_invoice',
                'pi.tanggal_lunas as tanggal_lunas_raw',
                'pi.sumber_kedatangan',
                'pi.jenis_transaksi',
                'jt.kode_jenis_transaksi',
                'jt.nama_jenis_transaksi',
                'pi.subtotal_produk',
                'pi.subtotal_treatment',
                'pi.subtotal_konsultasi',
                'pi.subtotal',
                'pi.total_diskon_item',
                'pi.diskon_subtotal_amount',
                'pi.total_diskon_referral',
                'pi.total_promo',
                'pi.diskon_member_amount',
                'pi.point_redeem_value',
                'pi.grand_total',
                'pi.total_bayar',
                'pi.total_kembalian',
                'pi.catatan',
                'metode.metode_bayar_raw',
                'metode.total_metode',
                'booking.kode_antrian',
                'booking.booking_code',
                'booking.appointment_at',
                'booking.booking_source',
                'rk.channel_konsultasi',
                'rk.is_pembelian_online',
            ])
            ->map(function ($row, $index) {
                $isBooking = ! empty($row->booking_code) || ! empty($row->kode_antrian);
                $totalDiskon = (float) $row->total_diskon_item
                    + (float) $row->diskon_subtotal_amount
                    + (float) $row->total_diskon_referral
                    + (float) $row->total_promo
                    + (float) $row->diskon_member_amount
                    + (float) $row->point_redeem_value;

                return [
                    'no' => $index + 1,
                    'id' => (int) $row->id,
                    'tanggal_lunas' => $row->tanggal_lunas ? Carbon::parse($row->tanggal_lunas)->format('d/m/Y') : '-',
                    'tanggal_lunas_raw' => $row->tanggal_lunas_raw,
                    'no_invoice' => $row->no_invoice,
                    'kode_registrasi' => $row->kode_registrasi,
                    'toko_id' => $row->toko_id,
                    'toko_nama' => $row->nama_toko ?: '-',
                    'pasien_id' => $row->pasien_id ? (int) $row->pasien_id : null,
                    'no_rm' => $row->no_rm ?: '-',
                    'pasien_nama' => $row->pasien_nama ?: '-',
                    'jenis_pemasukan_key' => $isBooking ? 'booking' : 'langsung',
                    'jenis_pemasukan' => $isBooking ? 'Booking' : 'Langsung',
                    'jenis_transaksi_id' => (int) $row->jenis_transaksi,
                    'jenis_transaksi_kode' => $row->kode_jenis_transaksi ?: '-',
                    'jenis_transaksi' => $row->nama_jenis_transaksi ?: $this->defaultJenisTransaksiLabel((int) $row->jenis_transaksi),
                    'kode_booking' => $row->booking_code ?: '-',
                    'kode_antrian' => $row->kode_antrian ?: '-',
                    'jadwal_booking' => $row->appointment_at ? Carbon::parse($row->appointment_at)->format('d/m/Y H:i') : '-',
                    'sumber_booking' => $row->booking_source ?: '-',
                    'sumber_kedatangan' => $row->sumber_kedatangan ?: '-',
                    'channel' => $this->channelLabel($row->channel_konsultasi, $row->is_pembelian_online),
                    'subtotal_produk' => (float) $row->subtotal_produk,
                    'subtotal_treatment' => (float) $row->subtotal_treatment,
                    'subtotal_konsultasi' => (float) $row->subtotal_konsultasi,
                    'subtotal' => (float) $row->subtotal,
                    'total_diskon_item' => (float) $row->total_diskon_item,
                    'diskon_subtotal_amount' => (float) $row->diskon_subtotal_amount,
                    'total_promo' => (float) $row->total_promo,
                    'diskon_member_amount' => (float) $row->diskon_member_amount,
                    'point_redeem_value' => (float) $row->point_redeem_value,
                    'total_diskon' => $totalDiskon,
                    'grand_total' => (float) $row->grand_total,
                    'total_bayar' => (float) $row->total_bayar,
                    'total_kembalian' => (float) $row->total_kembalian,
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
            ['key' => 'jenis_pemasukan', 'label' => 'Jenis Pemasukan'],
            ['key' => 'jenis_transaksi', 'label' => 'Jenis Transaksi'],
            ['key' => 'kode_booking', 'label' => 'Kode Booking'],
            ['key' => 'jadwal_booking', 'label' => 'Jadwal Booking'],
            ['key' => 'no_rm', 'label' => 'No RM'],
            ['key' => 'pasien_nama', 'label' => 'Pasien'],
            ['key' => 'channel', 'label' => 'Channel'],
            ['key' => 'sumber_kedatangan', 'label' => 'Sumber Kedatangan'],
            ['key' => 'subtotal_treatment', 'label' => 'Treatment', 'type' => 'currency'],
            ['key' => 'subtotal_produk', 'label' => 'Produk', 'type' => 'currency'],
            ['key' => 'subtotal_konsultasi', 'label' => 'Konsultasi', 'type' => 'currency'],
            ['key' => 'subtotal', 'label' => 'Subtotal', 'type' => 'currency'],
            ['key' => 'total_diskon', 'label' => 'Total Diskon', 'type' => 'currency'],
            ['key' => 'grand_total', 'label' => 'Grand Total', 'type' => 'currency'],
            ['key' => 'total_bayar', 'label' => 'Total Bayar', 'type' => 'currency'],
            ['key' => 'total_kembalian', 'label' => 'Kembalian', 'type' => 'currency'],
            ['key' => 'metode_bayar', 'label' => 'Metode Bayar'],
            ['key' => 'catatan', 'label' => 'Catatan'],
        ];
    }

    private function buildHtml(string $title, array $columns, $rows, array $filters, string $jenisPemasukan): string
    {
        $publicFilters = $this->publicFilters($filters);
        $period = Carbon::parse($filters['tanggal_awal'])->format('d/m/Y')
            . ' - '
            . Carbon::parse($filters['tanggal_akhir'])->format('d/m/Y');
        $aggregate = $this->makeAggregate($rows);

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
            $tbody = '<tr><td colspan="' . count($columns) . '" class="empty">Tidak ada data pada filter ini.</td></tr>';
        }

        return '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>' . e($title) . '</title>
<style>
    body { font-family: Arial, Helvetica, sans-serif; color: #111827; font-size: 12px; margin: 24px; }
    h1 { font-size: 18px; margin: 0 0 10px; }
    .meta { margin-bottom: 14px; color: #374151; line-height: 1.7; }
    .summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 12px 0 16px; }
    .box { border: 1px solid #d1d5db; padding: 8px; }
    .label { color: #6b7280; font-size: 11px; }
    .value { font-size: 14px; font-weight: 700; margin-top: 4px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f3f4f6; border: 1px solid #d1d5db; padding: 7px; text-align: left; white-space: nowrap; }
    td { border: 1px solid #d1d5db; padding: 7px; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .empty { text-align: center; color: #6b7280; padding: 20px; }
    @media print { body { margin: 10mm; } .summary { grid-template-columns: repeat(4, 1fr); } }
</style>
</head>
<body>
<h1>' . e($title) . '</h1>
<div class="meta">
    Periode: <strong>' . e($period) . '</strong><br>
    Jenis transaksi: <strong>' . e($publicFilters['jenis_transaksi_label']) . '</strong><br>
    Jenis pemasukan: <strong>' . e($this->jenisPemasukanLabel($jenisPemasukan)) . '</strong><br>
    Cabang: <strong>' . e($publicFilters['toko_nama'] ?: 'Semua cabang / sesuai akses') . '</strong>
</div>
<div class="summary">
    <div class="box"><div class="label">Total Invoice</div><div class="value">' . e($this->number((float) $aggregate['total_invoice'])) . '</div></div>
    <div class="box"><div class="label">Total Pasien</div><div class="value">' . e($this->number((float) $aggregate['total_pasien'])) . '</div></div>
    <div class="box"><div class="label">Total Diskon</div><div class="value">Rp ' . e($this->money((float) $aggregate['total_diskon'])) . '</div></div>
    <div class="box"><div class="label">Grand Total</div><div class="value">Rp ' . e($this->money((float) $aggregate['grand_total'])) . '</div></div>
</div>
<table>
<thead><tr>' . $thead . '</tr></thead>
<tbody>' . $tbody . '</tbody>
</table>
<script>window.addEventListener("load", function () { window.print(); });</script>
</body>
</html>';
    }

    private function filename(string $jenisPemasukan, array $filters): string
    {
        $jenisTransaksi = $filters['jenis_transaksi'] === null
            ? 'semua-jenis-transaksi'
            : $this->slug($this->jenisTransaksiLabel($filters['jenis_transaksi']));

        return implode('-', [
            'laporan',
            'pemasukan',
            $jenisPemasukan,
            $jenisTransaksi,
            Carbon::parse($filters['tanggal_awal'])->format('Ymd'),
            Carbon::parse($filters['tanggal_akhir'])->format('Ymd'),
        ]) . '.html';
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

    private function jenisPemasukanLabel(string $jenisPemasukan): string
    {
        return match ($jenisPemasukan) {
            'booking' => 'Booking',
            'langsung' => 'Langsung',
            default => 'Semua pemasukan',
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

        return (string) ($value ?? '-');
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
