<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanProdukResepDokterExportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class LaporanProdukResepDokterController extends Controller
{
    public function __construct(
        private readonly LaporanProdukResepDokterExportService $exportService
    ) {
    }

    public function dokter(Request $request)
    {
        try {
            $search = trim((string) $request->query('search', ''));
            $limit = max(1, min((int) $request->query('limit', 50), 100));

            $items = DB::table('master_karyawan as k')
                ->leftJoin('master_jabatan as j', 'j.id', '=', 'k.jabatan_id')
                ->where('k.is_delete', 0)
                ->where(function (Builder $query): void {
                    $query->where('j.kode_jabatan', 'like', '%DOK%')
                        ->orWhere('j.nama_jabatan', 'like', '%dokter%')
                        ->orWhereNotNull('k.no_sip_dok');
                })
                ->when($search !== '', function (Builder $query) use ($search): void {
                    $query->where(function (Builder $searchQuery) use ($search): void {
                        $searchQuery->where('k.nama', 'like', "%{$search}%")
                            ->orWhere('k.kode', 'like', "%{$search}%")
                            ->orWhere('j.nama_jabatan', 'like', "%{$search}%");
                    });
                })
                ->orderBy('k.nama')
                ->limit($limit)
                ->get([
                    'k.id',
                    'k.kode',
                    'k.nama',
                    'k.no_sip_dok',
                    'k.is_dokter_spesialis',
                    'j.nama_jabatan as jabatan',
                ])
                ->map(function (object $item): array {
                    $jabatan = trim((string) ($item->jabatan ?? 'Dokter'));

                    return [
                        'id' => (int) $item->id,
                        'kode' => $item->kode,
                        'nama' => $item->nama,
                        'jabatan' => $jabatan !== '' ? $jabatan : 'Dokter',
                        'no_sip_dok' => $item->no_sip_dok,
                        'is_dokter_spesialis' => (int) ($item->is_dokter_spesialis ?? 0),
                        'label' => trim(($item->nama ?: '-') . ($jabatan !== '' ? ' - ' . $jabatan : '')),
                    ];
                })
                ->values();

            return response()->json([
                'status' => true,
                'message' => 'Data dokter berhasil diambil.',
                'data' => $items,
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil data dokter.',
                'error' => $exception->getMessage(),
            ], 500);
        }
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
                'message' => 'Laporan produk resep dokter berhasil diambil.',
                'data' => [
                    'filters' => $this->publicFilters($filters),
                    'branch_label' => $this->branchLabel($filters['toko_id']),
                    'total_item' => $rows->count(),
                    'total_pasien' => $rows->pluck('pasien_id')->filter()->unique()->count(),
                    'total_qty' => (float) $rows->sum('jumlah'),
                    'grand_total' => (float) $rows->sum('total_harga'),
                    'rows' => $rows->values(),
                ],
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil laporan produk resep dokter.',
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
                'message' => 'Gagal mencetak laporan produk resep dokter.',
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
        $rawDokterId = $request->input('dokter_id');
        $dokterId = is_numeric($rawDokterId) && (int) $rawDokterId > 0
            ? (int) $rawDokterId
            : null;

        $data = [
            'tanggal_awal' => (string) $request->input('tanggal_awal', $today),
            'tanggal_akhir' => (string) $request->input(
                'tanggal_akhir',
                $request->input('tanggal_awal', $today)
            ),
            'dokter_id' => $dokterId,
            'toko_id' => $tokoId,
        ];

        $validator = Validator::make($data, [
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            'dokter_id' => ['required', 'integer', 'exists:master_karyawan,id'],
            'toko_id' => ['nullable', 'integer', 'exists:master_toko,id'],
        ], [
            'dokter_id.required' => 'Dokter wajib dipilih.',
            'tanggal_akhir.after_or_equal' => 'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
        ]);

        return compact('validator', 'data');
    }

    private function getRows(array $filters): Collection
    {
        /*
         * Tanggal laporan mengikuti tanggal operasional registrasi, bukan timestamp
         * finalized_at/created_at. Timestamp dapat bergeser hari ketika aplikasi dan
         * database memakai timezone berbeda, sehingga resep pada tanggal kunjungan
         * tertentu sebelumnya tidak muncul pada laporan.
         */
        $tanggalSql = 'registrasi.tanggal_kunjungan';

        /*
         * Dokter utama tetap berasal dari SOAP. assigned_karyawan_id dipakai sebagai
         * fallback untuk data task lama, kemudian dokter_awal_id sebagai fallback akhir.
         */
        $dokterSql = 'COALESCE(NULLIF(soap.dokter_id, 0), NULLIF(task_dokter.assigned_karyawan_id, 0), NULLIF(registrasi.dokter_awal_id, 0))';
        $totalSql = 'CASE WHEN COALESCE(resep.total, 0) <> 0 THEN resep.total ELSE COALESCE(resep.harga, 0) * COALESCE(resep.jumlah, 0) END';

        $rows = DB::table('registrasi_dokter_resep_detail as resep')
            ->join('registrasi_kunjungan as registrasi', 'registrasi.id', '=', 'resep.registrasi_id')
            ->leftJoin('registrasi_dokter_soap as soap', function ($join): void {
                $join->on('soap.id', '=', 'resep.soap_id')
                    ->on('soap.registrasi_id', '=', 'resep.registrasi_id');
            })
            ->leftJoin('registrasi_task as task_dokter', function ($join): void {
                $join->on('task_dokter.id', '=', 'soap.task_id')
                    ->on('task_dokter.registrasi_id', '=', 'resep.registrasi_id')
                    ->where('task_dokter.is_delete', 0);
            })
            ->leftJoin('pasien as pasien', 'pasien.id', '=', 'registrasi.pasien_id')
            ->leftJoin('master_produk as produk', 'produk.id', '=', 'resep.produk_id')
            ->leftJoin('master_toko as toko', 'toko.id', '=', 'registrasi.toko_id')
            ->leftJoin('master_karyawan as dokter', function ($join) use ($dokterSql): void {
                $join->on('dokter.id', '=', DB::raw($dokterSql));
            })
            ->where('resep.is_delete', 0)
            ->where('registrasi.is_delete', 0)
            ->where('resep.status', '<>', 9)
            ->where('registrasi.status', '<>', 9)
            ->where(function (Builder $query): void {
                $query->whereNull('soap.status')
                    ->orWhere('soap.status', '<>', 9);
            })
            /*
             * Jangan filter is_saran_dokter = 1. Pada proses dokter, produk yang
             * sebelumnya sudah dipilih FO tetap dibuat sebagai resep dengan
             * is_saran_dokter = 0. Selama tercatat pada tabel resep dan aktif, item
             * tersebut tetap merupakan produk resep dokter.
             */
            ->whereRaw("{$dokterSql} = ?", [(int) $filters['dokter_id']])
            ->whereBetween($tanggalSql, [
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
            ])
            ->when(
                $filters['toko_id'],
                fn (Builder $query) => $query->where('registrasi.toko_id', $filters['toko_id'])
            )
            ->orderBy($tanggalSql)
            ->orderBy('pasien.nama')
            ->orderBy('resep.id')
            ->get([
                'resep.id',
                'resep.registrasi_id',
                'resep.soap_id',
                'resep.produk_id',
                'resep.nama_produk',
                'resep.harga',
                'resep.jumlah',
                'resep.total',
                'resep.status',
                'resep.is_saran_dokter',
                'registrasi.toko_id',
                'registrasi.pasien_id',
                'pasien.no_rm',
                'pasien.nama as pasien_nama',
                'produk.nama as produk_nama_master',
                'toko.nama_toko',
                'dokter.nama as dokter_nama',
                DB::raw("{$tanggalSql} as tanggal"),
                DB::raw("{$totalSql} as total_harga"),
            ]);

        return $rows->map(function (object $row): array {
            /* Nama snapshot resep diprioritaskan agar laporan tidak berubah ketika
             * nama produk master diperbarui setelah transaksi. */
            $namaProduk = trim((string) ($row->nama_produk ?: $row->produk_nama_master));

            return [
                'id' => (int) $row->id,
                'registrasi_id' => (int) $row->registrasi_id,
                'soap_id' => $row->soap_id ? (int) $row->soap_id : null,
                'tanggal' => $row->tanggal,
                'toko_id' => $row->toko_id ? (int) $row->toko_id : null,
                'cabang' => $row->nama_toko ?: '-',
                'pasien_id' => $row->pasien_id ? (int) $row->pasien_id : null,
                'no_rm' => $row->no_rm ?: '-',
                'nama_pasien' => $row->pasien_nama ?: '-',
                'dokter_nama' => $row->dokter_nama ?: '-',
                'produk_id' => $row->produk_id ? (int) $row->produk_id : null,
                'nama_produk' => $namaProduk !== '' ? $namaProduk : '-',
                'jumlah' => (float) $row->jumlah,
                'harga_satuan' => (float) $row->harga,
                'total_harga' => (float) $row->total_harga,
                'status_resep' => (int) $row->status,
                'is_saran_dokter' => (int) $row->is_saran_dokter,
            ];
        });
    }

    private function buildReport(array $filters, Collection $rows): array
    {
        $publicFilters = $this->publicFilters($filters);
        $start = Carbon::createFromFormat('Y-m-d', $filters['tanggal_awal'])->locale('id');
        $end = Carbon::createFromFormat('Y-m-d', $filters['tanggal_akhir'])->locale('id');

        return [
            'title' => 'Laporan Produk Resep Dokter',
            'company_name' => 'PT. KOSMETIKA KLINIK INDONESIA',
            'company_contact' => 'Email : admin@msglowclinic.id | Website : www.msglowclinic.id',
            'branch_label' => $this->branchLabel($filters['toko_id']),
            'doctor_name' => $publicFilters['dokter_nama'],
            'period_label' => sprintf(
                '%s s/d %s',
                $start->translatedFormat('d F Y'),
                $end->translatedFormat('d F Y')
            ),
            'generated_at' => now()->format('d/m/Y H:i:s'),
            'filename_base' => sprintf(
                'laporan-produk-resep-dokter-%s-sd-%s',
                $filters['tanggal_awal'],
                $filters['tanggal_akhir']
            ),
            'rows' => $rows->map(function (array $row): array {
                return [
                    'tanggal' => $row['tanggal'],
                    'nama_pasien' => $row['nama_pasien'],
                    'no_rm' => $row['no_rm'],
                    'nama_produk' => $row['nama_produk'],
                    'jumlah' => (float) $row['jumlah'],
                    'harga_satuan' => (float) $row['harga_satuan'],
                    'total_harga' => (float) $row['total_harga'],
                ];
            })->values()->all(),
            'totals' => [
                'qty' => (float) $rows->sum('jumlah'),
                'grand_total' => (float) $rows->sum('total_harga'),
            ],
        ];
    }

    private function publicFilters(array $filters): array
    {
        $doctor = DB::table('master_karyawan')
            ->where('id', $filters['dokter_id'])
            ->first(['id', 'nama']);
        $toko = $filters['toko_id']
            ? DB::table('master_toko')->where('id', $filters['toko_id'])->first(['id', 'nama_toko'])
            : null;

        return [
            'tanggal_awal' => $filters['tanggal_awal'],
            'tanggal_akhir' => $filters['tanggal_akhir'],
            'dokter_id' => (int) $filters['dokter_id'],
            'dokter_nama' => $doctor->nama ?? '-',
            'toko_id' => $filters['toko_id'],
            'toko_nama' => $toko->nama_toko ?? null,
        ];
    }

    private function branchLabel(?int $tokoId): string
    {
        if (! $tokoId) {
            return 'SEMUA CABANG';
        }

        $name = DB::table('master_toko')->where('id', $tokoId)->value('nama_toko');

        return strtoupper((string) ($name ?: 'CABANG'));
    }
}
