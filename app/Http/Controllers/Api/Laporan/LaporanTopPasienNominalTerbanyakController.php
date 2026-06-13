<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanTopPasienNominalTerbanyakExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class LaporanTopPasienNominalTerbanyakController extends Controller
{
    private const ALLOWED_JENIS_TRANSAKSI = [0, 1, 2, 3, 4];

    public function __construct(
        private readonly LaporanTopPasienNominalTerbanyakExportService $exportService
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
        $rows = $this->getRows($filters);
        $jenisOptions = $this->getJenisTransaksiOptions();

        return response()->json([
            'status' => true,
            'message' => 'Data top pasien nominal terbanyak berhasil diambil.',
            'data' => [
                'filters' => $this->publicFilters($filters),
                'jenis_transaksi_options' => $jenisOptions->values(),
                'nominal_range_options' => $this->nominalRangeOptions(),
                'total_pasien' => $rows->count(),
                'total_invoice' => (int) $rows->sum('total_invoice'),
                'total_hari_transaksi' => (int) $rows->sum('total_hari_transaksi'),
                'total_nominal' => (float) $rows->sum('total_nominal'),
                'total_treatment' => (float) $rows->sum('total_treatment'),
                'total_produk' => (float) $rows->sum('total_produk'),
                'total_konsultasi' => (float) $rows->sum('total_konsultasi'),
                'rata_nominal_per_pasien' => $rows->count() > 0
                    ? round((float) $rows->sum('total_nominal') / $rows->count(), 2)
                    : 0,
                'rows' => $rows->values(),
                'top_pasien' => $rows->first(),
            ],
        ]);
    }

    public function export(Request $request, string $format): Response
    {
        $format = strtolower($format);

        if (! in_array($format, ['pdf', 'excel'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Format laporan harus pdf atau excel.',
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
        $report = $this->buildReport($this->getRows($filters), $filters);

        return $format === 'excel'
            ? $this->exportService->excel($report)
            : $this->exportService->pdf($report);
    }

    private function normalizeFilters(Request $request): array
    {
        $today = now()->toDateString();

        $tokoId = $request->query('toko_id', $request->header('X-Toko-Id'));
        $tokoId = is_numeric($tokoId) ? (int) $tokoId : null;

        $peringkat = $request->query('peringkat', $request->query('limit'));
        if ($peringkat === null || $peringkat === '') {
            $peringkat = 10;
        } elseif (is_numeric($peringkat)) {
            $peringkat = (int) $peringkat;
        }

        $nominalMin = $request->query(
            'nominal_min',
            $request->query('min_nominal', $request->query('range_nominal_awal', 1000000))
        );
        $nominalMax = $request->query(
            'nominal_max',
            $request->query('max_nominal', $request->query('range_nominal_akhir', 5000000))
        );

        $nominalMin = $this->normalizeNullableNumber($nominalMin, 1000000);
        $nominalMax = $this->normalizeNullableNumber($nominalMax, 5000000);

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
            'nominal_min' => $nominalMin,
            'nominal_max' => $nominalMax,
            'peringkat' => $peringkat,
            'toko_id' => $tokoId,
            'jenis_transaksi' => $jenisTransaksi,
        ];

        $validator = Validator::make($data, [
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            'nominal_min' => ['nullable', 'numeric', 'min:0'],
            'nominal_max' => ['nullable', 'numeric', 'min:0'],
            'peringkat' => ['required', 'integer', 'min:1', 'max:1000'],
            'toko_id' => ['nullable', 'integer', 'exists:master_toko,id'],
            'jenis_transaksi' => ['nullable', 'integer', 'in:0,1,2,3,4'],
        ], [
            'tanggal_akhir.after_or_equal' => 'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
            'nominal_min.min' => 'Nominal awal tidak boleh minus.',
            'nominal_max.min' => 'Nominal akhir tidak boleh minus.',
            'peringkat.min' => 'Peringkat minimal 1.',
            'peringkat.max' => 'Peringkat maksimal 1000 agar laporan tetap ringan.',
            'jenis_transaksi.in' => 'Jenis transaksi harus 0, 1, 2, 3, atau 4.',
        ]);

        $validator->after(function ($validator) use ($data): void {
            if (
                $data['nominal_min'] !== null
                && $data['nominal_max'] !== null
                && (float) $data['nominal_max'] < (float) $data['nominal_min']
            ) {
                $validator->errors()->add(
                    'nominal_max',
                    'Nominal akhir tidak boleh lebih kecil dari nominal awal.'
                );
            }
        });

        return [
            'validator' => $validator,
            'data' => $data,
        ];
    }

    private function normalizeNullableNumber(mixed $value, ?float $default = null): ?float
    {
        if ($value === null || $value === '' || $value === 'all' || $value === 'none') {
            return $default;
        }

        return is_numeric($value) ? (float) $value : $default;
    }

    private function publicFilters(array $filters): array
    {
        $toko = null;

        if (! empty($filters['toko_id'])) {
            $toko = DB::table('master_toko')
                ->where('id', (int) $filters['toko_id'])
                ->first(['id', 'nama_toko']);
        }

        return [
            'tanggal_awal' => $filters['tanggal_awal'],
            'tanggal_akhir' => $filters['tanggal_akhir'],
            'nominal_min' => $filters['nominal_min'],
            'nominal_max' => $filters['nominal_max'],
            'nominal_range_label' => $this->nominalRangeLabel(
                $filters['nominal_min'],
                $filters['nominal_max']
            ),
            'peringkat' => (int) $filters['peringkat'],
            'toko_id' => $filters['toko_id'],
            'toko_nama' => $toko->nama_toko ?? null,
            'tanggal_berdasarkan' => 'Tanggal lunas invoice',
            'nominal_berdasarkan' => 'Akumulasi grand total invoice lunas per pasien',
            'jenis_transaksi' => $filters['jenis_transaksi'],
            'jenis_transaksi_label' => $this->jenisTransaksiLabel($filters['jenis_transaksi']),
        ];
    }

    private function getRows(array $filters): Collection
    {
        $tanggalSql = 'COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)';

        $query = DB::table('pembayaran_invoice as pi')
            ->leftJoin('master_toko as toko', 'toko.id', '=', 'pi.toko_id')
            ->leftJoin('pasien as pasien', 'pasien.id', '=', 'pi.pasien_id')
            ->leftJoin('master_jenis_transaksi as jt', 'jt.id', '=', 'pi.jenis_transaksi')
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->whereIn('pi.jenis_transaksi', self::ALLOWED_JENIS_TRANSAKSI)
            ->whereRaw("DATE({$tanggalSql}) BETWEEN ? AND ?", [
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
            ])
            ->when(! empty($filters['toko_id']), function ($query) use ($filters): void {
                $query->where('pi.toko_id', (int) $filters['toko_id']);
            })
            ->when($filters['jenis_transaksi'] !== null, function ($query) use ($filters): void {
                $query->where('pi.jenis_transaksi', (int) $filters['jenis_transaksi']);
            })
            ->groupBy(
                'pi.pasien_id',
                'pasien.no_rm',
                'pasien.nama',
                'pasien.no_hp',
                'pasien.no_wa'
            )
            ->selectRaw("
                pi.pasien_id,
                pasien.no_rm,
                COALESCE(pasien.nama, 'Non Pasien') as nama_pasien,
                pasien.no_hp,
                pasien.no_wa,
                MIN(DATE({$tanggalSql})) as tanggal_awal_transaksi,
                MAX(DATE({$tanggalSql})) as tanggal_akhir_transaksi,
                COUNT(DISTINCT pi.id) as total_invoice,
                COUNT(DISTINCT DATE({$tanggalSql})) as total_hari_transaksi,
                COALESCE(SUM(pi.grand_total), 0) as total_nominal,
                COALESCE(SUM(pi.subtotal_treatment), 0) as total_treatment,
                COALESCE(SUM(pi.subtotal_produk), 0) as total_produk,
                COALESCE(SUM(pi.subtotal_konsultasi), 0) as total_konsultasi,
                COALESCE(SUM(pi.subtotal), 0) as total_bruto,
                COALESCE(SUM(pi.diskon_subtotal_amount), 0) as total_diskon_subtotal,
                COALESCE(SUM(pi.total_diskon_item), 0) as total_diskon_item,
                COALESCE(SUM(pi.total_diskon_referral), 0) as total_diskon_referral,
                COALESCE(SUM(pi.total_promo), 0) as total_promo,
                COALESCE(SUM(pi.diskon_member_amount), 0) as total_diskon_member,
                COALESCE(SUM(pi.total_bayar), 0) as total_bayar,
                COALESCE(SUM(pi.total_kembalian), 0) as total_kembalian,
                GROUP_CONCAT(
                    DISTINCT COALESCE(toko.nama_toko, '-')
                    ORDER BY toko.nama_toko
                    SEPARATOR ', '
                ) as cabang,
                GROUP_CONCAT(
                    DISTINCT COALESCE(
                        jt.nama_jenis_transaksi,
                        CONCAT('Jenis ', pi.jenis_transaksi)
                    )
                    ORDER BY pi.jenis_transaksi
                    SEPARATOR ', '
                ) as jenis_transaksi,
                GROUP_CONCAT(
                    DISTINCT pi.no_invoice
                    ORDER BY pi.no_invoice DESC
                    SEPARATOR ', '
                ) as invoice_terkait
            ");

        if ($filters['nominal_min'] !== null) {
            $query->havingRaw(
                'COALESCE(SUM(pi.grand_total), 0) >= ?',
                [(float) $filters['nominal_min']]
            );
        }

        if ($filters['nominal_max'] !== null) {
            $query->havingRaw(
                'COALESCE(SUM(pi.grand_total), 0) <= ?',
                [(float) $filters['nominal_max']]
            );
        }

        $rows = $query
            ->orderByDesc('total_nominal')
            ->orderByDesc('total_invoice')
            ->orderBy('nama_pasien')
            ->limit((int) $filters['peringkat'])
            ->get();

        return $rows->values()->map(function (object $row, int $index): array {
            $totalInvoice = max((int) $row->total_invoice, 1);
            $totalNominal = (float) $row->total_nominal;
            $totalBruto = (float) $row->total_bruto;
            $totalDiskon = (float) $row->total_diskon_subtotal
                + (float) $row->total_diskon_item
                + (float) $row->total_diskon_referral
                + (float) $row->total_promo
                + (float) $row->total_diskon_member;

            return [
                'peringkat' => $index + 1,
                'pasien_id' => $row->pasien_id,
                'no_rm' => $row->no_rm ?: '-',
                'nama_pasien' => $row->nama_pasien ?: 'Non Pasien',
                'no_hp' => $this->normalizePhone($row->no_wa ?: $row->no_hp),
                'cabang' => $row->cabang ?: '-',
                'tanggal_awal_transaksi' => $row->tanggal_awal_transaksi,
                'tanggal_akhir_transaksi' => $row->tanggal_akhir_transaksi,
                'periode_transaksi' => $this->formatDate($row->tanggal_awal_transaksi)
                    . ' s/d '
                    . $this->formatDate($row->tanggal_akhir_transaksi),
                'total_invoice' => (int) $row->total_invoice,
                'total_hari_transaksi' => (int) $row->total_hari_transaksi,
                'total_nominal' => $totalNominal,
                'total_treatment' => (float) $row->total_treatment,
                'total_produk' => (float) $row->total_produk,
                'total_konsultasi' => (float) $row->total_konsultasi,
                'total_bruto' => $totalBruto,
                'total_diskon' => $totalDiskon,
                'total_bayar' => (float) $row->total_bayar,
                'total_kembalian' => (float) $row->total_kembalian,
                'rata_nominal_per_invoice' => round($totalNominal / $totalInvoice, 2),
                'kontribusi_treatment_persen' => $totalNominal > 0
                    ? round(((float) $row->total_treatment / $totalNominal) * 100, 2)
                    : 0,
                'kontribusi_produk_persen' => $totalNominal > 0
                    ? round(((float) $row->total_produk / $totalNominal) * 100, 2)
                    : 0,
                'jenis_transaksi' => $row->jenis_transaksi ?: '-',
                'invoice_terkait' => $row->invoice_terkait ?: '-',
            ];
        });
    }

    private function buildReport(Collection $rows, array $filters): array
    {
        $publicFilters = $this->publicFilters($filters);
        $branchName = trim((string) ($publicFilters['toko_nama'] ?? ''));

        return [
            'title' => 'DATA TOP PASIEN NOMINAL TERBANYAK',
            'company_name' => 'PT. KOSMETIKA KLINIK INDONESIA',
            'company_contact' => 'Email : admin@msglowclinic.id | Website : www.msglowclinic.id',
            'branch_label' => $branchName !== ''
                ? 'MS GLOW AESTHETIC ' . mb_strtoupper($branchName)
                : 'SEMUA CABANG',
            'period_label' => $this->formatDateLong($filters['tanggal_awal'])
                . ' s/d '
                . $this->formatDateLong($filters['tanggal_akhir']),
            'nominal_range_label' => $publicFilters['nominal_range_label'],
            'jenis_transaksi_label' => $publicFilters['jenis_transaksi_label'],
            'peringkat_label' => 'Top ' . (int) $filters['peringkat'],
            'generated_at' => now()->format('d/m/Y H:i:s'),
            'filename_base' => sprintf(
                'laporan-top-pasien-nominal-terbanyak-%s-sd-%s-top-%d',
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
                (int) $filters['peringkat']
            ),
            'rows' => $rows
                ->map(function (array $row): array {
                    return [
                        'no' => (int) $row['peringkat'],
                        'nama_pasien' => $row['nama_pasien'],
                        'nominal' => (float) $row['total_nominal'],
                        'total_transaksi' => (int) $row['total_invoice'],
                    ];
                })
                ->values()
                ->all(),
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
            ->get(['id', 'kode_jenis_transaksi', 'nama_jenis_transaksi']);

        if ($rows->count() === count(self::ALLOWED_JENIS_TRANSAKSI)) {
            return $rows;
        }

        return collect(self::ALLOWED_JENIS_TRANSAKSI)->map(function (int $id) use ($rows): object {
            $row = $rows->firstWhere('id', $id);

            return (object) [
                'id' => $id,
                'kode_jenis_transaksi' => $row->kode_jenis_transaksi
                    ?? $this->defaultJenisTransaksiCode($id),
                'nama_jenis_transaksi' => $row->nama_jenis_transaksi
                    ?? $this->defaultJenisTransaksiLabel($id),
            ];
        });
    }

    private function jenisTransaksiLabel(mixed $id): string
    {
        if ($id === null || $id === '' || $id === 'all') {
            return 'Semua jenis transaksi';
        }

        $row = DB::table('master_jenis_transaksi')
            ->where('id', (int) $id)
            ->where('is_delete', 0)
            ->first(['nama_jenis_transaksi']);

        return $row->nama_jenis_transaksi
            ?? $this->defaultJenisTransaksiLabel((int) $id);
    }

    private function defaultJenisTransaksiCode(int $id): string
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

    private function defaultJenisTransaksiLabel(int $id): string
    {
        return match ($id) {
            0 => 'Umum',
            1 => 'Endorse / Fasilitas Karyawan',
            2 => 'EliteGlowbal',
            3 => 'Owner',
            4 => 'Deposit',
            default => 'Jenis ' . $id,
        };
    }

    private function nominalRangeOptions(): array
    {
        return [
            ['label' => 'Rp 0', 'value' => 0],
            ['label' => 'Rp 500.000', 'value' => 500000],
            ['label' => 'Rp 1.000.000', 'value' => 1000000],
            ['label' => 'Rp 2.000.000', 'value' => 2000000],
            ['label' => 'Rp 3.000.000', 'value' => 3000000],
            ['label' => 'Rp 5.000.000', 'value' => 5000000],
            ['label' => 'Rp 10.000.000', 'value' => 10000000],
            ['label' => 'Rp 25.000.000', 'value' => 25000000],
            ['label' => 'Rp 50.000.000', 'value' => 50000000],
            ['label' => 'Rp 100.000.000', 'value' => 100000000],
        ];
    }

    private function nominalRangeLabel(?float $min, ?float $max): string
    {
        if ($min !== null && $max !== null) {
            return $this->formatCurrency($min) . ' s/d ' . $this->formatCurrency($max);
        }

        if ($min !== null) {
            return '>= ' . $this->formatCurrency($min);
        }

        if ($max !== null) {
            return '<= ' . $this->formatCurrency($max);
        }

        return 'Semua nominal';
    }

    private function normalizePhone(?string $value): string
    {
        $phone = preg_replace('/\s+/', '', (string) $value) ?? '';

        if ($phone === '') {
            return '-';
        }

        if (str_starts_with($phone, '8')) {
            return '62' . $phone;
        }

        if (str_starts_with($phone, '0')) {
            return '62' . substr($phone, 1);
        }

        return $phone;
    }

    private function formatDate(?string $value): string
    {
        return $value ? Carbon::parse($value)->format('d/m/Y') : '-';
    }

    private function formatDateLong(?string $value): string
    {
        if (! $value) {
            return '-';
        }

        $date = Carbon::parse($value);
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

        return $date->day . ' ' . $months[$date->month] . ' ' . $date->year;
    }

    private function formatCurrency(float|int|string|null $value): string
    {
        return 'Rp ' . number_format((float) $value, 0, ',', '.');
    }
}
