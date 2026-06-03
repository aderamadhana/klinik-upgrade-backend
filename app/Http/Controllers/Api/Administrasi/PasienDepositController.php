<?php

namespace App\Http\Controllers\Api\Administrasi;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class PasienDepositController extends Controller
{
    public function show(Request $request, $id)
    {
        $pasien = $this->getPasien((int) $id);

        if (!$pasien) {
            return response()->json([
                'status' => false,
                'message' => 'Pasien tidak ditemukan',
                'data' => null,
            ], 404);
        }

        $status = strtolower(trim((string) $request->query('status', 'all')));
        $search = trim((string) $request->query('search', ''));
        $limit = (int) $request->query('limit', 300);

        if ($limit <= 0) {
            $limit = 300;
        }

        if ($limit > 500) {
            $limit = 500;
        }

        $query = $this->baseDepositQuery((int) $id);

        $this->applySearch($query, $search);
        $this->applyStatusFilter($query, $status);

        $rows = $query
            ->orderByRaw("CASE WHEN d.status = 1 THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN d.expired_at IS NULL THEN 1 ELSE 0 END")
            ->orderBy('d.expired_at', 'asc')
            ->orderBy('d.created_at', 'desc')
            ->limit($limit)
            ->get();

        $depositIds = $rows->pluck('id')->filter()->values()->all();
        $claims = $this->getClaimsByDepositIds($depositIds);

        $deposits = $rows->map(function ($row) use ($claims) {
            return $this->formatDeposit($row, $claims->get($row->id, collect()));
        })->values();

        return response()->json([
            'status' => true,
            'message' => 'Data saldo deposit pasien berhasil diambil',
            'data' => [
                'pasien' => $this->formatPasien($pasien),
                'summary' => $this->getSummary((int) $id),
                'filters' => [
                    'search' => $search,
                    'status' => $status,
                    'limit' => $limit,
                ],
                'claim_options' => $this->getClaimOptions(),
                'deposits' => $deposits,
            ],
        ]);
    }

    public function claim(Request $request, $id, $depositId)
    {
        $validator = Validator::make($request->all(), [
            'qty_claim' => 'nullable|numeric|min:0.0001',
            'claim_dokter_id' => 'nullable|integer',
            'claim_perawat_id' => 'nullable|integer',
            'catatan' => 'nullable|string|max:1000',
            'toko_claim_id' => 'nullable|integer',
        ], [
            'qty_claim.numeric' => 'Qty claim tidak valid',
            'qty_claim.min' => 'Qty claim minimal lebih dari 0',
            'claim_dokter_id.integer' => 'Dokter claim tidak valid',
            'claim_perawat_id.integer' => 'Perawat claim tidak valid',
            'catatan.max' => 'Catatan maksimal 1000 karakter',
            'toko_claim_id.integer' => 'Cabang claim tidak valid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($request, $id, $depositId) {
                $pasien = $this->getPasien((int) $id);

                if (!$pasien) {
                    throw ValidationException::withMessages([
                        'pasien' => 'Pasien tidak ditemukan.',
                    ]);
                }

                $deposit = DB::table('pembayaran_deposit_treatment as d')
                    ->where('d.id', (int) $depositId)
                    ->where('d.pasien_id', (int) $id)
                    ->where(function ($query) {
                        $query->whereNull('d.is_delete')
                            ->orWhere('d.is_delete', 0);
                    })
                    ->lockForUpdate()
                    ->first();

                if (!$deposit) {
                    throw ValidationException::withMessages([
                        'deposit' => 'Data deposit tidak ditemukan.',
                    ]);
                }

                $this->validateDepositCanBeClaimed($deposit);

                $qtyClaim = (float) $request->input('qty_claim', 1);
                if ($qtyClaim <= 0) {
                    $qtyClaim = 1;
                }

                $qtySisa = (float) $deposit->qty_sisa;

                if ($qtyClaim > $qtySisa) {
                    throw ValidationException::withMessages([
                        'qty_claim' => 'Qty claim melebihi sisa deposit.',
                    ]);
                }

                $tokoClaimId = $request->input('toko_claim_id') ?: ($deposit->toko_beli_id ?: $pasien->toko_id);
                $claimDokterId = $request->input('claim_dokter_id');
                $claimPerawatId = $request->input('claim_perawat_id');
                $catatan = trim((string) $request->input('catatan', ''));

                $this->validateKaryawanIfFilled($claimDokterId, 'Dokter claim');
                $this->validateKaryawanIfFilled($claimPerawatId, 'Perawat claim');
                $this->validateTokoIfFilled($tokoClaimId);

                $now = now();
                $today = Carbon::today()->toDateString();
                $username = $this->username();

                $sequence = $this->nextInvoiceSequence((int) $tokoClaimId, $today);

                $kodeRegistrasi = $this->makeClaimRegistrationCode((int) $tokoClaimId, $today, $sequence);
                $noInvoice = $this->makeClaimInvoiceNumber((int) $tokoClaimId, $today, $sequence);

                $nilaiRealisasi = $this->calculateClaimValue($deposit, $qtyClaim);

                $registrasiId = DB::table('registrasi_kunjungan')->insertGetId($this->onlyExistingColumns('registrasi_kunjungan', [
                    'kode_registrasi' => $kodeRegistrasi,
                    'toko_id' => $tokoClaimId,
                    'pasien_id' => (int) $id,
                    'tanggal_kunjungan' => $today,
                    'registered_at' => $now,
                    'catatan_registrasi' => $catatan !== '' ? $catatan : 'Claim deposit treatment',
                    'dokter_awal_id' => $claimDokterId,
                    'perawat_awal_id' => $claimPerawatId,
                    'channel_konsultasi' => 0,
                    'konsultasi_source_code' => null,
                    'konsultasi_source_name' => null,
                    'is_konsultasi_tambahan_dokter' => 0,
                    'has_saran_dokter' => 0,
                    'is_treatment' => 1,
                    'is_penjualan' => 0,
                    'is_pembelian_online' => 0,
                    'perlu_tindakan_perawat' => $claimPerawatId ? 2 : 1,
                    'current_task' => 5,
                    'status' => 2,
                    'total_treatment' => 0,
                    'total_penjualan' => 0,
                    'total_konsultasi' => 0,
                    'rule_biaya_konsultasi' => 0,
                    'catatan_biaya_konsultasi' => null,
                    'grand_total' => 0,
                    'is_delete' => 0,
                    'created_by' => $username,
                    'updated_by' => $username,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));

                $paymentTaskId = DB::table('registrasi_task')->insertGetId($this->onlyExistingColumns('registrasi_task', [
                    'registrasi_id' => $registrasiId,
                    'task_type' => 4,
                    'assigned_karyawan_id' => null,
                    'task_order' => 1,
                    'status' => 2,
                    'started_at' => $now,
                    'finished_at' => $now,
                    'catatan' => 'Invoice claim deposit otomatis',
                    'created_by' => $username,
                    'updated_by' => $username,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));

                $invoiceId = DB::table('pembayaran_invoice')->insertGetId($this->onlyExistingColumns('pembayaran_invoice', [
                    'registrasi_id' => $registrasiId,
                    'task_id' => $paymentTaskId,
                    'no_invoice' => $noInvoice,
                    'kode_registrasi' => $kodeRegistrasi,
                    'toko_id' => $tokoClaimId,
                    'pasien_id' => (int) $id,
                    'member_id' => null,
                    'member_no' => null,
                    'member_tier_id' => null,
                    'member_tier_nama' => null,
                    'dokter_id' => $claimDokterId,
                    'referensi_dokter_id' => $deposit->referensi_dokter_id,
                    'tanggal_invoice' => $now,
                    'tanggal_lunas' => $now,
                    'jenis_transaksi' => 0,
                    'sumber_informasi_id' => null,
                    'deposit_expired_option_id' => null,
                    'deposit_expired_at' => null,
                    'sumber_kedatangan' => null,
                    'poin' => 0,
                    'catatan' => $catatan !== '' ? $catatan : 'Claim deposit treatment',
                    'subtotal_produk' => 0,
                    'subtotal_treatment' => 0,
                    'subtotal_konsultasi' => 0,
                    'subtotal' => 0,
                    'diskon_subtotal_tipe' => 0,
                    'diskon_subtotal_nilai' => 0,
                    'diskon_subtotal_amount' => 0,
                    'total_diskon_item' => 0,
                    'total_diskon_referral' => 0,
                    'total_promo' => 0,
                    'diskon_member_amount' => 0,
                    'point_earned' => 0,
                    'point_redeemed' => 0,
                    'point_redeem_value' => 0,
                    'grand_total' => 0,
                    'total_bayar' => 0,
                    'sisa_tagihan' => 0,
                    'total_kembalian' => 0,
                    'status' => 3,
                    'is_delete' => 0,
                    'created_by' => $username,
                    'updated_by' => $username,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));

                $invoiceItemId = DB::table('pembayaran_invoice_item')->insertGetId($this->onlyExistingColumns('pembayaran_invoice_item', [
                    'pembayaran_id' => $invoiceId,
                    'registrasi_id' => $registrasiId,
                    'item_type' => 2,
                    'source_type' => 0,
                    'source_detail_id' => null,
                    'jenis_transaksi' => 0,
                    'deposit_treatment_id' => (int) $deposit->id,
                    'deposit_claim_id' => null,
                    'expired_at' => null,
                    'treatment_id' => $deposit->treatment_id,
                    'treatment_toko_id' => $deposit->treatment_toko_id,
                    'produk_id' => null,
                    'produk_toko_id' => null,
                    'tempat_produk_id' => null,
                    'stock_reservasi_id' => null,
                    'accurate_mapping_id' => null,
                    'accurate_source_type' => null,
                    'accurate_source_code' => null,
                    'kode_accurate_snapshot' => null,
                    'nama_accurate_snapshot' => null,
                    'is_send_to_accurate' => 0,
                    'send_when_zero' => 0,
                    'nama_item' => $deposit->nama_treatment,
                    'satuan' => 'Treatment',
                    'qty' => $qtyClaim,
                    'harga' => 0,
                    'diskon_tipe' => 0,
                    'diskon_nilai' => 0,
                    'diskon_amount' => 0,
                    'diskon_referral' => 0,
                    'subtotal_before_diskon_subtotal' => 0,
                    'diskon_subtotal_amount' => 0,
                    'subtotal_after_diskon_subtotal' => 0,
                    'subtotal' => 0,
                    'dokter_id' => $claimDokterId,
                    'perawat_id' => $claimPerawatId,
                    'is_saran_dokter' => 0,
                    'frekuensi' => null,
                    'waktu_pakai' => null,
                    'instruksi_pemakaian' => null,
                    'status' => 1,
                    'is_delete' => 0,
                    'created_by' => $username,
                    'updated_by' => $username,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));

                $claimId = DB::table('pembayaran_deposit_treatment_claim')->insertGetId($this->onlyExistingColumns('pembayaran_deposit_treatment_claim', [
                    'deposit_treatment_id' => (int) $deposit->id,
                    'registrasi_id' => $registrasiId,
                    'pembayaran_id' => $invoiceId,
                    'pembayaran_item_id' => $invoiceItemId,
                    'toko_claim_id' => $tokoClaimId,
                    'treatment_detail_id' => null,
                    'qty_claim' => $qtyClaim,
                    'nilai_realisasi' => $nilaiRealisasi,
                    'claim_dokter_id' => $claimDokterId,
                    'claim_perawat_id' => $claimPerawatId,
                    'claimed_at' => $now,
                    'status' => 1,
                    'is_delete' => 0,
                    'created_by' => $username,
                    'updated_by' => $username,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));

                DB::table('pembayaran_invoice_item')
                    ->where('id', $invoiceItemId)
                    ->update($this->onlyExistingColumns('pembayaran_invoice_item', [
                        'deposit_claim_id' => $claimId,
                        'updated_by' => $username,
                        'updated_at' => $now,
                    ]));

                $newQtyClaimed = (float) $deposit->qty_claimed + $qtyClaim;
                $newQtySisa = max(0, (float) $deposit->qty_sisa - $qtyClaim);
                $newNilaiClaimed = (float) $deposit->nilai_claimed + $nilaiRealisasi;
                $newNilaiSisa = max(0, (float) $deposit->nilai_sisa - $nilaiRealisasi);
                $newStatus = $newQtySisa <= 0 ? 2 : 1;

                DB::table('pembayaran_deposit_treatment')
                    ->where('id', (int) $deposit->id)
                    ->update($this->onlyExistingColumns('pembayaran_deposit_treatment', [
                        'qty_claimed' => $newQtyClaimed,
                        'qty_sisa' => $newQtySisa,
                        'nilai_claimed' => $newNilaiClaimed,
                        'nilai_sisa' => $newNilaiSisa,
                        'status' => $newStatus,
                        'updated_by' => $username,
                        'updated_at' => $now,
                    ]));

                return [
                    'registrasi_id' => $registrasiId,
                    'kode_registrasi' => $kodeRegistrasi,
                    'invoice_id' => $invoiceId,
                    'no_invoice' => $noInvoice,
                    'invoice_item_id' => $invoiceItemId,
                    'claim_id' => $claimId,
                    'qty_claim' => $qtyClaim,
                    'nilai_realisasi' => $nilaiRealisasi,
                    'qty_sisa' => $newQtySisa,
                    'nilai_sisa' => $newNilaiSisa,
                    'deposit_status' => $newStatus,
                ];
            }, 3);

            return response()->json([
                'status' => true,
                'message' => 'Claim deposit berhasil dibuat',
                'data' => $result,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'Validasi gagal',
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' => 'Gagal memproses claim deposit',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function getPasien(int $id)
    {
        return DB::table('pasien as p')
            ->leftJoin('master_toko as toko', 'toko.id', '=', 'p.toko_id')
            ->where('p.id', $id)
            ->where(function ($query) {
                $query->whereNull('p.is_delete')
                    ->orWhere('p.is_delete', 0);
            })
            ->first([
                'p.id',
                'p.no_rm',
                'p.nama',
                'p.no_identitas',
                'p.jenis_kelamin',
                'p.tanggal_lahir',
                'p.no_hp',
                'p.no_wa',
                'p.no_telp',
                'p.email',
                'p.alamat',
                'p.toko_id',
                'toko.nama_toko',
                'toko.kode_toko',
            ]);
    }

    private function baseDepositQuery(int $pasienId)
    {
        return DB::table('pembayaran_deposit_treatment as d')
            ->leftJoin('pembayaran_invoice as inv', 'inv.id', '=', 'd.pembayaran_id')
            ->leftJoin('pembayaran_invoice_item as item', 'item.id', '=', 'd.pembayaran_item_id')
            ->leftJoin('master_toko as toko_beli', 'toko_beli.id', '=', 'd.toko_beli_id')
            ->leftJoin('master_karyawan as dokter_ref', 'dokter_ref.id', '=', 'd.referensi_dokter_id')
            ->leftJoin('master_treatment as treatment', 'treatment.id', '=', 'd.treatment_id')
            ->where('d.pasien_id', $pasienId)
            ->where(function ($query) {
                $query->whereNull('d.is_delete')
                    ->orWhere('d.is_delete', 0);
            })
            ->select([
                'd.id',
                'd.pembayaran_id',
                'd.pembayaran_item_id',
                'd.pasien_id',
                'd.toko_beli_id',
                'd.treatment_id',
                'd.treatment_toko_id',
                'd.nama_treatment',
                'd.qty_total',
                'd.qty_claimed',
                'd.qty_sisa',
                'd.harga_satuan',
                'd.total_nilai',
                'd.nilai_claimed',
                'd.nilai_sisa',
                'd.expired_at',
                'd.referensi_dokter_id',
                'd.claim_scope',
                'd.status',
                'd.created_at',
                'd.updated_at',
                'inv.no_invoice',
                'inv.kode_registrasi',
                'inv.tanggal_invoice',
                'inv.tanggal_lunas',
                'inv.jenis_transaksi',
                'inv.status as invoice_status',
                'item.nama_item as item_nama',
                'item.qty as item_qty',
                'item.harga as item_harga',
                'item.subtotal as item_subtotal',
                'toko_beli.kode_toko as toko_beli_kode',
                'toko_beli.nama_toko as toko_beli_nama',
                'dokter_ref.nama as referensi_dokter_nama',
                'treatment.kode_accurate as treatment_kode_accurate',
            ]);
    }

    private function applySearch($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function ($q) use ($search) {
            $q->where('d.nama_treatment', 'LIKE', "%{$search}%")
                ->orWhere('inv.no_invoice', 'LIKE', "%{$search}%")
                ->orWhere('inv.kode_registrasi', 'LIKE', "%{$search}%")
                ->orWhere('toko_beli.nama_toko', 'LIKE', "%{$search}%")
                ->orWhere('toko_beli.kode_toko', 'LIKE', "%{$search}%")
                ->orWhere('dokter_ref.nama', 'LIKE', "%{$search}%");
        });
    }

    private function applyStatusFilter($query, string $status): void
    {
        $today = Carbon::today()->toDateString();
        $next30Days = Carbon::today()->addDays(30)->toDateString();

        if ($status === 'aktif') {
            $query->where('d.status', 1)
                ->where('d.qty_sisa', '>', 0)
                ->where(function ($q) use ($today) {
                    $q->whereNull('d.expired_at')
                        ->orWhereDate('d.expired_at', '>=', $today);
                });

            return;
        }

        if ($status === 'akan_expired') {
            $query->where('d.status', 1)
                ->where('d.qty_sisa', '>', 0)
                ->whereNotNull('d.expired_at')
                ->whereDate('d.expired_at', '>=', $today)
                ->whereDate('d.expired_at', '<=', $next30Days);

            return;
        }

        if ($status === 'expired') {
            $query->where(function ($q) use ($today) {
                $q->where('d.status', 3)
                    ->orWhere(function ($sub) use ($today) {
                        $sub->whereNotNull('d.expired_at')
                            ->whereDate('d.expired_at', '<', $today)
                            ->where('d.qty_sisa', '>', 0);
                    });
            });

            return;
        }

        if ($status === 'habis') {
            $query->where(function ($q) {
                $q->where('d.status', 2)
                    ->orWhere('d.qty_sisa', '<=', 0);
            });

            return;
        }

        if ($status === 'batal') {
            $query->where('d.status', 9);
        }
    }

    private function getClaimsByDepositIds(array $depositIds)
    {
        if (empty($depositIds)) {
            return collect();
        }

        return DB::table('pembayaran_deposit_treatment_claim as c')
            ->leftJoin('registrasi_kunjungan as reg', 'reg.id', '=', 'c.registrasi_id')
            ->leftJoin('pembayaran_invoice as inv', 'inv.id', '=', 'c.pembayaran_id')
            ->leftJoin('master_toko as toko_claim', 'toko_claim.id', '=', 'c.toko_claim_id')
            ->leftJoin('master_karyawan as dokter', 'dokter.id', '=', 'c.claim_dokter_id')
            ->leftJoin('master_karyawan as perawat', 'perawat.id', '=', 'c.claim_perawat_id')
            ->whereIn('c.deposit_treatment_id', $depositIds)
            ->where(function ($query) {
                $query->whereNull('c.is_delete')
                    ->orWhere('c.is_delete', 0);
            })
            ->orderBy('c.claimed_at', 'desc')
            ->get([
                'c.id',
                'c.deposit_treatment_id',
                'c.registrasi_id',
                'c.pembayaran_id',
                'c.pembayaran_item_id',
                'c.toko_claim_id',
                'c.treatment_detail_id',
                'c.qty_claim',
                'c.nilai_realisasi',
                'c.claim_dokter_id',
                'c.claim_perawat_id',
                'c.claimed_at',
                'c.status',
                'reg.kode_registrasi',
                'inv.no_invoice',
                'toko_claim.kode_toko as toko_claim_kode',
                'toko_claim.nama_toko as toko_claim_nama',
                'dokter.nama as dokter_nama',
                'perawat.nama as perawat_nama',
            ])
            ->groupBy('deposit_treatment_id');
    }

    private function getSummary(int $pasienId): array
    {
        $rows = DB::table('pembayaran_deposit_treatment as d')
            ->where('d.pasien_id', $pasienId)
            ->where(function ($query) {
                $query->whereNull('d.is_delete')
                    ->orWhere('d.is_delete', 0);
            })
            ->get([
                'd.status',
                'd.qty_total',
                'd.qty_claimed',
                'd.qty_sisa',
                'd.total_nilai',
                'd.nilai_claimed',
                'd.nilai_sisa',
                'd.expired_at',
            ]);

        $summary = [
            'total_record' => $rows->count(),
            'total_qty' => 0,
            'total_qty_claimed' => 0,
            'total_qty_sisa' => 0,
            'total_nilai' => 0,
            'total_nilai_claimed' => 0,
            'total_nilai_sisa' => 0,
            'aktif_count' => 0,
            'aktif_qty_sisa' => 0,
            'aktif_nilai_sisa' => 0,
            'akan_expired_count' => 0,
            'expired_count' => 0,
            'habis_count' => 0,
            'batal_count' => 0,
            'nearest_expired_at' => null,
        ];

        $today = Carbon::today();
        $next30Days = Carbon::today()->addDays(30);

        foreach ($rows as $row) {
            $logicalStatus = $this->logicalStatus($row);

            $summary['total_qty'] += (float) $row->qty_total;
            $summary['total_qty_claimed'] += (float) $row->qty_claimed;
            $summary['total_qty_sisa'] += (float) $row->qty_sisa;
            $summary['total_nilai'] += (float) $row->total_nilai;
            $summary['total_nilai_claimed'] += (float) $row->nilai_claimed;
            $summary['total_nilai_sisa'] += (float) $row->nilai_sisa;

            if ($logicalStatus === 'aktif') {
                $summary['aktif_count']++;
                $summary['aktif_qty_sisa'] += (float) $row->qty_sisa;
                $summary['aktif_nilai_sisa'] += (float) $row->nilai_sisa;
            }

            if ($logicalStatus === 'habis') {
                $summary['habis_count']++;
            }

            if ($logicalStatus === 'expired') {
                $summary['expired_count']++;
            }

            if ($logicalStatus === 'batal') {
                $summary['batal_count']++;
            }

            if ($row->expired_at) {
                $expiredAt = Carbon::parse($row->expired_at);

                if (
                    $logicalStatus === 'aktif'
                    && $expiredAt->greaterThanOrEqualTo($today)
                    && $expiredAt->lessThanOrEqualTo($next30Days)
                ) {
                    $summary['akan_expired_count']++;
                }

                if (
                    $logicalStatus === 'aktif'
                    && (
                        $summary['nearest_expired_at'] === null
                        || $expiredAt->lt(Carbon::parse($summary['nearest_expired_at']))
                    )
                ) {
                    $summary['nearest_expired_at'] = $expiredAt->toDateString();
                }
            }
        }

        $summary['nearest_expired_at_formatted'] = $this->formatDate($summary['nearest_expired_at']);

        return $summary;
    }

    private function getClaimOptions(): array
    {
        return [
            'dokter' => $this->getKaryawanOptions(['dokter']),
            'perawat' => $this->getKaryawanOptions(['nurse', 'perawat', 'beautician']),
        ];
    }

    private function getKaryawanOptions(array $keywords)
    {
        return DB::table('master_karyawan as k')
            ->leftJoin('master_jabatan as j', 'j.id', '=', 'k.jabatan_id')
            ->where(function ($query) {
                $query->whereNull('k.is_delete')
                    ->orWhere('k.is_delete', 0);
            })
            ->where(function ($query) use ($keywords) {
                foreach ($keywords as $keyword) {
                    $query->orWhereRaw('LOWER(j.nama_jabatan) LIKE ?', ['%' . strtolower($keyword) . '%'])
                        ->orWhereRaw('LOWER(k.nama) LIKE ?', ['%' . strtolower($keyword) . '%']);
                }
            })
            ->orderBy('k.sort_order')
            ->orderBy('k.nama')
            ->get([
                'k.id',
                'k.kode',
                'k.nama',
                'j.nama_jabatan',
            ])
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'title' => trim($row->nama . ($row->nama_jabatan ? ' - ' . $row->nama_jabatan : '')),
                    'value' => (int) $row->id,
                    'kode' => $row->kode,
                    'nama' => $row->nama,
                    'jabatan' => $row->nama_jabatan,
                ];
            })
            ->values();
    }

    private function validateDepositCanBeClaimed($deposit): void
    {
        if ((int) $deposit->status !== 1) {
            throw ValidationException::withMessages([
                'deposit' => 'Deposit tidak aktif.',
            ]);
        }

        if ((float) $deposit->qty_sisa <= 0) {
            throw ValidationException::withMessages([
                'deposit' => 'Qty deposit sudah habis.',
            ]);
        }

        if ($deposit->expired_at && Carbon::parse($deposit->expired_at)->lt(Carbon::today())) {
            throw ValidationException::withMessages([
                'deposit' => 'Deposit sudah expired.',
            ]);
        }
    }

    private function validateKaryawanIfFilled($id, string $label): void
    {
        if (!$id) {
            return;
        }

        $exists = DB::table('master_karyawan')
            ->where('id', (int) $id)
            ->where(function ($query) {
                $query->whereNull('is_delete')
                    ->orWhere('is_delete', 0);
            })
            ->exists();

        if (!$exists) {
            throw ValidationException::withMessages([
                'karyawan' => "{$label} tidak ditemukan.",
            ]);
        }
    }

    private function validateTokoIfFilled($id): void
    {
        if (!$id) {
            return;
        }

        $exists = DB::table('master_toko')
            ->where('id', (int) $id)
            ->where(function ($query) {
                $query->whereNull('is_delete')
                    ->orWhere('is_delete', 0);
            })
            ->exists();

        if (!$exists) {
            throw ValidationException::withMessages([
                'toko_claim_id' => 'Cabang claim tidak ditemukan.',
            ]);
        }
    }

    private function calculateClaimValue($deposit, float $qtyClaim): float
    {
        $qtySisa = (float) $deposit->qty_sisa;
        $nilaiSisa = (float) $deposit->nilai_sisa;

        if ($qtySisa <= 0 || $nilaiSisa <= 0) {
            return 0;
        }

        $nilaiPerQty = $nilaiSisa / $qtySisa;
        $nilaiRealisasi = round($nilaiPerQty * $qtyClaim, 2);

        return min($nilaiRealisasi, $nilaiSisa);
    }

    private function nextInvoiceSequence(int $tokoId, string $tanggal): int
    {
        $row = DB::table('pembayaran_invoice_sequence')
            ->where('toko_id', $tokoId)
            ->where('tanggal', $tanggal)
            ->lockForUpdate()
            ->first();

        if (!$row) {
            DB::table('pembayaran_invoice_sequence')->insert([
                'toko_id' => $tokoId,
                'tanggal' => $tanggal,
                'last_sequence' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return 1;
        }

        $next = ((int) $row->last_sequence) + 1;

        DB::table('pembayaran_invoice_sequence')
            ->where('id', $row->id)
            ->update([
                'last_sequence' => $next,
                'updated_at' => now(),
            ]);

        return $next;
    }

    private function makeClaimRegistrationCode(int $tokoId, string $tanggal, int $sequence): string
    {
        return sprintf(
            'REG-CLAIM-%s-%02d-%04d',
            Carbon::parse($tanggal)->format('Ymd'),
            $tokoId,
            $sequence
        );
    }

    private function makeClaimInvoiceNumber(int $tokoId, string $tanggal, int $sequence): string
    {
        return sprintf(
            'INV-CLAIM-%s-%02d-%04d',
            Carbon::parse($tanggal)->format('Ymd'),
            $tokoId,
            $sequence
        );
    }

    private function formatPasien($row): array
    {
        return [
            'id' => (int) $row->id,
            'no_rm' => $row->no_rm,
            'nama' => $row->nama,
            'no_identitas' => $row->no_identitas,
            'jenis_kelamin' => $row->jenis_kelamin,
            'tanggal_lahir' => $row->tanggal_lahir,
            'tanggal_lahir_formatted' => $this->formatDate($row->tanggal_lahir),
            'no_hp' => $row->no_hp,
            'no_wa' => $row->no_wa,
            'no_telp' => $row->no_telp,
            'email' => $row->email,
            'alamat' => $row->alamat,
            'toko_id' => $row->toko_id ? (int) $row->toko_id : null,
            'toko_nama' => $row->nama_toko,
            'toko_kode' => $row->kode_toko,
        ];
    }

    private function formatDeposit($row, $claims): array
    {
        $logicalStatus = $this->logicalStatus($row);

        return [
            'id' => (int) $row->id,
            'pembayaran_id' => (int) $row->pembayaran_id,
            'pembayaran_item_id' => (int) $row->pembayaran_item_id,
            'no_invoice' => $row->no_invoice,
            'kode_registrasi' => $row->kode_registrasi,
            'tanggal_invoice' => $row->tanggal_invoice,
            'tanggal_invoice_formatted' => $this->formatDate($row->tanggal_invoice),
            'tanggal_lunas' => $row->tanggal_lunas,
            'tanggal_lunas_formatted' => $this->formatDateTime($row->tanggal_lunas),
            'toko_beli_id' => $row->toko_beli_id ? (int) $row->toko_beli_id : null,
            'toko_beli_kode' => $row->toko_beli_kode,
            'toko_beli_nama' => $row->toko_beli_nama,
            'treatment_id' => $row->treatment_id ? (int) $row->treatment_id : null,
            'treatment_toko_id' => $row->treatment_toko_id ? (int) $row->treatment_toko_id : null,
            'treatment_kode_accurate' => $row->treatment_kode_accurate,
            'nama_treatment' => $row->nama_treatment ?: $row->item_nama,
            'qty_total' => (float) $row->qty_total,
            'qty_claimed' => (float) $row->qty_claimed,
            'qty_sisa' => (float) $row->qty_sisa,
            'harga_satuan' => (float) $row->harga_satuan,
            'total_nilai' => (float) $row->total_nilai,
            'nilai_claimed' => (float) $row->nilai_claimed,
            'nilai_sisa' => (float) $row->nilai_sisa,
            'expired_at' => $row->expired_at,
            'expired_at_formatted' => $this->formatDate($row->expired_at),
            'referensi_dokter_id' => $row->referensi_dokter_id ? (int) $row->referensi_dokter_id : null,
            'referensi_dokter_nama' => $row->referensi_dokter_nama,
            'claim_scope' => (int) $row->claim_scope,
            'claim_scope_text' => $this->claimScopeText((int) $row->claim_scope),
            'status' => (int) $row->status,
            'status_key' => $logicalStatus,
            'status_text' => $this->statusText($logicalStatus),
            'status_color' => $this->statusColor($logicalStatus),
            'created_at' => $row->created_at,
            'created_at_formatted' => $this->formatDateTime($row->created_at),
            'claims' => $claims->map(function ($claim) {
                return $this->formatClaim($claim);
            })->values(),
        ];
    }

    private function formatClaim($row): array
    {
        return [
            'id' => (int) $row->id,
            'deposit_treatment_id' => (int) $row->deposit_treatment_id,
            'registrasi_id' => $row->registrasi_id ? (int) $row->registrasi_id : null,
            'pembayaran_id' => $row->pembayaran_id ? (int) $row->pembayaran_id : null,
            'pembayaran_item_id' => $row->pembayaran_item_id ? (int) $row->pembayaran_item_id : null,
            'toko_claim_id' => $row->toko_claim_id ? (int) $row->toko_claim_id : null,
            'toko_claim_kode' => $row->toko_claim_kode,
            'toko_claim_nama' => $row->toko_claim_nama,
            'kode_registrasi' => $row->kode_registrasi,
            'no_invoice' => $row->no_invoice,
            'qty_claim' => (float) $row->qty_claim,
            'nilai_realisasi' => (float) $row->nilai_realisasi,
            'claim_dokter_id' => $row->claim_dokter_id ? (int) $row->claim_dokter_id : null,
            'claim_perawat_id' => $row->claim_perawat_id ? (int) $row->claim_perawat_id : null,
            'dokter_nama' => $row->dokter_nama,
            'perawat_nama' => $row->perawat_nama,
            'claimed_at' => $row->claimed_at,
            'claimed_at_formatted' => $this->formatDateTime($row->claimed_at),
            'status' => (int) $row->status,
        ];
    }

    private function logicalStatus($row): string
    {
        $status = (int) $row->status;
        $qtySisa = (float) $row->qty_sisa;

        if ($status === 9) {
            return 'batal';
        }

        if ($status === 2 || $qtySisa <= 0) {
            return 'habis';
        }

        if ($status === 3) {
            return 'expired';
        }

        if ($row->expired_at && Carbon::parse($row->expired_at)->lt(Carbon::today()) && $qtySisa > 0) {
            return 'expired';
        }

        if ($status === 1) {
            return 'aktif';
        }

        return 'lainnya';
    }

    private function statusText(string $status): string
    {
        return [
            'aktif' => 'Aktif',
            'expired' => 'Expired',
            'habis' => 'Habis',
            'batal' => 'Batal',
            'lainnya' => 'Lainnya',
        ][$status] ?? 'Lainnya';
    }

    private function statusColor(string $status): string
    {
        return [
            'aktif' => 'success',
            'expired' => 'error',
            'habis' => 'info',
            'batal' => 'grey',
            'lainnya' => 'secondary',
        ][$status] ?? 'secondary';
    }

    private function claimScopeText(int $claimScope): string
    {
        return $claimScope === 1 ? 'Cabang pembelian' : 'Lintas cabang';
    }

    private function formatDate($value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->translatedFormat('d M Y');
        } catch (Throwable $e) {
            return (string) $value;
        }
    }

    private function formatDateTime($value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->translatedFormat('d M Y H:i');
        } catch (Throwable $e) {
            return (string) $value;
        }
    }

    private function username(): string
    {
        $user = auth('api')->user();

        return $user->username
            ?? $user->name
            ?? $user->email
            ?? 'system';
    }

    private function onlyExistingColumns(string $table, array $payload): array
    {
        $columns = Schema::getColumnListing($table);

        return collect($payload)
            ->only($columns)
            ->all();
    }
}