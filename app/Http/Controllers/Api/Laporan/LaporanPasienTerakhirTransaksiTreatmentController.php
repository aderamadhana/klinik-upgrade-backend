<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanPasienTerakhirTransaksiTreatmentExportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class LaporanPasienTerakhirTransaksiTreatmentController extends Controller
{
    private const REPORT_ITEM_TYPES = [1, 2, 4, 5];

    public function __construct(
        private readonly LaporanPasienTerakhirTransaksiTreatmentExportService $exportService
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
                'message' => 'Laporan pasien terakhir transaksi treatment berhasil diambil.',
                'data' => [
                    'filters' => $this->publicFilters($filters),
                    'branch_name' => $this->branchName($filters['toko_id']),
                    'total_pasien' => $rows->count(),
                    'total_faktur' => (int) $rows->sum('jumlah_faktur'),
                    'total_item' => (int) $rows->sum('jumlah_item'),
                    'rows' => $rows->values(),
                ],
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil laporan pasien terakhir transaksi treatment.',
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
                'message' => 'Gagal mencetak laporan pasien terakhir transaksi treatment.',
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

        $details = DB::table('pembayaran_invoice_item as pii')
            ->join('pembayaran_invoice as pi', 'pi.id', '=', 'pii.pembayaran_id')
            ->join('pasien as pasien', 'pasien.id', '=', 'pi.pasien_id')
            ->leftJoin('master_toko as toko', 'toko.id', '=', 'pi.toko_id')
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->where('pasien.is_delete', 0)
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
            ->whereNotExists(function (Builder $query) use ($filters): void {
                $query->selectRaw('1')
                    ->from('pembayaran_invoice as newer')
                    ->whereColumn('newer.pasien_id', 'pi.pasien_id')
                    ->whereColumn('newer.registrasi_id', '<>', 'pi.registrasi_id')
                    ->where('newer.status', 3)
                    ->where('newer.is_delete', 0)
                    ->when(
                        $filters['toko_id'],
                        fn (Builder $branchQuery) => $branchQuery->where(
                            'newer.toko_id',
                            $filters['toko_id']
                        )
                    )
                    ->whereExists(function (Builder $itemQuery): void {
                        $itemQuery->selectRaw('1')
                            ->from('pembayaran_invoice_item as newer_item')
                            ->whereColumn('newer_item.pembayaran_id', 'newer.id')
                            ->where('newer_item.status', 1)
                            ->where('newer_item.is_delete', 0)
                            ->whereIn('newer_item.item_type', self::REPORT_ITEM_TYPES);
                    })
                    ->where(function (Builder $laterQuery): void {
                        $laterQuery
                            ->whereRaw(
                                'COALESCE(newer.tanggal_lunas, newer.tanggal_invoice) > COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)'
                            )
                            ->orWhere(function (Builder $sameTimeQuery): void {
                                $sameTimeQuery
                                    ->whereRaw(
                                        'COALESCE(newer.tanggal_lunas, newer.tanggal_invoice) = COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)'
                                    )
                                    ->whereColumn('newer.registrasi_id', '>', 'pi.registrasi_id');
                            });
                    });
            })
            ->orderByRaw('COALESCE(pi.tanggal_lunas, pi.tanggal_invoice) desc')
            ->orderBy('pasien.nama')
            ->orderBy('pi.no_invoice')
            ->orderBy('pii.id')
            ->get([
                'pii.id as item_id',
                'pii.item_type',
                'pii.nama_item',
                'pii.qty',
                'pi.id as pembayaran_id',
                'pi.registrasi_id',
                'pi.no_invoice',
                'pi.toko_id',
                'pi.pasien_id',
                'pasien.no_rm',
                'pasien.nama as pasien_nama',
                'toko.nama_toko',
                DB::raw('DATE(COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)) as tanggal_transaksi'),
                DB::raw('COALESCE(pi.tanggal_lunas, pi.tanggal_invoice) as waktu_transaksi'),
            ]);

        return $details
            ->groupBy('pasien_id')
            ->map(function (Collection $items): array {
                $first = $items->first();
                $latestDate = $items->max('tanggal_transaksi');

                $treatments = $items
                    ->filter(fn (object $item): bool => trim((string) $item->nama_item) !== '')
                    ->unique(fn (object $item): string => mb_strtolower(trim((string) $item->nama_item)))
                    ->pluck('nama_item')
                    ->map(fn ($name): string => trim((string) $name))
                    ->values();

                $invoices = $items
                    ->pluck('no_invoice')
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values();

                $branches = $items
                    ->pluck('nama_toko')
                    ->filter()
                    ->unique()
                    ->values();

                return [
                    'pasien_id' => (int) $first->pasien_id,
                    'nama_pasien' => $first->pasien_nama ?: '-',
                    'no_rm' => $first->no_rm ?: '-',
                    'treatment_terakhir' => $treatments->isNotEmpty()
                        ? $treatments->implode(', ')
                        : '-',
                    'tanggal_terakhir' => $latestDate,
                    'faktur' => $invoices->isNotEmpty() ? $invoices->implode(', ') : '-',
                    'cabang' => $branches->isNotEmpty() ? $branches->implode(', ') : '-',
                    'jumlah_item' => $items->count(),
                    'jumlah_faktur' => $invoices->count(),
                    'registrasi_id' => (int) $first->registrasi_id,
                ];
            })
            ->sort(function (array $a, array $b): int {
                $dateCompare = strcmp(
                    (string) $b['tanggal_terakhir'],
                    (string) $a['tanggal_terakhir']
                );

                if ($dateCompare !== 0) {
                    return $dateCompare;
                }

                return strcmp(
                    mb_strtoupper((string) $a['nama_pasien']),
                    mb_strtoupper((string) $b['nama_pasien'])
                );
            })
            ->values();
    }

    private function buildReport(array $filters, Collection $rows): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $filters['tanggal_awal'])->locale('id');
        $end = Carbon::createFromFormat('Y-m-d', $filters['tanggal_akhir'])->locale('id');
        $branchName = $this->branchName($filters['toko_id']);

        return [
            'title' => 'Data Laporan Pasien Terakhir Transaksi Treatment',
            'company_name' => 'PT. KOSMETIKA KLINIK INDONESIA',
            'company_contact' => 'Email : admin@msglowclinic.id | Website : www.msglowclinic.id',
            'branch_name' => mb_strtoupper($branchName),
            'period_label' => sprintf(
                '%s s/d %s (%s)',
                $start->translatedFormat('d F Y'),
                $end->translatedFormat('d F Y'),
                mb_strtoupper($branchName)
            ),
            'generated_at' => now()->format('d/m/Y H:i:s'),
            'filename_base' => sprintf(
                'laporan-pasien-terakhir-transaksi-treatment-%s-sd-%s',
                $filters['tanggal_awal'],
                $filters['tanggal_akhir']
            ),
            'rows' => $rows
                ->map(function (array $row, int $index): array {
                    return [
                        'no' => $index + 1,
                        'nama_pasien' => $row['nama_pasien'],
                        'no_rm' => $row['no_rm'],
                        'treatment_terakhir' => $row['treatment_terakhir'],
                        'tanggal_terakhir' => $row['tanggal_terakhir'],
                        'faktur' => $row['faktur'],
                    ];
                })
                ->values()
                ->all(),
            'totals' => [
                'total_pasien' => $rows->count(),
                'total_faktur' => (int) $rows->sum('jumlah_faktur'),
                'total_item' => (int) $rows->sum('jumlah_item'),
            ],
        ];
    }

    private function publicFilters(array $filters): array
    {
        return [
            'tanggal_awal' => $filters['tanggal_awal'],
            'tanggal_akhir' => $filters['tanggal_akhir'],
            'toko_id' => $filters['toko_id'],
            'tanggal_berdasarkan' => 'Tanggal lunas invoice, fallback tanggal invoice',
            'status_invoice' => 'Lunas',
            'cakupan_transaksi_terakhir' => $filters['toko_id']
                ? 'Transaksi treatment terakhir pada cabang aktif'
                : 'Transaksi treatment terakhir pada seluruh cabang',
            'item_yang_dihitung' => 'Konsultasi, treatment, deposit treatment, dan marker/non-billing',
        ];
    }

    private function branchName(?int $tokoId): string
    {
        if (! $tokoId) {
            return 'Semua Cabang';
        }

        $branchName = DB::table('master_toko')
            ->where('id', $tokoId)
            ->value('nama_toko');

        return $branchName ?: 'Cabang Tidak Ditemukan';
    }
}
