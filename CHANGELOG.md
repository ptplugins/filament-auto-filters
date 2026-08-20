# Changelog

All notable changes to `ptplugins/filament-auto-filters` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.4.1] - 2026-08-20

### Added
- `distinctOptionsUsing` may return an associative `value => label` map (e.g. amounts formatted for display). Its order and labels are kept as-is; blank values are dropped; the `distinct_max_options` threshold still applies. A flat list keeps the previous behavior (natural sort, value used as label).

## [1.4.0] - 2026-08-20

### Fixed
- **Overrides keep their column position.** `autoFilters()` used to `array_merge($overrides, $auto)`, which pushed every override to the top of the filter panel regardless of where its column sits in the table. An override now takes the slot of the column it replaces (matched by original name or `filterName()` slug); overrides matching no column are appended last. The panel mirrors the table header without any re-sorting on the consumer side.

### Added
- **Distinct-value select filters, opt-in.** `autoFilters($table, distinct: true, except: [...])` turns every plain text column (minus `$except`) into a multi-select whose options are the distinct values present in the table's query; `distinct: ['cost', 'data.city']` limits it to the listed columns. Direct, JSON (`data.key`) and `relation.column` columns are supported through the existing `resolveColumn()` convention.
- `distinctOptionsUsing: fn (string $column, Table $table): array` lets you supply (scoped, cached) option values yourself instead of the default query - e.g. per owner record in a relation manager.
- `auto-filters.distinct_max_options` (default `50`): a column with no values or with more distinct values than this silently stays a text filter.
- New protected helpers `wantsDistinct()`, `makeDistinctSelectFilter()`, `distinctColumnValues()`.

### Note
- Distinct options are resolved when the filters are built (one lightweight `SELECT DISTINCT` per column, eager loads and ordering dropped). On very large tables prefer `distinct: [...]` for a few columns, or `distinctOptionsUsing` with your own cache.

## [1.3.0] - 2026-07-17

### Added
- **Inline labels now apply to explicit overrides too.** When `auto-filters.inline_labels` is `true` (the default), `autoFilters()` applies `inlineLabel()` to every override you pass in - not just the auto-generated filters - so a whole slide-over panel reads with one uniform `Label [input]` rhythm without inlining each override by hand. Previously only auto-generated filters were inlined; a raw `SelectFilter::make(...)` override rendered with a stacked label.
- New reusable `HasAutoFilters::applyInlineLabel(BaseFilter $filter)` helper (used internally by `makeSelectFilter`, `makeTernaryFilter`, and the override pass).

### Note
- `applyInlineLabel()` works through `modifyFormFieldUsing`, which Filament only runs for filters **without** an explicit form schema (`SelectFilter`, `TernaryFilter`). It is a deliberate no-op for overrides you build with `->form([...])` - inline those by calling `->inlineLabel()` on your own form components, exactly as the package's own text and date filters do. See the README "Uniform inline labels" section.

## [1.2.0] - 2026-07-15

### Added
- **Safe filter names for dotted / spaced / accented columns.** Auto filters now derive their Livewire/URL state key through the new public `HasAutoFilters::filterName()` helper. A column like `data.Ukupna naknada` or `mktHcpItem.Client ID` (space + dot + diacritics) previously produced an invalid `wire:model` / HTML attribute that broke the whole filters form in the browser; it is now slugged to a safe key (`af_data_ukupna_naknada`) while the query still runs against the original column via `resolveColumn()`.
- `auto-filters.sanitize_names` config key (default `true`).

### Changed
- Sanitization is **conditional**: names that are already valid state keys (letters/digits/underscore, e.g. `product`, `created_at`) pass through unchanged, so existing simple-column filters keep their names, bookmarked URLs, and selectors. Only unsafe names are slugged. Set `auto-filters.sanitize_names` to `false` for the legacy raw-name behavior.
- Filter overrides can now target an auto column by **either** its original column name or its `filterName()` slug; `$skip` continues to match the original column name.

### Note
- For columns whose raw name was previously an *unsafe* key, the URL state key (`?tableFilters[...]`) changes to the new slug - old bookmarked filter URLs for those columns stop applying. Simple-column keys are unaffected.

## [1.1.2] - 2026-06-07

### Added
- "Buy us a beer" support badge under the README intro, linking to [ptplugins.com/buy-us-a-beer](https://ptplugins.com/buy-us-a-beer). Carries `filament-hidden` so it only shows on GitHub/Packagist, not the filamentphp.com listing.

## [1.1.1] - 2026-05-26

### Added
- `auto-filters.inline_labels` config key (default `true`) - controls whether the trait applies `inlineLabel()` to every auto-generated filter. Set to `false` if you keep the default Filament dropdown layout or prefer stacked labels above inputs. Existing setups are unaffected - the default preserves the 1.1.0 behavior.

### Changed
- README "Recommended UX" section now frames inline labels as the *default that pairs with* the slide-over panel, rather than an unconditional trait behavior. Adds a short note on when to switch the new config off.

## [1.1.0] - 2026-05-24

### Changed
- **DateRange filter** now renders as two stacked rows (`Date from`, `Date until`) with inline labels on both axes, replacing the previous single row with one inline label and one hidden-label datepicker. Yields uniform vertical rhythm in slide-over filter panels and removes the asymmetric "label only on first input" look.
- **TernaryFilter** (boolean `IconColumn` auto-filter) now applies `inlineLabel()` to its form field via `modifyFormFieldUsing`. Previously the label rendered above the select; now it sits inline with the other auto-filters.

### Removed
- `auto-filters.date_filter_columns` config key is no longer read (was used to set the grid column count for the date range filter). The DateRange filter now uses default vertical form layout. Existing config files can keep the key - it's silently ignored.

## [1.0.1] - 2026-05-07

### Changed
- Added `filament-hidden` class to the README hero image so it doesn't duplicate the listing banner on the filamentphp.com plugin page. The image still renders normally on GitHub.

## [1.0.0] - 2026-05-04

### Added
- Initial release.
- `HasAutoFilters` trait for Filament Resource classes.
- `static::autoFilters($table, overrides: [], skip: [])` - generates filters from the table's column definitions.
- Auto-detection rules:
  - `TextColumn` (date / datetime via Filament's `isDate()` / `isDateTime()`) → date range picker (from / until)
  - `TextColumn` (default) → text search (`LIKE %...%`)
  - `IconColumn->boolean()` → ternary (Yes / No / All)
  - `SelectColumn` with `->options([...])` → select filter using the same options
  - Dot-notation `rel.col` → `whereRelation()` query
  - Dot-notation `data.X` → JSON arrow query (`data->X`)
- Helpers exposed for manual use:
  - `makeTextFilter(name, label)` - text search filter
  - `makeDateRangeFilter(name, label)` - date range filter
  - `makeSelectFilter(name, label, options)` - select filter
  - `makeTernaryFilter(name, label)` - ternary boolean filter
  - `resolveColumn(name)` - column → query metadata resolver
- Single codebase across **Filament 3, 4, and 5** - same trait, same API. All filter / form / column classes used by the trait exist in identical namespaces across versions.
- Publishable config (`auto-filters.php`) for date format, select multi/searchable defaults, and search input placeholder.
