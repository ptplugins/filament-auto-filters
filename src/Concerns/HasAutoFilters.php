<?php

namespace PtPlugins\FilamentAutoFilters\Concerns;

use Carbon\Carbon;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use PtPlugins\FilamentAutoFilters\Enums\FilterType;

/**
 * Automatically generates Filament table filters from column definitions.
 *
 * Inspects table columns and creates appropriate filters:
 * - Date/DateTime TextColumn -> date range filter (from/until)
 * - Plain TextColumn -> text search filter (contains)
 * - IconColumn->boolean() -> ternary filter (yes/no/all)
 * - SelectColumn -> select filter using same options
 * - Relationship columns (dot notation) -> whereHas queries
 * - JSON columns (data.* prefix) -> arrow notation queries
 *
 * Works with Filament 3, 4, and 5 — single codebase. All filter / form / column
 * classes used here exist in identical namespaces across versions, and Filter
 * keeps `->form()` as alias of `->schema()` in v4+.
 *
 * Explicit filter overrides always take priority over auto-generated ones.
 */
trait HasAutoFilters
{
    /**
     * Build filters automatically from table columns.
     *
     * @param  array<BaseFilter>  $overrides  Explicit filters that replace auto-generated ones
     * @param  array<string>  $skip  Column names to skip
     * @return array<BaseFilter>
     */
    protected static function autoFilters(Table $table, array $overrides = [], array $skip = []): array
    {
        $overrideNames = array_map(
            fn (BaseFilter $filter): string => $filter->getName(),
            $overrides
        );

        $autoFilters = [];

        foreach ($table->getColumns() as $name => $column) {
            // Overrides may target a column by its original name or by the safe
            // filter slug (static::filterName). $skip stays keyed by original name.
            if (in_array($name, $overrideNames, true)
                || in_array(static::filterName($name), $overrideNames, true)) {
                continue;
            }

            if (in_array($name, $skip, true)) {
                continue;
            }

            $label = strip_tags($column->getLabel() ?? $name);

            if ($column instanceof IconColumn) {
                // Only auto-filter boolean IconColumns. Non-boolean IconColumns
                // (status icons mapped to enums) skip — user can override manually.
                if ($column->isBoolean()) {
                    $autoFilters[] = static::makeTernaryFilter($name, $label);
                }

                continue;
            }

            if ($column instanceof SelectColumn) {
                $options = $column->getOptions();
                if (! empty($options)) {
                    $autoFilters[] = static::makeSelectFilter($name, $label, $options);
                }

                continue;
            }

            if (! ($column instanceof TextColumn)) {
                continue;
            }

            if ($column->isDate() || $column->isDateTime()) {
                $autoFilters[] = static::makeDateRangeFilter($name, $label);
            } else {
                $autoFilters[] = static::makeTextFilter($name, $label);
            }
        }

        return array_merge($overrides, $autoFilters);
    }

    /**
     * Build a safe, stable Filament filter name from a (possibly dotted / spaced /
     * accented) column name.
     *
     * The column name still drives the query via resolveColumn(); this is only the
     * filter's identity, i.e. the Livewire/URL state key (?tableFilters[...]). A raw
     * column like `data.Ukupna naknada` (space + dot + diacritics) is an invalid
     * wire:model / HTML attribute and breaks the whole filters form in the browser -
     * this slug avoids that.
     *
     * Conditional by design: names that are already valid, safe state keys
     * (letters/digits/underscore) pass through unchanged, so existing simple-column
     * filters keep their names (and their bookmarked URLs / e2e selectors). Only
     * unsafe names are slugged. Set `auto-filters.sanitize_names` to false to disable
     * entirely (legacy raw-name behavior).
     *
     * Deterministic and public so callers can reproduce the slug to target an auto
     * filter with an override.
     */
    public static function filterName(string $column): string
    {
        if (! config('auto-filters.sanitize_names', true)) {
            return $column;
        }

        // Already a valid, safe Livewire state key -> leave untouched (backward compatible).
        if (preg_match('/^[A-Za-z0-9_]+$/', $column) === 1) {
            return $column;
        }

        // Turn path separators (dots) into spaces first so Str::slug keeps each segment
        // distinct (it strips dots otherwise: `rel.col` -> `relcol`), which also avoids
        // needless collisions.
        return 'af_'.Str::slug(str_replace('.', ' ', $column), '_');
    }

    /**
     * Create a ternary (yes/no/all) filter for a boolean column. Handles
     * direct, JSON, and relationship columns via the same dot-notation
     * convention as the other helpers.
     */
    protected static function makeTernaryFilter(string $name, string $label): TernaryFilter
    {
        $resolved = static::resolveColumn($name);
        $filter = TernaryFilter::make(static::filterName($name))->label($label);

        if (config('auto-filters.inline_labels', true)) {
            $filter->modifyFormFieldUsing(fn ($field) => $field->inlineLabel());
        }

        if ($resolved['type'] === FilterType::Json) {
            $filter->attribute($resolved['query_column']);

            return $filter;
        }

        if ($resolved['type'] === FilterType::Relationship) {
            return $filter->queries(
                true: fn (Builder $q) => $q->whereHas(
                    $resolved['relationship'],
                    fn (Builder $sub) => $sub->where($resolved['column'], true),
                ),
                false: fn (Builder $q) => $q->whereHas(
                    $resolved['relationship'],
                    fn (Builder $sub) => $sub->where($resolved['column'], false),
                ),
                blank: fn (Builder $q) => $q,
            );
        }

        return $filter;
    }

    /**
     * Create a smart select filter that handles direct, JSON, and relationship columns.
     *
     * @param  array<string, string>|Closure  $options
     */
    protected static function makeSelectFilter(string $name, string $label, array|Closure $options): SelectFilter
    {
        $filter = SelectFilter::make(static::filterName($name))
            ->label($label)
            ->options($options)
            ->multiple(config('auto-filters.select_multiple', true))
            ->searchable(config('auto-filters.select_searchable', true));

        if (config('auto-filters.inline_labels', true)) {
            $filter->modifyFormFieldUsing(fn ($field) => $field->inlineLabel());
        }

        $resolved = static::resolveColumn($name);

        if ($resolved['type'] === FilterType::Json) {
            $filter->attribute($resolved['query_column']);
        } elseif ($resolved['type'] === FilterType::Relationship) {
            $filter->query(function (Builder $query, array $data) use ($resolved): Builder {
                $values = $data['values'] ?? [];
                if (empty($values)) {
                    return $query;
                }

                return $query->whereHas(
                    $resolved['relationship'],
                    fn (Builder $q) => $q->whereIn($resolved['column'], $values)
                );
            });
        }

        return $filter;
    }

    /**
     * Resolve the date-picker field class used inside date range filters.
     *
     * Prefers the lightweight `ptplugins/filament-pikaday` field when it is
     * installed and `auto-filters.prefer_pikaday` is enabled; otherwise falls
     * back to Filament's native DatePicker. Pikaday is an optional (suggested)
     * dependency, so it is referenced by string and guarded with class_exists.
     *
     * @return class-string<\Filament\Forms\Components\Field>
     */
    protected static function dateFilterFieldClass(): string
    {
        $pikaday = 'PtPlugins\\FilamentPikaday\\Fields\\PikadayDatePicker';

        if (config('auto-filters.prefer_pikaday', false) && class_exists($pikaday)) {
            return $pikaday;
        }

        return DatePicker::class;
    }

    /**
     * Create a date range filter (from/until) for a column.
     */
    protected static function makeDateRangeFilter(string $name, string $label): Filter
    {
        $resolved = static::resolveColumn($name);
        $dateFormat = config('auto-filters.date_format', 'd.m.Y');
        $inlineLabels = config('auto-filters.inline_labels', true);

        $picker = static::dateFilterFieldClass();
        $fromInput = $picker::make('from')->label($label.' from');
        $untilInput = $picker::make('until')->label($label.' until');

        if ($inlineLabels) {
            $fromInput->inlineLabel();
            $untilInput->inlineLabel();
        }

        return Filter::make(static::filterName($name))
            ->label($label)
            ->form([$fromInput, $untilInput])
            ->query(function (Builder $query, array $data) use ($resolved): Builder {
                $from = $data['from'] ?? null;
                $until = $data['until'] ?? null;

                if ($resolved['type'] === FilterType::Relationship) {
                    return $query
                        ->when($from, fn (Builder $q, $d) => $q->whereHas(
                            $resolved['relationship'],
                            fn (Builder $sub) => $sub->whereDate($resolved['column'], '>=', $d)
                        ))
                        ->when($until, fn (Builder $q, $d) => $q->whereHas(
                            $resolved['relationship'],
                            fn (Builder $sub) => $sub->whereDate($resolved['column'], '<=', $d)
                        ));
                }

                $col = $resolved['query_column'];

                return $query
                    ->when($from, fn (Builder $q, $d) => $q->whereDate($col, '>=', $d))
                    ->when($until, fn (Builder $q, $d) => $q->whereDate($col, '<=', $d));
            })
            ->indicateUsing(function (array $data) use ($label, $dateFormat): array {
                $indicators = [];

                if ($data['from'] ?? null) {
                    $indicators[] = $label.' from '.Carbon::parse($data['from'])->format($dateFormat);
                }

                if ($data['until'] ?? null) {
                    $indicators[] = $label.' until '.Carbon::parse($data['until'])->format($dateFormat);
                }

                return $indicators;
            });
    }

    /**
     * Create a text search filter (contains) for a column.
     */
    protected static function makeTextFilter(string $name, string $label): Filter
    {
        $resolved = static::resolveColumn($name);
        $placeholder = config('auto-filters.text_search_placeholder', 'Search...');

        $input = TextInput::make('value')
            ->label($label)
            ->placeholder($placeholder);

        if (config('auto-filters.inline_labels', true)) {
            $input->inlineLabel();
        }

        return Filter::make(static::filterName($name))
            ->label($label)
            ->form([$input])
            ->query(function (Builder $query, array $data) use ($resolved): Builder {
                $value = $data['value'] ?? null;

                if (blank($value)) {
                    return $query;
                }

                if ($resolved['type'] === FilterType::Relationship) {
                    return $query->whereRelation(
                        $resolved['relationship'],
                        $resolved['column'],
                        'like',
                        "%{$value}%"
                    );
                }

                $col = $resolved['query_column'];

                return $query->where($col, 'like', "%{$value}%");
            })
            ->indicateUsing(function (array $data) use ($label): array {
                if ($data['value'] ?? null) {
                    return [$label.': "'.$data['value'].'"'];
                }

                return [];
            });
    }

    /**
     * Resolve a column name into its type and query components.
     *
     * @return array{type: FilterType, column?: string, query_column?: string, relationship?: string}
     */
    protected static function resolveColumn(string $name): array
    {
        // JSON column: data.xxx -> query as data->xxx
        if (str_starts_with($name, 'data.')) {
            $jsonPath = substr($name, 5);

            return [
                'type' => FilterType::Json,
                'query_column' => 'data->'.$jsonPath,
            ];
        }

        // Relationship column: rel.col
        if (str_contains($name, '.')) {
            [$rel, $col] = explode('.', $name, 2);

            return [
                'type' => FilterType::Relationship,
                'relationship' => $rel,
                'column' => $col,
            ];
        }

        // Direct column
        return [
            'type' => FilterType::Direct,
            'query_column' => $name,
        ];
    }
}
