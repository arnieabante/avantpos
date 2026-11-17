<?php

namespace App\Rules;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Exists;

/**
 * `exists` rule restricted to the current tenant. Use for EVERY foreign id
 * received from a client, so a record id of another tenant fails validation:
 *
 *   'branch_id' => ['required', TenantExists::on('branches')],
 *
 * Batched: for list fields (items.*.product_variant_id) the first element
 * checks the ids of ALL elements in one query and the others are answered
 * from that result, instead of one query per line (a 200-line offline sync
 * batch would otherwise run 200+ queries). Extra conditions chained on the
 * rule (where, whereNull, whereNot, withoutTrashed, using) still apply.
 */
class TenantExists extends Exists implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, array<string, true>> attribute pattern => existing values */
    private array $found = [];

    public static function on(string $table, string $column = 'id'): self
    {
        $rule = new self($table, $column);
        $rule->where('tenant_id', app(TenantContext::class)->requireId());

        // Ignore soft-deleted rows when the table supports soft deletes.
        if (in_array($table, self::SOFT_DELETE_TABLES, true)) {
            $rule->withoutTrashed();
        }

        return $rule;
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_scalar($value) || $value === '') {
            $fail('validation.exists')->translate();

            return;
        }

        // items.3.product_variant_id → items.*.product_variant_id: check all siblings at once.
        $pattern = (string) preg_replace('/\.\d+(?=\.|$)/', '.*', $attribute);
        if (! isset($this->found[$pattern])) {
            $values = $pattern === $attribute ? [$value] : Arr::flatten((array) data_get($this->data, $pattern));
            $values = array_values(array_unique(array_map('strval', array_filter($values, fn ($v) => is_scalar($v) && $v !== ''))));
            $this->found[$pattern] = array_fill_keys($this->existing($values), true);
        }

        if (! isset($this->found[$pattern][(string) $value])) {
            $fail('validation.exists')->translate();
        }
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function existing(array $values): array
    {
        if ($values === []) {
            return [];
        }
        $query = DB::table($this->table)->whereIn($this->column, $values);
        foreach ($this->wheres as $where) {
            $v = $where['value'];
            match (true) {
                $v === 'NULL' => $query->whereNull($where['column']),
                $v === 'NOT_NULL' => $query->whereNotNull($where['column']),
                is_string($v) && str_starts_with($v, '!') => $query->where($where['column'], '!=', mb_substr($v, 1)),
                default => $query->where($where['column'], $v),
            };
        }
        foreach ($this->queryCallbacks() as $callback) {
            $callback($query);
        }

        return $query->distinct()->pluck($this->column)->map(fn ($v) => (string) $v)->all();
    }

    private const SOFT_DELETE_TABLES = [
        'users', 'branches', 'product_categories', 'brands', 'products', 'product_variants',
        'suppliers', 'customers',
    ];
}
