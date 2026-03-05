<?php

namespace Bunce\LaravelDoctor\Scanners\Checks;

use Bunce\LaravelDoctor\Domain\Issue;
use Bunce\LaravelDoctor\Domain\IssueCategory;
use Bunce\LaravelDoctor\Domain\IssueSeverity;
use Bunce\LaravelDoctor\Scanners\AbstractPathScanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Throwable;

final class DatabaseAdvancedScanner extends AbstractPathScanner
{
    public function label(): string
    {
        return 'performance';
    }

    /** @param array<string, mixed> $config
     * @return array<int, Issue>
     */
    public function scan(array $paths, array $config): array
    {
        $issues = [];
        $extensions = $this->normalizeExtensions($config['extensions'] ?? ['php']);
        $files = $this->gatherPhpFiles($paths, $extensions);
        $maxIssuesPerFile = 25;
        if (is_array($config['index_checks'] ?? null) && is_numeric($config['index_checks']['max_issues_per_file'] ?? null)) {
            $maxIssuesPerFile = (int) $config['index_checks']['max_issues_per_file'];
        }

        foreach ($files as $file) {
            $lineNumber = 0;
            $issued = 0;
            foreach ($this->readLines($file) as $line) {
                $lineNumber++;
                if ($issued >= $maxIssuesPerFile) {
                    break;
                }

                if (! preg_match('/\b([A-Z][A-Za-z0-9_\\\\]*|DB)::(?:table\(\s*[\'"]([^\'"]+)[\'"]\s*\)|query\(\)|where\(|orderBy\(|groupBy\()/', $line, $firstMatch)) {
                    continue;
                }

                $table = '';
                if (($firstMatch[1] ?? '') === 'DB') {
                    $table = is_string($firstMatch[2] ?? null) ? trim($firstMatch[2]) : '';
                } elseif (is_string($firstMatch[1] ?? null)) {
                    $table = Str::plural(Str::snake(Str::afterLast((string) $firstMatch[1], '\\')));
                }
                if ($table === '') {
                    continue;
                }

                $columns = $this->extractIndexedColumns($line);
                if (count($columns) < 2) {
                    continue;
                }

                $indexes = $this->loadIndexesForTable($table);
                if ($indexes === null) {
                    $this->addIssue($issues, new Issue(
                        category: IssueCategory::PERFORMANCE,
                        severity: IssueSeverity::LOW,
                        rule: 'index-metadata-fallback',
                        message: 'Database metadata unavailable; index analysis used migration fallback.',
                        file: $file,
                        line: $lineNumber,
                        recommendation: 'Run doctor in an environment with live DB metadata for stronger index validation.',
                        code: trim($line),
                    ));
                    $issued++;
                    continue;
                }

                if ($this->hasCompositeIndexPrefix($indexes, $columns)) {
                    continue;
                }

                $first = $columns[0];
                $second = $columns[1];
                $this->addIssue($issues, new Issue(
                    category: IssueCategory::PERFORMANCE,
                    severity: IssueSeverity::HIGH,
                    rule: 'missing-composite-index',
                    message: sprintf('Likely missing composite index on %s (%s, %s).', $table, $first, $second),
                    file: $file,
                    line: $lineNumber,
                    recommendation: sprintf('Consider: CREATE INDEX idx_%s_%s_%s ON %s (%s, %s);', $table, $first, $second, $table, $first, $second),
                    code: trim($line),
                ));
                $issued++;
            }
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function extractIndexedColumns(string $line): array
    {
        $results = [];
        if (preg_match_all('/(?:->|::)\s*(?:where|orWhere|orderBy|groupBy)\s*\(\s*[\'"]([A-Za-z0-9_\.]+)[\'"]/i', $line, $matches)) {
            foreach ($matches[1] as $column) {
                $normalized = trim((string) $column);
                if (str_contains($normalized, '.')) {
                    $parts = explode('.', $normalized);
                    $normalized = (string) end($parts);
                }
                if ($normalized !== '' && ! in_array($normalized, $results, true)) {
                    $results[] = $normalized;
                }
            }
        }

        return $results;
    }

    /**
     * @param list<list<string>> $indexes
     * @param list<string> $columns
     */
    private function hasCompositeIndexPrefix(array $indexes, array $columns): bool
    {
        foreach ($indexes as $indexColumns) {
            if (count($indexColumns) < 2) {
                continue;
            }

            $a = strtolower($indexColumns[0] ?? '');
            $b = strtolower($indexColumns[1] ?? '');
            if ($a === strtolower($columns[0]) && $b === strtolower($columns[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<list<string>>|null
     */
    private function loadIndexesForTable(string $table): ?array
    {
        try {
            $driver = DB::getDriverName();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $rows = DB::select(sprintf('SHOW INDEX FROM `%s`', str_replace('`', '``', $table)));
                $indexes = [];
                foreach ($rows as $row) {
                    $row = (array) $row;
                    $name = (string) ($row['Key_name'] ?? '');
                    $column = (string) ($row['Column_name'] ?? '');
                    $position = isset($row['Seq_in_index']) && is_numeric($row['Seq_in_index']) ? (int) $row['Seq_in_index'] : 0;
                    if ($name === '' || $column === '' || $position < 1) {
                        continue;
                    }
                    $indexes[$name][$position - 1] = $column;
                }

                return array_values(array_map(static function (array $values): array {
                    ksort($values);

                    return array_values($values);
                }, $indexes));
            }

            if ($driver === 'pgsql') {
                $rows = DB::select('SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?', [$table]);
                $indexes = [];
                foreach ($rows as $row) {
                    $definition = (string) (((array) $row)['indexdef'] ?? '');
                    if ($definition === '' || ! preg_match('/\((.*)\)/', $definition, $match)) {
                        continue;
                    }

                    $columns = [];
                    foreach (explode(',', (string) $match[1]) as $column) {
                        $column = trim((string) preg_replace('/\s+(ASC|DESC)$/i', '', trim($column)), "\"`' ");
                        if ($column !== '' && ! str_contains($column, '(')) {
                            $columns[] = str_contains($column, '.') ? (string) explode('.', $column)[1] : $column;
                        }
                    }
                    if ($columns !== []) {
                        $indexes[] = $columns;
                    }
                }

                return $indexes;
            }

            if ($driver === 'sqlite') {
                $indexRows = DB::select("PRAGMA index_list('".str_replace("'", "''", $table)."')");
                $indexes = [];
                foreach ($indexRows as $indexRow) {
                    $indexName = (string) (((array) $indexRow)['name'] ?? '');
                    if ($indexName === '') {
                        continue;
                    }
                    $columns = DB::select("PRAGMA index_info('".str_replace("'", "''", $indexName)."')");
                    $ordered = [];
                    foreach ($columns as $column) {
                        $columnData = (array) $column;
                        $seq = isset($columnData['seqno']) && is_numeric($columnData['seqno']) ? (int) $columnData['seqno'] : null;
                        $name = (string) ($columnData['name'] ?? '');
                        if ($seq !== null && $name !== '') {
                            $ordered[$seq] = $name;
                        }
                    }
                    if ($ordered !== []) {
                        ksort($ordered);
                        $indexes[] = array_values($ordered);
                    }
                }

                return $indexes;
            }
        } catch (Throwable) {
            $fallback = $this->loadIndexesFromMigrations($table);
            return $fallback === [] ? null : $fallback;
        }

        $fallback = $this->loadIndexesFromMigrations($table);

        return $fallback === [] ? null : $fallback;
    }

    /**
     * @return list<list<string>>
     */
    private function loadIndexesFromMigrations(string $table): array
    {
        $migrationPath = base_path('database/migrations');
        if (! is_dir($migrationPath)) {
            return [];
        }

        $finder = new Finder;
        $finder->files()->in($migrationPath)->name('*.php')->ignoreDotFiles(true)->ignoreVcs(true);

        $indexes = [];
        foreach ($finder as $migration) {
            $contents = file_get_contents($migration->getRealPath());
            if (! is_string($contents) || $contents === '') {
                continue;
            }

            $tablePattern = sprintf('/Schema::(?:create|table)\(\s*[\'"]%s[\'"]\s*,\s*function\s*\([^)]+\)\s*\{([\s\S]*?)\}\s*\);/i', preg_quote($table, '/'));
            if (! preg_match_all($tablePattern, $contents, $blocks)) {
                continue;
            }

            foreach ($blocks[1] as $block) {
                if (preg_match_all('/->\s*(?:index|unique|primary)\s*\(\s*\[([^\]]+)\]/i', (string) $block, $arrays)) {
                    foreach ($arrays[1] as $columnsList) {
                        $cols = [];
                        if (preg_match_all('/[\'"]([A-Za-z0-9_]+)[\'"]/', (string) $columnsList, $parts)) {
                            foreach ($parts[1] as $column) {
                                $cols[] = (string) $column;
                            }
                        }
                        if ($cols !== []) {
                            $indexes[] = $cols;
                        }
                    }
                }

                if (preg_match_all('/->\w+\(\s*[\'"]([A-Za-z0-9_]+)[\'"][^\n;]*->\s*(?:index|unique|primary)\s*\(/i', (string) $block, $singleCols)) {
                    foreach ($singleCols[1] as $singleColumn) {
                        $indexes[] = [(string) $singleColumn];
                    }
                }
            }
        }

        return $indexes;
    }
}
