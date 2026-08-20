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
     * Filters come out in column order. An override takes the slot of the column it
     * replaces (matched by the column's original name or its filterName() slug), so
     * the filter panel always mirrors the table header; overrides that match no
     * column are appended at the end.
     *
     * Distinct-value selects: pass `distinct: true` (every plain text column except
     * `$except`) or `distinct: ['col', 'data.key']` (only those columns) to turn text
     * filters into multi-select filters whose options are the distinct values present
     * in the table's query. Options are resolved when the filters are built, via
     * `$distinctOptionsUsing(string $column, Table $table): array` when given (use it
     * to scope or cache), otherwise through the table's own query. A column whose
     * distinct set is empty or larger than `auto-filters.distinct_max_options`
     * silently stays a text filter, so a 5000-row list never gets a 5000-option select.
     *
     * @param  array<BaseFilter>  $overrides  Explicit filters that replace auto-generated ones
     * @param  array<string>  $skip  Column names to skip
     * @param  bool|array<string>  $distinct  true = all plain text columns, or an explicit list of columns
     * @param  array<string>  $except  Columns excluded from `distinct: true`
     * @param  (Closure(string, Table): array<int|string, mixed>)|null  $distinctOptionsUsing  Custom option resolver
     * @return array<BaseFilter>
     */
    protected static function autoFilters(
        Table $table,
        array $overrides = [],
        array $skip = [],
        bool|array $distinct = false,
        array $except = [],
        ?Closure $distinctOptionsUsing = null,
    ): array {
        /** @var array<string, BaseFilter> $overridesByName */
        $overridesByName = [];
        foreach ($overrides as $override) {
            $overridesByName[$override->getName()] = $override;
        }

        /** @var array<string, true> $placed */
        $placed = [];
        $filters = [];

        foreach ($table->getColumns() as $name => $column) {
            // Overrides may target a column by its original name or by the safe
            // filter slug (static::filterName). $skip stays keyed by original name.
            $override = $overridesByName[$name] ?? $overridesByName[static::filterName($name)] ?? null;

            if ($override !== null) {
                if (! isset($placed[$override->getName()])) {
                    $filters[] = $override;
                    $placed[$override->getName()] = true;
                }

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
                    $filters[] = static::makeTernaryFilter($name, $label);
                }

                continue;
            }

            if ($column instanceof SelectColumn) {
                $options = $column->getOptions();
                if (! empty($options)) {
                    $filters[] = static::makeSelectFilter($name, $label, $options);
                }

                continue;
            }

            if (! ($column instanceof TextColumn)) {
                continue;
            }

            if ($column->isDate() || $column->isDateTime()) {
                $filters[] = static::makeDateRangeFilter($name, $label);

                continue;
            }

            if (static::wantsDistinct($name, $distinct, $except)) {
                $select = static::makeDistinctSelectFilter($table, $name, $label, $distinctOptionsUsing);

                if ($select !== null) {
                    $filters[] = $select;

                    continue;
                }
            }

            $filters[] = static::makeTextFilter($name, $label);
        }

        foreach ($overrides as $override) {
            if (! isset($placed[$override->getName()])) {
                $filters[] = $override;
                $placed[$override->getName()] = true;
            }
        }

        // Auto-generated filters already carry their inline label (each make* helper
        // sets it per type). Explicit overrides do not — so apply it to them here as
        // well, keeping a whole slide-over panel visually uniform without the consumer
        // having to inline every override by hand. See applyInlineLabel() for the one
        // case this cannot cover (overrides built with a custom ->form([...]) schema).
        if (config('auto-filters.inline_labels', true)) {
            foreach ($overrides as $override) {
                static::applyInlineLabel($override);
            }
        }

        return $filters;
    }

    /**
     * Whether a plain text column should become a distinct-value select.
     *
     * @param  bool|array<string>  $distinct
     * @param  array<string>  $except
     */
    protected static function wantsDistinct(string $name, bool|array $distinct, array $except): bool
    {
        if ($distinct === true) {
            return ! in_array($name, $except, true);
        }

        if ($distinct === false) {
            return false;
        }

        return in_array($name, $distinct, true);
    }

    /**
     * Build a multi-select filter whose options are the distinct values of a column.
     *
     * Returns null when the column has no values or more than
     * `auto-filters.distinct_max_options` - the caller then falls back to a text
     * filter. Values are sorted naturally and used as both option key and label.
     *
     * @param  (Closure(string, Table): array<int|string, mixed>)|null  $optionsUsing
     */
    protected static function makeDistinctSelectFilter(Table $table, string $name, string $label, ?Closure $optionsUsing = null): ?SelectFilter
    {
        $raw = $optionsUsing !== null
            ? $optionsUsing($name, $table)
            : static::distinctColumnValues($table, $name);

        $values = [];
        foreach ($raw as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $values[] = (string) $value;
        }

        $values = array_values(array_unique($values));

        if ($values === [] || count($values) > (int) config('auto-filters.distinct_max_options', 50)) {
            return null;
        }

        natcasesort($values);
        $values = array_values($values);

        return static::makeSelectFilter($name, $label, array_combine($values, $values));
    }

    /**
     * Distinct non-null values of a column in the table's current query (direct,
     * JSON `data.key`, or `relation.column`). The query's ordering and eager loads
     * are dropped; its scopes and constraints (e.g. a relation manager's owner
     * record) are kept, so options reflect exactly the rows the table can show.
     *
     * @return array<int, mixed>
     */
    protected static function distinctColumnValues(Table $table, string $name): array
    {
        $resolved = static::resolveColumn($name);
        $query = $table->getQuery();

        if ($resolved['type'] === FilterType::Relationship) {
            $related = $query->getModel()->{$resolved['relationship']}()->getRelated();
            $column = $resolved['column'];

            return $related->newQuery()
                ->toBase()
                ->whereNotNull($column)
                ->distinct()
                ->pluck($column)
                ->all();
        }

        $column = $resolved['query_column'];
        $base = (clone $query)->toBase();
        $wrapped = $base->getGrammar()->wrap($column);

        return $base
            ->reorder()
            ->whereNotNull($column)
            ->distinct()
            ->selectRaw($wrapped.' as af_distinct_value')
            ->get()
            ->pluck('af_distinct_value')
            ->all();
    }

    /**
     * Apply an inline label to a filter whose form field the package controls
     * through getFormField() — i.e. SelectFilter and TernaryFilter, plus any
     * override of those types passed to autoFilters().
     *
     * Filament only runs a filter's modifyFormFieldUsing callback when the filter
     * has NO explicit form schema (see HasFormSchema::getFormSchema — it returns a
     * user-supplied ->form([...]) verbatim and never touches modifyFormFieldUsing).
     * So this is a deliberate no-op for the package's own text and date filters
     * (which build a ->form([...]) and therefore set inlineLabel() directly on their
     * fields), and likewise for any override you build with ->form([...]) — inline
     * those by calling ->inlineLabel() on your own components.
     */
    protected static function applyInlineLabel(BaseFilter $filter): void
    {
        $filter->modifyFormFieldUsing(fn ($field) => $field->inlineLabel());
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
            static::applyInlineLabel($filter);
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
            static::applyInlineLabel($filter);
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
