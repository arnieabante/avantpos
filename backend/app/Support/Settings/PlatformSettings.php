<?php

namespace App\Support\Settings;

use Illuminate\Support\Facades\DB;

/**
 * Platform-wide settings managed by AvantPOS admins (table platform_settings,
 * JSON values). Read with dot keys: "billing.bank_details" reads key
 * "billing", then its "bank_details" entry. Secrets never go here (env only).
 */
class PlatformSettings
{
    public const DEFAULTS = [
        'billing' => [
            'bank_details' => null,
        ],
        'support' => [
            'email' => null,
            'phone' => null,
        ],
        'platform' => [
            'announcement' => null,
            'registrations_open' => true,
        ],
    ];

    public function get(string $key, mixed $default = null): mixed
    {
        [$group, $rest] = array_pad(explode('.', $key, 2), 2, null);
        $value = $this->group($group);

        return $rest === null ? $value : data_get($value, $rest, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function group(string $group): array
    {
        $stored = DB::table('platform_settings')->where('key', $group)->value('value');
        $stored = is_string($stored) ? (json_decode($stored, true) ?: []) : [];

        return array_replace(self::DEFAULTS[$group] ?? [], array_intersect_key($stored, self::DEFAULTS[$group] ?? $stored));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return collect(array_keys(self::DEFAULTS))->mapWithKeys(fn ($g) => [$g => $this->group($g)])->all();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(string $group, array $values): void
    {
        $next = array_replace($this->group($group), array_intersect_key($values, self::DEFAULTS[$group] ?? []));
        DB::table('platform_settings')->updateOrInsert(['key' => $group], ['value' => json_encode($next), 'updated_at' => now(), 'created_at' => now()]);
    }
}
