# Laravel Google Sheets Database Driver

Treat a Google Sheets spreadsheet as a Laravel database connection.

## Install

```bash
composer require amazingbv/laravel-google-sheets-database-driver
```

## Environment

```env
DB_CONNECTION=google-sheets
DB_DATABASE=your-google-spreadsheet-id
GOOGLE_SHEETS_CREDENTIALS_PATH=/absolute/path/to/service-account.json
GOOGLE_SHEETS_CACHE_STORE=null
GOOGLE_SHEETS_CACHE_TTL=60
GOOGLE_SHEETS_QUOTA_RETRY_ATTEMPTS=5
GOOGLE_SHEETS_QUOTA_RETRY_BASE_DELAY_MS=1000
GOOGLE_SHEETS_QUOTA_RETRY_MAX_DELAY_MS=10000
GOOGLE_SHEETS_READ_REQUESTS_PER_MINUTE=50
GOOGLE_SHEETS_WRITE_REQUESTS_PER_MINUTE=45
```

Share the target spreadsheet with the service account email from the JSON credentials file.

## What It Does

- Spreadsheet = database
- Sheet tab = table
- First row = column names
- `id` column strongly recommended for Eloquent-style CRUD and `insertGetId()`
- Standard Laravel integration through `DB`, Eloquent and `Schema`
- A visible first tab named `Database Index` is maintained automatically with clickable links to the table tabs

## Commands

```bash
php artisan sheets:install
php artisan migrate --database=google-sheets
```

`sheets:install` validates access, creates the hidden metadata sheets, and syncs existing tabs into package metadata.

## Quota Handling

- Reuses sheet-directory and sheet-value reads within one Laravel connection
- Retries `429` and other rate-limit style Google API responses with exponential backoff
- Throttles read and write requests per minute within the current PHP process

If your project still hits quota, lower `GOOGLE_SHEETS_READ_REQUESTS_PER_MINUTE` or configure a real cache store via `GOOGLE_SHEETS_CACHE_STORE=file` or `redis`.

## Supported Query Subset

- `select`, `where`, `orWhere`, `whereIn`, `whereNull`, `whereLike`
- `orderBy`, `limit`, `offset`, `first`, `find`, `get`, `pluck`
- `count`, `min`, `max`, `avg`, `sum`
- `insert`, `insertGetId`, `update`, `delete`
- simple `inner join` evaluated in memory

Unsupported operations throw explicit exceptions, including raw SQL, unions, and grouped queries.
Schema indexes and relational constraints are ignored during migrations so standard Laravel migrations can run more easily on top of Sheets.
Transaction calls are treated as no-op Laravel control flow: they do not provide atomicity or rollback guarantees, but they also do not crash queue workers or framework internals.

## Schema Support

- `Schema::create`, `drop`, `rename`
- add, rename and drop columns
- supported types: `id`, `string`, `text`, `integer`, `float`, `boolean`, `date`, `dateTime`, `timestamp`, `json`, `uuid`, `ulid`
- `timestamps()` and `softDeletes()`

Indexes, foreign keys and relational constraints are intentionally not enforced by Google Sheets and are ignored by the driver.
