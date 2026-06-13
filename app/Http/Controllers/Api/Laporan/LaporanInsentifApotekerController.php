<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanInsentifApotekerExportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class LaporanInsentifApotekerController extends Controller
{
    public function __construct(
        private readonly LaporanInsentifApotekerExportService $exportService
    ) {
    }

    public function petugas(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $limit = max(1, min((int) $request->query('limit', 50), 100));

        $query = DB::table('master_karyawan as k')
            ->leftJoin('master_jabatan as j', 'j.id', '=', 'k.jabatan_id')
            ->where(function (Builder $builder): void {
                $builder->where('k.is_delete', 0)
                    ->orWhereNull('k.is_delete');
            })
            ->where(function (Builder $builder): void {
                $builder->whereIn('j.kode_jabatan', ['AP', 'AA'])
                    ->orWhere('j.nama_jabatan', 'like', '%apoteker%')
                    ->orWhere('j.nama_jabatan', 'like', '%farmasi%');
            });

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('k.nama', 'like', "%{$search}%")
                    ->orWhere('k.kode', 'like', "%{$search}%")
                    ->orWhere('j.nama_jabatan', 'like', "%{$search}%");
            });
        }

        $items = $query
            ->orderBy('j.sort_order')
            ->orderBy('k.nama')
            ->limit($limit)
            ->get([
                'k.id',
                'k.kode',
                'k.nama',
                'j.kode_jabatan',
                'j.nama_jabatan as jabatan',
            ])
            ->map(static function (object $item): array {
                $jabatan = $item->jabatan ?: 'Apoteker / Asisten Apoteker';

                return [
                    'id' => (int) $item->id,
                    'kode' => $item->kode,
                    'nama' => $item->nama,
                    'jabatan' => $jabatan,
                    'kode_jabatan' => $item->kode_jabatan,
                    'label' => trim(($item->nama ?: '-') . ' - ' . $jabatan),
                ];
            })
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Data apoteker berhasil diambil.',
            'data' => $items,
        ]);
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
        $rows = $this->getResepRows($filters);
        $summary = $this->makeSummary($rows);

        return response()->json([
            'status' => true,
            'message' => 'Ringkasan insentif apoteker berhasil diambil.',
            'data' => [
                'filters' => $this->publicFilters($filters),
                'resep' => $summary['resep'],
                // Dipertahankan agar FE lama tidak langsung rusak.
                'produk' => $summary['produk'],
                'grand_total_insentif' => $summary['resep']['total_insentif'],
            ],
        ]);
    }

    public function export(Request $request, string $format): Response|BinaryFileResponse|JsonResponse
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
        $rows = $this->getResepRows($filters);
        $publicFilters = $this->publicFilters($filters);
        $filename = $this->filename($format, $filters);

        if ($format === 'excel') {
            return $this->exportService->excel(
                rows: $rows,
                filters: $publicFilters,
                filename: $filename,
            );
        }

        return $this->exportService->pdf(
            rows: $rows,
            filters: $publicFilters,
            filename: $filename,
        );
    }

    /**
     * @return array{validator: LaravelValidator, data: array<string, mixed>}
     */
    private function normalizeFilters(Request $request): array
    {
        $today = now()->toDateString();
        $tokoId = $request->query('toko_id', $request->header('X-Toko-Id'));
        $petugasId = $request->query('apoteker_id', $request->query('petugas_id'));

        $data = [
            'tanggal_awal' => $request->query('tanggal_awal', $today),
            'tanggal_akhir' => $request->query(
                'tanggal_akhir',
                $request->query('tanggal_awal', $today)
            ),
            'apoteker_id' => is_numeric($petugasId) ? (int) $petugasId : null,
            'toko_id' => is_numeric($tokoId) ? (int) $tokoId : null,
        ];

        $validator = Validator::make($data, [
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            'apoteker_id' => ['nullable', 'integer', 'exists:master_karyawan,id'],
            'toko_id' => ['nullable', 'integer', 'exists:master_toko,id'],
        ], [
            'tanggal_akhir.after_or_equal' => 'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
        ]);

        return [
            'validator' => $validator,
            'data' => $data,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function publicFilters(array $filters): array
    {
        $petugas = null;
        $toko = null;

        if (! empty($filters['apoteker_id'])) {
            $petugas = DB::table('master_karyawan as k')
                ->leftJoin('master_jabatan as j', 'j.id', '=', 'k.jabatan_id')
                ->where('k.id', (int) $filters['apoteker_id'])
                ->first([
                    'k.id',
                    'k.nama',
                    'j.nama_jabatan as jabatan',
                ]);
        }

        if (! empty($filters['toko_id'])) {
            $toko = DB::table('master_toko')
                ->where('id', (int) $filters['toko_id'])
                ->first(['id', 'nama_toko']);
        }

        return [
            'tanggal_awal' => $filters['tanggal_awal'],
            'tanggal_akhir' => $filters['tanggal_akhir'],
            'apoteker_id' => $filters['apoteker_id'] ? (int) $filters['apoteker_id'] : null,
            'apoteker_nama' => $petugas->nama ?? null,
            'apoteker_jabatan' => $petugas->jabatan ?? null,
            'toko_id' => $filters['toko_id'] ? (int) $filters['toko_id'] : null,
            'toko_nama' => $toko->nama_toko ?? null,
            'fee_per_resep' => $this->feePerResep(),
        ];
    }

    /**
     * Menghasilkan satu baris untuk satu resep/faktur selesai.
     * Dengan demikian faktur yang memiliki banyak item obat tidak dihitung ganda.
     *
     * @param array<string, mixed> $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function getResepRows(array $filters): Collection
    {
        $tanggalSql = 'COALESCE(far.finished_at, pi.tanggal_lunas, pi.tanggal_invoice)';
        $feePerResep = $this->feePerResep();

        $produkAggregate = DB::table('pembayaran_invoice_item as item')
            ->selectRaw(
                'item.pembayaran_id,
                 COUNT(item.id) as total_item_produk,
                 SUM(item.qty) as total_qty_produk,
                 SUM(
                    COALESCE(
                        NULLIF(item.subtotal_after_diskon_subtotal, 0),
                        NULLIF(item.subtotal, 0),
                        item.qty * item.harga
                    )
                 ) as total_omzet_produk'
            )
            ->where('item.item_type', 3)
            ->where('item.status', 1)
            ->where('item.is_delete', 0)
            ->groupBy('item.pembayaran_id');

        $query = DB::table('farmasi_antrian_resep as far')
            ->join('pembayaran_invoice as pi', 'pi.id', '=', 'far.pembayaran_id')
            ->leftJoinSub($produkAggregate, 'produk', static function ($join): void {
                $join->on('produk.pembayaran_id', '=', 'pi.id');
            })
            ->leftJoin('master_toko as mt', 'mt.id', '=', 'pi.toko_id')
            ->leftJoin('pasien as ps', 'ps.id', '=', 'pi.pasien_id')
            ->leftJoin('master_karyawan as kp', 'kp.id', '=', 'far.petugas_karyawan_id')
            ->leftJoin('master_jabatan as jp', 'jp.id', '=', 'kp.jabatan_id')
            ->where('far.status', 2)
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->whereNotNull('far.petugas_karyawan_id')
            ->whereRaw("DATE({$tanggalSql}) BETWEEN ? AND ?", [
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
            ]);

        if (! empty($filters['apoteker_id'])) {
            $query->where('far.petugas_karyawan_id', (int) $filters['apoteker_id']);
        }

        if (! empty($filters['toko_id'])) {
            $query->where('pi.toko_id', (int) $filters['toko_id']);
        }

        return $query
            ->selectRaw(
                "far.id as resep_id,
                 DATE({$tanggalSql}) as tanggal,
                 far.finished_at,
                 pi.id as pembayaran_id,
                 pi.no_invoice,
                 pi.toko_id,
                 mt.nama_toko,
                 ps.no_rm,
                 ps.nama as pasien_nama,
                 far.petugas_karyawan_id as apoteker_id,
                 COALESCE(kp.nama, far.petugas_nama_snapshot) as apoteker_nama,
                 COALESCE(jp.nama_jabatan, far.petugas_jabatan_snapshot) as apoteker_jabatan,
                 COALESCE(produk.total_item_produk, 0) as total_item_produk,
                 COALESCE(produk.total_qty_produk, 0) as total_qty_produk,
                 COALESCE(produk.total_omzet_produk, 0) as total_omzet_produk"
            )
            ->orderBy('apoteker_nama')
            ->orderBy('tanggal')
            ->orderBy('pi.no_invoice')
            ->get()
            ->map(static function (object $row) use ($feePerResep): array {
                return [
                    'resep_id' => (int) $row->resep_id,
                    'tanggal' => $row->tanggal,
                    'finished_at' => $row->finished_at,
                    'pembayaran_id' => (int) $row->pembayaran_id,
                    'no_invoice' => $row->no_invoice ?: '-',
                    'toko_id' => (int) $row->toko_id,
                    'toko_nama' => $row->nama_toko ?: '-',
                    'no_rm' => $row->no_rm,
                    'pasien_nama' => $row->pasien_nama,
                    'apoteker_id' => (int) $row->apoteker_id,
                    'apoteker_nama' => $row->apoteker_nama ?: '-',
                    'apoteker_jabatan' => $row->apoteker_jabatan ?: 'Apoteker / Asisten Apoteker',
                    'total_item_produk' => (int) $row->total_item_produk,
                    'total_qty_produk' => (float) $row->total_qty_produk,
                    'total_omzet_produk' => (float) $row->total_omzet_produk,
                    'fee' => $feePerResep,
                    'nilai_insentif' => $feePerResep,
                ];
            })
            ->values();
    }

    /**
     * @return array{
     *   resep: array<string, float|int>,
     *   produk: array<string, float|int>
     * }
     */
    private function makeSummary(Collection $rows): array
    {
        $totalResep = $rows->count();
        $totalInsentif = (float) $rows->sum('nilai_insentif');

        return [
            'resep' => [
                'total_resep' => $totalResep,
                'total_faktur' => $totalResep,
                'fee_per_resep' => $this->feePerResep(),
                'total_insentif' => $totalInsentif,
            ],
            'produk' => [
                'total_item' => (int) $rows->sum('total_item_produk'),
                'total_qty' => (float) $rows->sum('total_qty_produk'),
                'total_omzet' => (float) $rows->sum('total_omzet_produk'),
                'total_insentif' => $totalInsentif,
            ],
        ];
    }

    private function feePerResep(): float
    {
        return max(
            0,
            (float) config('laporan.insentif_apoteker.fee_per_resep', 2000)
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function filename(string $format, array $filters): string
    {
        $extension = $format === 'excel' ? 'xlsx' : 'pdf';

        return implode('-', [
            'laporan',
            'insentif',
            'apoteker',
            Carbon::parse($filters['tanggal_awal'])->format('Ymd'),
            Carbon::parse($filters['tanggal_akhir'])->format('Ymd'),
        ]) . '.' . $extension;
    }
}
