<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class InitialAdminSeeder extends Seeder
{
    public function run(): void
    {
        DB::beginTransaction();

        try {
            $roleId = DB::table('master_role')->insertGetId([
                'nama_role' => 'IT',
                'kode_role' => 'it',
                'is_delete' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $jabatanId = DB::table('master_jabatan')->insertGetId([
                'nama_jabatan' => 'IT Pusat',
                'is_delete' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $tokoId = DB::table('master_toko')->insertGetId([
                'nama_toko' => 'PUSAT',
                'kode_toko' => 'HO',
                'is_delete' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $karyawanId = DB::table('master_karyawan')->insertGetId([
                'nama_karyawan' => 'Admin IT',
                'jabatan_id' => $jabatanId,
                'nik' => 'IT0001',
                'no_hp' => '6281234567890',
                'email' => 'admin@kki.local',
                'status_aktif' => 1,
                'is_delete' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('master_karyawan_penempatan')->insert([
                'karyawan_id' => $karyawanId,
                'toko_id' => $tokoId,
                'is_utama' => 1,
                'is_delete' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $userId = DB::table('master_user')->insertGetId([
                'role_id' => $roleId,
                'karyawan_id' => $karyawanId,
                'name' => 'Admin IT',
                'username' => 'admin',
                'email' => 'admin@kki.local',
                'password' => Hash::make('123456'),
                'is_active' => 1,
                'is_password_changed' => 0,
                'is_delete' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('master_user_penempatan')->insert([
                'user_id' => $userId,
                'toko_id' => $tokoId,
                'is_utama' => 1,
                'is_delete' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}