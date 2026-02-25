# Laravel Doctor

Laravel Doctor scans a Laravel application for practical quality issues across security, performance, correctness, and architecture.

## Requirements
- Laravel 10.x, 11.x, or 12.x
- PHP 8.2+

## Installation

### via Composer path repository (local dev)

Add this package in your application's `composer.json` and run composer update, then register service provider auto-discovery will wire the command.

## Usage

```
php artisan doctor:scan
php artisan doctor:scan --category=performance
php artisan doctor:scan --json > doctor-report.json
php artisan doctor:scan --min-severity=medium
```

## Scoring

Each scan starts from `100` and subtracts weighted points for findings.
Weights are configurable in `config/doctor.php`.

## Database index checks (performance)

Laravel Doctor now validates likely database query patterns against real index metadata on MySQL/MariaDB, PostgreSQL, and SQLite.

It flags places where common filtering and ordering columns may be missing supporting indexes and suggests a migration snippet.

It attempts to inspect:

- `DB::table('users')` filter/order/group chains
- Common `Model::where(...)` and `Model::query()->where(...)` patterns

Config options:

```
index_checks => [
    'enabled' => true,
    'max_issues_per_file' => 25,
],
```

If metadata cannot be inspected (no DB connection, unsupported driver), Laravel Doctor emits a low severity issue with a single actionable note.

Database quality checks also flag common MySQL anti-patterns in query strings:

- leading wildcard `LIKE` predicates
- oversized literal `IN` lists
- `select('*')` usage in query chains
- non-sargable `whereRaw` function calls (e.g. `DATE(...)`, `LOWER(...)`, `CONCAT(...)`)
- `orderByRaw('RAND()')`/`orderByRaw('random()')`

Config:

```
database_checks => [
    'enabled' => true,
    'max_in_list_items' => 20,
],
```

## Memory growth checks (performance)

Laravel Doctor heuristically detects potential memory retention patterns inside loops, such as repeated appends to arrays that can grow without bound.

Config options:

```
performance => [
    'memory_growth_threshold_per_loop' => 20,
],
```

Lower the threshold for stricter scanning in memory-sensitive code paths.

## Config

Publish config:

```
php artisan vendor:publish --tag=doctor-config
```

Then edit `config/doctor.php`.
