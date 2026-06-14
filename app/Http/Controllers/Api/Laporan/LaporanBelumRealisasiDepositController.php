<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanBelumRealisasiDepositExportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class LaporanBelumRealisasiDepositController extends Controller
{
    private const STATUS_INVOICE_LUNAS = 3;
    private const STATUS_DEPOSIT_BATAL = 9;
    private const STATUS_CLAIM_AKTIF = 1;

    public function __construct(
        private readonly LaporanBelumRealisasiDepositExportService $exportService
    ) {
    }

    public function summary(Request $request)
    {
        try {
            $filters = $this->normalizeFilters($request);
            $rows = $this->getRows($filters);

            return response()->json([
                'status' => true,
                'message' => 'Laporan deposit belum realisasi berhasil diambil.',
                'data' => [
                    'filters' => $this->publicFilters($filters),
                    'branch_label' => $this->branchLabel($filters['toko_id']),
                    'total_deposit' => $rows->count(),
                    'total_pasien' => $rows->pluck('pasien_id')->filter()->unique()->count(),
                    'total_qty_sisa' => (float) $rows->sum('qty_sisa'),
                    'total_nilai_sisa' => (float) $rows->sum('nilai_sisa'),
                    'total_expired' => $rows->where('is_expired', true)->count(),
                    'rows' => $rows->values(),
                ],
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil laporan deposit belum realisasi.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function exportPdf(Request $request)
    {
        try {
            $filters = $this->normalizeFilters($request);
            $report = $this->buildReport($filters, $this->getRows($filters));

            return $this->exportService->pdf($report);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mencetak laporan deposit belum realisasi.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    private function normalizeFilters(Request $request): array
    {
        $rawTokoId = $request->input('toko_id', $request->header('X-Toko-Id'));
        $tokoId = is_numeric($rawTokoId) && (int) $rawTokoId > 0
            ? (int) $rawTokoId
            : null;

        return [
            'toko_id' => $tokoId,
        ];
    }

    private function getRows(array $filters): Collection
    {
        $claimTotals = DB::table('pembayaran_deposit_treatment_claim')
            ->where('status', self::STATUS_CLAIM_AKTIF)
            ->where('is_delete', 0)
            ->groupBy('deposit_treatment_id')
            ->select('deposit_treatment_id')
            ->selectRaw('SUM(qty_claim) as qty_claimed_calc')
            ->selectRaw('SUM(nilai_realisasi) as nilai_claimed_calc');

        $remainingQtyExpression = 'GREATEST('
            . 'pdt.qty_total - GREATEST('
            . 'COALESCE(claim_total.qty_claimed_calc, 0), '
            . 'COALESCE(pdt.qty_claimed, 0)'
            . '), 0)';

        $remainingValueExpression = 'GREATEST('
            . 'pdt.total_nilai - GREATEST('
            . 'COALESCE(claim_total.nilai_claimed_calc, 0), '
            . 'COALESCE(pdt.nilai_claimed, 0)'
            . '), 0)';

        return DB::table('pembayaran_deposit_treatment as pdt')
            ->join('pembayaran_invoice as pi', 'pi.id', '=', 'pdt.pembayaran_id')
            ->join('pasien as pasien', 'pasien.id', '=', 'pdt.pasien_id')
            ->leftJoin('master_treatment as treatment', 'treatment.id', '=', 'pdt.treatment_id')
            ->leftJoin('master_toko as toko', 'toko.id', '=', 'pdt.toko_beli_id')
            ->leftJoinSub($claimTotals, 'claim_total', function ($join): void {
                $join->on('claim_total.deposit_treatment_id', '=', 'pdt.id');
            })
            ->where('pdt.is_delete', 0)
            ->where('pi.is_delete', 0)
            ->where('pi.status', self::STATUS_INVOICE_LUNAS)
            ->where('pdt.status', '!=', self::STATUS_DEPOSIT_BATAL)
            ->whereRaw("{$remainingQtyExpression} > 0")
            ->when(
                $filters['toko_id'],
                fn (Builder $query) => $query->where('pdt.toko_beli_id', $filters['toko_id'])
            )
            ->orderByRaw('CASE WHEN pdt.expired_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('pdt.expired_at')
            ->orderBy('pasien.nama')
            ->orderBy('pi.no_invoice')
            ->get([
                'pdt.id',
                'pdt.pasien_id',
                'pdt.toko_beli_id',
                'pdt.treatment_id',
                'pdt.nama_treatment as nama_treatment_snapshot',
                'pdt.harga_satuan',
                'pdt.expired_at',
                'pdt.status as deposit_status',
                'pi.no_invoice',
                'pi.catatan',
                'pi.tanggal_lunas',
                'pasien.no_rm',
                'pasien.nama as nama_pasien',
                'treatment.nama as nama_treatment_master',
                'toko.nama_toko',
                DB::raw("{$remainingQtyExpression} as qty_sisa_calc"),
                DB::raw("{$remainingValueExpression} as nilai_sisa_calc"),
            ])
            ->map(function (object $row): array {
                $expiredAt = $row->expired_at
                    ? Carbon::parse($row->expired_at)->toDateString()
                    : null;
                $isExpired = $expiredAt !== null
                    && Carbon::parse($expiredAt)->startOfDay()->lt(now()->startOfDay());
                $snapshotName = trim((string) ($row->nama_treatment_snapshot ?? ''));
                $masterName = trim((string) ($row->nama_treatment_master ?? ''));

                return [
                    'id' => (int) $row->id,
                    'pasien_id' => (int) $row->pasien_id,
                    'toko_id' => (int) $row->toko_beli_id,
                    'toko_nama' => $row->nama_toko ?: '-',
                    'treatment_id' => (int) $row->treatment_id,
                    'no_invoice' => $row->no_invoice ?: '-',
                    'no_rm' => $row->no_rm ?: '-',
                    'nama_pasien' => $row->nama_pasien ?: '-',
                    'nama_treatment' => $snapshotName !== ''
                        ? $snapshotName
                        : ($masterName !== '' ? $masterName : '-'),
                    'catatan' => trim((string) ($row->catatan ?? '')) ?: '-',
                    'expired_at' => $expiredAt,
                    'is_expired' => $isExpired,
                    'status_expired' => $isExpired ? 'Kedaluwarsa' : 'Aktif',
                    'qty_sisa' => (float) $row->qty_sisa_calc,
                    'harga_satuan' => (float) $row->harga_satuan,
                    'nilai_sisa' => (float) $row->nilai_sisa_calc,
                    'tanggal_lunas' => $row->tanggal_lunas
                        ? Carbon::parse($row->tanggal_lunas)->toDateString()
                        : null,
                ];
            });
    }

    private function buildReport(array $filters, Collection $rows): array
    {
        return [
            'title' => 'LAPORAN BELUM REALISASI / CLAIM DEPOSIT',
            'company_name' => 'PT. KOSMETIKA KLINIK INDONESIA',
            'company_contact' => 'Email : admin@msglowclinic.id | Website : www.msglowclinic.id',
            'branch_label' => $this->branchLabel($filters['toko_id']),
            'generated_at' => now()->format('d/m/Y H:i:s'),
            'filename_base' => 'laporan-belum-realisasi-deposit-' . now()->format('Ymd-His'),
            'rows' => $rows->all(),
            'totals' => [
                'total_deposit' => $rows->count(),
                'total_pasien' => $rows->pluck('pasien_id')->filter()->unique()->count(),
                'total_qty_sisa' => (float) $rows->sum('qty_sisa'),
                'total_nilai_sisa' => (float) $rows->sum('nilai_sisa'),
                'total_expired' => $rows->where('is_expired', true)->count(),
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
            ? mb_strtoupper((string) $branchName)
            : 'CABANG TIDAK DITEMUKAN';
    }

    private function publicFilters(array $filters): array
    {
        return [
            'toko_id' => $filters['toko_id'],
            'basis_data' => 'Deposit dari invoice lunas dengan sisa klaim lebih dari nol',
            'deposit_expired' => 'Deposit kedaluwarsa tetap ditampilkan selama masih memiliki sisa klaim',
        ];
    }
}
