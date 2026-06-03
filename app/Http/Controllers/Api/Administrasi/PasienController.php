<?php

namespace App\Http\Controllers\Api\Administrasi;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterToko;
use App\Models\Pasien;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PasienController extends Controller
{
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);

        if ($perPage <= 0) {
            $perPage = 10;
        }

        if ($perPage > 100) {
            $perPage = 100;
        }

        $search = trim((string) $request->get('search', ''));

        $query = Pasien::query()
            ->active()
            ->with([
                'toko:id,kode_toko,nama_toko',
                'pekerjaan:id,nama_pekerjaan',
                'agama:id,kode_agama,nama_agama',
            ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('no_rm', 'like', "%{$search}%")
                    ->orWhere('nama', 'like', "%{$search}%")
                    ->orWhere('no_identitas', 'like', "%{$search}%")
                    ->orWhere('no_hp', 'like', "%{$search}%")
                    ->orWhere('no_wa', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('alamat', 'like', "%{$search}%");
            });
        }

        if ($request->filled('tipe_pasien')) {
            $query->where('tipe_pasien', $this->mapTipePasien($request->tipe_pasien));
        }

        if ($request->filled('jenis_kelamin')) {
            $query->where('jenis_kelamin', $request->jenis_kelamin);
        }

        if ($request->filled('pekerjaan_id')) {
            $query->where('pekerjaan_id', $request->pekerjaan_id);
        }

        if ($request->filled('agama_id')) {
            $query->where('agama_id', $request->agama_id);
        }

        if ($request->filled('provinsi_kode')) {
            $query->where('provinsi_kode', $request->provinsi_kode);
        }

        if ($request->filled('kota_kode')) {
            $query->where('kota_kode', $request->kota_kode);
        }

        $paginator = $query
            ->orderByDesc('id')
            ->paginate($perPage);

        $paginator->getCollection()->transform(function ($pasien) {
            return $this->formatPasien($pasien);
        });

        return response()->json([
            'status' => true,
            'message' => 'Data pasien berhasil diambil',
            'data' => $paginator,
        ]);
    }

    public function show($id)
    {
        $pasien = $this->activePasienQuery()->find($id);

        if (!$pasien) {
            return response()->json([
                'status' => false,
                'message' => 'Data pasien tidak ditemukan',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Detail pasien berhasil diambil',
            'data' => $this->formatPasien($pasien),
        ]);
    }

    public function riwayat(Request $request, $id)
    {
        $pasien = $this->activePasienQuery()->find($id);

        if (!$pasien) {
            return response()->json([
                'status' => false,
                'message' => 'Data pasien tidak ditemukan',
                'data' => null,
            ], 404);
        }

        $limit = (int) $request->get('limit', 100);

        if ($limit <= 0) {
            $limit = 100;
        }

        if ($limit > 200) {
            $limit = 200;
        }

        $rows = DB::table('registrasi_kunjungan as rk')
            ->leftJoin('master_toko as toko', 'toko.id', '=', 'rk.toko_id')
            ->leftJoin('master_karyawan as dokter', 'dokter.id', '=', 'rk.dokter_awal_id')
            ->leftJoin('master_karyawan as perawat', 'perawat.id', '=', 'rk.perawat_awal_id')
            ->leftJoin('pembayaran_invoice as inv', function ($join) {
                $join->on('inv.registrasi_id', '=', 'rk.id')
                    ->where(function ($q) {
                        $q->where('inv.is_delete', 0)
                            ->orWhereNull('inv.is_delete');
                    });
            })
            ->where('rk.pasien_id', $pasien->id)
            ->where(function ($q) {
                $q->where('rk.is_delete', 0)
                    ->orWhereNull('rk.is_delete');
            })
            ->orderByDesc('rk.tanggal_kunjungan')
            ->orderByDesc('rk.id')
            ->limit($limit)
            ->get([
                'rk.id as registrasi_id',
                'rk.kode_registrasi',
                'rk.toko_id',
                'toko.kode_toko',
                'toko.nama_toko',
                'rk.tanggal_kunjungan',
                'rk.registered_at',
                'rk.catatan_registrasi',
                'rk.channel_konsultasi',
                'rk.konsultasi_source_code',
                'rk.konsultasi_source_name',
                'rk.is_konsultasi_tambahan_dokter',
                'rk.has_saran_dokter',
                'rk.is_treatment',
                'rk.is_penjualan',
                'rk.is_pembelian_online',
                'rk.perlu_tindakan_perawat',
                'rk.current_task',
                'rk.status as registrasi_status',
                'rk.total_treatment',
                'rk.total_penjualan',
                'rk.total_konsultasi',
                'rk.rule_biaya_konsultasi',
                'rk.grand_total as registrasi_grand_total',
                'dokter.id as dokter_id',
                'dokter.nama as dokter_nama',
                'perawat.id as perawat_id',
                'perawat.nama as perawat_nama',
                'inv.id as pembayaran_id',
                'inv.no_invoice',
                'inv.member_no',
                'inv.member_tier_nama',
                'inv.tanggal_invoice',
                'inv.tanggal_lunas',
                'inv.jenis_transaksi',
                'inv.subtotal_produk',
                'inv.subtotal_treatment',
                'inv.subtotal_konsultasi',
                'inv.subtotal',
                'inv.total_diskon_item',
                'inv.diskon_subtotal_amount',
                'inv.total_promo',
                'inv.diskon_member_amount',
                'inv.point_earned',
                'inv.point_redeemed',
                'inv.point_redeem_value',
                'inv.grand_total as invoice_grand_total',
                'inv.total_bayar',
                'inv.total_kembalian',
                'inv.sisa_tagihan',
                'inv.status as invoice_status',
            ]);

        $registrasiIds = $rows->pluck('registrasi_id')->filter()->unique()->values();
        $pembayaranIds = $rows->pluck('pembayaran_id')->filter()->unique()->values();

        $itemsByPayment = $this->invoiceItemsByPayment($pembayaranIds);
        $soapByRegistrasi = $this->soapByRegistrasi($registrasiIds);
        $cpptByRegistrasi = $this->cpptByRegistrasi($registrasiIds);
        $intakeByRegistrasi = $this->intakeByRegistrasi($registrasiIds);

        $riwayat = $rows->map(function ($row) use ($itemsByPayment, $soapByRegistrasi, $cpptByRegistrasi, $intakeByRegistrasi) {
            $items = $row->pembayaran_id
                ? $itemsByPayment->get((int) $row->pembayaran_id, collect())
                : collect();

            return [
                'id' => (int) $row->registrasi_id,
                'registrasi_id' => (int) $row->registrasi_id,
                'kode_registrasi' => $row->kode_registrasi,
                'tanggal' => $this->formatDate($row->tanggal_kunjungan),
                'waktu' => $this->formatTime($row->registered_at),
                'registered_at' => $this->formatDateTime($row->registered_at),
                'toko' => [
                    'id' => $row->toko_id ? (int) $row->toko_id : null,
                    'kode_toko' => $row->kode_toko,
                    'nama_toko' => $row->nama_toko,
                ],
                'dokter' => [
                    'id' => $row->dokter_id ? (int) $row->dokter_id : null,
                    'nama' => $row->dokter_nama,
                ],
                'perawat' => [
                    'id' => $row->perawat_id ? (int) $row->perawat_id : null,
                    'nama' => $row->perawat_nama,
                ],
                'layanan' => $this->buildLayanan($row, $items),
                'catatan' => $row->catatan_registrasi,
                'status' => [
                    'registrasi' => (int) $row->registrasi_status,
                    'registrasi_text' => $this->registrasiStatusText($row->registrasi_status),
                    'invoice' => $row->invoice_status !== null ? (int) $row->invoice_status : null,
                    'invoice_text' => $row->invoice_status !== null ? $this->invoiceStatusText($row->invoice_status) : null,
                    'text' => $row->invoice_status !== null
                        ? $this->invoiceStatusText($row->invoice_status)
                        : $this->registrasiStatusText($row->registrasi_status),
                    'color' => $this->statusColor($row->invoice_status, $row->registrasi_status),
                ],
                'pembayaran' => [
                    'id' => $row->pembayaran_id ? (int) $row->pembayaran_id : null,
                    'no_invoice' => $row->no_invoice,
                    'tanggal_invoice' => $this->formatDateTime($row->tanggal_invoice),
                    'tanggal_lunas' => $this->formatDateTime($row->tanggal_lunas),
                    'jenis_transaksi' => $row->jenis_transaksi !== null ? (int) $row->jenis_transaksi : null,
                    'jenis_transaksi_text' => $this->jenisTransaksiText($row->jenis_transaksi),
                    'subtotal_produk' => (float) ($row->subtotal_produk ?? 0),
                    'subtotal_treatment' => (float) ($row->subtotal_treatment ?? 0),
                    'subtotal_konsultasi' => (float) ($row->subtotal_konsultasi ?? 0),
                    'subtotal' => (float) ($row->subtotal ?? 0),
                    'total_diskon_item' => (float) ($row->total_diskon_item ?? 0),
                    'diskon_subtotal_amount' => (float) ($row->diskon_subtotal_amount ?? 0),
                    'total_promo' => (float) ($row->total_promo ?? 0),
                    'diskon_member_amount' => (float) ($row->diskon_member_amount ?? 0),
                    'point_earned' => (float) ($row->point_earned ?? 0),
                    'point_redeemed' => (float) ($row->point_redeemed ?? 0),
                    'point_redeem_value' => (float) ($row->point_redeem_value ?? 0),
                    'grand_total' => (float) ($row->invoice_grand_total ?? $row->registrasi_grand_total ?? 0),
                    'total_bayar' => (float) ($row->total_bayar ?? 0),
                    'total_kembalian' => (float) ($row->total_kembalian ?? 0),
                    'sisa_tagihan' => (float) ($row->sisa_tagihan ?? 0),
                ],
                'items' => $items->map(function ($item) {
                    return $this->formatInvoiceItem($item);
                })->values(),
                'soap' => $soapByRegistrasi->get((int) $row->registrasi_id),
                'cppt' => $cpptByRegistrasi->get((int) $row->registrasi_id, collect())->values(),
                'intake' => $intakeByRegistrasi->get((int) $row->registrasi_id),
            ];
        })->values();

        return response()->json([
            'status' => true,
            'message' => 'Riwayat pasien berhasil diambil',
            'data' => [
                'patient' => $this->formatPasienWithMember($pasien),
                'summary' => $this->riwayatSummary($pasien->id),
                'riwayat' => $riwayat,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validator = $this->validator($request, null, 'store');

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $payload = $this->payload($request);
            $payload['no_rm'] = $this->generateNoRm($payload['toko_id']);
            $payload['created_by'] = $this->authUserId();
            $payload['created_at'] = now();
            $payload['updated_at'] = null;

            $pasien = Pasien::create($payload);

            DB::commit();

            $pasien->load([
                'toko:id,kode_toko,nama_toko',
                'pekerjaan:id,nama_pekerjaan',
                'agama:id,kode_agama,nama_agama',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Data pasien berhasil ditambahkan',
                'data' => $this->formatPasien($pasien),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Data pasien gagal ditambahkan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $pasien = Pasien::query()
            ->active()
            ->find($id);

        if (!$pasien) {
            return response()->json([
                'status' => false,
                'message' => 'Data pasien tidak ditemukan',
                'data' => null,
            ], 404);
        }

        $validator = $this->validator($request, $id, 'update');

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $payload = $this->payload($request);
            unset($payload['no_rm'], $payload['toko_id'], $payload['is_delete']);

            $payload['updated_by'] = $this->authUserId();
            $payload['updated_at'] = now();

            $pasien->update($payload);

            DB::commit();

            $pasien = $pasien->fresh()->load([
                'toko:id,kode_toko,nama_toko',
                'pekerjaan:id,nama_pekerjaan',
                'agama:id,kode_agama,nama_agama',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Data pasien berhasil diperbarui',
                'data' => $this->formatPasien($pasien),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Data pasien gagal diperbarui',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $pasien = Pasien::query()
            ->active()
            ->find($id);

        if (!$pasien) {
            return response()->json([
                'status' => false,
                'message' => 'Data pasien tidak ditemukan',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $pasien->update([
                'is_delete' => 1,
                'updated_by' => $this->authUserId(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data pasien berhasil dihapus',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Data pasien gagal dihapus',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function activePasienQuery()
    {
        return Pasien::query()
            ->active()
            ->with([
                'toko:id,kode_toko,nama_toko',
                'pekerjaan:id,nama_pekerjaan',
                'agama:id,kode_agama,nama_agama',
            ]);
    }

    private function invoiceItemsByPayment($paymentIds)
    {
        if ($paymentIds->isEmpty()) {
            return collect();
        }

        return DB::table('pembayaran_invoice_item')
            ->whereIn('pembayaran_id', $paymentIds)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->where(function ($q) {
                $q->where('status', '!=', 9)
                    ->orWhereNull('status');
            })
            ->orderBy('item_type')
            ->orderBy('id')
            ->get()
            ->groupBy('pembayaran_id');
    }

    private function soapByRegistrasi($registrasiIds)
    {
        if ($registrasiIds->isEmpty()) {
            return collect();
        }

        $soapRows = DB::table('registrasi_dokter_soap as soap')
            ->leftJoin('master_karyawan as dokter', 'dokter.id', '=', 'soap.dokter_id')
            ->whereIn('soap.registrasi_id', $registrasiIds)
            ->where(function ($q) {
                $q->where('soap.status', '!=', 9)
                    ->orWhereNull('soap.status');
            })
            ->orderByDesc('soap.id')
            ->get([
                'soap.id',
                'soap.registrasi_id',
                'soap.task_id',
                'soap.dokter_id',
                'dokter.nama as dokter_nama',
                'soap.objective',
                'soap.assessment_lainnya',
                'soap.plan',
                'soap.next_konsultasi_date',
                'soap.status',
                'soap.finalized_at',
                'soap.created_at',
            ]);

        if ($soapRows->isEmpty()) {
            return collect();
        }

        $soapIds = $soapRows->pluck('id')->filter()->unique()->values();

        $subjectiveBySoap = DB::table('registrasi_dokter_soap_subjective as detail')
            ->leftJoin('master_subjective as master', 'master.id', '=', 'detail.subjective_id')
            ->whereIn('detail.soap_id', $soapIds)
            ->orderBy('detail.sort_order')
            ->orderBy('detail.id')
            ->get([
                'detail.soap_id',
                DB::raw('COALESCE(master.nama, detail.subjective_text) as text'),
            ])
            ->groupBy('soap_id');

        $diagnosaBySoap = DB::table('registrasi_dokter_soap_diagnosa as detail')
            ->leftJoin('master_assessment as master', 'master.id', '=', 'detail.diagnosa_id')
            ->whereIn('detail.soap_id', $soapIds)
            ->orderBy('detail.sort_order')
            ->orderBy('detail.id')
            ->get([
                'detail.soap_id',
                DB::raw('COALESCE(master.nama, detail.diagnosa_text) as text'),
            ])
            ->groupBy('soap_id');

        return $soapRows
            ->groupBy('registrasi_id')
            ->map(function ($rows) use ($subjectiveBySoap, $diagnosaBySoap) {
                $soap = $rows->first();

                return [
                    'id' => (int) $soap->id,
                    'dokter' => [
                        'id' => $soap->dokter_id ? (int) $soap->dokter_id : null,
                        'nama' => $soap->dokter_nama,
                    ],
                    'subjective' => $subjectiveBySoap->get($soap->id, collect())->pluck('text')->filter()->values(),
                    'objective' => $soap->objective,
                    'assessment' => $diagnosaBySoap->get($soap->id, collect())->pluck('text')->filter()->values(),
                    'assessment_lainnya' => $soap->assessment_lainnya,
                    'plan' => $soap->plan,
                    'next_konsultasi_date' => $this->formatDate($soap->next_konsultasi_date),
                    'status' => (int) $soap->status,
                    'status_text' => $this->medicalStatusText($soap->status),
                    'finalized_at' => $this->formatDateTime($soap->finalized_at),
                    'created_at' => $this->formatDateTime($soap->created_at),
                ];
            });
    }

    private function cpptByRegistrasi($registrasiIds)
    {
        if ($registrasiIds->isEmpty()) {
            return collect();
        }

        return DB::table('registrasi_perawat_cppt as cppt')
            ->leftJoin('master_karyawan as dokter', 'dokter.id', '=', 'cppt.dokter_id')
            ->leftJoin('master_karyawan as perawat', 'perawat.id', '=', 'cppt.perawat_id')
            ->leftJoin('master_assessment as assessment', 'assessment.id', '=', 'cppt.assessment_id')
            ->whereIn('cppt.registrasi_id', $registrasiIds)
            ->where(function ($q) {
                $q->where('cppt.is_delete', 0)
                    ->orWhereNull('cppt.is_delete');
            })
            ->where(function ($q) {
                $q->where('cppt.status', '!=', 9)
                    ->orWhereNull('cppt.status');
            })
            ->orderByDesc('cppt.tanggal_jam')
            ->orderByDesc('cppt.id')
            ->get([
                'cppt.id',
                'cppt.registrasi_id',
                'cppt.tanggal_jam',
                'cppt.dokter_id',
                'dokter.nama as dokter_nama',
                'cppt.perawat_id',
                'perawat.nama as perawat_nama',
                'cppt.subjective_note',
                'cppt.objective_note',
                'cppt.assessment_id',
                'assessment.nama as assessment_nama',
                'cppt.assessment_note',
                'cppt.plan_note',
                'cppt.tindakan_evaluasi_note',
                'cppt.status',
                'cppt.finalized_at',
            ])
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'registrasi_id' => (int) $row->registrasi_id,
                    'tanggal_jam' => $this->formatDateTime($row->tanggal_jam),
                    'dokter' => [
                        'id' => $row->dokter_id ? (int) $row->dokter_id : null,
                        'nama' => $row->dokter_nama,
                    ],
                    'perawat' => [
                        'id' => $row->perawat_id ? (int) $row->perawat_id : null,
                        'nama' => $row->perawat_nama,
                    ],
                    'subjective_note' => $row->subjective_note,
                    'objective_note' => $row->objective_note,
                    'assessment' => $row->assessment_nama,
                    'assessment_note' => $row->assessment_note,
                    'plan_note' => $row->plan_note,
                    'tindakan_evaluasi_note' => $row->tindakan_evaluasi_note,
                    'status' => (int) $row->status,
                    'status_text' => $this->medicalStatusText($row->status),
                    'finalized_at' => $this->formatDateTime($row->finalized_at),
                ];
            })
            ->groupBy('registrasi_id');
    }

    private function intakeByRegistrasi($registrasiIds)
    {
        if ($registrasiIds->isEmpty()) {
            return collect();
        }

        return DB::table('registrasi_konsultasi_intake as intake')
            ->whereIn('intake.registrasi_id', $registrasiIds)
            ->where(function ($q) {
                $q->where('intake.status', '!=', 9)
                    ->orWhereNull('intake.status');
            })
            ->orderByDesc('intake.id')
            ->get([
                'intake.id',
                'intake.registrasi_id',
                'intake.request_dokter_id',
                'intake.request_dokter_nama',
                'intake.alergi',
                'intake.keluhan_utama',
                'intake.produk_obat_sebelumnya',
                'intake.sedang_hamil',
                'intake.sedang_menyusui',
                'intake.catatan_cs',
                'intake.jenis_konsultasi',
                'intake.keluhan_awal',
                'intake.catatan_awal',
                'intake.status',
                'intake.created_at',
            ])
            ->groupBy('registrasi_id')
            ->map(function ($rows) {
                $row = $rows->first();

                return [
                    'id' => (int) $row->id,
                    'request_dokter_id' => $row->request_dokter_id ? (int) $row->request_dokter_id : null,
                    'request_dokter_nama' => $row->request_dokter_nama,
                    'alergi' => $row->alergi,
                    'keluhan_utama' => $row->keluhan_utama,
                    'produk_obat_sebelumnya' => $row->produk_obat_sebelumnya,
                    'sedang_hamil' => $this->booleanText($row->sedang_hamil),
                    'sedang_menyusui' => $this->booleanText($row->sedang_menyusui),
                    'catatan_cs' => $row->catatan_cs,
                    'jenis_konsultasi' => (int) $row->jenis_konsultasi,
                    'jenis_konsultasi_text' => (int) $row->jenis_konsultasi === 2 ? 'Online' : 'Offline',
                    'keluhan_awal' => $row->keluhan_awal,
                    'catatan_awal' => $row->catatan_awal,
                    'status' => (int) $row->status,
                    'status_text' => $this->medicalStatusText($row->status),
                    'created_at' => $this->formatDateTime($row->created_at),
                ];
            });
    }

    private function riwayatSummary($pasienId)
    {
        $totalKunjungan = DB::table('registrasi_kunjungan')
            ->where('pasien_id', $pasienId)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->count();

        $paymentQuery = DB::table('pembayaran_invoice')
            ->where('pasien_id', $pasienId)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            });

        $totalTransaksi = (clone $paymentQuery)
            ->where('status', 3)
            ->sum('grand_total');

        $lastVisit = DB::table('registrasi_kunjungan')
            ->where('pasien_id', $pasienId)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->orderByDesc('tanggal_kunjungan')
            ->orderByDesc('id')
            ->first(['tanggal_kunjungan', 'registered_at']);

        $deposit = DB::table('pembayaran_deposit_treatment')
            ->where('pasien_id', $pasienId)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->where('status', 1)
            ->selectRaw('COALESCE(SUM(qty_sisa), 0) as qty_sisa, COALESCE(SUM(nilai_sisa), 0) as nilai_sisa')
            ->first();

        $member = DB::table('pasien_member')
            ->where('pasien_id', $pasienId)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->orderByDesc('id')
            ->first(['no_member', 'total_point', 'point_sisa', 'status']);

        $memberPointSisa = (float) ($member->point_sisa ?? 0);

        return [
            'total_kunjungan' => (int) $totalKunjungan,
            'total_transaksi' => (float) $totalTransaksi,
            'last_visit_date' => $lastVisit ? $this->formatDate($lastVisit->tanggal_kunjungan) : null,
            'last_visit_at' => $lastVisit ? $this->formatDateTime($lastVisit->registered_at) : null,

            'deposit_qty_sisa' => (float) ($deposit->qty_sisa ?? 0),
            'deposit_nilai_sisa' => (float) ($deposit->nilai_sisa ?? 0),

            'member_no' => $member->no_member ?? null,
            'member_status' => isset($member->status) ? $this->memberStatusText($member->status) : null,
            'member_total_point' => (float) ($member->total_point ?? 0),
            'member_point_sisa' => $memberPointSisa,
            'member_point_value' => $memberPointSisa * 2500,
        ];
    }

    private function buildLayanan($row, $items)
    {
        $layanan = [];

        if ((int) $row->channel_konsultasi > 0) {
            $layanan[] = $row->konsultasi_source_name ?: $this->channelKonsultasiText($row->channel_konsultasi);
        }

        if ((int) $row->is_treatment === 1 || $items->contains('item_type', 2) || $items->contains('item_type', 4)) {
            $layanan[] = 'Treatment';
        }

        if ((int) $row->is_penjualan === 1 || $items->contains('item_type', 3)) {
            $layanan[] = 'Produk/Obat';
        }

        if ((int) $row->is_pembelian_online === 1) {
            $layanan[] = 'Pembelian Online';
        }

        if ((int) $row->has_saran_dokter === 1) {
            $layanan[] = 'Saran Dokter';
        }

        if (empty($layanan)) {
            $layanan[] = 'Registrasi';
        }

        return collect($layanan)->unique()->values();
    }

    private function formatInvoiceItem($item)
    {
        return [
            'id' => (int) $item->id,
            'item_type' => (int) $item->item_type,
            'item_type_text' => $this->itemTypeText($item->item_type),
            'nama_item' => $item->nama_item,
            'satuan' => $item->satuan,
            'qty' => (float) $item->qty,
            'harga' => (float) $item->harga,
            'diskon_amount' => (float) $item->diskon_amount,
            'diskon_subtotal_amount' => (float) $item->diskon_subtotal_amount,
            'subtotal' => (float) $item->subtotal,
            'dokter_id' => $item->dokter_id ? (int) $item->dokter_id : null,
            'perawat_id' => $item->perawat_id ? (int) $item->perawat_id : null,
            'frekuensi' => $item->frekuensi,
            'waktu_pakai' => $item->waktu_pakai,
            'instruksi_pemakaian' => $item->instruksi_pemakaian,
        ];
    }

    private function formatPasienWithMember($pasien)
    {
        $data = $this->formatPasien($pasien);

        $member = DB::table('pasien_member')
            ->where('pasien_id', $pasien->id)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->orderByDesc('id')
            ->first();

        $data['member'] = $member ? [
            'id' => (int) $member->id,
            'no_member' => $member->no_member,
            'member_tier_id' => $member->member_tier_id ? (int) $member->member_tier_id : null,
            'toko_daftar_id' => $member->toko_daftar_id ? (int) $member->toko_daftar_id : null,
            'tanggal_daftar' => $this->formatDate($member->tanggal_daftar),
            'tanggal_expired' => $this->formatDate($member->tanggal_expired),
            'total_spending' => (float) $member->total_spending,
            'total_point' => (float) $member->total_point,
            'point_terpakai' => (float) $member->point_terpakai,
            'point_sisa' => (float) $member->point_sisa,
            'status' => (int) $member->status,
            'status_text' => $this->memberStatusText($member->status),
        ] : null;

        return $data;
    }

    private function validator(Request $request, $id = null, $mode = 'store')
    {
        return Validator::make($request->all(), [
            'nama' => 'nullable|string|max:150',
            'nama_pasien' => 'nullable|string|max:150',
            'tipe_pasien' => 'required',
            'toko_id' => $mode === 'store'
                ? 'required|exists:master_toko,id'
                : 'nullable|exists:master_toko,id',
            'no_identitas' => 'required|string|max:50',
            'jenis_kelamin' => 'required|in:L,P',
            'pekerjaan_id' => 'required|exists:master_pekerjaan,id',
            'pekerjaan' => 'nullable',
            'status_pernikahan' => 'nullable',
            'agama_id' => 'required|exists:master_agama,id',
            'agama' => 'nullable',
            'tempat_lahir' => 'required|string|max:100',
            'tanggal_lahir' => 'required|date',
            'no_telp' => 'nullable|string|max:30',
            'no_hp' => 'required|string|max:30',
            'no_wa' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',
            'provinsi_kode' => 'nullable|string|max:20',
            'kota_kode' => 'nullable|string|max:20',
            'kecamatan_kode' => 'nullable|string|max:20',
            'kelurahan_kode' => 'nullable|string|max:20',
            'provinsi' => 'nullable|string|max:20',
            'kota' => 'nullable|string|max:20',
            'kecamatan' => 'nullable|string|max:20',
            'kelurahan' => 'nullable|string|max:20',
            'alamat' => 'nullable|string',
            'alamat_detail' => 'required|string',
            'sumber_info' => 'nullable|string|max:150',
            'alergi_obat' => 'nullable|string',
            'masalah_kulit' => 'nullable|string',
            'catatan' => 'nullable|string',
        ], [
            'nama.required' => 'Nama pasien wajib diisi',
            'tipe_pasien.required' => 'Tipe pasien wajib diisi',
            'toko_id.required' => 'Cabang wajib diisi',
            'toko_id.exists' => 'Cabang tidak valid',
            'no_identitas.required' => 'KTP/SIM/Passport wajib diisi',
            'jenis_kelamin.required' => 'Jenis kelamin wajib diisi',
            'jenis_kelamin.in' => 'Jenis kelamin tidak valid',
            'pekerjaan_id.required' => 'Pekerjaan wajib diisi',
            'pekerjaan_id.exists' => 'Pekerjaan tidak valid',
            'agama_id.required' => 'Agama wajib diisi',
            'agama_id.exists' => 'Agama tidak valid',
            'tempat_lahir.required' => 'Tempat lahir wajib diisi',
            'tanggal_lahir.required' => 'Tanggal lahir wajib diisi',
            'tanggal_lahir.date' => 'Format tanggal lahir tidak valid',
            'no_hp.required' => 'No. HP wajib diisi',
            'email.email' => 'Format email tidak valid',
            'alamat_detail.required' => 'Alamat detail wajib diisi',
        ])->after(function ($validator) use ($request) {
            if (!$request->filled('nama') && !$request->filled('nama_pasien')) {
                $validator->errors()->add('nama_pasien', 'Nama pasien wajib diisi');
            }

            $tipePasien = $this->mapTipePasien($request->tipe_pasien);

            if (!in_array($tipePasien, [1, 2], true)) {
                $validator->errors()->add('tipe_pasien', 'Tipe pasien tidak valid');
            }

            $statusPernikahan = $this->mapStatusPernikahan($request->status_pernikahan);

            if (
                $request->filled('status_pernikahan')
                && !in_array($statusPernikahan, [1, 2, 3], true)
            ) {
                $validator->errors()->add('status_pernikahan', 'Status pernikahan tidak valid');
            }

            $this->validateDigitsField($validator, 'no_identitas', $request->input('no_identitas'), 16, 'KTP/SIM/Passport', true);
            $this->validateDigitsField($validator, 'no_telp', $request->input('no_telp'), 10, 'No. Telp', false);
            $this->validateMobilePhone62($validator, 'no_hp', $request->input('no_hp'), 'No. HP', true);
            $this->validateMobilePhone62($validator, 'no_wa', $request->input('no_wa'), 'No. WA', false);
        });
    }

    private function payload(Request $request)
    {
        return [
            'nama' => $request->nama ?? $request->nama_pasien,
            'tipe_pasien' => $this->mapTipePasien($request->tipe_pasien),
            'toko_id' => $request->toko_id,
            'no_identitas' => $this->cleanDigits($request->no_identitas),
            'jenis_kelamin' => $request->jenis_kelamin,
            'pekerjaan_id' => $request->pekerjaan_id ?? $this->extractId($request->pekerjaan),
            'status_pernikahan' => $this->mapStatusPernikahan($request->status_pernikahan),
            'agama_id' => $request->agama_id ?? $this->extractId($request->agama),
            'tempat_lahir' => $request->tempat_lahir,
            'tanggal_lahir' => $request->tanggal_lahir,
            'no_telp' => $this->cleanNullableDigits($request->no_telp),
            'no_hp' => $this->normalizePhone62($request->no_hp),
            'no_wa' => $this->normalizePhone62($request->no_wa),
            'email' => $request->email,
            'provinsi_kode' => $request->provinsi_kode ?? $this->extractCode($request->provinsi),
            'kota_kode' => $request->kota_kode ?? $this->extractCode($request->kota),
            'kecamatan_kode' => $request->kecamatan_kode ?? $this->extractCode($request->kecamatan),
            'kelurahan_kode' => $request->kelurahan_kode ?? $this->extractCode($request->kelurahan),
            'alamat' => $request->alamat ?? $request->alamat_detail,
            'sumber_info' => $request->sumber_info,
            'alergi_obat' => $request->alergi_obat,
            'masalah_kulit' => $request->masalah_kulit,
            'catatan' => $request->catatan,
            'is_delete' => 0,
        ];
    }

    private function validateDigitsField($validator, $field, $value, $maxLength, $label, $required = false)
    {
        $value = trim((string) $value);

        if ($value === '') {
            if ($required) {
                $validator->errors()->add($field, "{$label} wajib diisi");
            }

            return;
        }

        if (!preg_match('/^[0-9]+$/', $value)) {
            $validator->errors()->add($field, "{$label} hanya boleh angka");
            return;
        }

        if (strlen($value) > $maxLength) {
            $validator->errors()->add($field, "{$label} maksimal {$maxLength} digit");
        }
    }

    private function validateMobilePhone62($validator, $field, $value, $label, $required = false)
    {
        $value = trim((string) $value);

        if ($value === '') {
            if ($required) {
                $validator->errors()->add($field, "{$label} wajib diisi");
            }

            return;
        }

        if (!preg_match('/^[0-9]+$/', $value)) {
            $validator->errors()->add($field, "{$label} hanya boleh angka");
            return;
        }

        $normalized = $this->normalizePhone62($value);

        if (!$normalized || !str_starts_with($normalized, '62')) {
            $validator->errors()->add($field, "{$label} harus menggunakan format nomor Indonesia");
            return;
        }

        if (strlen($normalized) > 13) {
            $validator->errors()->add($field, "{$label} maksimal 13 digit termasuk kode negara 62");
        }
    }

    private function cleanDigits($value)
    {
        return preg_replace('/[^0-9]/', '', (string) $value);
    }

    private function cleanNullableDigits($value)
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return $this->cleanDigits($value);
    }

    private function normalizePhone62($value)
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $digits = $this->cleanDigits($value);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '62')) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            return '62' . substr($digits, 1);
        }

        if (str_starts_with($digits, '8')) {
            return '62' . $digits;
        }

        return $digits;
    }

    private function formatPasien($pasien)
    {
        return [
            'id' => $pasien->id,
            'no_rm' => $pasien->no_rm,
            'nama' => $pasien->nama,
            'nama_pasien' => $pasien->nama,
            'tipe_pasien' => $pasien->tipe_pasien,
            'tipe_pasien_text' => $this->tipePasienText($pasien->tipe_pasien),
            'toko_id' => $pasien->toko_id,
            'toko' => $pasien->relationLoaded('toko') && $pasien->toko
                ? [
                    'id' => $pasien->toko->id,
                    'kode_toko' => $pasien->toko->kode_toko,
                    'nama_toko' => $pasien->toko->nama_toko,
                    'label' => $pasien->toko->nama_toko,
                    'value' => $pasien->toko->id,
                ]
                : null,
            'no_identitas' => $pasien->no_identitas,
            'jenis_kelamin' => $pasien->jenis_kelamin,
            'jenis_kelamin_text' => $this->jenisKelaminText($pasien->jenis_kelamin),
            'pekerjaan_id' => $pasien->pekerjaan_id,
            'pekerjaan' => $pasien->relationLoaded('pekerjaan') && $pasien->pekerjaan
                ? [
                    'id' => $pasien->pekerjaan->id,
                    'nama_pekerjaan' => $pasien->pekerjaan->nama_pekerjaan,
                    'label' => $pasien->pekerjaan->nama_pekerjaan,
                    'value' => $pasien->pekerjaan->id,
                ]
                : null,
            'status_pernikahan' => $pasien->status_pernikahan,
            'status_pernikahan_text' => $this->statusPernikahanText($pasien->status_pernikahan),
            'agama_id' => $pasien->agama_id,
            'agama' => $pasien->relationLoaded('agama') && $pasien->agama
                ? [
                    'id' => $pasien->agama->id,
                    'kode_agama' => $pasien->agama->kode_agama,
                    'nama_agama' => $pasien->agama->nama_agama,
                    'label' => $pasien->agama->nama_agama,
                    'value' => $pasien->agama->id,
                ]
                : null,
            'tempat_lahir' => $pasien->tempat_lahir,
            'tanggal_lahir' => $this->formatDate($pasien->tanggal_lahir),
            'no_telp' => $pasien->no_telp,
            'no_hp' => $pasien->no_hp,
            'no_wa' => $pasien->no_wa,
            'email' => $pasien->email,
            'provinsi_kode' => $pasien->provinsi_kode,
            'kota_kode' => $pasien->kota_kode,
            'kecamatan_kode' => $pasien->kecamatan_kode,
            'kelurahan_kode' => $pasien->kelurahan_kode,
            'alamat' => $pasien->alamat,
            'alamat_detail' => $pasien->alamat,
            'sumber_info' => $pasien->sumber_info,
            'alergi_obat' => $pasien->alergi_obat,
            'masalah_kulit' => $pasien->masalah_kulit,
            'catatan' => $pasien->catatan,
            'is_delete' => $pasien->is_delete,
            'created_by' => $pasien->created_by,
            'updated_by' => $pasien->updated_by,
            'created_at' => $this->formatDateTime($pasien->created_at),
            'updated_at' => $this->formatDateTime($pasien->updated_at),
        ];
    }

    private function generateNoRm($tokoId, $date = null)
    {
        $date = $date ?: now()->format('Y-m-d');

        $toko = MasterToko::query()
            ->where('id', $tokoId)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->lockForUpdate()
            ->first();

        if (!$toko) {
            throw new \Exception('Data cabang tidak ditemukan');
        }

        if (empty($toko->kode_toko)) {
            throw new \Exception('Kode toko belum diatur');
        }

        $tanggal = date('Ymd', strtotime($date));
        $prefix = $toko->kode_toko . $tanggal;

        $lastNoRm = Pasien::query()
            ->where('toko_id', $tokoId)
            ->where('no_rm', 'like', "{$prefix}%")
            ->lockForUpdate()
            ->orderByDesc('no_rm')
            ->value('no_rm');

        if (!$lastNoRm) {
            return $prefix . '001';
        }

        $lastNumber = (int) substr($lastNoRm, -3);
        $nextNumber = $lastNumber + 1;

        return $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
    }

    private function mapTipePasien($value)
    {
        if (is_array($value)) {
            $value = $value['value'] ?? $value['id'] ?? $value['title'] ?? null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return match (strtolower(trim((string) $value))) {
            'pasien' => 1,
            'non pasien', 'non_pasien', 'non-pasien' => 2,
            default => null,
        };
    }

    private function mapStatusPernikahan($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            $value = $value['value'] ?? $value['id'] ?? $value['title'] ?? null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return match (strtolower(trim((string) $value))) {
            'belum menikah' => 1,
            'menikah' => 2,
            'cerai' => 3,
            default => null,
        };
    }

    private function extractId($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            $value = $value['id'] ?? $value['value'] ?? null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function extractCode($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value['code'] ?? $value['kode'] ?? $value['value'] ?? $value['id'] ?? null;
        }

        return (string) $value;
    }

    private function tipePasienText($value)
    {
        return match ((int) $value) {
            1 => 'Pasien',
            2 => 'Non Pasien',
            default => null,
        };
    }

    private function statusPernikahanText($value)
    {
        return match ((int) $value) {
            1 => 'Belum Menikah',
            2 => 'Menikah',
            3 => 'Cerai',
            default => null,
        };
    }

    private function jenisKelaminText($value)
    {
        return match ($value) {
            'L' => 'Laki-laki',
            'P' => 'Perempuan',
            default => null,
        };
    }

    private function channelKonsultasiText($value)
    {
        return match ((int) $value) {
            1 => 'Konsultasi Offline',
            2 => 'Konsultasi Online',
            default => 'Konsultasi',
        };
    }

    private function itemTypeText($value)
    {
        return match ((int) $value) {
            1 => 'Konsultasi',
            2 => 'Treatment',
            3 => 'Produk/Obat',
            4 => 'Deposit Treatment',
            5 => 'Accurate Marker',
            default => 'Item',
        };
    }

    private function jenisTransaksiText($value)
    {
        if ($value === null) {
            return null;
        }

        return match ((int) $value) {
            0 => 'Umum',
            1 => 'Endorse/Fasilitas Karyawan',
            2 => 'EliteGlowbal',
            3 => 'Owner',
            4 => 'Deposit',
            default => 'Lainnya',
        };
    }

    private function registrasiStatusText($value)
    {
        return match ((int) $value) {
            0 => 'Draft',
            1 => 'Aktif',
            2 => 'Selesai',
            9 => 'Batal',
            default => 'Tidak diketahui',
        };
    }

    private function invoiceStatusText($value)
    {
        return match ((int) $value) {
            0 => 'Draft',
            1 => 'Menunggu Pembayaran',
            2 => 'Diproses',
            3 => 'Lunas',
            4 => 'Belum Lunas',
            9 => 'Batal',
            default => 'Tidak diketahui',
        };
    }

    private function medicalStatusText($value)
    {
        return match ((int) $value) {
            0 => 'Draft/Menunggu',
            1 => 'Proses/Final',
            2 => 'Selesai',
            9 => 'Batal',
            default => 'Tidak diketahui',
        };
    }

    private function memberStatusText($value)
    {
        return match ((int) $value) {
            1 => 'Aktif',
            2 => 'Expired',
            3 => 'Suspend',
            9 => 'Batal',
            default => 'Tidak diketahui',
        };
    }

    private function booleanText($value)
    {
        if ($value === null) {
            return null;
        }

        return (int) $value === 1 ? 'Ya' : 'Tidak';
    }

    private function statusColor($invoiceStatus, $registrasiStatus)
    {
        if ($invoiceStatus !== null) {
            return match ((int) $invoiceStatus) {
                3 => 'success',
                4 => 'warning',
                9 => 'error',
                1, 2 => 'primary',
                default => 'default',
            };
        }

        return match ((int) $registrasiStatus) {
            2 => 'success',
            9 => 'error',
            1 => 'primary',
            default => 'default',
        };
    }

    private function formatDate($value)
    {
        if (empty($value)) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'format')) {
            return $value->format('Y-m-d');
        }

        return date('Y-m-d', strtotime($value));
    }

    private function formatTime($value)
    {
        if (empty($value)) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'format')) {
            return $value->format('H:i');
        }

        return date('H:i', strtotime($value));
    }

    private function formatDateTime($value)
    {
        if (empty($value)) {
            return null;
        }

        if (is_object($value) && method_exists($value, 'format')) {
            return $value->format('Y-m-d H:i:s');
        }

        return date('Y-m-d H:i:s', strtotime($value));
    }

    private function authUserId()
    {
        if (auth('api')->check()) {
            return auth('api')->id();
        }

        return null;
    }
}
