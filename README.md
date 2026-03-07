# Laravel Doctor

Laravel Doctor scans a Laravel application for practical quality issues across security, performance, correctness, and architecture. It is designed for local development and CI, with machine-readable output, baseline support, and configurable scoring.

## Requirements

- PHP 8.2+
- Laravel 10.x, 11.x, or 12.x

## What it checks

Laravel Doctor groups findings into four categories:

- `security`: hard-coded credentials, dangerous execution functions, raw SQL usage
- `performance`: N+1 patterns, large unbounded reads, query materialization, weak query patterns, likely missing indexes, memory growth inside loops
- `correctness`: empty catch blocks, debug artifacts, TODO/FIXME markers, nullable edge-case paths
- `architecture`: large classes, controller query logic, validation gaps, pagination gaps, facade overuse

The checks are heuristic by design. They aim to surface likely problems quickly, not prove code correctness.

## Installation

### Install into a Laravel app from a local checkout

If you are developing this package locally, add it as a Composer path repository in the Laravel application you want to scan:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../laravel-doctor"
    }
  ],
  "require-dev": {
    "bunce/laravel-doctor": "*"
  }
}
```

Then install dependencies:

```bash
composer update bunce/laravel-doctor
```

Laravel package discovery registers the service provider automatically.

### Publish configuration

```bash
php artisan vendor:publish --tag=doctor-config
```

This creates `config/doctor.php`, where scan paths, scoring weights, thresholds, cache behavior, and baseline path can be adjusted.

## Quick start

Run a full scan with the default configured paths:

```bash
php artisan doctor:scan
```

Common examples:

```bash
# Scan one category only
php artisan doctor:scan --category=performance

# Scan specific paths
php artisan doctor:scan --path=app --path=routes

# Scan only changed files in the current git working tree
php artisan doctor:scan --changed

# Scan changes since a git ref
php artisan doctor:scan --changed --since=origin/main

# Emit JSON for CI or post-processing
php artisan doctor:scan --json > doctor-report.json

# Hide low-severity issues
php artisan doctor:scan --min-severity=medium

# Fail CI if medium-or-higher findings exist
php artisan doctor:scan --fail-on-severity=medium

# Fail CI if total findings exceed a threshold
php artisan doctor:scan --max-issues=10
```

## Command options

`doctor:scan` supports the following flags:

- `--path=*`: scan specific absolute or project-relative files/directories instead of configured defaults
- `--changed`: scan only changed files from git status or from `--since`
- `--since=<ref>`: diff against a git ref such as `origin/main`
- `--category=<name>`: limit scanning to `security`, `performance`, `correctness`, or `architecture`
- `--json`: print a JSON report instead of the console table
- `--min-severity=<low|medium|high>`: filter out lower-severity findings
- `--fail-on-severity=<low|medium|high>`: return a non-zero exit code if a finding at or above that severity exists
- `--max-issues=<n>`: return a non-zero exit code if the total finding count exceeds `n`
- `--baseline=<path>`: suppress findings listed in a baseline JSON file
- `--update-baseline`: write the current findings to the configured baseline path

## Baseline workflow

Baselines let you adopt the tool on an existing codebase without blocking on historic findings.

Generate or refresh the baseline:

```bash
php artisan doctor:scan --update-baseline
```

Use a custom path if needed:

```bash
php artisan doctor:scan --baseline=storage/doctor-baseline.json --update-baseline
```

On later runs, any finding whose fingerprint already exists in the baseline file is suppressed from output.

## CI usage

Typical CI commands:

```bash
# Fail if any high severity finding exists
php artisan doctor:scan --fail-on-severity=high

# Fail if more than 5 findings remain after baseline filtering
php artisan doctor:scan --baseline=doctor-baseline.json --max-issues=5

# JSON output for upload or annotation
php artisan doctor:scan --json --fail-on-severity=medium
```

When `--json` is enabled, the command still respects exit policies.

## JSON output

JSON output includes the computed score, total issue count, and serialized findings:

```json
{
  "score": 86,
  "total_issues": 2,
  "issues": [
    {
      "category": "performance",
      "severity": "high",
      "rule": "n-plus-one-query",
      "message": "Potential N+1 query call inside loop.",
      "file": "/absolute/path/app/Http/Controllers/OrderController.php",
      "line": 18,
      "recommendation": "Eager-load needed relations before the loop (with()).",
      "code": "$order->comments()->get();"
    }
  ]
}
```

## Scoring

Each scan starts from `100` and subtracts weighted points for findings.

Default weights:

- `high`: `20`
- `medium`: `10`
- `low`: `4`

The final score is clamped between `0` and `100`. You can change both the base score and weights in `config/doctor.php`.

## Configuration

Default configuration:

```php
return [
    'paths' => [
        base_path('app'),
        base_path('routes'),
        base_path('database'),
        base_path('config'),
        base_path('resources/views'),
    ],

    'extensions' => ['php'],

    'weights' => [
        'high' => 20,
        'medium' => 10,
        'low' => 4,
    ],

    'base_score' => 100,

    'categories' => [
        'security' => true,
        'performance' => true,
        'correctness' => true,
        'architecture' => true,
    ],

    'performance' => [
        'max_file_lines' => 400,
        'n_plus_one_threshold' => 0,
        'unbounded_get_max_per_file' => 6,
        'memory_growth_threshold_per_loop' => 20,
    ],

    'index_checks' => [
        'enabled' => true,
        'max_issues_per_file' => 25,
    ],

    'database_checks' => [
        'enabled' => true,
        'max_in_list_items' => 20,
    ],

    'baseline' => [
        'path' => base_path('doctor-baseline.json'),
    ],

    'cache' => [
        'enabled' => true,
        'path' => base_path('storage/app/doctor-cache.json'),
        'parallel_categories' => true,
    ],
];
```

Key settings:

- `paths`: default directories scanned when `--path` is not provided
- `extensions`: file extensions considered by the scanners
- `weights` and `base_score`: control score calculation
- `performance.unbounded_get_max_per_file`: threshold before repeated `all()` or `get()` calls are flagged
- `performance.memory_growth_threshold_per_loop`: threshold for repeated array growth inside loops
- `index_checks.enabled`: enable index inspection against live database metadata
- `database_checks.max_in_list_items`: threshold for large literal `IN (...)` lists
- `baseline.path`: default path used by `--baseline` and `--update-baseline`
- `cache.enabled`: reuse prior scan results for unchanged files

## Database and index checks

Laravel Doctor validates likely query patterns against live index metadata on:

- MySQL / MariaDB
- PostgreSQL
- SQLite

It inspects common patterns such as:

- `DB::table('users')->where(...)->orderBy(...)`
- `Model::where(...)`
- `Model::query()->where(...)`

It also flags common query anti-patterns, including:

- leading-wildcard `LIKE` predicates
- oversized literal `IN` lists
- `select('*')`
- non-sargable function filters in raw clauses
- `orderByRaw('RAND()')` and `orderByRaw('random()')`

If database metadata is unavailable, Laravel Doctor emits a low-severity fallback issue instead of failing the scan.

## Performance notes

Performance checks cover more than query count. They also look for patterns that tend to inflate memory or produce expensive SQL plans, including:

- eager `get()` or `all()` materialization of large datasets
- `->get()->toArray()` conversions
- caching entire query result sets
- repeated array growth or `array_merge()` rebuilds inside loops

## Changed-file scans and cache behavior

- `--changed` uses git diff output plus untracked files, filtered by configured extensions
- cached results are stored at `storage/app/doctor-cache.json` by default
- category-level parallel execution is only used when `pcntl_fork()` is available and cache is disabled

## Local package development

For work on this repository itself:

```bash
composer install
composer test
composer lint
```

## Limitations

- checks are heuristic and line-oriented in many cases
- dynamic table names, dynamic query construction, and metaprogrammed behavior may not be fully understood
- index validation is strongest when the scan runs against a live database with accurate schema metadata

Laravel Doctor is best used as an early warning layer alongside tests, static analysis, profiling, and code review.
