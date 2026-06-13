<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanPasienPalingSeringBelanjaExportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class LaporanPasienPalingSeringBelanjaController extends Controller
{
    public function __construct(
        private readonly LaporanPasienPalingSeringBelanjaExportService $exportService
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
            $totalTransaksi = (int) $rows->sum('jumlah_transaksi');
            $totalInvoice = (int) $rows->sum('jumlah_invoice');
            $totalNominal = (float) $rows->sum('total_nominal');

            return response()->json([
                'status' => true,
                'message' => 'Data pasien paling sering belanja berhasil diambil.',
                'data' => [
                    'filters' => $this->publicFilters($filters),
                    'branch_label' => $this->branchLabel($filters['toko_id']),
                    'total_pasien' => $rows->count(),
                    'total_transaksi' => $totalTransaksi,
                    'total_invoice' => $totalInvoice,
                    'total_nominal' => $totalNominal,
                    'rata_rata_per_transaksi' => $totalTransaksi > 0
                        ? round($totalNominal / $totalTransaksi, 2)
                        : 0,
                    'rows' => $rows->values(),
                    'top_pasien' => $rows->first(),
                ],
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil laporan pasien paling sering belanja.',
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
                'message' => 'Gagal mencetak laporan pasien paling sering belanja.',
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

        $rawPeringkat = $request->input('peringkat', $request->input('limit', 10));
        $peringkat = is_numeric($rawPeringkat) ? (int) $rawPeringkat : $rawPeringkat;

        $data = [
            'tanggal_awal' => (string) $request->input('tanggal_awal', $today),
            'tanggal_akhir' => (string) $request->input(
                'tanggal_akhir',
                $request->input('tanggal_awal', $today)
            ),
            'peringkat' => $peringkat,
            'toko_id' => $tokoId,
        ];

        $validator = Validator::make($data, [
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            'peringkat' => ['required', 'integer', 'min:1', 'max:1000'],
            'toko_id' => ['nullable', 'integer', 'exists:master_toko,id'],
        ], [
            'tanggal_akhir.after_or_equal' => 'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
            'peringkat.min' => 'Peringkat minimal 1.',
            'peringkat.max' => 'Peringkat maksimal 1000 agar laporan tetap ringan.',
        ]);

        return compact('validator', 'data');
    }

    private function getRows(array $filters): Collection
    {
        $startAt = Carbon::createFromFormat('Y-m-d', $filters['tanggal_awal'])->startOfDay();
        $endAt = Carbon::createFromFormat('Y-m-d', $filters['tanggal_akhir'])->endOfDay();

        $rows = DB::table('pembayaran_invoice as pi')
            ->join('pasien as pasien', 'pasien.id', '=', 'pi.pasien_id')
            ->leftJoin('master_toko as toko', 'toko.id', '=', 'pi.toko_id')
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->where('pi.grand_total', '>', 0)
            ->where('pasien.tipe_pasien', 1)
            ->where('pasien.is_delete', 0)
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
            ->groupBy([
                'pasien.id',
                'pasien.no_rm',
                'pasien.nama',
                'pasien.no_hp',
                'pasien.no_wa',
            ])
            ->selectRaw("
                pasien.id as pasien_id,
                pasien.no_rm,
                pasien.nama as nama_pasien,
                pasien.no_hp,
                pasien.no_wa,
                GROUP_CONCAT(DISTINCT toko.nama_toko ORDER BY toko.nama_toko SEPARATOR ', ') as cabang,
                COUNT(DISTINCT pi.registrasi_id) as jumlah_transaksi,
                COUNT(DISTINCT pi.id) as jumlah_invoice,
                SUM(pi.grand_total) as total_nominal,
                MAX(COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)) as transaksi_terakhir
            " )
            ->orderByDesc('jumlah_transaksi')
            ->orderByDesc('total_nominal')
            ->orderBy('pasien.nama')
            ->limit((int) $filters['peringkat'])
            ->get();

        return $rows->map(function (object $row, int $index): array {
            $jumlahTransaksi = (int) $row->jumlah_transaksi;
            $totalNominal = (float) $row->total_nominal;

            return [
                'peringkat' => $index + 1,
                'pasien_id' => (int) $row->pasien_id,
                'no_rm' => $row->no_rm ?: '-',
                'nama_pasien' => $row->nama_pasien ?: '-',
                'no_hp' => $this->normalizePhone($row->no_wa ?: $row->no_hp),
                'cabang' => $row->cabang ?: '-',
                'jumlah_transaksi' => $jumlahTransaksi,
                'jumlah_invoice' => (int) $row->jumlah_invoice,
                'total_nominal' => $totalNominal,
                'rata_rata_transaksi' => $jumlahTransaksi > 0
                    ? round($totalNominal / $jumlahTransaksi, 2)
                    : 0,
                'transaksi_terakhir' => $row->transaksi_terakhir
                    ? Carbon::parse($row->transaksi_terakhir)->format('Y-m-d H:i:s')
                    : null,
            ];
        });
    }

    private function buildReport(array $filters, Collection $rows): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $filters['tanggal_awal'])->locale('id');
        $end = Carbon::createFromFormat('Y-m-d', $filters['tanggal_akhir'])->locale('id');

        return [
            'title' => 'Data Pasien Paling Sering Belanja',
            'company_name' => 'PT. KOSMETIKA KLINIK INDONESIA',
            'company_contact' => 'Email : admin@msglowclinic.id | Website : www.msglowclinic.id',
            'branch_label' => $this->branchLabel($filters['toko_id']),
            'period_label' => sprintf(
                '%s s/d %s',
                $start->translatedFormat('d F Y'),
                $end->translatedFormat('d F Y')
            ),
            'peringkat_label' => 'Top ' . (int) $filters['peringkat'],
            'generated_at' => now()->format('d/m/Y H:i:s'),
            'filename_base' => sprintf(
                'data-pasien-paling-sering-belanja-%s-sd-%s-top-%d',
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
                (int) $filters['peringkat']
            ),
            'rows' => $rows->map(function (array $row): array {
                return [
                    'no' => (int) $row['peringkat'],
                    'nama_pasien' => $row['nama_pasien'],
                    'jumlah_transaksi' => (int) $row['jumlah_transaksi'],
                    'total_nominal' => (float) $row['total_nominal'],
                ];
            })->values()->all(),
            'totals' => [
                'jumlah_transaksi' => (int) $rows->sum('jumlah_transaksi'),
                'total_nominal' => (float) $rows->sum('total_nominal'),
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
            'peringkat' => (int) $filters['peringkat'],
            'toko_id' => $filters['toko_id'],
            'tanggal_berdasarkan' => 'Tanggal lunas invoice',
            'jumlah_transaksi_berdasarkan' => 'Registrasi unik',
            'nominal_berdasarkan' => 'Grand total invoice lunas bernilai lebih dari nol',
        ];
    }

    private function normalizePhone(?string $value): string
    {
        $phone = preg_replace('/\D+/', '', (string) $value);

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
}
