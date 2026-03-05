<?php

namespace Bunce\LaravelDoctor\Commands;

use Bunce\LaravelDoctor\Domain\Issue;
use Bunce\LaravelDoctor\Domain\IssueSeverity;
use Bunce\LaravelDoctor\Scanners\Checks\ArchitectureScanner;
use Bunce\LaravelDoctor\Scanners\Checks\CorrectnessScanner;
use Bunce\LaravelDoctor\Scanners\Checks\PerformanceScanner;
use Bunce\LaravelDoctor\Scanners\Checks\SecurityScanner;
use Bunce\LaravelDoctor\Scanners\DiagnosticCheck;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

final class DoctorScanCommand extends Command
{
    protected $signature = 'doctor:scan
                            {--path=* : Optional absolute or project-relative paths to scan.}
                            {--changed : Scan only changed files (git working tree + index) or with --since.}
                            {--since= : Git ref for changed-file diff base (example: origin/main).}
                            {--category= : One of security|performance|correctness|architecture.}
                            {--json : Output JSON report for automation.}
                            {--min-severity=low : Minimum severity (low|medium|high).}
                            {--fail-on-severity= : Fail with non-zero exit when this severity (or higher) exists.}
                            {--max-issues= : Fail with non-zero exit when total issue count exceeds this number.}
                            {--baseline= : Baseline JSON path for suppressing known findings.}
                            {--update-baseline : Write current findings to baseline file.}';

    protected $description = 'Analyze a Laravel codebase for security, performance, correctness, and architecture issues.';

    /** @var array<string, class-string<DiagnosticCheck>> */
    private array $availableScanners = [
        'security' => SecurityScanner::class,
        'performance' => PerformanceScanner::class,
        'correctness' => CorrectnessScanner::class,
        'architecture' => ArchitectureScanner::class,
    ];

    public function handle(): int
    {
        /** @var array<string, mixed> $config */
        $config = config('doctor', []);

        $pathInput = $this->option('path');
        if (is_string($pathInput)) {
            $paths = [$pathInput];
        } elseif (is_array($pathInput)) {
            $paths = $pathInput;
        } else {
            $configuredPaths = $config['paths'] ?? [base_path('app')];
            if (is_string($configuredPaths)) {
                $paths = [$configuredPaths];
            } elseif (is_array($configuredPaths)) {
                $paths = $configuredPaths;
            } else {
                $paths = [base_path('app')];
            }
        }
        /** @var array<int, string> $paths */
        $paths = array_values(array_filter($paths, 'is_string'));
        if ($paths === []) {
            $paths = [base_path('app')];
        }

        $category = is_string($this->option('category')) ? strtolower($this->option('category')) : null;
        $rawMinSeverity = $this->option('min-severity');
        $minSeverity = is_string($rawMinSeverity)
            ? IssueSeverity::tryFrom(strtolower($rawMinSeverity)) ?? IssueSeverity::LOW
            : IssueSeverity::LOW;

        $useChangedFiles = (bool) $this->option('changed');
        $since = is_string($this->option('since')) && $this->option('since') !== '' ? (string) $this->option('since') : null;
        if ($useChangedFiles) {
            $changedFiles = $this->resolveChangedFiles($since, $this->normalizeExtensions($config['extensions'] ?? ['php']));
            if ($changedFiles === []) {
                if (! $this->option('json')) {
                    $this->components->warn('No changed files matched configured extensions.');
                }

                $issues = [];

                if ($this->option('json')) {
                    return $this->outputJson($issues);
                }

                $this->outputTable($issues);

                return self::SUCCESS;
            }

            $paths = $changedFiles;
        }

        $checks = $this->buildChecks($category);
        if ($checks === []) {
            return self::INVALID;
        }

        $cacheEnabled = (bool) (($config['cache']['enabled'] ?? true));
        $cachePath = is_string($config['cache']['path'] ?? null) ? (string) $config['cache']['path'] : base_path('storage/app/doctor-cache.json');
        $cacheState = $cacheEnabled ? $this->loadCacheState($cachePath) : [];

        $parallelEnabled = (bool) (($config['cache']['parallel_categories'] ?? true));
        /** @var list<Issue> $issues */
        $issues = $this->runChecks($checks, $paths, $config, $minSeverity, $parallelEnabled, $cacheEnabled, $cacheState);

        if ($cacheEnabled) {
            $this->saveCacheState($cachePath, $cacheState);
        }

        $baselinePathOption = $this->option('baseline');
        $baselinePath = is_string($baselinePathOption) && $baselinePathOption !== ''
            ? $baselinePathOption
            : (is_string($config['baseline']['path'] ?? null) ? (string) $config['baseline']['path'] : '');
        $updateBaseline = (bool) $this->option('update-baseline');
        if ($updateBaseline && $baselinePath !== '') {
            $this->writeBaseline($baselinePath, $issues);
            if (! $this->option('json')) {
                $this->components->info(sprintf('Baseline updated at %s', $baselinePath));
            }
        }

        $issues = $this->filterByBaseline($issues, $baselinePath);

        usort($issues, static function (Issue $a, Issue $b) {
            return $b->severity->value <=> $a->severity->value;
        });

        $exitCode = $this->resolveExitCodeByPolicy($issues);

        if ($this->option('json')) {
            $this->outputJson($issues);

            return $exitCode;
        }

        $this->outputTable($issues);

        if ($exitCode !== self::SUCCESS) {
            $this->components->error('Scan policy failed due to severity or issue-count threshold.');
        }

        return $exitCode;
    }

    /** @return array<string, class-string<DiagnosticCheck>> */
    private function buildChecks(?string $categoryFilter): array
    {
        if ($categoryFilter === null || $categoryFilter === '') {
            return $this->availableScanners;
        }

        $categoryFilter = strtolower($categoryFilter);
        if (! array_key_exists($categoryFilter, $this->availableScanners)) {
            $this->error('Invalid category filter. Use security, performance, correctness, or architecture.');

            return [];
        }

        return [$categoryFilter => $this->availableScanners[$categoryFilter]];
    }

    private function passesSeverity(IssueSeverity $issueSeverity, IssueSeverity $minSeverity): bool
    {
        return $this->severityRank($issueSeverity) >= $this->severityRank($minSeverity);
    }

    /** @param array<string, int> $weights */
    private function scoreIssueSeverity(Issue $issue, array $weights): int
    {
        return $weights[$issue->severity->value] ?? 0;
    }

    /**
     * @param  list<Issue>  $issues
     * @param  array<string, mixed>  $config
     */
    private function calculateScore(array $issues, array $config): int
    {
        /** @var array<string, mixed> $configuredWeights */
        $configuredWeights = is_array($config['weights'] ?? null) ? $config['weights'] : [];
        /** @var array<string, int> $weights */
        $weights = [
            'high' => 20,
            'medium' => 10,
            'low' => 4,
        ];
        foreach (['high', 'medium', 'low'] as $severity) {
            $weight = $configuredWeights[$severity] ?? null;
            if (is_numeric($weight)) {
                $weights[$severity] = (int) $weight;
            }
        }

        $score = isset($config['base_score']) && is_numeric($config['base_score']) ? (int) $config['base_score'] : 100;
        foreach ($issues as $issue) {
            $score -= $this->scoreIssueSeverity($issue, $weights);
        }

        return max(0, min(100, $score));
    }

    /** @param list<Issue> $issues */
    private function outputJson(array $issues): int
    {
        $config = config('doctor', []);
        /** @var array<string, mixed> $config */
        $config = is_array($config) ? $config : [];

        $payload = [
            'score' => $this->calculateScore($issues, $config),
            'total_issues' => count($issues),
            'issues' => array_map(fn (Issue $issue) => $issue->toArray(), $issues),
        ];
        $payloadJson = json_encode($payload, JSON_PRETTY_PRINT);

        $this->line($payloadJson === false ? '{}' : $payloadJson);

        return self::SUCCESS;
    }

    /** @param list<Issue> $issues */
    private function outputTable(array $issues): int
    {
        $config = config('doctor', []);
        /** @var array<string, mixed> $config */
        $config = is_array($config) ? $config : [];
        $score = $this->calculateScore($issues, $config);

        $this->components->info(sprintf('Laravel Doctor Score: %d/100', $score));
        $this->newLine();

        $rows = array_map(function (Issue $issue) {
            return [
                strtoupper($issue->severity->value),
                $issue->category->value,
                $issue->rule,
                $issue->message,
                sprintf('%s:%d', str_replace(base_path().'/', '', $issue->file), $issue->line),
                $issue->recommendation,
            ];
        }, $issues);

        $this->table(['Severity', 'Category', 'Rule', 'Issue', 'Location', 'Action'], $rows);

        $this->newLine();

        $ruleGroups = count(array_unique(array_map(
            static fn (Issue $issue) => $issue->rule,
            $issues,
        )));

        $this->components->info(sprintf('Analyzed %d issue(s) across %d rule groups.', count($issues), $ruleGroups));

        return self::SUCCESS;
    }

    private function severityRank(IssueSeverity $severity): int
    {
        return match ($severity) {
            IssueSeverity::LOW => 0,
            IssueSeverity::MEDIUM => 1,
            IssueSeverity::HIGH => 2,
        };
    }

    /**
     * @param array<string, class-string<DiagnosticCheck>> $checks
     * @param array<int, string> $paths
     * @param array<string, mixed> $config
     * @param array<string, mixed> $cacheState
     * @return list<Issue>
     */
    private function runChecks(array $checks, array $paths, array $config, IssueSeverity $minSeverity, bool $parallelEnabled, bool $cacheEnabled, array &$cacheState): array
    {
        if ($parallelEnabled && function_exists('pcntl_fork') && ! $cacheEnabled && count($checks) > 1) {
            return $this->runChecksInParallel($checks, $paths, $config, $minSeverity);
        }

        $extensions = $this->normalizeExtensions($config['extensions'] ?? ['php']);
        $candidateFiles = $this->listCandidateFiles($paths, $extensions);
        $issues = [];

        foreach ($checks as $label => $scannerClass) {
            $scanPaths = $candidateFiles === [] ? $paths : $candidateFiles;
            $reusedIssues = [];

            if ($cacheEnabled && $candidateFiles !== []) {
                $scanPaths = [];
                foreach ($candidateFiles as $file) {
                    $hash = sha1_file($file);
                    if (! is_string($hash) || $hash === '') {
                        $scanPaths[] = $file;
                        continue;
                    }

                    $entry = $cacheState['scanners'][$label]['files'][$file] ?? null;
                    if (
                        is_array($entry)
                        && ($entry['hash'] ?? null) === $hash
                        && is_array($entry['issues'] ?? null)
                    ) {
                        /** @var array<int, array<string, mixed>> $rawIssues */
                        $rawIssues = $entry['issues'];
                        foreach ($rawIssues as $rawIssue) {
                            $issue = Issue::fromArray($rawIssue);
                            if ($this->passesSeverity($issue->severity, $minSeverity)) {
                                $reusedIssues[] = $issue;
                            }
                        }
                        continue;
                    }

                    $scanPaths[] = $file;
                }
            }

            /** @var DiagnosticCheck $scanner */
            $scanner = app($scannerClass);
            $checkIssues = $scanPaths === [] ? [] : $scanner->scan($scanPaths, $config);
            $filteredCheckIssues = array_values(array_filter($checkIssues, fn (Issue $issue) => $this->passesSeverity($issue->severity, $minSeverity)));
            $issues = array_merge($issues, $reusedIssues, $filteredCheckIssues);

            if ($cacheEnabled && $candidateFiles !== []) {
                $issuesByFile = [];
                foreach ($checkIssues as $issue) {
                    $issuesByFile[$issue->file][] = $issue->toArray();
                }

                foreach ($scanPaths as $file) {
                    $hash = sha1_file($file);
                    if (! is_string($hash) || $hash === '') {
                        continue;
                    }

                    $cacheState['scanners'][$label]['files'][$file] = [
                        'hash' => $hash,
                        'issues' => $issuesByFile[$file] ?? [],
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * @param array<string, class-string<DiagnosticCheck>> $checks
     * @param array<int, string> $paths
     * @param array<string, mixed> $config
     * @return list<Issue>
     */
    private function runChecksInParallel(array $checks, array $paths, array $config, IssueSeverity $minSeverity): array
    {
        $jobs = [];
        $issues = [];

        foreach ($checks as $label => $scannerClass) {
            $tempFile = tempnam(sys_get_temp_dir(), 'doctor-'.$label.'-');
            if (! is_string($tempFile) || $tempFile === '') {
                continue;
            }

            $pid = pcntl_fork();
            if ($pid === -1) {
                continue;
            }

            if ($pid === 0) {
                /** @var DiagnosticCheck $scanner */
                $scanner = app($scannerClass);
                $checkIssues = $scanner->scan($paths, $config);
                $checkIssues = array_values(array_filter($checkIssues, fn (Issue $issue) => $this->passesSeverity($issue->severity, $minSeverity)));
                $payload = array_map(static fn (Issue $issue): array => $issue->toArray(), $checkIssues);
                file_put_contents($tempFile, json_encode($payload, JSON_PRETTY_PRINT));
                exit(0);
            }

            $jobs[] = [
                'pid' => $pid,
                'file' => $tempFile,
            ];
        }

        foreach ($jobs as $job) {
            pcntl_waitpid((int) $job['pid'], $status);
            $content = file_get_contents((string) $job['file']);
            @unlink((string) $job['file']);
            if (! is_string($content) || $content === '') {
                continue;
            }

            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $row) {
                if (is_array($row)) {
                    $issues[] = Issue::fromArray($row);
                }
            }
        }

        return $issues;
    }

    /**
     * @param array<int, string> $paths
     * @param array<int, string> $extensions
     * @return array<int, string>
     */
    private function listCandidateFiles(array $paths, array $extensions): array
    {
        $files = [];
        $directories = [];

        foreach ($paths as $path) {
            $absolutePath = $path;
            if (! str_starts_with($absolutePath, '/')) {
                $absolutePath = base_path($absolutePath);
            }

            if (is_file($absolutePath)) {
                $files[] = $absolutePath;
                continue;
            }

            if (is_dir($absolutePath)) {
                $directories[] = $absolutePath;
            }
        }

        if ($directories !== []) {
            $finder = new Finder;
            $finder->files()->in($directories)->ignoreVcs(true)->ignoreDotFiles(true);
            if ($extensions !== []) {
                $finder->name('/\.('.implode('|', array_map(static fn (string $ext): string => preg_quote($ext, '/'), $extensions)).')$/');
            }
            foreach ($finder as $file) {
                $realPath = $file->getRealPath();
                if (is_string($realPath) && $realPath !== '') {
                    $files[] = $realPath;
                }
            }
        }

        if ($extensions !== []) {
            $files = array_values(array_filter($files, static function (string $file) use ($extensions): bool {
                $extension = pathinfo($file, PATHINFO_EXTENSION);
                if (! is_string($extension) || $extension === '') {
                    return false;
                }

                return in_array($extension, $extensions, true);
            }));
        }

        return array_values(array_unique($files));
    }

    /**
     * @param array<int, string> $extensions
     * @return array<int, string>
     */
    private function resolveChangedFiles(?string $since, array $extensions): array
    {
        $relativeFiles = [];
        if (is_string($since) && $since !== '') {
            $relativeFiles = array_merge(
                $relativeFiles,
                $this->linesFromCommand(sprintf('git diff --name-only --diff-filter=ACMRTUXB %s...HEAD', escapeshellarg($since)))
            );
        } else {
            $relativeFiles = array_merge(
                $relativeFiles,
                $this->linesFromCommand('git diff --name-only --diff-filter=ACMRTUXB'),
                $this->linesFromCommand('git diff --name-only --cached --diff-filter=ACMRTUXB'),
                $this->linesFromCommand('git ls-files --others --exclude-standard')
            );
        }

        $files = [];
        foreach (array_unique($relativeFiles) as $relativePath) {
            if ($relativePath === '') {
                continue;
            }

            $absolutePath = str_starts_with($relativePath, '/') ? $relativePath : base_path($relativePath);
            if (! is_file($absolutePath)) {
                continue;
            }

            $extension = pathinfo($absolutePath, PATHINFO_EXTENSION);
            if (is_string($extension) && in_array($extension, $extensions, true)) {
                $files[] = $absolutePath;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @return array<int, string>
     */
    private function linesFromCommand(string $command): array
    {
        $output = [];
        $exitCode = 1;
        @exec($command.' 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $output), static fn (string $line): bool => $line !== ''));
    }

    /**
     * @return array<int, string>
     */
    private function normalizeExtensions(mixed $extensions): array
    {
        if (! is_array($extensions)) {
            return ['php'];
        }

        $normalized = array_values(array_filter($extensions, 'is_string'));

        return $normalized === [] ? ['php'] : $normalized;
    }

    /**
     * @param list<Issue> $issues
     * @return list<Issue>
     */
    private function filterByBaseline(array $issues, string $baselinePath): array
    {
        if ($baselinePath === '' || ! is_file($baselinePath)) {
            return $issues;
        }

        $payload = file_get_contents($baselinePath);
        if (! is_string($payload) || trim($payload) === '') {
            return $issues;
        }

        $decoded = json_decode($payload, true);
        if (! is_array($decoded) || ! is_array($decoded['fingerprints'] ?? null)) {
            return $issues;
        }

        $fingerprints = array_flip(array_values(array_filter($decoded['fingerprints'], 'is_string')));

        return array_values(array_filter($issues, static fn (Issue $issue): bool => ! isset($fingerprints[$issue->fingerprint()])));
    }

    /**
     * @param list<Issue> $issues
     */
    private function writeBaseline(string $baselinePath, array $issues): void
    {
        $directory = dirname($baselinePath);
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $fingerprints = array_values(array_unique(array_map(static fn (Issue $issue): string => $issue->fingerprint(), $issues)));
        sort($fingerprints);
        $payload = json_encode([
            'generated_at' => now()->toIso8601String(),
            'fingerprints' => $fingerprints,
        ], JSON_PRETTY_PRINT);

        if (is_string($payload)) {
            file_put_contents($baselinePath, $payload);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCacheState(string $cachePath): array
    {
        $content = is_file($cachePath) ? file_get_contents($cachePath) : false;
        if (! is_string($content) || $content === '') {
            return ['scanners' => []];
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return ['scanners' => []];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $cacheState
     */
    private function saveCacheState(string $cachePath, array $cacheState): void
    {
        $directory = dirname($cachePath);
        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $payload = json_encode($cacheState, JSON_PRETTY_PRINT);
        if (is_string($payload)) {
            file_put_contents($cachePath, $payload);
        }
    }

    /**
     * @param list<Issue> $issues
     */
    private function resolveExitCodeByPolicy(array $issues): int
    {
        $rawFailOnSeverity = $this->option('fail-on-severity');
        $failOnSeverity = is_string($rawFailOnSeverity) ? IssueSeverity::tryFrom(strtolower($rawFailOnSeverity)) : null;
        if ($failOnSeverity instanceof IssueSeverity) {
            foreach ($issues as $issue) {
                if ($this->severityRank($issue->severity) >= $this->severityRank($failOnSeverity)) {
                    return self::FAILURE;
                }
            }
        }

        $rawMaxIssues = $this->option('max-issues');
        if (is_numeric($rawMaxIssues) && count($issues) > (int) $rawMaxIssues) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
