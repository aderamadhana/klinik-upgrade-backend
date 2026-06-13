<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanDetailPasienExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class LaporanDetailPasienController extends Controller
{
    public function __construct(
        private readonly LaporanDetailPasienExportService $exportService
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
            'message' => 'Ringkasan laporan detail pasien berhasil diambil.',
            'data' => [
                'filters' => $this->publicFilters($filters),
                'total_transaksi' => $rows->count(),
                'total_pasien' => $rows->pluck('pasien_id')->filter()->unique()->count(),
                'total_treatment' => (float) $rows->sum('total_treatment'),
                'total_produk' => (float) $rows->sum('total_produk'),
                'grand_total' => (float) $rows->sum('grand_total'),
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
        $publicFilters = $this->publicFilters($filters);
        $rows = $this->getRows($filters);

        if ($format === 'pdf') {
            return $this->exportService->pdf($rows, $publicFilters);
        }

        return $this->exportService->excel($rows, $publicFilters);
    }

    private function normalizeFilters(Request $request): array
    {
        $today = now()->toDateString();
        $tokoId = $request->query('toko_id', $request->header('X-Toko-Id'));
        $tokoId = is_numeric($tokoId) ? (int) $tokoId : null;

        $data = [
            'tanggal_awal' => $request->query('tanggal_awal', $today),
            'tanggal_akhir' => $request->query(
                'tanggal_akhir',
                $request->query('tanggal_awal', $today)
            ),
            'toko_id' => $tokoId,
        ];

        $validator = Validator::make($data, [
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:tanggal_awal',
            ],
            'toko_id' => ['nullable', 'integer', 'exists:master_toko,id'],
        ], [
            'tanggal_akhir.after_or_equal' =>
                'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
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
        ];
    }

    private function getRows(array $filters): Collection
    {
        $netItemExpression = <<<'SQL'
CASE
    WHEN COALESCE(pii.subtotal_before_diskon_subtotal, 0) <> 0
         OR COALESCE(pii.diskon_subtotal_amount, 0) <> 0
    THEN COALESCE(pii.subtotal_after_diskon_subtotal, 0)
    ELSE COALESCE(pii.subtotal, 0)
END
SQL;

        $itemTotals = DB::table('pembayaran_invoice_item as pii')
            ->select('pii.pembayaran_id')
            ->selectRaw(
                "SUM(CASE WHEN pii.item_type IN (2, 4) THEN {$netItemExpression} ELSE 0 END) AS total_treatment"
            )
            ->selectRaw(
                "SUM(CASE WHEN pii.item_type = 3 THEN {$netItemExpression} ELSE 0 END) AS total_produk"
            )
            ->where('pii.status', 1)
            ->where('pii.is_delete', 0)
            ->groupBy('pii.pembayaran_id');

        $query = DB::table('pembayaran_invoice as pi')
            ->join('pasien as p', 'p.id', '=', 'pi.pasien_id')
            ->leftJoin('master_toko as mt', 'mt.id', '=', 'pi.toko_id')
            ->leftJoinSub($itemTotals, 'item_total', function ($join) {
                $join->on('item_total.pembayaran_id', '=', 'pi.id');
            })
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->where(function ($query) {
                $query->where('p.is_delete', 0)
                    ->orWhereNull('p.is_delete');
            })
            ->whereRaw(
                'DATE(COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)) BETWEEN ? AND ?',
                [$filters['tanggal_awal'], $filters['tanggal_akhir']]
            );

        if (! empty($filters['toko_id'])) {
            $query->where('pi.toko_id', (int) $filters['toko_id']);
        }

        return $query
            ->orderByRaw('COALESCE(pi.tanggal_lunas, pi.tanggal_invoice) ASC')
            ->orderBy('pi.no_invoice')
            ->get([
                'pi.id',
                'pi.no_invoice',
                'pi.pasien_id',
                'p.nama as nama_pasien',
                'mt.nama_toko',
                DB::raw(
                    'DATE(COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)) as tanggal_transaksi'
                ),
                DB::raw('COALESCE(item_total.total_treatment, 0) as total_treatment'),
                DB::raw('COALESCE(item_total.total_produk, 0) as total_produk'),
            ])
            ->map(function ($row, int $index): array {
                $totalTreatment = (float) $row->total_treatment;
                $totalProduk = (float) $row->total_produk;

                return [
                    'no' => $index + 1,
                    'invoice_id' => (int) $row->id,
                    'pasien_id' => (int) $row->pasien_id,
                    'tanggal_transaksi' => $row->tanggal_transaksi,
                    'no_invoice' => (string) $row->no_invoice,
                    'nama_pasien' => (string) $row->nama_pasien,
                    'nama_toko' => (string) ($row->nama_toko ?? '-'),
                    'total_treatment' => $totalTreatment,
                    'total_produk' => $totalProduk,
                    'grand_total' => $totalTreatment + $totalProduk,
                ];
            })
            ->values();
    }
}
