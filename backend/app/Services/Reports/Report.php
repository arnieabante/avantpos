<?php

namespace App\Services\Reports;

/**
 * A tabular report: typed columns (so every report renders, prints and
 * exports the same way), rows, an optional totals row and notes.
 *
 * Column types: text, date, int, qty, money, percent.
 */
final class Report
{
    /**
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $totals
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly ?array $totals = null,
        public readonly array $notes = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(ReportFilters $filters): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'totals' => $this->totals,
            'notes' => $this->notes,
            'filters' => $filters->describe(),
        ];
    }

    /** Drop columns (e.g. costs for users who may not see them). */
    public function without(array $keys): self
    {
        if ($keys === []) {
            return $this;
        }
        $strip = fn (?array $row) => $row === null ? null : array_diff_key($row, array_flip($keys));

        return new self(
            $this->key,
            $this->title,
            array_values(array_filter($this->columns, fn ($c) => ! in_array($c['key'], $keys, true))),
            array_map($strip, $this->rows),
            $strip($this->totals),
            $this->notes,
        );
    }
}
