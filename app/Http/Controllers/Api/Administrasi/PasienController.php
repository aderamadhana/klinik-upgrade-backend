<?php

namespace App\Http\Controllers\Api\Administrasi;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterToko;
use App\Models\Pasien;
use App\Models\PasienMember;
use App\Models\Pembayaran\PembayaranDepositTreatment;
use App\Models\Pembayaran\PembayaranInvoice;
use App\Models\Registrasi\RegistrasiDokterSoapDiagnosa;
use App\Models\Registrasi\RegistrasiDokterSoapSubjective;
use App\Models\Registrasi\RegistrasiKunjungan;
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

        $invoices = PembayaranInvoice::query()
            ->with([
                'registrasi.toko:id,kode_toko,nama_toko',
                'registrasi.dokterAwal:id,nama',
                'registrasi.perawatAwal:id,nama',
                'registrasi.dokterSoap.dokter:id,nama',
                'registrasi.perawatCppts' => function ($query) {
                    $query
                        ->with([
                            'dokter:id,nama',
                            'perawat:id,nama',
                        ])
                        ->where(function ($q) {
                            $q->where('is_delete', 0)
                                ->orWhereNull('is_delete');
                        })
                        ->where(function ($q) {
                            $q->where('status', '!=', 9)
                                ->orWhereNull('status');
                        })
                        ->orderByDesc('tanggal_pengisian')
                        ->orderByDesc('id');
                },
                'registrasi.konsultasiIntake',
                'items' => function ($query) {
                    $query
                        ->where(function ($q) {
                            $q->where('is_delete', 0)
                                ->orWhereNull('is_delete');
                        })
                        ->where(function ($q) {
                            $q->where('status', '!=', 9)
                                ->orWhereNull('status');
                        })
                        ->orderBy('item_type')
                        ->orderBy('id');
                },
            ])
            ->where('pasien_id', $pasien->id)
            ->where('status', 3)
            ->whereNotNull('tanggal_lunas')
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->whereHas('registrasi', function ($query) use ($pasien) {
                $query
                    ->where('pasien_id', $pasien->id)
                    ->where(function ($q) {
                        $q->where('is_delete', 0)
                            ->orWhereNull('is_delete');
                    });
            })
            ->orderByDesc('tanggal_lunas')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $soapIds = $invoices
            ->pluck('registrasi.dokterSoap.id')
            ->filter()
            ->unique()
            ->values();

        $subjectiveBySoap = $this->subjectiveBySoap($soapIds);
        $diagnosaBySoap = $this->diagnosaBySoap($soapIds);

        $riwayat = $invoices
            ->filter(function ($invoice) {
                return $invoice->registrasi !== null;
            })
            ->map(function ($invoice) use ($subjectiveBySoap, $diagnosaBySoap) {
                return $this->formatRiwayatInvoice($invoice, $subjectiveBySoap, $diagnosaBySoap);
            })
            ->values();

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

    private function subjectiveBySoap($soapIds)
    {
        if ($soapIds->isEmpty()) {
            return collect();
        }

        return RegistrasiDokterSoapSubjective::query()
            ->whereIn('soap_id', $soapIds)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('soap_id');
    }

    private function diagnosaBySoap($soapIds)
    {
        if ($soapIds->isEmpty()) {
            return collect();
        }

        return RegistrasiDokterSoapDiagnosa::query()
            ->whereIn('soap_id', $soapIds)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('soap_id');
    }

    private function formatRiwayatInvoice($invoice, $subjectiveBySoap, $diagnosaBySoap)
    {
        $registrasi = $invoice->registrasi;
        $items = $invoice->relationLoaded('items') ? $invoice->items : collect();

        return [
            'id' => (int) $registrasi->id,
            'registrasi_id' => (int) $registrasi->id,
            'kode_registrasi' => $registrasi->kode_registrasi,
            'tanggal' => $this->formatDate($registrasi->tanggal_kunjungan),
            'waktu' => $this->formatTime($registrasi->registered_at),
            'registered_at' => $this->formatDateTime($registrasi->registered_at),
            'toko' => [
                'id' => $registrasi->toko_id ? (int) $registrasi->toko_id : null,
                'kode_toko' => optional($registrasi->toko)->kode_toko,
                'nama_toko' => optional($registrasi->toko)->nama_toko,
            ],
            'dokter' => [
                'id' => $registrasi->dokter_awal_id ? (int) $registrasi->dokter_awal_id : null,
                'nama' => optional($registrasi->dokterAwal)->nama,
            ],
            'perawat' => [
                'id' => $registrasi->perawat_awal_id ? (int) $registrasi->perawat_awal_id : null,
                'nama' => optional($registrasi->perawatAwal)->nama,
            ],
            'layanan' => $this->buildLayanan($registrasi, $items),
            'catatan' => $registrasi->catatan_registrasi,
            'status' => [
                'registrasi' => (int) $registrasi->status,
                'registrasi_text' => $this->registrasiStatusText($registrasi->status),
                'invoice' => (int) $invoice->status,
                'invoice_text' => $this->invoiceStatusText($invoice->status),
                'text' => $this->invoiceStatusText($invoice->status),
                'color' => $this->statusColor($invoice->status, $registrasi->status),
            ],
            'pembayaran' => [
                'id' => (int) $invoice->id,
                'no_invoice' => $invoice->no_invoice,
                'member_no' => $invoice->member_no,
                'member_tier_nama' => $invoice->member_tier_nama,
                'tanggal_invoice' => $this->formatDateTime($invoice->tanggal_invoice),
                'tanggal_lunas' => $this->formatDateTime($invoice->tanggal_lunas),
                'jenis_transaksi' => $invoice->jenis_transaksi !== null ? (int) $invoice->jenis_transaksi : null,
                'jenis_transaksi_text' => $this->jenisTransaksiText($invoice->jenis_transaksi),
                'subtotal_produk' => (float) ($invoice->subtotal_produk ?? 0),
                'subtotal_treatment' => (float) ($invoice->subtotal_treatment ?? 0),
                'subtotal_konsultasi' => (float) ($invoice->subtotal_konsultasi ?? 0),
                'subtotal' => (float) ($invoice->subtotal ?? 0),
                'total_diskon_item' => (float) ($invoice->total_diskon_item ?? 0),
                'diskon_subtotal_amount' => (float) ($invoice->diskon_subtotal_amount ?? 0),
                'total_promo' => (float) ($invoice->total_promo ?? 0),
                'diskon_member_amount' => (float) ($invoice->diskon_member_amount ?? 0),
                'point_earned' => (float) ($invoice->point_earned ?? 0),
                'point_redeemed' => (float) ($invoice->point_redeemed ?? 0),
                'point_redeem_value' => (float) ($invoice->point_redeem_value ?? 0),
                'grand_total' => (float) ($invoice->grand_total ?? $registrasi->grand_total ?? 0),
                'total_bayar' => (float) ($invoice->total_bayar ?? 0),
                'total_kembalian' => (float) ($invoice->total_kembalian ?? 0),
                'sisa_tagihan' => (float) ($invoice->sisa_tagihan ?? 0),
            ],
            'items' => $items->map(function ($item) {
                return $this->formatInvoiceItem($item);
            })->values(),
            'soap' => $this->formatSoap(
                $registrasi->relationLoaded('dokterSoap') ? $registrasi->dokterSoap : null,
                $subjectiveBySoap,
                $diagnosaBySoap
            ),
            'cppt' => $registrasi->relationLoaded('perawatCppts')
                ? $registrasi->perawatCppts->map(function ($cppt) {
                    return $this->formatCppt($cppt);
                })->values()
                : collect(),
            'intake' => $this->formatIntake(
                $registrasi->relationLoaded('konsultasiIntake') ? $registrasi->konsultasiIntake : null
            ),
        ];
    }

    private function formatSoap($soap, $subjectiveBySoap, $diagnosaBySoap)
    {
        if (!$soap) {
            return null;
        }

        $subjectiveRows = $subjectiveBySoap->get($soap->id, collect());
        $diagnosaRows = $diagnosaBySoap->get($soap->id, collect());

        return [
            'id' => (int) $soap->id,
            'dokter' => [
                'id' => $soap->dokter_id ? (int) $soap->dokter_id : null,
                'nama' => optional($soap->dokter)->nama,
            ],
            'subjective' => $subjectiveRows
                ->map(function ($row) {
                    return $row->subjective_text ?? null;
                })
                ->filter()
                ->values(),
            'objective' => $soap->objective,
            'assessment' => $diagnosaRows
                ->map(function ($row) {
                    return $row->diagnosa_text ?? null;
                })
                ->filter()
                ->values(),
            'assessment_lainnya' => $soap->assessment_lainnya,
            'plan' => $soap->plan,
            'next_konsultasi_date' => $this->formatDate($soap->next_konsultasi_date),
            'status' => (int) $soap->status,
            'status_text' => $this->medicalStatusText($soap->status),
            'finalized_at' => $this->formatDateTime($soap->finalized_at),
            'created_at' => $this->formatDateTime($soap->created_at),
        ];
    }

    private function formatCppt($cppt)
    {
        return [
            'id' => (int) $cppt->id,
            'registrasi_id' => (int) $cppt->registrasi_id,

            'tanggal_pengisian' => $this->formatDateTime($cppt->tanggal_pengisian),
            'tanggal_jam' => $this->formatDateTime($cppt->tanggal_pengisian),

            'dokter' => [
                'id' => $cppt->dokter_id ? (int) $cppt->dokter_id : null,
                'nama' => optional($cppt->dokter)->nama,
            ],

            'perawat' => [
                'id' => $cppt->perawat_id ? (int) $cppt->perawat_id : null,
                'nama' => optional($cppt->perawat)->nama,
            ],

            'subjective' => $cppt->subjective,
            'objective' => $cppt->objective,
            'assessment' => $cppt->assessment,
            'plan' => $cppt->plan,
            'tindakan' => $cppt->tindakan,

            'subjective_note' => $cppt->subjective,
            'objective_note' => $cppt->objective,
            'assessment_note' => $cppt->assessment,
            'plan_note' => $cppt->plan,
            'tindakan_evaluasi_note' => $cppt->tindakan,

            'status' => (int) $cppt->status,
            'status_text' => $this->medicalStatusText($cppt->status),
            'finalized_at' => null,

            'created_at' => $this->formatDateTime($cppt->created_at),
            'updated_at' => $this->formatDateTime($cppt->updated_at),
        ];
    }

    private function formatIntake($intake)
    {
        if (!$intake) {
            return null;
        }

        return [
            'id' => (int) $intake->id,
            'request_dokter_id' => $intake->request_dokter_id ? (int) $intake->request_dokter_id : null,
            'request_dokter_nama' => $intake->request_dokter_nama,
            'alergi' => $intake->alergi,
            'keluhan_utama' => $intake->keluhan_utama,
            'produk_obat_sebelumnya' => $intake->produk_obat_sebelumnya,
            'sedang_hamil' => $this->booleanText($intake->sedang_hamil),
            'sedang_menyusui' => $this->booleanText($intake->sedang_menyusui),
            'catatan_cs' => $intake->catatan_cs,
            'jenis_konsultasi' => (int) $intake->jenis_konsultasi,
            'jenis_konsultasi_text' => (int) $intake->jenis_konsultasi === 2 ? 'Online' : 'Offline',
            'keluhan_awal' => $intake->keluhan_awal,
            'catatan_awal' => $intake->catatan_awal,
            'status' => (int) $intake->status,
            'status_text' => $this->medicalStatusText($intake->status),
            'created_at' => $this->formatDateTime($intake->created_at),
        ];
    }

    private function riwayatSummary($pasienId)
    {
        $totalKunjungan = RegistrasiKunjungan::query()
            ->where('pasien_id', $pasienId)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->count();

        $paymentQuery = PembayaranInvoice::query()
            ->where('pasien_id', $pasienId)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            });

        $totalTransaksi = (clone $paymentQuery)
            ->where('status', 3)
            ->whereNotNull('tanggal_lunas')
            ->sum('grand_total');

        $lastVisit = RegistrasiKunjungan::query()
            ->where('pasien_id', $pasienId)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->orderByDesc('tanggal_kunjungan')
            ->orderByDesc('id')
            ->first(['tanggal_kunjungan', 'registered_at']);

        $depositQuery = PembayaranDepositTreatment::query()
            ->where('pasien_id', $pasienId)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->where('status', 1);

        $member = PasienMember::query()
            ->where('pasien_id', $pasienId)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->orderByDesc('id')
            ->first();

        $memberPointSisa = (float) ($member->point_sisa ?? 0);

        return [
            'total_kunjungan' => (int) $totalKunjungan,
            'total_transaksi' => (float) $totalTransaksi,
            'last_visit_date' => $lastVisit ? $this->formatDate($lastVisit->tanggal_kunjungan) : null,
            'last_visit_at' => $lastVisit ? $this->formatDateTime($lastVisit->registered_at) : null,

            'deposit_qty_sisa' => (float) (clone $depositQuery)->sum('qty_sisa'),
            'deposit_nilai_sisa' => (float) (clone $depositQuery)->sum('nilai_sisa'),

            'member_no' => $member->no_member ?? null,
            'member_status' => isset($member->status) ? $this->memberStatusText($member->status) : null,
            'member_total_point' => (float) ($member->total_point ?? 0),
            'member_point_sisa' => $memberPointSisa,
            'member_point_value' => $memberPointSisa * 2500,
        ];
    }

    private function buildLayanan($registrasi, $items)
    {
        $layanan = [];

        if ((int) $registrasi->channel_konsultasi > 0) {
            $layanan[] = $registrasi->konsultasi_source_name ?: $this->channelKonsultasiText($registrasi->channel_konsultasi);
        }

        if ((int) $registrasi->is_treatment === 1 || $items->contains('item_type', 2) || $items->contains('item_type', 4)) {
            $layanan[] = 'Treatment';
        }

        if ((int) $registrasi->is_penjualan === 1 || $items->contains('item_type', 3)) {
            $layanan[] = 'Produk/Obat';
        }

        if ((int) $registrasi->is_pembelian_online === 1) {
            $layanan[] = 'Pembelian Online';
        }

        if ((int) $registrasi->has_saran_dokter === 1) {
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
            'pembayaran_id' => $item->pembayaran_id ? (int) $item->pembayaran_id : null,
            'registrasi_id' => $item->registrasi_id ? (int) $item->registrasi_id : null,
            'item_type' => (int) $item->item_type,
            'item_type_text' => $this->itemTypeText($item->item_type),
            'source_type' => $item->source_type !== null ? (int) $item->source_type : null,
            'source_id' => $item->source_id !== null ? (int) $item->source_id : null,
            'source_detail_id' => $item->source_detail_id !== null ? (int) $item->source_detail_id : null,
            'kode_item' => $item->kode_item ?? null,
            'nama_item' => $item->nama_item,
            'satuan' => $item->satuan,
            'qty' => (float) $item->qty,
            'harga' => (float) $item->harga,
            'diskon_tipe' => $item->diskon_tipe !== null ? (int) $item->diskon_tipe : null,
            'diskon_nilai' => (float) ($item->diskon_nilai ?? 0),
            'diskon_amount' => (float) ($item->diskon_amount ?? 0),
            'diskon_referral' => (float) ($item->diskon_referral ?? 0),
            'diskon_subtotal_amount' => (float) ($item->diskon_subtotal_amount ?? 0),
            'subtotal' => (float) ($item->subtotal ?? 0),
            'dokter_id' => $item->dokter_id ? (int) $item->dokter_id : null,
            'perawat_id' => $item->perawat_id ? (int) $item->perawat_id : null,
            'frekuensi' => $item->frekuensi,
            'waktu_pakai' => $item->waktu_pakai,
            'instruksi_pemakaian' => $item->instruksi_pemakaian,
            'expired_at' => $this->formatDate($item->expired_at),
            'status' => $item->status !== null ? (int) $item->status : null,
            'is_saran_dokter' => isset($item->is_saran_dokter) ? (int) $item->is_saran_dokter : 0,
        ];
    }

    private function formatPasienWithMember($pasien)
    {
        $data = $this->formatPasien($pasien);

        $member = PasienMember::query()
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
