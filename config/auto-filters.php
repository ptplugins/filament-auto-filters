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

];
