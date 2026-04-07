<img src="assets/header.png" alt="Laravel Google Sheets Database Driver header" width="100%" />

# Laravel Google Sheets Database Driver

[![Latest Version on Packagist](https://img.shields.io/packagist/v/amazingbv/laravel-google-sheets-database-driver.svg?style=flat-square)](https://packagist.org/packages/amazingbv/laravel-google-sheets-database-driver)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg?style=flat-square)](LICENSE)

A Laravel database driver that uses a Google Sheets spreadsheet as its backing store.

This package lets you treat:

- one spreadsheet as a database
- each tab as a table
- the first row as the column definition

It is designed for lightweight back-office data, prototypes, operational tools, and integrations where a spreadsheet is more practical than a traditional database.

## Features

- Custom Laravel connection: `google-sheets`
- Works with `DB`, Eloquent, `Schema`, and migrations
- Keeps a visible `Database Index` tab as the first tab in the spreadsheet
- Ignores schema indexes and constraints during migrations instead of hard-failing
- Uses a hidden schema sheet and hidden migration sheet internally
- Handles Google Sheets API quotas with throttling, retries, and in-process caching
- Auto-heals common metadata drift when tabs are removed manually

## Requirements

- PHP `^8.2`
- Laravel `12` or `13`
- A Google Cloud project with the Google Sheets API enabled
- A Google service account with a downloaded JSON key
- A spreadsheet shared with that service account

## Installation

```bash
composer require amazingbv/laravel-google-sheets-database-driver
```

Laravel package discovery is enabled automatically.

## Quick Start

Add the following to your `.env`:

```env
DB_CONNECTION=google-sheets
DB_DATABASE=your-google-spreadsheet-id
GOOGLE_SHEETS_CREDENTIALS_PATH=/absolute/path/to/service-account.json

GOOGLE_SHEETS_CACHE_STORE=file
GOOGLE_SHEETS_CACHE_TTL=60

GOOGLE_SHEETS_QUOTA_RETRY_ATTEMPTS=5
GOOGLE_SHEETS_QUOTA_RETRY_BASE_DELAY_MS=1000
GOOGLE_SHEETS_QUOTA_RETRY_MAX_DELAY_MS=10000
GOOGLE_SHEETS_READ_REQUESTS_PER_MINUTE=50
GOOGLE_SHEETS_WRITE_REQUESTS_PER_MINUTE=45
```

Then run:

```bash
php artisan sheets:install
php artisan migrate --database=google-sheets
```

## How It Maps To Google Sheets

- Spreadsheet = database
- Tab = table
- Row 1 = column names
- Rows 2+ = records

The package also manages:

- `Database Index` as a visible first tab with links to the table tabs
- `__sheetsdbal_schema` as a hidden internal schema tab
- `__sheetsdbal_migrations` as a hidden internal migration-log tab

## Service Account Setup

This package uses a Google service account for server-to-server access.

The JSON file referenced by `GOOGLE_SHEETS_CREDENTIALS_PATH` is the service account key file downloaded from Google Cloud. The package uses that file to authenticate against the Google Sheets API.

In practice:

1. Create or choose a Google Cloud project.
2. Enable the Google Sheets API for that project.
3. Create a service account.
4. Create and download a JSON key for that service account.
5. Share your spreadsheet with the `client_email` from that JSON file, usually as `Editor`.
6. Store the JSON file outside your repository and point `GOOGLE_SHEETS_CREDENTIALS_PATH` to it.

Useful official Google documentation:

- [Create service accounts](https://cloud.google.com/iam/docs/service-accounts-create)
- [Best practices for managing service account keys](https://docs.cloud.google.com/iam/docs/best-practices-for-managing-service-account-keys)
- [Google Sheets API quickstart](https://developers.google.com/workspace/sheets/api/quickstart/go)

Notes:

- The `client_email` inside the JSON is the identity you share the spreadsheet with.
- Do not commit the JSON key file to Git.
- Rotate keys if a file is exposed or replaced.

## Usage

### Query Builder

```php
use Illuminate\Support\Facades\DB;

$users = DB::connection('google-sheets')
    ->table('users')
    ->where('active', true)
    ->orderBy('name')
    ->get();
```

### Eloquent

```php
class Contact extends Model
{
    protected $connection = 'google-sheets';
    protected $table = 'contacts';
    protected $guarded = [];
}
```

### Schema

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::connection('google-sheets')->create('products', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->integer('price');
    $table->timestamps();
});
```

## Supported Query Subset

The driver intentionally supports a practical subset instead of full SQL compatibility.

Supported:

- `select`
- `where`, `orWhere`
- `whereIn`, `whereNotIn`
- `whereNull`, `whereNotNull`
- `whereLike`
- `orderBy`
- `limit`, `offset`
- `first`, `find`, `get`, `pluck`
- `count`, `min`, `max`, `avg`, `sum`
- `insert`, `insertGetId`, `update`, `delete`
- simple in-memory `inner join`

Unsupported:

- raw SQL
- unions
- grouped queries / `groupBy` / `having`
- database-native transaction guarantees

## Migrations And Schema Behavior

This package tries to be migration-friendly for standard Laravel apps.

What it does:

- runs normal table creation migrations against Google Sheets
- ignores indexes and relational constraints instead of failing migrations
- keeps internal schema metadata in sync
- removes stale metadata and stale create-table migration entries when tabs were deleted manually

What it does not do:

- enforce unique constraints
- enforce foreign keys
- provide true relational integrity

So if your migration contains:

- `->unique()`
- `->index()`
- `->primary()`
- `->foreignId()->constrained()`

the migration can still run, but Google Sheets will not enforce those guarantees.

## Transactions

Laravel transaction calls are treated as no-op control flow.

That means:

- queue workers and framework internals will not crash when they call `transaction()`
- nested transaction bookkeeping still behaves sanely for Laravel
- there is no atomic commit/rollback guarantee

## Quota Handling

Google Sheets has strict API quotas. This driver includes a few protections:

- reuses sheet-directory and sheet-value reads within one connection
- throttles read and write requests per minute in the current PHP process
- retries quota-style failures with exponential backoff
- uses a configurable Laravel cache store for additional reduction in API traffic

If you still hit quotas:

- lower `GOOGLE_SHEETS_READ_REQUESTS_PER_MINUTE`
- lower `GOOGLE_SHEETS_WRITE_REQUESTS_PER_MINUTE`
- use `GOOGLE_SHEETS_CACHE_STORE=file` or `redis`
- avoid very large migration batches and write-heavy loops

## Database Index Tab

The package maintains a visible first tab named `Database Index`.

It contains:

- a formatted title in cell `A1`
- one row per table tab underneath
- clickable links to the actual tabs

This makes the spreadsheet easier to use as a navigable “database”.

## Operational Notes

- Manually editing data is supported, but changing headers or deleting tabs can invalidate metadata
- If you remove tabs manually, rerun `php artisan sheets:install` or `php artisan migrate`
- Hidden package-managed tabs should generally be left alone
- This package is best suited for small datasets and low write concurrency

## Local Development With A Path Repository

If you want to test this package from a local checkout in another Laravel app:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "/absolute/path/to/this/package",
      "options": {
        "symlink": true
      }
    }
  ],
  "require": {
    "amazingbv/laravel-google-sheets-database-driver": "^1.0"
  }
}
```

Then run:

```bash
composer update amazingbv/laravel-google-sheets-database-driver
php artisan optimize:clear
```

## Limits

This is not a replacement for MySQL or PostgreSQL.

Use it when:

- the spreadsheet itself is part of the workflow
- non-technical users need direct visibility
- the dataset is relatively small
- strong relational guarantees are not required

Do not use it when:

- you need high write throughput
- you need strict transactions
- you need database-level constraints
- you need efficient large-scale querying

## License

MIT
