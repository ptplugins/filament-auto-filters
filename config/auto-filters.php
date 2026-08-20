<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Date Filter Layout
    |--------------------------------------------------------------------------
    |
    | Number of columns for the date range filter form layout.
    |
    */
    'date_filter_columns' => 3,

    /*
    |--------------------------------------------------------------------------
    | Text Search Placeholder
    |--------------------------------------------------------------------------
    |
    | Default placeholder text for text search filter inputs.
    |
    */
    'text_search_placeholder' => 'Search...',

    /*
    |--------------------------------------------------------------------------
    | Date Format
    |--------------------------------------------------------------------------
    |
    | Display format for date indicators (PHP date format).
    |
    */
    'date_format' => 'd.m.Y',

    /*
    |--------------------------------------------------------------------------
    | Select Filter Defaults
    |--------------------------------------------------------------------------
    |
    | Default configuration for auto-generated select filters.
    |
    */
    'select_multiple' => true,
    'select_searchable' => true,

    /*
    |--------------------------------------------------------------------------
    | Inline Filter Labels
    |--------------------------------------------------------------------------
    |
    | When true, every auto-generated filter renders its form field with
    | `inlineLabel()` — label on the left, input on the right, one row per
    | filter. Pairs well with `filtersTriggerAction(slideOver())` for a wide,
    | Notion-style filter sidebar.
    |
    | Set to false if you keep the default Filament dropdown layout (the
    | dropdown is narrow and inline labels can look cramped there) or if you
    | prefer stacked labels above the inputs.
    |
    */
    'inline_labels' => true,

    /*
    |--------------------------------------------------------------------------
    | Prefer Pikaday for Date Filters
    |--------------------------------------------------------------------------
    |
    | When true and the optional `ptplugins/filament-pikaday` package is
    | installed, date range filters use the lightweight Pikaday date picker
    | instead of Filament's native DatePicker. Falls back to the native
    | DatePicker automatically when Pikaday is not installed. Defaults to
    | false — opt in to route date filters through Pikaday when available.
    |
    */
    'prefer_pikaday' => false,

    /*
    |--------------------------------------------------------------------------
    | Sanitize Filter Names
    |--------------------------------------------------------------------------
    |
    | Auto filters derive their Livewire/URL state key from the column name. A
    | column like `data.Ukupna naknada` (space + dot + diacritics) is an invalid
    | wire:model / HTML attribute and breaks the whole filters form in the browser.
    |
    | When true (default), such unsafe names are replaced with a safe slug
    | (`af_data_ukupna_naknada`) via `HasAutoFilters::filterName()`, while names
    | that are already valid keys (letters/digits/underscore, e.g. `product`) pass
    | through unchanged. The column name still drives the query.
    |
    | Set to false to keep the legacy raw-column-name behavior (unsafe with
    | spaced/dotted columns).
    |
    */
    'sanitize_names' => true,

    /*
    |--------------------------------------------------------------------------
    | Distinct Select Threshold
    |--------------------------------------------------------------------------
    |
    | Upper bound on the number of distinct values a column may have for
    | `autoFilters(..., distinct: ...)` to turn it into a multi-select. Above
    | it (or when the column has no values at all) the column silently keeps a
    | plain text filter, so a long list never gets a thousand-option select.
    |
    */
    'distinct_max_options' => 50,

];
