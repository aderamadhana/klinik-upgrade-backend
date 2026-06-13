<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanJumlahPeningkatanExportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class LaporanJumlahPeningkatanController extends Controller
{
    private const STATUS_LUNAS = 3;

    public function __construct(
        private readonly LaporanJumlahPeningkatanExportService $exportService
    ) {
    }

    public function summary(Request $request)
    {
        try {
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
                'message' => 'Laporan jumlah peningkatan berhasil diambil.',
                'data' => [
                    'filters' => $this->publicFilters($filters),
                    'branch_label' => $this->branchLabel($filters['toko_id']),
                    'total_cabang' => $rows->count(),
                    'total_pembelian' => (int) $rows->sum('total_pembelian'),
                    'total_perawatan' => (int) $rows->sum('total_perawatan'),
                    'total_pasien_baru' => (int) $rows->sum('total_pasien_baru'),
                    'rows' => $rows->values(),
                ],
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil laporan jumlah peningkatan.',
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

            $normalized = $this->normalizeFilters($request);

            if ($normalized['validator']->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Filter laporan tidak valid.',
                    'errors' => $normalized['validator']->errors(),
                ], 422);
            }

            $filters = $normalized['data'];
            $report = $this->buildReport($filters, $this->getRows($filters));

            return $format === 'pdf'
                ? $this->exportService->pdf($report)
                : $this->exportService->excel($report);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mencetak laporan jumlah peningkatan.',
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

        $branches = DB::table('master_toko')
            ->where('jenis_toko', 1)
            ->when(
                $filters['toko_id'],
                fn (Builder $query) => $query->where('id', $filters['toko_id']),
                fn (Builder $query) => $query->where('is_delete', 0)
            )
            ->orderBy('sort_order')
            ->orderBy('nama_toko')
            ->get(['id', 'kode', 'kode_toko', 'nama_toko']);

        $transactionCounts = DB::table('pembayaran_invoice as pi')
            ->join('pembayaran_invoice_item as item', function ($join): void {
                $join->on('item.pembayaran_id', '=', 'pi.id')
                    ->where('item.status', 1)
                    ->where('item.is_delete', 0);
            })
            ->where('pi.status', self::STATUS_LUNAS)
            ->where('pi.is_delete', 0)
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
            ->groupBy('pi.toko_id')
            ->selectRaw('pi.toko_id')
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN item.item_type = 3 THEN pi.registrasi_id END) as total_pembelian'
            )
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN item.item_type IN (1, 2, 4, 5) THEN pi.registrasi_id END) as total_perawatan'
            )
            ->get()
            ->keyBy('toko_id');

        $newPatientCounts = DB::table('pembayaran_invoice as pi')
            ->join('pasien as pasien', 'pasien.id', '=', 'pi.pasien_id')
            ->where('pi.status', self::STATUS_LUNAS)
            ->where('pi.is_delete', 0)
            ->where('pasien.tipe_pasien', 1)
            ->where('pasien.is_delete', 0)
            ->where(function (Builder $query) use ($startAt, $endAt): void {
                $query->whereBetween('pi.tanggal_lunas', [$startAt, $endAt])
                    ->orWhere(function (Builder $fallback) use ($startAt, $endAt): void {
                        $fallback->whereNull('pi.tanggal_lunas')
                            ->whereBetween('pi.tanggal_invoice', [$startAt, $endAt]);
                    });
            })
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('pembayaran_invoice as older')
                    ->whereColumn('older.pasien_id', 'pi.pasien_id')
                    ->where('older.status', self::STATUS_LUNAS)
                    ->where('older.is_delete', 0)
                    ->where(function (Builder $earlier): void {
                        $earlier->whereRaw(
                            'COALESCE(older.tanggal_lunas, older.tanggal_invoice) < COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)'
                        )->orWhere(function (Builder $sameTime): void {
                            $sameTime->whereRaw(
                                'COALESCE(older.tanggal_lunas, older.tanggal_invoice) = COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)'
                            )->whereColumn('older.id', '<', 'pi.id');
                        });
                    });
            })
            ->when(
                $filters['toko_id'],
                fn (Builder $query) => $query->where('pi.toko_id', $filters['toko_id'])
            )
            ->groupBy('pi.toko_id')
            ->selectRaw('pi.toko_id, COUNT(DISTINCT pi.pasien_id) as total_pasien_baru')
            ->get()
            ->keyBy('toko_id');

        return $branches->map(function (object $branch, int $index) use (
            $transactionCounts,
            $newPatientCounts
        ): array {
            $transactions = $transactionCounts->get($branch->id);
            $newPatients = $newPatientCounts->get($branch->id);

            return [
                'no' => $index + 1,
                'toko_id' => (int) $branch->id,
                'toko_kode' => $branch->kode ?: '-',
                'toko_kode_singkat' => $branch->kode_toko ?: '-',
                'toko_nama' => $branch->nama_toko ?: '-',
                'total_pembelian' => (int) ($transactions->total_pembelian ?? 0),
                'total_perawatan' => (int) ($transactions->total_perawatan ?? 0),
                'total_pasien_baru' => (int) ($newPatients->total_pasien_baru ?? 0),
            ];
        })->values();
    }

    private function buildReport(array $filters, Collection $rows): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $filters['tanggal_awal'])->locale('id');
        $end = Carbon::createFromFormat('Y-m-d', $filters['tanggal_akhir'])->locale('id');

        return [
            'title' => 'Data Laporan Jumlah Peningkatan',
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
                'data-laporan-jumlah-peningkatan-%s-sd-%s',
                $filters['tanggal_awal'],
                $filters['tanggal_akhir']
            ),
            'rows' => $rows->all(),
            'totals' => [
                'total_pembelian' => (int) $rows->sum('total_pembelian'),
                'total_perawatan' => (int) $rows->sum('total_perawatan'),
                'total_pasien_baru' => (int) $rows->sum('total_pasien_baru'),
            ],
        ];
    }

    private function publicFilters(array $filters): array
    {
        return [
            'tanggal_awal' => $filters['tanggal_awal'],
            'tanggal_akhir' => $filters['tanggal_akhir'],
            'toko_id' => $filters['toko_id'],
            'tanggal_berdasarkan' => 'Tanggal lunas invoice',
            'total_pembelian_berdasarkan' => 'Registrasi unik dengan item produk/obat aktif',
            'total_perawatan_berdasarkan' => 'Registrasi unik dengan item konsultasi, treatment, deposit treatment, atau marker layanan aktif',
            'pasien_baru_berdasarkan' => 'Transaksi lunas pertama pasien sepanjang histori',
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
            ? mb_strtoupper((string) $branchName)
            : 'CABANG TIDAK DITEMUKAN';
    }
}
