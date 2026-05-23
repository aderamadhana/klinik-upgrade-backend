<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->writeAuditLog('create');
        });

        static::updated(function ($model) {
            $action = 'update';

            if ($model->wasChanged('is_delete')) {
                $action = (int) $model->getAttribute('is_delete') === 1
                    ? 'delete'
                    : 'restore';
            }

            $model->writeAuditLog($action);
        });

        static::deleted(function ($model) {
            $model->writeAuditLog('force_delete');
        });
    }

    protected function writeAuditLog(string $action): void
    {
        try {
            if ($this->getTable() === 'audit_logs') {
                return;
            }

            if (!Schema::hasTable('audit_logs')) {
                return;
            }

            $user = $this->getCurrentAuditUser();

            $oldValues = null;
            $newValues = null;

            if ($action === 'create') {
                $newValues = $this->filterAuditValues($this->getAttributes());
            } elseif ($action === 'force_delete') {
                $oldValues = $this->filterAuditValues($this->getOriginal());
            } else {
                $changedKeys = array_keys($this->getChanges());

                $oldValues = $this->filterAuditValues(
                    $this->onlyOriginalKeys($changedKeys)
                );

                $newValues = $this->filterAuditValues(
                    $this->onlyCurrentKeys($changedKeys)
                );
            }

            if ($action === 'update' && empty($oldValues) && empty($newValues)) {
                return;
            }

            DB::table('audit_logs')->insert([
                'module_name' => $this->getAuditModuleName(),
                'table_name'  => $this->getTable(),
                'record_id'   => $this->getKey(),
                'action'      => $action,
                'description' => $this->getAuditDescription($action),

                'old_values'  => $this->toAuditJson($oldValues),
                'new_values'  => $this->toAuditJson($newValues),

                'toko_id'     => $this->getAuditTokoId($user),
                'user_id'     => $user ? $user->id : null,
                'username'    => $user ? ($user->username ?? $user->name ?? null) : null,
                'role_name'   => $this->getAuditRoleName($user),

                'ip_address'  => request() ? request()->ip() : null,
                'user_agent'  => request() ? request()->userAgent() : null,
                'reason'      => $this->getAuditReason(),

                'created_at'  => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function getCurrentAuditUser()
    {
        try {
            return Auth::guard('api')->user() ?: Auth::user();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getAuditRoleName($user = null)
    {
        if (!$user) {
            return null;
        }

        if (isset($user->role_name)) {
            return $user->role_name;
        }

        try {
            if (isset($user->role) && isset($user->role->nama)) {
                return $user->role->nama;
            }

            if (isset($user->role) && isset($user->role->name)) {
                return $user->role->name;
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }

    protected function getAuditModuleName(): string
    {
        if (property_exists($this, 'auditModuleName') && !empty($this->auditModuleName)) {
            return $this->auditModuleName;
        }

        $classParts = explode('\\', get_class($this));

        if (in_array('Master', $classParts)) {
            return 'Master';
        }

        if (in_array('Registrasi', $classParts)) {
            return 'Registrasi';
        }

        if (in_array('Stock', $classParts)) {
            return 'Stock';
        }

        if (in_array('Pembayaran', $classParts)) {
            return 'Pembayaran';
        }

        return 'General';
    }

    protected function getAuditDescription(string $action): string
    {
        $table = $this->getTable();
        $id = $this->getKey();

        if ($action === 'create') {
            return "Menambahkan data {$table} ID {$id}";
        }

        if ($action === 'update') {
            return "Mengubah data {$table} ID {$id}";
        }

        if ($action === 'delete') {
            return "Menghapus data {$table} ID {$id}";
        }

        if ($action === 'restore') {
            return "Mengembalikan data {$table} ID {$id}";
        }

        if ($action === 'force_delete') {
            return "Menghapus permanen data {$table} ID {$id}";
        }

        return "Aktivitas {$action} pada {$table} ID {$id}";
    }

    protected function getAuditTokoId($user = null)
    {
        if ($this->getAttribute('toko_id')) {
            return $this->getAttribute('toko_id');
        }

        if ($this->getAttribute('cabang_id')) {
            return $this->getAttribute('cabang_id');
        }

        if (request() && request()->filled('toko_id')) {
            return request()->input('toko_id');
        }

        if (request() && request()->filled('cabang_id')) {
            return request()->input('cabang_id');
        }

        if (request() && request()->header('X-Toko-Id')) {
            return request()->header('X-Toko-Id');
        }

        if (request() && request()->header('X-Cabang-Id')) {
            return request()->header('X-Cabang-Id');
        }

        if ($user && isset($user->toko_id)) {
            return $user->toko_id;
        }

        return null;
    }

    protected function getAuditReason()
    {
        if (!request()) {
            return null;
        }

        return request()->input('audit_reason')
            ?: request()->input('reason')
            ?: request()->header('X-Audit-Reason');
    }

    protected function onlyOriginalKeys(array $keys): array
    {
        $data = [];

        foreach ($keys as $key) {
            $data[$key] = $this->getOriginal($key);
        }

        return $data;
    }

    protected function onlyCurrentKeys(array $keys): array
    {
        $data = [];

        foreach ($keys as $key) {
            $data[$key] = $this->getAttribute($key);
        }

        return $data;
    }

    protected function filterAuditValues(?array $values): ?array
    {
        if (empty($values)) {
            return null;
        }

        $hidden = [
            'password',
            'remember_token',
            'token',
            'api_token',
            'access_token',
            'refresh_token',
            'secret',
            'secret_key',
        ];

        if (property_exists($this, 'auditHidden') && is_array($this->auditHidden)) {
            $hidden = array_merge($hidden, $this->auditHidden);
        }

        foreach ($hidden as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = '[HIDDEN]';
            }
        }

        return $values;
    }

    protected function toAuditJson(?array $values): ?string
    {
        if (empty($values)) {
            return null;
        }

        return json_encode(
            $values,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }
}