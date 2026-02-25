<?php

namespace Bunce\LaravelDoctor\Scanners\Checks;

use Bunce\LaravelDoctor\Domain\Issue;
use Bunce\LaravelDoctor\Domain\IssueCategory;
use Bunce\LaravelDoctor\Domain\IssueSeverity;
use Bunce\LaravelDoctor\Scanners\AbstractPathScanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class DatabaseScanner extends AbstractPathScanner
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
        $indexChecksConfig = is_array($config['index_checks'] ?? null) ? $config['index_checks'] : [];
        $databaseChecksConfig = is_array($config['database_checks'] ?? null) ? $config['database_checks'] : [];
        $extensions = $this->normalizeExtensions($config['extensions'] ?? ['php']);

        $indexChecksEnabled = (bool) ($indexChecksConfig['enabled'] ?? true);
        $queryChecksEnabled = (bool) ($databaseChecksConfig['enabled'] ?? true);

        if (! $indexChecksEnabled && ! $queryChecksEnabled) {
            return [];
        }

        /** @var array<int, Issue> $issues */
        $issues = [];
        $files = $this->gatherPhpFiles($paths, $extensions);
        $maxIssuesPerFile = isset($indexChecksConfig['max_issues_per_file']) && is_numeric($indexChecksConfig['max_issues_per_file']) ? (int) $indexChecksConfig['max_issues_per_file'] : 25;
        $maxInListItems = isset($databaseChecksConfig['max_in_list_items']) && is_numeric($databaseChecksConfig['max_in_list_items']) ? (int) $databaseChecksConfig['max_in_list_items'] : 20;

        /** @var array<string, list<list<string>>|null> $indexCache */
        $indexCache = [];
        /** @var array<string, string> $catalogErrors */
        $catalogErrors = [];

        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            if ($lines === []) {
                continue;
            }

            $issuedCount = 0;
            /** @var array<string, true> $issuedKeys */
            $issuedKeys = [];

            foreach ($lines as $lineNumber => $line) {
                if ($issuedCount >= $maxIssuesPerFile) {
                    break;
                }

                $lineNumber = $lineNumber + 1;

                if ($indexChecksEnabled) {
                    $this->scanDbFacadeChains(
                        $line,
                        $lineNumber,
                        $file,
                        $issues,
                        $issuedCount,
                        $issuedKeys,
                        $indexCache,
                        $catalogErrors
                    );

                    $this->scanEloquentStaticQueries(
                        $line,
                        $lineNumber,
                        $file,
                        $issues,
                        $issuedCount,
                        $issuedKeys,
                        $indexCache,
                        $catalogErrors
                    );
                }

                if (! $queryChecksEnabled) {
                    continue;
                }

                $this->scanQueryQualityPatterns(
                    $line,
                    $lineNumber,
                    $file,
                    $issues,
                    $issuedCount,
                    $issuedKeys,
                    $maxInListItems
                );
            }
        }

        return $issues;
    }

    /** @param array<int, Issue> $issues
     * @param  array<string, true>  $issuedKeys
     */
    private function scanQueryQualityPatterns(string $line, int $lineNumber, string $file, array &$issues, int &$issuedCount, array &$issuedKeys, int $maxInListItems): void
    {
        $this->checkLeadingWildcardLike($line, $lineNumber, $file, $issues, $issuedCount, $issuedKeys);
        $this->checkLargeInList($line, $lineNumber, $file, $issues, $issuedCount, $issuedKeys, $maxInListItems);
        $this->checkSelectStar($line, $lineNumber, $file, $issues, $issuedCount, $issuedKeys);
        $this->checkNonSargableFunctionFilters($line, $lineNumber, $file, $issues, $issuedCount, $issuedKeys);
        $this->checkRandomOrdering($line, $lineNumber, $file, $issues, $issuedCount, $issuedKeys);
    }

    /** @param array<int, Issue> $issues
     * @param  array<string, true>  $issuedKeys
     * @param  array<string, list<list<string>>|null>  $indexCache
     * @param  array<string, string>  $catalogErrors
     */
    private function scanDbFacadeChains(string $line, int $lineNumber, string $file, array &$issues, int &$issuedCount, array &$issuedKeys, array &$indexCache, array &$catalogErrors): void
    {
        if (! preg_match_all('/DB::table\(\s*[\"\']([^\\"\']+)[\"\']\s*\)([^;\n]*)/i', $line, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $table = $this->resolveTableName($match[1]);
            $chain = (string) $match[2];

            if (! $this->isLikelyTable($table)) {
                continue;
            }

            foreach ($this->extractOperationsFromChain($chain) as $operation) {
                $this->reportMissingIndex($table, $operation['column'], $operation['operation'], $file, $lineNumber, trim($line), $issues, $issuedCount, $issuedKeys, $indexCache, $catalogErrors);
            }
        }
    }

    /** @param array<int, Issue> $issues
     * @param  array<string, true>  $issuedKeys
     * @param  array<string, list<list<string>>|null>  $indexCache
     * @param  array<string, string>  $catalogErrors
     */
    private function scanEloquentStaticQueries(string $line, int $lineNumber, string $file, array &$issues, int &$issuedCount, array &$issuedKeys, array &$indexCache, array &$catalogErrors): void
    {
        $pattern = '/\b([A-Z][A-Za-z0-9_\\\\]*)::(?:query\(\)\s*->\s*)?(where(?:NotBetween|NotIn|Between|In)?|orWhere(?:NotBetween|NotIn|Between|In)?|orderBy|groupBy)\s*\(\s*[\'"]([^\'"]+)[\'"]/i';

        if (! preg_match_all($pattern, $line, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $modelClass = (string) $match[1];
            $method = strtolower((string) $match[2]);
            $column = $this->normalizeIdentifier((string) $match[3]);

            if ($column === '') {
                continue;
            }

            $operation = str_starts_with($method, 'orderby') ? 'orderBy' : (str_starts_with($method, 'groupby') ? 'groupBy' : 'where');
            $table = $this->modelToTable($modelClass);

            if ($table === '') {
                continue;
            }

            $this->reportMissingIndex($table, $column, $operation, $file, $lineNumber, trim($line), $issues, $issuedCount, $issuedKeys, $indexCache, $catalogErrors);
        }
    }

    /** @param array<int, Issue> $issues
     * @param array<string, true> $issuedKeys */
    private function checkLeadingWildcardLike(string $line, int $lineNumber, string $file, array &$issues, int &$issuedCount, array &$issuedKeys): void
    {
        if (! preg_match('/->\s*(?:where|orWhere|whereNot|orWhereNot)\s*\(\s*[\"\'][^\"\']+[\"\']\s*,\s*[\'"]\s*like\s*[\'"]\s*,\s*[\'"]\s*%/i', $line)) {
            return;
        }

        $issueKey = sprintf('leading-wildcard-like|%s|%d', $file, $lineNumber);
        if (isset($issuedKeys[$issueKey])) {
            return;
        }

        $this->addIssue($issues, new Issue(
            category: IssueCategory::PERFORMANCE,
            severity: IssueSeverity::MEDIUM,
            rule: 'leading-wildcard-like',
            message: 'Leading wildcard LIKE pattern prevents index usage for prefix searches.',
            file: $file,
            line: $lineNumber,
            recommendation: 'Prefer suffix wildcards or full-text search / trigram indexes for prefix matching.',
            code: trim($line),
        ));

        $issuedCount++;
        $issuedKeys[$issueKey] = true;
    }

    /** @param array<int, Issue> $issues
     * @param array<string, true> $issuedKeys */
    private function checkLargeInList(string $line, int $lineNumber, string $file, array &$issues, int &$issuedCount, array &$issuedKeys, int $maxItems): void
    {
        if (! preg_match('/->\s*(?:where|orWhere)(?:Not)?In\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*\[(.*?)\]\s*\)/i', $line, $matches)) {
            return;
        }

        $values = trim((string) $matches[1]);
        if ($values === '') {
            return;
        }

        $listItems = preg_split('/\s*,\s*/', $values);
        if ($listItems === false) {
            return;
        }
        if (count($listItems) <= $maxItems) {
            return;
        }

        $issueKey = sprintf('large-in-list|%s|%d', $file, $lineNumber);
        if (isset($issuedKeys[$issueKey])) {
            return;
        }

        $this->addIssue($issues, new Issue(
            category: IssueCategory::PERFORMANCE,
            severity: IssueSeverity::MEDIUM,
            rule: 'large-in-list',
            message: sprintf('Large literal IN list (%d items) may bypass index planner optimizations.', count($listItems)),
            file: $file,
            line: $lineNumber,
            recommendation: 'Load IDs into a temp table/CTE or use staged joins for large membership checks.',
            code: trim($line),
        ));

        $issuedCount++;
        $issuedKeys[$issueKey] = true;
    }

    /** @param array<int, Issue> $issues
     * @param array<string, true> $issuedKeys */
    private function checkSelectStar(string $line, int $lineNumber, string $file, array &$issues, int &$issuedCount, array &$issuedKeys): void
    {
        if (! str_contains($line, 'select')) {
            return;
        }

        if (! preg_match('/->\s*select\s*\(\s*[\'"]\s*\*\s*[\'"]\s*\)/i', $line)) {
            return;
        }

        $issueKey = sprintf('select-star|%s|%d', $file, $lineNumber);
        if (isset($issuedKeys[$issueKey])) {
            return;
        }

        $this->addIssue($issues, new Issue(
            category: IssueCategory::PERFORMANCE,
            severity: IssueSeverity::LOW,
            rule: 'select-star',
            message: 'SELECT * can increase I/O and hurt index-only query potential.',
            file: $file,
            line: $lineNumber,
            recommendation: 'Select only required columns in hot paths to reduce memory and improve covering-index opportunities.',
            code: trim($line),
        ));

        $issuedCount++;
        $issuedKeys[$issueKey] = true;
    }

    /** @param array<int, Issue> $issues
     * @param array<string, true> $issuedKeys */
    private function checkNonSargableFunctionFilters(string $line, int $lineNumber, string $file, array &$issues, int &$issuedCount, array &$issuedKeys): void
    {
        if (! str_contains($line, 'whereRaw')) {
            return;
        }

        if (! preg_match('/->\s*whereRaw\s*\(\s*[\'"][^\'"]*\b(DATE|YEAR|MONTH|DAY|HOUR|LOWER|UPPER|CAST|COALESCE|IFNULL|JSON_EXTRACT|CONCAT|SUBSTRING)\s*\(/i', $line)) {
            return;
        }

        $issueKey = sprintf('non-sargable-function-filter|%s|%d', $file, $lineNumber);
        if (isset($issuedKeys[$issueKey])) {
            return;
        }

        $this->addIssue($issues, new Issue(
            category: IssueCategory::PERFORMANCE,
            severity: IssueSeverity::MEDIUM,
            rule: 'non-sargable-function-filter',
            message: 'Function call in filter expression can disable index usage.',
            file: $file,
            line: $lineNumber,
            recommendation: 'Rewrite predicates to compare raw column values or add generated columns/indexes where needed.',
            code: trim($line),
        ));

        $issuedCount++;
        $issuedKeys[$issueKey] = true;
    }

    /** @param array<int, Issue> $issues
     * @param array<string, true> $issuedKeys */
    private function checkRandomOrdering(string $line, int $lineNumber, string $file, array &$issues, int &$issuedCount, array &$issuedKeys): void
    {
        if (! preg_match('/->\s*orderByRaw\s*\(\s*[\'"][^\'"]*rand\s*\(/i', $line)) {
            return;
        }

        $issueKey = sprintf('random-ordering|%s|%d', $file, $lineNumber);
        if (isset($issuedKeys[$issueKey])) {
            return;
        }

        $this->addIssue($issues, new Issue(
            category: IssueCategory::PERFORMANCE,
            severity: IssueSeverity::MEDIUM,
            rule: 'random-ordering',
            message: 'ORDER BY RAND()/random() forces full table evaluation and is expensive.',
            file: $file,
            line: $lineNumber,
            recommendation: 'Use random key sampling or precomputed shuffle keys for large result sets.',
            code: trim($line),
        ));

        $issuedCount++;
        $issuedKeys[$issueKey] = true;
    }

    /** @return list<array{operation:string,column:string}> */
    private function extractOperationsFromChain(string $chain): array
    {
        $operations = [];
        $patterns = [
            'where' => '/->\s*(?:where|whereIn|whereNotIn|whereBetween|whereNotBetween|orWhere|orWhereIn|orWhereNotIn|orWhereBetween|orWhereNotBetween)\s*\(\s*[\"\']([^\"\']+)[\"\']/i',
            'orderBy' => '/->\s*orderBy\s*\(\s*[\"\']([^\"\']+)[\"\']/i',
            'groupBy' => '/->\s*groupBy\s*\(\s*[\"\']([^\"\']+)[\"\']/i',
            'join' => '/->\s*join(?:Left|Right|Full|Cross)?\s*\(\s*[\"\']([^\"\']+)[\"\']\s*,\s*[\"\']([^\"\']+)[\"\']\s*,\s*[\"\']=[\"\']\s*,\s*[\"\']([^\"\']+)[\"\']/i',
        ];

        foreach ($patterns as $label => $pattern) {
            if (! preg_match_all($pattern, $chain, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                if ($label === 'join') {
                    if (! isset($match[2])) {
                        continue;
                    }

                    $joinColumn = $this->extractColumnFromExpression((string) $match[2]);
                    $joinTable = $this->resolveTableName((string) $match[1]);

                    if ($joinColumn !== '' && $this->isLikelyTable($joinTable)) {
                        $operations[] = [
                            'operation' => 'where',
                            'column' => $joinColumn,
                        ];
                    }

                    continue;
                }

                $operations[] = [
                    'operation' => $label,
                    'column' => $this->normalizeIdentifier($match[1]),
                ];
            }
        }

        return $operations;
    }

    private function extractColumnFromExpression(string $expression): string
    {
        if (str_contains($expression, '.')) {
            $parts = explode('.', $expression, 2);

            return $this->normalizeIdentifier($parts[1] ?? $expression);
        }

        return $this->normalizeIdentifier($expression);
    }

    /** @param array<int, Issue> $issues
     * @param  array<string, true>  $issuedKeys
     * @param  array<string, list<list<string>>|null>  $indexCache
     * @param  array<string, string>  $catalogErrors
     */
    private function reportMissingIndex(string $table, string $column, string $operation, string $file, int $line, string $code, array &$issues, int &$issuedCount, array &$issuedKeys, array &$indexCache, array &$catalogErrors): void
    {
        $column = $this->normalizeColumn($column);
        if ($column === '' || $table === '') {
            return;
        }

        $issueKey = sprintf('%s|%s|%s|%s|%d', $table, $operation, $column, $file, $line);
        if (isset($issuedKeys[$issueKey])) {
            return;
        }

        $indexes = $this->loadIndexesForTable($table, $indexCache, $catalogErrors);

        if ($indexes === null) {
            $errorKey = sprintf('metadata:%s', $table);
            if (! isset($catalogErrors[$errorKey])) {
                $this->addIssue($issues, new Issue(
                    category: IssueCategory::PERFORMANCE,
                    severity: IssueSeverity::LOW,
                    rule: 'index-metadata-unavailable',
                    message: 'Could not inspect index metadata for this table.',
                    file: $file,
                    line: $line,
                    recommendation: 'Run the scanner where database metadata is readable (DB connection).',
                    code: $table,
                ));

                $issuedCount++;
                $issuedKeys[$issueKey] = true;
                $catalogErrors[$errorKey] = 'missing-metadata';
            }

            return;
        }

        if (! $this->isIndexed($indexes, $column, $operation)) {
            $this->addIssue($issues, new Issue(
                category: IssueCategory::PERFORMANCE,
                severity: str_starts_with($operation, 'order') ? IssueSeverity::MEDIUM : IssueSeverity::HIGH,
                rule: str_starts_with($operation, 'order') || str_starts_with($operation, 'group') ? 'missing-index-for-ordering' : 'missing-index-for-filter',
                message: sprintf('Column %s on %s may not be backed by an index for %s operations.', $column, $table, $operation),
                file: $file,
                line: $line,
                recommendation: sprintf('Add an index: CREATE INDEX idx_%s_%s ON %s (%s);', $table, $column, $table, $column),
                code: $code,
            ));

            $issuedCount++;
            $issuedKeys[$issueKey] = true;
        }
    }

    /** @param list<list<string>> $indexes */
    private function isIndexed(array $indexes, string $column, string $operation): bool
    {
        $column = strtolower($column);

        foreach ($indexes as $indexColumns) {
            if (empty($indexColumns)) {
                continue;
            }

            $normalized = array_map('strtolower', array_filter($indexColumns, static fn (string $value): bool => $value !== ''));
            if ($normalized === []) {
                continue;
            }

            if (in_array($column, $normalized, true)) {
                return true;
            }

            if ((str_starts_with($operation, 'order') || str_starts_with($operation, 'group')) && ($normalized[0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, list<list<string>>|null> $indexCache
     * @param  array<string, string>  $catalogErrors
     * @return list<list<string>>|null
     */
    private function loadIndexesForTable(string $table, array &$indexCache, array &$catalogErrors): ?array
    {
        if (array_key_exists($table, $indexCache)) {
            return $indexCache[$table];
        }

        try {
            if (! $this->isLikelyTable($table)) {
                $indexCache[$table] = [];

                return [];
            }

            $driver = DB::getDriverName();

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $indexCache[$table] = $this->loadMySqlIndexes($table);

                return $indexCache[$table];
            }

            if ($driver === 'pgsql') {
                $indexCache[$table] = $this->loadPostgresIndexes($table);

                return $indexCache[$table];
            }

            if ($driver === 'sqlite') {
                $indexCache[$table] = $this->loadSqliteIndexes($table);

                return $indexCache[$table];
            }

            $catalogErrors['driver'] = sprintf('Unsupported database driver: %s', $driver);
            $indexCache[$table] = null;

            return null;
        } catch (Throwable $exception) {
            $catalogErrors['exception'] = $exception->getMessage();
            $indexCache[$table] = null;

            return null;
        }
    }

    /** @return list<list<string>> */
    private function loadMySqlIndexes(string $table): array
    {
        $rows = DB::select(sprintf('SHOW INDEX FROM `%s`', str_replace('`', '``', $table)));
        /** @var list<object> $rows */
        $indexes = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            if (! isset($row['Key_name']) || ! is_string($row['Key_name']) || $row['Key_name'] === '') {
                continue;
            }
            if (! isset($row['Column_name']) || ! is_string($row['Column_name']) || $row['Column_name'] === '') {
                continue;
            }
            if (! isset($row['Seq_in_index']) || ! is_numeric($row['Seq_in_index'])) {
                continue;
            }

            $name = $row['Key_name'];
            $column = $row['Column_name'];
            $position = (int) $row['Seq_in_index'];

            if (! isset($indexes[$name])) {
                $indexes[$name] = [];
            }

            $indexes[$name][$position - 1] = $column;
        }

        return array_values(array_map(static function (array $columns) {
            ksort($columns);

            return array_values($columns);
        }, $indexes));
    }

    /** @return list<list<string>> */
    private function loadSqliteIndexes(string $table): array
    {
        $indexRows = DB::select("PRAGMA index_list('".str_replace("'", "''", $table)."')");
        /** @var list<object> $indexRows */
        if (empty($indexRows)) {
            return [];
        }

        $indexes = [];
        foreach ($indexRows as $indexRow) {
            $indexRow = (array) $indexRow;
            if (! isset($indexRow['name']) || ! is_string($indexRow['name']) || $indexRow['name'] === '') {
                continue;
            }
            $indexName = $indexRow['name'];

            $columns = DB::select("PRAGMA index_info('".str_replace("'", "''", $indexName)."')");
            /** @var list<object> $columns */
            $ordered = [];
            foreach ($columns as $column) {
                $column = (array) $column;
                if (! isset($column['seqno']) || ! is_numeric($column['seqno'])) {
                    continue;
                }
                if (! isset($column['name']) || ! is_string($column['name'])) {
                    continue;
                }

                $seq = (int) $column['seqno'];
                $name = $column['name'];
                if ($name !== '') {
                    $ordered[$seq] = $name;
                }
            }

            if (! empty($ordered)) {
                ksort($ordered);
                $indexes[] = array_values($ordered);
            }
        }

        return $indexes;
    }

    /** @return list<list<string>> */
    private function loadPostgresIndexes(string $table): array
    {
        $rows = DB::select('SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?', [$table]);
        /** @var list<object> $rows */
        if (empty($rows)) {
            return [];
        }

        $indexes = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            if (! isset($row['indexdef']) || ! is_string($row['indexdef'])) {
                continue;
            }
            $definition = $row['indexdef'];
            if (! preg_match('/\((.*)\)/', $definition, $matches)) {
                continue;
            }

            $rawColumns = array_map('trim', explode(',', $matches[1]));
            $normalized = [];

            foreach ($rawColumns as $column) {
                if ($column === '') {
                    continue;
                }

                $column = (string) preg_replace('/\s+(ASC|DESC)$/i', '', $column);
                $column = trim($column);

                if (str_contains($column, '(') || str_contains($column, ')')) {
                    continue;
                }

                $column = trim($column, '"');
                $parts = explode('.', $column);
                if (count($parts) > 1) {
                    $column = $parts[1];
                }

                if ($column !== '') {
                    $normalized[] = $column;
                }
            }

            if ($normalized !== []) {
                $indexes[] = $normalized;
            }
        }

        return $indexes;
    }

    private function normalizeColumn(string $column): string
    {
        return $this->normalizeIdentifier($this->extractColumnFromExpression($column));
    }

    private function normalizeIdentifier(string $identifier): string
    {
        return trim($identifier, " \"'`");
    }

    private function resolveTableName(string $table): string
    {
        $table = trim($table);
        $parts = preg_split('/\s+/', $table, -1, PREG_SPLIT_NO_EMPTY);
        $resolved = $parts[0] ?? '';

        return $this->normalizeIdentifier($resolved);
    }

    private function modelToTable(string $modelClass): string
    {
        $modelName = (string) Str::of($modelClass)->afterLast('\\');
        if ($modelName === '') {
            return '';
        }

        return Str::plural(Str::snake($modelName));
    }

    private function isLikelyTable(string $table): bool
    {
        return $table !== '' && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table) === 1;
    }
}
