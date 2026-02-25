<?php

namespace Bunce\LaravelDoctor\Scanners\Checks;

use Bunce\LaravelDoctor\Domain\Issue;
use Bunce\LaravelDoctor\Domain\IssueCategory;
use Bunce\LaravelDoctor\Domain\IssueSeverity;
use Bunce\LaravelDoctor\Scanners\AbstractPathScanner;

final class PerformanceScanner extends AbstractPathScanner
{
    public function label(): string
    {
        return 'performance';
    }

    /** @param array<string, mixed> $config @return array<int, Issue> */
    public function scan(array $paths, array $config): array
    {
        $issues = [];
        $extensions = $this->normalizeExtensions($config['extensions'] ?? ['php']);
        $files = $this->gatherPhpFiles($paths, $extensions);
        $performanceConfig = is_array($config['performance'] ?? null) ? $config['performance'] : [];
        $unboundedGetThreshold = isset($performanceConfig['unbounded_get_max_per_file']) && is_numeric($performanceConfig['unbounded_get_max_per_file']) ? (int) $performanceConfig['unbounded_get_max_per_file'] : 6;
        $memoryGrowthThreshold = isset($performanceConfig['memory_growth_threshold_per_loop']) && is_numeric($performanceConfig['memory_growth_threshold_per_loop']) ? (int) $performanceConfig['memory_growth_threshold_per_loop'] : 20;

        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
            if ($lines === []) {
                continue;
            }

            $this->scanNPlusOneWithLoops($lines, $file, $issues);
            $this->scanUnboundedQueries($lines, $file, $issues, $unboundedGetThreshold);
            $this->scanQueryHotspots($lines, $file, $issues);
            $this->scanMemoryGrowthInLoops($lines, $file, $issues, $memoryGrowthThreshold);
            $this->scanQueryMaterializationPatterns($lines, $file, $issues);
        }

        $issues = array_merge($issues, (new DatabaseScanner)->scan($paths, $config));

        return $issues;
    }

    /** @param list<string> $lines
     * @param array<int, Issue> $issues */
    private function scanMemoryGrowthInLoops(array $lines, string $file, array &$issues, int $threshold): void
    {
        $loopDepth = 0;
        $pendingLoopWithoutBrace = false;
        $arrayPushCounts = [];

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $normalizedLine = trim($line);

            if (preg_match('/\b(foreach|for|while|do)\s*\(/', $normalizedLine)) {
                $pendingLoopWithoutBrace = ! str_contains($normalizedLine, '{');
                $loopDepth += (int) str_contains($normalizedLine, '{');
            }

            if ($pendingLoopWithoutBrace && str_contains($normalizedLine, '{')) {
                $loopDepth++;
                $pendingLoopWithoutBrace = false;
            }

            if ($loopDepth > 0) {
                if (preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\[\s*\]\s*;/', $normalizedLine, $resetMatch)) {
                    $arrayPushCounts[$resetMatch[1]] = 0;
                }

                if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*\[\]\s*=\s*/', $normalizedLine, $pushMatches)) {
                    foreach ($pushMatches[1] as $index => $variableName) {
                        $currentCount = $arrayPushCounts[$variableName] ?? 0;
                        $currentCount++;
                        $arrayPushCounts[$variableName] = $currentCount;

                        if ($currentCount === $threshold + 1) {
                            $this->addIssue($issues, new Issue(
                                category: IssueCategory::PERFORMANCE,
                                severity: IssueSeverity::MEDIUM,
                                rule: 'memory-growth-in-loop',
                                message: 'Potential memory growth from unbounded array accumulation inside a loop.',
                                file: $file,
                                line: $i + 1,
                                recommendation: 'Stream results (chunked queries, generators, LazyCollection) or persist intermediate output incrementally.',
                                code: trim($line),
                            ));

                            break 2;
                        }
                    }
                }

                if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)\s*\=\s*\w+\s*\(.*\)\s*;/', $normalizedLine, $mergeMatches)) {
                    foreach ($mergeMatches[1] as $variableName) {
                        if (str_contains($normalizedLine, '$'.$variableName.' = array_merge(')) {
                            $currentCount = $arrayPushCounts[$variableName] ?? 0;
                            $currentCount++;
                            $arrayPushCounts[$variableName] = $currentCount;

                            if ($currentCount === $threshold + 1) {
                                $this->addIssue($issues, new Issue(
                                    category: IssueCategory::PERFORMANCE,
                                    severity: IssueSeverity::MEDIUM,
                                    rule: 'memory-growth-in-loop',
                                    message: 'Potential memory growth from array merge/rebuild inside a loop.',
                                    file: $file,
                                    line: $i + 1,
                                    recommendation: 'Avoid repeated array_merge inside loops; append with [] or use generators/chunked processing.',
                                    code: trim($line),
                                ));

                                break 2;
                            }
                        }
                    }
                }

                if (preg_match('/unset\(\s*\$([A-Za-z_][A-Za-z0-9_]*)\s*\)/', $normalizedLine, $unsetMatch)) {
                    unset($arrayPushCounts[$unsetMatch[1]]);
                }
            }

            if (str_contains($normalizedLine, '}')) {
                $loopDepth = max(0, $loopDepth - substr_count($normalizedLine, '}'));
                if ($loopDepth === 0) {
                    $arrayPushCounts = [];
                    $pendingLoopWithoutBrace = false;
                }
            }
        }
    }

    /** @param list<string> $lines
     * @param array<int, Issue> $issues */
    private function scanNPlusOneWithLoops(array $lines, string $file, array &$issues): void
    {
        $loopDepth = 0;
        $pendingLoopWithoutBrace = false;
        $fileLineCount = count($lines);

        for ($i = 0; $i < $fileLineCount; $i++) {
            $line = $lines[$i];
            $normalizedLine = trim($line);

            if (preg_match('/\b(foreach|for|while|do)\s*\(/', $normalizedLine)) {
                $pendingLoopWithoutBrace = ! str_contains($normalizedLine, '{');
                $loopDepth += (int) str_contains($normalizedLine, '{');
            }

            if ($pendingLoopWithoutBrace && str_contains($normalizedLine, '{')) {
                $loopDepth++;
                $pendingLoopWithoutBrace = false;
            }

            if ($loopDepth > 0 && $this->containsDatabaseQueryInvocation($normalizedLine)) {
                $this->addIssue($issues, new Issue(
                    category: IssueCategory::PERFORMANCE,
                    severity: IssueSeverity::HIGH,
                    rule: 'n-plus-one-query',
                    message: 'Potential N+1 query call inside loop.',
                    file: $file,
                    line: $i + 1,
                    recommendation: 'Eager-load needed relations before the loop (with()).',
                    code: trim($line),
                ));
            }

            if (
                $loopDepth > 0
                && preg_match('/->\w+\s*->\s*\w+/', $normalizedLine)
                && preg_match('/\$[A-Za-z_][A-Za-z0-9_]*->(comments|posts|items|users|roles|permissions|products|orders|lineItems)/', $normalizedLine)
            ) {
                $this->addIssue($issues, new Issue(
                    category: IssueCategory::PERFORMANCE,
                    severity: IssueSeverity::MEDIUM,
                    rule: 'relation-in-loop',
                    message: 'Relation-like property access inside loop can trigger many queries.',
                    file: $file,
                    line: $i + 1,
                    recommendation: 'Use eager loading (with) on the parent query and keep data hydration in memory.',
                    code: trim($line),
                ));
            }

            $loopDepth = max(0, $loopDepth - substr_count($normalizedLine, '}'));
            if ($loopDepth === 0) {
                $pendingLoopWithoutBrace = false;
            }
        }
    }

    private function containsDatabaseQueryInvocation(string $line): bool
    {
        $needles = [
            '->get()',
            '->first()',
            '->paginate(',
            '::query()',
            '::where(',
            '::all()',
            'DB::table(',
            'DB::connection(',
        ];

        foreach ($needles as $needle) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $lines
     * @param array<int, Issue> $issues */
    private function scanUnboundedQueries(array $lines, string $file, array &$issues, int $threshold): void
    {
        $queryCallCount = 0;

        foreach ($lines as $lineNumber => $line) {
            if (preg_match('/\b(all|get)\s*\(/i', $line) && preg_match('/\$[A-Za-z_][A-Za-z0-9_]*\s*=/', $line)) {
                $queryCallCount++;

                if ($queryCallCount > $threshold) {
                    $this->addIssue($issues, new Issue(
                        category: IssueCategory::PERFORMANCE,
                        severity: IssueSeverity::MEDIUM,
                        rule: 'unbounded-results',
                        message: 'Potentially large unbounded dataset load detected.',
                        file: $file,
                        line: $lineNumber + 1,
                        recommendation: 'Use pagination, cursor iteration, or explicit limits to bound query size and memory usage.',
                        code: trim($line),
                    ));
                }
            }
        }
    }

    /** @param list<string> $lines
     * @param array<int, Issue> $issues */
    private function scanQueryHotspots(array $lines, string $file, array &$issues): void
    {
        foreach ($lines as $lineNumber => $line) {
            if (str_contains($line, '->whereRaw(') || str_contains($line, '->havingRaw(') || str_contains($line, '->orderByRaw(')) {
                $this->addIssue($issues, new Issue(
                    category: IssueCategory::PERFORMANCE,
                    severity: IssueSeverity::LOW,
                    rule: 'raw-query-operations',
                    message: 'Raw query clauses may prevent optimizer efficiency and index usage.',
                    file: $file,
                    line: $lineNumber + 1,
                    recommendation: 'Prefer Eloquent/Query Builder operators and expressions to allow index-friendly SQL planning.',
                    code: trim($line),
                ));
            }

            if (preg_match('/\bDB::table\([^\n]+\)->(?:get|count|exists)\s*\(/i', $line) && ! str_contains($line, '->limit(')) {
                $this->addIssue($issues, new Issue(
                    category: IssueCategory::PERFORMANCE,
                    severity: IssueSeverity::LOW,
                    rule: 'database-table-scan',
                    message: 'Unbounded table read on DB facade call.',
                    file: $file,
                    line: $lineNumber + 1,
                    recommendation: 'Add where constraints, limit, or pagination for bounded work on large datasets.',
                    code: trim($line),
                ));
            }
        }
    }

    /** @param list<string> $lines
     * @param array<int, Issue> $issues */
    private function scanQueryMaterializationPatterns(array $lines, string $file, array &$issues): void
    {
        foreach ($lines as $lineNumber => $line) {
            $normalizedLine = trim($line);
            if ($normalizedLine === '') {
                continue;
            }

            $this->checkEagerMaterialization($normalizedLine, $file, $lineNumber + 1, $issues);
            $this->checkToArrayMaterialization($normalizedLine, $file, $lineNumber + 1, $issues);
            $this->checkCachedFullResultSet($normalizedLine, $file, $lineNumber + 1, $issues);
        }
    }

    /** @param array<int, Issue> $issues */
    private function checkEagerMaterialization(string $line, string $file, int $lineNumber, array &$issues): void
    {
        if (str_contains($line, '->chunk(') || str_contains($line, '->lazy(') || str_contains($line, '->cursor(') || str_contains($line, '->paginate(')) {
            return;
        }

        if (str_contains($line, '->limit(') || str_contains($line, '->take(')) {
            return;
        }

        $hasAllQuery = str_contains($line, '::all(');
        $hasBuilder = str_contains($line, 'DB::table(')
            || preg_match('/\b[A-Za-z_][A-Za-z0-9_\\\\]*::query\(/', $line) === 1
            || preg_match('/\b[A-Za-z_][A-Za-z0-9_\\\\]*::where\(/', $line) === 1;

        if (
            $hasAllQuery
            || (str_contains($line, '->get(') && $hasBuilder)
        ) {
            $this->addIssue($issues, new Issue(
                category: IssueCategory::PERFORMANCE,
                severity: IssueSeverity::MEDIUM,
                rule: 'memory-query-materialization',
                message: 'Potential full result set materialization into memory detected.',
                file: $file,
                line: $lineNumber,
                recommendation: 'Prefer chunk(), cursor(), or lazy() when processing potentially large datasets.',
                code: trim($line),
            ));
        }
    }

    /** @param array<int, Issue> $issues */
    private function checkToArrayMaterialization(string $line, string $file, int $lineNumber, array &$issues): void
    {
        if (! preg_match('/->get\(\)\s*->\s*toArray\(/i', $line) && ! preg_match('/->pluck\([^)]+\)\s*->\s*toArray\(/i', $line)) {
            return;
        }

        $this->addIssue($issues, new Issue(
            category: IssueCategory::PERFORMANCE,
            severity: IssueSeverity::LOW,
            rule: 'memory-toarray-materialization',
            message: 'Immediate array materialization from query results can increase memory footprint.',
            file: $file,
            line: $lineNumber,
            recommendation: 'Keep results as collection/iterable when possible and pluck only needed fields.',
            code: trim($line),
        ));
    }

    /** @param array<int, Issue> $issues */
    private function checkCachedFullResultSet(string $line, string $file, int $lineNumber, array &$issues): void
    {
        if (! preg_match('/\b(?:Cache::put|Cache::forever|Cache::remember|cache\()/i', $line)) {
            return;
        }

        if (! preg_match('/->get\(\)|::all\(|->toArray\(\)|->pluck\(/i', $line)) {
            return;
        }

        $this->addIssue($issues, new Issue(
            category: IssueCategory::PERFORMANCE,
            severity: IssueSeverity::MEDIUM,
            rule: 'memory-cache-full-result',
            message: 'Caching full query results can pin large datasets in memory for cache TTL duration.',
            file: $file,
            line: $lineNumber,
            recommendation: 'Cache only summaries/projections and avoid caching unbounded, mutable collections.',
            code: trim($line),
        ));
    }
}
