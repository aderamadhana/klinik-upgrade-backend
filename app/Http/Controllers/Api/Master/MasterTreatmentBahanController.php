<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\master\MasterTreatmentBahan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MasterTreatmentBahanController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $treatmentId = $request->get('treatment_id');
        $produkId = $request->get('produk_id');
        $isRequired = $request->get('is_required');
        $isActive = $request->get('is_active');

        $perPage = (int) $request->get('per_page', 10);
        $perPage = $perPage > 0 ? min($perPage, 100) : 10;

        $query = MasterTreatmentBahan::query()
            ->where('is_delete', 0)
            ->with([
                'treatment',
                'produk.satuan',
                'satuan',
            ])
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->whereHas('treatment', function ($treatment) use ($search) {
                        $treatment->where('nama', 'like', "%{$search}%")
                            ->orWhere('kode_accurate', 'like', "%{$search}%");
                    })
                    ->orWhereHas('produk', function ($produk) use ($search) {
                        $produk->where('nama', 'like', "%{$search}%")
                            ->orWhere('kode_accurate', 'like', "%{$search}%");
                    })
                    ->orWhere('satuan_nama', 'like', "%{$search}%");
                });
            })
            ->when($treatmentId, function ($q) use ($treatmentId) {
                $q->where('treatment_id', $treatmentId);
            })
            ->when($produkId, function ($q) use ($produkId) {
                $q->where('produk_id', $produkId);
            })
            ->when($isRequired !== null && $isRequired !== '', function ($q) use ($isRequired) {
                $q->where('is_required', (int) $isRequired);
            })
            ->when($isActive !== null && $isActive !== '', function ($q) use ($isActive) {
                $q->where('is_active', (int) $isActive);
            })
            ->orderBy('treatment_id')
            ->orderBy('sort_order')
            ->orderBy('id');

        $data = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Data master bahan treatment berhasil diambil',
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
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $actor = $this->actor();
            $payload = $this->buildPayload($request, $actor, true);

            $existing = MasterTreatmentBahan::query()
                ->where('treatment_id', $payload['treatment_id'])
                ->where('produk_id', $payload['produk_id'])
                ->first();

            if ($existing && (int) $existing->is_delete === 0) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'Produk bahan ini sudah terdaftar pada treatment tersebut',
                ], 422);
            }

            if ($existing) {
                $existing->update(array_merge($payload, [
                    'is_delete' => 0,
                    'updated_by' => $actor,
                    'updated_at' => now(),
                ]));

                DB::commit();

                return response()->json([
                    'status' => true,
                    'message' => 'Data master bahan treatment berhasil diaktifkan kembali',
                    'data' => $existing->fresh()->load(['treatment', 'produk.satuan', 'satuan']),
                ], 201);
            }

            $data = MasterTreatmentBahan::create($payload);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data master bahan treatment berhasil disimpan',
                'data' => $data->fresh()->load(['treatment', 'produk.satuan', 'satuan']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyimpan data master bahan treatment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $data = MasterTreatmentBahan::query()
            ->where('is_delete', 0)
            ->with(['treatment', 'produk.satuan', 'satuan'])
            ->find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data master bahan treatment tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Detail master bahan treatment berhasil diambil',
            'data' => $data,
        ]);
    }

    public function update(Request $request, $id)
    {
        $data = MasterTreatmentBahan::query()
            ->where('is_delete', 0)
            ->find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data master bahan treatment tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), $this->rules($id));

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $actor = $this->actor();
            $payload = $this->buildPayload($request, $actor, false);

            $duplicate = MasterTreatmentBahan::query()
                ->where('id', '!=', $id)
                ->where('treatment_id', $payload['treatment_id'])
                ->where('produk_id', $payload['produk_id'])
                ->first();

            if ($duplicate) {
                DB::rollBack();

                return response()->json([
                    'status' => false,
                    'message' => 'Kombinasi treatment dan produk bahan sudah dipakai pada data lain',
                ], 422);
            }

            $data->update($payload);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data master bahan treatment berhasil diperbarui',
                'data' => $data->fresh()->load(['treatment', 'produk.satuan', 'satuan']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal memperbarui data master bahan treatment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $data = MasterTreatmentBahan::query()
            ->where('is_delete', 0)
            ->find($id);

        if (!$data) {
            return response()->json([
                'status' => false,
                'message' => 'Data master bahan treatment tidak ditemukan',
            ], 404);
        }

        DB::beginTransaction();

        try {
            $data->update([
                'is_delete' => 1,
                'updated_by' => $this->actor(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Data master bahan treatment berhasil dihapus',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menghapus data master bahan treatment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function syncByTreatment(Request $request, $treatmentId)
    {
        $treatmentExists = DB::table('master_treatment')
            ->where('id', $treatmentId)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->exists();

        if (!$treatmentExists) {
            return response()->json([
                'status' => false,
                'message' => 'Treatment tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'items' => 'required|array',
            'items.*.produk_id' => [
                'required',
                'integer',
                Rule::exists('master_produk', 'id')->where(function ($q) {
                    $q->where(function ($qq) {
                        $qq->where('is_delete', 0)
                            ->orWhereNull('is_delete');
                    });
                }),
            ],
            'items.*.qty_default' => 'required|numeric|min:0.0001',
            'items.*.satuan_id' => [
                'nullable',
                'integer',
                Rule::exists('master_satuan', 'id')->where(function ($q) {
                    $q->where(function ($qq) {
                        $qq->where('is_delete', 0)
                            ->orWhereNull('is_delete');
                    });
                }),
            ],
            'items.*.satuan_nama' => 'nullable|string|max:50',
            'items.*.is_required' => 'nullable|boolean',
            'items.*.is_active' => 'nullable|boolean',
            'items.*.sort_order' => 'nullable|integer|min:0|max:32767',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $items = collect($request->input('items', []))
            ->map(function ($item) {
                return [
                    'produk_id' => (int) $item['produk_id'],
                    'qty_default' => $item['qty_default'],
                    'satuan_id' => $item['satuan_id'] ?? null,
                    'satuan_nama' => $item['satuan_nama'] ?? null,
                    'is_required' => $item['is_required'] ?? 1,
                    'is_active' => $item['is_active'] ?? 1,
                    'sort_order' => $item['sort_order'] ?? 0,
                ];
            })
            ->values();

        $duplicateProduk = $items
            ->groupBy('produk_id')
            ->filter(function ($rows) {
                return $rows->count() > 1;
            })
            ->keys()
            ->values();

        if ($duplicateProduk->count() > 0) {
            return response()->json([
                'status' => false,
                'message' => 'Produk bahan tidak boleh duplikat dalam satu treatment',
                'duplicate_produk_ids' => $duplicateProduk,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $actor = $this->actor();
            $produkIds = $items->pluck('produk_id')->values()->toArray();

            MasterTreatmentBahan::query()
                ->where('treatment_id', $treatmentId)
                ->where('is_delete', 0)
                ->when(count($produkIds) > 0, function ($q) use ($produkIds) {
                    $q->whereNotIn('produk_id', $produkIds);
                })
                ->update([
                    'is_delete' => 1,
                    'updated_by' => $actor,
                    'updated_at' => now(),
                ]);

            foreach ($items as $item) {
                $rowRequest = new Request(array_merge($item, [
                    'treatment_id' => $treatmentId,
                ]));

                $payload = $this->buildPayload($rowRequest, $actor, true);

                $existing = MasterTreatmentBahan::query()
                    ->where('treatment_id', $treatmentId)
                    ->where('produk_id', $payload['produk_id'])
                    ->first();

                if ($existing) {
                    $existing->update(array_merge($payload, [
                        'is_delete' => 0,
                        'updated_by' => $actor,
                        'updated_at' => now(),
                    ]));

                    continue;
                }

                MasterTreatmentBahan::create($payload);
            }

            DB::commit();

            $data = MasterTreatmentBahan::query()
                ->where('treatment_id', $treatmentId)
                ->where('is_delete', 0)
                ->with(['treatment', 'produk.satuan', 'satuan'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Data master bahan treatment berhasil disinkronkan',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyinkronkan data master bahan treatment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function options(Request $request)
    {
        $treatmentId = $request->get('treatment_id');

        $data = MasterTreatmentBahan::query()
            ->where('is_delete', 0)
            ->where('is_active', 1)
            ->with(['produk.satuan', 'satuan'])
            ->when($treatmentId, function ($q) use ($treatmentId) {
                $q->where('treatment_id', $treatmentId);
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'treatment_id' => $item->treatment_id,
                    'produk_id' => $item->produk_id,
                    'nama_bahan' => $item->produk->nama ?? null,
                    'kode_accurate' => $item->produk->kode_accurate ?? null,
                    'qty_default' => $item->qty_default,
                    'satuan_id' => $item->satuan_id,
                    'satuan_nama' => $item->satuan_nama
                        ?? optional($item->satuan)->nama_satuan
                        ?? optional(optional($item->produk)->satuan)->nama_satuan,
                    'is_required' => $item->is_required,
                    'sort_order' => $item->sort_order,
                ];
            });

        return response()->json([
            'status' => true,
            'message' => 'Data opsi master bahan treatment berhasil diambil',
            'data' => $data,
        ]);
    }

    private function rules($ignoreId = null): array
    {
        return [
            'treatment_id' => [
                'required',
                'integer',
                Rule::exists('master_treatment', 'id')->where(function ($q) {
                    $q->where(function ($qq) {
                        $qq->where('is_delete', 0)
                            ->orWhereNull('is_delete');
                    });
                }),
            ],
            'produk_id' => [
                'required',
                'integer',
                Rule::exists('master_produk', 'id')->where(function ($q) {
                    $q->where(function ($qq) {
                        $qq->where('is_delete', 0)
                            ->orWhereNull('is_delete');
                    });
                }),
            ],
            'qty_default' => 'required|numeric|min:0.0001',
            'satuan_id' => [
                'nullable',
                'integer',
                Rule::exists('master_satuan', 'id')->where(function ($q) {
                    $q->where(function ($qq) {
                        $qq->where('is_delete', 0)
                            ->orWhereNull('is_delete');
                    });
                }),
            ],
            'satuan_nama' => 'nullable|string|max:50',
            'is_required' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:32767',
        ];
    }

    private function buildPayload(Request $request, string $actor, bool $isCreate): array
    {
        $produk = DB::table('master_produk')
            ->where('id', $request->produk_id)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->first();

        $satuanId = $request->filled('satuan_id')
            ? (int) $request->satuan_id
            : ($produk->satuan_id ?? null);

        $satuanNama = $request->satuan_nama;

        if (!$satuanNama && $satuanId) {
            $satuanNama = DB::table('master_satuan')
                ->where('id', $satuanId)
                ->where(function ($q) {
                    $q->where('is_delete', 0)
                        ->orWhereNull('is_delete');
                })
                ->value('nama_satuan');
        }

        $payload = [
            'treatment_id' => (int) $request->treatment_id,
            'produk_id' => (int) $request->produk_id,
            'qty_default' => $request->qty_default,
            'satuan_id' => $satuanId,
            'satuan_nama' => $satuanNama,
            'is_required' => $request->has('is_required') ? (int) $request->boolean('is_required') : 1,
            'is_active' => $request->has('is_active') ? (int) $request->boolean('is_active') : 1,
            'sort_order' => (int) $request->input('sort_order', 0),
        ];

        if ($isCreate) {
            $payload['is_delete'] = 0;
            $payload['created_by'] = $actor;
            $payload['created_at'] = now();
        } else {
            $payload['updated_by'] = $actor;
            $payload['updated_at'] = now();
        }

        return $payload;
    }

    private function actor(): string
    {
        return auth('api')->user()->username ?? 'system';
    }
}