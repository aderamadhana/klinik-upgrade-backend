<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
use App\Models\master\MasterVoucherDiskonJenis;
use App\Models\master\MasterVoucherDiskonKategori;
use App\Models\master\MasterVoucherDiskonTemplate;


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

        if (!$tokoId) {
            return response()->json([
                'status' => false,
                'message' => 'toko_id wajib diisi',
            ], 422);
        }

        $data = MasterProduk::query()
            ->active()
            ->with([
                'kategori',
                'golongan',
                'satuan',
                'tempatProduk',
                'hargaToko' => function ($q) use ($tokoId) {
                    $q->active()
                        ->where('toko_id', $tokoId)
                        ->with(['toko', 'supplier'])
                        ->orderBy('sort_order');
                },
            ])
            ->whereHas('hargaToko', function ($q) use ($tokoId) {
                $q->active()
                    ->where('toko_id', $tokoId);
            })
            ->orderBy('nama')
            ->get()
            ->map(function ($produk) {
                $hargaToko = $produk->hargaToko->first();

                $stok = (int) ($hargaToko->stok_awal ?? 0);
                $stokMinimum = (int) ($hargaToko->stok_minimum ?? 0);

                $isStokHabis = $stok <= 0 ? 1 : 0;
                $isStokMinimum = $stok > 0 && $stok <= $stokMinimum ? 1 : 0;

                if ($isStokHabis) {
                    $statusStok = 'HABIS';
                } elseif ($isStokMinimum) {
                    $statusStok = 'STOK MINIMUM';
                } else {
                    $statusStok = 'TERSEDIA';
                }

                return [
                    'produk_id' => $produk->id,
                    'kode' => $produk->kode,
                    'kode_accurate' => $produk->kode_accurate,
                    'nama' => $produk->nama,

                    'satuan_id' => $produk->satuan_id,
                    'nama_satuan' => $produk->satuan->nama ?? $produk->satuan->nama_satuan ?? null,

                    'kategori_produk_id' => $produk->kategori_produk_id,
                    'nama_kategori_produk' => $produk->kategori->nama_kategori_produk ?? null,

                    'golongan_produk_id' => $produk->golongan_produk_id,
                    'nama_golongan_produk' => $produk->golongan->nama_golongan_produk ?? null,

                    'tempat_produk_id' => $produk->tempat_produk_id,
                    'nama_tempat_produk' => $produk->tempatProduk->nama_tempat_produk ?? null,

                    'is_obat_resep' => (int) ($produk->is_obat_resep ?? 0),
                    'is_obat_bebas' => (int) ($produk->is_obat_bebas ?? 0),

                    'produk_toko_id' => $hargaToko->id ?? null,
                    'toko_id' => $hargaToko->toko_id ?? null,
                    'nama_toko' => $hargaToko->toko->nama_toko ?? null,

                    'supplier_id' => $hargaToko->supplier_id ?? null,
                    'nama_supplier' => $hargaToko->supplier->nama ?? null,

                    'harga_jual' => (float) ($hargaToko->harga_jual ?? 0),
                    'harga_beli' => (float) ($hargaToko->harga_beli ?? 0),

                    'stok_awal' => $stok,
                    'stok_minimum' => $stokMinimum,

                    'fee_dokter' => (float) ($hargaToko->fee_dokter ?? 0),
                    'fee_beautician' => (float) ($hargaToko->fee_beautician ?? 0),

                    'is_stok_habis' => $isStokHabis,
                    'is_stok_minimum' => $isStokMinimum,
                    'status_stok' => $statusStok,
                ];
            });

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
}