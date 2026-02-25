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

final class DoctorScanCommand extends Command
{
    protected $signature = 'doctor:scan
                            {--path=* : Optional absolute or project-relative paths to scan.}
                            {--category= : One of security|performance|correctness|architecture.}
                            {--json : Output JSON report for automation.}
                            {--min-severity=low : Minimum severity (low|medium|high).}';

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

        $category = is_string($this->option('category')) ? strtolower($this->option('category')) : null;
        $rawMinSeverity = $this->option('min-severity');
        $minSeverity = is_string($rawMinSeverity)
            ? IssueSeverity::tryFrom(strtolower($rawMinSeverity)) ?? IssueSeverity::LOW
            : IssueSeverity::LOW;

        $checks = $this->buildChecks($category);
        /** @var list<Issue> $issues */
        $issues = [];

        foreach ($checks as $label => $scannerClass) {
            /** @var DiagnosticCheck $scanner */
            $scanner = app($scannerClass);
            $checkIssues = $scanner->scan($paths, $config);
            $issues = array_merge($issues, array_filter($checkIssues, fn (Issue $issue) => $this->passesSeverity($issue->severity, $minSeverity)));
        }

        usort($issues, static function (Issue $a, Issue $b) {
            return $b->severity->value <=> $a->severity->value;
        });

        if ($this->option('json')) {
            return $this->outputJson($issues);
        }

        return $this->outputTable($issues);
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
        $order = [
            IssueSeverity::LOW->value => 0,
            IssueSeverity::MEDIUM->value => 1,
            IssueSeverity::HIGH->value => 2,
        ];

        return $order[$issueSeverity->value] >= $order[$minSeverity->value];
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
}
