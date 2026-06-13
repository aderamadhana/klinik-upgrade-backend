<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanTindakanTerlarisExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class LaporanTindakanTerlarisController extends Controller
{
    private const ALLOWED_JENIS_TRANSAKSI = [0, 1, 2, 3, 4];

    public function __construct(
        private readonly LaporanTindakanTerlarisExportService $exportService
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

        return response()->json([
            'status' => true,
            'message' => 'Data tindakan terlaris berhasil diambil.',
            'data' => [
                'filters' => $this->publicFilters($filters),
                'jenis_transaksi_options' => $this->getJenisTransaksiOptions()->values(),
                'total_tindakan' => $rows->count(),
                'total_invoice' => (int) $rows->sum('total_invoice'),
                'total_pasien' => (int) $rows->sum('total_pasien'),
                'total_item' => (int) $rows->sum('total_item'),
                'total_qty' => (float) $rows->sum('total_qty'),
                'total_gross' => (float) $rows->sum('total_gross'),
                'total_diskon' => (float) $rows->sum('total_diskon'),
                'total_net' => (float) $rows->sum('total_net'),
                'rata_net_per_qty' => (float) $rows->sum('total_qty') > 0
                    ? round((float) $rows->sum('total_net') / (float) $rows->sum('total_qty'), 2)
                    : 0,
                'top_tindakan' => $rows->first(),
                'rows' => $rows->take(50)->values(),
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
                ->where('id', (int) $filters['toko_id'])
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

    private function getRows(array $filters): Collection
    {
        $tanggalSql = 'COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)';
        $resolvedTreatmentIdSql = 'COALESCE(pii.treatment_id, treatment_toko.treatment_id)';

        /*
         * Gunakan ID treatment sebagai grouping utama.
         * Item lama yang belum memiliki treatment_id dikelompokkan memakai hash
         * dari snapshot dalam bentuk BINARY agar tidak membandingkan collation.
         */
        $groupKeySql = "
            CASE
                WHEN {$resolvedTreatmentIdSql} IS NOT NULL
                    THEN CONCAT('ID:', CAST({$resolvedTreatmentIdSql} AS CHAR))
                ELSE CONCAT(
                    'SNAPSHOT:',
                    MD5(
                        CONCAT(
                            CAST(COALESCE(pii.kode_accurate_snapshot, '') AS BINARY),
                            0x1F,
                            CAST(COALESCE(pii.nama_item, '') AS BINARY)
                        )
                    )
                )
            END
        ";

        $grossSql = "
            CASE
                WHEN pii.deposit_claim_id IS NOT NULL
                    AND claim.status = 1
                    AND claim.is_delete = 0
                THEN COALESCE(claim.nilai_realisasi, 0)
                ELSE COALESCE(pii.qty, 0) * COALESCE(pii.harga, 0)
            END
        ";

        $netSql = "
            CASE
                WHEN pii.deposit_claim_id IS NOT NULL
                    AND claim.status = 1
                    AND claim.is_delete = 0
                THEN COALESCE(claim.nilai_realisasi, 0)
                ELSE COALESCE(
                    pii.subtotal_after_diskon_subtotal,
                    pii.subtotal,
                    0
                )
            END
        ";

        $query = DB::table('pembayaran_invoice_item as pii')
            ->join('pembayaran_invoice as pi', 'pi.id', '=', 'pii.pembayaran_id')
            ->leftJoin('pembayaran_deposit_treatment_claim as claim', function ($join): void {
                $join->on('claim.id', '=', 'pii.deposit_claim_id');
            })
            ->leftJoin('master_toko as toko', 'toko.id', '=', 'pi.toko_id')
            ->leftJoin(
                'master_treatment_toko as treatment_toko',
                'treatment_toko.id',
                '=',
                'pii.treatment_toko_id'
            )
            ->leftJoin('master_karyawan as dokter', function ($join): void {
                $join->on(
                    'dokter.id',
                    '=',
                    DB::raw('COALESCE(pii.dokter_id, pi.dokter_id, pi.referensi_dokter_id)')
                );
            })
            ->leftJoin('master_karyawan as perawat', 'perawat.id', '=', 'pii.perawat_id')
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->whereIn('pii.item_type', [1, 2])
            ->where('pii.status', 1)
            ->where('pii.is_delete', 0)
            ->whereIn('pii.jenis_transaksi', self::ALLOWED_JENIS_TRANSAKSI)
            ->whereRaw("DATE({$tanggalSql}) BETWEEN ? AND ?", [
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
            ])
            ->when(! empty($filters['toko_id']), function ($query) use ($filters): void {
                $query->where('pi.toko_id', (int) $filters['toko_id']);
            })
            ->when($filters['jenis_transaksi'] !== null, function ($query) use ($filters): void {
                $query->where('pii.jenis_transaksi', (int) $filters['jenis_transaksi']);
            });

        /*
         * Jangan agregasi kolom teks master dan snapshot dengan MAX/MIN.
         * Database ini mempunyai collation berbeda pada kolom-kolom tersebut.
         * Query utama hanya mengagregasi ID dan nilai numerik; nama/kode/kategori
         * dilengkapi setelah query menggunakan lookup terpisah.
         */
        $rows = $query
            ->selectRaw("
                MAX(COALESCE({$resolvedTreatmentIdSql}, 0)) as treatment_id,
                MIN(pii.id) as sample_item_id,
                GROUP_CONCAT(
                    DISTINCT toko.nama_toko
                    ORDER BY toko.nama_toko
                    SEPARATOR ', '
                ) as cabang,
                COUNT(DISTINCT pi.id) as total_invoice,
                COUNT(DISTINCT pi.pasien_id) as total_pasien,
                COUNT(DISTINCT pii.id) as total_item,
                SUM(COALESCE(pii.qty, 0)) as total_qty,
                SUM({$grossSql}) as total_gross,
                SUM(
                    CASE
                        WHEN pii.deposit_claim_id IS NOT NULL THEN 0
                        ELSE COALESCE(pii.diskon_amount, 0)
                            + COALESCE(pii.diskon_referral, 0)
                            + COALESCE(pii.diskon_subtotal_amount, 0)
                    END
                ) as total_diskon,
                SUM({$netSql}) as total_net,
                MIN(DATE({$tanggalSql})) as tanggal_pertama,
                MAX(DATE({$tanggalSql})) as tanggal_terakhir,
                GROUP_CONCAT(
                    DISTINCT CAST(pii.jenis_transaksi AS CHAR)
                    ORDER BY pii.jenis_transaksi
                    SEPARATOR ','
                ) as jenis_transaksi_ids,
                GROUP_CONCAT(
                    DISTINCT dokter.nama
                    ORDER BY dokter.nama
                    SEPARATOR ', '
                ) as dokter_terkait,
                GROUP_CONCAT(
                    DISTINCT perawat.nama
                    ORDER BY perawat.nama
                    SEPARATOR ', '
                ) as perawat_terkait,
                GROUP_CONCAT(
                    DISTINCT pi.no_invoice
                    ORDER BY pi.no_invoice
                    SEPARATOR ', '
                ) as invoice_terkait
            ")
            ->groupByRaw($groupKeySql)
            ->orderByRaw('total_qty DESC')
            ->orderByRaw('total_net DESC')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $treatmentIds = $rows
            ->pluck('treatment_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $treatmentMap = $treatmentIds->isEmpty()
            ? collect()
            : DB::table('master_treatment')
                ->whereIn('id', $treatmentIds->all())
                ->get([
                    'id',
                    'kode_accurate',
                    'nama',
                    'kategori_sales',
                ])
                ->keyBy('id');

        $sampleItemIds = $rows
            ->pluck('sample_item_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $sampleItemMap = $sampleItemIds->isEmpty()
            ? collect()
            : DB::table('pembayaran_invoice_item')
                ->whereIn('id', $sampleItemIds->all())
                ->get([
                    'id',
                    'kode_accurate_snapshot',
                    'nama_item',
                ])
                ->keyBy('id');

        $jenisTransaksiMap = $this->getJenisTransaksiOptions()
            ->keyBy(static fn ($row): int => (int) $row->id);

        return $rows->map(function ($row, int $index) use (
            $treatmentMap,
            $sampleItemMap,
            $jenisTransaksiMap
        ): array {
            $treatmentId = (int) $row->treatment_id;
            $treatment = $treatmentId > 0
                ? $treatmentMap->get($treatmentId)
                : null;

            $sampleItem = $sampleItemMap->get((int) $row->sample_item_id);

            $kodeAccurate = trim((string) ($treatment->kode_accurate ?? ''));
            if ($kodeAccurate === '') {
                $kodeAccurate = trim((string) ($sampleItem->kode_accurate_snapshot ?? ''));
            }

            $namaTindakan = trim((string) ($treatment->nama ?? ''));
            if ($namaTindakan === '') {
                $namaTindakan = trim((string) ($sampleItem->nama_item ?? ''));
            }

            $kategoriSales = trim((string) ($treatment->kategori_sales ?? ''));

            $jenisTransaksiIds = collect(
                explode(',', (string) ($row->jenis_transaksi_ids ?? ''))
            )
                ->map(static fn (string $id): int => (int) trim($id))
                ->filter(static fn (int $id): bool => in_array(
                    $id,
                    self::ALLOWED_JENIS_TRANSAKSI,
                    true
                ))
                ->unique()
                ->values();

            $jenisTransaksi = $jenisTransaksiIds
                ->map(function (int $id) use ($jenisTransaksiMap): string {
                    $option = $jenisTransaksiMap->get($id);

                    return $option->nama_jenis_transaksi
                        ?? $this->defaultJenisTransaksiLabel($id);
                })
                ->implode(', ');

            $totalQty = (float) $row->total_qty;
            $totalNet = (float) $row->total_net;

            return [
                'peringkat' => $index + 1,
                'treatment_id' => $treatmentId > 0 ? $treatmentId : null,
                'kode_accurate' => $kodeAccurate !== '' ? $kodeAccurate : '-',
                'nama_tindakan' => $namaTindakan !== '' ? $namaTindakan : '-',
                'kategori_sales' => $kategoriSales !== '' ? $kategoriSales : '-',
                'cabang' => $row->cabang ?: '-',
                'total_invoice' => (int) $row->total_invoice,
                'total_pasien' => (int) $row->total_pasien,
                'total_item' => (int) $row->total_item,
                'total_qty' => $totalQty,
                'total_gross' => (float) $row->total_gross,
                'total_diskon' => (float) $row->total_diskon,
                'total_net' => $totalNet,
                'rata_net_per_qty' => $totalQty > 0
                    ? round($totalNet / $totalQty, 2)
                    : 0,
                'tanggal_pertama' => $row->tanggal_pertama,
                'tanggal_terakhir' => $row->tanggal_terakhir,
                'jenis_transaksi' => $jenisTransaksi !== ''
                    ? $jenisTransaksi
                    : '-',
                'dokter_terkait' => $row->dokter_terkait ?: '-',
                'perawat_terkait' => $row->perawat_terkait ?: '-',
                'invoice_terkait' => $row->invoice_terkait ?: '-',
            ];
        });
    }

    private function buildReport(Collection $rows, array $filters): array
    {
        $publicFilters = $this->publicFilters($filters);

        return [
            'title' => 'LAPORAN DATA TINDAKAN TERLARIS',
            'company_name' => 'PT. KOSMETIKA KLINIK INDONESIA',
            'company_contact' => 'Email : admin@msglowclinic.id | Website : www.msglowclinic.id',
            'branch_label' => $publicFilters['toko_nama']
                ? 'MS GLOW AESTHETIC ' . strtoupper($publicFilters['toko_nama'])
                : 'SEMUA CABANG',
            'period_label' => $this->formatDateIndonesia($filters['tanggal_awal'])
                . ' s/d '
                . $this->formatDateIndonesia($filters['tanggal_akhir']),
            'jenis_transaksi_label' => $publicFilters['jenis_transaksi_label'],
            'rows' => $rows->values()->map(function (array $row, int $index): array {
                return [
                    'no' => $index + 1,
                    'tindakan' => $row['nama_tindakan'],
                    'jumlah' => (float) $row['total_qty'],
                    'total_harga' => (float) $row['total_net'],
                ];
            })->all(),
            'total_jumlah' => (float) $rows->sum('total_qty'),
            'total_harga' => (float) $rows->sum('total_net'),
            'generated_at' => now()->format('d/m/Y H:i:s'),
            'filename_base' => sprintf(
                'data-laporan-tindakan-terlaris-%s-sd-%s',
                $filters['tanggal_awal'],
                $filters['tanggal_akhir']
            ),
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

    private function formatDateIndonesia(string $value): string
    {
        $monthNames = [
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

        $date = Carbon::parse($value);

        return $date->day . ' ' . $monthNames[$date->month] . ' ' . $date->year;
    }
}
