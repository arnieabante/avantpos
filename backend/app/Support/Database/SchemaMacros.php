<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * Schema helpers that enforce tenant isolation at the database level.
 *
 * Every tenant-owned table:
 *   - has `tenant_id` (FK -> tenants.id) and UNIQUE(tenant_id, id), and
 *   - references other tenant-owned tables through COMPOSITE foreign keys
 *     (tenant_id, x_id) -> x(tenant_id, id).
 *
 * MySQL therefore rejects any row that points at a record belonging to a
 * different tenant, even if application code has a bug.
 */
class SchemaMacros
{
    public static function register(): void
    {
        /*
         * Adds `tenant_id` + FK to tenants + UNIQUE(tenant_id, id).
         * Call right after $table->id().
         */
        Blueprint::macro('tenantKey', function (): void {
            /** @var Blueprint $this */
            $this->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $this->unique(['tenant_id', 'id'], SchemaMacros::name($this->getTable(), 'tenant_id_id', 'unique'));
        });

        /*
         * Adds `$column` (unsigned big int) and a composite FK
         * (tenant_id, $column) -> $on(tenant_id, id).
         */
        Blueprint::macro('tenantForeign', function (
            string $column,
            string $on,
            bool $nullable = false,
            string $onDelete = 'restrict',
        ) {
            /** @var Blueprint $this */
            $col = $this->unsignedBigInteger($column);
            if ($nullable) {
                $col->nullable();
            }

            $foreign = $this->foreign(['tenant_id', $column], SchemaMacros::name($this->getTable(), $column, 'fk'))
                ->references(['tenant_id', 'id'])
                ->on($on);

            match ($onDelete) {
                'cascade' => $foreign->cascadeOnDelete(),
                'restrict' => $foreign->restrictOnDelete(),
                default => throw new \InvalidArgumentException(
                    "Unsupported onDelete [{$onDelete}] for composite tenant FK (SET NULL would null tenant_id)."
                ),
            };

            return $col;
        });

        /*
         * Monetary amount: DECIMAL(15,2). Never use float/double for money.
         */
        Blueprint::macro('money', function (string $column) {
            /** @var Blueprint $this */
            return $this->decimal($column, 15, 2);
        });

        /*
         * Unit cost / average cost: DECIMAL(15,4).
         */
        Blueprint::macro('unitCost', function (string $column) {
            /** @var Blueprint $this */
            return $this->decimal($column, 15, 4);
        });

        /*
         * Stock quantity in base units: DECIMAL(15,3) (supports weighed / metered items).
         */
        Blueprint::macro('quantity', function (string $column) {
            /** @var Blueprint $this */
            return $this->decimal($column, 15, 3);
        });

        /*
         * Percentage / rate: DECIMAL(7,4), e.g. 12.0000 (%).
         */
        Blueprint::macro('rate', function (string $column) {
            /** @var Blueprint $this */
            return $this->decimal($column, 7, 4);
        });
    }

    /**
     * Index/constraint names are limited to 64 chars in MySQL.
     */
    public static function name(string $table, string $column, string $suffix): string
    {
        $name = "{$table}_{$column}_{$suffix}";

        if (strlen($name) <= 64) {
            return $name;
        }

        return substr($name, 0, 55).'_'.substr(md5($name), 0, 8);
    }
}
