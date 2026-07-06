# Changelog

## [1.1.0] - 2026-07-06

### Fixed

- Prevented Google Sheets table writes from clearing existing data before replacement data is written.
- Serialized Google Sheets table mutations with cache-backed table locks.
- Matched SQL boolean precedence for mixed `where` and `orWhere` clauses.
- Calculated aggregate queries before applying `limit` and `offset`.
- Failed explicitly for unsupported schema changes instead of silently ignoring them.
- Treated empty physical tabs as missing DBAL tables until a valid header/schema exists.
- Recorded tables created by each migration and failed explicitly on partially missing migration-managed tables.
- Rejected raw ordering on mutating queries.
- Stopped storing full table snapshots in the persistent Laravel cache.
- Made `composer test` run without requiring a coverage driver.
- Removed the hardcoded Composer package version so Git tags define package versions.
- Pointed the README test badge at the repository default branch.

### Added

- Added repository guidance that commit messages should be written in English.
- Added regression coverage for locking, boolean precedence, aggregate slicing, schema changes, stale caches, empty tabs, migration metadata, and raw order mutations.

