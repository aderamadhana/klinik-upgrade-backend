<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DashboardController extends Controller
{
    public function summary(Request $request)
    {
        try {
            $dateFrom = Carbon::parse(
                $request->input('date_from', now()->toDateString())
            )->toDateString();

            $dateTo = Carbon::parse(
                $request->input('date_to', $dateFrom)
            )->toDateString();

            $tokoId = $this->resolveTokoId($request);

            $invoiceLunas = $this->countInvoices($dateFrom, $dateTo, $tokoId, function ($query) {
                $query->where('status', 3);
            });

            $revenue = $this->sumPaidRevenue($dateFrom, $dateTo, $tokoId);

            $registrasi = $this->countRegistrasi($dateFrom, $dateTo, $tokoId);
            $antrianDokter = $this->countTasks($dateFrom, $dateTo, $tokoId, [1], [0, 1]);
            $antrianPerawat = $this->countTasks($dateFrom, $dateTo, $tokoId, [3], [0, 1]);
            $stokMenipis = $this->countStockLow($tokoId, false);
            $produkHabis = $this->countStockLow($tokoId, true);
            $auditTrail = $this->countAuditLogs($dateFrom, $dateTo, $tokoId);

            $cards = [
                'transaksi' => $this->countInvoices($dateFrom, $dateTo, $tokoId),
                'registrasi' => $registrasi,
                'pelayanan' => $this->countTasks($dateFrom, $dateTo, $tokoId, [1, 2, 3], [0, 1, 2]),
                'stok' => $stokMenipis + $produkHabis,

                'invoice_lunas' => $invoiceLunas,
                'invoice_belum_lunas' => $this->countInvoices($dateFrom, $dateTo, $tokoId, function ($query) {
                    $query->whereIn('status', [0, 1, 2, 4]);
                }),
                'metode_bayar' => $this->countPaymentMethods($dateFrom, $dateTo, $tokoId),
                'selisih' => $this->countPaymentIssue($dateFrom, $dateTo, $tokoId),

                'revenue' => $revenue,
                'visit' => $registrasi,
                'aov' => $invoiceLunas > 0 ? round($revenue / $invoiceLunas) : 0,
                'cabang_aktif' => $this->countActiveBranch($dateFrom, $dateTo, $tokoId),

                'antrian_dokter' => $antrianDokter,
                'antrian_perawat' => $antrianPerawat,
                'belum_bayar' => $this->countBelumBayar($dateFrom, $dateTo, $tokoId),

                'soap_draft' => $this->countSoapDraft($dateFrom, $dateTo, $tokoId),
                'konsultasi_selesai' => $this->countTasks($dateFrom, $dateTo, $tokoId, [1], [2]),
                'treatment' => $this->countRegistrasi($dateFrom, $dateTo, $tokoId, function ($query) {
                    $query->where('is_treatment', 1);
                }),

                'resep' => $this->countResep($dateFrom, $dateTo, $tokoId),
                'stok_menipis' => $stokMenipis,
                'mutasi' => $this->countStockMutation($dateFrom, $dateTo, $tokoId),
                'produk_habis' => $produkHabis,

                'error_log' => $this->countErrorLog($dateFrom, $dateTo, $tokoId),
                'user_login' => $this->countUserLogin($dateFrom, $dateTo, $tokoId),
                'audit_trail' => $auditTrail,
                'integrasi' => $this->countIntegrationIssue($dateFrom, $dateTo),

                'pasien_hadir' => $registrasi,
                'antrian' => $this->countQueue($dateFrom, $dateTo, $tokoId),
                'jam_ramai' => $this->countPeakHour($dateFrom, $dateTo, $tokoId),
                'catatan' => $auditTrail,

                'follow_up' => $this->countPasienBaru($dateFrom, $dateTo, $tokoId),
                'promo' => $this->countActivePromo($tokoId),
                'voucher' => $this->countActiveVoucher($tokoId),
                'lead' => $this->countPasienBaru($dateFrom, $dateTo, $tokoId),

                'master_data' => $this->countMasterData(),
                'user_aktif' => $this->countActiveUser(),
                'cabang' => $this->countToko(),
                'audit' => $auditTrail,
            ];

            return response()->json([
                'status' => true,
                'message' => 'Dashboard summary berhasil diambil',
                'data' => [
                    'periode' => [
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                        'toko_id' => $tokoId,
                        'generated_at' => now()->toDateTimeString(),
                    ],
                    'cards' => $cards,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil dashboard summary',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function resolveTokoId(Request $request): ?int
    {
        $value = $request->input('toko_id') ?: $request->header('X-Toko-Id');

        if (!$value || strtolower((string) $value) === 'all') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function invoiceQuery(string $dateFrom, string $dateTo, ?int $tokoId): Builder
    {
        $query = DB::table('pembayaran_invoice')
            ->where('is_delete', 0);

        $this->applyDateRange($query, 'tanggal_invoice', $dateFrom, $dateTo);
        $this->applyToko($query, $tokoId);

        return $query;
    }

    private function registrasiQuery(string $dateFrom, string $dateTo, ?int $tokoId): Builder
    {
        $query = DB::table('registrasi_kunjungan')
            ->where('is_delete', 0);

        $this->applyDateRange($query, 'tanggal_kunjungan', $dateFrom, $dateTo);
        $this->applyToko($query, $tokoId);

        return $query;
    }

    private function taskQuery(string $dateFrom, string $dateTo, ?int $tokoId): Builder
    {
        $query = DB::table('registrasi_task as rt')
            ->join('registrasi_kunjungan as rk', 'rk.id', '=', 'rt.registrasi_id')
            ->where('rk.is_delete', 0);

        $this->applyDateRange($query, 'rk.tanggal_kunjungan', $dateFrom, $dateTo);

        if ($tokoId) {
            $query->where('rk.toko_id', $tokoId);
        }

        return $query;
    }

    private function countInvoices(string $dateFrom, string $dateTo, ?int $tokoId, ?callable $callback = null): int
    {
        if (!Schema::hasTable('pembayaran_invoice')) {
            return 0;
        }

        $query = $this->invoiceQuery($dateFrom, $dateTo, $tokoId);

        if ($callback) {
            $callback($query);
        }

        return (int) $query->count();
    }

    private function sumPaidRevenue(string $dateFrom, string $dateTo, ?int $tokoId): float
    {
        if (!Schema::hasTable('pembayaran_invoice')) {
            return 0;
        }

        return (float) $this->invoiceQuery($dateFrom, $dateTo, $tokoId)
            ->where('status', 3)
            ->sum('grand_total');
    }

    private function countPaymentMethods(string $dateFrom, string $dateTo, ?int $tokoId): int
    {
        if (!Schema::hasTable('pembayaran_invoice_metode') || !Schema::hasTable('pembayaran_invoice')) {
            return 0;
        }

        $query = DB::table('pembayaran_invoice_metode as pim')
            ->join('pembayaran_invoice as pi', 'pi.id', '=', 'pim.pembayaran_id')
            ->where('pim.is_delete', 0)
            ->where('pi.is_delete', 0);

        $this->applyDateRange($query, 'pi.tanggal_invoice', $dateFrom, $dateTo);

        if ($tokoId) {
            $query->where('pi.toko_id', $tokoId);
        }

        return (int) $query->count();
    }

    private function countPaymentIssue(string $dateFrom, string $dateTo, ?int $tokoId): int
    {
        if (!Schema::hasTable('pembayaran_invoice')) {
            return 0;
        }

        return (int) $this->invoiceQuery($dateFrom, $dateTo, $tokoId)
            ->where(function ($query) {
                $query->where('status', 4)
                    ->orWhere('sisa_tagihan', '>', 0);
            })
            ->count();
    }

    private function countActiveBranch(string $dateFrom, string $dateTo, ?int $tokoId): int
    {
        if (!Schema::hasTable('pembayaran_invoice')) {
            return 0;
        }

        if ($tokoId) {
            return $this->countInvoices($dateFrom, $dateTo, $tokoId, function ($query) {
                $query->where('status', 3);
            }) > 0 ? 1 : 0;
        }

        return (int) $this->invoiceQuery($dateFrom, $dateTo, null)
            ->where('status', 3)
            ->distinct('toko_id')
            ->count('toko_id');
    }

    private function countRegistrasi(string $dateFrom, string $dateTo, ?int $tokoId, ?callable $callback = null): int
    {
        if (!Schema::hasTable('registrasi_kunjungan')) {
            return 0;
        }

        $query = $this->registrasiQuery($dateFrom, $dateTo, $tokoId);

        if ($callback) {
            $callback($query);
        }

        return (int) $query->count();
    }

    private function countBelumBayar(string $dateFrom, string $dateTo, ?int $tokoId): int
    {
        if (!Schema::hasTable('registrasi_kunjungan')) {
            return 0;
        }

        return (int) $this->registrasiQuery($dateFrom, $dateTo, $tokoId)
            ->where('current_task', 4)
            ->whereIn('status', [0, 1])
            ->count();
    }

    private function countTasks(string $dateFrom, string $dateTo, ?int $tokoId, array $taskTypes, array $statuses): int
    {
        if (!Schema::hasTable('registrasi_task') || !Schema::hasTable('registrasi_kunjungan')) {
            return 0;
        }

        return (int) $this->taskQuery($dateFrom, $dateTo, $tokoId)
            ->whereIn('rt.task_type', $taskTypes)
            ->whereIn('rt.status', $statuses)
            ->count();
    }

    private function countSoapDraft(string $dateFrom, string $dateTo, ?int $tokoId): int
    {
        if (!Schema::hasTable('registrasi_dokter_soap') || !Schema::hasTable('registrasi_kunjungan')) {
            return 0;
        }

        $query = DB::table('registrasi_dokter_soap as soap')
            ->join('registrasi_kunjungan as rk', 'rk.id', '=', 'soap.registrasi_id')
            ->where('rk.is_delete', 0)
            ->where(function ($query) {
                $query->whereNull('soap.finalized_at')
                    ->orWhere('soap.status', '<>', 2);
            });

        $this->applyDateRange($query, 'rk.tanggal_kunjungan', $dateFrom, $dateTo);

        if ($tokoId) {
            $query->where('rk.toko_id', $tokoId);
        }

        return (int) $query->count();
    }

    private function countResep(string $dateFrom, string $dateTo, ?int $tokoId): int
    {
        if (!Schema::hasTable('registrasi_dokter_resep_detail') || !Schema::hasTable('registrasi_kunjungan')) {
            return 0;
        }

        $query = DB::table('registrasi_dokter_resep_detail as resep')
            ->join('registrasi_kunjungan as rk', 'rk.id', '=', 'resep.registrasi_id')
            ->where('rk.is_delete', 0)
            ->where('resep.is_delete', 0);

        $this->applyDateRange($query, 'rk.tanggal_kunjungan', $dateFrom, $dateTo);

        if ($tokoId) {
            $query->where('rk.toko_id', $tokoId);
        }

        return (int) $query->count();
    }

    private function countStockLow(?int $tokoId, bool $onlyEmpty): int
    {
        if (!Schema::hasTable('stock_produk_toko')) {
            return 0;
        }

        $query = DB::table('stock_produk_toko')
            ->where('is_delete', 0);

        if ($tokoId) {
            $query->where('toko_id', $tokoId);
        }

        if ($onlyEmpty) {
            $query->whereRaw('(COALESCE(stok_akhir, 0) - COALESCE(stok_reserved, 0)) <= 0');
        } else {
            $query->whereRaw('(COALESCE(stok_akhir, 0) - COALESCE(stok_reserved, 0)) > 0')
                ->whereRaw('(COALESCE(stok_akhir, 0) - COALESCE(stok_reserved, 0)) <= COALESCE(stok_minimum, 0)');
        }

        return (int) $query->count();
    }

    private function countStockMutation(string $dateFrom, string $dateTo, ?int $tokoId): int
    {
        if (!Schema::hasTable('stock_mutasi_produk')) {
            return 0;
        }

        $query = DB::table('stock_mutasi_produk')
            ->where(function ($query) {
                $query->whereNull('is_void')
                    ->orWhere('is_void', 0);
            });

        $this->applyDateRange($query, 'tanggal', $dateFrom, $dateTo);
        $this->applyToko($query, $tokoId);

        return (int) $query->count();
    }

    private function countAuditLogs(string $dateFrom, string $dateTo, ?int $tokoId): int
    {
        if (!Schema::hasTable('audit_logs')) {
            return 0;
        }

        $query = DB::table('audit_logs');

        $this->applyDateRange($query, 'created_at', $dateFrom, $dateTo);
        $this->applyToko($query, $tokoId);

        return (int) $query->count();
    }

    private function countUserLogin(string $dateFrom, string $dateTo, ?int $tokoId): int
    {
        if (!Schema::hasTable('audit_logs')) {
            return 0;
        }

        $query = DB::table('audit_logs')
            ->where(function ($query) {
                $query->where('action', 'like', '%login%')
                    ->orWhere('module_name', 'like', '%auth%');
            });

        $this->applyDateRange($query, 'created_at', $dateFrom, $dateTo);
        $this->applyToko($query, $tokoId);

        return (int) $query->count();
    }

    private function countErrorLog(string $dateFrom, string $dateTo, ?int $tokoId): int
    {
        $total = 0;

        if (Schema::hasTable('audit_logs')) {
            $query = DB::table('audit_logs')
                ->where(function ($query) {
                    $query->where('action', 'like', '%error%')
                        ->orWhere('action', 'like', '%failed%')
                        ->orWhere('description', 'like', '%error%')
                        ->orWhere('description', 'like', '%gagal%');
                });

            $this->applyDateRange($query, 'created_at', $dateFrom, $dateTo);
            $this->applyToko($query, $tokoId);

            $total += (int) $query->count();
        }

        if (Schema::hasTable('accurate_sync_log')) {
            $query = DB::table('accurate_sync_log')
                ->where('status', 2);

            $this->applyDateRange($query, 'created_at', $dateFrom, $dateTo);

            $total += (int) $query->count();
        }

        return $total;
    }

    private function countIntegrationIssue(string $dateFrom, string $dateTo): int
    {
        if (!Schema::hasTable('accurate_sync_log')) {
            return 0;
        }

        $query = DB::table('accurate_sync_log')
            ->whereIn('status', [0, 2]);

        $this->applyDateRange($query, 'created_at', $dateFrom, $dateTo);

        return (int) $query->count();
    }

    private function countQueue(string $dateFrom, string $dateTo, ?int $tokoId): int
    {
        if (!Schema::hasTable('antrian')) {
            return 0;
        }

        $query = DB::table('antrian')
            ->where('is_delete', 0)
            ->whereNotIn('status', [9, '9', 'selesai', 'batal', 'cancel', 'cancelled']);

        $this->applyDateRange($query, 'tanggal', $dateFrom, $dateTo);
        $this->applyToko($query, $tokoId);

        return (int) $query->count();
    }

    private function countPeakHour(string $dateFrom, string $dateTo, ?int $tokoId): int
    {
        if (!Schema::hasTable('registrasi_kunjungan')) {
            return 0;
        }

        $query = DB::table('registrasi_kunjungan')
            ->selectRaw('HOUR(registered_at) as jam, COUNT(*) as total')
            ->where('is_delete', 0);

        $this->applyDateRange($query, 'tanggal_kunjungan', $dateFrom, $dateTo);
        $this->applyToko($query, $tokoId);

        $row = $query
            ->groupBy(DB::raw('HOUR(registered_at)'))
            ->orderByDesc('total')
            ->first();

        return $row ? (int) $row->total : 0;
    }

    private function countPasienBaru(string $dateFrom, string $dateTo, ?int $tokoId): int
    {
        if (!Schema::hasTable('pasien')) {
            return 0;
        }

        $query = DB::table('pasien')
            ->where('is_delete', 0);

        $this->applyDateRange($query, 'created_at', $dateFrom, $dateTo);
        $this->applyToko($query, $tokoId);

        return (int) $query->count();
    }

    private function countActivePromo(?int $tokoId): int
    {
        if (!Schema::hasTable('master_voucher_diskon')) {
            return 0;
        }

        $today = now()->toDateString();

        $query = DB::table('master_voucher_diskon')
            ->where('is_delete', 0)
            ->where('status_voucher', 1)
            ->where(function ($query) use ($today) {
                $query->where('is_unlimited_date', 1)
                    ->orWhere(function ($subQuery) use ($today) {
                        $subQuery->whereDate('tanggal_mulai', '<=', $today)
                            ->whereDate('tanggal_akhir', '>=', $today);
                    });
            });

        if ($tokoId) {
            $query->where(function ($query) use ($tokoId) {
                $query->where('is_all_toko', 1)
                    ->orWhere('toko_id', $tokoId);
            });
        }

        return (int) $query->count();
    }

    private function countActiveVoucher(?int $tokoId): int
    {
        return $this->countActivePromo($tokoId);
    }

    private function countMasterData(): int
    {
        return $this->countTableActive('pasien')
            + $this->countTableActive('master_produk')
            + $this->countTableActive('master_treatment');
    }

    private function countActiveUser(): int
    {
        if (!Schema::hasTable('master_user')) {
            return 0;
        }

        return (int) DB::table('master_user')
            ->where('is_delete', 0)
            ->where('is_active', 1)
            ->count();
    }

    private function countToko(): int
    {
        return $this->countTableActive('master_toko');
    }

    private function countTableActive(string $table): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);

        if (Schema::hasColumn($table, 'is_delete')) {
            $query->where('is_delete', 0);
        }

        return (int) $query->count();
    }

    private function applyDateRange(Builder $query, string $column, string $dateFrom, string $dateTo): void
    {
        $query->whereDate($column, '>=', $dateFrom)
            ->whereDate($column, '<=', $dateTo);
    }

    private function applyToko(Builder $query, ?int $tokoId, string $column = 'toko_id'): void
    {
        if ($tokoId) {
            $query->where($column, $tokoId);
        }
    }
}