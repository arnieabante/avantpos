<?php

namespace App\Services\Reports;

use App\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Date range (whole days in the business's time zone) and branches of a report.
 */
final class ReportFilters
{
    /**
     * @param  Collection<int, Branch>  $branches
     */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly Collection $branches,
        public readonly string $timezone,
        public readonly string $groupBy = 'day',
    ) {}

    /** @return list<int> */
    public function branchIds(): array
    {
        return $this->branches->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** Start of the first day, in UTC (how timestamps are stored). */
    public function fromUtc(): CarbonImmutable
    {
        return $this->from->startOfDay()->utc();
    }

    /** End of the last day, in UTC. */
    public function toUtc(): CarbonImmutable
    {
        return $this->to->endOfDay()->utc();
    }

    /** UTC offset of the business time zone, e.g. "+08:00" (for grouping by local day in SQL). */
    public function offset(): string
    {
        return $this->from->format('P');
    }

    /** SQL expression: a UTC timestamp column shown in local time. */
    public function local(string $column): string
    {
        return "CONVERT_TZ({$column}, '+00:00', '{$this->offset()}')";
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'branches' => $this->branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values()->all(),
            'group_by' => $this->groupBy,
            'timezone' => $this->timezone,
        ];
    }
}
