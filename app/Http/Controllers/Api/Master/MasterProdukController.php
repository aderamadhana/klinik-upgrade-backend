<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterProduk;
use App\Models\Master\MasterProdukToko;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MasterProdukController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));

        $kategoriProdukId = $request->get('kategori_produk_id');
        $golonganProdukId = $request->get('golongan_produk_id');
        $tempatProdukId = $request->get('tempat_produk_id') ?? $request->get('tempat_produk_id');
        $satuanId = $request->get('satuan_id');
        $tokoId = $request->get('toko_id');
        $supplierId = $request->get('supplier_id');

        $perPage = (int) $request->get('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 100) : 10;

        $query = MasterProduk::query()
            ->active()
            ->with($this->produkRelations())
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('kode_accurate', 'like', "%{$search}%")
                        ->orWhere('nama', 'like', "%{$search}%")
                        ->orWhereHas('kategori', function ($kategori) use ($search) {
                            $kategori->where('nama_kategori_produk', 'like', "%{$search}%");
                        })
                        ->orWhereHas('golongan', function ($golongan) use ($search) {
                            $golongan->where('nama_golongan_produk', 'like', "%{$search}%");
                        })
                        ->orWhereHas('tempatProduk', function ($tempat) use ($search) {
                            $tempat->where('nama_tempat_produk', 'like', "%{$search}%");
                        })
                        ->orWhereHas('satuan', function ($satuan) use ($search) {
                            $satuan->where('nama', 'like', "%{$search}%");
                        })
                        ->orWhereHas('hargaToko.toko', function ($toko) use ($search) {
                            $toko->where('nama_toko', 'like', "%{$search}%")
                                ->orWhere('kode_toko', 'like', "%{$search}%");
                        })
                        ->orWhereHas('hargaToko.supplier', function ($supplier) use ($search) {
                            $supplier->where('nama', 'like', "%{$search}%")
                                ->orWhere('kode', 'like', "%{$search}%");
                        });
                });
            })
            ->when($kategoriProdukId, function ($q) use ($kategoriProdukId) {
                $q->where('kategori_produk_id', $kategoriProdukId);
            })
            ->when($golonganProdukId, function ($q) use ($golonganProdukId) {
                $q->where('golongan_produk_id', $golonganProdukId);
            })
            ->when($tempatProdukId, function ($q) use ($tempatProdukId) {
                $q->where('tempat_produk_id', $tempatProdukId);
            })
            ->when($satuanId, function ($q) use ($satuanId) {
                $q->where('satuan_id', $satuanId);
            })
            ->when($tokoId, function ($q) use ($tokoId) {
                $q->whereHas('hargaToko', function ($hargaToko) use ($tokoId) {
                    $hargaToko->where('toko_id', $tokoId);
                });
            })
            ->when($supplierId, function ($q) use ($supplierId) {
                $q->whereHas('hargaToko', function ($hargaToko) use ($supplierId) {
                    $hargaToko->where('supplier_id', $supplierId);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('nama');

        $data = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Data produk berhasil diambil',
            'data' => $data->items(),
            'meta' => [
                'current_page' => $data->currentPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
                'last_page' => $data->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->normalizeRequestField($request);

        $validator = Validator::make($request->all(), [
            'kode_accurate' => 'nullable|string|max:100',
            'nama' => 'required|string|max:150',

            'tempat_produk_id' => 'nullable|exists:master_tempat_produk,id',
            'satuan_id' => 'nullable|exists:master_satuan,id',
            'kategori_produk_id' => 'nullable|exists:master_kategori_produk,id',
            'golongan_produk_id' => 'nullable|exists:master_golongan_produk,id',

            'is_obat_resep' => 'nullable|boolean',
            'is_obat_bebas' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:32767',

            'toko_mapping' => 'required|array|min:1',
            'toko_mapping.*.toko_id' => 'required|exists:master_toko,id',
            'toko_mapping.*.supplier_id' => 'nullable|exists:master_supplier,id',
            'toko_mapping.*.harga_jual' => 'nullable|numeric|min:0',
            'toko_mapping.*.harga_beli' => 'nullable|numeric|min:0',
            'toko_mapping.*.stok_awal' => 'nullable|integer|min:0',
            'toko_mapping.*.stok_minimum' => 'nullable|integer|min:0',
            'toko_mapping.*.fee_dokter' => 'nullable|numeric|min:0',
            'toko_mapping.*.fee_beautician' => 'nullable|numeric|min:0',
            'toko_mapping.*.sort_order' => 'nullable|integer|min:0|max:32767',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokoPayload = $this->normalizeTokoPayload($request);
        $tokoError = $this->validateProdukToko($tokoPayload);

        if ($tokoError) {
            return response()->json([
                'status' => false,
                'message' => $tokoError,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $actor = auth('api')->user()->username ?? 'system';

            $produk = MasterProduk::create([
                'kode_accurate' => $request->kode_accurate,
                'nama' => $request->nama,

                'tempat_produk_id' => $request->tempat_produk_id,
                'satuan_id' => $request->satuan_id,
                'kategori_produk_id' => $request->kategori_produk_id,
                'golongan_produk_id' => $request->golongan_produk_id,

                'is_obat_resep' => $request->is_obat_resep ?? 0,
                'is_obat_bebas' => $request->is_obat_bebas ?? 0,

                'sort_order' => $request->sort_order ?? 0,
                'is_delete' => 0,

                'created_by' => $actor,
                'created_at' => now(),
            ]);

            $this->syncProdukToko($produk->id, $tokoPayload, $actor);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data produk berhasil disimpan',
                'data' => $produk->fresh()->load($this->produkRelations()),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyimpan data produk',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $data = MasterProduk::query()
            ->active()
            ->with($this->produkRelations())
            ->find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data produk tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Detail produk berhasil diambil',
            'data' => $data,
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->normalizeRequestField($request);

        $produk = MasterProduk::active()->find($id);

        if (!$produk) {
            return response()->json([
                'status' => false,
                'message' => 'Data produk tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'kode_accurate' => 'nullable|string|max:100',
            'nama' => 'required|string|max:150',

            'tempat_produk_id' => 'nullable|exists:master_tempat_produk,id',
            'satuan_id' => 'nullable|exists:master_satuan,id',
            'kategori_produk_id' => 'nullable|exists:master_kategori_produk,id',
            'golongan_produk_id' => 'nullable|exists:master_golongan_produk,id',

            'is_obat_resep' => 'nullable|boolean',
            'is_obat_bebas' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:32767',

            'toko_mapping' => 'required|array|min:1',
            'toko_mapping.*.toko_id' => 'required|exists:master_toko,id',
            'toko_mapping.*.supplier_id' => 'nullable|exists:master_supplier,id',
            'toko_mapping.*.harga_jual' => 'nullable|numeric|min:0',
            'toko_mapping.*.harga_beli' => 'nullable|numeric|min:0',
            'toko_mapping.*.stok_awal' => 'nullable|integer|min:0',
            'toko_mapping.*.stok_minimum' => 'nullable|integer|min:0',
            'toko_mapping.*.fee_dokter' => 'nullable|numeric|min:0',
            'toko_mapping.*.fee_beautician' => 'nullable|numeric|min:0',
            'toko_mapping.*.sort_order' => 'nullable|integer|min:0|max:32767',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tokoPayload = $this->normalizeTokoPayload($request);
        $tokoError = $this->validateProdukToko($tokoPayload);

        if ($tokoError) {
            return response()->json([
                'status' => false,
                'message' => $tokoError,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $actor = auth('api')->user()->username ?? 'system';

            $produk->update([
                'kode_accurate' => $request->kode_accurate,
                'nama' => $request->nama,

                'tempat_produk_id' => $request->tempat_produk_id,
                'satuan_id' => $request->satuan_id,
                'kategori_produk_id' => $request->kategori_produk_id,
                'golongan_produk_id' => $request->golongan_produk_id,

                'is_obat_resep' => $request->is_obat_resep ?? 0,
                'is_obat_bebas' => $request->is_obat_bebas ?? 0,

                'sort_order' => $request->sort_order ?? 0,

                'updated_by' => $actor,
                'updated_at' => now(),
            ]);

            $this->syncProdukToko($produk->id, $tokoPayload, $actor);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data produk berhasil diperbarui',
                'data' => $produk->fresh()->load($this->produkRelations()),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal memperbarui data produk',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $produk = MasterProduk::active()->find($id);

        if (!$produk) {
            return response()->json([
                'status' => false,
                'message' => 'Data produk tidak ditemukan',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $actor = auth('api')->user()->username ?? 'system';

            $produk->update([
                'is_delete' => 1,
                'updated_by' => $actor,
                'updated_at' => now(),
            ]);

            MasterProdukToko::where('produk_id', $id)->update([
                'is_delete' => 1,
                'updated_by' => $actor,
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data produk berhasil dihapus',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menghapus data produk',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function produkRelations(): array
    {
        return [
            'kategori',
            'golongan',
            'satuan',
            'tempatProduk',
            'hargaToko.toko',
            'hargaToko.supplier',
        ];
    }

    private function normalizeRequestField(Request $request): void
    {
        if (!$request->has('nama') && $request->has('nama_produk')) {
            $request->merge([
                'nama' => $request->nama_produk,
            ]);
        }

        if (!$request->has('tempat_produk_id') && $request->has('tempat_produk_id')) {
            $request->merge([
                'tempat_produk_id' => $request->tempat_produk_id,
            ]);
        }

        if (!$request->has('toko_mapping') && $request->has('toko')) {
            $request->merge([
                'toko_mapping' => $request->toko,
            ]);
        }
    }

    private function normalizeTokoPayload(Request $request): array
    {
        $rows = $request->input('toko_mapping', []);

        if (!is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->map(function ($item) {
                return [
                    'toko_id' => $item['toko_id'] ?? null,
                    'supplier_id' => $item['supplier_id'] ?? null,
                    'harga_jual' => $item['harga_jual'] ?? 0,
                    'harga_beli' => $item['harga_beli'] ?? 0,
                    'stok_awal' => $item['stok_awal'] ?? 0,
                    'stok_minimum' => $item['stok_minimum'] ?? 0,
                    'fee_dokter' => $item['fee_dokter'] ?? 0,
                    'fee_beautician' => $item['fee_beautician'] ?? 0,
                    'sort_order' => $item['sort_order'] ?? 0,
                ];
            })
            ->filter(function ($item) {
                return !empty($item['toko_id']);
            })
            ->values()
            ->toArray();
    }

    private function validateProdukToko(array $items): ?string
    {
        if (!count($items)) {
            return 'Minimal harus ada 1 konfigurasi cabang';
        }

        $tokoIds = collect($items)
            ->pluck('toko_id')
            ->filter()
            ->values();

        if ($tokoIds->count() !== count($items)) {
            return 'Semua mapping toko wajib memilih toko';
        }

        if ($tokoIds->unique()->count() !== $tokoIds->count()) {
            return 'Toko pada mapping produk tidak boleh duplikat';
        }

        foreach ($items as $item) {
            if ((float) ($item['harga_jual'] ?? 0) < 0) {
                return 'Harga jual tidak boleh kurang dari 0';
            }

            if ((float) ($item['harga_beli'] ?? 0) < 0) {
                return 'Harga beli tidak boleh kurang dari 0';
            }

            if ((int) ($item['stok_awal'] ?? 0) < 0) {
                return 'Stok awal tidak boleh kurang dari 0';
            }

            if ((int) ($item['stok_minimum'] ?? 0) < 0) {
                return 'Stok minimum tidak boleh kurang dari 0';
            }

            if ((float) ($item['fee_dokter'] ?? 0) < 0) {
                return 'Fee dokter tidak boleh kurang dari 0';
            }

            if ((float) ($item['fee_beautician'] ?? 0) < 0) {
                return 'Fee beautician tidak boleh kurang dari 0';
            }
        }

        return null;
    }

    private function syncProdukToko($produkId, array $items, string $actor): void
    {
        MasterProdukToko::where('produk_id', $produkId)->delete();

        foreach ($items as $item) {
            MasterProdukToko::create([
                'produk_id' => $produkId,
                'toko_id' => $item['toko_id'],
                'supplier_id' => $item['supplier_id'],

                'harga_jual' => $item['harga_jual'] ?? 0,
                'harga_beli' => $item['harga_beli'] ?? 0,

                'stok_awal' => $item['stok_awal'] ?? 0,
                'stok_minimum' => $item['stok_minimum'] ?? 0,

                'fee_dokter' => $item['fee_dokter'] ?? 0,
                'fee_beautician' => $item['fee_beautician'] ?? 0,

                'sort_order' => $item['sort_order'] ?? 0,
                'is_delete' => 0,

                'created_by' => $actor,
                'created_at' => now(),
            ]);
        }
    }
}