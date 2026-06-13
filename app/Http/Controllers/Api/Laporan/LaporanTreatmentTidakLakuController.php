<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanTreatmentTidakLakuExportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class LaporanTreatmentTidakLakuController extends Controller
{
    public function __construct(
        private readonly LaporanTreatmentTidakLakuExportService $exportService
    ) {
    }

    public function summary(Request $request)
    {
        try {
            $filterResult = $this->normalizeFilters($request);

            if ($filterResult['validator']->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Filter laporan tidak valid.',
                    'errors' => $filterResult['validator']->errors(),
                ], 422);
            }

            $filters = $filterResult['data'];
            $rows = $this->getRows($filters);
            $totalActive = $this->activeTreatmentQuery($filters['toko_id'])->count('mt.id');
            $totalNotSold = $rows->count();
            $totalSold = max($totalActive - $totalNotSold, 0);

            return response()->json([
                'status' => true,
                'message' => 'Data treatment tidak laku berhasil diambil.',
                'data' => [
                    'filters' => $this->publicFilters($filters),
                    'branch_label' => $this->branchLabel($filters['toko_id']),
                    'total_treatment_aktif' => $totalActive,
                    'total_treatment_laku' => $totalSold,
                    'total_treatment_tidak_laku' => $totalNotSold,
                    'persentase_tidak_laku' => $totalActive > 0
                        ? round(($totalNotSold / $totalActive) * 100, 2)
                        : 0,
                    'rows' => $rows->values(),
                ],
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil laporan treatment tidak laku.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function export(Request $request, string $format)
    {
        try {
            $format = strtolower($format);

            if (! in_array($format, ['pdf', 'excel'], true)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Format export harus pdf atau excel.',
                ], 422);
            }

            $filterResult = $this->normalizeFilters($request);

            if ($filterResult['validator']->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Filter laporan tidak valid.',
                    'errors' => $filterResult['validator']->errors(),
                ], 422);
            }

            $filters = $filterResult['data'];
            $report = $this->buildReport($filters, $this->getRows($filters));

            return $format === 'pdf'
                ? $this->exportService->pdf($report)
                : $this->exportService->excel($report);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mencetak laporan treatment tidak laku.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    private function normalizeFilters(Request $request): array
    {
        $today = now()->toDateString();
        $rawTokoId = $request->input('toko_id', $request->header('X-Toko-Id'));
        $tokoId = is_numeric($rawTokoId) && (int) $rawTokoId > 0
            ? (int) $rawTokoId
            : null;

        $data = [
            'tanggal_awal' => (string) $request->input('tanggal_awal', $today),
            'tanggal_akhir' => (string) $request->input('tanggal_akhir', $today),
            'toko_id' => $tokoId,
        ];

        $validator = Validator::make($data, [
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            'toko_id' => ['nullable', 'integer', 'exists:master_toko,id'],
        ]);

        return compact('validator', 'data');
    }

    private function activeTreatmentQuery(?int $tokoId): Builder
    {
        return DB::table('master_treatment as mt')
            ->whereRaw('COALESCE(mt.is_delete, 0) = 0')
            ->whereExists(function (Builder $query) use ($tokoId): void {
                $query->selectRaw('1')
                    ->from('master_treatment_toko as active_mtt')
                    ->join('master_toko as active_toko', 'active_toko.id', '=', 'active_mtt.toko_id')
                    ->whereColumn('active_mtt.treatment_id', 'mt.id')
                    ->whereRaw('COALESCE(active_mtt.is_delete, 0) = 0')
                    ->whereRaw('COALESCE(active_mtt.is_active, 1) = 1')
                    ->whereRaw('COALESCE(active_toko.is_delete, 0) = 0')
                    ->when($tokoId, fn (Builder $builder) => $builder->where('active_mtt.toko_id', $tokoId));
            });
    }

    private function getRows(array $filters): Collection
    {
        return $this->activeTreatmentQuery($filters['toko_id'])
            ->whereNotExists(function (Builder $query) use ($filters): void {
                $query->selectRaw('1')
                    ->from('pembayaran_invoice_item as pii')
                    ->join('pembayaran_invoice as pi', 'pi.id', '=', 'pii.pembayaran_id')
                    ->leftJoin('master_treatment_toko as sold_mtt', 'sold_mtt.id', '=', 'pii.treatment_toko_id')
                    ->whereIn('pii.item_type', [2, 4])
                    ->where('pii.status', 1)
                    ->where('pii.is_delete', 0)
                    ->where('pi.status', 3)
                    ->where('pi.is_delete', 0)
                    ->whereBetween(
                        DB::raw('DATE(COALESCE(pi.tanggal_lunas, pi.tanggal_invoice))'),
                        [$filters['tanggal_awal'], $filters['tanggal_akhir']]
                    )
                    ->when(
                        $filters['toko_id'],
                        fn (Builder $builder) => $builder->where('pi.toko_id', $filters['toko_id'])
                    )
                    ->where(function (Builder $builder): void {
                        $builder->whereColumn('pii.treatment_id', 'mt.id')
                            ->orWhereColumn('sold_mtt.treatment_id', 'mt.id');
                    });
            })
            ->select([
                'mt.id as treatment_id',
                'mt.kode_accurate',
                'mt.nama',
                'mt.kategori_sales',
            ])
            ->orderBy('mt.sort_order')
            ->orderBy('mt.nama')
            ->get()
            ->map(function (object $row, int $index): array {
                return [
                    'no' => $index + 1,
                    'treatment_id' => (int) $row->treatment_id,
                    'kode_accurate' => $row->kode_accurate,
                    'nama' => $row->nama,
                    'kategori_sales' => $row->kategori_sales,
                ];
            });
    }

    private function buildReport(array $filters, Collection $rows): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $filters['tanggal_awal'])->locale('id');
        $end = Carbon::createFromFormat('Y-m-d', $filters['tanggal_akhir'])->locale('id');
        $branchLabel = $this->branchLabel($filters['toko_id']);

        return [
            'title' => 'Data Laporan Treatment Tidak Laku',
            'company_name' => 'PT. KOSMETIKA KLINIK INDONESIA',
            'company_contact' => 'Email : admin@msglowclinic.id | Website : www.msglowclinic.id',
            'branch_label' => $branchLabel,
            'tanggal_awal' => $start->translatedFormat('d F Y'),
            'tanggal_akhir' => $end->translatedFormat('d F Y'),
            'rows' => $rows->all(),
            'filename_base' => sprintf(
                'data-laporan-treatment-tidak-laku-%s-sd-%s',
                $filters['tanggal_awal'],
                $filters['tanggal_akhir']
            ),
        ];
    }

    private function branchLabel(?int $tokoId): string
    {
        if (! $tokoId) {
            return 'SEMUA CABANG';
        }

        return (string) (DB::table('master_toko')
            ->where('id', $tokoId)
            ->value('nama_toko') ?? 'CABANG TIDAK DITEMUKAN');
    }

    private function publicFilters(array $filters): array
    {
        return [
            'tanggal_awal' => $filters['tanggal_awal'],
            'tanggal_akhir' => $filters['tanggal_akhir'],
            'toko_id' => $filters['toko_id'],
        ];
    }
}
