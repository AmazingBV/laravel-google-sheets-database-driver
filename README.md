# Laravel Google Sheets DBAL

Treat a Google Sheets spreadsheet as a Laravel database connection.

## Install

```bash
composer require amazingnl/laravel-google-sheets-dbal
```

## Environment

```env
DB_CONNECTION=google-sheets
DB_DATABASE=your-google-spreadsheet-id
GOOGLE_SHEETS_CREDENTIALS_PATH=/absolute/path/to/service-account.json
GOOGLE_SHEETS_CACHE_STORE=null
GOOGLE_SHEETS_CACHE_TTL=60
```

Share the target spreadsheet with the service account email from the JSON credentials file.

## What It Does

- Spreadsheet = database
- Sheet tab = table
- First row = column names
- Required `id` column on every table
- Standard Laravel integration through `DB`, Eloquent and `Schema`

## Commands

```bash
php artisan sheets:install
php artisan migrate --database=google-sheets
```

`sheets:install` validates access, creates the hidden metadata sheets, and syncs existing tabs into package metadata.

## Supported Query Subset

- `select`, `where`, `orWhere`, `whereIn`, `whereNull`, `whereLike`
- `orderBy`, `limit`, `offset`, `first`, `find`, `get`, `pluck`
- `count`, `min`, `max`, `avg`, `sum`
- `insert`, `insertGetId`, `update`, `delete`
- simple `inner join` evaluated in memory

Unsupported operations throw explicit exceptions, including raw SQL, unions, grouped queries, and transactions.

## Schema Support

- `Schema::create`, `drop`, `rename`
- add, rename and drop columns
- supported types: `id`, `string`, `text`, `integer`, `float`, `boolean`, `date`, `dateTime`, `timestamp`, `json`, `uuid`, `ulid`
- `timestamps()` and `softDeletes()`

Indexes, foreign keys and relational constraints are intentionally unsupported.
