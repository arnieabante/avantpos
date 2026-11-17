<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Database-level guarantees, independent of application code.
 */

it('rejects a row that references another tenant record (composite FK)', function () {
    ['tenant' => $a] = $this->createTenant();
    ['branch' => $foreignBranch] = $this->createTenant();

    // Raw insert: bypasses Eloquent entirely, as a buggy query would.
    DB::table('terminals')->insert([
        'tenant_id' => $a->id,
        'branch_id' => $foreignBranch->id,
        'code' => 'EVIL',
        'name' => 'Cross-tenant terminal',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

it('enforces composite tenant foreign keys on EVERY tenant-to-tenant relationship', function () {
    $database = DB::getDatabaseName();

    $tenantTables = collect(DB::select(
        "SELECT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'tenant_id'",
        [$database]
    ))->pluck('t')->all();

    $foreignKeys = collect(DB::select(
        'SELECT k.TABLE_NAME AS tbl, k.CONSTRAINT_NAME AS name, k.REFERENCED_TABLE_NAME AS ref,
                GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION) AS cols
         FROM information_schema.KEY_COLUMN_USAGE k
         WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
         GROUP BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME',
        [$database]
    ));

    // Allowed single-column FKs between tenant tables (global/spatie tables or tenant_id itself).
    $exempt = ['roles', 'tenants'];

    $violations = $foreignKeys
        ->filter(fn ($fk) => in_array($fk->tbl, $tenantTables, true)
            && in_array($fk->ref, $tenantTables, true)
            && ! in_array($fk->ref, $exempt, true)
            && ! str_starts_with($fk->cols, 'tenant_id,'))
        ->map(fn ($fk) => "{$fk->tbl}.{$fk->cols} -> {$fk->ref}")
        ->values()
        ->all();

    expect($violations)->toBe([]);
});

it('has UNIQUE(tenant_id, id) on every tenant table referenced by a composite FK', function () {
    $database = DB::getDatabaseName();

    $referenced = collect(DB::select(
        "SELECT DISTINCT REFERENCED_TABLE_NAME AS ref FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL AND COLUMN_NAME = 'tenant_id' AND REFERENCED_COLUMN_NAME = 'tenant_id'",
        [$database]
    ))->pluck('ref');

    expect($referenced)->not->toBeEmpty();

    foreach ($referenced as $table) {
        $indexes = collect(Schema::getIndexes($table));
        $hasKey = $indexes->contains(fn ($i) => $i['unique'] && $i['columns'] === ['tenant_id', 'id']);

        expect($hasKey)->toBeTrue("{$table} is missing UNIQUE(tenant_id, id)");
    }
});

it('uses BelongsToTenant on every model whose table has tenant_id', function () {
    // Models deliberately managed differently (documented in each class).
    $exceptions = [
        Role::class,       // spatie team scoping; always queried via forCurrentTenant()
        AuditLog::class,   // nullable tenant (platform events); forCurrentTenant()
    ];

    $missing = collect(File::allFiles(app_path('Models')))
        ->map(fn ($f) => 'App\\Models\\'.Str::of($f->getRelativePathname())->replace(['/', '.php'], ['\\', ''])->toString())
        ->filter(fn ($class) => class_exists($class) && is_subclass_of($class, Model::class))
        ->reject(fn ($class) => in_array($class, $exceptions, true))
        ->filter(fn ($class) => Schema::hasColumn((new $class)->getTable(), 'tenant_id'))
        ->reject(fn ($class) => in_array(BelongsToTenant::class, class_uses_recursive($class), true))
        ->values()
        ->all();

    expect($missing)->toBe([]);
});

it('stores money as DECIMAL, never FLOAT/DOUBLE', function () {
    $floats = DB::select(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND DATA_TYPE IN ('float', 'double', 'real')",
        [DB::getDatabaseName()]
    );

    expect($floats)->toBe([]);
});
