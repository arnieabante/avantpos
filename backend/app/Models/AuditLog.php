<?php

namespace App\Models;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only audit trail. Rows are written only by AuditLogger and can
 * never be updated or deleted through the application.
 *
 * Tenant-level events carry tenant_id; platform events have tenant_id = null.
 * Tenant-facing queries MUST use AuditLog::forCurrentTenant().
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit logs are append-only.'));
    }

    /**
     * @return Builder<self>
     */
    public static function forCurrentTenant(): Builder
    {
        return static::query()->where('tenant_id', app(TenantContext::class)->requireId());
    }
}
