<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterPoinRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MasterPoinRuleController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 15);
            $search = $request->get('search');
            $isActive = $request->get('is_active');

            $query = MasterPoinRule::query()
                ->where('is_delete', 0);

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('nama_rule', 'LIKE', "%{$search}%")
                        ->orWhere('keterangan', 'LIKE', "%{$search}%");
                });
            }

            if ($isActive !== null && $isActive !== '') {
                $query->where('is_active', (int) $isActive);
            }

            $data = $query
                ->orderByDesc('is_active')
                ->orderByDesc('berlaku_mulai')
                ->orderByDesc('id')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Data master poin rule berhasil diambil',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data master poin rule',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $data = MasterPoinRule::where('is_delete', 0)
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Detail master poin rule berhasil diambil',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data master poin rule tidak ditemukan',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_rule' => [
                'required',
                'string',
                'max:150',
                Rule::unique('master_poin_rules', 'nama_rule')
                    ->where(fn ($q) => $q->where('is_delete', 0)),
            ],
            'nominal_per_poin' => ['required', 'numeric', 'min:0'],
            'minimal_transaksi' => ['required', 'numeric', 'min:0'],
            'berlaku_mulai' => ['nullable', 'date'],
            'berlaku_sampai' => ['nullable', 'date', 'after_or_equal:berlaku_mulai'],
            'is_berlaku_kelipatan' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'keterangan' => ['nullable', 'string'],
        ], [
            'nama_rule.required' => 'Nama rule wajib diisi.',
            'nama_rule.unique' => 'Nama rule sudah digunakan.',
            'nominal_per_poin.required' => 'Nominal per poin wajib diisi.',
            'minimal_transaksi.required' => 'Minimal transaksi wajib diisi.',
            'berlaku_sampai.after_or_equal' => 'Tanggal berlaku sampai tidak boleh lebih kecil dari tanggal berlaku mulai.',
        ]);

        DB::beginTransaction();

        try {
            $data = MasterPoinRule::create([
                'nama_rule' => $validated['nama_rule'],
                'nominal_per_poin' => $validated['nominal_per_poin'],
                'minimal_transaksi' => $validated['minimal_transaksi'],
                'berlaku_mulai' => $validated['berlaku_mulai'] ?? null,
                'berlaku_sampai' => $validated['berlaku_sampai'] ?? null,
                'is_berlaku_kelipatan' => $request->boolean('is_berlaku_kelipatan', true) ? 1 : 0,
                'is_active' => $request->boolean('is_active', true) ? 1 : 0,
                'is_delete' => 0,
                'keterangan' => $validated['keterangan'] ?? null,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Master poin rule berhasil ditambahkan',
                'data' => $data,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan master poin rule',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $data = MasterPoinRule::where('is_delete', 0)->findOrFail($id);

        $validated = $request->validate([
            'nama_rule' => [
                'required',
                'string',
                'max:150',
                Rule::unique('master_poin_rules', 'nama_rule')
                    ->ignore($id)
                    ->where(fn ($q) => $q->where('is_delete', 0)),
            ],
            'nominal_per_poin' => ['required', 'numeric', 'min:0'],
            'minimal_transaksi' => ['required', 'numeric', 'min:0'],
            'berlaku_mulai' => ['nullable', 'date'],
            'berlaku_sampai' => ['nullable', 'date', 'after_or_equal:berlaku_mulai'],
            'is_berlaku_kelipatan' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'keterangan' => ['nullable', 'string'],
        ], [
            'nama_rule.required' => 'Nama rule wajib diisi.',
            'nama_rule.unique' => 'Nama rule sudah digunakan.',
            'nominal_per_poin.required' => 'Nominal per poin wajib diisi.',
            'minimal_transaksi.required' => 'Minimal transaksi wajib diisi.',
            'berlaku_sampai.after_or_equal' => 'Tanggal berlaku sampai tidak boleh lebih kecil dari tanggal berlaku mulai.',
        ]);

        DB::beginTransaction();

        try {
            $data->update([
                'nama_rule' => $validated['nama_rule'],
                'nominal_per_poin' => $validated['nominal_per_poin'],
                'minimal_transaksi' => $validated['minimal_transaksi'],
                'berlaku_mulai' => $validated['berlaku_mulai'] ?? null,
                'berlaku_sampai' => $validated['berlaku_sampai'] ?? null,
                'is_berlaku_kelipatan' => $request->boolean('is_berlaku_kelipatan', true) ? 1 : 0,
                'is_active' => $request->boolean('is_active', true) ? 1 : 0,
                'keterangan' => $validated['keterangan'] ?? null,
                'updated_by' => auth()->id(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Master poin rule berhasil diperbarui',
                'data' => $data->fresh(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui master poin rule',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $data = MasterPoinRule::where('is_delete', 0)->findOrFail($id);

            $data->update([
                'is_delete' => 1,
                'updated_by' => auth()->id(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Master poin rule berhasil dihapus',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus master poin rule',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}