<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterMemberTier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MasterMemberTierController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = (int) $request->get('per_page', 15);
            $search = $request->get('search');
            $isActive = $request->get('is_active');

            $query = MasterMemberTier::query()
                ->where('is_delete', 0);

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('kode', 'LIKE', "%{$search}%")
                        ->orWhere('nama', 'LIKE', "%{$search}%");
                });
            }

            if ($isActive !== null && $isActive !== '') {
                $query->where('is_active', (int) $isActive);
            }

            $data = $query
                ->orderBy('sort_order')
                ->orderBy('minimal_spending')
                ->orderBy('id')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Data master member tier berhasil diambil',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data master member tier',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $data = MasterMemberTier::where('is_delete', 0)
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Detail master member tier berhasil diambil',
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data master member tier tidak ditemukan',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'kode' => [
                'required',
                'string',
                'max:30',
                Rule::unique('master_member_tier', 'kode')
                    ->where(fn ($q) => $q->where('is_delete', 0)),
            ],
            'nama' => [
                'required',
                'string',
                'max:100',
                Rule::unique('master_member_tier', 'nama')
                    ->where(fn ($q) => $q->where('is_delete', 0)),
            ],
            'minimal_spending' => ['required', 'numeric', 'min:0'],
            'diskon_persen' => ['required', 'numeric', 'min:0', 'max:100'],
            'point_rate' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ], [
            'kode.required' => 'Kode tier wajib diisi.',
            'kode.unique' => 'Kode tier sudah digunakan.',
            'nama.required' => 'Nama tier wajib diisi.',
            'nama.unique' => 'Nama tier sudah digunakan.',
            'minimal_spending.required' => 'Minimal spending wajib diisi.',
            'diskon_persen.required' => 'Diskon persen wajib diisi.',
            'diskon_persen.max' => 'Diskon persen maksimal 100.',
            'point_rate.required' => 'Point rate wajib diisi.',
        ]);

        DB::beginTransaction();

        try {
            $userName = $this->getUserNameForAudit();

            $data = MasterMemberTier::create([
                'kode' => strtoupper($validated['kode']),
                'nama' => $validated['nama'],
                'minimal_spending' => $validated['minimal_spending'],
                'diskon_persen' => $validated['diskon_persen'],
                'point_rate' => $validated['point_rate'],
                'is_active' => $request->boolean('is_active', true) ? 1 : 0,
                'is_delete' => 0,
                'sort_order' => $validated['sort_order'] ?? 0,
                'created_by' => $userName,
                'updated_by' => $userName,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Master member tier berhasil ditambahkan',
                'data' => $data,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan master member tier',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $data = MasterMemberTier::where('is_delete', 0)->findOrFail($id);

        $validated = $request->validate([
            'kode' => [
                'required',
                'string',
                'max:30',
                Rule::unique('master_member_tier', 'kode')
                    ->ignore($id)
                    ->where(fn ($q) => $q->where('is_delete', 0)),
            ],
            'nama' => [
                'required',
                'string',
                'max:100',
                Rule::unique('master_member_tier', 'nama')
                    ->ignore($id)
                    ->where(fn ($q) => $q->where('is_delete', 0)),
            ],
            'minimal_spending' => ['required', 'numeric', 'min:0'],
            'diskon_persen' => ['required', 'numeric', 'min:0', 'max:100'],
            'point_rate' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ], [
            'kode.required' => 'Kode tier wajib diisi.',
            'kode.unique' => 'Kode tier sudah digunakan.',
            'nama.required' => 'Nama tier wajib diisi.',
            'nama.unique' => 'Nama tier sudah digunakan.',
            'minimal_spending.required' => 'Minimal spending wajib diisi.',
            'diskon_persen.required' => 'Diskon persen wajib diisi.',
            'diskon_persen.max' => 'Diskon persen maksimal 100.',
            'point_rate.required' => 'Point rate wajib diisi.',
        ]);

        DB::beginTransaction();

        try {
            $data->update([
                'kode' => strtoupper($validated['kode']),
                'nama' => $validated['nama'],
                'minimal_spending' => $validated['minimal_spending'],
                'diskon_persen' => $validated['diskon_persen'],
                'point_rate' => $validated['point_rate'],
                'is_active' => $request->boolean('is_active', true) ? 1 : 0,
                'sort_order' => $validated['sort_order'] ?? 0,
                'updated_by' => $this->getUserNameForAudit(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Master member tier berhasil diperbarui',
                'data' => $data->fresh(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui master member tier',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $data = MasterMemberTier::where('is_delete', 0)->findOrFail($id);

            $data->update([
                'is_delete' => 1,
                'updated_by' => $this->getUserNameForAudit(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Master member tier berhasil dihapus',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus master member tier',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function getUserNameForAudit()
    {
        $user = auth()->user();

        if (!$user) {
            return null;
        }

        return $user->username
            ?? $user->name
            ?? $user->nama
            ?? (string) $user->id;
    }
}