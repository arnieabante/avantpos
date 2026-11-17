<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Philippine Standard Geographic Code reference data, loaded from the
 * committed snapshot database/data/psgc/psgc.json.gz (no network needed).
 * Skips if already loaded.
 */
class PsgcSeeder extends Seeder
{
    private const SNAPSHOT = 'data/psgc/psgc.json.gz';

    public function run(): void
    {
        if (DB::table('ph_regions')->exists()) {
            $this->command?->info('PSGC already loaded — skipped.');

            return;
        }

        $path = database_path(self::SNAPSHOT);
        if (! is_file($path)) {
            $this->command?->warn("PSGC snapshot not found at {$path} — skipped.");

            return;
        }

        $data = json_decode(gzdecode(file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($data) {
            $this->insert('ph_regions', $data['regions'], fn ($r) => ['code' => $r[0], 'name' => $r[1], 'region_name' => $r[2]]);
            $this->insert('ph_provinces', $data['provinces'], fn ($p) => ['code' => $p[0], 'name' => $p[1], 'region_code' => $p[2]]);
            $this->insert('ph_cities', $data['cities'], fn ($c) => [
                'code' => $c[0], 'name' => $c[1], 'type' => $c[2], 'region_code' => $c[3], 'province_code' => $c[4],
            ]);
            $this->insert('ph_barangays', $data['barangays'], fn ($b) => ['code' => $b[0], 'name' => $b[1], 'city_code' => $b[2]]);
        });

        $this->command?->info(sprintf(
            'PSGC loaded: %d regions, %d provinces, %d cities/municipalities, %d barangays.',
            count($data['regions']), count($data['provinces']), count($data['cities']), count($data['barangays']),
        ));
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     */
    private function insert(string $table, array $rows, callable $map): void
    {
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table($table)->insert(array_map($map, $chunk));
        }
    }
}
