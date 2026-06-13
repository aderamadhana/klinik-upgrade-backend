<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanPemasukanUmumExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class LaporanPemasukanUmumController extends Controller
{
    private const ALLOWED_JENIS_TRANSAKSI = [0, 1, 2, 3, 4];

    public function __construct(
        private readonly LaporanPemasukanUmumExportService $exportService
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        $normalized = $this->normalizeFilters($request);

        if ($normalized['validator']->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Filter laporan tidak valid.',
                'errors' => $normalized['validator']->errors(),
            ], 422);
        }

        $filters = $normalized['data'];
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
                    ->map(function (int $id) use ($rows, $jenisLabels): array {
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

    public function export(Request $request, string $jenis): Response
    {
        $jenis = strtolower($jenis);
        $format = strtolower((string) $request->query('format', 'pdf'));

        if (! in_array($jenis, ['semua', 'langsung', 'booking'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Jenis laporan harus semua, langsung, atau booking.',
            ], 422);
        }

        if (! in_array($format, ['pdf', 'excel'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Format export harus pdf atau excel.',
            ], 422);
        }

        $normalized = $this->normalizeFilters($request);

        if ($normalized['validator']->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Filter laporan tidak valid.',
                'errors' => $normalized['validator']->errors(),
            ], 422);
        }

        $filters = $normalized['data'];
        $rows = $this->getRows($filters, $jenis);
        $report = $this->buildReport($rows, $filters, $jenis);

        return $format === 'excel'
            ? $this->exportService->excel($report)
            : $this->exportService->pdf($report);
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
            'tanggal_akhir' => $request->query(
                'tanggal_akhir',
                $request->query('tanggal_awal', $today)
            ),
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
            $toko = DB::table('master_toko')
                ->where('id', $filters['toko_id'])
                ->first(['id', 'nama_toko']);
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

    private function makeAggregate(Collection $rows): array
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

    private function getRows(array $filters, ?string $jenisPemasukan): Collection
    {
        $tanggalSql = 'COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)';

        $bookingSub = DB::table('antrian as a')
            ->leftJoin('booking_layanan as bl', function ($join): void {
                $join->on('bl.id', '=', 'a.source_id')
                    ->where('a.source_type', '=', 'booking');
            })
            ->selectRaw('a.registrasi_id, MAX(a.kode_nomor) as kode_antrian, MAX(bl.booking_code) as booking_code')
            ->where('a.source_type', 'booking')
            ->whereNotNull('a.registrasi_id')
            ->where(function ($query): void {
                $query->where('a.is_delete', 0)
                    ->orWhereNull('a.is_delete');
            })
            ->groupBy('a.registrasi_id');

        $query = DB::table('pembayaran_invoice as pi')
            ->leftJoin('master_toko as mt', 'mt.id', '=', 'pi.toko_id')
            ->leftJoin('pasien as ps', 'ps.id', '=', 'pi.pasien_id')
            ->leftJoin('master_jenis_transaksi as jt', 'jt.id', '=', 'pi.jenis_transaksi')
            ->leftJoinSub($bookingSub, 'booking', function ($join): void {
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
                'pi.no_invoice',
                'pi.toko_id',
                'mt.nama_toko',
                'pi.pasien_id',
                'ps.nama as pasien_nama',
                DB::raw("DATE({$tanggalSql}) as tanggal_lunas"),
                'pi.jenis_transaksi',
                'jt.nama_jenis_transaksi',
                'pi.is_premier',
                'pi.subtotal_produk',
                'pi.subtotal_treatment',
                'pi.subtotal_konsultasi',
                'pi.total_diskon_item',
                'pi.diskon_subtotal_amount',
                'pi.total_diskon_referral',
                'pi.total_promo',
                'pi.diskon_member_amount',
                'pi.point_redeem_value',
                'pi.grand_total',
                'pi.total_bayar',
                'booking.registrasi_id as booking_registrasi_id',
                'booking.kode_antrian',
                'booking.booking_code',
            ])
            ->map(function (object $row): array {
                $isBooking = ! empty($row->booking_registrasi_id)
                    || ! empty($row->booking_code)
                    || ! empty($row->kode_antrian);

                $totalDiskon = (float) $row->total_diskon_item
                    + (float) $row->diskon_subtotal_amount
                    + (float) $row->total_diskon_referral
                    + (float) $row->total_promo
                    + (float) $row->diskon_member_amount
                    + (float) $row->point_redeem_value;

                return [
                    'id' => (int) $row->id,
                    'registrasi_id' => (int) $row->registrasi_id,
                    'no_invoice' => (string) $row->no_invoice,
                    'tanggal_lunas' => (string) $row->tanggal_lunas,
                    'toko_id' => (int) $row->toko_id,
                    'toko_nama' => $row->nama_toko ?: '-',
                    'pasien_id' => $row->pasien_id ? (int) $row->pasien_id : null,
                    'pasien_nama' => $row->pasien_nama ?: '-',
                    'jenis_pemasukan_key' => $isBooking ? 'booking' : 'langsung',
                    'jenis_pemasukan' => $isBooking ? 'Booking' : 'Langsung',
                    'jenis_transaksi_id' => (int) $row->jenis_transaksi,
                    'jenis_transaksi' => $row->nama_jenis_transaksi
                        ?: $this->defaultJenisTransaksiLabel((int) $row->jenis_transaksi),
                    'is_premier' => (int) $row->is_premier === 1,
                    'subtotal_produk' => (float) $row->subtotal_produk,
                    'subtotal_treatment' => (float) $row->subtotal_treatment,
                    'subtotal_konsultasi' => (float) $row->subtotal_konsultasi,
                    'total_diskon_item' => (float) $row->total_diskon_item,
                    'diskon_subtotal_amount' => (float) $row->diskon_subtotal_amount,
                    'total_diskon_referral' => (float) $row->total_diskon_referral,
                    'total_promo' => (float) $row->total_promo,
                    'diskon_member_amount' => (float) $row->diskon_member_amount,
                    'point_redeem_value' => (float) $row->point_redeem_value,
                    'total_diskon' => $totalDiskon,
                    'grand_total' => (float) $row->grand_total,
                    'total_bayar' => (float) $row->total_bayar,
                ];
            })
            ->values();
    }

    private function buildReport(Collection $rows, array $filters, string $jenisPemasukan): array
    {
        $invoiceIds = $rows->pluck('id')->map(fn ($id): int => (int) $id)->values();
        $regular = $this->emptySalesGroup();
        $premier = $this->emptySalesGroup();
        $invoicePremierMap = $rows->pluck('is_premier', 'id');

        if ($invoiceIds->isNotEmpty()) {
            DB::table('pembayaran_invoice_item')
                ->whereIn('pembayaran_id', $invoiceIds->all())
                ->where('status', 1)
                ->where('is_delete', 0)
                ->whereIn('item_type', [1, 2, 3, 4])
                ->get([
                    'pembayaran_id',
                    'item_type',
                    'qty',
                    'harga',
                    'diskon_amount',
                    'diskon_referral',
                    'subtotal_before_diskon_subtotal',
                ])
                ->each(function (object $item) use (&$regular, &$premier, $invoicePremierMap): void {
                    $group =& $regular;
                    if ((bool) ($invoicePremierMap[(int) $item->pembayaran_id] ?? false)) {
                        $group =& $premier;
                    }

                    $category = (int) $item->item_type === 3 ? 'produk' : 'treatment';
                    $gross = round((float) $item->qty * (float) $item->harga, 2);
                    $netBeforeSubtotalDiscount = (float) $item->subtotal_before_diskon_subtotal;

                    if ($netBeforeSubtotalDiscount < 0) {
                        $netBeforeSubtotalDiscount = 0.0;
                    }

                    if (
                        $netBeforeSubtotalDiscount === 0.0
                        && $gross > 0
                        && ((float) $item->diskon_amount + (float) $item->diskon_referral) < $gross
                    ) {
                        $netBeforeSubtotalDiscount = max(
                            0,
                            $gross - (float) $item->diskon_amount - (float) $item->diskon_referral
                        );
                    }

                    $discount = max(0, $gross - $netBeforeSubtotalDiscount);

                    $group["penjualan_{$category}"] += $gross;
                    $group["diskon_{$category}"] += $discount;
                    $group["total_penjualan_{$category}"] += $netBeforeSubtotalDiscount;
                });
        }

        $regular['total_penjualan'] = $regular['total_penjualan_produk']
            + $regular['total_penjualan_treatment'];
        $premier['total_penjualan'] = $premier['total_penjualan_produk']
            + $premier['total_penjualan_treatment'];

        foreach ($rows as $row) {
            $group =& $regular;
            if ((bool) $row['is_premier']) {
                $group =& $premier;
            }

            $group['total_diskon_subtotal'] += $this->invoiceLevelDiscount($row);
            $group['total_pendapatan'] += (float) $row['grand_total'];
            $group['total_invoice']++;
        }

        $paymentMethods = $this->getPaymentMethods($invoiceIds);
        $publicFilters = $this->publicFilters($filters);
        $jenisLabel = $publicFilters['jenis_transaksi_label'];
        $title = $this->reportTitle($filters['jenis_transaksi']);

        return [
            'title' => $title,
            'period_label' => $this->periodLabel($filters['tanggal_awal'], $filters['tanggal_akhir']),
            'branch_label' => $publicFilters['toko_nama'] ?: 'Semua cabang / sesuai akses',
            'jenis_pemasukan_label' => $this->jenisPemasukanLabel($jenisPemasukan),
            'jenis_transaksi_label' => $jenisLabel,
            'regular' => $regular,
            'premier' => $premier,
            'total_diskon_subtotal' => $regular['total_diskon_subtotal']
                + $premier['total_diskon_subtotal'],
            'total_pendapatan_all' => $regular['total_pendapatan']
                + $premier['total_pendapatan'],
            'payment_methods' => $paymentMethods->values()->all(),
            'total_cash' => (float) $paymentMethods
                ->filter(fn (array $item): bool => in_array($item['nama_key'], ['CASH', 'TUNAI'], true))
                ->sum('nominal'),
            'total_non_cash' => (float) $paymentMethods
                ->reject(fn (array $item): bool => in_array($item['nama_key'], ['CASH', 'TUNAI'], true))
                ->sum('nominal'),
            'total_invoice' => $rows->count(),
            'generated_at' => now()->format('d/m/Y H:i:s'),
            'filename_base' => $this->filenameBase($jenisPemasukan, $filters),
        ];
    }

    private function getPaymentMethods(Collection $invoiceIds): Collection
    {
        if ($invoiceIds->isEmpty()) {
            return collect();
        }

        return DB::table('pembayaran_invoice_metode as pim')
            ->whereIn('pim.pembayaran_id', $invoiceIds->all())
            ->where('pim.status', 1)
            ->where('pim.is_delete', 0)
            ->selectRaw(
                'UPPER(TRIM(pim.metode_bayar_nama)) as nama_key, '
                . 'MIN(pim.metode_bayar_nama) as nama, '
                . 'MIN(pim.sort_order) as urutan, '
                . 'SUM(COALESCE(pim.nominal_dialokasikan, 0)) as nominal'
            )
            ->groupByRaw('UPPER(TRIM(pim.metode_bayar_nama))')
            ->orderBy('urutan')
            ->orderBy('nama')
            ->get()
            ->map(fn (object $row): array => [
                'nama_key' => (string) $row->nama_key,
                'nama' => strtoupper((string) $row->nama),
                'nominal' => (float) $row->nominal,
            ]);
    }

    private function invoiceLevelDiscount(array $row): float
    {
        return (float) $row['diskon_subtotal_amount']
            + (float) $row['total_promo']
            + (float) $row['diskon_member_amount']
            + (float) $row['point_redeem_value'];
    }

    private function emptySalesGroup(): array
    {
        return [
            'penjualan_produk' => 0.0,
            'diskon_produk' => 0.0,
            'total_penjualan_produk' => 0.0,
            'penjualan_treatment' => 0.0,
            'diskon_treatment' => 0.0,
            'total_penjualan_treatment' => 0.0,
            'total_penjualan' => 0.0,
            'total_diskon_subtotal' => 0.0,
            'total_pendapatan' => 0.0,
            'total_invoice' => 0,
        ];
    }

    private function getJenisTransaksiOptions(): Collection
    {
        $rows = DB::table('master_jenis_transaksi')
            ->whereIn('id', self::ALLOWED_JENIS_TRANSAKSI)
            ->where('is_active', 1)
            ->where('is_delete', 0)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id',
                'kode_jenis_transaksi',
                'nama_jenis_transaksi',
                'deskripsi',
            ]);

        if ($rows->count() === count(self::ALLOWED_JENIS_TRANSAKSI)) {
            return $rows;
        }

        $existingIds = $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $missing = collect(self::ALLOWED_JENIS_TRANSAKSI)
            ->reject(fn (int $id): bool => in_array($id, $existingIds, true))
            ->map(fn (int $id): object => (object) [
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

        return $row->nama_jenis_transaksi
            ?? $this->defaultJenisTransaksiLabel((int) $jenisTransaksi);
    }

    private function reportTitle(?int $jenisTransaksi): string
    {
        return match ($jenisTransaksi) {
            0 => 'Laporan Transaksi Customer Umum',
            1 => 'Laporan Transaksi Endorse / Fasilitas Karyawan',
            2 => 'Laporan Transaksi EliteGlowbal',
            3 => 'Laporan Transaksi Owner',
            4 => 'Laporan Transaksi Deposit',
            default => 'Laporan Transaksi Semua Jenis',
        };
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

    private function jenisPemasukanLabel(string $jenisPemasukan): string
    {
        return match ($jenisPemasukan) {
            'booking' => 'Pemasukan Booking',
            'langsung' => 'Pemasukan Langsung',
            default => 'Semua Pemasukan',
        };
    }

    private function periodLabel(string $start, string $end): string
    {
        return $this->indonesianDate($start) . ' s/d ' . $this->indonesianDate($end);
    }

    private function indonesianDate(string $date): string
    {
        $months = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        $value = Carbon::parse($date);

        return $value->day . ' ' . $months[$value->month] . ' ' . $value->year;
    }

    private function filenameBase(string $jenisPemasukan, array $filters): string
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
        ]);
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?: 'laporan';

        return trim($value, '-');
    }
}
