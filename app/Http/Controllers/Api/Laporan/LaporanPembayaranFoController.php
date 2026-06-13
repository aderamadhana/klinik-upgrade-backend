<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanPembayaranFoExportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class LaporanPembayaranFoController extends Controller
{
    private const STATUS_LUNAS = 3;
    private const STATUS_BELUM_LUNAS = 4;

    public function __construct(
        private readonly LaporanPembayaranFoExportService $exportService
    ) {
    }

    public function kasir(Request $request)
    {
        try {
            $rawTokoId = $request->input('toko_id', $request->header('X-Toko-Id'));
            $tokoId = is_numeric($rawTokoId) && (int) $rawTokoId > 0
                ? (int) $rawTokoId
                : null;
            $tanggal = (string) $request->input('tanggal', now()->toDateString());

            $validator = Validator::make([
                'tanggal' => $tanggal,
                'toko_id' => $tokoId,
            ], [
                'tanggal' => ['required', 'date_format:Y-m-d'],
                'toko_id' => ['nullable', 'integer', 'exists:master_toko,id'],
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Filter kasir tidak valid.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            return response()->json([
                'status' => true,
                'message' => 'Daftar kasir berhasil diambil.',
                'data' => $this->getCashierOptions($tanggal, $tokoId)->values(),
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil daftar kasir.',
                'error' => $exception->getMessage(),
            ], 500);
        }
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
            $paymentTypes = $this->getPaymentTypeSummary($filters);
            $cashier = $this->resolveCashier($filters['kasir_username']);

            return response()->json([
                'status' => true,
                'message' => 'Laporan pembayaran FO berhasil diambil.',
                'data' => [
                    'filters' => $this->publicFilters($filters),
                    'branch_name' => $this->branchName($filters['toko_id']),
                    'cashier' => $cashier,
                    'totals' => $this->calculateTotals($rows),
                    'rows' => $rows->values(),
                    'payment_types' => $paymentTypes->values(),
                ],
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil laporan pembayaran FO.',
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
            $rows = $this->getRows($filters);
            $report = $this->buildReport(
                $filters,
                $rows,
                $this->getPaymentTypeSummary($filters)
            );

            return $format === 'pdf'
                ? $this->exportService->pdf($report)
                : $this->exportService->excel($report);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mencetak laporan pembayaran FO.',
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
            'tanggal' => (string) $request->input('tanggal', $today),
            'kasir_username' => trim((string) $request->input('kasir_username', '')),
            'toko_id' => $tokoId,
        ];

        $validator = Validator::make($data, [
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'kasir_username' => ['required', 'string', 'max:100'],
            'toko_id' => ['nullable', 'integer', 'exists:master_toko,id'],
        ], [
            'kasir_username.required' => 'Kasir wajib dipilih.',
        ]);

        return compact('validator', 'data');
    }

    private function getRows(array $filters): Collection
    {
        $itemAggregate = DB::table('pembayaran_invoice_item as item')
            ->select('item.pembayaran_id')
            ->selectRaw(
                'SUM(CASE WHEN item.item_type IN (1, 2, 4, 5) THEN item.subtotal_before_diskon_subtotal ELSE 0 END) AS total_treatment'
            )
            ->selectRaw(
                'SUM(CASE WHEN item.item_type = 3 THEN item.subtotal_before_diskon_subtotal ELSE 0 END) AS total_produk'
            )
            ->where('item.status', 1)
            ->where('item.is_delete', 0)
            ->groupBy('item.pembayaran_id');

        return $this->baseInvoiceQuery($filters)
            ->leftJoinSub($itemAggregate, 'item_total', function ($join): void {
                $join->on('item_total.pembayaran_id', '=', 'pi.id');
            })
            ->leftJoin('pasien as pasien', 'pasien.id', '=', 'pi.pasien_id')
            ->leftJoin('master_jenis_transaksi as jenis', 'jenis.id', '=', 'pi.jenis_transaksi')
            ->orderBy('pi.no_invoice')
            ->orderBy('pi.id')
            ->get([
                'pi.id',
                'pi.no_invoice',
                'pi.pasien_id',
                'pi.jenis_transaksi',
                'pi.diskon_subtotal_amount',
                'pi.grand_total',
                'pi.total_bayar',
                'pi.total_kembalian',
                'pi.status',
                'pasien.nama as pasien_nama',
                'jenis.nama_jenis_transaksi',
                DB::raw('COALESCE(item_total.total_treatment, 0) as total_treatment'),
                DB::raw('COALESCE(item_total.total_produk, 0) as total_produk'),
                DB::raw('DATE(COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)) as tanggal_transaksi'),
            ])
            ->map(function (object $row): array {
                $treatment = (float) $row->total_treatment;
                $produk = (float) $row->total_produk;
                $totalPembelian = $treatment + $produk;

                return [
                    'id' => (int) $row->id,
                    'tanggal' => $row->tanggal_transaksi,
                    'faktur' => $row->no_invoice ?: '-',
                    'pasien' => $row->pasien_nama ?: '-',
                    'treatment' => $treatment,
                    'produk' => $produk,
                    'total_pembelian' => $totalPembelian,
                    'diskon_subtotal' => (float) $row->diskon_subtotal_amount,
                    'bayar' => (float) $row->total_bayar,
                    'kembalian' => (float) $row->total_kembalian,
                    'jenis_transaksi_id' => (int) $row->jenis_transaksi,
                    'jenis_transaksi' => $row->nama_jenis_transaksi
                        ?: $this->defaultJenisTransaksiLabel((int) $row->jenis_transaksi),
                    'status_id' => (int) $row->status,
                    'status' => (int) $row->status === self::STATUS_LUNAS,
                    'status_label' => (int) $row->status === self::STATUS_LUNAS
                        ? 'TRUE'
                        : 'FALSE',
                    'grand_total' => (float) $row->grand_total,
                ];
            })
            ->values();
    }

    private function getPaymentTypeSummary(array $filters): Collection
    {
        $activeMethods = DB::table('master_metode_bayar')
            ->where('is_active', 1)
            ->where('is_delete', 0)
            ->orderBy('sort_order')
            ->orderBy('nama')
            ->get(['id', 'nama', 'sort_order']);

        $methodRows = $this->baseInvoiceQuery($filters)
            ->join('pembayaran_invoice_metode as pim', function ($join): void {
                $join->on('pim.pembayaran_id', '=', 'pi.id')
                    ->where('pim.status', 1)
                    ->where('pim.is_delete', 0);
            })
            ->groupBy('pim.metode_bayar_id', 'pim.metode_bayar_nama')
            ->get([
                'pim.metode_bayar_id',
                'pim.metode_bayar_nama',
                DB::raw(
                    "SUM(CASE
                        WHEN UPPER(TRIM(pim.metode_bayar_nama)) = 'CASH'
                        THEN CASE
                            WHEN pim.nominal_diterima > 0
                            THEN GREATEST(pim.nominal_diterima - pim.nominal_kembalian, 0)
                            ELSE pim.nominal_dialokasikan
                        END
                        ELSE pim.nominal_dialokasikan
                    END) AS total"
                ),
            ]);

        $totalsById = $methodRows
            ->filter(fn (object $row): bool => $row->metode_bayar_id !== null)
            ->keyBy(fn (object $row): int => (int) $row->metode_bayar_id);

        $usedNames = [];
        $result = $activeMethods->map(function (object $method) use ($totalsById, &$usedNames): array {
            $normalizedName = mb_strtoupper(trim((string) $method->nama));
            $usedNames[$normalizedName] = true;
            $label = $normalizedName === 'CASH'
                ? 'CASH - KEMBALIAN'
                : $normalizedName;

            return [
                'type' => 'payment_method',
                'key' => 'METHOD-' . (int) $method->id,
                'nama' => $label,
                'jumlah' => (float) ($totalsById[(int) $method->id]->total ?? 0),
                'sort_order' => (int) $method->sort_order,
            ];
        });

        $snapshotOnly = $methodRows
            ->filter(function (object $row) use ($usedNames): bool {
                $name = mb_strtoupper(trim((string) $row->metode_bayar_nama));
                return $name !== '' && ! isset($usedNames[$name]);
            })
            ->map(function (object $row, int $index): array {
                $name = mb_strtoupper(trim((string) $row->metode_bayar_nama));

                return [
                    'type' => 'payment_method',
                    'key' => 'SNAPSHOT-' . md5($name),
                    'nama' => $name === 'CASH' ? 'CASH - KEMBALIAN' : $name,
                    'jumlah' => (float) $row->total,
                    'sort_order' => 1000 + $index,
                ];
            });

        $voucherRows = $this->baseInvoiceQuery($filters)
            ->join('pembayaran_invoice_promo as promo', function ($join): void {
                $join->on('promo.pembayaran_id', '=', 'pi.id')
                    ->where('promo.is_delete', 0);
            })
            ->groupBy('promo.nama_voucher')
            ->orderBy('promo.nama_voucher')
            ->get([
                'promo.nama_voucher',
                DB::raw('SUM(promo.diskon_amount) AS total'),
            ])
            ->map(function (object $row, int $index): array {
                $name = mb_strtoupper(trim((string) $row->nama_voucher));
                $label = str_starts_with($name, 'VOUCHER')
                    ? $name
                    : 'VOUCHER ' . $name;

                return [
                    'type' => 'voucher',
                    'key' => 'VOUCHER-' . md5($name),
                    'nama' => $label,
                    'jumlah' => (float) $row->total,
                    'sort_order' => 2000 + $index,
                ];
            });

        return $result
            ->concat($snapshotOnly)
            ->concat($voucherRows)
            ->sortBy('sort_order')
            ->values()
            ->map(function (array $row, int $index): array {
                unset($row['sort_order']);
                $row['no'] = $index + 1;
                return $row;
            });
    }

    private function baseInvoiceQuery(array $filters): Builder
    {
        $actorSql = "COALESCE(NULLIF(TRIM(pi.updated_by), ''), NULLIF(TRIM(pi.created_by), ''))";

        return DB::table('pembayaran_invoice as pi')
            ->where('pi.is_delete', 0)
            ->whereIn('pi.status', [self::STATUS_LUNAS, self::STATUS_BELUM_LUNAS])
            ->whereRaw('DATE(COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)) = ?', [
                $filters['tanggal'],
            ])
            ->whereRaw("{$actorSql} = ?", [$filters['kasir_username']])
            ->when(
                $filters['toko_id'],
                fn (Builder $query) => $query->where('pi.toko_id', $filters['toko_id'])
            );
    }

    private function getCashierOptions(string $tanggal, ?int $tokoId): Collection
    {
        $users = DB::table('master_user as user')
            ->leftJoin('master_karyawan as karyawan', 'karyawan.id', '=', 'user.karyawan_id')
            ->leftJoin('master_jabatan as jabatan', 'jabatan.id', '=', 'karyawan.jabatan_id')
            ->where('user.is_active', 1)
            ->where('user.is_delete', 0)
            ->where(function (Builder $query): void {
                $query->whereRaw("LOWER(REPLACE(user.role_name, '_', ' ')) = 'front office'")
                    ->orWhereRaw("UPPER(COALESCE(jabatan.kode_jabatan, '')) = 'FO'")
                    ->orWhereRaw("LOWER(COALESCE(jabatan.nama_jabatan, '')) = 'front office'");
            })
            ->when($tokoId, function (Builder $query) use ($tokoId): void {
                $query->whereExists(function (Builder $placement) use ($tokoId): void {
                    $placement->selectRaw('1')
                        ->from('master_user_penempatan as placement')
                        ->whereColumn('placement.user_id', 'user.id')
                        ->where('placement.toko_id', $tokoId)
                        ->where('placement.is_active', 1)
                        ->where('placement.is_delete', 0);
                });
            })
            ->orderByRaw('COALESCE(karyawan.nama, user.display_name, user.nama, user.username)')
            ->get([
                'user.id',
                'user.username',
                'user.display_name',
                'user.nama as user_nama',
                'karyawan.nama as karyawan_nama',
            ])
            ->map(function (object $row): array {
                return [
                    'id' => (int) $row->id,
                    'username' => (string) $row->username,
                    'nama' => $row->karyawan_nama
                        ?: ($row->display_name ?: ($row->user_nama ?: $row->username)),
                ];
            });

        $actors = DB::table('pembayaran_invoice as pi')
            ->where('pi.is_delete', 0)
            ->whereIn('pi.status', [self::STATUS_LUNAS, self::STATUS_BELUM_LUNAS])
            ->whereRaw('DATE(COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)) = ?', [$tanggal])
            ->when($tokoId, fn (Builder $query) => $query->where('pi.toko_id', $tokoId))
            ->whereRaw("COALESCE(NULLIF(TRIM(pi.updated_by), ''), NULLIF(TRIM(pi.created_by), '')) IS NOT NULL")
            ->selectRaw("COALESCE(NULLIF(TRIM(pi.updated_by), ''), NULLIF(TRIM(pi.created_by), '')) AS actor_username")
            ->distinct()
            ->pluck('actor_username')
            ->map(fn ($username): string => trim((string) $username))
            ->filter();

        $knownUsernames = $users->pluck('username')->map(fn ($value): string => mb_strtolower($value))->all();
        $missingActors = $actors
            ->reject(fn (string $username): bool => in_array(mb_strtolower($username), $knownUsernames, true))
            ->map(function (string $username): array {
                $cashier = $this->resolveCashier($username);

                return [
                    'id' => $cashier['id'],
                    'username' => $username,
                    'nama' => $cashier['nama'],
                ];
            });

        return $users
            ->concat($missingActors)
            ->unique(fn (array $row): string => mb_strtolower($row['username']))
            ->sortBy(fn (array $row): string => mb_strtoupper($row['nama']))
            ->values();
    }

    private function resolveCashier(string $username): array
    {
        $row = DB::table('master_user as user')
            ->leftJoin('master_karyawan as karyawan', 'karyawan.id', '=', 'user.karyawan_id')
            ->where('user.username', $username)
            ->first([
                'user.id',
                'user.username',
                'user.display_name',
                'user.nama as user_nama',
                'karyawan.nama as karyawan_nama',
            ]);

        return [
            'id' => $row?->id ? (int) $row->id : null,
            'username' => $username,
            'nama' => $row?->karyawan_nama
                ?: ($row?->display_name ?: ($row?->user_nama ?: $username)),
        ];
    }

    private function calculateTotals(Collection $rows): array
    {
        return [
            'total_invoice' => $rows->count(),
            'total_treatment' => (float) $rows->sum('treatment'),
            'total_produk' => (float) $rows->sum('produk'),
            'total_pembelian' => (float) $rows->sum('total_pembelian'),
            'total_diskon_subtotal' => (float) $rows->sum('diskon_subtotal'),
            'total_bayar' => (float) $rows->sum('bayar'),
            'total_kembalian' => (float) $rows->sum('kembalian'),
            'total_lunas' => $rows->where('status', true)->count(),
            'total_belum_lunas' => $rows->where('status', false)->count(),
        ];
    }

    private function buildReport(
        array $filters,
        Collection $rows,
        Collection $paymentTypes
    ): array {
        $date = Carbon::createFromFormat('Y-m-d', $filters['tanggal'])->locale('id');
        $cashier = $this->resolveCashier($filters['kasir_username']);
        $branchName = $this->branchName($filters['toko_id']);

        return [
            'title' => 'Data Laporan Pembayaran FO',
            'company_name' => 'PT. KOSMETIKA KLINIK INDONESIA',
            'company_contact' => 'Email : admin@msglowclinic.id | Website : www.msglowclinic.id',
            'branch_name' => mb_strtoupper($branchName),
            'cashier_name' => $cashier['nama'],
            'cashier_username' => $cashier['username'],
            'date_label' => $date->translatedFormat('d F Y'),
            'date_raw' => $filters['tanggal'],
            'generated_at' => now()->format('d/m/Y H:i:s'),
            'filename_base' => sprintf(
                'laporan-pembayaran-fo-%s-%s',
                preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($cashier['username'])),
                $filters['tanggal']
            ),
            'rows' => $rows
                ->map(function (array $row, int $index): array {
                    $row['no'] = $index + 1;
                    return $row;
                })
                ->values()
                ->all(),
            'payment_types' => $paymentTypes->all(),
            'totals' => $this->calculateTotals($rows),
        ];
    }

    private function publicFilters(array $filters): array
    {
        return [
            'tanggal' => $filters['tanggal'],
            'kasir_username' => $filters['kasir_username'],
            'toko_id' => $filters['toko_id'],
            'tanggal_berdasarkan' => 'Tanggal lunas, fallback tanggal invoice untuk transaksi belum lunas',
            'status_invoice' => 'Lunas dan belum lunas',
            'kasir_berdasarkan' => 'updated_by, fallback created_by',
        ];
    }

    private function branchName(?int $tokoId): string
    {
        if (! $tokoId) {
            return 'Semua Cabang';
        }

        return DB::table('master_toko')
            ->where('id', $tokoId)
            ->value('nama_toko') ?: 'Cabang Tidak Ditemukan';
    }

    private function defaultJenisTransaksiLabel(int $id): string
    {
        return match ($id) {
            0 => 'Umum',
            1 => 'Endorse / Fasilitas Karyawan',
            2 => 'EliteGlowbal',
            3 => 'Owner',
            4 => 'Deposit',
            default => 'Tidak diketahui',
        };
    }
}
