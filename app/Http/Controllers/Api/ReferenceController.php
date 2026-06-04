<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;
use App\Models\Master\MasterRole;
use App\Models\Master\MasterJabatan;
use App\Models\Master\MasterToko;
use App\Models\Master\MasterKaryawan;
use App\Models\Master\MasterGolonganProduk;
use App\Models\Master\MasterKategoriProduk;
use App\Models\Master\MasterTempatProduk;
use App\Models\Master\MasterSatuan;
use App\Models\Master\MasterUnitTreatment;
use App\Models\Master\MasterTipeTreatment;
use App\Models\Master\MasterProduk;
use App\Models\Master\MasterTreatment;
use App\Models\master\MasterVoucherDiskon;
use App\Models\master\MasterVoucherDiskonJenis;
use App\Models\master\MasterVoucherDiskonKategori;
use App\Models\master\MasterVoucherDiskonTemplate;
use App\Models\Master\MasterAgama;
use App\Models\Master\MasterPekerjaan;
use App\Models\Master\MasterMetodeBayar;    
use App\Models\Master\MasterProdukToko;
use App\Models\Master\MasterMerchandise;
use App\Models\Master\MasterAccurateItemMapping;
use App\Models\Master\MasterSubjective;
use App\Models\Master\MasterAssessment;
use App\Models\Master\MasterJenisTransaksi;
use App\Models\Master\MasterSumberInformasi;
use App\Models\Master\MasterPerawatBahan;   
use App\Models\Pasien;

class ReferenceController extends Controller
{
    public function roles()
    {
        $data = MasterRole::active()
            ->select('id', 'kode_role', 'nama_role')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Data role berhasil diambil',
            'data' => $data,
        ]);
    }

    public function jabatan()
    {
        $data = MasterJabatan::active()
            ->select('id', 'kode_jabatan', 'nama_jabatan')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Data jabatan berhasil diambil',
            'data' => $data,
        ]);
    }

    public function toko()
    {
        $data = MasterToko::active()
            ->select('id', 'kode', 'kode_toko', 'nama_toko', 'jenis_toko', 'no_telepon', 'alamat')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Data toko berhasil diambil',
            'data' => $data,
        ]);
    }

    public function initialMaster()
    {
        return response()->json([
            'status' => true,
            'message' => 'Data referensi awal berhasil diambil',
            'data' => [
                'roles' => MasterRole::active()
                    ->select('id', 'kode_role', 'nama_role')
                    ->orderBy('sort_order')
                    ->get(),

                'jabatan' => MasterJabatan::active()
                    ->select('id', 'kode_jabatan', 'nama_jabatan')
                    ->orderBy('sort_order')
                    ->get(),

                'toko' => MasterToko::active()
                    ->select('id', 'kode', 'kode_toko', 'nama', 'jenis_toko', 'no_telepon', 'alamat')
                    ->orderBy('sort_order')
                    ->get(),
            ],
        ]);
    }

    public function karyawanCode(Request $request)
    {
        $request->validate([
            'jabatan_id' => 'required|integer',
            'toko_id' => 'required|integer',
        ]);

        $jabatan = MasterJabatan::query()
            ->where('id', $request->jabatan_id)
            ->first();

        $toko = MasterToko::query()
            ->where('id', $request->toko_id)
            ->first();

        if (!$jabatan || !$toko) {
            return response()->json([
                'message' => 'Jabatan atau toko tidak ditemukan',
            ], 404);
        }

        $kodeToko = $toko->kode
            ?? $toko->kode_toko
            ?? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $toko->nama ?? 'TKO'), 0, 3));

        $kodeJabatan = $jabatan->kode
            ?? $jabatan->kode_jabatan
            ?? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $jabatan->nama ?? 'KRY'), 0, 3));

        $kodeToko = strtoupper($kodeToko);
        $kodeJabatan = strtoupper($kodeJabatan);

        $prefix = 'KRY-' . $kodeToko . '-' . $kodeJabatan . '-';

        $lastKode = MasterKaryawan::query()
            ->where('kode', 'like', $prefix . '%')
            ->orderByRaw('CAST(RIGHT(kode, 4) AS UNSIGNED) DESC')
            ->value('kode');

        $lastNumber = 0;

        if ($lastKode) {
            $lastNumber = (int) substr($lastKode, -4);
        }

        $nextNumber = $lastNumber + 1;

        $kode = $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

        return response()->json([
            'data' => [
                'kode' => $kode,
            ],
        ]);
    }

    public function golonganProduk()
    {
        $data = MasterGolonganProduk::query()
            ->active()
            ->orderBy('nama_golongan_produk')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Data golongan produk berhasil diambil',
            'data' => $data,
        ]);
    }

    public function kategoriProduk()
    {
        $data = MasterKategoriProduk::query()
            ->active()
            ->orderBy('nama_kategori_produk')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Data kategori produk berhasil diambil',
            'data' => $data,
        ]);
    }

    public function tempatProduk()
    {
        $data = MasterTempatProduk::query()
            ->active()
            ->orderBy('nama_tempat_produk')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Data tempat produk berhasil diambil',
            'data' => $data,
        ]);
    }

    public function satuan()
    {
        $data = MasterSatuan::query()
            ->active()
            ->orderBy('nama_satuan')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Data satuan berhasil diambil',
            'data' => $data,
        ]);
    }

    public function unitTreatment()
    {
        $data = MasterUnitTreatment::query()
            ->active()
            ->orderBy('nama_unit')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Data unit treatment berhasil diambil',
            'data' => $data,
        ]);
    }

    public function tipeTreatment()
    {
        $data = MasterTipeTreatment::query()
            ->active()
            ->orderBy('nama_tipe_treatment')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Data tipe treatment berhasil diambil',
            'data' => $data,
        ]);
    }

    public function produkByToko(Request $request)
    {
        $tokoId = $request->get('toko_id');
        $tempatProdukId = $request->get('tempat_produk_id');

        if (!$tokoId) {
            return response()->json([
                'status' => false,
                'message' => 'toko_id wajib diisi',
            ], 422);
        }

        $data = MasterProdukToko::query()
            ->active()
            ->where('toko_id', $tokoId)
            ->with([
                'toko',
                'supplier',
                'produk' => function ($q) {
                    $q->where(function ($sub) {
                        $sub->where('is_delete', 0)
                            ->orWhereNull('is_delete');
                    })->with([
                        'kategori',
                        'golongan',
                        'satuan',
                        'tempatProduk',
                    ]);
                },
                'stockProdukToko' => function ($q) use ($tokoId, $tempatProdukId) {
                    $q->active()
                        ->where('toko_id', $tokoId);

                    if ($tempatProdukId) {
                        $q->where('tempat_produk_id', $tempatProdukId);
                    }

                    $q->orderByDesc('id');
                },
                'stockProdukToko.tempatProduk',
            ])
            ->whereHas('produk', function ($q) use ($tempatProdukId) {
                $q->where(function ($sub) {
                    $sub->where('is_delete', 0)
                        ->orWhereNull('is_delete');
                });

                if ($tempatProdukId) {
                    $q->where(function ($sub) use ($tempatProdukId) {
                        $sub->where('tempat_produk_id', $tempatProdukId);

                        if ((int) $tempatProdukId === 1) {
                            $sub->orWhereNull('tempat_produk_id');
                        }
                    });
                }
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function ($produkToko) use ($tokoId, $tempatProdukId) {
                $produk = $produkToko->produk;

                if (!$produk) {
                    return null;
                }

                $stock = $produkToko->stockProdukToko
                    ? $produkToko->stockProdukToko->sortByDesc('id')->first()
                    : null;

                $resolvedTempatProdukId = $tempatProdukId
                    ?: ($stock->tempat_produk_id ?? $produk->tempat_produk_id ?? 1);

                $namaTempatProduk =
                    $stock?->tempatProduk?->nama_tempat_produk
                    ?? $produk?->tempatProduk?->nama_tempat_produk
                    ?? $produk?->tempatProduk?->nama
                    ?? '-';

                /*
                * Aturan stok reference:
                * 1. Kalau stock_produk_toko ada, pakai stock_produk_toko.
                * 2. Kalau belum ada, fallback ke master_produk_toko.stok_awal.
                *
                * Ini membuat dropdown registrasi, stok produk, dan stok tersedia
                * membaca angka yang sama untuk masa transisi.
                */
                $stokAwalMaster = (float) ($produkToko->stok_awal ?? 0);

                if ($stock) {
                    $stokAwal = $stokAwalMaster;
                    $stokMasuk = (float) ($stock->stok_masuk ?? 0);
                    $stokKeluar = (float) ($stock->stok_keluar ?? 0);
                    $stokPenyesuaian = (float) ($stock->stok_penyesuaian ?? 0);
                    $stokAkhir = (float) ($stock->stok_akhir ?? 0);
                    $stokReserved = (float) ($stock->stok_reserved ?? 0);
                    $stokMinimum = (float) ($stock->stok_minimum ?? $produkToko->stok_minimum ?? 0);

                    $hargaBeliTerakhir = (float) ($stock->harga_beli_terakhir ?? $produkToko->harga_beli ?? 0);
                    $hargaJualTerakhir = (float) ($stock->harga_jual_terakhir ?? $produkToko->harga_jual ?? 0);

                    $stockProdukTokoId = $stock->id;
                    $sumberStok = 'stock_produk_toko';
                    $belumAdaSaldoStok = 0;
                    $lastMutationAt = $stock->last_mutation_at ?? null;
                } else {
                    $stokAwal = $stokAwalMaster;
                    $stokMasuk = 0;
                    $stokKeluar = 0;
                    $stokPenyesuaian = 0;
                    $stokAkhir = $stokAwalMaster;
                    $stokReserved = 0;
                    $stokMinimum = (float) ($produkToko->stok_minimum ?? 0);

                    $hargaBeliTerakhir = (float) ($produkToko->harga_beli ?? 0);
                    $hargaJualTerakhir = (float) ($produkToko->harga_jual ?? 0);

                    $stockProdukTokoId = null;
                    $sumberStok = 'master_produk_toko';
                    $belumAdaSaldoStok = 1;
                    $lastMutationAt = null;
                }

                $stokTersedia = max($stokAkhir - $stokReserved, 0);

                $isStokHabis = $stokTersedia <= 0 ? 1 : 0;
                $isStokMinimum = $stokTersedia > 0 && $stokMinimum > 0 && $stokTersedia <= $stokMinimum ? 1 : 0;

                if ($isStokHabis) {
                    $statusStok = 'HABIS';
                } elseif ($isStokMinimum) {
                    $statusStok = 'STOK MINIMUM';
                } else {
                    $statusStok = 'TERSEDIA';
                }

                $kodeProduk = $produk->kode_accurate ?? $produk->kode ?? '-';
                $namaProduk = $produk->nama ?? '-';

                $labelProduk = trim($kodeProduk . ' - ' . $namaProduk);

                $labelDropdown = trim(
                    $kodeProduk .
                    ' - ' .
                    $namaProduk .
                    ' | ' .
                    $namaTempatProduk .
                    ' | Bisa dijual: ' .
                    $stokTersedia
                );

                return [
                    'produk_id' => $produk->id,
                    'kode' => $produk->kode ?? null,
                    'kode_accurate' => $produk->kode_accurate ?? null,
                    'nama' => $namaProduk,

                    'label_produk' => $labelProduk,
                    'label_dropdown' => $labelDropdown,

                    'satuan_id' => $produk->satuan_id ?? null,
                    'nama_satuan' => $produk->satuan->nama_satuan ?? $produk->satuan->nama ?? null,

                    'kategori_produk_id' => $produk->kategori_produk_id ?? null,
                    'nama_kategori_produk' => $produk->kategori->nama_kategori_produk ?? $produk->kategori->nama ?? null,

                    'golongan_produk_id' => $produk->golongan_produk_id ?? null,
                    'nama_golongan_produk' => $produk->golongan->nama_golongan_produk ?? $produk->golongan->nama ?? null,

                    'tempat_produk_id' => $resolvedTempatProdukId,
                    'nama_tempat_produk' => $namaTempatProduk,

                    'is_obat_resep' => (int) ($produk->is_obat_resep ?? 0),
                    'is_obat_bebas' => (int) ($produk->is_obat_bebas ?? 0),

                    'produk_toko_id' => $produkToko->id,
                    'toko_id' => $produkToko->toko_id,
                    'nama_toko' => $produkToko->toko->nama_toko ?? $produkToko->toko->nama ?? null,

                    'supplier_id' => $produkToko->supplier_id ?? null,
                    'nama_supplier' => $produkToko->supplier->nama_supplier ?? $produkToko->supplier->nama ?? null,

                    'harga_jual' => (float) ($produkToko->harga_jual ?? 0),
                    'harga_beli' => (float) ($produkToko->harga_beli ?? 0),

                    'harga_jual_terakhir' => $hargaJualTerakhir,
                    'harga_beli_terakhir' => $hargaBeliTerakhir,

                    'stock_produk_toko_id' => $stockProdukTokoId,

                    'stok_awal' => $stokAwal,
                    'stok_masuk' => $stokMasuk,
                    'stok_keluar' => $stokKeluar,
                    'stok_penyesuaian' => $stokPenyesuaian,
                    'stok_akhir' => $stokAkhir,
                    'stok_reserved' => $stokReserved,
                    'stok_tersedia' => $stokTersedia,
                    'stok_minimum' => $stokMinimum,

                    'sumber_stok' => $sumberStok,
                    'belum_ada_saldo_stok' => $belumAdaSaldoStok,
                    'last_mutation_at' => $lastMutationAt,

                    'fee_dokter' => (float) ($produkToko->fee_dokter ?? 0),
                    'fee_beautician' => (float) ($produkToko->fee_beautician ?? 0),

                    'is_stok_habis' => $isStokHabis,
                    'is_stok_minimum' => $isStokMinimum,
                    'status_stok' => $statusStok,

                    'disabled' => $isStokHabis === 1,
                    'item_props' => [
                        'disabled' => $isStokHabis === 1,
                        'title' => $labelDropdown,
                        'subtitle' => $isStokHabis === 1
                            ? 'Stok kosong'
                            : 'Bisa dijual: ' . $stokTersedia,
                    ],

                    'produk' => $produk,
                    'produk_toko' => $produkToko,
                    'stock_produk_toko' => $stock,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Data produk berdasarkan toko berhasil diambil',
            'data' => $data,
        ]);
    }
    public function treatmentByToko(Request $request)
    {
        $tokoId = $request->get('toko_id');

        if (!$tokoId) {
            return response()->json([
                'status' => false,
                'message' => 'toko_id wajib diisi',
            ], 422);
        }

        $data = MasterTreatment::query()
            ->active()
            ->with([
                'unit',
                'tipe',
                'hargaToko' => function ($q) use ($tokoId) {
                    $q->active()
                        ->where('toko_id', $tokoId)
                        ->with(['toko'])
                        ->orderBy('sort_order');
                },
            ])
            ->whereHas('hargaToko', function ($q) use ($tokoId) {
                $q->active()
                    ->where('toko_id', $tokoId);
            })
            ->orderBy('nama')
            ->get()
            ->map(function ($treatment) {
                $hargaToko = $treatment->hargaToko->first();

                $isActive = (int) ($hargaToko->is_active ?? 0);

                return [
                    'treatment_id' => $treatment->id,
                    'legacy_id' => $treatment->legacy_id,
                    'kode' => $treatment->kode,
                    'kode_accurate' => $treatment->kode_accurate,
                    'nama' => $treatment->nama,
                    'kategori_sales' => $treatment->kategori_sales,

                    'unit_id' => $treatment->unit_id,
                    'nama_unit_treatment' => $treatment->unit->nama_unit_treatment ?? null,

                    'tipe_id' => $treatment->tipe_id,
                    'nama_tipe_treatment' => $treatment->tipe->nama_tipe_treatment ?? null,

                    'waktu' => (int) ($treatment->waktu ?? 0),
                    'is_ppn' => (int) ($treatment->is_ppn ?? 0),

                    'treatment_toko_id' => $hargaToko->id ?? null,
                    'toko_id' => $hargaToko->toko_id ?? null,
                    'nama_toko' => $hargaToko->toko->nama_toko ?? null,

                    'harga_terendah' => (float) ($hargaToko->harga_terendah ?? 0),
                    'tarif' => (float) ($hargaToko->tarif ?? 0),
                    'biaya_modal' => (float) ($hargaToko->biaya_modal ?? 0),

                    'tarif_dokter' => (float) ($hargaToko->tarif_dokter ?? 0),
                    'tarif_beautician' => (float) ($hargaToko->tarif_beautician ?? 0),

                    'presentase_tarif_dokter' => (float) ($hargaToko->presentase_tarif_dokter ?? 0),
                    'presentase_tarif_dokter_sp' => (float) ($hargaToko->presentase_tarif_dokter_sp ?? 0),

                    'flat_tarif_dokter' => (float) ($hargaToko->flat_tarif_dokter ?? 0),
                    'flat_tarif_dokter_sp' => (float) ($hargaToko->flat_tarif_dokter_sp ?? 0),

                    'insentif_use' => $hargaToko->insentif_use ?? null,
                    'insentif_use_sp' => $hargaToko->insentif_use_sp ?? null,

                    'is_active' => $isActive,
                    'is_treatment_active' => $isActive === 1 ? 1 : 0,
                    'status_treatment' => $isActive === 1 ? 'AKTIF' : 'NONAKTIF',
                ];
            });

        return response()->json([
            'status' => true,
            'message' => 'Data treatment berdasarkan toko berhasil diambil',
            'data' => $data,
        ]);
    }
    
    public function voucherDiskonJenis()
    {
        try {
            $data = MasterVoucherDiskonJenis::query()
                ->where('is_delete', 0)
                ->where('is_active', 1)
                ->orderBy('urutan', 'asc')
                ->orderBy('id', 'asc')
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'value' => $item->id,
                        'label' => $item->nama_jenis,
                        'kode' => $item->kode,
                        'nama_jenis' => $item->nama_jenis,
                        'deskripsi' => $item->deskripsi,
                        'bisa_treatment' => (bool) $item->bisa_treatment,
                        'bisa_produk' => (bool) $item->bisa_produk,
                    ];
                });

            return response()->json([
                'status' => true,
                'message' => 'Data jenis voucher diskon berhasil diambil',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil data jenis voucher diskon',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function voucherDiskonKategori()
    {
        try {
            $data = MasterVoucherDiskonKategori::query()
                ->where('is_delete', 0)
                ->where('is_active', 1)
                ->orderBy('urutan', 'asc')
                ->orderBy('id', 'asc')
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'value' => $item->id,
                        'label' => $item->nama_kategori,
                        'kode' => $item->kode,
                        'nama_kategori' => $item->nama_kategori,
                        'deskripsi' => $item->deskripsi,
                    ];
                });

            return response()->json([
                'status' => true,
                'message' => 'Data kategori voucher diskon berhasil diambil',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil data kategori voucher diskon',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function voucherDiskonTemplate()
    {
        try {
            $data = MasterVoucherDiskonTemplate::query()
                ->where('is_delete', 0)
                ->where('is_active', 1)
                ->orderBy('urutan', 'asc')
                ->orderBy('id', 'asc')
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'value' => $item->id,
                        'label' => $item->nama_template,
                        'kode' => $item->kode,
                        'nama_template' => $item->nama_template,
                        'deskripsi' => $item->deskripsi,
                        'file_url' => $item->file_url,
                        'file_name' => $item->file_name,
                    ];
                });

            return response()->json([
                'status' => true,
                'message' => 'Data template voucher diskon berhasil diambil',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil data template voucher diskon',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function agama()
    {
        $data = MasterAgama::query()
            ->where(function ($q) {
                $q->where('is_delete', 0)
                  ->orWhereNull('is_delete');
            })
            ->where('is_active', 1)
            ->orderBy('urutan', 'asc')
            ->orderBy('nama_agama', 'asc')
            ->get([
                'id',
                'kode_agama',
                'nama_agama',
            ])
            ->map(function ($item) {
                return [
                    'id'          => $item->id,
                    'kode_agama'  => $item->kode_agama,
                    'nama_agama'  => $item->nama_agama,
                    'label'       => $item->nama_agama,
                    'value'       => $item->id,
                    'value_text'  => $item->nama_agama,
                ];
            });

        return response()->json([
            'status'  => true,
            'message' => 'Data agama berhasil diambil',
            'data'    => $data,
        ]);
    }

    public function pekerjaan()
    {
        $data = MasterPekerjaan::query()
            ->where(function ($q) {
                $q->where('is_delete', 0)
                  ->orWhereNull('is_delete');
            })
            ->where('is_active', 1)
            ->orderBy('urutan', 'asc')
            ->orderBy('nama_pekerjaan', 'asc')
            ->get([
                'id',
                'nama_pekerjaan',
            ])
            ->map(function ($item) {
                return [
                    'id'              => $item->id,
                    'nama_pekerjaan'  => $item->nama_pekerjaan,
                    'label'           => $item->nama_pekerjaan,
                    'value'           => $item->id,
                    'value_text'      => $item->nama_pekerjaan,
                ];
            });

        return response()->json([
            'status'  => true,
            'message' => 'Data pekerjaan berhasil diambil',
            'data'    => $data,
        ]);
    }

     public function provinces()
    {
        return $this->getWilayahId('provinces.json');
    }

    public function regencies($provinceCode)
    {
        return $this->getWilayahId("regencies/{$provinceCode}.json");
    }

    public function districts($regencyCode)
    {
        return $this->getWilayahId("districts/{$regencyCode}.json");
    }

    public function villages($districtCode)
    {
        return $this->getWilayahId("villages/{$districtCode}.json");
    }

    private function getWilayahId($endpoint)
    {
        try {
            $url = "https://wilayah.id/api/{$endpoint}";

            $response = Http::timeout(20)
                ->withoutVerifying()
                ->acceptJson()
                ->get($url);

            if (! $response->successful()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Gagal mengambil data dari wilayah.id',
                    'url' => $url,
                    'http_status' => $response->status(),
                    'data' => [],
                    'error' => $response->body(),
                ], 502);
            }

            $json = $response->json();

            return response()->json([
                'status' => true,
                'message' => 'Data wilayah berhasil diambil',
                'data' => $json['data'] ?? $json ?? [],
                'meta' => $json['meta'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Terjadi kesalahan saat mengambil data wilayah',
                'data' => [],
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    public function pasien(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $limit = (int) $request->get('limit', 20);

        if ($limit <= 0) {
            $limit = 20;
        }

        if ($limit > 50) {
            $limit = 50;
        }

        $query = Pasien::query()
            ->active()
            ->select([
                'id',
                'no_rm',
                'nama',
                'no_identitas',
                'no_hp',
                'no_wa',
                'toko_id',
            ]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('no_rm', 'like', "%{$search}%")
                    ->orWhere('nama', 'like', "%{$search}%")
                    ->orWhere('no_identitas', 'like', "%{$search}%")
                    ->orWhere('no_hp', 'like', "%{$search}%")
                    ->orWhere('no_wa', 'like', "%{$search}%");
            });
        }

        $data = $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'no_rm' => $item->no_rm,
                    'nama' => $item->nama,
                    'no_identitas' => $item->no_identitas,
                    'no_hp' => $item->no_hp,
                    'no_wa' => $item->no_wa,
                    'toko_id' => $item->toko_id,

                    'label' => trim(
                        ($item->no_rm ? $item->no_rm . ' - ' : '') .
                        $item->nama
                    ),
                    'value' => $item->id,
                ];
            });

        return response()->json([
            'status' => true,
            'message' => 'Data pasien berhasil diambil',
            'data' => $data,
        ]);
    }

    public function metodeBayar()
    {
        $data = MasterMetodeBayar::query()
            ->select([
                'id',
                'nama',
            ])
            ->orderBy('id')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Data metode bayar berhasil diambil',
            'data' => $data,
        ]);
    }

    public function voucherDiskonEligible(Request $request)
    {
        $tokoId = $request->input('toko_id');

        $produkIds = $this->normalizeIdArray($request->input('produk_ids', []));
        $treatmentIds = $this->normalizeIdArray($request->input('treatment_ids', []));

        $hasProduk = count($produkIds) > 0;
        $hasTreatment = count($treatmentIds) > 0;

        if (!$hasProduk && !$hasTreatment) {
            return response()->json([
                'status' => true,
                'message' => 'Tidak ada item transaksi untuk pengecekan voucher',
                'data' => [
                    'treatment' => [],
                    'produk' => [],
                    'bundling' => [],
                    'value' => [],
                    'all' => [],
                ],
            ]);
        }

        $vouchers = MasterVoucherDiskon::query()
            ->with('items')
            ->aktifEligible()
            ->untukToko($tokoId)
            ->periodeMasihBerlaku()
            ->whereIn('jenis_voucher_id', [1, 2, 3, 4])
            ->orderBy('sort_order')
            ->orderBy('nama_voucher')
            ->get()
            ->filter(function ($voucher) use ($produkIds, $treatmentIds, $hasProduk, $hasTreatment) {
                return $this->isVoucherEligibleForTransaction(
                    $voucher,
                    $produkIds,
                    $treatmentIds,
                    $hasProduk,
                    $hasTreatment
                );
            })
            ->map(function ($voucher) {
                return $this->formatVoucherEligible($voucher);
            })
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Voucher eligible berhasil diambil',
            'data' => [
                'treatment' => $vouchers->where('jenis_voucher_id', 1)->values(),
                'produk' => $vouchers->where('jenis_voucher_id', 2)->values(),
                'bundling' => $vouchers->where('jenis_voucher_id', 3)->values(),
                'value' => $vouchers->where('jenis_voucher_id', 4)->values(),
                'all' => $vouchers,
            ],
        ]);
    }

    private function normalizeIdArray($value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            $value = [$value];
        }

        return collect($value)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function isVoucherEligibleForTransaction(
        $voucher,
        array $produkIds,
        array $treatmentIds,
        bool $hasProduk,
        bool $hasTreatment
    ): bool {
        $jenisVoucherId = (int) $voucher->jenis_voucher_id;

        $items = $voucher->items ?? collect();

        $produkItems = $items->where('item_type', 'produk');
        $treatmentItems = $items->where('item_type', 'treatment');

        if ($jenisVoucherId === 1) {
            if (!$hasTreatment) return false;

            return $treatmentItems->contains(function ($item) use ($treatmentIds) {
                return in_array((int) $item->item_id, $treatmentIds, true);
            });
        }

        if ($jenisVoucherId === 2) {
            if (!$hasProduk) return false;

            return $produkItems->contains(function ($item) use ($produkIds) {
                return in_array((int) $item->item_id, $produkIds, true);
            });
        }

        if ($jenisVoucherId === 3) {
            if ($items->isEmpty()) return false;

            $produkMatched = $produkItems->isEmpty()
                ? true
                : $produkItems->every(function ($item) use ($produkIds) {
                    return in_array((int) $item->item_id, $produkIds, true);
                });

            $treatmentMatched = $treatmentItems->isEmpty()
                ? true
                : $treatmentItems->every(function ($item) use ($treatmentIds) {
                    return in_array((int) $item->item_id, $treatmentIds, true);
                });

            return $produkMatched && $treatmentMatched;
        }

        if ($jenisVoucherId === 4) {
            if ($items->isEmpty()) return true;

            $produkMatched = $produkItems->contains(function ($item) use ($produkIds) {
                return in_array((int) $item->item_id, $produkIds, true);
            });

            $treatmentMatched = $treatmentItems->contains(function ($item) use ($treatmentIds) {
                return in_array((int) $item->item_id, $treatmentIds, true);
            });

            return $produkMatched || $treatmentMatched;
        }

        return false;
    }

    private function formatVoucherEligible($voucher): array
    {
        $items = $voucher->items ?? collect();

        return [
            'id' => $voucher->id,
            'legacy_id' => $voucher->legacy_id,
            'nama' => $voucher->nama_voucher,
            'nama_voucher' => $voucher->nama_voucher,
            'deskripsi' => $voucher->deskripsi,
            'mode_voucher' => $voucher->mode_voucher,
            'mode_voucher_label' => $voucher->mode_voucher_label,
            'toko_id' => $voucher->toko_id,
            'is_all_toko' => (int) $voucher->is_all_toko,
            'kategori_voucher_id' => $voucher->kategori_voucher_id,
            'jenis_voucher_id' => (int) $voucher->jenis_voucher_id,
            'jenis_voucher_label' => $voucher->jenis_voucher_label,
            'template_voucher_id' => $voucher->template_voucher_id,
            'tipe_diskon' => $voucher->tipe_diskon,
            'tipe_diskon_label' => $voucher->tipe_diskon_label,
            'tipe_diskon_kode' => $voucher->tipe_diskon_kode,
            'total_diskon' => (float) $voucher->total_diskon,
            'total_diskon_maksimal' => $voucher->total_diskon_maksimal !== null
                ? (float) $voucher->total_diskon_maksimal
                : 0,
            'diskon_maksimal' => $voucher->total_diskon_maksimal !== null
                ? (float) $voucher->total_diskon_maksimal
                : 0,
            'value' => (float) $voucher->total_diskon,
            'mode' => $voucher->tipe_diskon_kode,
            'qty_generate' => (int) $voucher->qty_generate,
            'kuota' => (int) $voucher->qty_generate > 0
                ? (int) $voucher->qty_generate
                : 'Tidak Terbatas',
            'is_bisa_digabung_promo' => (int) $voucher->is_bisa_digabung_promo,
            'is_unlimited_date' => (int) $voucher->is_unlimited_date,
            'tanggal_mulai' => $voucher->tanggal_mulai,
            'tanggal_akhir' => $voucher->tanggal_akhir,
            'status_voucher' => (int) $voucher->status_voucher,
            'status_voucher_label' => $voucher->status_voucher_label,
            'sort_order' => (int) $voucher->sort_order,
            'desc' => $this->buildVoucherDesc($voucher),
            'items' => $items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'voucher_diskon_id' => $item->voucher_diskon_id,
                    'item_type' => $item->item_type,
                    'item_type_label' => $item->item_type_label,
                    'item_id' => $item->item_id,
                    'harga_snapshot' => (float) $item->harga_snapshot,
                    'tipe_diskon_item' => $item->tipe_diskon_item,
                    'tipe_diskon_item_label' => $item->tipe_diskon_item_label,
                    'nilai_diskon_item' => $item->nilai_diskon_item !== null
                        ? (float) $item->nilai_diskon_item
                        : null,
                ];
            })->values(),
        ];
    }

    private function buildVoucherDesc($voucher): string
    {
        $diskon = number_format((float) $voucher->total_diskon, 0, ',', '.');
        $maksimal = (float) ($voucher->total_diskon_maksimal ?? 0);

        if ($voucher->tipe_diskon === 'nominal') {
            return 'Diskon Rp ' . $diskon;
        }

        if ($maksimal > 0) {
            return 'Diskon ' . $diskon . '% maks Rp ' . number_format($maksimal, 0, ',', '.');
        }

        return 'Diskon ' . $diskon . '%';
    }
    
    public function merchandise(Request $request)
    {
        $keyword = trim((string) $request->query('q', ''));
        $limit = (int) $request->query('limit', 50);

        if ($limit <= 0) {
            $limit = 50;
        }

        if ($limit > 100) {
            $limit = 100;
        }

        $query = MasterMerchandise::query()
            ->active();

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('kode', 'LIKE', "%{$keyword}%")
                    ->orWhere('nama', 'LIKE', "%{$keyword}%")
                    ->orWhere('jenis_reward', 'LIKE', "%{$keyword}%");
            });
        }

        $rows = $query
            ->orderBy('sort_order', 'asc')
            ->orderBy('nama', 'asc')
            ->limit($limit)
            ->get([
                'id',
                'kode',
                'nama',
                'jenis_reward',
                'nilai_diskon_persen',
                'nilai_diskon_nominal',
                'harga_poin',
                'stok',
                'deskripsi',
            ]);

        $data = $rows->map(function ($item) {
            $label = trim(($item->kode ? $item->kode . ' - ' : '') . $item->nama);

            return [
                'id' => $item->id,
                'value' => $item->id,
                'title' => $label,
                'text' => $label,

                'kode' => $item->kode,
                'nama' => $item->nama,
                'jenis_reward' => $item->jenis_reward,
                'nilai_diskon_persen' => (float) $item->nilai_diskon_persen,
                'nilai_diskon_nominal' => (float) $item->nilai_diskon_nominal,
                'harga_poin' => (int) $item->harga_poin,
                'stok' => (int) $item->stok,
                'deskripsi' => $item->deskripsi,

                'is_stok_kosong' => (int) $item->stok <= 0,
            ];
        })->values();

        return response()->json([
            'status' => true,
            'message' => 'Data merchandise berhasil diambil',
            'data' => $data,
        ]);
    }

    public function accurateItemMapping(Request $request)
    {
        $query = MasterAccurateItemMapping::query()
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->where(function ($q) {
                $q->where('is_active', 1)
                    ->orWhereNull('is_active');
            });

        if ($request->filled('source_type')) {
            $sourceTypes = collect(explode(',', (string) $request->source_type))
                ->map(fn ($item) => trim(strtolower($item)))
                ->filter()
                ->values()
                ->all();

            if (!empty($sourceTypes)) {
                $query->whereIn('source_type', $sourceTypes);
            }
        }

        $data = $query
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id',
                'source_type',
                'source_code',
                'source_name',
                'legacy_treatment_id',
                'legacy_treatment_name',
                'kode_accurate',
                'nama_accurate',
                'default_harga',
                'is_billable',
                'is_send_to_accurate',
                'send_when_zero',
                'sort_order',
                'is_active',
            ]);

        return response()->json([
            'status' => true,
            'message' => 'Data mapping Accurate berhasil diambil',
            'data' => $data,
        ]);
    }

    public function subjective(Request $request)
    {
        $keyword = trim((string) $request->query('q', $request->query('search', '')));
        $limit = (int) $request->query('limit', 50);

        if ($limit <= 0) {
            $limit = 50;
        }

        if ($limit > 100) {
            $limit = 100;
        }

        $query = MasterSubjective::query()
            ->active()
            ->where('is_active', 1);

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('kode', 'LIKE', "%{$keyword}%")
                    ->orWhere('nama', 'LIKE', "%{$keyword}%");
            });
        }

        $rows = $query
            ->orderBy('sort_order', 'asc')
            ->orderBy('nama', 'asc')
            ->limit($limit)
            ->get([
                'id',
                'kode',
                'nama',
                'deskripsi',
                'sort_order',
                'is_active',
            ])
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'value' => $item->id,
                    'label' => $item->nama,
                    'kode' => $item->kode,
                    'nama' => $item->nama,
                    'deskripsi' => $item->deskripsi,
                    'sort_order' => $item->sort_order,
                    'is_active' => (int) $item->is_active,
                ];
            });

        return response()->json([
            'status' => true,
            'message' => 'Data subjective berhasil diambil',
            'data' => $rows,
        ]);
    }

    public function assessment(Request $request)
    {
        $keyword = trim((string) $request->query('q', $request->query('search', '')));
        $limit = (int) $request->query('limit', 50);

        if ($limit <= 0) {
            $limit = 50;
        }

        if ($limit > 100) {
            $limit = 100;
        }

        $query = MasterAssessment::query()
            ->active()
            ->where('is_active', 1);

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('kode', 'LIKE', "%{$keyword}%")
                    ->orWhere('nama', 'LIKE', "%{$keyword}%");
            });
        }

        $rows = $query
            ->orderBy('sort_order', 'asc')
            ->orderBy('nama', 'asc')
            ->limit($limit)
            ->get([
                'id',
                'kode',
                'nama',
                'deskripsi',
                'sort_order',
                'is_active',
            ])
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'value' => $item->id,
                    'label' => trim($item->kode . ' - ' . $item->nama),
                    'kode' => $item->kode,
                    'nama' => $item->nama,
                    'deskripsi' => $item->deskripsi,
                    'sort_order' => $item->sort_order,
                    'is_active' => (int) $item->is_active,
                ];
            });

        return response()->json([
            'status' => true,
            'message' => 'Data assessment berhasil diambil',
            'data' => $rows,
        ]);
    }

    public function jenisTransaksi(Request $request)
    {
        $keyword = trim((string) $request->query('q', $request->query('search', '')));
        $limit = (int) $request->query('limit', 50);

        if ($limit <= 0) {
            $limit = 50;
        }

        if ($limit > 100) {
            $limit = 100;
        }

        $query = MasterJenisTransaksi::query()
            ->active();

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('kode_jenis_transaksi', 'LIKE', "%{$keyword}%")
                    ->orWhere('nama_jenis_transaksi', 'LIKE', "%{$keyword}%")
                    ->orWhere('deskripsi', 'LIKE', "%{$keyword}%");

                if (is_numeric($keyword)) {
                    $q->orWhere('id', (int) $keyword);
                }
            });
        }

        $data = $query
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get([
                'id',
                'kode_jenis_transaksi',
                'nama_jenis_transaksi',
                'deskripsi',
                'sort_order',
                'is_active',
            ])
            ->map(function ($item) {
                return [
                    'id' => (int) $item->id,
                    'id_jenis_transaksi' => (int) $item->id,

                    'kode_jenis_transaksi' => $item->kode_jenis_transaksi,
                    'nama_jenis_transaksi' => $item->nama_jenis_transaksi,

                    'deskripsi' => $item->deskripsi,
                    'deskripsi_jenis' => $item->nama_jenis_transaksi,

                    'sort_order' => (int) $item->sort_order,
                    'is_active' => (int) $item->is_active,

                    'label' => $item->nama_jenis_transaksi,
                    'value' => (int) $item->id,
                    'value_text' => $item->nama_jenis_transaksi,
                ];
            });

        return response()->json([
            'status' => true,
            'message' => 'Data jenis transaksi berhasil diambil',
            'data' => $data,
        ]);
    }

    public function sumberInformasi(Request $request)
    {
        $keyword = trim((string) $request->query('q', $request->query('search', '')));
        $kategoriSumber = trim((string) $request->query('kategori_sumber', ''));
        $limit = (int) $request->query('limit', 100);

        if ($limit <= 0) {
            $limit = 100;
        }

        if ($limit > 200) {
            $limit = 200;
        }

        $query = MasterSumberInformasi::query()
            ->active();

        if ($kategoriSumber !== '') {
            $query->where('kategori_sumber', $kategoriSumber);
        }

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('kode_sumber_informasi', 'LIKE', "%{$keyword}%")
                    ->orWhere('nama_sumber_informasi', 'LIKE', "%{$keyword}%")
                    ->orWhere('kategori_sumber', 'LIKE', "%{$keyword}%")
                    ->orWhere('deskripsi', 'LIKE', "%{$keyword}%");
            });
        }

        $data = $query
            ->orderBy('sort_order', 'asc')
            ->orderBy('nama_sumber_informasi', 'asc')
            ->limit($limit)
            ->get([
                'id',
                'kode_sumber_informasi',
                'nama_sumber_informasi',
                'kategori_sumber',
                'deskripsi',
                'sort_order',
                'is_active',
            ])
            ->map(function ($item) {
                return [
                    'id' => (int) $item->id,
                    'kode_sumber_informasi' => $item->kode_sumber_informasi,
                    'nama_sumber_informasi' => $item->nama_sumber_informasi,
                    'kategori_sumber' => $item->kategori_sumber,
                    'deskripsi' => $item->deskripsi,
                    'sort_order' => (int) $item->sort_order,
                    'is_active' => (int) $item->is_active,

                    'sumber' => $item->nama_sumber_informasi,
                    'nama_sumber' => $item->nama_sumber_informasi,

                    'label' => $item->nama_sumber_informasi,
                    'value' => (int) $item->id,
                    'value_text' => $item->nama_sumber_informasi,
                ];
            })
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Data sumber informasi berhasil diambil',
            'data' => $data,
        ]);
    }
    public function bahanPerawat(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $limit = (int) $request->get('limit', 100);

        if ($limit <= 0) {
            $limit = 100;
        }

        if ($limit > 500) {
            $limit = 500;
        }

        $data = MasterPerawatBahan::query()
            ->where('is_delete', 0)
            ->where('is_active', 1)
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('nama_bahan', 'like', "%{$search}%")
                        ->orWhere('kode_accurate_obat_bahan', 'like', "%{$search}%")
                        ->orWhere('satuan', 'like', "%{$search}%");
                });
            })
            ->orderBy('nama_bahan')
            ->limit($limit)
            ->get([
                'id',
                'nama_bahan',
                'kode_accurate_obat_bahan',
                'satuan',
            ])
            ->map(function ($item) {
                $kode = $item->kode_accurate_obat_bahan ?: '-';
                $satuan = $item->satuan ?: '-';

                return [
                    'id' => $item->id,
                    'value' => $item->id,
                    'nama_bahan' => $item->nama_bahan,
                    'kode_accurate_obat_bahan' => $item->kode_accurate_obat_bahan,
                    'satuan' => $item->satuan,
                    'label' => trim($item->nama_bahan . ' | ' . $kode . ' | ' . $satuan),
                ];
            })
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Data bahan perawat berhasil diambil',
            'data' => $data,
        ]);
    }
}