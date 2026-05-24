# Changelog

All notable changes to `ptplugins/filament-auto-filters` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-05-24

### Changed
- **DateRange filter** now renders as two stacked rows (`Date from`, `Date until`) with inline labels on both axes, replacing the previous single row with one inline label and one hidden-label datepicker. Yields uniform vertical rhythm in slide-over filter panels and removes the asymmetric "label only on first input" look.
- **TernaryFilter** (boolean `IconColumn` auto-filter) now applies `inlineLabel()` to its form field via `modifyFormFieldUsing`. Previously the label rendered above the select; now it sits inline with the other auto-filters.

### Removed
- `auto-filters.date_filter_columns` config key is no longer read (was used to set the grid column count for the date range filter). The DateRange filter now uses default vertical form layout. Existing config files can keep the key — it's silently ignored.

## [1.0.1] - 2026-05-07

### Changed
- Added `filament-hidden` class to the README hero image so it doesn't duplicate the listing banner on the filamentphp.com plugin page. The image still renders normally on GitHub.

## [1.0.0] - 2026-05-04

### Added
- Initial release.
- `HasAutoFilters` trait for Filament Resource classes.
- `static::autoFilters($table, overrides: [], skip: [])` — generates filters from the table's column definitions.
- Auto-detection rules:
  - `TextColumn` (date / datetime via Filament's `isDate()` / `isDateTime()`) → date range picker (from / until)
  - `TextColumn` (default) → text search (`LIKE %...%`)
  - `IconColumn->boolean()` → ternary (Yes / No / All)
  - `SelectColumn` with `->options([...])` → select filter using the same options
  - Dot-notation `rel.col` → `whereRelation()` query
  - Dot-notation `data.X` → JSON arrow query (`data->X`)
- Helpers exposed for manual use:
  - `makeTextFilter(name, label)` — text search filter
  - `makeDateRangeFilter(name, label)` — date range filter
  - `makeSelectFilter(name, label, options)` — select filter
  - `makeTernaryFilter(name, label)` — ternary boolean filter
  - `resolveColumn(name)` — column → query metadata resolver
- Single codebase across **Filament 3, 4, and 5** — same trait, same API. All filter / form / column classes used by the trait exist in identical namespaces across versions.
- Publishable config (`auto-filters.php`) for date format, select multi/searchable defaults, and search input placeholder.
