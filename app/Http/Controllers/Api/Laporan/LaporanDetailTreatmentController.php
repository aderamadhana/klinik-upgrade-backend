<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanDetailTreatmentExportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class LaporanDetailTreatmentController extends Controller
{
    private const REPORT_ITEM_TYPES = [1, 2, 4, 5];

    public function __construct(
        private readonly LaporanDetailTreatmentExportService $exportService
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

            return response()->json([
                'status' => true,
                'message' => 'Laporan detail treatment berhasil diambil.',
                'data' => [
                    'filters' => $this->publicFilters($filters),
                    'branch_label' => $this->branchLabel($filters['toko_id']),
                    'total_item' => $rows->count(),
                    'total_invoice' => $rows->pluck('pembayaran_id')->unique()->count(),
                    'total_pasien' => $rows->pluck('pasien_id')->filter()->unique()->count(),
                    'total_qty' => (float) $rows->sum('qty'),
                    'total_harga' => (float) $rows->sum('total_harga'),
                    'rows' => $rows->values(),
                ],
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil laporan detail treatment.',
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
                'message' => 'Gagal mencetak laporan detail treatment.',
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
            'tanggal_akhir' => (string) $request->input(
                'tanggal_akhir',
                $request->input('tanggal_awal', $today)
            ),
            'toko_id' => $tokoId,
        ];

        $validator = Validator::make($data, [
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            'toko_id' => ['nullable', 'integer', 'exists:master_toko,id'],
        ], [
            'tanggal_akhir.after_or_equal' => 'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
        ]);

        return compact('validator', 'data');
    }

    private function getRows(array $filters): Collection
    {
        $startAt = Carbon::createFromFormat('Y-m-d', $filters['tanggal_awal'])->startOfDay();
        $endAt = Carbon::createFromFormat('Y-m-d', $filters['tanggal_akhir'])->endOfDay();

        $rows = DB::table('pembayaran_invoice_item as pii')
            ->join('pembayaran_invoice as pi', 'pi.id', '=', 'pii.pembayaran_id')
            ->leftJoin('pasien as pasien', 'pasien.id', '=', 'pi.pasien_id')
            ->leftJoin('master_toko as toko', 'toko.id', '=', 'pi.toko_id')
            ->leftJoin('master_treatment as treatment', 'treatment.id', '=', 'pii.treatment_id')
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->where('pii.status', 1)
            ->where('pii.is_delete', 0)
            ->whereIn('pii.item_type', self::REPORT_ITEM_TYPES)
            ->where(function (Builder $query) use ($startAt, $endAt): void {
                $query->whereBetween('pi.tanggal_lunas', [$startAt, $endAt])
                    ->orWhere(function (Builder $fallback) use ($startAt, $endAt): void {
                        $fallback->whereNull('pi.tanggal_lunas')
                            ->whereBetween('pi.tanggal_invoice', [$startAt, $endAt]);
                    });
            })
            ->when(
                $filters['toko_id'],
                fn (Builder $query) => $query->where('pi.toko_id', $filters['toko_id'])
            )
            ->orderByRaw('COALESCE(pi.tanggal_lunas, pi.tanggal_invoice) asc')
            ->orderBy('pi.no_invoice')
            ->orderBy('pii.id')
            ->get([
                'pii.id',
                'pii.pembayaran_id',
                'pii.item_type',
                'pii.treatment_id',
                'pii.nama_item',
                'pii.qty',
                'pii.harga',
                'pii.diskon_amount',
                'pii.diskon_referral',
                'pii.diskon_subtotal_amount',
                'pii.subtotal',
                'pi.no_invoice',
                'pi.toko_id',
                'toko.nama_toko',
                'pi.pasien_id',
                'pasien.no_rm',
                'pasien.nama as pasien_nama',
                'treatment.nama as treatment_nama_master',
                DB::raw('DATE(COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)) as tanggal'),
            ]);

        return $rows->map(function (object $row): array {
            $namaTreatment = trim((string) ($row->treatment_nama_master ?: $row->nama_item));

            return [
                'id' => (int) $row->id,
                'pembayaran_id' => (int) $row->pembayaran_id,
                'tanggal' => $row->tanggal,
                'no_invoice' => $row->no_invoice ?: '-',
                'toko_id' => $row->toko_id ? (int) $row->toko_id : null,
                'cabang' => $row->nama_toko ?: '-',
                'pasien_id' => $row->pasien_id ? (int) $row->pasien_id : null,
                'no_rm' => $row->no_rm ?: '-',
                'nama_pasien' => $row->pasien_nama ?: '-',
                'treatment_id' => $row->treatment_id ? (int) $row->treatment_id : null,
                'nama_treatment' => $namaTreatment !== '' ? $namaTreatment : '-',
                'item_type' => (int) $row->item_type,
                'jenis_item' => $this->itemTypeLabel((int) $row->item_type),
                'qty' => (float) $row->qty,
                'harga' => (float) $row->harga,
                'diskon_item' => (float) $row->diskon_amount,
                'diskon_referral' => (float) $row->diskon_referral,
                'diskon_subtotal' => (float) $row->diskon_subtotal_amount,
                'total_harga' => (float) $row->subtotal,
            ];
        });
    }

    private function buildReport(array $filters, Collection $rows): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $filters['tanggal_awal'])->locale('id');
        $end = Carbon::createFromFormat('Y-m-d', $filters['tanggal_akhir'])->locale('id');

        return [
            'title' => 'Laporan Detail Treatment',
            'company_name' => 'PT. KOSMETIKA KLINIK INDONESIA',
            'company_contact' => 'Email : admin@msglowclinic.id | Website : www.msglowclinic.id',
            'branch_label' => $this->branchLabel($filters['toko_id']),
            'period_label' => sprintf(
                '%s s/d %s',
                $start->translatedFormat('d F Y'),
                $end->translatedFormat('d F Y')
            ),
            'generated_at' => now()->format('d/m/Y H:i:s'),
            'filename_base' => sprintf(
                'laporan-detail-treatment-%s-sd-%s',
                $filters['tanggal_awal'],
                $filters['tanggal_akhir']
            ),
            'rows' => $rows->map(function (array $row, int $index): array {
                return [
                    'no' => $index + 1,
                    'tanggal' => $row['tanggal'],
                    'no_invoice' => $row['no_invoice'],
                    'nama_pasien' => $row['nama_pasien'],
                    'no_rm' => $row['no_rm'],
                    'nama_treatment' => $row['nama_treatment'],
                    'qty' => (float) $row['qty'],
                    'total_harga' => (float) $row['total_harga'],
                ];
            })->values()->all(),
            'totals' => [
                'qty' => (float) $rows->sum('qty'),
                'total_harga' => (float) $rows->sum('total_harga'),
            ],
        ];
    }

    private function branchLabel(?int $tokoId): string
    {
        if (! $tokoId) {
            return 'SEMUA CABANG';
        }

        $branchName = DB::table('master_toko')
            ->where('id', $tokoId)
            ->value('nama_toko');

        return $branchName
            ? 'MS GLOW AESTHETIC ' . mb_strtoupper((string) $branchName)
            : 'CABANG TIDAK DITEMUKAN';
    }

    private function publicFilters(array $filters): array
    {
        return [
            'tanggal_awal' => $filters['tanggal_awal'],
            'tanggal_akhir' => $filters['tanggal_akhir'],
            'toko_id' => $filters['toko_id'],
            'tanggal_berdasarkan' => 'Tanggal lunas invoice, fallback tanggal invoice',
            'status_invoice' => 'Lunas',
            'item_yang_dihitung' => 'Konsultasi, treatment, deposit treatment, dan marker/non-billing',
            'nilai_berdasarkan' => 'Subtotal net item setelah seluruh diskon',
        ];
    }

    private function itemTypeLabel(int $itemType): string
    {
        return match ($itemType) {
            1 => 'Konsultasi',
            2 => 'Treatment',
            4 => 'Deposit Treatment',
            5 => 'Marker / Non Billing',
            default => 'Lainnya',
        };
    }
}
